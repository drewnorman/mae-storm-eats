<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

use Timber\Post;
use Timber\Term;
use Timber\Timber;

final class RecipeView
{
    /**
     * @return array<string, mixed>
     */
    public static function structuredData(Post $recipe): array
    {
        $structured_data = $recipe->meta(RecipeMeta::META_STRUCTURED_DATA);

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
     * @return array{recipe_yield: string, prep_time: int, cook_time: int, total_time: int, difficulty: string}
     */
    public static function meta(Post $recipe): array
    {
        return [
            'recipe_yield' => (string) $recipe->meta(RecipeMeta::META_SERVINGS),
            'prep_time' => (int) $recipe->meta(RecipeMeta::META_PREP_TIME_MINUTES),
            'cook_time' => (int) $recipe->meta(RecipeMeta::META_COOK_TIME_MINUTES),
            'total_time' => (int) $recipe->meta(RecipeMeta::META_TOTAL_TIME_MINUTES),
            'difficulty' => (string) $recipe->meta(RecipeMeta::META_DIFFICULTY),
        ];
    }

    /**
     * @param array<string, mixed>|null $recipe_data
     * @param array{recipe_yield: string, prep_time: int, cook_time: int, total_time: int, difficulty: string}|null $recipe_meta
     */
    public static function schema(Post $recipe, ?array $recipe_data = null, ?array $recipe_meta = null): string
    {
        $recipe_data ??= self::structuredData($recipe);
        $recipe_meta ??= self::meta($recipe);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => $recipe->title(),
            'description' => wp_strip_all_tags((string) $recipe->excerpt()),
            'recipeIngredient' => self::textList($recipe_data['ingredients'] ?? []),
            'recipeInstructions' => array_map(
                static fn (string $step): array => ['@type' => 'HowToStep', 'text' => $step],
                self::textList($recipe_data['steps'] ?? [])
            ),
        ];

        if ($recipe_meta['recipe_yield'] !== '') {
            $schema['recipeYield'] = $recipe_meta['recipe_yield'];
        }

        if ($recipe_meta['total_time'] > 0) {
            $schema['totalTime'] = 'PT' . $recipe_meta['total_time'] . 'M';
        }

        return wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return list<Term>
     */
    public static function terms(string $taxonomy): array
    {
        return array_values([...Timber::get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true])]);
    }

    /**
     * @return list<Post>
     */
    public static function relatedRecipes(Post $recipe, int $limit = 3): array
    {
        if ($limit <= 0) {
            return [];
        }

        $tax_query = ['relation' => 'OR'];

        foreach ([
            ContentTypes::TAXONOMY_COURSE,
            ContentTypes::TAXONOMY_CUISINE,
            ContentTypes::TAXONOMY_DIET,
        ] as $taxonomy) {
            $term_ids = array_map(
                static fn (Term $term): int => $term->ID,
                $recipe->terms(['taxonomy' => $taxonomy])
            );

            if ($term_ids !== []) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => array_map('intval', $term_ids),
                ];
            }
        }

        $related = [];

        if (count($tax_query) > 1) {
            $related = self::postList([
                'post_type' => ContentTypes::POST_TYPE_RECIPE,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'post__not_in' => [$recipe->ID],
                'orderby' => 'date',
                'order' => 'DESC',
                'tax_query' => $tax_query,
            ]);
        }

        $related_ids = array_map(static fn (Post $post): int => $post->ID, $related);

        if (count($related) < $limit) {
            $fallback = self::postList([
                'post_type' => ContentTypes::POST_TYPE_RECIPE,
                'post_status' => 'publish',
                'posts_per_page' => $limit - count($related),
                'post__not_in' => array_merge([$recipe->ID], $related_ids),
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            $related = array_merge($related, $fallback);
        }

        return array_slice($related, 0, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function reviews(Post $recipe): array
    {
        $reviews = self::postList([
            'post_type' => ContentTypes::POST_TYPE_REVIEW,
            'post_status' => ['publish', 'private'],
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => ReviewMeta::META_RECIPE_ID,
                    'value' => $recipe->ID,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
            ],
        ]);

        usort($reviews, static function (Post $a, Post $b): int {
            $a_reviewed_at = (string) $a->meta(ReviewMeta::META_REVIEWED_AT);
            $b_reviewed_at = (string) $b->meta(ReviewMeta::META_REVIEWED_AT);
            $a_timestamp = $a_reviewed_at !== '' ? strtotime($a_reviewed_at) : (int) $a->date('U');
            $b_timestamp = $b_reviewed_at !== '' ? strtotime($b_reviewed_at) : (int) $b->date('U');

            return ((int) $b_timestamp) <=> ((int) $a_timestamp);
        });

        return array_map(static function (Post $review): array {
            $reviewed_at = (string) $review->meta(ReviewMeta::META_REVIEWED_AT);
            $timestamp = $reviewed_at !== '' ? strtotime($reviewed_at) : false;

            return [
                'title' => $review->title(),
                'body' => $review->content(),
                'author' => (string) $review->meta(ReviewMeta::META_AUTHOR),
                'reviewed_at' => $timestamp === false ? '' : wp_date(get_option('date_format'), $timestamp),
                'pinterest_url' => (string) $review->meta(ReviewMeta::META_PINTEREST_URL),
                'verified' => $review->meta(ReviewMeta::META_VERIFICATION) === 'verified',
                'media_incomplete' => $review->meta(ReviewMeta::META_MEDIA_COMPLETE) === '0',
                'photos' => array_values(array_filter(array_map(
                    static fn (mixed $attachment_id): mixed => Timber::get_image((int) $attachment_id),
                    (array) $review->meta(ReviewMeta::META_ATTACHMENTS)
                ))),
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

    /**
     * @param array<string, mixed> $query
     * @return list<Post>
     */
    private static function postList(array $query): array
    {
        $posts = Timber::get_posts($query);

        return $posts === null ? [] : array_values([...$posts]);
    }
}
