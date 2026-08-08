<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class RecipeBackfill implements ServiceProviderInterface
{
    public const IMAGE_HOOK = 'mae_storm_recipes_backfill_images';
    private const IMAGE_QUEUE_OPTION = 'mae_storm_recipes_image_backfill_queue';

    public function register(): void
    {
        add_action(self::IMAGE_HOOK, [$this, 'processImages']);
    }

    public function migrate(): void
    {
        $image_queue = [];
        $recipe_ids = get_posts([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
        ]);

        foreach ($recipe_ids as $recipe_id) {
            $recipe_id = (int) $recipe_id;
            $normalized = $this->normalizedRawRecipe($recipe_id);

            $this->backfillValue($recipe_id, RecipeMeta::META_SERVINGS, (string) ($normalized['recipe_yield'] ?? ''));
            $this->backfillValue($recipe_id, RecipeMeta::META_PREP_TIME_MINUTES, (int) ($normalized['prep_time_minutes'] ?? 0));
            $this->backfillValue($recipe_id, RecipeMeta::META_COOK_TIME_MINUTES, (int) ($normalized['cook_time_minutes'] ?? 0));
            $this->backfillValue($recipe_id, RecipeMeta::META_TOTAL_TIME_MINUTES, (int) ($normalized['total_time_minutes'] ?? 0));

            $images = get_post_meta($recipe_id, RecipeMeta::META_IMAGES, true);
            $image_url = esc_url_raw((string) ($normalized['image_url'] ?? ''));
            if ((! is_array($images) || $images === []) && $image_url !== '') {
                update_post_meta($recipe_id, RecipeMeta::META_IMAGES, [[
                    'url' => $image_url,
                    'source_url' => $image_url,
                    'alt' => sanitize_text_field(get_the_title($recipe_id)),
                ]]);
            }

            $this->useExistingReviewPhoto($recipe_id);

            if (! has_post_thumbnail($recipe_id) && (new RecipeImages())->schemaImageUrl($recipe_id) !== '') {
                $image_queue[] = $recipe_id;
            }
        }

        if ($image_queue !== []) {
            update_option(self::IMAGE_QUEUE_OPTION, array_values(array_unique($image_queue)), false);
            $this->scheduleImageBatch();
        }
    }

    public function processImages(): void
    {
        $queue = array_values(array_map('absint', (array) get_option(self::IMAGE_QUEUE_OPTION, [])));
        $batch = array_splice($queue, 0, 10);

        foreach ($batch as $recipe_id) {
            $result = (new RecipeImages())->useSchemaFallback($recipe_id);
            if (is_wp_error($result)) {
                $errors = (array) get_post_meta($recipe_id, RecipeMeta::META_IMPORT_ERRORS, true);
                $errors[] = 'The schema.org recipe image could not be backfilled: ' . $result->get_error_message();
                update_post_meta($recipe_id, RecipeMeta::META_IMPORT_ERRORS, array_values(array_unique($errors)));
            }
        }

        if ($queue === []) {
            delete_option(self::IMAGE_QUEUE_OPTION);
            return;
        }

        update_option(self::IMAGE_QUEUE_OPTION, $queue, false);
        $this->scheduleImageBatch();
    }

    /** @return array<string,mixed> */
    private function normalizedRawRecipe(int $recipe_id): array
    {
        $structured = get_post_meta($recipe_id, RecipeMeta::META_STRUCTURED_DATA, true);
        $raw = is_array($structured) && is_array($structured['raw'] ?? null) ? $structured['raw'] : [];

        return $raw === [] ? [] : (new RecipeJsonLd())->normalize($raw);
    }

    private function backfillValue(int $recipe_id, string $key, string|int $value): void
    {
        $current = get_post_meta($recipe_id, $key, true);

        if ($value !== '' && $value !== 0 && ($current === '' || $current === 0 || $current === '0')) {
            update_post_meta($recipe_id, $key, $value);
        }
    }

    private function useExistingReviewPhoto(int $recipe_id): void
    {
        if (has_post_thumbnail($recipe_id)) {
            return;
        }

        $review_ids = get_posts([
            'post_type' => ContentTypes::POST_TYPE_REVIEW,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_key' => ReviewMeta::META_RECIPE_ID,
            'meta_value' => $recipe_id,
        ]);

        foreach ($review_ids as $review_id) {
            foreach ((array) get_post_meta((int) $review_id, ReviewMeta::META_ATTACHMENTS, true) as $attachment_id) {
                if ((new RecipeImages())->useReviewPhoto($recipe_id, (int) $attachment_id)) {
                    return;
                }
            }
        }
    }

    private function scheduleImageBatch(): void
    {
        if (! wp_next_scheduled(self::IMAGE_HOOK)) {
            wp_schedule_single_event(time() + 5, self::IMAGE_HOOK);
        }
    }
}
