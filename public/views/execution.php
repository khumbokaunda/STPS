<?php /** @var array $contracts */ /** @var array $pos */ ?>
<div class="page-header"><h2>Execution</h2><p>Purchase orders, deliveries, and signed inspections.</p></div>

<section class="card">
  <div class="card-header">Issue a purchase order</div>
  <div class="card-body">
    <form method="post" action="/execution/po" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">Contract</label><select class="form-select" name="contract_id" required><?php foreach ($contracts as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['contract_number']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">PO number</label><input class="form-control" name="po_number" required></div>
      <div><label class="form-label">Total value</label><div class="input-group"><input class="form-control" name="total_value" inputmode="decimal" required><span class="input-group-text">MWK</span></div></div>
      <div><label class="form-label">Currency</label><select class="form-select" name="currency_code"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></div>
      <div><button type="submit" class="btn btn-primary">Issue PO</button></div>
    </form>
    <?php if ($pos): ?>
      <div class="table-wrap mt-3"><table class="table table-hover">
        <thead><tr><th>PO id</th><th>Number</th><th>Contract</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($pos as $p): ?><tr>
          <td><span class="hashid"><code><?= $e(substr($p['id'], 0, 12)) ?>…</code><button type="button" class="copy-btn" data-copy="<?= $e($p['id']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button></span></td>
          <td><?= $e($p['po_number']) ?></td><td><?= $e($p['contract_number']) ?></td><td><?= View::pill($p['status']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="card-header">Record a delivery</div>
  <div class="card-body">
    <form method="post" action="/execution/delivery" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">Purchase order id (hex)</label><input class="form-control mono" name="purchase_order_id" required></div>
      <div><label class="form-label">Delivery reference</label><input class="form-control" name="delivery_reference" required></div>
      <div><button type="submit" class="btn btn-primary">Record delivery</button></div>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header">Record an inspection</div>
  <div class="card-body">
    <form method="post" action="/execution/inspection" data-sign="generic" data-domain="PROCUREMENT-INSPECTION-V1" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
      <div><label class="form-label">Delivery id (hex)</label><input class="form-control mono" name="delivery_id" data-canon="delivery_id" required></div>
      <div><label class="form-label">Result</label><select class="form-select" name="result" data-canon="result"><option>accepted</option><option>partially_accepted</option><option>rejected</option></select></div>
      <div><button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> Sign inspection</button></div>
    </form>
    <p class="sign-note mt-2 mb-0"><i class="bi bi-pen"></i> Signed with your device key.</p>
    <div class="sign-error d-none"></div>
  </div>
</section>
