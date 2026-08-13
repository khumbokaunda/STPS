<?php
declare(strict_types=1);

/**
 * LedgerActions  --  the fixed set of ledger action constants (build spec
 * section 15). Extend only by adding new constants; never rename or repurpose.
 */
final class LedgerActions
{
    public const REQUISITION_SUBMITTED   = 'REQUISITION_SUBMITTED';
    public const APPROVAL_RECORDED       = 'APPROVAL_RECORDED';
    public const DOCUMENT_VERSION_ADDED  = 'DOCUMENT_VERSION_ADDED';
    public const DOCUMENT_APPROVED       = 'DOCUMENT_APPROVED';
    public const RFQ_PUBLISHED           = 'RFQ_PUBLISHED';
    public const EVAL_TEAM_CONSTITUTED   = 'EVAL_TEAM_CONSTITUTED';
    public const BID_COMMITTED           = 'BID_COMMITTED';
    public const RFQ_CLOSED              = 'RFQ_CLOSED';
    public const BID_REVEALED            = 'BID_REVEALED';
    public const BID_INVALID             = 'BID_INVALID';
    public const BID_EXPIRED             = 'BID_EXPIRED';
    public const SCORE_SUBMITTED         = 'SCORE_SUBMITTED';
    public const REPORT_SUBMITTED        = 'REPORT_SUBMITTED';
    public const AWARD_DECIDED           = 'AWARD_DECIDED';
    public const AWARD_RECORDED          = 'AWARD_RECORDED';
    public const CONTRACT_SIGNED         = 'CONTRACT_SIGNED';
    public const PO_ISSUED               = 'PO_ISSUED';
    public const DELIVERY_RECORDED       = 'DELIVERY_RECORDED';
    public const INSPECTION_RECORDED     = 'INSPECTION_RECORDED';
    public const INVOICE_SUBMITTED       = 'INVOICE_SUBMITTED';
    public const PAYMENT_RECORDED        = 'PAYMENT_RECORDED';
}
