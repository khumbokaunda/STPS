<?php /** @var array $items */ /** @var array $awards */ /** @var array $bidders */ ?>
<section class="card">
  <h2>Sign a contract</h2>
  <form method="post" action="/contracts/sign" data-sign="generic" data-domain="PROCUREMENT-CONTRACT-V1" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
    <label>Award id (hex) <input name="award_id" data-canon="award_id" class="mono" required></label>
    <label>Bidder
      <select name="bidder_id" required>
        <?php foreach ($bidders as $b): ?><option value="<?= $e($b['id']) ?>"><?= $e($b['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Contract number <input name="contract_number" data-canon="contract_number" required></label>
    <label>Contract value <input name="contract_value" data-canon="contract_value" data-decimal="2" inputmode="decimal" required></label>
    <label>Currency <select name="currency_code" data-canon="currency"><option>MWK</option><option>USD</option><option>EUR</option><option>GBP</option><option>ZAR</option></select></label>
    <button type="submit">Sign contract</button>
  </form>
</section>

<section class="card">
  <h2>Contracts</h2>
  <table>
    <thead><tr><th>Contract id</th><th>Number</th><th>Bidder</th><th>Value</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($items as $c): ?>
        <tr><td class="mono small"><?= $e($c['id']) ?></td><td><?= $e($c['contract_number']) ?></td><td><?= $e($c['bidder']) ?></td><td class="num"><?= $e($c['contract_value']) ?> <?= $e($c['currency_code']) ?></td><td><?= $e($c['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="5" class="muted">No contracts yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
