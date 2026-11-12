<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Domain\Listings;
use SweetSen\Domain\Programs;
use SweetSen\Domain\Registrations;
use SweetSen\Domain\Reports;
use SweetSen\Roles\Roles;
use SweetSen\Support\Db;
use SweetSen\Tenancy\Actor;

/**
 * LGU console for administrators and program officers: dashboard, seniors, applications,
 * releases, sponsors, imports and audit. Read views are server-rendered; actions call the REST API.
 */
final class Console
{
    /** key => [label, capability, icon path] */
    private const NAV = [
        'dashboard' => ['Dashboard', 'ss_manage_beneficiaries', 'M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z'],
        'seniors' => ['Seniors', 'ss_manage_beneficiaries', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 12 0v1M17 11a3 3 0 1 0 0-6M22 21v-1a5 5 0 0 0-4-4.9'],
        'programs' => ['Programs', 'ss_manage_programs', 'M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4l-5.2 2.7 1-5.8L3.5 9.2l5.9-.9z'],
        'applications' => ['Applications', 'ss_manage_beneficiaries', 'M9 4h6v3H9zM7 5H5v16h14V5h-2M8 12h8M8 16h5'],
        'requests' => ['Requests', 'ss_manage_beneficiaries', 'M4 4h16v12H8l-4 4zM8 9h8M8 12.5h5'],
        'releases' => ['Releases', 'ss_manage_beneficiaries', 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10'],
        'sponsors' => ['Sponsors', 'ss_review_pledges', 'M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H8a2.5 2.5 0 1 1 0-5c2 0 4 5 4 5zM12 7h4a2.5 2.5 0 1 0 0-5c-2 0-4 5-4 5z'],
        'imports' => ['Imports', 'ss_import', 'M12 15V3M7 8l5-5 5 5M4 15v5h16v-5'],
        'audit' => ['Audit log', 'ss_view_audit', 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01'],
    ];

    public static function render(Actor $a, string $sub): void
    {
        $sub = $sub === '' ? 'dashboard' : $sub;
        if ($sub === 'seniors-export') {
            self::exportSeniors($a);
            return;
        }
        if (!isset(self::NAV[$sub]) || !$a->can(self::NAV[$sub][1])) {
            Layout::message('Page not available', 'This console page does not exist or your role cannot open it.', 404,
                ['label' => 'Back to dashboard', 'href' => Router::url('console')]);
            return;
        }
        $html = match ($sub) {
            'dashboard' => self::dashboard($a),
            'seniors' => self::seniors($a),
            'applications' => self::applications($a),
            'programs' => self::programs($a),
            'requests' => self::requests($a),
            'releases' => self::releases($a),
            'sponsors' => self::sponsors($a),
            'imports' => self::imports($a),
            'audit' => self::audit($a),
        };
        self::shell($a, $sub, self::NAV[$sub][0], $html);
    }

    /* ------------------------------------------------------------------ pages */

    private static function dashboard(Actor $a): string
    {
        $k = Reports::kpis($a);
        $days = Reports::releasesPerDay($a, 14);
        $progress = Reports::programProgress($a);
        $queue = Reports::reviewQueue($a, 6);

        $tiles = self::tile('Seniors registered', number_format($k['seniors']), $k['verified'] . ' verified, ' . ($k['seniors'] - $k['verified']) . ' to verify', Router::url('console/seniors'))
            . self::tile('Waiting for review', (string) $k['waiting'], $k['waiting'] === 1 ? 'application' : 'applications', Router::url('console/applications'), $k['waiting'] > 0)
            . self::tile('Released today', (string) $k['released_today'], $k['released_month'] . ' this month', Router::url('console/releases'))
            . self::tile('Sponsor goods received', number_format($k['received_qty']), 'of ' . number_format($k['pledged_qty']) . ' items pledged', $a->can('ss_review_pledges') ? Router::url('console/sponsors') : null);

        $rows = '';
        foreach ($queue as $q) {
            $rows .= '<tr><td><span class="strong">' . self::e($q['senior']) . '</span><span class="sub ref">' . self::e($q['senior_ref']) . '</span></td><td>'
                . self::e($q['program']) . '</td><td>' . self::status($q['status']) . '</td><td class="num">' . self::e($q['submitted']) . '</td></tr>';
        }
        $queueHtml = $rows === ''
            ? '<p class="empty">No applications are waiting. New ones from seniors and the OSCA desk appear here.</p>'
            : '<table class="tbl"><thead><tr><th>Senior</th><th>Program</th><th>Status</th><th class="num">Submitted</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        $side = $a->can('ss_view_audit') ? self::activityPanel($a) : self::recentReleasesPanel($a);

        return '<section class="tiles" aria-label="Key figures">' . $tiles . '</section>'
            . '<div class="grid-2">'
            . self::panel('Waiting for review', $queueHtml, ['label' => 'All applications', 'href' => Router::url('console/applications')])
            . self::panel('Releases, last 14 days', Charts::dailyBars($days))
            . '</div><div class="grid-2">'
            . self::panel('Program progress', Charts::progress($progress))
            . $side
            . '</div>';
    }

    private static function seniors(Actor $a): string
    {
        $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $status = sanitize_key($_GET['status'] ?? '');
        $page = max(1, (int) ($_GET['pg'] ?? 1));
        $list = Listings::seniors($a, $q, $status, $page);
        $canVerify = $a->can('ss_verify_beneficiary');
        $canQr = $a->can('ss_manage_qr');

        $filters = '';
        foreach (['' => 'All', 'unverified' => 'To verify', 'verified' => 'Verified'] as $key => $label) {
            $url = add_query_arg(array_filter(['q' => $q, 'status' => $key]), Router::url('console/seniors'));
            $filters .= '<a class="chip" href="' . esc_url($url) . '"' . ($status === $key ? ' aria-current="true"' : '') . '>' . self::e($label) . '</a>';
        }
        $rows = '';
        foreach ($list['rows'] as $s) {
            $actions = '';
            $actions .= '<button class="btn sm' . ($s['status'] !== 'verified' && $canVerify ? ' primary' : '') . '" type="button" data-act="senior-review" data-id="' . esc_attr($s['id']) . '"'
                . ' data-can-verify="' . ($canVerify ? '1' : '') . '">' . ($s['status'] !== 'verified' && $canVerify ? 'Review' : 'Details') . '</button>';
            if ($s['status'] === 'verified' && $canQr) {
                $actions .= $s['has_qr']
                    ? '<button class="btn sm" type="button" data-act="qr-show" data-id="' . esc_attr($s['id']) . '" data-name="' . esc_attr($s['name']) . '">Show QR</button>'
                        . '<button class="btn sm" type="button" data-act="qr-replace" data-id="' . esc_attr($s['id']) . '" data-name="' . esc_attr($s['name']) . '" data-ref="' . esc_attr($s['ref_no']) . '">Replace QR</button>'
                    : self::actionBtn('Issue QR', 'console/seniors/' . $s['id'] . '/qr', [], '', 'qr', $s['name']);
            }
            $rows .= '<tr><td><span class="strong">' . self::e($s['name']) . '</span><span class="sub ref">' . self::e($s['ref_no'])
                . ($s['has_login'] ? ', has a login' : '') . '</span></td><td>' . self::e($s['barangay']) . '</td><td class="num">' . (int) $s['age']
                . '</td><td>' . self::status($s['status']) . '</td><td class="acts">' . $actions . '</td></tr>';
        }
        $search = '<form class="search" method="get" role="search"><label for="q" class="vh">Search seniors</label>'
            . '<input id="q" name="q" value="' . esc_attr($q) . '" placeholder="Name or reference number">'
            . ($status ? '<input type="hidden" name="status" value="' . esc_attr($status) . '">' : '')
            . '<button class="btn sm" type="submit">Search</button></form>';
        $table = $rows === '' ? '<p class="empty">No seniors match. Try another name, or clear the filter.</p>'
            : '<table class="tbl"><thead><tr><th>Senior</th><th>Barangay</th><th class="num">Age</th><th>Status</th><th><span class="vh">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return '<div class="toolbar">' . $search . '<div class="chips" role="group" aria-label="Filter by status">' . $filters . '</div></div>'
            . self::panel($list['total'] . ' ' . ($list['total'] === 1 ? 'senior' : 'seniors'), $table . self::pager($list, ['q' => $q, 'status' => $status], 'console/seniors'),
                array_values(array_filter([
                    $a->can('ss_export_beneficiaries') ? ['label' => 'Export to Excel (CSV)', 'href' => wp_nonce_url(
                        add_query_arg(array_filter(['q' => $q, 'status' => $status]), Router::url('console/seniors-export')), 'ss_export_seniors')] : null,
                    $canQr ? ['label' => 'Print QR cards', 'href' => Router::url('qr-cards')] : null,
                ])));
    }

    /** CSV download of the Seniors list as filtered on screen. UTF-8 with BOM so Excel keeps ñ and other characters. */
    private static function exportSeniors(Actor $a): void
    {
        if (!$a->can('ss_export_beneficiaries')) {
            Layout::message('Export not available', 'Only LGU admins can export the senior list.', 403,
                ['label' => 'Back to seniors', 'href' => Router::url('console/seniors')]);
            return;
        }
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'ss_export_seniors')) {
            Layout::message('Link expired', 'Open the Seniors page and press Export again.', 403,
                ['label' => 'Back to seniors', 'href' => Router::url('console/seniors')]);
            return;
        }
        $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $status = sanitize_key($_GET['status'] ?? '');
        $rows = Listings::seniorsExport($a, $q, $status);
        $name = 'sweetsen-seniors-demo-' . ($status === 'verified' ? 'verified-' : ($status === 'unverified' ? 'to-verify-' : ''))
            . Db::todayManila()->format('Y-m-d') . '.csv';
        do_action('litespeed_control_set_nocache', 'senior list export');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, Listings::EXPORT_COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            // A cell starting with = @ or a sign followed by a non-digit could run as a formula in Excel.
            fputcsv($out, array_map(static fn(string $v): string => preg_match('/^(?:[=@\t\r]|[+-](?!\d))/', $v) ? "'" . $v : $v, $row), ',', '"', '');
        }
        fclose($out);
        exit;
    }

    /** Programs the LGU runs: create as draft, publish to seniors, close. */
    private static function programs(Actor $a): string
    {
        $today = Db::todayManila()->format('Y-m-d');
        $rows = '';
        foreach (Programs::forConsole($a) as $p) {
            $what = $p['benefit_type'] === 'cash'
                ? 'PHP ' . number_format((float) $p['unit_amount'], 2)
                : (int) $p['unit_quantity'] . ' ' . $p['unit_label'] . ((int) $p['unit_quantity'] === 1 ? '' : 's');
            $often = match ($p['claim_period']) { 'monthly' => 'every month', 'yearly' => 'once a year', default => 'one time only' };
            $dates = wp_date('M j, Y', strtotime($p['starts_on'] . ' 12:00')) . ' to ' . wp_date('M j, Y', strtotime($p['ends_on'] . ' 12:00'));
            [$label, $cls] = match (true) {
                $p['status'] === 'draft' => ['Draft', 'todo'],
                $p['status'] === 'closed' => ['Closed', 'neg'],
                $p['starts_on'] > $today => ['Opens ' . wp_date('M j', strtotime($p['starts_on'] . ' 12:00')), 'ok'],
                $p['ends_on'] < $today => ['Ended', ''],
                default => ['Open', 'ok'],
            };
            $route = 'console/programs/' . $p['id'];
            $edit = [
                'id' => $p['id'], 'status' => $p['status'], 'name' => $p['name'], 'description' => (string) $p['description'],
                'category' => $p['category'], 'benefit_type' => $p['benefit_type'], 'unit_amount' => (string) $p['unit_amount'],
                'unit_quantity' => (string) $p['unit_quantity'], 'unit_label' => (string) $p['unit_label'], 'claim_period' => $p['claim_period'],
                'starts_on' => $p['starts_on'], 'ends_on' => $p['ends_on'], 'funding_source' => $p['funding_source'],
            ];
            $acts = '';
            if ($p['status'] !== 'closed') {
                $acts .= '<button class="btn sm" type="button" data-act="prog-edit" data-program="' . esc_attr((string) wp_json_encode($edit)) . '">Edit</button>';
            }
            if ($p['status'] === 'draft') {
                $acts .= self::actionBtn('Publish', $route . '/publish', [], 'Publish "' . $p['name'] . '"? Seniors will see it and can apply from ' . $dates . '. After this, the amount, how often, and the start date cannot change.', '', '', 'primary');
            }
            if ($p['status'] !== 'closed') {
                $acts .= self::actionBtn($p['status'] === 'draft' ? 'Discard' : 'Close', $route . '/close', [],
                    $p['status'] === 'draft' ? 'Discard the draft "' . $p['name'] . '"? It was never shown to seniors.'
                        : 'Close "' . $p['name'] . '"? Seniors can no longer apply. Benefits already approved can still be released.');
            }
            $rows .= '<tr><td><span class="strong">' . self::e($p['name']) . '</span><span class="sub ref">' . self::e($p['code']) . ', '
                . self::e(Programs::CATEGORIES[$p['category']] ?? $p['category']) . '</span></td>'
                . '<td><span class="strong">' . self::e($what) . '</span><span class="sub">' . self::e($often) . ', ' . self::e(strtolower(Programs::FUNDING[$p['funding_source']] ?? '')) . '</span></td>'
                . '<td class="nowrap">' . self::e($dates) . '</td>'
                . '<td><span class="pill ' . $cls . '">' . self::e($label) . '</span></td>'
                . '<td class="num">' . (int) $p['applications'] . ((int) $p['waiting'] > 0 ? '<span class="sub">' . (int) $p['waiting'] . ' waiting</span>' : '') . '</td>'
                . '<td class="num">' . (int) $p['released'] . '</td><td class="acts">' . $acts . '</td></tr>';
        }
        $table = $rows === '' ? '<p class="empty">No programs yet. Create the first one.</p>'
            : '<table class="tbl"><thead><tr><th>Program</th><th>Each senior gets</th><th>Runs</th><th>Status</th><th class="num">Applications</th><th class="num">Released</th><th><span class="vh">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return '<div class="toolbar"><p class="lede">New programs start as drafts. Publishing shows them to seniors and on the website. '
            . 'Once published, what seniors receive cannot change, so every senior in a program is treated the same.</p>'
            . '<button class="btn primary" type="button" data-act="prog-new">New program</button></div>'
            . self::panel('All programs', $table) . self::programDialog();
    }

    private static function programDialog(): string
    {
        $opts = static function (array $list, string $empty = ''): string {
            $o = $empty !== '' ? '<option value="">' . esc_html($empty) . '</option>' : '';
            foreach ($list as $k => $v) {
                $o .= '<option value="' . esc_attr((string) $k) . '">' . esc_html($v) . '</option>';
            }
            return $o;
        };
        $f = static fn(string $id, string $label, string $control, string $hint = ''): string => '<div class="pf"><label for="pf-' . $id . '">' . $label . '</label>'
            . ($hint !== '' ? '<span class="hint" id="pf-' . $id . '-hint">' . $hint . '</span>' : '') . $control . '</div>';
        return '<dialog id="prog" class="wide" aria-labelledby="prog-title"><form id="prog-form" novalidate>'
            . '<h2 id="prog-title">New program</h2>'
            . '<p class="hint block" id="prog-locked" hidden>This program is open to seniors. Only its name, description and end date can change.</p>'
            . '<div class="pf-grid">'
            . $f('name', 'Program name', '<input id="pf-name" name="name" maxlength="120" required>')
            . $f('category', 'Category', '<select id="pf-category" name="category" required data-lock>' . $opts(Programs::CATEGORIES, 'Choose one') . '</select>')
            . $f('description', 'Short description for seniors', '<textarea id="pf-description" name="description" rows="2" maxlength="400"></textarea>', 'Optional. Shown on the senior page and the website.')
            . $f('type', 'Seniors receive', '<select id="pf-type" name="benefit_type" required data-lock>' . $opts(['cash' => 'Cash', 'goods' => 'Goods (packs, kits, items)', 'service' => 'A service (checkup, session)']) . '</select>')
            . '<div class="pf" data-cash><label for="pf-amount">Amount per senior (PHP)</label><input id="pf-amount" name="unit_amount" inputmode="decimal" placeholder="1000.00" data-lock></div>'
            . '<div class="pf pf-pair" data-items hidden><div><label for="pf-qty">How many</label><input id="pf-qty" name="unit_quantity" type="number" min="1" max="1000" value="1" data-lock></div>'
            . '<div><label for="pf-unit">Unit</label><input id="pf-unit" name="unit_label" maxlength="24" placeholder="pack" data-lock></div></div>'
            . $f('period', 'How often a senior can claim', '<select id="pf-period" name="claim_period" required data-lock>' . $opts(['once' => 'One time only', 'yearly' => 'Once a year', 'monthly' => 'Once a month']) . '</select>')
            . $f('funding', 'Funded by', '<select id="pf-funding" name="funding_source" required data-lock>' . $opts(Programs::FUNDING) . '</select>')
            . '<div class="pf pf-pair"><div><label for="pf-start">Start date</label><input id="pf-start" name="starts_on" type="date" required data-lock></div>'
            . '<div><label for="pf-end">End date</label><input id="pf-end" name="ends_on" type="date" required></div></div>'
            . '</div><p id="prog-error" class="err" role="alert"></p>'
            . '<div class="dlg-actions"><button class="btn" type="button" id="prog-cancel">Cancel</button><button class="btn primary" type="submit" id="prog-save">Save as draft</button></div>'
            . '</form></dialog>';
    }

    private static function applications(Actor $a): string
    {
        $filter = sanitize_key($_GET['show'] ?? 'waiting');
        $filter = in_array($filter, ['waiting', 'approved', 'rejected', 'withdrawn', 'all'], true) ? $filter : 'waiting';
        $page = max(1, (int) ($_GET['pg'] ?? 1));
        $list = Listings::applications($a, $filter, $page);
        $decide = $a->can('ss_decide_application');

        $tabs = '';
        $counts = Listings::applicationCounts($a);
        foreach (['waiting' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'all' => 'All'] as $key => $label) {
            $tabs .= '<a class="chip" href="' . esc_url(add_query_arg('show', $key, Router::url('console/applications'))) . '"' . ($filter === $key ? ' aria-current="true"' : '') . '>'
                . self::e($label) . ' <span class="chip-n">' . (int) ($counts[$key] ?? 0) . '</span></a>';
        }
        $rows = '';
        foreach ($list['rows'] as $r) {
            $acts = '';
            if ($decide) {
                $url = 'console/applications/' . $r['id'] . '/transition';
                if ($r['status'] === 'submitted') {
                    $acts .= self::actionBtn('Start review', $url, ['to' => 'under_review']);
                } elseif ($r['status'] === 'under_review') {
                    $acts .= $r['senior_verified']
                        ? self::actionBtn('Approve', $url, ['to' => 'approved'], 'Approve ' . $r['program'] . ' for ' . $r['senior'] . '? The benefit becomes ready for release.', '', '', 'primary')
                        : '<span class="hint">Verify the senior first</span>';
                    $acts .= self::askBtn('Ask for info', $url, ['to' => 'info_needed'], 'note', 'What does the senior need to provide?');
                    $acts .= self::askBtn('Reject', $url, ['to' => 'rejected'], 'note', 'Reason for rejecting (the senior will see this)');
                }
            }
            $rows .= '<tr><td><span class="strong">' . self::e($r['senior']) . '</span><span class="sub ref">' . self::e($r['ref_no']) . '</span></td><td>'
                . self::e($r['program']) . '</td><td>' . self::status($r['status']) . ($r['note'] !== '' ? '<span class="sub">' . self::e($r['note']) . '</span>' : '')
                . '</td><td class="num">' . self::e($r['submitted']) . '</td><td class="acts">' . $acts . '</td></tr>';
        }
        $note = $decide ? '' : '<p class="hint block">Program officers start reviews and decide applications. Administrators can follow them here.</p>';
        $table = $rows === '' ? '<p class="empty">Nothing in this list.</p>'
            : '<table class="tbl"><thead><tr><th>Senior</th><th>Program</th><th>Status</th><th class="num">Submitted</th><th><span class="vh">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return '<div class="toolbar"><div class="chips" role="group" aria-label="Show applications">' . $tabs . '</div></div>' . $note
            . self::panel($list['total'] . ' ' . ($list['total'] === 1 ? 'application' : 'applications'), $table . self::pager($list, ['show' => $filter], 'console/applications'));
    }

    private static function requests(Actor $a): string
    {
        $canSponsor = $a->can('ss_review_pledges');
        $kind = sanitize_key($_GET['kind'] ?? 'senior');
        $kind = match (true) { $kind === 'sponsor' && $canSponsor => 'sponsor', $kind === 'inquiry' => 'inquiry', default => 'senior' };
        if ($kind === 'inquiry') {
            return self::messages($a);
        }
        if (sanitize_key($_GET['kind'] ?? '') === 'settings' && $a->can('ss_manage_programs')) {
            return self::formSettings($a);
        }
        $tabs = self::requestTabs($a, $kind);
        $rows = '';
        $canDecide = $kind === 'sponsor' ? $canSponsor : $a->can('ss_verify_beneficiary');
        $status = ['submitted' => ['New', 'todo'], 'approved' => ['Approved', 'ok'], 'rejected' => ['Rejected', 'neg']];
        foreach (Registrations::queue($a, $kind) as $r) {
            [$sl, $sc] = $status[$r['status']] ?? [$r['status'], ''];
            $sent = get_date_from_gmt(substr((string) $r['created_at'], 0, 19), 'M j, g:i A');
            $acts = '';
            if ($r['status'] === 'submitted' && $canDecide) {
                $route = 'console/requests/' . $r['id'];
                $acts = $kind === 'senior'
                    ? self::actionBtn('Create record', $route . '/approve', [], 'Create a senior record for ' . $r['first_name'] . ' ' . $r['last_name'] . '? It starts as "to verify" until you check their ID.' . (!empty($r['contact_email']) ? ' They gave an email, so they also get a sign-in and a welcome email.' : ''), '', '', 'primary')
                    : self::actionBtn('Approve', $route . '/approve', [], 'Approve ' . $r['org_name'] . '? This creates their organization and sponsor login, and emails the contact person a link to set a password.', '', '', 'primary');
                $acts .= self::askBtn('Reject', $route . '/reject', [], 'note', 'Reason for rejecting this request');
            }
            if ($kind === 'senior') {
                $name = trim($r['first_name'] . ' ' . ($r['middle_name'] ? mb_substr($r['middle_name'], 0, 1) . '. ' : '') . $r['last_name'] . ' ' . ($r['suffix'] ?? ''));
                $age = $r['birthdate'] ? (new \DateTimeImmutable($r['birthdate']))->diff(\SweetSen\Support\Db::todayManila())->y : 0;
                $by = $r['filed_by'] === 'family' ? 'Sent by ' . $r['filer_name'] . ($r['filer_relation'] ? ' (' . $r['filer_relation'] . ')' : '') : 'Sent by the senior';
                $rows .= '<tr><td><span class="strong">' . self::e($name) . '</span><span class="sub ref">' . self::e($r['ref_no']) . '</span></td>'
                    . '<td>' . self::e(date_i18n('M j, Y', strtotime((string) $r['birthdate']))) . '<span class="sub">' . $age . ' years old</span></td>'
                    . '<td>' . self::e((string) $r['barangay']) . ($r['osca_id'] ? '<span class="sub">OSCA ' . self::e($r['osca_id']) . '</span>' : '') . '</td>'
                    . '<td>' . self::e($by) . '<span class="sub">' . self::e((string) ($r['contact_phone'] ?? 'No phone given')) . '</span>' . ($r['contact_email'] ? '<span class="sub">' . self::e((string) $r['contact_email']) . '</span>' : '') . '</td>'
                    . '<td>' . '<span class="pill ' . $sc . '">' . self::e($sl) . '</span>' . ($r['review_note'] ? '<span class="sub">' . self::e($r['review_note']) . '</span>' : '') . '</td>'
                    . '<td class="num">' . self::e($sent) . '</td><td class="acts">' . $acts . '</td></tr>';
            } else {
                $rows .= '<tr><td><span class="strong">' . self::e((string) $r['org_name']) . '</span><span class="sub">' . self::e(Registrations::ORG_TYPES[$r['org_type']] ?? '') . ', <span class="ref">' . self::e($r['ref_no']) . '</span></span></td>'
                    . '<td>' . self::e((string) $r['contact_name']) . '<span class="sub">' . self::e((string) $r['contact_email']) . ($r['contact_phone'] ? ', ' . self::e($r['contact_phone']) : '') . '</span></td>'
                    . '<td>' . self::e((string) ($r['message'] ?? '')) . '</td>'
                    . '<td><span class="pill ' . $sc . '">' . self::e($sl) . '</span>' . ($r['review_note'] ? '<span class="sub">' . self::e($r['review_note']) . '</span>' : '') . '</td>'
                    . '<td class="num">' . self::e($sent) . '</td><td class="acts">' . $acts . '</td></tr>';
            }
        }
        $head = $kind === 'senior'
            ? '<tr><th>Senior</th><th>Birthday</th><th>Barangay</th><th>Sent by</th><th>Status</th><th class="num">Received</th><th><span class="vh">Actions</span></th></tr>'
            : '<tr><th>Organization</th><th>Contact</th><th>Wants to give</th><th>Status</th><th class="num">Received</th><th><span class="vh">Actions</span></th></tr>';
        $table = $rows === '' ? '<p class="empty">No requests yet. They arrive from the forms on the public website.</p>'
            : '<table class="tbl"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>';
        $lede = $kind === 'senior'
            ? 'Seniors and their families ask to be registered from the website. Creating a record does not verify it: check their ID at the office first, then verify the record on the Seniors page.'
            : 'Organizations ask to become sponsors from the website. Approving creates the organization and its login, and emails the contact a link to set their own password.';
        return '<div class="toolbar"><div class="chips" role="group" aria-label="Request type">' . $tabs . '</div></div>'
            . '<p class="lede">' . self::e($lede) . '</p>' . self::panel('Latest requests', $table);
    }

    private static function requestTabs(Actor $a, string $kind): string
    {
        $tabs = '';
        $list = ['senior' => 'Senior registrations', 'sponsor' => $a->can('ss_review_pledges') ? 'Sponsor sign-ups' : null, 'inquiry' => 'Messages'];
        if ($a->can('ss_manage_programs')) {
            $list['settings'] = 'Form settings';
        }
        foreach (array_filter($list) as $k => $label) {
            $n = $k === 'settings' ? 0 : Registrations::pendingCount($a, $k);
            $tabs .= '<a class="chip" href="' . esc_url(add_query_arg('kind', $k, Router::url('console/requests'))) . '"' . ($kind === $k ? ' aria-current="true"' : '') . '>'
                . self::e($label) . ($n > 0 ? ' (' . $n . ' new)' : '') . '</a>';
        }
        return $tabs;
    }

    /** LGU admin: open or close each website form, give it dates, and limit how many requests it takes. */
    private static function formSettings(Actor $a): string
    {
        $all = \SweetSen\Domain\FormLimits::get($a->tenantId);
        $boxes = '';
        foreach (\SweetSen\Domain\FormLimits::KINDS as $k => $label) {
            $c = $all[$k];
            $st = \SweetSen\Domain\FormLimits::state($a->tenantId, $k);
            $today = \SweetSen\Domain\FormLimits::todayCount($a->tenantId, $k);
            $pill = $st['open'] ? '<span class="pill ok">Taking requests</span>' : '<span class="pill neg">Not taking requests</span>';
            $id = 'fl-' . $k;
            $boxes .= '<fieldset class="fl-box" data-kind="' . esc_attr($k) . '"><legend>' . self::e($label) . ' ' . $pill . '</legend>'
                . '<p class="hint">' . $today . ' received today' . ($st['open'] ? '' : '. Visitors see: &ldquo;' . self::e($st['message']) . '&rdquo;') . '</p>'
                . '<label class="fl-open"><input type="checkbox" name="open"' . checked($c['open'], true, false) . '> Accept requests from the website</label>'
                . '<div class="pf-grid">'
                . '<div class="pf"><label for="' . $id . '-opens">Opens on (optional)</label><input id="' . $id . '-opens" name="opens_on" type="date" value="' . esc_attr($c['opens_on']) . '"></div>'
                . '<div class="pf"><label for="' . $id . '-closes">Closes after (optional)</label><input id="' . $id . '-closes" name="closes_on" type="date" value="' . esc_attr($c['closes_on']) . '"></div>'
                . '<div class="pf"><label for="' . $id . '-per">Requests per visitor per day</label><input id="' . $id . '-per" name="per_visitor" type="number" min="1" max="50" required value="' . (int) $c['per_visitor'] . '"></div>'
                . '<div class="pf"><label for="' . $id . '-cap">Most requests per day, whole site</label><input id="' . $id . '-cap" name="daily_cap" type="number" min="1" max="5000" required value="' . (int) $c['daily_cap'] . '"></div>'
                . '<div class="pf fl-msg"><label for="' . $id . '-msg">Message when closed (optional)</label><span class="hint">For example: Online registration reopens in January.</span><input id="' . $id . '-msg" name="message" maxlength="200" value="' . esc_attr($c['message']) . '"></div>'
                . '</div></fieldset>';
        }
        $form = '<form id="fl-form" novalidate>' . $boxes . '<p id="fl-error" class="err" role="alert"></p>'
            . '<p><button class="btn primary" type="submit" id="fl-save">Save form settings</button></p></form>';
        return '<div class="toolbar"><div class="chips" role="group" aria-label="Request type">' . self::requestTabs($a, 'settings') . '</div></div>'
            . '<p class="lede">Control the three forms on the public website. Dates use Philippine time; a form opens at the start of its opening day and stops at the end of its closing day. Changes show on the website straight away.</p>'
            . self::panel('Website forms', $form);
    }

    /** Questions from the Contact page. Staff reply by email or phone, then mark the message answered. */
    private static function messages(Actor $a): string
    {
        $status = ['submitted' => ['New', 'todo'], 'answered' => ['Answered', 'ok'], 'rejected' => ['Closed', 'neg']];
        $rows = '';
        foreach (Registrations::queue($a, 'inquiry') as $r) {
            [$sl, $sc] = $status[$r['status']] ?? [$r['status'], ''];
            $sent = get_date_from_gmt(substr((string) $r['created_at'], 0, 19), 'M j, g:i A');
            $reply = [];
            if ($r['contact_email']) {
                $subject = 'Re: your message to SweetSen (' . $r['ref_no'] . ')';
                $reply[] = '<a href="mailto:' . esc_attr($r['contact_email']) . '?subject=' . rawurlencode($subject) . '">' . self::e($r['contact_email']) . '</a>';
            }
            if ($r['contact_phone']) {
                $reply[] = '<a href="tel:' . esc_attr($r['contact_phone']) . '">' . self::e($r['contact_phone']) . '</a>';
            }
            $acts = '';
            if ($r['status'] === 'submitted') {
                $route = 'console/requests/' . $r['id'];
                $acts = self::askBtn('Mark answered', $route . '/answer', [], 'note', 'How did you reply? For example: emailed the program list')
                    . self::askBtn('Close', $route . '/reject', [], 'note', 'Reason for closing without a reply (for example: spam)');
            }
            $rows .= '<tr><td><span class="strong">' . self::e((string) $r['contact_name']) . '</span><span class="sub ref">' . self::e($r['ref_no']) . '</span></td>'
                . '<td>' . implode('<span class="sub">', $reply) . (count($reply) > 1 ? '</span>' : '') . '</td>'
                . '<td><span class="strong">' . self::e(Registrations::TOPICS[$r['org_type']] ?? '') . '</span><span class="sub msg">' . nl2br(self::e((string) $r['message'])) . '</span></td>'
                . '<td><span class="pill ' . $sc . '">' . self::e($sl) . '</span>' . ($r['review_note'] ? '<span class="sub">' . self::e($r['review_note']) . '</span>' : '') . '</td>'
                . '<td class="num">' . self::e($sent) . '</td><td class="acts">' . $acts . '</td></tr>';
        }
        $table = $rows === '' ? '<p class="empty">No messages yet. They arrive from the Contact page on the public website.</p>'
            : '<table class="tbl"><thead><tr><th>From</th><th>Reply to</th><th>Message</th><th>Status</th><th class="num">Received</th><th><span class="vh">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return '<div class="toolbar"><div class="chips" role="group" aria-label="Request type">' . self::requestTabs($a, 'inquiry') . '</div></div>'
            . '<p class="lede">Questions sent from the Contact page. Reply by email or phone, then mark the message as answered so the team knows it is done.</p>'
            . self::panel('Latest messages', $table);
    }

    private static function releases(Actor $a): string
    {
        $rows = '';
        $canReverse = $a->can('ss_reverse_release');
        foreach (Listings::releases($a, 50) as $r) {
            $act = '';
            if ($canReverse && $r['kind'] === 'release' && !$r['reversed']) {
                $act = $r['by_me'] ? '<span class="hint">Your release</span>'
                    : self::askBtn('Reverse', 'console/releases/' . $r['id'] . '/reverse', [], 'reason', 'Why is this release being reversed?');
            }
            $kind = $r['kind'] === 'reversal' ? '<span class="pill neg">Reversal</span>' : ($r['reversed'] ? '<span class="pill">Reversed</span>' : '<span class="pill ok">Released</span>');
            $rows .= '<tr><td><span class="strong">' . self::e($r['senior']) . '</span><span class="sub ref">' . self::e($r['ref_no']) . '</span></td><td>'
                . self::e($r['program']) . '<span class="sub">' . self::e($r['what']) . '</span></td><td>' . $kind
                . ($r['note'] !== '' ? '<span class="sub">' . self::e($r['note']) . '</span>' : '') . '</td><td>' . self::e($r['by'])
                . '<span class="sub">' . ($r['method'] === 'qr' ? 'QR scan' : 'Manual') . '</span></td><td class="num">' . self::e($r['time']) . '</td><td class="acts">' . $act . '</td></tr>';
        }
        $table = $rows === '' ? '<p class="empty">No releases yet. They appear here as verifiers scan seniors at the desk.</p>'
            : '<table class="tbl"><thead><tr><th>Senior</th><th>Benefit</th><th>Entry</th><th>By</th><th class="num">When</th><th><span class="vh">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return '<p class="lede">Every release and reversal, newest first. Entries are never edited; a reversal is added as its own line.</p>'
            . self::panel('Latest 50 entries', $table);
    }

    private static function sponsors(Actor $a): string
    {
        $rows = '';
        $canRecord = $a->can('ss_record_donations');
        foreach (Listings::pledges($a) as $p) {
            $acts = '';
            if ($p['status'] === 'submitted') {
                $acts .= self::actionBtn('Approve', 'console/pledges/' . $p['id'] . '/review', ['approve' => true], 'Approve pledge ' . $p['ref_no'] . ' from ' . $p['org'] . '?', '', '', 'primary');
                $acts .= self::askBtn('Reject', 'console/pledges/' . $p['id'] . '/review', ['approve' => false], 'note', 'Reason for rejecting this pledge');
            } elseif ($p['status'] === 'approved' && $canRecord && (float) $p['received'] < (float) $p['pledged']) {
                $acts .= $p['kind'] === 'cash'
                    ? self::askBtn('Record delivery', 'console/pledges/' . $p['id'] . '/delivery', [], 'amount', 'Amount received (PHP)')
                    : self::askBtn('Record delivery', 'console/pledges/' . $p['id'] . '/delivery', [], 'quantity', 'How many items arrived?');
            }
            $done = $p['kind'] === 'cash'
                ? 'PHP ' . number_format((float) $p['received'], 2) . ' of ' . number_format((float) $p['pledged'], 2)
                : number_format((int) $p['received']) . ' of ' . number_format((int) $p['pledged']);
            $rows .= '<tr><td><span class="strong">' . self::e($p['org']) . '</span><span class="sub ref">' . self::e($p['ref_no']) . ', ' . self::e($p['date']) . '</span></td><td>'
                . self::e($p['what']) . '<span class="sub">' . self::e($p['program']) . '</span></td><td>' . self::status($p['status']) . '</td><td class="num">'
                . ($p['status'] === 'approved' ? self::e($done) . '<span class="sub">received</span>' : '') . '</td><td class="acts">' . $acts . '</td></tr>';
        }
        $table = $rows === '' ? '<p class="empty">No pledges yet. Sponsors submit them from their own portal.</p>'
            : '<table class="tbl"><thead><tr><th>Sponsor</th><th>Pledge</th><th>Status</th><th class="num">Progress</th><th><span class="vh">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return '<p class="lede">A pledge is a promise; a delivery is what arrived. Approving a pledge does not count it as received.</p>'
            . self::panel('Pledges', $table);
    }

    private static function imports(Actor $a): string
    {
        $data = Listings::imports($a);
        $form = '<form id="import-form" class="upload" enctype="multipart/form-data">'
            . '<label for="csv">CSV file of senior records</label>'
            . '<p class="hint">Columns: ref_no, last_name, first_name, middle_name, suffix, sex, birthdate (YYYY-MM-DD), barangay, address_line, contact_phone, osca_id. '
            . 'New records start as "to verify". Possible duplicates are held for review, never merged.</p>'
            . '<div class="row"><input id="csv" name="file" type="file" accept=".csv,text/csv" required><button class="btn primary" type="submit">Import file</button></div></form>';
        $jobs = '';
        foreach ($data['jobs'] as $j) {
            $jobs .= '<tr><td><span class="strong">' . self::e($j['file']) . '</span><span class="sub">' . self::e($j['by']) . '</span></td><td class="num">' . $j['total']
                . '</td><td class="num">' . $j['created'] . '</td><td class="num">' . $j['flagged'] . '</td><td class="num">' . $j['errors'] . '</td><td class="num">' . self::e($j['time']) . '</td></tr>';
        }
        $flag = '';
        foreach ($data['flagged'] as $f) {
            $flag .= '<tr><td><span class="strong">' . self::e($f['name']) . '</span><span class="sub">' . self::e($f['source_ref']) . ', born ' . self::e($f['birthdate']) . '</span></td><td>'
                . self::e($f['reason']) . '</td><td>' . ($f['match_ref'] ? '<a href="' . esc_url(add_query_arg('q', $f['match_ref'], Router::url('console/seniors'))) . '">' . self::e($f['match_ref']) . '</a>' : '') . '</td><td class="num">'
                . self::e($f['file']) . ', row ' . $f['row'] . '</td></tr>';
        }
        return self::panel('Import seniors', $form)
            . self::panel('Held for review', $flag === '' ? '<p class="empty">Nothing is waiting for review.</p>'
                : '<table class="tbl"><thead><tr><th>Record in file</th><th>Why it was held</th><th>Existing senior</th><th class="num">Source</th></tr></thead><tbody>' . $flag . '</tbody></table>')
            . self::panel('Recent imports', $jobs === '' ? '<p class="empty">No files imported yet.</p>'
                : '<table class="tbl"><thead><tr><th>File</th><th class="num">Rows</th><th class="num">Added</th><th class="num">Held</th><th class="num">Errors</th><th class="num">When</th></tr></thead><tbody>' . $jobs . '</tbody></table>');
    }

    private static function audit(Actor $a): string
    {
        $rows = '';
        foreach (Listings::audit($a, 100) as $r) {
            $rows .= '<tr><td class="num nowrap">' . self::e($r['time']) . '</td><td>' . self::e($r['who']) . '</td><td>' . self::e($r['what'])
                . ($r['detail'] !== '' ? '<span class="sub">' . self::e($r['detail']) . '</span>' : '') . '</td><td><code>' . self::e($r['code']) . '</code></td></tr>';
        }
        return '<p class="lede">Every privileged action, newest first. Entries cannot be edited or deleted, and they never contain personal details.</p>'
            . self::panel('Latest 100 entries', '<table class="tbl"><thead><tr><th class="num">When</th><th>Who</th><th>What</th><th>Code</th></tr></thead><tbody>' . $rows . '</tbody></table>');
    }

    /* ------------------------------------------------------------------ pieces */

    private static function activityPanel(Actor $a): string
    {
        $items = '';
        foreach (Reports::activity($a, 7) as $r) {
            $items .= '<li><span class="t">' . self::e($r['time']) . '</span><span><span class="strong">' . self::e($r['who']) . '</span> ' . self::e($r['what']) . '</span></li>';
        }
        return self::panel('Recent activity', $items === '' ? '<p class="empty">No activity yet.</p>' : '<ol class="feed">' . $items . '</ol>',
            ['label' => 'Audit log', 'href' => Router::url('console/audit')]);
    }

    private static function recentReleasesPanel(Actor $a): string
    {
        $items = '';
        foreach (array_slice(Listings::releases($a, 7), 0, 7) as $r) {
            $items .= '<li><span class="t">' . self::e($r['time']) . '</span><span><span class="strong">' . self::e($r['senior']) . '</span> '
                . self::e(($r['kind'] === 'reversal' ? 'reversal of ' : '') . $r['program']) . '</span></li>';
        }
        return self::panel('Recent releases', $items === '' ? '<p class="empty">No releases yet.</p>' : '<ol class="feed">' . $items . '</ol>',
            ['label' => 'All releases', 'href' => Router::url('console/releases')]);
    }

    private static function tile(string $label, string $value, string $sub, ?string $href, bool $attention = false): string
    {
        $inner = '<p class="tile-label">' . self::e($label) . '</p><p class="tile-value">' . self::e($value) . '</p><p class="tile-sub">' . self::e($sub) . '</p>';
        $cls = 'tile' . ($attention ? ' attention' : '');
        return $href ? '<a class="' . $cls . '" href="' . esc_url($href) . '">' . $inner . '</a>' : '<div class="' . $cls . '">' . $inner . '</div>';
    }

    /** @param array{label:string, href:string}|null $link */
    /** @param array{label:string,href:string}|list<array{label:string,href:string}>|null $link */
    private static function panel(string $title, string $body, ?array $link = null): string
    {
        $links = $link === null ? [] : (isset($link['href']) ? [$link] : $link);
        $more = implode('', array_map(static fn(array $l): string => '<a class="more" href="' . esc_url($l['href']) . '">' . self::e($l['label']) . '</a>', $links));
        $more = count($links) > 1 ? '<span class="more-links">' . $more . '</span>' : $more;
        return '<section class="panel"><div class="panel-head"><h2>' . self::e($title) . '</h2>' . $more . '</div>' . $body . '</section>';
    }

    private const STATUS_LABELS = [
        'verified' => ['Verified', 'ok'], 'unverified' => ['To verify', 'todo'],
        'submitted' => ['New', 'todo'], 'under_review' => ['In review', 'todo'], 'info_needed' => ['Needs info', 'todo'],
        'approved' => ['Approved', 'ok'], 'rejected' => ['Rejected', 'neg'], 'withdrawn' => ['Withdrawn', ''],
    ];

    private static function status(string $s): string
    {
        [$label, $cls] = self::STATUS_LABELS[$s] ?? [ucfirst(str_replace('_', ' ', $s)), ''];
        return '<span class="pill ' . $cls . '">' . self::e($label) . '</span>';
    }

    /** @param array<string, mixed> $body */
    private static function actionBtn(string $label, string $route, array $body, string $confirm = '', string $after = '', string $name = '', string $style = ''): string
    {
        return '<button class="btn sm ' . $style . '" type="button" data-act="post" data-route="' . esc_attr($route) . '" data-body="' . esc_attr((string) wp_json_encode((object) $body)) . '"'
            . ($confirm !== '' ? ' data-confirm="' . esc_attr($confirm) . '"' : '')
            . ($after !== '' ? ' data-after="' . esc_attr($after) . '" data-name="' . esc_attr($name) . '"' : '') . '>' . self::e($label) . '</button>';
    }

    /** @param array<string, mixed> $body */
    private static function askBtn(string $label, string $route, array $body, string $field, string $question): string
    {
        return '<button class="btn sm" type="button" data-act="post" data-route="' . esc_attr($route) . '" data-body="' . esc_attr((string) wp_json_encode((object) $body))
            . '" data-ask="' . esc_attr($field) . '" data-question="' . esc_attr($question) . '" data-title="' . esc_attr($label) . '">' . self::e($label) . '</button>';
    }

    /** @param array{page:int, pages:int} $list @param array<string, string> $args */
    private static function pager(array $list, array $args, string $path): string
    {
        if ($list['pages'] < 2) {
            return '';
        }
        $base = Router::url($path);
        $prev = $list['page'] > 1 ? '<a class="btn sm" href="' . esc_url(add_query_arg(array_filter($args + ['pg' => $list['page'] - 1]), $base)) . '">Previous</a>' : '';
        $next = $list['page'] < $list['pages'] ? '<a class="btn sm" href="' . esc_url(add_query_arg(array_filter($args + ['pg' => $list['page'] + 1]), $base)) . '">Next</a>' : '';
        return '<nav class="pager" aria-label="Pages">' . $prev . '<span>Page ' . $list['page'] . ' of ' . $list['pages'] . '</span>' . $next . '</nav>';
    }

    private static function e(string $s): string
    {
        return esc_html($s);
    }

    /* ------------------------------------------------------------------ shell */

    private static function shell(Actor $a, string $active, string $title, string $content): void
    {
        $user = wp_get_current_user();
        $tenant = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('tenants') . ' WHERE id = UNHEX(%s)', $a->tenantId));
        $waiting = (int) Db::value(Db::prepare(
            "SELECT COUNT(*) FROM " . Db::t('applications') . " WHERE tenant_id = UNHEX(%s) AND status IN ('submitted','under_review','info_needed')",
            $a->tenantId
        ));
        $counts = ['applications' => $waiting, 'requests' => $a->can('ss_manage_beneficiaries') ? Registrations::pendingCount($a) : 0];
        $nav = '';
        foreach (self::NAV as $key => [$label, $cap, $icon]) {
            if (!$a->can($cap)) {
                continue;
            }
            $n = $counts[$key] ?? 0;
            $badge = $n > 0 ? '<span class="badge" aria-label="' . $n . ' waiting">' . $n . '</span>' : '';
            $nav .= '<li><a href="' . esc_url(Router::url($key === 'dashboard' ? 'console' : 'console/' . $key)) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $icon . '"/></svg><span>' . self::e($label) . '</span>' . $badge . '</a></li>';
        }
        if ($a->can('ss_manage_qr')) {
            $nav .= '<li><a href="' . esc_url(Router::url('qr-cards')) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM18 18h3v3h-3zM14 20h2"/></svg><span>QR cards</span></a></li>';
        }
        $role = Roles::LABELS[$a->role] ?? '';
        $role = str_replace(['SweetSen: ', 'Sweet Sen: '], '', $role);
        $date = Db::todayManila()->format('l, F j');
        $body = '<div class="shell"><nav class="side" aria-label="Console">'
            . '<p class="brand"><span class="mark" aria-hidden="true"></span>SweetSen</p><p class="office">' . self::e($tenant) . '</p>'
            . '<ul class="nav">' . $nav . '</ul>'
            . '<div class="me"><p class="strong">' . self::e($user->display_name) . '</p><p>' . self::e($role) . '</p><a href="' . esc_url(wp_logout_url(Router::url())) . '">Log out</a></div>'
            . '</nav><div class="work"><header class="top"><h1>' . self::e($title) . '</h1><p class="date">' . self::e($date) . '</p></header>'
            . '<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>'
            . '<div class="content">' . $content . '</div></div></div>'
            . self::dialogs();
        Layout::bare($title, $body, self::css(), self::js(), [], ['https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js']);
    }

    private static function dialogs(): string
    {
        return '<dialog id="ask" aria-labelledby="ask-title"><form method="dialog" id="ask-form">'
            . '<h2 id="ask-title"></h2><label id="ask-label" for="ask-input"></label><textarea id="ask-input" rows="3"></textarea>'
            . '<p id="ask-error" class="err" role="alert"></p>'
            . '<div class="dlg-actions"><button class="btn" value="cancel" formnovalidate>Cancel</button><button class="btn primary" id="ask-ok" value="ok">Confirm</button></div></form></dialog>'
            . '<dialog id="review" class="wide" aria-labelledby="rv-title"><form id="rv-form" novalidate>'
            . '<p class="rv-ref" id="rv-ref"></p><h2 id="rv-title">Senior record</h2><p id="rv-status"></p>'
            . '<div id="rv-loading" class="hint">Loading the record…</div><div id="rv-body" hidden>'
            . '<h3>Details on file</h3><dl class="rv-dl" id="rv-details"></dl>'
            . '<h3>Where this record came from</h3><p id="rv-origin" class="rv-origin"></p>'
            . '<div id="rv-similar-box" hidden><h3>Possible duplicates</h3><p class="hint block">Other records with the same name, birth date or OSCA ID. Make sure this is not the same person.</p><ul id="rv-similar" class="rv-similar"></ul></div>'
            . '<div id="rv-verified" class="rv-done" hidden></div>'
            . '<fieldset id="rv-check" class="rv-check" hidden><legend>Check the senior\'s ID in person</legend>'
            . '<label for="rv-idtype" class="rv-lbl">Type of ID shown</label><select id="rv-idtype" required></select>'
            . '<div id="rv-items"></div>'
            . '<p class="hint block">If anything does not match, do not verify. Leave the record as "To verify" and ask the senior to come back with the right ID.</p></fieldset>'
            . '</div><p id="rv-error" class="err" role="alert"></p>'
            . '<div class="dlg-actions"><button class="btn" type="button" id="rv-close">Close</button><button class="btn primary" type="submit" id="rv-verify" hidden disabled>Verify senior</button></div>'
            . '</form></dialog>'
            . '<dialog id="qrr" aria-labelledby="qrr-title"><form id="qrr-form" novalidate><h2 id="qrr-title">Replace QR code</h2>'
            . '<p class="qrr-who">Senior: <strong id="qrr-name"></strong></p>'
            . '<p>A new code is made and <strong>the old code stops working right away</strong>, so a lost or stolen card cannot be used.</p>'
            . '<label for="qrr-reason" class="rv-lbl">Why is it being replaced?</label><select id="qrr-reason" class="qrr-select">'
            . '<option value="lost">Lost</option><option value="stolen">Stolen</option><option value="damaged">Damaged or unreadable</option><option value="other">Other</option></select>'
            . '<div class="qrr-mail"><label class="rv-item" id="qrr-mail-row"><input type="checkbox" id="qrr-mail"><span id="qrr-mail-text">Checking for an email on file…</span></label>'
            . '<p class="hint block" id="qrr-mail-note" hidden></p></div>'
            . '<p id="qrr-error" class="err" role="alert"></p>'
            . '<div class="dlg-actions"><button class="btn" type="button" id="qrr-cancel">Cancel</button><button class="btn primary" type="submit" id="qrr-go">Replace QR code</button></div>'
            . '</form></dialog>'
            . '<dialog id="qr" aria-labelledby="qr-title"><h2 id="qr-title"></h2><div id="qr-box" class="qr-box"></div>'
            . '<p class="hint">The senior shows this code at the release desk. It contains no personal details.</p>'
            . '<div class="dlg-actions"><button class="btn" type="button" id="qr-print">Print</button><button class="btn primary" type="button" id="qr-close">Done</button></div></dialog>';
    }

    private static function css(): string
    {
        return <<<'CSS'
body{background:var(--paper)}
.shell{display:grid;grid-template-columns:16rem minmax(0,1fr);min-height:100vh;background:linear-gradient(to right,var(--grey-deep) 16rem,var(--paper) 16rem)}
.side{background:var(--grey-deep);color:#E4E8ED;padding:1.5rem 1rem;display:flex;flex-direction:column;gap:.25rem;position:sticky;top:0;height:100vh}
.side .brand{font-weight:700;font-size:1.3rem;display:flex;align-items:center;gap:.6rem;margin:0 .5rem;color:#fff}
.side .office{margin:.15rem .5rem 1.5rem 2.35rem;font-size:var(--step--1);color:#B9C1CB}
.nav{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.2rem}
.nav a{display:flex;align-items:center;gap:.75rem;padding:.65rem .75rem;border-radius:8px;color:#E4E8ED;text-decoration:none;font-size:1.05rem;min-height:2.9rem}
.nav a:hover{background:rgba(255,255,255,.08)}
.nav a[aria-current="page"]{background:var(--action);color:var(--ink);font-weight:700}
.nav svg{width:1.3rem;height:1.3rem;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.badge{margin-left:auto;background:var(--action);color:var(--ink);border-radius:999px;min-width:1.6rem;padding:0 .45rem;text-align:center;font-weight:700;font-size:.9rem}
.nav a[aria-current="page"] .badge{background:var(--ink);color:#fff}
.me{margin-top:auto;padding:1rem .75rem 0;border-top:1px solid rgba(255,255,255,.15);font-size:var(--step--1)}
.me p{margin:0;color:#B9C1CB}.me .strong{color:#fff}.me a{color:#fff;display:inline-block;margin-top:.4rem}
.work{min-width:0;padding:1.5rem clamp(1rem,3vw,2.5rem) 3rem}
.top{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap}
.top h1{margin:0}.date{margin:0;color:var(--muted)}
.work .demo{margin:.75rem 0 1.25rem;border-radius:var(--r-small)}
.content{display:flex;flex-direction:column;gap:1.25rem}
.lede{margin:0;max-width:46rem;color:var(--muted)}
.tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
.tile{display:block;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem;text-decoration:none;color:var(--ink)}
a.tile:hover{border-color:var(--ink)}
.tile.attention{border-left:8px solid var(--action)}
.tile-label{margin:0;color:var(--muted);font-size:var(--step--1)}
.tile-value{margin:.15rem 0;font-size:clamp(2rem,1.6rem + 1.4vw,2.75rem);font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums}
.tile-sub{margin:0;font-size:var(--step--1);color:var(--muted)}
.grid-2{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:1.25rem}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:1.1rem 1.25rem;min-width:0}
.panel-head{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;margin-bottom:.6rem}
.panel h2{margin:0;font-size:var(--step-0)}
.more{font-size:var(--step--1);white-space:nowrap}
.chip-n{font-weight:400;opacity:.75;margin-left:.15em}
.more-links{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.4rem 1.25rem}
.tbl{width:100%;border-collapse:collapse;font-size:1.02rem}
.tbl th{text-align:left;font-weight:700;color:var(--muted);font-size:var(--step--1);padding:.5rem .6rem;border-bottom:2px solid var(--line)}
.tbl td{padding:.7rem .6rem;border-bottom:1px solid var(--line);vertical-align:top}
.tbl tr:last-child td{border-bottom:0}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.sub.ref{white-space:nowrap}
.nowrap{white-space:nowrap}
.strong{font-weight:700;display:block}
.sub{display:block;color:var(--muted);font-size:.92rem}
.acts{text-align:right;white-space:nowrap}
.acts .btn{margin-left:.35rem}
.btn.sm{min-height:2.75rem;padding:.35rem .9rem;font-size:1rem}
.pill{display:inline-block;padding:.1rem .6rem;border-radius:999px;border:1.5px solid var(--muted);font-size:.9rem;font-weight:700;white-space:nowrap}
.pill.ok{background:var(--grey-deep);border-color:var(--grey-deep);color:#fff}
.pill.todo{background:var(--action-tint);border-color:#C99A00}
.pill.neg{border-style:dashed}
.hint{color:var(--muted);font-size:var(--step--1)}
.hint.block{margin:0}
.empty{margin:.5rem 0;color:var(--muted)}
.toolbar{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between}
.search{display:flex;gap:.5rem;flex:1;max-width:30rem}
.search input{min-height:2.75rem}
.chips{display:flex;gap:.4rem;flex-wrap:wrap}
.chip{padding:.45rem .9rem;border-radius:999px;border:1.5px solid var(--line);background:var(--surface);text-decoration:none;font-size:1rem;min-height:2.75rem;display:inline-flex;align-items:center}
.chip[aria-current="true"]{background:var(--ink);border-color:var(--ink);color:#fff}
.pager{display:flex;align-items:center;gap:1rem;justify-content:flex-end;margin-top:1rem}
.feed{list-style:none;margin:0;padding:0}
.feed li{display:grid;grid-template-columns:7.5rem 1fr;gap:.75rem;padding:.55rem 0;border-bottom:1px solid var(--line);font-size:1rem}
.feed li:last-child{border-bottom:0}
.feed .t{color:var(--muted);font-variant-numeric:tabular-nums}
.feed .strong{display:inline}
.upload .row{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
.upload input[type=file]{flex:1;min-width:14rem;border-style:dashed}
.vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.toast{background:var(--grey-deep);color:#fff;padding:.8rem 1.1rem;border-radius:var(--r-small);border-left:8px solid var(--action);margin-bottom:1rem}
.toast.err{border-left-color:var(--refused)}
dialog{border:0;border-radius:14px;padding:1.5rem;max-width:30rem;width:calc(100% - 2rem);box-shadow:0 24px 60px rgba(34,39,46,.35)}
dialog::backdrop{background:rgba(34,39,46,.55)}
dialog h2{margin:0 0 .5rem}
.dlg-actions{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1rem}
.err{color:var(--refused);font-weight:700;margin:.4rem 0 0;min-height:1.2em}
dialog.wide{max-width:40rem}
#review h3{font-size:1.05rem;margin:1.1rem 0 .4rem}
.rv-ref{margin:0;color:var(--muted);font-size:var(--step--1)}
#rv-status{margin:.2rem 0 0}
.rv-dl{display:grid;grid-template-columns:minmax(8rem,auto) 1fr;gap:.3rem 1rem;margin:0;font-size:1rem}
.rv-dl dt{color:var(--muted)}
.rv-dl dd{margin:0;font-weight:700;overflow-wrap:anywhere}
.rv-origin{margin:0;font-size:1rem}
.rv-similar{margin:.4rem 0 0;padding:0;list-style:none}
.rv-similar li{padding:.5rem .75rem;border:2px solid #C99A00;background:var(--action-tint);border-radius:var(--r-small);margin-bottom:.4rem;font-size:1rem}
.rv-done{margin-top:1rem;padding:.75rem 1rem;border-left:6px solid var(--grey-deep);background:var(--paper);border-radius:var(--r-small);font-size:1rem}
.rv-check{margin:1.25rem 0 0;padding:1rem;border:2px solid var(--ink);border-radius:var(--r-small)}
.rv-check legend{font-weight:700;padding:0 .4rem}
.rv-lbl{display:block;font-weight:700;font-size:1rem;margin-bottom:.25rem}
.rv-check select{width:100%;min-height:2.75rem;font:inherit;font-size:1rem;padding:.4rem .6rem;border:2px solid var(--muted);border-radius:var(--r-small);background:#fff}
.rv-item{display:flex;gap:.7rem;align-items:flex-start;margin-top:.8rem;font-size:1rem;cursor:pointer}
.rv-item input{width:1.5rem;height:1.5rem;flex:none;margin:.05rem 0 0;accent-color:var(--ink)}
.rv-check .hint{margin-top:.9rem}
#qrr{max-width:32rem}
.qrr-who{margin:.25rem 0 .5rem}
.qrr-select{width:100%;min-height:2.75rem;font:inherit;font-size:1rem;padding:.4rem .6rem;border:2px solid var(--muted);border-radius:var(--r-small);background:#fff}
.qrr-mail{margin-top:1rem;padding:.75rem 1rem;background:var(--paper);border-radius:var(--r-small)}
.qrr-mail .rv-item{margin-top:0}
.qrr-mail .hint{margin:.5rem 0 0}
@media (max-width:40rem){.rv-dl{grid-template-columns:1fr}.rv-dl dd{margin-bottom:.35rem}}
.fl-box{border:1px solid var(--line);border-radius:var(--r-small);padding:1rem 1.25rem 1.25rem;margin:0 0 1.25rem}
.fl-box legend{font-weight:700;font-size:1.1rem;padding:0 .4rem;display:flex;gap:.6rem;align-items:center}
.fl-box .hint{margin:.25rem 0 .75rem}
.fl-open{display:flex;gap:.6rem;align-items:center;font-weight:700;min-height:2.75rem}
.fl-open input{width:1.4rem;height:1.4rem}
.fl-msg{grid-column:1/-1}
.pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:.9rem 1rem;margin-top:.75rem}
.pf{display:flex;flex-direction:column;gap:.25rem;min-width:0}
.pf label{font-weight:700;font-size:1rem}
.pf .hint{margin:0}
.pf input,.pf select,.pf textarea{width:100%;min-height:2.75rem;font:inherit;font-size:1rem;padding:.45rem .6rem;border:2px solid var(--muted);border-radius:var(--r-small);background:#fff;color:var(--ink)}
.pf input:disabled,.pf select:disabled{background:var(--paper);border-color:var(--line);color:var(--muted)}
.pf-pair{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.pf-pair>div{display:flex;flex-direction:column;gap:.25rem;min-width:0}
.pf-pair input{min-width:0}
#pf-name,#pf-description{grid-column:auto}
.pf:has(#pf-name),.pf:has(#pf-description){grid-column:1/-1}
.toolbar .lede{flex:1;min-width:16rem}
@media (max-width:40rem){.pf-grid{grid-template-columns:1fr}}
.qr-box{width:15rem;height:15rem;margin:.5rem auto}
.qr-box svg{width:100%;height:100%}
/* charts */
.chart{width:100%;height:auto;display:block}
.chart .bar{fill:var(--grey-deep)}
.chart .bar.today{fill:var(--action);stroke:var(--ink);stroke-width:1.5}
.chart .bar-g:hover .bar,.chart .bar-g:focus .bar{fill:#20262D}
.chart .bar-g:hover .bar.today{fill:var(--action-press)}
.chart .grid{stroke:var(--line);stroke-width:1}
.chart .axis{fill:var(--muted);font-size:14px}
.chart .val{fill:var(--ink);font-size:15px;font-weight:700}
.chart .bar-g:focus{outline:none}
.chart .bar-g:focus .bar{stroke:var(--ink);stroke-width:2}
.chart-note{margin:.4rem 0 0;color:var(--muted);font-size:var(--step--1)}
details.table-view{margin-top:.5rem;font-size:var(--step--1)}
details.table-view summary{cursor:pointer;color:var(--muted)}
.prog{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1rem}
.prog p{margin:0}
.prog .line{display:flex;justify-content:space-between;gap:1rem;font-size:1rem}
.track{height:14px;background:#DDE2E8;border-radius:4px;margin-top:.35rem;overflow:hidden}
.fill{height:100%;background:var(--grey-deep);border-radius:4px 0 0 4px}
@media (max-width:72rem){.tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-2{grid-template-columns:1fr}}
@media (max-width:52rem){
  .shell{grid-template-columns:minmax(0,1fr);background:var(--paper)}
  .side{position:static;height:auto;padding:.75rem 1rem;flex-direction:row;flex-wrap:wrap;align-items:center}
  .side .office,.me p{display:none}
  .side{min-width:0}
  .nav{flex-direction:row;overflow-x:auto;width:100%;min-width:0;order:3;padding-bottom:.25rem}
  .nav a{white-space:nowrap}
  .me{margin:0 0 0 auto;padding:0;border:0}
  .tbl thead{display:none}
  .tbl tr{display:block;border-bottom:1px solid var(--line);padding:.5rem 0}
  .tbl td{display:block;border:0;padding:.2rem 0;text-align:left}
  .acts{text-align:left;white-space:normal}.acts .btn{margin:.3rem .3rem 0 0}
}
@media (max-width:30rem){.tiles{grid-template-columns:1fr}}
@media print{.side,.demo,.acts,.toolbar{display:none!important}.shell{display:block}}
@media (min-width:52.01rem){.ss-top{left:17rem}}
CSS;
    }

    private static function js(): string
    {
        return <<<'JS'
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('ss-config').textContent);
  var $ = function (id) { return document.getElementById(id); };

  function api(method, route, body, isForm) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
    if (body && !isForm) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    if (isForm) { opts.body = body; }
    return fetch(cfg.rest + route, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || 'The server did not answer properly.'); e.code = j.code; throw e; }
        return j;
      });
    }, function () { throw new Error('No connection. Check the network, then try again.'); });
  }

  function toast(msg, isErr) {
    var t = $('toast'); t.textContent = msg; t.className = 'toast' + (isErr ? ' err' : ''); t.hidden = false;
    t.setAttribute('tabindex', '-1'); t.focus();
  }
  function done(msg) { try { sessionStorage.setItem('ss_toast', msg); } catch (e) {} location.reload(); }
  try { var m = sessionStorage.getItem('ss_toast'); if (m) { sessionStorage.removeItem('ss_toast'); toast(m); } } catch (e) {}

  function showQr(payload, name) {
    if (!window.qrcode) { toast('The QR library did not load. Check the connection and reload.', true); return; }
    var q = qrcode(0, 'M'); q.addData(payload); q.make();
    $('qr-title').textContent = 'QR code for ' + name;
    $('qr-box').innerHTML = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    $('qr').showModal();
  }
  $('qr-close').addEventListener('click', function () { $('qr').close(); if ($('qr').dataset.reload) { location.reload(); } });
  $('qr-print').addEventListener('click', function () {
    var w = window.open('', '_blank', 'width=420,height=520');
    if (!w) return;
    w.document.write('<title>QR</title><body style="font-family:sans-serif;text-align:center"><h2></h2>' + $('qr-box').innerHTML + '</body>');
    w.document.querySelector('h2').textContent = $('qr-title').textContent; w.document.close(); w.focus(); w.print();
  });

  var pending = null;
  function ask(btn) {
    var field = btn.dataset.ask;
    $('ask-title').textContent = btn.dataset.title || btn.textContent;
    $('ask-label').textContent = btn.dataset.question;
    $('ask-error').textContent = '';
    var input = $('ask-input');
    input.value = '';
    if (field === 'quantity' || field === 'amount') { input.rows = 1; input.setAttribute('inputmode', 'decimal'); } else { input.rows = 3; input.removeAttribute('inputmode'); }
    pending = btn;
    $('ask').showModal(); input.focus();
  }
  $('ask-form').addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.value === 'cancel') { pending = null; return; }
    e.preventDefault();
    var btn = pending, field = btn.dataset.ask, v = $('ask-input').value.trim();
    if (!v) { $('ask-error').textContent = 'This is required.'; return; }
    if ((field === 'quantity' && !/^\d+$/.test(v)) || (field === 'amount' && !/^\d+(\.\d{1,2})?$/.test(v.replace(/,/g, '')))) {
      $('ask-error').textContent = field === 'quantity' ? 'Enter a whole number.' : 'Enter an amount like 25000 or 25000.50.'; return;
    }
    var body = JSON.parse(btn.dataset.body || '{}');
    body[field] = field === 'amount' ? v.replace(/,/g, '') : v;
    $('ask-ok').disabled = true;
    api('POST', btn.dataset.route, body).then(function (r) { done(r.message); }, function (err) {
      $('ask-ok').disabled = false; $('ask-error').textContent = err.message;
    });
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn || (btn.dataset.act !== 'post' && btn.dataset.act !== 'qr-show')) return;
    if (btn.dataset.act === 'qr-show') {
      btn.disabled = true;
      api('GET', 'console/seniors/' + btn.dataset.id + '/qr').then(function (r) {
        btn.disabled = false;
        if (r.qr_payload) showQr(r.qr_payload, btn.dataset.name); else toast('This senior has no active QR code.', true);
      }, function (err) { btn.disabled = false; toast(err.message, true); });
      return;
    }
    if (btn.dataset.ask) { ask(btn); return; }
    if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) return;
    btn.disabled = true; var label = btn.textContent; btn.textContent = 'Working…';
    api('POST', btn.dataset.route, JSON.parse(btn.dataset.body || '{}')).then(function (r) {
      if (btn.dataset.after === 'qr' && r.qr_payload) {
        try { sessionStorage.setItem('ss_toast', r.message); } catch (e2) {}
        $('qr').dataset.reload = '1'; showQr(r.qr_payload, btn.dataset.name); return;
      }
      done(r.message);
    }, function (err) { btn.disabled = false; btn.textContent = label; toast(err.message, true); });
  });

  // Replace a lost or stolen QR code, then optionally email the new one (picture drawn here, sent by the server).
  var qrr = $('qrr'), qrrBtn = null;
  function qrPng(payload, ref) {
    var q = qrcode(0, 'M'); q.addData(payload); q.make();
    var n = q.getModuleCount(), cell = Math.max(6, Math.floor(400 / n)), m = 4 * cell, size = n * cell + 2 * m;
    var c = document.createElement('canvas'); c.width = size; c.height = size + 56;
    var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.fillStyle = '#22272E';
    for (var r = 0; r < n; r++) for (var k = 0; k < n; k++) if (q.isDark(r, k)) g.fillRect(m + k * cell, m + r * cell, cell, cell);
    g.fillStyle = '#F2B705'; g.fillRect(0, size, c.width, 56);
    g.fillStyle = '#22272E'; g.font = 'bold 22px Arial, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillText('SweetSen  ·  ' + ref, c.width / 2, size + 28);
    return c.toDataURL('image/png');
  }
  if (qrr) {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act="qr-replace"]'); if (!b) return;
      qrrBtn = b; $('qrr-form').reset(); $('qrr-error').textContent = ''; $('qrr-name').textContent = b.dataset.name;
      var box = $('qrr-mail'), txt = $('qrr-mail-text'), note = $('qrr-mail-note');
      box.checked = false; box.disabled = true; note.hidden = true; txt.textContent = 'Checking for an email on file…';
      qrr.showModal();
      api('GET', 'console/seniors/' + b.dataset.id + '/qr/email').then(function (r) {
        if (!r.email) { txt.textContent = 'No email on file.'; note.textContent = 'Print a new QR card from the QR cards page instead.'; note.hidden = false; return; }
        txt.textContent = 'Also email the new code to ' + r.email + (r.source === 'registration' ? ' (from the website registration)' : '');
        if (r.demo) { note.textContent = 'This is a demo address and cannot receive email. Print a new card instead.'; note.hidden = false; return; }
        box.disabled = false; box.checked = true;
      }, function () { txt.textContent = 'Could not check for an email.'; });
    });
    $('qrr-cancel').addEventListener('click', function () { qrr.close(); });
    $('qrr-form').addEventListener('submit', function (e) {
      e.preventDefault(); if (!qrrBtn) return;
      if (!window.qrcode) { $('qrr-error').textContent = 'The QR library did not load. Check the connection and reload.'; return; }
      var b = qrrBtn, go = $('qrr-go'), mail = $('qrr-mail').checked && !$('qrr-mail').disabled;
      go.disabled = true; go.textContent = 'Replacing…'; $('qrr-error').textContent = '';
      api('POST', 'console/seniors/' + b.dataset.id + '/qr', { reason: $('qrr-reason').value }).then(function (r) {
        var finish = function (msg, isErr) {
          qrr.close(); go.disabled = false; go.textContent = 'Replace QR code';
          toast(msg, isErr); $('qr').dataset.reload = '1'; showQr(r.qr_payload, b.dataset.name);
        };
        if (!mail) { finish('New QR code made. The old one no longer works.'); return; }
        go.textContent = 'Emailing…';
        api('POST', 'console/seniors/' + b.dataset.id + '/qr/email', { image: qrPng(r.qr_payload, b.dataset.ref) })
          .then(function (m) { finish('New QR code made. ' + m.message); },
                function (err) { finish('New QR code made, but it was not emailed: ' + err.message, true); });
      }, function (err) { go.disabled = false; go.textContent = 'Replace QR code'; $('qrr-error').textContent = err.message; });
    });
  }

  var rv = $('review'), rvForm = $('rv-form'), rvId = '';
  if (rv) {
    var el = function (tag, text, cls) { var n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
    var syncVerify = function () {
      var ok = $('rv-idtype').value !== '' && Array.prototype.every.call(rvForm.querySelectorAll('#rv-items input'), function (c) { return c.checked; });
      $('rv-verify').disabled = !ok;
    };
    var openReview = function (btn) {
      rvId = btn.dataset.id; rvForm.reset(); $('rv-error').textContent = '';
      $('rv-loading').hidden = false; $('rv-body').hidden = true; $('rv-verify').hidden = true; $('rv-verify').disabled = true;
      $('rv-title').textContent = 'Senior record'; $('rv-ref').textContent = ''; $('rv-status').textContent = '';
      rv.showModal();
      api('GET', 'console/seniors/' + rvId + '/review').then(function (d) {
        $('rv-title').textContent = d.name; $('rv-ref').textContent = d.ref_no;
        var pill = el('span', d.status === 'verified' ? 'Verified' : 'To verify', 'pill ' + (d.status === 'verified' ? 'ok' : 'todo'));
        $('rv-status').replaceChildren(pill);
        var dl = $('rv-details'); dl.replaceChildren();
        Object.keys(d.details).forEach(function (k) { dl.append(el('dt', k), el('dd', d.details[k])); });
        $('rv-origin').replaceChildren(el('strong', d.origin.label), d.origin.detail ? el('span', '. ' + d.origin.detail) : '');
        var ul = $('rv-similar'); ul.replaceChildren();
        d.similar.forEach(function (s) { ul.append(el('li', s.ref_no + ': ' + s.name + ', born ' + s.born + ', ' + s.barangay + (s.verified ? ' (verified)' : ' (to verify)'))); });
        $('rv-similar-box').hidden = d.similar.length === 0;
        var vd = $('rv-verified'), canVerify = btn.dataset.canVerify === '1' && d.status !== 'verified';
        vd.hidden = !d.verified;
        if (d.verified) { vd.replaceChildren(el('strong', 'Verified on ' + d.verified.on + ' by ' + d.verified.by + '.'), el('span', ' ID checked: ' + d.verified.id_type + '.')); }
        $('rv-check').hidden = !canVerify; $('rv-verify').hidden = !canVerify;
        if (canVerify) {
          var sel = $('rv-idtype'); sel.replaceChildren(el('option', 'Choose the ID type'));
          sel.firstChild.value = '';
          Object.keys(d.id_types).forEach(function (k) { var o = el('option', d.id_types[k]); o.value = k; sel.append(o); });
          var items = $('rv-items'); items.replaceChildren();
          Object.keys(d.checks).forEach(function (k) {
            var lab = el('label', undefined, 'rv-item'), cb = document.createElement('input');
            cb.type = 'checkbox'; cb.name = k; lab.append(cb, el('span', d.checks[k])); items.append(lab);
          });
        }
        $('rv-loading').hidden = true; $('rv-body').hidden = false; syncVerify();
      }, function (err) { $('rv-loading').hidden = true; $('rv-error').textContent = err.message; });
    };
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-act="senior-review"]'); if (b) openReview(b); });
    rvForm.addEventListener('change', syncVerify);
    $('rv-close').addEventListener('click', function () { rv.close(); });
    rvForm.addEventListener('submit', function (e) {
      e.preventDefault(); if ($('rv-verify').disabled) return;
      var body = { id_type: $('rv-idtype').value };
      rvForm.querySelectorAll('#rv-items input').forEach(function (c) { body[c.name] = c.checked; });
      var b = $('rv-verify'); b.disabled = true; b.textContent = 'Verifying…'; $('rv-error').textContent = '';
      api('POST', 'console/seniors/' + rvId + '/verify', body).then(function (r) { done(r.message); },
        function (err) { b.disabled = false; b.textContent = 'Verify senior'; $('rv-error').textContent = err.message; });
    });
  }

  var pd = $('prog'), pform = $('prog-form');
  if (pd) {
    var showType = function () {
      var t = $('pf-type').value;
      pform.querySelector('[data-cash]').hidden = t !== 'cash';
      pform.querySelector('[data-items]').hidden = t === 'cash';
      $('pf-unit').placeholder = t === 'service' ? 'session' : 'pack';
    };
    $('pf-type').addEventListener('change', showType);
    var openProg = function (p) {
      pform.reset(); $('prog-error').textContent = '';
      var locked = p && p.status === 'published';
      $('prog-title').textContent = p ? 'Edit program' : 'New program';
      $('prog-save').textContent = p ? 'Save changes' : 'Save as draft';
      $('prog-locked').hidden = !locked;
      pform.dataset.id = p ? p.id : '';
      if (p) { ['name','description','category','benefit_type','unit_amount','unit_quantity','unit_label','claim_period','starts_on','ends_on','funding_source'].forEach(function (k) {
        var el = pform.elements[k]; if (el) el.value = p[k] || (k === 'unit_quantity' ? '1' : '');
      }); }
      pform.querySelectorAll('[data-lock]').forEach(function (el) { el.disabled = !!locked; });
      showType(); pd.showModal(); $('pf-name').focus();
    };
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act="prog-new"],[data-act="prog-edit"]');
      if (!b) return;
      openProg(b.dataset.act === 'prog-edit' ? JSON.parse(b.dataset.program) : null);
    });
    $('prog-cancel').addEventListener('click', function () { pd.close(); });
    pform.addEventListener('submit', function (e) {
      e.preventDefault();
      $('prog-error').textContent = '';
      var body = {};
      ['name','description','category','benefit_type','unit_amount','unit_quantity','unit_label','claim_period','starts_on','ends_on','funding_source'].forEach(function (k) {
        var el = pform.elements[k]; if (el) body[k] = el.value.trim();
      });
      var miss = !body.name ? 'pf-name' : !body.category ? 'pf-category' : !body.starts_on ? 'pf-start' : !body.ends_on ? 'pf-end' : '';
      if (miss) { $('prog-error').textContent = 'Fill in the required fields.'; $(miss).focus(); return; }
      var save = $('prog-save'), label = save.textContent; save.disabled = true; save.textContent = 'Saving…';
      api('POST', pform.dataset.id ? 'console/programs/' + pform.dataset.id : 'console/programs', body).then(function (r) { done(r.message); },
        function (err) { save.disabled = false; save.textContent = label; $('prog-error').textContent = err.message; });
    });
  }

  var fl = $('fl-form');
  if (fl) {
    fl.addEventListener('submit', function (e) {
      e.preventDefault();
      $('fl-error').textContent = '';
      var body = {};
      fl.querySelectorAll('.fl-box').forEach(function (box) {
        var v = {};
        box.querySelectorAll('input[name]').forEach(function (el) { v[el.name] = el.type === 'checkbox' ? el.checked : el.value.trim(); });
        body[box.dataset.kind] = v;
      });
      var save = $('fl-save'); save.disabled = true; save.textContent = 'Saving…';
      api('POST', 'console/form-limits', body).then(function (r) { done(r.message); },
        function (err) { save.disabled = false; save.textContent = 'Save form settings'; $('fl-error').textContent = err.message; });
    });
  }

  var form = $('import-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var b = form.querySelector('button'); b.disabled = true; b.textContent = 'Importing…';
      api('POST', 'console/import', new FormData(form), true).then(function (r) { done(r.message); },
        function (err) { b.disabled = false; b.textContent = 'Import file'; toast(err.message, true); });
    });
  }
})();
JS;
    }
}
