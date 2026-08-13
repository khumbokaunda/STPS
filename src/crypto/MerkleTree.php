<?php
declare(strict_types=1);

require_once __DIR__ . '/Hasher.php';

/**
 * MerkleTree  --  SHA-256 Merkle tree over ordered ledger entry hashes
 * (build spec section 11).
 *
 * Rules (the verifier MUST use the identical rules):
 *   - Leaf = the 32-byte entry_hash, used as-is (no leaf prefix).
 *   - Internal node = SHA-256(left || right).
 *   - If a level has an odd number of nodes, carry the last node up unchanged
 *     (do NOT duplicate it).
 */
final class MerkleTree
{
    /**
     * Compute the Merkle root over an ordered list of 32-byte leaves.
     * @param string[] $leaves each a 32-byte binary hash
     * @return string 32-byte root
     */
    public static function root(array $leaves): string
    {
        $n = count($leaves);
        if ($n === 0) {
            throw new InvalidArgumentException('MerkleTree: empty leaf set.');
        }
        $level = array_values($leaves);
        while (count($level) > 1) {
            $next = [];
            $count = count($level);
            for ($i = 0; $i < $count; $i += 2) {
                if ($i + 1 < $count) {
                    $next[] = Hasher::sha256($level[$i] . $level[$i + 1]);
                } else {
                    // Odd node carried up unchanged.
                    $next[] = $level[$i];
                }
            }
            $level = $next;
        }
        return $level[0];
    }

    /**
     * Build an inclusion proof for the leaf at $index. Returns an ordered list of
     * [side => 'left'|'right', hash => 32 bytes]. A carried (odd) node contributes
     * no sibling at that level.
     */
    public static function proof(array $leaves, int $index): array
    {
        $level = array_values($leaves);
        $proof = [];
        $idx = $index;
        while (count($level) > 1) {
            $next = [];
            $count = count($level);
            for ($i = 0; $i < $count; $i += 2) {
                if ($i + 1 < $count) {
                    $combined = Hasher::sha256($level[$i] . $level[$i + 1]);
                    if ($i === $idx) {
                        $proof[] = ['side' => 'right', 'hash' => $level[$i + 1]];
                    } elseif ($i + 1 === $idx) {
                        $proof[] = ['side' => 'left', 'hash' => $level[$i]];
                    }
                    $next[] = $combined;
                } else {
                    // Odd node carried up; no sibling recorded.
                    $next[] = $level[$i];
                }
            }
            $idx = intdiv($idx, 2);
            $level = $next;
        }
        return $proof;
    }

    /** Verify an inclusion proof produces the expected root. */
    public static function verifyProof(string $leaf, array $proof, string $root): bool
    {
        $acc = $leaf;
        foreach ($proof as $step) {
            $acc = $step['side'] === 'left'
                ? Hasher::sha256($step['hash'] . $acc)
                : Hasher::sha256($acc . $step['hash']);
        }
        return Hasher::equals($acc, $root);
    }
}
