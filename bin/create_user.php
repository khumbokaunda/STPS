<?php
declare(strict_types=1);

/**
 * bin/create_user.php  --  provision a user account (accounts are provisioned,
 * not self-registered). Creates a users row with an Argon2id password hash and
 * optionally assigns one or more seeded roles (migration 004) and links a
 * department or bidder organisation.
 *
 * Passwords are hashed with Argon2id via src/auth/Password.php; the plaintext is
 * never stored. Run under an account with INSERT on users/user_roles (proc_app or
 * proc_migrate).
 *
 * Usage:
 *   php bin/create_user.php --username alice --email alice@example.test \
 *       --password 'S3cret!' --role Requisitioner --role Bidder
 *
 *   Optional: --department-code IT-01   (links the user to that department)
 *             --bidder-name "Acme Ltd"  (links the user to that bidder org;
 *                                         creates the bidder if it does not exist)
 *
 * If --password is omitted you will be prompted (input hidden where supported).
 */

$root = dirname(__DIR__);
require_once $root . '/src/db/Db.php';
require_once $root . '/src/db/Uuid.php';
require_once $root . '/src/db/Clock.php';
require_once $root . '/src/auth/Password.php';

// ---- parse arguments ----
$opts = ['role' => []];
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $key = substr($arg, 2);
    $val = $argv[$i + 1] ?? '';
    $i++;
    if ($key === 'role') {
        $opts['role'][] = $val;
    } else {
        $opts[$key] = $val;
    }
}

$username = $opts['username'] ?? null;
$email = $opts['email'] ?? null;
if ($username === null || $email === null) {
    fwrite(STDERR, "Required: --username and --email. See the header of this file for usage.\n");
    exit(2);
}

$password = $opts['password'] ?? null;
if ($password === null || $password === '') {
    fwrite(STDOUT, 'Password: ');
    // Best-effort hidden input on *nix; plain on Windows.
    if (stripos(PHP_OS, 'WIN') === false) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $password = trim((string) fgets(STDIN));
    if (stripos(PHP_OS, 'WIN') === false) {
        shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Refusing to set a password shorter than 8 characters.\n");
    exit(2);
}

$configPath = file_exists($root . '/config/config.php')
    ? $root . '/config/config.php'
    : $root . '/config/config.example.php';
$config = require $configPath;
$pdo = Db::connect($config['db']);

try {
    $pdo->beginTransaction();

    // Optional department link.
    $departmentId = null;
    if (!empty($opts['department-code'])) {
        $st = $pdo->prepare('SELECT department_id FROM departments WHERE code = :c LIMIT 1');
        $st->bindValue(':c', $opts['department-code']);
        $st->execute();
        $departmentId = $st->fetchColumn();
        if ($departmentId === false) {
            throw new RuntimeException("No department with code '{$opts['department-code']}'.");
        }
    }

    // Optional bidder link (create the org if missing).
    $bidderId = null;
    if (!empty($opts['bidder-name'])) {
        $st = $pdo->prepare('SELECT bidder_id FROM bidders WHERE legal_name = :n LIMIT 1');
        $st->bindValue(':n', $opts['bidder-name']);
        $st->execute();
        $bidderId = $st->fetchColumn();
        if ($bidderId === false) {
            $bidderId = Uuid::bin();
            $ins = $pdo->prepare('INSERT INTO bidders (bidder_id, legal_name, verification_status, created_at) VALUES (:i,:n,"verified",:t)');
            $ins->bindValue(':i', $bidderId, PDO::PARAM_LOB);
            $ins->bindValue(':n', $opts['bidder-name']);
            $ins->bindValue(':t', Clock::mysql(Clock::now()));
            $ins->execute();
            fwrite(STDOUT, "Created bidder org '{$opts['bidder-name']}'.\n");
        }
    }

    // Create the user.
    $userId = Uuid::bin();
    $st = $pdo->prepare(
        'INSERT INTO users (user_id, username, email, department_id, bidder_id, password_hash, status, created_at)
         VALUES (:id, :u, :e, :dept, :bidder, :ph, "active", :t)'
    );
    $st->bindValue(':id', $userId, PDO::PARAM_LOB);
    $st->bindValue(':u', $username);
    $st->bindValue(':e', $email);
    $st->bindValue(':dept', $departmentId, $departmentId === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(':bidder', $bidderId, $bidderId === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(':ph', Password::hash($password));
    $st->bindValue(':t', Clock::mysql(Clock::now()));
    $st->execute();

    // Assign roles.
    foreach ($opts['role'] as $roleName) {
        $rs = $pdo->prepare('SELECT role_id FROM roles WHERE name = :n LIMIT 1');
        $rs->bindValue(':n', $roleName);
        $rs->execute();
        $roleId = $rs->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException("No role named '{$roleName}'. Apply migration 004_seed_roles.sql first.");
        }
        $ar = $pdo->prepare('INSERT INTO user_roles (user_role_id, user_id, role_id, valid_from, status) VALUES (:i,:u,:r,:f,"active")');
        $ar->bindValue(':i', Uuid::bin(), PDO::PARAM_LOB);
        $ar->bindValue(':u', $userId, PDO::PARAM_LOB);
        $ar->bindValue(':r', $roleId, PDO::PARAM_LOB);
        $ar->bindValue(':f', Clock::mysql(Clock::now()));
        $ar->execute();
    }

    $pdo->commit();

    fwrite(STDOUT, "Created user '{$username}' (user_id " . bin2hex($userId) . ").\n");
    if ($opts['role']) {
        fwrite(STDOUT, "Roles: " . implode(', ', $opts['role']) . "\n");
    }
    fwrite(STDOUT, "You can now sign in with that username and password.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
