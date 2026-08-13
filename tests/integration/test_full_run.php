<?php
declare(strict_types=1);

/**
 * MySQL integration test (build spec section 21 "integration"). Runs the REAL
 * services and the REAL LedgerWriter/Verifier against a MySQL instance, then
 * asserts the ledger chain verifies PASS and signatures check out.
 *
 * Gated: requires STPS_DB_DSN / STPS_DB_USER / STPS_DB_PASSWORD (a proc_app or
 * proc_migrate account) pointing at a database with the schema + migrations 002-004
 * applied. Skipped (exit 0) when not configured, so the offline suite still runs
 * everywhere. Run with: php tests/run.php --with-db
 *
 * A P-256 keypair here SIMULATES the browser client for the test only; the server
 * never holds a private key in production.
 */

$root = dirname(__DIR__, 1);
require_once dirname($root) . '/src/procurement/Services.php';
require_once dirname($root) . '/src/procurement/RequisitionService.php';
require_once dirname($root) . '/src/ledger/Verifier.php';

$dsn = getenv('STPS_DB_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDOUT, "integration: STPS_DB_DSN not set; skipping MySQL integration test.\n");
    exit(0);
}

$pdo = Db::connect([
    'dsn' => $dsn,
    'user' => getenv('STPS_DB_USER') ?: 'proc_app',
    'password' => getenv('STPS_DB_PASSWORD') ?: '',
]);
Db::setConnection($pdo);

// ---- simulated browser client (TEST ONLY) ----
final class ItClient
{
    public $priv; public string $spkiBase64;
    public function __construct()
    {
        $this->priv = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($this->priv)['key'];
        $this->spkiBase64 = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"], '', $pem);
    }
    public function signCanonical(string $domain, string $canonical): string
    {
        $toSign = Hasher::toSign($domain, $canonical);
        openssl_sign($toSign, $sig, $this->priv, OPENSSL_ALGO_SHA256);
        return $sig;
    }
}

function it_now(): string { return Clock::mysql(Clock::now()); }

$failures = 0;
try {
    $pdo->beginTransaction();

    // Seed the minimum: a department, a Requisitioner user + role + enrolled key.
    $deptId = Uuid::bin();
    $st = $pdo->prepare('INSERT INTO departments (department_id, name, code, created_at) VALUES (:i,:n,:c,:t)');
    $st->bindValue(':i', $deptId, PDO::PARAM_LOB); $st->bindValue(':n', 'IT Dept ' . bin2hex(random_bytes(3)));
    $st->bindValue(':c', 'IT-' . bin2hex(random_bytes(3))); $st->bindValue(':t', it_now()); $st->execute();

    $userId = Uuid::bin();
    $st = $pdo->prepare('INSERT INTO users (user_id, username, email, password_hash, status, created_at) VALUES (:i,:u,:e,:p,"active",:t)');
    $st->bindValue(':i', $userId, PDO::PARAM_LOB);
    $st->bindValue(':u', 'req_' . bin2hex(random_bytes(4)));
    $st->bindValue(':e', bin2hex(random_bytes(4)) . '@example.test');
    $st->bindValue(':p', Password::hash('pw')); $st->bindValue(':t', it_now()); $st->execute();

    // Ensure the Requisitioner role exists (seed migration 004), assign it.
    $roleId = $pdo->query('SELECT role_id FROM roles WHERE name = "Requisitioner" LIMIT 1')->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('Requisitioner role missing; apply migration 004_seed_roles.sql.');
    }
    $st = $pdo->prepare('INSERT INTO user_roles (user_role_id,user_id,role_id,valid_from,status) VALUES (:i,:u,:r,:f,"active")');
    $st->bindValue(':i', Uuid::bin(), PDO::PARAM_LOB); $st->bindValue(':u', $userId, PDO::PARAM_LOB);
    $st->bindValue(':r', $roleId, PDO::PARAM_LOB); $st->bindValue(':f', it_now()); $st->execute();

    // Enroll a signing key.
    $client = new ItClient();
    $keys = new KeyStore($pdo);
    $keyId = $keys->enroll($userId, $client->spkiBase64);

    $pdo->commit();

    // Submit a signed requisition through the real service (its own transaction).
    $requisitionId = Uuid::bin();
    $canonical = Canonicalizer::encode((object) [
        'schema' => DomainSeparators::REQUISITION,
        'reference_no' => 'REQ-' . bin2hex(random_bytes(4)),
        'title' => 'Integration test requisition',
        'estimated_value' => Canonicalizer::decimalString('1000000', 2),
        'currency_code' => 'MWK',
        'procurement_type' => 'GOODS',
    ]);
    $signature = $client->signCanonical(DomainSeparators::REQUISITION, $canonical);
    $refNo = json_decode($canonical, true)['reference_no'];

    $svc = new RequisitionService(Services::boot());
    $newId = $svc->submit($userId, [
        'department_id' => Uuid::toString($deptId),
        'reference_no' => $refNo,
        'title' => 'Integration test requisition',
        'estimated_value' => Canonicalizer::decimalString('1000000', 2),
        'currency_code' => 'MWK',
        'procurement_type' => 'GOODS',
        'items' => [],
    ], $canonical, $signature, $keyId);

    echo "[ok] requisition submitted: " . bin2hex($newId) . "\n";

    // Verify the ledger chain (the real Verifier).
    $result = (new Verifier($pdo))->run();
    if ($result->pass) {
        echo "[ok] independent verifier PASS after integration run\n";
    } else {
        $failures++;
        fwrite(STDERR, "[FAIL] verifier did not pass: " . json_encode($result->failures[0]) . "\n");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $failures++;
    fwrite(STDERR, "[FAIL] integration exception: " . $e->getMessage() . "\n");
}

if ($failures > 0) {
    fwrite(STDERR, "integration: {$failures} failure(s)\n");
    exit(1);
}
echo "integration: passed\n";
