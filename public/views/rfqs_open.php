<?php /** @var array $items */ /** @var ?string $bidder_id */ ?>
<div class="page-header"><h2>Open RFQs</h2><p>Opportunities open for sealed bids.</p></div>

<?php if (!$bidder_id): ?>
  <section class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-exclamation-triangle"></i>Your account is not linked to a bidder organisation. An administrator must link it before you can bid.</div></div></section>
<?php endif; ?>

<section class="card">
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Reference</th><th>Method</th><th>Closes in</th><th>Bid deadline (UTC)</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($items as $r): ?>
          <tr>
            <td class="mono"><?= $e($r['reference_no']) ?></td>
            <td><?= $e($r['procurement_method']) ?></td>
            <td><span class="countdown" data-deadline="<?= $e(str_replace(' ', 'T', $r['bid_deadline'])) ?>Z" data-expired-text="closed">—</span></td>
            <td class="small text-muted"><?= $e($r['bid_deadline']) ?></td>
            <td>
              <?php if ($bidder_id): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#commit-<?= $e($r['id']) ?>"><i class="bi bi-safe"></i> Commit bid</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-inboxes"></i>No open RFQs right now.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($bidder_id): foreach ($items as $r): ?>
  <div class="modal fade" id="commit-<?= $e($r['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" method="post" action="/bids/commit" data-sign="commit">
        <div class="modal-header"><h5 class="modal-title">Commit a sealed bid — <?= $e($r['reference_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body stack-sm">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="rfq_id" value="<?= $e($r['id']) ?>">
          <input type="hidden" name="bidder_id" value="<?= $e($bidder_id) ?>">
          <div>
            <label class="form-label">Bid amount</label>
            <div class="input-group"><input class="form-control" name="amount" inputmode="decimal" required><span class="input-group-text">MWK</span></div>
          </div>
          <div class="alert alert-warning small mb-0" role="alert">
            <i class="bi bi-lock"></i> The amount is committed and <strong>cannot be changed</strong>. Only the commitment and its signature are sent now; the amount and a secret nonce stay in <strong>this browser</strong> for the later reveal.
          </div>
          <p class="sign-note mb-0"><i class="bi bi-pen"></i> Signed with your device key.</p>
          <div class="sign-error d-none"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-safe"></i> Commit bid</button></div>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>
