<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class ContentTypes implements ServiceProviderInterface
{
    public const POST_TYPE_RECIPE = 'recipe';
    public const POST_TYPE_REVIEW = 'recipe_review';
    public const POST_TYPE_PIN_IMPORT = 'pinterest_import';
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
                'name' => _x('Recipes', 'post type general name', 'mae-storm-eats'),
                'singular_name' => _x('Recipe', 'post type singular name', 'mae-storm-eats'),
                'add_new_item' => __('Add New Recipe', 'mae-storm-eats'),
                'edit_item' => __('Edit Recipe', 'mae-storm-eats'),
                'new_item' => __('New Recipe', 'mae-storm-eats'),
                'view_item' => __('View Recipe', 'mae-storm-eats'),
                'search_items' => __('Search Recipes', 'mae-storm-eats'),
                'not_found' => __('No recipes found.', 'mae-storm-eats'),
                'not_found_in_trash' => __('No recipes found in Trash.', 'mae-storm-eats'),
                'all_items' => __('All Recipes', 'mae-storm-eats'),
                'menu_name' => __('Recipes', 'mae-storm-eats'),
            ],
            'description' => __('Private recipe entries and reviewed imports.', 'mae-storm-eats'),
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

        register_post_type(self::POST_TYPE_REVIEW, [
            'labels' => [
                'name' => _x('Recipe Reviews', 'post type general name', 'mae-storm-eats'),
                'singular_name' => _x('Recipe Review', 'post type singular name', 'mae-storm-eats'),
                'add_new_item' => __('Add New Recipe Review', 'mae-storm-eats'),
                'edit_item' => __('Edit Recipe Review', 'mae-storm-eats'),
                'new_item' => __('New Recipe Review', 'mae-storm-eats'),
                'view_item' => __('View Recipe Review', 'mae-storm-eats'),
                'search_items' => __('Search Recipe Reviews', 'mae-storm-eats'),
                'not_found' => __('No recipe reviews found.', 'mae-storm-eats'),
                'not_found_in_trash' => __('No recipe reviews found in Trash.', 'mae-storm-eats'),
                'all_items' => __('Recipe Reviews', 'mae-storm-eats'),
                'menu_name' => __('Reviews', 'mae-storm-eats'),
            ],
            'description' => __('Imported Pinterest comments and reviews linked to recipes.', 'mae-storm-eats'),
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=' . self::POST_TYPE_RECIPE,
            'show_in_rest' => true,
            'rest_base' => 'recipe-reviews',
            'supports' => ['title', 'editor', 'revisions', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::POST_TYPE_PIN_IMPORT, [
            'labels' => ['name' => __('Pinterest Imports', 'mae-storm-eats')],
            'description' => __('Private staged Pinterest import inventory.', 'mae-storm-eats'),
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'custom-fields'],
        ]);
    }

    public function disableBlockEditor(bool $use_block_editor, string $post_type): bool
    {
        return in_array($post_type, [self::POST_TYPE_RECIPE, self::POST_TYPE_REVIEW], true) ? false : $use_block_editor;
    }

    public function registerTaxonomies(): void
    {
        $this->registerHierarchicalTaxonomy(
            self::TAXONOMY_CUISINE,
            __('Cuisines', 'mae-storm-eats'),
            __('Cuisine', 'mae-storm-eats'),
            'cuisines'
        );

        $this->registerHierarchicalTaxonomy(
            self::TAXONOMY_COURSE,
            __('Courses', 'mae-storm-eats'),
            __('Course', 'mae-storm-eats'),
            'courses'
        );

        $this->registerHierarchicalTaxonomy(
            self::TAXONOMY_DIET,
            __('Diets', 'mae-storm-eats'),
            __('Diet', 'mae-storm-eats'),
            'diets'
        );

        register_taxonomy(self::TAXONOMY_TAG, [self::POST_TYPE_RECIPE], [
            'labels' => [
                'name' => __('Recipe Tags', 'mae-storm-eats'),
                'singular_name' => __('Recipe Tag', 'mae-storm-eats'),
                'search_items' => __('Search Recipe Tags', 'mae-storm-eats'),
                'popular_items' => __('Popular Recipe Tags', 'mae-storm-eats'),
                'all_items' => __('All Recipe Tags', 'mae-storm-eats'),
                'edit_item' => __('Edit Recipe Tag', 'mae-storm-eats'),
                'update_item' => __('Update Recipe Tag', 'mae-storm-eats'),
                'add_new_item' => __('Add New Recipe Tag', 'mae-storm-eats'),
                'new_item_name' => __('New Recipe Tag Name', 'mae-storm-eats'),
                'menu_name' => __('Tags', 'mae-storm-eats'),
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
                'search_items' => sprintf(__('Search %s', 'mae-storm-eats'), $plural),
                'all_items' => sprintf(__('All %s', 'mae-storm-eats'), $plural),
                'parent_item' => sprintf(__('Parent %s', 'mae-storm-eats'), $singular),
                'parent_item_colon' => sprintf(__('Parent %s:', 'mae-storm-eats'), $singular),
                'edit_item' => sprintf(__('Edit %s', 'mae-storm-eats'), $singular),
                'update_item' => sprintf(__('Update %s', 'mae-storm-eats'), $singular),
                'add_new_item' => sprintf(__('Add New %s', 'mae-storm-eats'), $singular),
                'new_item_name' => sprintf(__('New %s Name', 'mae-storm-eats'), $singular),
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
