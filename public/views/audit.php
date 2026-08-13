<?php
/** @var VerificationResult $result */ /** @var array $ledger */
$pass = $result->pass;
$failedCheck = (!$pass && !empty($result->failures)) ? $result->failures[0]['check'] : null;
$checks = [
  ['payload_hash', 'Payload hashes reproduce'],
  ['entry_hash', 'Entry hashes recompute'],
  ['chain_linkage', 'Chain linkage intact'],
  ['sequence_continuity', 'Sequence continuity (no gaps)'],
  ['approval_signature', 'Approval signatures verify'],
  ['reveal_signature', 'Bid reveal signatures verify'],
  ['signature', 'Actor signatures / keys valid'],
  ['merkle_root', 'Merkle roots match'],
  ['anchor', 'External RFC 3161 anchors'],
  ['commitment', 'Revealed bid commitments'],
  ['committee_decision', 'Committee quorum outcomes'],
  ['timing', 'Timing invariants'],
  ['authorization', 'Authorization at decision time'],
];
?>
<div class="page-header page-header-row">
  <div><h2>Audit</h2><p>Independent verification against the current database. For a real audit, run <code>php bin/verify.php</code> with read-only credentials, trusting no application code.</p></div>
  <div class="header-actions"><button id="run-audit" class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Run verification</button></div>
</div>

<section class="card">
  <div class="card-body">
    <div id="audit-report">
      <div class="verdict-banner <?= $pass ? 'verdict-pass' : 'verdict-fail' ?>">
        <i class="bi <?= $pass ? 'bi-shield-check' : 'bi-shield-exclamation' ?>"></i>
        <span class="big"><?= $pass ? 'PASS' : 'FAIL' ?></span>
        <span><?= $pass ? 'No unauthorized historical modification detected.' : 'A break was detected. See below.' ?></span>
      </div>

      <?php if (!$pass && !empty($result->failures)): $f = $result->failures[0]; ?>
        <div class="mt-3 p-3 rounded border">
          <div class="fw-semibold mb-1">First break</div>
          <dl class="row small mb-0 mt-2">
            <dt class="col-4 text-muted">check</dt><dd class="col-8 mono"><?= $e($f['check']) ?></dd>
            <dt class="col-4 text-muted">sequence_no</dt><dd class="col-8"><?= $e($f['sequence_no'] ?? '(n/a)') ?></dd>
            <dt class="col-4 text-muted">entity</dt><dd class="col-8 mono"><?= $e($f['entity'] ?? '(n/a)') ?></dd>
            <dt class="col-4 text-muted">detail</dt><dd class="col-8"><?= $e($f['detail']) ?></dd>
          </dl>
        </div>
      <?php endif; ?>

      <div class="mt-3">
        <?php foreach ($checks as $c): $isFail = $failedCheck === $c[0]; ?>
          <div class="check-row">
            <i class="bi <?= $isFail ? 'bi-x-circle' : 'bi-check-circle' ?>"></i>
            <span class="name"><?= $e($c[1]) ?></span>
            <?= $isFail ? '<span class="pill pill-err">FAIL</span>' : '<span class="pill pill-ok">PASS</span>' ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($result->notes): ?>
        <ul class="small text-muted mt-3">
          <?php foreach ($result->notes as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="card">
  <div class="card-header">Recent ledger</div>
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>#</th><th>Action</th><th>Entity</th><th>When (UTC)</th></tr></thead>
      <tbody>
        <?php foreach ($ledger as $row): ?>
          <tr><td class="mono"><?= $e($row['sequence_no']) ?></td><td><?= $e($row['action']) ?></td><td><?= $e($row['entity_type']) ?></td><td class="small text-muted"><?= $e($row['created_at']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$ledger): ?><tr><td colspan="4"><div class="empty-state"><i class="bi bi-link-45deg"></i>No ledger entries.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
