<?php
/**
 * Database-level append-only guards for the ledgers. Any UPDATE or DELETE on these tables is refused,
 * even from raw SQL, unless the session sets @ss_allow_purge = 1 (used only by the selftest teardown).
 * Optional: if the host's database user lacks the TRIGGER privilege, this is recorded as skipped and
 * the application-level rule (no UPDATE/DELETE in code) still applies.
 */

declare(strict_types=1);

return [
    'version' => '0002_ledger_guards',
    'optional' => true,
    'statements' => static function (string $p, string $o): array {
        $guard = static function (string $table, string $op) use ($p): string {
            $name = "{$p}{$table}_no_" . strtolower($op);
            return "CREATE TRIGGER {$name} BEFORE {$op} ON {$p}{$table} FOR EACH ROW
                    BEGIN
                      IF COALESCE(@ss_allow_purge, 0) <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sweet Sen ledger rows are append-only';
                      END IF;
                    END";
        };
        $out = [];
        foreach (['fulfillments', 'audit_log', 'application_events', 'donations_received', 'redemption_attempts'] as $t) {
            $out[] = $guard($t, 'UPDATE');
            $out[] = $guard($t, 'DELETE');
        }
        return $out;
    },
];
