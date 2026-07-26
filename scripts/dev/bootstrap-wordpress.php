<?php

declare(strict_types=1);

const MAE_STORM_RECIPES_PLUGIN = 'mae-storm-recipes/mae-storm-recipes.php';
const MAE_STORM_RECIPES_THEME = 'mae-storm-recipes';

chdir('/var/www/html');

if (! defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if (! is_blog_installed()) {
    $site_title = getenv('RECIPES_DEV_SITE_TITLE') ?: 'Mae Storm Recipes (Local)';
    $admin_user = getenv('RECIPES_DEV_ADMIN_USER') ?: 'admin';
    $admin_password = getenv('RECIPES_DEV_ADMIN_PASSWORD') ?: 'password';
    $admin_email = getenv('RECIPES_DEV_ADMIN_EMAIL') ?: 'admin@recipes.localhost';
    $result = wp_install(
        $site_title,
        $admin_user,
        $admin_email,
        false,
        '',
        $admin_password
    );

    if (! isset($result['user_id']) || (int) $result['user_id'] < 1) {
        fwrite(STDERR, "WordPress installation did not create the local administrator.\n");
        exit(3);
    }

    fwrite(STDOUT, "Installed WordPress with local administrator '{$admin_user}'.\n");
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

$theme = wp_get_theme(MAE_STORM_RECIPES_THEME);

if (! $theme->exists()) {
    fwrite(STDERR, "Mae Storm Recipes theme is not mounted.\n");
    exit(7);
}

if (get_stylesheet() !== MAE_STORM_RECIPES_THEME) {
    switch_theme(MAE_STORM_RECIPES_THEME);
}

if (get_stylesheet() !== MAE_STORM_RECIPES_THEME) {
    fwrite(STDERR, "WordPress did not retain the Mae Storm Recipes theme.\n");
    exit(8);
}

if (! class_exists(\Timber\Timber::class)) {
    fwrite(STDERR, "Timber is not available through vendor/autoload.php.\n");
    exit(9);
}

fwrite(STDOUT, "Mae Storm Recipes plugin and theme are active; Timber is available.\n");
