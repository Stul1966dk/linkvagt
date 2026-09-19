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
        add_action('linkvagt_scan_completed', [$this, 'mark_scan_report'], 10, 1);
        add_action('linkvagt_scheduled_batch_completed', [$this, 'send_batch_report'], 10, 2);
    }

    /**
     * Statusmails sendes kun for planlagte scanninger, samlet i én mail, når
     * hele kørslen er færdig (send_batch_report). En enkelt scanning markeres
     * her: planlagte afventer samlerapporten, manuelle får aldrig en mail.
     */
    public function mark_scan_report(int $scan_id): void
    {
        global $wpdb;
        $scans = Schema::table('scans');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$scans}
             SET report_status=IF(scan_origin='scheduled','deferred','skipped')
             WHERE id=%d AND status='completed' AND report_status IS NULL",
            $scan_id
        ));
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
            "SELECT destination_url FROM {$findings} WHERE scan_id=%d AND category<>'ok' AND override IS NULL",
            (int) $scan['id']
        ));
        $previous = $wpdb->get_col($wpdb->prepare(
            "SELECT destination_url FROM {$findings} WHERE scan_id=%d AND category<>'ok' AND override IS NULL",
            (int) $previous_id
        ));
        return [
            'new' => count(array_diff($current, $previous)),
            'resolved' => count(array_diff($previous, $current)),
            'has_previous' => true,
        ];
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
