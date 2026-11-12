<?php

declare(strict_types=1);

namespace SweetSen\Tenancy;

use SweetSen\Roles\Roles;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;

/**
 * Who is acting, in which tenant, with which role. Every service call takes one.
 *
 * The tenant always comes from the user's active membership, never from a request parameter.
 * A capability counts only if BOTH the WordPress user has it AND the membership role grants it.
 */
final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $tenantId,
        public readonly string $role,
        public readonly ?string $organizationId = null,
    ) {
    }

    /** Resolve the actor from a user's active membership. Optional tenant slug when a user belongs to several. */
    public static function forUser(int $userId, ?string $tenantSlug = null): self
    {
        $sql = 'SELECT ' . Db::hex('m.tenant_id', 'tenant_id') . ', m.role, ' . Db::hex('m.organization_id', 'organization_id') . '
                FROM ' . Db::t('memberships') . ' m JOIN ' . Db::t('tenants') . ' t ON t.id = m.tenant_id
                WHERE m.user_id = %d AND m.status = %s';
        $args = [$userId, 'active'];
        if ($tenantSlug !== null) {
            $sql .= ' AND t.slug = %s';
            $args[] = $tenantSlug;
        }
        $rows = Db::rows(Db::prepare($sql . ' ORDER BY m.created_at LIMIT 2', ...$args));
        if ($rows === []) {
            throw new DomainError('no_membership', 'This account is not a member of any SweetSen office.');
        }
        if (count($rows) > 1 && $tenantSlug === null) {
            throw new DomainError('tenant_required', 'This account belongs to more than one office; choose one.');
        }
        $r = $rows[0];
        return new self($userId, $r['tenant_id'], $r['role'], $r['organization_id'] ?: null);
    }

    public static function current(): self
    {
        $uid = get_current_user_id();
        if ($uid === 0) {
            throw new DomainError('not_logged_in', 'Please log in.');
        }
        return self::forUser($uid);
    }

    public function can(string $cap): bool
    {
        return in_array($cap, Roles::caps($this->role), true) && user_can($this->userId, $cap);
    }

    public function require(string $cap): void
    {
        if (!$this->can($cap)) {
            throw new DomainError('forbidden', 'You do not have permission to do this.', ['capability' => $cap]);
        }
    }
}
