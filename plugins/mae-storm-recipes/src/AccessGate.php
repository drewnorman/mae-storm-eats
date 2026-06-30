<?php

declare(strict_types=1);

namespace MaeStorm\Recipes;

final class AccessGate implements ServiceProviderInterface
{
    public const PASSWORD_ENV = 'MAE_STORM_RECIPES_SHARED_PASSWORD';
    public const PASSWORD_CONSTANT = 'MAE_STORM_RECIPES_SHARED_PASSWORD';

    private const COOKIE = 'mae_storm_recipes_access';
    private const ACTION = 'mae_storm_recipes_login';
    private const NONCE = 'mae_storm_recipes_nonce';
    private const LOCKOUT_FAILURES = 5;
    private const LOCKOUT_SECONDS = 1800;
    private const SESSION_SECONDS = 2592000;

    public function register(): void
    {
        add_action('template_redirect', [$this, 'protectFrontend'], 0);
        add_action('wp_head', [$this, 'addNoindexMeta'], 0);
        add_action('send_headers', [$this, 'sendNoindexHeader']);
    }

    public function protectFrontend(): void
    {
        if (! $this->isProtectedFrontendRequest() || $this->isAuthorized()) {
            return;
        }

        $error = null;

        if ($this->isLoginAttempt()) {
            $error = $this->handleLoginAttempt();

            if ($error === null) {
                $this->redirectAfterLogin();
            }
        }

        status_header(401);
        nocache_headers();
        $this->sendNoindexHeader();
        $this->renderLogin($error);
        exit;
    }

    public function isAuthorized(): bool
    {
        if (is_user_logged_in() && current_user_can('read')) {
            return true;
        }

        $configured_password = $this->configuredPassword();

        if ($configured_password === null) {
            return false;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? null;

        if (! is_string($cookie) || $cookie === '') {
            return false;
        }

        $parts = explode('|', wp_unslash($cookie), 2);

        if (count($parts) !== 2 || ! ctype_digit($parts[0])) {
            return false;
        }

        $issued_at = (int) $parts[0];

        if ($issued_at < time() - self::SESSION_SECONDS) {
            return false;
        }

        return hash_equals($this->cookieSignature($issued_at, $configured_password), $parts[1]);
    }

    public function addNoindexMeta(): void
    {
        echo "<meta name=\"robots\" content=\"noindex,nofollow\">\n";
    }

    public function sendNoindexHeader(): void
    {
        header('X-Robots-Tag: noindex, nofollow', false);
    }

    private function isProtectedFrontendRequest(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        return true;
    }

    private function isLoginAttempt(): bool
    {
        return isset($_POST['mae_storm_recipes_password']);
    }

    private function handleLoginAttempt(): ?string
    {
        if ($this->isLockedOut()) {
            return __('Too many failed attempts. Try again in 30 minutes.', 'mae-storm-recipes');
        }

        $nonce = $_POST[self::NONCE] ?? '';

        if (! is_string($nonce) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), self::ACTION)) {
            $this->recordFailure();

            return __('The login form expired. Try again.', 'mae-storm-recipes');
        }

        $configured_password = $this->configuredPassword();

        if ($configured_password === null) {
            return __('Private access is not configured yet.', 'mae-storm-recipes');
        }

        $submitted_password = $_POST['mae_storm_recipes_password'] ?? '';

        if (! is_string($submitted_password) || ! hash_equals($configured_password, wp_unslash($submitted_password))) {
            $this->recordFailure();

            return __('Invalid password.', 'mae-storm-recipes');
        }

        $this->clearFailures();
        $this->setAccessCookie($configured_password);

        return null;
    }

    private function renderLogin(?string $error): void
    {
        if (! class_exists(\Timber\Timber::class)) {
            wp_die(
                esc_html__('Mae Storm Recipes requires Timber to render the private access screen.', 'mae-storm-recipes'),
                esc_html__('Mae Storm Recipes is not ready', 'mae-storm-recipes'),
                ['response' => 500]
            );
        }

        $context = [
            'login_action' => esc_url(home_url(add_query_arg([]))),
            'login_error' => $error,
            'login_nonce' => wp_nonce_field(self::ACTION, self::NONCE, true, false),
        ];

        /**
         * Filters the Timber template used for the private frontend login screen.
         *
         * The Mae Storm Recipes theme provides the default template in its
         * registered Timber locations.
         *
         * @param string $template Template name.
         */
        $template = (string) apply_filters('mae_storm_recipes_private_login_template', 'private-login.twig');
        $context = array_merge(\Timber\Timber::context(), $context);

        \Timber\Timber::render($template, $context);
    }

    private function configuredPassword(): ?string
    {
        if (defined(self::PASSWORD_CONSTANT) && is_string(constant(self::PASSWORD_CONSTANT))) {
            $password = constant(self::PASSWORD_CONSTANT);
        } else {
            $password = getenv(self::PASSWORD_ENV);
        }

        if (! is_string($password) || $password === '') {
            return null;
        }

        return $password;
    }

    private function setAccessCookie(string $configured_password): void
    {
        $issued_at = time();
        $value = $issued_at . '|' . $this->cookieSignature($issued_at, $configured_password);

        setcookie(self::COOKIE, $value, [
            'expires' => $issued_at + self::SESSION_SECONDS,
            'path' => COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::COOKIE] = $value;
    }

    private function cookieSignature(int $issued_at, string $configured_password): string
    {
        return hash_hmac('sha256', (string) $issued_at, wp_salt('auth') . $configured_password);
    }

    private function redirectAfterLogin(): void
    {
        wp_safe_redirect(home_url(add_query_arg([])));
        exit;
    }

    private function isLockedOut(): bool
    {
        return (bool) get_transient($this->lockoutKey());
    }

    private function recordFailure(): void
    {
        $key = $this->failureKey();
        $failures = (int) get_transient($key) + 1;

        set_transient($key, $failures, self::LOCKOUT_SECONDS);

        if ($failures >= self::LOCKOUT_FAILURES) {
            set_transient($this->lockoutKey(), 1, self::LOCKOUT_SECONDS);
        }
    }

    private function clearFailures(): void
    {
        delete_transient($this->failureKey());
        delete_transient($this->lockoutKey());
    }

    private function failureKey(): string
    {
        return 'mae_storm_recipes_failures_' . md5($this->clientIp());
    }

    private function lockoutKey(): string
    {
        return 'mae_storm_recipes_lockout_' . md5($this->clientIp());
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        return is_string($ip) ? $ip : 'unknown';
    }
}
