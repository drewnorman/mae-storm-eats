<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

if (!mae_storm_recipes_timber_available()) {
  get_header();
  ?>
  <main class="mae-storm-recipes-fallback">
    <h1><?php esc_html_e('Mae Storm Recipes', 'mae-storm-recipes'); ?></h1>
    <p>
      <?php esc_html_e('The theme is active. Install and activate Timber to render Twig templates.', 'mae-storm-recipes'); ?>
    </p>
  </main>
  <?php
  get_footer();
  return;
}

$context = \Timber\Timber::context();
$context['posts'] = \Timber\Timber::get_posts();
$context['site_name'] = get_bloginfo('name');
$context['recipe_query'] = get_search_query();

$templates = ['index.twig'];

if (is_search()) {
  $templates = ['search.twig', 'archive.twig', 'index.twig'];
} elseif (is_singular('recipe')) {
  $context['post'] = \Timber\Timber::get_post();
  $templates = ['single-recipe.twig', 'index.twig'];
} elseif (is_post_type_archive('recipe') || is_tax() || is_archive()) {
  $templates = ['archive.twig', 'index.twig'];
}

if (is_singular('recipe') && (isset($_GET['print']) || isset($_GET['share']))) {
  $templates = ['print-share.twig', 'single-recipe.twig', 'index.twig'];
}

\Timber\Timber::render($templates, $context);
