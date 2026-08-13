<?php
declare(strict_types=1);

require_once __DIR__ . '/Services.php';

/**
 * AwardContractExecutionService  --  award, contract, purchase order, delivery,
 * inspection, invoice, payment (build spec section 15). System-actor events
 * still require an authenticated, authorized service account and are ledgered.
 *
 * Ledger actions: AWARD_RECORDED, CONTRACT_SIGNED, PO_ISSUED, DELIVERY_RECORDED,
 * INSPECTION_RECORDED, INVOICE_SUBMITTED, PAYMENT_RECORDED. (AWARD_DECIDED is a
 * committee decision handled by ApprovalService.)
 */
final class AwardContractExecutionService
{
    private Services $s;

    public function __construct(Services $s)
    {
        $this->s = $s;
    }

    /** Record an award for the winning bid (system actor). Ledger AWARD_RECORDED. */
    public function recordAward(
        string $userId16,
        string $rfqId16,
        string $winningBidId16,
        string $awardValueDecimal,
        string $currency,
        string $awardCanonical
    ): string {
        $this->s->authz->requireAnyRole($userId16, [Rbac::PDU_OFFICER, Rbac::CONTROLLING_OFFICER, Rbac::SYSTEM_ADMINISTRATOR]);
        $now = Clock::now();
        // The winning bid must be revealed (opened) to be awardable.
        $this->requireBidStatus($winningBidId16, ['revealed', 'evaluated']);
        $awardHash = Hasher::sha256($awardCanonical);
        $awardId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $awardId, $rfqId16, $winningBidId16, $awardValueDecimal, $currency, $awardHash, $userId16, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO awards
                    (award_id, rfq_id, winning_bid_id, award_value, currency_code,
                     award_hash, schema_version, status, awarded_at)
                 VALUES (:id, :rfq, :bid, :val, :cur, :hash, :schema, "approved", :at)'
            );
            $stmt->bindValue(':id', $awardId, PDO::PARAM_LOB);
            $stmt->bindValue(':rfq', $rfqId16, PDO::PARAM_LOB);
            $stmt->bindValue(':bid', $winningBidId16, PDO::PARAM_LOB);
            $stmt->bindValue(':val', $awardValueDecimal);
            $stmt->bindValue(':cur', $currency);
            $stmt->bindValue(':hash', $awardHash, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::AWARD);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $upd = $pdo->prepare('UPDATE bids SET status = "awarded" WHERE bid_id = :id');
            $upd->bindValue(':id', $winningBidId16, PDO::PARAM_LOB);
            $upd->execute();
            $this->s->ledger->append($userId16, LedgerActions::AWARD_RECORDED, 'award', $awardId,
                ['rfq_id' => bin2hex($rfqId16), 'winning_bid_id' => bin2hex($winningBidId16),
                 'award_value' => $awardValueDecimal, 'award_hash' => bin2hex($awardHash)]);
            return $awardId;
        });
    }

    /** Sign a contract (controlling officer). Ledger CONTRACT_SIGNED, signed. */
    public function signContract(
        string $userId16,
        string $awardId16,
        string $bidderId16,
        string $contractNumber,
        string $contractValueDecimal,
        string $currency,
        bool $requiresNoObjection,
        ?string $noObjectionReference,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $this->s->authz->requireRole($userId16, Rbac::CONTROLLING_OFFICER);
        $now = Clock::now();
        $contractHash = $this->s->evidence->verify(
            DomainSeparators::CONTRACT, $canonical, $signature, $keyId16, $userId16, $now
        );
        $contractId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $contractId, $awardId16, $bidderId16, $contractNumber, $contractValueDecimal,
            $currency, $requiresNoObjection, $noObjectionReference, $contractHash, $userId16, $signature, $keyId16, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO contracts
                    (contract_id, award_id, bidder_id, contract_number, contract_value,
                     currency_code, contract_hash, schema_version, requires_ppda_no_objection,
                     ppda_no_objection_reference, controlling_officer_id, signed_at, status)
                 VALUES (:id, :award, :bidder, :num, :val, :cur, :hash, :schema, :ronj, :onj, :co, :at, "signed")'
            );
            $stmt->bindValue(':id', $contractId, PDO::PARAM_LOB);
            $stmt->bindValue(':award', $awardId16, PDO::PARAM_LOB);
            $stmt->bindValue(':bidder', $bidderId16, PDO::PARAM_LOB);
            $stmt->bindValue(':num', $contractNumber);
            $stmt->bindValue(':val', $contractValueDecimal);
            $stmt->bindValue(':cur', $currency);
            $stmt->bindValue(':hash', $contractHash, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::CONTRACT);
            $stmt->bindValue(':ronj', $requiresNoObjection ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':onj', $noObjectionReference, $noObjectionReference === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':co', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append($userId16, LedgerActions::CONTRACT_SIGNED, 'contract', $contractId,
                ['contract_number' => $contractNumber, 'contract_hash' => bin2hex($contractHash),
                 'contract_value' => $contractValueDecimal], $signature, $keyId16);
            return $contractId;
        });
    }

    /** Issue a purchase order (authorized officer). Ledger PO_ISSUED. */
    public function issuePurchaseOrder(string $userId16, string $contractId16, string $poNumber, string $totalDecimal, string $currency): string
    {
        $this->s->authz->requireAnyRole($userId16, [Rbac::PDU_OFFICER, Rbac::CONTROLLING_OFFICER]);
        $now = Clock::now();
        $poId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use ($poId, $contractId16, $poNumber, $totalDecimal, $currency, $userId16, $now) {
            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders (purchase_order_id, contract_id, po_number, total_value, currency_code, status, issued_at)
                 VALUES (:id, :c, :num, :val, :cur, "issued", :at)'
            );
            $stmt->bindValue(':id', $poId, PDO::PARAM_LOB);
            $stmt->bindValue(':c', $contractId16, PDO::PARAM_LOB);
            $stmt->bindValue(':num', $poNumber);
            $stmt->bindValue(':val', $totalDecimal);
            $stmt->bindValue(':cur', $currency);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append($userId16, LedgerActions::PO_ISSUED, 'contract', $contractId16,
                ['po_number' => $poNumber, 'total_value' => $totalDecimal]);
            return $poId;
        });
    }

    /** Record a delivery (stores officer). Ledger DELIVERY_RECORDED. */
    public function recordDelivery(string $userId16, string $poId16, string $reference): string
    {
        $this->s->authz->requireRole($userId16, Rbac::STORES_OFFICER);
        $now = Clock::now();
        $deliveryId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use ($deliveryId, $poId16, $reference, $userId16, $now) {
            $stmt = $pdo->prepare(
                'INSERT INTO deliveries (delivery_id, purchase_order_id, delivery_reference, delivered_at, status)
                 VALUES (:id, :po, :ref, :at, "received")'
            );
            $stmt->bindValue(':id', $deliveryId, PDO::PARAM_LOB);
            $stmt->bindValue(':po', $poId16, PDO::PARAM_LOB);
            $stmt->bindValue(':ref', $reference);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append($userId16, LedgerActions::DELIVERY_RECORDED, 'delivery', $deliveryId,
                ['delivery_reference' => $reference]);
            return $deliveryId;
        });
    }

    /** Record an inspection (inspector). Ledger INSPECTION_RECORDED, signed. */
    public function recordInspection(
        string $userId16,
        string $deliveryId16,
        string $result,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        $this->s->authz->requireAnyRole($userId16, [Rbac::STORES_OFFICER, Rbac::CONTROLLING_OFFICER]);
        $now = Clock::now();
        $inspectionHash = $this->s->evidence->verify(
            DomainSeparators::INSPECTION, $canonical, $signature, $keyId16, $userId16, $now
        );
        $inspectionId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use ($inspectionId, $deliveryId16, $result, $inspectionHash, $userId16, $signature, $keyId16, $now) {
            $stmt = $pdo->prepare(
                'INSERT INTO inspections (inspection_id, delivery_id, inspector_id, result, inspection_hash, schema_version, inspected_at)
                 VALUES (:id, :d, :ins, :res, :hash, :schema, :at)'
            );
            $stmt->bindValue(':id', $inspectionId, PDO::PARAM_LOB);
            $stmt->bindValue(':d', $deliveryId16, PDO::PARAM_LOB);
            $stmt->bindValue(':ins', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':res', $result);
            $stmt->bindValue(':hash', $inspectionHash, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::INSPECTION);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append($userId16, LedgerActions::INSPECTION_RECORDED, 'delivery', $deliveryId16,
                ['result' => $result, 'inspection_hash' => bin2hex($inspectionHash)], $signature, $keyId16);
            return $inspectionId;
        });
    }

    /** Submit an invoice (finance / bidder). Ledger INVOICE_SUBMITTED. */
    public function submitInvoice(string $userId16, string $contractId16, string $invoiceNumber, string $amountDecimal, string $currency): string
    {
        $this->s->authz->requireAnyRole($userId16, [Rbac::FINANCE_OFFICER, Rbac::BIDDER]);
        $now = Clock::now();
        $invoiceId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use ($invoiceId, $contractId16, $invoiceNumber, $amountDecimal, $currency, $userId16, $now) {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices (invoice_id, contract_id, invoice_number, amount, currency_code, status, submitted_at)
                 VALUES (:id, :c, :num, :amt, :cur, "submitted", :at)'
            );
            $stmt->bindValue(':id', $invoiceId, PDO::PARAM_LOB);
            $stmt->bindValue(':c', $contractId16, PDO::PARAM_LOB);
            $stmt->bindValue(':num', $invoiceNumber);
            $stmt->bindValue(':amt', $amountDecimal);
            $stmt->bindValue(':cur', $currency);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $this->s->ledger->append($userId16, LedgerActions::INVOICE_SUBMITTED, 'contract', $contractId16,
                ['invoice_number' => $invoiceNumber, 'amount' => $amountDecimal]);
            return $invoiceId;
        });
    }

    /** Record a payment (finance officer). Ledger PAYMENT_RECORDED. */
    public function recordPayment(string $userId16, string $invoiceId16, string $amountDecimal, string $reference): string
    {
        $this->s->authz->requireRole($userId16, Rbac::FINANCE_OFFICER);
        $now = Clock::now();
        $paymentId = Uuid::bin();
        return Db::transaction($this->s->pdo, function (PDO $pdo) use ($paymentId, $invoiceId16, $amountDecimal, $reference, $userId16, $now) {
            $stmt = $pdo->prepare(
                'INSERT INTO payments (payment_id, invoice_id, amount, payment_reference, paid_at, status)
                 VALUES (:id, :inv, :amt, :ref, :at, "paid")'
            );
            $stmt->bindValue(':id', $paymentId, PDO::PARAM_LOB);
            $stmt->bindValue(':inv', $invoiceId16, PDO::PARAM_LOB);
            $stmt->bindValue(':amt', $amountDecimal);
            $stmt->bindValue(':ref', $reference);
            $stmt->bindValue(':at', Clock::mysql($now));
            $stmt->execute();
            $upd = $pdo->prepare('UPDATE invoices SET status = "paid" WHERE invoice_id = :id');
            $upd->bindValue(':id', $invoiceId16, PDO::PARAM_LOB);
            $upd->execute();
            $this->s->ledger->append($userId16, LedgerActions::PAYMENT_RECORDED, 'invoice', $invoiceId16,
                ['amount' => $amountDecimal, 'payment_reference' => $reference]);
            return $paymentId;
        });
    }

    private function requireBidStatus(string $bidId16, array $allowed): void
    {
        $stmt = $this->s->pdo->prepare('SELECT status FROM bids WHERE bid_id = :id LIMIT 1');
        $stmt->bindValue(':id', $bidId16, PDO::PARAM_LOB);
        $stmt->execute();
        $status = $stmt->fetchColumn();
        if ($status === false || !in_array($status, $allowed, true)) {
            throw new RuntimeException('Winning bid is not in an awardable state.');
        }
    }
}
