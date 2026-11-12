<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Domain\SponsorView;
use SweetSen\Support\DomainError;
use SweetSen\Tenancy\Actor;

/**
 * Sponsor dashboard: pledges, deliveries, where support went, and program reach.
 * Aggregates only; the page has no access to any senior's identity.
 */
final class SponsorHome
{
    public static function render(Actor $actor): void
    {
        try {
            $d = SponsorView::dashboard($actor);
        } catch (DomainError $e) {
            Layout::message('Sponsor page not available', $e->getMessage(), 403);
            return;
        }
        $e = static fn(string $s): string => esc_html($s);
        $t = $d['totals'];
        $peso = static fn(int $c): string => "\u{20B1}" . number_format($c / 100, $c % 100 === 0 ? 0 : 2);
        $user = wp_get_current_user();

        $tiles = self::tile('Pledged', $t['pledged_items'] > 0 ? number_format($t['pledged_items']) . ' items' : $peso($t['pledged_cash']),
                $t['pledged_items'] > 0 && $t['pledged_cash'] > 0 ? 'and ' . $peso($t['pledged_cash']) . ' in cash' : 'approved and waiting pledges')
            . self::tile('Received by the LGU', number_format($t['received_items']) . ' items',
                ($t['received_cash'] > 0 ? $peso($t['received_cash']) . ' cash, ' : '') . $t['deliveries'] . ' ' . ($t['deliveries'] === 1 ? 'delivery' : 'deliveries'))
            . self::tile('Seniors reached', number_format($t['seniors_reached']), 'through the ' . $t['programs'] . ' ' . ($t['programs'] === 1 ? 'program' : 'programs') . ' you support')
            . self::tile('Waiting for LGU review', (string) $t['waiting_review'], $t['waiting_review'] === 1 ? 'pledge' : 'pledges', $t['waiting_review'] > 0);

        // Where support goes.
        $where = '';
        foreach ($d['programs'] as $p) {
            $yours = $p['benefit_type'] === 'cash'
                ? $peso(SponsorView::centavos((string) $p['assigned_amount'])) . ' from you'
                : number_format((int) $p['assigned_quantity']) . ' ' . ($p['unit_label'] ?: 'item') . 's from you';
            $where .= '<li><p class="line"><span class="strong">' . $e($p['program']) . '</span><span class="yours">' . $e($yours) . '</span></p>'
                . '<p class="reach"><span class="reach-n">' . (int) $p['seniors_reached'] . '</span> ' . ((int) $p['seniors_reached'] === 1 ? 'senior has' : 'seniors have') . ' received this benefit'
                . ((int) $p['waiting'] > 0 ? '<span class="sub">' . (int) $p['waiting'] . ' more approved and waiting to claim</span>' : '') . '</p></li>';
        }
        $whereHtml = $where === '' ? '<p class="empty">Once the LGU records a delivery from you, it shows here with the program it went to.</p>' : '<ul class="where">' . $where . '</ul>';

        // Deliveries.
        $rc = '';
        foreach ($d['receipts'] as $r) {
            $what = $r['amount'] !== null ? $peso(SponsorView::centavos((string) $r['amount'])) : number_format((int) $r['quantity']) . ' items';
            $rc .= '<li><span class="t">' . $e(self::manila((string) $r['received_at'])) . '</span><span><span class="strong">' . $e($what) . '</span> '
                . $e((string) ($r['item_description'] ?? '')) . '<span class="sub">Receipt <span class="ref">' . $e($r['receipt_no']) . '</span>'
                . ($r['programs'] ? ', assigned to ' . $e($r['programs']) : ', not yet assigned') . '</span></span></li>';
        }
        $rcHtml = $rc === '' ? '<p class="empty">No deliveries recorded yet.</p>' : '<ol class="feed">' . $rc . '</ol>';

        // Pledges.
        $rows = '';
        foreach ($d['pledges'] as $p) {
            $cash = $p['kind'] === 'cash';
            $pledged = $cash ? SponsorView::centavos((string) $p['amount']) : (int) $p['quantity'];
            $got = $cash ? SponsorView::centavos((string) $p['received_amount']) : (int) $p['received_quantity'];
            $pct = $pledged > 0 ? min(100, round($got / $pledged * 100)) : 0;
            $what = $cash ? $peso($pledged) . ' cash' : number_format($pledged) . ' x ' . $p['item_description'];
            $prog = $p['status'] === 'approved'
                ? '<p class="pline"><span>' . ($cash ? $e($peso($got)) . ' of ' . $e($peso($pledged)) : number_format($got) . ' of ' . number_format($pledged)) . ' received</span><span>' . $pct . '%</span></p>'
                    . '<div class="track" aria-hidden="true"><div class="fill" style="width:' . $pct . '%"></div></div>'
                : '';
            $rows .= '<tr><td><span class="strong ref">' . $e($p['ref_no']) . '</span><span class="sub">' . $e(self::manila((string) $p['created_at'])) . '</span></td>'
                . '<td>' . $e($what) . '<span class="sub">' . $e((string) ($p['program'] ?? 'Any program')) . '</span></td>'
                . '<td><span class="pill ' . ($p['status'] === 'approved' ? 'ok' : ($p['status'] === 'submitted' ? 'todo' : 'neg')) . '">' . $e(SponsorView::STATUS[$p['status']] ?? $p['status']) . '</span>'
                . ($p['status'] === 'rejected' && $p['review_note'] ? '<span class="sub">' . $e((string) $p['review_note']) . '</span>' : '') . '</td>'
                . '<td class="progress">' . $prog . '</td></tr>';
        }
        $pledgeHtml = $rows === '' ? '<p class="empty">You have not made a pledge yet.</p>'
            : '<table class="tbl"><thead><tr><th>Pledge</th><th>What</th><th>Status</th><th>Delivered</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        $body = '<a class="skip" href="#main">Skip to main content</a>'
            . '<header class="bar"><p class="brand"><span class="mark" aria-hidden="true"></span>SweetSen <span class="desk">Sponsor</span></p>'
            . '<p class="who"><span class="who-name">' . $e($user->display_name) . '</span> <a href="' . esc_url(wp_logout_url(Router::url())) . '">Log out</a></p></header>'
            . '<main id="main" class="wide"><div id="toast" class="toast" role="status" aria-live="polite" hidden></div>'
            . '<div class="head"><div><h1>' . $e($d['org']['name']) . '</h1><p class="lede">Sponsor dashboard'
            . ($d['org']['status'] === 'verified' ? ' &middot; Verified by the LGU' : ' &middot; Waiting for LGU verification') . '</p></div>'
            . '<button class="btn primary" type="button" id="new-pledge">Make a new pledge</button></div>'
            . '<section class="tiles" aria-label="Key figures">' . $tiles . '</section>'
            . '<div class="grid-2">' . self::panel('Where your support goes', $whereHtml) . self::panel('Deliveries received', $rcHtml) . '</div>'
            . self::panel('My pledges', $pledgeHtml)
            . '<p class="privacy"><strong>Privacy.</strong> You see totals only. SweetSen never shows sponsors the names or details of seniors. '
            . '"Seniors reached" counts everyone who received a benefit from the programs you support, whatever the funding source.</p>'
            . '</main>' . self::form($d['open_programs']);

        Layout::bare('Sponsor dashboard', $body, self::css(), self::js());
    }

    private static function tile(string $label, string $value, string $sub, bool $attention = false): string
    {
        return '<div class="tile' . ($attention ? ' attention' : '') . '"><p class="tile-label">' . esc_html($label) . '</p><p class="tile-value">' . esc_html($value)
            . '</p><p class="tile-sub">' . esc_html($sub) . '</p></div>';
    }

    private static function panel(string $title, string $body): string
    {
        return '<section class="panel"><h2>' . esc_html($title) . '</h2>' . $body . '</section>';
    }

    private static function manila(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M j, Y');
    }

    /** @param list<array{id:string, name:string, benefit_type:string}> $programs */
    private static function form(array $programs): string
    {
        $opts = '';
        foreach ($programs as $p) {
            $opts .= '<option value="' . esc_attr($p['id']) . '" data-type="' . esc_attr($p['benefit_type']) . '">' . esc_html($p['name']) . '</option>';
        }
        return '<dialog id="pledge" aria-labelledby="pledge-h"><form id="pledge-form" novalidate>'
            . '<h2 id="pledge-h">New pledge</h2>'
            . '<p class="sub">A pledge is a promise. The LGU reviews it, then records each delivery when it arrives.</p>'
            . '<fieldset><legend>What will you give?</legend>'
            . '<label class="opt"><input type="radio" name="kind" value="in_kind" checked> Goods</label>'
            . '<label class="opt"><input type="radio" name="kind" value="cash"> Cash</label></fieldset>'
            . '<label for="pl-program">For which program?</label><select id="pl-program" name="program_id" required>' . $opts . '</select>'
            . '<div id="goods"><label for="pl-what">Describe the goods</label><input id="pl-what" name="item_description" maxlength="200" placeholder="For example: grocery packs with rice and canned goods">'
            . '<label for="pl-qty">How many?</label><input id="pl-qty" name="quantity" inputmode="numeric" pattern="[0-9]*" placeholder="100"></div>'
            . '<div id="cash" hidden><label for="pl-amount">Amount in pesos</label><input id="pl-amount" name="amount" inputmode="decimal" placeholder="25000"></div>'
            . '<p id="pledge-err" class="err" role="alert"></p>'
            . '<div class="dlg-actions"><button class="btn" type="button" id="pledge-cancel">Cancel</button><button class="btn primary" type="submit" id="pledge-ok">Send pledge</button></div>'
            . '</form></dialog>';
    }

    private static function css(): string
    {
        return <<<'CSS'
main.wide{max-width:72rem;padding:1.5rem clamp(1rem,3vw,2.5rem) 3rem;display:flex;flex-direction:column;gap:1.25rem}
.head{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap}
.head h1{margin:0}
.lede{margin:.25rem 0 0;color:var(--muted)}
.ref{white-space:nowrap}
.tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:1rem 1.25rem}
.tile.attention{border-left:8px solid var(--action)}
.tile-label{margin:0;color:var(--muted);font-size:var(--step--1)}
.tile-value{margin:.15rem 0;font-size:clamp(1.8rem,1.5rem + 1.2vw,2.5rem);font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums}
.tile-sub{margin:0;font-size:var(--step--1);color:var(--muted)}
.grid-2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:1.25rem}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:1.1rem 1.25rem;min-width:0}
.panel h2{margin:0 0 .75rem;font-size:var(--step-0)}
.strong{font-weight:700;display:block}
.sub{display:block;color:var(--muted);font-size:.92rem}
.empty{margin:.25rem 0;color:var(--muted)}
.where{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1rem}
.where li{padding-bottom:1rem;border-bottom:1px solid var(--line)}
.where li:last-child{border-bottom:0;padding-bottom:0}
.where p{margin:0}
.line{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.line .strong{display:inline}
.yours{font-weight:700;background:var(--action-tint);padding:0 .5rem;border-radius:var(--r-small)}
.reach{margin-top:.4rem!important}
.reach-n{font-size:var(--step-1);font-weight:700;font-variant-numeric:tabular-nums}
.feed{list-style:none;margin:0;padding:0}
.feed li{display:grid;grid-template-columns:7.5rem 1fr;gap:.75rem;padding:.55rem 0;border-bottom:1px solid var(--line);font-size:1rem}
.feed li:last-child{border-bottom:0}
.feed .t{color:var(--muted);font-variant-numeric:tabular-nums}
.feed .strong{display:inline}
.tbl{width:100%;border-collapse:collapse;font-size:1.02rem}
.tbl th{text-align:left;font-weight:700;color:var(--muted);font-size:var(--step--1);padding:.5rem .6rem;border-bottom:2px solid var(--line)}
.tbl td{padding:.7rem .6rem;border-bottom:1px solid var(--line);vertical-align:top}
.tbl tr:last-child td{border-bottom:0}
.progress{min-width:14rem}
.pline span:first-child{white-space:nowrap}
.pline{display:flex;justify-content:space-between;gap:1rem;margin:0;font-size:.95rem;font-variant-numeric:tabular-nums}
.track{height:12px;background:#DDE2E8;border-radius:4px;margin-top:.35rem;overflow:hidden}
.fill{height:100%;background:var(--grey-deep)}
.pill{display:inline-block;padding:.1rem .6rem;border-radius:999px;border:1.5px solid var(--muted);font-size:.9rem;font-weight:700;white-space:nowrap}
.pill.ok{background:var(--grey-deep);border-color:var(--grey-deep);color:#fff}
.pill.todo{background:var(--action-tint);border-color:#C99A00}
.pill.neg{border-style:dashed}
.privacy{margin:0;color:var(--muted);font-size:var(--step--1);max-width:52rem}
.toast{background:var(--grey-deep);color:#fff;padding:.9rem 1.1rem;border-radius:var(--r-small);border-left:8px solid var(--action);font-weight:700}
dialog{border:0;border-radius:14px;padding:1.5rem;max-width:32rem;width:calc(100% - 2rem);box-shadow:0 24px 60px rgba(34,39,46,.35)}
dialog::backdrop{background:rgba(34,39,46,.55)}
dialog h2{margin:0 0 .25rem}
fieldset{border:0;margin:1rem 0 0;padding:0;display:flex;gap:.75rem;flex-wrap:wrap}
legend{font-weight:700;margin-bottom:.35rem;padding:0}
.opt{display:flex;align-items:center;gap:.5rem;margin:0;font-weight:400;border:2px solid var(--line);border-radius:var(--r-small);padding:.6rem 1rem;min-height:3rem;cursor:pointer}
.opt:has(input:checked){border-color:var(--ink);background:var(--action-tint);font-weight:700}
.opt input{width:1.3rem;height:1.3rem;min-height:0;margin:0;accent-color:var(--grey-deep)}
select{width:100%;font:inherit;padding:.7rem .9rem;min-height:3.25rem;border:2px solid var(--muted);border-radius:var(--r-small);background:var(--surface);color:var(--ink)}
.dlg-actions{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1rem}
.err{color:var(--refused);font-weight:700;margin:.5rem 0 0;min-height:1.2em}
@media (max-width:64rem){.tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-2{grid-template-columns:1fr}}
@media (max-width:40rem){
  .tiles{grid-template-columns:1fr}
  .head .btn{width:100%}
  .tbl thead{display:none}
  .tbl tr{display:block;border-bottom:1px solid var(--line);padding:.5rem 0}
  .tbl td{display:block;border:0;padding:.2rem 0}
  .feed li{grid-template-columns:1fr;gap:0}
}
CSS;
    }

    private static function js(): string
    {
        return <<<'JS'
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('ss-config').textContent);
  var $ = function (id) { return document.getElementById(id); };
  try { var m = sessionStorage.getItem('ss_toast'); if (m) { sessionStorage.removeItem('ss_toast'); var t = $('toast'); t.textContent = m; t.hidden = false; t.setAttribute('tabindex', '-1'); t.focus(); } } catch (e) {}

  var dlg = $('pledge'), form = $('pledge-form');
  function kind() { return form.querySelector('input[name=kind]:checked').value; }
  function sync() { var cash = kind() === 'cash'; $('cash').hidden = !cash; $('goods').hidden = cash; $('pledge-err').textContent = ''; }
  form.querySelectorAll('input[name=kind]').forEach(function (r) { r.addEventListener('change', sync); });
  $('new-pledge').addEventListener('click', function () { form.reset(); sync(); $('pledge-ok').disabled = false; dlg.showModal(); });
  $('pledge-cancel').addEventListener('click', function () { dlg.close(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('pledge-err'), body = { kind: kind(), program_id: $('pl-program').value };
    if (body.kind === 'cash') {
      body.amount = $('pl-amount').value.replace(/,/g, '').trim();
      if (!/^\d+(\.\d{1,2})?$/.test(body.amount)) { err.textContent = 'Enter an amount like 25000 or 25000.50.'; $('pl-amount').focus(); return; }
    } else {
      body.item_description = $('pl-what').value.trim();
      body.quantity = $('pl-qty').value.trim();
      if (!body.item_description) { err.textContent = 'Describe the goods in a few words.'; $('pl-what').focus(); return; }
      if (!/^\d+$/.test(body.quantity) || +body.quantity < 1) { err.textContent = 'Enter how many, as a whole number.'; $('pl-qty').focus(); return; }
    }
    var ok = $('pledge-ok'); ok.disabled = true; ok.textContent = 'Sending…';
    fetch(cfg.rest + 'sponsor/pledges', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.message || 'Something went wrong. Please try again.');
        try { sessionStorage.setItem('ss_toast', j.message); } catch (e2) {}
        location.reload();
      });
    }, function () { throw new Error('No connection. Please try again.'); })
      .catch(function (x) { ok.disabled = false; ok.textContent = 'Send pledge'; err.textContent = x.message; });
  });
})();
JS;
    }
}
