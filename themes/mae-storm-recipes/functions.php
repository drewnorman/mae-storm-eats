<?php

declare(strict_types=1);

use MaeStorm\Recipes\ContentTypes;
use MaeStorm\Recipes\RecipeView;

if (!defined('ABSPATH')) {
  exit;
}

define('MAE_STORM_RECIPES_THEME_VERSION', '0.1.0');
define('MAE_STORM_RECIPES_THEME_DIR', get_template_directory());
define('MAE_STORM_RECIPES_THEME_URI', get_template_directory_uri());

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
    'primary' => __('Primary navigation', 'mae-storm-recipes'),
  ]);
});

add_action('wp_enqueue_scripts', function (): void {
  $css_path = MAE_STORM_RECIPES_THEME_DIR . '/assets/dist/app.css';
  $js_path = MAE_STORM_RECIPES_THEME_DIR . '/assets/dist/app.js';

  if (file_exists($css_path)) {
    wp_enqueue_style(
      'mae-storm-recipes-app',
      MAE_STORM_RECIPES_THEME_URI . '/assets/dist/app.css',
      [],
      (string) filemtime($css_path),
    );
  }

  if (file_exists($js_path)) {
    wp_enqueue_script(
      'mae-storm-recipes-app',
      MAE_STORM_RECIPES_THEME_URI . '/assets/dist/app.js',
      [],
      (string) filemtime($js_path),
      true,
    );
  }
});

add_filter('timber/locations', function (array $paths): array {
  $paths[\Timber\Loader::MAIN_NAMESPACE][] = MAE_STORM_RECIPES_THEME_DIR . '/views';
  $paths[\Timber\Loader::MAIN_NAMESPACE][] = MAE_STORM_RECIPES_THEME_DIR . '/views/layouts';

  return $paths;
});

function mae_storm_recipes_timber_available(): bool
{
  return class_exists(\Timber\Timber::class);
}

function mae_storm_recipes_app_ready(): bool
{
  return mae_storm_recipes_timber_available() && class_exists(RecipeView::class);
}

function mae_storm_recipes_render_operational_error(): void
{
  get_header();
  ?>
  <main class="mae-storm-recipes-fallback">
    <h1><?php esc_html_e('Mae Storm Recipes is not ready', 'mae-storm-recipes'); ?></h1>
    <p>
      <?php esc_html_e('This app requires the Mae Storm Recipes plugin and Timber. Install Composer dependencies and activate the plugin before rendering recipe views.', 'mae-storm-recipes'); ?>
    </p>
  </main>
  <?php
  get_footer();
}

add_action('pre_get_posts', function (\WP_Query $query): void {
  if (is_admin() || !$query->is_main_query()) {
    return;
  }

  if ($query->is_home() || $query->is_search()) {
    $query->set('post_type', [class_exists(ContentTypes::class) ? ContentTypes::POST_TYPE_RECIPE : 'recipe']);
  }
});
