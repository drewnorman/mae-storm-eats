<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class RecipeView
{
    /**
     * @return array<string, mixed>
     */
    public static function structuredData(int $post_id): array
    {
        $structured_data = get_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, true);

        if (! is_array($structured_data)) {
            return [];
        }

        foreach (['ingredients', 'steps'] as $key) {
            if (! is_array($structured_data[$key] ?? null)) {
                $structured_data[$key] = [];
                continue;
            }

            $structured_data[$key] = array_values(array_filter(array_map(static function (mixed $item): string {
                if (is_array($item)) {
                    $item = $item['text'] ?? $item['name'] ?? '';
                }

                return trim(wp_kses_post((string) $item));
            }, $structured_data[$key]), static fn (string $item): bool => trim(wp_strip_all_tags($item)) !== ''));
        }

        return $structured_data;
    }

    /**
     * @return array{servings: int, prep_time: int, cook_time: int, total_time: int, difficulty: string}
     */
    public static function meta(int $post_id): array
    {
        return [
            'servings' => (int) get_post_meta($post_id, RecipeMeta::META_SERVINGS, true),
            'prep_time' => (int) get_post_meta($post_id, RecipeMeta::META_PREP_TIME_MINUTES, true),
            'cook_time' => (int) get_post_meta($post_id, RecipeMeta::META_COOK_TIME_MINUTES, true),
            'total_time' => (int) get_post_meta($post_id, RecipeMeta::META_TOTAL_TIME_MINUTES, true),
            'difficulty' => (string) get_post_meta($post_id, RecipeMeta::META_DIFFICULTY, true),
        ];
    }

    /**
     * @param array<string, mixed>|null $recipe_data
     * @param array{servings: int, prep_time: int, cook_time: int, total_time: int, difficulty: string}|null $recipe_meta
     */
    public static function schema(int $post_id, ?array $recipe_data = null, ?array $recipe_meta = null): string
    {
        $recipe_data ??= self::structuredData($post_id);
        $recipe_meta ??= self::meta($post_id);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => get_the_title($post_id),
            'description' => wp_strip_all_tags(get_the_excerpt($post_id)),
            'recipeIngredient' => self::textList($recipe_data['ingredients'] ?? []),
            'recipeInstructions' => array_map(
                static fn (string $step): array => ['@type' => 'HowToStep', 'text' => $step],
                self::textList($recipe_data['steps'] ?? [])
            ),
        ];

        if ($recipe_meta['servings'] > 0) {
            $schema['recipeYield'] = (string) $recipe_meta['servings'];
        }

        if ($recipe_meta['total_time'] > 0) {
            $schema['totalTime'] = 'PT' . $recipe_meta['total_time'] . 'M';
        }

        return wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<int, \WP_Term>
     */
    public static function terms(string $taxonomy): array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);

        return is_wp_error($terms) ? [] : $terms;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function reviews(int $post_id): array
    {
        $query = new \WP_Query([
            'post_type' => ContentTypes::POST_TYPE_REVIEW,
            'post_status' => ['publish', 'private'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => ReviewMeta::META_RECIPE_ID,
                    'value' => $post_id,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        $reviews = array_values(array_filter($query->posts, static fn (mixed $post): bool => $post instanceof \WP_Post));

        usort($reviews, static function (\WP_Post $a, \WP_Post $b): int {
            $a_reviewed_at = (string) get_post_meta($a->ID, ReviewMeta::META_REVIEWED_AT, true);
            $b_reviewed_at = (string) get_post_meta($b->ID, ReviewMeta::META_REVIEWED_AT, true);
            $a_timestamp = $a_reviewed_at !== '' ? strtotime($a_reviewed_at) : strtotime($a->post_date_gmt);
            $b_timestamp = $b_reviewed_at !== '' ? strtotime($b_reviewed_at) : strtotime($b->post_date_gmt);

            return ((int) $b_timestamp) <=> ((int) $a_timestamp);
        });

        return array_map(static function (\WP_Post $review): array {
            $reviewed_at = (string) get_post_meta($review->ID, ReviewMeta::META_REVIEWED_AT, true);
            $timestamp = $reviewed_at !== '' ? strtotime($reviewed_at) : false;

            return [
                'title' => get_the_title($review),
                'body' => apply_filters('the_content', $review->post_content),
                'author' => (string) get_post_meta($review->ID, ReviewMeta::META_AUTHOR, true),
                'reviewed_at' => $timestamp === false ? '' : wp_date(get_option('date_format'), $timestamp),
                'pinterest_url' => (string) get_post_meta($review->ID, ReviewMeta::META_PINTEREST_URL, true),
                'verified' => get_post_meta($review->ID, ReviewMeta::META_VERIFICATION, true) === 'verified',
                'media_incomplete' => get_post_meta($review->ID, ReviewMeta::META_MEDIA_COMPLETE, true) === '0',
                'photos' => array_values(array_filter(array_map(static function (mixed $attachment_id): array {
                    $url = wp_get_attachment_image_url((int) $attachment_id, 'large');
                    return $url ? ['url' => $url, 'alt' => (string) get_post_meta((int) $attachment_id, '_wp_attachment_image_alt', true)] : [];
                }, (array) get_post_meta($review->ID, ReviewMeta::META_ATTACHMENTS, true)))),
            ];
        }, $reviews);
    }

    /**
     * @return list<string>
     */
    public static function textList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_map(static function (mixed $item): string {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['name'] ?? '';
            }

            return trim(wp_strip_all_tags((string) $item));
        }, $value);

        return array_values(array_filter($items));
    }
}
