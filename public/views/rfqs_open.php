<?php /** @var array $items */ /** @var ?string $bidder_id */ ?>
<section class="card">
  <h2>Open RFQs</h2>
  <?php if (!$bidder_id): ?>
    <p class="flash flash-err">Your account is not linked to a bidder organisation. An administrator must link it before you can bid.</p>
  <?php endif; ?>
  <table>
    <thead><tr><th>Reference</th><th>Method</th><th>Bid deadline (UTC)</th><th>Commit</th></tr></thead>
    <tbody>
      <?php foreach ($items as $r): ?>
        <tr>
          <td class="mono"><?= $e($r['reference_no']) ?></td>
          <td><?= $e($r['procurement_method']) ?></td>
          <td class="small"><?= $e($r['bid_deadline']) ?></td>
          <td>
            <?php if ($bidder_id): ?>
              <details>
                <summary>Commit a sealed bid</summary>
                <form method="post" action="/bids/commit" data-sign="commit" class="stack">
                  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="rfq_id" value="<?= $e($r['id']) ?>">
                  <input type="hidden" name="bidder_id" value="<?= $e($bidder_id) ?>">
                  <label>Bid amount (MWK) <input name="amount" inputmode="decimal" required></label>
                  <p class="muted small">Only the commitment and its signature are sent. The amount and a random nonce stay in this browser until you reveal.</p>
                  <button type="submit">Commit bid</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" class="muted">No open RFQs.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
