<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class BrowserImport implements ServiceProviderInterface
{
    public const CAPABILITY = 'mae_storm_eats_import_recipes';
    public const NAMESPACE = 'mae-storm-eats/v1';

    public function register(): void
    {
        add_filter('application_password_is_api_request', [$this, 'allowApplicationPassword']);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function allowApplicationPassword(bool $is_api_request): bool
    {
        if ($is_api_request) {
            return true;
        }

        $route = $_GET['rest_route'] ?? null;

        if (! is_string($route)) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? null;

            if (! is_string($request_uri)) {
                return false;
            }

            $path = parse_url($request_uri, PHP_URL_PATH);

            if (! is_string($path)) {
                return false;
            }

            $prefix = '/' . rest_get_url_prefix();
            $position = strpos($path, $prefix . '/');

            if ($position === false) {
                return false;
            }

            $route = substr($path, $position + strlen($prefix));
        }

        $route = '/' . ltrim(rawurldecode($route), '/');
        $import_route = '/' . self::NAMESPACE . '/pinterest-imports';

        return $route === $import_route || str_starts_with($route, $import_route . '/');
    }

    public function routes(): void
    {
        $permission = fn (): bool => current_user_can(self::CAPABILITY);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/status', [
            'methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports', [
            'methods' => 'POST', 'callback' => [$this, 'capture'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/pins', [
            'methods' => 'POST', 'callback' => [$this, 'capture'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/queue', [
            'methods' => 'GET', 'callback' => [$this, 'queue'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/recipe', [
            'methods' => 'POST', 'callback' => [$this, 'resolve'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/resolve', [
            'methods' => 'POST', 'callback' => [$this, 'resolve'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/reviews', [
            'methods' => 'POST', 'callback' => [$this, 'reviews'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/failure', [
            'methods' => 'POST', 'callback' => [$this, 'failure'], 'permission_callback' => $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/pinterest-imports/(?P<pin_id>[0-9]+)/finalize', [
            'methods' => 'POST', 'callback' => [$this, 'finalize'], 'permission_callback' => $permission,
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
            'last_import_at' => get_option('mae_storm_eats_last_browser_import_at', ''),
            'stages' => (new PinImportStore())->counts(),
        ]);
    }

    public function capture(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pins = $request->get_param('pins');
        if (! is_array($pins) || count($pins) > 5000) {
            return new WP_Error('invalid_pins', 'pins must be an array containing at most 5000 items.', ['status' => 400]);
        }
        $owner = $request->get_param('owner');
        $owner = is_array($owner) ? $owner : [];
        $store = new PinImportStore();
        $captured = 0;
        foreach ($pins as $pin) {
            if (! is_array($pin)) {
                continue;
            }
            $result = $store->capture($pin, $owner);
            if (is_wp_error($result)) { return $result; }
            $captured++;
        }
        update_option('mae_storm_eats_last_browser_import_at', gmdate('c'), false);
        return new WP_REST_Response(['captured' => $captured, 'stages' => $store->counts()]);
    }

    public function queue(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $stage = sanitize_key((string) $request->get_param('stage'));
        $mode = sanitize_key((string) $request->get_param('mode'));
        if (! in_array($stage, ['recipe', 'reviews'], true) || ! in_array($mode, ['pending', 'retry', 'refresh'], true)) {
            return new WP_Error('invalid_queue', 'stage and mode must identify a supported queue.', ['status' => 400]);
        }
        return new WP_REST_Response(['pins' => (new PinImportStore())->queue($stage, $mode, 25)]);
    }

    public function resolve(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pin_id = (string) $request['pin_id'];
        $payload = $request->get_json_params();
        if (! is_array($payload) || ! isset($payload['pin_url'])) {
            return new WP_Error('invalid_payload', 'pin_url is required.', ['status' => 400]);
        }

        $source_url = esc_url_raw((string) ($payload['source_url'] ?? ''));
        $store = new PinImportStore();
        if ($store->get($pin_id) === null) {
            $capture = $store->capture(['pin_id' => $pin_id, 'pin_url' => (string) $payload['pin_url'], 'board_url' => (string) ($payload['board_url'] ?? '')]);
            if (is_wp_error($capture)) { return $capture; }
        }
        if ($source_url === '') {
            $store->fail($pin_id, 'recipe', 'The pin did not expose a destination URL.');
            return new WP_REST_Response(['pin_id' => $pin_id, 'status' => 'failed', 'warnings' => ['The pin did not expose a destination URL.']]);
        }
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
        }

        if (! $recipe_id && $source_url !== '') {
            $recipe_id = $this->findRecipeBySource($source_url);
        }
        if (! $recipe_id) {
            $result = (new RecipeImporter())->createCandidate([
                ...$normalized,
                'pinterest_pin_id' => $pin_id,
                'pinterest_urls' => array_values(array_filter([(string) $payload['pin_url'], (string) ($payload['board_url'] ?? '')])),
                'source_urls' => $source_url !== '' ? [$source_url] : [],
                'errors' => $errors,
            ]);
            if (is_wp_error($result)) {
                return $result;
            }
            $recipe_id = $result['post_id'];
        }
        $editorial_status = (string) get_post_meta($recipe_id, RecipeMeta::META_IMPORT_STATUS, true);
        if ($editorial_status !== 'reviewed' && $normalized !== []) {
            (new RecipeImporter())->updateCandidate($recipe_id, [
                ...$normalized,
                'source_urls' => [$source_url],
            ]);
        }
        if ($editorial_status !== 'reviewed') {
            update_post_meta($recipe_id, RecipeMeta::META_IMPORT_STATUS, $errors === [] && ($normalized['ingredients'] ?? []) !== [] && ($normalized['steps'] ?? []) !== [] ? 'candidate' : 'needs_review');
        }
        update_post_meta($recipe_id, RecipeMeta::META_IMPORT_ERRORS, $errors);
        $pin_record = $store->get($pin_id);
        update_post_meta(
            $recipe_id,
            RecipeMeta::META_PINTEREST_SYNC_STATUS,
            ($pin_record['reviews_status'] ?? '') === 'complete' ? 'complete' : 'pending'
        );
        if ($errors === []) {
            $store->completeRecipe($pin_id, $recipe_id, $source_url);
            (new RecipeImages())->useSchemaFallback($recipe_id);
        } else {
            $store->fail($pin_id, 'recipe', implode(' ', $errors));
        }
        update_option('mae_storm_eats_last_browser_import_at', gmdate('c'), false);

        return new WP_REST_Response([
            'pin_id' => $pin_id, 'recipe_id' => $recipe_id, 'status' => $errors === [] ? 'complete' : 'failed',
            'warnings' => $errors,
        ]);
    }

    public function reviews(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pin_id = (string) $request['pin_id'];
        $payload = $request->get_json_params();
        $record = (new PinImportStore())->get($pin_id);
        if (! is_array($payload) || $record === null || (int) $record['recipe_id'] < 1) {
            return new WP_Error('recipe_not_imported', 'The recipe stage must complete before reviews.', ['status' => 409]);
        }
        $runtime_owner = is_array($payload['owner'] ?? null) ? $payload['owner'] : [];
        $owner = is_array($record['owner'] ?? null) && (new ReviewReconciler())->hasIdentity($record['owner']) ? $record['owner'] : $runtime_owner;
        $reconciler = new ReviewReconciler();
        if (! $reconciler->hasIdentity($owner) || ! $reconciler->isOwner($runtime_owner, $owner)) {
            return new WP_Error('owner_mismatch', 'The signed-in Pinterest account does not match the captured board owner.', ['status' => 409]);
        }
        if (! (new ReviewReconciler())->hasIdentity($record['owner'] ?? [])) {
            (new PinImportStore())->capture([
                'pin_id' => $pin_id,
                'pin_url' => (string) $record['pin_url'],
                'board_url' => (string) ($record['board_urls'][0] ?? ''),
            ], $owner);
        }
        update_post_meta((int) $record['recipe_id'], RecipeMeta::META_PINTEREST_SYNC_STATUS, 'pending');
        $results = [];
        foreach (is_array($payload['comments'] ?? null) ? $payload['comments'] : [] as $comment) {
            if (is_array($comment) && $reconciler->isOwner($comment['author'] ?? [], $owner)) {
                $results[] = $reconciler->reconcile((int) $record['recipe_id'], $pin_id, $comment, $owner, (bool) ($payload['comments_complete'] ?? false));
            }
        }
        update_option('mae_storm_eats_last_browser_import_owner', array_intersect_key($owner, array_flip(['id', 'username', 'profile_url'])), false);
        return new WP_REST_Response(['pin_id' => $pin_id, 'recipe_id' => (int) $record['recipe_id'], 'status' => 'pending', 'reviews' => $results]);
    }

    public function failure(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $stage = sanitize_key((string) $request->get_param('stage'));
        if (! in_array($stage, ['recipe', 'reviews'], true)) {
            return new WP_Error('invalid_stage', 'A supported stage is required.', ['status' => 400]);
        }
        (new PinImportStore())->fail((string) $request['pin_id'], $stage, (string) $request->get_param('error'));
        return new WP_REST_Response(['status' => 'failed']);
    }

    public function finalize(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pin_id = (string) $request['pin_id'];
        $record = (new PinImportStore())->get($pin_id);
        $recipe_id = (int) ($record['recipe_id'] ?? 0);
        if (! $recipe_id) {
            $recipe_id = $this->findRecipeByPin($pin_id);
        }
        if (! $recipe_id) {
            return new WP_Error('pin_not_resolved', 'The pin must be resolved before finalization.', ['status' => 409]);
        }
        $payload = $request->get_json_params();
        if (! is_array($payload) || ($payload['comments_complete'] ?? false) !== true) {
            return new WP_Error('comments_incomplete', 'Complete comment expansion is required before finalization.', ['status' => 409]);
        }
        $reviews = get_posts(['post_type' => ContentTypes::POST_TYPE_REVIEW, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
            'meta_key' => ReviewMeta::META_PINTEREST_PIN_ID, 'meta_value' => $pin_id]);
        foreach ($reviews as $review_id) {
            if ((string) get_post_meta((int) $review_id, ReviewMeta::META_THREAD_COMPLETE, true) !== '1'
                || (string) get_post_meta((int) $review_id, ReviewMeta::META_MEDIA_COMPLETE, true) !== '1') {
                return new WP_Error('pin_incomplete', 'Complete comments and media are required before finalization.', ['status' => 409]);
            }
        }
        $warnings = [];
        $synced_at = gmdate('c');
        update_post_meta($recipe_id, RecipeMeta::META_PINTEREST_SYNC_STATUS, 'complete');
        update_post_meta($recipe_id, RecipeMeta::META_PINTEREST_SYNCED_AT, $synced_at);
        (new PinImportStore())->completeReviews($pin_id);
        return new WP_REST_Response(['pin_id' => $pin_id, 'recipe_id' => $recipe_id, 'status' => 'complete', 'synced_at' => $synced_at, 'warnings' => $warnings]);
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
        $photo_id = sanitize_text_field((string) $request->get_param('photo_id'));
        $hash = hash_file('sha256', (string) $file['tmp_name']);
        $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => [
            ['key' => '_mae_storm_eats_pinterest_comment_id', 'value' => $comment_id],
            ['key' => '_mae_storm_eats_pinterest_content_hash', 'value' => $hash],
        ]]);
        if ($existing !== []) {
            (new ReviewReconciler())->attachMedia((string) $request['pin_id'], $comment_id, (int) $existing[0], $photo_id);
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
        update_post_meta($attachment_id, '_mae_storm_eats_pinterest_comment_id', $comment_id);
        update_post_meta($attachment_id, '_mae_storm_eats_pinterest_content_hash', $hash);
        update_post_meta($attachment_id, '_mae_storm_eats_pinterest_photo_id', $photo_id);
        (new ReviewReconciler())->attachMedia((string) $request['pin_id'], $comment_id, (int) $attachment_id, $photo_id);
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
