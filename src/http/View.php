<?php
declare(strict_types=1);

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Csrf.php';

/**
 * View  --  minimal server-rendered layout (no SPA framework, per build spec).
 * Provides HTML escaping on every rendered value, a role-aware navigation bar,
 * CSRF token exposure for forms, and flash messages. Templates are plain PHP
 * files under /public/views included within render().
 */
final class View
{
    /** Escape a value for safe HTML output (output encoding on every value). */
    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    private static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /**
     * Render a view template inside the layout.
     *
     * @param string $template basename under /public/views (without .php)
     * @param array  $data     variables extracted into the template scope
     * @param array  $ctx      ['user' => hex|null, 'roles' => string[], 'title' => string]
     */
    public static function render(string $template, array $data, array $ctx): void
    {
        $viewsDir = dirname(__DIR__, 2) . '/public/views';
        $roles = $ctx['roles'] ?? [];
        $userHex = $ctx['user'] ?? null;
        $title = $ctx['title'] ?? 'Secure Procurement';
        $keyId = $ctx['key_id'] ?? null;
        $csrf = Csrf::token();
        $flash = self::takeFlash();

        // Capture the page body.
        $render = function (string $__tpl, array $__data): string {
            extract($__data, EXTR_SKIP);
            $e = [View::class, 'e'];
            ob_start();
            include $__tpl;
            return (string) ob_get_clean();
        };
        $body = $render("{$viewsDir}/{$template}.php", $data + ['roles' => $roles, 'csrf' => $csrf, 'user' => $userHex]);

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        include "{$viewsDir}/layout.php";
    }

    /**
     * Role-aware navigation grouped under quiet section labels (build spec
     * section 5). Returns groups of items; each item has href, label, and a
     * Bootstrap icon class. Deny by default: an item appears only for roles that
     * can use its route. Dashboard and Audit are available to all signed-in users.
     */
    public static function navGroups(array $roles): array
    {
        $has = fn(string $r) => in_array($r, $roles, true);
        $groups = [];

        $groups[] = ['label' => null, 'items' => [
            ['href' => '/', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2'],
        ]];

        $proc = [];
        if ($has('Requisitioner')) {
            $proc[] = ['href' => '/requisitions', 'label' => 'Requisitions', 'icon' => 'bi-file-earmark-text'];
        }
        if ($has('HeadOfDepartment') || $has('PDUOfficer') || $has('IPDCMember') || $has('ControllingOfficer')) {
            $proc[] = ['href' => '/approvals', 'label' => 'Approvals', 'icon' => 'bi-check2-square'];
        }
        if ($proc) {
            $groups[] = ['label' => 'Procurement', 'items' => $proc];
        }

        $bidding = [];
        if ($has('PDUOfficer')) {
            $bidding[] = ['href' => '/bidding-documents', 'label' => 'Bidding docs', 'icon' => 'bi-journal-text'];
            $bidding[] = ['href' => '/rfqs', 'label' => 'RFQs', 'icon' => 'bi-megaphone'];
        }
        if ($has('Bidder')) {
            $bidding[] = ['href' => '/rfqs/open', 'label' => 'Open RFQs', 'icon' => 'bi-inboxes'];
            $bidding[] = ['href' => '/bids', 'label' => 'My bids', 'icon' => 'bi-safe'];
        }
        if ($bidding) {
            $groups[] = ['label' => 'Bidding', 'items' => $bidding];
        }

        $eval = [];
        if ($has('IPDCMember')) {
            $eval[] = ['href' => '/committee', 'label' => 'Committee', 'icon' => 'bi-people'];
        }
        if ($has('ControllingOfficer') || $has('EvaluationTeamMember')) {
            $eval[] = ['href' => '/evaluation', 'label' => 'Evaluation', 'icon' => 'bi-clipboard-data'];
        }
        if ($eval) {
            $groups[] = ['label' => 'Evaluation', 'items' => $eval];
        }

        $contracts = [];
        if ($has('PDUOfficer') || $has('ControllingOfficer')) {
            $contracts[] = ['href' => '/awards', 'label' => 'Awards', 'icon' => 'bi-award'];
            $contracts[] = ['href' => '/contracts', 'label' => 'Contracts', 'icon' => 'bi-file-earmark-check'];
        }
        if ($has('StoresOfficer')) {
            $contracts[] = ['href' => '/execution', 'label' => 'Execution', 'icon' => 'bi-truck'];
        }
        if ($has('FinanceOfficer')) {
            $contracts[] = ['href' => '/finance', 'label' => 'Finance', 'icon' => 'bi-cash-coin'];
        }
        if ($contracts) {
            $groups[] = ['label' => 'Contracts', 'items' => $contracts];
        }

        $oversight = [];
        if ($has('SystemAdministrator')) {
            $oversight[] = ['href' => '/admin', 'label' => 'Admin', 'icon' => 'bi-gear'];
        }
        $oversight[] = ['href' => '/audit', 'label' => 'Audit', 'icon' => 'bi-shield-check'];
        $groups[] = ['label' => 'Oversight', 'items' => $oversight];

        return $groups;
    }

    /**
     * Render a status pill with a consistent color mapping (build spec 6.4).
     * Status is paired with its label text so meaning is not by color alone.
     */
    public static function pill(string $status): string
    {
        $warn = ['pending', 'committed', 'submitted', 'draft', 'pending_approval', 'pending_signature', 'pending_no_objection', 'deferred', 'received'];
        $ok = ['approved', 'revealed', 'signed', 'active', 'verified', 'pass', 'completed', 'accepted', 'paid', 'fulfilled'];
        $err = ['rejected', 'invalid', 'expired', 'cancelled', 'failed', 'fail', 'terminated'];
        $info = ['published', 'reveal_open', 'evaluation', 'notified', 'issued', 'awarded', 'not_awarded', 'closed', 'partially_accepted', 'partially_fulfilled', 'superseded', 'authorized'];
        $s = strtolower(trim($status));
        $tone = 'neutral';
        if (in_array($s, $warn, true)) { $tone = 'warn'; }
        elseif (in_array($s, $ok, true)) { $tone = 'ok'; }
        elseif (in_array($s, $err, true)) { $tone = 'err'; }
        elseif (in_array($s, $info, true)) { $tone = 'info'; }
        return '<span class="pill pill-' . $tone . '">' . self::e($status) . '</span>';
    }
}
