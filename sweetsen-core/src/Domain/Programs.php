<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Sequence;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/** Configurable benefit programs. Amounts and rules are tenant data, never hardcoded. */
final class Programs
{
    public const BENEFIT_TYPES = ['cash', 'goods', 'service'];
    public const PERIODS = ['once', 'yearly', 'monthly'];
    public const CATEGORIES = ['birthday' => 'Birthday', 'food' => 'Food and groceries', 'medical' => 'Medicine and health',
        'financial' => 'Financial aid', 'social' => 'Social and wellness', 'other' => 'Other'];
    public const FUNDING = ['lgu' => 'LGU budget', 'sponsor' => 'Sponsors', 'mixed' => 'LGU and sponsors'];
    /** After publishing, these define what a senior gets and cannot change (fairness between seniors in one program). */
    public const LOCKED = ['category', 'benefit_type', 'unit_amount', 'unit_quantity', 'unit_label', 'claim_period', 'starts_on', 'funding_source'];

    /**
     * @param array{code:string,name:string,category:string,description?:string,benefit_type:string,unit_amount?:?string,
     *              unit_quantity?:?int,unit_label?:?string,claim_period:string,starts_on:string,ends_on:string,
     *              status?:string,funding_source?:string} $p
     */
    public static function create(Actor $actor, array $p): string
    {
        $actor->require('ss_manage_programs');
        if (!in_array($p['benefit_type'], self::BENEFIT_TYPES, true) || !in_array($p['claim_period'], self::PERIODS, true)) {
            throw new DomainError('invalid_program', 'Unknown benefit type or claim period.');
        }
        $id = Uuid::v7();
        Db::insert('programs', [
            'id' => $id,
            'tenant_id' => $actor->tenantId,
            'code' => $p['code'],
            'name' => $p['name'],
            'category' => $p['category'],
            'description' => $p['description'] ?? null,
            'benefit_type' => $p['benefit_type'],
            'unit_amount' => $p['unit_amount'] ?? null,
            'unit_quantity' => $p['unit_quantity'] ?? null,
            'unit_label' => $p['unit_label'] ?? null,
            'claim_limit' => 1,
            'claim_period' => $p['claim_period'],
            'starts_on' => $p['starts_on'],
            'ends_on' => $p['ends_on'],
            'status' => $p['status'] ?? 'published',
            'rules_version' => 1,
            'rules_json' => wp_json_encode(['min_age' => 60, 'requires_verified' => true]),
            'funding_source' => $p['funding_source'] ?? 'lgu',
            'created_at' => Db::now(),
        ], ['id', 'tenant_id']);
        Audit::log($actor->tenantId, $actor->userId, 'program.created', 'program', $id, ['code' => $p['code']]);
        return $id;
    }

    /**
     * New program from the console. Starts as a draft: seniors and the public do not see it until it is published.
     *
     * @param array<string, mixed> $in
     */
    public static function draft(Actor $actor, array $in): string
    {
        $actor->require('ss_manage_programs');
        $p = self::clean($in);
        return Db::transaction(static function () use ($actor, $p): string {
            return self::create($actor, $p + ['code' => Sequence::ref($actor->tenantId, 'PRG'), 'status' => 'draft']);
        });
    }

    /**
     * Edit a program. A draft can change everything. A published program keeps what a senior gets
     * (self::LOCKED); only its name, description and end date can change. A closed program cannot change.
     *
     * @param array<string, mixed> $in
     */
    public static function update(Actor $actor, string $programId, array $in): void
    {
        $actor->require('ss_manage_programs');
        $programId = Uuid::assertHex($programId, 'program id');
        Db::transaction(static function () use ($actor, $programId, $in): void {
            $cur = self::lock($actor, $programId);
            if ($cur['status'] === 'closed') {
                throw new DomainError('invalid_transition', 'This program is closed and can no longer be changed.');
            }
            $p = self::clean($in, $cur['status'] === 'published' ? $cur : null);
            if ($cur['status'] === 'published') {
                foreach (self::LOCKED as $f) {
                    if ((string) ($p[$f] ?? '') !== (string) ($cur[$f] ?? '')) {
                        throw new DomainError('program_locked', 'Seniors can already apply for this program, so what they receive, how often, and the start date cannot change. Close it and create a new program instead.');
                    }
                }
            }
            $set = $cur['status'] === 'published' ? array_intersect_key($p, array_flip(['name', 'description', 'ends_on'])) : $p;
            $cols = [];
            $args = [];
            foreach ($set as $c => $v) {
                $cols[] = $v === null ? "`{$c}` = NULL" : "`{$c}` = %s";
                if ($v !== null) {
                    $args[] = (string) $v;
                }
            }
            array_push($args, Db::now(), $programId, $actor->tenantId);
            Db::exec(Db::prepare('UPDATE ' . Db::t('programs') . ' SET ' . implode(', ', $cols) . ', updated_at = %s WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)', ...$args));
            Audit::log($actor->tenantId, $actor->userId, 'program.updated', 'program', $programId, ['code' => $cur['code'], 'fields' => count($set)]);
        });
    }

    /** Draft -> published: seniors can apply from the start date, and the website lists it. */
    public static function publish(Actor $actor, string $programId): void
    {
        $actor->require('ss_manage_programs');
        $programId = Uuid::assertHex($programId, 'program id');
        Db::transaction(static function () use ($actor, $programId): void {
            $cur = self::lock($actor, $programId);
            if ($cur['status'] !== 'draft') {
                throw new DomainError('invalid_transition', 'Only a draft program can be published.');
            }
            if ($cur['ends_on'] < Db::todayManila()->format('Y-m-d')) {
                throw new DomainError('invalid_program', 'The end date has passed. Change the end date first.');
            }
            self::setStatus($actor, $programId, 'published');
            Audit::log($actor->tenantId, $actor->userId, 'program.published', 'program', $programId, ['code' => $cur['code']]);
        });
    }

    /** Draft or published -> closed. No new applications; approved benefits can still be released. */
    public static function close(Actor $actor, string $programId): void
    {
        $actor->require('ss_manage_programs');
        $programId = Uuid::assertHex($programId, 'program id');
        Db::transaction(static function () use ($actor, $programId): void {
            $cur = self::lock($actor, $programId);
            if ($cur['status'] === 'closed') {
                throw new DomainError('invalid_transition', 'This program is already closed.');
            }
            self::setStatus($actor, $programId, 'closed');
            Audit::log($actor->tenantId, $actor->userId, 'program.closed', 'program', $programId, ['code' => $cur['code'], 'was' => $cur['status']]);
        });
    }

    /** Every program of the tenant with its activity, newest first. @return list<array<string, mixed>> */
    public static function forConsole(Actor $actor): array
    {
        $actor->require('ss_manage_programs');
        $p = Db::t('programs');
        return Db::rows(Db::prepare(
            'SELECT ' . Db::hex('p.id', 'id') . ", p.code, p.name, p.description, p.category, p.benefit_type, p.unit_amount, p.unit_quantity,
                    p.unit_label, p.claim_period, p.starts_on, p.ends_on, p.status, p.funding_source,
                    (SELECT COUNT(*) FROM " . Db::t('applications') . " a WHERE a.program_id = p.id) AS applications,
                    (SELECT COUNT(*) FROM " . Db::t('applications') . " a WHERE a.program_id = p.id AND a.status IN ('submitted','under_review','info_needed')) AS waiting,
                    (SELECT COUNT(*) FROM " . Db::t('entitlements') . " e WHERE e.program_id = p.id AND e.status = 'fulfilled') AS released
             FROM {$p} p WHERE p.tenant_id = UNHEX(%s)
             ORDER BY FIELD(p.status, 'published', 'draft', 'closed'), p.created_at DESC",
            $actor->tenantId
        ));
    }

    /** @return array<string, mixed> */
    private static function lock(Actor $actor, string $programId): array
    {
        $cur = Db::row(Db::prepare(
            'SELECT code, name, description, category, benefit_type, unit_amount, unit_quantity, unit_label, claim_period, starts_on, ends_on,
                    status, funding_source FROM ' . Db::t('programs') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) FOR UPDATE',
            $programId, $actor->tenantId
        ));
        if ($cur === null) {
            throw new DomainError('not_found', 'Program not found.');
        }
        return $cur;
    }

    private static function setStatus(Actor $actor, string $programId, string $status): void
    {
        Db::exec(Db::prepare('UPDATE ' . Db::t('programs') . ' SET status = %s, updated_at = %s WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $status, Db::now(), $programId, $actor->tenantId));
    }

    /**
     * Validate console input into program columns. For a published program ($cur), the start date may lie in the past.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $cur
     * @return array<string, mixed>
     */
    private static function clean(array $in, ?array $cur = null): array
    {
        $bad = static fn(string $m) => new DomainError('invalid_program', $m);
        $name = trim(sanitize_text_field((string) ($in['name'] ?? '')));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            throw $bad('Give the program a name of 3 to 120 characters.');
        }
        $desc = trim(sanitize_textarea_field((string) ($in['description'] ?? '')));
        if (mb_strlen($desc) > 400) {
            throw $bad('Keep the description under 400 characters.');
        }
        $cat = (string) ($in['category'] ?? '');
        $type = (string) ($in['benefit_type'] ?? '');
        $period = (string) ($in['claim_period'] ?? '');
        $fund = (string) ($in['funding_source'] ?? '');
        if (!isset(self::CATEGORIES[$cat])) {
            throw $bad('Choose a category.');
        }
        if (!in_array($type, self::BENEFIT_TYPES, true)) {
            throw $bad('Choose what seniors receive: cash, goods or a service.');
        }
        if (!in_array($period, self::PERIODS, true)) {
            throw $bad('Choose how often a senior can claim.');
        }
        if (!isset(self::FUNDING[$fund])) {
            throw $bad('Choose who funds the program.');
        }
        $amount = null; $qty = null; $label = null;
        if ($type === 'cash') {
            $raw = str_replace(',', '', trim((string) ($in['unit_amount'] ?? '')));
            if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw) || (int) round((float) $raw * 100) < 100) {
                throw $bad('Enter the cash amount in pesos, at least 1.00 (for example 1000 or 1500.50).');
            }
            $amount = number_format((int) round((float) $raw * 100) / 100, 2, '.', '');
        } else {
            $qty = (int) ($in['unit_quantity'] ?? 0);
            $label = trim(sanitize_text_field((string) ($in['unit_label'] ?? '')));
            if ($qty < 1 || $qty > 1000) {
                throw $bad('Enter how many each senior receives, from 1 to 1000.');
            }
            if ($label === '' || mb_strlen($label) > 24) {
                throw $bad('Name the unit in one word or two, for example "pack", "kit" or "session".');
            }
        }
        $date = static function (string $f, string $what) use ($in, $bad): string {
            $v = (string) ($in[$f] ?? '');
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($d === false || $d->format('Y-m-d') !== $v) {
                throw $bad("Enter the {$what} date.");
            }
            return $v;
        };
        $starts = $date('starts_on', 'start');
        $ends = $date('ends_on', 'end');
        if ($ends < $starts) {
            throw $bad('The end date must be on or after the start date.');
        }
        if ($ends < Db::todayManila()->format('Y-m-d')) {
            throw $bad('The end date has already passed.');
        }
        if ($cur === null && $ends > Db::todayManila()->modify('+5 years')->format('Y-m-d')) {
            throw $bad('Programs can run for up to 5 years. Choose an earlier end date.');
        }
        return ['name' => $name, 'description' => $desc === '' ? null : $desc, 'category' => $cat, 'benefit_type' => $type,
            'unit_amount' => $amount, 'unit_quantity' => $qty, 'unit_label' => $label, 'claim_period' => $period,
            'starts_on' => $starts, 'ends_on' => $ends, 'funding_source' => $fund];
    }

    /** @return array<string, mixed>|null */
    public static function byCode(string $tenantId, string $code): ?array
    {
        return Db::row(Db::prepare(
            'SELECT ' . Db::hex('id') . ', code, name, benefit_type, unit_amount, unit_quantity, unit_label, claim_period,
                    starts_on, ends_on, status, rules_version
             FROM ' . Db::t('programs') . ' WHERE tenant_id = UNHEX(%s) AND code = %s',
            $tenantId, $code
        ));
    }

    /** Period key that enforces the claim limit: 'once', '2026', or '2026-11'. */
    public static function periodKey(string $claimPeriod, \DateTimeImmutable $day): string
    {
        return match ($claimPeriod) {
            'yearly' => $day->format('Y'),
            'monthly' => $day->format('Y-m'),
            default => 'once',
        };
    }
}
