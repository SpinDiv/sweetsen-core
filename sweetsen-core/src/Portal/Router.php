<?php

declare(strict_types=1);

namespace SweetSen\Portal;

use SweetSen\Support\DomainError;
use SweetSen\Tenancy\Actor;

/**
 * Front-end portal under /sweetsen/. Pages are rendered by the plugin, outside the theme, so the
 * screens stay fast, accessible and identical whatever theme the site uses.
 */
final class Router
{
    private const REWRITE_VERSION = '3';

    /** @var array<string, string> path => page key */
    private const PAGES = [
        '' => 'home',
        'verify' => 'verify',
        'qr-cards' => 'qr-cards',
    ];

    public static function register(): void
    {
        foreach (self::PAGES as $path => $key) {
            $regex = $path === '' ? '^sweetsen/?$' : '^sweetsen/' . preg_quote($path, '#') . '/?$';
            add_rewrite_rule($regex, 'index.php?ss_page=' . $key, 'top');
        }
        add_rewrite_rule('^sweetsen/console/?$', 'index.php?ss_page=console', 'top');
        add_rewrite_rule('^sweetsen/console/([a-z-]+)/?$', 'index.php?ss_page=console&ss_sub=$matches[1]', 'top');
        add_filter('query_vars', static fn(array $v): array => array_merge($v, ['ss_page', 'ss_sub']));
        add_action('template_redirect', [self::class, 'dispatch'], 0);
        add_filter('login_redirect', [self::class, 'loginRedirect'], 10, 3);
        // SweetSen users (seniors, LGU staff, sponsors) never see WordPress: no toolbar, no wp-admin.
        add_filter('show_admin_bar', static fn(bool $show): bool => $show && current_user_can('manage_options'));
        add_action('admin_init', [self::class, 'keepOutOfAdmin'], 0);
        Login::register();

        if (get_option('sweetsen_rewrite_version') !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('sweetsen_rewrite_version', self::REWRITE_VERSION, false);
        }
    }

    public static function url(string $path = ''): string
    {
        return home_url('/sweetsen/' . ($path !== '' ? trailingslashit($path) : ''));
    }

    public static function dispatch(): void
    {
        $page = (string) get_query_var('ss_page');
        if ($page === '') {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');

        if (!is_user_logged_in()) {
            $back = $page === 'home' ? '' : ($page === 'console' ? trim('console/' . get_query_var('ss_sub'), '/') : $page);
            wp_safe_redirect(wp_login_url(self::url($back)));
            exit;
        }

        try {
            $actor = Actor::current();
        } catch (DomainError $e) {
            // Website administrators have no Sweet Sen data access by design; send them to the dashboard instead.
            if (current_user_can('manage_options')) {
                wp_safe_redirect(admin_url());
                exit;
            }
            Layout::message('No SweetSen access', 'This account is not linked to a SweetSen office. Ask your LGU administrator to add you.', 403);
            exit;
        }

        switch ($page) {
            case 'verify':
                if (!$actor->can('ss_release')) {
                    Layout::message('Release desk is for verifiers', 'Your account does not have release permission.', 403);
                    break;
                }
                ReleaseDesk::render($actor);
                break;
            case 'console':
                if (!$actor->can('ss_manage_beneficiaries')) {
                    Layout::message('The console is for LGU staff', 'Your account cannot open the LGU console.', 403);
                    break;
                }
                Console::render($actor, sanitize_key((string) get_query_var('ss_sub')));
                break;
            case 'qr-cards':
                if (!$actor->can('ss_manage_qr')) {
                    Layout::message('QR cards are for LGU staff', 'Your account cannot print QR cards.', 403);
                    break;
                }
                QrCards::render($actor);
                break;
            default:
                self::home($actor);
        }
        exit;
    }

    private static function home(Actor $actor): void
    {
        if ($actor->can('ss_release')) {
            wp_safe_redirect(self::url('verify'));
            exit;
        }
        if ($actor->can('ss_manage_beneficiaries')) {
            wp_safe_redirect(self::url('console'));
            exit;
        }
        if ($actor->can('ss_submit_pledge')) {
            SponsorHome::render($actor);
            return;
        }
        if ($actor->can('ss_view_own_profile')) {
            SeniorHome::render($actor);
            return;
        }
        Layout::message('Welcome to SweetSen', 'Your page is being prepared. Please check back soon.');
    }

    /** Sweet Sen accounts land on the portal after login, never in wp-admin. */
    /** Anyone without manage_options who opens /wp-admin/ goes to their own SweetSen page. AJAX and admin-post still work. */
    public static function keepOutOfAdmin(): void
    {
        if (wp_doing_ajax() || current_user_can('manage_options') || ($GLOBALS['pagenow'] ?? '') === 'admin-post.php') {
            return;
        }
        wp_safe_redirect(self::url());
        exit;
    }

    public static function loginRedirect(string $to, string $requested, $user): string
    {
        if ($user instanceof \WP_User && array_intersect((array) $user->roles, ['ss_senior', 'ss_lgu_admin', 'ss_program_officer', 'ss_verifier', 'ss_org_user'])) {
            return str_contains($requested, '/sweetsen/') ? $requested : self::url();
        }
        // Website administrators (no Sweet Sen role) always land on the WordPress dashboard.
        if ($user instanceof \WP_User && user_can($user, 'manage_options')) {
            return str_contains($requested, '/wp-admin/') ? $requested : admin_url();
        }
        return $to;
    }
}
