<?php

declare(strict_types=1);

namespace MaeStorm\Eats;

final class PrivateAccessSettings implements ServiceProviderInterface
{
    public const OPTION = 'mae_storm_eats_shared_password_hash';

    private const PAGE = 'mae-storm-eats-private-access';
    private const ACTION = 'mae_storm_eats_change_password';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
    }

    public function addPage(): void
    {
        add_options_page(
            __('Private Access', 'mae-storm-eats'),
            __('Private Access', 'mae-storm-eats'),
            'manage_options',
            self::PAGE,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot manage private access.', 'mae-storm-eats'), '', ['response' => 403]);
        }

        $cms_configured = get_option(self::OPTION, null) !== null;
        $notice = isset($_GET['mae_storm_eats_notice']) && is_string($_GET['mae_storm_eats_notice'])
            ? sanitize_key(wp_unslash($_GET['mae_storm_eats_notice']))
            : '';
        $notices = [
            'changed' => [__('Frontend password changed. Existing frontend access sessions have expired.', 'mae-storm-eats'), 'success'],
            'empty' => [__('Enter a new password and confirmation.', 'mae-storm-eats'), 'error'],
            'mismatch' => [__('The passwords did not match.', 'mae-storm-eats'), 'error'],
            'whitespace' => [__('The password cannot begin or end with whitespace.', 'mae-storm-eats'), 'error'],
            'too_long' => [__('The password is too long.', 'mae-storm-eats'), 'error'],
            'save_failed' => [__('The password could not be saved. Please try again.', 'mae-storm-eats'), 'error'],
        ];

        echo '<div class="wrap"><h1>' . esc_html__('Private Access', 'mae-storm-eats') . '</h1>';

        if (isset($notices[$notice])) {
            [$message, $type] = $notices[$notice];
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        echo '<p><strong>' . esc_html__('Active password source:', 'mae-storm-eats') . '</strong> ';
        echo esc_html($cms_configured
            ? __('WordPress', 'mae-storm-eats')
            : __('Server configuration', 'mae-storm-eats')) . '</p>';
        echo '<p>' . esc_html__('Changing this password signs out everyone using the shared frontend password. WordPress user logins are unaffected.', 'mae-storm-eats') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="mae-storm-eats-new-password">' . esc_html__('New frontend password', 'mae-storm-eats') . '</label></th>';
        echo '<td><input class="regular-text" type="password" id="mae-storm-eats-new-password" name="mae_storm_eats_new_password" autocomplete="new-password" required></td></tr>';
        echo '<tr><th scope="row"><label for="mae-storm-eats-confirm-password">' . esc_html__('Confirm new password', 'mae-storm-eats') . '</label></th>';
        echo '<td><input class="regular-text" type="password" id="mae-storm-eats-confirm-password" name="mae_storm_eats_confirm_password" autocomplete="new-password" required></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Change frontend password', 'mae-storm-eats'));
        echo '</form></div>';
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot manage private access.', 'mae-storm-eats'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        $password = $_POST['mae_storm_eats_new_password'] ?? null;
        $confirmation = $_POST['mae_storm_eats_confirm_password'] ?? null;

        if (! is_string($password) || ! is_string($confirmation)) {
            $this->redirectWithNotice('empty');
        }

        $password = wp_unslash($password);
        $confirmation = wp_unslash($confirmation);

        if ($password === '' || $confirmation === '') {
            $this->redirectWithNotice('empty');
        }
        if (! hash_equals($password, $confirmation)) {
            $this->redirectWithNotice('mismatch');
        }
        if ($password !== trim($password)) {
            $this->redirectWithNotice('whitespace');
        }
        if (strlen($password) > 4096) {
            $this->redirectWithNotice('too_long');
        }

        $hash = wp_hash_password($password);

        if ($hash === '*' || ! update_option(self::OPTION, $hash, false)) {
            $this->redirectWithNotice('save_failed');
        }

        $this->redirectWithNotice('changed');
    }

    private function redirectWithNotice(string $notice): never
    {
        $url = add_query_arg('mae_storm_eats_notice', $notice, admin_url('options-general.php?page=' . self::PAGE));
        wp_safe_redirect($url);
        exit;
    }
}
