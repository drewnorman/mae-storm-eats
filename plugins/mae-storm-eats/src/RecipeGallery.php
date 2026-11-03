<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Post;

final class RecipeGallery implements ServiceProviderInterface
{
    public const META_KEY = '_mae_storm_eats_recipe_gallery_items';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('add_meta_boxes_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'addMetaBox']);
        add_action('save_post_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'save'], 10, 2);
    }

    public function enqueueAdminAssets(): void
    {
        $screen = get_current_screen();

        if (! $screen || $screen->post_type !== ContentTypes::POST_TYPE_RECIPE || $screen->base !== 'post') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        $script = 'admin/recipe-gallery.js';
        $style = 'admin/recipe-gallery.css';
        wp_enqueue_script(
            'mae-storm-eats-recipe-gallery',
            MAE_STORM_EATS_URL . $script,
            ['jquery', 'jquery-ui-sortable', 'media-views'],
            (string) filemtime(MAE_STORM_EATS_PATH . $script),
            true
        );
        wp_enqueue_style(
            'mae-storm-eats-recipe-gallery',
            MAE_STORM_EATS_URL . $style,
            [],
            (string) filemtime(MAE_STORM_EATS_PATH . $style)
        );
        wp_localize_script('mae-storm-eats-recipe-gallery', 'maeRecipeGallery', [
            'mediaTitle' => __('Add recipe photos or videos', 'mae-storm-eats'),
            'mediaButton' => __('Add to recipe gallery', 'mae-storm-eats'),
            'moveEarlier' => __('Move earlier', 'mae-storm-eats'),
            'moveLater' => __('Move later', 'mae-storm-eats'),
            'drag' => __('Drag to reorder', 'mae-storm-eats'),
            'remove' => __('Remove', 'mae-storm-eats'),
            'edit' => __('Edit media', 'mae-storm-eats'),
            'editUrl' => admin_url('post.php?action=edit&post='),
            'added' => __('Media added to gallery.', 'mae-storm-eats'),
            'removed' => __('Media removed from gallery.', 'mae-storm-eats'),
            'reordered' => __('Gallery order updated.', 'mae-storm-eats'),
        ]);
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            'mae-recipe-gallery',
            __('Gallery', 'mae-storm-eats'),
            [$this, 'renderMetaBox'],
            ContentTypes::POST_TYPE_RECIPE,
            'normal',
            'high'
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        $featured_id = self::featuredId($post->ID);
        $additional_ids = self::additionalIds($post->ID);
        wp_nonce_field('mae_recipe_gallery_' . $post->ID, 'mae_recipe_gallery_nonce');
        ?>
        <div class="mae-recipe-gallery" data-mae-recipe-gallery data-featured-id="<?php echo esc_attr((string) $featured_id); ?>">
            <p><?php esc_html_e('The featured image always appears first. Add photos or videos below, then drag them or use the arrows to set their order.', 'mae-storm-eats'); ?></p>
            <div class="mae-recipe-gallery__featured">
                <strong><?php esc_html_e('First item: featured image', 'mae-storm-eats'); ?></strong>
                <?php if ($featured_id) : ?>
                    <?php echo wp_kses_post(wp_get_attachment_image($featured_id, 'thumbnail', true, ['alt' => ''])); ?>
                <?php else : ?>
                    <p><?php esc_html_e('Set a featured image to add the first gallery photo.', 'mae-storm-eats'); ?></p>
                <?php endif; ?>
            </div>
            <input type="hidden" name="mae_recipe_gallery_items" value="<?php echo esc_attr(implode(',', $additional_ids)); ?>" data-mae-recipe-gallery-ids>
            <p><button type="button" class="button button-secondary" data-mae-recipe-gallery-add><?php esc_html_e('Add Photos or Videos', 'mae-storm-eats'); ?></button></p>
            <p data-mae-recipe-gallery-empty<?php echo $additional_ids ? ' hidden' : ''; ?>><?php esc_html_e('No additional media yet.', 'mae-storm-eats'); ?></p>
            <ul class="mae-recipe-gallery__items" data-mae-recipe-gallery-items>
                <?php foreach ($additional_ids as $id) : ?>
                    <?php $this->renderItem($id); ?>
                <?php endforeach; ?>
            </ul>
            <p class="screen-reader-text" role="status" aria-live="polite" data-mae-recipe-gallery-status></p>
        </div>
        <?php
    }

    private function renderItem(int $id): void
    {
        $label = get_the_title($id) ?: basename((string) get_attached_file($id));
        $preview = wp_attachment_is_image($id)
            ? wp_get_attachment_image($id, 'thumbnail', true, ['alt' => ''])
            : sprintf('<img src="%s" alt="">', esc_url(wp_mime_type_icon($id)));
        ?>
        <li class="mae-recipe-gallery__item" data-id="<?php echo esc_attr((string) $id); ?>">
            <div class="mae-recipe-gallery__preview"><?php echo wp_kses_post($preview); ?></div>
            <span class="mae-recipe-gallery__label"><?php echo esc_html($label); ?></span>
            <div class="mae-recipe-gallery__actions">
                <button type="button" class="button mae-recipe-gallery__drag" data-mae-recipe-gallery-drag aria-label="<?php esc_attr_e('Drag to reorder', 'mae-storm-eats'); ?>"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>
                <button type="button" class="button" data-mae-recipe-gallery-earlier aria-label="<?php esc_attr_e('Move earlier', 'mae-storm-eats'); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>
                <button type="button" class="button" data-mae-recipe-gallery-later aria-label="<?php esc_attr_e('Move later', 'mae-storm-eats'); ?>"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
                <?php if (get_edit_post_link($id)) : ?>
                    <a class="button" href="<?php echo esc_url(get_edit_post_link($id)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Edit', 'mae-storm-eats'); ?><span class="screen-reader-text"> <?php echo esc_html($label); ?></span></a>
                <?php endif; ?>
                <button type="button" class="button" data-mae-recipe-gallery-remove><?php esc_html_e('Remove', 'mae-storm-eats'); ?><span class="screen-reader-text"> <?php echo esc_html($label); ?></span></button>
            </div>
        </li>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void
    {
        if ($post->post_type !== ContentTypes::POST_TYPE_RECIPE
            || ! isset($_POST['mae_recipe_gallery_nonce'], $_POST['mae_recipe_gallery_items'])
            || ! is_string($_POST['mae_recipe_gallery_nonce'])
            || ! is_string($_POST['mae_recipe_gallery_items'])
        ) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['mae_recipe_gallery_nonce']));
        if (! wp_verify_nonce($nonce, 'mae_recipe_gallery_' . $post_id)
            || ! current_user_can('edit_post', $post_id)
            || wp_is_post_revision($post_id)
            || wp_is_post_autosave($post_id)
        ) {
            return;
        }

        $raw = wp_unslash($_POST['mae_recipe_gallery_items']);
        update_post_meta($post_id, self::META_KEY, self::normalizeIds(explode(',', $raw), self::featuredId($post_id)));
    }

    /** @return list<int> */
    public static function additionalIds(int $recipe_id): array
    {
        $stored = get_post_meta($recipe_id, self::META_KEY, true);
        return self::normalizeIds(is_array($stored) ? $stored : [], self::featuredId($recipe_id));
    }

    /** @return list<int> */
    public static function galleryIds(int $recipe_id): array
    {
        $featured_id = self::featuredId($recipe_id);
        return $featured_id ? [$featured_id, ...self::additionalIds($recipe_id)] : self::additionalIds($recipe_id);
    }

    /** @param array<mixed> $values @return list<int> */
    public static function normalizeIds(array $values, int $featured_id = 0): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0 && $id !== $featured_id && ! in_array($id, $ids, true) && self::isSupportedAttachment($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function isSupportedAttachment(int $id): bool
    {
        if ($id <= 0 || get_post_type($id) !== 'attachment') {
            return false;
        }

        if (wp_attachment_is_image($id)) {
            return true;
        }

        return in_array(get_post_mime_type($id), ['video/mp4', 'video/webm', 'video/ogg'], true);
    }

    private static function featuredId(int $recipe_id): int
    {
        $id = get_post_thumbnail_id($recipe_id);
        return $id && wp_attachment_is_image($id) ? $id : 0;
    }

    /** @return list<array{kind:string,url:string,alt:string,mime:string}> */
    public static function frontendItems(int $recipe_id): array
    {
        $items = [];
        foreach (self::galleryIds($recipe_id) as $id) {
            $image = wp_attachment_is_image($id);
            $url = $image ? wp_get_attachment_image_url($id, 'large') : wp_get_attachment_url($id);
            if (! is_string($url) || $url === '') {
                continue;
            }
            $items[] = [
                'kind' => $image ? 'image' : 'video',
                'url' => $url,
                'alt' => $image ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : '',
                'mime' => $image ? '' : (string) get_post_mime_type($id),
            ];
        }
        return $items;
    }
}
