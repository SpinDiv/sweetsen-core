<?php

declare(strict_types=1);

namespace SweetSen\Seed;

use SweetSen\Domain\Programs;
use SweetSen\Roles\Roles;
use SweetSen\Support\Db;
use SweetSen\Tenancy\Actor;

/**
 * Demo tenant, staff accounts and three demo programs. Idempotent.
 * Program names and amounts are placeholders for the demo, NOT official Makati benefits.
 * New account passwords are written once to the private folder above the web root.
 */
final class DemoSeeder
{
    public const TENANT_SLUG = 'makati';

    public const STAFF = [
        ['demo_lgu_admin', 'lgu.admin@sweetsen-demo.example', 'Liza Admin (Demo)', Roles::LGU_ADMIN],
        ['demo_officer', 'program.officer@sweetsen-demo.example', 'Paolo Officer (Demo)', Roles::OFFICER],
        ['demo_verifier', 'verifier@sweetsen-demo.example', 'Vina Verifier (Demo)', Roles::VERIFIER],
        ['demo_sponsor', 'sponsor@sweetsen-demo.example', 'Sam Sponsor (Demo)', Roles::ORG_USER],
    ];

    public const SPONSOR_ORG = 'Bayanihan Foods Demo Inc.';

    /** @return list<string> */
    public static function run(): array
    {
        $log = [];
        Roles::install();
        $log[] = 'Roles installed: ' . implode(', ', array_keys(Roles::CAPS));

        $tenant = Accounts::tenant(self::TENANT_SLUG, 'Makati City (demo tenant)');
        $log[] = "Tenant makati: {$tenant}";

        $created = [];
        $ids = [];
        foreach (self::STAFF as [$login, $email, $name, $role]) {
            [$uid, $pw] = Accounts::user($login, $email, $name, $role);
            $ids[$login] = $uid;
            if ($pw !== null) {
                $created[$login] = $pw;
            }
        }
        $org = Accounts::organization($tenant, self::SPONSOR_ORG, 'sponsor', $ids['demo_lgu_admin']);
        foreach (self::STAFF as [$login, , , $role]) {
            Accounts::membership($tenant, $ids[$login], $role, $role === Roles::ORG_USER ? $org : null);
        }
        $log[] = 'Staff accounts and memberships ready: ' . implode(', ', array_keys($ids));

        $admin = Actor::forUser($ids['demo_lgu_admin'], self::TENANT_SLUG);
        $year = (int) Db::todayManila()->format('Y');
        $programs = [
            ['code' => 'BDAY-CASH', 'name' => 'Birthday Cash Gift (demo)', 'category' => 'birthday', 'benefit_type' => 'cash',
             'unit_amount' => '1000.00', 'claim_period' => 'yearly', 'funding_source' => 'lgu'],
            ['code' => 'GROCERY-PACK', 'name' => 'Monthly Grocery Pack (demo)', 'category' => 'food', 'benefit_type' => 'goods',
             'unit_quantity' => 1, 'unit_label' => 'pack', 'claim_period' => 'monthly', 'funding_source' => 'mixed'],
            ['code' => 'MED-ASSIST', 'name' => 'Medicine Assistance (demo)', 'category' => 'medical', 'benefit_type' => 'cash',
             'unit_amount' => '2500.00', 'claim_period' => 'once', 'funding_source' => 'lgu'],
        ];
        foreach ($programs as $p) {
            if (Programs::byCode($tenant, $p['code']) !== null) {
                continue;
            }
            Programs::create($admin, $p + [
                'description' => 'Demo program for the SweetSen MVP. Name, amount and rules are placeholders, not an official Makati benefit.',
                'starts_on' => ($year) . '-01-01',
                'ends_on' => ($year + 1) . '-12-31',
                'status' => 'published',
            ]);
            $log[] = "Program {$p['code']} created";
        }

        if ($created !== []) {
            $file = self::writeCredentials($created);
            $log[] = 'New account passwords written to ' . $file . ' (outside the web root).';
        }
        return $log;
    }

    /** @param array<string, string> $creds */
    public static function writeCredentials(array $creds, string $name = 'demo-credentials.txt'): string
    {
        if (!is_dir(SWEETSEN_PRIVATE_DIR)) {
            wp_mkdir_p(SWEETSEN_PRIVATE_DIR);
            @chmod(SWEETSEN_PRIVATE_DIR, 0700);
        }
        $file = SWEETSEN_PRIVATE_DIR . $name;
        $lines = '';
        foreach ($creds as $login => $pw) {
            $lines .= "{$login}\t{$pw}\n";
        }
        file_put_contents($file, "# SweetSen demo accounts, created " . gmdate('c') . " (synthetic data only)\n" . $lines, FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
        return $file;
    }
}
