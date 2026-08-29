<?php

declare(strict_types=1);

define('WP_ENVIRONMENT_TYPE', 'production');
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('FORCE_SSL_ADMIN', true);

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
    $value = getenv($salt);
    if ($value !== false && !defined($salt)) {
        define($salt, $value);
    }
}

if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
