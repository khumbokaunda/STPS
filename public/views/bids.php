<?php /** @var array $items */ /** @var array $open */ /** @var ?string $bidder_id */ ?>
<section class="card">
  <h2>My bids</h2>
  <table>
    <thead><tr><th>RFQ</th><th>Status</th><th>Committed (UTC)</th><th>Reveal</th></tr></thead>
    <tbody>
      <?php foreach ($items as $b): ?>
        <tr>
          <td class="mono"><?= $e($b['reference_no']) ?></td>
          <td><span class="badge badge-<?= $e($b['status']) ?>"><?= $e($b['status']) ?></span></td>
          <td class="small"><?= $e($b['committed_at']) ?></td>
          <td>
            <?php if ($b['status'] === 'committed'): ?>
              <form method="post" action="/bids/reveal" data-sign="reveal" class="inline">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="bid_id" value="<?= $e($b['id']) ?>">
                <input type="hidden" name="rfq_id" value="<?= $e($b['rfq_id']) ?>">
                <button type="submit">Reveal</button>
              </form>
            <?php else: ?>&mdash;<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" class="muted">No bids yet. Commit one from Open RFQs.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p class="muted small">Reveal uses the bid and nonce this browser kept locally at commit time. Reveal on the same device you committed from.</p>
</section>
