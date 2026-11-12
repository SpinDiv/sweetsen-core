<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Sequence;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * Sponsor flow: pledge (promise) -> LGU review -> receipt (what arrived) -> allocation to programs.
 * Pledged, received and allocated are separate records so they always reconcile.
 */
final class Sponsors
{
    /** @param array{kind:string, amount?:?string, item_description?:?string, quantity?:?int, program_id?:?string} $p */
    public static function submitPledge(Actor $actor, array $p): string
    {
        $actor->require('ss_submit_pledge');
        if ($actor->organizationId === null) {
            throw new DomainError('no_organization', 'Your account is not linked to an organization.');
        }
        $org = Db::row(Db::prepare(
            'SELECT status FROM ' . Db::t('organizations') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $actor->organizationId, $actor->tenantId
        ));
        if ($org === null || $org['status'] !== 'verified') {
            throw new DomainError('organization_not_verified', 'Your organization must be verified by the LGU before pledging.');
        }
        if (!in_array($p['kind'], ['cash', 'in_kind'], true)) {
            throw new DomainError('invalid_pledge', 'Pledge kind must be cash or in_kind.');
        }
        return Db::transaction(static function () use ($actor, $p): string {
            $id = Uuid::v7();
            Db::insert('pledges', [
                'id' => $id,
                'tenant_id' => $actor->tenantId,
                'ref_no' => Sequence::ref($actor->tenantId, 'PLG'),
                'organization_id' => $actor->organizationId,
                'program_id' => isset($p['program_id']) ? Uuid::assertHex($p['program_id'], 'program id') : null,
                'kind' => $p['kind'],
                'amount' => $p['amount'] ?? null,
                'item_description' => $p['item_description'] ?? null,
                'quantity' => $p['quantity'] ?? null,
                'status' => 'submitted',
                'submitted_by' => $actor->userId,
                'created_at' => Db::now(),
            ], ['id', 'tenant_id', 'organization_id', 'program_id']);
            Audit::log($actor->tenantId, $actor->userId, 'pledge.submitted', 'pledge', $id);
            return $id;
        });
    }

    public static function reviewPledge(Actor $actor, string $pledgeId, bool $approve, string $note = ''): void
    {
        $actor->require('ss_review_pledges');
        $pledgeId = Uuid::assertHex($pledgeId, 'pledge id');
        Db::transaction(static function () use ($actor, $pledgeId, $approve, $note): void {
            $p = Db::row(Db::prepare(
                'SELECT status, submitted_by FROM ' . Db::t('pledges') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) FOR UPDATE',
                $pledgeId, $actor->tenantId
            ));
            if ($p === null) {
                throw new DomainError('not_found', 'Pledge not found.');
            }
            if ((int) $p['submitted_by'] === $actor->userId) {
                throw new DomainError('separation_of_duties', 'You cannot review a pledge you submitted.');
            }
            if ($p['status'] !== 'submitted') {
                throw new DomainError('invalid_transition', 'This pledge has already been reviewed.');
            }
            $status = $approve ? 'approved' : 'rejected';
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('pledges') . ' SET status = %s, reviewed_by = %d, reviewed_at = %s, review_note = %s, updated_at = %s
                 WHERE id = UNHEX(%s)',
                $status, $actor->userId, Db::now(), substr($note, 0, 250), Db::now(), $pledgeId
            ));
            Audit::log($actor->tenantId, $actor->userId, 'pledge.' . $status, 'pledge', $pledgeId);
        });
    }

    /** Record what actually arrived (with or without a pledge). */
    public static function recordReceipt(Actor $actor, string $organizationId, ?string $pledgeId, ?string $amount, ?int $quantity, ?string $itemDescription): string
    {
        $actor->require('ss_record_donations');
        $organizationId = Uuid::assertHex($organizationId, 'organization id');
        return Db::transaction(static function () use ($actor, $organizationId, $pledgeId, $amount, $quantity, $itemDescription): string {
            if ($pledgeId !== null) {
                $pledgeId = Uuid::assertHex($pledgeId, 'pledge id');
                $p = Db::row(Db::prepare(
                    'SELECT status, LOWER(HEX(organization_id)) AS org FROM ' . Db::t('pledges') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
                    $pledgeId, $actor->tenantId
                ));
                if ($p === null || $p['status'] !== 'approved' || $p['org'] !== $organizationId) {
                    throw new DomainError('pledge_not_approved', 'Receipts can only be recorded against an approved pledge from this organization.');
                }
            }
            $id = Uuid::v7();
            $now = Db::now();
            Db::insert('donations_received', [
                'id' => $id,
                'tenant_id' => $actor->tenantId,
                'pledge_id' => $pledgeId,
                'organization_id' => $organizationId,
                'receipt_no' => Sequence::ref($actor->tenantId, 'RCT'),
                'amount' => $amount,
                'quantity' => $quantity,
                'item_description' => $itemDescription,
                'received_at' => $now,
                'received_by' => $actor->userId,
                'created_at' => $now,
            ], ['id', 'tenant_id', 'pledge_id', 'organization_id']);
            Audit::log($actor->tenantId, $actor->userId, 'donation.received', 'donation', $id);
            return $id;
        });
    }

    /**
     * Record a delivery against an approved pledge and assign it to the pledge's program.
     * Returns the donation id. Quantity for in-kind pledges, amount for cash pledges.
     */
    public static function deliver(Actor $actor, string $pledgeId, ?int $quantity, ?string $amount): string
    {
        $actor->require('ss_record_donations');
        $pledgeId = Uuid::assertHex($pledgeId, 'pledge id');
        $p = Db::row(Db::prepare(
            'SELECT kind, item_description, LOWER(HEX(organization_id)) AS org, LOWER(HEX(program_id)) AS program
             FROM ' . Db::t('pledges') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $pledgeId, $actor->tenantId
        ));
        if ($p === null) {
            throw new DomainError('not_found', 'Pledge not found.');
        }
        if ($p['kind'] === 'in_kind' && ($quantity === null || $quantity < 1)) {
            throw new DomainError('invalid_amount', 'Enter how many items arrived.');
        }
        if ($p['kind'] === 'cash') {
            $quantity = null;
            if ($amount === null || self::centavos($amount) <= 0) {
                throw new DomainError('invalid_amount', 'Enter the amount received.');
            }
        } else {
            $amount = null;
        }
        $donation = self::recordReceipt($actor, $p['org'], $pledgeId, $amount, $quantity, $p['item_description']);
        if ($p['program'] !== null) {
            self::allocate($actor, $donation, $p['program'], $amount, $quantity);
        }
        return $donation;
    }

    /** Assign part of a received donation to a program. Cannot exceed what was received. */
    public static function allocate(Actor $actor, string $donationId, string $programId, ?string $amount, ?int $quantity): string
    {
        $actor->require('ss_record_donations');
        $donationId = Uuid::assertHex($donationId, 'donation id');
        $programId = Uuid::assertHex($programId, 'program id');
        return Db::transaction(static function () use ($actor, $donationId, $programId, $amount, $quantity): string {
            $d = Db::row(Db::prepare(
                'SELECT amount, quantity FROM ' . Db::t('donations_received') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) FOR UPDATE',
                $donationId, $actor->tenantId
            ));
            if ($d === null) {
                throw new DomainError('not_found', 'Donation not found.');
            }
            $used = Db::row(Db::prepare(
                'SELECT COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(quantity), 0) AS quantity
                 FROM ' . Db::t('donation_allocations') . ' WHERE donation_id = UNHEX(%s)',
                $donationId
            ));
            if ($amount !== null && self::centavos((string) $used['amount']) + self::centavos($amount) > self::centavos((string) ($d['amount'] ?? '0'))) {
                throw new DomainError('over_allocated', 'Allocation would exceed the amount received.');
            }
            if ($quantity !== null && ((int) $used['quantity'] + $quantity) > (int) ($d['quantity'] ?? 0)) {
                throw new DomainError('over_allocated', 'Allocation would exceed the quantity received.');
            }
            $id = Uuid::v7();
            Db::insert('donation_allocations', [
                'id' => $id,
                'tenant_id' => $actor->tenantId,
                'donation_id' => $donationId,
                'program_id' => $programId,
                'amount' => $amount,
                'quantity' => $quantity,
                'allocated_by' => $actor->userId,
                'created_at' => Db::now(),
            ], ['id', 'tenant_id', 'donation_id', 'program_id']);
            Audit::log($actor->tenantId, $actor->userId, 'donation.allocated', 'donation_allocation', $id);
            return $id;
        });
    }

    /**
     * Aggregated impact for the sponsor's own organization. No names or personal data, ever.
     *
     * @return array<string, mixed>
     */
    public static function impact(Actor $actor): array
    {
        $actor->require('ss_submit_pledge');
        if ($actor->organizationId === null) {
            throw new DomainError('no_organization', 'Your account is not linked to an organization.');
        }
        $org = $actor->organizationId;
        $t = $actor->tenantId;
        $pledged = Db::row(Db::prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS amount FROM ' . Db::t('pledges') . '
             WHERE organization_id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND status IN (%s, %s)',
            $org, $t, 'submitted', 'approved'
        ));
        $received = Db::row(Db::prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(quantity),0) AS quantity
             FROM ' . Db::t('donations_received') . ' WHERE organization_id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $org, $t
        ));
        $programs = Db::rows(Db::prepare(
            'SELECT p.name AS program, COALESCE(SUM(al.amount),0) AS allocated_amount, COALESCE(SUM(al.quantity),0) AS allocated_quantity,
                    (SELECT COUNT(DISTINCT e.beneficiary_id) FROM ' . Db::t('entitlements') . ' e
                      WHERE e.program_id = p.id AND e.status = %s) AS seniors_served
             FROM ' . Db::t('donation_allocations') . ' al
             JOIN ' . Db::t('donations_received') . ' d ON d.id = al.donation_id
             JOIN ' . Db::t('programs') . ' p ON p.id = al.program_id
             WHERE d.organization_id = UNHEX(%s) AND al.tenant_id = UNHEX(%s)
             GROUP BY p.id, p.name',
            'fulfilled', $org, $t
        ));
        return ['pledged' => $pledged, 'received' => $received, 'by_program' => $programs];
    }

    /** Exact decimal string ("1500.5", "1500.50") to integer centavos. */
    private static function centavos(string $v): int
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim($v), $m)) {
            throw new DomainError('invalid_amount', 'Amounts must be numbers with up to 2 decimals.');
        }
        $n = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$n : $n;
    }
}
