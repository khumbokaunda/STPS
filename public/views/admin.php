<?php /** @var array $roles */ /** @var array $departments */ /** @var array $bidders */ ?>
<div class="page-header"><h2>Administration</h2><p>Reference data. User provisioning stays in the CLI so account creation remains an audited action.</p></div>

<div class="row g-4">
  <div class="col-12 col-lg-6">
    <section class="card h-100">
      <div class="card-header">Create a department</div>
      <div class="card-body">
        <form method="post" action="/admin/department" class="stack-sm">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <div><label class="form-label">Name</label><input class="form-control" name="name" required></div>
          <div><label class="form-label">Code</label><input class="form-control" name="code" required></div>
          <button type="submit" class="btn btn-primary">Create department</button>
        </form>
      </div>
    </section>
  </div>
  <div class="col-12 col-lg-6">
    <section class="card h-100">
      <div class="card-header">Create a bidder organisation</div>
      <div class="card-body">
        <form method="post" action="/admin/bidder" class="stack-sm">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <div><label class="form-label">Legal name</label><input class="form-control" name="legal_name" required></div>
          <div><label class="form-label">Registration no</label><input class="form-control" name="registration_number"></div>
          <button type="submit" class="btn btn-primary">Create bidder</button>
        </form>
        <p class="form-text mt-2">Link a bidder to a user with <code>php bin/create_user.php --role Bidder --bidder-name "…"</code>.</p>
      </div>
    </section>
  </div>
</div>

<section class="card">
  <div class="card-header">Roles</div>
  <div class="card-body d-flex flex-wrap gap-2">
    <?php foreach ($roles as $r): ?><span class="pill pill-neutral"><?= $e($r) ?></span><?php endforeach; ?>
  </div>
</section>

<div class="row g-4">
  <div class="col-12 col-lg-6">
    <section class="card">
      <div class="card-header">Departments</div>
      <div class="table-wrap"><table class="table table-hover">
        <thead><tr><th>Name</th><th>Code</th></tr></thead>
        <tbody><?php foreach ($departments as $d): ?><tr><td><?= $e($d['name']) ?></td><td class="mono"><?= $e($d['code']) ?></td></tr><?php endforeach; ?>
        <?php if (!$departments): ?><tr><td colspan="2"><div class="empty-state"><i class="bi bi-building"></i>None yet.</div></td></tr><?php endif; ?></tbody>
      </table></div>
    </section>
  </div>
  <div class="col-12 col-lg-6">
    <section class="card">
      <div class="card-header">Bidders</div>
      <div class="table-wrap"><table class="table table-hover">
        <thead><tr><th>Legal name</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($bidders as $b): ?><tr><td><?= $e($b['name']) ?></td><td><?= View::pill($b['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$bidders): ?><tr><td colspan="2"><div class="empty-state"><i class="bi bi-people"></i>None yet.</div></td></tr><?php endif; ?></tbody>
      </table></div>
    </section>
  </div>
</div>
