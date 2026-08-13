<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/Clock.php';

/**
 * Rbac  --  time-bounded role membership and committee membership queries
 * (build spec sections 13, 14). Role assignment is time-bounded via
 * user_roles.valid_from/valid_until; an expired assignment confers nothing.
 *
 * The seeded roles are:
 *   Requisitioner, HeadOfDepartment, PDUOfficer, EvaluationTeamMember,
 *   IPDCMember, ControllingOfficer, StoresOfficer, FinanceOfficer,
 *   SystemAdministrator, Bidder
 */
final class Rbac
{
    public const REQUISITIONER        = 'Requisitioner';
    public const HEAD_OF_DEPARTMENT   = 'HeadOfDepartment';
    public const PDU_OFFICER          = 'PDUOfficer';
    public const EVALUATION_TEAM_MEMBER = 'EvaluationTeamMember';
    public const IPDC_MEMBER          = 'IPDCMember';
    public const CONTROLLING_OFFICER  = 'ControllingOfficer';
    public const STORES_OFFICER       = 'StoresOfficer';
    public const FINANCE_OFFICER      = 'FinanceOfficer';
    public const SYSTEM_ADMINISTRATOR = 'SystemAdministrator';
    public const BIDDER               = 'Bidder';

    public const ALL_ROLES = [
        self::REQUISITIONER, self::HEAD_OF_DEPARTMENT, self::PDU_OFFICER,
        self::EVALUATION_TEAM_MEMBER, self::IPDC_MEMBER, self::CONTROLLING_OFFICER,
        self::STORES_OFFICER, self::FINANCE_OFFICER, self::SYSTEM_ADMINISTRATOR,
        self::BIDDER,
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * True if $userId16 holds $roleName with an assignment valid at $at
     * (defaults to now). Deny by default.
     */
    public function hasRoleAt(string $userId16, string $roleName, ?DateTimeImmutable $at = null): bool
    {
        $at = $at ?? Clock::now();
        $atStr = Clock::mysql($at);
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM user_roles ur
             JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = :uid
               AND r.name = :role
               AND ur.status = "active"
               AND ur.valid_from <= :at1
               AND (ur.valid_until IS NULL OR ur.valid_until > :at2)
             LIMIT 1'
        );
        $stmt->bindValue(':uid', $userId16, PDO::PARAM_LOB);
        $stmt->bindValue(':role', $roleName);
        $stmt->bindValue(':at1', $atStr);
        $stmt->bindValue(':at2', $atStr);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /** Role names held by a user right now (for UI and session context). */
    public function rolesOf(string $userId16, ?DateTimeImmutable $at = null): array
    {
        $at = $at ?? Clock::now();
        $atStr = Clock::mysql($at);
        $stmt = $this->pdo->prepare(
            'SELECT r.name
             FROM user_roles ur
             JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = :uid
               AND ur.status = "active"
               AND ur.valid_from <= :at1
               AND (ur.valid_until IS NULL OR ur.valid_until > :at2)'
        );
        $stmt->bindValue(':uid', $userId16, PDO::PARAM_LOB);
        $stmt->bindValue(':at1', $atStr);
        $stmt->bindValue(':at2', $atStr);
        $stmt->execute();
        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * True if $userId16 is a member of $committeeId16 with validity covering $at.
     * Used for committee-stage voting (build spec section 13). Only members whose
     * validity period covers the decision time may vote.
     */
    public function isCommitteeMemberAt(string $userId16, string $committeeId16, DateTimeImmutable $at): bool
    {
        $atStr = Clock::mysql($at);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM committee_members
             WHERE committee_id = :cid AND user_id = :uid
               AND status = "active"
               AND valid_from <= :at1
               AND (valid_until IS NULL OR valid_until > :at2)
             LIMIT 1'
        );
        $stmt->bindValue(':cid', $committeeId16, PDO::PARAM_LOB);
        $stmt->bindValue(':uid', $userId16, PDO::PARAM_LOB);
        $stmt->bindValue(':at1', $atStr);
        $stmt->bindValue(':at2', $atStr);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /** True if the user is an active member of the RFQ's evaluation team at $at. */
    public function isEvaluationTeamMemberAt(string $userId16, string $teamId16, DateTimeImmutable $at): bool
    {
        $atStr = Clock::mysql($at);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM evaluation_team_members
             WHERE team_id = :tid AND user_id = :uid
               AND valid_from <= :at1
               AND (valid_until IS NULL OR valid_until > :at2)
             LIMIT 1'
        );
        $stmt->bindValue(':tid', $teamId16, PDO::PARAM_LOB);
        $stmt->bindValue(':uid', $userId16, PDO::PARAM_LOB);
        $stmt->bindValue(':at1', $atStr);
        $stmt->bindValue(':at2', $atStr);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }
}
