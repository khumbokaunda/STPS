<?php /** @var VerificationResult $result */ /** @var array $ledger */ ?>
<section class="card">
  <h2>Independent verification</h2>
  <p class="muted small">This runs the same checks as <code>bin/verify.php</code> against the current database. For a real audit, run the CLI with read-only credentials or against an export, trusting none of the application's write path.</p>
  <?php if ($result->pass): ?>
    <div class="verdict verdict-pass">PASS</div>
    <p>All ledger entries reproduce, the chain is intact and gapless, every recorded signature and commitment verifies, and every anchored Merkle root matches. No unauthorized historical modification detected.</p>
  <?php else: ?>
    <div class="verdict verdict-fail">FAIL</div>
    <?php $f = $result->failures[0]; ?>
    <table>
      <tr><th>check</th><td class="mono"><?= $e($f['check']) ?></td></tr>
      <tr><th>sequence_no</th><td><?= $e($f['sequence_no'] ?? '(n/a)') ?></td></tr>
      <tr><th>entity</th><td class="mono"><?= $e($f['entity'] ?? '(n/a)') ?></td></tr>
      <tr><th>detail</th><td><?= $e($f['detail']) ?></td></tr>
    </table>
  <?php endif; ?>
  <?php if ($result->notes): ?>
    <ul class="muted small"><?php foreach ($result->notes as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Recent ledger</h2>
  <table>
    <thead><tr><th>#</th><th>Action</th><th>Entity</th><th>When (UTC)</th></tr></thead>
    <tbody>
      <?php foreach ($ledger as $row): ?>
        <tr><td><?= $e($row['sequence_no']) ?></td><td><?= $e($row['action']) ?></td><td><?= $e($row['entity_type']) ?></td><td class="small"><?= $e($row['created_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$ledger): ?><tr><td colspan="4" class="muted">No ledger entries.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
