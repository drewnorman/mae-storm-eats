<?php

declare(strict_types=1);

const MAE_STORM_RECIPES_PLUGIN = 'mae-storm-recipes/mae-storm-recipes.php';

chdir('/var/www/html');

if (! defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if (! is_blog_installed()) {
    fwrite(STDERR, "WordPress is not installed. Complete setup in the browser, then run `just dev-up` again.\n");
    exit(3);
}

if (! file_exists(WP_PLUGIN_DIR . '/' . MAE_STORM_RECIPES_PLUGIN)) {
    fwrite(STDERR, "Mae Storm Recipes is not mounted in the WordPress plugin directory.\n");
    exit(4);
}

if (! is_plugin_active(MAE_STORM_RECIPES_PLUGIN)) {
    $result = activate_plugin(MAE_STORM_RECIPES_PLUGIN);

    if (is_wp_error($result) && ! is_plugin_active(MAE_STORM_RECIPES_PLUGIN)) {
        fwrite(STDERR, sprintf("Plugin activation failed: %s\n", $result->get_error_message()));
        exit(5);
    }
}

if (! is_plugin_active(MAE_STORM_RECIPES_PLUGIN)) {
    fwrite(STDERR, "WordPress did not retain the Mae Storm Recipes activation state.\n");
    exit(6);
}

fwrite(STDOUT, "Mae Storm Recipes is mounted and active.\n");
