<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Support\Db;
use SweetSen\Tenancy\Actor;

/**
 * Read-only lists for the LGU console. Each method checks its own capability and always
 * filters by the actor's tenant. Staff screens show full names; the release desk does not.
 */
final class Listings
{
    public const PER_PAGE = 25;

    /** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} */
    public static function seniors(Actor $a, string $q = '', string $status = '', int $page = 1): array
    {
        $a->require('ss_manage_beneficiaries');
        $b = Db::t('beneficiaries');
        $where = Db::prepare('b.tenant_id = UNHEX(%s) AND b.status = %s', $a->tenantId, 'active');
        if (in_array($status, ['verified', 'unverified'], true)) {
            $where .= Db::prepare(' AND b.verification_status = %s', $status);
        }
        $q = trim($q);
        if ($q !== '') {
            $like = Db::wpdb()->esc_like($q) . '%';
            $where .= Db::prepare(' AND (b.ref_no LIKE %s OR b.last_name LIKE %s OR b.first_name LIKE %s OR b.source_ref LIKE %s)', $like, $like, $like, $like);
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM {$b} b WHERE {$where}");
        [$page, $pages, $offset] = self::paging($total, $page);
        $rows = Db::rows("SELECT LOWER(HEX(b.id)) AS id, b.ref_no, b.first_name, b.middle_name, b.last_name, b.suffix, b.barangay,
                b.birthdate, b.verification_status, b.user_id, b.osca_id,
                EXISTS(SELECT 1 FROM " . Db::t('qr_credentials') . " q WHERE q.beneficiary_id = b.id AND q.status = 'active') AS has_qr
            FROM {$b} b WHERE {$where} ORDER BY b.ref_no LIMIT " . self::PER_PAGE . " OFFSET {$offset}");
        $today = Db::todayManila();
        $rows = array_map(static fn(array $r): array => [
            'id' => $r['id'],
            'ref_no' => $r['ref_no'],
            'name' => self::fullName($r),
            'barangay' => $r['barangay'],
            'age' => (new \DateTimeImmutable($r['birthdate']))->diff($today)->y,
            'status' => $r['verification_status'],
            'has_qr' => (bool) $r['has_qr'],
            'has_login' => $r['user_id'] !== null,
            'osca_id' => $r['osca_id'],
        ], $rows);
        return compact('rows', 'total', 'page', 'pages');
    }

    /**
     * One senior record for the review screen: full details, where it came from, similar records, and how it was verified.
     *
     * @return array<string, mixed>
     */
    public static function seniorReview(Actor $a, string $id): array
    {
        $a->require('ss_manage_beneficiaries');
        $id = \SweetSen\Support\Uuid::assertHex($id, 'senior id');
        $b = Db::row(Db::prepare('SELECT LOWER(HEX(id)) AS id, ref_no, source_ref, last_name, first_name, middle_name, suffix, sex, birthdate,
                barangay, address_line, contact_phone, osca_id, verification_status, verified_by, verified_at, user_id, created_at,
                LOWER(HEX(import_row_id)) AS import_row
            FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND status = %s', $id, $a->tenantId, 'active'));
        if ($b === null) {
            throw new \SweetSen\Support\DomainError('not_found', 'Senior record not found.');
        }
        $local = static fn(?string $utc, string $f = 'M j, Y'): string => $utc ? get_date_from_gmt(substr($utc, 0, 19), $f) : '';
        $origin = ['kind' => 'staff', 'label' => 'Added by LGU staff', 'detail' => ''];
        $req = Db::row(Db::prepare('SELECT ref_no, filed_by, filer_name, filer_relation, created_at FROM ' . Db::t('registration_requests')
            . ' WHERE tenant_id = UNHEX(%s) AND beneficiary_id = UNHEX(%s) LIMIT 1', $a->tenantId, $id));
        if ($req !== null) {
            $by = $req['filed_by'] === 'family' && $req['filer_name']
                ? 'filed by ' . $req['filer_name'] . ($req['filer_relation'] ? ' (' . $req['filer_relation'] . ')' : '')
                : 'filed by the senior';
            $origin = ['kind' => 'website', 'label' => 'Website registration ' . $req['ref_no'], 'detail' => ucfirst($by) . ' on ' . $local($req['created_at'])];
        } elseif ($b['import_row'] !== null) {
            $imp = Db::row(Db::prepare('SELECT j.filename, r.row_no, j.created_at FROM ' . Db::t('import_rows') . ' r JOIN ' . Db::t('import_jobs')
                . ' j ON j.id = r.job_id WHERE r.id = UNHEX(%s)', $b['import_row']));
            if ($imp !== null) {
                $origin = ['kind' => 'import', 'label' => 'CSV import: ' . $imp['filename'], 'detail' => 'Row ' . $imp['row_no'] . ', imported ' . $local($imp['created_at'])];
            }
        }
        $similar = Db::rows(Db::prepare('SELECT ref_no, first_name, middle_name, last_name, suffix, birthdate, barangay, verification_status
            FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND status = %s AND id <> UNHEX(%s)
              AND ((last_name = %s AND birthdate = %s) OR (last_name = %s AND first_name = %s) OR (osca_id IS NOT NULL AND osca_id = %s))
            ORDER BY ref_no LIMIT 5', $a->tenantId, 'active', $id, $b['last_name'], $b['birthdate'], $b['last_name'], $b['first_name'], (string) $b['osca_id']));
        $verified = null;
        if ($b['verification_status'] === 'verified') {
            $meta = (string) Db::value(Db::prepare('SELECT meta_json FROM ' . Db::t('audit_log') . ' WHERE tenant_id = UNHEX(%s) AND action = %s
                AND object_id = UNHEX(%s) ORDER BY created_at DESC LIMIT 1', $a->tenantId, 'beneficiary.verified', $id));
            $type = (string) (json_decode($meta ?: '{}', true)['id_type'] ?? '');
            $user = $b['verified_by'] ? get_userdata((int) $b['verified_by']) : false;
            $verified = ['on' => $local($b['verified_at']), 'by' => $user ? $user->display_name : 'LGU staff',
                'id_type' => Beneficiaries::ID_TYPES[$type] ?? 'Not recorded (verified before the checklist existed)'];
        }
        $today = Db::todayManila();
        return [
            'id' => $b['id'], 'ref_no' => $b['ref_no'], 'name' => self::fullName($b), 'status' => $b['verification_status'],
            'details' => array_filter([
                'Last name' => $b['last_name'], 'First name' => $b['first_name'], 'Middle name' => (string) $b['middle_name'], 'Suffix' => (string) $b['suffix'],
                'Sex' => match ($b['sex']) { 'F' => 'Female', 'M' => 'Male', default => '' },
                'Birth date' => wp_date('F j, Y', strtotime($b['birthdate'] . ' 12:00')) . ' (age ' . (new \DateTimeImmutable($b['birthdate']))->diff($today)->y . ')',
                'Barangay' => $b['barangay'], 'Address' => (string) $b['address_line'], 'Mobile number' => (string) $b['contact_phone'],
                'OSCA ID' => (string) $b['osca_id'], 'Source reference' => (string) $b['source_ref'],
                'Has a SweetSen login' => $b['user_id'] !== null ? 'Yes' : 'No', 'Record created' => $local($b['created_at']),
            ], static fn($v): bool => $v !== ''),
            'origin' => $origin,
            'similar' => array_map(static fn(array $r): array => ['ref_no' => $r['ref_no'], 'name' => self::fullName($r),
                'born' => wp_date('M j, Y', strtotime($r['birthdate'] . ' 12:00')), 'barangay' => $r['barangay'],
                'verified' => $r['verification_status'] === 'verified'], $similar),
            'verified' => $verified,
            'id_types' => Beneficiaries::ID_TYPES, 'checks' => Beneficiaries::CHECKS,
        ];
    }

    public const EXPORT_COLUMNS = ['Reference no.', 'Last name', 'First name', 'Middle name', 'Suffix', 'Sex', 'Birth date', 'Age',
        'Barangay', 'Address', 'Mobile number', 'OSCA ID', 'Verification', 'Verified on', 'Has login', 'Has QR code', 'Registered on'];

    /**
     * Every senior matching the Seniors page filter, for a spreadsheet. LGU admins only; each export is audited
     * (filter and row count, never names).
     *
     * @return list<list<string>>
     */
    public static function seniorsExport(Actor $a, string $q = '', string $status = ''): array
    {
        $a->require('ss_export_beneficiaries');
        $b = Db::t('beneficiaries');
        $where = Db::prepare('b.tenant_id = UNHEX(%s) AND b.status = %s', $a->tenantId, 'active');
        $status = in_array($status, ['verified', 'unverified'], true) ? $status : '';
        if ($status !== '') {
            $where .= Db::prepare(' AND b.verification_status = %s', $status);
        }
        $q = trim($q);
        if ($q !== '') {
            $like = Db::wpdb()->esc_like($q) . '%';
            $where .= Db::prepare(' AND (b.ref_no LIKE %s OR b.last_name LIKE %s OR b.first_name LIKE %s OR b.source_ref LIKE %s)', $like, $like, $like, $like);
        }
        $rows = Db::rows("SELECT b.ref_no, b.last_name, b.first_name, b.middle_name, b.suffix, b.sex, b.birthdate, b.barangay,
                b.address_line, b.contact_phone, b.osca_id, b.verification_status, b.verified_at, b.user_id, b.created_at,
                EXISTS(SELECT 1 FROM " . Db::t('qr_credentials') . " q WHERE q.beneficiary_id = b.id AND q.status = 'active') AS has_qr
            FROM {$b} b WHERE {$where} ORDER BY b.ref_no");
        $today = Db::todayManila();
        $local = static fn(?string $utc): string => $utc ? get_date_from_gmt(substr($utc, 0, 19), 'Y-m-d') : '';
        $out = array_map(static fn(array $r): array => [
            (string) $r['ref_no'], (string) $r['last_name'], (string) $r['first_name'], (string) $r['middle_name'], (string) $r['suffix'],
            match ($r['sex']) { 'F' => 'Female', 'M' => 'Male', default => '' },
            (string) $r['birthdate'], (string) (new \DateTimeImmutable($r['birthdate']))->diff($today)->y,
            (string) $r['barangay'], (string) $r['address_line'], (string) $r['contact_phone'], (string) $r['osca_id'],
            $r['verification_status'] === 'verified' ? 'Verified' : 'To verify', $local($r['verified_at']),
            $r['user_id'] !== null ? 'Yes' : 'No', $r['has_qr'] ? 'Yes' : 'No', $local($r['created_at']),
        ], $rows);
        \SweetSen\Audit\Audit::log($a->tenantId, $a->userId, 'beneficiary.exported', 'beneficiary', null,
            ['filter' => $status ?: 'all', 'searched' => $q !== '', 'rows' => count($out)]);
        return $out;
    }

    /** @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int} */
    public static function applications(Actor $a, string $filter = 'waiting', int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $a->require('ss_manage_beneficiaries');
        $where = Db::prepare('a.tenant_id = UNHEX(%s)', $a->tenantId);
        $where .= match ($filter) {
            'waiting' => " AND a.status IN ('submitted','under_review','info_needed')",
            'approved' => " AND a.status = 'approved'",
            'rejected' => " AND a.status = 'rejected'",
            'withdrawn' => " AND a.status = 'withdrawn'",
            default => '',
        };
        $from = Db::t('applications') . ' a JOIN ' . Db::t('beneficiaries') . ' b ON b.id = a.beneficiary_id JOIN ' . Db::t('programs') . ' p ON p.id = a.program_id';
        $total = (int) Db::value("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        [$page, $pages, $offset] = self::paging($total, $page, $perPage);
        $rows = Db::rows("SELECT LOWER(HEX(a.id)) AS id, a.ref_no, a.status, a.submitted_at, a.decided_at, a.decision_note,
                b.first_name, b.middle_name, b.last_name, b.suffix, b.ref_no AS senior_ref, b.verification_status, p.name AS program
            FROM {$from} WHERE {$where} ORDER BY a.submitted_at ASC LIMIT {$perPage} OFFSET {$offset}");
        $rows = array_map(static fn(array $r): array => [
            'id' => $r['id'],
            'ref_no' => $r['ref_no'],
            'status' => $r['status'],
            'senior' => self::fullName($r),
            'senior_ref' => $r['senior_ref'],
            'senior_verified' => $r['verification_status'] === 'verified',
            'program' => $r['program'],
            'submitted' => $r['submitted_at'] ? get_date_from_gmt(substr($r['submitted_at'], 0, 19), 'M j, g:i A') : '',
            'note' => (string) $r['decision_note'],
        ], $rows);
        return compact('rows', 'total', 'page', 'pages');
    }

    /** Number of applications behind each tab of the Applications page. @return array<string, int> */
    public static function applicationCounts(Actor $a): array
    {
        $a->require('ss_manage_beneficiaries');
        $by = [];
        foreach (Db::rows(Db::prepare('SELECT status, COUNT(*) AS n FROM ' . Db::t('applications') . ' WHERE tenant_id = UNHEX(%s) GROUP BY status', $a->tenantId)) as $r) {
            $by[$r['status']] = (int) $r['n'];
        }
        return [
            'waiting' => ($by['submitted'] ?? 0) + ($by['under_review'] ?? 0) + ($by['info_needed'] ?? 0),
            'approved' => $by['approved'] ?? 0, 'rejected' => $by['rejected'] ?? 0, 'withdrawn' => $by['withdrawn'] ?? 0,
            'all' => array_sum($by),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function releases(Actor $a, int $limit = 50): array
    {
        $a->require('ss_manage_beneficiaries');
        $f = Db::t('fulfillments');
        $rows = Db::rows(Db::prepare(
            "SELECT LOWER(HEX(f.id)) AS id, f.ref_no, f.kind, f.method, f.created_at, f.note, f.verifier_user_id,
                    f.amount, f.quantity, p.unit_label, p.benefit_type,
                    b.first_name, b.middle_name, b.last_name, b.suffix, p.name AS program, u.display_name AS by_name,
                    EXISTS(SELECT 1 FROM {$f} r WHERE r.reverses_id = f.id) AS reversed
             FROM {$f} f JOIN " . Db::t('entitlements') . ' e ON e.id = f.entitlement_id
             JOIN ' . Db::t('beneficiaries') . ' b ON b.id = e.beneficiary_id
             JOIN ' . Db::t('programs') . ' p ON p.id = e.program_id
             LEFT JOIN ' . Db::wpdb()->users . " u ON u.ID = f.verifier_user_id
             WHERE f.tenant_id = UNHEX(%s) ORDER BY f.created_at DESC LIMIT %d",
            $a->tenantId, $limit
        ));
        return array_map(static fn(array $r): array => [
            'id' => $r['id'],
            'ref_no' => $r['ref_no'],
            'kind' => $r['kind'],
            'method' => $r['method'],
            'time' => get_date_from_gmt(substr($r['created_at'], 0, 19), 'M j, g:i A'),
            'senior' => self::fullName($r),
            'program' => $r['program'],
            'what' => $r['benefit_type'] === 'cash' && $r['amount'] !== null
                ? 'PHP ' . number_format((float) $r['amount'], 2)
                : ($r['quantity'] !== null ? $r['quantity'] . ' ' . ($r['unit_label'] ?: 'item') : ''),
            'by' => $r['by_name'] ?? '',
            'by_me' => (int) $r['verifier_user_id'] === $a->userId,
            'reversed' => (bool) $r['reversed'],
            'note' => (string) $r['note'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    public static function pledges(Actor $a): array
    {
        $a->require('ss_review_pledges');
        $rows = Db::rows(Db::prepare(
            'SELECT LOWER(HEX(pl.id)) AS id, pl.ref_no, pl.kind, pl.amount, pl.quantity, pl.item_description, pl.status, pl.created_at,
                    o.name AS org, p.name AS program,
                    (SELECT COALESCE(SUM(d.quantity), 0) FROM ' . Db::t('donations_received') . ' d WHERE d.pledge_id = pl.id) AS got_qty,
                    (SELECT COALESCE(SUM(d.amount), 0) FROM ' . Db::t('donations_received') . ' d WHERE d.pledge_id = pl.id) AS got_amount
             FROM ' . Db::t('pledges') . ' pl JOIN ' . Db::t('organizations') . ' o ON o.id = pl.organization_id
             LEFT JOIN ' . Db::t('programs') . ' p ON p.id = pl.program_id
             WHERE pl.tenant_id = UNHEX(%s) ORDER BY FIELD(pl.status, %s, %s, %s), pl.created_at DESC',
            $a->tenantId, 'submitted', 'approved', 'rejected'
        ));
        return array_map(static fn(array $r): array => [
            'id' => $r['id'],
            'ref_no' => $r['ref_no'],
            'org' => $r['org'],
            'kind' => $r['kind'],
            'program' => $r['program'] ?? 'Any program',
            'status' => $r['status'],
            'what' => $r['kind'] === 'cash' ? 'PHP ' . number_format((float) $r['amount'], 2) : $r['quantity'] . ' x ' . ($r['item_description'] ?: 'items'),
            'pledged' => $r['kind'] === 'cash' ? (string) $r['amount'] : (string) $r['quantity'],
            'received' => $r['kind'] === 'cash' ? (string) $r['got_amount'] : (string) $r['got_qty'],
            'date' => get_date_from_gmt(substr($r['created_at'], 0, 19), 'M j, Y'),
        ], $rows);
    }

    /** @return array{jobs:list<array<string,mixed>>, flagged:list<array<string,mixed>>} */
    public static function imports(Actor $a): array
    {
        $a->require('ss_import');
        $jobs = Db::rows(Db::prepare(
            'SELECT LOWER(HEX(j.id)) AS id, j.filename, j.total_rows, j.created_count, j.flagged_count, j.error_count, j.created_at, u.display_name
             FROM ' . Db::t('import_jobs') . ' j LEFT JOIN ' . Db::wpdb()->users . ' u ON u.ID = j.created_by
             WHERE j.tenant_id = UNHEX(%s) ORDER BY j.created_at DESC LIMIT 10',
            $a->tenantId
        ));
        $flagged = Db::rows(Db::prepare(
            "SELECT r.row_no, r.outcome, r.match_reason, r.message, r.payload_json, j.filename, m.ref_no AS match_ref
             FROM " . Db::t('import_rows') . ' r JOIN ' . Db::t('import_jobs') . ' j ON j.id = r.job_id
             LEFT JOIN ' . Db::t('beneficiaries') . " m ON m.id = r.match_beneficiary_id
             WHERE r.tenant_id = UNHEX(%s) AND r.outcome IN ('flagged','error') AND r.resolved_at IS NULL
             ORDER BY r.created_at DESC LIMIT 50",
            $a->tenantId
        ));
        return [
            'jobs' => array_map(static fn(array $j): array => [
                'file' => $j['filename'], 'total' => (int) $j['total_rows'], 'created' => (int) $j['created_count'],
                'flagged' => (int) $j['flagged_count'], 'errors' => (int) $j['error_count'], 'by' => $j['display_name'] ?? '',
                'time' => get_date_from_gmt(substr($j['created_at'], 0, 19), 'M j, g:i A'),
            ], $jobs),
            'flagged' => array_map(static function (array $r): array {
                $d = json_decode($r['payload_json'], true) ?: [];
                return [
                    'file' => $r['filename'], 'row' => (int) $r['row_no'], 'outcome' => $r['outcome'],
                    'name' => trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')),
                    'birthdate' => $d['birthdate'] ?? '', 'source_ref' => $d['ref_no'] ?? '',
                    'reason' => self::REASONS[$r['match_reason']] ?? (string) $r['message'], 'match_ref' => $r['match_ref'],
                ];
            }, $flagged),
        ];
    }

    public const REASONS = [
        'same_osca_id' => 'Same OSCA ID as an existing senior',
        'same_source_ref' => 'Same source reference as an existing senior',
        'same_name_and_birthdate' => 'Same name and birthdate as an existing senior',
        'same_name_birthdate_off_by_one' => 'Same name, birthdate one day apart (possible typo)',
    ];

    /** @return list<array<string,string>> */
    public static function audit(Actor $a, int $limit = 100): array
    {
        $a->require('ss_view_audit');
        $rows = Db::rows(Db::prepare(
            'SELECT l.action, l.object_type, l.created_at, l.meta_json, l.actor_user_id, u.display_name
             FROM ' . Db::t('audit_log') . ' l LEFT JOIN ' . Db::wpdb()->users . ' u ON u.ID = l.actor_user_id
             WHERE l.tenant_id = UNHEX(%s) ORDER BY l.created_at DESC LIMIT %d',
            $a->tenantId, $limit
        ));
        return array_map(static fn(array $r): array => [
            'time' => get_date_from_gmt(substr($r['created_at'], 0, 19), 'M j, Y g:i:s A'),
            'who' => $r['display_name'] ?? ((int) $r['actor_user_id'] === 0 ? 'Website visitor' : 'Unknown user'),
            'what' => Reports::ACTIONS[$r['action']] ?? $r['action'],
            'code' => $r['action'],
            'detail' => self::metaText($r['meta_json']),
        ], $rows);
    }

    private static function metaText(?string $json): string
    {
        $m = $json ? (json_decode($json, true) ?: []) : [];
        $parts = [];
        foreach ($m as $k => $v) {
            if ($k === 'reverses') {
                continue;
            }
            $parts[] = str_replace('_', ' ', (string) $k) . ': ' . (is_scalar($v) ? (string) $v : '');
        }
        return implode(', ', $parts);
    }

    /** @param array<string, mixed> $r */
    public static function fullName(array $r): string
    {
        $mid = !empty($r['middle_name']) ? ' ' . mb_substr((string) $r['middle_name'], 0, 1) . '.' : '';
        return trim($r['first_name'] . $mid . ' ' . $r['last_name'] . (!empty($r['suffix']) ? ' ' . $r['suffix'] : ''));
    }

    /** @return array{0:int, 1:int, 2:int} page, pages, offset */
    private static function paging(int $total, int $page, int $per = self::PER_PAGE): array
    {
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, $page), $pages);
        return [$page, $pages, ($page - 1) * $per];
    }
}
