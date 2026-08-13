<?php /** @var array $items */ /** @var array $approved */ ?>
<div class="page-header"><h2>RFQs</h2><p>Prepare, publish, and close requests for quotations. Publishing locks the timing and records it in the ledger.</p></div>

<section class="card">
  <div class="card-header">Prepare an RFQ from an approved requisition</div>
  <div class="card-body">
    <?php if ($approved): ?>
      <form method="post" action="/rfqs/prepare" class="grid-form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <div><label class="form-label">Requisition</label>
          <select class="form-select" name="requisition_id" required>
            <?php foreach ($approved as $r): ?><option value="<?= $e($r['id']) ?>"><?= $e($r['reference_no']) ?> — <?= $e($r['title']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="form-label">Method</label>
          <select class="form-select" name="procurement_method"><option>OPEN_TENDER</option><option>RESTRICTED_TENDER</option><option>REQUEST_FOR_QUOTATIONS</option><option>SINGLE_SOURCE</option></select></div>
        <div><button type="submit" class="btn btn-primary">Prepare draft RFQ</button></div>
      </form>
    <?php else: ?>
      <div class="empty-state"><i class="bi bi-clipboard-check"></i>No approved requisitions awaiting an RFQ.</div>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Reference</th><th>Method</th><th>Status</th><th>Bid deadline</th><th>Reveal window</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($items as $r): ?>
          <tr>
            <td class="mono"><?= $e($r['reference_no']) ?></td>
            <td><?= $e($r['procurement_method']) ?></td>
            <td><?= View::pill($r['status']) ?></td>
            <td class="small text-muted"><?= $e($r['bid_deadline']) ?></td>
            <td class="small text-muted"><?= $e($r['reveal_start']) ?> → <?= $e($r['reveal_deadline']) ?></td>
            <td>
              <?php if ($r['status'] === 'draft'): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#pub-<?= $e($r['id']) ?>"><i class="bi bi-megaphone"></i> Publish</button>
              <?php elseif ($r['status'] === 'published'): ?>
                <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#close-<?= $e($r['id']) ?>">Close</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-megaphone"></i>No RFQs yet.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php foreach ($items as $r): ?>
  <?php if ($r['status'] === 'draft'): ?>
    <div class="modal fade" id="pub-<?= $e($r['id']) ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="/rfqs/publish" data-sign="generic" data-domain="PROCUREMENT-REQUISITION-V1">
          <div class="modal-header"><h5 class="modal-title">Publish <?= $e($r['reference_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body stack-sm">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="rfq_id" value="<?= $e($r['id']) ?>" data-canon="rfq_id">
            <p class="text-muted small">On publish, the timing below becomes <strong>immutable</strong> and is captured in the ledger. Any later change is independently detectable.</p>
            <div><label class="form-label">Bid deadline</label><input class="form-control" type="datetime-local" name="bid_deadline" data-canon="bid_deadline" required></div>
            <div><label class="form-label">Reveal start</label><input class="form-control" type="datetime-local" name="reveal_start" data-canon="reveal_start" required></div>
            <div><label class="form-label">Reveal deadline</label><input class="form-control" type="datetime-local" name="reveal_deadline" data-canon="reveal_deadline" required></div>
            <p class="sign-note"><i class="bi bi-pen"></i> Signed with your device key.</p>
            <div class="sign-error d-none"></div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> Sign &amp; publish</button></div>
        </form>
      </div>
    </div>
  <?php elseif ($r['status'] === 'published'): ?>
    <div class="modal fade" id="close-<?= $e($r['id']) ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form class="modal-content" method="post" action="/rfqs/close">
          <div class="modal-header"><h5 class="modal-title">Close <?= $e($r['reference_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="rfq_id" value="<?= $e($r['id']) ?>">
            <p>Close bidding for this RFQ? This is recorded in the ledger and opens the reveal phase. It cannot be undone.</p>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Close RFQ</button></div>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endforeach; ?>
