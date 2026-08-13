<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/crypto/Canonicalizer.php';

/**
 * Canonicalization vectors (PHP side). Asserts the PHP canonicalizer reproduces
 * the shared 'canonical' string for every vector. JS parity is checked by the
 * companion node runner tests/unit/test_canonical.mjs; the harness runs both.
 */
$vectorsFile = __DIR__ . '/../vectors/canonical.json';
// Decode with objects preserved (not associative) so {} is a stdClass object and
// [] is a list, removing PHP's empty-array/empty-object ambiguity.
$data = json_decode(file_get_contents($vectorsFile), false, 512, JSON_THROW_ON_ERROR);

$failures = 0;
foreach ($data->vectors as $v) {
    // The canonicalizer must sort keys regardless of input order.
    $got = Canonicalizer::encode($v->input);
    $want = $v->canonical;
    if ($got !== $want) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$v->name}\n  want: {$want}\n  got:  {$got}\n");
    } else {
        echo "[ok] canonical: {$v->name}\n";
    }
}

// decimalString checks
$dec = [
    ['125000000', 2, '125000000.00'],
    ['3', 4, '3.0000'],
    ['0.5', 2, '0.50'],
    ['10.999', 2, '10.99'],
    ['-42.1', 2, '-42.10'],
    ['-0.00', 2, '0.00'],
];
foreach ($dec as [$in, $scale, $exp]) {
    $out = Canonicalizer::decimalString($in, $scale);
    if ($out !== $exp) {
        $failures++;
        fwrite(STDERR, "[FAIL] decimalString({$in},{$scale}) want {$exp} got {$out}\n");
    } else {
        echo "[ok] decimalString {$in}/{$scale} = {$out}\n";
    }
}

// uuid hex round trip
$raw = random_bytes(16);
if (Canonicalizer::hexToUuid(Canonicalizer::uuidToHex($raw)) !== $raw) {
    $failures++;
    fwrite(STDERR, "[FAIL] uuid hex round trip\n");
} else {
    echo "[ok] uuid hex round trip\n";
}

if ($failures > 0) {
    fwrite(STDERR, "canonical: {$failures} failure(s)\n");
    exit(1);
}
echo "canonical: all passed\n";
