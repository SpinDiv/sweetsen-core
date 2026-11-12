<?php

declare(strict_types=1);

namespace SweetSen\Seed;

use SweetSen\Support\Db;
use SweetSen\Support\Uuid;

/** Helpers to create tenants, users and memberships (used by the seeder and the selftest). */
final class Accounts
{
    public static function tenant(string $slug, string $name): string
    {
        $id = Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('tenants') . ' WHERE slug = %s', $slug));
        if ($id) {
            return (string) $id;
        }
        $id = Uuid::v7();
        Db::insert('tenants', ['id' => $id, 'slug' => $slug, 'name' => $name, 'timezone' => 'Asia/Manila', 'created_at' => Db::now()], ['id']);
        return $id;
    }

    /**
     * Create (or reuse) a WordPress user with one Sweet Sen role. Returns [user_id, password|null];
     * the password is returned only when the user is newly created.
     *
     * @return array{0:int, 1:?string}
     */
    public static function user(string $login, string $email, string $displayName, string $role): array
    {
        $existing = get_user_by('login', $login);
        if ($existing) {
            $existing->set_role($role);
            return [(int) $existing->ID, null];
        }
        $password = wp_generate_password(16, false, false);
        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $displayName,
            'role' => $role,
        ]);
        if (is_wp_error($id)) {
            throw new \RuntimeException("Could not create user {$login}: " . $id->get_error_message());
        }
        return [(int) $id, $password];
    }

    public static function membership(string $tenantId, int $userId, string $role, ?string $organizationId = null): void
    {
        $exists = Db::value(Db::prepare(
            'SELECT 1 FROM ' . Db::t('memberships') . ' WHERE tenant_id = UNHEX(%s) AND user_id = %d',
            $tenantId, $userId
        ));
        if ($exists) {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('memberships') . ' SET role = %s, organization_id = ' . ($organizationId ? 'UNHEX(%s)' : 'NULL') . ', status = %s, updated_at = %s
                 WHERE tenant_id = UNHEX(%s) AND user_id = %d',
                ...array_values(array_filter([$role, $organizationId, 'active', Db::now(), $tenantId, $userId], static fn($v) => $v !== null))
            ));
            return;
        }
        Db::insert('memberships', [
            'id' => Uuid::v7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role' => $role,
            'organization_id' => $organizationId,
            'status' => 'active',
            'created_at' => Db::now(),
        ], ['id', 'tenant_id', 'organization_id']);
    }

    public static function organization(string $tenantId, string $name, string $type, int $verifiedBy): string
    {
        $id = Db::value(Db::prepare(
            'SELECT LOWER(HEX(id)) FROM ' . Db::t('organizations') . ' WHERE tenant_id = UNHEX(%s) AND name = %s',
            $tenantId, $name
        ));
        if ($id) {
            return (string) $id;
        }
        $id = Uuid::v7();
        Db::insert('organizations', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'type' => $type,
            'status' => 'verified',
            'verified_by' => $verifiedBy,
            'verified_at' => Db::now(),
            'created_at' => Db::now(),
        ], ['id', 'tenant_id']);
        return $id;
    }
}
