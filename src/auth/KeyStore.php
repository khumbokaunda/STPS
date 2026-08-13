<?php
declare(strict_types=1);

require_once __DIR__ . '/../crypto/Signer.php';
require_once __DIR__ . '/../db/Uuid.php';
require_once __DIR__ . '/../db/Clock.php';

/**
 * KeyStore  --  enrollment and lookup of client ECDSA P-256 public keys
 * (build spec section 9). The server stores ONLY public keys; private keys are
 * generated in the browser and never transmitted.
 *
 * The verifier must be able to establish which key was valid at the time an
 * event was signed, so keys carry status and revoked_at, and lookups are by
 * key_id (recorded on every signed row).
 */
final class KeyStore
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Enroll a public key sent as base64 SPKI. Returns the new 16-byte key_id.
     * key_fingerprint = SHA-256(DER SPKI bytes).
     */
    public function enroll(string $userId16, string $spkiBase64): string
    {
        $der = Signer::spkiBase64ToDer($spkiBase64);
        $pem = Signer::spkiBase64ToPem($spkiBase64);
        // Reject anything that is not a loadable EC public key.
        if (openssl_pkey_get_public($pem) === false) {
            throw new InvalidArgumentException('enroll: not a valid public key.');
        }
        $fingerprint = Signer::fingerprint($der);
        $keyId = Uuid::bin();

        $stmt = $this->pdo->prepare(
            'INSERT INTO crypto_keys
                (key_id, user_id, algorithm, public_key, key_fingerprint, status, created_at)
             VALUES (:kid, :uid, :alg, :pub, :fp, "active", :created)'
        );
        $stmt->bindValue(':kid', $keyId, PDO::PARAM_LOB);
        $stmt->bindValue(':uid', $userId16, PDO::PARAM_LOB);
        $stmt->bindValue(':alg', 'ECDSA-P256');
        $stmt->bindValue(':pub', $spkiBase64);
        $stmt->bindValue(':fp', $fingerprint, PDO::PARAM_LOB);
        $stmt->bindValue(':created', Clock::mysql(Clock::now()));
        $stmt->execute();

        return $keyId;
    }

    /** Fetch a key row by id (any status). */
    public function byId(string $keyId16): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT key_id, user_id, algorithm, public_key, status, created_at, revoked_at
             FROM crypto_keys WHERE key_id = :kid LIMIT 1'
        );
        $stmt->bindValue(':kid', $keyId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** The user's currently active key, if any. */
    public function activeKeyForUser(string $userId16): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT key_id, user_id, public_key, created_at
             FROM crypto_keys
             WHERE user_id = :uid AND status = "active"
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->bindValue(':uid', $userId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * PEM public key for a key that belonged to $userId16 and was valid at $at
     * (created and not revoked before $at). Returns null if no such key.
     * This is the check the server uses at signing time and the verifier uses at
     * audit time.
     */
    public function validPublicKeyPem(string $keyId16, string $userId16, DateTimeImmutable $at): ?string
    {
        $row = $this->byId($keyId16);
        if ($row === null) {
            return null;
        }
        if (!hash_equals($row['user_id'], $userId16)) {
            return null;
        }
        $created = Clock::fromMysql($row['created_at']);
        if ($created > $at) {
            return null;
        }
        if ($row['revoked_at'] !== null) {
            $revoked = Clock::fromMysql($row['revoked_at']);
            if ($revoked <= $at) {
                return null;
            }
        }
        return Signer::spkiBase64ToPem($row['public_key']);
    }
}
