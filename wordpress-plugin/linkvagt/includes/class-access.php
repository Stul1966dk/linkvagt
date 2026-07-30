<?php

declare(strict_types=1);

namespace LinkVagt;

final class Access
{
    public const CAP_ACCESS = 'linkvagt_access';
    public const CAP_SCAN = 'linkvagt_run_scans';
    public const CAP_REPAIR = 'linkvagt_repair_links';
    public const CAP_SETTINGS = 'linkvagt_manage_settings';
    public const CAP_MEMBERS = 'linkvagt_manage_members';

    public static function install_capabilities(): void
    {
        $administrator = get_role('administrator');
        if (!$administrator) {
            return;
        }

        foreach (self::all_capabilities() as $capability) {
            $administrator->add_cap($capability);
        }
    }

    /** @return list<string> */
    public static function all_capabilities(): array
    {
        return [
            self::CAP_ACCESS,
            self::CAP_SCAN,
            self::CAP_REPAIR,
            self::CAP_SETTINGS,
            self::CAP_MEMBERS,
        ];
    }

    public static function owner_email(): string
    {
        if (defined('LINKVAGT_OWNER_EMAIL')) {
            return strtolower(trim((string) LINKVAGT_OWNER_EMAIL));
        }
        return strtolower((string) get_option('linkvagt_owner_email', 'stig@su-media.dk'));
    }

    public static function can_access(): bool
    {
        if (!is_user_logged_in() || !current_user_can(self::CAP_ACCESS)) {
            return false;
        }

        $user = wp_get_current_user();
        if (self::is_active_member((int) $user->ID)) {
            return true;
        }

        // Local keeps a narrow bootstrap path while Google cannot redirect to a
        // .local domain. This branch can never authorize production.
        return wp_get_environment_type() === 'local'
            && strtolower((string) $user->user_email) === self::owner_email();
    }

    public static function is_active_member(int $wp_user_id): bool
    {
        global $wpdb;
        $table = Schema::table('members');
        return 'active' === $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$table} WHERE wp_user_id = %d", $wp_user_id)
        );
    }
}
