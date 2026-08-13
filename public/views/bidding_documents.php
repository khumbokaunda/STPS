<?php /** @var array $docs */ ?>
<section class="card">
  <h2>Bidding documents</h2>
  <p class="muted small">Document versions are content-hashed and version-approved before an RFQ can reference a version. (Version upload and approval are performed by the PDU workflow; this list shows current documents.)</p>
  <table>
    <thead><tr><th>Requisition</th><th>Title</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($docs as $d): ?>
        <tr><td class="mono"><?= $e($d['reference_no']) ?></td><td><?= $e($d['title']) ?></td><td><span class="badge badge-<?= $e($d['status']) ?>"><?= $e($d['status']) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (!$docs): ?><tr><td colspan="3" class="muted">No bidding documents yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
