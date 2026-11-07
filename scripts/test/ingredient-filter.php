<?php

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/wp-load.php';

use MaeStorm\Eats\ContentTypes;
use MaeStorm\Eats\IngredientIndex;
use MaeStorm\Eats\RecipeMeta;

function expect_ingredients(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . wp_json_encode($actual));
    }
}

expect_ingredients(
    IngredientIndex::extractNames(['2 c. grated raw summer squash', '6 tbsp. flour', '2 eggs, beaten', '1 (16 oz) sour cream']),
    ['egg', 'flour', 'sour cream', 'summer squash'],
    'Ingredient extraction failed'
);

$post_ids = [];
$course = wp_insert_term('Ingredient filter test course', ContentTypes::TAXONOMY_COURSE);
if (is_wp_error($course)) {
    throw new RuntimeException($course->get_error_message());
}
$occasion = wp_insert_term('Ingredient filter test occasion', ContentTypes::TAXONOMY_OCCASION);
$vibe = wp_insert_term('Ingredient filter test vibe', ContentTypes::TAXONOMY_VIBE);
if (is_wp_error($occasion) || is_wp_error($vibe)) {
    throw new RuntimeException('Could not create filter taxonomy terms.');
}

function recipe_filter_ids(array $post_ids, array $selected): array
{
    $results = new WP_Query([
        'post_type' => ContentTypes::POST_TYPE_RECIPE,
        'post__in' => $post_ids,
        'posts_per_page' => -1,
        'tax_query' => mae_storm_eats_recipe_tax_query($selected),
    ]);
    $ids = array_map('intval', wp_list_pluck($results->posts, 'ID'));
    sort($ids);

    return $ids;
}

try {
    foreach (['Test squash fritters', 'Test cornbread'] as $title) {
        $post_id = wp_insert_post([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);
        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }
        $post_ids[] = (int) $post_id;
    }

    update_post_meta($post_ids[0], RecipeMeta::META_STRUCTURED_DATA, [
        'ingredients' => ['2 eggs, beaten', '6 tbsp. flour'],
    ]);
    update_post_meta($post_ids[1], RecipeMeta::META_STRUCTURED_DATA, [
        'ingredients' => ['4 flax eggs', '1 cup corn meal'],
    ]);
    wp_set_object_terms($post_ids[0], (int) $course['term_id'], ContentTypes::TAXONOMY_COURSE);
    expect_ingredients(
        wp_get_object_terms($post_ids[0], ContentTypes::TAXONOMY_INGREDIENT, ['fields' => 'names']),
        ['egg', 'flour'],
        'Automatic indexing failed'
    );

    update_post_meta($post_ids[0], RecipeMeta::META_INGREDIENT_NAMES_OVERRIDE, ['egg', 'all-purpose flour']);
    update_post_meta($post_ids[0], RecipeMeta::META_STRUCTURED_DATA, [
        'ingredients' => ['2 eggs, beaten', '8 tbsp. flour', '1 tsp. salt'],
    ]);
    expect_ingredients(
        wp_get_object_terms($post_ids[0], ContentTypes::TAXONOMY_INGREDIENT, ['fields' => 'names']),
        ['all-purpose flour', 'egg'],
        'Editor correction was overwritten'
    );

    $index = new IngredientIndex();
    $index->backfill();
    $index->backfill();
    expect_ingredients(
        wp_get_object_terms($post_ids[0], ContentTypes::TAXONOMY_INGREDIENT, ['fields' => 'names']),
        ['all-purpose flour', 'egg'],
        'Repeated backfill changed corrected names'
    );

    delete_post_meta($post_ids[0], RecipeMeta::META_INGREDIENT_NAMES_OVERRIDE);
    expect_ingredients(
        wp_get_object_terms($post_ids[0], ContentTypes::TAXONOMY_INGREDIENT, ['fields' => 'names']),
        ['egg', 'flour', 'salt'],
        'Automatic indexing did not resume'
    );

    $results = new WP_Query([
        'post_type' => ContentTypes::POST_TYPE_RECIPE,
        'post__in' => $post_ids,
        'tax_query' => [
            'relation' => 'AND',
            ['taxonomy' => ContentTypes::TAXONOMY_INGREDIENT, 'field' => 'name', 'terms' => ['flour']],
            ['taxonomy' => ContentTypes::TAXONOMY_COURSE, 'field' => 'term_id', 'terms' => [(int) $course['term_id']]],
        ],
    ]);
    expect_ingredients(array_map('intval', wp_list_pluck($results->posts, 'ID')), [$post_ids[0]], 'Combined filter failed');

    $third_id = wp_insert_post([
        'post_type' => ContentTypes::POST_TYPE_RECIPE,
        'post_status' => 'publish',
        'post_title' => 'Test flour bread',
    ], true);
    if (is_wp_error($third_id)) {
        throw new RuntimeException($third_id->get_error_message());
    }
    $post_ids[] = (int) $third_id;
    update_post_meta($third_id, RecipeMeta::META_STRUCTURED_DATA, [
        'ingredients' => ['2 cups flour'],
    ]);
    wp_set_object_terms($post_ids[0], (int) $occasion['term_id'], ContentTypes::TAXONOMY_OCCASION);
    wp_set_object_terms($post_ids[0], (int) $vibe['term_id'], ContentTypes::TAXONOMY_VIBE);

    $selected = [
        'course' => '',
        'cuisine' => '',
        'occasion' => '',
        'vibe' => '',
        'ingredient' => ['egg', 'flour'],
        'ingredient_mode' => 'any',
    ];
    $expected_any = [$post_ids[0], $post_ids[2]];
    sort($expected_any);
    expect_ingredients(recipe_filter_ids($post_ids, $selected), $expected_any, 'Any ingredient matching failed');

    $selected['ingredient_mode'] = 'all';
    expect_ingredients(recipe_filter_ids($post_ids, $selected), [$post_ids[0]], 'All ingredient matching failed');

    $selected['ingredient_mode'] = 'any';
    $selected['course'] = get_term((int) $course['term_id'])->slug;
    $selected['occasion'] = get_term((int) $occasion['term_id'])->slug;
    $selected['vibe'] = get_term((int) $vibe['term_id'])->slug;
    expect_ingredients(recipe_filter_ids($post_ids, $selected), [$post_ids[0]], 'Combined taxonomy filters failed');

    $selected['occasion'] = 'term-that-does-not-exist';
    expect_ingredients(recipe_filter_ids($post_ids, $selected), [], 'Unknown taxonomy term broadened results');

    $selected['occasion'] = '';
    $selected['vibe'] = '';
    $selected['course'] = '';
    $selected['ingredient'] = ['term-that-does-not-exist'];
    expect_ingredients(recipe_filter_ids($post_ids, $selected), [], 'Unknown ingredient broadened results');

    $_GET['ingredient'] = 'egg';
    unset($_GET['ingredient_mode']);
    $parsed = mae_storm_eats_selected_recipe_filters();
    expect_ingredients($parsed['ingredient'], ['egg'], 'Legacy ingredient link was not recognized');
    expect_ingredients($parsed['ingredient_mode'], 'any', 'Default ingredient mode changed');
    $_GET['ingredient'] = ['egg', 'flour'];
    $_GET['ingredient_mode'] = 'all';
    $parsed = mae_storm_eats_selected_recipe_filters();
    expect_ingredients($parsed['ingredient'], ['egg', 'flour'], 'Multiple ingredient links were not recognized');
    expect_ingredients($parsed['ingredient_mode'], 'all', 'All ingredient mode was not recognized');
} finally {
    unset($_GET['ingredient'], $_GET['ingredient_mode']);
    foreach ($post_ids as $post_id) {
        wp_delete_post($post_id, true);
    }
    wp_delete_term((int) $course['term_id'], ContentTypes::TAXONOMY_COURSE);
    wp_delete_term((int) $occasion['term_id'], ContentTypes::TAXONOMY_OCCASION);
    wp_delete_term((int) $vibe['term_id'], ContentTypes::TAXONOMY_VIBE);
}

echo "Ingredient index integration checks passed.\n";
