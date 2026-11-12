<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Domain\SelfService;
use SweetSen\Support\DomainError;
use SweetSen\Tenancy\Actor;

/**
 * The senior's own page: QR code to show at the desk, benefits ready to claim, applications,
 * programs open to them, and what they have received. Phone first, large type, one column.
 */
final class SeniorHome
{
    /** status => [label, plain explanation, step reached (1-3)] */
    private const STATUS = [
        'submitted' => ['Sent', 'The office has your application and will review it.', 1],
        'under_review' => ['Being reviewed', 'The office is checking your application now.', 2],
        'info_needed' => ['More information needed', 'The office needs something from you before it can decide.', 2],
        'approved' => ['Approved', 'Your benefit is listed under "Ready to claim" or "Already received".', 3],
        'rejected' => ['Not approved', '', 3],
    ];

    public static function render(Actor $actor): void
    {
        try {
            $o = SelfService::overview($actor);
        } catch (DomainError $e) {
            Layout::message('We could not find your record', $e->getMessage(), $e->reason === 'no_record' ? 404 : 403);
            return;
        }
        $me = $o['me'];
        $e = static fn(string $s): string => esc_html($s);
        $first = $me['first_name'];
        $full = trim($me['first_name'] . ' ' . ($me['middle_name'] ? mb_substr($me['middle_name'], 0, 1) . '. ' : '') . $me['last_name'] . ' ' . ($me['suffix'] ?? ''));

        $html = '<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>'
            . '<h1>Hello, ' . $e($first) . '</h1>'
            . '<p class="lede">Senior ID <span class="ref">' . $e($me['ref_no']) . '</span> &middot; ' . $e($me['barangay']) . '</p>';

        // QR code, or why there is none yet.
        if ($o['qr'] !== null) {
            $html .= '<section class="card qr-card" aria-labelledby="qr-h"><h2 id="qr-h">My QR code</h2>'
                . '<div id="qr" class="qr" role="img" aria-label="Your QR code for the release desk"><p class="qr-wait">Loading your QR code&hellip;</p></div>'
                . '<p class="qr-name">' . $e($full) . '<br><span class="ref">' . $e($me['ref_no']) . '</span></p>'
                . '<p>Show this at the release desk to claim your benefits. It does not contain your personal details.</p>'
                . '<div class="stack"><button class="btn primary full" type="button" id="big">Show it bigger</button>'
                . '<button class="btn full" type="button" id="print">Print my QR card</button></div></section>';
        } elseif ($me['verification_status'] !== 'verified') {
            $html .= '<section class="card notice" aria-labelledby="qr-h"><h2 id="qr-h">Your record is waiting to be checked</h2>'
                . '<p>Visit the senior citizens\' office (OSCA) and bring an ID. After your record is checked, your QR code will appear here.</p></section>';
        } else {
            $html .= '<section class="card notice" aria-labelledby="qr-h"><h2 id="qr-h">No QR code yet</h2>'
                . '<p>Your record is checked. The office will give you a QR code; it will appear here.</p></section>';
        }

        // Ready to claim.
        $ready = '';
        foreach ($o['ready'] as $r) {
            $ready .= '<li class="card ready"><p class="big">' . $e($r['program']) . '</p><p class="amount">' . $e($r['what']) . '</p>'
                . '<p>Claim by <strong>' . $e(self::date($r['valid_until'])) . '</strong>.</p>'
                . '<p>' . ($r['location'] !== '' ? 'Where: ' . $e($r['location']) . '. ' : '') . 'Bring your QR code to the release desk.</p></li>';
        }
        $html .= '<section aria-labelledby="ready-h"><h2 id="ready-h">Ready to claim</h2>'
            . ($ready === '' ? '<p class="empty">Nothing to claim right now.</p>' : '<ul class="list">' . $ready . '</ul>') . '</section>';

        // Applications.
        $apps = '';
        foreach ($o['applications'] as $a) {
            [$label, $explain, $step] = self::STATUS[$a['status']] ?? [ucfirst($a['status']), '', 1];
            $steps = '';
            foreach (['Sent', 'Reviewed', 'Decided'] as $i => $s) {
                $steps .= '<li' . ($i < $step ? ' class="done"' : '') . '>' . $s . '</li>';
            }
            $acts = '';
            if ($a['status'] === 'submitted') {
                $acts = '<button class="btn full" type="button" data-route="me/applications/' . esc_attr($a['id']) . '/withdraw"'
                    . ' data-confirm-title="Withdraw this application?" data-confirm="You can apply again later while the program is open." data-ok="Yes, withdraw">Withdraw</button>';
            } elseif ($a['status'] === 'info_needed') {
                $acts = '<button class="btn primary full" type="button" data-route="me/applications/' . esc_attr($a['id']) . '/resubmit"'
                    . ' data-confirm-title="Did you give the office what it asked for?" data-confirm="The office will then look at your application again." data-ok="Yes, I gave it">I have given what was asked</button>';
            }
            $apps .= '<li class="card app"><p class="big">' . $e($a['program']) . '</p>'
                . '<p class="status s-' . esc_attr($a['status']) . '"><strong>' . $e($label) . '</strong></p>'
                . '<ol class="steps" aria-hidden="true">' . $steps . '</ol>'
                . ($explain !== '' ? '<p>' . $e($explain) . '</p>' : '')
                . ($a['note'] !== '' ? '<p class="office-note">' . $e($a['note']) . '</p>' : '')
                . '<p class="sub">Sent ' . $e($a['submitted']) . ($a['decided'] !== '' ? ', decided ' . $e($a['decided']) : '') . '. Ref <span class="ref">' . $e($a['ref_no']) . '</span></p>'
                . $acts . '</li>';
        }
        $html .= '<section aria-labelledby="apps-h"><h2 id="apps-h">My applications</h2>'
            . ($apps === '' ? '<p class="empty">You have not applied for anything yet.</p>' : '<ul class="list">' . $apps . '</ul>') . '</section>';

        // Programs.
        $progs = '';
        foreach ($o['programs'] as $p) {
            $btn = $p['can_apply']
                ? '<button class="btn primary full" type="button" data-route="me/apply" data-body="' . esc_attr((string) wp_json_encode(['program_id' => $p['id']])) . '"'
                    . ' data-confirm-title="' . esc_attr('Apply for ' . $p['name'] . '?') . '" data-confirm="The office will review your application. You will see the answer on this page." data-ok="Yes, apply">Apply</button>'
                : '<p class="why">' . $e($p['why_not']) . '</p>';
            $progs .= '<li class="card program"><p class="big">' . $e($p['name']) . '</p>'
                . '<p class="amount">' . $e($p['what']) . ' <span class="often">' . $e($p['how_often']) . '</span></p>'
                . ($p['description'] !== '' ? '<p class="sub">' . $e($p['description']) . '</p>' : '') . $btn . '</li>';
        }
        $unverifiedHint = $me['verification_status'] !== 'verified'
            ? '<p class="sub">You can apply now. The office can only approve after your record is checked.</p>' : '';
        $html .= '<section aria-labelledby="prog-h"><h2 id="prog-h">Programs you can apply for</h2>' . $unverifiedHint
            . ($progs === '' ? '<p class="empty">No programs are open right now.</p>' : '<ul class="list">' . $progs . '</ul>') . '</section>';

        // Received.
        $got = '';
        foreach ($o['received'] as $r) {
            $got .= '<li><span class="strong">' . $e($r['program']) . '</span><span class="got-what">' . $e($r['what']) . '</span>'
                . '<span class="sub">Received ' . $e($r['date']) . '. Ref <span class="ref">' . $e((string) $r['ref_no']) . '</span></span></li>';
        }
        $html .= '<section aria-labelledby="got-h"><h2 id="got-h">Already received</h2>'
            . ($got === '' ? '<p class="empty">Nothing yet.</p>' : '<ul class="received">' . $got . '</ul>') . '</section>';

        // Details.
        $html .= '<details class="card mine"><summary>My details</summary><dl>'
            . '<dt>Name</dt><dd>' . $e($full) . '</dd>'
            . '<dt>Senior ID</dt><dd class="ref">' . $e($me['ref_no']) . '</dd>'
            . '<dt>Birthday</dt><dd>' . $e(self::date($me['birthdate'])) . '</dd>'
            . '<dt>Barangay</dt><dd>' . $e($me['barangay']) . '</dd>'
            . '<dt>Record</dt><dd>' . ($me['verification_status'] === 'verified' ? 'Checked by the office' : 'Waiting to be checked') . '</dd>'
            . '</dl><p class="sub">If something is wrong, tell the senior citizens\' office. Only the office can change your details.</p></details>'
            . '<p class="help">Need help? Ask a family member, or visit the senior citizens\' office.</p>';

        $html .= self::dialogs($full, $me['ref_no']);

        Layout::page('My benefits', 'My benefits', $html, self::css(), self::js(),
            ['qr' => $o['qr']], ['https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js']);
    }

    private static function date(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $d ? $d->format('F j, Y') : $ymd;
    }

    private static function dialogs(string $name, string $ref): string
    {
        return '<dialog id="bigqr" aria-labelledby="bigqr-h"><h2 id="bigqr-h" class="vh">Your QR code</h2>'
            . '<div id="bigqr-box" class="bigqr-box"></div>'
            . '<p class="qr-name">' . esc_html($name) . '<br><span class="ref">' . esc_html($ref) . '</span></p>'
            . '<p class="sub">Tip: turn your screen brightness up.</p>'
            . '<button class="btn primary full" type="button" id="bigqr-close">Done</button></dialog>'
            . '<dialog id="confirm" aria-labelledby="confirm-h"><form method="dialog">'
            . '<h2 id="confirm-h"></h2><p id="confirm-text"></p><p id="confirm-err" class="err" role="alert"></p>'
            . '<div class="stack"><button class="btn primary full" id="confirm-ok" value="ok"></button>'
            . '<button class="btn full" value="cancel" formnovalidate>No, go back</button></div></form></dialog>';
    }

    private static function css(): string
    {
        return <<<'CSS'
.lede{margin:-.25rem 0 1.25rem;color:var(--muted)}
.ref{white-space:nowrap;font-variant-numeric:tabular-nums}
section{margin-top:2rem}
section:first-of-type{margin-top:0}
section h2{margin:0 0 .75rem}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-big);padding:1.25rem}
.card p{margin:.4rem 0}
.list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1rem}
.big{font-size:var(--step-1);font-weight:700;line-height:1.25;margin-top:0!important}
.amount{font-size:var(--step-1);font-weight:700}
.often{display:inline-block;font-size:var(--step--1);font-weight:400;color:var(--muted);margin-left:.4rem}
.sub{color:var(--muted);font-size:var(--step--1)}
.strong{font-weight:700}
.empty{color:var(--muted);margin:.25rem 0}
.stack{display:flex;flex-direction:column;gap:.75rem;margin-top:1rem}
.btn.full{margin-top:.75rem}
.stack .btn.full{margin-top:0}
.qr-card{text-align:center;border:3px solid var(--grey-deep)}
.qr{width:min(17rem,100%);aspect-ratio:1;margin:.5rem auto;display:grid;place-items:center;background:#fff}
.qr svg,.bigqr-box svg{width:100%;height:100%;display:block}
.qr-wait{color:var(--muted)}
.qr-name{font-weight:700;font-size:var(--step-0)}
.qr-name .ref{font-weight:400;color:var(--muted)}
.notice{border-left:10px solid var(--action);background:var(--action-tint)}
.notice h2{font-size:var(--step-0)}
.ready{border-left:10px solid var(--action)}
.status{font-size:var(--step-0)}
.s-info_needed strong{background:var(--action-tint);padding:.1rem .5rem;border-radius:var(--r-small);box-shadow:inset 0 0 0 2px #C99A00}
.steps{list-style:none;display:flex;gap:.35rem;margin:.6rem 0;padding:0;counter-reset:s}
.steps li{flex:1;text-align:center;font-size:.95rem;color:var(--muted);padding-top:.7rem;border-top:6px solid #DDE2E8}
.steps li.done{border-top-color:var(--grey-deep);color:var(--ink);font-weight:700}
.office-note{background:var(--paper);border-radius:var(--r-small);padding:.75rem 1rem;font-style:italic}
.office-note::before{content:"Message from the office: ";font-style:normal;font-weight:700;display:block}
.why{color:var(--muted);margin-top:.75rem!important;padding:.75rem 1rem;border:2px dashed var(--line);border-radius:var(--r-small)}
.received{list-style:none;margin:0;padding:0;background:var(--surface);border:1px solid var(--line);border-radius:var(--r-big)}
.received li{padding:1rem 1.25rem;border-bottom:1px solid var(--line)}
.received li:last-child{border-bottom:0}
.received .strong,.received .got-what,.received .sub{display:block}
.received .got-what{font-weight:700;font-size:var(--step-0)}
.mine{margin-top:2rem}
.mine summary{font-weight:700;font-size:var(--step-0);cursor:pointer;min-height:2.75rem;display:flex;align-items:center}
.mine dl{display:grid;grid-template-columns:max-content 1fr;gap:.4rem 1rem;margin:.75rem 0}
.mine dt{color:var(--muted)}.mine dd{margin:0;font-weight:700}
.help{margin:2rem 0 1rem;color:var(--muted);text-align:center}
.vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.toast{background:var(--grey-deep);color:#fff;padding:1rem 1.25rem;border-radius:var(--r-small);border-left:10px solid var(--action);margin-bottom:1rem;font-weight:700}
.toast.err{border-left-color:var(--refused)}
.err{color:var(--refused);font-weight:700;min-height:1.2em}
dialog{border:0;border-radius:var(--r-big);padding:1.5rem;width:calc(100% - 2rem);max-width:30rem;box-shadow:0 24px 60px rgba(34,39,46,.35)}
dialog::backdrop{background:rgba(34,39,46,.6)}
dialog h2{margin:0 0 .5rem}
#bigqr{max-width:none;width:100vw;height:100dvh;max-height:none;margin:0;border-radius:0;padding:1.25rem;text-align:center;display:none;flex-direction:column;justify-content:center;background:#fff}
#bigqr[open]{display:flex}
.bigqr-box{width:min(92vw,62dvh);aspect-ratio:1;margin:0 auto}
@media print{
  body{background:#fff}
  .bar,.demo,section:not(.qr-card),details,.help,.stack,.toast,.lede,h1{display:none!important}
  .qr-card{border:2px solid #000;max-width:9cm;margin:0 auto}
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

  function toast(msg, isErr) {
    var t = $('toast'); t.textContent = msg; t.className = 'toast' + (isErr ? ' err' : ''); t.hidden = false;
    t.setAttribute('tabindex', '-1'); t.focus(); window.scrollTo(0, 0);
  }
  try { var m = sessionStorage.getItem('ss_toast'); if (m) { sessionStorage.removeItem('ss_toast'); toast(m); } } catch (e) {}

  function drawQr(el) {
    if (!cfg.qr || !el) return;
    if (!window.qrcode) { el.innerHTML = '<p class="qr-wait">Your QR code could not load. Check the internet connection, then reload the page.</p>'; return; }
    var q = qrcode(0, 'M'); q.addData(cfg.qr); q.make();
    el.innerHTML = q.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
  }
  window.addEventListener('load', function () { drawQr($('qr')); });

  var big = $('big');
  if (big) {
    big.addEventListener('click', function () { drawQr($('bigqr-box')); $('bigqr').showModal(); });
    $('bigqr-close').addEventListener('click', function () { $('bigqr').close(); big.focus(); });
    $('print').addEventListener('click', function () { window.print(); });
  }

  var pending = null;
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-route]');
    if (!btn) return;
    pending = btn;
    $('confirm-h').textContent = btn.dataset.confirmTitle || 'Are you sure?';
    $('confirm-text').textContent = btn.dataset.confirm || '';
    $('confirm-ok').textContent = btn.dataset.ok || 'Yes';
    $('confirm-ok').disabled = false;
    $('confirm-err').textContent = '';
    $('confirm').showModal();
  });

  $('confirm').querySelector('form').addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.value === 'cancel') { pending = null; return; }
    e.preventDefault();
    var btn = pending, ok = $('confirm-ok');
    ok.disabled = true; ok.textContent = 'Sending…';
    fetch(cfg.rest + btn.dataset.route, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      body: btn.dataset.body || '{}'
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.message || 'Something went wrong. Please try again.');
        try { sessionStorage.setItem('ss_toast', j.message); } catch (e2) {}
        location.reload();
      });
    }, function () { throw new Error('No internet connection. Please try again.'); })
      .catch(function (err) { ok.disabled = false; ok.textContent = btn.dataset.ok || 'Yes'; $('confirm-err').textContent = err.message; });
  });
})();
JS;
    }
}
