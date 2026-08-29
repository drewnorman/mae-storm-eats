<?php
/**
 * Plugin Name: Mae Storm Recipes
 * Description: First-party recipe content model, imports, and private access controls for recipes.maestorm.com.
 * x-release-please-start-version
 * Version: 0.1.0
 * x-release-please-end
 * Requires at least: 6.5
 * Requires PHP: 8.3
 * Author: Mae Storm
 * License: Proprietary
 * Text Domain: mae-storm-recipes
 *
 * @package MaeStorm\Recipes
 */

declare(strict_types=1);

use MaeStorm\Recipes\Activation;
use MaeStorm\Recipes\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('MAE_STORM_RECIPES_FILE', __FILE__);
define('MAE_STORM_RECIPES_PATH', plugin_dir_path(__FILE__));
define('MAE_STORM_RECIPES_URL', plugin_dir_url(__FILE__));
// x-release-please-start-version
define('MAE_STORM_RECIPES_VERSION', '0.1.0');
// x-release-please-end

$mae_storm_recipes_autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (is_readable($mae_storm_recipes_autoload)) {
    require_once $mae_storm_recipes_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'MaeStorm\\Recipes\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative_class = substr($class, strlen($prefix));
        $path = MAE_STORM_RECIPES_PATH . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(MAE_STORM_RECIPES_FILE, [Activation::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->register();
    add_action('init', [Activation::class, 'upgrade'], 20);
});
