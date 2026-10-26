<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class CommentsDisabled implements ServiceProviderInterface
{
    public function register(): void
    {
        add_action('init', [$this, 'removePostTypeSupport'], 100);
        add_action('admin_init', [$this, 'removeAdminSurfaces']);
        add_action('admin_menu', [$this, 'removeAdminMenu']);

        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', '__return_empty_array', 20);
        add_filter('pre_option_default_comment_status', [$this, 'closed']);
        add_filter('pre_option_default_ping_status', [$this, 'closed']);
        add_filter('rest_endpoints', [$this, 'removeRestEndpoints']);
    }

    public function removePostTypeSupport(): void
    {
        foreach (get_post_types([], 'names') as $post_type) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }

    public function removeAdminSurfaces(): void
    {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

        foreach (get_post_types([], 'names') as $post_type) {
            remove_meta_box('commentstatusdiv', $post_type, 'normal');
            remove_meta_box('commentsdiv', $post_type, 'normal');
            remove_meta_box('trackbacksdiv', $post_type, 'normal');
        }
    }

    public function removeAdminMenu(): void
    {
        remove_menu_page('edit-comments.php');
    }

    public function closed(): string
    {
        return 'closed';
    }

    /**
     * @param array<string,mixed> $endpoints
     * @return array<string,mixed>
     */
    public function removeRestEndpoints(array $endpoints): array
    {
        foreach (array_keys($endpoints) as $route) {
            if (str_starts_with((string) $route, '/wp/v2/comments')) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }
}
