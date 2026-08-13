<?php
/** @var array $items */ /** @var array $open */ /** @var ?string $bidder_id */
$phaseFor = function (string $status): array {
    // Return [commit, closed, reveal, result] states: 'done'|'current'|''.
    switch ($status) {
        case 'committed': return ['done', 'current', '', ''];
        case 'revealed':  return ['done', 'done', 'done', 'current'];
        case 'invalid':
        case 'expired':   return ['done', 'done', 'done', 'current'];
        case 'awarded':
        case 'not_awarded':
        case 'evaluated': return ['done', 'done', 'done', 'done'];
        default:          return ['current', '', '', ''];
    }
};
?>
<div class="page-header"><h2>My bids</h2><p>Sealed bids move through commit and reveal. Reveal from the same browser you committed on.</p></div>

<?php if (!$items): ?>
  <section class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-safe"></i>No bids yet. Commit one from <a href="/rfqs/open">Open RFQs</a>.</div></div></section>
<?php endif; ?>

<?php foreach ($items as $b): $ph = $phaseFor($b['status']); ?>
  <section class="card">
    <div class="card-body">
      <div class="page-header-row mb-2">
        <div><h5 class="mb-1"><?= $e($b['reference_no']) ?></h5>
          <div class="small text-muted">Committed <?= $e($b['committed_at']) ?> UTC</div></div>
        <div class="header-actions"><?= View::pill($b['status']) ?></div>
      </div>
      <ul class="stepper">
        <li class="<?= $e($ph[0]) ?>"><i class="bi <?= $ph[0] === 'done' ? 'bi-check-circle' : 'bi-1-circle' ?>"></i> Commit</li>
        <li class="<?= $e($ph[1]) ?>"><i class="bi bi-hourglass-split"></i> Closed</li>
        <li class="<?= $e($ph[2]) ?>"><i class="bi bi-unlock"></i> Reveal</li>
        <li class="<?= $e($ph[3]) ?>"><i class="bi bi-flag"></i> Result</li>
      </ul>
      <?php if ($b['status'] === 'committed'): ?>
        <div class="alert alert-info small" role="alert">
          <i class="bi bi-info-circle"></i> Reveal must happen from the <strong>same browser and device</strong> that committed, because the bid and its secret live only there. If nothing is found locally you will see a specific error.
        </div>
        <form method="post" action="/bids/reveal" data-sign="reveal" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="bid_id" value="<?= $e($b['id']) ?>">
          <input type="hidden" name="rfq_id" value="<?= $e($b['rfq_id']) ?>">
          <button type="submit" class="btn btn-primary"><i class="bi bi-unlock"></i> Reveal bid</button>
        </form>
        <div class="sign-error d-none mt-2"></div>
      <?php else: ?>
        <p class="text-muted small mb-0">Result: <?= View::pill($b['status']) ?></p>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
