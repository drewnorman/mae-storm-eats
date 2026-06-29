<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class BrowserImport implements ServiceProviderInterface
{
    public const CAPABILITY = 'mae_storm_import_recipes';
    public const NAMESPACE = 'mae-storm-recipes/v1';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $permission = fn (): bool => current_user_can(self::CAPABILITY);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/status', [
            'methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports', [
            'methods' => 'POST', 'callback' => [$this, 'discover'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/resolve', [
            'methods' => 'POST', 'callback' => [$this, 'resolve'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/comments/(?P<comment_id>[A-Za-z0-9_-]+)/media', [
            'methods' => 'POST', 'callback' => [$this, 'media'], 'permission_callback' => $permission,
        ]);
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response([
            'authenticated' => true,
            'totals' => [
                'recipes' => (int) wp_count_posts(ContentTypes::POST_TYPE_RECIPE)->draft + (int) wp_count_posts(ContentTypes::POST_TYPE_RECIPE)->publish,
                'reviews' => (int) wp_count_posts(ContentTypes::POST_TYPE_REVIEW)->publish,
            ],
            'last_import_at' => get_option('mae_storm_last_browser_import_at', ''),
        ]);
    }

    public function discover(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pins = $request->get_param('pins');
        if (! is_array($pins) || count($pins) > 5000) {
            return new WP_Error('invalid_pins', 'pins must be an array containing at most 5000 items.', ['status' => 400]);
        }
        $instructions = [];
        foreach ($pins as $pin) {
            $pin_id = preg_replace('/\D/', '', (string) (is_array($pin) ? ($pin['pin_id'] ?? '') : $pin));
            if ($pin_id === '') {
                continue;
            }
            $recipe = $this->findRecipeByPin($pin_id);
            $status = $recipe ? (string) get_post_meta($recipe, RecipeMeta::META_IMPORT_STATUS, true) : '';
            $instructions[] = [
                'pin_id' => $pin_id,
                'action' => $recipe && $status === 'reviewed' ? 'skip' : 'resolve',
                'reason' => $recipe ? $status : 'new',
            ];
        }
        return new WP_REST_Response(['instructions' => $instructions]);
    }

    public function resolve(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pin_id = (string) $request['pin_id'];
        $payload = $request->get_json_params();
        if (! is_array($payload) || ! isset($payload['pin_url'], $payload['board_url'], $payload['owner'])) {
            return new WP_Error('invalid_payload', 'pin_url, board_url, and owner are required.', ['status' => 400]);
        }
        $owner = $payload['owner'];
        if (! is_array($owner) || ! (new ReviewReconciler())->hasIdentity($owner)) {
            return new WP_Error('invalid_owner', 'Runtime owner identity is required.', ['status' => 400]);
        }

        $source_url = esc_url_raw((string) ($payload['source_url'] ?? ''));
        $recipe_id = $this->findRecipeByPin($pin_id);
        $normalized = [];
        $errors = [];
        if ($source_url !== '') {
            $html = (new SourceFetcher())->fetch($source_url);
            if (is_wp_error($html)) {
                $errors[] = $html->get_error_message();
            } else {
                $recipe = (new RecipeJsonLd())->extract($html);
                if ($recipe === null) {
                    $errors[] = 'No schema.org Recipe JSON-LD was found.';
                } else {
                    $normalized = (new RecipeJsonLd())->normalize($recipe);
                }
            }
        } else {
            $errors[] = 'The pin did not expose a destination URL.';
        }

        if (! $recipe_id && $source_url !== '') {
            $recipe_id = $this->findRecipeBySource($source_url);
        }
        if (! $recipe_id) {
            $result = (new RecipeImporter())->createCandidate([
                ...$normalized,
                'pinterest_pin_id' => $pin_id,
                'pinterest_urls' => [(string) $payload['pin_url'], (string) $payload['board_url']],
                'source_urls' => $source_url !== '' ? [$source_url] : [],
                'errors' => $errors,
            ]);
            if (is_wp_error($result)) {
                return $result;
            }
            $recipe_id = $result['post_id'];
        }
        update_post_meta($recipe_id, RecipeMeta::META_IMPORT_STATUS, $errors === [] && ($normalized['ingredients'] ?? []) !== [] && ($normalized['steps'] ?? []) !== [] ? 'candidate' : 'needs_review');
        update_post_meta($recipe_id, RecipeMeta::META_IMPORT_ERRORS, $errors);

        $reconciler = new ReviewReconciler();
        $review_results = [];
        foreach (is_array($payload['comments'] ?? null) ? $payload['comments'] : [] as $comment) {
            if (is_array($comment) && $reconciler->isOwner($comment['author'] ?? [], $owner)) {
                $review_results[] = $reconciler->reconcile($recipe_id, $pin_id, $comment, $owner, (bool) ($payload['comments_complete'] ?? false));
            }
        }
        update_option('mae_storm_last_browser_import_at', gmdate('c'), false);
        update_option('mae_storm_last_browser_import_owner', array_intersect_key($owner, array_flip(['id', 'username', 'profile_url'])), false);

        return new WP_REST_Response([
            'pin_id' => $pin_id, 'recipe_id' => $recipe_id, 'status' => get_post_meta($recipe_id, RecipeMeta::META_IMPORT_STATUS, true),
            'reviews' => $review_results, 'warnings' => $errors,
        ]);
    }

    public function media(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $files = $request->get_file_params();
        $file = $files['photo'] ?? null;
        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('invalid_upload', 'A successful multipart photo upload is required.', ['status' => 400]);
        }
        if ((int) ($file['size'] ?? 0) > 10 * MB_IN_BYTES) {
            return new WP_Error('photo_too_large', 'Photos may not exceed 10 MB.', ['status' => 413]);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (! isset($allowed[$mime])) {
            return new WP_Error('invalid_photo_type', 'Only valid JPEG, PNG, WebP, and GIF photos are accepted.', ['status' => 415]);
        }
        $comment_id = sanitize_text_field((string) $request['comment_id']);
        $hash = hash_file('sha256', (string) $file['tmp_name']);
        $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => [
            ['key' => '_mae_storm_pinterest_comment_id', 'value' => $comment_id],
            ['key' => '_mae_storm_pinterest_content_hash', 'value' => $hash],
        ]]);
        if ($existing !== []) {
            return new WP_REST_Response(['attachment_id' => (int) $existing[0], 'duplicate' => true]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file['name'] = sanitize_file_name($comment_id . '-' . substr($hash, 0, 12) . '.' . $allowed[$mime]);
        $_FILES['photo'] = $file;
        $attachment_id = media_handle_upload('photo', 0, [], ['test_form' => false]);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_mae_storm_pinterest_comment_id', $comment_id);
        update_post_meta($attachment_id, '_mae_storm_pinterest_content_hash', $hash);
        (new ReviewReconciler())->attachMedia((string) $request['pin_id'], $comment_id, (int) $attachment_id);
        return new WP_REST_Response(['attachment_id' => (int) $attachment_id, 'duplicate' => false], 201);
    }

    private function findRecipeByPin(string $pin_id): int
    {
        $ids = get_posts(['post_type' => ContentTypes::POST_TYPE_RECIPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1,
            'meta_key' => RecipeMeta::META_PINTEREST_PIN_ID, 'meta_value' => $pin_id]);
        return (int) ($ids[0] ?? 0);
    }

    private function findRecipeBySource(string $url): int
    {
        $canonical = untrailingslashit(strtolower($url));
        $ids = get_posts(['post_type' => ContentTypes::POST_TYPE_RECIPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]);
        foreach ($ids as $id) {
            foreach ((array) get_post_meta((int) $id, RecipeMeta::META_SOURCE_URLS, true) as $candidate) {
                if (untrailingslashit(strtolower((string) $candidate)) === $canonical) {
                    return (int) $id;
                }
            }
        }
        return 0;
    }
}
