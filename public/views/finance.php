<?php /** @var array $contracts */ ?>
<section class="card">
  <h2>Submit an invoice</h2>
  <form method="post" action="/finance/invoice" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Contract
      <select name="contract_id" required>
        <?php foreach ($contracts as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['contract_number']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Invoice number <input name="invoice_number" required></label>
    <label>Amount <input name="amount" inputmode="decimal" required></label>
    <label>Currency <select name="currency_code"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></label>
    <button type="submit">Submit invoice</button>
  </form>
</section>

<section class="card">
  <h2>Record a payment</h2>
  <form method="post" action="/finance/payment" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Invoice id (hex) <input name="invoice_id" class="mono" required></label>
    <label>Amount <input name="amount" inputmode="decimal" required></label>
    <label>Payment reference <input name="payment_reference" required></label>
    <button type="submit">Record payment</button>
  </form>
</section>
