<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/crypto/Signer.php';

/**
 * Verifies the WebCrypto-produced signature vector (P1363) against its SPKI key,
 * proving the P1363 -> DER conversion and openssl verification path. Also checks
 * a P1363 -> DER -> P1363 round trip.
 */
$vectorFile = __DIR__ . '/../vectors/signature.json';
if (!file_exists($vectorFile)) {
    fwrite(STDERR, "signature.json missing; run gen_signature_vector.mjs first\n");
    exit(1);
}
$v = json_decode(file_get_contents($vectorFile), true, 512, JSON_THROW_ON_ERROR);

$failures = 0;

$pem = Signer::spkiBase64ToPem($v['spki_base64']);
$toSign = hex2bin($v['to_sign_hex']);
$sigP1363 = base64_decode($v['signature_p1363_base64'], true);

if (strlen($sigP1363) !== 64) {
    $failures++;
    fwrite(STDERR, "[FAIL] expected 64-byte P1363 sig, got " . strlen($sigP1363) . "\n");
}

// Verify with the P1363 signature (Signer converts to DER internally).
if (Signer::verifyDigest($toSign, $sigP1363, $pem)) {
    echo "[ok] P1363 signature verifies via openssl (DER conversion works)\n";
} else {
    $failures++;
    fwrite(STDERR, "[FAIL] P1363 signature did not verify\n");
}

// Tampered digest must fail.
$tampered = $toSign;
$tampered[0] = chr(ord($tampered[0]) ^ 0x01);
if (!Signer::verifyDigest($tampered, $sigP1363, $pem)) {
    echo "[ok] tampered digest correctly rejected\n";
} else {
    $failures++;
    fwrite(STDERR, "[FAIL] tampered digest was accepted\n");
}

// P1363 -> DER -> P1363 round trip.
$der = Signer::toDer($sigP1363);
$back = Signer::toP1363($der);
if ($back === $sigP1363) {
    echo "[ok] P1363 -> DER -> P1363 round trip\n";
} else {
    $failures++;
    fwrite(STDERR, "[FAIL] P1363 round trip mismatch\n");
}

// fingerprint of SPKI DER
$der2 = Signer::spkiBase64ToDer($v['spki_base64']);
$fp = Signer::fingerprint($der2);
if (strlen($fp) === 32) {
    echo "[ok] SPKI fingerprint is 32 bytes\n";
} else {
    $failures++;
    fwrite(STDERR, "[FAIL] fingerprint wrong length\n");
}

if ($failures > 0) {
    fwrite(STDERR, "signature: {$failures} failure(s)\n");
    exit(1);
}
echo "signature: all passed\n";
