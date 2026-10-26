<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class ReviewMeta implements ServiceProviderInterface
{
    public const META_RECIPE_ID = '_mae_storm_eats_review_recipe_id';
    public const META_PINTEREST_URL = '_mae_storm_eats_review_pinterest_url';
    public const META_PINTEREST_PIN_ID = '_mae_storm_eats_review_pinterest_pin_id';
    public const META_SOURCE_URL = '_mae_storm_eats_review_source_url';
    public const META_CONTENT_HASH = '_mae_storm_eats_review_content_hash';
    public const META_REVIEWED_AT = '_mae_storm_eats_review_reviewed_at';
    public const META_AUTHOR = '_mae_storm_eats_review_author';
    public const META_RAW_PAYLOAD = '_mae_storm_eats_review_raw_payload';
    public const META_COMMENT_ID = '_mae_storm_eats_review_comment_id';
    public const META_PARENT_ID = '_mae_storm_eats_review_parent_id';
    public const META_IMPORTED_HASH = '_mae_storm_eats_review_imported_hash';
    public const META_VERIFICATION = '_mae_storm_eats_review_verification';
    public const META_CONFLICT = '_mae_storm_eats_review_conflict';
    public const META_MEDIA_COMPLETE = '_mae_storm_eats_review_media_complete';
    public const META_THREAD_COMPLETE = '_mae_storm_eats_review_thread_complete';
    public const META_PHOTO_MANIFEST = '_mae_storm_eats_review_photo_manifest';
    public const META_ATTACHMENTS = '_mae_storm_eats_review_attachments';

    public function register(): void
    {
        add_action('init', [$this, 'registerMeta']);
    }

    public function registerMeta(): void
    {
        $this->registerIntegerMeta(self::META_RECIPE_ID, 'Recipe post ID this review belongs to.');
        $this->registerStringMeta(self::META_PINTEREST_URL, 'Pinterest pin URL this review was imported from.', 'esc_url_raw');
        $this->registerStringMeta(self::META_PINTEREST_PIN_ID, 'Pinterest pin ID this review was imported from.', 'sanitize_text_field');
        $this->registerStringMeta(self::META_SOURCE_URL, 'Original source URL when extracted from the review payload.', 'esc_url_raw');
        $this->registerStringMeta(self::META_CONTENT_HASH, 'SHA-256 hash of normalized review content.', 'sanitize_text_field');
        $this->registerStringMeta(self::META_REVIEWED_AT, 'Review or comment timestamp when extracted.', 'sanitize_text_field');
        $this->registerStringMeta(self::META_AUTHOR, 'Review author or account label when extracted.', 'sanitize_text_field');
        foreach ([self::META_COMMENT_ID, self::META_PARENT_ID, self::META_IMPORTED_HASH, self::META_VERIFICATION, self::META_CONFLICT, self::META_MEDIA_COMPLETE, self::META_THREAD_COMPLETE] as $key) {
            $this->registerStringMeta($key, 'Browser import review provenance.', 'sanitize_text_field');
        }
        $array_meta = [
            self::META_PHOTO_MANIFEST => [
                'description' => 'Pinterest review photo references awaiting or associated with local media.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'url' => ['type' => 'string', 'format' => 'uri'],
                    ],
                    'additionalProperties' => true,
                ],
            ],
            self::META_ATTACHMENTS => [
                'description' => 'WordPress attachment IDs imported for this review.',
                'items' => ['type' => 'integer'],
            ],
        ];
        foreach ($array_meta as $key => $config) {
            register_post_meta(ContentTypes::POST_TYPE_REVIEW, $key, [
                'type' => 'array',
                'description' => $config['description'],
                'single' => true,
                'default' => [],
                'show_in_rest' => [
                    'schema' => [
                        'type' => 'array',
                        'items' => $config['items'],
                    ],
                ],
                'auth_callback' => [$this, 'canEditPostMeta'],
                'sanitize_callback' => [$this, 'sanitizeArray'],
            ]);
        }

        register_post_meta(ContentTypes::POST_TYPE_REVIEW, self::META_RAW_PAYLOAD, [
            'type' => 'object',
            'description' => 'Raw extracted Pinterest review payload.',
            'single' => true,
            'default' => [],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'object',
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
        register_post_meta(ContentTypes::POST_TYPE_REVIEW, $key, [
            'type' => 'integer',
            'description' => $description,
            'single' => true,
            'default' => 0,
            'show_in_rest' => true,
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => 'absint',
        ]);
    }

    private function registerStringMeta(string $key, string $description, callable $sanitize_callback): void
    {
        register_post_meta(ContentTypes::POST_TYPE_REVIEW, $key, [
            'type' => 'string',
            'description' => $description,
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'auth_callback' => [$this, 'canEditPostMeta'],
            'sanitize_callback' => $sanitize_callback,
        ]);
    }
}
