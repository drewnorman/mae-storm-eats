<?php

declare(strict_types=1);

use MaeStorm\Eats\ContentTypes;
use MaeStorm\Eats\RecipeView;

if (!defined('ABSPATH')) {
  exit;
}

define('MAE_STORM_EATS_THEME_VERSION', '0.1.0');
define('MAE_STORM_EATS_THEME_DIR', get_template_directory());
define('MAE_STORM_EATS_THEME_URI', get_template_directory_uri());
define('MAE_STORM_EATS_GOOGLE_FONTS_URL', 'https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@400;700&family=Montserrat:wght@400&display=swap');

add_action('after_setup_theme', function (): void {
  add_theme_support('title-tag');
  add_theme_support('post-thumbnails');
  add_theme_support('html5', [
    'caption',
    'gallery',
    'navigation-widgets',
    'script',
    'search-form',
    'style',
  ]);

  register_nav_menus([
    'primary' => __('Primary navigation', 'mae-storm-eats'),
  ]);
});

add_action('wp_enqueue_scripts', function (): void {
  $css_path = MAE_STORM_EATS_THEME_DIR . '/assets/dist/app.css';
  $js_path = MAE_STORM_EATS_THEME_DIR . '/assets/dist/app.js';

  wp_enqueue_style(
    'mae-storm-eats-fonts',
    MAE_STORM_EATS_GOOGLE_FONTS_URL,
    [],
    null,
  );

  if (file_exists($css_path)) {
    wp_enqueue_style(
      'mae-storm-eats-app',
      MAE_STORM_EATS_THEME_URI . '/assets/dist/app.css',
      ['mae-storm-eats-fonts'],
      (string) filemtime($css_path),
    );
  }

  if (file_exists($js_path)) {
    wp_enqueue_script(
      'mae-storm-eats-app',
      MAE_STORM_EATS_THEME_URI . '/assets/dist/app.js',
      [],
      (string) filemtime($js_path),
      true,
    );
  }
});

add_filter('wp_resource_hints', function (array $urls, string $relation_type): array {
  if ('preconnect' !== $relation_type) {
    return $urls;
  }

  $urls[] = 'https://fonts.googleapis.com';
  $urls[] = [
    'href' => 'https://fonts.gstatic.com',
    'crossorigin',
  ];

  return $urls;
}, 10, 2);

add_filter('timber/locations', function (array $paths): array {
  $paths[\Timber\Loader::MAIN_NAMESPACE][] = MAE_STORM_EATS_THEME_DIR . '/views';
  $paths[\Timber\Loader::MAIN_NAMESPACE][] = MAE_STORM_EATS_THEME_DIR . '/views/layouts';

  return $paths;
});

function mae_storm_eats_timber_available(): bool
{
  return class_exists(\Timber\Timber::class);
}

function mae_storm_eats_app_ready(): bool
{
  return mae_storm_eats_timber_available() && class_exists(RecipeView::class);
}

function mae_storm_eats_render_operational_error(): void
{
  get_header();
  ?>
  <main class="mae-storm-eats-fallback">
    <h1><?php esc_html_e('Mae Storm Eats is not ready', 'mae-storm-eats'); ?></h1>
    <p>
      <?php esc_html_e('This app requires the Mae Storm Eats plugin and Timber. Install Composer dependencies and activate the plugin before rendering recipe views.', 'mae-storm-eats'); ?>
    </p>
  </main>
  <?php
  get_footer();
}

/**
 * @return array{course:string,cuisine:string,occasion:string,vibe:string,ingredient:list<string>,ingredient_mode:string}
 */
function mae_storm_eats_selected_recipe_filters(): array
{
  $selected = [];
  foreach (['course', 'cuisine', 'occasion', 'vibe'] as $parameter) {
    $raw = $_GET[$parameter] ?? '';
    $selected[$parameter] = is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';
  }

  $raw_ingredients = $_GET['ingredient'] ?? [];
  $legacy_ingredient = is_string($raw_ingredients);
  $ingredients = is_array($raw_ingredients) ? $raw_ingredients : [$raw_ingredients];
  $selected['ingredient'] = [];
  foreach ($ingredients as $raw) {
    if (!is_string($raw)) {
      continue;
    }

    $value = sanitize_text_field(wp_unslash($raw));
    if ($value === '') {
      continue;
    }

    if ($legacy_ingredient) {
      $term = get_term_by('slug', $value, ContentTypes::TAXONOMY_INGREDIENT);
      if (!$term instanceof \WP_Term) {
        $term = get_term_by('name', $value, ContentTypes::TAXONOMY_INGREDIENT);
      }
      $value = $term instanceof \WP_Term ? $term->slug : sanitize_title($value);
    }

    $selected['ingredient'][] = sanitize_title($value);
  }
  $selected['ingredient'] = array_values(array_unique($selected['ingredient']));

  $raw_mode = $_GET['ingredient_mode'] ?? 'any';
  $selected['ingredient_mode'] = $raw_mode === 'all' ? 'all' : 'any';

  return $selected;
}

/**
 * @param array{course:string,cuisine:string,occasion:string,vibe:string,ingredient:list<string>,ingredient_mode:string} $selected
 * @param array<mixed> $existing
 * @return array<mixed>
 */
function mae_storm_eats_recipe_tax_query(array $selected, array $existing = []): array
{
  $filters = [
    'course' => ContentTypes::TAXONOMY_COURSE,
    'cuisine' => ContentTypes::TAXONOMY_CUISINE,
    'occasion' => ContentTypes::TAXONOMY_OCCASION,
    'vibe' => ContentTypes::TAXONOMY_VIBE,
  ];
  foreach ($filters as $parameter => $taxonomy) {
    if ($selected[$parameter] === '') {
      continue;
    }

    $term = get_term_by('slug', $selected[$parameter], $taxonomy);
    $existing[] = [
      'taxonomy' => $taxonomy,
      'field' => 'term_id',
      'terms' => $term instanceof \WP_Term ? [(int) $term->term_id] : [0],
    ];
  }

  if ($selected['ingredient'] !== []) {
    $ingredient_ids = array_map(static function (string $slug): int {
      $term = get_term_by('slug', $slug, ContentTypes::TAXONOMY_INGREDIENT);

      return $term instanceof \WP_Term ? (int) $term->term_id : 0;
    }, $selected['ingredient']);

    if ($selected['ingredient_mode'] === 'all') {
      foreach ($ingredient_ids as $id) {
        $existing[] = [
          'taxonomy' => ContentTypes::TAXONOMY_INGREDIENT,
          'field' => 'term_id',
          'terms' => [$id],
        ];
      }
    } else {
      $existing[] = [
        'taxonomy' => ContentTypes::TAXONOMY_INGREDIENT,
        'field' => 'term_id',
        'terms' => $ingredient_ids,
      ];
    }
  }

  return $existing === [] ? [] : ['relation' => 'AND', ...$existing];
}

add_action('pre_get_posts', function (\WP_Query $query): void {
  if (is_admin() || !$query->is_main_query()) {
    return;
  }

  if (!class_exists(ContentTypes::class)) {
    if ($query->is_home() || $query->is_search()) {
      $query->set('post_type', ['recipe']);
    }
    return;
  }

  $recipe_taxonomies = [
    ContentTypes::TAXONOMY_COURSE,
    ContentTypes::TAXONOMY_CUISINE,
    ContentTypes::TAXONOMY_OCCASION,
    ContentTypes::TAXONOMY_VIBE,
    ContentTypes::TAXONOMY_INGREDIENT,
    ContentTypes::TAXONOMY_TAG,
  ];
  if ($query->is_home() || $query->is_search() || $query->is_post_type_archive(ContentTypes::POST_TYPE_RECIPE) || $query->is_tax($recipe_taxonomies)) {
    $query->set('post_type', [ContentTypes::POST_TYPE_RECIPE]);
    $sort = $_GET['sort'] ?? '';
    $sort = is_string($sort) ? sanitize_key(wp_unslash($sort)) : '';
    $sort = in_array($sort, ['recent', 'title', 'time', 'difficulty'], true) ? $sort : 'recent';
    $query->set('mae_recipe_sort', $sort);
    if ($sort === 'recent') {
      $query->set('orderby', ['date' => 'DESC', 'ID' => 'DESC']);
    } elseif ($sort === 'title') {
      $query->set('orderby', ['title' => 'ASC', 'ID' => 'DESC']);
    }
    $tax_query = $query->get('tax_query');
    $tax_query = is_array($tax_query) ? $tax_query : [];
    $tax_query = mae_storm_eats_recipe_tax_query(mae_storm_eats_selected_recipe_filters(), $tax_query);
    if ($tax_query !== []) {
      $query->set('tax_query', $tax_query);
    }
  }
});

add_filter('posts_orderby', function (string $orderby, \WP_Query $query): string {
  if (is_admin()) {
    return $orderby;
  }

  $sort = $query->get('mae_recipe_sort');
  if ($sort !== 'time' && $sort !== 'difficulty') {
    return $orderby;
  }

  global $wpdb;
  $meta_key = $sort === 'time'
    ? \MaeStorm\Eats\RecipeMeta::META_TOTAL_TIME_MINUTES
    : \MaeStorm\Eats\RecipeMeta::META_DIFFICULTY;
  $value = $wpdb->prepare(
    "(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = %s ORDER BY pm.meta_id DESC LIMIT 1)",
    $meta_key,
  );

  if ($sort === 'time') {
    $minutes = "CAST({$value} AS UNSIGNED)";
    return "CASE WHEN {$minutes} > 0 THEN 0 ELSE 1 END ASC, {$minutes} ASC, {$wpdb->posts}.post_title ASC, {$wpdb->posts}.ID DESC";
  }

  $difficulty = "LOWER(TRIM({$value}))";
  return "CASE {$difficulty} WHEN 'easy' THEN 0 WHEN 'medium' THEN 1 WHEN 'hard' THEN 2 ELSE 3 END ASC, {$wpdb->posts}.post_title ASC, {$wpdb->posts}.ID DESC";
}, 10, 2);
