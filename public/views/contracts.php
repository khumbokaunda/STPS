<?php /** @var array $items */ /** @var array $awards */ /** @var array $bidders */ ?>
<div class="page-header"><h2>Contracts</h2><p>Sign contracts arising from awards. Signing is a device-key action, recorded in the ledger.</p></div>

<section class="card">
  <div class="card-header">Sign a contract</div>
  <div class="card-body">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#contract-modal"><i class="bi bi-pen"></i> Sign a contract</button>
  </div>
</section>

<div class="modal fade" id="contract-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="/contracts/sign" data-sign="generic" data-domain="PROCUREMENT-CONTRACT-V1">
      <div class="modal-header"><h5 class="modal-title">Sign contract</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body stack-sm">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
        <div><label class="form-label">Award id (hex)</label><input class="form-control mono" name="award_id" data-canon="award_id" required></div>
        <div><label class="form-label">Bidder</label><select class="form-select" name="bidder_id" required><?php foreach ($bidders as $b): ?><option value="<?= $e($b['id']) ?>"><?= $e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label">Contract number</label><input class="form-control" name="contract_number" data-canon="contract_number" required></div>
        <div><label class="form-label">Contract value</label><div class="input-group"><input class="form-control" name="contract_value" data-canon="contract_value" data-decimal="2" inputmode="decimal" required><span class="input-group-text">MWK</span></div></div>
        <div><label class="form-label">Currency</label><select class="form-select" name="currency_code" data-canon="currency"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></div>
        <p class="sign-note mb-0"><i class="bi bi-pen"></i> Signed with your device key and recorded in the ledger.</p>
        <div class="sign-error d-none"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> Sign contract</button></div>
    </form>
  </div>
</div>

<section class="card">
  <div class="card-header">Contracts</div>
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Contract id</th><th>Number</th><th>Bidder</th><th class="num">Value</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($items as $c): ?>
          <tr>
            <td><span class="hashid"><code><?= $e(substr($c['id'], 0, 12)) ?>…</code><button type="button" class="copy-btn" data-copy="<?= $e($c['id']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button></span></td>
            <td><?= $e($c['contract_number']) ?></td><td><?= $e($c['bidder']) ?></td><td class="num"><?= $e($c['contract_value']) ?> <span class="text-muted"><?= $e($c['currency_code']) ?></span></td><td><?= View::pill($c['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-file-earmark-check"></i>No contracts yet.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
