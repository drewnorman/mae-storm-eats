<?php

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failures = [];
$admin_user = getenv('MAE_STORM_EATS_DEV_ADMIN_USER') ?: 'admin';
$admin_password = getenv('MAE_STORM_EATS_DEV_ADMIN_PASSWORD') ?: 'password';

if (! is_blog_installed()) {
    $failures[] = 'WordPress is not installed.';
}

if (! is_plugin_active('mae-storm-eats/mae-storm-eats.php')) {
    $failures[] = 'Mae Storm Eats plugin is inactive.';
}

if (get_stylesheet() !== 'mae-storm-eats') {
    $failures[] = 'Mae Storm Eats theme is inactive.';
}

if (! class_exists(\Timber\Timber::class)) {
    $failures[] = 'Timber is unavailable.';
}

foreach (['app.css', 'app.js'] as $asset) {
    if (! is_file(get_theme_root() . '/mae-storm-eats/assets/dist/' . $asset)) {
        $failures[] = "Theme asset {$asset} is unavailable.";
    }
}

$authenticated_user = wp_authenticate($admin_user, $admin_password);

if (is_wp_error($authenticated_user) || $authenticated_user->user_login !== $admin_user) {
    $failures[] = "Local administrator {$admin_user} could not authenticate.";
}

$context = stream_context_create([
    'http' => [
        'ignore_errors' => true,
        'timeout' => 10,
    ],
]);
$response = file_get_contents('http://127.0.0.1/', false, $context);

if (! is_string($response) || ! str_contains($response, 'name="mae_storm_eats_password"')
    || str_contains($response, 'data-conversions-toggle')) {
    $failures[] = 'Apache did not render the private-access page.';
}

$import_user = get_user_by('login', 'pinterest-importer');
$application_password_uuid = null;

if (! $import_user instanceof WP_User) {
    $failures[] = 'Pinterest importer user is unavailable.';
} else {
    $created = WP_Application_Passwords::create_new_application_password($import_user->ID, [
        'name' => 'development boot smoke',
    ]);

    if (is_wp_error($created)) {
        $failures[] = 'Could not create a temporary Application Password.';
    } else {
        [$application_password, $application_password_item] = $created;
        $application_password_uuid = $application_password_item['uuid'];
        $authorization = 'Basic ' . base64_encode($import_user->user_login . ':' . $application_password);

        try {
            $authenticated_context = stream_context_create([
                'http' => [
                    'header' => "Authorization: {$authorization}\r\n",
                    'ignore_errors' => true,
                    'timeout' => 10,
                ],
            ]);
            $authenticated_response = file_get_contents(
                'http://127.0.0.1/wp-json/mae-storm-eats/v1/pinterest-imports/status',
                false,
                $authenticated_context
            );
            $authenticated_body = is_string($authenticated_response) ? json_decode($authenticated_response, true) : null;

            if (($authenticated_body['authenticated'] ?? false) !== true) {
                $failures[] = 'Application Password did not authenticate through Apache.';
            }

            $invalid_context = stream_context_create([
                'http' => [
                    'header' => 'Authorization: Basic ' . base64_encode($import_user->user_login . ':definitelywrong') . "\r\n",
                    'ignore_errors' => true,
                    'timeout' => 10,
                ],
            ]);
            $invalid_response = file_get_contents(
                'http://127.0.0.1/wp-json/mae-storm-eats/v1/pinterest-imports/status',
                false,
                $invalid_context
            );
            $invalid_body = is_string($invalid_response) ? json_decode($invalid_response, true) : null;

            if (($invalid_body['code'] ?? '') !== 'incorrect_password') {
                $failures[] = 'Invalid Application Password did not return the authentication-specific error.';
            }
        } finally {
            WP_Application_Passwords::delete_application_password($import_user->ID, $application_password_uuid);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "WordPress development stack verification passed.\n");
