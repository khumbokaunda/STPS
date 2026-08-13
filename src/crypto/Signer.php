<?php
declare(strict_types=1);

/**
 * Signer  --  server-side ECDSA P-256 verification.
 *
 * The server NEVER signs on a user's behalf (build spec section 9, do-not list).
 * Private keys are generated in the browser (WebCrypto), stored non-extractable
 * in IndexedDB, and never transmitted. The server holds only public keys and
 * verifies signatures.
 *
 * Signature format gotcha: WebCrypto produces ECDSA signatures in IEEE P1363
 * form (raw r || s, 64 bytes). PHP openssl_verify expects ASN.1 DER. This class
 * converts P1363 to DER before verification. The conversion is covered by a test
 * vector (tests/vectors/signature.json).
 *
 * What gets verified: to_sign = SHA-256(domain_separator || 0x1F || canonical_json);
 * the raw digest is what ECDSA signs, so we verify using OPENSSL_ALGO with the
 * pre-hashed value via openssl_verify over the message. We reconstruct by verifying
 * the signature over the canonical "to_sign" bytes using SHA-256 again is wrong;
 * instead we verify the ECDSA signature of the 32-byte digest directly.
 */
final class Signer
{
    /**
     * Verify an ECDSA P-256 signature over a 32-byte digest.
     *
     * @param string $digest32    The 32-byte SHA-256 digest that was signed
     *                            (i.e. Hasher::toSign output). WebCrypto signs
     *                            the digest of the data it is given; here the
     *                            client signs the raw digest bytes as its message,
     *                            so we verify the signature of digest32 directly.
     * @param string $signature   Signature bytes, either P1363 (64 bytes) or DER.
     * @param string $publicKeyPem PEM-encoded SPKI public key.
     */
    public static function verifyDigest(string $digest32, string $signature, string $publicKeyPem): bool
    {
        $der = self::toDer($signature);
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }
        // The client computes to_sign = SHA-256(...) and signs THAT 32-byte value
        // as the message with ECDSA/SHA-256, meaning WebCrypto hashes it again.
        // See Signer note: the browser signer (webcrypto-signer.js) signs the
        // 32-byte digest as the message input to sign() with hash SHA-256.
        // Therefore the server must verify with SHA-256 over the same 32-byte
        // message. openssl_verify hashes the message per the chosen algorithm.
        $ok = openssl_verify($digest32, $der, $key, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    /**
     * Convert an IEEE P1363 (raw r||s) ECDSA signature to ASN.1 DER.
     * If the input already looks like DER (starts with 0x30), it is returned
     * unchanged. P-256 signatures are 64 bytes in P1363 form.
     */
    public static function toDer(string $signature): string
    {
        $len = strlen($signature);
        // DER SEQUENCE tag; treat as already-DER only when not exactly 64 bytes.
        if ($len !== 64 && $len > 0 && ord($signature[0]) === 0x30) {
            return $signature;
        }
        if ($len !== 64) {
            throw new InvalidArgumentException(
                'Signer::toDer expected 64-byte P1363 signature, got ' . $len . ' bytes.'
            );
        }
        $r = substr($signature, 0, 32);
        $s = substr($signature, 32, 32);
        return self::derSequenceOfTwoInts($r, $s);
    }

    /** Convert DER ECDSA signature back to 64-byte P1363 (for test round-trips). */
    public static function toP1363(string $der): string
    {
        [$r, $s] = self::parseDerSequence($der);
        $r = self::leftPad32($r);
        $s = self::leftPad32($s);
        return $r . $s;
    }

    private static function derSequenceOfTwoInts(string $r, string $s): string
    {
        $rInt = self::derInteger($r);
        $sInt = self::derInteger($s);
        $body = $rInt . $sInt;
        return "\x30" . self::derLength(strlen($body)) . $body;
    }

    private static function derInteger(string $value): string
    {
        // Strip leading zero bytes but keep at least one.
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        // If high bit set, prepend a 0x00 so it is interpreted as positive.
        if (ord($value[0]) & 0x80) {
            $value = "\x00" . $value;
        }
        return "\x02" . self::derLength(strlen($value)) . $value;
    }

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xFF) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** Parse a DER SEQUENCE { INTEGER r, INTEGER s } into [r, s] raw bytes. */
    private static function parseDerSequence(string $der): array
    {
        $offset = 0;
        if (ord($der[$offset]) !== 0x30) {
            throw new InvalidArgumentException('parseDerSequence: not a SEQUENCE.');
        }
        $offset++;
        [$seqLen, $offset] = self::readDerLength($der, $offset);
        $r = self::readDerInteger($der, $offset);
        $offset = $r[1];
        $s = self::readDerInteger($der, $offset);
        return [$r[0], $s[0]];
    }

    private static function readDerLength(string $der, int $offset): array
    {
        $first = ord($der[$offset]);
        $offset++;
        if (($first & 0x80) === 0) {
            return [$first, $offset];
        }
        $numBytes = $first & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $len = ($len << 8) | ord($der[$offset]);
            $offset++;
        }
        return [$len, $offset];
    }

    private static function readDerInteger(string $der, int $offset): array
    {
        if (ord($der[$offset]) !== 0x02) {
            throw new InvalidArgumentException('readDerInteger: not an INTEGER.');
        }
        $offset++;
        [$len, $offset] = self::readDerLength($der, $offset);
        $value = substr($der, $offset, $len);
        $offset += $len;
        return [$value, $offset];
    }

    private static function leftPad32(string $value): string
    {
        $value = ltrim($value, "\x00");
        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Compute the SHA-256 fingerprint of a DER SPKI public key.
     * @param string $derSpki raw DER bytes of the SubjectPublicKeyInfo.
     */
    public static function fingerprint(string $derSpki): string
    {
        return hash('sha256', $derSpki, true);
    }

    /** Convert a base64 SPKI string (as sent by the browser) to a PEM public key. */
    public static function spkiBase64ToPem(string $base64Spki): string
    {
        $wrapped = chunk_split(trim($base64Spki), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $wrapped . "-----END PUBLIC KEY-----\n";
    }

    /** Raw DER SPKI bytes from a base64 SPKI string. */
    public static function spkiBase64ToDer(string $base64Spki): string
    {
        $der = base64_decode(trim($base64Spki), true);
        if ($der === false) {
            throw new InvalidArgumentException('spkiBase64ToDer: invalid base64.');
        }
        return $der;
    }
}
