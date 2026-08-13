<?php /** @var array $docs */ ?>
<div class="page-header"><h2>Bidding documents</h2><p>Documents are content-hashed and version-approved. Post-approval change is exactly what the system detects.</p></div>

<section class="card">
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Requisition</th><th>Title</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
          <tr><td class="mono"><?= $e($d['reference_no']) ?></td><td><?= $e($d['title']) ?></td><td><?= View::pill($d['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$docs): ?><tr><td colspan="3"><div class="empty-state"><i class="bi bi-journal-text"></i>No bidding documents yet. Prepare an RFQ from an approved requisition.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
