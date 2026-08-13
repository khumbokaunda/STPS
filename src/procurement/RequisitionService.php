<?php
declare(strict_types=1);

require_once __DIR__ . '/Services.php';

/**
 * RequisitionService  --  create/submit a requisition (build spec section 15).
 * Ledger action: REQUISITION_SUBMITTED, signed by the requester.
 *
 * Pattern for every service method: authenticate (caller), authorize, validate
 * inputs, verify the client signature, then perform the business write and the
 * ledger append in ONE transaction.
 */
final class RequisitionService
{
    private Services $s;

    public function __construct(Services $s)
    {
        $this->s = $s;
    }

    /**
     * @param string $userId16     the authenticated requester
     * @param array  $input        validated fields: department_id(hex), reference_no,
     *                             title, estimated_value(decimal string, scale 2),
     *                             currency_code, procurement_type, items[]
     * @param string $canonical    the exact canonical JSON the client signed
     * @param string $signature    client signature bytes
     * @param string $keyId16      key that produced the signature
     * @return string the new 16-byte requisition_id
     */
    public function submit(
        string $userId16,
        array $input,
        string $canonical,
        string $signature,
        string $keyId16
    ): string {
        // Authorize: only a Requisitioner may submit.
        $this->s->authz->requireRole($userId16, Rbac::REQUISITIONER);

        $now = Clock::now();
        // Verify the client signature over the canonical requisition payload.
        $payloadHash = $this->s->evidence->verify(
            DomainSeparators::REQUISITION,
            $canonical,
            $signature,
            $keyId16,
            $userId16,
            $now
        );

        $requisitionId = Uuid::bin();
        $departmentId = Uuid::fromString($input['department_id']);

        return Db::transaction($this->s->pdo, function (PDO $pdo) use (
            $requisitionId, $departmentId, $userId16, $input, $payloadHash, $now
        ) {
            $stmt = $pdo->prepare(
                'INSERT INTO requisitions
                    (requisition_id, department_id, requester_id, reference_no, title,
                     estimated_value, currency_code, procurement_type, status,
                     canonical_hash, schema_version, created_at, submitted_at)
                 VALUES
                    (:id, :dept, :req, :ref, :title, :val, :cur, :ptype, "submitted",
                     :chash, :schema, :created, :submitted)'
            );
            $stmt->bindValue(':id', $requisitionId, PDO::PARAM_LOB);
            $stmt->bindValue(':dept', $departmentId, PDO::PARAM_LOB);
            $stmt->bindValue(':req', $userId16, PDO::PARAM_LOB);
            $stmt->bindValue(':ref', $input['reference_no']);
            $stmt->bindValue(':title', $input['title']);
            $stmt->bindValue(':val', $input['estimated_value']);
            $stmt->bindValue(':cur', $input['currency_code']);
            $stmt->bindValue(':ptype', $input['procurement_type']);
            $stmt->bindValue(':chash', $payloadHash, PDO::PARAM_LOB);
            $stmt->bindValue(':schema', DomainSeparators::REQUISITION);
            $stmt->bindValue(':created', Clock::mysql($now));
            $stmt->bindValue(':submitted', Clock::mysql($now));
            $stmt->execute();

            foreach ($input['items'] ?? [] as $item) {
                $itemStmt = $pdo->prepare(
                    'INSERT INTO requisition_items
                        (item_id, requisition_id, description, quantity, unit, estimated_unit_cost)
                     VALUES (:iid, :rid, :desc, :qty, :unit, :cost)'
                );
                $itemStmt->bindValue(':iid', Uuid::bin(), PDO::PARAM_LOB);
                $itemStmt->bindValue(':rid', $requisitionId, PDO::PARAM_LOB);
                $itemStmt->bindValue(':desc', $item['description']);
                $itemStmt->bindValue(':qty', $item['quantity']);
                $itemStmt->bindValue(':unit', $item['unit']);
                $itemStmt->bindValue(':cost', $item['estimated_unit_cost']);
                $itemStmt->execute();
            }

            // Ledger append in the same transaction, carrying the client signature.
            $this->s->ledger->append(
                $userId16,
                LedgerActions::REQUISITION_SUBMITTED,
                'requisition',
                $requisitionId,
                [
                    'reference_no'    => $input['reference_no'],
                    'estimated_value' => $input['estimated_value'],
                    'currency_code'   => $input['currency_code'],
                    'procurement_type' => $input['procurement_type'],
                    'payload_hash'    => bin2hex($payloadHash),
                ],
                $signature,
                $keyId16
            );

            return $requisitionId;
        });
    }
}
