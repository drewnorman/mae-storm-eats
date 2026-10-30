<?php

declare(strict_types=1);

use MaeStorm\Eats\RecipeJsonLd;
use MaeStorm\Eats\RecipeImages;
use MaeStorm\Eats\RecipeImporter;
use MaeStorm\Eats\BrowserImport;
use MaeStorm\Eats\ReviewReconciler;
use MaeStorm\Eats\ReviewMeta;
use MaeStorm\Eats\RecipeMeta;
use MaeStorm\Eats\SourceFetcher;
use MaeStorm\Eats\PinImportStore;

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
    unset($return_error);
    $id = ($post['post_type'] ?? '') === 'pinterest_import' ? ($GLOBALS['nextPinImportId'] ?? 84) : 42;
    if (($post['post_type'] ?? '') === 'pinterest_import') {
        $GLOBALS['nextPinImportId'] = $id + 1;
    }
    $GLOBALS['insertedPosts'][$id] = $post;
    return $id;
}

function update_post_meta(int $post_id, string $key, mixed $value): bool
{
    $GLOBALS['updatedPostMeta'][$post_id][$key] = $value;
    return true;
}

function get_post_meta(int $post_id, string $key, bool $single = false): mixed
{
    unset($single);
    return $GLOBALS['updatedPostMeta'][$post_id][$key] ?? '';
}

/** @param array<string,mixed> $args @return list<int> */
function get_posts(array $args): array
{
    if (($args['post_type'] ?? '') !== 'pinterest_import') {
        return [];
    }
    $matches = static function (int $id, array $clause): bool {
        return (string) get_post_meta($id, (string) ($clause['key'] ?? ''), true) === (string) ($clause['value'] ?? '');
    };
    $ids = [];
    foreach ($GLOBALS['insertedPosts'] ?? [] as $id => $post) {
        if (($post['post_type'] ?? '') !== 'pinterest_import') {
            continue;
        }
        if (isset($args['meta_key']) && ! $matches($id, ['key' => $args['meta_key'], 'value' => $args['meta_value'] ?? ''])) {
            continue;
        }
        foreach ((array) ($args['meta_query'] ?? []) as $clause) {
            if (is_array($clause) && ! $matches($id, $clause)) {
                continue 2;
            }
        }
        $ids[] = $id;
    }
    $limit = (int) ($args['posts_per_page'] ?? -1);
    return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
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

function rest_get_url_prefix(): string
{
    return 'wp-json';
}

final class WP_Error
{
    public function __construct(public string $code, public string $message = '')
    {
    }
}

final class WP_REST_Request
{
    public function __construct(private array $params = [])
    {
    }

    public function get_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }
}

final class WP_REST_Response
{
    public function __construct(public array $data)
    {
    }
}

function sanitize_key(string $value): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value));
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

require_once __DIR__ . '/../plugins/mae-storm-eats/src/RecipeJsonLd.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/RecipeImages.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/RecipeImporter.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/ReviewReconciler.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/ServiceProviderInterface.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/ContentTypes.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/RecipeMeta.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/BrowserImport.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/ReviewMeta.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/SourceFetcher.php';
require_once __DIR__ . '/../plugins/mae-storm-eats/src/PinImportStore.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$browserImport = new BrowserImport();
$_SERVER['REQUEST_URI'] = '/wp-json/mae-storm-eats/v1/pinterest-imports/status';
assertSameValue(true, $browserImport->allowApplicationPassword(false), 'Pretty import REST routes must allow Application Password authentication.');
$_SERVER['REQUEST_URI'] = '/blog/wp-json/mae-storm-eats/v1/pinterest-imports/123/resolve';
assertSameValue(true, $browserImport->allowApplicationPassword(false), 'Import REST routes under a subdirectory must allow Application Password authentication.');
$_SERVER['REQUEST_URI'] = '/wp-json/mae-storm-eats/v1/pinterest-imports-elsewhere';
assertSameValue(false, $browserImport->allowApplicationPassword(false), 'Similar route names must not enable Application Password authentication.');
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';
assertSameValue(false, $browserImport->allowApplicationPassword(false), 'Unrelated REST routes must not enable Application Password authentication.');
$_GET['rest_route'] = '/mae-storm-eats/v1/pinterest-imports';
assertSameValue(true, $browserImport->allowApplicationPassword(false), 'Query-parameter import REST routes must allow Application Password authentication.');
assertSameValue(true, $browserImport->allowApplicationPassword(true), 'Prior API request classification must be preserved.');
unset($_GET['rest_route'], $_SERVER['REQUEST_URI']);

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

$pinStore = new PinImportStore();
$pinImport = $pinStore->capture([
    'pin_id' => '123',
    'pin_url' => 'https://www.pinterest.com/pin/123/',
    'board_url' => 'https://www.pinterest.com/mae/recipes/',
], $owner);
assertSameValue(84, $pinImport, 'Pin capture must create a durable inventory record.');
$pinStore->capture([
    'pin_id' => '123',
    'pin_url' => 'https://www.pinterest.com/pin/123/',
    'board_url' => 'https://www.pinterest.com/mae/favorites/',
], $owner);
assertSameValue(2, count($updatedPostMeta[84][PinImportStore::META_BOARD_URLS] ?? []), 'Recapture must merge board provenance.');
assertSameValue(1, $pinStore->counts()['recipe']['pending'], 'Captured pins must enter the recipe queue.');
assertSameValue(0, $pinStore->counts()['reviews']['pending'], 'Reviews must not be eligible before recipe completion.');
$pinStore->completeRecipe('123', 42, 'https://example.com/soup');
assertSameValue(1, $pinStore->counts()['recipe']['complete'], 'Recipe completion must update its independent stage.');
assertSameValue(1, $pinStore->counts()['reviews']['pending'], 'Recipe completion must make reviews eligible.');
$pinStore->fail('123', 'reviews', 'Try later');
assertSameValue('123', $pinStore->queue('reviews', 'retry')[0]['pin_id'] ?? '', 'Failed reviews must be available only from the retry queue.');

for ($pinId = 200; $pinId < 226; $pinId++) {
    $pinStore->capture([
        'pin_id' => (string) $pinId,
        'pin_url' => 'https://www.pinterest.com/pin/' . $pinId . '/',
    ], $owner);
}
assertSameValue(25, count($pinStore->queue('recipe', 'pending')), 'The default recipe queue must contain up to 25 pins.');
assertSameValue(25, count($pinStore->queue('recipe', 'pending', 100)), 'Recipe queue requests must be capped at 25 pins.');
assertSameValue(7, count($pinStore->queue('recipe', 'pending', 7)), 'Smaller recipe queue requests must still work.');
$recipeQueue = $browserImport->queue(new WP_REST_Request(['stage' => 'recipe', 'mode' => 'pending']));
assertSameValue(25, count($recipeQueue->data['pins']), 'The recipe REST queue must return 25 pins.');

for ($pinId = 200; $pinId < 226; $pinId++) {
    $pinStore->completeRecipe((string) $pinId, 42, 'https://example.com/soup');
}
assertSameValue(25, count($pinStore->queue('reviews', 'pending')), 'The default review queue must contain up to 25 pins.');
$reviewQueue = $browserImport->queue(new WP_REST_Request(['stage' => 'reviews', 'mode' => 'pending']));
assertSameValue(25, count($reviewQueue->data['pins']), 'The review REST queue must return 25 pins.');

$reviewMeta = new ReviewMeta();
$reviewMeta->registerMeta();
$photoSchema = $registeredPostMeta[ReviewMeta::META_PHOTO_MANIFEST]['show_in_rest']['schema'] ?? [];
$attachmentSchema = $registeredPostMeta[ReviewMeta::META_ATTACHMENTS]['show_in_rest']['schema'] ?? [];
assertSameValue('object', $photoSchema['items']['type'] ?? null, 'Photo manifest REST meta must define object items.');
assertSameValue('integer', $attachmentSchema['items']['type'] ?? null, 'Attachment REST meta must define integer items.');

(new RecipeMeta())->registerMeta();
assertSameValue('string', $registeredPostMeta[RecipeMeta::META_SERVINGS]['type'] ?? null, 'Recipe yield meta must preserve source text.');
assertSameValue('string', $registeredPostMeta[RecipeMeta::META_PINTEREST_SYNC_STATUS]['type'] ?? null, 'Pinterest sync status must be stored separately from editorial status.');
assertSameValue('string', $registeredPostMeta[RecipeMeta::META_PINTEREST_SYNCED_AT]['type'] ?? null, 'The last successful Pinterest sync timestamp must be registered.');

$fetcher = new SourceFetcher();
assertSameValue('private_source_address', $fetcher->validateUrl('http://127.0.0.1/')->code, 'Loopback sources must be rejected.');
assertSameValue('invalid_source_url', $fetcher->validateUrl('file:///etc/passwd')->code, 'Non-HTTP sources must be rejected.');

echo "Browser import PHP tests passed.\n";
