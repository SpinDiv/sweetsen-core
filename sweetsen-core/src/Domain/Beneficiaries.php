<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Support\Db;
use SweetSen\Support\Mail;
use SweetSen\Support\DomainError;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/** Verification and QR credentials for senior records. */
final class Beneficiaries
{
    public const QR_PREFIX = 'SS1:';

    /** @return array<string, mixed> */
    public static function get(Actor $actor, string $beneficiaryId): array
    {
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        $row = Db::row(Db::prepare(
            'SELECT ' . Db::hex('id') . ', ref_no, user_id, last_name, first_name, middle_name, suffix, sex, birthdate,
                    barangay, verification_status, status
             FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)',
            $beneficiaryId, $actor->tenantId
        ));
        if ($row === null) {
            throw new DomainError('not_found', 'Senior record not found.');
        }
        $isOwn = $row['user_id'] !== null && (int) $row['user_id'] === $actor->userId;
        if (!$isOwn && !$actor->can('ss_manage_beneficiaries')) {
            throw new DomainError('forbidden', 'You do not have permission to view this record.');
        }
        return $row;
    }

    /** IDs staff may accept at the office. Only the type is kept (in the audit log), never the ID number or an image. */
    public const ID_TYPES = ['osca' => 'Senior citizen (OSCA) ID', 'philsys' => 'PhilSys National ID', 'passport' => 'Passport',
        'drivers' => "Driver's license", 'umid' => 'UMID, SSS or GSIS ID', 'voters' => "Voter's ID", 'postal' => 'Postal ID',
        'other' => 'Other government ID'];
    /** Every item must be confirmed before a record is verified. */
    public const CHECKS = ['birthdate_matches' => 'The birth date on the ID matches the record',
        'photo_matches' => 'The person in front of me matches the photo on the ID',
        'address_confirmed' => 'The barangay and address are confirmed'];

    /**
     * Mark a record verified after an in-person ID check. $check = id_type (self::ID_TYPES) + every self::CHECKS item true.
     *
     * @param array<string, mixed> $check
     */
    public static function verify(Actor $actor, string $beneficiaryId, array $check): void
    {
        $actor->require('ss_verify_beneficiary');
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        $idType = (string) ($check['id_type'] ?? '');
        if (!isset(self::ID_TYPES[$idType])) {
            throw new DomainError('checklist_incomplete', 'Choose the type of ID you checked.');
        }
        foreach (array_keys(self::CHECKS) as $item) {
            if (!filter_var($check[$item] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new DomainError('checklist_incomplete', 'Tick every item on the checklist. If something does not match, leave the senior as "To verify".');
            }
        }
        Db::transaction(static function () use ($actor, $beneficiaryId, $idType): void {
            $b = Db::row(Db::prepare(
                'SELECT verification_status, status FROM ' . Db::t('beneficiaries') . '
                 WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) FOR UPDATE',
                $beneficiaryId, $actor->tenantId
            ));
            if ($b === null) {
                throw new DomainError('not_found', 'Senior record not found.');
            }
            if ($b['verification_status'] === 'verified') {
                throw new DomainError('already_verified', 'This senior is already verified.');
            }
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('beneficiaries') . ' SET verification_status = %s, verified_by = %d, verified_at = %s, updated_at = %s
                 WHERE id = UNHEX(%s)',
                'verified', $actor->userId, Db::now(), Db::now(), $beneficiaryId
            ));
            Audit::log($actor->tenantId, $actor->userId, 'beneficiary.verified', 'beneficiary', $beneficiaryId, ['id_type' => $idType, 'checklist' => 'complete']);
        });
    }

    /**
     * Issue a new QR credential (revoking any active one). Returns the raw QR payload ONCE;
     * only its SHA-256 hash is stored.
     *
     * @return array{credential_id:string, qr_payload:string}
     */
    /** Why a QR code was replaced (stored on the revoked credential). */
    public const REPLACE_REASONS = ['lost' => 'Lost', 'stolen' => 'Stolen', 'damaged' => 'Damaged or unreadable', 'other' => 'Other'];

    public static function issueQr(Actor $actor, string $beneficiaryId, string $reason = 'replaced'): array
    {
        $actor->require('ss_manage_qr');
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        $reason = isset(self::REPLACE_REASONS[$reason]) ? $reason : 'replaced';
        return Db::transaction(static function () use ($actor, $beneficiaryId, $reason): array {
            $b = Db::row(Db::prepare(
                'SELECT verification_status, status FROM ' . Db::t('beneficiaries') . '
                 WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) FOR UPDATE',
                $beneficiaryId, $actor->tenantId
            ));
            if ($b === null) {
                throw new DomainError('not_found', 'Senior record not found.');
            }
            if ($b['verification_status'] !== 'verified' || $b['status'] !== 'active') {
                throw new DomainError('beneficiary_not_verified', 'A QR code can only be issued to a verified, active senior.');
            }
            $now = Db::now();
            $replaced = Db::exec(Db::prepare(
                'UPDATE ' . Db::t('qr_credentials') . ' SET status = %s, revoked_by = %d, revoked_at = %s, revoke_reason = %s
                 WHERE beneficiary_id = UNHEX(%s) AND status = %s',
                'revoked', $actor->userId, $now, $reason, $beneficiaryId, 'active'
            )) > 0;
            $credId = Uuid::v7();
            $token = self::signedToken($credId);
            Db::insert('qr_credentials', [
                'id' => $credId,
                'tenant_id' => $actor->tenantId,
                'beneficiary_id' => $beneficiaryId,
                'token_hash' => hash('sha256', $token),
                'status' => 'active',
                'issued_by' => $actor->userId,
                'issued_at' => $now,
            ], ['id', 'tenant_id', 'beneficiary_id']);
            Audit::log($actor->tenantId, $actor->userId, 'qr.issued', 'qr_credential', $credId, $replaced ? ['replaced' => $reason] : []);
            return ['credential_id' => $credId, 'qr_payload' => self::QR_PREFIX . $token];
        });
    }

    /**
     * Where a new QR code can be emailed: the senior's own SweetSen login, else the email given on their website
     * registration (often a family member). demo=true for addresses that cannot receive mail (.example, .test, .invalid).
     *
     * @return array{email:string, source:string, masked:string, demo:bool}|null
     */
    public static function emailOnFile(Actor $actor, string $beneficiaryId): ?array
    {
        $actor->require('ss_manage_qr');
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        $row = Db::row(Db::prepare('SELECT b.user_id, (SELECT r.contact_email FROM ' . Db::t('registration_requests') . ' r
                WHERE r.tenant_id = b.tenant_id AND r.beneficiary_id = b.id AND r.contact_email IS NOT NULL LIMIT 1) AS reg_email
            FROM ' . Db::t('beneficiaries') . ' b WHERE b.id = UNHEX(%s) AND b.tenant_id = UNHEX(%s)', $beneficiaryId, $actor->tenantId));
        if ($row === null) {
            throw new DomainError('not_found', 'Senior record not found.');
        }
        $user = $row['user_id'] ? get_userdata((int) $row['user_id']) : false;
        [$email, $source] = $user && is_email($user->user_email) ? [$user->user_email, 'login']
            : (is_email((string) $row['reg_email']) ? [(string) $row['reg_email'], 'registration'] : ['', '']);
        if ($email === '') {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        return ['email' => $email, 'source' => $source, 'masked' => mb_substr($local, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain,
            'demo' => Mail::isDemo($email)];
    }

    /**
     * Email the senior's CURRENT QR code. The picture is drawn by the staff member's browser from the payload this
     * server just issued (no QR library on the server); it must be a PNG of sensible size. Audited without the address.
     */
    public static function emailQr(Actor $actor, string $beneficiaryId, string $pngDataUrl): string
    {
        $actor->require('ss_manage_qr');
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        $to = self::emailOnFile($actor, $beneficiaryId);
        if ($to === null) {
            throw new DomainError('no_email', 'There is no email on file for this senior. Print a new QR card instead.');
        }
        if ($to['demo']) {
            throw new DomainError('no_email', 'The email on file is a demo address that cannot receive mail. Print a new QR card instead.');
        }
        $b = self::get($actor, $beneficiaryId);
        $active = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('qr_credentials') . ' WHERE beneficiary_id = UNHEX(%s) AND status = %s', $beneficiaryId, 'active'));
        if ($active === 0) {
            throw new DomainError('no_qr', 'This senior has no active QR code to send.');
        }
        $png = str_starts_with($pngDataUrl, 'data:image/png;base64,') ? base64_decode(substr($pngDataUrl, 22), true) : false;
        $size = is_string($png) && strlen($png) < 400000 ? @getimagesizefromstring($png) : false;
        if ($size === false || ($size['mime'] ?? '') !== 'image/png' || $size[0] < 200 || $size[0] > 1600 || $size[1] < 200 || $size[1] > 2000) {
            throw new DomainError('invalid_request', 'The QR picture could not be prepared. Reload the page and try again.');
        }
        $ref = (string) $b['ref_no'];
        $body = Mail::p('Hello ' . esc_html((string) $b['first_name']) . ',')
            . Mail::p('The office has given you a new SweetSen QR code. <strong>Your old code no longer works</strong>, so no one else can use it.')
            . '<p style="text-align:center;margin:8px 0 20px"><img src="cid:ss-qr" alt="Your SweetSen QR code" width="280" style="width:280px;max-width:100%;height:auto;border:1px solid #C5CCD5;border-radius:8px"></p>'
            . Mail::p('Show this code at the release desk, on your phone or printed. It is also attached to this email.')
            . Mail::fact('Your reference number', $ref)
            . Mail::p('Keep it private, like a bank card. If you did not ask for a new code, contact the senior citizens&#039; office.', true);
        $text = "Your new SweetSen QR code is attached. Your old code no longer works. Show the new code at the release desk. Reference: {$ref}";
        $sent = Mail::send($to['email'], 'Your new SweetSen QR code', Mail::render('Your new QR code', $body, 'Your old code no longer works. Here is the new one.'), $text,
            static function ($mailer) use ($png, $ref): void {
                $mailer->addStringEmbeddedImage($png, 'ss-qr', 'sweetsen-qr.png', 'base64', 'image/png');
                $mailer->addStringAttachment($png, 'sweetsen-qr-' . strtolower(str_replace(' ', '', $ref)) . '.png', 'base64', 'image/png');
            });
        Audit::log($actor->tenantId, $actor->userId, $sent ? 'qr.emailed' : 'qr.email_failed', 'beneficiary', $beneficiaryId, ['to' => $to['source']]);
        if (!$sent) {
            throw new DomainError('not_available', 'The email could not be sent from this server. Print a new QR card instead.');
        }
        return $to['masked'];
    }

    public static function revokeQr(Actor $actor, string $beneficiaryId, string $reason): void
    {
        $actor->require('ss_manage_qr');
        $beneficiaryId = Uuid::assertHex($beneficiaryId, 'senior id');
        Db::transaction(static function () use ($actor, $beneficiaryId, $reason): void {
            $n = Db::exec(Db::prepare(
                'UPDATE ' . Db::t('qr_credentials') . ' SET status = %s, revoked_by = %d, revoked_at = %s, revoke_reason = %s
                 WHERE beneficiary_id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND status = %s',
                'revoked', $actor->userId, Db::now(), substr($reason, 0, 190), $beneficiaryId, $actor->tenantId, 'active'
            ));
            if ($n === 0) {
                throw new DomainError('no_active_qr', 'This senior has no active QR code.');
            }
            Audit::log($actor->tenantId, $actor->userId, 'qr.revoked', 'beneficiary', $beneficiaryId);
        });
    }

    /**
     * Resolve a scanned QR payload to its active credential within the actor's tenant.
     * The signature is checked before any database lookup, so forged or altered codes cost nothing.
     *
     * @return array{credential_id:string, beneficiary_id:string}|null
     */
    public static function resolveQr(string $tenantId, string $payload): ?array
    {
        $payload = trim($payload);
        if (str_starts_with($payload, self::QR_PREFIX)) {
            $payload = substr($payload, strlen(self::QR_PREFIX));
        }
        if (!preg_match('/^[A-Za-z0-9_-]{40,48}$/', $payload)) {
            return null;
        }
        $raw = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }
        $credId = bin2hex(substr($raw, 0, 16));
        if (!hash_equals(self::signedToken($credId), $payload)) {
            return null;
        }
        $row = Db::row(Db::prepare(
            'SELECT ' . Db::hex('id', 'credential_id') . ', ' . Db::hex('beneficiary_id') . '
             FROM ' . Db::t('qr_credentials') . ' WHERE id = UNHEX(%s) AND token_hash = %s AND tenant_id = UNHEX(%s) AND status = %s',
            $credId, hash('sha256', $payload), $tenantId, 'active'
        ));
        return $row ?: null;
    }

    /**
     * The current QR payload for a senior, for display (senior portal, printed cards).
     * Only the senior themself or staff who manage QR codes may see it.
     */
    public static function qrPayload(Actor $actor, string $beneficiaryId): ?string
    {
        $b = self::get($actor, $beneficiaryId);
        $own = $b['user_id'] !== null && (int) $b['user_id'] === $actor->userId;
        if (!$own) {
            $actor->require('ss_manage_qr');
        }
        $credId = Db::value(Db::prepare(
            'SELECT LOWER(HEX(id)) FROM ' . Db::t('qr_credentials') . ' WHERE beneficiary_id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND status = %s',
            $b['id'], $actor->tenantId, 'active'
        ));
        return $credId ? self::QR_PREFIX . self::signedToken((string) $credId) : null;
    }

    /**
     * Printable QR cards for every verified senior with an active code (staff only).
     *
     * @return list<array{ref_no:string, name:string, barangay:string, qr_payload:string}>
     */
    public static function qrCards(Actor $actor): array
    {
        $actor->require('ss_manage_qr');
        $rows = Db::rows(Db::prepare(
            'SELECT b.ref_no, b.first_name, b.middle_name, b.last_name, b.suffix, b.barangay, LOWER(HEX(q.id)) AS credential_id
             FROM ' . Db::t('qr_credentials') . ' q JOIN ' . Db::t('beneficiaries') . ' b ON b.id = q.beneficiary_id
             WHERE q.tenant_id = UNHEX(%s) AND q.status = %s AND b.verification_status = %s
             ORDER BY b.ref_no',
            $actor->tenantId, 'active', 'verified'
        ));
        Audit::log($actor->tenantId, $actor->userId, 'qr.cards_viewed', 'tenant', $actor->tenantId, ['count' => count($rows)]);
        return array_map(static fn(array $r): array => [
            'ref_no' => $r['ref_no'],
            'name' => trim($r['first_name'] . ' ' . ($r['middle_name'] ? mb_substr($r['middle_name'], 0, 1) . '. ' : '') . $r['last_name'] . ' ' . ($r['suffix'] ?? '')),
            'barangay' => $r['barangay'],
            'qr_payload' => self::QR_PREFIX . self::signedToken($r['credential_id']),
        ], $rows);
    }

    /** Opaque token: credential id (16 bytes) + truncated HMAC (16 bytes), base64url. No personal data. */
    private static function signedToken(string $credentialIdHex): string
    {
        $bin = (string) hex2bin($credentialIdHex);
        $mac = substr(hash_hmac('sha256', $bin, self::qrKey(), true), 0, 16);
        return rtrim(strtr(base64_encode($bin . $mac), '+/', '-_'), '=');
    }

    private static function qrKey(): string
    {
        // Derived from the site's secret salts. Rotating the salts invalidates every QR code (re-issue them).
        return hash_hmac('sha256', 'sweetsen-qr-v1', wp_salt('secure_auth'), true);
    }
}
