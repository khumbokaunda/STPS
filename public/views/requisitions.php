<?php /** @var array $items */ ?>
<div class="page-header page-header-row">
  <div><h2>Requisitions</h2><p>Requisitions you have raised.</p></div>
  <div class="header-actions"><a class="btn btn-primary" href="/requisitions/new"><i class="bi bi-plus-lg"></i> New requisition</a></div>
</div>

<section class="card">
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Reference</th><th>Title</th><th class="num">Value</th><th>Type</th><th>Department</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($items as $r): ?>
          <tr>
            <td class="mono"><?= $e($r['reference_no']) ?></td>
            <td><?= $e($r['title']) ?></td>
            <td class="num"><?= $e($r['estimated_value']) ?> <span class="text-muted"><?= $e($r['currency_code']) ?></span></td>
            <td><?= $e($r['procurement_type']) ?></td>
            <td><?= $e($r['department']) ?></td>
            <td><?= View::pill($r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="bi bi-file-earmark-plus"></i>No requisitions yet. Create your first one.</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
