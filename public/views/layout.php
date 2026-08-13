<?php
/**
 * layout.php  --  the outer HTML shell. Receives $body (rendered page), $title,
 * $roles, $userHex, $flash from View::render(). All dynamic values are escaped.
 */
/** @var string $body */
/** @var string $title */
/** @var array $roles */
/** @var ?string $userHex */
/** @var array $flash */
$nav = View::navFor($roles ?? []);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= View::e($title) ?> &middot; Secure Procurement</title>
  <meta name="stps-csrf" content="<?= View::e($csrf ?? Csrf::token()) ?>">
  <?php if (!empty($keyId)): ?><meta name="stps-key-id" content="<?= View::e($keyId) ?>"><?php endif; ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="topbar">
    <div class="brand">
      <strong>Secure Procurement</strong>
      <span class="tag">tamper-evident</span>
    </div>
    <?php if ($userHex): ?>
      <nav class="mainnav">
        <?php foreach ($nav as $item): ?>
          <a href="<?= View::e($item['href']) ?>"><?= $item['label'] /* labels are trusted, may contain &amp; */ ?></a>
        <?php endforeach; ?>
      </nav>
      <form method="post" action="/logout" class="logout">
        <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
        <button type="submit">Sign out</button>
      </form>
    <?php endif; ?>
  </header>

  <?php foreach (($flash ?? []) as $f): ?>
    <div class="flash flash-<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
  <?php endforeach; ?>

  <main>
    <?= $body /* already-escaped rendered template */ ?>
  </main>

  <footer>
    <span>Prototype. The system is tamper-<strong>evident</strong>, not tamper-proof.</span>
    <span>Independent check: <code>php bin/verify.php</code></span>
  </footer>

  <script src="/assets/js/canonicalizer.js"></script>
  <script src="/assets/js/webcrypto-client.js"></script>
  <script src="/assets/js/signer.js"></script>
</body>
</html>
