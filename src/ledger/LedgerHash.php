<?php
declare(strict_types=1);

require_once __DIR__ . '/../crypto/Hasher.php';

/**
 * LedgerHash  --  the single canonical construction of ledger entry_hash
 * (build spec section 10 rule 5). Shared by LedgerWriter (append) and
 * bin/verify.php (independent recomputation) so there is exactly one definition.
 *
 *   entry_hash = SHA-256(
 *       ascii(sequence_no)              0x1F
 *       prev_entry_hash_bytes (32,      0x1F   zeros for genesis)
 *       actor_id (16 bytes)             0x1F
 *       action (utf-8)                  0x1F
 *       entity_type (utf-8)             0x1F
 *       entity_id (16 bytes)            0x1F
 *       payload_hash (32 bytes)         0x1F
 *       created_at (iso-8601 Z, utf-8)  0x1F
 *       schema_version (utf-8)
 *   )
 *
 * Genesis (sequence_no == 1): prev_entry_hash is stored as SQL NULL in the row,
 * but for hashing purposes it is treated as 32 zero bytes.
 */
final class LedgerHash
{
    public const ZERO32 = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
        . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    /**
     * @param int         $sequenceNo
     * @param string|null $prevEntryHash 32 bytes, or null for genesis
     * @param string      $actorId       16 bytes
     * @param string      $action
     * @param string      $entityType
     * @param string      $entityId      16 bytes
     * @param string      $payloadHash   32 bytes
     * @param string      $createdAtIso  ISO-8601 UTC ms 'Z'
     * @param string      $schemaVersion domain separator string
     */
    public static function compute(
        int $sequenceNo,
        ?string $prevEntryHash,
        string $actorId,
        string $action,
        string $entityType,
        string $entityId,
        string $payloadHash,
        string $createdAtIso,
        string $schemaVersion
    ): string {
        $prev = $prevEntryHash ?? self::ZERO32;
        if (strlen($prev) !== 32) {
            throw new InvalidArgumentException('LedgerHash: prev_entry_hash must be 32 bytes.');
        }
        if (strlen($actorId) !== 16 || strlen($entityId) !== 16) {
            throw new InvalidArgumentException('LedgerHash: actor_id/entity_id must be 16 bytes.');
        }
        if (strlen($payloadHash) !== 32) {
            throw new InvalidArgumentException('LedgerHash: payload_hash must be 32 bytes.');
        }
        return Hasher::sha256Fields([
            (string) $sequenceNo,
            $prev,
            $actorId,
            $action,
            $entityType,
            $entityId,
            $payloadHash,
            $createdAtIso,
            $schemaVersion,
        ]);
    }
}
