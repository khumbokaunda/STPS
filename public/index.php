<?php
declare(strict_types=1);

/**
 * Front controller (build spec section 3). Only /public is served. Every
 * state-changing request requires authentication, authorization, and a CSRF
 * token. Deny by default. Server-rendered pages (no SPA), with browser-held
 * signing keys for the actions that require a client signature.
 *
 * Routing:
 *   GET  page routes  -> render a view (auth-gated, role-aware nav).
 *   POST action routes -> mutate via the /src services, then redirect with a
 *                        flash message. Signed actions carry _signature + _key_id
 *                        produced in the browser; the server rebuilds the exact
 *                        canonical payload from the posted fields and re-verifies.
 *   GET/POST /api/*   -> small JSON API (CSRF token, key enrollment, verifier).
 */

$root = dirname(__DIR__);
require_once $root . '/src/http/Http.php';
require_once $root . '/src/http/Csrf.php';
require_once $root . '/src/http/Validator.php';
require_once $root . '/src/http/View.php';
require_once $root . '/src/crypto/Canonicalizer.php';
require_once $root . '/src/crypto/DomainSeparators.php';
require_once $root . '/src/auth/Session.php';
require_once $root . '/src/auth/Authenticator.php';
require_once $root . '/src/auth/Authorization.php';
require_once $root . '/src/procurement/Services.php';
require_once $root . '/src/procurement/ReadModel.php';
require_once $root . '/src/procurement/RequisitionService.php';
require_once $root . '/src/procurement/ApprovalService.php';
require_once $root . '/src/procurement/RfqService.php';
require_once $root . '/src/procurement/BidService.php';
require_once $root . '/src/procurement/EvaluationService.php';
require_once $root . '/src/procurement/AwardContractExecutionService.php';

$configPath = file_exists($root . '/config/config.php')
    ? $root . '/config/config.php'
    : $root . '/config/config.example.php';
$config = require $configPath;

if (($config['app']['require_https'] ?? true) && !Request::isHttps() && PHP_SAPI !== 'cli-server') {
    $host = $_SERVER['SERVER_NAME'] ?? '';
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        Response::error(400, 'https_required', 'HTTPS is required.');
    }
}

Session::start($config['session']);

$method = Request::method();
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') {
    $path = '/';
}
$route = "{$method} {$path}";

// ---- context helpers -----------------------------------------------------

/** Current roles + active key for the logged-in user (empty if anonymous). */
function currentContext(): array
{
    $userId = Session::userId();
    if ($userId === null) {
        return ['user' => null, 'user_bin' => null, 'roles' => [], 'key_id' => null];
    }
    $svc = Services::boot();
    $roles = $svc->rbac->rolesOf($userId);
    $active = $svc->keys->activeKeyForUser($userId);
    return [
        'user' => bin2hex($userId),
        'user_bin' => $userId,
        'roles' => $roles,
        'key_id' => $active !== null ? bin2hex($active['key_id']) : null,
    ];
}

function page(string $template, array $data, string $title): void
{
    $ctx = currentContext();
    View::render($template, $data + ['ctx' => $ctx], [
        'user' => $ctx['user'], 'roles' => $ctx['roles'], 'title' => $title, 'key_id' => $ctx['key_id'],
    ]);
}

function requireLogin(): array
{
    $ctx = currentContext();
    if ($ctx['user'] === null) {
        header('Location: /login');
        exit;
    }
    return $ctx;
}

function requireCsrf(): void
{
    if (!Csrf::validate($_POST['_csrf'] ?? Request::header('X-CSRF-Token'))) {
        Response::error(403, 'csrf_failed');
    }
}

function redirect(string $to, string $flashType = '', string $flashMsg = ''): void
{
    if ($flashMsg !== '') {
        View::flash($flashType, $flashMsg);
    }
    header('Location: ' . $to);
    exit;
}

/** Rebuild the exact canonical bytes the client signed for a signed form. */
function rebuildCanonical(string $domain, array $fields): string
{
    return Canonicalizer::encode(array_merge(['schema' => $domain], $fields));
}

// Page and action handler functions.
require_once $root . '/src/http/handlers.php';

// ---- dispatch ------------------------------------------------------------

try {
    switch (true) {
        // --- JSON API ---
        case $route === 'GET /api/csrf':
            Response::json(['token' => Csrf::token()]);

        case $route === 'POST /api/keys/enroll':
            requireCsrf();
            apiEnroll();
            break;

        case $route === 'GET /api/verify':
            apiVerify($config);
            break;

        // --- auth ---
        case $route === 'GET /login':
            page('login', [], 'Sign in');
            break;
        case $route === 'POST /login':
            requireCsrf();
            actionLogin();
            break;
        case $route === 'POST /logout':
            requireCsrf();
            Session::destroy();
            redirect('/login', 'ok', 'Signed out.');
            break;

        // --- dashboard ---
        case $route === 'GET /':
            pageDashboard();
            break;

        // --- requisitions ---
        case $route === 'GET /requisitions':
            pageRequisitions();
            break;
        case $route === 'GET /requisitions/new':
            pageRequisitionNew();
            break;
        case $route === 'POST /requisitions':
            requireCsrf();
            actionSubmitRequisition();
            break;

        // --- approvals ---
        case $route === 'GET /approvals':
            pageApprovals();
            break;
        case $route === 'POST /approvals/role':
            requireCsrf();
            actionRoleApproval();
            break;

        // --- committee ---
        case $route === 'GET /committee':
            pageCommittee();
            break;
        case $route === 'POST /committee/open':
            requireCsrf();
            actionCommitteeOpen();
            break;
        case $route === 'POST /committee/vote':
            requireCsrf();
            actionCommitteeVote();
            break;

        // --- bidding documents ---
        case $route === 'GET /bidding-documents':
            pageBiddingDocuments();
            break;

        // --- RFQs ---
        case $route === 'GET /rfqs':
            pageRfqs();
            break;
        case $route === 'GET /rfqs/open':
            pageRfqsOpen();
            break;
        case $route === 'POST /rfqs/publish':
            requireCsrf();
            actionPublishRfq();
            break;
        case $route === 'POST /rfqs/close':
            requireCsrf();
            actionCloseRfq();
            break;

        // --- bids ---
        case $route === 'GET /bids':
            pageBids();
            break;
        case $route === 'POST /bids/commit':
            requireCsrf();
            actionBidCommit();
            break;
        case $route === 'POST /bids/reveal':
            requireCsrf();
            actionBidReveal();
            break;

        // --- evaluation ---
        case $route === 'GET /evaluation':
            pageEvaluation();
            break;
        case $route === 'POST /evaluation/score':
            requireCsrf();
            actionSubmitScore();
            break;

        // --- awards / contracts ---
        case $route === 'GET /awards':
            pageAwards();
            break;
        case $route === 'POST /awards/record':
            requireCsrf();
            actionRecordAward();
            break;
        case $route === 'GET /contracts':
            pageContracts();
            break;
        case $route === 'POST /contracts/sign':
            requireCsrf();
            actionSignContract();
            break;

        // --- execution ---
        case $route === 'GET /execution':
            pageExecution();
            break;
        case $route === 'POST /execution/delivery':
            requireCsrf();
            actionRecordDelivery();
            break;
        case $route === 'POST /execution/inspection':
            requireCsrf();
            actionRecordInspection();
            break;

        // --- finance ---
        case $route === 'GET /finance':
            pageFinance();
            break;
        case $route === 'POST /finance/invoice':
            requireCsrf();
            actionSubmitInvoice();
            break;
        case $route === 'POST /finance/payment':
            requireCsrf();
            actionRecordPayment();
            break;

        // --- admin ---
        case $route === 'GET /admin':
            pageAdmin();
            break;

        // --- audit ---
        case $route === 'GET /audit':
            pageAudit($config);
            break;

        default:
            if ($method === 'GET') {
                http_response_code(404);
                page('error', ['code' => 404, 'message' => 'Page not found.'], 'Not found');
            } else {
                Response::error(404, 'not_found');
            }
    }
} catch (ValidationException $e) {
    redirect($_SERVER['HTTP_REFERER'] ?? '/', 'err', 'Invalid input: ' . $e->getMessage());
} catch (AuthorizationException $e) {
    redirect($_SERVER['HTTP_REFERER'] ?? '/', 'err', 'Not permitted: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[stps] ' . $e->getMessage());
    if (($method ?? 'GET') === 'GET' && !str_starts_with($path ?? '', '/api/')) {
        http_response_code(500);
        page('error', ['code' => 500, 'message' => 'Something went wrong.'], 'Error');
    }
    Response::error(500, 'server_error');
}
