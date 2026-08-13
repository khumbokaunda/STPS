<?php /** @var array $committees */ /** @var array $awards */ ?>
<div class="page-header"><h2>Committee</h2><p>Open decisions and cast signed votes. Quorum is recomputable from the linked votes.</p></div>

<section class="card">
  <div class="card-header">Open a committee decision</div>
  <div class="card-body">
    <form method="post" action="/committee/open" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div><label class="form-label">Committee</label>
        <select class="form-select" name="committee_id" required>
          <?php foreach ($committees as $c): ?><option value="<?= $e($c['id']) ?>"><?= $e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="form-label">Entity</label>
        <select class="form-select" name="entity"><option>award</option><option>bidding_document</option></select></div>
      <div><label class="form-label">Entity id (hex)</label><input class="form-control mono" name="entity_id" required></div>
      <div><label class="form-label">Quorum</label><input class="form-control" type="number" min="1" name="quorum" value="2" required></div>
      <div><button type="submit" class="btn btn-primary">Open decision</button></div>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header">Cast a signed vote</div>
  <div class="card-body">
    <form method="post" action="/committee/vote" data-sign="generic" data-domain="PROCUREMENT-APPROVAL-V1" class="grid-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="decided_at" data-canon="decided_at" data-timestamp value="">
      <div><label class="form-label">Stage id (hex)</label><input class="form-control mono" name="stage_id" required></div>
      <div><label class="form-label">Decision id (hex)</label><input class="form-control mono" name="committee_decision_id" required></div>
      <div><label class="form-label">Entity</label><select class="form-select" name="entity" data-canon="entity"><option>award</option><option>bidding_document</option></select></div>
      <div><label class="form-label">Entity id (hex)</label><input class="form-control mono" name="entity_id" data-canon="entity_id" required></div>
      <div><label class="form-label">Vote</label><select class="form-select" name="decision" data-canon="decision"><option>approved</option><option>rejected</option></select></div>
      <div><button type="submit" class="btn btn-primary"><i class="bi bi-pen"></i> Sign vote</button></div>
    </form>
    <p class="sign-note mt-2"><i class="bi bi-pen"></i> Signed with your device key. Only members valid at the decision time may vote.</p>
    <div class="sign-error d-none"></div>
  </div>
</section>

<section class="card">
  <div class="card-header">Awards (candidates for committee approval)</div>
  <div class="table-wrap">
    <table class="table table-hover">
      <thead><tr><th>Reference</th><th class="num">Value</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($awards as $a): ?>
          <tr><td class="mono"><?= $e($a['reference_no']) ?></td><td class="num"><?= $e($a['award_value']) ?> <span class="text-muted"><?= $e($a['currency_code']) ?></span></td><td><?= View::pill($a['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$awards): ?><tr><td colspan="3"><div class="empty-state"><i class="bi bi-award"></i>No awards yet.</div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
