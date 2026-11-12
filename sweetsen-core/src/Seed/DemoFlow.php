<?php

declare(strict_types=1);

namespace SweetSen\Seed;

use SweetSen\Domain\Applications;
use SweetSen\Domain\Beneficiaries;
use SweetSen\Domain\Programs;
use SweetSen\Domain\Release;
use SweetSen\Domain\Sponsors;
use SweetSen\Roles\Roles;
use SweetSen\Support\Db;
use SweetSen\Support\Uuid;
use SweetSen\Tenancy\Actor;

/**
 * Puts the demo tenant into a "mid-story" state using the real services (no shortcuts):
 * some seniors verified with QR codes and approved grocery packs, a few already released,
 * the rest waiting so the partner can watch a live release and the rejected re-scan.
 */
final class DemoFlow
{
    /** @return list<string> */
    public static function run(int $verifyCount = 12, int $releaseCount = 4, int $seniorLogins = 3): array
    {
        $tenant = DemoSeeder::TENANT_SLUG;
        $admin = Actor::forUser((int) get_user_by('login', 'demo_lgu_admin')->ID, $tenant);
        $officer = Actor::forUser((int) get_user_by('login', 'demo_officer')->ID, $tenant);
        $verifier = Actor::forUser((int) get_user_by('login', 'demo_verifier')->ID, $tenant);
        $sponsor = Actor::forUser((int) get_user_by('login', 'demo_sponsor')->ID, $tenant);
        $log = [];

        $grocery = Programs::byCode($officer->tenantId, 'GROCERY-PACK');
        $bday = Programs::byCode($officer->tenantId, 'BDAY-CASH');

        $pending = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('id') . ', ref_no, first_name, last_name FROM ' . Db::t('beneficiaries') . '
             WHERE tenant_id = UNHEX(%s) AND verification_status = %s ORDER BY ref_no LIMIT %d',
            $officer->tenantId, 'unverified', $verifyCount
        ));
        $qrLines = [];
        $credentials = [];
        $logins = [];
        $entitlements = [];
        foreach ($pending as $i => $b) {
            Beneficiaries::verify($officer, $b['id'], ['id_type' => 'osca', 'birthdate_matches' => true, 'photo_matches' => true, 'address_confirmed' => true]);
            $qr = Beneficiaries::issueQr($officer, $b['id']);
            $credentials[$b['id']] = $qr['credential_id'];
            $qrLines[] = "{$b['ref_no']}\t{$b['first_name']} {$b['last_name']}\t{$qr['qr_payload']}";

            $app = Applications::submit($officer, $b['id'], $grocery['id']);
            Applications::transition($officer, $app, 'under_review');
            $entitlements[$b['id']] = Applications::transition($officer, $app, 'approved', 'Demo approval');

            if ($i < $seniorLogins) {
                $login = 'senior_' . strtolower(str_replace('-', '', $b['ref_no']));
                [$uid, $pw] = Accounts::user($login, $login . '@sweetsen-demo.example', $b['first_name'] . ' ' . $b['last_name'], Roles::SENIOR);
                Accounts::membership($officer->tenantId, $uid, Roles::SENIOR);
                Db::exec(Db::prepare('UPDATE ' . Db::t('beneficiaries') . ' SET user_id = %d WHERE id = UNHEX(%s)', $uid, $b['id']));
                if ($pw !== null) {
                    $logins[$login] = $pw;
                }
                // Each senior with a login also has a pending birthday application to track.
                Applications::submit(Actor::forUser($uid, $tenant), $b['id'], $bday['id']);
            }
        }
        $log[] = count($pending) . ' seniors verified, QR issued, grocery pack approved';

        $released = 0;
        foreach (array_slice($entitlements, 0, $releaseCount, true) as $benId => $entId) {
            Release::release($verifier, $entId, Uuid::v4String(), 'qr', $credentials[$benId]);
            $released++;
        }
        $log[] = "{$released} grocery packs released by the verifier; " . (count($entitlements) - $released) . ' waiting for the live demo';

        // Sponsor story: pledge -> LGU approval -> receipt -> allocation.
        $pledge = Sponsors::submitPledge($sponsor, ['kind' => 'in_kind', 'item_description' => 'Grocery packs (rice, canned goods, noodles)', 'quantity' => 200, 'program_id' => $grocery['id']]);
        Sponsors::reviewPledge($admin, $pledge, true, 'Demo approval');
        $donation = Sponsors::recordReceipt($admin, (string) $sponsor->organizationId, $pledge, null, 150, 'Grocery packs, first delivery');
        Sponsors::allocate($admin, $donation, $grocery['id'], null, 150);
        $cash = Sponsors::submitPledge($sponsor, ['kind' => 'cash', 'amount' => '50000.00', 'program_id' => $bday['id']]);
        $log[] = 'Sponsor: 200 grocery packs pledged and approved, 150 received and allocated; PHP 50,000 cash pledge waiting for LGU review';

        if ($qrLines !== []) {
            $file = SWEETSEN_PRIVATE_DIR . 'demo-qr-payloads.tsv';
            file_put_contents($file, implode("\n", $qrLines) . "\n", FILE_APPEND | LOCK_EX);
            @chmod($file, 0600);
            $log[] = 'QR payloads (shown once) written to ' . $file;
        }
        if ($logins !== []) {
            $log[] = 'Senior logins written to ' . DemoSeeder::writeCredentials($logins);
        }
        unset($cash);
        return $log;
    }
}
