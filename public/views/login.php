<?php /** Login page. */ ?>
<section class="card narrow">
  <h2>Sign in</h2>
  <p class="muted">Accounts are provisioned by an administrator (see <code>bin/create_user.php</code>). There is no self-registration.</p>
  <form method="post" action="/login">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Username <input name="username" autocomplete="username" required></label>
    <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
    <button type="submit">Sign in</button>
  </form>
</section>
