<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Error;

final class RecipeImages
{
    public function useReviewPhoto(int $recipe_id, int $attachment_id): bool
    {
        if ($recipe_id <= 0 || $attachment_id <= 0 || has_post_thumbnail($recipe_id)) {
            return false;
        }

        return (bool) set_post_thumbnail($recipe_id, $attachment_id);
    }

    /** @return int|WP_Error|null */
    public function useSchemaFallback(int $recipe_id): int|WP_Error|null
    {
        if ($recipe_id <= 0 || has_post_thumbnail($recipe_id)) {
            return null;
        }

        $url = $this->schemaImageUrl($recipe_id);

        if ($url === '') {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image($url, $recipe_id, get_the_title($recipe_id), 'id');

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if ((string) get_post_meta((int) $attachment_id, '_wp_attachment_image_alt', true) === '') {
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field(get_the_title($recipe_id)));
        }

        set_post_thumbnail($recipe_id, (int) $attachment_id);

        return (int) $attachment_id;
    }

    public function schemaImageUrl(int $recipe_id): string
    {
        $images = get_post_meta($recipe_id, RecipeMeta::META_IMAGES, true);

        if (! is_array($images)) {
            return '';
        }

        foreach ($images as $image) {
            $url = is_array($image) ? esc_url_raw((string) ($image['url'] ?? '')) : '';

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }
}
