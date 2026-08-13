<?php
declare(strict_types=1);

require_once __DIR__ . '/Services.php';

/**
 * RfqService  --  bidding document versions, document approval, and RFQ
 * publication (build spec section 15).
 *
 * Publication sets published_at, moves status off 'draft', and from that point
 * the timing trigger makes bid_deadline, reveal_start, reveal_deadline immutable.
 * The publication ledger entry captures those timestamps so any attempt to change
 * them is detectable even if the trigger is bypassed.
 */
final class RfqService
{
    private Services $s;

    public function __construct(Services $s)
    {
        $this->s = $s;
    }

    /** Add a bidding document version (PDU officer). Ledger DOCUMENT_VERSION_ADDED. */
    public function addDocumentVersion(
        string $userId16,
        string $documentId16,
        int $versionNo,
        string $contentHash32,
        string $storageReference,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $this->s->authz->requireRole($userId16, Rbac::PDU_OFFICER);
        $now = Clock::now();
        // Signature covers the version metadata payload.
        $this->s->evidence->verify(DomainSeparators::REQUISITION, $canonical, $signature, $keyId16, $userId16, $now);
        $versionId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $versionId, $documentId16, $versionNo, $contentHash32, $storageReference, $userId16, $signature, $keyId16, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO bidding_document_versions
                    (version_id, document_id, version_no, content_hash, schema_version,
                     storage_reference, created_by, created_at)
                 VALUES (:id, :doc, :vno, :chash, :schema, :ref, :by, :at)'
            );
            $stmt->bindValue(':id', $versionId, PDO::PARAM_LOB);
            $stmt->bindValue(':doc', $documentId16, PDO::PARAM_LOB);
            $stmt->bindValue(':vno', $versionNo, PDO::PARAM_INT);
            $stmt->bindValue(':chash', $contentHash32, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::REQUISITION);
            $stmt->bindValue(':ref', $storageReference);
            $stmt->bindValue(':by', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append(
                $userId16, LedgerActions::DOCUMENT_VERSION_ADDED, 'bidding_document', $documentId16,
                ['version_no' => $versionNo, 'content_hash' => bin2hex($contentHash32)], $signature, $keyId16
            );
            return $versionId;
        });
    }

    /**
     * Publish an RFQ, locking timing. bid_deadline < reveal_start < reveal_deadline
     * must already be ordered (schema CHECK). The ledger entry captures the exact
     * timing so deadline tampering is detectable.
     */
    public function publish(
        string $userId16,
        string $rfqId16,
        DateTimeImmutable $bidDeadline,
        DateTimeImmutable $revealStart,
        DateTimeImmutable $revealDeadline,
        string $canonical,
        string $signature,
        string $keyId16
    ): void {
        $this->s->authz->requireRole($userId16, Rbac::PDU_OFFICER);
        if (!($bidDeadline < $revealStart && $revealStart < $revealDeadline)) {
            throw new InvalidArgumentException('Timing must be bid_deadline < reveal_start < reveal_deadline.');
        }
        $now = Clock::now();
        $this->s->evidence->verify(DomainSeparators::REQUISITION, $canonical, $signature, $keyId16, $userId16, $now);

        Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $rfqId16, $bidDeadline, $revealStart, $revealDeadline, $userId16, $signature, $keyId16, $now
        ) {
            // Set timing and publish while still 'draft' (trigger allows changes
            // only in draft; after this the timing is immutable).
            $upd = $pdo->prepare(
                'UPDATE rfqs
                 SET bid_deadline = :bd, reveal_start = :rs, reveal_deadline = :rd,
                     published_at = :pub, status = "published"
                 WHERE rfq_id = :id AND status = "draft"'
            );
            $upd->bindValue(':bd', Clock::mysql($bidDeadline));
            $upd->bindValue(':rs', Clock::mysql($revealStart));
            $upd->bindValue(':rd', Clock::mysql($revealDeadline));
            $upd->bindValue(':pub', Clock::mysql($now));
            $upd->bindValue(':id', $rfqId16, PDO::PARAM_LOB);
            $upd->execute();
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('RFQ not in draft state; cannot publish.');
            }

            // Capture the exact timing in the ledger (deadline-tamper evidence).
            $this->s->ledger->append(
                $userId16, LedgerActions::RFQ_PUBLISHED, 'rfq', $rfqId16,
                [
                    'bid_deadline'    => Clock::iso($bidDeadline),
                    'reveal_start'    => Clock::iso($revealStart),
                    'reveal_deadline' => Clock::iso($revealDeadline),
                    'published_at'    => Clock::iso($now),
                ],
                $signature, $keyId16
            );
        });
    }
}
