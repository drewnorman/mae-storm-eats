<?php

declare(strict_types=1);

use MaeStorm\Recipes\ContentTypes;
use MaeStorm\Recipes\RecipeView;

if (!defined('ABSPATH')) {
  exit;
}

if (!mae_storm_recipes_app_ready()) {
  mae_storm_recipes_render_operational_error();

  return;
}

$context = \Timber\Timber::context();
$context['posts'] = \Timber\Timber::get_posts();
$context['site_name'] = get_bloginfo('name');
$context['recipe_query'] = get_search_query();
$context['recipe_archive_url'] = get_post_type_archive_link(ContentTypes::POST_TYPE_RECIPE) ?: home_url('/');
$context['recipe_filters'] = [
  [
    'label' => __('Course', 'mae-storm-recipes'),
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_COURSE),
  ],
  [
    'label' => __('Cuisine', 'mae-storm-recipes'),
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_CUISINE),
  ],
  [
    'label' => __('Diet', 'mae-storm-recipes'),
    'terms' => RecipeView::terms(ContentTypes::TAXONOMY_DIET),
  ],
];

$templates = ['index.twig'];

if (is_search()) {
  $templates = ['search.twig', 'archive.twig', 'index.twig'];
} elseif (is_singular(ContentTypes::POST_TYPE_RECIPE)) {
  $post_id = get_queried_object_id();
  $context['post'] = \Timber\Timber::get_post();
  $context['recipe_data'] = RecipeView::structuredData($post_id);
  $context['recipe_meta'] = RecipeView::meta($post_id);
  $context['recipe_schema'] = RecipeView::schema($post_id, $context['recipe_data'], $context['recipe_meta']);
  $templates = ['single-recipe.twig', 'index.twig'];
} elseif (is_post_type_archive(ContentTypes::POST_TYPE_RECIPE) || is_tax() || is_archive()) {
  $templates = ['archive.twig', 'index.twig'];
}

if (is_singular(ContentTypes::POST_TYPE_RECIPE) && (isset($_GET['print']) || isset($_GET['share']))) {
  $templates = ['print-share.twig', 'single-recipe.twig', 'index.twig'];
}

\Timber\Timber::render($templates, $context);
