<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

use Timber\Timber;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

final class RecipeListing implements ServiceProviderInterface
{
    private AccessGate $access_gate;

    public function __construct(?AccessGate $access_gate = null)
    {
        $this->access_gate = $access_gate ?? new AccessGate();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('posts_orderby', [self::class, 'orderBy'], 10, 2);
    }

    public function routes(): void
    {
        register_rest_route('mae-storm-eats/v1', '/recipe-list', [
            'methods' => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(): bool|WP_Error
    {
        if ($this->access_gate->isAuthorized()) {
            return true;
        }

        return new WP_Error(
            'mae_storm_eats_private_rest',
            __('Recipe API routes require private recipe access.', 'mae-storm-eats'),
            ['status' => 401]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! class_exists(Timber::class) || ! is_readable(get_template_directory() . '/views/partials/recipe-card.twig')) {
            return new WP_Error(
                'mae_storm_eats_recipe_view_unavailable',
                __('The recipe listing view is unavailable.', 'mae-storm-eats'),
                ['status' => 503]
            );
        }

        $params = $request->get_params();
        $page = max(1, (int) ($params['page'] ?? 1));
        $search = $params['s'] ?? '';
        $search = is_string($search) ? sanitize_text_field(wp_unslash($search)) : '';
        $query = new WP_Query(array_merge(
            [
                'post_type' => ContentTypes::POST_TYPE_RECIPE,
                'post_status' => 'publish',
                'paged' => $page,
                's' => $search,
            ],
            self::queryArgs(self::selectedFilters($params), self::sortFromParams($params))
        ));

        $html = '';
        foreach (Timber::get_posts($query) as $post) {
            $html .= Timber::compile('partials/recipe-card.twig', ['post' => $post]);
        }

        return new WP_REST_Response([
            'html' => $html,
            'total' => (int) $query->found_posts,
            'page' => $page,
            'pages' => (int) $query->max_num_pages,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{course:string,cuisine:string,occasion:string,vibe:string,ingredient:list<string>,ingredient_mode:string}
     */
    public static function selectedFilters(array $params): array
    {
        $selected = [];
        foreach (['course', 'cuisine', 'occasion', 'vibe'] as $parameter) {
            $raw = $params[$parameter] ?? '';
            $selected[$parameter] = is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';
        }

        $raw_ingredients = $params['ingredient'] ?? [];
        $legacy_ingredient = is_string($raw_ingredients);
        $ingredients = is_array($raw_ingredients) ? $raw_ingredients : [$raw_ingredients];
        $selected['ingredient'] = [];
        foreach ($ingredients as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash($raw));
            if ($value === '') {
                continue;
            }

            if ($legacy_ingredient) {
                $term = get_term_by('slug', $value, ContentTypes::TAXONOMY_INGREDIENT);
                if (! $term instanceof \WP_Term) {
                    $term = get_term_by('name', $value, ContentTypes::TAXONOMY_INGREDIENT);
                }
                $value = $term instanceof \WP_Term ? $term->slug : sanitize_title($value);
            }

            $selected['ingredient'][] = sanitize_title($value);
        }
        $selected['ingredient'] = array_values(array_unique($selected['ingredient']));

        $raw_mode = $params['ingredient_mode'] ?? 'any';
        $selected['ingredient_mode'] = $raw_mode === 'all' ? 'all' : 'any';

        return $selected;
    }

    /** @param array<string, mixed> $params */
    public static function sortFromParams(array $params): string
    {
        $raw = $params['sort'] ?? '';
        $sort = is_string($raw) ? sanitize_key(wp_unslash($raw)) : '';

        return in_array($sort, ['recent', 'title', 'time', 'difficulty'], true) ? $sort : 'recent';
    }

    /**
     * @param array{course:string,cuisine:string,occasion:string,vibe:string,ingredient:list<string>,ingredient_mode:string} $selected
     * @param array<mixed> $existing
     * @return array<mixed>
     */
    public static function taxQuery(array $selected, array $existing = []): array
    {
        $filters = [
            'course' => ContentTypes::TAXONOMY_COURSE,
            'cuisine' => ContentTypes::TAXONOMY_CUISINE,
            'occasion' => ContentTypes::TAXONOMY_OCCASION,
            'vibe' => ContentTypes::TAXONOMY_VIBE,
        ];
        foreach ($filters as $parameter => $taxonomy) {
            if ($selected[$parameter] === '') {
                continue;
            }

            $term = get_term_by('slug', $selected[$parameter], $taxonomy);
            $existing[] = [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term instanceof \WP_Term ? [(int) $term->term_id] : [0],
            ];
        }

        if ($selected['ingredient'] !== []) {
            $ingredient_ids = array_map(static function (string $slug): int {
                $term = get_term_by('slug', $slug, ContentTypes::TAXONOMY_INGREDIENT);

                return $term instanceof \WP_Term ? (int) $term->term_id : 0;
            }, $selected['ingredient']);

            if ($selected['ingredient_mode'] === 'all') {
                foreach ($ingredient_ids as $id) {
                    $existing[] = [
                        'taxonomy' => ContentTypes::TAXONOMY_INGREDIENT,
                        'field' => 'term_id',
                        'terms' => [$id],
                    ];
                }
            } else {
                $existing[] = [
                    'taxonomy' => ContentTypes::TAXONOMY_INGREDIENT,
                    'field' => 'term_id',
                    'terms' => $ingredient_ids,
                ];
            }
        }

        return $existing === [] ? [] : ['relation' => 'AND', ...$existing];
    }

    /**
     * @param array{course:string,cuisine:string,occasion:string,vibe:string,ingredient:list<string>,ingredient_mode:string} $selected
     * @param array<mixed> $existing_tax_query
     * @return array<string, mixed>
     */
    public static function queryArgs(array $selected, string $sort, array $existing_tax_query = []): array
    {
        $args = ['mae_recipe_sort' => $sort];
        if ($sort === 'recent') {
            $args['orderby'] = ['date' => 'DESC', 'ID' => 'DESC'];
        } elseif ($sort === 'title') {
            $args['orderby'] = ['title' => 'ASC', 'ID' => 'DESC'];
        }
        $tax_query = self::taxQuery($selected, $existing_tax_query);
        if ($tax_query !== []) {
            $args['tax_query'] = $tax_query;
        }

        return $args;
    }

    public static function orderBy(string $orderby, WP_Query $query): string
    {
        if (is_admin()) {
            return $orderby;
        }

        $sort = $query->get('mae_recipe_sort');
        if ($sort !== 'time' && $sort !== 'difficulty') {
            return $orderby;
        }

        global $wpdb;
        $meta_key = $sort === 'time'
            ? RecipeMeta::META_TOTAL_TIME_MINUTES
            : RecipeMeta::META_DIFFICULTY;
        $value = $wpdb->prepare(
            "(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = %s ORDER BY pm.meta_id DESC LIMIT 1)",
            $meta_key
        );

        if ($sort === 'time') {
            $minutes = "CAST({$value} AS UNSIGNED)";

            return "CASE WHEN {$minutes} > 0 THEN 0 ELSE 1 END ASC, CASE WHEN {$minutes} > 0 THEN {$minutes} ELSE 0 END ASC, {$wpdb->posts}.post_title ASC, {$wpdb->posts}.ID DESC";
        }

        $difficulty = "LOWER(TRIM({$value}))";

        return "CASE {$difficulty} WHEN 'easy' THEN 0 WHEN 'medium' THEN 1 WHEN 'hard' THEN 2 ELSE 3 END ASC, {$wpdb->posts}.post_title ASC, {$wpdb->posts}.ID DESC";
    }
}
