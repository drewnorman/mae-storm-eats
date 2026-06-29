<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class ContentTypes implements ServiceProviderInterface
{
    public const POST_TYPE_RECIPE = 'recipe';
    public const TAXONOMY_CUISINE = 'recipe_cuisine';
    public const TAXONOMY_COURSE = 'recipe_course';
    public const TAXONOMY_DIET = 'recipe_diet';
    public const TAXONOMY_TAG = 'recipe_tag';

    public function register(): void
    {
        add_action('init', [$this, 'registerPostTypes']);
        add_action('init', [$this, 'registerTaxonomies']);
        add_filter('use_block_editor_for_post_type', [$this, 'disableBlockEditor'], 10, 2);
    }

    public function registerPostTypes(): void
    {
        register_post_type(self::POST_TYPE_RECIPE, [
            'labels' => [
                'name' => _x('Recipes', 'post type general name', 'mae-storm-recipes'),
                'singular_name' => _x('Recipe', 'post type singular name', 'mae-storm-recipes'),
                'add_new_item' => __('Add New Recipe', 'mae-storm-recipes'),
                'edit_item' => __('Edit Recipe', 'mae-storm-recipes'),
                'new_item' => __('New Recipe', 'mae-storm-recipes'),
                'view_item' => __('View Recipe', 'mae-storm-recipes'),
                'search_items' => __('Search Recipes', 'mae-storm-recipes'),
                'not_found' => __('No recipes found.', 'mae-storm-recipes'),
                'not_found_in_trash' => __('No recipes found in Trash.', 'mae-storm-recipes'),
                'all_items' => __('All Recipes', 'mae-storm-recipes'),
                'menu_name' => __('Recipes', 'mae-storm-recipes'),
            ],
            'description' => __('Private recipe entries and reviewed imports.', 'mae-storm-recipes'),
            'public' => true,
            'publicly_queryable' => true,
            'exclude_from_search' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'rest_base' => 'recipes',
            'menu_icon' => 'dashicons-carrot',
            'has_archive' => true,
            'rewrite' => ['slug' => 'recipes'],
            'supports' => ['title', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function disableBlockEditor(bool $use_block_editor, string $post_type): bool
    {
        return $post_type === self::POST_TYPE_RECIPE ? false : $use_block_editor;
    }

    public function registerTaxonomies(): void
    {
        $this->registerHierarchicalTaxonomy(
            self::TAXONOMY_CUISINE,
            __('Cuisines', 'mae-storm-recipes'),
            __('Cuisine', 'mae-storm-recipes'),
            'cuisines'
        );

        $this->registerHierarchicalTaxonomy(
            self::TAXONOMY_COURSE,
            __('Courses', 'mae-storm-recipes'),
            __('Course', 'mae-storm-recipes'),
            'courses'
        );

        $this->registerHierarchicalTaxonomy(
            self::TAXONOMY_DIET,
            __('Diets', 'mae-storm-recipes'),
            __('Diet', 'mae-storm-recipes'),
            'diets'
        );

        register_taxonomy(self::TAXONOMY_TAG, [self::POST_TYPE_RECIPE], [
            'labels' => [
                'name' => __('Recipe Tags', 'mae-storm-recipes'),
                'singular_name' => __('Recipe Tag', 'mae-storm-recipes'),
                'search_items' => __('Search Recipe Tags', 'mae-storm-recipes'),
                'popular_items' => __('Popular Recipe Tags', 'mae-storm-recipes'),
                'all_items' => __('All Recipe Tags', 'mae-storm-recipes'),
                'edit_item' => __('Edit Recipe Tag', 'mae-storm-recipes'),
                'update_item' => __('Update Recipe Tag', 'mae-storm-recipes'),
                'add_new_item' => __('Add New Recipe Tag', 'mae-storm-recipes'),
                'new_item_name' => __('New Recipe Tag Name', 'mae-storm-recipes'),
                'menu_name' => __('Tags', 'mae-storm-recipes'),
            ],
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rest_base' => 'recipe-tags',
            'hierarchical' => false,
            'rewrite' => ['slug' => 'recipe-tags'],
        ]);
    }

    private function registerHierarchicalTaxonomy(string $taxonomy, string $plural, string $singular, string $slug): void
    {
        register_taxonomy($taxonomy, [self::POST_TYPE_RECIPE], [
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
                'search_items' => sprintf(__('Search %s', 'mae-storm-recipes'), $plural),
                'all_items' => sprintf(__('All %s', 'mae-storm-recipes'), $plural),
                'parent_item' => sprintf(__('Parent %s', 'mae-storm-recipes'), $singular),
                'parent_item_colon' => sprintf(__('Parent %s:', 'mae-storm-recipes'), $singular),
                'edit_item' => sprintf(__('Edit %s', 'mae-storm-recipes'), $singular),
                'update_item' => sprintf(__('Update %s', 'mae-storm-recipes'), $singular),
                'add_new_item' => sprintf(__('Add New %s', 'mae-storm-recipes'), $singular),
                'new_item_name' => sprintf(__('New %s Name', 'mae-storm-recipes'), $singular),
                'menu_name' => $plural,
            ],
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rest_base' => $slug,
            'hierarchical' => true,
            'rewrite' => ['slug' => $slug],
        ]);
    }
}
