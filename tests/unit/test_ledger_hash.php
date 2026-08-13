<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ledger/LedgerHash.php';
require_once __DIR__ . '/../../src/crypto/Hasher.php';

/**
 * Ledger entry_hash construction: genesis uses 32 zero bytes for prev; linkage
 * chains one entry to the next; any field change alters the hash.
 */
$failures = 0;

$actor = str_repeat("\x11", 16);
$entity = str_repeat("\x22", 16);
$payloadHash = hash('sha256', 'payload-1', true);
$created = '2026-08-13T07:30:00.000Z';

// Genesis entry (seq 1, prev null == zeros).
$h1 = LedgerHash::compute(1, null, $actor, 'REQUISITION_SUBMITTED', 'requisition', $entity, $payloadHash, $created, 'PROCUREMENT-LEDGER-V1');

// Recompute manually with explicit zero prev to confirm null == zeros.
$h1b = LedgerHash::compute(1, LedgerHash::ZERO32, $actor, 'REQUISITION_SUBMITTED', 'requisition', $entity, $payloadHash, $created, 'PROCUREMENT-LEDGER-V1');
if ($h1 === $h1b && strlen($h1) === 32) {
    echo "[ok] genesis prev null equals 32 zero bytes\n";
} else { $failures++; fwrite(STDERR, "[FAIL] genesis zero-prev\n"); }

// Second entry links to first.
$payloadHash2 = hash('sha256', 'payload-2', true);
$h2 = LedgerHash::compute(2, $h1, $actor, 'APPROVAL_RECORDED', 'requisition', $entity, $payloadHash2, '2026-08-13T08:00:00.000Z', 'PROCUREMENT-LEDGER-V1');

// Changing the parent hash must change the entry hash (tamper sensitivity).
$forkedParent = $h1;
$forkedParent[0] = chr(ord($forkedParent[0]) ^ 0x01);
$h2Forked = LedgerHash::compute(2, $forkedParent, $actor, 'APPROVAL_RECORDED', 'requisition', $entity, $payloadHash2, '2026-08-13T08:00:00.000Z', 'PROCUREMENT-LEDGER-V1');
if ($h2 !== $h2Forked) {
    echo "[ok] entry_hash depends on prev_entry_hash (chain linkage)\n";
} else { $failures++; fwrite(STDERR, "[FAIL] chain linkage not sensitive\n"); }

// Changing the payload hash must change entry hash.
$h2Alt = LedgerHash::compute(2, $h1, $actor, 'APPROVAL_RECORDED', 'requisition', $entity, hash('sha256', 'tampered', true), '2026-08-13T08:00:00.000Z', 'PROCUREMENT-LEDGER-V1');
if ($h2 !== $h2Alt) {
    echo "[ok] entry_hash depends on payload_hash\n";
} else { $failures++; fwrite(STDERR, "[FAIL] payload hash not bound\n"); }

if ($failures > 0) { fwrite(STDERR, "ledger_hash: {$failures} failure(s)\n"); exit(1); }
echo "ledger_hash: all passed\n";
