<?php

declare(strict_types=1);

namespace SweetSen\Cli;

use SweetSen\Domain\Importer;
use SweetSen\Migrations\Runner;
use SweetSen\Seed\DemoFlow;
use SweetSen\Seed\DemoSeeder;
use SweetSen\Support\Db;
use SweetSen\Support\DomainError;
use SweetSen\Tenancy\Actor;
use WP_CLI;

/**
 * Sweet Sen maintenance commands.
 */
final class Command
{
    /**
     * Apply pending database migrations.
     */
    public function migrate(): void
    {
        foreach (Runner::migrate() as $line) {
            WP_CLI::log($line);
        }
        WP_CLI::success('Migrations complete.');
    }

    /**
     * Install roles and seed the demo tenant, staff accounts and programs. Safe to re-run.
     */
    public function seed(): void
    {
        $this->run(static fn() => DemoSeeder::run());
    }

    /**
     * Import seniors from a CSV file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the CSV file on the server.
     *
     * [--as=<login>]
     * : Account that performs the import. Default: demo_lgu_admin.
     *
     * [--tenant=<slug>]
     * : Tenant slug. Default: makati.
     *
     * [--show=<outcome>]
     * : Print row details for this outcome (created, flagged, error, all). Default: flagged.
     */
    public function import(array $args, array $assoc): void
    {
        $this->run(static function () use ($args, $assoc): array {
            $user = get_user_by('login', $assoc['as'] ?? 'demo_lgu_admin');
            if (!$user) {
                throw new DomainError('no_user', 'Unknown --as account.');
            }
            $actor = Actor::forUser((int) $user->ID, $assoc['tenant'] ?? DemoSeeder::TENANT_SLUG);
            $r = Importer::importCsv($actor, $args[0]);
            $out = ["Job {$r['job_id']}: {$r['total']} rows, {$r['created']} created, {$r['flagged']} flagged, {$r['errors']} errors"];
            $show = $assoc['show'] ?? 'flagged';
            foreach ($r['rows'] as $row) {
                if ($show === 'all' || $row['outcome'] === $show) {
                    $out[] = '  row ' . $row['row_no'] . ' (' . ($row['source_ref'] ?? '-') . '): ' . $row['outcome']
                        . (isset($row['match_ref']) ? " -> {$row['match_ref']} [{$row['reason']}]" : '')
                        . (isset($row['ref_no']) ? " as {$row['ref_no']}" : '')
                        . (isset($row['message']) ? " ({$row['message']})" : '');
                }
            }
            return $out;
        });
    }

    /**
     * Put the demo tenant into a mid-story state using the real services.
     *
     * ## OPTIONS
     *
     * [--verify=<n>]
     * : Seniors to verify, issue QR and approve. Default: 12.
     *
     * [--release=<n>]
     * : Of those, how many to release already. Default: 4.
     *
     * @subcommand demo-flow
     */
    public function demoFlow(array $args, array $assoc): void
    {
        $this->run(static fn() => DemoFlow::run((int) ($assoc['verify'] ?? 12), (int) ($assoc['release'] ?? 4)));
    }

    /**
     * Re-issue every active QR code in a tenant (e.g. after the token format or site salts change).
     * Old codes stop working immediately.
     *
     * ## OPTIONS
     *
     * [--as=<login>]
     * : Staff account with QR permission. Default: demo_officer.
     *
     * [--tenant=<slug>]
     * : Tenant slug. Default: makati.
     *
     * @subcommand reissue-qr
     */
    public function reissueQr(array $args, array $assoc): void
    {
        $this->run(static function () use ($assoc): array {
            $user = get_user_by('login', $assoc['as'] ?? 'demo_officer');
            if (!$user) {
                throw new DomainError('no_user', 'Unknown --as account.');
            }
            $actor = Actor::forUser((int) $user->ID, $assoc['tenant'] ?? DemoSeeder::TENANT_SLUG);
            $ids = Db::rows(Db::prepare(
                'SELECT DISTINCT LOWER(HEX(beneficiary_id)) AS id FROM ' . Db::t('qr_credentials') . ' WHERE tenant_id = UNHEX(%s) AND status = %s',
                $actor->tenantId, 'active'
            ));
            foreach ($ids as $r) {
                \SweetSen\Domain\Beneficiaries::issueQr($actor, $r['id']);
            }
            $old = SWEETSEN_PRIVATE_DIR . 'demo-qr-payloads.tsv';
            if (is_file($old)) {
                unlink($old);
            }
            return [count($ids) . ' QR codes re-issued. Print new cards from /sweetsen/qr-cards/.'];
        });
    }

    /**
     * Run the guarantee checks against the live database in throwaway tenants, then clean up.
     */
    public function selftest(): void
    {
        $results = SelfTest::run();
        $failed = 0;
        foreach ($results as [$ok, $name, $detail]) {
            WP_CLI::log(($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " — {$detail}" : ''));
            $failed += $ok ? 0 : 1;
        }
        $failed === 0
            ? WP_CLI::success(count($results) . ' checks passed.')
            : WP_CLI::error("{$failed} of " . count($results) . ' checks failed.');
    }

    /**
     * Row counts per Sweet Sen table.
     */
    public function status(): void
    {
        $rows = [];
        foreach (['tenants', 'memberships', 'organizations', 'beneficiaries', 'qr_credentials', 'programs', 'applications',
                     'application_events', 'entitlements', 'fulfillments', 'redemption_attempts', 'pledges',
                     'donations_received', 'donation_allocations', 'import_jobs', 'import_rows', 'audit_log', 'migrations'] as $t) {
            $rows[] = ['table' => 'ss_' . $t, 'rows' => (int) Db::value('SELECT COUNT(*) FROM ' . Db::t($t))];
        }
        WP_CLI\Utils\format_items('table', $rows, ['table', 'rows']);
    }

    /** @param callable(): list<string> $fn */
    private function run(callable $fn): void
    {
        try {
            foreach ($fn() as $line) {
                WP_CLI::log($line);
            }
            WP_CLI::success('Done.');
        } catch (DomainError $e) {
            WP_CLI::error("[{$e->reason}] " . $e->getMessage());
        }
    }
}
