<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Tenancy\Actor;

/**
 * Release desk: scan -> confirm -> released / refused.
 * The outcome fills the whole screen so it can be read at arm's length in daylight.
 */
final class ReleaseDesk
{
    public static function render(Actor $actor): void
    {
        $body = <<<'HTML'
<section id="v-scan" aria-labelledby="h-scan">
  <h1 id="h-scan">Scan the senior's QR code</h1>
  <div class="viewer" id="viewer">
    <video id="cam" playsinline muted aria-label="Camera view"></video>
    <div class="frame" aria-hidden="true"></div>
    <p id="cam-msg" class="cam-msg">Camera is off</p>
  </div>
  <button id="cam-btn" class="btn primary full" type="button">Start camera</button>

  <form id="code-form" class="block" autocomplete="off">
    <label for="code">Using a handheld scanner? Scan into this box</label>
    <div class="row">
      <input id="code" name="code" spellcheck="false" autocapitalize="off">
      <button class="btn" type="submit">Check code</button>
    </div>
  </form>

  <details id="manual" class="block">
    <summary>No QR code? Find the senior another way</summary>
    <form id="find-form" autocomplete="off">
      <label for="ref">SweetSen reference number</label>
      <input id="ref" name="ref_no" placeholder="SS-2026-000123" spellcheck="false">
      <p class="or">or</p>
      <label for="last">Last name</label>
      <input id="last" name="last_name">
      <label for="dob">Birthdate</label>
      <input id="dob" name="birthdate" type="date">
      <button class="btn full" type="submit">Find senior</button>
    </form>
    <div id="find-results" aria-live="polite"></div>
  </details>

  <section class="tally" aria-labelledby="h-tally">
    <h2 id="h-tally">Released by you today: <span id="tally-count">0</span></h2>
    <ol id="tally-list" class="tally-list"></ol>
  </section>
</section>

<section id="v-confirm" aria-labelledby="who-name" hidden>
  <button id="back-btn" class="btn back" type="button">Back to scanning</button>
  <div class="person">
    <h1 id="who-name"></h1>
    <dl class="facts">
      <div><dt>Reference</dt><dd id="who-ref"></dd></div>
      <div><dt>Barangay</dt><dd id="who-brgy"></dd></div>
      <div><dt>Age</dt><dd id="who-age"></dd></div>
    </dl>
    <p class="check">Compare with the senior's OSCA ID before you release.</p>
  </div>
  <div id="reason-box" hidden>
    <label for="reason">Why is this a manual release? (required)</label>
    <textarea id="reason" rows="2" placeholder="Example: QR card left at home, ID checked"></textarea>
  </div>
  <h2>Ready to release today</h2>
  <div id="benefits"></div>
</section>

<section id="v-result" class="result" hidden aria-live="assertive">
  <div class="result-inner">
    <p class="glyph" id="res-glyph" aria-hidden="true"></p>
    <h1 id="res-title" tabindex="-1"></h1>
    <p id="res-detail" class="res-detail"></p>
    <p id="res-ref" class="res-ref"></p>
    <button id="next-btn" class="btn next" type="button">Scan next senior</button>
  </div>
</section>
HTML;

        $css = <<<'CSS'
.block{margin-top:1.5rem}
.row{display:flex;gap:.6rem}
.row input{flex:1}
.viewer{position:relative;aspect-ratio:4/3;background:var(--grey-deep);border-radius:var(--r-big);overflow:hidden;margin-bottom:1rem}
.viewer video{width:100%;height:100%;object-fit:cover;display:block}
.frame{position:absolute;inset:16% 22%;border:4px solid var(--action);border-radius:var(--r-small)}
.cam-msg{position:absolute;left:0;right:0;bottom:0;margin:0;padding:.6rem 1rem;background:rgba(34,39,46,.82);color:#fff;text-align:center;font-size:var(--step--1)}
details{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-big);padding:1rem 1.25rem}
summary{font-weight:700;cursor:pointer;min-height:2.5rem;display:flex;align-items:center}
.or{margin:1rem 0 0;color:var(--muted);font-weight:700}
.match{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.9rem 0;border-top:1px solid var(--line)}
.match p{margin:0}
.muted{color:var(--muted)}
.tally{margin-top:2rem}
.tally-list{list-style:none;padding:0;margin:0}
.tally-list li{display:grid;grid-template-columns:5.5rem 1fr;gap:0 .75rem;padding:.6rem 0;border-top:1px solid var(--line);font-size:var(--step--1)}
.tally-list .t{color:var(--muted);grid-row:span 2}
.back{min-height:3rem;margin-bottom:1rem}
.person{background:var(--surface);border:1px solid var(--line);border-left:10px solid var(--action);border-radius:var(--r-big);padding:1.25rem 1.5rem}
.person h1{margin-top:0;font-size:clamp(2.2rem,1.6rem + 3vw,3.4rem)}
.facts{display:grid;grid-template-columns:repeat(3,auto);gap:.25rem 1.5rem;margin:0;justify-content:start}
.facts dt{font-size:var(--step--1);color:var(--muted)}
.facts dd{margin:0;font-weight:700;white-space:nowrap}
.check{margin:1rem 0 0;padding:.6rem .9rem;background:var(--action-tint);border-radius:var(--r-small)}
.benefit{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1rem;background:var(--surface);border:1px solid var(--line);border-radius:var(--r-big);padding:1rem 1.25rem;margin-bottom:.75rem}
.benefit p{margin:0}
.what{font-weight:700}
.inline-err{color:var(--refused);font-weight:700;margin:.5rem 0 0;flex-basis:100%}
.benefit .btn{min-width:9rem}
.empty{background:var(--surface);border:2px dashed var(--line);border-radius:var(--r-big);padding:1.25rem;color:var(--muted)}
.result{position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;padding:clamp(1.25rem,5vw,3rem);color:#fff;text-align:center}
.result.granted{background:var(--granted)}
.result.refused{background:var(--refused)}
.result-inner{max-width:36rem}
.glyph{font-size:clamp(5rem,30vw,11rem);line-height:1;margin:0;font-weight:700}
.result h1{font-size:clamp(2.8rem,12vw,5.5rem);line-height:1;margin:.2em 0 .35em;outline:none}
.res-detail{font-size:var(--step-1);margin:0 0 .5em}
.res-ref{font-size:var(--step-0);opacity:.92;margin:0 0 2rem}
.next{background:#fff;color:var(--ink);border-color:#fff;min-height:4rem;padding:1rem 2rem;font-size:var(--step-1)}
.result.granted .glyph{animation:pop .45s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.4);opacity:0}to{transform:scale(1);opacity:1}}
@media (max-width:30rem){.facts{grid-template-columns:1fr 1fr}.benefit{flex-direction:column;align-items:stretch}.row{flex-direction:column}}
CSS;

        $script = <<<'JS'
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('ss-config').textContent);
  var $ = function (id) { return document.getElementById(id); };
  var views = { scan: $('v-scan'), confirm: $('v-confirm'), result: $('v-result') };
  var state = { credentialId: null, method: 'qr', keys: {} };
  var peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16)); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = Array.prototype.map.call(b, function (x) { return (x + 256).toString(16).slice(1); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  function api(path, body) {
    return fetch(cfg.rest + path, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || 'The server did not answer properly.'); e.code = j.code || 'http_' + r.status; throw e; }
        return j;
      });
    }, function () { var e = new Error('No connection. Check the signal, then try again.'); e.code = 'network'; throw e; });
  }

  function show(name) {
    Object.keys(views).forEach(function (k) { views[k].hidden = k !== name; });
    if (name === 'scan') { stopCamera(); refreshTally(); }
  }

  function describe(ent) {
    if (ent.benefit_type === 'cash' && ent.amount) return peso.format(Number(ent.amount));
    if (ent.quantity) return ent.quantity + ' ' + (ent.unit_label || 'item') + (Number(ent.quantity) === 1 ? '' : 's');
    return 'Service';
  }

  /* ---------- Confirm ---------- */
  function showConfirm(data, method, credentialId) {
    state.method = method; state.credentialId = credentialId || null; state.keys = {};
    var b = data.beneficiary;
    $('who-name').textContent = b.display_name;
    $('who-ref').textContent = b.ref_no;
    $('who-brgy').textContent = b.barangay;
    $('who-age').textContent = b.age;
    $('reason-box').hidden = method !== 'manual';
    $('reason').value = '';
    var list = $('benefits'); list.textContent = '';
    if (!data.entitlements.length) {
      list.appendChild(el('p', 'empty', 'Nothing is ready for release today for this senior. Ask them to check with the OSCA desk.'));
    }
    data.entitlements.forEach(function (ent) {
      state.keys[ent.id] = uuid();
      var card = el('div', 'benefit');
      var text = el('div');
      text.appendChild(el('p', 'what', ent.program));
      text.appendChild(el('p', 'muted', describe(ent)));
      var btn = el('button', 'btn primary', 'Release');
      btn.type = 'button';
      btn.setAttribute('aria-label', 'Release ' + ent.program + ', ' + describe(ent));
      btn.addEventListener('click', function () { release(ent, btn); });
      card.appendChild(text); card.appendChild(btn);
      list.appendChild(card);
    });
    show('confirm');
    $('who-name').setAttribute('tabindex', '-1'); $('who-name').focus();
  }

  function release(ent, btn) {
    var note = $('reason').value.trim();
    if (state.method === 'manual' && !note) { $('reason').focus(); $('reason').setAttribute('aria-invalid', 'true'); return; }
    btn.disabled = true; btn.textContent = 'Releasing…';
    api('desk/release', {
      entitlement_id: ent.id,
      idempotency_key: state.keys[ent.id],
      method: state.method,
      credential_id: state.credentialId,
      note: note
    }).then(function (res) {
      result('granted', 'Released', 'Hand over ' + describe(ent), ent.program + ', ref ' + res.ref_no);
    }, function (err) {
      if (err.code === 'network') {
        // Same request key on retry, so a release that did reach the server is never duplicated.
        btn.disabled = false; btn.textContent = 'Try again';
        alertInline(btn, err.message);
        return;
      }
      refuse(err);
    });
  }

  function alertInline(near, msg) {
    var p = near.parentNode.querySelector('.inline-err') || el('p', 'inline-err');
    p.setAttribute('role', 'alert'); p.textContent = msg; near.parentNode.appendChild(p);
  }

  /* ---------- Result ---------- */
  var TITLES = {
    already_released: 'Already released',
    credential_invalid: 'QR code not accepted',
    credential_mismatch: 'Wrong QR code',
    beneficiary_not_eligible: 'Not eligible today',
    outside_validity: 'Not in release dates',
    rate_limited: 'Please wait a moment',
    forbidden: 'Not allowed'
  };
  function refuse(err) { result('refused', TITLES[err.code] || 'Not released', err.message, ''); }

  function result(kind, title, detail, ref) {
    stopCamera();
    var v = views.result;
    v.className = 'result ' + kind;
    $('res-glyph').textContent = kind === 'granted' ? '✓' : '✕';
    $('res-title').textContent = title;
    $('res-detail').textContent = detail;
    $('res-ref').textContent = ref;
    show('result');
    if (navigator.vibrate) navigator.vibrate(kind === 'granted' ? 120 : [80, 60, 80, 60, 80]);
    $('res-title').focus();
  }

  /* ---------- Scan ---------- */
  function check(payload) {
    payload = (payload || '').trim();
    if (!payload) return;
    $('cam-msg').textContent = 'Checking code…';
    api('desk/scan', { payload: payload }).then(function (data) {
      showConfirm(data, 'qr', data.credential_id);
    }, function (err) {
      if (err.code === 'network') { $('cam-msg').textContent = err.message; return; }
      refuse(err);
    });
  }

  var cam = { stream: null, raf: 0, detector: null, canvas: null };
  function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      $('cam-msg').textContent = 'This browser cannot use the camera. Use the box below or a handheld scanner.';
      return;
    }
    $('cam-btn').disabled = true; $('cam-msg').textContent = 'Starting camera…';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
      cam.stream = stream; var v = $('cam'); v.srcObject = stream; v.play();
      $('cam-btn').disabled = false; $('cam-btn').textContent = 'Stop camera';
      $('cam-msg').textContent = 'Hold the QR code inside the yellow frame';
      if ('BarcodeDetector' in window) { try { cam.detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { cam.detector = null; } }
      loop();
    }, function () {
      $('cam-btn').disabled = false;
      $('cam-msg').textContent = 'Camera permission was refused. Allow it in the browser settings, or use the box below.';
    });
  }
  function stopCamera() {
    cancelAnimationFrame(cam.raf);
    if (cam.stream) { cam.stream.getTracks().forEach(function (t) { t.stop(); }); cam.stream = null; }
    $('cam').srcObject = null; $('cam-btn').textContent = 'Start camera'; $('cam-btn').disabled = false;
    if ($('cam-msg').textContent.indexOf('Hold the QR') === 0) $('cam-msg').textContent = 'Camera is off';
  }
  var busy = false;
  function loop() {
    cam.raf = requestAnimationFrame(loop);
    var v = $('cam');
    if (busy || !cam.stream || v.readyState < 2) return;
    busy = true;
    var found = function (text) { busy = false; if (text) { stopCamera(); check(text); } };
    if (cam.detector) {
      cam.detector.detect(v).then(function (codes) { found(codes.length ? codes[0].rawValue : null); }, function () { cam.detector = null; busy = false; });
    } else if (window.jsQR) {
      var c = cam.canvas || (cam.canvas = document.createElement('canvas'));
      var w = 480, h = Math.round(480 * v.videoHeight / v.videoWidth) || 360;
      c.width = w; c.height = h;
      var ctx = c.getContext('2d', { willReadFrequently: true }); ctx.drawImage(v, 0, 0, w, h);
      var code = window.jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
      found(code ? code.data : null);
    } else { busy = false; }
  }

  /* ---------- Manual lookup ---------- */
  $('find-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var out = $('find-results'); out.textContent = 'Searching…';
    api('desk/find', { ref_no: $('ref').value, last_name: $('last').value, birthdate: $('dob').value }).then(function (data) {
      out.textContent = '';
      if (!data.results.length) { out.appendChild(el('p', 'muted', 'No verified senior matches. Check the spelling and birthdate, or send them to the OSCA desk.')); return; }
      data.results.forEach(function (r) {
        var row = el('div', 'match');
        var t = el('div');
        t.appendChild(el('p', 'what', r.beneficiary.display_name));
        t.appendChild(el('p', 'muted', r.beneficiary.ref_no + ', ' + r.beneficiary.barangay + ', age ' + r.beneficiary.age));
        var b = el('button', 'btn', 'Select'); b.type = 'button';
        b.addEventListener('click', function () { showConfirm(r, 'manual', null); });
        row.appendChild(t); row.appendChild(b); out.appendChild(row);
      });
    }, function (err) { out.textContent = ''; out.appendChild(el('p', 'muted', err.message)); });
  });

  /* ---------- Tally ---------- */
  function refreshTally() {
    api('desk/today').then(function (t) {
      $('tally-count').textContent = t.count;
      var ol = $('tally-list'); ol.textContent = '';
      t.items.forEach(function (i) {
        var li = el('li');
        li.appendChild(el('span', 't', i.time));
        li.appendChild(el('span', 'what', i.display_name));
        li.appendChild(el('span', 'muted', i.program + (i.method === 'manual' ? ', manual' : '')));
        ol.appendChild(li);
      });
    }, function () {});
  }

  /* ---------- Wiring ---------- */
  $('cam-btn').addEventListener('click', function () { cam.stream ? stopCamera() : startCamera(); });
  $('code-form').addEventListener('submit', function (e) { e.preventDefault(); var v = $('code').value; $('code').value = ''; check(v); });
  $('back-btn').addEventListener('click', function () { show('scan'); });
  $('next-btn').addEventListener('click', function () { show('scan'); $('code').focus(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !views.result.hidden) { show('scan'); } });
  refreshTally();
})();
JS;

        Layout::page('Release desk', 'Release desk', $body, $css, $script, [], [
            'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
        ]);
    }
}
