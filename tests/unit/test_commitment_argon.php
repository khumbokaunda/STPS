<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/crypto/Commitment.php';
require_once __DIR__ . '/../../src/crypto/Canonicalizer.php';
require_once __DIR__ . '/../../src/crypto/Hasher.php';
require_once __DIR__ . '/../../src/auth/Password.php';

$failures = 0;

// Commitment determinism and sensitivity.
$rfq = random_bytes(16);
$bidder = random_bytes(16);
$bid = Canonicalizer::encode((object) [
    'schema' => 'PROCUREMENT-BID-COMMITMENT-V1',
    'amount' => Canonicalizer::decimalString('98000000', 2),
    'currency' => 'MWK',
]);
$nonce = random_bytes(32);

$c1 = Commitment::compute($rfq, $bidder, $bid, $nonce);
$c2 = Commitment::compute($rfq, $bidder, $bid, $nonce);
if ($c1 === $c2 && strlen($c1) === 32) {
    echo "[ok] commitment deterministic, 32 bytes\n";
} else { $failures++; fwrite(STDERR, "[FAIL] commitment determinism\n"); }

// Different nonce -> different commitment (hiding).
$c3 = Commitment::compute($rfq, $bidder, $bid, random_bytes(32));
if ($c3 !== $c1) {
    echo "[ok] commitment changes with nonce (hiding)\n";
} else { $failures++; fwrite(STDERR, "[FAIL] commitment nonce sensitivity\n"); }

// Different bid -> different commitment (binding).
$bid2 = Canonicalizer::encode((object) [
    'schema' => 'PROCUREMENT-BID-COMMITMENT-V1',
    'amount' => Canonicalizer::decimalString('98000001', 2),
    'currency' => 'MWK',
]);
$c4 = Commitment::compute($rfq, $bidder, $bid2, $nonce);
if ($c4 !== $c1) {
    echo "[ok] commitment changes with bid (binding)\n";
} else { $failures++; fwrite(STDERR, "[FAIL] commitment binding\n"); }

// Argon2id round trip and algorithm check.
$hash = Password::hash('correct horse battery staple');
if (str_starts_with($hash, '$argon2id$')) {
    echo "[ok] password hash is argon2id\n";
} else { $failures++; fwrite(STDERR, "[FAIL] not argon2id: {$hash}\n"); }

if (Password::verify('correct horse battery staple', $hash)) {
    echo "[ok] argon2id verify accepts correct password\n";
} else { $failures++; fwrite(STDERR, "[FAIL] argon2id verify rejected correct password\n"); }

if (!Password::verify('wrong password', $hash)) {
    echo "[ok] argon2id verify rejects wrong password\n";
} else { $failures++; fwrite(STDERR, "[FAIL] argon2id accepted wrong password\n"); }

if ($failures > 0) { fwrite(STDERR, "commitment/argon: {$failures} failure(s)\n"); exit(1); }
echo "commitment/argon: all passed\n";
