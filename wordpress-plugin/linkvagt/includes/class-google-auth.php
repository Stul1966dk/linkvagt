<?php

declare(strict_types=1);

namespace LinkVagt;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Google_Auth
{
    private const STATE_TTL = 600;
    private const COOKIE = 'linkvagt_oauth_binding';
    private const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_TOKEN_INFO = 'https://oauth2.googleapis.com/tokeninfo';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('linkvagt/v1', '/auth/google/start', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'start'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('linkvagt/v1', '/auth/google/callback', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'callback'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('linkvagt/v1', '/auth/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('linkvagt/v1', '/auth/logout', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'logout'],
            'permission_callback' => static fn (): bool => Access::can_access(),
        ]);
    }

    public function configured(): bool
    {
        return $this->client_id() !== '' && $this->client_secret() !== '';
    }

    public function login_url(): string
    {
        return rest_url('linkvagt/v1/auth/google/start');
    }

    public function start(): WP_REST_Response|WP_Error
    {
        if (!$this->configured()) {
            return new WP_Error(
                'linkvagt_google_not_configured',
                'Google-login er endnu ikke konfigureret.',
                ['status' => 503]
            );
        }
        if (!$this->allow_attempt()) {
            return new WP_Error(
                'linkvagt_login_rate_limited',
                'For mange loginforsøg. Vent nogle minutter og prøv igen.',
                ['status' => 429]
            );
        }

        $state = $this->random_token(32);
        $nonce = $this->random_token(32);
        $verifier = $this->random_token(64);
        $binding = $this->random_token(32);
        $challenge = $this->base64url(hash('sha256', $verifier, true));

        set_transient('linkvagt_oidc_' . hash('sha256', $state), [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'binding_hash' => hash('sha256', $binding),
            'created_at' => time(),
        ], self::STATE_TTL);
        $this->set_binding_cookie($binding, time() + self::STATE_TTL);

        $url = add_query_arg([
            'client_id' => $this->client_id(),
            'redirect_uri' => $this->redirect_uri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], self::GOOGLE_AUTH);

        return new WP_REST_Response(null, 302, [
            'Location' => esc_url_raw($url),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function callback(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->configured()) {
            return $this->auth_error('Google-login er ikke konfigureret.');
        }
        if ($request->get_param('error')) {
            return $this->auth_error('Google-login blev afbrudt eller afvist.');
        }

        $state = (string) $request->get_param('state');
        $code = (string) $request->get_param('code');
        if ($state === '' || $code === '') {
            return $this->auth_error('Login-svaret mangler påkrævede oplysninger.');
        }
        $transient_key = 'linkvagt_oidc_' . hash('sha256', $state);
        $attempt = get_transient($transient_key);
        delete_transient($transient_key);

        $binding = isset($_COOKIE[self::COOKIE])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE]))
            : '';
        $this->set_binding_cookie('', time() - HOUR_IN_SECONDS);

        if (
            !is_array($attempt)
            || !isset($attempt['binding_hash'], $attempt['verifier'], $attempt['nonce'], $attempt['created_at'])
            || time() - (int) $attempt['created_at'] > self::STATE_TTL
            || $binding === ''
            || !hash_equals((string) $attempt['binding_hash'], hash('sha256', $binding))
        ) {
            $this->audit('auth.state_rejected');
            return $this->auth_error('Loginforsøget er udløbet eller kunne ikke valideres.');
        }

        $token_response = wp_safe_remote_post(self::GOOGLE_TOKEN, [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'code' => $code,
                'client_id' => $this->client_id(),
                'client_secret' => $this->client_secret(),
                'redirect_uri' => $this->redirect_uri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => (string) $attempt['verifier'],
            ],
        ]);
        if (is_wp_error($token_response)) {
            $this->audit('auth.token_exchange_failed');
            return $this->auth_error('Google kunne ikke kontaktes. Prøv igen.');
        }
        $tokens = json_decode((string) wp_remote_retrieve_body($token_response), true);
        if (wp_remote_retrieve_response_code($token_response) !== 200 || empty($tokens['id_token'])) {
            $this->audit('auth.token_exchange_rejected');
            return $this->auth_error('Google afviste loginforsøget.');
        }

        $claims = $this->verify_id_token((string) $tokens['id_token'], (string) $attempt['nonce']);
        if (is_wp_error($claims)) {
            $this->audit('auth.id_token_rejected', ['reason' => $claims->get_error_code()]);
            return $this->auth_error($claims->get_error_message());
        }

        $user_id = $this->resolve_member($claims);
        if (is_wp_error($user_id)) {
            $this->audit('auth.identity_rejected', ['reason' => $user_id->get_error_code()]);
            return $this->auth_error($user_id->get_error_message());
        }

        wp_set_current_user($user_id);
        // Do not clear cookies immediately before setting the new session.
        // Some reverse proxies collapse duplicate Set-Cookie headers and may
        // retain the expired cookie emitted by wp_clear_auth_cookie().
        wp_set_auth_cookie($user_id, false, is_ssl());
        $this->record_login($user_id);
        $this->audit('auth.login_succeeded', [], $user_id);

        return new WP_REST_Response(null, 302, [
            'Location' => esc_url_raw(add_query_arg('linkvagt_login', 'success', $this->app_url())),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function status(): array
    {
        $user = wp_get_current_user();
        return [
            'configured' => $this->configured(),
            'authenticated' => Access::can_access(),
            'wordpress_authenticated' => is_user_logged_in(),
            'environment' => wp_get_environment_type(),
            'user' => Access::can_access() ? [
                'email' => (string) $user->user_email,
                'name' => (string) $user->display_name,
            ] : null,
        ];
    }

    public function logout(): array
    {
        $user_id = get_current_user_id();
        $this->audit('auth.logout', [], $user_id ?: null);
        wp_logout();

        return [
            'login_url' => add_query_arg('linkvagt_logout', 'success', $this->app_url()),
        ];
    }

    private function verify_id_token(string $id_token, string $expected_nonce): array|WP_Error
    {
        $url = add_query_arg(['id_token' => $id_token], self::GOOGLE_TOKEN_INFO);
        $response = wp_safe_remote_get($url, [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('token_verification_failed', 'Google-identiteten kunne ikke valideres.');
        }
        $claims = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($claims)) {
            return new WP_Error('invalid_claims', 'Google returnerede et ugyldigt login-svar.');
        }

        $issuer = (string) ($claims['iss'] ?? '');
        $audience = (string) ($claims['aud'] ?? '');
        $expires = (int) ($claims['exp'] ?? 0);
        $issued = (int) ($claims['iat'] ?? 0);
        $nonce = (string) ($claims['nonce'] ?? '');
        $verified = $claims['email_verified'] ?? false;
        $email_verified = $verified === true || $verified === 'true' || $verified === '1';

        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            return new WP_Error('invalid_issuer', 'Google-loginets udsteder er ugyldig.');
        }
        if (!hash_equals($this->client_id(), $audience)) {
            return new WP_Error('invalid_audience', 'Google-loginet er udstedt til en anden applikation.');
        }
        if ($expires < time() - 30 || $issued > time() + 120) {
            return new WP_Error('expired_token', 'Google-loginet er udløbet.');
        }
        if ($nonce === '' || !hash_equals($expected_nonce, $nonce)) {
            return new WP_Error('invalid_nonce', 'Google-loginet kunne ikke beskyttes mod genbrug.');
        }
        if (!$email_verified || empty($claims['email']) || empty($claims['sub'])) {
            return new WP_Error('unverified_email', 'Google-mailadressen er ikke verificeret.');
        }
        return $claims;
    }

    private function resolve_member(array $claims): int|WP_Error
    {
        global $wpdb;
        $email = strtolower(sanitize_email((string) $claims['email']));
        $subject = sanitize_text_field((string) $claims['sub']);
        $name = sanitize_text_field((string) ($claims['name'] ?? $email));
        $members = Schema::table('members');

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$members} WHERE provider='google' AND provider_subject=%s",
            $subject
        ), ARRAY_A);
        if ($member) {
            if ($member['status'] !== 'active' || strtolower((string) $member['email']) !== $email) {
                return new WP_Error('member_inactive', 'Denne LinkVagt-konto er ikke aktiv.');
            }
            return (int) $member['wp_user_id'];
        }

        $is_owner = hash_equals(Access::owner_email(), $email);
        $invite = null;
        if (!$is_owner) {
            $invites = Schema::table('invites');
            $invite = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$invites}
                 WHERE email=%s AND accepted_at IS NULL AND expires_at>UTC_TIMESTAMP()
                 ORDER BY id DESC LIMIT 1",
                $email
            ), ARRAY_A);
            if (!$invite) {
                return new WP_Error('not_invited', 'Denne Google-konto er ikke inviteret til LinkVagt.');
            }
        }

        $wp_user = get_user_by('email', $email);
        if (!$wp_user) {
            $user_id = wp_insert_user([
                'user_login' => $this->unique_login($email),
                'user_email' => $email,
                'display_name' => $name,
                'user_pass' => wp_generate_password(64, true, true),
                'role' => 'subscriber',
            ]);
            if (is_wp_error($user_id)) {
                return new WP_Error('user_creation_failed', 'LinkVagt-brugeren kunne ikke oprettes.');
            }
            $wp_user = get_user_by('id', (int) $user_id);
        }
        if (!$wp_user) {
            return new WP_Error('user_missing', 'LinkVagt-brugeren kunne ikke indlæses.');
        }

        $role = $is_owner ? 'owner' : sanitize_key((string) $invite['role']);
        $this->grant_role_capabilities($wp_user, $role);
        $now = current_time('mysql', true);
        $wpdb->insert($members, [
            'wp_user_id' => (int) $wp_user->ID,
            'provider' => 'google',
            'provider_subject' => $subject,
            'email' => $email,
            'display_name' => $name,
            'role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$wpdb->insert_id) {
            return new WP_Error('member_creation_failed', 'LinkVagt-medlemskabet kunne ikke oprettes.');
        }
        if ($invite) {
            $wpdb->update(Schema::table('invites'), ['accepted_at' => $now], ['id' => (int) $invite['id']]);
        }
        return (int) $wp_user->ID;
    }

    private function grant_role_capabilities(\WP_User $user, string $role): void
    {
        $user->add_cap(Access::CAP_ACCESS);
        if (in_array($role, ['owner', 'administrator', 'editor'], true)) {
            $user->add_cap(Access::CAP_SCAN);
        }
        if (in_array($role, ['owner', 'administrator', 'editor'], true)) {
            $user->add_cap(Access::CAP_REPAIR);
        }
        if (in_array($role, ['owner', 'administrator'], true)) {
            $user->add_cap(Access::CAP_SETTINGS);
        }
        if ($role === 'owner') {
            $user->add_cap(Access::CAP_MEMBERS);
        }
    }

    private function unique_login(string $email): string
    {
        $base = sanitize_user((string) strstr($email, '@', true), true) ?: 'linkvagt';
        $login = $base;
        $suffix = 1;
        while (username_exists($login)) {
            $login = $base . '-' . ++$suffix;
        }
        return $login;
    }

    private function record_login(int $wp_user_id): void
    {
        global $wpdb;
        $wpdb->update(Schema::table('members'), [
            'last_login_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['wp_user_id' => $wp_user_id]);
    }

    private function allow_attempt(): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'linkvagt_login_rate_' . hash_hmac('sha256', $ip, wp_salt('auth'));
        $attempts = (int) get_transient($key);
        if ($attempts >= 10) {
            return false;
        }
        set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
        return true;
    }

    private function set_binding_cookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function client_id(): string
    {
        return defined('LINKVAGT_GOOGLE_CLIENT_ID')
            ? trim((string) LINKVAGT_GOOGLE_CLIENT_ID)
            : '';
    }

    private function client_secret(): string
    {
        return defined('LINKVAGT_GOOGLE_CLIENT_SECRET')
            ? trim((string) LINKVAGT_GOOGLE_CLIENT_SECRET)
            : '';
    }

    private function redirect_uri(): string
    {
        if (defined('LINKVAGT_GOOGLE_REDIRECT_URI')) {
            return esc_url_raw((string) LINKVAGT_GOOGLE_REDIRECT_URI);
        }
        return rest_url('linkvagt/v1/auth/google/callback');
    }

    private function app_url(): string
    {
        $page_id = (int) get_option('linkvagt_page_id');
        return $page_id ? (string) get_permalink($page_id) : home_url('/linkvagt/');
    }

    private function auth_error(string $message): WP_REST_Response
    {
        $url = add_query_arg('linkvagt_login_error', rawurlencode($message), $this->app_url());
        return new WP_REST_Response(null, 302, [
            'Location' => esc_url_raw($url),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function random_token(int $bytes): string
    {
        return $this->base64url(random_bytes($bytes));
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function audit(string $action, array $metadata = [], ?int $wp_user_id = null): void
    {
        global $wpdb;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $member_id = null;
        if ($wp_user_id) {
            $member_id = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . Schema::table('members') . ' WHERE wp_user_id=%d',
                $wp_user_id
            ));
        }
        $wpdb->insert(Schema::table('audit_log'), [
            'member_id' => $member_id ? (int) $member_id : null,
            'action' => $action,
            'object_type' => 'authentication',
            'object_id' => null,
            'ip_hash' => $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : null,
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql', true),
        ]);
    }
}
