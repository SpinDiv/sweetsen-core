<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * What a senior sees and does about their own record. Every query is pinned to the senior record
 * linked to the logged-in user; nothing here accepts another senior's id.
 */
final class SelfService
{
    private const OPEN = ['submitted', 'under_review', 'info_needed'];

    /** @return array<string, mixed> The senior record linked to this login. */
    public static function record(Actor $actor): array
    {
        $actor->require('ss_view_own_profile');
        $b = Db::row(Db::prepare(
            'SELECT ' . Db::hex('id') . ', ref_no, first_name, middle_name, last_name, suffix, sex, birthdate, barangay,
                    verification_status, status
             FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND user_id = %d LIMIT 1',
            $actor->tenantId, $actor->userId
        ));
        if ($b === null) {
            throw new DomainError('no_record', 'Your login is not linked to a senior record yet. Please visit the senior citizens\' office.');
        }
        return $b;
    }

    /**
     * Everything for the senior's home page.
     *
     * @return array{me: array<string, mixed>, qr: ?string, ready: list<array<string, mixed>>, received: list<array<string, mixed>>,
     *               applications: list<array<string, mixed>>, programs: list<array<string, mixed>>}
     */
    public static function overview(Actor $actor): array
    {
        $me = self::record($actor);
        $today = Db::todayManila();
        $todayS = $today->format('Y-m-d');
        $qr = $me['verification_status'] === 'verified' && $me['status'] === 'active' ? Beneficiaries::qrPayload($actor, $me['id']) : null;

        $ents = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('e.id', 'id') . ', ' . Db::hex('e.program_id', 'program_id') . ', e.status, e.amount, e.quantity, e.period_key,
                    e.valid_from, e.valid_until, e.location, p.name AS program, p.benefit_type, p.unit_label,
                    f.ref_no AS release_ref, f.created_at AS released_at
             FROM ' . Db::t('entitlements') . ' e
             JOIN ' . Db::t('programs') . ' p ON p.id = e.program_id
             LEFT JOIN ' . Db::t('fulfillments') . ' f ON f.entitlement_id = e.id AND f.kind = %s AND f.release_seq = e.release_seq
             WHERE e.tenant_id = UNHEX(%s) AND e.beneficiary_id = UNHEX(%s)
             ORDER BY e.created_at DESC',
            'release', $actor->tenantId, $me['id']
        ));
        $ready = $received = [];
        foreach ($ents as $e) {
            $item = [
                'program' => $e['program'],
                'what' => self::what($e['benefit_type'], $e['amount'], $e['quantity'], $e['unit_label']),
                'valid_until' => $e['valid_until'],
                'location' => (string) ($e['location'] ?? ''),
            ];
            if ($e['status'] === 'fulfilled' && $e['released_at'] !== null) {
                $received[] = $item + ['ref_no' => $e['release_ref'], 'date' => self::manilaDate($e['released_at'])];
            } elseif ($e['status'] === 'scheduled' && $todayS >= $e['valid_from'] && $todayS <= $e['valid_until']) {
                $ready[] = $item;
            }
        }

        $apps = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('a.id', 'id') . ', ' . Db::hex('a.program_id', 'program_id') . ', a.ref_no, a.status, a.submitted_at, a.decided_at,
                    a.decision_note, p.name AS program,
                    (SELECT ev.note FROM ' . Db::t('application_events') . ' ev WHERE ev.application_id = a.id ORDER BY ev.created_at DESC LIMIT 1) AS last_note
             FROM ' . Db::t('applications') . ' a JOIN ' . Db::t('programs') . ' p ON p.id = a.program_id
             WHERE a.tenant_id = UNHEX(%s) AND a.beneficiary_id = UNHEX(%s) AND a.status <> %s
             ORDER BY a.created_at DESC LIMIT 12',
            $actor->tenantId, $me['id'], 'withdrawn'
        ));
        $applications = array_map(static fn(array $a): array => [
            'id' => $a['id'],
            'ref_no' => $a['ref_no'],
            'program' => $a['program'],
            'status' => $a['status'],
            'submitted' => $a['submitted_at'] ? self::manilaDate($a['submitted_at']) : '',
            'decided' => $a['decided_at'] ? self::manilaDate($a['decided_at']) : '',
            'note' => (string) ($a['status'] === 'info_needed' ? $a['last_note'] : ($a['status'] === 'rejected' ? $a['decision_note'] : '')),
        ], $apps);

        $openPrograms = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('id') . ', name, description, benefit_type, unit_amount, unit_quantity, unit_label, claim_period
             FROM ' . Db::t('programs') . ' WHERE tenant_id = UNHEX(%s) AND status = %s AND starts_on <= %s AND ends_on >= %s ORDER BY name',
            $actor->tenantId, 'published', $todayS, $todayS
        ));
        $programs = [];
        foreach ($openPrograms as $p) {
            $why = '';
            foreach ($apps as $a) {
                if ($a['program_id'] === $p['id'] && in_array($a['status'], self::OPEN, true)) {
                    $why = 'You have already applied. Watch "My applications" below for the answer.';
                }
            }
            $period = Programs::periodKey($p['claim_period'], $today);
            foreach ($ents as $e) {
                if ($why === '' && $e['program_id'] === $p['id'] && $e['period_key'] === $period) {
                    $why = match ($p['claim_period']) {
                        'monthly' => 'You already have this for this month.',
                        'yearly' => 'You already have this for this year.',
                        default => 'This is given only once, and you already have it.',
                    };
                }
            }
            $programs[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'description' => (string) $p['description'],
                'what' => self::what($p['benefit_type'], $p['unit_amount'], $p['unit_quantity'], $p['unit_label']),
                'how_often' => match ($p['claim_period']) { 'monthly' => 'Every month', 'yearly' => 'Once a year', default => 'One time only' },
                'can_apply' => $why === '',
                'why_not' => $why,
            ];
        }

        return ['me' => $me, 'qr' => $qr, 'ready' => $ready, 'received' => $received, 'applications' => $applications, 'programs' => $programs];
    }

    public static function apply(Actor $actor, string $programId): string
    {
        $me = self::record($actor);
        return Applications::submit($actor, $me['id'], Uuid::assertHex($programId, 'program id'));
    }

    public static function withdraw(Actor $actor, string $applicationId): void
    {
        self::ownApplication($actor, $applicationId);
        Applications::transition($actor, $applicationId, 'withdrawn', 'Withdrawn by the senior.');
    }

    /** The office asked for more information; the senior says it has been provided. */
    public static function resubmit(Actor $actor, string $applicationId): void
    {
        self::ownApplication($actor, $applicationId);
        Applications::transition($actor, $applicationId, 'submitted', 'Senior says the requested information was provided.');
    }

    private static function ownApplication(Actor $actor, string $applicationId): void
    {
        $me = self::record($actor);
        $ok = Db::value(Db::prepare(
            'SELECT 1 FROM ' . Db::t('applications') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND beneficiary_id = UNHEX(%s)',
            Uuid::assertHex($applicationId, 'application id'), $actor->tenantId, $me['id']
        ));
        if (!$ok) {
            throw new DomainError('not_found', 'Application not found.');
        }
    }

    private static function what(string $type, ?string $amount, $quantity, ?string $unit): string
    {
        if ($type === 'cash' && $amount !== null) {
            $n = (float) $amount;
            return "\u{20B1}" . number_format($n, fmod($n, 1.0) === 0.0 ? 0 : 2);
        }
        $q = (int) ($quantity ?? 1);
        return $q . ' ' . ($unit ?: 'item') . ($q === 1 ? '' : 's');
    }

    private static function manilaDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('F j, Y');
    }
}
