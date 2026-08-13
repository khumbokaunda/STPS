<?php /** @var array $rfqs */ /** @var array $bids */ /** @var array $teams */ ?>
<section class="card">
  <h2>Constitute an evaluation team</h2>
  <p class="muted small">Controlling officer constitutes a team for an RFQ. Add an evaluator by their username (they must hold the EvaluationTeamMember role).</p>
  <form method="post" action="/evaluation/team" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>RFQ
      <select name="rfq_id" required>
        <?php foreach ($rfqs as $r): ?><option value="<?= $e($r['id']) ?>"><?= $e($r['reference_no']) ?> (<?= $e($r['status']) ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <label>Evaluator username <input name="member_username" required></label>
    <button type="submit">Constitute team</button>
  </form>
  <?php if ($teams): ?>
    <table>
      <thead><tr><th>Team id</th><th>RFQ</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($teams as $t): ?><tr><td class="mono small"><?= $e($t['id']) ?></td><td class="mono"><?= $e($t['reference_no']) ?></td><td><?= $e($t['status']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Add an evaluation criterion</h2>
  <form method="post" action="/evaluation/criterion" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>RFQ
      <select name="rfq_id" required>
        <?php foreach ($rfqs as $r): ?><option value="<?= $e($r['id']) ?>"><?= $e($r['reference_no']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Name <input name="name" required></label>
    <label>Weight <input name="weight" inputmode="decimal" value="1.0000" required></label>
    <label>Max score <input name="maximum_score" inputmode="decimal" value="100.0000" required></label>
    <label>Sequence <input name="sequence_no" type="number" min="1" value="1" required></label>
    <button type="submit">Add criterion</button>
  </form>
</section>

<section class="card">
  <h2>Submit an evaluation score</h2>
  <p class="muted small">Scores are append-only and signed. A change is a new signed revision, never an in-place edit. Enter the team, criterion and bid ids for the RFQ being evaluated.</p>
  <form method="post" action="/evaluation/score" data-sign="generic" data-domain="PROCUREMENT-EVALUATION-SCORE-V1" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
    <label>Team id (hex) <input name="team_id" class="mono" required></label>
    <label>Criterion id (hex) <input name="criterion_id" data-canon="criterion_id" class="mono" required></label>
    <label>Bid id (hex) <input name="bid_id" data-canon="bid_id" class="mono" required></label>
    <label>Score <input name="score" data-canon="score" data-decimal="4" inputmode="decimal" required></label>
    <label>Justification <input name="justification" data-canon="justification" maxlength="4000"></label>
    <button type="submit">Sign score</button>
  </form>
</section>

<section class="card">
  <h2>Bids</h2>
  <table>
    <thead><tr><th>Bid id</th><th>RFQ</th><th>Bidder</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($bids as $b): ?>
        <tr><td class="mono small"><?= $e($b['id']) ?></td><td class="mono"><?= $e($b['reference_no']) ?></td><td><?= $e($b['bidder']) ?></td><td><?= $e($b['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$bids): ?><tr><td colspan="4" class="muted">No bids to evaluate.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
