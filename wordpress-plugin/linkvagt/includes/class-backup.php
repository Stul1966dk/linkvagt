<?php

declare(strict_types=1);

namespace LinkVagt;

final class Backup
{
    private const HOOK = 'linkvagt_daily_backup';
    private const FORMAT = 1;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_action('init', [$this, 'schedule_daily']);
        add_action(self::HOOK, [$this, 'daily']);
        add_action('linkvagt_scan_completed', [$this, 'after_scan'], 20);
    }

    public function schedule_daily(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'daily', self::HOOK);
        }
    }

    public function daily(): void
    {
        $today = gmdate('Y-m-d');
        if ((string) get_option('linkvagt_last_daily_backup') === $today) {
            return;
        }
        try {
            $this->create('daily');
            update_option('linkvagt_last_daily_backup', $today, false);
        } catch (\Throwable $error) {
            $this->remember_error($error);
        }
    }

    public function after_scan(int $scan_id): void
    {
        global $wpdb;
        $mode = $wpdb->get_var($wpdb->prepare(
            'SELECT mode FROM ' . Schema::table('scans') . ' WHERE id=%d',
            $scan_id
        ));
        if ($mode !== 'site') {
            return;
        }
        try {
            $this->create('post-scan');
        } catch (\Throwable $error) {
            $this->remember_error($error);
        }
    }

    public function create(string $reason = 'manual'): array
    {
        if (!Crypto::available()) {
            throw new \RuntimeException('Backup kræver LINKVAGT_ENCRYPTION_KEY i wp-config.php.');
        }
        $directory = $this->directory();
        $this->protect_directory($directory);
        $tables = $this->export_tables();
        $checksums = [];
        foreach ($tables as $name => $rows) {
            $checksums[$name] = hash('sha256', (string) wp_json_encode($rows));
        }
        $created_at = gmdate('c');
        $payload = [
            'format' => self::FORMAT,
            'plugin_version' => LINKVAGT_VERSION,
            'site_url' => home_url('/'),
            'created_at' => $created_at,
            'reason' => sanitize_key($reason),
            'checksums' => $checksums,
            'tables' => $tables,
            'options' => $this->export_options(),
        ];
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Backupdata kunne ikke serialiseres.');
        }
        $compressed = gzencode($json, 6);
        if (!is_string($compressed)) {
            throw new \RuntimeException('Backupdata kunne ikke komprimeres.');
        }
        $encrypted = Crypto::encrypt($compressed);
        $stamp = gmdate('Ymd-His');
        $suffix = substr(hash('sha256', random_bytes(32)), 0, 10);
        $filename = "linkvagt-{$stamp}-" . sanitize_key($reason) . "-{$suffix}.lvbackup";
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $temporary = $target . '.tmp';
        if (file_put_contents($temporary, $encrypted, LOCK_EX) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Backupfilen kunne ikke skrives atomisk.');
        }
        $verified = $this->verify_file($target);
        if (!$verified['valid']) {
            wp_delete_file($target);
            throw new \RuntimeException('Backupkontrollen fejlede: ' . $verified['message']);
        }
        update_option('linkvagt_latest_backup', [
            'filename' => $filename,
            'created_at' => $created_at,
            'reason' => sanitize_key($reason),
            'size' => filesize($target) ?: 0,
            'integrity' => 'ok',
        ], false);
        delete_option('linkvagt_backup_error');
        $this->prune();
        return [
            'filename' => $filename,
            'created_at' => $created_at,
            'reason' => sanitize_key($reason),
            'size' => filesize($target) ?: 0,
            'integrity' => 'ok',
        ];
    }

    public function status(): array
    {
        $files = $this->files();
        $latest = get_option('linkvagt_latest_backup');
        if (is_array($latest)) {
            $path = $this->directory() . DIRECTORY_SEPARATOR . basename((string) $latest['filename']);
            if (!is_file($path)) {
                $latest = null;
            }
        }
        return [
            'in_progress' => false,
            'latest' => $latest ?: null,
            'count' => count($files),
            'total_size' => array_sum(array_map(static fn (string $file): int => (int) filesize($file), $files)),
            'location' => $this->display_location(),
            'last_error' => get_option('linkvagt_backup_error') ?: null,
        ];
    }

    public function verify_latest(): array
    {
        $latest = get_option('linkvagt_latest_backup');
        if (!is_array($latest) || empty($latest['filename'])) {
            throw new \RuntimeException('Der findes ingen backup at kontrollere.');
        }
        $path = $this->directory() . DIRECTORY_SEPARATOR . basename((string) $latest['filename']);
        $result = $this->verify_file($path);
        if (!$result['valid']) {
            throw new \RuntimeException($result['message']);
        }
        $latest['integrity'] = 'ok';
        update_option('linkvagt_latest_backup', $latest, false);
        return $result;
    }

    public function restore_latest(string $confirmation): array
    {
        if (!hash_equals('GENDAN', trim($confirmation))) {
            throw new \RuntimeException('Skriv GENDAN for at bekræfte gendannelsen.');
        }
        $latest = get_option('linkvagt_latest_backup');
        if (!is_array($latest) || empty($latest['filename'])) {
            throw new \RuntimeException('Der findes ingen backup at gendanne.');
        }
        $target = $this->directory() . DIRECTORY_SEPARATOR . basename((string) $latest['filename']);
        $payload = $this->read_payload($target);
        $this->create('pre-restore');

        global $wpdb;
        $allowed = [
            'sites', 'scans', 'findings', 'finding_sources', 'ignore_rules',
            'connections', 'link_changes', 'diagnostics', 'jobs', 'scan_pages',
            'scan_links', 'members', 'invites', 'audit_log',
        ];
        $delete_order = [
            'finding_sources', 'scan_links', 'scan_pages', 'jobs', 'findings',
            'diagnostics', 'link_changes', 'ignore_rules', 'connections',
            'scans', 'sites', 'invites', 'members', 'audit_log',
        ];
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($delete_order as $name) {
                $table = Schema::table($name);
                $wpdb->query("DELETE FROM {$table}");
            }
            foreach ($allowed as $name) {
                $table = Schema::table($name);
                foreach ((array) ($payload['tables'][$name] ?? []) as $row) {
                    if (!is_array($row) || $wpdb->insert($table, $row) === false) {
                        throw new \RuntimeException("Tabellen {$name} kunne ikke gendannes.");
                    }
                }
            }
            foreach ((array) ($payload['options'] ?? []) as $name => $value) {
                if ($name === 'linkvagt_page_id') {
                    continue;
                }
                update_option((string) $name, $value, false);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
        update_option('linkvagt_latest_backup', $latest, false);
        return ['restored' => true, 'filename' => basename($target), 'created_at' => $payload['created_at'] ?? null];
    }

    private function export_tables(): array
    {
        global $wpdb;
        $names = [
            'sites', 'scans', 'findings', 'finding_sources', 'ignore_rules',
            'connections', 'link_changes', 'diagnostics', 'jobs', 'scan_pages',
            'scan_links', 'members', 'invites', 'audit_log',
        ];
        $result = [];
        foreach ($names as $name) {
            $table = Schema::table($name);
            // Table names come exclusively from the fixed allowlist above.
            $result[$name] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A) ?: [];
        }
        return $result;
    }

    private function export_options(): array
    {
        $names = [
            'linkvagt_schema_version', 'linkvagt_page_id', 'linkvagt_owner_email',
            'linkvagt_mail_settings', 'linkvagt_excluded_domains',
        ];
        $result = [];
        foreach ($names as $name) {
            $result[$name] = get_option($name);
        }
        return $result;
    }

    private function verify_file(string $path): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            return ['valid' => false, 'message' => 'Backupfilen mangler eller er tom.'];
        }
        try {
            $payload = $this->read_payload($path);
            foreach ((array) ($payload['checksums'] ?? []) as $name => $expected) {
                $actual = hash('sha256', (string) wp_json_encode($payload['tables'][$name] ?? null));
                if (!hash_equals((string) $expected, $actual)) {
                    throw new \RuntimeException("Kontrolsummen for {$name} stemmer ikke.");
                }
            }
            return ['valid' => true, 'message' => 'Backupfilen er dekrypteret og godkendt.', 'created_at' => $payload['created_at'] ?? null];
        } catch (\Throwable $error) {
            return ['valid' => false, 'message' => $error->getMessage()];
        }
    }

    private function read_payload(string $path): array
    {
        $encrypted = file_get_contents($path);
        if (!is_string($encrypted)) {
            throw new \RuntimeException('Backupfilen kunne ikke læses.');
        }
        $json = gzdecode(Crypto::decrypt($encrypted));
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || !is_array($payload['tables'] ?? null)) {
            throw new \RuntimeException('Backupformatet er ugyldigt.');
        }
        return $payload;
    }

    private function directory(): string
    {
        if (defined('LINKVAGT_BACKUP_DIR')) {
            return rtrim((string) LINKVAGT_BACKUP_DIR, '/\\');
        }
        return dirname(rtrim(ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'linkvagt-backups';
    }

    private function display_location(): string
    {
        $directory = $this->directory();
        $root = wp_normalize_path(ABSPATH);
        $normalized = wp_normalize_path($directory);
        return str_starts_with($normalized, $root)
            ? '[WordPress]/' . ltrim(substr($normalized, strlen($root)), '/')
            : $normalized;
    }

    private function protect_directory(string $directory): void
    {
        if (!wp_mkdir_p($directory)) {
            throw new \RuntimeException('Backupmappen kunne ikke oprettes.');
        }
        $protections = [
            'index.php' => "<?php\nhttp_response_code(404);\nexit;\n",
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => "<?xml version=\"1.0\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
        ];
        foreach ($protections as $name => $contents) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($path)) {
                file_put_contents($path, $contents, LOCK_EX);
            }
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->directory() . DIRECTORY_SEPARATOR . '*.lvbackup') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return $files;
    }

    private function prune(): void
    {
        $files = $this->files();
        $automatic = [];
        $pre_write = [];
        foreach ($files as $file) {
            if (str_contains(basename($file), '-pre-wordpress-')) {
                $pre_write[] = $file;
            } elseif (!str_contains(basename($file), '-manual-')) {
                $automatic[] = $file;
            }
        }
        foreach (array_slice($automatic, 30) as $file) {
            wp_delete_file($file);
        }
        foreach (array_slice($pre_write, 10) as $file) {
            wp_delete_file($file);
        }
    }

    private function remember_error(\Throwable $error): void
    {
        update_option('linkvagt_backup_error', [
            'message' => mb_substr($error->getMessage(), 0, 1000),
            'at' => gmdate('c'),
        ], false);
    }
}
