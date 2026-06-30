<?php

declare(strict_types=1);

use MaeStorm\Recipes\RecipeJsonLd;
use MaeStorm\Recipes\RecipeImages;
use MaeStorm\Recipes\RecipeImporter;
use MaeStorm\Recipes\ReviewReconciler;
use MaeStorm\Recipes\ReviewMeta;
use MaeStorm\Recipes\RecipeMeta;
use MaeStorm\Recipes\SourceFetcher;

/** @var array<string,array<string,mixed>> */
$registeredPostMeta = [];
$updatedPostMeta = [];
$featuredImages = [];

/** @param array<string,mixed> $args */
function register_post_meta(string $post_type, string $meta_key, array $args): void
{
    unset($post_type);
    $GLOBALS['registeredPostMeta'][$meta_key] = $args;
}

function wp_strip_all_tags(string $value): string
{
    return strip_tags($value);
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function __(string $value, string $domain = ''): string
{
    unset($domain);
    return $value;
}

/** @param array<string,mixed> $post */
function wp_insert_post(array $post, bool $return_error = false): int
{
    unset($post, $return_error);
    return 42;
}

function update_post_meta(int $post_id, string $key, mixed $value): bool
{
    $GLOBALS['updatedPostMeta'][$post_id][$key] = $value;
    return true;
}

function has_post_thumbnail(int $post_id): bool
{
    return isset($GLOBALS['featuredImages'][$post_id]);
}

function set_post_thumbnail(int $post_id, int $attachment_id): bool
{
    $GLOBALS['featuredImages'][$post_id] = $attachment_id;
    return true;
}

function esc_url_raw(string $url, array $protocols = []): string
{
    unset($protocols);
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

final class WP_Error
{
    public function __construct(public string $code, public string $message = '')
    {
    }
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

require_once __DIR__ . '/../plugins/mae-storm-recipes/src/RecipeJsonLd.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/RecipeImages.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/RecipeImporter.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/ReviewReconciler.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/ServiceProviderInterface.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/ContentTypes.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/RecipeMeta.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/ReviewMeta.php';
require_once __DIR__ . '/../plugins/mae-storm-recipes/src/SourceFetcher.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$extractor = new RecipeJsonLd();
$variants = [
    '<script type="application/ld+json">{"@type":"Recipe","name":"Soup","recipeIngredient":["water"],"recipeInstructions":["Boil"],"recipeYield":"4-6 bowls","prepTime":"PT5M","cookTime":"PT1H","totalTime":"PT1H5M","image":"https://example.com/soup.jpg"}</script>',
    '<script type="application/ld+json">[{"@type":"Thing"},{"@type":"Recipe","name":"Soup","recipeIngredient":["water"],"recipeInstructions":[{"@type":"HowToStep","text":"Boil"}]}]</script>',
    '<script type="application/ld+json">{"@graph":[{"@type":"Recipe","name":"Soup","recipeInstructions":[{"@type":"HowToSection","name":"Finish","itemListElement":[{"text":"Serve"}]}]}]}</script>',
];
foreach ($variants as $html) {
    $recipe = $extractor->extract($html);
    assertSameValue('Soup', $recipe['name'] ?? '', 'Recipe must be found in object, array, and @graph variants.');
}
$normalized = $extractor->normalize($extractor->extract($variants[0]) ?? []);
assertSameValue(65, $normalized['total_time_minutes'], 'ISO-8601 duration must normalize to minutes.');
assertSameValue(5, $normalized['prep_time_minutes'], 'Prep time must normalize to minutes.');
assertSameValue(60, $normalized['cook_time_minutes'], 'Cook time must normalize to minutes.');
assertSameValue('4-6 bowls', $normalized['recipe_yield'], 'Recipe yield text and units must be preserved.');
assertSameValue('https://example.com/nested.jpg', $extractor->normalize(['image' => [['@type' => 'ImageObject', 'url' => 'https://example.com/nested.jpg']]])['image_url'], 'Nested schema image objects must normalize.');
assertSameValue('Boil', $normalized['steps'][0]['text'], 'Instruction text must normalize.');
assertSameValue(null, $extractor->extract('<script type="application/ld+json">{bad}</script>'), 'Malformed JSON-LD must be ignored.');

$candidate = (new RecipeImporter())->createCandidate([
    ...$normalized,
    'source_urls' => ['https://example.com/soup'],
]);
assertSameValue(42, $candidate['post_id'] ?? 0, 'Recipe candidate must be created.');
assertSameValue('4-6 bowls', $updatedPostMeta[42][RecipeMeta::META_SERVINGS] ?? null, 'Recipe yield must persist as text.');
assertSameValue(5, $updatedPostMeta[42][RecipeMeta::META_PREP_TIME_MINUTES] ?? null, 'Prep time must persist.');
assertSameValue(60, $updatedPostMeta[42][RecipeMeta::META_COOK_TIME_MINUTES] ?? null, 'Cook time must persist.');
assertSameValue(65, $updatedPostMeta[42][RecipeMeta::META_TOTAL_TIME_MINUTES] ?? null, 'Total time must persist.');
assertSameValue('https://example.com/soup.jpg', $updatedPostMeta[42][RecipeMeta::META_IMAGES][0]['url'] ?? null, 'Schema image reference must persist.');

$images = new RecipeImages();
assertSameValue(true, $images->useReviewPhoto(42, 100), 'The first review photo must become the featured image.');
assertSameValue(false, $images->useReviewPhoto(42, 101), 'A later review photo must not replace the featured image.');
assertSameValue(100, $featuredImages[42] ?? null, 'The first featured image must be preserved.');

$reconciler = new ReviewReconciler();
$owner = ['id' => '1', 'username' => 'mae', 'profile_url' => 'https://www.pinterest.com/mae/'];
assertSameValue(true, $reconciler->hasIdentity(['username' => 'mae']), 'A Pinterest username must be accepted as an owner identity.');
assertSameValue(true, $reconciler->hasIdentity(['profile_url' => 'https://www.pinterest.com/mae/']), 'A Pinterest profile URL must be accepted as an owner identity.');
assertSameValue(false, $reconciler->hasIdentity(['id' => '', 'username' => '  ']), 'An empty owner identity must be rejected.');
assertSameValue(true, $reconciler->isOwner(['username' => 'mae'], $owner), 'Owner username must match.');
assertSameValue(false, $reconciler->isOwner(['username' => 'other'], $owner), 'Other users must be excluded.');

$reviewMeta = new ReviewMeta();
$reviewMeta->registerMeta();
$photoSchema = $registeredPostMeta[ReviewMeta::META_PHOTO_MANIFEST]['show_in_rest']['schema'] ?? [];
$attachmentSchema = $registeredPostMeta[ReviewMeta::META_ATTACHMENTS]['show_in_rest']['schema'] ?? [];
assertSameValue('object', $photoSchema['items']['type'] ?? null, 'Photo manifest REST meta must define object items.');
assertSameValue('integer', $attachmentSchema['items']['type'] ?? null, 'Attachment REST meta must define integer items.');

(new RecipeMeta())->registerMeta();
assertSameValue('string', $registeredPostMeta[RecipeMeta::META_SERVINGS]['type'] ?? null, 'Recipe yield meta must preserve source text.');
$fetcher = new SourceFetcher();
assertSameValue('private_source_address', $fetcher->validateUrl('http://127.0.0.1/')->code, 'Loopback sources must be rejected.');
assertSameValue('invalid_source_url', $fetcher->validateUrl('file:///etc/passwd')->code, 'Non-HTTP sources must be rejected.');

echo "Browser import PHP tests passed.\n";
