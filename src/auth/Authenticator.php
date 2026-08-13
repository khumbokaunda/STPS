<?php
declare(strict_types=1);

require_once __DIR__ . '/Password.php';
require_once __DIR__ . '/../db/Clock.php';

/**
 * Authenticator  --  password authentication with Argon2id (build spec section
 * 6). A successful login is authentication only; it is never a substitute for a
 * client signature on approvals, scores, bids, or reveals (build spec section 9).
 */
final class Authenticator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Verify credentials. Returns the 16-byte user_id on success, or null on
     * failure. Uses a constant-ish path (always runs a verify) to reduce user
     * enumeration via timing.
     */
    public function authenticate(string $username, string $plaintext): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, password_hash, status FROM users WHERE username = :u LIMIT 1'
        );
        $stmt->bindValue(':u', $username);
        $stmt->execute();
        $row = $stmt->fetch();

        // Dummy hash keeps timing similar when the user does not exist.
        $hash = $row['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$'
            . base64_encode(random_bytes(16)) . '$' . base64_encode(random_bytes(32));

        $ok = Password::verify($plaintext, $hash);
        if ($row === false || !$ok || ($row['status'] ?? '') !== 'active') {
            return null;
        }

        // Opportunistic rehash if parameters changed.
        if (Password::needsRehash($row['password_hash'])) {
            $newHash = Password::hash($plaintext);
            $upd = $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id');
            $upd->bindValue(':h', $newHash);
            $upd->bindValue(':id', $row['user_id'], PDO::PARAM_LOB);
            $upd->execute();
        }

        return $row['user_id'];
    }
}
