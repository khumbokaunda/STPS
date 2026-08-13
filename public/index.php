<?php
declare(strict_types=1);

/**
 * Front controller (build spec section 3). Only /public is served. Every
 * state-changing endpoint requires authentication, an authorization check, and a
 * CSRF token. Deny by default. HTTPS enforced. No secrets in code.
 *
 * This exposes a small JSON API plus a Bootstrap UI shell. It is intentionally
 * thin: all security-significant logic lives in the /src services, which perform
 * signature verification and the ledger append atomically.
 */

$root = dirname(__DIR__);
require_once $root . '/src/http/Http.php';
require_once $root . '/src/http/Csrf.php';
require_once $root . '/src/http/Validator.php';
require_once $root . '/src/crypto/Canonicalizer.php';
require_once $root . '/src/auth/Session.php';
require_once $root . '/src/auth/Authenticator.php';
require_once $root . '/src/auth/Authorization.php';
require_once $root . '/src/procurement/Services.php';
require_once $root . '/src/procurement/RequisitionService.php';
require_once $root . '/src/procurement/BidService.php';

$configPath = file_exists($root . '/config/config.php')
    ? $root . '/config/config.php'
    : $root . '/config/config.example.php';
$config = require $configPath;

// Enforce HTTPS in any environment (build spec: no plaintext HTTP).
if (($config['app']['require_https'] ?? true) && !Request::isHttps()
    && (($_SERVER['SERVER_NAME'] ?? '') !== 'localhost' || ($_SERVER['HTTPS'] ?? '') === '')) {
    // Allow the CLI test server on localhost without TLS for local development.
    if (PHP_SAPI !== 'cli-server') {
        Response::error(400, 'https_required', 'HTTPS is required.');
    }
}

Session::start($config['session']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = Request::method();

// Serve the UI shell for non-API GETs.
if ($method === 'GET' && !str_starts_with($path, '/api/')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    exit;
}

// ---- JSON API ------------------------------------------------------------
try {
    // CSRF on every state-changing request.
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        if (!Csrf::validate(Request::header('X-CSRF-Token'))) {
            Response::error(403, 'csrf_failed');
        }
    }

    switch ("{$method} {$path}") {
        case 'GET /api/csrf':
            Response::json(['token' => Csrf::token()]);
            // no break; Response::json exits

        case 'POST /api/login':
            handleLogin($config);
            break;

        case 'POST /api/logout':
            Session::destroy();
            Response::json(['ok' => true]);
            break;

        case 'POST /api/keys/enroll':
            handleEnroll();
            break;

        case 'POST /api/requisitions':
            handleSubmitRequisition();
            break;

        case 'POST /api/bids/commit':
            handleBidCommit();
            break;

        case 'POST /api/bids/reveal':
            handleBidReveal();
            break;

        default:
            Response::error(404, 'not_found');
    }
} catch (ValidationException $e) {
    Response::error(400, 'validation_error', $e->getMessage());
} catch (AuthorizationException $e) {
    Response::error(403, 'forbidden');
} catch (Throwable $e) {
    // Deterministic error: never leak stack traces or SQL to the client.
    error_log('[stps] ' . $e->getMessage());
    Response::error(500, 'server_error');
}

// ---- handlers ------------------------------------------------------------

function handleLogin(array $config): void
{
    $body = Request::json();
    $username = Validator::requireString($body['username'] ?? null, 'username', 100);
    $password = Validator::requireString($body['password'] ?? null, 'password', 1024);
    $auth = new Authenticator(Db::app());
    $userId = $auth->authenticate($username, $password);
    if ($userId === null) {
        Response::error(401, 'invalid_credentials');
    }
    Session::login($userId);
    Response::json(['ok' => true, 'csrf' => Csrf::token()]);
}

function handleEnroll(): void
{
    $userId = Session::require();
    $body = Request::json();
    $spki = Validator::requireString($body['spki_base64'] ?? null, 'spki_base64', 4096);
    $keys = new KeyStore(Db::app());
    $keyId = $keys->enroll($userId, $spki);
    Response::json(['ok' => true, 'key_id' => bin2hex($keyId)]);
}

function handleSubmitRequisition(): void
{
    $userId = Session::require();
    $body = Request::json();
    $canonical = Validator::requireString($body['canonical'] ?? null, 'canonical', 65535);
    $signature = Validator::requireBase64($body['signature'] ?? null, 'signature');
    $keyId = Uuid::fromString(Validator::requireUuidHex($body['key_id'] ?? null, 'key_id'));
    $input = [
        'department_id'    => Validator::requireUuidHex($body['department_id'] ?? null, 'department_id'),
        'reference_no'     => Validator::requireString($body['reference_no'] ?? null, 'reference_no', 80),
        'title'            => Validator::requireString($body['title'] ?? null, 'title', 255),
        'estimated_value'  => Validator::requireDecimal($body['estimated_value'] ?? null, 'estimated_value', 2),
        'currency_code'    => Validator::requireEnum($body['currency_code'] ?? 'MWK', 'currency_code', ['MWK', 'USD', 'EUR', 'GBP', 'ZAR']),
        'procurement_type' => Validator::requireEnum($body['procurement_type'] ?? null, 'procurement_type', ['GOODS', 'WORKS', 'SERVICES', 'CONSULTING']),
        'items'            => [],
    ];
    $svc = new RequisitionService(Services::boot());
    $id = $svc->submit($userId, $input, $canonical, $signature, $keyId);
    Response::json(['ok' => true, 'requisition_id' => bin2hex($id)]);
}

function handleBidCommit(): void
{
    $userId = Session::require();
    $body = Request::json();
    $rfqId = Uuid::fromString(Validator::requireUuidHex($body['rfq_id'] ?? null, 'rfq_id'));
    $bidderId = Uuid::fromString(Validator::requireUuidHex($body['bidder_id'] ?? null, 'bidder_id'));
    $C = Validator::requireBase64($body['C'] ?? null, 'C', 64);
    $sigC = Validator::requireBase64($body['sigC'] ?? null, 'sigC');
    $keyId = Uuid::fromString(Validator::requireUuidHex($body['key_id'] ?? null, 'key_id'));
    $escrow = isset($body['escrow_ciphertext'])
        ? Validator::requireBase64($body['escrow_ciphertext'], 'escrow_ciphertext', 1 << 20)
        : null;
    $svc = new BidService(Services::boot());
    $bidId = $svc->commit($userId, $bidderId, $rfqId, $C, $sigC, $keyId, $escrow);
    Response::json(['ok' => true, 'bid_id' => bin2hex($bidId)]);
}

function handleBidReveal(): void
{
    $userId = Session::require();
    $body = Request::json();
    $bidId = Uuid::fromString(Validator::requireUuidHex($body['bid_id'] ?? null, 'bid_id'));
    $canonical = Validator::requireString($body['canonical_bid'] ?? null, 'canonical_bid', 65535);
    $nonce = Validator::requireBase64($body['nonce'] ?? null, 'nonce', 64);
    $sigR = Validator::requireBase64($body['sigR'] ?? null, 'sigR');
    $keyId = Uuid::fromString(Validator::requireUuidHex($body['key_id'] ?? null, 'key_id'));
    $svc = new BidService(Services::boot());
    $status = $svc->reveal($userId, $bidId, $canonical, $nonce, $sigR, $keyId);
    Response::json(['ok' => true, 'status' => $status]);
}
