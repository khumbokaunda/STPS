<?php
declare(strict_types=1);

/**
 * bin/anchor.php  --  scheduled Merkle batching and external anchoring
 * (build spec section 11). Run on a fixed interval (default every 10 minutes) or
 * triggered by entry count.
 *
 *   1. Select ledger entries since the last batch [from_sequence, to_sequence].
 *   2. Build a Merkle tree over the ordered entry_hash leaves (odd node carried up).
 *   3. Insert a ledger_batches row with the range and merkle_root.
 *   4. Build an RFC 3161 query over the root, POST to FreeTSA, store the DER token
 *      in merkle_anchors (anchor_type RFC3161).
 *
 * Anchoring frequency is the exposure window: anything not yet anchored is still
 * malleable by an insider. Shorter interval, smaller window.
 *
 * Usage: php bin/anchor.php
 */

require_once __DIR__ . '/../src/db/Db.php';
require_once __DIR__ . '/../src/db/Uuid.php';
require_once __DIR__ . '/../src/db/Clock.php';
require_once __DIR__ . '/../src/crypto/MerkleTree.php';
require_once __DIR__ . '/../src/crypto/TsaClient.php';
require_once __DIR__ . '/../src/ledger/LedgerReader.php';

$configPath = file_exists(__DIR__ . '/../config/config.php')
    ? __DIR__ . '/../config/config.php'
    : __DIR__ . '/../config/config.example.php';
$config = require $configPath;

$pdo = Db::connect($config['db']);
Db::setConnection($pdo);
$reader = new LedgerReader($pdo);

// 1. Determine the next range.
$lastTo = (int) ($pdo->query('SELECT COALESCE(MAX(to_sequence), 0) AS t FROM ledger_batches')->fetch()['t']);
$head = $reader->headSequence();
if ($head <= $lastTo) {
    fwrite(STDOUT, "anchor: nothing new to anchor (head={$head}, lastTo={$lastTo}).\n");
    exit(0);
}
$fromSeq = $lastTo + 1;
$maxBatch = (int) ($config['anchor']['max_batch_entries'] ?? 1024);
$toSeq = min($head, $fromSeq + $maxBatch - 1);

// 2. Build the Merkle tree over ordered entry_hash leaves.
$rows = $reader->range($fromSeq, $toSeq);
$leaves = array_map(static fn($r) => $r['entry_hash'], $rows);
$root = MerkleTree::root($leaves);

$now = Clock::now();
$batchId = Uuid::bin();

// 3. Insert the batch row.
$stmt = $pdo->prepare(
    'INSERT INTO ledger_batches (batch_id, from_sequence, to_sequence, merkle_root, created_at, status)
     VALUES (:id, :from, :to, :root, :at, "created")'
);
$stmt->bindValue(':id', $batchId, PDO::PARAM_LOB);
$stmt->bindValue(':from', $fromSeq, PDO::PARAM_INT);
$stmt->bindValue(':to', $toSeq, PDO::PARAM_INT);
$stmt->bindValue(':root', $root, PDO::PARAM_LOB);
$stmt->bindValue(':at', Clock::mysql($now));
$stmt->execute();

fwrite(STDOUT, sprintf("anchor: batch %s covers [%d..%d], root=%s\n",
    Uuid::toString($batchId), $fromSeq, $toSeq, bin2hex($root)));

// 4. Anchor the root with FreeTSA (RFC 3161).
$anchorId = Uuid::bin();
$anchorStatus = 'pending';
$token = null;
$anchoredAt = null;
try {
    $tsa = new TsaClient($config['tsa']);
    $token = $tsa->timestamp($root);
    $anchorStatus = $tsa->verifyToken($token, $root) ? 'verified' : 'pending';
    $anchoredAt = Clock::mysql($now);
    fwrite(STDOUT, "anchor: FreeTSA token obtained ({$anchorStatus}).\n");
} catch (Throwable $e) {
    // Anchoring failure leaves the batch 'created' and the anchor 'pending'; a
    // later run can retry. We do NOT fabricate a token.
    fwrite(STDERR, "anchor: TSA anchoring failed: {$e->getMessage()}\n");
}

$aStmt = $pdo->prepare(
    'INSERT INTO merkle_anchors (anchor_id, batch_id, anchor_type, merkle_root, timestamp_token, anchored_at, verification_status)
     VALUES (:id, :batch, "RFC3161", :root, :token, :at, :vs)'
);
$aStmt->bindValue(':id', $anchorId, PDO::PARAM_LOB);
$aStmt->bindValue(':batch', $batchId, PDO::PARAM_LOB);
$aStmt->bindValue(':root', $root, PDO::PARAM_LOB);
$aStmt->bindValue(':token', $token, $token === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
$aStmt->bindValue(':at', $anchoredAt, $anchoredAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$aStmt->bindValue(':vs', $anchorStatus);
$aStmt->execute();

if ($token !== null) {
    $upd = $pdo->prepare('UPDATE ledger_batches SET status = "anchored" WHERE batch_id = :id');
    $upd->bindValue(':id', $batchId, PDO::PARAM_LOB);
    $upd->execute();
}

fwrite(STDOUT, "anchor: done.\n");
exit(0);
