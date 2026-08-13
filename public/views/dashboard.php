<?php
/** Dashboard: device-key enrollment, counts, recent ledger. */
/** @var array $counts */ /** @var array $ledger */ /** @var array $roles */ /** @var ?string $user */
?>
<section class="card">
  <h2>Your signing key</h2>
  <p class="muted">Your private key is generated in this browser as a non-extractable key and never leaves your device. Only the public key is enrolled.</p>
  <button id="btn-enroll">Generate &amp; enroll a device key</button>
  <div id="enroll-status" class="status"></div>
  <p class="muted small">If actions say "no signing key on this device", click the button above. Keys are per browser/device.</p>
</section>

<section class="card">
  <h2>Overview</h2>
  <div class="tiles">
    <div class="tile"><span class="n"><?= $e($counts['requisitions']) ?></span><span>Requisitions</span></div>
    <div class="tile"><span class="n"><?= $e($counts['rfqs']) ?></span><span>RFQs</span></div>
    <div class="tile"><span class="n"><?= $e($counts['bids']) ?></span><span>Bids</span></div>
    <div class="tile"><span class="n"><?= $e($counts['awards']) ?></span><span>Awards</span></div>
    <div class="tile"><span class="n"><?= $e($counts['ledger']) ?></span><span>Ledger entries</span></div>
  </div>
  <p class="muted small">Your roles: <?= $e(implode(', ', $roles) ?: 'none assigned') ?></p>
</section>

<section class="card">
  <h2>Recent ledger activity</h2>
  <table>
    <thead><tr><th>#</th><th>Action</th><th>Entity</th><th>When (UTC)</th></tr></thead>
    <tbody>
      <?php foreach ($ledger as $row): ?>
        <tr>
          <td><?= $e($row['sequence_no']) ?></td>
          <td><?= $e($row['action']) ?></td>
          <td><?= $e($row['entity_type']) ?> <span class="mono small"><?= $e(substr($row['entity'], 0, 12)) ?>…</span></td>
          <td class="small"><?= $e($row['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ledger): ?><tr><td colspan="4" class="muted">No ledger entries yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
