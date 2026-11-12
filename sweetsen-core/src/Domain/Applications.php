<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Support\Db;
use SweetSen\Support\DbError;
use SweetSen\Support\DomainError;
use SweetSen\Support\Sequence;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * Application lifecycle (Architecture Pack, State machines).
 * Approval creates exactly one entitlement in the same transaction; the claim-limit unique key
 * refuses a second entitlement for the same senior, program and period.
 */
final class Applications
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['submitted', 'withdrawn'],
        'submitted' => ['under_review', 'withdrawn'],
        'under_review' => ['approved', 'rejected', 'info_needed', 'withdrawn'],
        'info_needed' => ['submitted', 'withdrawn'],
    ];

    public const ENTITLEMENT_VALID_DAYS = 30;

    public static function submit(Actor $actor, string $beneficiaryId, string $programId): string
    {
        $actor->require('ss_submit_application');
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        $programId = Uuid::assertHex($programId, 'program id');

        $ben = Db::row(Db::prepare(
            'SELECT user_id, status FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $beneficiaryId, $actor->tenantId
        ));
        if ($ben === null || $ben['status'] !== 'active') {
            throw new DomainError('not_found', 'Senior record not found.');
        }
        $own = $ben['user_id'] !== null && (int) $ben['user_id'] === $actor->userId;
        if (!$own && !$actor->can('ss_manage_beneficiaries')) {
            throw new DomainError('forbidden', 'You can only apply for your own account.');
        }
        $prog = Db::row(Db::prepare(
            'SELECT status, starts_on, ends_on, rules_version FROM ' . Db::t('programs') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $programId, $actor->tenantId
        ));
        $today = Db::todayManila()->format('Y-m-d');
        if ($prog === null || $prog['status'] !== 'published' || $today < $prog['starts_on'] || $today > $prog['ends_on']) {
            throw new DomainError('program_closed', 'This program is not open for applications.');
        }
        $open = Db::value(Db::prepare(
            'SELECT ref_no FROM ' . Db::t('applications') . ' WHERE tenant_id = UNHEX(%s) AND beneficiary_id = UNHEX(%s) AND program_id = UNHEX(%s)
               AND status IN (%s, %s, %s) LIMIT 1',
            $actor->tenantId, $beneficiaryId, $programId, 'submitted', 'under_review', 'info_needed'
        ));
        if ($open !== null) {
            throw new DomainError('already_applied', "There is already an open application for this program (ref {$open}).");
        }

        return Db::transaction(static function () use ($actor, $beneficiaryId, $programId, $prog): string {
            $id = Uuid::v7();
            $now = Db::now();
            Db::insert('applications', [
                'id' => $id,
                'tenant_id' => $actor->tenantId,
                'ref_no' => Sequence::ref($actor->tenantId, 'APP'),
                'beneficiary_id' => $beneficiaryId,
                'program_id' => $programId,
                'rules_version' => $prog['rules_version'],
                'status' => 'submitted',
                'submitted_by' => $actor->userId,
                'submitted_at' => $now,
                'created_at' => $now,
            ], ['id', 'tenant_id', 'beneficiary_id', 'program_id']);
            self::event($actor, $id, null, 'submitted', null);
            Audit::log($actor->tenantId, $actor->userId, 'application.submitted', 'application', $id);
            return $id;
        });
    }

    /**
     * Move an application to a new status. Returns the new entitlement id when approving.
     */
    public static function transition(Actor $actor, string $applicationId, string $to, ?string $note = null): ?string
    {
        $applicationId = Uuid::assertHex($applicationId, 'application id');
        $decision = in_array($to, ['under_review', 'approved', 'rejected', 'info_needed'], true);
        $actor->require($decision ? 'ss_decide_application' : 'ss_submit_application');

        try {
            return Db::transaction(static function () use ($actor, $applicationId, $to, $note, $decision): ?string {
                $app = Db::row(Db::prepare(
                    'SELECT a.status, ' . Db::hex('a.beneficiary_id', 'beneficiary_id') . ', ' . Db::hex('a.program_id', 'program_id') . ',
                            a.submitted_by, b.user_id AS ben_user_id, b.verification_status
                     FROM ' . Db::t('applications') . ' a JOIN ' . Db::t('beneficiaries') . ' b ON b.id = a.beneficiary_id
                     WHERE a.id = UNHEX(%s) AND a.tenant_id = UNHEX(%s) FOR UPDATE',
                    $applicationId, $actor->tenantId
                ));
                if ($app === null) {
                    throw new DomainError('not_found', 'Application not found.');
                }
                $from = $app['status'];
                if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                    throw new DomainError('invalid_transition', "An application that is {$from} cannot become {$to}.");
                }
                if (!$decision) {
                    $own = $app['ben_user_id'] !== null && (int) $app['ben_user_id'] === $actor->userId;
                    if (!$own && !$actor->can('ss_manage_beneficiaries')) {
                        throw new DomainError('forbidden', 'You can only change your own application.');
                    }
                }

                $entitlementId = null;
                if ($to === 'approved') {
                    if ($app['verification_status'] !== 'verified') {
                        throw new DomainError('beneficiary_not_verified', 'The senior must be verified before approval.');
                    }
                    $entitlementId = self::createEntitlement($actor, $applicationId, $app['beneficiary_id'], $app['program_id']);
                }

                $now = Db::now();
                $set = $to === 'approved' || $to === 'rejected'
                    ? Db::prepare(', decided_by = %d, decided_at = %s, decision_note = %s', $actor->userId, $now, (string) $note)
                    : '';
                Db::exec(Db::prepare('UPDATE ' . Db::t('applications') . ' SET status = %s, updated_at = %s', $to, $now)
                    . $set . Db::prepare(' WHERE id = UNHEX(%s)', $applicationId));
                self::event($actor, $applicationId, $from, $to, $note);
                Audit::log($actor->tenantId, $actor->userId, 'application.' . $to, 'application', $applicationId);
                return $entitlementId;
            });
        } catch (DbError $e) {
            if ($e->isDuplicateKey('uq_ent_claim_limit')) {
                throw new DomainError('claim_limit_reached', 'This senior already has this benefit for the current period.');
            }
            throw $e;
        }
    }

    private static function createEntitlement(Actor $actor, string $applicationId, string $beneficiaryId, string $programId): string
    {
        $p = Db::row(Db::prepare(
            'SELECT claim_period, unit_amount, currency, unit_quantity, ends_on FROM ' . Db::t('programs') . ' WHERE id = UNHEX(%s)',
            $programId
        ));
        $today = Db::todayManila();
        $until = $today->modify('+' . self::ENTITLEMENT_VALID_DAYS . ' days')->format('Y-m-d');
        if ($p['ends_on'] < $until) {
            $until = $p['ends_on'];
        }
        $id = Uuid::v7();
        Db::insert('entitlements', [
            'id' => $id,
            'tenant_id' => $actor->tenantId,
            'application_id' => $applicationId,
            'beneficiary_id' => $beneficiaryId,
            'program_id' => $programId,
            'period_key' => Programs::periodKey($p['claim_period'], $today),
            'amount' => $p['unit_amount'],
            'currency' => $p['currency'],
            'quantity' => $p['unit_quantity'],
            'status' => 'scheduled',
            'valid_from' => $today->format('Y-m-d'),
            'valid_until' => $until,
            'release_seq' => 1,
            'created_at' => Db::now(),
        ], ['id', 'tenant_id', 'application_id', 'beneficiary_id', 'program_id']);
        return $id;
    }

    private static function event(Actor $actor, string $applicationId, ?string $from, string $to, ?string $note): void
    {
        Db::insert('application_events', [
            'id' => Uuid::v7(),
            'tenant_id' => $actor->tenantId,
            'application_id' => $applicationId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->userId,
            'note' => $note,
            'created_at' => Db::now(),
        ], ['id', 'tenant_id', 'application_id']);
    }
}
