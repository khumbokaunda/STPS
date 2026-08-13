<?php
declare(strict_types=1);

/**
 * Authorization  --  deny-by-default authorization guard (build spec sections 4,
 * 12, 14, 17). A permission check precedes every state change; object-level
 * checks prevent IDOR (a user acting on an entity they do not own or are not
 * assigned to).
 */
final class AuthorizationException extends RuntimeException
{
}

final class Authorization
{
    private Rbac $rbac;

    public function __construct(Rbac $rbac)
    {
        $this->rbac = $rbac;
    }

    /** Require the user to hold a role now, else throw (deny by default). */
    public function requireRole(string $userId16, string $roleName): void
    {
        if (!$this->rbac->hasRoleAt($userId16, $roleName)) {
            throw new AuthorizationException("Missing required role: {$roleName}");
        }
    }

    /** Require the user to hold at least one of the given roles now. */
    public function requireAnyRole(string $userId16, array $roleNames): void
    {
        foreach ($roleNames as $role) {
            if ($this->rbac->hasRoleAt($userId16, $role)) {
                return;
            }
        }
        throw new AuthorizationException('Missing any required role.');
    }

    /** Object-level ownership check (IDOR prevention). */
    public function requireOwnership(string $actualOwnerId16, string $userId16): void
    {
        if (!hash_equals($actualOwnerId16, $userId16)) {
            throw new AuthorizationException('Not the owner of this entity.');
        }
    }

    public function requireCommitteeMembership(string $userId16, string $committeeId16, DateTimeImmutable $at): void
    {
        if (!$this->rbac->isCommitteeMemberAt($userId16, $committeeId16, $at)) {
            throw new AuthorizationException('Not an active committee member at decision time.');
        }
    }
}
