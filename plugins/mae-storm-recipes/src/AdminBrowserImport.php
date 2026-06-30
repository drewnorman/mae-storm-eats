<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class AdminBrowserImport implements ServiceProviderInterface
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('restrict_manage_posts', [$this, 'filter']);
        add_action('pre_get_posts', [$this, 'applyFilter']);
    }

    public function menu(): void
    {
        add_submenu_page('edit.php?post_type=' . ContentTypes::POST_TYPE_RECIPE, __('Pinterest Import', 'mae-storm-recipes'), __('Pinterest Import', 'mae-storm-recipes'), 'manage_options', 'mae-storm-browser-import', [$this, 'page']);
    }

    public function page(): void
    {
        $last = (string) get_option('mae_storm_last_browser_import_at', '');
        echo '<div class="wrap"><h1>' . esc_html__('Pinterest Firefox import', 'mae-storm-recipes') . '</h1>';
        echo '<p>' . esc_html__('Create a non-interactive pinterest-importer user with the Recipe Importer role, then create a revocable Application Password. Enter only that credential in the Firefox extension; never use an editorial account.', 'mae-storm-recipes') . '</p>';
        echo '<ol><li>' . esc_html__('Temporarily load extension/ from about:debugging in Firefox.', 'mae-storm-recipes') . '</li><li>' . esc_html__('Capture pin URLs from the Pinterest board while signed in as the owner.', 'mae-storm-recipes') . '</li><li>' . esc_html__('Import recipes, then import reviews and photos in separate paced batches.', 'mae-storm-recipes') . '</li></ol>';
        echo '<p><strong>' . esc_html__('Last import:', 'mae-storm-recipes') . '</strong> ' . esc_html($last !== '' ? $last : __('Never', 'mae-storm-recipes')) . '</p></div>';
    }

    public function filter(string $post_type): void
    {
        if ($post_type !== ContentTypes::POST_TYPE_REVIEW) {
            return;
        }
        $selected = sanitize_key((string) ($_GET['review_state'] ?? ''));
        echo '<select name="review_state"><option value="">' . esc_html__('All review states', 'mae-storm-recipes') . '</option>';
        foreach (['verified', 'unverified', 'conflicted', 'media-incomplete'] as $state) {
            echo '<option value="' . esc_attr($state) . '"' . selected($selected, $state, false) . '>' . esc_html(ucwords(str_replace('-', ' ', $state))) . '</option>';
        }
        echo '</select>';
    }

    public function applyFilter(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== ContentTypes::POST_TYPE_REVIEW) {
            return;
        }
        $state = sanitize_key((string) ($_GET['review_state'] ?? ''));
        $map = [
            'verified' => [ReviewMeta::META_VERIFICATION, 'verified'],
            'unverified' => [ReviewMeta::META_VERIFICATION, 'unverified'],
            'conflicted' => [ReviewMeta::META_CONFLICT, '1'],
            'media-incomplete' => [ReviewMeta::META_MEDIA_COMPLETE, '0'],
        ];
        if (isset($map[$state])) {
            $query->set('meta_key', $map[$state][0]);
            $query->set('meta_value', $map[$state][1]);
        }
    }
}
