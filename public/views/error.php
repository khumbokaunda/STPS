<?php /** @var int $code */ /** @var string $message */ ?>
<div class="card auth-card">
  <div class="card-body p-4 text-center">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
    <h2 class="h4 mt-2">Error <?= $e($code) ?></h2>
    <p class="text-muted"><?= $e($message) ?></p>
    <a href="/" class="btn btn-primary">Return to the dashboard</a>
  </div>
</div>
