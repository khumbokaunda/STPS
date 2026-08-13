<?php /** @var array $rfqs */ /** @var array $bids */ /** @var array $teams */ ?>
<div class="page-header"><h2>Evaluation</h2><p>Constitute a team, define criteria, and submit signed, append-only scores.</p></div>

<section class="card">
  <div class="card-header">Constitute an evaluation team</div>
  <div class="card-body">
    <form method="post" action="/evaluation/team" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">RFQ</label>
        <select class="form-select" name="rfq_id" required>
          <?php foreach ($rfqs as $r): ?><option value="<?= $e($r['id']) ?>"><?= $e($r['reference_no']) ?> (<?= $e($r['status']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div><label class="form-label">Evaluator username</label><input class="form-control" name="member_username" required>
        <div class="form-text">Must hold the EvaluationTeamMember role.</div></div>
      <div><button type="submit" class="btn btn-primary">Constitute team</button></div>
    </form>
    <?php if ($teams): ?>
      <div class="table-wrap mt-3">
        <table class="table table-hover">
          <thead><tr><th>Team id</th><th>RFQ</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($teams as $t): ?><tr>
            <td><span class="hashid"><code><?= $e(substr($t['id'], 0, 12)) ?>…</code><button type="button" class="copy-btn" data-copy="<?= $e($t['id']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button></span></td>
            <td class="mono"><?= $e($t['reference_no']) ?></td><td><?= View::pill($t['status']) ?></td></tr><?php endforeach; ?></tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="card-header">Add an evaluation criterion</div>
  <div class="card-body">
    <form method="post" action="/evaluation/criterion" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">RFQ</label><select class="form-select" name="rfq_id" required><?php foreach ($rfqs as $r): ?><option value="<?= $e($r['id']) ?>"><?= $e($r['reference_no']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label">Name</label><input class="form-control" name="name" required></div>
      <div><label class="form-label">Weight</label><input class="form-control" name="weight" inputmode="decimal" value="1.0000" required></div>
      <div><label class="form-label">Max score</label><input class="form-control" name="maximum_score" inputmode="decimal" value="100.0000" required></div>
      <div><label class="form-label">Sequence</label><input class="form-control" type="number" min="1" name="sequence_no" value="1" required></div>
      <div><button type="submit" class="btn btn-primary">Add criterion</button></div>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header">Submit an evaluation score</div>
  <div class="card-body">
    <p class="text-muted small">Scores are append-only. A change is a new signed revision, never an in-place edit, so a changed score is visible rather than silent.</p>
    <form method="post" action="/evaluation/score" data-sign="generic" data-domain="PROCUREMENT-EVALUATION-SCORE-V1" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="created_at" data-canon="created_at" data-timestamp value="">
      <div><label class="form-label">Team id (hex)</label><input class="form-control mono" name="team_id" required></div>
      <div><label class="form-label">Criterion id (hex)</label><input class="form-control mono" name="criterion_id" data-canon="criterion_id" required></div>
      <div><label class="form-label">Bid id (hex)</label><input class="form-control mono" name="bid_id" data-canon="bid_id" required></div>
      <div><label class="form-label">Score</label><input class="form-control" name="score" data-canon="score" data-decimal="4" inputmode="decimal" required></div>
      <div><label class="form-label">Justification</label><input class="form-control" name="justification" data-canon="justification" maxlength="4000"></div>
      <div><button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> Sign score</button></div>
    </form>
    <div class="sign-error d-none mt-2"></div>
  </div>
</section>

<section class="card">
  <div class="card-header">Bids</div>
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Bid id</th><th>RFQ</th><th>Bidder</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($bids as $b): ?>
          <tr>
            <td><span class="hashid"><code><?= $e(substr($b['id'], 0, 12)) ?>…</code><button type="button" class="copy-btn" data-copy="<?= $e($b['id']) ?>" title="Copy"><i class="bi bi-clipboard"></i></button></span></td>
            <td class="mono"><?= $e($b['reference_no']) ?></td><td><?= $e($b['bidder']) ?></td><td><?= View::pill($b['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$bids): ?><tr><td colspan="4"><div class="empty-state"><i class="bi bi-clipboard-data"></i>No bids to evaluate.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
