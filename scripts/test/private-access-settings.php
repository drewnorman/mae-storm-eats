<?php

declare(strict_types=1);

use MaeStorm\Eats\PrivateAccessSettings;

chdir('/var/www/html');
require '/var/www/html/wp-load.php';

/** @return array{status: int, body: string, headers: list<string>} */
function request(string $path, string $method = 'GET', array $fields = [], string $cookies = ''): array
{
    $headers = "Host: wordpress.mae-storm-eats.localhost\r\n";
    if ($cookies !== '') {
        $headers .= "Cookie: {$cookies}\r\n";
    }
    if ($method === 'POST') {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $method === 'POST' ? http_build_query($fields) : '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents('http://127.0.0.1' . $path, false, $context);
    $response_headers = $http_response_header ?? [];

    if (! is_string($body) || ! preg_match('/^HTTP\/\S+ (\d{3})/', $response_headers[0] ?? '', $match)) {
        throw new RuntimeException("HTTP request failed for {$path}.");
    }

    return ['status' => (int) $match[1], 'body' => $body, 'headers' => $response_headers];
}

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{status: int, cookie: string} */
function frontendLogin(string $password): array
{
    $page = request('/');
    expect($page['status'] === 401, 'Frontend login page did not require a password.');
    expect((bool) preg_match('/name="mae_storm_eats_nonce" value="([^"]+)"/', $page['body'], $match), 'Frontend nonce is missing.');

    $response = request('/', 'POST', [
        'mae_storm_eats_password' => $password,
        'mae_storm_eats_nonce' => $match[1],
    ]);
    $cookie = '';
    foreach ($response['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*(mae_storm_eats_access=[^;]+)/i', $header, $match)) {
            $cookie = $match[1];
        }
    }

    return ['status' => $response['status'], 'cookie' => $cookie];
}

function authCookies(WP_User $user, string $token): string
{
    $expires = time() + HOUR_IN_SECONDS;
    $auth = wp_generate_auth_cookie($user->ID, $expires, 'auth', $token);
    $logged_in = wp_generate_auth_cookie($user->ID, $expires, 'logged_in', $token);

    return AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $logged_in;
}

function optionHash(): ?string
{
    wp_cache_flush();
    $value = get_option(PrivateAccessSettings::OPTION, null);

    return is_string($value) ? $value : null;
}

$option = PrivateAccessSettings::OPTION;
$server_password = getenv('MAE_STORM_EATS_SHARED_PASSWORD');
$admin = get_user_by('login', getenv('MAE_STORM_EATS_DEV_ADMIN_USER') ?: 'admin');
$importer = get_user_by('login', 'pinterest-importer');
expect(get_option($option, null) === null, 'The disposable WordPress stack already has a CMS password.');
expect(is_string($server_password) && $server_password !== '', 'The server password is missing.');
expect($admin instanceof WP_User && $importer instanceof WP_User, 'Test users are missing.');

$admin_token = WP_Session_Tokens::get_instance($admin->ID)->create(time() + HOUR_IN_SECONDS);
$importer_token = WP_Session_Tokens::get_instance($importer->ID)->create(time() + HOUR_IN_SECONDS);

try {
    $admin_cookies = authCookies($admin, $admin_token);
    $importer_cookies = authCookies($importer, $importer_token);
    wp_set_current_user($admin->ID);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($admin->ID, time() + HOUR_IN_SECONDS, 'logged_in', $admin_token);
    $nonce = wp_create_nonce('mae_storm_eats_change_password');
    unset($_COOKIE[LOGGED_IN_COOKIE]);
    wp_set_current_user(0);

    $page_path = '/wp-admin/options-general.php?page=mae-storm-eats-private-access';
    $admin_page = request($page_path, 'GET', [], $admin_cookies);
    expect($admin_page['status'] === 200, 'The admin settings page is unavailable.');
    expect(str_contains($admin_page['body'], 'Server configuration'), 'The initial password source is not shown.');
    expect(str_contains($admin_page['body'], 'name="mae_storm_eats_new_password"'), 'The password field is missing.');
    expect(request($page_path, 'GET', [], $importer_cookies)['status'] !== 200, 'A non-admin can view the settings page.');

    $legacy_login = frontendLogin($server_password);
    expect($legacy_login['status'] === 302 && $legacy_login['cookie'] !== '', 'The initial server password failed.');
    expect(request('/', 'GET', [], $legacy_login['cookie'])['status'] === 200, 'The initial access cookie failed.');

    $change_path = '/wp-admin/admin-post.php';
    $first_password = 'private-access-first-2026!';
    $second_password = 'private-access-second-2026!';
    $fields = [
        'action' => 'mae_storm_eats_change_password',
        '_wpnonce' => $nonce,
        'mae_storm_eats_new_password' => $first_password,
        'mae_storm_eats_confirm_password' => $first_password,
    ];

    $invalid_nonce = request($change_path, 'POST', array_replace($fields, ['_wpnonce' => 'invalid']), $admin_cookies);
    expect($invalid_nonce['status'] === 403 && optionHash() === null, 'An invalid nonce changed the password.');
    $unauthorized = request($change_path, 'POST', $fields, $importer_cookies);
    expect($unauthorized['status'] === 403 && optionHash() === null, 'A non-admin changed the password.');
    $blank = request($change_path, 'POST', array_replace($fields, ['mae_storm_eats_new_password' => '']), $admin_cookies);
    expect($blank['status'] === 302 && optionHash() === null, 'A blank password changed the setting.');
    $mismatch = request($change_path, 'POST', array_replace($fields, ['mae_storm_eats_confirm_password' => 'different']), $admin_cookies);
    expect($mismatch['status'] === 302 && optionHash() === null, 'Mismatched passwords changed the setting.');
    $whitespace = request($change_path, 'POST', array_replace($fields, [
        'mae_storm_eats_new_password' => ' ' . $first_password,
        'mae_storm_eats_confirm_password' => ' ' . $first_password,
    ]), $admin_cookies);
    expect($whitespace['status'] === 302 && optionHash() === null, 'A password with surrounding whitespace changed the setting.');
    $too_long = str_repeat('a', 4097);
    $oversized = request($change_path, 'POST', array_replace($fields, [
        'mae_storm_eats_new_password' => $too_long,
        'mae_storm_eats_confirm_password' => $too_long,
    ]), $admin_cookies);
    expect($oversized['status'] === 302 && optionHash() === null, 'An oversized password changed the setting.');
    expect(request('/', 'GET', [], $legacy_login['cookie'])['status'] === 200, 'A rejected change invalidated the active cookie.');

    $saved = request($change_path, 'POST', $fields, $admin_cookies);
    $hash = optionHash();
    expect($saved['status'] === 302 && $hash !== null, 'The CMS password was not saved.');
    expect($hash !== $first_password && wp_check_password($first_password, $hash), 'The CMS password was not hashed correctly.');
    expect(! isset(get_registered_settings()[$option]), 'The password hash is registered as a public setting.');
    global $wpdb;
    $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
    expect(in_array($autoload, ['no', 'off', 'auto-off'], true), 'The password hash option is autoloaded.');

    $admin_page = request($page_path, 'GET', [], $admin_cookies);
    expect($admin_page['status'] === 200 && str_contains($admin_page['body'], 'WordPress'), 'The CMS password source is not shown.');
    expect(! str_contains($admin_page['body'], $first_password) && ! str_contains($admin_page['body'], $hash), 'The settings page disclosed a credential.');
    expect(request('/', 'GET', [], $legacy_login['cookie'])['status'] === 401, 'The legacy access cookie survived the CMS change.');
    expect(frontendLogin($server_password)['status'] === 401, 'The server password still unlocks the frontend.');

    $first_login = frontendLogin($first_password);
    expect($first_login['status'] === 302 && $first_login['cookie'] !== '', 'The CMS password failed.');
    expect(request('/', 'GET', [], $first_login['cookie'])['status'] === 200, 'The CMS access cookie failed.');
    expect(request('/wp-json/wp/v2/recipes', 'GET', [], $first_login['cookie'])['status'] === 200, 'The CMS cookie did not authorize the recipe API.');

    $rotated = request($change_path, 'POST', array_replace($fields, [
        'mae_storm_eats_new_password' => $second_password,
        'mae_storm_eats_confirm_password' => $second_password,
    ]), $admin_cookies);
    expect($rotated['status'] === 302 && optionHash() !== $hash, 'The CMS password did not rotate.');
    expect(request('/', 'GET', [], $first_login['cookie'])['status'] === 401, 'An old access cookie survived rotation.');
    expect(frontendLogin($first_password)['status'] === 401, 'The old CMS password still works.');
    expect(frontendLogin($second_password)['status'] === 302, 'The rotated CMS password failed.');

    update_option($option, '', false);
    expect(frontendLogin($server_password)['status'] === 401, 'An invalid CMS value restored the server password.');
} finally {
    delete_option($option);
    WP_Session_Tokens::get_instance($admin->ID)->destroy($admin_token);
    WP_Session_Tokens::get_instance($importer->ID)->destroy($importer_token);
}

fwrite(STDOUT, "Private access settings integration checks passed.\n");
