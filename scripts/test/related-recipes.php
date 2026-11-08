<?php

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/wp-load.php';

use MaeStorm\Eats\ContentTypes;
use MaeStorm\Eats\RecipeView;
use MaeStorm\Eats\RelatedRecipes;
use Timber\Timber;

function expect_related_view(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . wp_json_encode($expected) . ', got ' . wp_json_encode($actual));
    }
}

$created_ids = [];
$term_id = 0;
$suffix = wp_generate_uuid4();

$create = static function (string $label, int $minutes_ago, string $status = 'publish') use (&$created_ids, $suffix): int {
    $id = wp_insert_post([
        'post_type' => ContentTypes::POST_TYPE_RECIPE,
        'post_status' => $status,
        'post_title' => $label . ' ' . $suffix,
        'post_date' => wp_date('Y-m-d H:i:s', time() - $minutes_ago * MINUTE_IN_SECONDS),
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $created_ids[] = (int) $id;
    return (int) $id;
};

try {
    $term = wp_insert_term('Related fixture ' . $suffix, ContentTypes::TAXONOMY_COURSE);
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    $term_id = (int) $term['term_id'];

    $source = $create('Source', 20);
    $auto_a = $create('Automatic A', 1);
    $auto_b = $create('Automatic B', 2);
    $manual_a = $create('Manual A', 10);
    $manual_b = $create('Manual B', 11);
    $manual_c = $create('Manual C', 12);
    $manual_d = $create('Manual D', 13);
    $draft = $create('Draft pick', 14, 'draft');
    $fallback = $create('Newest fallback', 0);

    foreach ([$source, $auto_a, $auto_b] as $id) {
        wp_set_post_terms($id, [$term_id], ContentTypes::TAXONOMY_COURSE);
    }

    $recipe = Timber::get_post($source);
    $get_ids = static fn (): array => array_map(
        static fn ($post): int => $post->ID,
        RecipeView::relatedRecipes($recipe)
    );

    expect_related_view([$auto_a, $auto_b, $fallback], $get_ids(), 'Automatic matches should lead before the newest fallback');

    update_post_meta($source, RelatedRecipes::META_KEY, [$manual_a]);
    expect_related_view([$manual_a, $auto_a, $auto_b], $get_ids(), 'One manual pick should leave two automatic slots');

    update_post_meta($source, RelatedRecipes::META_KEY, [$manual_b, $manual_a]);
    expect_related_view([$manual_b, $manual_a, $auto_a], $get_ids(), 'Manual order should lead before one automatic match');

    update_post_meta($source, RelatedRecipes::META_KEY, [$manual_c, $manual_b, $manual_a]);
    expect_related_view([$manual_c, $manual_b, $manual_a], $get_ids(), 'Three picks should use all baseline slots');

    update_post_meta($source, RelatedRecipes::META_KEY, [$manual_d, $manual_c, $manual_b, $manual_a]);
    expect_related_view([$manual_d, $manual_c, $manual_b, $manual_a], $get_ids(), 'More than three picks should expand the section');

    update_post_meta($source, RelatedRecipes::META_KEY, [$manual_a, $draft]);
    expect_related_view([$manual_a, $auto_a, $auto_b], $get_ids(), 'An unpublished pick should be skipped and its slot filled');
    expect_related_view([$manual_a, $draft], RelatedRecipes::selectedIds($source), 'An unpublished pick should remain saved');

    update_post_meta($source, RelatedRecipes::META_KEY, [$auto_a]);
    expect_related_view([$auto_a, $auto_b, $fallback], $get_ids(), 'A picked taxonomy match should not repeat automatically');
} finally {
    foreach ($created_ids as $id) {
        wp_delete_post($id, true);
    }
    if ($term_id > 0) {
        wp_delete_term($term_id, ContentTypes::TAXONOMY_COURSE);
    }
}

echo "Related recipe integration checks passed.\n";
