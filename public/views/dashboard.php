<?php
/** Dashboard (build spec 7.2): device-key panel, stat tiles, recent ledger. */
/** @var array $counts */ /** @var array $ledger */ /** @var array $roles */
?>
<div class="page-header">
  <h2>Dashboard</h2>
  <p>Overview of activity and your device signing key.</p>
</div>

<section class="card">
  <div class="card-body">
    <h5 class="mb-1"><i class="bi bi-key"></i> Your signing key</h5>
    <p class="text-muted small mb-3">Your private key is generated in this browser as a non-extractable key and never leaves your device. Only the public key is enrolled with the server.</p>
    <button id="btn-enroll" class="btn btn-primary"><i class="bi bi-shield-plus"></i> Generate &amp; enroll a device key</button>
    <div id="enroll-status" class="small mt-2"></div>
    <p class="text-muted small mt-2 mb-0">If an action says "no signing key on this device", enroll one here. Keys are per browser and per device.</p>
  </div>
</section>

<section class="card">
  <div class="card-body">
    <h5 class="mb-3">Overview</h5>
    <div class="tiles">
      <div class="tile"><span class="n"><?= $e($counts['requisitions']) ?></span><span class="l"><i class="bi bi-file-earmark-text"></i> Requisitions</span></div>
      <div class="tile"><span class="n"><?= $e($counts['rfqs']) ?></span><span class="l"><i class="bi bi-megaphone"></i> RFQs</span></div>
      <div class="tile"><span class="n"><?= $e($counts['bids']) ?></span><span class="l"><i class="bi bi-safe"></i> Bids</span></div>
      <div class="tile"><span class="n"><?= $e($counts['awards']) ?></span><span class="l"><i class="bi bi-award"></i> Awards</span></div>
      <div class="tile"><span class="n"><?= $e($counts['ledger']) ?></span><span class="l"><i class="bi bi-link-45deg"></i> Ledger entries</span></div>
    </div>
    <p class="text-muted small mt-3 mb-0">Your roles: <?= $e(implode(', ', $roles) ?: 'none assigned') ?></p>
  </div>
</section>

<section class="card">
  <div class="card-header">Recent ledger activity</div>
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>#</th><th>Action</th><th>Entity</th><th>When (UTC)</th></tr></thead>
      <tbody>
        <?php foreach ($ledger as $row): ?>
          <tr>
            <td class="mono"><?= $e($row['sequence_no']) ?></td>
            <td><?= $e($row['action']) ?></td>
            <td>
              <?= $e($row['entity_type']) ?>
              <span class="hashid"><code><?= $e(substr($row['entity'], 0, 12)) ?>…</code>
                <button type="button" class="copy-btn" data-copy="<?= $e($row['entity']) ?>" title="Copy id" aria-label="Copy id"><i class="bi bi-clipboard"></i></button>
              </span>
            </td>
            <td class="small text-muted"><?= $e($row['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ledger): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="bi bi-inbox"></i>No ledger entries yet.</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
