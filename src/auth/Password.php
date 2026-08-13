<?php
declare(strict_types=1);

/**
 * Password  --  Argon2id password hashing (build spec sections 4, 6).
 *
 * Never hash passwords with SHA-256 or any fast hash. Argon2id only. Store the
 * full encoded hash string returned by password_hash. Minimum parameters:
 * memory_cost 65536 KiB, time_cost 4, threads 1, or stronger.
 */
final class Password
{
    private const OPTIONS = [
        'memory_cost' => 65536, // KiB
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    public static function hash(string $plaintext): string
    {
        $hash = password_hash($plaintext, PASSWORD_ARGON2ID, self::OPTIONS);
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public static function verify(string $plaintext, string $encodedHash): bool
    {
        return password_verify($plaintext, $encodedHash);
    }

    /** True if the stored hash should be re-computed with current parameters. */
    public static function needsRehash(string $encodedHash): bool
    {
        return password_needs_rehash($encodedHash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
