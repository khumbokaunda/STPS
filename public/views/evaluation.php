<?php /** @var array $rfqs */ /** @var array $bids */ ?>
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
