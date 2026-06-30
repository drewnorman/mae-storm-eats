<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class Activation
{
    private const SCHEMA_VERSION = 3;
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
        (new ReviewMeta())->registerMeta();
        self::upgrade();

        update_option('default_comment_status', 'closed');
        update_option('default_ping_status', 'closed');

        flush_rewrite_rules();
    }

    public static function upgrade(): void
    {
        if ((int) get_option('mae_storm_recipes_schema_version', 0) >= self::SCHEMA_VERSION) {
            return;
        }
        add_role('recipe_importer', __('Recipe Importer', 'mae-storm-recipes'), [
            'read' => true,
            BrowserImport::CAPABILITY => true,
        ]);
        get_role('recipe_importer')?->add_cap(BrowserImport::CAPABILITY);
        if (username_exists('pinterest-importer') === false) {
            $user_id = wp_create_user('pinterest-importer', wp_generate_password(64, true, true), '');
            if (! is_wp_error($user_id)) {
                (new \WP_User($user_id))->set_role('recipe_importer');
            }
        }
        $reviews = get_posts(['post_type' => ContentTypes::POST_TYPE_REVIEW, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]);
        foreach ($reviews as $review_id) {
            if (get_post_meta((int) $review_id, ReviewMeta::META_VERIFICATION, true) === '') {
                update_post_meta((int) $review_id, ReviewMeta::META_VERIFICATION, 'unverified');
            }
        }
        self::backfillPinImports();
        update_option('mae_storm_recipes_schema_version', self::SCHEMA_VERSION, false);
    }

    private static function backfillPinImports(): void
    {
        $store = new PinImportStore();
        $owner = get_option('mae_storm_last_browser_import_owner', []);
        $recipes = get_posts([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
        ]);
        foreach ($recipes as $recipe_id) {
            $pin_id = (string) get_post_meta((int) $recipe_id, RecipeMeta::META_PINTEREST_PIN_ID, true);
            if ($pin_id === '') { continue; }
            $pinterest_urls = (array) get_post_meta((int) $recipe_id, RecipeMeta::META_PINTEREST_URLS, true);
            $pin_url = '';
            $board_urls = [];
            foreach ($pinterest_urls as $url) {
                if (preg_match('~/pin/\d+~', (string) $url)) { $pin_url = (string) $url; }
                else { $board_urls[] = (string) $url; }
            }
            if ($pin_url === '') { $pin_url = 'https://www.pinterest.com/pin/' . $pin_id . '/'; }
            $capture = $store->capture([
                'pin_id' => $pin_id, 'pin_url' => $pin_url,
                'board_url' => (string) ($board_urls[0] ?? ''),
            ], is_array($owner) ? $owner : []);
            if (is_wp_error($capture)) { continue; }
            foreach (array_slice($board_urls, 1) as $board_url) {
                $store->capture(['pin_id' => $pin_id, 'pin_url' => $pin_url, 'board_url' => $board_url], is_array($owner) ? $owner : []);
            }
            $sources = (array) get_post_meta((int) $recipe_id, RecipeMeta::META_SOURCE_URLS, true);
            if (($sources[0] ?? '') !== '') {
                $store->completeRecipe($pin_id, (int) $recipe_id, (string) $sources[0]);
            }
            if ((string) get_post_meta((int) $recipe_id, RecipeMeta::META_PINTEREST_SYNC_STATUS, true) === 'complete') {
                $store->completeReviews($pin_id);
            }
        }
    }
}
