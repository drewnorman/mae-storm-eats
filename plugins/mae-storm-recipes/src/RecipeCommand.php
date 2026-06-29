<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class RecipeCommand
{
    /**
     * Prints the current Mae Storm Recipes plugin status.
     *
     * ## EXAMPLES
     *
     *     wp mae-storm recipes status
     */
    public function status(): void
    {
        \WP_CLI::success('Mae Storm Recipes plugin is loaded. Import commands are not implemented yet.');
    }

    /**
     * Placeholder for the future import pipeline.
     *
     * ## EXAMPLES
     *
     *     wp mae-storm recipes import
     */
    public function import(): void
    {
        \WP_CLI::warning('Recipe import pipeline is not implemented yet.');
    }
}
