<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

interface ServiceProviderInterface
{
    public function register(): void;
}
