<?php /** @var array $departments */ ?>
<div class="page-header">
  <h2>New requisition</h2>
  <p>Signed in your browser, then re-verified and ledgered by the server.</p>
</div>

<?php if (empty($departments)): ?>
  <section class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-building"></i>No departments exist yet. An administrator must create one first.</div></div></section>
<?php else: ?>
<section class="card">
  <div class="card-body">
    <form method="post" action="/requisitions" data-sign="generic" data-domain="PROCUREMENT-REQUISITION-V1">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
      <div class="grid-form">
        <div>
          <label class="form-label" for="rn-dept">Department</label>
          <select class="form-select" id="rn-dept" name="department_id" data-canon="department_id" required>
            <option value="">Select…</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= $e($d['id']) ?>"><?= $e($d['name']) ?> (<?= $e($d['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="rn-ref">Reference no</label>
          <input class="form-control" id="rn-ref" name="reference_no" data-canon="reference_no" maxlength="80" required>
        </div>
        <div>
          <label class="form-label" for="rn-title">Title</label>
          <input class="form-control" id="rn-title" name="title" data-canon="title" maxlength="255" required>
        </div>
        <div>
          <label class="form-label" for="rn-val">Estimated value</label>
          <div class="input-group">
            <input class="form-control" id="rn-val" name="estimated_value" data-canon="estimated_value" data-decimal="2" inputmode="decimal" required>
            <span class="input-group-text">.00</span>
          </div>
        </div>
        <div>
          <label class="form-label" for="rn-cur">Currency</label>
          <select class="form-select" id="rn-cur" name="currency_code" data-canon="currency_code">
            <option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="rn-type">Procurement type</label>
          <select class="form-select" id="rn-type" name="procurement_type" data-canon="procurement_type" required>
            <option value="">Select…</option>
            <option>GOODS</option><option>WORKS</option><option>SERVICES</option><option>CONSULTING</option>
          </select>
        </div>
      </div>
      <p class="sign-note mt-3"><i class="bi bi-pen"></i> This action is signed with your device key.</p>
      <div class="sign-error d-none mb-2"></div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> Sign &amp; submit</button>
      <a class="btn btn-outline-secondary" href="/requisitions">Cancel</a>
    </form>
  </div>
</section>
<?php endif; ?>
