<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

use WP_Error;

final class PinImportStore
{
    public const META_PIN_ID = '_mae_storm_pin_import_pin_id';
    public const META_PIN_URL = '_mae_storm_pin_import_pin_url';
    public const META_BOARD_URLS = '_mae_storm_pin_import_board_urls';
    public const META_OWNER = '_mae_storm_pin_import_owner';
    public const META_DESTINATION_URL = '_mae_storm_pin_import_destination_url';
    public const META_RECIPE_ID = '_mae_storm_pin_import_recipe_id';
    public const META_RECIPE_STATUS = '_mae_storm_pin_import_recipe_status';
    public const META_REVIEW_STATUS = '_mae_storm_pin_import_review_status';
    public const META_RECIPE_ERROR = '_mae_storm_pin_import_recipe_error';
    public const META_REVIEW_ERROR = '_mae_storm_pin_import_review_error';
    public const META_CAPTURED_AT = '_mae_storm_pin_import_captured_at';
    public const META_RECIPE_UPDATED_AT = '_mae_storm_pin_import_recipe_updated_at';
    public const META_REVIEW_UPDATED_AT = '_mae_storm_pin_import_review_updated_at';

    /** @param array<string,mixed> $pin @param array<string,mixed> $owner */
    public function capture(array $pin, array $owner = []): int|WP_Error
    {
        $pin_id = preg_replace('/\D/', '', (string) ($pin['pin_id'] ?? ''));
        $pin_url = esc_url_raw((string) ($pin['pin_url'] ?? ''));
        $board_url = esc_url_raw((string) ($pin['board_url'] ?? ''));
        $pin_parts = wp_parse_url($pin_url);
        if ($pin_id === '' || $pin_url === '' || ! is_array($pin_parts)
            || ($pin_parts['scheme'] ?? '') !== 'https' || ($pin_parts['host'] ?? '') !== 'www.pinterest.com'
            || ! preg_match('~/pin/' . preg_quote($pin_id, '~') . '/?$~', (string) ($pin_parts['path'] ?? ''))) {
            return new WP_Error('invalid_pin', 'A numeric pin ID and pin URL are required.');
        }
        $pin_url = 'https://www.pinterest.com/pin/' . $pin_id . '/';
        $id = $this->find($pin_id);
        if (! $id) {
            $id = wp_insert_post([
                'post_type' => ContentTypes::POST_TYPE_PIN_IMPORT,
                'post_status' => 'publish',
                'post_title' => $pin_id,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ], true);
            if (is_wp_error($id)) {
                return $id;
            }
            update_post_meta((int) $id, self::META_PIN_ID, $pin_id);
            update_post_meta((int) $id, self::META_RECIPE_STATUS, 'pending');
            update_post_meta((int) $id, self::META_REVIEW_STATUS, 'pending');
            update_post_meta((int) $id, self::META_CAPTURED_AT, gmdate('c'));
        }
        $id = (int) $id;
        update_post_meta($id, self::META_PIN_URL, $pin_url);
        $boards = array_values(array_unique(array_filter([
            ...(array) get_post_meta($id, self::META_BOARD_URLS, true),
            $board_url,
        ])));
        update_post_meta($id, self::META_BOARD_URLS, $boards);
        if ((new ReviewReconciler())->hasIdentity($owner)) {
            update_post_meta($id, self::META_OWNER, array_intersect_key($owner, array_flip(['id', 'username', 'profile_url'])));
        }
        return $id;
    }

    public function find(string $pin_id): int
    {
        $ids = get_posts([
            'post_type' => ContentTypes::POST_TYPE_PIN_IMPORT,
            'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1,
            'meta_key' => self::META_PIN_ID, 'meta_value' => $pin_id,
        ]);
        return (int) ($ids[0] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function get(string $pin_id): ?array
    {
        $id = $this->find($pin_id);
        return $id ? $this->record($id) : null;
    }

    /** @return list<array<string,mixed>> */
    public function queue(string $stage, string $mode, int $limit = 10): array
    {
        $status = match ($mode) { 'retry' => 'failed', 'refresh' => 'complete', default => 'pending' };
        $status_key = $stage === 'reviews' ? self::META_REVIEW_STATUS : self::META_RECIPE_STATUS;
        $ids = get_posts([
            'post_type' => ContentTypes::POST_TYPE_PIN_IMPORT,
            'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
            'meta_key' => $status_key, 'meta_value' => $status,
        ]);
        $records = array_map(fn (mixed $id): array => $this->record((int) $id), $ids);
        if ($stage === 'reviews') {
            $records = array_values(array_filter($records, static fn (array $item): bool => $item['recipe_status'] === 'complete'));
        }
        $time_key = $stage === 'reviews' ? 'review_updated_at' : 'recipe_updated_at';
        usort($records, static fn (array $a, array $b): int => strcmp((string) ($a[$time_key] ?: $a['captured_at']), (string) ($b[$time_key] ?: $b['captured_at'])));
        return array_slice($records, 0, max(1, min(10, $limit)));
    }

    /** @return array<string,array<string,int>> */
    public function counts(): array
    {
        $result = ['recipe' => ['pending' => 0, 'complete' => 0, 'failed' => 0], 'reviews' => ['pending' => 0, 'complete' => 0, 'failed' => 0]];
        foreach (['recipe', 'reviews'] as $stage) {
            foreach (['pending', 'complete', 'failed'] as $status) {
                $meta_query = [[
                    'key' => $stage === 'reviews' ? self::META_REVIEW_STATUS : self::META_RECIPE_STATUS,
                    'value' => $status,
                ]];
                if ($stage === 'reviews' && $status === 'pending') {
                    $meta_query[] = ['key' => self::META_RECIPE_STATUS, 'value' => 'complete'];
                }
                $result[$stage][$status] = count(get_posts([
                    'post_type' => ContentTypes::POST_TYPE_PIN_IMPORT,
                    'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
                    'meta_query' => $meta_query,
                ]));
            }
        }
        return $result;
    }

    public function completeRecipe(string $pin_id, int $recipe_id, string $destination_url): void
    {
        $this->setStage($pin_id, 'recipe', 'complete', '');
        $id = $this->find($pin_id);
        update_post_meta($id, self::META_RECIPE_ID, $recipe_id);
        update_post_meta($id, self::META_DESTINATION_URL, esc_url_raw($destination_url));
    }

    public function completeReviews(string $pin_id): void { $this->setStage($pin_id, 'reviews', 'complete', ''); }
    public function fail(string $pin_id, string $stage, string $error): void { $this->setStage($pin_id, $stage, 'failed', $error); }

    private function setStage(string $pin_id, string $stage, string $status, string $error): void
    {
        $id = $this->find($pin_id);
        if (! $id) { return; }
        $reviews = $stage === 'reviews';
        update_post_meta($id, $reviews ? self::META_REVIEW_STATUS : self::META_RECIPE_STATUS, $status);
        update_post_meta($id, $reviews ? self::META_REVIEW_ERROR : self::META_RECIPE_ERROR, sanitize_text_field($error));
        update_post_meta($id, $reviews ? self::META_REVIEW_UPDATED_AT : self::META_RECIPE_UPDATED_AT, gmdate('c'));
    }

    /** @return array<string,mixed> */
    private function record(int $id): array
    {
        return [
            'pin_id' => (string) get_post_meta($id, self::META_PIN_ID, true),
            'pin_url' => (string) get_post_meta($id, self::META_PIN_URL, true),
            'board_urls' => (array) get_post_meta($id, self::META_BOARD_URLS, true),
            'owner' => (array) get_post_meta($id, self::META_OWNER, true),
            'destination_url' => (string) get_post_meta($id, self::META_DESTINATION_URL, true),
            'recipe_id' => (int) get_post_meta($id, self::META_RECIPE_ID, true),
            'recipe_status' => (string) get_post_meta($id, self::META_RECIPE_STATUS, true) ?: 'pending',
            'reviews_status' => (string) get_post_meta($id, self::META_REVIEW_STATUS, true) ?: 'pending',
            'recipe_error' => (string) get_post_meta($id, self::META_RECIPE_ERROR, true),
            'reviews_error' => (string) get_post_meta($id, self::META_REVIEW_ERROR, true),
            'captured_at' => (string) get_post_meta($id, self::META_CAPTURED_AT, true),
            'recipe_updated_at' => (string) get_post_meta($id, self::META_RECIPE_UPDATED_AT, true),
            'review_updated_at' => (string) get_post_meta($id, self::META_REVIEW_UPDATED_AT, true),
        ];
    }
}
