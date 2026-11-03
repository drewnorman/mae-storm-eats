<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use RuntimeException;

final class IngredientIndex implements ServiceProviderInterface
{
    public function register(): void
    {
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [$this, 'onMetaChange'], 10, 4);
        }
    }

    public function onMetaChange(mixed $meta_id, int $post_id, string $key, mixed $value): void
    {
        unset($meta_id, $value);

        if (in_array($key, [RecipeMeta::META_STRUCTURED_DATA, RecipeMeta::META_INGREDIENT_NAMES_OVERRIDE], true)) {
            $this->sync($post_id);
        }
    }

    /** Returns true when the recipe's ingredient terms changed. */
    public function sync(int $post_id): bool
    {
        if (get_post_type($post_id) !== ContentTypes::POST_TYPE_RECIPE) {
            return false;
        }

        if (metadata_exists('post', $post_id, RecipeMeta::META_INGREDIENT_NAMES_OVERRIDE)) {
            $names = self::cleanNames(get_post_meta($post_id, RecipeMeta::META_INGREDIENT_NAMES_OVERRIDE, true));
        } else {
            $structured = get_post_meta($post_id, RecipeMeta::META_STRUCTURED_DATA, true);
            $names = self::extractNames(is_array($structured) ? ($structured['ingredients'] ?? []) : []);
        }

        $current = wp_get_object_terms($post_id, ContentTypes::TAXONOMY_INGREDIENT, ['fields' => 'names']);
        if (is_wp_error($current)) {
            throw new RuntimeException($current->get_error_message());
        }

        $old_names = array_map('strval', $current);
        sort($old_names);
        $new_names = $names;
        sort($new_names);
        if ($old_names === $new_names) {
            return false;
        }

        $result = wp_set_object_terms($post_id, $names, ContentTypes::TAXONOMY_INGREDIENT);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }

        return true;
    }

    /** Reindexes every recipe in bounded queries; safe to repeat. */
    public function backfill(): int
    {
        $count = 0;
        $page = 1;
        do {
            $query = new \WP_Query([
                'post_type' => ContentTypes::POST_TYPE_RECIPE,
                'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
                'fields' => 'ids',
                'posts_per_page' => 100,
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            foreach ($query->posts as $post_id) {
                $this->sync((int) $post_id);
                ++$count;
            }
            ++$page;
        } while ($page <= $query->max_num_pages);

        return $count;
    }

    /** @return list<string> */
    public static function extractNames(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $names = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                $line = $line['text'] ?? $line['name'] ?? '';
            }
            $name = self::extractName((string) $line);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return self::cleanNames($names);
    }

    /** @return list<string> */
    public static function cleanNames(mixed $names): array
    {
        if (! is_array($names)) {
            return [];
        }

        $result = [];
        foreach ($names as $name) {
            $name = strtolower(trim(sanitize_text_field((string) $name)));
            $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
            if ($name !== '') {
                $result[$name] = $name;
            }
        }

        $result = array_values($result);
        sort($result);
        return $result;
    }

    private static function extractName(string $line): string
    {
        $name = html_entity_decode(wp_strip_all_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = trim($name);
        // Package sizes and optional notes are not part of the ingredient name.
        $name = preg_replace('/\([^)]*\)/u', '', $name) ?? $name;
        $name = preg_replace('/^\s*(?:\d+(?:[.,]\d+)?(?:\/\d+)?|[¼½¾⅓⅔⅛⅜⅝⅞])(?:\s*[-–]\s*(?:\d+(?:[.,]\d+)?(?:\/\d+)?|[¼½¾⅓⅔]))?(?:\s+\d+\/\d+)?\s*/u', '', $name) ?? $name;
        $name = preg_replace('/^(?:cups?|c\.?|tablespoons?|tbsp\.?|teaspoons?|tsp\.?|ounces?|oz\.?|pounds?|lbs?\.?|grams?|g|kilograms?|kg|ml|liters?|cloves?|cans?|packages?|pkg\.?|sticks?|slices?|bunches?)\b\.?\s+/iu', '', $name) ?? $name;
        $name = preg_replace('/^(?:(?:finely|roughly|coarsely)\s+|grated\s+|shredded\s+|chopped\s+|diced\s+|minced\s+|sliced\s+|melted\s+|raw\s+|fresh\s+|large\s+|small\s+)+/iu', '', $name) ?? $name;
        $name = preg_split('/[,;]|\s+to taste\b|\s+drained\b|\s+and diced\b|\s+& diced\b/iu', $name)[0] ?? $name;
        $name = strtolower(trim($name, " \t\n\r\0\x0B.-"));
        if ($name === '') {
            return '';
        }

        $singular = [
            'tomatoes' => 'tomato', 'potatoes' => 'potato', 'eggs' => 'egg',
            'onions' => 'onion', 'peppers' => 'pepper', 'carrots' => 'carrot',
            'mushrooms' => 'mushroom', 'berries' => 'berry', 'cloves' => 'clove',
        ];
        $name = preg_replace_callback(
            '/\b(?:tomatoes|potatoes|eggs|onions|peppers|carrots|mushrooms|berries|cloves)\b/u',
            static fn (array $match): string => $singular[$match[0]],
            $name
        ) ?? $name;
        return trim($name);
    }
}
