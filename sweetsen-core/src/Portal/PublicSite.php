<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Support\Db;

/**
 * Public website glue: the Sweet Sen typeface for the theme and editor, and the
 * [sweetsen_programs] shortcode that lists open programs from the database.
 * Public pages only ever read published program fields. No senior data.
 */
final class PublicSite
{
    private const FONT = 'https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap';

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('enqueue_block_assets', [self::class, 'editorFont']);
        add_shortcode('sweetsen_programs', [self::class, 'programs']);
        add_shortcode('sweetsen_headline', [self::class, 'headline']);
        add_action('wp_footer', [self::class, 'headlineJs'], 20);
        add_action('wp_head', [Brand::class, 'printFavicon'], 1);
        add_action('wp_footer', [self::class, 'backToTop']);
        add_filter('render_block_core/navigation-link', [self::class, 'markCurrent'], 10, 2);
    }

    public static function assets(): void
    {
        wp_enqueue_style('sweetsen-font', self::FONT, [], null);
        wp_register_style('sweetsen-public', false, [], SWEETSEN_VERSION);
        wp_enqueue_style('sweetsen-public');
        wp_add_inline_style('sweetsen-public', self::css() . self::topCss() . Brand::markCss('.wp-block-site-title a::before', '1.6em'));
    }

    public static function editorFont(): void
    {
        if (is_admin()) {
            wp_enqueue_style('sweetsen-font', self::FONT, [], null);
        }
    }

    /**
     * WordPress does not mark custom navigation links as current. Mark the link whose page is being viewed
     * (links with a #section never count), so it gets aria-current="page" and the active underline.
     *
     * @param array<string, mixed> $block
     */
    public static function markCurrent(string $html, array $block): string
    {
        $url = (string) ($block['attrs']['url'] ?? '');
        if ($url === '' || str_contains($url, '#')) {
            return $html;
        }
        $current = untrailingslashit((string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'));
        $link = untrailingslashit((string) wp_parse_url($url, PHP_URL_PATH));
        if ($link === '' || $link !== $current) {
            return $html;
        }
        $html = (string) preg_replace('/class="wp-block-navigation-item /', 'class="wp-block-navigation-item current-menu-item ', $html, 1);
        return (string) preg_replace('/<a /', '<a aria-current="page" ', $html, 1);
    }

    /** Round "back to top" button, bottom left, shown after the visitor scrolls down. */
    public static function backToTop(): void
    {
        echo self::backToTopHtml();
    }

    /** Same button for the portal pages (senior, console, desk, sponsor), which render outside the theme. */
    public static function backToTopHtml(): string
    {
        return '<button type="button" class="ss-top" id="ss-top" aria-label="Back to top" hidden>'
            . '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
            . "<script>(function(){var b=document.getElementById('ss-top');if(!b)return;var r=window.matchMedia('(prefers-reduced-motion: reduce)').matches;"
            . "function u(){b.hidden=window.scrollY<400;}window.addEventListener('scroll',u,{passive:true});u();"
            . "b.addEventListener('click',function(){window.scrollTo({top:0,behavior:r?'auto':'smooth'});var t=document.querySelector('a[href]');if(t){t.focus({preventScroll:true});}});})();</script>";
    }

    /** @param array<string, string>|string $atts */
    /**
     * Typewriter hero headline: fixed words, then place names typed, held, erased and replaced (only the name changes).
     * [sweetsen_headline lead="Support Senior Citizens" prefix="in" items="Makati City|Manila"]
     * Screen readers get the whole sentence once; reduced-motion users see a still headline; a pause button stops it.
     *
     * @param array<string, string>|string $atts
     */
    public static function headline($atts = []): string
    {
        $a = shortcode_atts(['lead' => '', 'prefix' => '', 'items' => '', 'every' => '2600'], is_array($atts) ? $atts : []);
        $items = array_values(array_filter(array_map('trim', explode('|', (string) $a['items'])), 'strlen'));
        if ($items === []) {
            return '';
        }
        $every = max(1500, min(8000, (int) $a['every']));
        $prefix = trim((string) $a['prefix']);
        $last = count($items) > 1 ? array_pop($items) : null;
        $spoken = trim($a['lead'] . ' ' . $prefix . ' ' . implode(', ', $items) . ($last !== null ? ' and ' . $last : ''));
        if ($last !== null) {
            $items[] = $last;
        }
        return '<div class="ss-rot" data-hold="' . $every . '" data-items="' . esc_attr((string) wp_json_encode($items)) . '">'
            . '<h1 class="wp-block-heading has-xx-large-font-size ss-rot-h"><span class="screen-reader-text">' . esc_html($spoken) . '</span>'
            . '<span aria-hidden="true"><span class="ss-rot-lead">' . esc_html((string) $a['lead']) . '</span>'
            . '<span class="ss-rot-line">' . ($prefix !== '' ? '<span class="ss-rot-pre">' . esc_html($prefix) . '</span> ' : '')
            . '<span class="ss-rot-box"><span class="ss-tw">' . esc_html($items[0]) . '</span><span class="ss-caret"></span></span></span></span></h1>'
            . (count($items) > 1 ? '<button type="button" class="ss-rot-pause" aria-pressed="false" aria-label="Pause the moving headline">'
                . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path class="i-pause" d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/><path class="i-play" d="M8 5l11 7-11 7z"/></svg></button>' : '')
            . '</div>';
    }

    /** Printed in the footer, not inside post content: wptexturize would turn && into &#038;&#038; there. */
    public static function headlineJs(): void
    {
        $content = (string) get_post_field('post_content', get_queried_object_id());
        if (has_shortcode($content, 'sweetsen_programs')) {
            echo <<<'JS'
<script>
(function () {
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('.ss-slider').forEach(function (root) {
    var list = root.querySelector('.ss-programs'), nav = root.querySelector('.ss-slider-nav'), count = root.querySelector('.ss-slide-count');
    var prev = root.querySelector('[data-dir="-1"]'), next = root.querySelector('[data-dir="1"]'), cards = list.children;
    var step = function () { return cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : list.clientWidth; };
    var update = function () {
      var s = step(), first = Math.round(list.scrollLeft / s), shown = Math.max(1, Math.round((list.clientWidth + 20) / s));
      var fits = list.scrollWidth <= list.clientWidth + 2;
      nav.hidden = fits;
      prev.disabled = list.scrollLeft <= 2;
      next.disabled = list.scrollLeft + list.clientWidth >= list.scrollWidth - 2;
      var last = Math.min(cards.length, first + shown);
      count.textContent = (first + 1 === last ? String(last) : (first + 1) + '\u2013' + last) + ' of ' + cards.length;
    };
    root.querySelectorAll('.ss-slide-btn').forEach(function (b) {
      b.addEventListener('click', function () { list.scrollBy({ left: +b.dataset.dir * step(), behavior: still ? 'auto' : 'smooth' }); });
    });
    var t; list.addEventListener('scroll', function () { clearTimeout(t); t = setTimeout(update, 80); });
    window.addEventListener('resize', update);
    update();
  });
})();
</script>
JS;
        }
        if (!has_shortcode($content, 'sweetsen_headline')) {
            return;
        }
        echo <<<'JS'
<script>
(function () {
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('.ss-rot').forEach(function (root) {
    var items = JSON.parse(root.dataset.items || '[]'), out = root.querySelector('.ss-tw'), btn = root.querySelector('.ss-rot-pause');
    if (items.length < 2 || still) { if (btn) btn.hidden = true; return; }
    root.classList.add('is-live');
    var hold = +root.dataset.hold || 2600, i = 0, text = items[0], deleting = true, paused = false, timer = null;
    var tick = function () {
      if (paused) return;
      var target = items[i], delay;
      if (deleting) {
        text = text.slice(0, -1); delay = 45;
        if (text === '') { deleting = false; i = (i + 1) % items.length; delay = 350; }
      } else {
        target = items[i]; text = target.slice(0, text.length + 1); delay = 85;
        if (text === target) { deleting = true; delay = hold; }
      }
      out.textContent = text;
      root.classList.toggle('is-typing', !(deleting && text === items[i]));
      timer = setTimeout(tick, delay);
    };
    var start = function () { clearTimeout(timer); timer = setTimeout(tick, hold); };
    btn.addEventListener('click', function () {
      paused = !paused; clearTimeout(timer);
      root.classList.toggle('is-paused', paused);
      if (paused) { out.textContent = items[i]; text = items[i]; deleting = true; } else { start(); }
      btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
      btn.setAttribute('aria-label', paused ? 'Play the moving headline' : 'Pause the moving headline');
    });
    document.addEventListener('visibilitychange', function () {
      clearTimeout(timer); if (!document.hidden && !paused) start();
    });
    start();
  });
})();
</script>
JS;
    }

    public static function programs($atts = []): string
    {
        $slug = (string) get_option('sweetsen_public_tenant', 'makati');
        $today = Db::todayManila()->format('Y-m-d');
        try {
            $rows = Db::rows(Db::prepare(
                'SELECT p.name, p.description, p.benefit_type, p.unit_amount, p.unit_quantity, p.unit_label, p.claim_period
                 FROM ' . Db::t('programs') . ' p JOIN ' . Db::t('tenants') . ' t ON t.id = p.tenant_id
                 WHERE t.slug = %s AND p.status = %s AND p.starts_on <= %s AND p.ends_on >= %s ORDER BY p.name',
                $slug, 'published', $today, $today
            ));
        } catch (\Throwable $e) {
            return '<p class="ss-programs-empty">Programs are not available right now.</p>';
        }
        if ($rows === []) {
            return '<p class="ss-programs-empty">No programs are open right now. Please check again soon.</p>';
        }
        $out = '';
        foreach ($rows as $p) {
            if ($p['benefit_type'] === 'cash' && $p['unit_amount'] !== null) {
                $n = (float) $p['unit_amount'];
                $what = "\u{20B1}" . number_format($n, fmod($n, 1.0) === 0.0 ? 0 : 2);
            } else {
                $q = (int) ($p['unit_quantity'] ?? 1);
                $what = $q . ' ' . ($p['unit_label'] ?: 'item') . ($q === 1 ? '' : 's');
            }
            $often = match ($p['claim_period']) { 'monthly' => 'Every month', 'yearly' => 'Once a year', default => 'One time only' };
            $out .= '<li class="ss-program"><p class="ss-program-name">' . esc_html($p['name']) . '</p>'
                . '<p class="ss-program-what">' . esc_html($what) . '<span>' . esc_html($often) . '</span></p>'
                . ($p['description'] ? '<p class="ss-program-desc">' . esc_html($p['description']) . '</p>' : '') . '</li>';
        }
        $n = count($rows);
        return '<div class="ss-slider" role="region" aria-roledescription="carousel" aria-label="Programs open now">'
            . '<ul class="ss-programs" tabindex="0" aria-label="' . $n . ' programs">' . $out . '</ul>'
            . '<div class="ss-slider-nav" hidden><button type="button" class="ss-slide-btn" data-dir="-1" aria-label="Previous programs">'
            . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
            . '<p class="ss-slide-count" aria-live="polite"></p>'
            . '<button type="button" class="ss-slide-btn" data-dir="1" aria-label="Next programs">'
            . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>'
            . '</div>';
    }

    public static function topCss(): string
    {
        return '.ss-top{position:fixed;left:1rem;bottom:1rem;z-index:60;width:3.25rem;height:3.25rem;border-radius:50%;border:2px solid #22272E;background:#F2B705;color:#22272E;display:grid;place-items:center;cursor:pointer;box-shadow:0 6px 18px rgba(34,39,46,.25)}'
            . '.ss-top[hidden]{display:none}.ss-top:hover{background:#D9A300}.ss-top:focus-visible{outline:4px solid #22272E;outline-offset:3px}'
            . '@media print{.ss-top{display:none!important}}';
    }

    private static function css(): string
    {
        return <<<'CSS'
.ss-slider{margin:1.75rem auto 0;max-width:100%}
@media (min-width:1024px){.ss-slider{max-width:1100px}}
.ss-programs{list-style:none;margin:0!important;padding:.25rem .1rem 1rem!important;display:flex;gap:1.25rem;overflow-x:auto;scroll-snap-type:x mandatory;
  scrollbar-width:none;overscroll-behavior-x:contain;scroll-behavior:smooth}
.ss-programs::-webkit-scrollbar{display:none}
.ss-programs:focus-visible{outline:3px solid #22272E;outline-offset:4px;border-radius:14px}
.ss-programs .ss-program{flex:0 0 calc((100% - 2.5rem) / 3);scroll-snap-align:start;background:#fff;border:1px solid #C5CCD5;border-top:8px solid #F2B705;border-radius:14px;padding:1.4rem 1.5rem!important;margin:0}
@media (max-width:1023px){.ss-programs .ss-program{flex-basis:calc((100% - 1.25rem) / 2)}}
@media (max-width:599px){.ss-programs .ss-program{flex-basis:86%}}
@media (prefers-reduced-motion:reduce){.ss-programs{scroll-behavior:auto}}
.ss-slider-nav{display:flex;align-items:center;justify-content:center;gap:1.25rem;margin-top:.75rem}
.ss-slider-nav[hidden]{display:none}
.ss-slide-btn{width:3rem;height:3rem;border-radius:50%;border:2px solid #22272E;background:#fff;color:#22272E;display:grid;place-items:center;cursor:pointer;padding:0}
.ss-slide-btn:hover:not(:disabled){background:#F2B705}
.ss-slide-btn:focus-visible{outline:3px solid #22272E;outline-offset:3px}
.ss-slide-btn:disabled{opacity:.35;cursor:default}
.ss-slide-count{margin:0!important;min-width:7rem;text-align:center;font-weight:700;color:#3A424D}
.ss-program p{margin:0}
.ss-program-name{font-weight:700;font-size:1.25rem;line-height:1.3}
.ss-program-what{font-weight:700;font-size:1.9rem;margin:.5rem 0 .25rem!important;line-height:1.1}
.ss-program-what span{display:block;font-size:1rem;font-weight:400;color:#555E69;margin-top:.25rem}
.ss-program-desc{color:#555E69;font-size:.95rem;margin-top:.75rem!important}
.ss-programs-empty{color:#555E69}
.wp-block-site-title a{display:inline-flex;align-items:center;gap:.55rem;text-decoration:none!important}
.wp-block-site-title a::before{content:""}
.ss-card{background:#fff;border:1px solid #C5CCD5;border-radius:14px;padding:1.5rem 1.6rem}
.ss-card.ss-accent{border-top:8px solid #F2B705}
.ss-card h3{margin-top:0}
.ss-checks{list-style:none;padding-left:0}
.ss-checks li{position:relative;padding-left:2rem;margin:.6rem 0}
.ss-checks li::before{content:"";position:absolute;left:0;top:.2em;width:1.2rem;height:1.2rem;border-radius:50%;background:#F2B705;box-shadow:inset 0 0 0 4px #FFF4CC}
.ss-eyebrow{text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:#3A424D}
.ss-step-num{display:inline-grid;place-items:center;width:2.75rem;height:2.75rem;border-radius:50%;background:#F2B705;color:#22272E;font-weight:700;font-size:1.25rem;position:relative;z-index:1;box-shadow:0 0 0 6px var(--ss-step-ring,#fff)}
.ss-steps>.wp-block-column{position:relative;text-align:center}
.ss-steps>.wp-block-column:not(:last-child)::before{content:"";position:absolute;top:calc(1.375rem - 2px);left:calc(50% + 1.375rem + 10px);right:calc(-50% - var(--wp--style--block-gap, 2rem) + 1.375rem + 10px);border-top:4px dotted #3A424D}
.has-primary-accent-background-color{--ss-step-ring:#FFF4CC}
.has-tertiary-background-color{--ss-step-ring:#ECEFF3}
@media (max-width:781px){
  .ss-steps>.wp-block-column:not(:last-child)::before{top:2.75rem;bottom:calc(-1 * var(--wp--style--block-gap, 2em) - .25rem);left:calc(1.375rem - 2px);right:auto;border-top:0;border-left:4px dotted #3A424D}
  .ss-steps>.wp-block-column{padding-left:4rem;min-height:3.5rem;text-align:left}
  .ss-steps .ss-step-num{position:absolute;left:0;top:0}
  .ss-steps .ss-step-num+.wp-block-heading{margin-top:.35rem}
}
.ss-faq details{border-bottom:1px solid #C5CCD5;padding:1rem 0}
.ss-faq summary{font-weight:700;font-size:1.15rem;cursor:pointer;min-height:2.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;list-style:none}
.ss-faq summary::-webkit-details-marker{display:none}
.ss-faq summary::marker{content:""}
.ss-faq summary::after{content:"";flex:none;width:1.1rem;height:1.1rem;margin-right:.25rem;background:linear-gradient(#22272E,#22272E) center/100% 3px no-repeat,linear-gradient(#22272E,#22272E) center/3px 100% no-repeat;transition:transform .2s ease}
.ss-faq details[open]>summary::after{background:linear-gradient(#22272E,#22272E) center/100% 3px no-repeat;transform:rotate(180deg)}
.ss-faq summary:focus-visible{outline:3px solid #22272E;outline-offset:4px;border-radius:4px}
@media (prefers-reduced-motion:reduce){.ss-faq summary::after{transition:none}}
/* blog: no author name or avatar (posts are by the SweetSen team); category links readable on white */
.wp-block-post-author,.wp-block-post-author+p{display:none!important}
.wp-block-post-terms a{color:#22272E!important;background:#FFF4CC;border-radius:999px;padding:.15rem .7rem;text-decoration:none;font-weight:700}
.wp-block-post-terms a:hover,.wp-block-post-terms a:focus-visible{background:#F2B705}
.ss-faq details p{margin:.5rem 0 0;color:#3A424D}
.ss-demo-note{background:repeating-linear-gradient(135deg,#FFF4CC 0 12px,#FFEDB0 12px 24px)}
:where(.wp-site-blocks) a:where(:not(.wp-element-button)){text-decoration-color:#F2B705;text-decoration-thickness:3px;text-underline-offset:3px}
:where(.wp-site-blocks) :focus-visible{outline:4px solid #22272E;outline-offset:3px}
.wp-block-button>.wp-block-button__link,.wp-block-button.is-style-outline>.wp-block-button__link{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-height:3.25rem;padding:.75em 1.5em!important;border:2px solid #22272E!important;font-size:1.1rem;font-weight:700;line-height:1.2}
@media (max-width:599px){
  header .wp-block-site-title,footer .wp-block-site-title{font-size:26.8px!important}
  header .wp-block-site-title a,footer .wp-block-site-title a{gap:.5rem}
  header .wp-block-buttons .wp-block-button__link{min-height:32px!important;height:32px;padding:0 .9rem!important;font-size:15px!important}
  header .wp-block-buttons,header .wp-block-button{display:flex;align-items:center;margin-block:0!important;line-height:1;flex-shrink:0}
  header .wp-block-buttons .wp-block-button__link{white-space:nowrap}
}
@media (max-width:360px){
  header .wp-block-site-title,footer .wp-block-site-title{font-size:20px!important}
  header .wp-block-group.alignwide>.wp-block-group{gap:.6rem!important}
}
@media (max-width:1100px){footer.wp-block-template-part>.wp-block-group:last-child{padding-bottom:5.5rem!important}}
html{scroll-padding-top:6.5rem}
/* Animated hero headline ([sweetsen_headline]) */
.ss-rot{position:relative}
.ss-rot-h{margin:0}
.ss-rot-lead{display:block}
.ss-rot-line{display:flex;align-items:baseline;gap:.28em;flex-wrap:nowrap}
.ss-rot-pre{flex:none}
.ss-rot-box{display:inline-flex;align-items:baseline;min-width:0;white-space:nowrap}
.ss-tw{background:linear-gradient(transparent 62%,#F2B705 62%,#F2B705 92%,transparent 92%);padding:0 .06em;min-height:1em}
.ss-caret{display:inline-block;width:.07em;height:.9em;margin-left:.05em;background:#22272E;align-self:center;opacity:0}
.ss-rot.is-live .ss-caret{opacity:1;animation:ss-blink 1s steps(1) infinite}
.ss-rot.is-live.is-typing .ss-caret{animation:none}
.ss-rot.is-paused .ss-caret{display:none}
@keyframes ss-blink{50%{opacity:0}}
.ss-tw:empty{padding:0;background:none}
.ss-rot .screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0;padding:0;margin:-1px}
.ss-rot-pause{margin-top:.5rem;width:2.25rem;height:2.25rem;border-radius:50%;border:2px solid #8A939E;
  background:#fff;color:#22272E;display:none;place-items:center;cursor:pointer;padding:0}
.ss-rot.is-live .ss-rot-pause{display:grid}
.ss-rot-pause:hover{border-color:#22272E}
.ss-rot-pause:focus-visible{outline:3px solid #22272E;outline-offset:2px}
.ss-rot-pause svg{fill:currentColor}
.ss-rot-pause .i-play,.ss-rot-pause[aria-pressed="true"] .i-pause{display:none}
.ss-rot-pause[aria-pressed="true"] .i-play{display:inline}
@media (max-width:599px){.ss-rot-h{font-size:clamp(1.9rem,8.6vw,2.6rem)!important}}
/* Full-width sections sit edge to edge: no white band from the theme's block gap. */
.entry-content>.alignfull+.alignfull{margin-block-start:0!important}
header.site-header{position:sticky;top:0;z-index:50;background:#fff;box-shadow:0 2px 10px rgba(34,39,46,.08)}
.admin-bar header.site-header{top:32px}
@media (max-width:782px){.admin-bar header.site-header{top:46px}}
@media (max-width:600px){.admin-bar header.site-header{top:0}}
/* Below 1024px (tablets, phones in landscape) the menu collapses to the hamburger, not two wrapped rows. */
header .wp-block-buttons .wp-block-button__link{white-space:nowrap}
@media (min-width:600px) and (max-width:1023px){
  header .wp-block-navigation__responsive-container-open:not(.always-shown){display:flex!important}
  header .wp-block-navigation__responsive-container:not(.hidden-by-default):not(.is-menu-open){display:none!important}
  header .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation__responsive-container-close{display:flex!important}
  header .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation__container{flex-direction:column!important;align-items:var(--navigation-layout-align,flex-start)!important}
}
@media (max-width:1023px){
  .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item__content{font-size:32.3px!important;line-height:1.25}
  .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation__container{gap:1.5rem!important}
  .wp-block-navigation__responsive-container.is-menu-open .current-menu-item{background:none!important}
  .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item__content[aria-current="page"]{text-decoration-line:underline!important;text-decoration-style:solid!important;text-decoration-color:#F2B705!important;text-decoration-thickness:10px!important;text-underline-offset:10px!important;text-decoration-skip-ink:none!important;background:none!important}
}
@media (max-width:1023px) and (max-height:500px){
  .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item__content{font-size:24px!important}
  .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation__container{display:grid!important;grid-template-columns:repeat(2,max-content);column-gap:4rem!important;row-gap:1.1rem!important}
}
.wp-element-button:focus-visible,.wp-block-button__link:focus-visible{box-shadow:0 0 0 3px #fff,0 0 0 7px #22272E}
CSS;
    }
}
