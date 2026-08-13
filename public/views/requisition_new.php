<?php /** @var array $departments */ ?>
<section class="card narrow">
  <h2>New requisition</h2>
  <p class="muted small">This form is signed in your browser before submission. The server re-verifies the signature and appends a ledger entry in the same transaction.</p>
  <form method="post" action="/requisitions" data-sign="generic" data-domain="PROCUREMENT-REQUISITION-V1">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
    <label>Department
      <select name="department_id" data-canon="department_id" required>
        <option value="">Select…</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $e($d['id']) ?>"><?= $e($d['name']) ?> (<?= $e($d['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Reference no <input name="reference_no" data-canon="reference_no" maxlength="80" required></label>
    <label>Title <input name="title" data-canon="title" maxlength="255" required></label>
    <label>Estimated value <input name="estimated_value" data-canon="estimated_value" data-decimal="2" inputmode="decimal" required></label>
    <label>Currency
      <select name="currency_code" data-canon="currency_code">
        <option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option>
      </select>
    </label>
    <label>Procurement type
      <select name="procurement_type" data-canon="procurement_type" required>
        <option value="">Select…</option>
        <option>GOODS</option><option>WORKS</option><option>SERVICES</option><option>CONSULTING</option>
      </select>
    </label>
    <button type="submit">Sign &amp; submit</button>
  </form>
</section>
<?php if (empty($departments)): ?>
<section class="card"><p class="muted">No departments exist yet. An administrator must create one before you can submit a requisition.</p></section>
<?php endif; ?>
