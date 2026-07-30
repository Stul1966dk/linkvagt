<?php

declare(strict_types=1);

namespace LinkVagt;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WordPress_Service
{
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
        $permission = static fn (): bool => Access::can_access() && current_user_can(Access::CAP_REPAIR);
        register_rest_route('linkvagt/v1', '/sites/(?P<id>\d+)/wordpress', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'connection'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'save_connection'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'delete_connection'], 'permission_callback' => $permission],
        ]);
        register_rest_route('linkvagt/v1', '/sites/(?P<id>\d+)/wordpress/test', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'test_connection'], 'permission_callback' => $permission,
        ]);
        register_rest_route('linkvagt/v1', '/sites/(?P<id>\d+)/wordpress/changes', [
            'methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'changes'], 'permission_callback' => $permission,
        ]);
        register_rest_route('linkvagt/v1', '/wordpress/preview', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'preview'], 'permission_callback' => $permission,
        ]);
        register_rest_route('linkvagt/v1', '/wordpress/apply', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'apply'], 'permission_callback' => $permission,
        ]);
        register_rest_route('linkvagt/v1', '/wordpress/changes/(?P<id>\d+)/undo', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'undo'], 'permission_callback' => $permission,
        ]);
    }

    public function connection(WP_REST_Request $request): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT username,verified_at,created_at,updated_at FROM ' . Schema::table('connections') . ' WHERE site_id=%d',
            (int) $request['id']
        ), ARRAY_A);
        return $row ? [...$row, 'connected' => true, 'app_password' => '********'] : ['connected' => false];
    }

    public function save_connection(WP_REST_Request $request): array|WP_Error
    {
        if (!Crypto::available()) {
            return $this->error('Krypteringsnøglen mangler i wp-config.php.', 503);
        }
        global $wpdb;
        $site_id = (int) $request['id'];
        if (!$this->site($site_id)) {
            return $this->error('Hjemmesiden findes ikke.', 404);
        }
        $username = sanitize_text_field((string) $request->get_param('username'));
        $password = trim((string) $request->get_param('app_password'));
        $table = Schema::table('connections');
        $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE site_id=%d", $site_id), ARRAY_A);
        if ($username === '' || (!$current && ($password === '' || $password === '********'))) {
            return $this->error('WordPress-brugernavn og applikationskodeord er påkrævet.');
        }
        $password_changed = $password !== '' && $password !== '********';
        $encrypted = $password_changed ? Crypto::encrypt($password) : (string) $current['encrypted_app_password'];
        $changed = !$current || $current['username'] !== $username || $password_changed;
        $now = current_time('mysql', true);
        $wpdb->replace($table, [
            'site_id' => $site_id,
            'username' => $username,
            'encrypted_app_password' => $encrypted,
            'verified_at' => $changed ? null : $current['verified_at'],
            'created_at' => $current['created_at'] ?? $now,
            'updated_at' => $now,
        ]);
        $this->audit('wordpress.connection_saved', 'site', $site_id);
        return ['connected' => true, 'username' => $username, 'app_password' => '********', 'verified_at' => $changed ? null : $current['verified_at']];
    }

    public function delete_connection(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $site_id = (int) $request['id'];
        $wpdb->delete(Schema::table('connections'), ['site_id' => $site_id]);
        $this->audit('wordpress.connection_deleted', 'site', $site_id);
        return new WP_REST_Response(null, 204);
    }

    public function test_connection(WP_REST_Request $request): array|WP_Error
    {
        try {
            $client = $this->client((int) $request['id'], false);
            $user = $client->request('users/me?context=edit&_fields=id,name,capabilities');
            if (empty($user['capabilities']['edit_posts']) && empty($user['capabilities']['edit_pages'])) {
                return $this->error('WordPress-brugeren må ikke redigere indhold.', 403);
            }
            global $wpdb;
            $wpdb->update(Schema::table('connections'), ['verified_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)], ['site_id' => (int) $request['id']]);
            return ['ok' => true, 'user' => (string) ($user['name'] ?? 'WordPress-bruger')];
        } catch (\Throwable $error) {
            return $this->error($error->getMessage(), 502);
        }
    }

    public function changes(WP_REST_Request $request): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id,source_url,post_title,old_url,new_url,replacement_count,old_anchor_text,new_anchor_text,
             anchor_replacement_count,url_changed,status,error_message,applied_at,undone_at,created_at
             FROM ' . Schema::table('link_changes') . ' WHERE site_id=%d ORDER BY id DESC LIMIT 30',
            (int) $request['id']
        ), ARRAY_A) ?: [];
    }

    public function preview(WP_REST_Request $request): array|WP_Error
    {
        try {
            return $this->preview_data($request);
        } catch (\Throwable $error) {
            return $this->error($error->getMessage());
        }
    }

    public function apply(WP_REST_Request $request): array|WP_Error
    {
        try {
            $prepared = $this->prepare($request);
            $before_hash = hash('sha256', $prepared['original']);
            if (!hash_equals($before_hash, (string) $request->get_param('before_hash'))) {
                throw new \RuntimeException('Siden er ændret siden forhåndsvisningen. Kontrollér ændringen igen.');
            }
            Backup::instance()->create('pre-wordpress');
            global $wpdb;
            $now = current_time('mysql', true);
            $wpdb->insert(Schema::table('link_changes'), [
                'site_id' => $prepared['finding']['site_id'], 'finding_id' => $prepared['finding']['id'],
                'source_url' => $prepared['source']['source_url'], 'post_type' => $prepared['post']['post_type'],
                'post_id' => $prepared['post']['id'], 'post_title' => $prepared['title'],
                'old_url' => $prepared['finding']['destination_url'], 'new_url' => $prepared['new_url'],
                'backup_content' => $prepared['original'], 'changed_content' => $prepared['replacement']['content'],
                'before_hash' => $before_hash, 'replacement_count' => $prepared['replacement']['count'],
                'source_link_text' => $prepared['source']['link_text'], 'old_anchor_text' => $prepared['source']['link_text'],
                'new_anchor_text' => $prepared['new_anchor_text'], 'anchor_replacement_count' => $prepared['replacement']['anchor_count'],
                'url_changed' => $prepared['url_changed'] ? 1 : 0, 'status' => 'pending',
                'created_by' => get_current_user_id(), 'created_at' => $now,
            ]);
            $change_id = (int) $wpdb->insert_id;
            $fresh = $prepared['client']->get_post($prepared['post']['post_type'], (int) $prepared['post']['id']);
            if (!hash_equals($before_hash, hash('sha256', $this->raw_content($fresh)))) {
                throw new \RuntimeException('Siden blev ændret i WordPress under godkendelsen.');
            }
            try {
                $prepared['client']->update_content($prepared['post']['post_type'], (int) $prepared['post']['id'], $prepared['replacement']['content']);
                $verified = $prepared['client']->get_post($prepared['post']['post_type'], (int) $prepared['post']['id']);
                $verified_content = $this->raw_content($verified);
                if ($verified_content !== $prepared['replacement']['content']) {
                    throw new \RuntimeException('WordPress gemte ikke præcis den godkendte ændring.');
                }
                $wpdb->update(Schema::table('link_changes'), ['status' => 'applied', 'after_hash' => hash('sha256', $verified_content), 'applied_at' => current_time('mysql', true)], ['id' => $change_id]);
                if ($prepared['url_changed']) {
                    $this->mark_source_resolved($change_id, $prepared['finding'], $prepared['source']);
                }
                $this->audit('wordpress.change_applied', 'change', $change_id);
                return ['id' => $change_id, 'status' => 'applied', 'replacement_count' => $prepared['replacement']['count'], 'anchor_replacement_count' => $prepared['replacement']['anchor_count']];
            } catch (\Throwable $write_error) {
                try {
                    $prepared['client']->update_content($prepared['post']['post_type'], (int) $prepared['post']['id'], $prepared['original']);
                    $restored = $prepared['client']->get_post($prepared['post']['post_type'], (int) $prepared['post']['id']);
                    $status = hash_equals($before_hash, hash('sha256', $this->raw_content($restored))) ? 'rolled_back' : 'failed';
                } catch (\Throwable) {
                    $status = 'failed';
                }
                $wpdb->update(Schema::table('link_changes'), ['status' => $status, 'error_message' => mb_substr($write_error->getMessage(), 0, 2000)], ['id' => $change_id]);
                throw $write_error;
            }
        } catch (\Throwable $error) {
            return $this->error($error->getMessage());
        }
    }

    public function undo(WP_REST_Request $request): array|WP_Error
    {
        try {
            global $wpdb;
            $table = Schema::table('link_changes');
            $change = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='applied'", (int) $request['id']), ARRAY_A);
            if (!$change) {
                throw new \RuntimeException('Ændringen findes ikke eller er allerede fortrudt.');
            }
            $client = $this->client((int) $change['site_id']);
            $current = $client->get_post($change['post_type'], (int) $change['post_id']);
            if (!hash_equals((string) $change['after_hash'], hash('sha256', $this->raw_content($current)))) {
                throw new \RuntimeException('Siden er ændret efter linkrettelsen og kan ikke fortrydes automatisk.');
            }
            $client->update_content($change['post_type'], (int) $change['post_id'], $change['backup_content']);
            $restored = $client->get_post($change['post_type'], (int) $change['post_id']);
            if (!hash_equals((string) $change['before_hash'], hash('sha256', $this->raw_content($restored)))) {
                throw new \RuntimeException('WordPress kunne ikke verificere det gendannede indhold.');
            }
            $wpdb->update($table, ['status' => 'undone', 'undone_at' => current_time('mysql', true)], ['id' => (int) $request['id']]);
            $this->restore_source($change);
            $this->audit('wordpress.change_undone', 'change', (int) $request['id']);
            return ['id' => (int) $request['id'], 'status' => 'undone'];
        } catch (\Throwable $error) {
            return $this->error($error->getMessage());
        }
    }

    private function preview_data(WP_REST_Request $request): array
    {
        $prepared = $this->prepare($request);
        return [
            'post_id' => $prepared['post']['id'], 'post_type' => $prepared['post']['post_type'],
            'post_title' => $prepared['title'], 'source_url' => $prepared['post']['link'],
            'old_url' => $prepared['finding']['destination_url'], 'new_url' => $prepared['new_url'],
            'replacement_count' => $prepared['replacement']['count'], 'old_anchor_text' => $prepared['source']['link_text'],
            'new_anchor_text' => $prepared['new_anchor_text'], 'anchor_text_changed' => $prepared['anchor_changed'],
            'anchor_replacement_count' => $prepared['replacement']['anchor_count'], 'url_changed' => $prepared['url_changed'],
            'before_hash' => hash('sha256', $prepared['original']),
        ];
    }

    private function prepare(WP_REST_Request $request): array
    {
        global $wpdb;
        $finding_id = absint($request->get_param('finding_id'));
        $source_url = esc_url_raw((string) $request->get_param('source_url'), ['http', 'https']);
        $finding = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('findings') . ' WHERE id=%d', $finding_id), ARRAY_A);
        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('finding_sources') . ' WHERE finding_id=%d AND source_hash=%s', $finding_id, hash('sha256', $source_url)), ARRAY_A);
        if (!$finding || !$source) {
            throw new \RuntimeException('Linkfundet eller kildesiden findes ikke.');
        }
        $client = $this->client((int) $finding['site_id']);
        $post = $client->find_post($source_url);
        $original = $this->raw_content($post);
        $new_url = esc_url_raw((string) $request->get_param('new_url'), ['http', 'https']);
        if (!wp_http_validate_url($new_url)) {
            throw new \RuntimeException('Den nye linkadresse er ugyldig.');
        }
        $new_anchor = sanitize_text_field((string) $request->get_param('new_anchor_text'));
        $old_anchor = (string) ($source['link_text'] ?? '');
        $anchor_changed = $new_anchor !== '' && $new_anchor !== $old_anchor;
        $url_changed = $this->normalize($new_url, $post['link']) !== $this->normalize($finding['destination_url'], $post['link']);
        if (!$url_changed && !$anchor_changed) {
            throw new \RuntimeException('Hverken link eller ankertekst er ændret.');
        }
        $replacement = $this->replace_anchors($original, $finding['destination_url'], $new_url, $anchor_changed ? $new_anchor : null, $post['link']);
        if (!$replacement['count']) {
            throw new \RuntimeException('Linket findes ikke i WordPress-sidens almindelige indhold.');
        }
        if ($replacement['formatted']) {
            throw new \RuntimeException('Ankerteksten indeholder formatering og kan ikke ændres sikkert automatisk.');
        }
        return compact('finding', 'source', 'client', 'post', 'original', 'new_url', 'new_anchor', 'anchor_changed', 'url_changed') + [
            'new_anchor_text' => $anchor_changed ? $new_anchor : $old_anchor,
            'replacement' => $replacement,
            'title' => (string) ($post['title']['raw'] ?? $post['title']['rendered'] ?? $post['link']),
        ];
    }

    private function replace_anchors(string $html, string $old_url, string $new_url, ?string $new_text, string $base): array
    {
        $count = $anchor_count = $formatted = 0;
        $target = $this->normalize($old_url, $base);
        $content = preg_replace_callback('~(<a\b)([^>]*)(>)(.*?)(</a\s*>)~is', function (array $m) use ($target, $new_url, $new_text, $base, &$count, &$anchor_count, &$formatted): string {
            if (!preg_match('~(\bhref\s*=\s*)(["\'])(.*?)\2~is', $m[2], $href)) {
                return $m[0];
            }
            if ($this->normalize(html_entity_decode($href[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $base) !== $target) {
                return $m[0];
            }
            ++$count;
            $attributes = str_replace($href[0], $href[1] . $href[2] . esc_attr($new_url) . $href[2], $m[2]);
            $inner = $m[4];
            if ($new_text !== null) {
                if (preg_match('/<[^>]+>/', $inner)) {
                    ++$formatted;
                } else {
                    $inner = esc_html($new_text);
                    ++$anchor_count;
                }
            }
            return $m[1] . $attributes . $m[3] . $inner . $m[5];
        }, $html);
        return ['content' => (string) $content, 'count' => $count, 'anchor_count' => $anchor_count, 'formatted' => $formatted];
    }

    private function client(int $site_id, bool $verified = true): WordPress_Client
    {
        global $wpdb;
        $site = $this->site($site_id);
        $connection = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('connections') . ' WHERE site_id=%d', $site_id), ARRAY_A);
        if (!$connection || ($verified && !$connection['verified_at'])) {
            throw new \RuntimeException('WordPress-forbindelsen skal gemmes og testes først.');
        }
        return new WordPress_Client($site, $connection['username'], Crypto::decrypt($connection['encrypted_app_password']));
    }

    private function site(int $id): ?array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('sites') . ' WHERE id=%d', $id), ARRAY_A) ?: null;
    }

    private function raw_content(array $post): string
    {
        if (!isset($post['content']['raw']) || !is_string($post['content']['raw'])) {
            throw new \RuntimeException('WordPress udleverede ikke redigerbart indhold.');
        }
        return $post['content']['raw'];
    }

    private function normalize(string $url, string $base): string
    {
        if (!preg_match('~^https?://~i', $url)) {
            $origin = wp_parse_url($base, PHP_URL_SCHEME) . '://' . wp_parse_url($base, PHP_URL_HOST);
            $url = str_starts_with($url, '/') ? $origin . $url : rtrim(dirname($base), '/') . '/' . $url;
        }
        return rtrim(strtolower((string) preg_replace('/#.*$/', '', $url)), '/');
    }

    private function error(string $message, int $status = 400): WP_Error
    {
        return new WP_Error('linkvagt_wordpress', $message, ['status' => $status]);
    }

    private function audit(string $action, string $type, int $id): void
    {
        global $wpdb;
        $wpdb->insert(Schema::table('audit_log'), ['member_id' => null, 'action' => $action, 'object_type' => $type, 'object_id' => $id, 'ip_hash' => null, 'metadata' => '{}', 'created_at' => current_time('mysql', true)]);
    }

    private function mark_source_resolved(int $change_id, array $finding, array $source): void
    {
        global $wpdb;
        $sources = Schema::table('finding_sources');
        $wpdb->delete($sources, [
            'finding_id' => (int) $finding['id'],
            'source_hash' => hash('sha256', (string) $source['source_url']),
        ]);
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$sources} WHERE finding_id=%d",
            (int) $finding['id']
        ));
        if ($remaining > 0) {
            return;
        }
        $wpdb->update(Schema::table('findings'), ['resolved_at' => current_time('mysql', true)], ['id' => (int) $finding['id']]);
        $column = match ($finding['category']) {
            'broken' => 'broken_count',
            'redirect' => 'redirect_count',
            'warning' => 'warning_count',
            default => null,
        };
        if ($column) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table('scans') . " SET {$column}=GREATEST(0,{$column}-1) WHERE id=%d",
                (int) $finding['scan_id']
            ));
        }
        $wpdb->update(Schema::table('link_changes'), ['error_message' => null], ['id' => $change_id]);
    }

    private function restore_source(array $change): void
    {
        if (empty($change['finding_id'])) {
            return;
        }
        global $wpdb;
        $findings = Schema::table('findings');
        $finding = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$findings} WHERE id=%d",
            (int) $change['finding_id']
        ), ARRAY_A);
        if (!$finding) {
            return;
        }
        $wpdb->replace(Schema::table('finding_sources'), [
            'finding_id' => (int) $change['finding_id'],
            'source_hash' => hash('sha256', (string) $change['source_url']),
            'source_url' => (string) $change['source_url'],
            'link_text' => (string) ($change['source_link_text'] ?? ''),
        ]);
        if (!$finding['resolved_at']) {
            return;
        }
        $wpdb->update($findings, ['resolved_at' => null], ['id' => (int) $change['finding_id']]);
        $column = match ($finding['category']) {
            'broken' => 'broken_count',
            'redirect' => 'redirect_count',
            'warning' => 'warning_count',
            default => null,
        };
        if ($column) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table('scans') . " SET {$column}={$column}+1 WHERE id=%d",
                (int) $finding['scan_id']
            ));
        }
    }
}

final class WordPress_Client
{
    public function __construct(private array $site, private string $username, private string $password)
    {
    }

    public function request(string $path, string $method = 'GET', ?array $body = null): array
    {
        $url = rtrim((string) $this->site['base_url'], '/') . '/wp-json/wp/v2/' . ltrim($path, '/');
        $args = [
            'method' => $method, 'timeout' => 15, 'redirection' => 0, 'reject_unsafe_urls' => true,
            'limit_response_size' => 6_000_000,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password), 'Accept' => 'application/json'],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
            throw new \RuntimeException(wp_strip_all_tags((string) ($data['message'] ?? 'WordPress afviste handlingen.')));
        }
        return is_array($data) ? $data : [];
    }

    public function find_post(string $source_url): array
    {
        $slug = basename(rtrim((string) wp_parse_url($source_url, PHP_URL_PATH), '/'));
        if ($slug === '') {
            throw new \RuntimeException('Forsiden kan endnu ikke rettes automatisk.');
        }
        foreach (['pages', 'posts'] as $type) {
            $items = $this->request($type . '?context=edit&slug=' . rawurlencode($slug) . '&per_page=20&_fields=id,link,content,title,status');
            foreach ($items as $item) {
                if ($this->canonical((string) $item['link']) === $this->canonical($source_url)) {
                    return [...$item, 'post_type' => $type];
                }
            }
        }
        throw new \RuntimeException('Kildesiden blev ikke fundet som et almindeligt WordPress-indlæg eller en side.');
    }

    public function get_post(string $type, int $id): array
    {
        return $this->request($type . '/' . $id . '?context=edit&_fields=id,link,content,title,status');
    }

    public function update_content(string $type, int $id, string $content): array
    {
        return $this->request($type . '/' . $id . '?context=edit', 'POST', ['content' => $content]);
    }

    private function canonical(string $url): string
    {
        return rtrim(strtolower((string) preg_replace('/[?#].*$/', '', $url)), '/');
    }
}
