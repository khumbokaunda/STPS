/*
 * Generates tests/vectors/signature.json using WebCrypto exactly as the browser
 * client does: an ECDSA P-256 key, a signature over a 32-byte to_sign digest in
 * IEEE P1363 form. The PHP test (test_signature.php) converts P1363 -> DER and
 * verifies with openssl, proving cross-language signature interop.
 */
import { webcrypto as crypto } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

function b64(buf) {
  return Buffer.from(buf).toString('base64');
}
function hex(buf) {
  return Buffer.from(buf).toString('hex');
}

const keyPair = await crypto.subtle.generateKey(
  { name: 'ECDSA', namedCurve: 'P-256' },
  true,
  ['sign', 'verify']
);

const spki = new Uint8Array(await crypto.subtle.exportKey('spki', keyPair.publicKey));

// The client signs a 32-byte "to_sign" digest as the message input, with hash
// SHA-256 (WebCrypto hashes it again). The server verifies openssl_verify over
// the same 32-byte message with OPENSSL_ALGO_SHA256.
const toSign = new Uint8Array(32);
for (let i = 0; i < 32; i++) toSign[i] = (i * 7 + 3) & 0xff;

const sigP1363 = new Uint8Array(
  await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, keyPair.privateKey, toSign)
);

const out = {
  description: 'ECDSA P-256 signature over a 32-byte to_sign digest, WebCrypto P1363 form.',
  spki_base64: b64(spki),
  to_sign_hex: hex(toSign),
  signature_p1363_base64: b64(sigP1363),
  signature_p1363_len: sigP1363.length
};

writeFileSync(join(__dirname, 'signature.json'), JSON.stringify(out, null, 2) + '\n');
console.log('wrote signature.json (p1363 len ' + sigP1363.length + ')');
