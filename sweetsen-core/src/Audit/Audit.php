<?php

declare(strict_types=1);

namespace SweetSen\Audit;

use SweetSen\Support\Db;
use SweetSen\Support\Uuid;

/**
 * Append-only audit trail. Records ids and codes, never names, birthdates or document contents.
 * Call it inside the same transaction as the change it records, so both commit or neither does.
 */
final class Audit
{
    /** @param array<string, scalar|null> $meta Ids, codes and counts only. */
    public static function log(?string $tenantId, int $actorUserId, string $action, string $objectType, ?string $objectId, array $meta = []): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? (defined('WP_CLI') && WP_CLI ? 'cli' : 'unknown');
        Db::insert('audit_log', [
            'id' => Uuid::v7(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'ip_hash' => hash('sha256', $ip . wp_salt('auth')),
            'meta_json' => $meta === [] ? null : wp_json_encode($meta),
            'created_at' => Db::now(),
        ], ['id', 'tenant_id', 'object_id']);
    }
}
