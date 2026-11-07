<?php

declare(strict_types=1);

use MaeStorm\Eats\ContentTypes;
use MaeStorm\Eats\RecipeView;

if (!defined('ABSPATH')) {
  exit;
}

if (!mae_storm_eats_app_ready()) {
  mae_storm_eats_render_operational_error();

  return;
}

$context = \Timber\Timber::context();
$context['posts'] = \Timber\Timber::get_posts();
$context['search_query'] = get_search_query(false);
$context['selected_filters'] = mae_storm_eats_selected_recipe_filters();
$context['recipe_filter_count'] = count($context['selected_filters']['ingredient'])
  + (int) ($context['selected_filters']['course'] !== '')
  + (int) ($context['selected_filters']['cuisine'] !== '')
  + (int) ($context['selected_filters']['occasion'] !== '')
  + (int) ($context['selected_filters']['vibe'] !== '');
$context['recipe_filter_selections_active'] = $context['recipe_filter_count'] > 0 || is_tax();
$context['recipe_filters_active'] = $context['search_query'] !== ''
  || $context['recipe_filter_selections_active'];
$context['recipe_archive_url'] = get_post_type_archive_link(ContentTypes::POST_TYPE_RECIPE) ?: home_url('/');
$raw_sort = $_GET['sort'] ?? '';
$raw_sort = is_string($raw_sort) ? sanitize_key(wp_unslash($raw_sort)) : '';
$context['recipe_sort'] = in_array($raw_sort, ['recent', 'title', 'time', 'difficulty'], true) ? $raw_sort : 'recent';
$context['recipe_sort_names'] = [
  'recent' => 'Recently added',
  'title' => 'Alphabetical A–Z',
  'time' => 'Shortest total time',
  'difficulty' => 'Easiest first',
];
$raw_view = $_GET['view'] ?? '';
$raw_view = is_string($raw_view) ? sanitize_key(wp_unslash($raw_view)) : '';
$context['recipe_view'] = in_array($raw_view, ['card', 'grid', 'list'], true) ? $raw_view : 'card';
$context['recipe_view_names'] = ['card' => 'Card', 'grid' => 'Grid', 'list' => 'List'];
$context['recipe_view_links'] = [];
foreach (['card', 'grid', 'list'] as $view) {
  $context['recipe_view_links'][$view] = add_query_arg('view', $view, get_pagenum_link(1, false));
}
$current_page = max(1, (int) get_query_var('paged'));
$context['recipe_next_page_url'] = $current_page < (int) ($GLOBALS['wp_query']->max_num_pages ?? 1)
  ? get_pagenum_link($current_page + 1, false)
  : '';
$clear_filter_args = ['sort' => $context['recipe_sort'], 'view' => $context['recipe_view']];
if ($context['search_query'] !== '') {
  $clear_filter_args['s'] = $context['search_query'];
}
$context['recipe_clear_filters_url'] = add_query_arg($clear_filter_args, $context['recipe_archive_url']);
$filter_url_args = $clear_filter_args;
foreach (['course', 'cuisine', 'occasion', 'vibe'] as $parameter) {
  if ($context['selected_filters'][$parameter] !== '') {
    $filter_url_args[$parameter] = $context['selected_filters'][$parameter];
  }
}
if ($context['selected_filters']['ingredient'] !== []) {
  $filter_url_args['ingredient'] = $context['selected_filters']['ingredient'];
  $filter_url_args['ingredient_mode'] = $context['selected_filters']['ingredient_mode'];
}
$context['recipe_filter_chips'] = [];
$filter_taxonomies = [
  'course' => [__('Course', 'mae-storm-eats'), ContentTypes::TAXONOMY_COURSE],
  'cuisine' => [__('Cuisine', 'mae-storm-eats'), ContentTypes::TAXONOMY_CUISINE],
  'occasion' => [__('Occasion', 'mae-storm-eats'), ContentTypes::TAXONOMY_OCCASION],
  'vibe' => [__('Vibe', 'mae-storm-eats'), ContentTypes::TAXONOMY_VIBE],
];
foreach ($filter_taxonomies as $parameter => [$label, $taxonomy]) {
  $slug = $context['selected_filters'][$parameter];
  if ($slug === '') {
    continue;
  }
  $term = get_term_by('slug', $slug, $taxonomy);
  $remaining = $filter_url_args;
  unset($remaining[$parameter]);
  $context['recipe_filter_chips'][] = [
    'label' => $label . ': ' . ($term instanceof \WP_Term ? $term->name : $slug),
    'url' => add_query_arg($remaining, $context['recipe_archive_url']),
  ];
}
foreach ($context['selected_filters']['ingredient'] as $slug) {
  $term = get_term_by('slug', $slug, ContentTypes::TAXONOMY_INGREDIENT);
  $remaining = $filter_url_args;
  $remaining['ingredient'] = array_values(array_diff($remaining['ingredient'], [$slug]));
  if ($remaining['ingredient'] === []) {
    unset($remaining['ingredient'], $remaining['ingredient_mode']);
  }
  $context['recipe_filter_chips'][] = [
    'label' => __('Ingredient', 'mae-storm-eats') . ': ' . ($term instanceof \WP_Term ? $term->name : $slug),
    'url' => add_query_arg($remaining, $context['recipe_archive_url']),
  ];
}
$context['recipe_total_results'] = (int) ($GLOBALS['wp_query']->found_posts ?? 0);
$context['recipe_current_page'] = $current_page;
$context['recipe_total_pages'] = (int) ($GLOBALS['wp_query']->max_num_pages ?? 1);
$context['recipe_ajax_url'] = is_post_type_archive(ContentTypes::POST_TYPE_RECIPE) || is_search() || is_home()
  ? rest_url('mae-storm-eats/v1/recipe-list')
  : '';
$context['ingredient_terms'] = RecipeView::terms(ContentTypes::TAXONOMY_INGREDIENT);
$context['recipe_filters'] = [
  [
    'label' => __('Course', 'mae-storm-eats'),
    'parameter' => 'course',
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_COURSE),
  ],
  [
    'label' => __('Cuisine', 'mae-storm-eats'),
    'parameter' => 'cuisine',
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_CUISINE),
  ],
];
$context['more_recipe_filters'] = [
  [
    'label' => __('Occasion', 'mae-storm-eats'),
    'parameter' => 'occasion',
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_OCCASION),
  ],
  [
    'label' => __('Vibe', 'mae-storm-eats'),
    'parameter' => 'vibe',
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_VIBE),
  ],
];

$templates = ['index.twig'];

if (is_search()) {
  $templates = ['search.twig', 'archive.twig', 'index.twig'];
} elseif (is_singular(ContentTypes::POST_TYPE_RECIPE)) {
  $context['post'] = \Timber\Timber::get_post();
  $context['recipe_data'] = RecipeView::structuredData($context['post']);
  $context['recipe_meta'] = RecipeView::meta($context['post']);
  $context['recipe_gallery'] = RecipeView::gallery($context['post']);
  $context['recipe_reviews'] = RecipeView::reviews($context['post']);
  $context['recipe_schema'] = RecipeView::schema($context['post'], $context['recipe_data'], $context['recipe_meta']);
  $context['related_recipes'] = RecipeView::relatedRecipes($context['post']);
  $templates = ['single-recipe.twig', 'index.twig'];
} elseif (is_post_type_archive(ContentTypes::POST_TYPE_RECIPE) || is_tax() || is_archive()) {
  $templates = ['archive.twig', 'index.twig'];
}

if (is_singular(ContentTypes::POST_TYPE_RECIPE) && (isset($_GET['print']) || isset($_GET['share']))) {
  $templates = ['print-share.twig', 'single-recipe.twig', 'index.twig'];
}

\Timber\Timber::render($templates, $context);
