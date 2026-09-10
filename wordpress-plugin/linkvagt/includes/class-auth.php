<?php

declare(strict_types=1);

namespace LinkVagt;

use WP_REST_Server;
use WP_User;

/**
 * LinkVagt bruger WordPress' eget login. Denne klasse oversætter en indlogget
 * WordPress-bruger til et LinkVagt-medlemskab og holder capabilities i sync.
 * Selve legitimationskontrollen — herunder to-faktor — ligger i wp-login.php.
 */
final class Auth
{
    private const PROVIDER = 'wordpress';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_login', [$this, 'on_login'], 10, 2);
    }

    public function routes(): void
    {
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

    public function login_url(): string
    {
        return wp_login_url($this->app_url());
    }

    public function logout_url(): string
    {
        return wp_logout_url($this->app_url());
    }

    public function app_url(): string
    {
        $page_id = (int) get_option('linkvagt_page_id');
        return $page_id ? (string) get_permalink($page_id) : home_url('/linkvagt/');
    }

    public function on_login(string $login, WP_User $user): void
    {
        if (!$this->provision($user)) {
            return;
        }
        $this->record_login((int) $user->ID);
        $this->audit('auth.login_succeeded', [], (int) $user->ID);
    }

    /**
     * Idempotent. Opretter eller opdaterer medlemskabet for en WordPress-bruger,
     * der er berettiget til adgang, og synkroniserer capabilities. Returnerer
     * true, når brugeren har et aktivt medlemskab bagefter.
     */
    public function provision(WP_User $user): bool
    {
        if ((int) $user->ID <= 0) {
            return false;
        }

        global $wpdb;
        $members = Schema::table('members');
        $email = strtolower(sanitize_email((string) $user->user_email));
        $name = sanitize_text_field((string) $user->display_name) ?: $email;
        $now = current_time('mysql', true);

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$members} WHERE wp_user_id=%d",
            (int) $user->ID
        ), ARRAY_A);

        if ($member) {
            if ((string) $member['status'] !== 'active') {
                $this->revoke_capabilities($user);
                return false;
            }
            $this->sync_capabilities($user, (string) $member['role']);
            if (strtolower((string) $member['email']) !== $email || (string) $member['display_name'] !== $name) {
                $wpdb->update($members, [
                    'email' => $email,
                    'display_name' => $name,
                    'updated_at' => $now,
                ], ['id' => (int) $member['id']]);
            }
            return true;
        }

        $entitlement = $this->entitlement($user, $email);
        if ($entitlement === null) {
            $this->revoke_capabilities($user);
            return false;
        }

        $wpdb->insert($members, [
            'wp_user_id' => (int) $user->ID,
            'provider' => self::PROVIDER,
            'provider_subject' => (string) $user->ID,
            'email' => $email,
            'display_name' => $name,
            'role' => $entitlement['role'],
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$wpdb->insert_id) {
            $this->audit('auth.member_creation_failed', [], (int) $user->ID);
            return false;
        }

        if ($entitlement['invite_id']) {
            $wpdb->update(
                Schema::table('invites'),
                ['accepted_at' => $now],
                ['id' => $entitlement['invite_id']]
            );
        }
        $this->sync_capabilities($user, $entitlement['role']);
        $this->audit('auth.member_provisioned', ['role' => $entitlement['role']], (int) $user->ID);
        return true;
    }

    public function status(): array
    {
        $user = wp_get_current_user();
        return [
            'provider' => self::PROVIDER,
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

    /**
     * Afgør om en WordPress-bruger uden medlemskab er berettiget til et.
     *
     * @return array{role: string, invite_id: int|null}|null
     */
    private function entitlement(WP_User $user, string $email): ?array
    {
        if ($email !== '' && hash_equals(Access::owner_email(), $email)) {
            return ['role' => 'owner', 'invite_id' => null];
        }

        global $wpdb;
        $invite = $wpdb->get_row($wpdb->prepare(
            'SELECT id, role FROM ' . Schema::table('invites') . '
             WHERE email=%s AND accepted_at IS NULL AND expires_at>UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1',
            $email
        ), ARRAY_A);
        if ($invite) {
            return [
                'role' => sanitize_key((string) $invite['role']) ?: 'viewer',
                'invite_id' => (int) $invite['id'],
            ];
        }

        // Bootstrap: den første WordPress-administrator, der åbner LinkVagt,
        // bliver ejer. Betingelsen lukker sig selv, så snart der findes ét
        // medlem. En administrator kan i forvejen redigere pluginets kode, så
        // det er ingen rettighedsudvidelse.
        if ($this->members_table_is_empty() && user_can($user, 'manage_options')) {
            return ['role' => 'owner', 'invite_id' => null];
        }

        return null;
    }

    private function members_table_is_empty(): bool
    {
        global $wpdb;
        return 0 === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('members'));
    }

    /** @return list<string> */
    private function capabilities_for_role(string $role): array
    {
        $capabilities = [Access::CAP_ACCESS];
        if (in_array($role, ['owner', 'administrator', 'editor'], true)) {
            $capabilities[] = Access::CAP_SCAN;
            $capabilities[] = Access::CAP_REPAIR;
        }
        if (in_array($role, ['owner', 'administrator'], true)) {
            $capabilities[] = Access::CAP_SETTINGS;
        }
        if ($role === 'owner') {
            $capabilities[] = Access::CAP_MEMBERS;
        }
        return $capabilities;
    }

    /**
     * Skriver kun til usermeta, når noget faktisk mangler eller er for meget,
     * så en sidevisning ikke koster en skrivning. Håndterer også nedgradering.
     */
    private function sync_capabilities(WP_User $user, string $role): void
    {
        $desired = $this->capabilities_for_role($role);
        foreach (Access::all_capabilities() as $capability) {
            $should_have = in_array($capability, $desired, true);
            $granted = isset($user->caps[$capability]) && $user->caps[$capability];
            if ($should_have && !$granted) {
                $user->add_cap($capability);
            } elseif (!$should_have && isset($user->caps[$capability])) {
                $user->remove_cap($capability);
            }
        }
    }

    private function revoke_capabilities(WP_User $user): void
    {
        foreach (Access::all_capabilities() as $capability) {
            if (isset($user->caps[$capability])) {
                $user->remove_cap($capability);
            }
        }
    }

    private function record_login(int $wp_user_id): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(Schema::table('members'), [
            'last_login_at' => $now,
            'updated_at' => $now,
        ], ['wp_user_id' => $wp_user_id]);
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
