<?php
declare(strict_types=1);

/**
 * Example configuration. Copy to config/config.php (which is git-ignored and must
 * live OUTSIDE the web root in production) and supply values via environment
 * variables. No secrets are committed to the repository (build spec section 19).
 *
 * The runtime uses the proc_app account. The verifier uses proc_verify. Migrations
 * use proc_migrate. See migrations/002_privileges.sql.
 */
return [
    'db' => [
        'dsn'      => getenv('STPS_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=secure_procurement;charset=utf8mb4',
        'user'     => getenv('STPS_DB_USER') ?: 'proc_app',
        'password' => getenv('STPS_DB_PASSWORD') ?: '',
    ],
    'verifier_db' => [
        'dsn'      => getenv('STPS_VERIFY_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=secure_procurement;charset=utf8mb4',
        'user'     => getenv('STPS_VERIFY_USER') ?: 'proc_verify',
        'password' => getenv('STPS_VERIFY_PASSWORD') ?: '',
    ],
    'session' => [
        'name'            => 'STPSSESS',
        'idle_timeout'    => 900,    // 15 minutes
        'absolute_timeout' => 28800, // 8 hours
        'cookie_secure'   => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ],
    'tsa' => [
        // FreeTSA RFC 3161 endpoint (primary anchor).
        'url'          => getenv('STPS_TSA_URL') ?: 'https://freetsa.org/tsr',
        'ca_file'      => __DIR__ . '/certs/freetsa_cacert.pem',
        'tsa_cert'     => __DIR__ . '/certs/freetsa_tsa.crt',
        'timeout'      => 30,
    ],
    'anchor' => [
        'interval_seconds'   => 600, // default every 10 minutes
        'max_batch_entries'  => 1024,
    ],
    'app' => [
        // Enforced everywhere: no plaintext HTTP.
        'require_https' => true,
        'base_url'      => getenv('STPS_BASE_URL') ?: 'https://localhost',
    ],
];
