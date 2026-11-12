<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Audit\Audit;
use SweetSen\Roles\Roles;
use SweetSen\Tenancy\Actor;

/**
 * "Sign in with Google" for seniors and sponsors (Ramon's decision, Nov 2026). Authorization code flow with PKCE and a
 * state cookie, run through wp-login.php?action=sweetsen_google / sweetsen_google_cb. Google only proves an email: it
 * signs in the EXISTING account with that verified email. Never creates accounts. LGU staff and WordPress administrators
 * are refused and keep username + password. Client ID/secret live in a private file, entered on Settings > SweetSen Google.
 */
final class GoogleSignIn
{
    private const FILE = '/google-oauth.json';
    private const ALLOWED_ROLES = [Roles::SENIOR, Roles::ORG_USER];
    private const STAFF_CAPS = ['manage_options', 'ss_manage_beneficiaries', 'ss_release', 'ss_manage_qr', 'ss_review_pledges'];
    private const ERRORS = [
        'cancelled' => 'Google sign-in was cancelled. You can try again, or sign in with your username.',
        'expired' => 'That sign-in took too long or was opened in another browser. Please try again.',
        'google' => 'Google could not confirm your account right now. Please try again, or sign in with your username.',
        'unverified' => 'Google has not verified this email address yet, so it cannot be used to sign in.',
        'no_account' => 'No SweetSen account uses this Google email. Sign in with your username, or ask the senior citizens\' office to add this email to your account.',
        'staff' => 'LGU staff accounts sign in with their username and password, not Google.',
        'off' => 'Google sign-in is not available right now. Please sign in with your username.',
    ];

    public static function register(): void
    {
        add_action('login_form_sweetsen_google', [self::class, 'start']);
        add_action('login_form_sweetsen_google_cb', [self::class, 'callback']);
        add_filter('wp_login_errors', [self::class, 'errors']);
        add_action('login_form', [self::class, 'inForm']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_ss_google_settings', [self::class, 'save']);
    }

    /** @return array{client_id:string, client_secret:string, enabled:bool} */
    private static function config(): array
    {
        $f = SWEETSEN_PRIVATE_DIR . self::FILE;
        $c = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
        return ['client_id' => (string) ($c['client_id'] ?? ''), 'client_secret' => (string) ($c['client_secret'] ?? ''), 'enabled' => !empty($c['enabled'])];
    }

    public static function available(): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function redirectUri(): string
    {
        return add_query_arg('action', 'sweetsen_google_cb', wp_login_url());
    }

    /**
     * The button under the Sign in button, after an "or" divider. Carries Google's own "G" mark as Google's branding
     * guidelines ask for this button (Ramon approved this one exception to "no third-party logos", Nov 2026).
     */
    public static function button(): string
    {
        if (!self::available()) {
            return '';
        }
        return '<div class="ss-google-block"><p class="ss-google-or"><span>or</span></p>'
            . '<p class="ss-google"><a class="ss-google-btn" href="' . esc_url(add_query_arg('action', 'sweetsen_google', wp_login_url())) . '">'
            . '<svg class="ss-g" width="22" height="22" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg><span>Sign in with Google</span></a></p></div>';
    }

    /** Prints the button inside the sign-in form; CSS moves it below the Sign in button. */
    public static function inForm(): void
    {
        $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : 'login';
        if ($action === 'login') {
            echo self::button();
        }
    }

    public static function start(): void
    {
        if (!self::available()) {
            self::fail('off');
        }
        $state = bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        set_transient('ss_g_' . hash('sha256', $state), ['verifier' => $verifier], 10 * MINUTE_IN_SECONDS);
        setcookie('ss_g_state', $state, ['expires' => time() + 600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        $url = add_query_arg(array_map('rawurlencode', [
            'client_id' => self::config()['client_id'], 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => 'openid email', 'state' => $state, 'prompt' => 'select_account',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]), 'https://accounts.google.com/o/oauth2/v2/auth');
        nocache_headers();
        wp_redirect($url);
        exit;
    }

    public static function callback(): void
    {
        nocache_headers();
        $state = (string) ($_GET['state'] ?? '');
        $cookie = (string) ($_COOKIE['ss_g_state'] ?? '');
        setcookie('ss_g_state', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        if (isset($_GET['error'])) {
            self::fail('cancelled');
        }
        $key = 'ss_g_' . hash('sha256', $state);
        $saved = $state !== '' ? get_transient($key) : false;
        delete_transient($key);
        if (!self::available()) {
            self::fail('off');
        }
        if ($saved === false || !hash_equals($cookie, $state) || empty($_GET['code'])) {
            self::fail('expired');
        }
        $c = self::config();
        $tok = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 15, 'body' => [
            'code' => (string) $_GET['code'], 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code', 'code_verifier' => $saved['verifier'],
        ]]);
        $idToken = is_wp_error($tok) ? '' : (string) (json_decode(wp_remote_retrieve_body($tok), true)['id_token'] ?? '');
        if ($idToken === '') {
            self::fail('google');
        }
        // Google checks the token signature; we check it is for this site, from Google, and unexpired.
        $info = wp_remote_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken), ['timeout' => 15]);
        $claims = is_wp_error($info) || wp_remote_retrieve_response_code($info) !== 200 ? [] : (array) json_decode(wp_remote_retrieve_body($info), true);
        if (($claims['aud'] ?? '') !== $c['client_id'] || !in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            || (int) ($claims['exp'] ?? 0) < time()) {
            self::fail('google');
        }
        if (($claims['email_verified'] ?? '') !== 'true' && ($claims['email_verified'] ?? false) !== true) {
            self::fail('unverified');
        }
        $user = get_user_by('email', (string) ($claims['email'] ?? ''));
        $refuse = self::refusal($user ?: null);
        if ($refuse !== null) {
            self::fail($refuse);
        }
        wp_set_auth_cookie($user->ID, false, true);
        wp_set_current_user($user->ID);
        do_action('wp_login', $user->user_login, $user);
        try {
            $a = Actor::current();
            Audit::log($a->tenantId, $user->ID, 'auth.google_signin', 'user', null, ['role' => $a->role]);
        } catch (\Throwable $e) {
            // A sign-in must not fail because the audit write did; the session is already set.
        }
        wp_safe_redirect(Router::url());
        exit;
    }

    /** Null when this account may sign in with Google, else the error code. Staff are checked before roles. */
    public static function refusal(?\WP_User $user): ?string
    {
        if ($user === null) {
            return 'no_account';
        }
        foreach (self::STAFF_CAPS as $cap) {
            if (user_can($user, $cap)) {
                return 'staff';
            }
        }
        return array_intersect(self::ALLOWED_ROLES, (array) $user->roles) === [] ? 'no_account' : null;
    }

    private static function fail(string $code): never
    {
        wp_safe_redirect(add_query_arg('ss_google', $code, wp_login_url()));
        exit;
    }

    public static function errors(\WP_Error $errors): \WP_Error
    {
        $code = sanitize_key($_GET['ss_google'] ?? '');
        if (isset(self::ERRORS[$code])) {
            $errors->add('ss_google', self::ERRORS[$code]);
        }
        return $errors;
    }

    public static function menu(): void
    {
        add_options_page('SweetSen Google sign-in', 'SweetSen Google', 'manage_options', 'sweetsen-google', [self::class, 'page']);
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $c = self::config();
        $saved = isset($_GET['saved']);
        echo '<div class="wrap"><h1>SweetSen: sign in with Google</h1>'
            . ($saved ? '<div class="notice notice-success"><p>Saved.</p></div>' : '')
            . '<p>Seniors and sponsors can sign in with the Google account whose email matches their SweetSen account. LGU staff and WordPress administrators always use their password. Accounts are never created from Google.</p>'
            . '<h2>1. In Google Cloud Console</h2><ol>'
            . '<li>APIs &amp; Services &gt; OAuth consent screen: app name <strong>SweetSen</strong>, user type <strong>External</strong>, scopes <code>openid</code> and <code>email</code> only.</li>'
            . '<li>Credentials &gt; Create credentials &gt; OAuth client ID &gt; <strong>Web application</strong>.</li>'
            . '<li>Authorized redirect URI (copy exactly):<br><input type="text" readonly class="large-text code" value="' . esc_attr(self::redirectUri()) . '" onclick="this.select()"></li>'
            . '<li>Publish the consent screen (or add test users while it is in testing).</li></ol>'
            . '<h2>2. Paste the client details here</h2>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('ss_google_settings', '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="ss_google_settings"><table class="form-table" role="presentation">'
            . '<tr><th scope="row"><label for="ss-gid">Client ID</label></th><td><input id="ss-gid" name="client_id" class="large-text code" value="' . esc_attr($c['client_id']) . '"></td></tr>'
            . '<tr><th scope="row"><label for="ss-gsec">Client secret</label></th><td><input id="ss-gsec" name="client_secret" type="password" class="regular-text code" autocomplete="off" placeholder="'
            . ($c['client_secret'] !== '' ? 'Saved. Leave empty to keep it.' : '') . '"><p class="description">Stored in a private file outside the website folder, never in the database.</p></td></tr>'
            . '<tr><th scope="row">Show the button</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($c['enabled'], true, false) . '> Show "Sign in with Google" on the sign-in page</label></td></tr>'
            . '</table>' . get_submit_button('Save') . '</form></div>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('ss_google_settings')) {
            wp_die('Not allowed.', 403);
        }
        $c = self::config();
        // People often paste it as a URL ("https://…apps.googleusercontent.com/"); Google wants the bare ID.
        $id = trim(preg_replace('#^\s*https?://|/+\s*$#i', '', sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''))));
        $secret = trim((string) wp_unslash($_POST['client_secret'] ?? ''));
        $new = ['client_id' => $id, 'client_secret' => $secret !== '' ? $secret : $c['client_secret'], 'enabled' => !empty($_POST['enabled'])];
        $f = SWEETSEN_PRIVATE_DIR . self::FILE;
        file_put_contents($f, (string) wp_json_encode($new), LOCK_EX);
        @chmod($f, 0600);
        Audit::log(null, get_current_user_id(), 'settings.google_signin', 'settings', null, ['enabled' => $new['enabled'], 'has_client' => $id !== '' && $new['client_secret'] !== '']);
        wp_safe_redirect(add_query_arg(['page' => 'sweetsen-google', 'saved' => 1], admin_url('options-general.php')));
        exit;
    }
}
