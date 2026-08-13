<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/Uuid.php';

/**
 * ReadModel  --  read-only queries backing the UI screens. Never writes. All
 * access is via PDO prepared statements. Binary ids are returned as lowercase
 * hex for convenient rendering; callers convert back with Uuid::fromString.
 */
final class ReadModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function q(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_string($v) && strlen($v) === 16 ? PDO::PARAM_LOB : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function departments(): array
    {
        $rows = $this->q('SELECT department_id, name, code FROM departments ORDER BY name');
        return array_map(fn($r) => ['id' => bin2hex($r['department_id']), 'name' => $r['name'], 'code' => $r['code']], $rows);
    }

    public function bidders(): array
    {
        $rows = $this->q('SELECT bidder_id, legal_name, verification_status FROM bidders ORDER BY legal_name');
        return array_map(fn($r) => ['id' => bin2hex($r['bidder_id']), 'name' => $r['legal_name'], 'status' => $r['verification_status']], $rows);
    }

    public function roles(): array
    {
        return array_map(fn($r) => $r['name'], $this->q('SELECT name FROM roles ORDER BY name'));
    }

    public function committees(): array
    {
        $rows = $this->q('SELECT committee_id, name, committee_type FROM committees WHERE status = "active" ORDER BY name');
        return array_map(fn($r) => ['id' => bin2hex($r['committee_id']), 'name' => $r['name'], 'type' => $r['committee_type']], $rows);
    }

    public function requisitions(?string $requesterId16 = null): array
    {
        $sql = 'SELECT r.requisition_id, r.reference_no, r.title, r.estimated_value, r.currency_code,
                       r.procurement_type, r.status, r.created_at, d.name AS department
                FROM requisitions r JOIN departments d ON d.department_id = r.department_id';
        $params = [];
        if ($requesterId16 !== null) {
            $sql .= ' WHERE r.requester_id = :req';
            $params[':req'] = $requesterId16;
        }
        $sql .= ' ORDER BY r.created_at DESC';
        return array_map(fn($r) => [
            'id' => bin2hex($r['requisition_id']),
            'reference_no' => $r['reference_no'], 'title' => $r['title'],
            'estimated_value' => $r['estimated_value'], 'currency_code' => $r['currency_code'],
            'procurement_type' => $r['procurement_type'], 'status' => $r['status'],
            'department' => $r['department'], 'created_at' => $r['created_at'],
        ], $this->q($sql, $params));
    }

    /** Requisitions awaiting approval (submitted), for the approvals inbox. */
    public function requisitionsPendingApproval(): array
    {
        $rows = $this->q("SELECT requisition_id, reference_no, title, estimated_value, currency_code, procurement_type
                          FROM requisitions WHERE status = 'submitted' ORDER BY created_at ASC");
        return array_map(fn($r) => [
            'id' => bin2hex($r['requisition_id']), 'reference_no' => $r['reference_no'],
            'title' => $r['title'], 'estimated_value' => $r['estimated_value'],
            'currency_code' => $r['currency_code'], 'procurement_type' => $r['procurement_type'],
        ], $rows);
    }

    public function workflowStagesFor(string $procurementType, string $amount): array
    {
        $wf = $this->q(
            'SELECT workflow_id, name FROM approval_workflows
             WHERE status = "active" AND procurement_type = :pt AND min_amount <= :a1
               AND (max_amount IS NULL OR max_amount >= :a2)
             ORDER BY min_amount DESC LIMIT 1',
            [':pt' => $procurementType, ':a1' => $amount, ':a2' => $amount]
        );
        if ($wf === []) {
            return [];
        }
        $stages = $this->q(
            'SELECT ws.stage_id, ws.sequence_no, ws.stage_type, ws.min_approvers,
                    r.name AS role_name, c.name AS committee_name, ws.required_committee
             FROM workflow_stages ws
             LEFT JOIN roles r ON r.role_id = ws.required_role
             LEFT JOIN committees c ON c.committee_id = ws.required_committee
             WHERE ws.workflow_id = :w ORDER BY ws.sequence_no',
            [':w' => $wf[0]['workflow_id']]
        );
        return array_map(fn($s) => [
            'stage_id' => bin2hex($s['stage_id']), 'sequence_no' => (int) $s['sequence_no'],
            'stage_type' => $s['stage_type'], 'min_approvers' => (int) $s['min_approvers'],
            'role_name' => $s['role_name'], 'committee_name' => $s['committee_name'],
            'committee_id' => $s['required_committee'] !== null ? bin2hex($s['required_committee']) : null,
        ], $stages);
    }

    /** Requisitions approved and not yet turned into an RFQ. */
    public function approvedRequisitions(): array
    {
        $rows = $this->q("SELECT requisition_id, reference_no, title, estimated_value, currency_code, procurement_type
                          FROM requisitions WHERE status = 'approved' ORDER BY created_at ASC");
        return array_map(fn($r) => [
            'id' => bin2hex($r['requisition_id']), 'reference_no' => $r['reference_no'],
            'title' => $r['title'], 'estimated_value' => $r['estimated_value'],
            'currency_code' => $r['currency_code'], 'procurement_type' => $r['procurement_type'],
        ], $rows);
    }

    public function criteria(string $rfqId16): array
    {
        $stmt = $this->pdo->prepare('SELECT criterion_id, name, weight, maximum_score, sequence_no
                                     FROM evaluation_criteria WHERE rfq_id = :r ORDER BY sequence_no');
        $stmt->bindValue(':r', $rfqId16, PDO::PARAM_LOB);
        $stmt->execute();
        return array_map(fn($r) => [
            'id' => bin2hex($r['criterion_id']), 'name' => $r['name'],
            'weight' => $r['weight'], 'maximum_score' => $r['maximum_score'], 'sequence_no' => (int) $r['sequence_no'],
        ], $stmt->fetchAll());
    }

    public function evaluationTeams(): array
    {
        $rows = $this->q('SELECT t.team_id, r.reference_no, t.status
                          FROM evaluation_teams t JOIN rfqs r ON r.rfq_id = t.rfq_id ORDER BY t.constituted_at DESC');
        return array_map(fn($r) => ['id' => bin2hex($r['team_id']), 'reference_no' => $r['reference_no'], 'status' => $r['status']], $rows);
    }

    public function purchaseOrders(): array
    {
        $rows = $this->q('SELECT po.purchase_order_id, po.po_number, po.status, c.contract_number
                          FROM purchase_orders po JOIN contracts c ON c.contract_id = po.contract_id ORDER BY po.issued_at DESC');
        return array_map(fn($r) => [
            'id' => bin2hex($r['purchase_order_id']), 'po_number' => $r['po_number'],
            'status' => $r['status'], 'contract_number' => $r['contract_number'],
        ], $rows);
    }

    /** Look up a user id (hex) by username, or null. */
    public function userIdByUsername(string $username): ?string
    {
        $rows = $this->q('SELECT user_id FROM users WHERE username = :u LIMIT 1', [':u' => $username]);
        $id = $rows[0]['user_id'] ?? null;
        return $id ? bin2hex($id) : null;
    }

    public function rfqs(?string $status = null): array
    {
        $sql = 'SELECT r.rfq_id, r.reference_no, r.procurement_method, r.status, r.bid_deadline,
                       r.reveal_start, r.reveal_deadline, r.published_at
                FROM rfqs r';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE r.status = :s';
            $params[':s'] = $status;
        }
        $sql .= ' ORDER BY r.created_at DESC';
        return array_map(fn($r) => [
            'id' => bin2hex($r['rfq_id']), 'reference_no' => $r['reference_no'],
            'procurement_method' => $r['procurement_method'], 'status' => $r['status'],
            'bid_deadline' => $r['bid_deadline'], 'reveal_start' => $r['reveal_start'],
            'reveal_deadline' => $r['reveal_deadline'], 'published_at' => $r['published_at'],
        ], $this->q($sql, $params));
    }

    public function biddingDocuments(): array
    {
        $rows = $this->q('SELECT bd.document_id, bd.status, r.reference_no, r.title
                          FROM bidding_documents bd JOIN requisitions r ON r.requisition_id = bd.requisition_id
                          ORDER BY bd.created_at DESC');
        return array_map(fn($r) => [
            'id' => bin2hex($r['document_id']), 'status' => $r['status'],
            'reference_no' => $r['reference_no'], 'title' => $r['title'],
        ], $rows);
    }

    public function bids(?string $rfqId16 = null, ?string $bidderId16 = null): array
    {
        $sql = 'SELECT b.bid_id, b.rfq_id, b.status, b.committed_at, r.reference_no, bd.legal_name AS bidder
                FROM bids b JOIN rfqs r ON r.rfq_id = b.rfq_id JOIN bidders bd ON bd.bidder_id = b.bidder_id';
        $where = [];
        $params = [];
        if ($rfqId16 !== null) { $where[] = 'b.rfq_id = :rfq'; $params[':rfq'] = $rfqId16; }
        if ($bidderId16 !== null) { $where[] = 'b.bidder_id = :bidder'; $params[':bidder'] = $bidderId16; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY b.committed_at DESC';
        return array_map(fn($r) => [
            'id' => bin2hex($r['bid_id']), 'rfq_id' => bin2hex($r['rfq_id']),
            'status' => $r['status'], 'committed_at' => $r['committed_at'],
            'reference_no' => $r['reference_no'], 'bidder' => $r['bidder'],
        ], $this->q($sql, $params));
    }

    public function awards(): array
    {
        $rows = $this->q('SELECT a.award_id, a.award_value, a.currency_code, a.status, r.reference_no
                          FROM awards a JOIN rfqs r ON r.rfq_id = a.rfq_id ORDER BY a.awarded_at DESC');
        return array_map(fn($r) => [
            'id' => bin2hex($r['award_id']), 'award_value' => $r['award_value'],
            'currency_code' => $r['currency_code'], 'status' => $r['status'], 'reference_no' => $r['reference_no'],
        ], $rows);
    }

    public function contracts(): array
    {
        $rows = $this->q('SELECT c.contract_id, c.contract_number, c.contract_value, c.currency_code, c.status, b.legal_name AS bidder
                          FROM contracts c JOIN bidders b ON b.bidder_id = c.bidder_id ORDER BY c.contract_id DESC');
        return array_map(fn($r) => [
            'id' => bin2hex($r['contract_id']), 'contract_number' => $r['contract_number'],
            'contract_value' => $r['contract_value'], 'currency_code' => $r['currency_code'],
            'status' => $r['status'], 'bidder' => $r['bidder'],
        ], $rows);
    }

    public function ledgerRecent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->q("SELECT sequence_no, action, entity_type, HEX(entity_id) AS entity_hex, created_at
                          FROM ledger_entries ORDER BY sequence_no DESC LIMIT {$limit}");
        return array_map(fn($r) => [
            'sequence_no' => (int) $r['sequence_no'], 'action' => $r['action'],
            'entity_type' => $r['entity_type'], 'entity' => strtolower($r['entity_hex']),
            'created_at' => $r['created_at'],
        ], $rows);
    }

    public function counts(): array
    {
        $one = fn(string $sql) => (int) ($this->q($sql)[0]['n'] ?? 0);
        return [
            'requisitions' => $one('SELECT COUNT(*) n FROM requisitions'),
            'rfqs'         => $one('SELECT COUNT(*) n FROM rfqs'),
            'bids'         => $one('SELECT COUNT(*) n FROM bids'),
            'awards'       => $one('SELECT COUNT(*) n FROM awards'),
            'ledger'       => $one('SELECT COUNT(*) n FROM ledger_entries'),
        ];
    }

    /** The current user's active bidder organisation id (hex) or null. */
    public function bidderIdForUser(string $userId16): ?string
    {
        $rows = $this->q('SELECT bidder_id FROM users WHERE user_id = :u LIMIT 1', [':u' => $userId16]);
        $b = $rows[0]['bidder_id'] ?? null;
        return $b ? bin2hex($b) : null;
    }
}
