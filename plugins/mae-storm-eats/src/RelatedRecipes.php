<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Post;

final class RelatedRecipes implements ServiceProviderInterface
{
    public const META_KEY = '_mae_storm_eats_related_recipe_ids';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('add_meta_boxes_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'addMetaBox']);
        add_action('save_post_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'save'], 10, 2);
        add_action('wp_ajax_mae_related_recipe_search', [$this, 'search']);
    }

    public function enqueueAdminAssets(): void
    {
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== ContentTypes::POST_TYPE_RECIPE || $screen->base !== 'post') {
            return;
        }

        $editing_post = $GLOBALS['post'] ?? null;
        $post_id = $editing_post instanceof WP_Post ? $editing_post->ID : 0;

        $script = 'admin/related-recipes.js';
        $style = 'admin/related-recipes.css';
        wp_enqueue_script(
            'mae-storm-eats-related-recipes',
            MAE_STORM_EATS_URL . $script,
            ['jquery', 'jquery-ui-sortable'],
            (string) filemtime(MAE_STORM_EATS_PATH . $script),
            true
        );
        wp_enqueue_style(
            'mae-storm-eats-related-recipes',
            MAE_STORM_EATS_URL . $style,
            [],
            (string) filemtime(MAE_STORM_EATS_PATH . $style)
        );
        wp_localize_script('mae-storm-eats-related-recipes', 'maeRelatedRecipes', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mae_related_recipes_search_' . $post_id),
            'postId' => $post_id,
            'editUrl' => admin_url('post.php?action=edit&post='),
            'searching' => __('Searching recipes…', 'mae-storm-eats'),
            'searchError' => __('Recipe search failed. Please try again.', 'mae-storm-eats'),
            'noResults' => __('No matching recipes found.', 'mae-storm-eats'),
            'noMoreResults' => __('All shown matches are already selected. Refine your search to find more recipes.', 'mae-storm-eats'),
            'oneResult' => __('matching recipe found.', 'mae-storm-eats'),
            'manyResults' => __('matching recipes found.', 'mae-storm-eats'),
            'added' => __('Related recipe added.', 'mae-storm-eats'),
            'removed' => __('Related recipe removed.', 'mae-storm-eats'),
            'reordered' => __('Related recipe order updated.', 'mae-storm-eats'),
            'add' => __('Add', 'mae-storm-eats'),
            'remove' => __('Remove', 'mae-storm-eats'),
            'moveEarlier' => __('Move earlier', 'mae-storm-eats'),
            'moveLater' => __('Move later', 'mae-storm-eats'),
            'drag' => __('Drag to reorder', 'mae-storm-eats'),
            'edit' => __('Edit recipe', 'mae-storm-eats'),
        ]);
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            'mae-related-recipes',
            __('Related Recipes', 'mae-storm-eats'),
            [$this, 'renderMetaBox'],
            ContentTypes::POST_TYPE_RECIPE,
            'normal',
            'default'
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        $ids = self::selectedIds($post->ID);
        wp_nonce_field('mae_related_recipes_' . $post->ID, 'mae_related_recipes_nonce');
        ?>
        <div class="mae-related-recipes" data-mae-related-recipes>
            <p><?php esc_html_e('Chosen recipes appear first in this order. If fewer than three are published, matching recipes fill the remaining spaces automatically.', 'mae-storm-eats'); ?></p>
            <label for="mae-related-recipes-search"><?php esc_html_e('Search published recipes', 'mae-storm-eats'); ?></label>
            <input class="widefat" id="mae-related-recipes-search" type="search" autocomplete="off" data-mae-related-search>
            <ul class="mae-related-recipes__results" data-mae-related-results hidden></ul>
            <input type="hidden" name="mae_related_recipe_ids" value="<?php echo esc_attr(implode(',', $ids)); ?>" data-mae-related-ids>
            <p data-mae-related-empty<?php echo $ids ? ' hidden' : ''; ?>><?php esc_html_e('No recipes selected yet.', 'mae-storm-eats'); ?></p>
            <ol class="mae-related-recipes__selected" data-mae-related-selected>
                <?php foreach ($ids as $id) : ?>
                    <?php $this->renderItem($id); ?>
                <?php endforeach; ?>
            </ol>
            <p class="screen-reader-text" role="status" aria-live="polite" data-mae-related-status></p>
        </div>
        <?php
    }

    private function renderItem(int $id): void
    {
        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return;
        }
        $title = get_the_title($id) ?: sprintf(__('Recipe #%d', 'mae-storm-eats'), $id);
        $status = $post->post_status !== 'publish' ? sprintf(__(' (%s)', 'mae-storm-eats'), $post->post_status) : '';
        ?>
        <li class="mae-related-recipes__item" data-id="<?php echo esc_attr((string) $id); ?>">
            <span class="mae-related-recipes__title"><?php echo esc_html($title . $status); ?></span>
            <div class="mae-related-recipes__actions">
                <button type="button" class="button mae-related-recipes__drag" data-mae-related-drag aria-label="<?php esc_attr_e('Drag to reorder', 'mae-storm-eats'); ?>"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
                <button type="button" class="button" data-mae-related-earlier aria-label="<?php esc_attr_e('Move earlier', 'mae-storm-eats'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                <button type="button" class="button" data-mae-related-later aria-label="<?php esc_attr_e('Move later', 'mae-storm-eats'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                <a class="button" href="<?php echo esc_url(get_edit_post_link($id)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Edit recipe', 'mae-storm-eats'); ?></a>
                <button type="button" class="button" data-mae-related-remove><?php esc_html_e('Remove', 'mae-storm-eats'); ?><span class="screen-reader-text"> <?php echo esc_html($title); ?></span></button>
            </div>
        </li>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void
    {
        if ($post->post_type !== ContentTypes::POST_TYPE_RECIPE
            || ! isset($_POST['mae_related_recipes_nonce'], $_POST['mae_related_recipe_ids'])
            || ! is_string($_POST['mae_related_recipes_nonce'])
            || ! is_string($_POST['mae_related_recipe_ids'])
        ) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['mae_related_recipes_nonce']));
        if (! wp_verify_nonce($nonce, 'mae_related_recipes_' . $post_id)
            || ! current_user_can('edit_post', $post_id)
            || wp_is_post_revision($post_id)
            || wp_is_post_autosave($post_id)
        ) {
            return;
        }

        $raw = wp_unslash($_POST['mae_related_recipe_ids']);
        update_post_meta($post_id, self::META_KEY, self::normalizeIds(explode(',', $raw), $post_id));
    }

    /** @return list<int> */
    public static function selectedIds(int $recipe_id): array
    {
        $stored = get_post_meta($recipe_id, self::META_KEY, true);
        return self::normalizeIds(is_array($stored) ? $stored : [], $recipe_id);
    }

    /** @param array<mixed> $values @return list<int> */
    public static function normalizeIds(array $values, int $recipe_id): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0 && $id !== $recipe_id && ! in_array($id, $ids, true) && get_post_type($id) === ContentTypes::POST_TYPE_RECIPE) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function search(): void
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $nonce = isset($_GET['nonce']) && is_string($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if ($post_id <= 0
            || get_post_type($post_id) !== ContentTypes::POST_TYPE_RECIPE
            || ! wp_verify_nonce($nonce, 'mae_related_recipes_search_' . $post_id)
            || ! current_user_can('edit_post', $post_id)
        ) {
            wp_send_json_error(['message' => __('Not allowed to search related recipes.', 'mae-storm-eats')], 403);
        }

        $query = isset($_GET['q']) && is_string($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        if ($query === '') {
            wp_send_json_success([]);
        }

        $posts = get_posts([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'post__not_in' => [$post_id],
            's' => $query,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        wp_send_json_success(array_map(static fn (WP_Post $post): array => [
            'id' => $post->ID,
            'title' => get_the_title($post->ID) ?: sprintf(__('Recipe #%d', 'mae-storm-eats'), $post->ID),
        ], $posts));
    }
}
