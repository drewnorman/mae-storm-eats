<?php

declare(strict_types=1);

use MaeStorm\Eats\RecipeGallery;

final class WP_Post
{
    public function __construct(public string $post_type = 'recipe')
    {
    }
}

$attachments = [
    10 => ['mime' => 'image/jpeg', 'url' => 'https://example.test/featured.jpg'],
    11 => ['mime' => 'image/png', 'url' => 'https://example.test/photo.png'],
    12 => ['mime' => 'video/mp4', 'url' => 'https://example.test/clip.mp4'],
    13 => ['mime' => 'video/quicktime', 'url' => 'https://example.test/clip.mov'],
    14 => ['mime' => 'application/pdf', 'url' => 'https://example.test/file.pdf'],
    15 => ['mime' => 'video/webm', 'url' => 'https://example.test/clip.webm'],
];
$featured = [42 => 10];
$meta = [];
$canEdit = true;
$validNonce = true;
$revision = false;
$autosave = false;

function get_post_type(int $id): ?string
{
    return isset($GLOBALS['attachments'][$id]) ? 'attachment' : null;
}

function get_post_mime_type(int $id): ?string
{
    return $GLOBALS['attachments'][$id]['mime'] ?? null;
}

function wp_attachment_is_image(int $id): bool
{
    return str_starts_with((string) get_post_mime_type($id), 'image/');
}

function get_post_thumbnail_id(int $id): int
{
    return $GLOBALS['featured'][$id] ?? 0;
}

function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    unset($single);
    return $GLOBALS['meta'][$id][$key] ?? '';
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    $GLOBALS['meta'][$id][$key] = $value;
    return true;
}

function wp_get_attachment_image_url(int $id, string $size): string|false
{
    unset($size);
    return $GLOBALS['attachments'][$id]['url'] ?? false;
}

function wp_get_attachment_url(int $id): string|false
{
    return $GLOBALS['attachments'][$id]['url'] ?? false;
}

function sanitize_text_field(string $value): string
{
    return trim($value);
}

function wp_unslash(string $value): string
{
    return stripslashes($value);
}

function wp_verify_nonce(string $nonce, string $action): bool
{
    return $GLOBALS['validNonce'] && $nonce === $action;
}

function current_user_can(string $capability, int $id): bool
{
    return $capability === 'edit_post' && $id === 42 && $GLOBALS['canEdit'];
}

function wp_is_post_revision(int $id): bool
{
    unset($id);
    return $GLOBALS['revision'];
}

function wp_is_post_autosave(int $id): bool
{
    unset($id);
    return $GLOBALS['autosave'];
}

require_once __DIR__ . '/../plugins/mae-storm-eats/src/ServiceProviderInterface.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/ContentTypes.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/RecipeGallery.php';

function expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

expect([11, 12, 15], RecipeGallery::normalizeIds([0, 10, 11, 12, 11, 13, 14, 15, 99, '12', 'bad'], 10), 'Only unique supported extra attachments remain.');

$gallery = new RecipeGallery();
$_POST = [
    'mae_recipe_gallery_nonce' => 'mae_recipe_gallery_42',
    'mae_recipe_gallery_items' => '12,11,10,12,13,14,99',
];
$gallery->save(42, new WP_Post());
expect([12, 11], $meta[42][RecipeGallery::META_KEY], 'Valid extras save in editor order without the featured image.');
expect([10, 12, 11], RecipeGallery::galleryIds(42), 'Featured image is always first.');
expect(['image', 'video', 'image'], array_column(RecipeGallery::frontendItems(42), 'kind'), 'Frontend retains mixed media order.');

$featured[42] = 11;
expect([11, 12], RecipeGallery::galleryIds(42), 'Changing featured image updates the first item and removes duplicates.');
$featured[42] = 10;

$canEdit = false;
$_POST['mae_recipe_gallery_items'] = '15';
$gallery->save(42, new WP_Post());
expect([12, 11], $meta[42][RecipeGallery::META_KEY], 'Unauthorized saves cannot change the gallery.');
$canEdit = true;
$validNonce = false;
$gallery->save(42, new WP_Post());
expect([12, 11], $meta[42][RecipeGallery::META_KEY], 'Invalid nonce cannot change the gallery.');
$validNonce = true;
$revision = true;
$gallery->save(42, new WP_Post());
expect([12, 11], $meta[42][RecipeGallery::META_KEY], 'Revision saves cannot change the gallery.');
$revision = false;
$autosave = true;
$gallery->save(42, new WP_Post());
expect([12, 11], $meta[42][RecipeGallery::META_KEY], 'Autosaves cannot change the gallery.');
$autosave = false;
$gallery->save(42, new WP_Post('post'));
expect([12, 11], $meta[42][RecipeGallery::META_KEY], 'Other post types cannot change a recipe gallery.');

unset($attachments[12]);
expect([10, 11], RecipeGallery::galleryIds(42), 'Deleted attachments are omitted when reading the gallery.');
$_POST['mae_recipe_gallery_items'] = '';
$gallery->save(42, new WP_Post());
expect([], $meta[42][RecipeGallery::META_KEY], 'Editors can clear all additional media.');

echo "Recipe gallery checks passed.\n";
