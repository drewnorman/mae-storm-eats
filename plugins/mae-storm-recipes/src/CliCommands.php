<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class CliCommands implements ServiceProviderInterface
{
    public function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_command('mae-storm recipes', RecipeCommand::class);
    }
}
