<?php
declare(strict_types=1);

/**
 * EntityGuard  --  validates polymorphic entity references (build spec section
 * 13). MySQL cannot foreign-key entity_type + entity_id pairs (approvals,
 * committee_decisions, conflict_declarations, ledger_entries), so the service
 * layer must confirm the referenced entity exists and is in an approvable state
 * before inserting.
 */
final class EntityGuard
{
    /** entity_type -> [table, primary key column, status column|null]. */
    private const MAP = [
        'requisition'       => ['requisitions', 'requisition_id', 'status'],
        'bidding_document'  => ['bidding_documents', 'document_id', 'status'],
        'rfq'               => ['rfqs', 'rfq_id', 'status'],
        'evaluation_report' => ['evaluation_reports', 'report_id', null],
        'award'             => ['awards', 'award_id', 'status'],
        'contract'          => ['contracts', 'contract_id', 'status'],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function exists(string $entityType, string $entityId16): bool
    {
        [$table, $pk] = $this->resolve($entityType);
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE {$pk} = :id LIMIT 1");
        $stmt->bindValue(':id', $entityId16, PDO::PARAM_LOB);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /** Current status string of the entity, or null if statusless / not found. */
    public function statusOf(string $entityType, string $entityId16): ?string
    {
        [$table, $pk, $statusCol] = $this->resolve($entityType, true);
        if ($statusCol === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT {$statusCol} FROM {$table} WHERE {$pk} = :id LIMIT 1");
        $stmt->bindValue(':id', $entityId16, PDO::PARAM_LOB);
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }

    /** Require the entity to exist and be in one of the given states. */
    public function requireState(string $entityType, string $entityId16, array $allowedStates): void
    {
        if (!$this->exists($entityType, $entityId16)) {
            throw new RuntimeException("EntityGuard: {$entityType} does not exist.");
        }
        $status = $this->statusOf($entityType, $entityId16);
        if ($status !== null && !in_array($status, $allowedStates, true)) {
            throw new RuntimeException("EntityGuard: {$entityType} is in state '{$status}', not approvable.");
        }
    }

    private function resolve(string $entityType, bool $withStatus = false): array
    {
        // Table and column names come from this fixed whitelist ONLY; they are
        // never taken from user input, so interpolating them into SQL is safe.
        if (!isset(self::MAP[$entityType])) {
            throw new InvalidArgumentException("EntityGuard: unknown entity type '{$entityType}'.");
        }
        return self::MAP[$entityType];
    }
}
