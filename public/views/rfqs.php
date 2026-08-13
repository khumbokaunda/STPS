<?php /** @var array $items */ /** @var array $approved */ ?>
<section class="card">
  <h2>Prepare an RFQ from an approved requisition</h2>
  <?php if ($approved): ?>
    <form method="post" action="/rfqs/prepare" class="row">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label>Requisition
        <select name="requisition_id" required>
          <?php foreach ($approved as $r): ?>
            <option value="<?= $e($r['id']) ?>"><?= $e($r['reference_no']) ?> — <?= $e($r['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Method
        <select name="procurement_method">
          <option>OPEN_TENDER</option><option>RESTRICTED_TENDER</option><option>REQUEST_FOR_QUOTATIONS</option><option>SINGLE_SOURCE</option>
        </select>
      </label>
      <button type="submit">Prepare draft RFQ</button>
    </form>
  <?php else: ?>
    <p class="muted">No approved requisitions awaiting an RFQ.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>RFQs</h2>
  <p class="muted small">Publishing an RFQ locks its timing (bid deadline, reveal window). The exact timing is captured in the ledger, so any later change is detectable even if a trigger is bypassed.</p>
  <table>
    <thead><tr><th>Reference</th><th>Method</th><th>Status</th><th>Bid deadline (UTC)</th><th>Reveal window (UTC)</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $r): ?>
        <tr>
          <td class="mono"><?= $e($r['reference_no']) ?></td>
          <td><?= $e($r['procurement_method']) ?></td>
          <td><span class="badge badge-<?= $e($r['status']) ?>"><?= $e($r['status']) ?></span></td>
          <td class="small"><?= $e($r['bid_deadline']) ?></td>
          <td class="small"><?= $e($r['reveal_start']) ?> → <?= $e($r['reveal_deadline']) ?></td>
          <td>
            <?php if ($r['status'] === 'draft'): ?>
              <details>
                <summary>Publish</summary>
                <form method="post" action="/rfqs/publish" data-sign="generic" data-domain="PROCUREMENT-REQUISITION-V1" class="stack">
                  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="rfq_id" value="<?= $e($r['id']) ?>" data-canon="rfq_id">
                  <label>Bid deadline <input type="datetime-local" name="bid_deadline" data-canon="bid_deadline" required></label>
                  <label>Reveal start <input type="datetime-local" name="reveal_start" data-canon="reveal_start" required></label>
                  <label>Reveal deadline <input type="datetime-local" name="reveal_deadline" data-canon="reveal_deadline" required></label>
                  <button type="submit">Sign &amp; publish</button>
                </form>
              </details>
            <?php elseif ($r['status'] === 'published'): ?>
              <form method="post" action="/rfqs/close" class="inline">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="rfq_id" value="<?= $e($r['id']) ?>">
                <button type="submit">Close</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="6" class="muted">No RFQs yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
