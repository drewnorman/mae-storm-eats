<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class AdminBarDisabled implements ServiceProviderInterface
{
    public function register(): void
    {
        add_filter('show_admin_bar', '__return_false');
    }
}
