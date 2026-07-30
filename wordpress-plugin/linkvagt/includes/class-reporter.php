<?php

declare(strict_types=1);

namespace LinkVagt;

final class Reporter
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_action('linkvagt_scan_completed', [$this, 'send_scan_report'], 10, 1);
        add_action('linkvagt_scheduled_batch_completed', [$this, 'send_batch_report'], 10, 2);
    }

    public function send_pending_manual_report(): void
    {
        global $wpdb;
        $scans = Schema::table('scans');
        $scan_id = (int) $wpdb->get_var(
            "SELECT id FROM {$scans}
             WHERE status='completed' AND scan_origin='manual' AND report_status IS NULL
             ORDER BY completed_at ASC LIMIT 1"
        );
        if ($scan_id > 0) {
            $this->send_scan_report($scan_id);
        }
    }

    public function send_scan_report(int $scan_id): void
    {
        global $wpdb;
        $global_settings = (array) get_option('linkvagt_mail_settings', []);
        if (($global_settings['mail_enabled'] ?? '1') !== '1') {
            $wpdb->update(Schema::table('scans'), ['report_status' => 'skipped'], ['id' => $scan_id]);
            return;
        }
        $scans = Schema::table('scans');
        $sites = Schema::table('sites');
        $scan = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*,w.name site_name,w.base_url,w.report_mode,w.report_recipients,
                    w.report_include_csv,w.report_include_redirects,w.report_include_warnings
             FROM {$scans} s INNER JOIN {$sites} w ON w.id=s.site_id WHERE s.id=%d",
            $scan_id
        ), ARRAY_A);
        if (!$scan || $scan['status'] !== 'completed') {
            return;
        }
        if ($scan['report_mode'] === 'off') {
            $wpdb->update($scans, ['report_status' => 'skipped'], ['id' => $scan_id]);
            return;
        }
        if (in_array((string) ($scan['report_status'] ?? ''), ['sent', 'skipped', 'deferred'], true)) {
            return;
        }
        if (($scan['scan_origin'] ?? 'manual') === 'scheduled') {
            $wpdb->update($scans, ['report_status' => 'deferred'], ['id' => $scan_id]);
            return;
        }

        $problem_count = (int) $scan['broken_count'] + (int) $scan['redirect_count'] + (int) $scan['warning_count'];
        if (($global_settings['mail_include_clean'] ?? '1') !== '1' && $problem_count === 0) {
            $wpdb->update($scans, ['report_status' => 'skipped'], ['id' => $scan_id]);
            return;
        }

        $configured_recipients = (string) ($global_settings['mail_to'] ?? $scan['report_recipients']);
        $recipients = array_values(array_unique(array_filter(array_map(
            'sanitize_email',
            preg_split('/[\s,;]+/', $configured_recipients) ?: []
        ), 'is_email')));
        if (!$recipients) {
            $recipients = [Access::owner_email()];
        }

        $comparison = $this->comparison($scan);
        $findings = $this->report_findings($scan);
        $subject = sprintf(
            'LinkVagt: %d døde, %d redirects – %s',
            (int) $scan['broken_count'],
            (int) $scan['redirect_count'],
            (string) $scan['site_name']
        );
        $html = $this->render($scan, $findings, $comparison);
        $attachments = [];
        $csv_path = null;
        if (!empty($scan['report_include_csv'])) {
            $csv_path = $this->csv_file($scan_id, $findings);
            if ($csv_path) {
                $attachments[] = $csv_path;
            }
        }

        $headers = [];
        $from = sanitize_email((string) ($global_settings['mail_from'] ?? ''));
        if ($from !== '') {
            $headers[] = 'From: LinkVagt <' . $from . '>';
        }
        $content_type = static fn (): string => 'text/html';
        add_filter('wp_mail_content_type', $content_type);
        try {
            $sent = wp_mail($recipients, $subject, $html, $headers, $attachments);
        } finally {
            remove_filter('wp_mail_content_type', $content_type);
            if ($csv_path && file_exists($csv_path)) {
                wp_delete_file($csv_path);
            }
        }

        $wpdb->update($scans, [
            'report_status' => $sent ? 'sent' : 'failed',
            'report_error' => $sent ? null : 'WordPress kunne ikke aflevere rapporten til mailtransporten.',
        ], ['id' => $scan_id]);
        $this->audit($sent ? 'report.sent' : 'report.failed', $scan_id, [
            'recipient_count' => count($recipients),
        ]);
    }

    public function send_batch_report(string $run_id, array $scan_ids): void
    {
        global $wpdb;
        $settings = (array) get_option('linkvagt_mail_settings', []);
        if (($settings['mail_enabled'] ?? '1') !== '1' || !$scan_ids) {
            return;
        }
        $ids = implode(',', array_map('intval', $scan_ids));
        $scans = Schema::table('scans');
        $sites = Schema::table('sites');
        $rows = $wpdb->get_results(
            "SELECT s.*,w.name site_name,w.base_url
             FROM {$scans} s INNER JOIN {$sites} w ON w.id=s.site_id
             WHERE s.id IN ({$ids}) AND s.scan_origin='scheduled' AND s.scheduled_run='" . esc_sql($run_id) . "'
             ORDER BY w.name",
            ARRAY_A
        ) ?: [];
        if (!$rows) {
            return;
        }
        $recipients = $this->recipients($settings);
        $totals = ['links' => 0, 'broken' => 0, 'redirects' => 0, 'warnings' => 0, 'failed' => 0, 'new' => 0, 'resolved' => 0];
        $table_rows = '';
        foreach ($rows as $scan) {
            $comparison = $scan['status'] === 'completed' ? $this->comparison($scan) : ['new' => 0, 'resolved' => 0];
            $totals['links'] += (int) $scan['links_checked'];
            $totals['broken'] += (int) $scan['broken_count'];
            $totals['redirects'] += (int) $scan['redirect_count'];
            $totals['warnings'] += (int) $scan['warning_count'];
            $totals['failed'] += $scan['status'] === 'failed' ? 1 : 0;
            $totals['new'] += (int) ($comparison['new'] ?? 0);
            $totals['resolved'] += (int) ($comparison['resolved'] ?? 0);
            $status = $scan['status'] === 'completed' ? 'Gennemført' : 'Fejlet';
            $table_rows .= '<tr>'
                . '<td style="padding:8px;border:1px solid #dbe3ea"><strong>' . esc_html((string) $scan['site_name']) . '</strong></td>'
                . '<td style="padding:8px;border:1px solid #dbe3ea">' . esc_html($status) . '</td>'
                . '<td style="padding:8px;border:1px solid #dbe3ea;text-align:right">' . (int) $scan['links_checked'] . '</td>'
                . '<td style="padding:8px;border:1px solid #dbe3ea;text-align:right">' . (int) $scan['broken_count'] . '</td>'
                . '<td style="padding:8px;border:1px solid #dbe3ea;text-align:right">' . (int) $scan['redirect_count'] . '</td>'
                . '<td style="padding:8px;border:1px solid #dbe3ea;text-align:right">' . (int) $scan['warning_count'] . '</td>'
                . '<td style="padding:8px;border:1px solid #dbe3ea;text-align:right">' . (int) ($comparison['new'] ?? 0) . '</td>'
                . '</tr>';
        }
        $page_id = (int) get_option('linkvagt_page_id');
        $app_url = $page_id ? get_permalink($page_id) : home_url('/linkvagt/');
        $subject = sprintf(
            'LinkVagt ugekontrol: %d døde, %d redirects på %d websites',
            $totals['broken'],
            $totals['redirects'],
            count($rows)
        );
        $html = '<div style="font-family:Arial,sans-serif;color:#172337;max-width:900px">'
            . '<h1>Samlet ugentlig LinkVagt-rapport</h1>'
            . '<p><strong>' . count($rows) . '</strong> websites og <strong>' . $totals['links'] . '</strong> links blev behandlet.</p>'
            . '<p><strong>' . $totals['broken'] . '</strong> døde, <strong>' . $totals['redirects'] . '</strong> redirects, <strong>'
            . $totals['warnings'] . '</strong> advarsler, <strong>' . $totals['new'] . '</strong> nye og <strong>'
            . $totals['resolved'] . '</strong> løste problemer.</p>'
            . ($totals['failed'] ? '<p style="color:#9b2c2c"><strong>' . $totals['failed'] . '</strong> scanninger fejlede og kræver kontrol.</p>' : '')
            . '<p><a style="display:inline-block;background:#166534;color:#fff;padding:10px 16px;border-radius:7px;text-decoration:none" href="'
            . esc_url($app_url) . '">Åbn LinkVagt</a></p>'
            . '<table style="border-collapse:collapse;width:100%"><thead><tr>'
            . '<th style="text-align:left;padding:8px;border:1px solid #dbe3ea">Website</th><th style="text-align:left;padding:8px;border:1px solid #dbe3ea">Status</th>'
            . '<th style="padding:8px;border:1px solid #dbe3ea">Links</th><th style="padding:8px;border:1px solid #dbe3ea">Døde</th>'
            . '<th style="padding:8px;border:1px solid #dbe3ea">Redirects</th><th style="padding:8px;border:1px solid #dbe3ea">Advarsler</th>'
            . '<th style="padding:8px;border:1px solid #dbe3ea">Nye</th></tr></thead><tbody>' . $table_rows . '</tbody></table></div>';
        $headers = [];
        $from = sanitize_email((string) ($settings['mail_from'] ?? ''));
        if ($from !== '') {
            $headers[] = 'From: LinkVagt <' . $from . '>';
        }
        $content_type = static fn (): string => 'text/html';
        add_filter('wp_mail_content_type', $content_type);
        try {
            $sent = wp_mail($recipients, $subject, $html, $headers);
        } finally {
            remove_filter('wp_mail_content_type', $content_type);
        }
        $wpdb->query(
            "UPDATE {$scans} SET report_status='" . ($sent ? 'sent' : 'failed') . "',
             report_error=" . ($sent ? 'NULL' : "'Samlerapporten kunne ikke afleveres.'") . "
             WHERE id IN ({$ids})"
        );
        $this->audit($sent ? 'batch_report.sent' : 'batch_report.failed', (int) end($scan_ids), [
            'run_id' => $run_id,
            'site_count' => count($rows),
            'recipient_count' => count($recipients),
        ]);
    }

    private function recipients(array $settings): array
    {
        $configured = (string) ($settings['mail_to'] ?? Access::owner_email());
        $recipients = array_values(array_unique(array_filter(array_map(
            'sanitize_email',
            preg_split('/[\s,;]+/', $configured) ?: []
        ), 'is_email')));
        return $recipients ?: [Access::owner_email()];
    }

    private function report_findings(array $scan): array
    {
        global $wpdb;
        $table = Schema::table('findings');
        $conditions = ["scan_id=%d", "category<>'ok'"];
        if (empty($scan['report_include_redirects'])) {
            $conditions[] = "category<>'redirect'";
        }
        if (empty($scan['report_include_warnings'])) {
            $conditions[] = "category<>'warning'";
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT destination_url,final_url,status_code,category,error_type,error_message
             FROM {$table} WHERE " . implode(' AND ', $conditions) . "
             ORDER BY FIELD(category,'broken','redirect','warning'),destination_url LIMIT 5000",
            (int) $scan['id']
        ), ARRAY_A) ?: [];
    }

    private function comparison(array $scan): array
    {
        global $wpdb;
        $scans = Schema::table('scans');
        $findings = Schema::table('findings');
        $previous_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$scans}
             WHERE site_id=%d AND status='completed' AND mode=%s
               AND COALESCE(target_url,'')=COALESCE(%s,'') AND id<%d
             ORDER BY id DESC LIMIT 1",
            (int) $scan['site_id'],
            (string) $scan['mode'],
            (string) ($scan['target_url'] ?? ''),
            (int) $scan['id']
        ));
        if (!$previous_id) {
            return ['new' => 0, 'resolved' => 0, 'has_previous' => false];
        }
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT destination_url FROM {$findings} WHERE scan_id=%d AND category<>'ok'",
            (int) $scan['id']
        ));
        $previous = $wpdb->get_col($wpdb->prepare(
            "SELECT destination_url FROM {$findings} WHERE scan_id=%d AND category<>'ok'",
            (int) $previous_id
        ));
        return [
            'new' => count(array_diff($current, $previous)),
            'resolved' => count(array_diff($previous, $current)),
            'has_previous' => true,
        ];
    }

    private function render(array $scan, array $findings, array $comparison): string
    {
        $rows = '';
        foreach (array_slice($findings, 0, 100) as $finding) {
            $status = $finding['status_code'] ?: ($finding['error_type'] ?: 'Netværk');
            $rows .= '<tr>'
                . '<td style="padding:7px;border:1px solid #dbe3ea">' . esc_html((string) $finding['category']) . '</td>'
                . '<td style="padding:7px;border:1px solid #dbe3ea">' . esc_html((string) $status) . '</td>'
                . '<td style="padding:7px;border:1px solid #dbe3ea"><a href="' . esc_url((string) $finding['destination_url']) . '">' . esc_html((string) $finding['destination_url']) . '</a></td>'
                . '<td style="padding:7px;border:1px solid #dbe3ea">' . esc_html((string) ($finding['final_url'] ?: '–')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" style="padding:12px;border:1px solid #dbe3ea">Ingen problemer fundet.</td></tr>';
        }
        $comparison_text = $comparison['has_previous']
            ? sprintf('%d nye problemer og %d løste siden sidste tilsvarende scanning.', $comparison['new'], $comparison['resolved'])
            : 'Dette er den første tilsvarende scanning.';
        $page_id = (int) get_option('linkvagt_page_id');
        $app_url = $page_id ? get_permalink($page_id) : home_url('/linkvagt/');
        $duration = '';
        if ($scan['started_at'] && $scan['completed_at']) {
            $seconds = max(0, strtotime((string) $scan['completed_at']) - strtotime((string) $scan['started_at']));
            $duration = sprintf(' Varighed: %d min. %d sek.', intdiv($seconds, 60), $seconds % 60);
        }

        return '<div style="font-family:Arial,sans-serif;color:#172337;max-width:850px">'
            . '<h1>Linkrapport for ' . esc_html((string) $scan['site_name']) . '</h1>'
            . '<p><strong>' . (int) $scan['links_checked'] . '</strong> links på <strong>' . (int) $scan['pages_scanned'] . '</strong> sider blev kontrolleret.'
            . esc_html($duration) . '</p>'
            . '<p><strong>' . (int) $scan['broken_count'] . '</strong> døde, <strong>' . (int) $scan['redirect_count']
            . '</strong> redirects og <strong>' . (int) $scan['warning_count'] . '</strong> advarsler.</p>'
            . '<p>' . esc_html($comparison_text) . '</p>'
            . '<p><a style="display:inline-block;background:#166534;color:#fff;padding:10px 16px;border-radius:7px;text-decoration:none" href="'
            . esc_url(add_query_arg('scan_id', (int) $scan['id'], $app_url)) . '">Åbn resultat i LinkVagt</a></p>'
            . '<table style="border-collapse:collapse;width:100%"><thead><tr>'
            . '<th style="text-align:left;padding:7px;border:1px solid #dbe3ea">Type</th>'
            . '<th style="text-align:left;padding:7px;border:1px solid #dbe3ea">Status</th>'
            . '<th style="text-align:left;padding:7px;border:1px solid #dbe3ea">Destination</th>'
            . '<th style="text-align:left;padding:7px;border:1px solid #dbe3ea">Slutadresse</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . (count($findings) > 100 ? '<p>Mailen viser de første 100 fund. Se alle resultater i LinkVagt.</p>' : '')
            . '</div>';
    }

    private function csv_file(int $scan_id, array $findings): ?string
    {
        $path = wp_tempnam('linkvagt-' . $scan_id . '.csv');
        if (!$path) {
            return null;
        }
        $handle = fopen($path, 'wb');
        if (!$handle) {
            return null;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Type', 'Status', 'Destination', 'Slutadresse', 'Fejl'], ';', '"', '');
        foreach ($findings as $finding) {
            fputcsv($handle, [
                $finding['category'],
                $finding['status_code'] ?: $finding['error_type'],
                $finding['destination_url'],
                $finding['final_url'],
                $finding['error_message'],
            ], ';', '"', '');
        }
        fclose($handle);
        return $path;
    }

    private function audit(string $action, int $scan_id, array $metadata): void
    {
        global $wpdb;
        $wpdb->insert(Schema::table('audit_log'), [
            'member_id' => null,
            'action' => $action,
            'object_type' => 'scan',
            'object_id' => $scan_id,
            'ip_hash' => null,
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql', true),
        ]);
    }
}
