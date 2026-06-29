<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class Activation
{
    private const MINIMUM_PHP_VERSION = '8.3.0';
    private const MINIMUM_WORDPRESS_VERSION = '6.5';

    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            deactivate_plugins(plugin_basename(MAE_STORM_RECIPES_FILE));

            wp_die(
                esc_html(sprintf(
                    'Mae Storm Recipes requires PHP %s or newer. Current PHP version: %s.',
                    self::MINIMUM_PHP_VERSION,
                    PHP_VERSION
                )),
                esc_html__('Plugin activation failed', 'mae-storm-recipes'),
                ['back_link' => true]
            );
        }

        global $wp_version;

        if (isset($wp_version) && version_compare((string) $wp_version, self::MINIMUM_WORDPRESS_VERSION, '<')) {
            deactivate_plugins(plugin_basename(MAE_STORM_RECIPES_FILE));

            wp_die(
                esc_html(sprintf(
                    'Mae Storm Recipes requires WordPress %s or newer. Current WordPress version: %s.',
                    self::MINIMUM_WORDPRESS_VERSION,
                    (string) $wp_version
                )),
                esc_html__('Plugin activation failed', 'mae-storm-recipes'),
                ['back_link' => true]
            );
        }

        (new ContentTypes())->registerPostTypes();
        (new ContentTypes())->registerTaxonomies();
        (new RecipeMeta())->registerMeta();

        update_option('default_comment_status', 'closed');
        update_option('default_ping_status', 'closed');

        flush_rewrite_rules();
    }
}
