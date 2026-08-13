<?php
declare(strict_types=1);

/**
 * LedgerReader  --  read-only access to the ledger for services and the verifier.
 * Never writes. The verifier (bin/verify.php) uses its own read-only connection
 * and this same class so there is one query surface.
 */
final class LedgerReader
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Fetch all entries ordered by sequence_no ascending. */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT sequence_no, actor_id, action, entity_type, entity_id,
                    canonical_payload, payload_hash, prev_entry_hash, entry_hash,
                    actor_signature, key_id, schema_version, created_at
             FROM ledger_entries ORDER BY sequence_no ASC'
        )->fetchAll();
    }

    /** Fetch entries in an inclusive sequence range. */
    public function range(int $fromSeq, int $toSeq): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sequence_no, entry_hash
             FROM ledger_entries
             WHERE sequence_no BETWEEN :from AND :to
             ORDER BY sequence_no ASC'
        );
        $stmt->bindValue(':from', $fromSeq, PDO::PARAM_INT);
        $stmt->bindValue(':to', $toSeq, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Highest sequence_no, or 0 if the ledger is empty. */
    public function headSequence(): int
    {
        $row = $this->pdo->query(
            'SELECT sequence_no FROM ledger_entries ORDER BY sequence_no DESC LIMIT 1'
        )->fetch();
        return $row === false ? 0 : (int) $row['sequence_no'];
    }

    /** Entries for a given entity (audit trail). */
    public function forEntity(string $entityType, string $entityId16): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sequence_no, action, canonical_payload, created_at
             FROM ledger_entries
             WHERE entity_type = :etype AND entity_id = :eid
             ORDER BY sequence_no ASC'
        );
        $stmt->bindValue(':etype', $entityType);
        $stmt->bindValue(':eid', $entityId16, PDO::PARAM_LOB);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
