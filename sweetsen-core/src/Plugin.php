<?php

declare(strict_types=1);

namespace SweetSen;

/**
 * Boot entry point. Portal routes, REST endpoints and admin screens are registered here
 * as they are built. Kept deliberately small so a broken module never blocks the site.
 */
final class Plugin
{
    public static function boot(): void
    {
        // Seniors never use wp-admin or see the admin bar.
        add_action('admin_init', [self::class, 'keepSeniorsOutOfAdmin']);
        add_filter('show_admin_bar', [self::class, 'hideAdminBarForSeniors']);

        Portal\Router::register();
        Portal\PublicSite::register();
        Portal\PublicForms::register();
        Portal\GoogleSignIn::register();
        Domain\Notify::register();
        add_action('rest_api_init', [Http\Rest::class, 'register']);
    }

    public static function keepSeniorsOutOfAdmin(): void
    {
        if (wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }
        $user = wp_get_current_user();
        if (in_array('ss_senior', (array) $user->roles, true)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    public static function hideAdminBarForSeniors(bool $show): bool
    {
        $user = wp_get_current_user();
        return in_array('ss_senior', (array) $user->roles, true) ? false : $show;
    }
}
