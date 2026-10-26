<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Post;

final class AdminReview implements ServiceProviderInterface
{
    private const NONCE_ACTION = 'mae_storm_eats_review_meta';
    private const NONCE_FIELD = 'mae_storm_eats_review_nonce';
    private const STRUCTURED_RAW_FIELD = '_mae_storm_eats_recipe_structured_raw';

    public function register(): void
    {
        add_action('add_meta_boxes_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'addMetaBox']);
        add_action('save_post_' . ContentTypes::POST_TYPE_RECIPE, [$this, 'saveMetaBox']);
    }

    public function addMetaBox(WP_Post $post): void
    {
        unset($post);

        add_meta_box(
            'mae-storm-eats-review',
            __('Recipe Details', 'mae-storm-eats'),
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
            RecipeMeta::META_PREP_TIME_MINUTES => __('Prep minutes', 'mae-storm-eats'),
            RecipeMeta::META_COOK_TIME_MINUTES => __('Cook minutes', 'mae-storm-eats'),
            RecipeMeta::META_TOTAL_TIME_MINUTES => __('Total minutes', 'mae-storm-eats'),
        ];

        echo '<div class="mae-storm-eats-review-grid">';

        foreach ($fields as $key => $label) {
            $value = (int) get_post_meta($post->ID, $key, true);
            echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
            echo '<input class="widefat" id="' . esc_attr($key) . '" name="mae_storm_eats_meta[' . esc_attr($key) . ']" type="number" min="0" step="1" value="' . esc_attr((string) $value) . '"></p>';
        }

        $yield = (string) get_post_meta($post->ID, RecipeMeta::META_SERVINGS, true);
        echo '<p><label for="' . esc_attr(RecipeMeta::META_SERVINGS) . '">' . esc_html__('Recipe yield', 'mae-storm-eats') . '</label>';
        echo '<input class="widefat" id="' . esc_attr(RecipeMeta::META_SERVINGS) . '" name="mae_storm_eats_meta[' . esc_attr(RecipeMeta::META_SERVINGS) . ']" type="text" value="' . esc_attr($yield) . '"></p>';

        echo '</div>';

        $this->renderSelect(
            RecipeMeta::META_DIFFICULTY,
            __('Difficulty', 'mae-storm-eats'),
            ['', 'easy', 'medium', 'hard'],
            (string) get_post_meta($post->ID, RecipeMeta::META_DIFFICULTY, true)
        );

        $this->renderSelect(
            RecipeMeta::META_IMPORT_STATUS,
            __('Import status', 'mae-storm-eats'),
            ['manual', 'candidate', 'needs_review', 'reviewed', 'failed'],
            (string) get_post_meta($post->ID, RecipeMeta::META_IMPORT_STATUS, true)
        );

        $this->renderTextarea(
            RecipeMeta::META_SOURCE_URLS,
            __('Source URLs, one per line', 'mae-storm-eats'),
            implode("\n", $this->listMeta($post->ID, RecipeMeta::META_SOURCE_URLS))
        );

        $this->renderTextarea(
            RecipeMeta::META_PINTEREST_URLS,
            __('Pinterest URLs, one per line', 'mae-storm-eats'),
            implode("\n", $this->listMeta($post->ID, RecipeMeta::META_PINTEREST_URLS))
        );

        $this->renderTextarea(
            RecipeMeta::META_IMPORT_ERRORS,
            __('Import errors, one per line', 'mae-storm-eats'),
            implode("\n", $this->listMeta($post->ID, RecipeMeta::META_IMPORT_ERRORS))
        );

        $structured_data = $this->structuredData($post->ID);

        $this->renderEditor(
            'ingredients',
            __('Ingredients', 'mae-storm-eats'),
            $this->listToEditorHtml($structured_data['ingredients'] ?? [], 'ul')
        );

        $this->renderEditor(
            'steps',
            __('Steps', 'mae-storm-eats'),
            $this->listToEditorHtml($structured_data['steps'] ?? [], 'ol')
        );

        $this->renderTextarea(
            'notes',
            __('Notes, one per line', 'mae-storm-eats'),
            implode("\n", $this->stringList($structured_data['notes'] ?? []))
        );

        $this->renderTextarea(
            self::STRUCTURED_RAW_FIELD,
            __('Raw import data JSON', 'mae-storm-eats'),
            wp_json_encode([
                'nutrition' => is_array($structured_data['nutrition'] ?? null) ? $structured_data['nutrition'] : [],
                'raw' => is_array($structured_data['raw'] ?? null) ? $structured_data['raw'] : [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    public function saveMetaBox(int $post_id): void
    {
        if (! $this->canSave($post_id)) {
            return;
        }

        $values = $_POST['mae_storm_eats_meta'] ?? [];

        if (! is_array($values)) {
            return;
        }

        update_post_meta($post_id, RecipeMeta::META_SERVINGS, sanitize_text_field((string) ($values[RecipeMeta::META_SERVINGS] ?? '')));

        foreach ([RecipeMeta::META_PREP_TIME_MINUTES, RecipeMeta::META_COOK_TIME_MINUTES, RecipeMeta::META_TOTAL_TIME_MINUTES] as $key) {
            update_post_meta($post_id, $key, absint($values[$key] ?? 0));
        }

        foreach ([RecipeMeta::META_DIFFICULTY, RecipeMeta::META_IMPORT_STATUS] as $key) {
            update_post_meta($post_id, $key, sanitize_key((string) ($values[$key] ?? '')));
        }

        foreach ([RecipeMeta::META_SOURCE_URLS, RecipeMeta::META_PINTEREST_URLS, RecipeMeta::META_IMPORT_ERRORS] as $key) {
            update_post_meta($post_id, $key, $this->sanitizeList((string) ($values[$key] ?? '')));
        }

        $existing_structured = $this->structuredData($post_id);
        $raw_json = (string) ($values[self::STRUCTURED_RAW_FIELD] ?? '{}');
        $decoded_raw = json_decode(wp_unslash($raw_json), true);
        $decoded_raw = is_array($decoded_raw) ? $decoded_raw : [];

        update_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, [
            'ingredients' => $this->sanitizeRichTextList((string) ($values['ingredients'] ?? '')),
            'steps' => $this->sanitizeRichTextList((string) ($values['steps'] ?? '')),
            'notes' => $this->sanitizeList((string) ($values['notes'] ?? '')),
            'nutrition' => is_array($decoded_raw['nutrition'] ?? null)
                ? $decoded_raw['nutrition']
                : (is_array($existing_structured['nutrition'] ?? null) ? $existing_structured['nutrition'] : []),
            'raw' => is_array($decoded_raw['raw'] ?? null)
                ? $decoded_raw['raw']
                : (is_array($existing_structured['raw'] ?? null) ? $existing_structured['raw'] : []),
        ]);
    }

    private function renderSelect(string $key, string $label, array $options, string $selected): void
    {
        echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<select class="widefat" id="' . esc_attr($key) . '" name="mae_storm_eats_meta[' . esc_attr($key) . ']">';

        foreach ($options as $option) {
            echo '<option value="' . esc_attr((string) $option) . '"' . selected($selected, $option, false) . '>' . esc_html($option === '' ? __('Unspecified', 'mae-storm-eats') : ucfirst((string) $option)) . '</option>';
        }

        echo '</select></p>';
    }

    private function renderTextarea(string $key, string $label, string $value): void
    {
        echo '<p><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<textarea class="widefat" id="' . esc_attr($key) . '" name="mae_storm_eats_meta[' . esc_attr($key) . ']" rows="5">' . esc_textarea($value) . '</textarea></p>';
    }

    private function renderEditor(string $key, string $label, string $value): void
    {
        echo '<div class="mae-storm-eats-review-editor">';
        echo '<p><strong>' . esc_html($label) . '</strong></p>';
        wp_editor($value, 'mae_storm_eats_' . $key, [
            'textarea_name' => 'mae_storm_eats_meta[' . $key . ']',
            'textarea_rows' => 8,
            'media_buttons' => false,
            'teeny' => true,
            'quicktags' => true,
        ]);
        echo '</div>';
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
    private function sanitizeRichTextList(string $value): array
    {
        $html = wp_kses_post(wp_unslash($value));

        if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $html, $matches) && ($matches[1] ?? []) !== []) {
            $items = $matches[1];
        } else {
            $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?: '';
            $html = preg_replace('/<\/(?:p|div|h[1-6])>/i', "\n", $html) ?: '';
            $html = preg_replace('/<(?:p|div|h[1-6])\b[^>]*>/i', '', $html) ?: '';
            $items = preg_split('/\r\n|\r|\n/', $html) ?: [];
        }

        $items = array_map(static fn (string $item): string => trim(wp_kses_post($item)), $items);
        $items = array_filter($items, static fn (string $item): bool => trim(wp_strip_all_tags($item)) !== '');

        return array_values($items);
    }

    /**
     * @param mixed $value
     */
    private function listToEditorHtml(mixed $value, string $list_tag): string
    {
        $items = $this->stringList($value);

        if ($items === []) {
            return '';
        }

        $tag = $list_tag === 'ol' ? 'ol' : 'ul';
        $html = '<' . $tag . '>';

        foreach ($items as $item) {
            $html .= '<li>' . wp_kses_post($item) . '</li>';
        }

        return $html . '</' . $tag . '>';
    }

    /**
     * @return array<string,mixed>
     */
    private function structuredData(int $post_id): array
    {
        $structured_data = get_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, true);

        return is_array($structured_data) ? $structured_data : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_map(static function (mixed $item): string {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['name'] ?? '';
            }

            return trim((string) $item);
        }, $value);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
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
