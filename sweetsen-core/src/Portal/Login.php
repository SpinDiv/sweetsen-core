<?php

declare(strict_types=1);

namespace SweetSen\Portal;

/**
 * Sweet Sen look for wp-login.php: the first screen a senior sees. Large type, one clear button,
 * no WordPress branding. Behaviour is unchanged; only presentation and wording.
 */
final class Login
{
    public static function register(): void
    {
        add_action('login_enqueue_scripts', [self::class, 'styles']);
        add_filter('login_headerurl', static fn(): string => home_url('/'));
        add_filter('login_headertext', static fn(): string => Brand::NAME);
        add_action('login_head', [Brand::class, 'printFavicon']);
        add_filter('login_title', static fn(string $t): string => str_replace([' &#8212; WordPress', ' — WordPress'], '', $t));
        add_filter('login_message', [self::class, 'message']);
        add_filter('gettext', [self::class, 'labels'], 10, 3);
        add_action('login_footer', [self::class, 'art']);
    }

    /** Desktop only: photo panel on the right (option sweetsen_login_art). Hidden below 1024px, where the page stays one column. */
    public static function art(): void
    {
        $url = (string) get_option('sweetsen_login_art', '');
        if ($url === '') {
            return;
        }
        // A CSS background inside the media query: phones and tablets never download the photo.
        echo '<div class="ss-login-art" aria-hidden="true"></div><style>@media (min-width:1024px){.ss-login-art{background:#22272E url("'
            . esc_url($url) . '") 50% 35%/cover no-repeat}}</style>';
    }

    public static function message(string $m): string
    {
        $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : 'login';
        if ($action !== 'login' || isset($_GET['loggedout'])) {
            return $m;
        }
        return $m . '<p class="ss-intro">Sign in to see your QR code, your benefits and your applications.</p>';
    }

    /** Plainer words on the login form only. */
    public static function labels(string $translated, string $text, string $domain): string
    {
        if ($domain !== 'default' || ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return $translated;
        }
        return match ($text) {
            'Username or Email Address' => 'Username',
            'Log In' => 'Sign in',
            'Remember Me' => 'Keep me signed in',
            default => $translated,
        };
    }

    public static function styles(): void
    {
        echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">';
        echo '<style>' . Brand::markCss('.login h1 a::before', '1.9rem') . '</style>';
        echo <<<'HTML'
<style>
body.login{background:#ECEFF3;color:#22272E;font-family:"Atkinson Hyperlegible",system-ui,sans-serif;font-size:1.2rem}
#login{width:min(26rem,calc(100% - 2rem));padding:6vh 0 2rem}
.login h1 a{background:none!important;width:auto!important;height:auto!important;text-indent:0!important;font-size:2rem;font-weight:700;
  color:#22272E;display:inline-flex!important;align-items:center;gap:.6rem;margin:0 0 .5rem!important;text-decoration:none;outline-offset:4px}
.login h1 a::before{content:""}
.login h1{text-align:left}
.ss-intro{font-size:1.2rem;margin:0 0 .5rem;line-height:1.45}
.ss-demo{display:inline-block;font-size:1rem;padding:.25rem .6rem;border-radius:6px;background:repeating-linear-gradient(135deg,#FFF4CC 0 10px,#FFEDB0 10px 20px);margin:0 0 1rem}
.login form{border:1px solid #C5CCD5;border-radius:14px;box-shadow:none;padding:1.5rem;margin-top:0}
.login label{font-size:1.15rem;font-weight:700;color:#22272E}
.login form .input,.login input[type=text],.login input[type=password]{font-size:1.25rem;min-height:3.25rem;padding:.5rem .8rem;border:2px solid #555E69;border-radius:6px;margin-top:.3rem}
.login form .input:focus{border-color:#22272E;box-shadow:0 0 0 4px #F2B705}
.login .button.wp-hide-pw{height:3.25rem;min-height:3.25rem;color:#22272E}
.login .forgetmenot{float:none;margin:.25rem 0 1rem}
.login .forgetmenot label{font-weight:400;font-size:1.05rem;white-space:nowrap}
.login input[type=checkbox]{width:1.4rem;height:1.4rem}
.login .button-primary{float:none!important;width:100%;min-height:3.25rem;font-size:1.1rem!important;font-weight:700!important;line-height:1.2!important;background:#F2B705!important;border:2px solid #22272E!important;
  color:#22272E!important;border-radius:6px;text-shadow:none;box-shadow:none}
.login .button-primary:hover,.login .button-primary:active{background:#D9A300!important}
.login .button-primary:focus{box-shadow:0 0 0 3px #fff,0 0 0 6px #22272E!important}
#loginform{display:flex;flex-direction:column}
#loginform>*{order:0}
#loginform .ss-google-block{order:5}
.ss-google{margin:.5rem 0 0}
.ss-g{flex:none;width:22px;height:22px}
.ss-google-btn{display:flex;align-items:center;justify-content:center;gap:.75rem;min-height:3.25rem;box-sizing:border-box;padding:.5rem 1rem;background:#fff;color:#22272E;border:2px solid #22272E;border-radius:8px;font-size:1.1rem;font-weight:700;text-decoration:none}
.ss-google-btn:hover{background:#ECEFF3;color:#22272E}
.ss-google-btn:focus{outline:none;box-shadow:0 0 0 3px #fff,0 0 0 6px #22272E}
.ss-google-or{display:flex;align-items:center;gap:.75rem;margin:1rem 0 .25rem;color:#555E69;font-size:1rem}
.ss-google-or::before,.ss-google-or::after{content:"";flex:1;border-top:1px solid #C5CCD5}
.login #nav,.login #backtoblog{font-size:1.05rem;padding:0 1.5rem}
.login #nav a,.login #backtoblog a{color:#22272E!important;text-decoration:underline;text-decoration-color:#F2B705;text-decoration-thickness:3px}
.login .message,.login .notice,.login #login_error{border-left-color:#F2B705!important;font-size:1.05rem;border-radius:6px}
.login #login_error,.login .notice-error{border-left-color:#A4231B!important}
.language-switcher{display:none}
@media all{
  .login h1 a{font-size:35px!important;gap:.9rem;font-weight:700!important;color:#22272E!important;letter-spacing:normal!important}
  .login h1 a::before{width:53px!important;height:53px!important}
  .ss-intro,.login #nav,.login #backtoblog{text-align:center}
  .login .ss-demo{display:block!important;width:fit-content;margin:0 auto 1rem!important}
}
.ss-login-art{display:none}
@media (max-width:1023px){
  body.login{display:flex;flex-direction:column;min-height:100vh;min-height:100dvh;box-sizing:border-box}
  #login{margin:auto;padding:1.5rem 0}
}
@media (min-width:1024px){
  body.login{display:flex;align-items:center;min-height:100vh;padding-left:52vw;box-sizing:border-box}
  #login{margin:0 auto;padding:4vh 0}
  .ss-login-art{display:block;position:fixed;top:0;left:0;bottom:0;width:52vw;overflow:hidden;background:#22272E}
  .ss-login-art::before{content:"";position:absolute;right:0;top:0;bottom:0;width:8px;background:#F2B705;z-index:1}
}
</style>
HTML;
    }
}
