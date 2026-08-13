<?php
/**
 * layout.php  --  application shell (build spec section 5). Sidebar + top bar for
 * signed-in users; a clean centered frame for the login page. Bootstrap 5.3 is
 * vendored locally; dark mode via data-bs-theme. No inline scripts or styles:
 * all behaviour is in app.js, all styling in Bootstrap + app.css.
 *
 * Receives from View::render(): $body, $title, $roles, $userHex, $keyId, $csrf,
 * $flash.
 */
/** @var string $body */ /** @var string $title */ /** @var array $roles */
/** @var ?string $userHex */ /** @var ?string $keyId */ /** @var array $flash */
$groups = View::navGroups($roles ?? []);
$currentPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$isAuthed = !empty($userHex);
$userShort = $userHex ? substr($userHex, 0, 8) : '';
?><!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= View::e($title) ?> &middot; Secure Procurement</title>
  <meta name="stps-csrf" content="<?= View::e($csrf ?? Csrf::token()) ?>">
  <?php if (!empty($keyId)): ?><meta name="stps-key-id" content="<?= View::e($keyId) ?>"><?php endif; ?>
  <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="<?= $isAuthed ? 'app-authed' : 'app-plain' ?>">

<?php
// Reusable sidebar nav markup (used in both the fixed sidebar and the offcanvas).
$renderNav = function (array $groups, string $currentPath) {
    foreach ($groups as $group) {
        if (!empty($group['label'])) {
            echo '<div class="nav-section">' . View::e($group['label']) . '</div>';
        }
        echo '<ul class="nav flex-column">';
        foreach ($group['items'] as $item) {
            $active = ($currentPath === rtrim($item['href'], '/') || ($item['href'] === '/' && $currentPath === '/')) ? ' active' : '';
            echo '<li class="nav-item"><a class="nav-link' . $active . '" href="' . View::e($item['href']) . '">'
               . '<i class="bi ' . View::e($item['icon']) . '"></i><span>' . View::e($item['label']) . '</span></a></li>';
        }
        echo '</ul>';
    }
};
?>

<?php if ($isAuthed): ?>
  <div class="app-grid">
    <!-- Fixed sidebar (desktop) -->
    <aside class="app-sidebar d-none d-lg-flex flex-column">
      <a class="brand" href="/">
        <i class="bi bi-shield-lock"></i>
        <span>Secure Procurement</span>
      </a>
      <nav class="sidebar-nav flex-grow-1"><?php $renderNav($groups, $currentPath); ?></nav>
      <div class="sidebar-foot small text-muted">Tamper-<strong>evident</strong>. Never tamper-proof.</div>
    </aside>

    <!-- Offcanvas sidebar (mobile) -->
    <div class="offcanvas offcanvas-start app-offcanvas" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
      <div class="offcanvas-header">
        <span class="brand" id="sidebarOffcanvasLabel"><i class="bi bi-shield-lock"></i> Secure Procurement</span>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body"><nav class="sidebar-nav"><?php $renderNav($groups, $currentPath); ?></nav></div>
    </div>

    <div class="app-main">
      <header class="app-topbar">
        <button class="btn btn-subtle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-label="Open menu">
          <i class="bi bi-list"></i>
        </button>
        <h1 class="topbar-title"><?= View::e($title) ?></h1>
        <div class="topbar-right">
          <span id="key-status" class="key-status" data-has-key="<?= !empty($keyId) ? '1' : '0' ?>" title="Device signing key status">
            <i class="bi bi-key"></i><span class="label">key</span>
          </span>
          <div class="dropdown">
            <button class="btn btn-subtle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle"></i>
              <span class="mono d-none d-sm-inline"><?= View::e($userShort) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><h6 class="dropdown-header">Roles</h6></li>
              <?php foreach (($roles ?: ['none assigned']) as $r): ?>
                <li><span class="dropdown-item-text small"><?= View::e($r) ?></span></li>
              <?php endforeach; ?>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="post" action="/logout" class="px-2">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                  <button type="submit" class="btn btn-subtle w-100 text-start"><i class="bi bi-box-arrow-right"></i> Sign out</button>
                </form>
              </li>
            </ul>
          </div>
        </div>
      </header>

      <main class="app-content">
        <?= $body ?>
      </main>
    </div>
  </div>
<?php else: ?>
  <main class="plain-main">
    <?= $body ?>
  </main>
<?php endif; ?>

  <!-- Flash toasts (shown by app.js; no inline script) -->
  <div class="toast-container position-fixed top-0 end-0 p-3" id="toast-container">
    <?php foreach (($flash ?? []) as $f): ?>
      <?php
        $tone = $f['type'] === 'ok' ? 'ok' : ($f['type'] === 'err' ? 'err' : 'info');
        $icon = $tone === 'ok' ? 'bi-check-circle' : ($tone === 'err' ? 'bi-exclamation-octagon' : 'bi-info-circle');
      ?>
      <div class="toast app-toast toast-<?= View::e($tone) ?>" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true">
        <div class="toast-body"><i class="bi <?= $icon ?>"></i> <?= View::e($f['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    <?php endforeach; ?>
  </div>

  <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="/assets/js/canonicalizer.js"></script>
  <script src="/assets/js/webcrypto-client.js"></script>
  <script src="/assets/js/signer.js"></script>
  <script src="/assets/js/app.js"></script>
</body>
</html>
