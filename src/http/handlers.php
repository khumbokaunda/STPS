<?php
declare(strict_types=1);

/**
 * handlers.php  --  page (GET) and action (POST) handlers for the front
 * controller. Page handlers render role-appropriate views; action handlers
 * validate input, verify signatures via the services, mutate, and redirect with
 * a flash. Signed actions rebuild the exact canonical bytes the browser signed
 * from the posted fields, so the server trusts only the signature, not a
 * client-supplied canonical string.
 */

function svc(): Services
{
    static $s = null;
    return $s ??= Services::boot();
}

function rm(): ReadModel
{
    static $r = null;
    return $r ??= new ReadModel(Db::app());
}

/** [signatureBytes, keyId16] from a signed form's hidden fields. */
function postSignature(): array
{
    $sig = base64_decode((string) ($_POST['_signature'] ?? ''), true);
    $keyHex = Validator::requireUuidHex($_POST['_key_id'] ?? null, 'key_id');
    if ($sig === false || $sig === '') {
        throw new ValidationException('Missing client signature.');
    }
    return [$sig, Uuid::fromString($keyHex)];
}

// ==========================================================================
// API
// ==========================================================================

function apiEnroll(): void
{
    $userId = Session::require();
    $body = Request::json();
    $spki = Validator::requireString($body['spki_base64'] ?? null, 'spki_base64', 4096);
    $keyId = svc()->keys->enroll($userId, $spki);
    Response::json(['ok' => true, 'key_id' => bin2hex($keyId)]);
}

function apiVerify(array $config): void
{
    Session::require();
    require_once dirname(__DIR__) . '/ledger/Verifier.php';
    $tsa = null;
    if (is_readable($config['tsa']['ca_file'] ?? '') && is_readable($config['tsa']['tsa_cert'] ?? '')) {
        $tsa = new TsaClient($config['tsa']);
    }
    $result = (new Verifier(Db::app(), $tsa))->run();
    Response::json([
        'verdict' => $result->pass ? 'PASS' : 'FAIL',
        'failures' => $result->failures,
        'notes' => $result->notes,
    ]);
}

// ==========================================================================
// AUTH
// ==========================================================================

function actionLogin(): void
{
    $username = Validator::requireString($_POST['username'] ?? null, 'username', 100);
    $password = Validator::requireString($_POST['password'] ?? null, 'password', 1024);
    $userId = (new Authenticator(Db::app()))->authenticate($username, $password);
    if ($userId === null) {
        redirect('/login', 'err', 'Invalid credentials.');
    }
    Session::login($userId);
    redirect('/', 'ok', 'Signed in.');
}

// ==========================================================================
// DASHBOARD
// ==========================================================================

function pageDashboard(): void
{
    $ctx = currentContext();
    if ($ctx['user'] === null) {
        redirect('/login');
    }
    page('dashboard', ['counts' => rm()->counts(), 'ledger' => rm()->ledgerRecent(10)], 'Dashboard');
}

// ==========================================================================
// REQUISITIONS
// ==========================================================================

function pageRequisitions(): void
{
    $ctx = requireLogin();
    page('requisitions', ['items' => rm()->requisitions($ctx['user_bin'])], 'Requisitions');
}

function pageRequisitionNew(): void
{
    requireLogin();
    page('requisition_new', ['departments' => rm()->departments()], 'New requisition');
}

function actionSubmitRequisition(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $deptHex = Validator::requireUuidHex($_POST['department_id'] ?? null, 'department_id');
    $fields = [
        'reference_no'     => Validator::requireString($_POST['reference_no'] ?? null, 'reference_no', 80),
        'title'            => Validator::requireString($_POST['title'] ?? null, 'title', 255),
        'estimated_value'  => Validator::requireDecimal($_POST['estimated_value'] ?? null, 'estimated_value', 2),
        'currency_code'    => Validator::requireEnum($_POST['currency_code'] ?? 'MWK', 'currency_code', ['MWK', 'USD', 'EUR', 'GBP', 'ZAR']),
        'procurement_type' => Validator::requireEnum($_POST['procurement_type'] ?? null, 'procurement_type', ['GOODS', 'WORKS', 'SERVICES', 'CONSULTING']),
        'department_id'    => $deptHex,
        'created_at'       => Validator::requireString($_POST['created_at'] ?? null, 'created_at', 40),
    ];
    $canonical = rebuildCanonical(DomainSeparators::REQUISITION, $fields);
    $input = $fields;
    $input['items'] = [];
    $svc = new RequisitionService(svc());
    $svc->submit($ctx['user_bin'], $input, $canonical, $sig, $keyId);
    redirect('/requisitions', 'ok', 'Requisition submitted and ledgered.');
}

// ==========================================================================
// APPROVALS (role stages)
// ==========================================================================

function pageApprovals(): void
{
    requireLogin();
    page('approvals', ['pending' => rm()->requisitionsPendingApproval()], 'Approvals');
}

function actionRoleApproval(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $entityType = Validator::requireEnum($_POST['entity'] ?? null, 'entity', ['requisition', 'bidding_document', 'award', 'contract']);
    $entityHex = Validator::requireUuidHex($_POST['entity_id'] ?? null, 'entity_id');
    $decision = Validator::requireEnum($_POST['decision'] ?? null, 'decision', ['approved', 'rejected']);
    $decidedAt = Validator::requireString($_POST['decided_at'] ?? null, 'decided_at', 40);
    $reason = Validator::optional($_POST['reason'] ?? null, [Validator::class, 'requireString'], 'reason', 2000);

    // Select the workflow and the first role stage the user can satisfy.
    $entityId = Uuid::fromString($entityHex);
    $meta = requisitionAmountType($entityId, $entityType);
    $stages = rm()->workflowStagesFor($meta['type'], $meta['amount']);
    $stage = firstRoleStageFor($ctx['user_bin'], $stages);
    if ($stage === null) {
        redirect('/approvals', 'err', 'No role stage you can satisfy for this item.');
    }

    $canonical = rebuildCanonical(DomainSeparators::APPROVAL, [
        'entity' => $entityType, 'entity_id' => $entityHex, 'decision' => $decision, 'decided_at' => $decidedAt,
    ]);
    $approvals = new ApprovalService(svc());
    $approvals->recordRoleApproval(
        $ctx['user_bin'], Uuid::fromString($stage['stage_id']), $entityType, $entityId,
        $decision, $reason, $canonical, $sig, $keyId
    );
    // Simplified workflow advancement: mark the requisition approved on approval.
    if ($entityType === 'requisition' && $decision === 'approved') {
        markRequisitionApproved($entityId);
    }
    redirect('/approvals', 'ok', "Signed {$decision} recorded and ledgered.");
}

// ==========================================================================
// COMMITTEE
// ==========================================================================

function pageCommittee(): void
{
    requireLogin();
    page('committee', [
        'committees' => rm()->committees(),
        'awards' => rm()->awards(),
    ], 'Committee');
}

function actionCommitteeOpen(): void
{
    $ctx = requireLogin();
    $committeeHex = Validator::requireUuidHex($_POST['committee_id'] ?? null, 'committee_id');
    $entityType = Validator::requireEnum($_POST['entity'] ?? null, 'entity', ['award', 'bidding_document']);
    $entityHex = Validator::requireUuidHex($_POST['entity_id'] ?? null, 'entity_id');
    $quorum = Validator::requireInt($_POST['quorum'] ?? 1, 'quorum', 1, 50);
    $decisionCanonical = rebuildCanonical(DomainSeparators::COMMITTEE_DECISION, [
        'entity' => $entityType, 'entity_id' => $entityHex, 'quorum_required' => (string) $quorum,
    ]);
    $approvals = new ApprovalService(svc());
    $decisionId = $approvals->openCommitteeDecision(
        $ctx['user_bin'], Uuid::fromString($committeeHex), $entityType, Uuid::fromString($entityHex), $quorum, $decisionCanonical
    );
    redirect('/committee', 'ok', 'Committee decision opened: ' . bin2hex($decisionId));
}

function actionCommitteeVote(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $stageHex = Validator::requireUuidHex($_POST['stage_id'] ?? null, 'stage_id');
    $decisionHex = Validator::requireUuidHex($_POST['committee_decision_id'] ?? null, 'committee_decision_id');
    $entityType = Validator::requireEnum($_POST['entity'] ?? null, 'entity', ['award', 'bidding_document']);
    $entityHex = Validator::requireUuidHex($_POST['entity_id'] ?? null, 'entity_id');
    $decision = Validator::requireEnum($_POST['decision'] ?? null, 'decision', ['approved', 'rejected']);
    $decidedAt = Validator::requireString($_POST['decided_at'] ?? null, 'decided_at', 40);
    $canonical = rebuildCanonical(DomainSeparators::APPROVAL, [
        'entity' => $entityType, 'entity_id' => $entityHex, 'decision' => $decision, 'decided_at' => $decidedAt,
    ]);
    $approvals = new ApprovalService(svc());
    $approvals->recordCommitteeVote(
        $ctx['user_bin'], Uuid::fromString($stageHex), Uuid::fromString($decisionHex),
        $entityType, Uuid::fromString($entityHex), $decision, null, $canonical, $sig, $keyId
    );
    $outcome = $approvals->tallyCommitteeDecision(Uuid::fromString($decisionHex));
    redirect('/committee', 'ok', "Vote recorded. Current tally: {$outcome}.");
}

// ==========================================================================
// BIDDING DOCUMENTS
// ==========================================================================

function pageBiddingDocuments(): void
{
    requireLogin();
    page('bidding_documents', ['docs' => rm()->biddingDocuments()], 'Bidding documents');
}

// ==========================================================================
// RFQs
// ==========================================================================

function pageRfqs(): void
{
    requireLogin();
    page('rfqs', ['items' => rm()->rfqs()], 'RFQs');
}

function pageRfqsOpen(): void
{
    $ctx = requireLogin();
    page('rfqs_open', [
        'items' => rm()->rfqs('published'),
        'bidder_id' => rm()->bidderIdForUser($ctx['user_bin']),
    ], 'Open RFQs');
}

function actionPublishRfq(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $rfqHex = Validator::requireUuidHex($_POST['rfq_id'] ?? null, 'rfq_id');
    $bd = Validator::requireString($_POST['bid_deadline'] ?? null, 'bid_deadline', 40);
    $rs = Validator::requireString($_POST['reveal_start'] ?? null, 'reveal_start', 40);
    $rd = Validator::requireString($_POST['reveal_deadline'] ?? null, 'reveal_deadline', 40);
    $canonical = rebuildCanonical(DomainSeparators::REQUISITION, [
        'rfq_id' => $rfqHex, 'bid_deadline' => $bd, 'reveal_start' => $rs, 'reveal_deadline' => $rd,
    ]);
    $rfqSvc = new RfqService(svc());
    $rfqSvc->publish(
        $ctx['user_bin'], Uuid::fromString($rfqHex),
        parseLocalDateTime($bd), parseLocalDateTime($rs), parseLocalDateTime($rd),
        $canonical, $sig, $keyId
    );
    redirect('/rfqs', 'ok', 'RFQ published; timing locked and captured in the ledger.');
}

function actionCloseRfq(): void
{
    $ctx = requireLogin();
    $rfqHex = Validator::requireUuidHex($_POST['rfq_id'] ?? null, 'rfq_id');
    (new BidService(svc()))->closeRfq($ctx['user_bin'], Uuid::fromString($rfqHex));
    redirect('/rfqs', 'ok', 'RFQ closed.');
}

// ==========================================================================
// BIDS
// ==========================================================================

function pageBids(): void
{
    $ctx = requireLogin();
    $bidderId = rm()->bidderIdForUser($ctx['user_bin']);
    $bidderBin = $bidderId !== null ? Uuid::fromString($bidderId) : null;
    page('bids', [
        'items' => $bidderBin !== null ? rm()->bids(null, $bidderBin) : [],
        'open' => rm()->rfqs('published'),
        'bidder_id' => $bidderId,
    ], 'My bids');
}

function actionBidCommit(): void
{
    $ctx = requireLogin();
    $rfqHex = Validator::requireUuidHex($_POST['rfq_id'] ?? null, 'rfq_id');
    $bidderHex = Validator::requireUuidHex($_POST['bidder_id'] ?? null, 'bidder_id');
    $C = base64_decode((string) ($_POST['C'] ?? ''), true);
    $sigC = base64_decode((string) ($_POST['sigC'] ?? ''), true);
    $keyId = Uuid::fromString(Validator::requireUuidHex($_POST['_key_id'] ?? null, 'key_id'));
    if ($C === false || strlen($C) !== 32 || $sigC === false) {
        throw new ValidationException('Malformed commitment.');
    }
    (new BidService(svc()))->commit(
        $ctx['user_bin'], Uuid::fromString($bidderHex), Uuid::fromString($rfqHex), $C, $sigC, $keyId
    );
    redirect('/bids', 'ok', 'Bid committed (only the commitment and its signature were sent).');
}

function actionBidReveal(): void
{
    $ctx = requireLogin();
    $bidHex = Validator::requireUuidHex($_POST['bid_id'] ?? null, 'bid_id');
    $canonical = Validator::requireString($_POST['canonical_bid'] ?? null, 'canonical_bid', 65535);
    $nonce = base64_decode((string) ($_POST['nonce'] ?? ''), true);
    $sigR = base64_decode((string) ($_POST['sigR'] ?? ''), true);
    $keyId = Uuid::fromString(Validator::requireUuidHex($_POST['_key_id'] ?? null, 'key_id'));
    if ($nonce === false || $sigR === false) {
        throw new ValidationException('Malformed reveal.');
    }
    $status = (new BidService(svc()))->reveal($ctx['user_bin'], Uuid::fromString($bidHex), $canonical, $nonce, $sigR, $keyId);
    redirect('/bids', $status === 'revealed' ? 'ok' : 'err', "Reveal result: {$status}.");
}

// ==========================================================================
// EVALUATION
// ==========================================================================

function pageEvaluation(): void
{
    requireLogin();
    page('evaluation', ['rfqs' => rm()->rfqs(), 'bids' => rm()->bids()], 'Evaluation');
}

function actionSubmitScore(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $teamHex = Validator::requireUuidHex($_POST['team_id'] ?? null, 'team_id');
    $critHex = Validator::requireUuidHex($_POST['criterion_id'] ?? null, 'criterion_id');
    $bidHex = Validator::requireUuidHex($_POST['bid_id'] ?? null, 'bid_id');
    $justification = Validator::requireString($_POST['justification'] ?? '', 'justification', 4000, 0);
    $fields = [
        'criterion_id' => $critHex,
        'bid_id' => $bidHex,
        'score' => Validator::requireDecimal($_POST['score'] ?? null, 'score', 4),
        'justification' => $justification,
        'created_at' => Validator::requireString($_POST['created_at'] ?? null, 'created_at', 40),
    ];
    $canonical = rebuildCanonical(DomainSeparators::EVALUATION_SCORE, $fields);
    (new EvaluationService(svc()))->submitScore(
        $ctx['user_bin'], Uuid::fromString($teamHex), Uuid::fromString($critHex), Uuid::fromString($bidHex),
        $fields['score'], $justification, null, $canonical, $sig, $keyId
    );
    redirect('/evaluation', 'ok', 'Evaluation score signed and ledgered (append-only revision).');
}

// ==========================================================================
// AWARDS / CONTRACTS
// ==========================================================================

function pageAwards(): void
{
    requireLogin();
    page('awards', ['items' => rm()->awards(), 'rfqs' => rm()->rfqs(), 'bids' => rm()->bids()], 'Awards');
}

function actionRecordAward(): void
{
    $ctx = requireLogin();
    $rfqHex = Validator::requireUuidHex($_POST['rfq_id'] ?? null, 'rfq_id');
    $bidHex = Validator::requireUuidHex($_POST['winning_bid_id'] ?? null, 'winning_bid_id');
    $value = Validator::requireDecimal($_POST['award_value'] ?? null, 'award_value', 2);
    $currency = Validator::requireEnum($_POST['currency_code'] ?? 'MWK', 'currency_code', ['MWK', 'USD', 'EUR', 'GBP', 'ZAR']);
    $canonical = rebuildCanonical(DomainSeparators::AWARD, [
        'rfq_id' => $rfqHex, 'winning_bid_id' => $bidHex, 'award_value' => $value, 'currency_code' => $currency,
    ]);
    (new AwardContractExecutionService(svc()))->recordAward(
        $ctx['user_bin'], Uuid::fromString($rfqHex), Uuid::fromString($bidHex), $value, $currency, $canonical
    );
    redirect('/awards', 'ok', 'Award recorded and ledgered.');
}

function pageContracts(): void
{
    requireLogin();
    page('contracts', ['items' => rm()->contracts(), 'awards' => rm()->awards(), 'bidders' => rm()->bidders()], 'Contracts');
}

function actionSignContract(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $awardHex = Validator::requireUuidHex($_POST['award_id'] ?? null, 'award_id');
    $bidderHex = Validator::requireUuidHex($_POST['bidder_id'] ?? null, 'bidder_id');
    $fields = [
        'contract_number' => Validator::requireString($_POST['contract_number'] ?? null, 'contract_number', 100),
        'contract_value' => Validator::requireDecimal($_POST['contract_value'] ?? null, 'contract_value', 2),
        'currency' => Validator::requireEnum($_POST['currency_code'] ?? 'MWK', 'currency_code', ['MWK', 'USD', 'EUR', 'GBP', 'ZAR']),
        'award_id' => $awardHex,
        'created_at' => Validator::requireString($_POST['created_at'] ?? null, 'created_at', 40),
    ];
    $canonical = rebuildCanonical(DomainSeparators::CONTRACT, $fields);
    (new AwardContractExecutionService(svc()))->signContract(
        $ctx['user_bin'], Uuid::fromString($awardHex), Uuid::fromString($bidderHex),
        $fields['contract_number'], $fields['contract_value'], $fields['currency'], false, null, $canonical, $sig, $keyId
    );
    redirect('/contracts', 'ok', 'Contract signed and ledgered.');
}

// ==========================================================================
// EXECUTION (deliveries, inspections)
// ==========================================================================

function pageExecution(): void
{
    requireLogin();
    page('execution', ['contracts' => rm()->contracts()], 'Execution');
}

function actionRecordDelivery(): void
{
    $ctx = requireLogin();
    $poHex = Validator::requireUuidHex($_POST['purchase_order_id'] ?? null, 'purchase_order_id');
    $ref = Validator::requireString($_POST['delivery_reference'] ?? null, 'delivery_reference', 100);
    (new AwardContractExecutionService(svc()))->recordDelivery($ctx['user_bin'], Uuid::fromString($poHex), $ref);
    redirect('/execution', 'ok', 'Delivery recorded and ledgered.');
}

function actionRecordInspection(): void
{
    $ctx = requireLogin();
    [$sig, $keyId] = postSignature();
    $deliveryHex = Validator::requireUuidHex($_POST['delivery_id'] ?? null, 'delivery_id');
    $result = Validator::requireEnum($_POST['result'] ?? null, 'result', ['accepted', 'partially_accepted', 'rejected']);
    $canonical = rebuildCanonical(DomainSeparators::INSPECTION, [
        'delivery_id' => $deliveryHex, 'result' => $result,
        'created_at' => Validator::requireString($_POST['created_at'] ?? null, 'created_at', 40),
    ]);
    (new AwardContractExecutionService(svc()))->recordInspection(
        $ctx['user_bin'], Uuid::fromString($deliveryHex), $result, $canonical, $sig, $keyId
    );
    redirect('/execution', 'ok', 'Inspection signed and ledgered.');
}

// ==========================================================================
// FINANCE (invoices, payments)
// ==========================================================================

function pageFinance(): void
{
    requireLogin();
    page('finance', ['contracts' => rm()->contracts()], 'Finance');
}

function actionSubmitInvoice(): void
{
    $ctx = requireLogin();
    $contractHex = Validator::requireUuidHex($_POST['contract_id'] ?? null, 'contract_id');
    $number = Validator::requireString($_POST['invoice_number'] ?? null, 'invoice_number', 100);
    $amount = Validator::requireDecimal($_POST['amount'] ?? null, 'amount', 2);
    $currency = Validator::requireEnum($_POST['currency_code'] ?? 'MWK', 'currency_code', ['MWK', 'USD', 'EUR', 'GBP', 'ZAR']);
    (new AwardContractExecutionService(svc()))->submitInvoice($ctx['user_bin'], Uuid::fromString($contractHex), $number, $amount, $currency);
    redirect('/finance', 'ok', 'Invoice submitted and ledgered.');
}

function actionRecordPayment(): void
{
    $ctx = requireLogin();
    $invoiceHex = Validator::requireUuidHex($_POST['invoice_id'] ?? null, 'invoice_id');
    $amount = Validator::requireDecimal($_POST['amount'] ?? null, 'amount', 2);
    $ref = Validator::requireString($_POST['payment_reference'] ?? null, 'payment_reference', 150);
    (new AwardContractExecutionService(svc()))->recordPayment($ctx['user_bin'], Uuid::fromString($invoiceHex), $amount, $ref);
    redirect('/finance', 'ok', 'Payment recorded and ledgered.');
}

// ==========================================================================
// ADMIN / AUDIT
// ==========================================================================

function pageAdmin(): void
{
    requireLogin();
    page('admin', ['roles' => rm()->roles(), 'departments' => rm()->departments(), 'bidders' => rm()->bidders()], 'Admin');
}

function pageAudit(array $config): void
{
    requireLogin();
    require_once dirname(__DIR__) . '/ledger/Verifier.php';
    $tsa = null;
    if (is_readable($config['tsa']['ca_file'] ?? '') && is_readable($config['tsa']['tsa_cert'] ?? '')) {
        $tsa = new TsaClient($config['tsa']);
    }
    $result = (new Verifier(Db::app(), $tsa))->run();
    page('audit', ['result' => $result, 'ledger' => rm()->ledgerRecent(30)], 'Audit');
}

// ==========================================================================
// small helpers
// ==========================================================================

/** ISO datetime-local string ("2026-08-05T12:00") -> UTC DateTimeImmutable. */
function parseLocalDateTime(string $s): DateTimeImmutable
{
    $s = trim($s);
    // Accept both "Y-m-d\TH:i" and with seconds/millis.
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $s, new DateTimeZone('UTC'))
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $s, new DateTimeZone('UTC'));
    if ($dt === false) {
        try {
            $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new ValidationException('Invalid date/time.');
        }
    }
    return $dt->setTimezone(new DateTimeZone('UTC'));
}

function requisitionAmountType(string $entityId16, string $entityType): array
{
    if ($entityType !== 'requisition') {
        // Fall back to a permissive band for non-requisition entities.
        return ['amount' => '0.00', 'type' => 'GOODS'];
    }
    $stmt = Db::app()->prepare('SELECT estimated_value, procurement_type FROM requisitions WHERE requisition_id = :id LIMIT 1');
    $stmt->bindValue(':id', $entityId16, PDO::PARAM_LOB);
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row === false) {
        throw new ValidationException('Requisition not found.');
    }
    return ['amount' => $row['estimated_value'], 'type' => $row['procurement_type']];
}

function firstRoleStageFor(string $userId16, array $stages): ?array
{
    foreach ($stages as $stage) {
        if ($stage['role_name'] !== null && svc()->rbac->hasRoleAt($userId16, $stage['role_name'])) {
            return $stage;
        }
    }
    return null;
}

function markRequisitionApproved(string $requisitionId16): void
{
    $stmt = Db::app()->prepare('UPDATE requisitions SET status = "approved" WHERE requisition_id = :id AND status = "submitted"');
    $stmt->bindValue(':id', $requisitionId16, PDO::PARAM_LOB);
    $stmt->execute();
}
