<?php
declare(strict_types=1);

require_once __DIR__ . '/../crypto/Hasher.php';
require_once __DIR__ . '/../crypto/Signer.php';
require_once __DIR__ . '/../crypto/DomainSeparators.php';

/**
 * SignedEvidence  --  verify a real client signature over a canonical payload
 * (build spec section 9). The server NEVER signs on a user's behalf; a logged-in
 * click is authentication, not a signature. Approvals, scores, bids, and reveals
 * require a real client signature.
 *
 * to_sign = SHA-256( domain_separator || 0x1F || canonical_json )
 * The client signs to_sign as the message with WebCrypto ECDSA/SHA-256; the
 * server verifies via Signer::verifyDigest (which converts P1363 -> DER).
 */
final class SignedEvidence
{
    private KeyStore $keys;

    public function __construct(KeyStore $keys)
    {
        $this->keys = $keys;
    }

    /** The digest the client signed for a payload of the given domain separator. */
    public static function toSign(string $domainSeparator, string $canonicalJson): string
    {
        if (!DomainSeparators::isValid($domainSeparator)) {
            throw new InvalidArgumentException('SignedEvidence: unknown domain separator.');
        }
        return Hasher::toSign($domainSeparator, $canonicalJson);
    }

    /**
     * Verify that $signature over the canonical payload was produced by a key
     * ($keyId16) that belonged to $userId16 and was valid at $at. Returns the
     * 32-byte payload_hash on success; throws on any failure.
     */
    public function verify(
        string $domainSeparator,
        string $canonicalJson,
        string $signature,
        string $keyId16,
        string $userId16,
        DateTimeImmutable $at
    ): string {
        $pem = $this->keys->validPublicKeyPem($keyId16, $userId16, $at);
        if ($pem === null) {
            throw new RuntimeException('SignedEvidence: no valid key for signer at that time.');
        }
        $digest = self::toSign($domainSeparator, $canonicalJson);
        if (!Signer::verifyDigest($digest, $signature, $pem)) {
            throw new RuntimeException('SignedEvidence: signature verification failed.');
        }
        return Hasher::sha256($canonicalJson);
    }
}
