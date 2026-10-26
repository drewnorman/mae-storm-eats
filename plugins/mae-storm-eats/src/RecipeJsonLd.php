<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class RecipeJsonLd
{
    /** @return array<string,mixed>|null */
    public function extract(string $html): ?array
    {
        if (! preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5), true);
            if (! is_array($decoded)) {
                continue;
            }
            $recipe = $this->findRecipe($decoded);
            if ($recipe !== null) {
                return $recipe;
            }
        }

        return null;
    }

    /** @param array<mixed> $value @return array<string,mixed>|null */
    private function findRecipe(array $value): ?array
    {
        if ($this->hasRecipeType($value['@type'] ?? null)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            if ($key === '@context' || ! is_array($child)) {
                continue;
            }
            $found = $this->findRecipe($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    private function hasRecipeType(mixed $type): bool
    {
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (is_string($candidate) && strtolower(basename(str_replace('\\', '/', $candidate))) === 'recipe') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $recipe @return array<string,mixed> */
    public function normalize(array $recipe): array
    {
        return [
            'title' => $this->text($recipe['name'] ?? ''),
            'ingredients' => $this->strings($recipe['recipeIngredient'] ?? []),
            'steps' => $this->instructions($recipe['recipeInstructions'] ?? []),
            'recipe_yield' => $this->text($recipe['recipeYield'] ?? ''),
            'prep_time_minutes' => $this->duration($recipe['prepTime'] ?? ''),
            'cook_time_minutes' => $this->duration($recipe['cookTime'] ?? ''),
            'total_time_minutes' => $this->duration($recipe['totalTime'] ?? ''),
            'nutrition' => is_array($recipe['nutrition'] ?? null) ? $recipe['nutrition'] : [],
            'image_url' => $this->image($recipe['image'] ?? ''),
            'raw' => $recipe,
        ];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map(fn (mixed $item): string => $this->text($item), $items)));
    }

    /** @return list<array{section:string,text:string}> */
    private function instructions(mixed $value, string $section = ''): array
    {
        $items = is_array($value) ? $value : [$value];
        $steps = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $steps[] = ['section' => $section, 'text' => $this->text($item)];
            } elseif (is_array($item) && isset($item['itemListElement'])) {
                $steps = array_merge($steps, $this->instructions($item['itemListElement'], $this->text($item['name'] ?? $section)));
            } elseif (is_array($item)) {
                $text = $this->text($item['text'] ?? $item['name'] ?? '');
                if ($text !== '') {
                    $steps[] = ['section' => $section, 'text' => $text];
                }
            }
        }
        return $steps;
    }

    private function duration(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?/i', $value, $m)) {
            return 0;
        }
        return ((int) ($m[1] ?? 0) * 1440) + ((int) ($m[2] ?? 0) * 60) + (int) ($m[3] ?? 0);
    }

    private function image(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            if (isset($value['url'])) {
                return $this->image($value['url']);
            }

            foreach ($value as $candidate) {
                $url = $this->image($candidate);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        return '';
    }

    private function text(mixed $value): string
    {
        return trim(wp_strip_all_tags(is_scalar($value) ? (string) $value : ''));
    }
}
