<?php
declare(strict_types=1);

require_once __DIR__ . '/LedgerHash.php';
require_once __DIR__ . '/../crypto/Hasher.php';
require_once __DIR__ . '/../crypto/Canonicalizer.php';
require_once __DIR__ . '/../crypto/DomainSeparators.php';
require_once __DIR__ . '/../db/Clock.php';

/**
 * LedgerWriter  --  the ONLY code path that writes ledger_entries (build spec
 * section 10). No other module inserts into that table.
 *
 * Append protocol:
 *   1. Build the canonical event payload (canonical JSON, includes schema =
 *      PROCUREMENT-LEDGER-V1 plus event fields). Store the exact bytes.
 *   2. payload_hash = SHA-256(canonical_payload).
 *   3. Lock the current head: SELECT ... ORDER BY sequence_no DESC LIMIT 1 FOR UPDATE.
 *   4. Genesis -> sequence_no 1, prev NULL (hashed as 32 zero bytes); else
 *      sequence_no = head + 1, prev = head.entry_hash.
 *   5. Compute entry_hash (LedgerHash).
 *   6. Optionally attach actor_signature and key_id.
 *   7. INSERT, then the surrounding transaction commits. The FOR UPDATE lock
 *      serializes concurrent appends so two entries never claim the same parent.
 *
 * The caller MUST invoke append() inside the same transaction as its business
 * write, so the ledger entry and the business row commit atomically.
 */
final class LedgerWriter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Append one ledger entry. Must be called within an open transaction.
     *
     * @param string      $actorId16   16 raw bytes (the user acting)
     * @param string      $action      ledger action constant (see LedgerActions)
     * @param string      $entityType  e.g. 'requisition'
     * @param string      $entityId16  16 raw bytes of the target entity
     * @param array       $eventFields associative event fields (canonical values;
     *                                 decimals as strings, ids as hex, times as ISO)
     * @param string|null $actorSignature optional raw signature bytes
     * @param string|null $keyId16     optional 16-byte key id for the signature
     * @return array{sequence_no:int, entry_hash:string, payload_hash:string, canonical_payload:string, created_at:string}
     */
    public function append(
        string $actorId16,
        string $action,
        string $entityType,
        string $entityId16,
        array $eventFields,
        ?string $actorSignature = null,
        ?string $keyId16 = null
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('LedgerWriter::append must run inside a transaction.');
        }

        // Capture one instant; both the hashed ISO form and the stored DB value
        // must derive from it, or the verifier's recomputation will not match.
        $now = Clock::now();
        $createdAtIso = Clock::iso($now);
        $schemaVersion = DomainSeparators::LEDGER;

        // 1. canonical payload (exact bytes stored and hashed).
        $payload = array_merge(
            ['schema' => $schemaVersion],
            [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => Canonicalizer::uuidToHex($entityId16),
                'actor_id'    => Canonicalizer::uuidToHex($actorId16),
                'created_at'  => $createdAtIso,
            ],
            $eventFields
        );
        $canonicalPayload = Canonicalizer::encode($payload);

        // 2. payload hash.
        $payloadHash = Hasher::sha256($canonicalPayload);

        // 3. lock head.
        $head = $this->pdo->query(
            'SELECT sequence_no, entry_hash FROM ledger_entries ORDER BY sequence_no DESC LIMIT 1 FOR UPDATE'
        )->fetch();

        if ($head === false) {
            $sequenceNo = 1;
            $prevEntryHash = null; // genesis: stored NULL, hashed as zeros
        } else {
            $sequenceNo = (int) $head['sequence_no'] + 1;
            $prevEntryHash = $head['entry_hash'];
        }

        // 5. entry hash.
        $entryHash = LedgerHash::compute(
            $sequenceNo,
            $prevEntryHash,
            $actorId16,
            $action,
            $entityType,
            $entityId16,
            $payloadHash,
            $createdAtIso,
            $schemaVersion
        );

        // 7. insert.
        $stmt = $this->pdo->prepare(
            'INSERT INTO ledger_entries
                (sequence_no, actor_id, action, entity_type, entity_id,
                 canonical_payload, payload_hash, prev_entry_hash, entry_hash,
                 actor_signature, key_id, schema_version, created_at)
             VALUES
                (:seq, :actor, :action, :etype, :eid,
                 :payload, :phash, :prev, :ehash,
                 :sig, :key, :schema, :created)'
        );
        $stmt->bindValue(':seq', $sequenceNo, PDO::PARAM_INT);
        $stmt->bindValue(':actor', $actorId16, PDO::PARAM_LOB);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':etype', $entityType);
        $stmt->bindValue(':eid', $entityId16, PDO::PARAM_LOB);
        $stmt->bindValue(':payload', $canonicalPayload, PDO::PARAM_LOB);
        $stmt->bindValue(':phash', $payloadHash, PDO::PARAM_LOB);
        $stmt->bindValue(':prev', $prevEntryHash, $prevEntryHash === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':ehash', $entryHash, PDO::PARAM_LOB);
        $stmt->bindValue(':sig', $actorSignature, $actorSignature === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':key', $keyId16, $keyId16 === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':schema', $schemaVersion);
        $stmt->bindValue(':created', Clock::mysql($now));
        $stmt->execute();

        return [
            'sequence_no'       => $sequenceNo,
            'entry_hash'        => $entryHash,
            'payload_hash'      => $payloadHash,
            'canonical_payload' => $canonicalPayload,
            'created_at'        => $createdAtIso,
        ];
    }
}
