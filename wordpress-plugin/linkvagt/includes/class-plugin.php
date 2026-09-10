<?php

declare(strict_types=1);

namespace LinkVagt;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ((string) get_option('linkvagt_schema_version') !== Schema::VERSION) {
            Schema::install();
            update_option('linkvagt_schema_version', Schema::VERSION, false);
        }
        Auth::instance()->register();
        Backup::instance()->register();
        WordPress_Service::instance()->register();
        Scanner::instance()->register();
        Scheduler::instance()->register();
        Reporter::instance()->register();
        (new Rest())->register();
        add_action('template_redirect', [$this, 'protect_app_page']);
        add_filter('template_include', [$this, 'app_template'], 99);
        add_filter('show_admin_bar', [$this, 'maybe_hide_admin_bar']);
        add_action('admin_post_linkvagt_export', [$this, 'export_csv']);
    }

    public function protect_app_page(): void
    {
        if (!$this->is_app_page()) {
            return;
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('X-Robots-Tag: noindex, nofollow, noarchive');

        // Brugeren kan være logget ind i WordPress uden at have været forbi
        // wp_login-hooket i denne session. Provisioneringen er idempotent.
        if (is_user_logged_in()) {
            Auth::instance()->provision(wp_get_current_user());
        }
    }

    public function app_template(string $template): string
    {
        if ($this->is_app_page()) {
            return LINKVAGT_DIR . 'templates/app.php';
        }
        return $template;
    }

    public function maybe_hide_admin_bar(bool $show): bool
    {
        return $this->is_app_page() ? false : $show;
    }

    public function export_csv(): void
    {
        if (!Access::can_access() || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'linkvagt_export')) {
            wp_die(esc_html__('Du har ikke adgang til eksporten.', 'linkvagt'), '', ['response' => 403]);
        }
        global $wpdb;
        $scan_id = absint($_GET['scan_id'] ?? 0);
        $scans = Schema::table('scans');
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$scans} WHERE id=%d", $scan_id))) {
            wp_die(esc_html__('Scanningen findes ikke.', 'linkvagt'), '', ['response' => 404]);
        }
        $findings = Schema::table('findings');
        $sources = Schema::table('finding_sources');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*,GROUP_CONCAT(s.source_url SEPARATOR ' | ') source_urls
             FROM {$findings} f LEFT JOIN {$sources} s ON s.finding_id=f.id
             WHERE f.scan_id=%d GROUP BY f.id ORDER BY f.id",
            $scan_id
        ), ARRAY_A) ?: [];
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="linkvagt-scan-' . $scan_id . '.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Type', 'Status', 'Destination', 'Slutadresse', 'Kildesider', 'Fejl'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($output, [$row['category'], $row['status_code'] ?: $row['error_type'], $row['destination_url'], $row['final_url'], $row['source_urls'], $row['error_message']], ';', '"', '');
        }
        fclose($output);
        exit;
    }

    private function is_app_page(): bool
    {
        $page_id = (int) get_option('linkvagt_page_id');
        return $page_id > 0 && is_page($page_id);
    }
}
