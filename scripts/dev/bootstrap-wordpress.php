<?php

declare(strict_types=1);

const MAE_STORM_EATS_PLUGIN = 'mae-storm-eats/mae-storm-eats.php';
const MAE_STORM_EATS_THEME = 'mae-storm-eats';

chdir('/var/www/html');

if (! defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if (! is_blog_installed()) {
    $site_title = getenv('MAE_STORM_EATS_DEV_SITE_TITLE') ?: 'Mae Storm Eats (Local)';
    $admin_user = getenv('MAE_STORM_EATS_DEV_ADMIN_USER') ?: 'admin';
    $admin_password = getenv('MAE_STORM_EATS_DEV_ADMIN_PASSWORD') ?: 'password';
    $admin_email = getenv('MAE_STORM_EATS_DEV_ADMIN_EMAIL') ?: 'admin@mae-storm-eats.localhost';
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

if (! file_exists(WP_PLUGIN_DIR . '/' . MAE_STORM_EATS_PLUGIN)) {
    fwrite(STDERR, "Mae Storm Eats is not mounted in the WordPress plugin directory.\n");
    exit(4);
}

if (! is_plugin_active(MAE_STORM_EATS_PLUGIN)) {
    $result = activate_plugin(MAE_STORM_EATS_PLUGIN);

    if (is_wp_error($result) && ! is_plugin_active(MAE_STORM_EATS_PLUGIN)) {
        fwrite(STDERR, sprintf("Plugin activation failed: %s\n", $result->get_error_message()));
        exit(5);
    }
}

if (! is_plugin_active(MAE_STORM_EATS_PLUGIN)) {
    fwrite(STDERR, "WordPress did not retain the Mae Storm Eats activation state.\n");
    exit(6);
}

$theme = wp_get_theme(MAE_STORM_EATS_THEME);

if (! $theme->exists()) {
    fwrite(STDERR, "Mae Storm Eats theme is not mounted.\n");
    exit(7);
}

if (get_stylesheet() !== MAE_STORM_EATS_THEME) {
    switch_theme(MAE_STORM_EATS_THEME);
}

if (get_stylesheet() !== MAE_STORM_EATS_THEME) {
    fwrite(STDERR, "WordPress did not retain the Mae Storm Eats theme.\n");
    exit(8);
}

if (! class_exists(\Timber\Timber::class)) {
    fwrite(STDERR, "Timber is not available through vendor/autoload.php.\n");
    exit(9);
}

fwrite(STDOUT, "Mae Storm Eats plugin and theme are active; Timber is available.\n");
