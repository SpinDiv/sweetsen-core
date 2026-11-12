<?php

declare(strict_types=1);

namespace SweetSen\Domain;

use SweetSen\Audit\Audit;
use SweetSen\Portal\GoogleSignIn;
use SweetSen\Support\Db;
use SweetSen\Support\Mail;

/**
 * Emails to seniors, sent after the database change has committed (hooked to do_action from the services).
 * Each send is audited with ids and outcome only, never the address. Demo addresses are skipped silently.
 */
final class Notify
{
    public static function register(): void
    {
        add_action('sweetsen_registration_received', [self::class, 'registrationReceived'], 10, 2);
        add_action('sweetsen_senior_account_created', [self::class, 'seniorWelcome'], 10, 3);
        add_action('sweetsen_sponsor_received', [self::class, 'sponsorReceived'], 10, 2);
        add_action('sweetsen_sponsor_account_created', [self::class, 'sponsorWelcome'], 10, 3);
    }

    private static function tenantName(string $tenantId): string
    {
        return (string) (Db::value(Db::prepare('SELECT name FROM ' . Db::t('tenants') . ' WHERE id = UNHEX(%s)', $tenantId)) ?: 'the LGU');
    }

    /** "We received your sponsor registration": to the contact person on the website form. */
    public static function sponsorReceived(string $tenantId, string $requestId): bool
    {
        $r = Db::row(Db::prepare(
            'SELECT ref_no, org_name, contact_name, contact_email FROM ' . Db::t('registration_requests') . '
             WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND kind = %s', $requestId, $tenantId, 'sponsor'
        ));
        $to = (string) ($r['contact_email'] ?? '');
        if ($r === null || !is_email($to) || Mail::isDemo($to)) {
            return false;
        }
        $lgu = esc_html(self::tenantName($tenantId));
        $body = Mail::p('Hello ' . esc_html((string) $r['contact_name']) . ',')
            . Mail::p('Thank you for offering to support senior citizens through SweetSen. We have the request for <strong>' . esc_html((string) $r['org_name']) . '</strong>, and ' . $lgu . ' will review it.')
            . Mail::fact('Your reference number', (string) $r['ref_no'])
            . '<p style="margin:8px 0 12px;font-size:19px;font-weight:700;color:#22272E">What happens next</p>'
            . Mail::steps([
                'The LGU reviews your organization&#039;s details. They may call or email you.',
                'Once approved, we email you a link to set your SweetSen password.',
                'Sign in to your sponsor dashboard and make your first pledge to a program.',
            ])
            . Mail::p('If you did not send this request, you can ignore this email.', true);
        $text = "Hello {$r['contact_name']},\n\nThank you for offering to support senior citizens through SweetSen. We have the request for {$r['org_name']}. Reference: {$r['ref_no']}.\n\n"
            . "What happens next:\n1. The LGU reviews your organization's details.\n2. Once approved, we email you a link to set your SweetSen password.\n3. Sign in to your sponsor dashboard and make your first pledge.\n\n"
            . "SweetSen demo with synthetic records. Not an official government message.";
        $sent = Mail::send($to, 'We received your SweetSen sponsor registration (' . $r['ref_no'] . ')',
            Mail::render('We received your sponsor registration', $body, 'Your reference number is ' . $r['ref_no'] . '. Here is what happens next.'), $text);
        Audit::log($tenantId, 0, $sent ? 'email.sponsor_received' : 'email.failed', 'registration_request', $requestId, ['email' => 'sponsor_received']);
        return $sent;
    }

    /** "Welcome to SweetSen" for a sponsor: set-password link for the login created when the LGU approved them. */
    public static function sponsorWelcome(string $tenantId, int $userId, string $organizationId): bool
    {
        $user = get_userdata($userId);
        if (!$user || !is_email($user->user_email) || Mail::isDemo($user->user_email)) {
            return false;
        }
        $o = Db::row(Db::prepare('SELECT name, contact_name FROM ' . Db::t('organizations') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)', $organizationId, $tenantId));
        $key = get_password_reset_key($user);
        if ($o === null || is_wp_error($key)) {
            Audit::log($tenantId, 0, 'email.failed', 'organization', $organizationId, ['email' => 'sponsor_welcome']);
            return false;
        }
        $link = add_query_arg(['action' => 'rp', 'key' => $key, 'login' => rawurlencode($user->user_login)], wp_login_url());
        $lgu = esc_html(self::tenantName($tenantId));
        $body = Mail::p('Hello ' . esc_html((string) $o['contact_name']) . ',')
            . Mail::p('Good news: ' . $lgu . ' has approved <strong>' . esc_html((string) $o['name']) . '</strong> as a SweetSen sponsor. Set a password to open your sponsor dashboard.')
            . Mail::button('Set my password', $link)
            . Mail::p('This link works for 24 hours. If it has expired, open the sign-in page and choose <strong>Lost your password?</strong>', true)
            . Mail::fact('Your username', $user->user_login)
            . (GoogleSignIn::available() ? Mail::p('If you use Google with this email address, you can also choose <strong>Sign in with Google</strong> instead of a password.') : '')
            . '<p style="margin:8px 0 12px;font-size:19px;font-weight:700;color:#22272E">On your dashboard</p>'
            . Mail::steps([
                'See the programs open for support and pledge cash or goods.',
                'Follow each pledge from LGU review to delivery.',
                'See how many seniors your programs reached. Seniors&#039; names and details are never shown.',
            ])
            . Mail::p('Never share your password. SweetSen staff will never ask for it.', true);
        $text = "Hello {$o['contact_name']},\n\n{$o['name']} is now a SweetSen sponsor. Set a password here (works for 24 hours):\n{$link}\n\nYour username: {$user->user_login}\n\n"
            . "On your dashboard: pledge to programs, follow each pledge, and see how many seniors your programs reached.\n\n"
            . "SweetSen demo with synthetic records. Not an official government message.";
        $sent = Mail::send($user->user_email, 'Welcome to SweetSen: set up your sponsor sign-in',
            Mail::render('Welcome to SweetSen', $body, 'Your organization is approved. Set a password to sign in.'), $text);
        Audit::log($tenantId, 0, $sent ? 'email.sponsor_welcome' : 'email.failed', 'organization', $organizationId, ['email' => 'sponsor_welcome']);
        return $sent;
    }

    /** "We received your registration": to the email given on the website form. */
    public static function registrationReceived(string $tenantId, string $requestId): bool
    {
        $r = Db::row(Db::prepare(
            'SELECT ref_no, first_name, last_name, filed_by, filer_name, contact_email FROM ' . Db::t('registration_requests') . '
             WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s) AND kind = %s', $requestId, $tenantId, 'senior'
        ));
        $to = (string) ($r['contact_email'] ?? '');
        if ($r === null || !is_email($to) || Mail::isDemo($to)) {
            return false;
        }
        $family = $r['filed_by'] === 'family' && (string) $r['filer_name'] !== '';
        $hello = esc_html($family ? (string) $r['filer_name'] : (string) $r['first_name']);
        $senior = esc_html(trim($r['first_name'] . ' ' . $r['last_name']));
        $body = Mail::p('Hello ' . $hello . ',')
            . Mail::p($family
                ? 'Thank you for registering <strong>' . $senior . '</strong> with SweetSen. We have your request and the senior citizens&#039; office will review it.'
                : 'Thank you for registering with SweetSen. We have your request and the senior citizens&#039; office will review it.')
            . Mail::fact('Your reference number', (string) $r['ref_no'])
            . Mail::p('Keep this number. The office may ask for it.', true)
            . '<p style="margin:8px 0 12px;font-size:19px;font-weight:700;color:#22272E">What happens next</p>'
            . Mail::steps([
                'The office checks the details in your request.',
                'When the record is ready, we email a link to set up a SweetSen sign-in.',
                'Visit the senior citizens&#039; office (OSCA) with a valid ID so the record can be verified. After that, the office can approve benefits.',
            ])
            . Mail::p('If you did not send this request, you can ignore this email.', true);
        $text = "Hello {$r['first_name']},\n\nThank you for registering with SweetSen. Your reference number is {$r['ref_no']}.\n\n"
            . "What happens next:\n1. The office checks the details in your request.\n2. When the record is ready, we email a link to set up a SweetSen sign-in.\n"
            . "3. Visit the senior citizens' office (OSCA) with a valid ID so the record can be verified.\n\nIf you did not send this request, you can ignore this email.\n\n"
            . "SweetSen demo with synthetic records. Not an official government message.";
        $sent = Mail::send($to, 'We received your SweetSen registration (' . $r['ref_no'] . ')',
            Mail::render('We received your registration', $body, 'Your reference number is ' . $r['ref_no'] . '. Here is what happens next.'), $text);
        Audit::log($tenantId, 0, $sent ? 'email.registration_received' : 'email.failed', 'registration_request', $requestId, ['email' => 'registration_received']);
        return $sent;
    }

    /** "Welcome to SweetSen": set-password link for the login just created for a senior. */
    public static function seniorWelcome(string $tenantId, int $userId, string $beneficiaryId): bool
    {
        $user = get_userdata($userId);
        if (!$user || !is_email($user->user_email) || Mail::isDemo($user->user_email)) {
            return false;
        }
        $b = Db::row(Db::prepare('SELECT ref_no, first_name FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s) AND tenant_id = UNHEX(%s)', $beneficiaryId, $tenantId));
        $key = get_password_reset_key($user);
        if ($b === null || is_wp_error($key)) {
            Audit::log($tenantId, 0, 'email.failed', 'beneficiary', $beneficiaryId, ['email' => 'senior_welcome']);
            return false;
        }
        $link = add_query_arg(['action' => 'rp', 'key' => $key, 'login' => rawurlencode($user->user_login)], wp_login_url());
        $google = GoogleSignIn::available();
        $body = Mail::p('Hello ' . esc_html((string) $b['first_name']) . ',')
            . Mail::p('Your SweetSen record is ready. Set a password to sign in. You can then apply for programs and follow your applications.')
            . Mail::button('Set my password', $link)
            . Mail::p('This link works for 24 hours. If it has expired, open the sign-in page and choose <strong>Lost your password?</strong>', true)
            . Mail::fact('Your username', $user->user_login)
            . Mail::p('This is also your SweetSen reference number.', true)
            . ($google ? Mail::p('If you use Google with this email address, you can also choose <strong>Sign in with Google</strong> instead of a password.') : '')
            . '<p style="margin:8px 0 12px;font-size:19px;font-weight:700;color:#22272E">One more step</p>'
            . Mail::p('Visit the senior citizens&#039; office (OSCA) with a valid ID so your record can be verified. You can apply for programs now, but the office can only approve them after this check.')
            . Mail::p('Never share your password. SweetSen staff will never ask for it.', true);
        $text = "Hello {$b['first_name']},\n\nYour SweetSen record is ready. Set a password here (works for 24 hours):\n{$link}\n\n"
            . "Your username: {$user->user_login}\n" . ($google ? "If you use Google with this email address, you can also choose 'Sign in with Google'.\n" : '')
            . "\nOne more step: visit the senior citizens' office (OSCA) with a valid ID so your record can be verified.\n\n"
            . "SweetSen demo with synthetic records. Not an official government message.";
        $sent = Mail::send($user->user_email, 'Welcome to SweetSen: set up your sign-in',
            Mail::render('Welcome to SweetSen', $body, 'Your record is ready. Set a password to sign in.'), $text);
        Audit::log($tenantId, 0, $sent ? 'email.senior_welcome' : 'email.failed', 'beneficiary', $beneficiaryId, ['email' => 'senior_welcome']);
        return $sent;
    }
}
