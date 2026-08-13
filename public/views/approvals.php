<?php /** @var array $pending */ ?>
<div class="page-header">
  <h2>Approvals</h2>
  <p>Items awaiting your signed decision. The server selects the workflow stage your role can satisfy.</p>
</div>

<section class="card">
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Reference</th><th>Title</th><th class="num">Value</th><th>Type</th><th>Decision</th></tr></thead>
      <tbody>
        <?php foreach ($pending as $r): ?>
          <tr>
            <td class="mono"><?= $e($r['reference_no']) ?></td>
            <td><?= $e($r['title']) ?></td>
            <td class="num"><?= $e($r['estimated_value']) ?> <span class="text-muted"><?= $e($r['currency_code']) ?></span></td>
            <td><?= $e($r['procurement_type']) ?></td>
            <td>
              <form method="post" action="/approvals/role" data-sign="generic" data-domain="PROCUREMENT-APPROVAL-V1" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="entity" value="requisition" data-canon="entity">
                <input type="hidden" name="entity_id" value="<?= $e($r['id']) ?>" data-canon="entity_id">
                <input type="hidden" name="decided_at" data-canon="decided_at" data-timestamp value="">
                <select class="form-select form-select-sm w-auto" name="decision" data-canon="decision" aria-label="Decision">
                  <option value="approved">approve</option>
                  <option value="rejected">reject</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-pen"></i> Sign</button>
              </form>
              <div class="sign-error d-none small mt-1"></div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pending): ?>
          <tr><td colspan="5"><div class="empty-state"><i class="bi bi-check2-all"></i>Nothing awaiting approval.</div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<p class="sign-note"><i class="bi bi-pen"></i> Each decision is signed with your device key and recorded as an append-only ledgered vote.</p>
