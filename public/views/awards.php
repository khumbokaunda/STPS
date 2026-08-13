<?php /** @var array $items */ /** @var array $rfqs */ /** @var array $bids */ ?>
<section class="card">
  <h2>Record an award</h2>
  <form method="post" action="/awards/record" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>RFQ id (hex) <input name="rfq_id" class="mono" required></label>
    <label>Winning bid id (hex) <input name="winning_bid_id" class="mono" required></label>
    <label>Award value <input name="award_value" inputmode="decimal" required></label>
    <label>Currency <select name="currency_code"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></label>
    <button type="submit">Record award</button>
  </form>
  <p class="muted small">Only a revealed bid can be awarded. The award is ledgered as a system-actor event.</p>
</section>

<section class="card">
  <h2>Awards</h2>
  <table>
    <thead><tr><th>Award id</th><th>Reference</th><th>Value</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($items as $a): ?>
        <tr><td class="mono small"><?= $e($a['id']) ?></td><td class="mono"><?= $e($a['reference_no']) ?></td><td class="num"><?= $e($a['award_value']) ?> <?= $e($a['currency_code']) ?></td><td><?= $e($a['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="4" class="muted">No awards yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
