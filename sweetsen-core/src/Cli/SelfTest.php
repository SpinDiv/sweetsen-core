<?php

declare(strict_types=1);

namespace SweetSen\Cli;

use SweetSen\Domain\Applications;
use SweetSen\Domain\Beneficiaries;
use SweetSen\Domain\Importer;
use SweetSen\Domain\Listings;
use SweetSen\Domain\Programs;
use SweetSen\Domain\Registrations;
use SweetSen\Domain\Release;
use SweetSen\Domain\SelfService;
use SweetSen\Domain\SponsorView;
use SweetSen\Domain\Sponsors;
use SweetSen\Roles\Roles;
use SweetSen\Seed\Accounts;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * Proves the Architecture Pack guarantees against the real MariaDB, in two throwaway tenants,
 * then removes everything it created. Nothing here touches the makati demo tenant.
 */
final class SelfTest
{
    /** @var list<array{0:bool,1:string,2:string}> */
    private static array $results = [];

    /** @return list<array{0:bool,1:string,2:string}> */
    public static function run(): array
    {
        self::$results = [];
        $suffix = substr(Uuid::v7(), -6);
        $tenantA = Accounts::tenant("selftest-a-{$suffix}", 'Selftest A');
        $tenantB = Accounts::tenant("selftest-b-{$suffix}", 'Selftest B');
        $users = [];
        $csv = null;
        try {
            $mk = static function (string $who, string $tenant, string $role) use (&$users, $suffix): Actor {
                [$uid] = Accounts::user("sstest_{$who}_{$suffix}", "sstest.{$who}.{$suffix}@sweetsen-demo.example", "Selftest {$who}", $role);
                $users[] = $uid;
                Accounts::membership($tenant, $uid, $role);
                return Actor::forUser($uid);
            };
            $admin = $mk('admin', $tenantA, Roles::LGU_ADMIN);
            $officer = $mk('officer', $tenantA, Roles::OFFICER);
            $verifier = $mk('verifier', $tenantA, Roles::VERIFIER);
            $verifierB = $mk('verifierb', $tenantB, Roles::VERIFIER);

            // 1. Site administrator has no data access.
            self::expect('WordPress admin without membership cannot act', 'no_membership', static fn() => Actor::forUser(1, "selftest-a-{$suffix}"));

            // 2. Import, duplicates flagged, records unverified.
            $csv = wp_tempnam('sstest.csv');
            file_put_contents($csv, "ref_no,last_name,first_name,middle_name,suffix,sex,birthdate,barangay,address_line,contact_phone,osca_id\n"
                . "T-1,Dela Cruz,Rosario,Santos,,F,1950-04-12,Poblacion,,,T-OSCA-1\n"
                . "T-2,Reyes,Ernesto,Garcia,,M,1948-09-30,Bel-Air,,,T-OSCA-2\n"
                . "T-3,Bautista,Corazon,Lim,,F,1955-01-20,La Paz,,,\n"
                . "T-4,DELA CRUZ,Ma. Rosario,S.,,F,1950-04-13,Tejeros,,,\n"
                . "T-5,Young,Juan,,,M,1990-01-01,Poblacion,,,\n");
            $imp = Importer::importCsv($admin, $csv, 'selftest.csv');
            self::check('Import creates 3, flags 1 near-duplicate, rejects 1 under-60', $imp['created'] === 3 && $imp['flagged'] === 1 && $imp['errors'] === 1,
                "created {$imp['created']}, flagged {$imp['flagged']}, errors {$imp['errors']}");
            $unverified = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND verification_status = %s', $tenantA, 'unverified'));
            self::check('Imported records start unverified', $unverified === 3, "{$unverified} unverified");
            $again = Importer::importCsv($admin, $csv, 'selftest-again.csv');
            self::check('Re-importing the same file creates nothing', $again['created'] === 0, "created {$again['created']}, flagged {$again['flagged']}");

            $bens = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', source_ref FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) ORDER BY source_ref', $tenantA));
            [$b1, $b2, $b3] = array_column($bens, 'id');

            // 3. QR and verification rules.
            self::expect('No QR for an unverified senior', 'beneficiary_not_verified', static fn() => Beneficiaries::issueQr($officer, $b1));
            $chk = ['id_type' => 'osca', 'birthdate_matches' => true, 'photo_matches' => true, 'address_confirmed' => true];
            self::expect('Verifier cannot verify seniors', 'forbidden', static fn() => Beneficiaries::verify($verifier, $b1, $chk));
            self::expect('Verifying without the full ID checklist is refused', 'checklist_incomplete', static fn() => Beneficiaries::verify($officer, $b1, array_merge($chk, ['photo_matches' => false])));
            $rev = Listings::seniorReview($officer, $b1);
            self::check('Staff can open a full record before verifying', $rev['status'] === 'unverified' && isset($rev['details']['Birth date']), $rev['ref_no']);
            Beneficiaries::verify($officer, $b1, $chk);
            Beneficiaries::verify($officer, $b2, $chk);
            $vt = Listings::seniorReview($officer, $b1)['verified']['id_type'] ?? '';
            self::check('The record shows which ID was checked', $vt === Beneficiaries::ID_TYPES['osca'], $vt);
            $qr1 = Beneficiaries::issueQr($officer, $b1);
            $qr1b = Beneficiaries::issueQr($officer, $b1, 'stolen');
            $why = (string) Db::value(Db::prepare('SELECT revoke_reason FROM ' . Db::t('qr_credentials') . ' WHERE beneficiary_id = UNHEX(%s) AND status = %s ORDER BY revoked_at DESC LIMIT 1', $b1, 'revoked'));
            self::check('Replacing a QR code records why (lost, stolen, damaged)', $why === 'stolen', $why);
            self::expect('Verifier cannot email QR codes', 'forbidden', static fn() => Beneficiaries::emailQr($verifier, $b1, 'data:image/png;base64,AAAA'));
            self::expect('A QR code is never emailed when there is no usable email on file', 'no_email', static fn() => Beneficiaries::emailQr($officer, $b1, 'data:image/png;base64,AAAA'));
            $active = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('qr_credentials') . ' WHERE beneficiary_id = UNHEX(%s) AND status = %s', $b1, 'active'));
            self::check('Re-issuing a QR leaves exactly one active credential', $active === 1, "{$active} active");
            self::expect('Old QR is rejected after re-issue', 'credential_invalid', static fn() => Release::lookup($verifier, $qr1['qr_payload']));
            $tampered = substr($qr1b['qr_payload'], 0, -2) . (substr($qr1b['qr_payload'], -2) === 'AA' ? 'AB' : 'AA');
            self::expect('An altered QR code is rejected (signature check)', 'credential_invalid', static fn() => Release::lookup($verifier, $tampered));
            self::check('Staff can redisplay the current QR code', Beneficiaries::qrPayload($officer, $b1) === $qr1b['qr_payload'], 'payload matches');
            self::expect('Verifier cannot redisplay QR codes', 'forbidden', static fn() => Beneficiaries::qrPayload($verifier, $b1));
            $qr2 = Beneficiaries::issueQr($officer, $b2);
            self::check('QR payload carries no personal data', !preg_match('/dela|cruz|rosario|1950/i', $qr1b['qr_payload']), $qr1b['qr_payload'] === '' ? '' : 'opaque token only');

            // 4. Applications and claim limit.
            $prog = Programs::create($admin, ['code' => 'T-PACK', 'name' => 'Test pack', 'category' => 'food', 'benefit_type' => 'goods',
                'unit_quantity' => 1, 'unit_label' => 'pack', 'claim_period' => 'monthly', 'starts_on' => '2020-01-01', 'ends_on' => '2099-12-31']);
            $app3 = Applications::submit($officer, $b3, $prog);
            Applications::transition($officer, $app3, 'under_review');
            self::expect('Unverified senior cannot be approved', 'beneficiary_not_verified', static fn() => Applications::transition($officer, $app3, 'approved'));
            self::expect('Verifier cannot approve applications', 'forbidden', static fn() => Applications::transition($verifier, $app3, 'approved'));
            self::expect('Invalid transition is refused', 'invalid_transition', static fn() => Applications::transition($officer, $app3, 'submitted'));

            $app1 = Applications::submit($officer, $b1, $prog);
            Applications::transition($officer, $app1, 'under_review');
            $ent1 = (string) Applications::transition($officer, $app1, 'approved');
            $app1b = Applications::submit($officer, $b1, $prog);
            Applications::transition($officer, $app1b, 'under_review');
            self::expect('Claim limit: second approval in the same period is refused', 'claim_limit_reached', static fn() => Applications::transition($officer, $app1b, 'approved'));
            self::expect('A second open application for the same program is refused', 'already_applied', static fn() => Applications::submit($officer, $b1, $prog));

            // 4b. Senior self-service: own record only.
            $senior = $mk('senior', $tenantA, Roles::SENIOR);
            Db::exec(Db::prepare('UPDATE ' . Db::t('beneficiaries') . ' SET user_id = %d WHERE id = UNHEX(%s)', $senior->userId, $b2));
            $ov = SelfService::overview($senior);
            self::check('Senior sees their own record and current QR code', $ov['me']['id'] === $b2 && $ov['qr'] === $qr2['qr_payload'], $ov['me']['ref_no']);
            self::expect('Senior cannot apply for another senior', 'forbidden', static fn() => Applications::submit($senior, $b1, $prog));
            $sApp = SelfService::apply($senior, $prog);
            self::expect('Senior cannot decide their own application', 'forbidden', static fn() => Applications::transition($senior, $sApp, 'approved'));
            self::expect('Senior cannot withdraw another senior\'s application', 'not_found', static fn() => SelfService::withdraw($senior, $app1b));
            SelfService::withdraw($senior, $sApp);
            $sStatus = Db::value(Db::prepare('SELECT status FROM ' . Db::t('applications') . ' WHERE id = UNHEX(%s)', $sApp));
            self::check('Senior can withdraw their own application', $sStatus === 'withdrawn', (string) $sStatus);

            // 4c. Sponsor dashboard: own organization, aggregates only.
            $orgA = Accounts::organization($tenantA, 'Selftest Sponsor Org', 'sponsor', $admin->userId);
            [$spUid] = Accounts::user("sstest_sponsor_{$suffix}", "sstest.sponsor.{$suffix}@sweetsen-demo.example", 'Selftest sponsor', Roles::ORG_USER);
            $users[] = $spUid;
            Accounts::membership($tenantA, $spUid, Roles::ORG_USER, $orgA);
            $sponsor = Actor::forUser($spUid);

            // Google sign-in: only seniors and sponsors; staff and site admins keep their password; no match = no account.
            $g = static fn(int $uid): ?string => \SweetSen\Portal\GoogleSignIn::refusal(get_user_by('id', $uid) ?: null);
            self::check('Google sign-in allowed for seniors and sponsors', $g($senior->userId) === null && $g($spUid) === null,
                var_export([$g($senior->userId), $g($spUid)], true));
            self::check('Google sign-in refused for LGU staff and site admins', $g($admin->userId) === 'staff' && $g($officer->userId) === 'staff'
                && $g($verifier->userId) === 'staff' && $g(1) === 'staff', var_export([$g($admin->userId), $g($officer->userId), $g($verifier->userId), $g(1)], true));
            self::check('Google sign-in with an unknown email finds no account', \SweetSen\Portal\GoogleSignIn::refusal(null) === 'no_account');
            self::expect('Pledge with a bad amount is refused', 'invalid_pledge', static fn() => SponsorView::pledge($sponsor, ['kind' => 'cash', 'program_id' => $prog, 'amount' => '-5']));
            $pl = SponsorView::pledge($sponsor, ['kind' => 'in_kind', 'program_id' => $prog, 'item_description' => 'Test packs', 'quantity' => '10']);
            self::expect('Sponsor cannot approve their own pledge', 'forbidden', static fn() => Sponsors::reviewPledge($sponsor, $pl, true));
            $dash = SponsorView::dashboard($sponsor);
            $blob = (string) wp_json_encode($dash);
            self::check('Sponsor dashboard shows own pledges and no senior details', count($dash['pledges']) === 1 && $dash['totals']['pledged_items'] === 10
                && !preg_match('/rosario|ernesto|corazon|dela cruz|bautista|1950-04|beneficiary/i', $blob), count($dash['pledges']) . ' pledge, ' . strlen($blob) . ' bytes checked');
            self::expect('Staff without a sponsor role cannot open the sponsor dashboard', 'forbidden', static fn() => SponsorView::dashboard($officer));

            // 4d. Website registration requests: nothing is created until staff approve.
            $ip = "selftest-{$suffix}";
            // Earlier checks send several requests from one test visitor; the per-visitor daily limit is tested on its own in 4e.
            update_option('sweetsen_form_limits_' . $tenantA, ['senior' => ['per_visitor' => 50], 'sponsor' => ['per_visitor' => 50], 'inquiry' => ['per_visitor' => 50]], false);
            $ok = ['demo_ack' => '1', 'elapsed_ms' => 5000, 'website' => ''];
            $lorna = ['first_name' => 'Lorna', 'last_name' => 'Pascual', 'birthdate' => '1952-02-02', 'barangay' => 'Poblacion', 'filed_by' => 'self', 'contact_email' => "sstest.lorna.{$suffix}@sweetsen-demo.example"];
            self::expect('Website form without the demo confirmation is refused', 'invalid_request', static fn() => Registrations::submitSenior($tenantA, ['elapsed_ms' => 5000] + $lorna, $ip));
            self::expect('Website form with the bot trap filled is refused', 'invalid_request', static fn() => Registrations::submitSenior($tenantA, array_merge($ok, $lorna, ['website' => 'spam']), $ip));
            self::expect('Website registration under 60 is refused', 'invalid_request', static fn() => Registrations::submitSenior($tenantA, array_merge($ok, $lorna, ['birthdate' => '1990-01-01']), $ip));
            $regRef = Registrations::submitSenior($tenantA, array_merge($ok, $lorna), $ip);
            $regId = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND ref_no = %s', $tenantA, $regRef));
            $before = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s)', $tenantA));
            self::expect('The same senior cannot be requested twice while waiting', 'already_requested', static fn() => Registrations::submitSenior($tenantA, array_merge($ok, $lorna), $ip));
            self::expect('Verifier cannot create a record from a request', 'forbidden', static fn() => Registrations::approveSenior($verifier, $regId));
            $made = Registrations::approveSenior($officer, $regId);
            $newStatus = Db::value(Db::prepare('SELECT verification_status FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s)', $made['beneficiary_id']));
            $after = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s)', $tenantA));
            self::check('Approving a request creates one unverified senior record', $after === $before + 1 && $newStatus === 'unverified', "{$made['ref_no']} {$newStatus}");
            $login = get_user_by('login', $made['ref_no']);
            if ($login) {
                $users[] = (int) $login->ID;
            }
            $linked = $login ? (int) Db::value(Db::prepare('SELECT user_id FROM ' . Db::t('beneficiaries') . ' WHERE id = UNHEX(%s)', $made['beneficiary_id'])) : 0;
            self::check('A request with an email also gets a senior sign-in linked to the record', $made['account'] === 'created' && $login
                && in_array(Roles::SENIOR, (array) $login->roles, true) && $linked === (int) $login->ID
                && Actor::forUser((int) $login->ID)->role === Roles::SENIOR, "{$made['account']} " . ($login ? $login->user_login : 'no login'));
            self::check('Emails are never sent to demo addresses', \SweetSen\Support\Mail::isDemo((string) $login?->user_email) && !\SweetSen\Domain\Notify::seniorWelcome($tenantA, (int) $login?->ID, $made['beneficiary_id']));
            $taken = Registrations::submitSenior($tenantA, array_merge($ok, ['first_name' => 'Celia', 'last_name' => 'Ramos', 'birthdate' => '1949-03-03', 'barangay' => 'Poblacion',
                'contact_email' => get_userdata($officer->userId)->user_email]), "{$ip}-mail");
            $takenId = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND ref_no = %s', $tenantA, $taken));
            $made2 = Registrations::approveSenior($officer, $takenId);
            self::check('An email already used by another account gets a record but no new sign-in', $made2['account'] === 'email_in_use' && $made2['login'] === null
                && !get_user_by('login', $made2['ref_no']), $made2['account']);
            self::expect('Website registration with a broken email is refused', 'invalid_request', static fn() => Registrations::submitSenior($tenantA,
                array_merge($ok, ['first_name' => 'Nena', 'last_name' => 'Cruz', 'birthdate' => '1948-01-01', 'barangay' => 'Poblacion', 'contact_email' => 'not-an-email']), "{$ip}-mail"));
            $mailHtml = \SweetSen\Support\Mail::render('Test <heading>', \SweetSen\Support\Mail::button('Go', 'https://example.test/?a=1&b=2'));
            self::check('Email template is branded and escapes text', str_contains($mailHtml, '#F2B705') && str_contains($mailHtml, 'Atkinson Hyperlegible')
                && str_contains($mailHtml, 'Test &lt;heading&gt;') && str_contains($mailHtml, 'Not an official government'));
            $dupRef = Registrations::submitSenior($tenantA, array_merge($ok, ['first_name' => 'Rosario', 'last_name' => 'Dela Cruz', 'birthdate' => '1950-04-12', 'barangay' => 'Poblacion']), $ip);
            $dupId = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND ref_no = %s', $tenantA, $dupRef));
            self::expect('A request for an existing senior is caught as a duplicate', 'possible_duplicate', static fn() => Registrations::approveSenior($officer, $dupId));
            $spRef = Registrations::submitSponsor($tenantA, array_merge($ok, ['org_name' => 'Selftest Bakery', 'org_type' => 'company', 'contact_name' => 'Test Contact', 'contact_email' => "sstest.bakery.{$suffix}@sweetsen-demo.example"]), $ip);
            $spId = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND ref_no = %s', $tenantA, $spRef));
            self::check('Sponsor emails are never sent to demo addresses', !\SweetSen\Domain\Notify::sponsorReceived($tenantA, $spId));
            self::expect('Program officer cannot approve a sponsor sign-up', 'forbidden', static fn() => Registrations::approveSponsor($officer, $spId));
            delete_transient('ss_reg_' . substr($ip, 0, 24));
            // 4g. Programs created in the console.
            $today = Db::todayManila();
            $pgIn = ['name' => 'Selftest Vitamins (demo)', 'category' => 'medical', 'benefit_type' => 'goods', 'unit_quantity' => '2', 'unit_label' => 'bottle',
                'claim_period' => 'monthly', 'funding_source' => 'lgu', 'starts_on' => $today->format('Y-m-d'), 'ends_on' => $today->modify('+60 days')->format('Y-m-d')];
            self::expect('Program officer cannot create a program', 'forbidden', static fn() => Programs::draft($officer, $pgIn));
            self::expect('A cash program without an amount is refused', 'invalid_program', static fn() => Programs::draft($admin, array_merge($pgIn, ['benefit_type' => 'cash', 'unit_amount' => '0'])));
            Programs::draft($admin, $pgIn);
            $pid = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('programs') . ' WHERE tenant_id = UNHEX(%s) AND name = %s', $tenantA, $pgIn['name']));
            $pstat = static fn(): string => (string) Db::value(Db::prepare('SELECT status FROM ' . Db::t('programs') . ' WHERE id = UNHEX(%s)', $pid));
            self::check('A new program starts as a hidden draft', $pid !== '' && $pstat() === 'draft', $pstat());
            Programs::update($admin, $pid, array_merge($pgIn, ['unit_quantity' => '3']));
            Programs::publish($admin, $pid);
            self::check('Publishing opens the program to seniors', $pstat() === 'published', $pstat());
            self::expect('After publishing, what seniors receive cannot change', 'program_locked', static fn() => Programs::update($admin, $pid, array_merge($pgIn, ['unit_quantity' => '5'])));
            Programs::update($admin, $pid, array_merge($pgIn, ['unit_quantity' => '3', 'name' => 'Selftest Vitamins renamed (demo)']));
            $nm = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('programs') . ' WHERE id = UNHEX(%s)', $pid));
            self::check('After publishing, the name can still change', $nm === 'Selftest Vitamins renamed (demo)', $nm);
            Programs::close($admin, $pid);
            self::expect('A closed program cannot be edited', 'invalid_transition', static fn() => Programs::update($admin, $pid, $pgIn));
            // 4f. Spreadsheet export of the senior list.
            self::expect('Program officer cannot export the senior list', 'forbidden', static fn() => Listings::seniorsExport($officer));
            self::expect('Verifier cannot export the senior list', 'forbidden', static fn() => Listings::seniorsExport($verifier));
            $export = Listings::seniorsExport($admin);
            $active = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('beneficiaries') . ' WHERE tenant_id = UNHEX(%s) AND status = %s', $tenantA, 'active'));
            $logged = (string) Db::value(Db::prepare('SELECT meta_json FROM ' . Db::t('audit_log') . ' WHERE tenant_id = UNHEX(%s) AND action = %s ORDER BY created_at DESC LIMIT 1', $tenantA, 'beneficiary.exported'));
            self::check('LGU admin export has every senior, and the audit entry has no names',
                count($export) === $active && count($export[0] ?? []) === count(Listings::EXPORT_COLUMNS) && str_contains($logged, '"rows":' . $active) && !str_contains($logged, (string) ($export[0][1] ?? 'x')),
                count($export) . ' rows, ' . count(Listings::EXPORT_COLUMNS) . ' columns');
            // 4e. Contact page messages.
            $msg = array_merge($ok, ['contact_name' => 'Test Visitor', 'topic' => 'programs', 'message' => 'When is the next grocery pack release?']);
            self::expect('A message with no email or mobile number is refused', 'invalid_request', static fn() => Registrations::submitInquiry($tenantA, $msg, $ip));
            $msgRef = Registrations::submitInquiry($tenantA, $msg + ['contact_email' => "sstest.visitor.{$suffix}@sweetsen-demo.example"], $ip);
            $msgId = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('registration_requests') . ' WHERE tenant_id = UNHEX(%s) AND ref_no = %s AND kind = %s', $tenantA, $msgRef, 'inquiry'));
            self::check('A Contact page message lands in the console queue', $msgId !== '' && str_starts_with($msgRef, 'MSG-'), $msgRef);
            self::expect('Verifier cannot mark a message answered', 'forbidden', static fn() => Registrations::answer($verifier, $msgId, 'Emailed'));
            Registrations::answer($officer, $msgId, 'Emailed the release schedule');
            self::expect('A message cannot be answered twice', 'invalid_transition', static fn() => Registrations::answer($admin, $msgId, 'Again'));
            delete_transient('ss_reg_' . substr($ip, 0, 24));

            // 4e. Website form limits (LGU admin settings).
            $FL = \SweetSen\Domain\FormLimits::class;
            $lim = static fn(array $over) => array_map(static fn($c) => $c, array_replace_recursive($FL::get($tenantA), $over));
            self::expect('Program officer cannot change website form settings', 'forbidden', static fn() => $FL::save($officer, $lim([])));
            $FL::save($admin, $lim(['senior' => ['open' => false, 'message' => 'Back in January (demo).']]));
            self::expect('A closed form refuses new requests', 'form_closed', static fn() => Registrations::submitSenior($tenantA,
                array_merge($ok, ['first_name' => 'Oscar', 'last_name' => 'Reyes', 'birthdate' => '1950-05-05', 'barangay' => 'Poblacion']), "fl-{$ip}"));
            self::check('A closed form shows its own message', str_starts_with($FL::state($tenantA, 'senior')['message'], 'Back in January'));
            $FL::save($admin, $lim(['senior' => ['open' => true, 'opens_on' => Db::todayManila()->modify('+3 days')->format('Y-m-d')]]));
            self::check('A form with a future opening date is not open yet', $FL::state($tenantA, 'senior')['reason'] === 'not_yet');
            $FL::save($admin, $lim(['senior' => ['opens_on' => '', 'daily_cap' => max(1, $FL::todayCount($tenantA, 'senior'))]]));
            self::expect('A form that reached its daily limit refuses more', 'daily_limit', static fn() => Registrations::submitSenior($tenantA,
                array_merge($ok, ['first_name' => 'Oscar', 'last_name' => 'Reyes', 'birthdate' => '1950-05-05', 'barangay' => 'Poblacion']), "fl-{$ip}"));
            $FL::save($admin, $lim(['senior' => ['daily_cap' => 100], 'inquiry' => ['per_visitor' => 1]]));
            Registrations::submitInquiry($tenantA, $msg + ['contact_phone' => '09170000001'], "fl-{$ip}");
            self::expect('One visitor cannot send more than the daily limit per form', 'rate_limited', static fn() => Registrations::submitInquiry($tenantA, $msg + ['contact_phone' => '09170000002'], "fl-{$ip}"));
            self::expect('Form settings refuse a closing date before the opening date', 'invalid_request', static fn() => $FL::save($admin, $lim(['sponsor' => ['opens_on' => '2026-12-10', 'closes_on' => '2026-12-01']])));
            delete_option('sweetsen_form_limits_' . $tenantA);
            foreach (['senior', 'inquiry'] as $k) {
                delete_transient('ss_regd_' . $k . '_' . substr("fl-{$ip}", 0, 20) . '_' . Db::todayManila()->format('Ymd'));
            }
            delete_transient('ss_reg_' . substr("fl-{$ip}", 0, 24));

            // 5. Release, duplicate scan, idempotent retry.
            $look = Release::lookup($verifier, $qr1b['qr_payload']);
            self::check('Scan shows minimal identity only', !isset($look['beneficiary']['birthdate']) && str_ends_with($look['beneficiary']['display_name'], '.'), $look['beneficiary']['display_name']);
            $key = Uuid::v4String();
            $r1 = Release::release($verifier, $ent1, $key, 'qr', $look['credential_id']);
            self::check('First release succeeds', !$r1['replayed'], $r1['ref_no']);
            $r1again = Release::release($verifier, $ent1, $key, 'qr', $look['credential_id']);
            self::check('Retried request returns the original release', $r1again['replayed'] && $r1again['fulfillment_id'] === $r1['fulfillment_id'], $r1again['ref_no']);
            self::expect('Second scan is rejected as already released', 'already_released', static fn() => Release::release($verifier, $ent1, Uuid::v4String(), 'qr', $look['credential_id']));
            self::expect('QR of another senior cannot release this benefit', 'credential_mismatch', static fn() => Release::release($verifier, $ent1, Uuid::v4String(), 'qr', Beneficiaries::resolveQr($tenantA, $qr2['qr_payload'])['credential_id']));
            self::expect('Manual release needs a reason', 'reason_required', static fn() => Release::release($verifier, $ent1, Uuid::v4String(), 'manual'));

            // 6. Concurrency: the row lock blocks a second connection, and the unique key refuses a raw duplicate.
            $app2 = Applications::submit($officer, $b2, $prog);
            Applications::transition($officer, $app2, 'under_review');
            $ent2 = (string) Applications::transition($officer, $app2, 'approved');
            $conn2 = self::secondConnection();
            Db::begin();
            Db::row(Db::prepare('SELECT status FROM ' . Db::t('entitlements') . ' WHERE id = UNHEX(%s) FOR UPDATE', $ent2));
            $conn2->query('SET SESSION innodb_lock_wait_timeout = 1');
            $blocked = false;
            try {
                $res = $conn2->query('SELECT status FROM ' . Db::t('entitlements') . " WHERE id = UNHEX('{$ent2}') FOR UPDATE");
                $blocked = $res === false && $conn2->errno === 1205;
            } catch (\mysqli_sql_exception $e) {
                $blocked = $e->getCode() === 1205;
            }
            Db::rollback();
            self::check('A second connection waits on the locked benefit (lock wait timeout)', $blocked, $blocked ? 'blocked as expected' : 'was NOT blocked: ' . $conn2->error);

            $look2 = Release::lookup($verifier, $qr2['qr_payload']);
            Release::release($verifier, $ent2, Uuid::v4String(), 'qr', $look2['credential_id']);
            $dupBlocked = false;
            $raw = 'INSERT INTO ' . Db::t('fulfillments') . " (id, tenant_id, ref_no, entitlement_id, kind, release_seq, method, verifier_user_id, idempotency_key, created_at)
                    VALUES (UNHEX('" . Uuid::v7() . "'), UNHEX('{$tenantA}'), 'RAW-DUP-1', UNHEX('{$ent2}'), 'release', 1, 'qr', {$verifier->userId}, '" . Uuid::v4String() . "', UTC_TIMESTAMP(6))";
            try {
                $dupBlocked = $conn2->query($raw) === false && $conn2->errno === 1062;
            } catch (\mysqli_sql_exception $e) {
                $dupBlocked = $e->getCode() === 1062;
            }
            self::check('Database refuses a raw second release row (unique key)', $dupBlocked, $dupBlocked ? 'duplicate key 1062' : 'raw insert was accepted!');

            // 7. Tenant isolation.
            self::expect('Verifier from another tenant cannot release this benefit', 'not_found', static fn() => Release::release($verifierB, $ent1, Uuid::v4String(), 'manual', null, 'cross-tenant probe'));
            self::expect("Another tenant's QR is invalid here", 'credential_invalid', static fn() => Release::lookup($verifierB, $qr1b['qr_payload']));
            self::expect('Another tenant cannot read this senior', 'not_found', static fn() => Beneficiaries::get(Actor::forUser($verifierB->userId), $b1));

            // 8. Reversal rules.
            self::expect('Verifier cannot reverse', 'forbidden', static fn() => Release::reverse($verifier, $r1['fulfillment_id'], 'test'));
            self::expect('Reversal needs a reason', 'reason_required', static fn() => Release::reverse($officer, $r1['fulfillment_id'], ' '));
            Release::reverse($officer, $r1['fulfillment_id'], 'Selftest: wrong item handed over');
            self::expect('A release cannot be reversed twice', 'not_reversible', static fn() => Release::reverse($officer, $r1['fulfillment_id'], 'again'));
            $r2 = Release::release($verifier, $ent1, Uuid::v4String(), 'qr', $look['credential_id']);
            $ledger = Db::rows(Db::prepare('SELECT kind, release_seq FROM ' . Db::t('fulfillments') . ' WHERE entitlement_id = UNHEX(%s) ORDER BY created_at', $ent1));
            $shape = implode(',', array_map(static fn($l) => $l['kind'] . '#' . $l['release_seq'], $ledger));
            self::check('After reversal the benefit can be released again (cycle 2)', $shape === 'release#1,reversal#1,release#2', $shape . " ({$r2['ref_no']})");

            // 9. Ledger is append-only at the database level (if triggers were installed).
            $guard = Db::value('SELECT status FROM ' . Db::t('migrations') . " WHERE version = '0002_ledger_guards'");
            if ($guard === 'applied') {
                $refused = false;
                try {
                    Db::exec(Db::prepare('UPDATE ' . Db::t('fulfillments') . ' SET note = %s WHERE id = UNHEX(%s)', 'tamper', $r1['fulfillment_id']));
                } catch (\Throwable $e) {
                    $refused = str_contains($e->getMessage(), 'append-only');
                }
                self::check('Database refuses edits to ledger rows (trigger)', $refused, $refused ? 'UPDATE refused' : 'UPDATE was allowed!');
            } else {
                self::check('Ledger triggers installed', false, 'migration 0002 was skipped: ' . (string) $guard);
            }

            // 10. Audit trail exists for privileged actions and carries no names.
            $audits = Db::rows(Db::prepare('SELECT action, meta_json FROM ' . Db::t('audit_log') . ' WHERE tenant_id = UNHEX(%s)', $tenantA));
            $actions = array_unique(array_column($audits, 'action'));
            $needed = ['import.completed', 'beneficiary.verified', 'qr.issued', 'application.approved', 'fulfillment.released', 'fulfillment.reversed'];
            $missing = array_diff($needed, $actions);
            $leak = (bool) preg_grep('/rosario|ernesto|dela cruz|1950-04/i', array_filter(array_column($audits, 'meta_json')));
            self::check('Audit log covers privileged actions without personal data', $missing === [] && !$leak,
                count($audits) . ' entries' . ($missing ? '; missing ' . implode(',', $missing) : '') . ($leak ? '; PERSONAL DATA FOUND' : ''));
        } catch (\Throwable $e) {
            self::$results[] = [false, 'Selftest crashed', get_class($e) . ': ' . $e->getMessage()];
        } finally {
            if ($csv && is_file($csv)) {
                unlink($csv);
            }
            self::teardown([$tenantA, $tenantB], $users);
        }
        return self::$results;
    }

    private static function expect(string $name, string $reason, callable $fn): void
    {
        try {
            $fn();
            self::$results[] = [false, $name, "expected {$reason}, but it succeeded"];
        } catch (DomainError $e) {
            self::$results[] = [$e->reason === $reason, $name, $e->reason === $reason ? $reason : "expected {$reason}, got {$e->reason}: {$e->getMessage()}"];
        }
    }

    private static function check(string $name, bool $ok, string $detail = ''): void
    {
        self::$results[] = [$ok, $name, $detail];
    }

    private static function secondConnection(): \mysqli
    {
        $w = Db::wpdb();
        [$host, $port, $socket] = $w->parse_db_host(DB_HOST);
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = mysqli_init();
        $c->real_connect($host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : null, $socket ?: null);
        $c->set_charset('utf8mb4');
        return $c;
    }

    /** @param list<string> $tenants @param list<int> $users */
    private static function teardown(array $tenants, array $users): void
    {
        $in = implode(',', array_map(static fn($t) => "UNHEX('{$t}')", $tenants));
        Db::exec('SET @ss_allow_purge = 1');
        try {
            $order = ['redemption_attempts', 'audit_log', 'donation_allocations', 'donations_received', 'pledges'];
            foreach ($order as $t) {
                Db::exec('DELETE FROM ' . Db::t($t) . " WHERE tenant_id IN ({$in})");
            }
            Db::exec('DELETE FROM ' . Db::t('fulfillments') . " WHERE tenant_id IN ({$in}) AND reverses_id IS NOT NULL");
            foreach (['registration_requests', 'fulfillments', 'entitlements', 'application_events', 'applications', 'qr_credentials', 'documents', 'beneficiaries',
                         'import_rows', 'import_jobs', 'programs', 'memberships', 'organizations', 'sequences'] as $t) {
                Db::exec('DELETE FROM ' . Db::t($t) . " WHERE tenant_id IN ({$in})");
            }
            Db::exec('DELETE FROM ' . Db::t('tenants') . " WHERE id IN ({$in})");
        } finally {
            Db::exec('SET @ss_allow_purge = 0');
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($users as $uid) {
            wp_delete_user($uid);
        }
    }
}
