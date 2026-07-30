<?php

declare(strict_types=1);

namespace LinkVagt;

use WP_Error;
use WP_REST_Request;

final class Scanner
{
    private const HOOK = 'linkvagt_process_job';
    private const HTTP_TIMEOUT = 10;
    private const MAX_BODY_BYTES = 5_000_000;
    private const MAX_REDIRECTS = 10;
    private const PAGE_BATCH = 2;
    private const LINK_BATCH = 2;
    private const MAX_JOBS_PER_RUN = 2;
    private const MAX_RUN_SECONDS = 15.0;

    private static ?self $instance = null;
    private float $run_started_at = 0.0;
    private int $jobs_in_run = 0;
    /** @var array<int,list<string>> */
    private array $exclusion_cache = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'cron_schedule']);
        add_action(self::HOOK, [$this, 'process_next']);
        add_action('init', [$this, 'ensure_schedule']);
    }

    public function cron_schedule(array $schedules): array
    {
        $schedules['linkvagt_minute'] = [
            'interval' => 60,
            'display' => 'Hvert minut (LinkVagt)',
        ];
        return $schedules;
    }

    public function ensure_schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 20, 'linkvagt_minute', self::HOOK);
        }
    }

    public function wake(): void
    {
        if (!wp_next_scheduled(self::HOOK, ['immediate'])) {
            wp_schedule_single_event(time() + 1, self::HOOK, ['immediate']);
        }
    }

    public function worker_url(): string
    {
        return add_query_arg('token', $this->worker_token(), rest_url('linkvagt/v1/system/worker'));
    }

    public function authorize_worker(WP_REST_Request $request): bool
    {
        $provided = sanitize_text_field((string) $request->get_param('token'));
        return $provided !== '' && hash_equals($this->worker_token(), $provided);
    }

    public function external_tick(): array
    {
        Scheduler::instance()->run_due_schedule();
        Reporter::instance()->send_pending_manual_report();
        $excluded_removed = $this->purge_excluded_queued_links();
        $this->process_next('external');

        return ['ok' => true, 'excluded_links_removed' => $excluded_removed, 'at' => gmdate('c')];
    }

    public function purge_excluded_queued_links(): int
    {
        global $wpdb;
        $links = Schema::table('scan_links');
        $scans = Schema::table('scans');
        $rows = $wpdb->get_results(
            "SELECT l.id,l.scan_id,l.destination_url
             FROM {$links} l INNER JOIN {$scans} s ON s.id=l.scan_id
             WHERE l.status='queued' AND s.status IN ('queued','running')",
            ARRAY_A
        ) ?: [];
        $removed = 0;
        $affected_scans = [];
        foreach ($rows as $row) {
            if ($this->is_excluded((int) $row['scan_id'], (string) $row['destination_url'])) {
                $removed += (int) (bool) $wpdb->delete($links, ['id' => (int) $row['id']]);
                $affected_scans[(int) $row['scan_id']] = true;
            }
        }
        if ($removed > 0) {
            $this->clean_empty_link_jobs();
            foreach (array_keys($affected_scans) as $scan_id) {
                $this->finish_if_done((int) $scan_id);
            }
        }
        return $removed;
    }

    private function clean_empty_link_jobs(): void
    {
        global $wpdb;
        $jobs = Schema::table('jobs');
        $links = Schema::table('scan_links');
        $queued_jobs = $wpdb->get_results(
            "SELECT id,scan_id,payload FROM {$jobs}
             WHERE job_type='check_links' AND status='queued'",
            ARRAY_A
        ) ?: [];
        foreach ($queued_jobs as $job) {
            $payload = json_decode((string) $job['payload'], true);
            $ids = array_values(array_filter(array_map('absint', (array) ($payload['ids'] ?? []))));
            if (!$ids) {
                $wpdb->delete($jobs, ['id' => (int) $job['id']]);
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $existing = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$links} WHERE scan_id=%d AND id IN ({$placeholders})",
                (int) $job['scan_id'],
                ...$ids
            )));
            if (!$existing) {
                $wpdb->delete($jobs, ['id' => (int) $job['id']]);
            } elseif (count($existing) !== count($ids)) {
                $wpdb->update($jobs, [
                    'payload' => wp_json_encode(['ids' => $existing]),
                    'updated_at' => current_time('mysql', true),
                ], ['id' => (int) $job['id']]);
            }
        }
    }

    public function process_next(string $trigger = 'cron'): void
    {
        unset($trigger);
        if ($this->run_started_at === 0.0) {
            $this->run_started_at = microtime(true);
            $this->jobs_in_run = 0;
        }
        $job = $this->claim_job();
        if (!$job) {
            $this->reset_run_budget();
            return;
        }
        ++$this->jobs_in_run;
        $job_id = (int) $job['id'];
        $scan_id = (int) $job['scan_id'];

        try {
            $this->mark_scan_running($scan_id);
            $payload = json_decode((string) $job['payload'], true);
            $payload = is_array($payload) ? $payload : [];
            match ($job['job_type']) {
                'discover' => $this->discover($scan_id, $payload),
                'crawl_pages' => $this->crawl_pages($scan_id, $payload),
                'check_links' => $this->check_links($scan_id, $payload),
                default => throw new \RuntimeException('Ukendt jobtype.'),
            };
            $this->complete_job($job_id);
            $this->finish_if_done($scan_id);
        } catch (\Throwable $error) {
            $this->fail_job($job, $error);
        }

        if (
            $this->has_ready_jobs()
            && $this->jobs_in_run < self::MAX_JOBS_PER_RUN
            && microtime(true) - $this->run_started_at < self::MAX_RUN_SECONDS
        ) {
            $this->process_next('continuation');
        } elseif ($this->has_ready_jobs()) {
            $this->wake();
            $this->reset_run_budget();
        } else {
            $this->reset_run_budget();
        }
    }

    private function discover(int $scan_id, array $payload): void
    {
        $scan = $this->scan($scan_id);
        $site = $this->site((int) $scan['site_id']);
        $mode = (string) $scan['mode'];
        $target = (string) ($scan['target_url'] ?: ($payload['target_url'] ?? ''));

        if ($mode === 'link') {
            $id = $this->register_link($scan_id, $target, [['source_url' => $target, 'link_text' => 'Direkte kontrol']]);
            if ($id) {
                $this->enqueue($scan_id, 'check_links', ['ids' => [$id]]);
            }
            return;
        }
        if ($mode === 'page') {
            $page_id = $this->register_page($scan_id, $target);
            if ($page_id) {
                $this->enqueue($scan_id, 'crawl_pages', ['ids' => [$page_id]]);
            }
            return;
        }

        $pages = $this->sitemap_pages($site);
        if (!$pages) {
            $pages = [(string) $site['base_url']];
        }
        $page_ids = [];
        foreach (array_slice($pages, 0, (int) $site['max_pages']) as $url) {
            $id = $this->register_page($scan_id, $url);
            if ($id) {
                $page_ids[] = $id;
            }
        }
        foreach (array_chunk($page_ids, self::PAGE_BATCH) as $ids) {
            $this->enqueue($scan_id, 'crawl_pages', ['ids' => $ids]);
        }
    }

    private function crawl_pages(int $scan_id, array $payload): void
    {
        global $wpdb;
        $scan = $this->scan($scan_id);
        $site = $this->site((int) $scan['site_id']);
        $page_table = Schema::table('scan_pages');
        $link_ids = [];
        $new_page_ids = [];

        foreach (array_map('absint', (array) ($payload['ids'] ?? [])) as $page_id) {
            $page = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$page_table} WHERE id=%d AND scan_id=%d",
                $page_id,
                $scan_id
            ), ARRAY_A);
            if (!$page || $page['status'] === 'completed') {
                continue;
            }
            $document = $this->fetch_document((string) $page['page_url'], 'text/html');
            if (is_wp_error($document)) {
                $this->diagnostic($scan_id, (int) $site['id'], 'warning', 'page_fetch', (string) $page['page_url'], $document->get_error_message());
                $wpdb->update($page_table, ['status' => 'failed', 'updated_at' => current_time('mysql', true)], ['id' => $page_id]);
                continue;
            }
            $links = $this->extract_links($document, (string) $page['page_url']);
            foreach ($links as $link) {
                $link_id = $this->register_link($scan_id, $link['url'], [[
                    'source_url' => (string) $page['page_url'],
                    'link_text' => $link['text'],
                ]]);
                if ($link_id) {
                    $link_ids[] = $link_id;
                }
                if (
                    $scan['mode'] === 'site'
                    && !empty($site['crawl_fallback'])
                    && $this->same_origin($link['url'], (string) $site['base_url'])
                    && $this->looks_like_page($link['url'])
                    && $this->page_count($scan_id) < (int) $site['max_pages']
                ) {
                    $new_page_id = $this->register_page($scan_id, $link['url']);
                    if ($new_page_id) {
                        $new_page_ids[] = $new_page_id;
                    }
                }
            }
            $wpdb->update($page_table, ['status' => 'completed', 'updated_at' => current_time('mysql', true)], ['id' => $page_id]);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table('scans') . ' SET pages_scanned=pages_scanned+1 WHERE id=%d',
                $scan_id
            ));
        }

        foreach (array_chunk(array_values(array_unique($link_ids)), self::LINK_BATCH) as $ids) {
            $this->enqueue($scan_id, 'check_links', ['ids' => $ids]);
        }
        foreach (array_chunk(array_values(array_unique($new_page_ids)), self::PAGE_BATCH) as $ids) {
            $this->enqueue($scan_id, 'crawl_pages', ['ids' => $ids]);
        }
    }

    private function check_links(int $scan_id, array $payload): void
    {
        global $wpdb;
        $links_table = Schema::table('scan_links');
        foreach (array_map('absint', (array) ($payload['ids'] ?? [])) as $link_id) {
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$links_table} WHERE id=%d AND scan_id=%d",
                $link_id,
                $scan_id
            ), ARRAY_A);
            if (!$link || $link['status'] === 'completed') {
                continue;
            }
            if ($this->is_excluded($scan_id, (string) $link['destination_url'])) {
                $wpdb->delete($links_table, ['id' => $link_id]);
                continue;
            }
            $result = $this->check_url($scan_id, (string) $link['destination_url']);
            if ($result['category'] === 'excluded') {
                $wpdb->delete($links_table, ['id' => $link_id]);
                continue;
            }
            if (in_array($result['category'], ['broken', 'warning'], true)) {
                $second = $this->check_url($scan_id, (string) $link['destination_url']);
                $same_problem = in_array($second['category'], ['broken', 'warning'], true);
                $result = $second;
                $result['attempt_count'] = 2;
                $result['verification_status'] = $same_problem ? 'confirmed' : 'recovered';
            } else {
                $result['attempt_count'] = 1;
                $result['verification_status'] = 'single';
            }
            $this->store_result($scan_id, $link, $result);
            $wpdb->update($links_table, ['status' => 'completed', 'updated_at' => current_time('mysql', true)], ['id' => $link_id]);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table('scans') . ' SET links_checked=links_checked+1 WHERE id=%d',
                $scan_id
            ));
        }
    }

    /** @return array<string,mixed> */
    private function check_url(int $scan_id, string $url): array
    {
        $chain = [];
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if ($this->is_excluded($scan_id, $current)) {
                return [
                    'category' => 'excluded',
                    'error_type' => null,
                    'error_message' => null,
                    'status_code' => null,
                    'final_url' => $current,
                    'redirect_chain' => $chain,
                ];
            }
            $response = $this->safe_request($current, 'HEAD', 0);
            if (is_wp_error($response)) {
                return [
                    'category' => 'broken',
                    'error_type' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                    'status_code' => null,
                    'final_url' => $current,
                    'redirect_chain' => $chain,
                ];
            }
            $status = wp_remote_retrieve_response_code($response);
            if (in_array($status, [405, 501], true)) {
                $response = $this->safe_request($current, 'GET', 1024);
                if (is_wp_error($response)) {
                    return [
                        'category' => 'broken',
                        'error_type' => $response->get_error_code(),
                        'error_message' => $response->get_error_message(),
                        'status_code' => null,
                        'final_url' => $current,
                        'redirect_chain' => $chain,
                    ];
                }
                $status = wp_remote_retrieve_response_code($response);
            }
            if ($status >= 300 && $status < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                if (!$location) {
                    return ['category' => 'warning', 'status_code' => $status, 'final_url' => $current, 'redirect_chain' => $chain, 'error_type' => 'redirect_without_location'];
                }
                $next = $this->absolute_url((string) $location, $current);
                if (!$next || in_array($next, array_column($chain, 'url'), true)) {
                    return ['category' => 'broken', 'status_code' => $status, 'final_url' => $current, 'redirect_chain' => $chain, 'error_type' => 'redirect_loop'];
                }
                $chain[] = ['url' => $current, 'status' => $status];
                $current = $next;
                continue;
            }
            $category = $chain ? 'redirect' : 'ok';
            if ($status >= 400 && $status < 500) {
                $category = in_array($status, [401, 403, 408, 429], true) ? 'warning' : 'broken';
            } elseif ($status >= 500 || $status === 0) {
                $category = 'broken';
            }
            return [
                'category' => $category,
                'status_code' => $status,
                'final_url' => $current,
                'redirect_chain' => $chain,
                'error_type' => null,
                'error_message' => null,
            ];
        }
        return ['category' => 'broken', 'status_code' => null, 'final_url' => $current, 'redirect_chain' => $chain, 'error_type' => 'too_many_redirects'];
    }

    private function safe_request(string $url, string $method, int $limit): array|WP_Error
    {
        if (!wp_http_validate_url($url)) {
            return new WP_Error('unsafe_url', 'Adressen er ugyldig eller peger på et privat netværk.');
        }
        $args = [
            'method' => $method,
            'timeout' => self::HTTP_TIMEOUT,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'user-agent' => 'LinkVagt/' . LINKVAGT_VERSION . '; ' . home_url('/'),
            'headers' => ['Accept' => '*/*'],
        ];
        if ($limit > 0) {
            $args['limit_response_size'] = $limit;
        }
        return wp_safe_remote_request($url, $args);
    }

    private function worker_token(): string
    {
        $token = (string) get_option('linkvagt_worker_token', '');
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $token;
        }

        $generated = bin2hex(random_bytes(32));
        if (!add_option('linkvagt_worker_token', $generated, '', false)) {
            $stored = (string) get_option('linkvagt_worker_token', '');
            if (preg_match('/^[a-f0-9]{64}$/', $stored)) {
                return $stored;
            }
            update_option('linkvagt_worker_token', $generated, false);
        }
        return $generated;
    }

    private function fetch_document(string $url, string $accept): string|WP_Error
    {
        $response = $this->safe_request($url, 'GET', self::MAX_BODY_BYTES);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('http_' . $status, "Siden svarede med HTTP {$status}.");
        }
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($accept === 'text/html' && $type !== '' && !str_contains($type, 'html')) {
            return new WP_Error('unexpected_content_type', 'Siden returnerede ikke HTML.');
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /** @return list<array{url:string,text:string}> */
    private function extract_links(string $html, string $page_url): array
    {
        if (!class_exists(\DOMDocument::class)) {
            return [];
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('~^(mailto|tel|javascript|data):~i', $href)) {
                continue;
            }
            $absolute = $this->absolute_url($href, $page_url);
            if (!$absolute || !wp_http_validate_url($absolute)) {
                continue;
            }
            $links[] = [
                'url' => $absolute,
                'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $anchor->textContent)), 0, 500),
            ];
        }
        return $links;
    }

    /** @return list<string> */
    private function sitemap_pages(array $site): array
    {
        $queue = (array) ($site['sitemap_urls'] ?? []);
        if (!$queue) {
            $queue[] = rtrim((string) $site['base_url'], '/') . '/sitemap.xml';
        }
        $seen = [];
        $pages = [];
        while ($queue && count($seen) < 25 && count($pages) < (int) $site['max_pages']) {
            $url = array_shift($queue);
            if (isset($seen[$url]) || !wp_http_validate_url($url)) {
                continue;
            }
            $seen[$url] = true;
            $xml = $this->fetch_document($url, 'application/xml');
            if (is_wp_error($xml)) {
                continue;
            }
            if (!preg_match_all('~<loc\b[^>]*>(.*?)</loc>~is', $xml, $matches)) {
                continue;
            }
            $locations = array_map(static fn (string $value): string => trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8')), $matches[1]);
            if (stripos($xml, '<sitemapindex') !== false) {
                array_push($queue, ...$locations);
            } else {
                array_push($pages, ...$locations);
            }
        }
        return array_values(array_unique(array_filter($pages, 'wp_http_validate_url')));
    }

    private function register_page(int $scan_id, string $url): ?int
    {
        global $wpdb;
        $url = $this->normalize_url($url);
        if (!$url || $this->is_excluded($scan_id, $url)) {
            return null;
        }
        $table = Schema::table('scan_pages');
        $hash = hash('sha256', $url);
        $now = current_time('mysql', true);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (scan_id,url_hash,page_url,status,created_at,updated_at)
             VALUES (%d,%s,%s,'queued',%s,%s)",
            $scan_id,
            $hash,
            $url,
            $now,
            $now
        ));
        if (!$inserted) {
            return null;
        }
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('scans') . ' SET pages_total=pages_total+1 WHERE id=%d',
            $scan_id
        ));
        return (int) $wpdb->insert_id;
    }

    private function register_link(int $scan_id, string $url, array $sources): ?int
    {
        global $wpdb;
        $url = $this->normalize_url($url);
        if (!$url || $this->is_excluded($scan_id, $url)) {
            return null;
        }
        $table = Schema::table('scan_links');
        $hash = hash('sha256', $url);
        $now = current_time('mysql', true);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (scan_id,url_hash,destination_url,sources,status,created_at,updated_at)
             VALUES (%d,%s,%s,%s,'queued',%s,%s)",
            $scan_id,
            $hash,
            $url,
            wp_json_encode($sources),
            $now,
            $now
        ));
        if ($inserted) {
            return (int) $wpdb->insert_id;
        }
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id,sources FROM {$table} WHERE scan_id=%d AND url_hash=%s",
            $scan_id,
            $hash
        ), ARRAY_A);
        if ($existing) {
            $merged = array_merge(json_decode((string) $existing['sources'], true) ?: [], $sources);
            $unique = [];
            foreach ($merged as $source) {
                $unique[hash('sha256', (string) ($source['source_url'] ?? ''))] = $source;
            }
            $wpdb->update($table, ['sources' => wp_json_encode(array_values($unique)), 'updated_at' => $now], ['id' => (int) $existing['id']]);
        }
        return null;
    }

    private function store_result(int $scan_id, array $link, array $result): void
    {
        global $wpdb;
        $scan = $this->scan($scan_id);
        $now = current_time('mysql', true);
        $findings = Schema::table('findings');
        $wpdb->insert($findings, [
            'scan_id' => $scan_id,
            'site_id' => (int) $scan['site_id'],
            'destination_url' => (string) $link['destination_url'],
            'final_url' => $result['final_url'] ?? null,
            'status_code' => $result['status_code'] ?? null,
            'category' => (string) $result['category'],
            'error_type' => $result['error_type'] ?? null,
            'error_message' => $result['error_message'] ?? null,
            'redirect_chain' => wp_json_encode($result['redirect_chain'] ?? []),
            'attempt_count' => (int) ($result['attempt_count'] ?? 1),
            'verification_status' => (string) ($result['verification_status'] ?? 'single'),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
        $finding_id = (int) $wpdb->insert_id;
        foreach (json_decode((string) $link['sources'], true) ?: [] as $source) {
            $source_url = (string) ($source['source_url'] ?? '');
            $wpdb->insert(Schema::table('finding_sources'), [
                'finding_id' => $finding_id,
                'source_hash' => hash('sha256', $source_url),
                'source_url' => $source_url,
                'link_text' => (string) ($source['link_text'] ?? ''),
            ]);
        }
        $column = match ($result['category']) {
            'broken' => 'broken_count',
            'redirect' => 'redirect_count',
            'warning' => 'warning_count',
            default => null,
        };
        if ($column) {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . Schema::table('scans') . " SET {$column}={$column}+1 WHERE id=%d",
                $scan_id
            ));
        }
    }

    private function claim_job(): ?array
    {
        global $wpdb;
        $table = Schema::table('jobs');
        $lock_name = substr($wpdb->prefix . 'linkvagt_worker', 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock_name)) !== 1) {
            return null;
        }
        try {
            // A killed PHP request must not block the queue permanently.
            $wpdb->query(
                "UPDATE {$table} SET status='queued',locked_at=NULL,lock_token=NULL,
                 available_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE status='running' AND locked_at<UTC_TIMESTAMP()-INTERVAL 5 MINUTE"
            );
            if ($wpdb->get_var("SELECT id FROM {$table} WHERE status='running' LIMIT 1")) {
                return null;
            }
            $job = $wpdb->get_row(
                "SELECT j.* FROM {$table} j
                 INNER JOIN " . Schema::table('scans') . " s ON s.id=j.scan_id
                 WHERE j.status='queued' AND j.available_at<=UTC_TIMESTAMP()
                   AND s.status IN ('queued','running')
                 ORDER BY s.created_at,s.id,j.id LIMIT 1",
                ARRAY_A
            );
            if (!$job) {
                return null;
            }
            $token = wp_generate_uuid4();
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='running',locked_at=UTC_TIMESTAMP(),lock_token=%s,
                 attempts=attempts+1,updated_at=UTC_TIMESTAMP()
                 WHERE id=%d AND status='queued'",
                $token,
                (int) $job['id']
            ));
            if (!$updated) {
                return null;
            }
            $job['lock_token'] = $token;
            return $job;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    private function complete_job(int $job_id): void
    {
        global $wpdb;
        $wpdb->update(Schema::table('jobs'), [
            'status' => 'completed',
            'locked_at' => null,
            'lock_token' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $job_id]);
    }

    private function fail_job(array $job, \Throwable $error): void
    {
        global $wpdb;
        $attempts = (int) $job['attempts'] + 1;
        $retry = $attempts < 3;
        $wpdb->update(Schema::table('jobs'), [
            'status' => $retry ? 'queued' : 'failed',
            'available_at' => gmdate('Y-m-d H:i:s', time() + 60 * $attempts),
            'locked_at' => null,
            'lock_token' => null,
            'last_error' => mb_substr($error->getMessage(), 0, 2000),
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int) $job['id']]);
        $scan = $this->scan((int) $job['scan_id']);
        $this->diagnostic((int) $job['scan_id'], (int) $scan['site_id'], 'error', 'job_failed', null, $error->getMessage());
        if (!$retry) {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . Schema::table('jobs') . " SET status='cancelled',updated_at=UTC_TIMESTAMP()
                 WHERE scan_id=%d AND status='queued'",
                (int) $job['scan_id']
            ));
            $wpdb->update(Schema::table('scans'), [
                'status' => 'failed',
                'error_message' => mb_substr($error->getMessage(), 0, 2000),
                'completed_at' => current_time('mysql', true),
            ], ['id' => (int) $job['scan_id']]);
            do_action('linkvagt_scan_finished', (int) $job['scan_id']);
        }
    }

    private function enqueue(int $scan_id, string $type, array $payload): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(Schema::table('jobs'), [
            'scan_id' => $scan_id,
            'job_type' => $type,
            'status' => 'queued',
            'payload' => wp_json_encode($payload),
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function finish_if_done(int $scan_id): void
    {
        global $wpdb;
        $jobs = Schema::table('jobs');
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs} WHERE scan_id=%d AND status IN ('queued','running')",
            $scan_id
        ));
        if ($remaining > 0) {
            return;
        }
        $scans = Schema::table('scans');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$scans} SET status='completed',completed_at=UTC_TIMESTAMP()
             WHERE id=%d AND status IN ('queued','running')",
            $scan_id
        ));
        if (!$updated) {
            return;
        }
        $scan = $this->scan($scan_id);
        $wpdb->update(Schema::table('sites'), [
            'last_scan_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int) $scan['site_id']]);
        do_action('linkvagt_scan_completed', $scan_id);
        do_action('linkvagt_scan_finished', $scan_id);
        $this->prune_superseded_details($scan_id);
    }

    private function mark_scan_running(int $scan_id): void
    {
        global $wpdb;
        $table = Schema::table('scans');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status='running',started_at=COALESCE(started_at,UTC_TIMESTAMP())
             WHERE id=%d AND status='queued'",
            $scan_id
        ));
    }

    private function has_ready_jobs(): bool
    {
        global $wpdb;
        $table = Schema::table('jobs');
        return (bool) $wpdb->get_var("SELECT id FROM {$table} WHERE status='queued' AND available_at<=UTC_TIMESTAMP() LIMIT 1");
    }

    private function scan(int $id): array
    {
        global $wpdb;
        $table = Schema::table('scans');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
        if (!$row) {
            throw new \RuntimeException('Scanningen findes ikke.');
        }
        return $row;
    }

    private function site(int $id): array
    {
        global $wpdb;
        $table = Schema::table('sites');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
        if (!$row) {
            throw new \RuntimeException('Hjemmesiden findes ikke.');
        }
        $row['sitemap_urls'] = json_decode((string) $row['sitemap_urls'], true) ?: [];
        return $row;
    }

    private function page_count(int $scan_id): int
    {
        global $wpdb;
        $table = Schema::table('scan_pages');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE scan_id=%d", $scan_id));
    }

    private function diagnostic(int $scan_id, int $site_id, string $severity, string $type, ?string $url, string $message): void
    {
        global $wpdb;
        $wpdb->insert(Schema::table('diagnostics'), [
            'scan_id' => $scan_id,
            'site_id' => $site_id,
            'severity' => $severity,
            'event_type' => $type,
            'url' => $url,
            'message' => mb_substr($message, 0, 2000),
            'details' => '{}',
            'created_at' => current_time('mysql', true),
        ]);
    }

    private function normalize_url(string $url): ?string
    {
        $url = esc_url_raw(trim($url), ['http', 'https']);
        if (!wp_http_validate_url($url)) {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $normalized = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }
        return $normalized;
    }

    private function absolute_url(string $value, string $base): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('~^https?://~i', $value)) {
            return $this->normalize_url($value);
        }
        $base_parts = wp_parse_url($base);
        if (!$base_parts || empty($base_parts['scheme']) || empty($base_parts['host'])) {
            return null;
        }
        if (str_starts_with($value, '//')) {
            return $this->normalize_url($base_parts['scheme'] . ':' . $value);
        }
        $origin = $base_parts['scheme'] . '://' . $base_parts['host'] . (!empty($base_parts['port']) ? ':' . $base_parts['port'] : '');
        if (str_starts_with($value, '/')) {
            return $this->normalize_url($origin . $value);
        }
        $directory = preg_replace('~/[^/]*$~', '/', (string) ($base_parts['path'] ?? '/'));
        $path = $directory . $value;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }
        return $this->normalize_url($origin . '/' . implode('/', $segments));
    }

    private function same_origin(string $left, string $right): bool
    {
        $a = wp_parse_url($left);
        $b = wp_parse_url($right);
        return $a && $b
            && strtolower((string) $a['scheme']) === strtolower((string) $b['scheme'])
            && strtolower((string) $a['host']) === strtolower((string) $b['host'])
            && (int) ($a['port'] ?? 0) === (int) ($b['port'] ?? 0);
    }

    private function looks_like_page(string $url): bool
    {
        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        return !preg_match('~\.(?:jpe?g|png|gif|webp|svg|pdf|zip|mp[34]|avi|mov|css|js|xml|json|woff2?|ttf|ico)$~', $path);
    }

    private function is_excluded(int $scan_id, string $url): bool
    {
        if (!isset($this->exclusion_cache[$scan_id])) {
            $scan = $this->scan($scan_id);
            $site = $this->site((int) $scan['site_id']);
            $site_domains = json_decode((string) ($site['excluded_domains'] ?? '[]'), true) ?: [];
            $global_domains = (array) get_option('linkvagt_excluded_domains', []);
            $this->exclusion_cache[$scan_id] = array_values(array_unique(array_map(
                static fn ($domain): string => strtolower(trim((string) $domain)),
                array_merge($site_domains, $global_domains)
            )));
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        foreach ($this->exclusion_cache[$scan_id] as $domain) {
            if (self::domain_matches_host($domain, $host)) {
                return true;
            }
        }
        return false;
    }

    public static function domain_matches_host(string $domain, string $host): bool
    {
        $domain = strtolower(rtrim(preg_replace('/^www\./i', '', trim($domain)), '.'));
        $host = strtolower(rtrim(trim($host), '.'));
        return $domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    private function reset_run_budget(): void
    {
        $this->run_started_at = 0.0;
        $this->jobs_in_run = 0;
    }

    private function prune_superseded_details(int $scan_id): void
    {
        global $wpdb;
        $scan = $this->scan($scan_id);
        $scans = Schema::table('scans');
        $older_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$scans}
             WHERE site_id=%d AND status='completed' AND mode=%s
               AND COALESCE(target_url,'')=COALESCE(%s,'')
               AND id<>%d AND details_retained=1",
            (int) $scan['site_id'],
            (string) $scan['mode'],
            (string) ($scan['target_url'] ?? ''),
            $scan_id
        ));
        if (!$older_ids) {
            return;
        }
        $ids = implode(',', array_map('absint', $older_ids));
        $findings = Schema::table('findings');
        $sources = Schema::table('finding_sources');
        $wpdb->query("DELETE FROM {$sources} WHERE finding_id IN (SELECT id FROM {$findings} WHERE scan_id IN ({$ids}))");
        foreach (['findings', 'scan_pages', 'scan_links', 'jobs'] as $name) {
            $table = Schema::table($name);
            $wpdb->query("DELETE FROM {$table} WHERE scan_id IN ({$ids})");
        }
        $wpdb->query("UPDATE {$scans} SET details_retained=0 WHERE id IN ({$ids})");
    }
}
