<?php
/**
 * Plugin Name: Sweet Sen Core (sandbox build)
 * Description: Senior citizen services MVP for Sweet Sen. Loaded by the Novamira sandbox loader.
 * Version: 0.1.0
 * Author: Spindiv Kinetics
 * Author URI: https://spindiv.com
 * Requires PHP: 8.2
 *
 * All code lives in ./sweetsen-core/. This file only wires the autoloader and boot hooks.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SWEETSEN_VERSION')) {
    define('SWEETSEN_VERSION', '0.1.0');
    define('SWEETSEN_DIR', __DIR__ . '/sweetsen-core/');
    define('SWEETSEN_PRIVATE_DIR', dirname(rtrim(ABSPATH, '/')) . '/sweetsen-private/');

    spl_autoload_register(static function (string $class): void {
        $prefix = 'SweetSen\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = SWEETSEN_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    add_action('init', [\SweetSen\Plugin::class, 'boot'], 5);

    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('sweetsen', \SweetSen\Cli\Command::class);
    }
}
