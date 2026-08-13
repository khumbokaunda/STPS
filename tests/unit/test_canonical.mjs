/*
 * Canonicalization vectors (JS side). Loads the browser canonicalizer under a
 * minimal window shim and asserts it reproduces the shared 'canonical' string
 * for every vector, matching the PHP runner byte for byte.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { webcrypto } from 'node:crypto';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');

// Minimal browser shims so canonicalizer.js (an IIFE assigning to window) loads.
globalThis.window = globalThis;
globalThis.TextEncoder = TextEncoder;
if (!globalThis.crypto) globalThis.crypto = webcrypto;

const src = readFileSync(join(root, 'public/assets/js/canonicalizer.js'), 'utf8');
// eslint-disable-next-line no-eval
(0, eval)(src);
const C = globalThis.window.Canonicalizer;

const vectors = JSON.parse(
  readFileSync(join(root, 'tests/vectors/canonical.json'), 'utf8')
).vectors;

let failures = 0;
for (const v of vectors) {
  const got = C.encodeString(v.input);
  if (got !== v.canonical) {
    failures++;
    process.stderr.write(`[FAIL] ${v.name}\n  want: ${v.canonical}\n  got:  ${got}\n`);
  } else {
    console.log(`[ok] canonical(js): ${v.name}`);
  }
}

const dec = [
  ['125000000', 2, '125000000.00'],
  ['3', 4, '3.0000'],
  ['0.5', 2, '0.50'],
  ['10.999', 2, '10.99'],
  ['-42.1', 2, '-42.10'],
  ['-0.00', 2, '0.00'],
];
for (const [inp, scale, exp] of dec) {
  const out = C.decimalString(inp, scale);
  if (out !== exp) {
    failures++;
    process.stderr.write(`[FAIL] decimalString(${inp},${scale}) want ${exp} got ${out}\n`);
  } else {
    console.log(`[ok] decimalString(js) ${inp}/${scale} = ${out}`);
  }
}

if (failures > 0) {
  process.stderr.write(`canonical(js): ${failures} failure(s)\n`);
  process.exit(1);
}
console.log('canonical(js): all passed');
