<?php /** @var array $pending */ ?>
<section class="card">
  <h2>Approvals inbox</h2>
  <p class="muted small">Each decision is signed in your browser over the canonical approval payload and recorded as an append-only, ledgered vote. The server selects the workflow stage your role can satisfy.</p>
  <table>
    <thead><tr><th>Reference</th><th>Title</th><th>Value</th><th>Type</th><th>Decision</th></tr></thead>
    <tbody>
      <?php foreach ($pending as $r): ?>
        <tr>
          <td class="mono"><?= $e($r['reference_no']) ?></td>
          <td><?= $e($r['title']) ?></td>
          <td class="num"><?= $e($r['estimated_value']) ?> <?= $e($r['currency_code']) ?></td>
          <td><?= $e($r['procurement_type']) ?></td>
          <td>
            <form method="post" action="/approvals/role" data-sign="generic" data-domain="PROCUREMENT-APPROVAL-V1" class="inline">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="entity" value="requisition" data-canon="entity">
              <input type="hidden" name="entity_id" value="<?= $e($r['id']) ?>" data-canon="entity_id">
              <input type="hidden" name="decided_at" data-canon="decided_at" data-timestamp value="">
              <select name="decision" data-canon="decision">
                <option value="approved">approve</option>
                <option value="rejected">reject</option>
              </select>
              <button type="submit">Sign</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pending): ?><tr><td colspan="5" class="muted">Nothing awaiting approval.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
