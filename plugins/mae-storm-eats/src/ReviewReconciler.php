<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class ReviewReconciler
{
    /** @param array<mixed> $identity */
    public function hasIdentity(array $identity): bool
    {
        foreach (['id', 'username', 'profile_url'] as $key) {
            if (trim((string) ($identity[$key] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $author @param array<mixed> $owner */
    public function isOwner(array $author, array $owner): bool
    {
        foreach (['id', 'username', 'profile_url'] as $key) {
            if (($author[$key] ?? '') !== '' && (string) ($author[$key] ?? '') === (string) ($owner[$key] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $comment @param array<string,mixed> $owner @return array<string,mixed> */
    public function reconcile(int $recipe_id, string $pin_id, array $comment, array $owner, bool $thread_complete): array
    {
        $comment_id = sanitize_text_field((string) ($comment['id'] ?? ''));
        $body = sanitize_textarea_field((string) ($comment['body'] ?? ''));
        $parent_id = sanitize_text_field((string) ($comment['parent_id'] ?? ''));
        $timestamp = sanitize_text_field((string) ($comment['timestamp'] ?? ''));
        $fallback = hash('sha256', implode('|', [$pin_id, $body, $timestamp, $parent_id, wp_json_encode($comment['photos'] ?? [])]));
        $key = $comment_id !== '' ? $comment_id : $fallback;
        $ids = get_posts(['post_type' => ContentTypes::POST_TYPE_REVIEW, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1,
            'meta_key' => ReviewMeta::META_COMMENT_ID, 'meta_value' => $key]);
        $id = (int) ($ids[0] ?? 0);
        $new_hash = hash('sha256', $body);
        $conflict = false;
        if ($id) {
            $old_hash = (string) get_post_meta($id, ReviewMeta::META_IMPORTED_HASH, true);
            if (hash('sha256', (string) get_post_field('post_content', $id)) === $old_hash) {
                wp_update_post(['ID' => $id, 'post_content' => $body]);
            } elseif ($old_hash !== $new_hash) {
                $conflict = true;
            }
        } else {
            $id = (int) wp_insert_post([
                'post_type' => ContentTypes::POST_TYPE_REVIEW, 'post_status' => 'publish',
                'post_title' => $body !== '' ? wp_trim_words($body, 8) : __('Pinterest photo review', 'mae-storm-eats'),
                'post_content' => $body,
            ]);
        }
        $photos = is_array($comment['photos'] ?? null) ? $comment['photos'] : [];
        update_post_meta($id, ReviewMeta::META_RECIPE_ID, $recipe_id);
        update_post_meta($id, ReviewMeta::META_PINTEREST_PIN_ID, $pin_id);
        update_post_meta($id, ReviewMeta::META_COMMENT_ID, $key);
        update_post_meta($id, ReviewMeta::META_PARENT_ID, $parent_id);
        update_post_meta($id, ReviewMeta::META_REVIEWED_AT, $timestamp);
        update_post_meta($id, ReviewMeta::META_AUTHOR, (string) ($owner['username'] ?? $owner['profile_url'] ?? $owner['id']));
        update_post_meta($id, ReviewMeta::META_IMPORTED_HASH, $new_hash);
        update_post_meta($id, ReviewMeta::META_VERIFICATION, 'verified');
        update_post_meta($id, ReviewMeta::META_CONFLICT, $conflict ? '1' : '');
        update_post_meta($id, ReviewMeta::META_MEDIA_COMPLETE, $photos === [] ? '1' : '0');
        update_post_meta($id, ReviewMeta::META_THREAD_COMPLETE, $thread_complete ? '1' : '0');
        update_post_meta($id, ReviewMeta::META_PHOTO_MANIFEST, $photos);
        return ['review_id' => $id, 'comment_id' => $key, 'conflict' => $conflict, 'media_required' => count($photos)];
    }

    public function attachMedia(string $pin_id, string $comment_id, int $attachment_id, string $photo_id = ''): void
    {
        $ids = get_posts(['post_type' => ContentTypes::POST_TYPE_REVIEW, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1,
            'meta_key' => ReviewMeta::META_COMMENT_ID, 'meta_value' => $comment_id]);
        if ($ids === []) {
            return;
        }
        $id = (int) $ids[0];
        if ((string) get_post_meta($id, ReviewMeta::META_PINTEREST_PIN_ID, true) !== $pin_id) {
            return;
        }
        $attachments = array_map('absint', (array) get_post_meta($id, ReviewMeta::META_ATTACHMENTS, true));
        $attachments[] = $attachment_id;
        update_post_meta($id, ReviewMeta::META_ATTACHMENTS, array_values(array_unique($attachments)));
        $recipe_id = (int) get_post_meta($id, ReviewMeta::META_RECIPE_ID, true);
        (new RecipeImages())->useReviewPhoto($recipe_id, $attachment_id);
        $manifest = (array) get_post_meta($id, ReviewMeta::META_PHOTO_MANIFEST, true);
        if ($photo_id !== '') {
            update_post_meta($attachment_id, '_mae_storm_eats_pinterest_photo_id', $photo_id);
        }
        $required = array_values(array_filter(array_map(static fn (mixed $photo): string => is_array($photo) ? (string) ($photo['id'] ?? '') : '', $manifest)));
        $uploaded = array_map(static fn (int $attachment): string => (string) get_post_meta($attachment, '_mae_storm_eats_pinterest_photo_id', true), array_unique($attachments));
        update_post_meta($id, ReviewMeta::META_MEDIA_COMPLETE, array_diff($required, $uploaded) === [] ? '1' : '0');
    }
}
