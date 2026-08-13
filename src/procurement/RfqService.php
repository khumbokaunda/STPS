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
     * Prepare a draft RFQ from an approved requisition (PDU officer). Bundles the
     * bidding-document, an initial approved version, and a draft RFQ so the sealed
     * bid flow can begin. Timing is a placeholder until publish() locks it.
     * Ledger DOCUMENT_VERSION_ADDED (system-actor step).
     *
     * @return string the new rfq_id (16 bytes)
     */
    public function prepareFromRequisition(string $userId16, string $requisitionId16, string $method): string
    {
        $this->s->authz->requireRole($userId16, Rbac::PDU_OFFICER);
        $now = Clock::now();

        $req = $this->loadRequisition($requisitionId16);
        if ($req['status'] !== 'approved') {
            throw new RuntimeException('Requisition must be approved before an RFQ is prepared.');
        }

        $documentId = Uuid::bin();
        $versionId = Uuid::bin();
        $rfqId = Uuid::bin();
        $contentHash = Hasher::sha256('bidding-document:' . $req['reference_no']);
        // Placeholder deadline (NOT NULL column); publish() overwrites it while draft.
        $placeholder = $now->add(new DateInterval('P365D'));

        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $documentId, $versionId, $rfqId, $requisitionId16, $req, $method, $contentHash, $userId16, $now, $placeholder
        ) {
            $d = $pdo->prepare('INSERT INTO bidding_documents (document_id, requisition_id, status, created_at) VALUES (:id,:req,"approved",:at)');
            $d->bindValue(':id', $documentId, PDO::PARAM_LOB);
            $d->bindValue(':req', $requisitionId16, PDO::PARAM_LOB);
            $d->bindValue(':at', Clock::mysql($now));
            $d->execute();

            $v = $pdo->prepare('INSERT INTO bidding_document_versions (version_id, document_id, version_no, content_hash, schema_version, storage_reference, created_by, created_at) VALUES (:id,:doc,1,:ch,:sv,:ref,:by,:at)');
            $v->bindValue(':id', $versionId, PDO::PARAM_LOB);
            $v->bindValue(':doc', $documentId, PDO::PARAM_LOB);
            $v->bindValue(':ch', $contentHash, PDO::PARAM_LOB);
            $v->bindValue(':sv', DomainSeparators::REQUISITION);
            $v->bindValue(':ref', 'req:' . $req['reference_no']);
            $v->bindValue(':by', $userId16, PDO::PARAM_LOB);
            $v->bindValue(':at', Clock::mysql($now));
            $v->execute();

            $u = $pdo->prepare('UPDATE bidding_documents SET approved_version_id = :v WHERE document_id = :d');
            $u->bindValue(':v', $versionId, PDO::PARAM_LOB);
            $u->bindValue(':d', $documentId, PDO::PARAM_LOB);
            $u->execute();

            $r = $pdo->prepare('INSERT INTO rfqs (rfq_id, requisition_id, bidding_document_version_id, reference_no, procurement_method, bid_deadline, status, created_at) VALUES (:id,:req,:ver,:ref,:method,:bd,"draft",:at)');
            $r->bindValue(':id', $rfqId, PDO::PARAM_LOB);
            $r->bindValue(':req', $requisitionId16, PDO::PARAM_LOB);
            $r->bindValue(':ver', $versionId, PDO::PARAM_LOB);
            $r->bindValue(':ref', $req['reference_no'] . '-RFQ');
            $r->bindValue(':method', $method);
            $r->bindValue(':bd', Clock::mysql($placeholder));
            $r->bindValue(':at', Clock::mysql($now));
            $r->execute();

            $up = $pdo->prepare('UPDATE requisitions SET status = "procurement" WHERE requisition_id = :id');
            $up->bindValue(':id', $requisitionId16, PDO::PARAM_LOB);
            $up->execute();

            $this->s->ledger->append($userId16, LedgerActions::DOCUMENT_VERSION_ADDED, 'bidding_document', $documentId,
                ['requisition_reference' => $req['reference_no'], 'content_hash' => bin2hex($contentHash)]);
            return $rfqId;
        });
    }

    private function loadRequisition(string $requisitionId16): array
    {
        $stmt = $this->s->pdo->prepare('SELECT requisition_id, reference_no, status FROM requisitions WHERE requisition_id = :id LIMIT 1');
        $stmt->bindValue(':id', $requisitionId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Requisition not found.');
        }
        return $row;
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
