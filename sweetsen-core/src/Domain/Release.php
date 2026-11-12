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
 * Benefit release and reversal (Architecture Pack, QR release transaction).
 *
 * Two independent guarantees stop a double release:
 *  1. SELECT ... FOR UPDATE on the entitlement serializes concurrent attempts.
 *  2. UNIQUE (entitlement_id, kind, release_seq) refuses a second release row even if code is wrong.
 * A retried request with the same idempotency key returns the original release instead of failing.
 */
final class Release
{
    public const SCANS_PER_MINUTE = 30;

    /**
     * Scan step: validate a QR payload and list what can be released today.
     * Shows the verifier only the minimum needed to confirm identity.
     *
     * @return array{credential_id:string, beneficiary:array<string,mixed>, entitlements:list<array<string,mixed>>}
     */
    public static function lookup(Actor $actor, string $qrPayload): array
    {
        $actor->require('ss_release');
        self::rateLimit($actor);

        $cred = Beneficiaries::resolveQr($actor->tenantId, $qrPayload);
        if ($cred === null) {
            self::attempt($actor, null, null, 'qr', 'rejected', 'credential_invalid', null);
            throw new DomainError('credential_invalid', 'This QR code is not valid. Use manual lookup or ask the senior to visit the OSCA desk.');
        }
        $view = self::present($actor, $cred['beneficiary_id']);
        if ($view === null) {
            self::attempt($actor, $cred['credential_id'], null, 'qr', 'rejected', 'beneficiary_not_eligible', null);
            throw new DomainError('beneficiary_not_eligible', 'This senior is not currently eligible for releases.');
        }
        return ['credential_id' => $cred['credential_id']] + $view;
    }

    /**
     * Manual lookup for seniors without a usable QR: by reference number, or by last name + birthdate.
     * Returns at most 5 eligible matches, each with what can be released today.
     *
     * @return list<array<string, mixed>>
     */
    public static function find(Actor $actor, string $refNo = '', string $lastName = '', string $birthdate = ''): array
    {
        $actor->require('ss_release');
        self::rateLimit($actor);
        $refNo = strtoupper(trim($refNo));
        if ($refNo !== '') {
            $ids = Db::rows(Db::prepare(
                'SELECT ' . Db::hex('id') . ' FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND ref_no = %s',
                $actor->tenantId, $refNo
            ));
        } else {
            $dob = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
            if (trim($lastName) === '' || $dob === false) {
                throw new DomainError('lookup_incomplete', 'Enter the reference number, or the last name and birthdate.');
            }
            $key = Names::key($lastName);
            $rows = Db::rows(Db::prepare(
                'SELECT ' . Db::hex('id') . ', last_name FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND birthdate = %s',
                $actor->tenantId, $dob->format('Y-m-d')
            ));
            $ids = array_values(array_filter($rows, static fn(array $r): bool => Names::key($r['last_name']) === $key));
        }
        $out = [];
        foreach (array_slice($ids, 0, 5) as $r) {
            $view = self::present($actor, $r['id']);
            if ($view !== null) {
                $out[] = $view;
            }
        }
        return $out;
    }

    /**
     * The verifier's own releases today (Manila day), newest first.
     *
     * @return array{count:int, items:list<array<string,string>>}
     */
    public static function today(Actor $actor): array
    {
        $actor->require('ss_release');
        $startUtc = Db::todayManila()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows = Db::rows(Db::prepare(
            'SELECT f.ref_no, f.created_at, f.method, p.name AS program, b.first_name, b.last_name
             FROM ' . Db::t('fulfillments') . ' f
             JOIN ' . Db::t('entitlements') . ' e ON e.id = f.entitlement_id
             JOIN ' . Db::t('programs') . ' p ON p.id = e.program_id
             JOIN ' . Db::t('beneficiaries') . ' b ON b.id = e.beneficiary_id
             WHERE f.tenant_id = UNHEX(%s) AND f.verifier_user_id = %d AND f.kind = %s AND f.created_at >= %s
             ORDER BY f.created_at DESC',
            $actor->tenantId, $actor->userId, 'release', $startUtc
        ));
        return [
            'count' => count($rows),
            'items' => array_map(static fn(array $r): array => [
                'ref_no' => $r['ref_no'],
                'time' => get_date_from_gmt(substr($r['created_at'], 0, 19), 'g:i A'),
                'program' => $r['program'],
                'display_name' => $r['first_name'] . ' ' . mb_substr($r['last_name'], 0, 1) . '.',
                'method' => $r['method'],
            ], array_slice($rows, 0, 10)),
        ];
    }

    /**
     * Minimal identity + today's releasable benefits for one senior, or null if not eligible.
     *
     * @return array{beneficiary:array<string,mixed>, entitlements:list<array<string,mixed>>}|null
     */
    private static function present(Actor $actor, string $beneficiaryId): ?array
    {
        $b = Db::row(Db::prepare(
            'SELECT ref_no, first_name, last_name, barangay, birthdate, verification_status, status
             FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $beneficiaryId, $actor->tenantId
        ));
        if ($b === null || $b['verification_status'] !== 'verified' || $b['status'] !== 'active') {
            return null;
        }
        $today = Db::todayManila()->format('Y-m-d');
        $ents = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('e.id', 'id') . ', p.name AS program, p.benefit_type, e.amount, e.currency, e.quantity, p.unit_label,
                    e.status, e.valid_until
             FROM ' . Db::t('entitlements') . ' e JOIN ' . Db::t('programs') . ' p ON p.id = e.program_id
             WHERE e.beneficiary_id = UNHEX(%s) AND e.tenant_id = UNHEX(%s)
               AND e.status = %s AND %s BETWEEN e.valid_from AND e.valid_until
             ORDER BY e.valid_until',
            $beneficiaryId, $actor->tenantId, 'scheduled', $today
        ));
        $age = (new \DateTimeImmutable($b['birthdate']))->diff(Db::todayManila())->y;
        return [
            'beneficiary' => [
                'ref_no' => $b['ref_no'],
                'display_name' => $b['first_name'] . ' ' . mb_substr($b['last_name'], 0, 1) . '.',
                'barangay' => $b['barangay'],
                'age' => $age,
            ],
            'entitlements' => $ents,
        ];
    }

    /**
     * Confirm a release. $credentialId is required for QR releases; manual releases need a note.
     *
     * @return array{fulfillment_id:string, ref_no:string, released_at:string, replayed:bool}
     */
    public static function release(Actor $actor, string $entitlementId, string $idempotencyKey, string $method = 'qr', ?string $credentialId = null, string $note = ''): array
    {
        $actor->require('ss_release');
        $entitlementId = Uuid::assertHex($entitlementId, 'entitlement id');
        if (!Uuid::isUuidString($idempotencyKey)) {
            throw new DomainError('invalid_idempotency_key', 'Missing or invalid request key; reload the scan screen.');
        }
        $idempotencyKey = strtolower($idempotencyKey);
        if (!in_array($method, ['qr', 'manual'], true)) {
            throw new DomainError('invalid_method', 'Unknown release method.');
        }
        if ($method === 'qr' && $credentialId === null) {
            throw new DomainError('credential_required', 'Scan the QR code first.');
        }
        if ($method === 'manual' && trim($note) === '') {
            throw new DomainError('reason_required', 'Type the reason for a manual release.');
        }

        // Retried request (shaky signal): hand back what the first attempt committed.
        $replay = self::findByIdempotencyKey($actor, $idempotencyKey, $entitlementId);
        if ($replay !== null) {
            return $replay;
        }

        try {
            $result = Db::transaction(static function () use ($actor, $entitlementId, $idempotencyKey, $method, $credentialId, $note): array {
                $e = Db::row(Db::prepare(
                    'SELECT ' . Db::hex('e.beneficiary_id', 'beneficiary_id') . ', e.status, e.valid_from, e.valid_until,
                            e.release_seq, e.amount, e.currency, e.quantity, a.decided_by, b.verification_status, b.status AS ben_status
                     FROM ' . Db::t('entitlements') . ' e
                     JOIN ' . Db::t('applications') . ' a ON a.id = e.application_id
                     JOIN ' . Db::t('beneficiaries') . ' b ON b.id = e.beneficiary_id
                     WHERE e.id = UNHEX(%s) AND e.tenant_id = UNHEX(%s) FOR UPDATE',
                    $entitlementId, $actor->tenantId
                ));
                if ($e === null) {
                    throw new DomainError('not_found', 'Benefit not found.');
                }
                if ($credentialId !== null) {
                    $credentialId = Uuid::assertHex($credentialId, 'credential id');
                    $owner = Db::value(Db::prepare(
                        'SELECT LOWER(HEX(beneficiary_id)) FROM ' . Db::t('qr_credentials') . '
                         WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND status = %s',
                        $credentialId, $actor->tenantId, 'active'
                    ));
                    if ($owner !== $e['beneficiary_id']) {
                        throw new DomainError('credential_mismatch', 'This QR code does not belong to the senior for this benefit.');
                    }
                }
                if ($e['verification_status'] !== 'verified' || $e['ben_status'] !== 'active') {
                    throw new DomainError('beneficiary_not_eligible', 'This senior is not currently eligible for releases.');
                }
                if ($e['status'] === 'fulfilled') {
                    throw self::alreadyReleased($actor, $entitlementId);
                }
                if ($e['status'] !== 'scheduled') {
                    throw new DomainError('not_releasable', "This benefit is {$e['status']} and cannot be released.");
                }
                $today = Db::todayManila()->format('Y-m-d');
                if ($today < $e['valid_from'] || $today > $e['valid_until']) {
                    throw new DomainError('outside_validity', "This benefit can be released from {$e['valid_from']} to {$e['valid_until']}.");
                }
                if ((int) $e['decided_by'] === $actor->userId) {
                    throw new DomainError('separation_of_duties', 'The officer who approved a benefit cannot also release it.');
                }

                $id = Uuid::v7();
                $now = Db::now();
                $ref = Sequence::ref($actor->tenantId, 'REL');
                Db::insert('fulfillments', [
                    'id' => $id,
                    'tenant_id' => $actor->tenantId,
                    'ref_no' => $ref,
                    'entitlement_id' => $entitlementId,
                    'kind' => 'release',
                    'release_seq' => $e['release_seq'],
                    'amount' => $e['amount'],
                    'currency' => $e['currency'],
                    'quantity' => $e['quantity'],
                    'method' => $method,
                    'verifier_user_id' => $actor->userId,
                    'idempotency_key' => $idempotencyKey,
                    'note' => $note !== '' ? substr($note, 0, 250) : null,
                    'created_at' => $now,
                ], ['id', 'tenant_id', 'entitlement_id']);
                Db::exec(Db::prepare(
                    'UPDATE ' . Db::t('entitlements') . ' SET status = %s, updated_at = %s WHERE id = UNHEX(%s)',
                    'fulfilled', $now, $entitlementId
                ));
                Audit::log($actor->tenantId, $actor->userId, 'fulfillment.released', 'fulfillment', $id, ['method' => $method]);
                return ['fulfillment_id' => $id, 'ref_no' => $ref, 'released_at' => $now, 'replayed' => false];
            });
        } catch (DbError $ex) {
            if ($ex->isDuplicateKey('uq_ful_idem')) {
                $replay = self::findByIdempotencyKey($actor, $idempotencyKey, $entitlementId);
                if ($replay !== null) {
                    return $replay;
                }
            }
            $err = $ex->isDuplicateKey('uq_ful_release') ? self::alreadyReleased($actor, $entitlementId) : $ex;
            self::attempt($actor, $credentialId, $entitlementId, $method, 'rejected', $err instanceof DomainError ? $err->reason : 'db_error', $idempotencyKey);
            throw $err;
        } catch (DomainError $ex) {
            self::attempt($actor, $credentialId, $entitlementId, $method, 'rejected', $ex->reason, $idempotencyKey);
            throw $ex;
        }

        self::attempt($actor, $credentialId, $entitlementId, $method, 'accepted', null, $idempotencyKey);
        // Notifications run after commit; a failed SMS or email can never undo a release.
        do_action('sweetsen_benefit_released', $actor->tenantId, $result['fulfillment_id']);
        return $result;
    }

    /**
     * Reverse a release. Program officers only, never the verifier who released it; reason required.
     * Adds a reversal row (the release row is never changed), reopens the entitlement and bumps release_seq.
     */
    public static function reverse(Actor $actor, string $fulfillmentId, string $reason): string
    {
        $actor->require('ss_reverse_release');
        $fulfillmentId = Uuid::assertHex($fulfillmentId, 'release id');
        if (trim($reason) === '') {
            throw new DomainError('reason_required', 'A reason is required to reverse a release.');
        }
        try {
            return Db::transaction(static function () use ($actor, $fulfillmentId, $reason): string {
                $f = Db::row(Db::prepare(
                    'SELECT ' . Db::hex('entitlement_id') . ', kind, release_seq, amount, currency, quantity, verifier_user_id
                     FROM ' . Db::t('fulfillments') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
                    $fulfillmentId, $actor->tenantId
                ));
                if ($f === null || $f['kind'] !== 'release') {
                    throw new DomainError('not_found', 'Release not found.');
                }
                if ((int) $f['verifier_user_id'] === $actor->userId) {
                    throw new DomainError('separation_of_duties', 'You cannot reverse a release you made yourself.');
                }
                $e = Db::row(Db::prepare(
                    'SELECT status, release_seq FROM ' . Db::t('entitlements') . ' WHERE id = UNHEX(%s) FOR UPDATE',
                    $f['entitlement_id']
                ));
                if ($e['status'] !== 'fulfilled' || (int) $e['release_seq'] !== (int) $f['release_seq']) {
                    throw new DomainError('not_reversible', 'This release has already been reversed or superseded.');
                }
                $id = Uuid::v7();
                $now = Db::now();
                Db::insert('fulfillments', [
                    'id' => $id,
                    'tenant_id' => $actor->tenantId,
                    'ref_no' => Sequence::ref($actor->tenantId, 'REV'),
                    'entitlement_id' => $f['entitlement_id'],
                    'kind' => 'reversal',
                    'release_seq' => $f['release_seq'],
                    'amount' => $f['amount'],
                    'currency' => $f['currency'],
                    'quantity' => $f['quantity'],
                    'method' => 'manual',
                    'verifier_user_id' => $actor->userId,
                    'idempotency_key' => Uuid::v4String(),
                    'reverses_id' => $fulfillmentId,
                    'note' => substr($reason, 0, 250),
                    'created_at' => $now,
                ], ['id', 'tenant_id', 'entitlement_id', 'reverses_id']);
                Db::exec(Db::prepare(
                    'UPDATE ' . Db::t('entitlements') . ' SET status = %s, release_seq = release_seq + 1, updated_at = %s WHERE id = UNHEX(%s)',
                    'scheduled', $now, $f['entitlement_id']
                ));
                Audit::log($actor->tenantId, $actor->userId, 'fulfillment.reversed', 'fulfillment', $id, ['reverses' => $fulfillmentId]);
                return $id;
            });
        } catch (DbError $ex) {
            if ($ex->isDuplicateKey('uq_ful_reverses')) {
                throw new DomainError('not_reversible', 'This release has already been reversed.');
            }
            throw $ex;
        }
    }

    private static function alreadyReleased(Actor $actor, string $entitlementId): DomainError
    {
        $last = Db::row(Db::prepare(
            'SELECT f.ref_no, f.created_at, u.display_name
             FROM ' . Db::t('fulfillments') . ' f LEFT JOIN ' . Db::wpdb()->users . ' u ON u.ID = f.verifier_user_id
             WHERE f.entitlement_id = UNHEX(%s) AND f.tenant_id = UNHEX(%s) AND f.kind = %s
             ORDER BY f.created_at DESC LIMIT 1',
            $entitlementId, $actor->tenantId, 'release'
        ));
        $when = $last ? get_date_from_gmt(substr($last['created_at'], 0, 19), 'M j, Y g:i A') : 'earlier';
        $who = $last['display_name'] ?? 'another verifier';
        $ref = $last['ref_no'] ?? 'n/a';
        return new DomainError('already_released', "This benefit was given on {$when} by {$who}. Ref {$ref}.", [
            'ref_no' => $last['ref_no'] ?? null,
            'released_at' => $last['created_at'] ?? null,
        ]);
    }

    /** @return array{fulfillment_id:string, ref_no:string, released_at:string, replayed:bool}|null */
    private static function findByIdempotencyKey(Actor $actor, string $key, string $entitlementId): ?array
    {
        $row = Db::row(Db::prepare(
            'SELECT ' . Db::hex('id') . ', ref_no, created_at, LOWER(HEX(entitlement_id)) AS entitlement_id
             FROM ' . Db::t('fulfillments') . ' WHERE idempotency_key = %s AND tenant_id = UNHEX(%s)',
            $key, $actor->tenantId
        ));
        if ($row === null) {
            return null;
        }
        if ($row['entitlement_id'] !== $entitlementId) {
            throw new DomainError('idempotency_conflict', 'This request key was already used for a different benefit.');
        }
        return ['fulfillment_id' => $row['id'], 'ref_no' => $row['ref_no'], 'released_at' => $row['created_at'], 'replayed' => true];
    }

    /** Every scan is logged, accepted or not, outside the release transaction. */
    private static function attempt(Actor $actor, ?string $credentialId, ?string $entitlementId, string $method, string $outcome, ?string $reason, ?string $key): void
    {
        try {
            Db::insert('redemption_attempts', [
                'id' => Uuid::v7(),
                'tenant_id' => $actor->tenantId,
                'credential_id' => $credentialId !== null && Uuid::isHex($credentialId) ? $credentialId : null,
                'entitlement_id' => $entitlementId !== null && Uuid::isHex($entitlementId) ? $entitlementId : null,
                'verifier_user_id' => $actor->userId,
                'method' => $method,
                'outcome' => $outcome,
                'reason_code' => $reason,
                'idempotency_key' => $key,
                'created_at' => Db::now(),
            ], ['id', 'tenant_id', 'credential_id', 'entitlement_id']);
        } catch (\Throwable) {
            // Logging must never block or undo a release decision.
        }
    }

    private static function rateLimit(Actor $actor): void
    {
        $key = 'ss_scan_rl_' . $actor->userId . '_' . gmdate('YmdHi');
        $n = (int) get_transient($key);
        if ($n >= self::SCANS_PER_MINUTE) {
            throw new DomainError('rate_limited', 'Too many scans in a minute. Please wait a moment.');
        }
        set_transient($key, $n + 1, 120);
    }
}
