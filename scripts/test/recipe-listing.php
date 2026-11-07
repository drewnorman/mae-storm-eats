<?php

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/wp-load.php';

use MaeStorm\Eats\ContentTypes;
use MaeStorm\Eats\RecipeMeta;

function expect_listing(array $actual, array $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . wp_json_encode($actual));
    }
}

$recipes = [
    ['title' => 'Z Missing', 'time' => null, 'difficulty' => null],
    ['title' => 'B Thirty', 'time' => 30, 'difficulty' => 'easy'],
    ['title' => 'C Ten', 'time' => 10, 'difficulty' => 'hard'],
    ['title' => 'D Five', 'time' => 5, 'difficulty' => 'medium'],
    ['title' => 'A Zero', 'time' => 0, 'difficulty' => ''],
];
$ids = [];

try {
    foreach ($recipes as $recipe) {
        $id = wp_insert_post([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'publish',
            'post_title' => $recipe['title'],
        ], true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $ids[] = (int) $id;
        if ($recipe['time'] !== null) {
            update_post_meta((int) $id, RecipeMeta::META_TOTAL_TIME_MINUTES, $recipe['time']);
        }
        if ($recipe['difficulty'] !== null) {
            update_post_meta((int) $id, RecipeMeta::META_DIFFICULTY, $recipe['difficulty']);
        }
    }

    $query = static function (string $sort, int $page = 1, int $per_page = 10) use (&$ids): \WP_Query {
        return new \WP_Query([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'publish',
            'post__in' => $ids,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'mae_recipe_sort' => $sort,
        ]);
    };
    $get_ids = static fn (\WP_Query $results): array => array_map('intval', wp_list_pluck($results->posts, 'ID'));

    expect_listing($get_ids($query('time')), [$ids[3], $ids[2], $ids[1], $ids[4], $ids[0]], 'Time sort lost or misplaced missing values');
    expect_listing($get_ids($query('difficulty')), [$ids[1], $ids[3], $ids[2], $ids[4], $ids[0]], 'Difficulty sort lost or misplaced missing values');

    $first = $query('time', 1, 2);
    $second = $query('time', 2, 2);
    $third = $query('time', 3, 2);
    expect_listing($get_ids($first), [$ids[3], $ids[2]], 'First page order was wrong');
    expect_listing($get_ids($second), [$ids[1], $ids[4]], 'Second page order was wrong');
    expect_listing($get_ids($third), [$ids[0]], 'Last page order was wrong');
    expect_listing([$first->max_num_pages, $second->max_num_pages, $third->max_num_pages], [3, 3, 3], 'Pagination count was wrong');
} finally {
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
}

echo "Recipe listing integration checks passed.\n";
