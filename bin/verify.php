<?php
declare(strict_types=1);

/**
 * bin/verify.php  --  independent auditor CLI (build spec section 16).
 *
 * An auditor runs this with READ-ONLY credentials (or against an export). It must
 * not depend on any application write path. It performs, in order, and reports
 * the first failure with the exact sequence_no and entity:
 *
 *   1. Recompute every payload_hash from canonical_payload; compare.
 *   2. Recompute every entry_hash; compare.
 *   3. Chain linkage: prev_entry_hash == previous entry_hash.
 *   4. Sequence continuity 1..N, no gaps.
 *   5. Verify actor signatures against the key valid at created_at.
 *   6. Rebuild every batch Merkle root; compare.
 *   7. Verify RFC 3161 tokens against the batch root (bundled TSA certs).
 *   8. Recompute each revealed bid commitment; compare.
 *   9. Recompute committee decision outcomes from linked signed votes.
 *  10. Check timing invariants.
 *  11. Check authorization at decision time.
 *  12. Produce a report ending in a single PASS, or FAIL with the first break.
 *
 * The verifier passing while trusting no application code is the headline result.
 *
 * Usage: php bin/verify.php            (uses verifier_db read-only credentials)
 *        php bin/verify.php --json     (machine-readable output)
 */

require_once __DIR__ . '/../src/db/Db.php';
require_once __DIR__ . '/../src/crypto/TsaClient.php';
require_once __DIR__ . '/../src/ledger/Verifier.php';

$json = in_array('--json', $argv, true);

$configPath = file_exists(__DIR__ . '/../config/config.php')
    ? __DIR__ . '/../config/config.php'
    : __DIR__ . '/../config/config.example.php';
$config = require $configPath;

// Read-only connection (proc_verify). The verifier trusts no write credential.
$pdo = Db::connect($config['verifier_db']);

// Only supply a TsaClient if the bundled certificates exist; otherwise token
// verification is skipped with a note (the chain and Merkle checks still run).
$tsa = null;
if (is_readable($config['tsa']['ca_file']) && is_readable($config['tsa']['tsa_cert'])) {
    $tsa = new TsaClient($config['tsa']);
}

$verifier = new Verifier($pdo, $tsa);
$result = $verifier->run();

if ($json) {
    echo json_encode([
        'verdict'  => $result->pass ? 'PASS' : 'FAIL',
        'failures' => $result->failures,
        'notes'    => $result->notes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($result->pass ? 0 : 1);
}

echo "==============================================\n";
echo " Secure Procurement  --  Independent Verifier\n";
echo "==============================================\n";
echo "Auditor trusts: external TSA anchor + public keys only.\n";
echo "Auditor does NOT trust: the application server or its write credentials.\n\n";

foreach ($result->notes as $note) {
    echo "  note: {$note}\n";
}
if ($result->notes) {
    echo "\n";
}

if ($result->pass) {
    echo "Result: PASS\n";
    echo "All ledger entries reproduce, the chain is intact and gapless, every\n";
    echo "recorded signature and commitment verifies, and every anchored Merkle\n";
    echo "root matches. No unauthorized historical modification detected.\n";
    exit(0);
}

echo "Result: FAIL\n";
$first = $result->failures[0];
echo sprintf(
    "First detected break:\n  check:       %s\n  sequence_no: %s\n  entity:      %s\n  detail:      %s\n",
    $first['check'],
    $first['sequence_no'] === null ? '(n/a)' : $first['sequence_no'],
    $first['entity'] ?? '(n/a)',
    $first['detail']
);
if (count($result->failures) > 1) {
    echo "\n(" . (count($result->failures) - 1) . " further issue(s) suppressed; fix the first and re-run.)\n";
}
exit(1);
