<?php /** @var array $contracts */ ?>
<section class="card">
  <h2>Record a delivery</h2>
  <form method="post" action="/execution/delivery" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Purchase order id (hex) <input name="purchase_order_id" class="mono" required></label>
    <label>Delivery reference <input name="delivery_reference" required></label>
    <button type="submit">Record delivery</button>
  </form>
</section>

<section class="card">
  <h2>Record an inspection</h2>
  <p class="muted small">Inspections are signed by the inspector and ledgered.</p>
  <form method="post" action="/execution/inspection" data-sign="generic" data-domain="PROCUREMENT-INSPECTION-V1" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
    <label>Delivery id (hex) <input name="delivery_id" data-canon="delivery_id" class="mono" required></label>
    <label>Result
      <select name="result" data-canon="result"><option>accepted</option><option>partially_accepted</option><option>rejected</option></select>
    </label>
    <button type="submit">Sign inspection</button>
  </form>
</section>

<section class="card">
  <h2>Contracts (for reference)</h2>
  <table>
    <thead><tr><th>Contract id</th><th>Number</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($contracts as $c): ?>
        <tr><td class="mono small"><?= $e($c['id']) ?></td><td><?= $e($c['contract_number']) ?></td><td><?= $e($c['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$contracts): ?><tr><td colspan="3" class="muted">No contracts yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
