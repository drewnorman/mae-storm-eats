<?php

declare(strict_types=1);

use MaeStorm\Eats\RelatedRecipes;

final class WP_Post
{
    public function __construct(public string $post_type = 'recipe')
    {
    }
}

$posts = [42 => 'recipe', 10 => 'recipe', 11 => 'recipe', 12 => 'recipe', 13 => 'post'];
$meta = [];
$can_edit = true;
$valid_nonce = true;
$revision = false;
$autosave = false;

function get_post_type(int $id): ?string
{
    return $GLOBALS['posts'][$id] ?? null;
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
    return $GLOBALS['valid_nonce'] && $nonce === $action;
}

function current_user_can(string $capability, int $id): bool
{
    return $capability === 'edit_post' && $id === 42 && $GLOBALS['can_edit'];
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
require_once __DIR__ . '/../plugins/mae-storm-eats/src/RelatedRecipes.php';

function expect_related(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

expect_related([11, 10, 12], RelatedRecipes::normalizeIds([0, '11', 42, 10, '11', 13, 99, 'bad', 12], 42), 'Only other unique recipes remain in editor order.');

$related = new RelatedRecipes();
$_POST = [
    'mae_related_recipes_nonce' => 'mae_related_recipes_42',
    'mae_related_recipe_ids' => '12,10,12,42,13,99,11',
];
$related->save(42, new WP_Post());
expect_related([12, 10, 11], RelatedRecipes::selectedIds(42), 'Valid recipe choices save in order.');

$can_edit = false;
$_POST['mae_related_recipe_ids'] = '11';
$related->save(42, new WP_Post());
expect_related([12, 10, 11], RelatedRecipes::selectedIds(42), 'Unauthorized saves cannot change selections.');
$can_edit = true;
$valid_nonce = false;
$related->save(42, new WP_Post());
expect_related([12, 10, 11], RelatedRecipes::selectedIds(42), 'Invalid nonce cannot change selections.');
$valid_nonce = true;
$revision = true;
$related->save(42, new WP_Post());
expect_related([12, 10, 11], RelatedRecipes::selectedIds(42), 'Revision saves cannot change selections.');
$revision = false;
$autosave = true;
$related->save(42, new WP_Post());
expect_related([12, 10, 11], RelatedRecipes::selectedIds(42), 'Autosaves cannot change selections.');
$autosave = false;
$related->save(42, new WP_Post('post'));
expect_related([12, 10, 11], RelatedRecipes::selectedIds(42), 'Other post types cannot change selections.');

$meta[42][RelatedRecipes::META_KEY] = [12, 10, 11];
unset($posts[10]);
expect_related([12, 11], RelatedRecipes::selectedIds(42), 'Deleted recipes are omitted on read.');
$_POST['mae_related_recipe_ids'] = '';
$related->save(42, new WP_Post());
expect_related([], RelatedRecipes::selectedIds(42), 'Editors can clear all selections.');

echo "Related recipe editor checks passed.\n";
