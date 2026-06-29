<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class RecipeCommand
{
    private RecipeImporter $importer;

    public function __construct()
    {
        $this->importer = new RecipeImporter();
    }

    /**
     * Prints the current Mae Storm Recipes plugin status.
     *
     * ## EXAMPLES
     *
     *     wp mae-storm recipes status
     */
    public function status(): void
    {
        \WP_CLI::success('Mae Storm Recipes plugin is loaded.');
    }

    /**
     * Revalidates a recipe candidate.
     *
     * ## EXAMPLES
     *
     *     wp mae-storm recipes validate 123
     */
    public function validate(array $args): void
    {
        $post_id = absint($args[0] ?? 0);

        if ($post_id === 0) {
            \WP_CLI::error('Provide a recipe post ID.');
        }

        $errors = $this->importer->validateRecipe($post_id);

        if ($errors === []) {
            update_post_meta($post_id, RecipeMeta::META_IMPORT_STATUS, 'reviewed');
            \WP_CLI::success('Recipe candidate is valid.');

            return;
        }

        update_post_meta($post_id, RecipeMeta::META_IMPORT_STATUS, 'needs_review');
        update_post_meta($post_id, RecipeMeta::META_IMPORT_ERRORS, $errors);
        \WP_CLI::warning(implode("\n", $errors));
    }

    /**
     * Lists likely duplicate recipes for a candidate.
     *
     * ## EXAMPLES
     *
     *     wp mae-storm recipes duplicate-scan 123
     */
    public function duplicate_scan(array $args): void
    {
        $post_id = absint($args[0] ?? 0);

        if ($post_id === 0) {
            \WP_CLI::error('Provide a recipe post ID.');
        }

        $matches = $this->importer->duplicateCandidates($post_id);

        if ($matches === []) {
            \WP_CLI::success('No likely duplicates found.');

            return;
        }

        \WP_CLI\Utils\format_items('table', $matches, ['post_id', 'title', 'match']);
    }

    /**
     * Reparse is reserved for source-aware importer adapters.
     */
    public function reparse(): void
    {
        \WP_CLI::warning('Reparse requires stored source artifacts and will be expanded with importer adapters.');
    }

    /**
     * Retry is reserved for failed source-aware importer adapters.
     */
    public function retry(): void
    {
        \WP_CLI::warning('Retry requires stored source artifacts and will be expanded with importer adapters.');
    }
}
