<?php
declare(strict_types=1);

/**
 * DomainSeparators  --  fixed domain separator strings (build spec section 8).
 *
 * The exact string used for a payload is stored in that row's schema_version
 * column, so the canonicalisation ruleset and separator are always reproducible.
 * A domain separator prevents a signature or hash made for one payload type from
 * being valid for another. Never reuse a separator across types.
 */
final class DomainSeparators
{
    public const REQUISITION       = 'PROCUREMENT-REQUISITION-V1';
    public const APPROVAL          = 'PROCUREMENT-APPROVAL-V1';
    public const BID_COMMITMENT    = 'PROCUREMENT-BID-COMMITMENT-V1';
    public const BID_REVEAL        = 'PROCUREMENT-BID-REVEAL-V1';
    public const EVALUATION_SCORE  = 'PROCUREMENT-EVALUATION-SCORE-V1';
    public const EVALUATION_REPORT = 'PROCUREMENT-EVALUATION-REPORT-V1';
    public const COMMITTEE_DECISION = 'PROCUREMENT-COMMITTEE-DECISION-V1';
    public const CONFLICT          = 'PROCUREMENT-CONFLICT-V1';
    public const AWARD             = 'PROCUREMENT-AWARD-V1';
    public const CONTRACT          = 'PROCUREMENT-CONTRACT-V1';
    public const INSPECTION        = 'PROCUREMENT-INSPECTION-V1';
    public const LEDGER            = 'PROCUREMENT-LEDGER-V1';

    private const ALL = [
        self::REQUISITION, self::APPROVAL, self::BID_COMMITMENT, self::BID_REVEAL,
        self::EVALUATION_SCORE, self::EVALUATION_REPORT, self::COMMITTEE_DECISION,
        self::CONFLICT, self::AWARD, self::CONTRACT, self::INSPECTION, self::LEDGER,
    ];

    public static function isValid(string $s): bool
    {
        return in_array($s, self::ALL, true);
    }
}
