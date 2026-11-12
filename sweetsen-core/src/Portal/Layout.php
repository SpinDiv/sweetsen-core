<?php

declare(strict_types=1);

namespace SweetSen\Portal;

/**
 * Page shell for the portal: one legible typeface, high-contrast tokens, large targets.
 * Designed for phones held at arm's length in daylight and for older eyes.
 */
final class Layout
{
    public static function esc(string $s): string
    {
        return esc_html($s);
    }

    /**
     * Standard page: top bar with the desk name, demo notice, centred main column.
     *
     * @param array<string, mixed> $config Data handed to the page script as JSON.
     * @param list<string> $scripts External scripts (CDN) loaded with defer.
     */
    public static function page(string $title, string $deskName, string $body, string $css = '', string $script = '', array $config = [], array $scripts = []): void
    {
        $user = wp_get_current_user();
        $logout = esc_url(wp_logout_url(Router::url()));
        $deskE = self::esc($deskName);
        $nameE = self::esc($user->display_name);
        $chrome = <<<HTML
<a class="skip" href="#main">Skip to main content</a>
<header class="bar">
  <p class="brand"><span class="mark" aria-hidden="true"></span>SweetSen <span class="desk">{$deskE}</span></p>
  <p class="who"><span class="who-name">{$nameE}</span> <a href="{$logout}">Log out</a></p>
</header>
<main id="main">
{$body}
</main>
HTML;
        self::bare($title, $chrome, $css, $script, $config, $scripts);
    }

    /**
     * Document shell only (head, tokens, config, scripts). The caller supplies the whole body.
     *
     * @param array<string, mixed> $config
     * @param list<string> $scripts
     */
    public static function bare(string $title, string $body, string $css = '', string $script = '', array $config = [], array $scripts = []): void
    {
        $config += ['rest' => esc_url_raw(rest_url('sweetsen/v1/')), 'nonce' => wp_create_nonce('wp_rest')];
        $json = wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
        $ext = '';
        foreach ($scripts as $src) {
            $ext .= '<script src="' . esc_url($src) . '" defer></script>';
        }
        $titleE = self::esc($title);
        $base = self::baseCss() . Brand::markCss('.mark', '1.35rem') . PublicSite::topCss();
        $top = PublicSite::backToTopHtml();
        $icon = Brand::favicon();
        echo <<<HTML
<!doctype html>
<html lang="en" translate="no">
<head>
<meta charset="utf-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#3A424D">
<title>{$titleE} | SweetSen</title>
{$icon}
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">
<style>{$base}{$css}</style>
{$ext}
</head>
<body>
{$body}
<script id="ss-config" type="application/json">{$json}</script>
<script>{$script}</script>
{$top}
</body>
</html>
HTML;
    }

    /** @param array{label:string, href:string}|null $action */
    public static function message(string $title, string $text, int $status = 200, ?array $action = null): void
    {
        status_header($status);
        $btn = $action ? '<p><a class="btn primary" href="' . esc_url($action['href']) . '">' . self::esc($action['label']) . '</a></p>' : '';
        self::page($title, '', '<section class="note"><h1>' . self::esc($title) . '</h1><p>' . self::esc($text) . '</p>' . $btn . '</section>');
    }

    private static function baseCss(): string
    {
        return <<<'CSS'
:root{
  /* Cool grey ground and type, golden yellow for action. Green/red are reserved for release outcomes. */
  --paper:#ECEFF3; --surface:#FFFFFF; --ink:#22272E; --muted:#555E69; --line:#C5CCD5; --grey-deep:#3A424D;
  --action:#F2B705; --action-ink:#22272E; --action-press:#D9A300; --action-tint:#FFF4CC;
  --granted:#0E6B47; --refused:#A4231B; --focus:#22272E;
  --r-small:6px; --r-big:14px;
  --step--1:clamp(1rem,0.95rem + .2vw,1.06rem); --step-0:clamp(1.15rem,1.08rem + .35vw,1.3rem);
  --step-1:clamp(1.4rem,1.25rem + .7vw,1.75rem); --step-2:clamp(1.9rem,1.6rem + 1.5vw,2.6rem);
}
*{box-sizing:border-box}
[hidden]{display:none!important}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--paper);color:var(--ink);font-family:"Atkinson Hyperlegible",system-ui,sans-serif;font-size:var(--step-0);line-height:1.5}
a{color:var(--ink);text-decoration-color:var(--action);text-decoration-thickness:3px;text-underline-offset:3px}
:focus-visible{outline:4px solid var(--focus);outline-offset:3px;box-shadow:0 0 0 9px var(--action)}
.skip{position:absolute;left:-999px;top:0;background:var(--surface);padding:.75rem 1rem;z-index:20}
.skip:focus{left:1rem}
.bar{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.9rem clamp(1rem,4vw,2rem);background:var(--grey-deep);color:#FFFFFF}
.bar p{margin:0}
.bar a{color:#FFFFFF}
.brand{font-weight:700;font-size:var(--step-0);display:flex;align-items:center;gap:.6rem;white-space:nowrap}
@media (max-width:30rem){.who-name{display:none}.desk{font-size:var(--step--1)}}
.desk{font-weight:400;color:#D3D8DE}
.who{font-size:var(--step--1);color:#D3D8DE;text-align:right}
.who a{margin-left:.5rem}
.demo{margin:0;padding:.35rem clamp(1rem,4vw,2rem);font-size:var(--step--1);color:var(--ink);background:repeating-linear-gradient(135deg,var(--action-tint) 0 10px,#FFEDB0 10px 20px)}
main{padding:clamp(1rem,4vw,2rem);max-width:40rem;margin:0 auto}
h1{font-size:var(--step-2);line-height:1.15;margin:.2em 0 .5em}
h2{font-size:var(--step-1);line-height:1.2;margin:1.4em 0 .5em}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:3.5rem;padding:.7rem 1.4rem;border-radius:var(--r-small);border:2px solid var(--ink);background:var(--surface);color:var(--ink);font:inherit;font-weight:700;text-decoration:none;cursor:pointer}
.btn.primary{background:var(--action);border-color:var(--ink);color:var(--action-ink)}
.btn.primary:active{background:var(--action-press)}
.btn[disabled]{opacity:.55;cursor:progress}
.btn.full{width:100%}
label{display:block;font-weight:700;margin:1rem 0 .35rem}
input,textarea{width:100%;font:inherit;padding:.75rem .9rem;min-height:3.25rem;border:2px solid var(--muted);border-radius:var(--r-small);background:var(--surface);color:var(--ink)}
.note{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-big);padding:1.5rem}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
CSS;
    }
}
