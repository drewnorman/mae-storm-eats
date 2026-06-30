<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class RecipeMeta implements ServiceProviderInterface
{
    public const META_SOURCE_URLS = '_mae_storm_recipe_source_urls';
    public const META_PINTEREST_URLS = '_mae_storm_recipe_pinterest_urls';
    public const META_PINTEREST_PIN_ID = '_mae_storm_recipe_pinterest_pin_id';
    public const META_IMAGES = '_mae_storm_recipe_images';
    public const META_SERVINGS = '_mae_storm_recipe_servings';
    public const META_PREP_TIME_MINUTES = '_mae_storm_recipe_prep_time_minutes';
    public const META_COOK_TIME_MINUTES = '_mae_storm_recipe_cook_time_minutes';
    public const META_TOTAL_TIME_MINUTES = '_mae_storm_recipe_total_time_minutes';
    public const META_DIFFICULTY = '_mae_storm_recipe_difficulty';
    public const META_IMPORT_STATUS = '_mae_storm_recipe_import_status';
    public const META_IMPORT_ERRORS = '_mae_storm_recipe_import_errors';
    public const META_STRUCTURED_DATA = '_mae_storm_recipe_structured_data';
    public const META_PINTEREST_SYNC_STATUS = '_mae_storm_recipe_pinterest_sync_status';
    public const META_PINTEREST_SYNCED_AT = '_mae_storm_recipe_pinterest_synced_at';

    public function register(): void
    {
        add_action('init', [$this, 'registerMeta']);
    }

    public function registerMeta(): void
    {
        $this->registerStringListMeta(self::META_SOURCE_URLS, 'Source URLs for the original recipe or recovered archive pages.');
        $this->registerStringListMeta(self::META_PINTEREST_URLS, 'Pinterest URLs associated with the recipe import.');

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_PINTEREST_PIN_ID, [
            'type' => 'string',
            'description' => 'Canonical Pinterest pin ID associated with this recipe import.',
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_IMAGES, [
            'type' => 'array',
            'description' => 'Recipe image references and source provenance.',
            'single' => true,
            'default' => [],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'attachment_id' => ['type' => 'integer'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'source_url' => ['type' => 'string', 'format' => 'uri'],
                            'alt' => ['type' => 'string'],
                            'credit' => ['type' => 'string'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => [$this, 'sanitizeArray'],
        ]);

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_SERVINGS, [
            'type' => 'string',
            'description' => 'Recipe yield exactly as supplied by the source, including units or ranges.',
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        $this->registerIntegerMeta(self::META_PREP_TIME_MINUTES, 'Preparation time in minutes.');
        $this->registerIntegerMeta(self::META_COOK_TIME_MINUTES, 'Cook time in minutes.');
        $this->registerIntegerMeta(self::META_TOTAL_TIME_MINUTES, 'Total time in minutes.');

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_DIFFICULTY, [
            'type' => 'string',
            'description' => 'Editorial difficulty label.',
            'single' => true,
            'default' => '',
            'show_in_rest' => [
                'schema' => [
                    'type' => 'string',
                    'enum' => ['', 'easy', 'medium', 'hard'],
                ],
            ],
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'sanitize_key',
        ]);

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_IMPORT_STATUS, [
            'type' => 'string',
            'description' => 'Import workflow status placeholder.',
            'single' => true,
            'default' => 'manual',
            'show_in_rest' => [
                'schema' => [
                    'type' => 'string',
                    'enum' => ['manual', 'candidate', 'needs_review', 'reviewed', 'failed'],
                ],
            ],
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'sanitize_key',
        ]);

        $this->registerStringListMeta(self::META_IMPORT_ERRORS, 'Importer warnings or errors retained for review.');

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_PINTEREST_SYNC_STATUS, [
            'type' => 'string',
            'description' => 'Pinterest browser synchronization state, independent of editorial workflow.',
            'single' => true,
            'default' => '',
            'show_in_rest' => ['schema' => ['type' => 'string', 'enum' => ['', 'pending', 'complete', 'failed']]],
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'sanitize_key',
        ]);
        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_PINTEREST_SYNCED_AT, [
            'type' => 'string',
            'description' => 'Timestamp of the last successfully finalized Pinterest synchronization.',
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_post_meta(ContentTypes::POST_TYPE_RECIPE, self::META_STRUCTURED_DATA, [
            'type' => 'object',
            'description' => 'Structured recipe data placeholder for ingredients, steps, notes, nutrition, and raw extracted artifacts.',
            'single' => true,
            'default' => [],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ingredients' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                        ],
                        'steps' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                        ],
                        'notes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'nutrition' => [
                            'type' => 'object',
                        ],
                        'raw' => [
                            'type' => 'object',
                        ],
                    ],
                    'additionalProperties' => true,
                ],
            ],
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => [$this, 'sanitizeArray'],
        ]);
    }

    public function canEditPostMeta(bool $allowed, string $meta_key, int $post_id): bool
    {
        unset($allowed, $meta_key);

        return current_user_can('edit_post', $post_id);
    }

    /**
     * @return array<mixed>
     */
    public function sanitizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function registerIntegerMeta(string $key, string $description): void
    {
        register_post_meta(ContentTypes::POST_TYPE_RECIPE, $key, [
            'type' => 'integer',
            'description' => $description,
            'single' => true,
            'default' => 0,
            'show_in_rest' => true,
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'absint',
        ]);
    }

    private function registerStringListMeta(string $key, string $description): void
    {
        register_post_meta(ContentTypes::POST_TYPE_RECIPE, $key, [
            'type' => 'array',
            'description' => $description,
            'single' => true,
            'default' => [],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => [$this, 'sanitizeStringList'],
        ]);
    }

    /**
     * @return list<string>
     */
    public function sanitizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = array_map(static fn (mixed $item): string => sanitize_text_field((string) $item), $value);
        $strings = array_filter($strings, static fn (string $item): bool => $item !== '');

        return array_values($strings);
    }
}
