<?php /** @var array $contracts */ ?>
<div class="page-header"><h2>Finance</h2><p>Submit invoices and record payments.</p></div>

<section class="card">
  <div class="card-header">Submit an invoice</div>
  <div class="card-body">
    <form method="post" action="/finance/invoice" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">Contract</label><select class="form-select" name="contract_id" required><?php foreach ($contracts as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['contract_number']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">Invoice number</label><input class="form-control" name="invoice_number" required></div>
      <div><label class="form-label">Amount</label><div class="input-group"><input class="form-control" name="amount" inputmode="decimal" required><span class="input-group-text">MWK</span></div></div>
      <div><label class="form-label">Currency</label><select class="form-select" name="currency_code"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></div>
      <div><button type="submit" class="btn btn-primary">Submit invoice</button></div>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header">Record a payment</div>
  <div class="card-body">
    <form method="post" action="/finance/payment" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">Invoice id (hex)</label><input class="form-control mono" name="invoice_id" required></div>
      <div><label class="form-label">Amount</label><div class="input-group"><input class="form-control" name="amount" inputmode="decimal" required><span class="input-group-text">MWK</span></div></div>
      <div><label class="form-label">Payment reference</label><input class="form-control" name="payment_reference" required></div>
      <div><button type="submit" class="btn btn-primary">Record payment</button></div>
    </form>
  </div>
</section>
