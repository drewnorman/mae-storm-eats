<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

use WP_Error;

final class RestProtection implements ServiceProviderInterface
{
    private AccessGate $access_gate;

    public function __construct(?AccessGate $access_gate = null)
    {
        $this->access_gate = $access_gate ?? new AccessGate();
    }

    public function register(): void
    {
        add_filter('rest_authentication_errors', [$this, 'protectRecipeRoutes']);
    }

    public function protectRecipeRoutes(WP_Error|bool|null $result): WP_Error|bool|null
    {
        if ($result instanceof WP_Error || $result === true) {
            return $result;
        }

        $route = $this->currentRoute();

        if ($route === null || ! $this->isRecipeRoute($route)) {
            return $result;
        }

        if ($this->access_gate->isAuthorized()) {
            return $result;
        }

        return new WP_Error(
            'mae_storm_recipes_private_rest',
            __('Recipe API routes require private recipe access.', 'mae-storm-recipes'),
            ['status' => 401]
        );
    }

    private function currentRoute(): ?string
    {
        $request = $_GET['rest_route'] ?? null;

        if (! is_string($request)) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? null;

            if (! is_string($request_uri)) {
                return null;
            }

            $path = (string) parse_url($request_uri, PHP_URL_PATH);
            $prefix = '/' . rest_get_url_prefix();
            $position = strpos($path, $prefix);

            if ($position === false) {
                return null;
            }

            $request = substr($path, $position + strlen($prefix));
        }

        $route = '/' . ltrim($request, '/');

        return sanitize_text_field(wp_unslash($route));
    }

    private function isRecipeRoute(string $route): bool
    {
        return str_starts_with($route, '/wp/v2/recipes')
            || str_starts_with($route, '/wp/v2/recipe-reviews')
            || str_starts_with($route, '/wp/v2/cuisines')
            || str_starts_with($route, '/wp/v2/courses')
            || str_starts_with($route, '/wp/v2/diets')
            || str_starts_with($route, '/wp/v2/recipe-tags');
    }
}
