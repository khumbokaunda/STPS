<?php /** Login page (build spec 7.1). Centered card, not signed. */ ?>
<div class="card auth-card">
  <div class="card-body p-4">
    <div class="brand mb-2"><i class="bi bi-shield-lock"></i><span>Secure Procurement</span></div>
    <p class="text-muted small mb-4">Tamper-evident public procurement. Accounts are provisioned by an administrator; there is no self-registration.</p>
    <form method="post" action="/login" class="stack-sm">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div>
        <label class="form-label" for="login-username">Username</label>
        <input class="form-control" id="login-username" name="username" autocomplete="username" required autofocus>
      </div>
      <div>
        <label class="form-label" for="login-password">Password</label>
        <input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 mt-2"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
    </form>
  </div>
</div>
