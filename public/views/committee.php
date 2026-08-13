<?php /** @var array $committees */ /** @var array $awards */ ?>
<section class="card">
  <h2>Open a committee decision</h2>
  <p class="muted small">The summary decision is recomputable from the linked signed votes and the quorum. Only members whose validity covers the decision time may vote.</p>
  <form method="post" action="/committee/open" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Committee
      <select name="committee_id" required>
        <?php foreach ($committees as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Entity
      <select name="entity"><option>award</option><option>bidding_document</option></select>
    </label>
    <label>Entity id (hex) <input name="entity_id" class="mono" required></label>
    <label>Quorum <input name="quorum" type="number" min="1" value="2" required></label>
    <button type="submit">Open decision</button>
  </form>
</section>

<section class="card">
  <h2>Cast a signed vote</h2>
  <p class="muted small">Paste the committee decision id and workflow stage id, then sign your vote.</p>
  <form method="post" action="/committee/vote" data-sign="generic" data-domain="PROCUREMENT-APPROVAL-V1" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="decided_at" data-canon="decided_at" data-timestamp value="">
    <label>Stage id (hex) <input name="stage_id" class="mono" required></label>
    <label>Decision id (hex) <input name="committee_decision_id" class="mono" required></label>
    <label>Entity
      <select name="entity" data-canon="entity"><option>award</option><option>bidding_document</option></select>
    </label>
    <label>Entity id (hex) <input name="entity_id" class="mono" data-canon="entity_id" required></label>
    <label>Vote
      <select name="decision" data-canon="decision"><option>approved</option><option>rejected</option></select>
    </label>
    <button type="submit">Sign vote</button>
  </form>
</section>

<section class="card">
  <h2>Awards (candidates for committee approval)</h2>
  <table>
    <thead><tr><th>Reference</th><th>Value</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($awards as $a): ?>
        <tr><td class="mono"><?= $e($a['reference_no']) ?></td><td class="num"><?= $e($a['award_value']) ?> <?= $e($a['currency_code']) ?></td><td><?= $e($a['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$awards): ?><tr><td colspan="3" class="muted">No awards yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
