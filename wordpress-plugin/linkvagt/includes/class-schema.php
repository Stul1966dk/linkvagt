<?php

declare(strict_types=1);

namespace LinkVagt;

final class Schema
{
    public const VERSION = '7';

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'linkvagt_' . $name;
    }

    public static function activate(): void
    {
        self::install();
        Access::install_capabilities();
        self::ensure_app_page();
        update_option('linkvagt_schema_version', self::VERSION, false);
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sites = self::table('sites');
        $scans = self::table('scans');
        $findings = self::table('findings');
        $sources = self::table('finding_sources');
        $ignores = self::table('ignore_rules');
        $connections = self::table('connections');
        $changes = self::table('link_changes');
        $diagnostics = self::table('diagnostics');
        $jobs = self::table('jobs');
        $scan_pages = self::table('scan_pages');
        $scan_links = self::table('scan_links');
        $members = self::table('members');
        $invites = self::table('invites');
        $audit = self::table('audit_log');

        dbDelta("CREATE TABLE {$sites} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            base_url text NOT NULL,
            sitemap_urls longtext NOT NULL,
            excluded_domains longtext NOT NULL,
            max_pages int unsigned NOT NULL DEFAULT 5000,
            crawl_fallback tinyint unsigned NOT NULL DEFAULT 1,
            auto_frequency varchar(20) NOT NULL DEFAULT 'weekly',
            auto_next_date date NULL,
            report_mode varchar(20) NOT NULL DEFAULT 'always',
            report_recipients text NOT NULL,
            report_include_csv tinyint unsigned NOT NULL DEFAULT 0,
            report_include_redirects tinyint unsigned NOT NULL DEFAULT 1,
            report_include_warnings tinyint unsigned NOT NULL DEFAULT 1,
            last_scan_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$scans} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint unsigned NOT NULL,
            mode varchar(20) NOT NULL DEFAULT 'site',
            target_url text NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            scan_origin varchar(20) NOT NULL DEFAULT 'manual',
            scheduled_run char(36) NULL,
            pages_total int unsigned NOT NULL DEFAULT 0,
            pages_scanned int unsigned NOT NULL DEFAULT 0,
            links_checked int unsigned NOT NULL DEFAULT 0,
            broken_count int unsigned NOT NULL DEFAULT 0,
            redirect_count int unsigned NOT NULL DEFAULT 0,
            warning_count int unsigned NOT NULL DEFAULT 0,
            details_retained tinyint unsigned NOT NULL DEFAULT 1,
            error_message text NULL,
            report_status varchar(20) NULL,
            report_error text NULL,
            created_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY site_status (site_id,status),
            KEY scheduled_run (scheduled_run),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$findings} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint unsigned NOT NULL,
            site_id bigint unsigned NOT NULL,
            destination_url text NOT NULL,
            final_url text NULL,
            status_code smallint unsigned NULL,
            category varchar(20) NOT NULL,
            error_type varchar(60) NULL,
            error_message text NULL,
            redirect_chain longtext NULL,
            attempt_count tinyint unsigned NOT NULL DEFAULT 1,
            verification_status varchar(20) NOT NULL DEFAULT 'single',
            resolved_at datetime NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY scan_category (scan_id,category),
            KEY site_category (site_id,category)
        ) {$charset};");

        dbDelta("CREATE TABLE {$sources} (
            finding_id bigint unsigned NOT NULL,
            source_hash char(64) NOT NULL,
            source_url text NOT NULL,
            link_text text NULL,
            PRIMARY KEY  (finding_id,source_hash)
        ) {$charset};");

        dbDelta("CREATE TABLE {$ignores} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint unsigned NOT NULL,
            destination_hash char(64) NOT NULL,
            destination_url text NOT NULL,
            source_hash char(64) NOT NULL,
            source_url text NOT NULL,
            reason text NULL,
            expires_at datetime NULL,
            created_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY site_destination_source (site_id,destination_hash,source_hash)
        ) {$charset};");

        dbDelta("CREATE TABLE {$connections} (
            site_id bigint unsigned NOT NULL,
            username varchar(191) NOT NULL,
            encrypted_app_password longtext NOT NULL,
            verified_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (site_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$changes} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            site_id bigint unsigned NULL,
            finding_id bigint unsigned NULL,
            source_url text NOT NULL,
            post_type varchar(30) NOT NULL,
            post_id bigint unsigned NOT NULL,
            post_title text NULL,
            old_url text NOT NULL,
            new_url text NOT NULL,
            backup_content longtext NOT NULL,
            changed_content longtext NULL,
            before_hash char(64) NOT NULL,
            after_hash char(64) NULL,
            replacement_count int unsigned NOT NULL DEFAULT 0,
            source_link_text text NULL,
            old_anchor_text text NULL,
            new_anchor_text text NULL,
            anchor_replacement_count int unsigned NOT NULL DEFAULT 0,
            url_changed tinyint unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL,
            error_message text NULL,
            created_by bigint unsigned NULL,
            created_at datetime NOT NULL,
            applied_at datetime NULL,
            undone_at datetime NULL,
            PRIMARY KEY  (id),
            KEY site_created (site_id,created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$diagnostics} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint unsigned NULL,
            site_id bigint unsigned NULL,
            severity varchar(20) NOT NULL,
            event_type varchar(60) NOT NULL,
            url text NULL,
            message text NOT NULL,
            details longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY site_created (site_id,created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$jobs} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint unsigned NOT NULL,
            job_type varchar(30) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            payload longtext NOT NULL,
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            locked_at datetime NULL,
            lock_token char(36) NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY ready_jobs (status,available_at),
            KEY scan_status (scan_id,status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$scan_pages} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint unsigned NOT NULL,
            url_hash char(64) NOT NULL,
            page_url text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_url (scan_id,url_hash),
            KEY scan_status (scan_id,status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$scan_links} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint unsigned NOT NULL,
            url_hash char(64) NOT NULL,
            destination_url text NOT NULL,
            sources longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_url (scan_id,url_hash),
            KEY scan_status (scan_id,status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$members} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            wp_user_id bigint unsigned NOT NULL,
            provider varchar(30) NOT NULL DEFAULT 'wordpress',
            provider_subject varchar(191) NOT NULL,
            email varchar(191) NOT NULL,
            display_name varchar(191) NOT NULL,
            role varchar(30) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            last_login_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_identity (provider,provider_subject),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY email (email)
        ) {$charset};");

        dbDelta("CREATE TABLE {$invites} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            email varchar(191) NOT NULL,
            role varchar(30) NOT NULL,
            token_hash char(64) NOT NULL,
            invited_by bigint unsigned NOT NULL,
            expires_at datetime NOT NULL,
            accepted_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY email_status (email,accepted_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint unsigned NULL,
            action varchar(80) NOT NULL,
            object_type varchar(50) NULL,
            object_id bigint unsigned NULL,
            ip_hash char(64) NULL,
            metadata longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY member_created (member_id,created_at),
            KEY action_created (action,created_at)
        ) {$charset};");

        self::migrate_members_to_wordpress_provider();
    }

    /**
     * LinkVagt loggede tidligere ind via Google OIDC, hvor identiteten var
     * Googles subject-id. Efter skiftet til WordPress-login er identiteten
     * WordPress-bruger-id'et. Idempotent.
     */
    private static function migrate_members_to_wordpress_provider(): void
    {
        global $wpdb;
        $members = self::table('members');
        $wpdb->query(
            "UPDATE {$members} SET provider='wordpress', provider_subject=wp_user_id
             WHERE provider<>'wordpress'"
        );
    }

    private static function ensure_app_page(): void
    {
        $page_id = (int) get_option('linkvagt_page_id');
        if ($page_id > 0 && get_post($page_id)) {
            return;
        }

        $existing = get_page_by_path('linkvagt');
        if ($existing instanceof \WP_Post) {
            update_option('linkvagt_page_id', $existing->ID, false);
            return;
        }

        $page_id = wp_insert_post([
            'post_title' => 'LinkVagt',
            'post_name' => 'linkvagt',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
            'comment_status' => 'closed',
        ], true);

        if (!is_wp_error($page_id)) {
            update_option('linkvagt_page_id', (int) $page_id, false);
        }
    }
}
