<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$theme_url = 'https://example.test/wp-content/themes/mae-storm-eats';
$twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/themes/mae-storm-eats/views'));
$twig->addFunction(new TwigFunction('function', static function (string $name) use ($theme_url): string {
    return $name === 'get_template_directory_uri' ? $theme_url : '';
}));

$post = new class {
    public function meta(string $key): int|string
    {
        return match ($key) {
            '_mae_storm_eats_recipe_total_time_minutes' => 20,
            '_mae_storm_eats_recipe_servings' => '4',
            default => '',
        };
    }
};

$html = $twig->render('partials/recipe-meta.twig', ['post' => $post]);

foreach (['time', 'servings'] as $icon) {
    $expected = sprintf('src="%s/assets/src/images/%s.svg"', $theme_url, $icon);
    if (! str_contains($html, $expected)) {
        fwrite(STDERR, "Recipe metadata is missing the full {$icon} icon URL.\n");
        exit(1);
    }
}

$missing_post = new class {
    public function meta(string $key): int|string
    {
        unset($key);

        return '';
    }
};

$missing_html = $twig->render('partials/recipe-meta.twig', ['post' => $missing_post]);
if (substr_count($missing_html, '<dd>N/A</dd>') !== 2) {
    fwrite(STDERR, "Missing time and servings must both render as N/A.\n");
    exit(1);
}

echo "Recipe metadata icons and missing-value labels render with restricted context.\n";
