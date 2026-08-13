<?php
declare(strict_types=1);

require_once __DIR__ . '/Services.php';

/**
 * EvaluationService  --  constitute team, append-only scoring with revisions,
 * and report submission (build spec sections 13, 15). A score change is a NEW
 * signed row (revision_no + supersedes_score_id), never an in-place UPDATE.
 * Ledger actions: EVAL_TEAM_CONSTITUTED, SCORE_SUBMITTED, REPORT_SUBMITTED.
 */
final class EvaluationService
{
    private Services $s;

    public function __construct(Services $s)
    {
        $this->s = $s;
    }

    /** Add an evaluation criterion to an RFQ (controlling officer). */
    public function addCriterion(
        string $userId16,
        string $rfqId16,
        string $name,
        string $weightDecimal,
        string $maxScoreDecimal,
        int $sequenceNo
    ): string {
        $this->s->authz->requireAnyRole($userId16, [Rbac::CONTROLLING_OFFICER, Rbac::PDU_OFFICER]);
        $criterionId = Uuid::bin();
        $stmt = $this->s->pdo->prepare(
            'INSERT INTO evaluation_criteria (criterion_id, rfq_id, name, weight, maximum_score, sequence_no)
             VALUES (:id, :rfq, :name, :w, :max, :seq)'
        );
        $stmt->bindValue(':id', $criterionId, PDO::PARAM_LOB);
        $stmt->bindValue(':rfq', $rfqId16, PDO::PARAM_LOB);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':w', $weightDecimal);
        $stmt->bindValue(':max', $maxScoreDecimal);
        $stmt->bindValue(':seq', $sequenceNo, PDO::PARAM_INT);
        $stmt->execute();
        return $criterionId;
    }

    /** Constitute the evaluation team for an RFQ (controlling officer). */
    public function constituteTeam(string $userId16, string $rfqId16, array $memberUserIds16): string
    {
        $this->s->authz->requireRole($userId16, Rbac::CONTROLLING_OFFICER);
        $now = Clock::now();
        $teamId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use ($teamId, $rfqId16, $userId16, $memberUserIds16, $now) {
            $stmt = $pdo->prepare(
                'INSERT INTO evaluation_teams (team_id, rfq_id, constituted_by, constituted_at, status)
                 VALUES (:id, :rfq, :by, :at, "active")'
            );
            $stmt->bindValue(':id', $teamId, PDO::PARAM_LOB);
            $stmt->bindValue(':rfq', $rfqId16, PDO::PARAM_LOB);
            $stmt->bindValue(':by', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            foreach ($memberUserIds16 as $m) {
                $ms = $pdo->prepare(
                    'INSERT INTO evaluation_team_members (membership_id, team_id, user_id, valid_from)
                     VALUES (:mid, :tid, :uid, :vf)'
                );
                $ms->bindValue(':mid', Uuid::bin(), PDO::PARAM_LOB);
                $ms->bindValue(':tid', $teamId, PDO::PARAM_LOB);
                $ms->bindValue(':uid', $m, PDO::PARAM_LOB);
                $ms->bindValue(':vf', Clock::mysql($now));
                $ms->execute();
            }
            $this->s->ledger->append($userId16, LedgerActions::EVAL_TEAM_CONSTITUTED, 'rfq', $rfqId16,
                ['team_id' => bin2hex($teamId), 'member_count' => count($memberUserIds16)]);
            return $teamId;
        });
    }

    /**
     * Submit an evaluation score (or a revision that supersedes a prior one).
     * Append-only: never updates the prior row. Evaluator must be an active team
     * member for the RFQ at submission time.
     */
    public function submitScore(
        string $userId16,
        string $teamId16,
        string $criterionId16,
        string $bidId16,
        string $scoreDecimal,
        ?string $justification,
        ?string $supersedesScoreId16,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $this->s->authz->requireRole($userId16, Rbac::EVALUATION_TEAM_MEMBER);
        $now = Clock::now();
        if (!$this->s->rbac->isEvaluationTeamMemberAt($userId16, $teamId16, $now)) {
            throw new AuthorizationException('Not an active evaluation team member.');
        }
        $payloadHash = $this->s->evidence->verify(
            DomainSeparators::EVALUATION_SCORE, $canonical, $signature, $keyId16, $userId16, $now
        );
        $revisionNo = $this->nextRevision($criterionId16, $bidId16, $userId16);
        $scoreId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $scoreId, $criterionId16, $bidId16, $userId16, $keyId16, $revisionNo,
            $supersedesScoreId16, $scoreDecimal, $justification, $payloadHash, $signature, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO evaluation_scores
                    (score_id, criterion_id, bid_id, evaluator_id, key_id, revision_no,
                     supersedes_score_id, score, justification, payload_hash, signature,
                     schema_version, created_at)
                 VALUES (:id, :crit, :bid, :ev, :key, :rev, :sup, :score, :just, :phash, :sig, :schema, :at)'
            );
            $stmt->bindValue(':id', $scoreId, PDO::PARAM_LOB);
            $stmt->bindValue(':crit', $criterionId16, PDO::PARAM_LOB);
            $stmt->bindValue(':bid', $bidId16, PDO::PARAM_LOB);
            $stmt->bindValue(':ev', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':key', $keyId16, PDO::PARAM_LOB);
            $stmt->bindValue(':rev', $revisionNo, PDO::PARAM_INT);
            $stmt->bindValue(':sup', $supersedesScoreId16, $supersedesScoreId16 === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $stmt->bindValue(':score', $scoreDecimal);
            $stmt->bindValue(':just', $justification, $justification === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':phash', $payloadHash, PDO::PARAM_LOB);
            $stmt->bindValue(':sig', $signature, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::EVALUATION_SCORE);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append(
                $userId16, LedgerActions::SCORE_SUBMITTED, 'bid', $bidId16,
                ['criterion_id' => bin2hex($criterionId16), 'revision_no' => $revisionNo, 'payload_hash' => bin2hex($payloadHash)],
                $signature, $keyId16
            );
            return $scoreId;
        });
    }

    /** Submit the evaluation report (team lead). Ledger REPORT_SUBMITTED. */
    public function submitReport(
        string $userId16,
        string $rfqId16,
        string $teamId16,
        string $recommendation,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $this->s->authz->requireRole($userId16, Rbac::EVALUATION_TEAM_MEMBER);
        $now = Clock::now();
        $reportHash = $this->s->evidence->verify(
            DomainSeparators::EVALUATION_REPORT, $canonical, $signature, $keyId16, $userId16, $now
        );
        $reportId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $reportId, $rfqId16, $teamId16, $recommendation, $reportHash, $userId16, $signature, $keyId16, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO evaluation_reports
                    (report_id, rfq_id, evaluation_team_id, report_hash, schema_version, recommendation, submitted_at)
                 VALUES (:id, :rfq, :team, :rhash, :schema, :rec, :at)'
            );
            $stmt->bindValue(':id', $reportId, PDO::PARAM_LOB);
            $stmt->bindValue(':rfq', $rfqId16, PDO::PARAM_LOB);
            $stmt->bindValue(':team', $teamId16, PDO::PARAM_LOB);
            $stmt->bindValue(':rhash', $reportHash, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::EVALUATION_REPORT);
            $stmt->bindValue(':rec', $recommendation);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append($userId16, LedgerActions::REPORT_SUBMITTED, 'rfq', $rfqId16,
                ['report_hash' => bin2hex($reportHash)], $signature, $keyId16);
            return $reportId;
        });
    }

    private function nextRevision(string $criterionId16, string $bidId16, string $evaluatorId16): int
    {
        $stmt = $this->s->pdo->prepare(
            'SELECT COALESCE(MAX(revision_no), 0) + 1 AS next
             FROM evaluation_scores
             WHERE criterion_id = :c AND bid_id = :b AND evaluator_id = :e'
        );
        $stmt->bindValue(':c', $criterionId16, PDO::PARAM_LOB);
        $stmt->bindValue(':b', $bidId16, PDO::PARAM_LOB);
        $stmt->bindValue(':e', $evaluatorId16, PDO::PARAM_LOB);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
