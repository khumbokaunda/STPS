<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/crypto/MerkleTree.php';
require_once __DIR__ . '/../../src/crypto/Hasher.php';

/**
 * Merkle root vectors, including the odd-node carry-up rule. The verifier must
 * reproduce these exactly.
 */
$failures = 0;

function h(string $s): string { return hash('sha256', $s, true); }

// Single leaf: root == leaf.
$l0 = h('a');
if (MerkleTree::root([$l0]) === $l0) {
    echo "[ok] single leaf root == leaf\n";
} else { $failures++; fwrite(STDERR, "[FAIL] single leaf\n"); }

// Two leaves: root == SHA256(l0||l1).
$l1 = h('b');
$expect2 = h($l0 . $l1);
if (MerkleTree::root([$l0, $l1]) === $expect2) {
    echo "[ok] two-leaf root\n";
} else { $failures++; fwrite(STDERR, "[FAIL] two-leaf\n"); }

// Three leaves (odd): level1 = [H(l0||l1), l2 carried]; root = H(level1_0 || l2).
$l2 = h('c');
$n01 = h($l0 . $l1);
$expect3 = h($n01 . $l2);
if (MerkleTree::root([$l0, $l1, $l2]) === $expect3) {
    echo "[ok] three-leaf odd-node carry-up root\n";
} else { $failures++; fwrite(STDERR, "[FAIL] three-leaf odd node\n"); }

// Five leaves: exercises carry-up at two levels.
$leaves = array_map('h', ['a', 'b', 'c', 'd', 'e']);
$n01b = h($leaves[0] . $leaves[1]);
$n23 = h($leaves[2] . $leaves[3]);
// level1 = [n01b, n23, e]; level2 = [H(n01b||n23), e]; root = H(level2_0 || e)
$lvl2_0 = h($n01b . $n23);
$expect5 = h($lvl2_0 . $leaves[4]);
if (MerkleTree::root($leaves) === $expect5) {
    echo "[ok] five-leaf multi-level carry-up root\n";
} else { $failures++; fwrite(STDERR, "[FAIL] five-leaf\n"); }

// Inclusion proofs verify for every index.
foreach ([0, 1, 2, 3, 4] as $idx) {
    $proof = MerkleTree::proof($leaves, $idx);
    if (MerkleTree::verifyProof($leaves[$idx], $proof, $expect5)) {
        echo "[ok] inclusion proof index {$idx}\n";
    } else { $failures++; fwrite(STDERR, "[FAIL] proof index {$idx}\n"); }
}

if ($failures > 0) { fwrite(STDERR, "merkle: {$failures} failure(s)\n"); exit(1); }
echo "merkle: all passed\n";
