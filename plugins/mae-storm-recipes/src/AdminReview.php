<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

use WP_Post;

final class AdminReview implements ServiceProviderInterface
{
    private const NONCE_ACTION = 'mae_storm_recipes_review_meta';
    private const NONCE_FIELD = 'mae_storm_recipes_review_nonce';

    public function register(): void
    {
        add_action('add_meta_boxes_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'addMetaBox']);
        add_action('save_post_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'saveMetaBox']);
    }

    public function addMetaBox(WP_Post $post): void
    {
        unset($post);

        add_meta_box(
            'mae-storm-recipes-review',
            __('Recipe Details', 'mae-storm-recipes'),
            [$this, 'renderMetaBox'],
            ContentTypes::POST_TYPE_RECIPE,
            'normal',
            'high'
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $fields = [
            RecipeMeta::META_SERVINGS => __('Servings', 'mae-storm-recipes'),
            RecipeMeta::META_PREP_TIME_MINUTES => __('Prep minutes', 'mae-storm-recipes'),
            RecipeMeta::META_COOK_TIME_MINUTES => __('Cook minutes', 'mae-storm-recipes'),
            RecipeMeta::META_TOTAL_TIME_MINUTES => __('Total minutes', 'mae-storm-recipes'),
        ];

        echo '<div class="mae-storm-recipes-review-grid">';

        foreach ($fields as $key => $label) {
            $value = (int) get_post_meta($post->ID, $key, true);
            echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
            echo '<input class="widefat" id="' . esc_attr($key) . '" name="mae_storm_recipes_meta[' . esc_attr($key) . ']" type="number" min="0" step="1" value="' . esc_attr((string) $value) . '"></p>';
        }

        echo '</div>';

        $this->renderSelect(
            RecipeMeta::META_DIFFICULTY,
            __('Difficulty', 'mae-storm-recipes'),
            ['', 'easy', 'medium', 'hard'],
            (string) get_post_meta($post->ID, RecipeMeta::META_DIFFICULTY, true)
        );

        $this->renderSelect(
            RecipeMeta::META_IMPORT_STATUS,
            __('Import status', 'mae-storm-recipes'),
            ['manual', 'candidate', 'needs_review', 'reviewed', 'failed'],
            (string) get_post_meta($post->ID, RecipeMeta::META_IMPORT_STATUS, true)
        );

        $this->renderTextarea(
            RecipeMeta::META_SOURCE_URLS,
            __('Source URLs, one per line', 'mae-storm-recipes'),
            implode("\n", $this->listMeta($post->ID, RecipeMeta::META_SOURCE_URLS))
        );

        $this->renderTextarea(
            RecipeMeta::META_PINTEREST_URLS,
            __('Pinterest URLs, one per line', 'mae-storm-recipes'),
            implode("\n", $this->listMeta($post->ID, RecipeMeta::META_PINTEREST_URLS))
        );

        $this->renderTextarea(
            RecipeMeta::META_IMPORT_ERRORS,
            __('Import errors, one per line', 'mae-storm-recipes'),
            implode("\n", $this->listMeta($post->ID, RecipeMeta::META_IMPORT_ERRORS))
        );

        $structured_data = get_post_meta($post->ID, RecipeMeta::META_STRUCTURED_DATA, true);
        $this->renderTextarea(
            RecipeMeta::META_STRUCTURED_DATA,
            __('Structured recipe JSON', 'mae-storm-recipes'),
            wp_json_encode(is_array($structured_data) ? $structured_data : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    public function saveMetaBox(int $post_id): void
    {
        if (! $this->canSave($post_id)) {
            return;
        }

        $values = $_POST['mae_storm_recipes_meta'] ?? [];

        if (! is_array($values)) {
            return;
        }

        foreach ([RecipeMeta::META_SERVINGS, RecipeMeta::META_PREP_TIME_MINUTES, RecipeMeta::META_COOK_TIME_MINUTES, RecipeMeta::META_TOTAL_TIME_MINUTES] as $key) {
            update_post_meta($post_id, $key, absint($values[$key] ?? 0));
        }

        foreach ([RecipeMeta::META_DIFFICULTY, RecipeMeta::META_IMPORT_STATUS] as $key) {
            update_post_meta($post_id, $key, sanitize_key((string) ($values[$key] ?? '')));
        }

        foreach ([RecipeMeta::META_SOURCE_URLS, RecipeMeta::META_PINTEREST_URLS, RecipeMeta::META_IMPORT_ERRORS] as $key) {
            update_post_meta($post_id, $key, $this->sanitizeList((string) ($values[$key] ?? '')));
        }

        $structured_json = (string) ($values[RecipeMeta::META_STRUCTURED_DATA] ?? '{}');
        $decoded = json_decode(wp_unslash($structured_json), true);
        update_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, is_array($decoded) ? $decoded : []);
    }

    private function renderSelect(string $key, string $label, array $options, string $selected): void
    {
        echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<select class="widefat" id="' . esc_attr($key) . '" name="mae_storm_recipes_meta[' . esc_attr($key) . ']">';

        foreach ($options as $option) {
            echo '<option value="' . esc_attr((string) $option) . '"' . selected($selected, $option, false) . '>' . esc_html($option === '' ? __('Unspecified', 'mae-storm-recipes') : ucfirst((string) $option)) . '</option>';
        }

        echo '</select></p>';
    }

    private function renderTextarea(string $key, string $label, string $value): void
    {
        echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<textarea class="widefat" id="' . esc_attr($key) . '" name="mae_storm_recipes_meta[' . esc_attr($key) . ']" rows="5">' . esc_textarea($value) . '</textarea></p>';
    }

    private function canSave(int $post_id): bool
    {
        $nonce = $_POST[self::NONCE_FIELD] ?? '';

        if (! is_string($nonce) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), self::NONCE_ACTION)) {
            return false;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        return current_user_can('edit_post', $post_id);
    }

    /**
     * @return list<string>
     */
    private function sanitizeList(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', wp_unslash($value)) ?: [];
        $lines = array_map(static fn (string $line): string => sanitize_text_field($line), $lines);
        $lines = array_filter($lines, static fn (string $line): bool => $line !== '');

        return array_values($lines);
    }

    /**
     * @return list<string>
     */
    private function listMeta(int $post_id, string $key): array
    {
        $value = get_post_meta($post_id, $key, true);

        return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
    }
}
