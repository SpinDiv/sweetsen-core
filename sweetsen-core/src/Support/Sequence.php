<?php

declare(strict_types=1);

namespace SweetSen\Support;

/**
 * Human-readable reference numbers per tenant, e.g. SS-2026-000123.
 * Backed by ss_sequences; the row lock taken by the upsert serializes concurrent callers.
 */
final class Sequence
{
    public static function next(string $tenantHex, string $name): int
    {
        $sql = Db::prepare(
            'INSERT INTO ' . Db::t('sequences') . ' (tenant_id, name, value) VALUES (UNHEX(%s), %s, 1)
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)',
            $tenantHex,
            $name
        );
        $affected = Db::exec($sql);
        // 1 = new row inserted (value 1); 2 = existing row updated (LAST_INSERT_ID holds the new value).
        return $affected === 1 ? 1 : (int) Db::value('SELECT LAST_INSERT_ID()');
    }

    /** e.g. ref('…', 'SS') => SS-2026-000001, counter resets each Manila calendar year. */
    public static function ref(string $tenantHex, string $prefix): string
    {
        $year = Db::todayManila()->format('Y');
        $n = self::next($tenantHex, $prefix . '-' . $year);
        return sprintf('%s-%s-%06d', $prefix, $year, $n);
    }
}
