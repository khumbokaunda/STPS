<?php
declare(strict_types=1);

require_once __DIR__ . '/Services.php';

/**
 * BidService  --  sealed-bid commit / reveal (build spec section 12).
 *
 * State machine:
 *   committed -> revealed -> evaluated -> awarded | not_awarded
 *   committed -> expired   (reveal window passed with no valid reveal)
 *   committed -> invalid   (reveal did not match the commitment)
 *
 * Invariants: never store the nonce or plaintext bid at commit; never UPDATE the
 * original commitment; the server clock is authoritative for deadlines.
 */
final class BidService
{
    private Services $s;

    public function __construct(Services $s)
    {
        $this->s = $s;
    }

    /**
     * Commit a sealed bid (before bid_deadline). The client sends only
     * { rfq_id, C, sigC, key_id, escrow_ciphertext? } -- no bid, no nonce.
     *
     * @param string      $bidderUserId16 the authenticated bidder user
     * @param string      $bidderId16     the bidders row id (organisation)
     * @param string      $rfqId16
     * @param string      $commitmentC    32-byte commitment hash C
     * @param string      $sigC           client signature over commit digest
     * @param string      $keyId16
     * @param string|null $escrowCiphertext optional; meaningful only with threshold decryption
     * @return string bid_id
     */
    public function commit(
        string $bidderUserId16,
        string $bidderId16,
        string $rfqId16,
        string $commitmentC,
        string $sigC,
        string $keyId16,
        ?string $escrowCiphertext = null
    ): string {
        $this->s->authz->requireRole($bidderUserId16, Rbac::BIDDER);
        if (strlen($commitmentC) !== 32) {
            throw new InvalidArgumentException('commitment C must be 32 bytes.');
        }

        $rfq = $this->loadRfq($rfqId16);
        $now = Clock::now();
        // Server clock authoritative: reject at or after the deadline.
        $deadline = Clock::fromMysql($rfq['bid_deadline']);
        if ($now >= $deadline) {
            throw new RuntimeException('Bid deadline has passed.');
        }
        if ($rfq['status'] !== 'published') {
            throw new RuntimeException('RFQ is not open for bids.');
        }

        // Verify sigC: the bidder signs SHA-256(SEP 0x1F C) with domain BID_COMMITMENT.
        $commitDigest = Commitment::commitSigningDigest($commitmentC);
        $pem = $this->s->keys->validPublicKeyPem($keyId16, $bidderUserId16, $now);
        if ($pem === null || !Signer::verifyDigest($commitDigest, $sigC, $pem)) {
            throw new RuntimeException('Commit signature verification failed.');
        }

        $bidId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $bidId, $rfqId16, $bidderId16, $commitmentC, $escrowCiphertext, $sigC, $keyId16, $bidderUserId16, $now
        ) {
            // No nonce, no plaintext stored (invariant 1).
            $stmt = $pdo->prepare(
                'INSERT INTO bids (bid_id, rfq_id, bidder_id, commitment_hash, escrow_ciphertext, status, committed_at)
                 VALUES (:id, :rfq, :bidder, :c, :escrow, "committed", :at)'
            );
            $stmt->bindValue(':id', $bidId, PDO::PARAM_LOB);
            $stmt->bindValue(':rfq', $rfqId16, PDO::PARAM_LOB);
            $stmt->bindValue(':bidder', $bidderId16, PDO::PARAM_LOB);
            $stmt->bindValue(':c', $commitmentC, PDO::PARAM_LOB);
            $stmt->bindValue(':escrow', $escrowCiphertext, $escrowCiphertext === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();

            // Signed commitment record (append-only). Signs bids.commitment_hash.
            $commitStmt = $pdo->prepare(
                'INSERT INTO bid_commitments (commitment_id, bid_id, signature, key_id, schema_version, committed_at)
                 VALUES (:cid, :bid, :sig, :key, :schema, :at)'
            );
            $commitStmt->bindValue(':cid', Uuid::bin(), PDO::PARAM_LOB);
            $commitStmt->bindValue(':bid', $bidId, PDO::PARAM_LOB);
            $commitStmt->bindValue(':sig', $sigC, PDO::PARAM_LOB);
            $commitStmt->bindValue(':key', $keyId16, PDO::PARAM_LOB);
            $commitStmt->bindValue(':schema', DomainSeparators::BID_COMMITMENT);
            $commitStmt->bindValue(':at', Clock::mysql($now));
            $commitStmt->execute();

            $this->s->ledger->append(
                $bidderUserId16,
                LedgerActions::BID_COMMITTED,
                'bid',
                $bidId,
                [
                    'rfq_id'          => bin2hex($rfqId16),
                    'commitment_hash' => bin2hex($commitmentC),
                ],
                $sigC,
                $keyId16
            );
            return $bidId;
        });
    }

    /**
     * Reveal a committed bid within [reveal_start, reveal_deadline]. The bidder
     * sends { bid_id, canonical_bid, nonce, sigR }. The server recomputes C and
     * requires it to equal bids.commitment_hash.
     *
     * @return string 'revealed' | 'invalid'
     */
    public function reveal(
        string $bidderUserId16,
        string $bidId16,
        string $canonicalBid,
        string $nonce,
        string $sigR,
        string $keyId16
    ): string {
        $this->s->authz->requireRole($bidderUserId16, Rbac::BIDDER);
        $bid = $this->loadBid($bidId16);
        $rfq = $this->loadRfq($bid['rfq_id']);
        $now = Clock::now();

        // Ownership: the bid must belong to this bidder user's organisation.
        $this->requireBidOwnership($bidderUserId16, $bid['bidder_id']);

        // Reveal window (server clock authoritative).
        $revealStart = Clock::fromMysql($rfq['reveal_start']);
        $revealDeadline = Clock::fromMysql($rfq['reveal_deadline']);
        if ($now < $revealStart || $now > $revealDeadline) {
            throw new RuntimeException('Outside the reveal window.');
        }
        if ($bid['status'] !== 'committed') {
            throw new RuntimeException('Bid is not in committed state.');
        }

        // Verify the reveal signature over the canonical bid (domain BID_REVEAL).
        $payloadHash = $this->s->evidence->verify(
            DomainSeparators::BID_REVEAL, $canonicalBid, $sigR, $keyId16, $bidderUserId16, $now
        );

        // Recompute C2 with the identical commit construction.
        $c2 = Commitment::compute($bid['rfq_id'], $bid['bidder_id'], $canonicalBid, $nonce);
        $matches = Hasher::equals($c2, $bid['commitment_hash']);

        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $bid, $bidId16, $canonicalBid, $nonce, $payloadHash, $sigR, $keyId16, $bidderUserId16, $matches, $now
        ) {
            if ($matches) {
                // Insert the reveal (append-only), set status revealed.
                $stmt = $pdo->prepare(
                    'INSERT INTO bid_reveals
                        (reveal_id, bid_id, canonical_payload, payload_json, nonce, payload_hash,
                         signature, key_id, schema_version, revealed_at)
                     VALUES (:id, :bid, :payload, :json, :nonce, :phash, :sig, :key, :schema, :at)'
                );
                $stmt->bindValue(':id', Uuid::bin(), PDO::PARAM_LOB);
                $stmt->bindValue(':bid', $bidId16, PDO::PARAM_LOB);
                $stmt->bindValue(':payload', $canonicalBid, PDO::PARAM_LOB);
                // payload_json is a non-authoritative copy for querying only; it is
                // NEVER hashed or signed. Store a valid JSON copy of the same bytes.
                $stmt->bindValue(':json', $canonicalBid);
                $stmt->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
                $stmt->bindValue(':phash', $payloadHash, PDO::PARAM_LOB);
                $stmt->bindValue(':sig', $sigR, PDO::PARAM_LOB);
                $stmt->bindValue(':key', $keyId16, PDO::PARAM_LOB);
                $stmt->bindValue(':schema', DomainSeparators::BID_REVEAL);
                $stmt->bindValue(':at', Clock::mysql($now));
                $stmt->execute();

                $this->setBidStatus($pdo, $bidId16, 'revealed');
                $this->s->ledger->append(
                    $bidderUserId16, LedgerActions::BID_REVEALED, 'bid', $bidId16,
                    ['payload_hash' => bin2hex($payloadHash)], $sigR, $keyId16
                );
                return 'revealed';
            }

            // Mismatch: the bidder tried to open a different bid than committed.
            $this->setBidStatus($pdo, $bidId16, 'invalid');
            $this->s->ledger->append(
                $bidderUserId16, LedgerActions::BID_INVALID, 'bid', $bidId16,
                [
                    'reason'          => 'commitment_mismatch',
                    'claimed_hash'    => bin2hex($payloadHash),
                ], $sigR, $keyId16
            );
            return 'invalid';
        });
    }

    /**
     * Close an RFQ at its deadline (system actor). Moves status to 'closed' and
     * opens the reveal phase; ledger RFQ_CLOSED.
     */
    public function closeRfq(string $systemUserId16, string $rfqId16): void
    {
        $this->s->authz->requireAnyRole($systemUserId16, [Rbac::PDU_OFFICER, Rbac::SYSTEM_ADMINISTRATOR]);
        $rfq = $this->loadRfq($rfqId16);
        $now = Clock::now();
        if ($now < Clock::fromMysql($rfq['bid_deadline'])) {
            throw new RuntimeException('Cannot close before the bid deadline.');
        }
        Db::transaction($this->s->pdo, function (PDO $pdo) use ($rfqId16, $systemUserId16) {
            $upd = $pdo->prepare('UPDATE rfqs SET status = "closed" WHERE rfq_id = :id AND status = "published"');
            $upd->bindValue(':id', $rfqId16, PDO::PARAM_LOB);
            $upd->execute();
            $this->s->ledger->append($systemUserId16, LedgerActions::RFQ_CLOSED, 'rfq', $rfqId16, []);
        });
    }

    /**
     * Expire a committed bid whose reveal window passed with no valid reveal
     * (selective non-reveal is a business rule, not a crypto failure).
     */
    public function expireUnrevealed(string $systemUserId16, string $bidId16): void
    {
        $this->s->authz->requireAnyRole($systemUserId16, [Rbac::PDU_OFFICER, Rbac::SYSTEM_ADMINISTRATOR]);
        $bid = $this->loadBid($bidId16);
        $rfq = $this->loadRfq($bid['rfq_id']);
        $now = Clock::now();
        if ($now <= Clock::fromMysql($rfq['reveal_deadline'])) {
            throw new RuntimeException('Reveal window has not closed yet.');
        }
        if ($bid['status'] !== 'committed') {
            throw new RuntimeException('Only committed bids can expire.');
        }
        Db::transaction($this->s->pdo, function (PDO $pdo) use ($bidId16, $systemUserId16) {
            $this->setBidStatus($pdo, $bidId16, 'expired');
            $this->s->ledger->append($systemUserId16, LedgerActions::BID_EXPIRED, 'bid', $bidId16, ['reason' => 'no_valid_reveal']);
        });
    }

    // ---- internals -------------------------------------------------------

    private function setBidStatus(PDO $pdo, string $bidId16, string $status): void
    {
        $upd = $pdo->prepare('UPDATE bids SET status = :s WHERE bid_id = :id');
        $upd->bindValue(':s', $status);
        $upd->bindValue(':id', $bidId16, PDO::PARAM_LOB);
        $upd->execute();
    }

    private function requireBidOwnership(string $bidderUserId16, string $bidderId16): void
    {
        // The user must be linked to the bidders organisation on the bid.
        $stmt = $this->s->pdo->prepare(
            'SELECT 1 FROM users WHERE user_id = :uid AND bidder_id = :bid LIMIT 1'
        );
        $stmt->bindValue(':uid', $bidderUserId16, PDO::PARAM_LOB);
        $stmt->bindValue(':bid', $bidderId16, PDO::PARAM_LOB);
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            throw new AuthorizationException('User does not own this bid.');
        }
    }

    private function loadRfq(string $rfqId16): array
    {
        $stmt = $this->s->pdo->prepare(
            'SELECT rfq_id, status, bid_deadline, reveal_start, reveal_deadline
             FROM rfqs WHERE rfq_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $rfqId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Unknown RFQ.');
        }
        return $row;
    }

    private function loadBid(string $bidId16): array
    {
        $stmt = $this->s->pdo->prepare(
            'SELECT bid_id, rfq_id, bidder_id, commitment_hash, status FROM bids WHERE bid_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $bidId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Unknown bid.');
        }
        return $row;
    }
}
