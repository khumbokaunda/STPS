<?php
declare(strict_types=1);

require_once __DIR__ . '/Services.php';

/**
 * ApprovalService  --  workflow selection, role-stage and committee-stage
 * approvals (build spec section 13). Ledger action APPROVAL_RECORDED, signed by
 * the approver. Committee decisions (AWARD_DECIDED / DOCUMENT_APPROVED) are
 * recomputable from the linked signed votes and quorum_required.
 *
 * Every approval is a real client signature over the canonical approval payload,
 * with key_id recorded (append-only; corrections are new rows).
 */
final class ApprovalService
{
    private Services $s;

    public function __construct(Services $s)
    {
        $this->s = $s;
    }

    /**
     * Select the workflow whose band contains $amount for $procurementType, then
     * return its ordered stages.
     */
    public function selectWorkflow(string $procurementType, string $amountDecimal): array
    {
        $stmt = $this->s->pdo->prepare(
            'SELECT workflow_id FROM approval_workflows
             WHERE status = "active" AND procurement_type = :ptype
               AND min_amount <= :amt1
               AND (max_amount IS NULL OR max_amount >= :amt2)
             ORDER BY min_amount DESC LIMIT 1'
        );
        $stmt->bindValue(':ptype', $procurementType);
        $stmt->bindValue(':amt1', $amountDecimal);
        $stmt->bindValue(':amt2', $amountDecimal);
        $stmt->execute();
        $wf = $stmt->fetch();
        if ($wf === false) {
            throw new RuntimeException('No approval workflow matches that amount/type.');
        }
        $stages = $this->s->pdo->prepare(
            'SELECT stage_id, sequence_no, stage_type, required_role, required_committee,
                    min_approvers, requires_all
             FROM workflow_stages WHERE workflow_id = :wid ORDER BY sequence_no ASC'
        );
        $stages->bindValue(':wid', $wf['workflow_id'], PDO::PARAM_LOB);
        $stages->execute();
        return ['workflow_id' => $wf['workflow_id'], 'stages' => $stages->fetchAll()];
    }

    /**
     * Record a role-stage approval (one signed vote). committee_decision_id null.
     *
     * @param string $stageId16 the workflow stage being satisfied
     * @return string approval_id
     */
    public function recordRoleApproval(
        string $userId16,
        string $stageId16,
        string $entityType,
        string $entityId16,
        string $decision,        // 'approved' | 'rejected'
        ?string $reason,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $stage = $this->loadStage($stageId16);
        if ($stage['required_role'] === null) {
            throw new RuntimeException('Stage is a committee stage; use recordCommitteeVote.');
        }
        // Authorize: approver must hold the stage's required role now.
        $roleName = $this->roleName($stage['required_role']);
        $this->s->authz->requireRole($userId16, $roleName);
        // The referenced entity must exist and be in an approvable state.
        $this->s->guard->requireState($entityType, $entityId16, $this->approvableStates($entityType));

        return $this->insertApproval(
            $userId16, $stageId16, null, $entityType, $entityId16,
            $decision, $reason, $canonical, $signature, $keyId16
        );
    }

    /**
     * Open a committee decision summary row (status effectively pending). Members
     * then cast signed votes linked to it via recordCommitteeVote.
     *
     * @return string decision_id
     */
    public function openCommitteeDecision(
        string $userId16,
        string $committeeId16,
        string $entityType,
        string $entityId16,
        int $quorumRequired,
        string $decisionCanonical
    ): string {
        // Only a system/PDU actor opens the decision; membership is checked on vote.
        $this->s->guard->requireState($entityType, $entityId16, $this->approvableStates($entityType));
        $decisionId = Uuid::bin();
        $decisionHash = Hasher::sha256($decisionCanonical);
        $now = Clock::now();
        $stmt = $this->s->pdo->prepare(
            'INSERT INTO committee_decisions
                (decision_id, committee_id, entity_type, entity_id, decision,
                 quorum_required, decision_hash, schema_version, decided_at)
             VALUES (:id, :cid, :etype, :eid, "deferred", :quorum, :dhash, :schema, :decided)'
        );
        $stmt->bindValue(':id', $decisionId, PDO::PARAM_LOB);
        $stmt->bindValue(':cid', $committeeId16, PDO::PARAM_LOB);
        $stmt->bindValue(':etype', $entityType);
        $stmt->bindValue(':eid', $entityId16, PDO::PARAM_LOB);
        $stmt->bindValue(':quorum', $quorumRequired, PDO::PARAM_INT);
        $stmt->bindValue(':dhash', $decisionHash, PDO::PARAM_LOB);
        $stmt->bindValue(':schema', DomainSeparators::COMMITTEE_DECISION);
        $stmt->bindValue(':decided', Clock::mysql($now));
        $stmt->execute();
        return $decisionId;
    }

    /**
     * Cast a signed committee vote linked to a decision. Only members whose
     * validity covers the decision time may vote (checked against decided_at).
     *
     * @return string approval_id
     */
    public function recordCommitteeVote(
        string $userId16,
        string $stageId16,
        string $committeeDecisionId16,
        string $entityType,
        string $entityId16,
        string $decision,
        ?string $reason,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $stage = $this->loadStage($stageId16);
        if ($stage['required_committee'] === null) {
            throw new RuntimeException('Stage is a role stage; use recordRoleApproval.');
        }
        $decisionRow = $this->loadDecision($committeeDecisionId16);
        $decidedAt = Clock::fromMysql($decisionRow['decided_at']);
        // Authorize: active committee membership covering the decision time.
        $this->s->authz->requireCommitteeMembership($userId16, $stage['required_committee'], $decidedAt);

        return $this->insertApproval(
            $userId16, $stageId16, $committeeDecisionId16, $entityType, $entityId16,
            $decision, $reason, $canonical, $signature, $keyId16
        );
    }

    /**
     * Recompute a committee decision outcome from its linked signed votes and
     * quorum_required (the same computation the verifier performs). Returns
     * 'approved' | 'rejected' | 'deferred'.
     */
    public function tallyCommitteeDecision(string $committeeDecisionId16): string
    {
        $row = $this->loadDecision($committeeDecisionId16);
        $stmt = $this->s->pdo->prepare(
            'SELECT decision, COUNT(*) AS n FROM approvals
             WHERE committee_decision_id = :id GROUP BY decision'
        );
        $stmt->bindValue(':id', $committeeDecisionId16, PDO::PARAM_LOB);
        $stmt->execute();
        $counts = ['approved' => 0, 'rejected' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $counts[$r['decision']] = (int) $r['n'];
        }
        $quorum = (int) $row['quorum_required'];
        if ($counts['approved'] >= $quorum) {
            return 'approved';
        }
        if ($counts['rejected'] >= $quorum) {
            return 'rejected';
        }
        return 'deferred';
    }

    // ---- internals -------------------------------------------------------

    private function insertApproval(
        string $userId16,
        string $stageId16,
        ?string $committeeDecisionId16,
        string $entityType,
        string $entityId16,
        string $decision,
        ?string $reason,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('decision must be approved|rejected.');
        }
        $now = Clock::now();
        $payloadHash = $this->s->evidence->verify(
            DomainSeparators::APPROVAL, $canonical, $signature, $keyId16, $userId16, $now
        );
        $approvalId = Uuid::bin();

        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $approvalId, $stageId16, $committeeDecisionId16, $entityType, $entityId16,
            $userId16, $keyId16, $decision, $reason, $payloadHash, $signature, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO approvals
                    (approval_id, workflow_stage_id, committee_decision_id, entity_type,
                     entity_id, approver_id, key_id, decision, reason,
                     signed_payload_hash, signature, schema_version, decided_at)
                 VALUES (:id, :stage, :cdid, :etype, :eid, :approver, :key, :decision,
                         :reason, :phash, :sig, :schema, :decided)'
            );
            $stmt->bindValue(':id', $approvalId, PDO::PARAM_LOB);
            $stmt->bindValue(':stage', $stageId16, PDO::PARAM_LOB);
            $stmt->bindValue(':cdid', $committeeDecisionId16, $committeeDecisionId16 === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $stmt->bindValue(':etype', $entityType);
            $stmt->bindValue(':eid', $entityId16, PDO::PARAM_LOB);
            $stmt->bindValue(':approver', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':key', $keyId16, PDO::PARAM_LOB);
            $stmt->bindValue(':decision', $decision);
            $stmt->bindValue(':reason', $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':phash', $payloadHash, PDO::PARAM_LOB);
            $stmt->bindValue(':sig', $signature, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::APPROVAL);
            $stmt->bindValue(':decided', Clock::mysql($now));
            $stmt->execute();

            $this->s->ledger->append(
                $userId16,
                LedgerActions::APPROVAL_RECORDED,
                $entityType,
                $entityId16,
                [
                    'decision'     => $decision,
                    'stage_id'     => bin2hex($stageId16),
                    'committee_decision_id' => $committeeDecisionId16 === null ? null : bin2hex($committeeDecisionId16),
                    'payload_hash' => bin2hex($payloadHash),
                ],
                $signature,
                $keyId16
            );
            return $approvalId;
        });
    }

    private function loadStage(string $stageId16): array
    {
        $stmt = $this->s->pdo->prepare(
            'SELECT stage_id, required_role, required_committee, min_approvers
             FROM workflow_stages WHERE stage_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $stageId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Unknown workflow stage.');
        }
        return $row;
    }

    private function loadDecision(string $decisionId16): array
    {
        $stmt = $this->s->pdo->prepare(
            'SELECT decision_id, committee_id, quorum_required, decided_at
             FROM committee_decisions WHERE decision_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $decisionId16, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Unknown committee decision.');
        }
        return $row;
    }

    private function roleName(string $roleId16): string
    {
        $stmt = $this->s->pdo->prepare('SELECT name FROM roles WHERE role_id = :id LIMIT 1');
        $stmt->bindValue(':id', $roleId16, PDO::PARAM_LOB);
        $stmt->execute();
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new RuntimeException('Unknown role on stage.');
        }
        return (string) $name;
    }

    private function approvableStates(string $entityType): array
    {
        return match ($entityType) {
            'requisition'      => ['submitted'],
            'bidding_document' => ['pending_approval'],
            'award'            => ['pending'],
            'contract'         => ['pending_signature', 'pending_no_objection'],
            default            => [],
        };
    }
}
