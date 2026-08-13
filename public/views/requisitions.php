<?php /** @var array $items */ ?>
<section class="card">
  <div class="card-head">
    <h2>Requisitions</h2>
    <a class="btn" href="/requisitions/new">New requisition</a>
  </div>
  <table>
    <thead><tr><th>Reference</th><th>Title</th><th>Value</th><th>Type</th><th>Dept</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($items as $r): ?>
        <tr>
          <td class="mono"><?= $e($r['reference_no']) ?></td>
          <td><?= $e($r['title']) ?></td>
          <td class="num"><?= $e($r['estimated_value']) ?> <?= $e($r['currency_code']) ?></td>
          <td><?= $e($r['procurement_type']) ?></td>
          <td><?= $e($r['department']) ?></td>
          <td><span class="badge badge-<?= $e($r['status']) ?>"><?= $e($r['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="6" class="muted">No requisitions yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
