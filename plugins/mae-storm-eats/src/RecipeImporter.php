<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Error;

final class RecipeImporter
{
    /**
     * @param array<string,mixed> $candidate
     * @return array{post_id:int,status:string}
     */
    public function createCandidate(array $candidate): array|WP_Error
    {
        $title = sanitize_text_field((string) ($candidate['title'] ?? __('Untitled recipe candidate', 'mae-storm-eats')));
        $post_id = wp_insert_post([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'draft',
            'post_title' => $title !== '' ? $title : __('Untitled recipe candidate', 'mae-storm-eats'),
            'post_content' => $this->candidateContent($candidate),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, RecipeMeta::META_IMPORT_STATUS, 'needs_review');
        update_post_meta($post_id, RecipeMeta::META_SOURCE_URLS, $this->stringList($candidate['source_urls'] ?? []));
        update_post_meta($post_id, RecipeMeta::META_PINTEREST_URLS, $this->stringList($candidate['pinterest_urls'] ?? []));
        update_post_meta($post_id, RecipeMeta::META_IMAGES, $this->images($candidate));
        update_post_meta($post_id, RecipeMeta::META_SERVINGS, sanitize_text_field((string) ($candidate['recipe_yield'] ?? $candidate['servings'] ?? '')));
        update_post_meta($post_id, RecipeMeta::META_PREP_TIME_MINUTES, absint($candidate['prep_time_minutes'] ?? 0));
        update_post_meta($post_id, RecipeMeta::META_COOK_TIME_MINUTES, absint($candidate['cook_time_minutes'] ?? 0));
        update_post_meta($post_id, RecipeMeta::META_TOTAL_TIME_MINUTES, absint($candidate['total_time_minutes'] ?? 0));
        update_post_meta($post_id, RecipeMeta::META_IMPORT_ERRORS, $this->stringList($candidate['errors'] ?? []));

        $pin_id = sanitize_text_field((string) ($candidate['pinterest_pin_id'] ?? ''));

        if ($pin_id !== '') {
            update_post_meta($post_id, RecipeMeta::META_PINTEREST_PIN_ID, $pin_id);
        }

        update_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, [
            'ingredients' => is_array($candidate['ingredients'] ?? null) ? $candidate['ingredients'] : [],
            'steps' => is_array($candidate['steps'] ?? null) ? $candidate['steps'] : [],
            'notes' => $this->stringList($candidate['notes'] ?? []),
            'nutrition' => is_array($candidate['nutrition'] ?? null) ? $candidate['nutrition'] : [],
            'raw' => is_array($candidate['raw'] ?? null) ? $candidate['raw'] : [],
        ]);

        return [
            'post_id' => (int) $post_id,
            'status' => 'needs_review',
        ];
    }

    /**
     * @return list<string>
     */
    public function validateRecipe(int $post_id): array
    {
        $errors = [];
        $structured = get_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, true);

        if (! is_array($structured)) {
            return [__('Structured recipe data is missing.', 'mae-storm-eats')];
        }

        if (($structured['ingredients'] ?? []) === []) {
            $errors[] = __('Ingredients are missing.', 'mae-storm-eats');
        }

        if (($structured['steps'] ?? []) === []) {
            $errors[] = __('Steps are missing.', 'mae-storm-eats');
        }

        return $errors;
    }

    /** @param array<string,mixed> $candidate */
    public function updateCandidate(int $post_id, array $candidate): void
    {
        $title = sanitize_text_field((string) ($candidate['title'] ?? ''));
        wp_update_post(array_filter([
            'ID' => $post_id,
            'post_title' => $title,
            'post_content' => $this->candidateContent($candidate),
        ], static fn (mixed $value): bool => $value !== ''));
        update_post_meta($post_id, RecipeMeta::META_SOURCE_URLS, $this->stringList($candidate['source_urls'] ?? []));
        update_post_meta($post_id, RecipeMeta::META_IMAGES, $this->images($candidate));
        update_post_meta($post_id, RecipeMeta::META_SERVINGS, sanitize_text_field((string) ($candidate['recipe_yield'] ?? $candidate['servings'] ?? '')));
        update_post_meta($post_id, RecipeMeta::META_PREP_TIME_MINUTES, absint($candidate['prep_time_minutes'] ?? 0));
        update_post_meta($post_id, RecipeMeta::META_COOK_TIME_MINUTES, absint($candidate['cook_time_minutes'] ?? 0));
        update_post_meta($post_id, RecipeMeta::META_TOTAL_TIME_MINUTES, absint($candidate['total_time_minutes'] ?? 0));
        update_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, [
            'ingredients' => is_array($candidate['ingredients'] ?? null) ? $candidate['ingredients'] : [],
            'steps' => is_array($candidate['steps'] ?? null) ? $candidate['steps'] : [],
            'notes' => $this->stringList($candidate['notes'] ?? []),
            'nutrition' => is_array($candidate['nutrition'] ?? null) ? $candidate['nutrition'] : [],
            'raw' => is_array($candidate['raw'] ?? null) ? $candidate['raw'] : [],
        ]);
    }

    /**
     * @return list<array{post_id:int,title:string,match:string}>
     */
    public function duplicateCandidates(int $post_id): array
    {
        $post = get_post($post_id);

        if (! $post) {
            return [];
        }

        $matches = [];
        $query = new \WP_Query([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => ['draft', 'pending', 'publish', 'private'],
            'posts_per_page' => 10,
            'post__not_in' => [$post_id],
            's' => $post->post_title,
            'fields' => 'ids',
        ]);

        foreach ($query->posts as $match_id) {
            $matches[] = [
                'post_id' => (int) $match_id,
                'title' => get_the_title((int) $match_id),
                'match' => 'title',
            ];
        }

        return $matches;
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function candidateContent(array $candidate): string
    {
        $parts = [];

        foreach ($this->stringList($candidate['ingredients'] ?? []) as $ingredient) {
            $parts[] = '- ' . $ingredient;
        }

        foreach ($this->stringList($candidate['steps'] ?? []) as $index => $step) {
            $parts[] = ($index + 1) . '. ' . $step;
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $strings = array_map(static function (mixed $item): string {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['name'] ?? '';
            }

            return sanitize_text_field((string) $item);
        }, $value);
        $strings = array_filter($strings, static fn (string $item): bool => $item !== '');

        return array_values($strings);
    }

    /** @param array<string,mixed> $candidate @return list<array<string,mixed>> */
    private function images(array $candidate): array
    {
        $images = is_array($candidate['images'] ?? null) ? array_values($candidate['images']) : [];
        $image_url = esc_url_raw((string) ($candidate['image_url'] ?? ''));

        if ($images === [] && $image_url !== '') {
            $images[] = [
                'url' => $image_url,
                'source_url' => esc_url_raw((string) (($candidate['source_urls'][0] ?? '') ?: $image_url)),
                'alt' => sanitize_text_field((string) ($candidate['title'] ?? '')),
            ];
        }

        return $images;
    }
}
