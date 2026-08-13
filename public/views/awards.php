<?php /** @var array $items */ /** @var array $rfqs */ /** @var array $bids */ ?>
<div class="page-header"><h2>Awards</h2><p>Record an award for a revealed winning bid.</p></div>

<section class="card">
  <div class="card-header">Record an award</div>
  <div class="card-body">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#award-modal"><i class="bi bi-award"></i> Record award</button>
    <p class="text-muted small mb-0 mt-2">Only a revealed bid can be awarded. The award is ledgered.</p>
  </div>
</section>

<div class="modal fade" id="award-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="/awards/record">
      <div class="modal-header"><h5 class="modal-title">Record award</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body stack-sm">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <div><label class="form-label">RFQ id (hex)</label><input class="form-control mono" name="rfq_id" required></div>
        <div><label class="form-label">Winning bid id (hex)</label><input class="form-control mono" name="winning_bid_id" required></div>
        <div><label class="form-label">Award value</label><div class="input-group"><input class="form-control" name="award_value" inputmode="decimal" required><span class="input-group-text">MWK</span></div></div>
        <div><label class="form-label">Currency</label><select class="form-select" name="currency_code"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></div>
        <p class="text-muted small mb-0">This records the award and is written to the ledger.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Record award</button></div>
    </form>
  </div>
</div>

<section class="card">
  <div class="card-header">Awards</div>
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Award id</th><th>Reference</th><th class="num">Value</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($items as $a): ?>
          <tr>
            <td><span class="hashid"><code><?= $e(substr($a['id'], 0, 12)) ?>…</code><button type="button" class="copy-btn" data-copy="<?= $e($a['id']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button></span></td>
            <td class="mono"><?= $e($a['reference_no']) ?></td><td class="num"><?= $e($a['award_value']) ?> <span class="text-muted"><?= $e($a['currency_code']) ?></span></td><td><?= View::pill($a['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="4"><div class="empty-state"><i class="bi bi-award"></i>No awards yet.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
