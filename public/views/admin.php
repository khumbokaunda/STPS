<?php /** @var array $roles */ /** @var array $departments */ /** @var array $bidders */ ?>
<section class="card">
  <h2>Administration</h2>
  <p class="muted small">User provisioning and role assignment are done with the CLI (<code>php bin/create_user.php</code>) so account creation stays an administrative, audited action outside the web tier. This page summarises reference data.</p>
</section>

<section class="card">
  <h2>Roles</h2>
  <ul class="chips"><?php foreach ($roles as $r): ?><li><?= $e($r) ?></li><?php endforeach; ?></ul>
</section>

<section class="card">
  <h2>Create a department</h2>
  <form method="post" action="/admin/department" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Name <input name="name" required></label>
    <label>Code <input name="code" required></label>
    <button type="submit">Create department</button>
  </form>
</section>

<section class="card">
  <h2>Create a bidder organisation</h2>
  <form method="post" action="/admin/bidder" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Legal name <input name="legal_name" required></label>
    <label>Registration no <input name="registration_number"></label>
    <button type="submit">Create bidder</button>
  </form>
  <p class="muted small">Link a bidder to a user account with <code>php bin/create_user.php --role Bidder --bidder-name "…"</code>.</p>
</section>

<section class="card">
  <h2>Departments</h2>
  <table>
    <thead><tr><th>Name</th><th>Code</th></tr></thead>
    <tbody>
      <?php foreach ($departments as $d): ?><tr><td><?= $e($d['name']) ?></td><td class="mono"><?= $e($d['code']) ?></td></tr><?php endforeach; ?>
      <?php if (!$departments): ?><tr><td colspan="2" class="muted">None.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Bidders</h2>
  <table>
    <thead><tr><th>Legal name</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($bidders as $b): ?><tr><td><?= $e($b['name']) ?></td><td><?= $e($b['status']) ?></td></tr><?php endforeach; ?>
      <?php if (!$bidders): ?><tr><td colspan="2" class="muted">None.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
