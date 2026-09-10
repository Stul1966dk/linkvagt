<?php

declare(strict_types=1);

namespace LinkVagt;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Rest
{
    private const NS = 'linkvagt/v1';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NS, '/summary', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'summary'],
            'permission_callback' => [$this, 'can_read'],
        ]);
        register_rest_route(self::NS, '/sites', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'sites'],
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_site'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/sites/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_site'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_site'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/sites/(?P<id>\d+)/scan', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'queue_scan'],
            'permission_callback' => [$this, 'can_scan'],
        ]);
        register_rest_route(self::NS, '/scans', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'scans'],
            'permission_callback' => [$this, 'can_read'],
        ]);
        register_rest_route(self::NS, '/findings', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'findings'],
            'permission_callback' => [$this, 'can_read'],
        ]);
        register_rest_route(self::NS, '/diagnostics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'diagnostics'],
            'permission_callback' => [$this, 'can_read'],
        ]);
        register_rest_route(self::NS, '/audit-log', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'audit_log'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/settings/mail', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'mail_settings'],
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'save_mail_settings'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/settings/mail/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_mail'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/settings/exclusions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'exclusion_settings'],
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'save_exclusion_settings'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/settings/schedule', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => function (): array {
                    $status = Scheduler::instance()->status();
                    if (!$this->can_manage()) {
                        unset($status['worker_url']);
                    }
                    return $status;
                },
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request): array|WP_Error => Scheduler::instance()->save_settings((array) $request->get_json_params()),
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/ignore', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_ignore'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/ignore/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_ignore'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/backups', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (): array => Backup::instance()->status(),
                'permission_callback' => [$this, 'can_read'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_backup'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
        register_rest_route(self::NS, '/backups/verify', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'verify_backup'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/backups/restore', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'restore_backup'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/system/process-next-job', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => function (): array {
                Scanner::instance()->process_next('manual');
                return ['processed' => true];
            },
            'permission_callback' => [$this, 'can_manage'],
        ]);
        register_rest_route(self::NS, '/system/worker', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): array => Scanner::instance()->external_tick(),
            'permission_callback' => fn (WP_REST_Request $request): bool => Scanner::instance()->authorize_worker($request),
        ]);
    }

    public function can_read(): bool
    {
        return Access::can_access();
    }

    public function can_manage(): bool
    {
        return Access::can_access() && current_user_can(Access::CAP_SETTINGS);
    }

    public function can_scan(): bool
    {
        return Access::can_access() && current_user_can(Access::CAP_SCAN);
    }

    public function create_backup(): array|WP_Error
    {
        try {
            return Backup::instance()->create('manual');
        } catch (\Throwable $error) {
            return new WP_Error('linkvagt_backup_failed', $error->getMessage(), ['status' => 500]);
        }
    }

    public function verify_backup(): array|WP_Error
    {
        try {
            return Backup::instance()->verify_latest();
        } catch (\Throwable $error) {
            return new WP_Error('linkvagt_backup_verify_failed', $error->getMessage(), ['status' => 500]);
        }
    }

    public function restore_backup(WP_REST_Request $request): array|WP_Error
    {
        try {
            return Backup::instance()->restore_latest((string) $request->get_param('confirmation'));
        } catch (\Throwable $error) {
            return new WP_Error('linkvagt_backup_restore_failed', $error->getMessage(), ['status' => 400]);
        }
    }

    public function summary(): array
    {
        global $wpdb;
        $sites = Schema::table('sites');
        $scans = Schema::table('scans');

        $site_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sites}");
        $latest = $wpdb->get_results(
            "SELECT s.*, w.name AS site_name
             FROM {$scans} s INNER JOIN {$sites} w ON w.id=s.site_id
             ORDER BY s.id DESC LIMIT 12",
            ARRAY_A
        );
        $links = Schema::table('scan_links');
        $pages = Schema::table('scan_pages');
        $jobs = Schema::table('jobs');
        $current = $wpdb->get_row(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM {$links} l WHERE l.scan_id=s.id) AS links_total,
                    (SELECT COUNT(*) FROM {$pages} p WHERE p.scan_id=s.id AND p.status IN ('completed','failed')) AS pages_processed,
                    (SELECT COUNT(*) FROM {$pages} p WHERE p.scan_id=s.id AND p.status='failed') AS pages_failed,
                    (SELECT MAX(j.updated_at) FROM {$jobs} j WHERE j.scan_id=s.id) AS activity_at
             FROM {$scans} s
             WHERE s.status IN ('queued','running') ORDER BY s.id LIMIT 1",
            ARRAY_A
        );
        $totals = $wpdb->get_row(
            "SELECT COALESCE(SUM(broken_count),0) broken,
                    COALESCE(SUM(redirect_count),0) redirects,
                    COALESCE(SUM(warning_count),0) warnings
             FROM {$scans}
             WHERE id IN (
                SELECT MAX(id) FROM {$scans}
                WHERE status='completed' AND mode='site' GROUP BY site_id
             )",
            ARRAY_A
        );

        return [
            'sites' => $site_count,
            'broken' => (int) ($totals['broken'] ?? 0),
            'redirects' => (int) ($totals['redirects'] ?? 0),
            'warnings' => (int) ($totals['warnings'] ?? 0),
            'latest' => array_map([$this, 'cast_scan'], $latest ?: []),
            'current' => $current ? $this->cast_scan($current) : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function sites(): array
    {
        global $wpdb;
        $sites = Schema::table('sites');
        $scans = Schema::table('scans');
        $connections = Schema::table('connections');
        $rows = $wpdb->get_results(
            "SELECT w.*,
                s.id latest_scan_id, s.status latest_scan_status,
                s.broken_count, s.redirect_count, s.warning_count, s.links_checked,
                r.id latest_result_scan_id, r.status latest_result_scan_status,
                r.completed_at last_checked_at,
                IF(c.site_id IS NULL,0,1) wordpress_configured,
                IF(c.verified_at IS NULL,0,1) wordpress_connected
             FROM {$sites} w
             LEFT JOIN {$scans} s ON s.id=(
                SELECT id FROM {$scans} WHERE site_id=w.id AND mode='site'
                ORDER BY id DESC LIMIT 1
             )
             LEFT JOIN {$scans} r ON r.id=(
                SELECT id FROM {$scans} WHERE site_id=w.id AND status='completed'
                ORDER BY id DESC LIMIT 1
             )
             LEFT JOIN {$connections} c ON c.site_id=w.id
             ORDER BY w.name",
            ARRAY_A
        );
        return array_map([$this, 'cast_site'], $rows ?: []);
    }

    public function create_site(WP_REST_Request $request): array|WP_Error
    {
        try {
            $data = $this->validate_site($request);
            if (is_wp_error($data)) {
                return $data;
            }
            global $wpdb;
            $now = current_time('mysql', true);
            $inserted = $wpdb->insert(Schema::table('sites'), [
                ...$data,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$inserted) {
                $detail = sanitize_text_field((string) $wpdb->last_error);
                error_log('[LinkVagt] Website could not be inserted: ' . $detail);
                return new WP_Error(
                    'linkvagt_db',
                    $detail !== '' ? 'Hjemmesiden kunne ikke gemmes: ' . $detail : 'Hjemmesiden kunne ikke gemmes.',
                    ['status' => 500]
                );
            }
            $this->audit('site.created', 'site', (int) $wpdb->insert_id);
            return $this->get_site((int) $wpdb->insert_id);
        } catch (\Throwable $error) {
            error_log('[LinkVagt] Website creation failed: ' . $error->getMessage());
            return new WP_Error(
                'linkvagt_site_creation_failed',
                'Hjemmesiden kunne ikke gemmes: ' . sanitize_text_field($error->getMessage()),
                ['status' => 500]
            );
        }
    }

    public function update_site(WP_REST_Request $request): array|WP_Error
    {
        $id = (int) $request['id'];
        if (!$this->site_exists($id)) {
            return new WP_Error('linkvagt_not_found', 'Hjemmesiden findes ikke.', ['status' => 404]);
        }
        $data = $this->validate_site($request);
        if (is_wp_error($data)) {
            return $data;
        }
        global $wpdb;
        $wpdb->update(Schema::table('sites'), [
            ...$data,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $id]);
        $this->audit('site.updated', 'site', $id);
        return $this->get_site($id);
    }

    public function delete_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request['id'];
        if (!$this->site_exists($id)) {
            return new WP_Error('linkvagt_not_found', 'Hjemmesiden findes ikke.', ['status' => 404]);
        }
        global $wpdb;
        // Child records are explicitly removed because dbDelta does not add foreign keys.
        foreach (['jobs', 'finding_sources', 'findings', 'scans', 'ignore_rules', 'connections', 'diagnostics'] as $child) {
            $table = Schema::table($child);
            if ($child === 'finding_sources') {
                $findings = Schema::table('findings');
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE finding_id IN (SELECT id FROM {$findings} WHERE site_id=%d)", $id));
            } elseif ($child === 'jobs') {
                $scans = Schema::table('scans');
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE scan_id IN (SELECT id FROM {$scans} WHERE site_id=%d)", $id));
            } else {
                $wpdb->delete($table, ['site_id' => $id]);
            }
        }
        $wpdb->delete(Schema::table('sites'), ['id' => $id]);
        $this->audit('site.deleted', 'site', $id);
        return new WP_REST_Response(null, 204);
    }

    public function queue_scan(WP_REST_Request $request): array|WP_Error
    {
        $site_id = (int) $request['id'];
        if (!$this->site_exists($site_id)) {
            return new WP_Error('linkvagt_not_found', 'Hjemmesiden findes ikke.', ['status' => 404]);
        }

        global $wpdb;
        $scans = Schema::table('scans');
        $duplicate = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$scans} WHERE site_id=%d AND status IN ('queued','running') LIMIT 1",
            $site_id
        ));
        if ($duplicate) {
            return new WP_Error('linkvagt_duplicate_scan', 'Der er allerede en scanning i kø eller i gang.', [
                'status' => 409,
                'scan_id' => (int) $duplicate,
            ]);
        }

        $mode = sanitize_key((string) ($request->get_param('mode') ?: 'site'));
        if (!in_array($mode, ['site', 'page', 'link'], true)) {
            $mode = 'site';
        }
        $target = trim((string) $request->get_param('target_url'));
        if ($mode !== 'site' && !wp_http_validate_url($target)) {
            return new WP_Error('linkvagt_invalid_url', 'En gyldig HTTP- eller HTTPS-adresse er påkrævet.', ['status' => 400]);
        }
        $now = current_time('mysql', true);
        $wpdb->insert($scans, [
            'site_id' => $site_id,
            'mode' => $mode,
            'target_url' => $target ?: null,
            'status' => 'queued',
            'scan_origin' => 'manual',
            'scheduled_run' => null,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
        ]);
        $scan_id = (int) $wpdb->insert_id;
        $wpdb->insert(Schema::table('jobs'), [
            'scan_id' => $scan_id,
            'job_type' => 'discover',
            'status' => 'queued',
            'payload' => wp_json_encode(['target_url' => $target]),
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Scanner::instance()->wake();
        $this->audit('scan.queued', 'scan', $scan_id);
        return ['scan_id' => $scan_id];
    }

    /** @return list<array<string,mixed>> */
    public function scans(WP_REST_Request $request): array
    {
        global $wpdb;
        $scans = Schema::table('scans');
        $sites = Schema::table('sites');
        $site_id = absint($request->get_param('site_id'));
        $where = $site_id ? $wpdb->prepare('WHERE s.site_id=%d', $site_id) : '';
        $rows = $wpdb->get_results(
            "SELECT s.*, w.name site_name FROM {$scans} s
             INNER JOIN {$sites} w ON w.id=s.site_id {$where}
             ORDER BY s.id DESC LIMIT 100",
            ARRAY_A
        );
        return array_map([$this, 'cast_scan'], $rows ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function findings(WP_REST_Request $request): array
    {
        global $wpdb;
        $scan_id = absint($request->get_param('scan_id'));
        if (!$scan_id) {
            return [];
        }
        $findings = Schema::table('findings');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$findings} WHERE scan_id=%d AND resolved_at IS NULL ORDER BY id LIMIT 5000",
            $scan_id
        ), ARRAY_A);
        if (!$rows) {
            return [];
        }

        // Sources and ignore rules are fetched once for the whole scan (in
        // small batches) instead of per finding, to avoid thousands of
        // separate queries on scans with many findings.
        $finding_ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $sources_table = Schema::table('finding_sources');
        $sources_by_finding = [];
        foreach (array_chunk($finding_ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $source_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT finding_id,source_url,link_text FROM {$sources_table}
                 WHERE finding_id IN ({$placeholders}) ORDER BY source_url",
                ...$chunk
            ), ARRAY_A) ?: [];
            foreach ($source_rows as $source_row) {
                $sources_by_finding[(int) $source_row['finding_id']][] = [
                    'source_url' => $source_row['source_url'],
                    'link_text' => $source_row['link_text'],
                ];
            }
        }

        $site_id = (int) $rows[0]['site_id'];
        $rules_table = Schema::table('ignore_rules');
        $rule_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$rules_table}
             WHERE site_id=%d AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY id",
            $site_id
        ), ARRAY_A) ?: [];
        $rules_by_destination = [];
        foreach ($rule_rows as $rule_row) {
            $rules_by_destination[(string) $rule_row['destination_hash']][] = $rule_row;
        }

        foreach ($rows as &$row) {
            foreach (['id', 'scan_id', 'site_id', 'status_code', 'attempt_count'] as $field) {
                $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
            }
            $row['redirect_chain'] = json_decode((string) ($row['redirect_chain'] ?? '[]'), true) ?: [];
            $row['sources'] = $sources_by_finding[(int) $row['id']] ?? [];

            $rules = $rules_by_destination[hash('sha256', (string) $row['destination_url'])] ?? [];
            $matched = null;
            foreach ($rules as $rule) {
                if ($rule['source_url'] === '' || array_filter(
                    $row['sources'],
                    static fn (array $source): bool => hash_equals((string) $rule['source_hash'], hash('sha256', (string) $source['source_url']))
                )) {
                    $matched = $rule;
                    break;
                }
            }
            $row['ignored'] = (bool) $matched;
            $row['ignore_rule_id'] = $matched ? (int) $matched['id'] : null;
        }
        unset($row);

        return $rows;
    }

    public function mail_settings(): array
    {
        $settings = (array) get_option('linkvagt_mail_settings', []);
        return [
            'mail_enabled' => (string) ($settings['mail_enabled'] ?? '1'),
            'mail_include_clean' => (string) ($settings['mail_include_clean'] ?? '1'),
            'mail_to' => (string) ($settings['mail_to'] ?? Access::owner_email()),
            'mail_from' => (string) ($settings['mail_from'] ?? ''),
            'transport' => 'wp_mail',
        ];
    }

    public function save_mail_settings(WP_REST_Request $request): array|WP_Error
    {
        $to = sanitize_email((string) $request->get_param('mail_to'));
        $from = sanitize_email((string) $request->get_param('mail_from'));
        if ($to === '' || !is_email($to)) {
            return new WP_Error('linkvagt_invalid_email', 'En gyldig rapportmodtager er påkrævet.', ['status' => 400]);
        }
        if ($from !== '' && !is_email($from)) {
            return new WP_Error('linkvagt_invalid_from', 'Afsenderadressen er ugyldig.', ['status' => 400]);
        }
        $settings = [
            'mail_enabled' => $request->get_param('mail_enabled') === '1' ? '1' : '0',
            'mail_include_clean' => $request->get_param('mail_include_clean') === '1' ? '1' : '0',
            'mail_to' => $to,
            'mail_from' => $from,
        ];
        update_option('linkvagt_mail_settings', $settings, false);
        $this->audit('settings.mail_updated');
        return [...$settings, 'transport' => 'wp_mail'];
    }

    public function test_mail(): array|WP_Error
    {
        $settings = $this->mail_settings();
        $headers = $settings['mail_from'] !== '' ? ['From: LinkVagt <' . $settings['mail_from'] . '>'] : [];
        $sent = wp_mail($settings['mail_to'], 'LinkVagt: Test af mailforbindelse', 'LinkVagt kan sende rapporter gennem WordPress og den konfigurerede SMTP-forbindelse.', $headers);
        if (!$sent) {
            return new WP_Error('linkvagt_mail_failed', 'WordPress kunne ikke aflevere testmailen.', ['status' => 502]);
        }
        $this->audit('settings.test_mail_sent');
        return ['sent' => true];
    }

    public function exclusion_settings(): array
    {
        return ['excluded_domains' => array_values((array) get_option('linkvagt_excluded_domains', []))];
    }

    public function save_exclusion_settings(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();
        $requested_domains = is_array($body) && array_key_exists('excluded_domains', $body)
            ? (array) $body['excluded_domains']
            : [];
        $domains = [];
        foreach ($requested_domains as $value) {
            $value = strtolower(trim((string) $value));
            $host = wp_parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
            $host = is_string($host) ? preg_replace('/^www\./', '', rtrim($host, '.')) : '';
            if ($host && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
                $domains[] = $host;
            }
        }
        $domains = array_values(array_unique($domains));
        sort($domains);
        update_option('linkvagt_excluded_domains', $domains, false);
        $removed = Scanner::instance()->purge_excluded_queued_links();
        $this->audit('settings.exclusions_updated');
        return ['excluded_domains' => $domains, 'queued_links_removed' => $removed];
    }

    public function create_ignore(WP_REST_Request $request): array|WP_Error
    {
        $site_id = absint($request->get_param('site_id'));
        $destination = esc_url_raw((string) $request->get_param('destination_url'), ['http', 'https']);
        $source = esc_url_raw((string) $request->get_param('source_url'), ['http', 'https']);
        if (!$this->site_exists($site_id) || !wp_http_validate_url($destination)) {
            return new WP_Error('linkvagt_invalid_ignore', 'Website og destination er påkrævet.', ['status' => 400]);
        }
        $expires = sanitize_text_field((string) $request->get_param('expires_at'));
        $expires_at = $expires !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? $expires . ' 23:59:59' : null;
        global $wpdb;
        $table = Schema::table('ignore_rules');
        $data = [
            'site_id' => $site_id,
            'destination_hash' => hash('sha256', $destination),
            'destination_url' => $destination,
            'source_hash' => hash('sha256', $source),
            'source_url' => $source,
            'reason' => sanitize_textarea_field((string) $request->get_param('reason')),
            'expires_at' => $expires_at,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ];
        $wpdb->replace($table, $data);
        $this->audit('ignore.created', 'ignore', (int) $wpdb->insert_id);
        return ['id' => (int) $wpdb->insert_id];
    }

    public function delete_ignore(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $id = (int) $request['id'];
        $deleted = $wpdb->delete(Schema::table('ignore_rules'), ['id' => $id]);
        if (!$deleted) {
            return new WP_Error('linkvagt_ignore_not_found', 'Ignoreringsreglen findes ikke.', ['status' => 404]);
        }
        $this->audit('ignore.deleted', 'ignore', $id);
        return new WP_REST_Response(null, 204);
    }

    public function diagnostics(WP_REST_Request $request): array
    {
        global $wpdb;
        $table = Schema::table('diagnostics');
        $sites = Schema::table('sites');
        $limit = min(250, max(10, absint($request->get_param('limit')) ?: 100));
        $site_id = absint($request->get_param('site_id'));
        $where = $site_id ? $wpdb->prepare('WHERE d.site_id=%d', $site_id) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, w.name AS site_name
             FROM {$table} d LEFT JOIN {$sites} w ON w.id=d.site_id
             {$where}
             ORDER BY d.id DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
        $counts = ['error' => 0, 'warning' => 0];
        foreach ($rows as $row) {
            if (isset($counts[$row['severity']])) {
                ++$counts[$row['severity']];
            }
        }
        return ['items' => $rows, 'counts' => $counts];
    }

    /** @return list<array<string,mixed>> */
    public function audit_log(WP_REST_Request $request): array
    {
        global $wpdb;
        $table = Schema::table('audit_log');
        $limit = min(200, max(10, absint($request->get_param('limit')) ?: 50));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, action, object_type, object_id, created_at
             FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['object_id'] = isset($row['object_id']) ? (int) $row['object_id'] : null;
        }
        unset($row);
        return $rows;
    }

    private function validate_site(WP_REST_Request $request): array|WP_Error
    {
        $base_url = esc_url_raw(trim((string) $request->get_param('base_url')), ['http', 'https']);
        if (!wp_http_validate_url($base_url)) {
            return new WP_Error('linkvagt_invalid_url', 'Website-adressen er ugyldig.', ['status' => 400]);
        }
        $sitemaps = array_values(array_filter(array_map(
            static fn ($url): string => esc_url_raw(trim((string) $url), ['http', 'https']),
            (array) $request->get_param('sitemap_urls')
        ), 'wp_http_validate_url'));
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') {
            $name = (string) wp_parse_url($base_url, PHP_URL_HOST);
        }
        $frequency = in_array((string) $request->get_param('auto_frequency'), ['weekly', 'biweekly', 'monthly', 'manual'], true)
            ? (string) $request->get_param('auto_frequency')
            : 'weekly';
        $next_date = sanitize_text_field((string) $request->get_param('auto_next_date'));
        if ($frequency !== 'manual') {
            $parsed_date = \DateTimeImmutable::createFromFormat('!Y-m-d', $next_date, wp_timezone());
            $date_errors = \DateTimeImmutable::getLastErrors();
            if (
                !$parsed_date
                || ($date_errors !== false && ((int) $date_errors['warning_count'] > 0 || (int) $date_errors['error_count'] > 0))
                || $parsed_date->format('Y-m-d') !== $next_date
            ) {
                return new WP_Error('linkvagt_invalid_next_date', 'Vælg datoen for næste automatiske scanning.', ['status' => 400]);
            }
        }
        return [
            'name' => mb_substr($name, 0, 191),
            'base_url' => $base_url,
            'sitemap_urls' => wp_json_encode($sitemaps),
            'excluded_domains' => '[]',
            'max_pages' => min(100000, max(1, absint($request->get_param('max_pages')) ?: 5000)),
            'crawl_fallback' => $request->get_param('crawl_fallback') === false ? 0 : 1,
            'auto_frequency' => $frequency,
            'auto_next_date' => $frequency === 'manual' ? null : $next_date,
            'report_mode' => 'always',
            'report_recipients' => Access::owner_email(),
        ];
    }

    private function site_exists(int $id): bool
    {
        global $wpdb;
        $table = Schema::table('sites');
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d", $id));
    }

    /** @return array<string,mixed> */
    private function get_site(int $id): array
    {
        global $wpdb;
        $table = Schema::table('sites');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
        return $this->cast_site($row ?: []);
    }

    /** @param array<string,mixed> $site */
    private function cast_site(array $site): array
    {
        $site['id'] = (int) ($site['id'] ?? 0);
        $site['max_pages'] = (int) ($site['max_pages'] ?? 5000);
        $site['crawl_fallback'] = (bool) ($site['crawl_fallback'] ?? true);
        $site['auto_frequency'] = in_array((string) ($site['auto_frequency'] ?? ''), ['weekly', 'biweekly', 'monthly', 'manual'], true)
            ? (string) $site['auto_frequency']
            : 'weekly';
        $site['sitemap_urls'] = json_decode((string) ($site['sitemap_urls'] ?? '[]'), true) ?: [];
        $site['excluded_domains'] = json_decode((string) ($site['excluded_domains'] ?? '[]'), true) ?: [];
        foreach (['broken_count', 'redirect_count', 'warning_count', 'links_checked'] as $field) {
            $site[$field] = (int) ($site[$field] ?? 0);
        }
        foreach (['wordpress_configured', 'wordpress_connected'] as $field) {
            $site[$field] = (bool) ($site[$field] ?? false);
        }
        $site['latest_scan_id'] = isset($site['latest_scan_id']) ? (int) $site['latest_scan_id'] : null;
        $site['latest_result_scan_id'] = isset($site['latest_result_scan_id']) ? (int) $site['latest_result_scan_id'] : null;
        return $site;
    }

    /** @param array<string,mixed> $scan */
    private function cast_scan(array $scan): array
    {
        foreach (['id', 'site_id', 'pages_total', 'pages_scanned', 'pages_processed', 'pages_failed', 'links_total', 'links_checked', 'broken_count', 'redirect_count', 'warning_count', 'details_retained'] as $field) {
            $scan[$field] = (int) ($scan[$field] ?? 0);
        }
        return $scan;
    }

    private function audit(string $action, ?string $object_type = null, ?int $object_id = null): void
    {
        global $wpdb;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $wpdb->insert(Schema::table('audit_log'), [
            'member_id' => null,
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'ip_hash' => $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : null,
            'metadata' => '{}',
            'created_at' => current_time('mysql', true),
        ]);
    }
}
