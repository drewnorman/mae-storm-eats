<?php

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/wp-load.php';

use MaeStorm\Eats\ContentTypes;
use MaeStorm\Eats\RecipeMeta;

/** @return array{status:int,body:string,headers:list<string>} */
function listing_request(string $path, string $cookie = '', ?array $post = null): array
{
    $headers = "Host: wordpress.mae-storm-eats.localhost\r\n";
    if ($cookie !== '') {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    if ($post !== null) {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }

    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => $headers,
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents('http://127.0.0.1' . $path, false, $context);
    $response_headers = $http_response_header ?? [];

    if (! is_string($body) || ! preg_match('/^HTTP\/\S+ (\d{3})/', $response_headers[0] ?? '', $match)) {
        throw new RuntimeException("Listing request failed: {$path}");
    }

    return ['status' => (int) $match[1], 'body' => $body, 'headers' => $response_headers];
}

function listing_expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function listing_access_cookie(): string
{
    $password = getenv('MAE_STORM_EATS_SHARED_PASSWORD');
    listing_expect(is_string($password) && $password !== '', 'The local shared password is missing.');
    $page = listing_request('/');
    listing_expect($page['status'] === 401, 'The private frontend was not gated.');
    listing_expect(
        (bool) preg_match('/name="mae_storm_eats_nonce" value="([^"]+)"/', $page['body'], $match),
        'The private frontend login nonce was missing.'
    );
    $login = listing_request('/', '', [
        'mae_storm_eats_password' => $password,
        'mae_storm_eats_nonce' => $match[1],
    ]);
    listing_expect($login['status'] === 302, 'The private frontend login failed.');
    foreach ($login['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*(mae_storm_eats_access=[^;]+)/i', $header, $match)) {
            return $match[1];
        }
    }

    throw new RuntimeException('The private frontend access cookie was missing.');
}

/** @return array{html:string,total:int,page:int,pages:int} */
function listing_data(array $parameters, string $cookie): array
{
    $response = listing_request('/wp-json/mae-storm-eats/v1/recipe-list?' . http_build_query($parameters), $cookie);
    listing_expect($response['status'] === 200, 'The recipe listing endpoint did not return 200: ' . $response['body']);
    $data = json_decode($response['body'], true);
    listing_expect(
        is_array($data)
            && is_string($data['html'] ?? null)
            && is_int($data['total'] ?? null)
            && is_int($data['page'] ?? null)
            && is_int($data['pages'] ?? null),
        'The recipe listing response shape was invalid.'
    );

    return $data;
}

/** @return list<string> */
function listing_titles(string $html): array
{
    preg_match_all('/<h2 class="recipe-card__title">\s*<a[^>]*>(.*?)<\/a>/s', $html, $matches);

    return array_map(
        static fn (string $title): string => html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES, 'UTF-8'),
        $matches[1]
    );
}

$route = '/wp-json/mae-storm-eats/v1/recipe-list';
listing_expect(listing_request($route)['status'] === 401, 'The recipe listing endpoint exposed private data.');
$cookie = listing_access_cookie();
$prefix = 'AjaxFixture2026';
$original_page_size = get_option('posts_per_page');
$terms = [];
$posts = [];

try {
    update_option('posts_per_page', 2);
    foreach ([
        'course' => ContentTypes::TAXONOMY_COURSE,
        'cuisine' => ContentTypes::TAXONOMY_CUISINE,
        'occasion' => ContentTypes::TAXONOMY_OCCASION,
        'vibe' => ContentTypes::TAXONOMY_VIBE,
        'ingredient' => ContentTypes::TAXONOMY_INGREDIENT,
    ] as $type => $taxonomy) {
        $names = match ($type) {
            'course' => ['Breakfast', 'Dessert'],
            'cuisine' => ['American', 'French'],
            'occasion' => ['Brunch'],
            'vibe' => ['Cozy'],
            default => ['Apple', 'Banana', 'Cherry', 'Flour'],
        };
        foreach ($names as $name) {
            $term = wp_insert_term("{$prefix} {$name}", $taxonomy);
            if (is_wp_error($term)) {
                throw new RuntimeException($term->get_error_message());
            }
            $terms[$type][$name] = [
                'id' => (int) $term['term_id'],
                'slug' => (string) get_term($term['term_id'], $taxonomy)->slug,
                'taxonomy' => $taxonomy,
            ];
        }
    }

    $fixtures = [
        ['Apple', 10, 'easy', 'Breakfast', 'American', ['Apple', 'Flour']],
        ['Banana', 20, 'medium', 'Breakfast', 'American', ['Banana', 'Flour']],
        ['Cherry', 5, 'hard', 'Dessert', 'French', ['Cherry']],
        ['Date', 30, 'easy', 'Dessert', 'French', ['Flour']],
        ['Elderberry', null, null, 'Dessert', 'American', ['Apple', 'Banana']],
    ];
    foreach ($fixtures as [$name, $time, $difficulty, $course, $cuisine, $ingredients]) {
        $post = wp_insert_post([
            'post_type' => ContentTypes::POST_TYPE_RECIPE,
            'post_status' => 'publish',
            'post_title' => "{$prefix} {$name}",
        ], true);
        if (is_wp_error($post)) {
            throw new RuntimeException($post->get_error_message());
        }
        $posts[] = (int) $post;
        if ($time !== null) {
            update_post_meta((int) $post, RecipeMeta::META_TOTAL_TIME_MINUTES, $time);
        }
        if ($difficulty !== null) {
            update_post_meta((int) $post, RecipeMeta::META_DIFFICULTY, $difficulty);
        }
        wp_set_object_terms((int) $post, [$terms['course'][$course]['id']], ContentTypes::TAXONOMY_COURSE);
        wp_set_object_terms((int) $post, [$terms['cuisine'][$cuisine]['id']], ContentTypes::TAXONOMY_CUISINE);
        wp_set_object_terms(
            (int) $post,
            array_map(static fn (string $ingredient): int => $terms['ingredient'][$ingredient]['id'], $ingredients),
            ContentTypes::TAXONOMY_INGREDIENT
        );
    }
    foreach ([$posts[0], $posts[3]] as $post) {
        wp_set_object_terms($post, [$terms['occasion']['Brunch']['id']], ContentTypes::TAXONOMY_OCCASION);
    }
    foreach ([$posts[0], $posts[1]] as $post) {
        wp_set_object_terms($post, [$terms['vibe']['Cozy']['id']], ContentTypes::TAXONOMY_VIBE);
    }

    $base = ['s' => $prefix];
    $first = listing_data([...$base, 'sort' => 'time'], $cookie);
    listing_expect($first['total'] === 5 && $first['page'] === 1 && $first['pages'] === 3, 'The first page metadata was wrong.');
    listing_expect(listing_titles($first['html']) === ["{$prefix} Cherry", "{$prefix} Apple"], 'Time sort or first page was wrong.');
    listing_expect(str_contains($first['html'], 'recipe-card') && str_contains($first['html'], 'Added'), 'Server-rendered recipe card details were missing.');
    $page = listing_request('/recipes/?' . http_build_query([...$base, 'sort' => 'time']), $cookie);
    listing_expect($page['status'] === 200, 'The equivalent recipe archive did not load.');
    listing_expect(listing_titles($page['body']) === listing_titles($first['html']), 'REST and archive first-page results differed.');

    $last = listing_data([...$base, 'sort' => 'time', 'page' => 3], $cookie);
    listing_expect($last['total'] === 5 && $last['page'] === 3 && $last['pages'] === 3, 'The last page metadata was wrong.');
    listing_expect(listing_titles($last['html']) === ["{$prefix} Elderberry"], 'The last page was wrong.');

    $breakfast = listing_data([...$base, 'course' => $terms['course']['Breakfast']['slug'], 'sort' => 'title'], $cookie);
    listing_expect($breakfast['total'] === 2 && $breakfast['pages'] === 1, 'Course filtering was wrong.');
    listing_expect(listing_titles($breakfast['html']) === ["{$prefix} Apple", "{$prefix} Banana"], 'Course/title result order was wrong.');

    $combined = listing_data([
        ...$base,
        'course' => $terms['course']['Dessert']['slug'],
        'cuisine' => $terms['cuisine']['French']['slug'],
        'sort' => 'difficulty',
    ], $cookie);
    listing_expect($combined['total'] === 2, 'Combined taxonomy filtering was wrong.');
    listing_expect(listing_titles($combined['html']) === ["{$prefix} Date", "{$prefix} Cherry"], 'Difficulty sorting was wrong.');
    $combined_page = listing_request('/recipes/?' . http_build_query([
        ...$base,
        'course' => $terms['course']['Dessert']['slug'],
        'cuisine' => $terms['cuisine']['French']['slug'],
        'sort' => 'difficulty',
    ]), $cookie);
    listing_expect(
        $combined_page['status'] === 200 && listing_titles($combined_page['body']) === listing_titles($combined['html']),
        'REST and archive combined-filter results differed.'
    );

    $any = listing_data([
        ...$base,
        'ingredient' => [$terms['ingredient']['Apple']['slug'], $terms['ingredient']['Flour']['slug']],
        'ingredient_mode' => 'any',
    ], $cookie);
    listing_expect($any['total'] === 4, 'Any-ingredient filtering was wrong.');
    $all = listing_data([
        ...$base,
        'ingredient' => [$terms['ingredient']['Apple']['slug'], $terms['ingredient']['Flour']['slug']],
        'ingredient_mode' => 'all',
    ], $cookie);
    listing_expect($all['total'] === 1 && listing_titles($all['html']) === ["{$prefix} Apple"], 'All-ingredient filtering was wrong.');

    $more = listing_data([
        ...$base,
        'occasion' => $terms['occasion']['Brunch']['slug'],
        'vibe' => $terms['vibe']['Cozy']['slug'],
    ], $cookie);
    listing_expect($more['total'] === 1 && listing_titles($more['html']) === ["{$prefix} Apple"], 'Occasion/vibe filtering was wrong.');

    $none = listing_data([...$base, 'course' => $terms['course']['Breakfast']['slug'], 'cuisine' => $terms['cuisine']['French']['slug']], $cookie);
    listing_expect($none['total'] === 0 && listing_titles($none['html']) === [], 'The empty filtered state was wrong.');
} finally {
    foreach ($posts as $post) {
        wp_delete_post($post, true);
    }
    foreach ($terms as $group) {
        foreach ($group as $term) {
            wp_delete_term($term['id'], $term['taxonomy']);
        }
    }
    update_option('posts_per_page', $original_page_size);
}

echo "Recipe listing REST integration checks passed.\n";
