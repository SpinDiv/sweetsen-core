<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Tenancy\Actor;

/**
 * Limits on the three website forms, set by the LGU admin per tenant (Console > Requests > Form settings):
 * open or closed, an optional opening and closing date (Manila), submissions per visitor per day, and a daily cap for
 * the whole site. Settings, not domain data: kept in one option per tenant. Enforced by Registrations::guard.
 */
final class FormLimits
{
    public const KINDS = ['senior' => 'Senior registration', 'sponsor' => 'Sponsor sign-up', 'inquiry' => 'Contact messages'];
    private const DEFAULTS = ['open' => true, 'opens_on' => '', 'closes_on' => '', 'per_visitor' => 3, 'daily_cap' => 100, 'message' => ''];

    /** @return array<string, array{open:bool, opens_on:string, closes_on:string, per_visitor:int, daily_cap:int, message:string}> */
    public static function get(string $tenantId): array
    {
        $saved = (array) get_option('sweetsen_form_limits_' . $tenantId, []);
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $out[$k] = array_merge(self::DEFAULTS, array_intersect_key((array) ($saved[$k] ?? []), self::DEFAULTS));
        }
        return $out;
    }

    /** @param array<string, mixed> $in kind => {open, opens_on, closes_on, per_visitor, daily_cap, message} */
    public static function save(Actor $actor, array $in): void
    {
        $actor->require('ss_manage_programs');
        $clean = [];
        foreach (self::KINDS as $k => $label) {
            $v = (array) ($in[$k] ?? []);
            $date = static function ($d) use ($label): string {
                $d = trim((string) $d);
                if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    throw new DomainError('invalid_request', "{$label}: please use a valid date.");
                }
                return $d;
            };
            $opens = $date($v['opens_on'] ?? '');
            $closes = $date($v['closes_on'] ?? '');
            if ($opens !== '' && $closes !== '' && $closes < $opens) {
                throw new DomainError('invalid_request', "{$label}: the closing date must be on or after the opening date.");
            }
            $per = (int) ($v['per_visitor'] ?? 0);
            $cap = (int) ($v['daily_cap'] ?? 0);
            if ($per < 1 || $per > 50) {
                throw new DomainError('invalid_request', "{$label}: requests per visitor must be between 1 and 50 a day.");
            }
            if ($cap < 1 || $cap > 5000) {
                throw new DomainError('invalid_request', "{$label}: the daily limit must be between 1 and 5,000.");
            }
            $clean[$k] = [
                'open' => !empty($v['open']) && $v['open'] !== 'false', 'opens_on' => $opens, 'closes_on' => $closes,
                'per_visitor' => $per, 'daily_cap' => $cap, 'message' => mb_substr(trim(sanitize_text_field((string) ($v['message'] ?? ''))), 0, 200),
            ];
        }
        update_option('sweetsen_form_limits_' . $actor->tenantId, $clean, false);
        $meta = [];
        foreach ($clean as $k => $c) {
            $meta[$k] = ['open' => $c['open'], 'dated' => $c['opens_on'] !== '' || $c['closes_on'] !== '', 'per_visitor' => $c['per_visitor'], 'daily_cap' => $c['daily_cap']];
        }
        Audit::log($actor->tenantId, $actor->userId, 'settings.form_limits', 'settings', null, $meta);
        do_action('litespeed_purge_all');
    }

    /**
     * Whether a form takes submissions right now, and the plain message to show when it does not.
     *
     * @return array{open:bool, reason:?string, message:string}
     */
    public static function state(string $tenantId, string $kind): array
    {
        $c = self::get($tenantId)[$kind];
        $today = Db::todayManila()->format('Y-m-d');
        $what = $kind === 'inquiry' ? 'The contact form' : 'Online registration';
        $fallback = $kind === 'inquiry' ? '' : ' You can still register in person at the senior citizens\' office.';
        $day = static fn(string $d): string => date_i18n('F j, Y', strtotime($d . ' 12:00:00'));
        if (!$c['open']) {
            return ['open' => false, 'reason' => 'closed', 'message' => $c['message'] !== '' ? $c['message'] : "{$what} is closed right now.{$fallback}"];
        }
        if ($c['opens_on'] !== '' && $today < $c['opens_on']) {
            return ['open' => false, 'reason' => 'not_yet', 'message' => "{$what} opens on {$day($c['opens_on'])}."];
        }
        if ($c['closes_on'] !== '' && $today > $c['closes_on']) {
            return ['open' => false, 'reason' => 'ended', 'message' => ($c['message'] !== '' ? $c['message'] . ' ' : '') . "{$what} closed on {$day($c['closes_on'])}.{$fallback}"];
        }
        if (self::todayCount($tenantId, $kind) >= $c['daily_cap']) {
            return ['open' => false, 'reason' => 'full', 'message' => 'We have received all the requests we can take today. Please come back tomorrow.'];
        }
        return ['open' => true, 'reason' => null, 'message' => ''];
    }

    /** Requests of this kind received since midnight Manila time. */
    public static function todayCount(string $tenantId, string $kind): int
    {
        $start = Db::todayManila()->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return (int) Db::value(Db::prepare(
            'SELECT COUNT(*) FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND kind = %s AND created_at >= %s',
            $tenantId, $kind, $start
        ));
    }

    /** Refuses a closed or full form, then counts this visitor's submissions for today (per form). */
    public static function enforce(string $tenantId, string $kind, string $ipHash): void
    {
        $s = self::state($tenantId, $kind);
        if (!$s['open']) {
            throw new DomainError($s['reason'] === 'full' ? 'daily_limit' : 'form_closed', $s['message']);
        }
        $per = self::get($tenantId)[$kind]['per_visitor'];
        $key = 'ss_regd_' . $kind . '_' . substr($ipHash, 0, 20) . '_' . Db::todayManila()->format('Ymd');
        $n = (int) get_transient($key);
        if ($n >= $per) {
            throw new DomainError('rate_limited', 'You have sent the most requests allowed from this connection today. Please try again tomorrow.');
        }
        set_transient($key, $n + 1, DAY_IN_SECONDS);
    }
}
