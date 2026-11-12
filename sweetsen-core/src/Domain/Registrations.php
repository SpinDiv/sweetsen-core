<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Roles\Roles;
use SweetSen\Seed\Accounts;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Sequence;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * Public registration requests (website forms) and their review by LGU staff.
 *
 * Submitting needs no login, so it is defended in depth: strict validation, a honeypot, a minimum
 * fill time, a per-visitor rate limit, and duplicate checks. A request is never a record: approval by
 * staff creates the senior (unverified, still checked in person) or the organization and its login.
 */
final class Registrations
{
    public const KINDS = ['senior', 'sponsor', 'inquiry'];
    public const TOPICS = ['programs' => 'Programs and benefits', 'register' => 'Registering a senior', 'sponsor' => 'Sponsoring or donating',
        'account' => 'Signing in or my account', 'partner' => 'Partnership or media', 'other' => 'Something else'];
    public const ORG_TYPES = ['company' => 'Company', 'ngo' => 'Non-profit or foundation', 'faith' => 'Church or faith group',
        'community' => 'Community or civic group', 'individual' => 'Individual donor'];
    private const MAX_PER_HOUR = 5;

    /** Tenant served by the public website (option sweetsen_public_tenant). */
    public static function publicTenant(): string
    {
        $slug = (string) get_option('sweetsen_public_tenant', 'makati');
        $id = Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('tenants') . ' WHERE slug = %s', $slug));
        if (!$id) {
            throw new DomainError('not_available', 'Registration is not available right now.');
        }
        return (string) $id;
    }

    /** @param array<string, mixed> $in */
    public static function submitSenior(string $tenantId, array $in, string $ipHash): string
    {
        self::guard($tenantId, 'senior', $in, $ipHash);
        $d = [
            'last_name' => self::text($in, 'last_name', 100),
            'first_name' => self::text($in, 'first_name', 100),
            'middle_name' => self::text($in, 'middle_name', 100),
            'suffix' => self::text($in, 'suffix', 10),
            'sex' => strtoupper(self::text($in, 'sex', 1)),
            'birthdate' => self::text($in, 'birthdate', 10),
            'barangay' => self::text($in, 'barangay', 100),
            'osca_id' => self::text($in, 'osca_id', 40),
            'contact_phone' => self::phone($in['contact_phone'] ?? ''),
        ];
        $error = Importer::validateRecord($d);
        if ($error !== null) {
            throw new DomainError('invalid_request', str_starts_with($error, 'Age ')
                ? 'SweetSen is for senior citizens 60 years old and above. Please check the birthday.' : $error);
        }
        $filedBy = ($in['filed_by'] ?? 'self') === 'family' ? 'family' : 'self';
        $filer = self::text($in, 'filer_name', 191);
        if ($filedBy === 'family' && $filer === '') {
            throw new DomainError('invalid_request', 'Please write your own name, since you are applying for a family member.');
        }
        $email = trim((string) ($in['contact_email'] ?? ''));
        if ($email !== '' && !is_email($email)) {
            throw new DomainError('invalid_request', 'Please check the email address, or leave it empty.');
        }
        $email = $email !== '' ? strtolower(sanitize_email($email)) : '';
        $open = Db::value(Db::prepare(
            'SELECT ref_no FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND kind = %s AND status = %s
               AND birthdate = %s AND LOWER(last_name) = LOWER(%s) AND LOWER(first_name) = LOWER(%s) LIMIT 1',
            $tenantId, 'senior', 'submitted', $d['birthdate'], $d['last_name'], $d['first_name']
        ));
        if ($open) {
            throw new DomainError('already_requested', "We already have a request for this senior (ref {$open}). The office will contact you.");
        }

        [$id, $ref] = Db::transaction(static function () use ($tenantId, $d, $filedBy, $filer, $in, $ipHash, $email): array {
            $id = Uuid::v7();
            $ref = Sequence::ref($tenantId, 'REG');
            Db::insert('registration_requests', [
                'id' => $id, 'tenant_id' => $tenantId, 'ref_no' => $ref, 'kind' => 'senior', 'status' => 'submitted',
                'last_name' => $d['last_name'], 'first_name' => $d['first_name'],
                'middle_name' => $d['middle_name'] ?: null, 'suffix' => $d['suffix'] ?: null, 'sex' => $d['sex'] ?: null,
                'birthdate' => $d['birthdate'], 'barangay' => $d['barangay'], 'osca_id' => $d['osca_id'] ?: null,
                'filed_by' => $filedBy, 'filer_name' => $filer ?: null, 'filer_relation' => self::text($in, 'filer_relation', 60) ?: null,
                'contact_phone' => $d['contact_phone'] ?: null, 'contact_email' => $email ?: null, 'ip_hash' => $ipHash, 'created_at' => Db::now(),
            ], ['id', 'tenant_id']);
            Audit::log($tenantId, 0, 'registration.submitted', 'registration_request', $id, ['kind' => 'senior']);
            return [$id, $ref];
        });
        if ($email !== '') {
            do_action('sweetsen_registration_received', $tenantId, $id);
        }
        return $ref;
    }

    /** @param array<string, mixed> $in */
    public static function submitSponsor(string $tenantId, array $in, string $ipHash): string
    {
        self::guard($tenantId, 'sponsor', $in, $ipHash);
        $org = self::text($in, 'org_name', 191);
        $type = (string) ($in['org_type'] ?? '');
        $contact = self::text($in, 'contact_name', 191);
        $email = sanitize_email((string) ($in['contact_email'] ?? ''));
        $phone = self::phone($in['contact_phone'] ?? '');
        $message = self::text($in, 'message', 500);
        if (mb_strlen($org) < 2) {
            throw new DomainError('invalid_request', 'Please write the name of your organization.');
        }
        if (!isset(self::ORG_TYPES[$type])) {
            throw new DomainError('invalid_request', 'Please choose what kind of organization you are.');
        }
        if (mb_strlen($contact) < 2) {
            throw new DomainError('invalid_request', 'Please write the name of the contact person.');
        }
        if (!is_email($email)) {
            throw new DomainError('invalid_request', 'Please write a valid email address. We send the sign-in details there.');
        }
        $exists = Db::value(Db::prepare(
            'SELECT 1 FROM ' . Db::t('organizations') . ' WHERE tenant_id = UNHEX(%s) AND LOWER(name) = LOWER(%s)', $tenantId, $org
        )) ?: Db::value(Db::prepare(
            'SELECT 1 FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND kind = %s AND status = %s AND LOWER(org_name) = LOWER(%s)',
            $tenantId, 'sponsor', 'submitted', $org
        ));
        if ($exists) {
            throw new DomainError('already_requested', 'This organization is already registered or waiting for review. The LGU will contact you.');
        }

        [$id, $ref] = Db::transaction(static function () use ($tenantId, $org, $type, $contact, $email, $phone, $message, $ipHash): array {
            $id = Uuid::v7();
            $ref = Sequence::ref($tenantId, 'REG');
            Db::insert('registration_requests', [
                'id' => $id, 'tenant_id' => $tenantId, 'ref_no' => $ref, 'kind' => 'sponsor', 'status' => 'submitted',
                'org_name' => $org, 'org_type' => $type, 'contact_name' => $contact, 'contact_email' => $email,
                'contact_phone' => $phone ?: null, 'message' => $message ?: null, 'ip_hash' => $ipHash, 'created_at' => Db::now(),
            ], ['id', 'tenant_id']);
            Audit::log($tenantId, 0, 'registration.submitted', 'registration_request', $id, ['kind' => 'sponsor']);
            return [$id, $ref];
        });
        do_action('sweetsen_sponsor_received', $tenantId, $id);
        return $ref;
    }

    /**
     * General question from the Contact page. Stored with the other website requests (kind "inquiry")
     * so staff see it in the console. Needs a way to reply: an email or a mobile number.
     *
     * @param array<string, mixed> $in
     */
    public static function submitInquiry(string $tenantId, array $in, string $ipHash): string
    {
        self::guard($tenantId, 'inquiry', $in, $ipHash);
        $name = self::text($in, 'contact_name', 191);
        $email = sanitize_email((string) ($in['contact_email'] ?? ''));
        $phone = self::phone($in['contact_phone'] ?? '');
        $topic = (string) ($in['topic'] ?? '');
        $message = mb_substr(trim(sanitize_textarea_field((string) ($in['message'] ?? ''))), 0, 500);
        if (mb_strlen($name) < 2) {
            throw new DomainError('invalid_request', 'Please write your name.');
        }
        if (!isset(self::TOPICS[$topic])) {
            throw new DomainError('invalid_request', 'Please choose what your message is about.');
        }
        if ($email === '' && strlen(ltrim($phone, '+')) < 10) {
            throw new DomainError('invalid_request', 'Please give an email or a mobile number so we can reply.');
        }
        if ($email !== '' && !is_email($email)) {
            throw new DomainError('invalid_request', 'Please check the email address.');
        }
        if (mb_strlen($message) < 10) {
            throw new DomainError('invalid_request', 'Please write your question or message.');
        }

        return Db::transaction(static function () use ($tenantId, $name, $email, $phone, $topic, $message, $ipHash): string {
            $id = Uuid::v7();
            $ref = Sequence::ref($tenantId, 'MSG');
            Db::insert('registration_requests', [
                'id' => $id, 'tenant_id' => $tenantId, 'ref_no' => $ref, 'kind' => 'inquiry', 'status' => 'submitted',
                'org_type' => $topic, 'contact_name' => $name, 'contact_email' => $email ?: null, 'contact_phone' => $phone ?: null,
                'message' => $message, 'ip_hash' => $ipHash, 'created_at' => Db::now(),
            ], ['id', 'tenant_id']);
            Audit::log($tenantId, 0, 'inquiry.submitted', 'registration_request', $id, ['topic' => $topic]);
            return $ref;
        });
    }

    /** Staff mark a website message as answered (they reply by email or phone outside SweetSen). */
    public static function answer(Actor $actor, string $requestId, string $note): void
    {
        $actor->require('ss_manage_beneficiaries');
        $requestId = Uuid::assertHex($requestId, 'request id');
        $note = trim(sanitize_text_field($note));
        Db::transaction(static function () use ($actor, $requestId, $note): void {
            self::lock($actor, $requestId, 'inquiry');
            self::close($actor, $requestId, 'answered', $note);
            Audit::log($actor->tenantId, $actor->userId, 'inquiry.answered', 'registration_request', $requestId, []);
        });
    }

    /** @return list<array<string, mixed>> */
    public static function queue(Actor $actor, string $kind): array
    {
        $actor->require($kind === 'sponsor' ? 'ss_review_pledges' : 'ss_manage_beneficiaries');
        return Db::rows(Db::prepare(
            'SELECT ' . Db::hex('id') . ', ref_no, kind, status, last_name, first_name, middle_name, suffix, sex, birthdate, barangay, osca_id,
                    filed_by, filer_name, filer_relation, org_name, org_type, contact_name, contact_email, contact_phone, message,
                    review_note, created_at, reviewed_at
             FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND kind = %s
             ORDER BY status = %s DESC, created_at DESC LIMIT 50',
            $actor->tenantId, $kind, 'submitted'
        ));
    }

    public static function pendingCount(Actor $actor, ?string $kind = null): int
    {
        $kinds = $kind !== null ? [$kind] : ['senior', 'inquiry'];
        if ($kind === null && $actor->can('ss_review_pledges')) {
            $kinds[] = 'sponsor';
        }
        $in = implode(',', array_fill(0, count($kinds), '%s'));
        return (int) Db::value(Db::prepare(
            'SELECT COUNT(*) FROM ' . Db::t('registration_requests') . " WHERE tenant_id = UNHEX(%s) AND status = %s AND kind IN ({$in})",
            $actor->tenantId, 'submitted', ...$kinds
        ));
    }

    /**
     * Approve a senior request: create the senior record as unverified (documents are still checked in person).
     * Refuses when the senior already exists. When the request has an email no other account uses, this also creates the
     * senior's login (username = their SS reference) and, after commit, emails a welcome with a set-password link.
     *
     * @return array{ref_no:string, beneficiary_id:string, login:?string, account:string}
     */
    public static function approveSenior(Actor $actor, string $requestId): array
    {
        $actor->require('ss_verify_beneficiary');
        $requestId = Uuid::assertHex($requestId, 'request id');
        $res = Db::transaction(static function () use ($actor, $requestId): array {
            $r = self::lock($actor, $requestId, 'senior');
            $d = ['last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'birthdate' => $r['birthdate'],
                'osca_id' => (string) $r['osca_id'], 'ref_no' => ''];
            $match = Importer::duplicateOf($actor->tenantId, $d);
            if ($match !== null) {
                throw new DomainError('possible_duplicate', "This senior may already be registered as {$match['ref_no']}. Check that record, then reject this request if it is the same person.");
            }
            $benId = Uuid::v7();
            $ref = Sequence::ref($actor->tenantId, 'SS');
            Db::insert('beneficiaries', [
                'id' => $benId, 'tenant_id' => $actor->tenantId, 'ref_no' => $ref, 'source_ref' => $r['ref_no'],
                'last_name' => $r['last_name'], 'first_name' => $r['first_name'], 'middle_name' => $r['middle_name'],
                'suffix' => $r['suffix'], 'sex' => $r['sex'], 'birthdate' => $r['birthdate'], 'barangay' => $r['barangay'],
                'contact_phone' => $r['contact_phone'], 'osca_id' => $r['osca_id'],
                'verification_status' => 'unverified', 'status' => 'active', 'created_at' => Db::now(),
            ], ['id', 'tenant_id']);
            $email = (string) $r['contact_email'];
            $uid = null;
            $account = !is_email($email) ? 'no_email' : (email_exists($email) || username_exists($ref) ? 'email_in_use' : 'created');
            if ($account === 'created') {
                [$uid] = Accounts::user($ref, $email, trim($r['first_name'] . ' ' . $r['last_name']), Roles::SENIOR);
                Accounts::membership($actor->tenantId, $uid, Roles::SENIOR);
                Db::exec(Db::prepare('UPDATE ' . Db::t('beneficiaries') . ' SET user_id = %d WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)', $uid, $benId, $actor->tenantId));
            }
            self::close($actor, $requestId, 'approved', '', ['beneficiary_id' => $benId] + ($uid ? ['user_id' => $uid] : []));
            Audit::log($actor->tenantId, $actor->userId, 'registration.approved', 'registration_request', $requestId, ['kind' => 'senior', 'account' => $account]);
            return ['ref_no' => $ref, 'beneficiary_id' => $benId, 'login' => $uid ? $ref : null, 'account' => $account, 'user_id' => $uid];
        });
        if ($res['user_id']) {
            do_action('sweetsen_senior_account_created', $actor->tenantId, (int) $res['user_id'], $res['beneficiary_id']);
        }
        unset($res['user_id']);
        return $res;
    }

    /**
     * Approve a sponsor request: create the organization (verified), a sponsor login and its membership,
     * then email the contact a link to set their own password.
     *
     * @return array{login:string, organization_id:string, emailed:bool}
     */
    public static function approveSponsor(Actor $actor, string $requestId): array
    {
        $actor->require('ss_review_pledges');
        $requestId = Uuid::assertHex($requestId, 'request id');
        $r = Db::row(Db::prepare(
            'SELECT status, org_name, org_type, contact_name, contact_email FROM ' . Db::t('registration_requests') . '
             WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND kind = %s',
            $requestId, $actor->tenantId, 'sponsor'
        ));
        if ($r === null) {
            throw new DomainError('not_found', 'Request not found.');
        }
        if ($r['status'] !== 'submitted') {
            throw new DomainError('invalid_transition', 'This request has already been reviewed.');
        }
        if (email_exists($r['contact_email'])) {
            throw new DomainError('email_in_use', 'An account already uses this email address. Ask the sponsor for another address, or reject this request.');
        }
        $base = 'sponsor_' . substr(preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($r['org_name']))) ?: 'org', 0, 20);
        $login = $base;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        [$uid] = Accounts::user($login, $r['contact_email'], $r['contact_name'] . ' (' . $r['org_name'] . ')', Roles::ORG_USER);
        try {
            $orgId = Db::transaction(static function () use ($actor, $requestId, $r, $uid): string {
                self::lock($actor, $requestId, 'sponsor');
                $orgId = Uuid::v7();
                Db::insert('organizations', [
                    'id' => $orgId, 'tenant_id' => $actor->tenantId, 'name' => $r['org_name'], 'type' => 'sponsor',
                    'status' => 'verified', 'contact_name' => $r['contact_name'], 'contact_email' => $r['contact_email'],
                    'verified_by' => $actor->userId, 'verified_at' => Db::now(), 'created_at' => Db::now(),
                ], ['id', 'tenant_id']);
                Accounts::membership($actor->tenantId, $uid, Roles::ORG_USER, $orgId);
                self::close($actor, $requestId, 'approved', '', ['organization_id' => $orgId, 'user_id' => $uid]);
                Audit::log($actor->tenantId, $actor->userId, 'registration.approved', 'registration_request', $requestId, ['kind' => 'sponsor']);
                return $orgId;
            });
        } catch (\Throwable $e) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($uid);
            throw $e;
        }
        // After commit: the contact sets their own password from the emailed link. No password is shown or stored by us.
        $emailed = !\SweetSen\Support\Mail::isDemo((string) $r['contact_email']);
        do_action('sweetsen_sponsor_account_created', $actor->tenantId, $uid, $orgId);
        return ['login' => $login, 'organization_id' => $orgId, 'emailed' => $emailed];
    }

    public static function reject(Actor $actor, string $requestId, string $note): void
    {
        $requestId = Uuid::assertHex($requestId, 'request id');
        $kind = Db::value(Db::prepare(
            'SELECT kind FROM ' . Db::t('registration_requests') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)', $requestId, $actor->tenantId
        ));
        if (!$kind) {
            throw new DomainError('not_found', 'Request not found.');
        }
        $actor->require(match ($kind) { 'sponsor' => 'ss_review_pledges', 'inquiry' => 'ss_manage_beneficiaries', default => 'ss_verify_beneficiary' });
        $note = trim(sanitize_text_field($note));
        if ($note === '') {
            throw new DomainError('reason_required', 'Write a short reason for rejecting this request.');
        }
        Db::transaction(static function () use ($actor, $requestId, $kind, $note): void {
            self::lock($actor, $requestId, (string) $kind);
            self::close($actor, $requestId, 'rejected', $note);
            Audit::log($actor->tenantId, $actor->userId, 'registration.rejected', 'registration_request', $requestId, ['kind' => $kind]);
        });
    }

    /* ------------------------------------------------------------------ helpers */

    /** @param array<string, mixed> $in */
    private static function guard(string $tenantId, string $kind, array $in, string $ipHash): void
    {
        if (empty($in['demo_ack'])) {
            throw new DomainError('invalid_request', 'Please tick the box to confirm you are not entering real personal information.');
        }
        // Honeypot and minimum fill time: quiet refusals that look like success to a bot are not worth the confusion here.
        if (trim((string) ($in['website'] ?? '')) !== '' || (int) ($in['elapsed_ms'] ?? 0) < 3000) {
            throw new DomainError('invalid_request', 'Please fill in the form again, a little more slowly.');
        }
        FormLimits::enforce($tenantId, $kind, $ipHash);
        $key = 'ss_reg_' . substr($ipHash, 0, 24);
        $n = (int) get_transient($key);
        if ($n >= self::MAX_PER_HOUR) {
            throw new DomainError('rate_limited', 'Too many requests from this connection. Please try again in an hour.');
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
    }

    /** @return array<string, mixed> */
    private static function lock(Actor $actor, string $requestId, string $kind): array
    {
        $r = Db::row(Db::prepare(
            'SELECT * FROM ' . Db::t('registration_requests') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND kind = %s FOR UPDATE',
            $requestId, $actor->tenantId, $kind
        ));
        if ($r === null) {
            throw new DomainError('not_found', 'Request not found.');
        }
        if ($r['status'] !== 'submitted') {
            throw new DomainError('invalid_transition', 'This request has already been reviewed.');
        }
        return $r;
    }

    /** @param array<string, string|int> $links */
    private static function close(Actor $actor, string $requestId, string $status, string $note, array $links = []): void
    {
        $set = '';
        foreach ($links as $col => $val) {
            $set .= is_int($val) ? Db::prepare(", {$col} = %d", $val) : Db::prepare(", {$col} = UNHEX(%s)", $val);
        }
        Db::exec(Db::prepare(
            'UPDATE ' . Db::t('registration_requests') . ' SET status = %s, reviewed_by = %d, reviewed_at = %s, review_note = %s, updated_at = %s',
            $status, $actor->userId, Db::now(), substr($note, 0, 250), Db::now()
        ) . $set . Db::prepare(' WHERE id = UNHEX(%s)', $requestId));
    }

    /** @param array<string, mixed> $in */
    private static function text(array $in, string $key, int $max): string
    {
        return mb_substr(trim(sanitize_text_field((string) ($in[$key] ?? ''))), 0, $max);
    }

    private static function phone($v): string
    {
        $v = preg_replace('/[^0-9+]/', '', (string) $v);
        return substr((string) $v, 0, 20);
    }
}
