<?php
declare(strict_types=1);

/**
 * Test runner. Executes PHP unit tests, the node canonicalization parity test,
 * and (when a database is configured via env) the integration and adversarial
 * suites. Exits non-zero if any test fails.
 *
 * Usage: php tests/run.php [--with-db]
 */
$root = dirname(__DIR__);
$withDb = in_array('--with-db', $argv, true);

$phpUnit = [
    'unit/test_canonical.php',
    'unit/test_signature.php',
    'unit/test_merkle.php',
    'unit/test_commitment_argon.php',
    'unit/test_ledger_hash.php',
];

$failed = [];

// Regenerate the signature vector if node is available (keeps it fresh).
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    passthru('node ' . escapeshellarg("$root/tests/vectors/gen_signature_vector.mjs"), $rc);
}

foreach ($phpUnit as $t) {
    $path = "$root/tests/$t";
    if (!file_exists($path)) {
        continue;
    }
    echo "\n=== php $t ===\n";
    passthru('php ' . escapeshellarg($path), $rc);
    if ($rc !== 0) {
        $failed[] = $t;
    }
}

if ($node !== '') {
    echo "\n=== node unit/test_canonical.mjs ===\n";
    passthru('node ' . escapeshellarg("$root/tests/unit/test_canonical.mjs"), $rc);
    if ($rc !== 0) {
        $failed[] = 'unit/test_canonical.mjs';
    }
}

if ($withDb) {
    foreach (['integration/test_full_run.php', 'adversarial/test_adversarial.php'] as $t) {
        $path = "$root/tests/$t";
        if (!file_exists($path)) {
            continue;
        }
        echo "\n=== php $t ===\n";
        passthru('php ' . escapeshellarg($path), $rc);
        if ($rc !== 0) {
            $failed[] = $t;
        }
    }
} else {
    echo "\n(skipping db-backed integration/adversarial tests; pass --with-db to run)\n";
}

echo "\n========================================\n";
if ($failed) {
    echo "FAILED: " . implode(', ', $failed) . "\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
