<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Domain\Beneficiaries;
use SweetSen\Tenancy\Actor;

/**
 * Printable QR cards for verified seniors (staff with ss_manage_qr). QR images are drawn in the
 * browser from the signed payload; nothing is uploaded or cached as an image.
 */
final class QrCards
{
    public static function render(Actor $actor): void
    {
        $cards = Beneficiaries::qrCards($actor);
        $count = count($cards);
        $body = <<<HTML
<section class="cards-head">
  <h1>QR cards</h1>
  <p>{$count} verified seniors have an active QR code. Print on A4, cut along the borders, and hand each card to its senior.</p>
  <button class="btn primary" type="button" onclick="window.print()">Print cards</button>
</section>
<ul class="cards" id="cards"></ul>
HTML;
        $css = <<<'CSS'
main{max-width:64rem}
.cards-head p{max-width:38rem}
.cards{list-style:none;padding:0;margin:1.5rem 0 0;display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1rem}
.card{background:var(--surface);border:2px solid var(--ink);border-radius:var(--r-big);padding:1rem 1.1rem;break-inside:avoid;display:grid;grid-template-columns:1fr auto;gap:.25rem 1rem;align-items:start}
.card .top{grid-column:1/-1;display:flex;align-items:center;gap:.5rem;font-weight:700;font-size:var(--step--1);padding-bottom:.5rem;margin-bottom:.4rem;border-bottom:4px solid var(--action)}
.card .name{font-weight:700;font-size:var(--step-0);line-height:1.2;margin:0}
.card .meta{margin:0;color:var(--muted);font-size:var(--step--1)}
.card .qr{width:7.5rem;height:7.5rem;grid-row:2/5;grid-column:2}
.card .qr svg{width:100%;height:100%;display:block}
@media print{
  .bar,.demo,.cards-head,.skip{display:none!important}
  body{background:#fff}
  main{padding:0;max-width:none}
  .cards{grid-template-columns:repeat(2,1fr);gap:6mm;margin:0}
  .card{border-width:1px;border-style:dashed}
}
CSS;
        $script = <<<'JS'
(function () {
  var cfg = JSON.parse(document.getElementById('ss-config').textContent);
  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x != null) e.textContent = x; return e; }
  function draw() {
    if (!window.qrcode) { return setTimeout(draw, 100); }
    var ul = document.getElementById('cards');
    cfg.cards.forEach(function (c) {
      var li = el('li', 'card');
      var top = el('p', 'top'); top.appendChild(el('span', 'mark')); top.appendChild(document.createTextNode('SweetSen senior card (demo)'));
      li.appendChild(top);
      li.appendChild(el('p', 'name', c.name));
      li.appendChild(el('p', 'meta', c.ref_no));
      li.appendChild(el('p', 'meta', 'Barangay ' + c.barangay));
      var q = qrcode(0, 'M'); q.addData(c.qr_payload); q.make();
      var box = el('div', 'qr'); box.setAttribute('role', 'img'); box.setAttribute('aria-label', 'QR code for ' + c.name);
      box.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
      li.appendChild(box);
      ul.appendChild(li);
    });
  }
  draw();
})();
JS;
        Layout::page('QR cards', 'QR cards', $body, $css, $script, ['cards' => $cards], [
            'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js',
        ]);
    }
}
