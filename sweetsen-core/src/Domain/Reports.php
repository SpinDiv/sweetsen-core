<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Support\Db;
use SweetSen\Tenancy\Actor;

/**
 * Dashboard numbers for the LGU console. Every figure is computed from the ledgers,
 * so approved, released, pledged and received always reconcile with the source records.
 */
final class Reports
{
    public const WAITING = ['submitted', 'under_review', 'info_needed'];

    /** @return array<string, mixed> */
    public static function kpis(Actor $a): array
    {
        $a->require('ss_view_reports');
        $t = $a->tenantId;
        $seniors = Db::row(Db::prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(verification_status = %s), 0) AS verified
             FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND status = %s',
            'verified', $t, 'active'
        ));
        $waiting = (int) Db::value(Db::prepare(
            'SELECT COUNT(*) FROM ' . Db::t('applications') . ' WHERE tenant_id = UNHEX(%s) AND status IN (%s, %s, %s)',
            $t, ...self::WAITING
        ));
        $today = Db::todayManila();
        $startToday = $today->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $startMonth = $today->modify('first day of this month')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rel = Db::row(Db::prepare(
            'SELECT COALESCE(SUM(created_at >= %s), 0) AS today, COUNT(*) AS month
             FROM ' . Db::t('fulfillments') . ' WHERE tenant_id = UNHEX(%s) AND kind = %s AND created_at >= %s',
            $startToday, $t, 'release', $startMonth
        ));
        $pledged = Db::row(Db::prepare(
            'SELECT COALESCE(SUM(CASE WHEN kind = %s THEN quantity END), 0) AS qty, COALESCE(SUM(CASE WHEN kind = %s THEN amount END), 0) AS amount
             FROM ' . Db::t('pledges') . ' WHERE tenant_id = UNHEX(%s) AND status IN (%s, %s)',
            'in_kind', 'cash', $t, 'submitted', 'approved'
        ));
        $received = Db::row(Db::prepare(
            'SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(amount), 0) AS amount
             FROM ' . Db::t('donations_received') . ' WHERE tenant_id = UNHEX(%s)',
            $t
        ));
        return [
            'seniors' => (int) $seniors['total'],
            'verified' => (int) $seniors['verified'],
            'waiting' => $waiting,
            'released_today' => (int) $rel['today'],
            'released_month' => (int) $rel['month'],
            'pledged_qty' => (int) $pledged['qty'],
            'received_qty' => (int) $received['qty'],
            'pledged_amount' => (string) $pledged['amount'],
            'received_amount' => (string) $received['amount'],
        ];
    }

    /**
     * Releases per Manila day for the last $days days, oldest first, zero-filled.
     *
     * @return list<array{date:string, label:string, count:int}>
     */
    public static function releasesPerDay(Actor $a, int $days = 14): array
    {
        $a->require('ss_view_reports');
        $today = Db::todayManila();
        $from = $today->modify('-' . ($days - 1) . ' days');
        $rows = Db::rows(Db::prepare(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', '+08:00')) AS d, COUNT(*) AS n
             FROM " . Db::t('fulfillments') . ' WHERE tenant_id = UNHEX(%s) AND kind = %s AND created_at >= %s GROUP BY d',
            $a->tenantId, 'release', $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        ));
        $byDay = array_column($rows, 'n', 'd');
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->modify("+{$i} days");
            $key = $d->format('Y-m-d');
            $out[] = ['date' => $key, 'label' => $d->format('M j'), 'count' => (int) ($byDay[$key] ?? 0)];
        }
        return $out;
    }

    /**
     * Per program: how many benefits were approved, how many are released, how many still wait.
     *
     * @return list<array{program:string, approved:int, released:int, waiting:int}>
     */
    public static function programProgress(Actor $a): array
    {
        $a->require('ss_view_reports');
        $rows = Db::rows(Db::prepare(
            'SELECT p.name AS program, COUNT(e.id) AS approved,
                    COALESCE(SUM(e.status = %s), 0) AS released, COALESCE(SUM(e.status = %s), 0) AS waiting
             FROM ' . Db::t('programs') . ' p LEFT JOIN ' . Db::t('entitlements') . ' e ON e.program_id = p.id
             WHERE p.tenant_id = UNHEX(%s) AND p.status = %s
             GROUP BY p.id, p.name ORDER BY approved DESC, p.name',
            'fulfilled', 'scheduled', $a->tenantId, 'published'
        ));
        return array_map(static fn(array $r): array => [
            'program' => $r['program'], 'approved' => (int) $r['approved'],
            'released' => (int) $r['released'], 'waiting' => (int) $r['waiting'],
        ], $rows);
    }

    /** @return list<array<string, string>> */
    public static function reviewQueue(Actor $a, int $limit = 6): array
    {
        $a->require('ss_manage_beneficiaries');
        return Listings::applications($a, 'waiting', 1, $limit)['rows'];
    }

    public const ACTIONS = [
        'import.completed' => 'imported a CSV file of seniors',
        'beneficiary.verified' => 'verified a senior record',
        'qr.issued' => 'issued a QR code',
        'qr.revoked' => 'revoked a QR code',
        'qr.cards_viewed' => 'opened the printable QR cards',
        'program.created' => 'created a program',
        'auth.google_signin' => 'signed in with Google',
        'email.registration_received' => 'emailed a registration receipt',
        'email.senior_welcome' => 'emailed a welcome and sign-in link',
        'email.sponsor_received' => 'emailed a sponsor registration receipt',
        'email.sponsor_welcome' => 'emailed a sponsor welcome and sign-in link',
        'email.failed' => 'could not send an email',
        'settings.google_signin' => 'changed the Google sign-in settings',
        'application.submitted' => 'submitted an application',
        'application.under_review' => 'started reviewing an application',
        'application.approved' => 'approved an application',
        'application.rejected' => 'rejected an application',
        'application.info_needed' => 'asked for more information on an application',
        'application.withdrawn' => 'withdrew an application',
        'fulfillment.released' => 'released a benefit',
        'fulfillment.reversed' => 'reversed a release',
        'pledge.submitted' => 'submitted a sponsor pledge',
        'pledge.approved' => 'approved a sponsor pledge',
        'pledge.rejected' => 'rejected a sponsor pledge',
        'donation.received' => 'recorded a sponsor delivery',
        'donation.allocated' => 'assigned a delivery to a program',
        'registration.submitted' => 'sent a registration request from the website',
        'registration.approved' => 'approved a registration request',
        'registration.rejected' => 'rejected a registration request',
        'beneficiary.exported' => 'exported the senior list to a spreadsheet',
        'program.updated' => 'edited a program',
        'program.published' => 'published a program',
        'program.closed' => 'closed a program',
        'inquiry.submitted' => 'sent a message from the Contact page',
        'inquiry.answered' => 'marked a website message as answered',
    ];

    /** @return list<array{time:string, who:string, what:string}> */
    public static function activity(Actor $a, int $limit = 8): array
    {
        $a->require('ss_view_audit');
        $rows = Db::rows(Db::prepare(
            'SELECT l.action, l.created_at, l.actor_user_id, u.display_name FROM ' . Db::t('audit_log') . ' l
             LEFT JOIN ' . Db::wpdb()->users . ' u ON u.ID = l.actor_user_id
             WHERE l.tenant_id = UNHEX(%s) ORDER BY l.created_at DESC LIMIT %d',
            $a->tenantId, $limit
        ));
        return array_map(static fn(array $r): array => [
            'time' => get_date_from_gmt(substr($r['created_at'], 0, 19), 'M j, g:i A'),
            'who' => $r['display_name'] ?? ((int) $r['actor_user_id'] === 0 ? 'Website visitor' : 'Unknown user'),
            'what' => self::ACTIONS[$r['action']] ?? $r['action'],
        ], $rows);
    }
}
