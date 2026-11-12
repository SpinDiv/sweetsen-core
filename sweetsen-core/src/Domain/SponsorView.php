<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * The sponsor's own dashboard: their pledges, what arrived, where it was assigned, and program
 * totals. Aggregates only. No senior names, ids or details ever leave this class.
 */
final class SponsorView
{
    public const STATUS = [
        'submitted' => 'Waiting for LGU review',
        'approved' => 'Approved',
        'rejected' => 'Not accepted',
    ];

    /** @return array<string, mixed> */
    public static function dashboard(Actor $actor): array
    {
        $actor->require('ss_submit_pledge');
        $org = self::org($actor);
        $t = $actor->tenantId;
        $o = $org['id'];

        $pledges = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('pl.id', 'id') . ', pl.ref_no, pl.kind, pl.amount, pl.item_description, pl.quantity, pl.status, pl.review_note,
                    pl.created_at, p.name AS program,
                    (SELECT COALESCE(SUM(d.amount), 0) FROM ' . Db::t('donations_received') . ' d WHERE d.pledge_id = pl.id) AS received_amount,
                    (SELECT COALESCE(SUM(d.quantity), 0) FROM ' . Db::t('donations_received') . ' d WHERE d.pledge_id = pl.id) AS received_quantity
             FROM ' . Db::t('pledges') . ' pl
             LEFT JOIN ' . Db::t('programs') . ' p ON p.id = pl.program_id
             WHERE pl.tenant_id = UNHEX(%s) AND pl.organization_id = UNHEX(%s)
             ORDER BY pl.created_at DESC',
            $t, $o
        ));

        $receipts = Db::rows(Db::prepare(
            'SELECT d.receipt_no, d.amount, d.quantity, d.item_description, d.received_at, pl.ref_no AS pledge_ref,
                    (SELECT GROUP_CONCAT(DISTINCT p.name SEPARATOR \', \') FROM ' . Db::t('donation_allocations') . ' al
                       JOIN ' . Db::t('programs') . ' p ON p.id = al.program_id WHERE al.donation_id = d.id) AS programs
             FROM ' . Db::t('donations_received') . ' d
             LEFT JOIN ' . Db::t('pledges') . ' pl ON pl.id = d.pledge_id
             WHERE d.tenant_id = UNHEX(%s) AND d.organization_id = UNHEX(%s)
             ORDER BY d.received_at DESC LIMIT 20',
            $t, $o
        ));

        // Programs this sponsor's deliveries were assigned to, with program-wide reach (all funding sources).
        $programs = Db::rows(Db::prepare(
            'SELECT p.name AS program, p.benefit_type, p.unit_label,
                    COALESCE(SUM(al.amount), 0) AS assigned_amount, COALESCE(SUM(al.quantity), 0) AS assigned_quantity,
                    (SELECT COUNT(DISTINCT e.beneficiary_id) FROM ' . Db::t('entitlements') . ' e
                      WHERE e.program_id = p.id AND e.tenant_id = p.tenant_id AND e.status = %s) AS seniors_reached,
                    (SELECT COUNT(*) FROM ' . Db::t('entitlements') . ' e
                      WHERE e.program_id = p.id AND e.tenant_id = p.tenant_id AND e.status = %s) AS waiting
             FROM ' . Db::t('donation_allocations') . ' al
             JOIN ' . Db::t('donations_received') . ' d ON d.id = al.donation_id
             JOIN ' . Db::t('programs') . ' p ON p.id = al.program_id
             WHERE al.tenant_id = UNHEX(%s) AND d.organization_id = UNHEX(%s)
             GROUP BY p.id, p.name, p.benefit_type, p.unit_label ORDER BY p.name',
            'fulfilled', 'scheduled', $t, $o
        ));

        $reached = (int) Db::value(Db::prepare(
            'SELECT COUNT(DISTINCT e.beneficiary_id) FROM ' . Db::t('entitlements') . ' e
             WHERE e.tenant_id = UNHEX(%s) AND e.status = %s AND e.program_id IN (
                SELECT al.program_id FROM ' . Db::t('donation_allocations') . ' al
                JOIN ' . Db::t('donations_received') . ' d ON d.id = al.donation_id
                WHERE al.tenant_id = UNHEX(%s) AND d.organization_id = UNHEX(%s))',
            $t, 'fulfilled', $t, $o
        ));

        $sum = static function (array $rows, string $kind, string $field, array $statuses = []) {
            $total = 0;
            foreach ($rows as $r) {
                if ($r['kind'] === $kind && ($statuses === [] || in_array($r['status'], $statuses, true))) {
                    $total += $field === 'amount' ? self::centavos((string) $r['amount']) : (int) $r['quantity'];
                }
            }
            return $total;
        };
        $live = ['submitted', 'approved'];
        $totals = [
            'pledged_cash' => $sum($pledges, 'cash', 'amount', $live),
            'pledged_items' => $sum($pledges, 'in_kind', 'quantity', $live),
            'received_cash' => array_sum(array_map(static fn($r) => self::centavos((string) $r['received_amount']), $pledges)),
            'received_items' => array_sum(array_map(static fn($r) => (int) $r['received_quantity'], $pledges)),
            'deliveries' => count($receipts),
            'seniors_reached' => $reached,
            'programs' => count($programs),
            'waiting_review' => count(array_filter($pledges, static fn($r) => $r['status'] === 'submitted')),
        ];

        $open = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('id') . ', name, benefit_type FROM ' . Db::t('programs') . '
             WHERE tenant_id = UNHEX(%s) AND status = %s AND ends_on >= %s ORDER BY name',
            $t, 'published', Db::todayManila()->format('Y-m-d')
        ));

        return ['org' => $org, 'totals' => $totals, 'pledges' => $pledges, 'receipts' => $receipts, 'programs' => $programs, 'open_programs' => $open];
    }

    /**
     * Validate a pledge from the sponsor form, then hand it to Sponsors::submitPledge.
     *
     * @param array<string, mixed> $in
     */
    public static function pledge(Actor $actor, array $in): string
    {
        $actor->require('ss_submit_pledge');
        $kind = (string) ($in['kind'] ?? '');
        $programId = Uuid::assertHex((string) ($in['program_id'] ?? ''), 'program id');
        $prog = Db::value(Db::prepare(
            'SELECT 1 FROM ' . Db::t('programs') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND status = %s',
            $programId, $actor->tenantId, 'published'
        ));
        if (!$prog) {
            throw new DomainError('program_closed', 'Choose one of the open programs.');
        }
        if ($kind === 'cash') {
            $amount = str_replace(',', '', trim((string) ($in['amount'] ?? '')));
            if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) || self::centavos($amount) < 100) {
                throw new DomainError('invalid_pledge', 'Enter an amount of at least 1 peso, like 25000 or 25000.50.');
            }
            return Sponsors::submitPledge($actor, ['kind' => 'cash', 'amount' => $amount, 'program_id' => $programId]);
        }
        if ($kind === 'in_kind') {
            $what = trim(sanitize_text_field((string) ($in['item_description'] ?? '')));
            $qty = (string) ($in['quantity'] ?? '');
            if ($what === '' || mb_strlen($what) > 200) {
                throw new DomainError('invalid_pledge', 'Describe the goods in a few words (up to 200 characters).');
            }
            if (!preg_match('/^\d{1,6}$/', $qty) || (int) $qty < 1) {
                throw new DomainError('invalid_pledge', 'Enter how many items, as a whole number.');
            }
            return Sponsors::submitPledge($actor, ['kind' => 'in_kind', 'item_description' => $what, 'quantity' => (int) $qty, 'program_id' => $programId]);
        }
        throw new DomainError('invalid_pledge', 'Choose cash or goods.');
    }

    /** @return array{id:string, name:string, status:string} */
    private static function org(Actor $actor): array
    {
        if ($actor->organizationId === null) {
            throw new DomainError('no_organization', 'Your account is not linked to an organization.');
        }
        $o = Db::row(Db::prepare(
            'SELECT ' . Db::hex('id') . ', name, status FROM ' . Db::t('organizations') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $actor->organizationId, $actor->tenantId
        ));
        if ($o === null) {
            throw new DomainError('no_organization', 'Your account is not linked to an organization.');
        }
        return $o;
    }

    public static function centavos(string $v): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($v), $m)) {
            return 0;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
