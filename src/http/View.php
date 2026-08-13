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
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
        include "{$viewsDir}/layout.php";
    }

    /** Navigation items available to the given roles (deny by default). */
    public static function navFor(array $roles): array
    {
        $has = fn(string $r) => in_array($r, $roles, true);
        $nav = [['href' => '/', 'label' => 'Dashboard']];

        if ($has('Requisitioner')) {
            $nav[] = ['href' => '/requisitions', 'label' => 'Requisitions'];
        }
        if ($has('HeadOfDepartment') || $has('PDUOfficer') || $has('IPDCMember') || $has('ControllingOfficer')) {
            $nav[] = ['href' => '/approvals', 'label' => 'Approvals'];
        }
        if ($has('IPDCMember')) {
            $nav[] = ['href' => '/committee', 'label' => 'Committee'];
        }
        if ($has('PDUOfficer')) {
            $nav[] = ['href' => '/bidding-documents', 'label' => 'Bidding docs'];
            $nav[] = ['href' => '/rfqs', 'label' => 'RFQs'];
        }
        if ($has('Bidder')) {
            $nav[] = ['href' => '/rfqs/open', 'label' => 'Open RFQs'];
            $nav[] = ['href' => '/bids', 'label' => 'My bids'];
        }
        if ($has('ControllingOfficer') || $has('EvaluationTeamMember')) {
            $nav[] = ['href' => '/evaluation', 'label' => 'Evaluation'];
        }
        if ($has('PDUOfficer') || $has('ControllingOfficer')) {
            $nav[] = ['href' => '/awards', 'label' => 'Awards'];
            $nav[] = ['href' => '/contracts', 'label' => 'Contracts'];
        }
        if ($has('StoresOfficer')) {
            $nav[] = ['href' => '/execution', 'label' => 'Deliveries'];
        }
        if ($has('FinanceOfficer')) {
            $nav[] = ['href' => '/finance', 'label' => 'Invoices &amp; payments'];
        }
        if ($has('SystemAdministrator')) {
            $nav[] = ['href' => '/admin', 'label' => 'Admin'];
        }
        $nav[] = ['href' => '/audit', 'label' => 'Audit'];
        return $nav;
    }
}
