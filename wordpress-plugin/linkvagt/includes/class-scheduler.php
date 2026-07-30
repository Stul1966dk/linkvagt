<?php

declare(strict_types=1);

namespace LinkVagt;

final class Scheduler
{
    private const HOOK = 'linkvagt_weekly_scan';
    private const OPTION = 'linkvagt_scheduled_run';
    private const SETTINGS_OPTION = 'linkvagt_schedule_settings';
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'cron_schedule']);
        add_action('init', [$this, 'ensure_schedule']);
        add_action(self::HOOK, [$this, 'run_wp_scheduled_event']);
        add_action('linkvagt_scan_finished', [$this, 'continue_run'], 5, 1);
    }

    public function cron_schedule(array $schedules): array
    {
        $schedules['linkvagt_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => 'Ugentligt (LinkVagt)',
        ];
        return $schedules;
    }

    public function ensure_schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event($this->next_run_timestamp(), 'linkvagt_weekly', self::HOOK);
        }
    }

    public function save_settings(array $input): array|\WP_Error
    {
        $day = sanitize_key((string) ($input['day'] ?? ''));
        $time = trim((string) ($input['time'] ?? ''));
        if (!array_key_exists($day, $this->weekdays()) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return new \WP_Error('linkvagt_invalid_schedule', 'Vælg en gyldig ugedag og et gyldigt klokkeslæt.', ['status' => 400]);
        }

        update_option(self::SETTINGS_OPTION, ['day' => $day, 'time' => $time], false);
        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event($this->next_run_timestamp(), 'linkvagt_weekly', self::HOOK);
        return $this->status();
    }

    public function start_weekly_run(): void
    {
        global $wpdb;
        $existing = $this->state();
        if (($existing['status'] ?? '') === 'running') {
            return;
        }
        $site_ids = $this->due_site_ids();
        if (!$site_ids) {
            return;
        }
        $state = [
            'run_id' => wp_generate_uuid4(),
            'status' => 'running',
            'site_ids' => $site_ids,
            'next_index' => 0,
            'scan_ids' => [],
            'finished_scan_ids' => [],
            'started_at' => current_time('mysql', true),
            'completed_at' => null,
        ];
        update_option(self::OPTION, $state, false);
        $this->enqueue_next($state);
    }

    public function run_due_schedule(): void
    {
        $state = $this->state();
        if (($state['status'] ?? '') === 'running') {
            return;
        }
        $settings = $this->settings();
        $now = new \DateTimeImmutable('now', wp_timezone());
        if ($now->format('H:i') < $settings['time']) {
            return;
        }
        $this->start_weekly_run();
    }

    public function run_wp_scheduled_event(): void
    {
        $this->start_weekly_run();
    }

    public function continue_run(int $scan_id): void
    {
        global $wpdb;
        $state = $this->state();
        if (($state['status'] ?? '') !== 'running' || !in_array($scan_id, array_map('intval', (array) ($state['scan_ids'] ?? [])), true)) {
            return;
        }
        if (in_array($scan_id, array_map('intval', (array) ($state['finished_scan_ids'] ?? [])), true)) {
            return;
        }
        $scan = $wpdb->get_row($wpdb->prepare(
            'SELECT scheduled_run,site_id,status FROM ' . Schema::table('scans') . ' WHERE id=%d',
            $scan_id
        ), ARRAY_A);
        if (!$scan || !hash_equals((string) $state['run_id'], (string) $scan['scheduled_run'])) {
            return;
        }
        $this->advance_next_date((int) $scan['site_id'], (string) $scan['status']);
        $state['finished_scan_ids'][] = $scan_id;
        update_option(self::OPTION, $state, false);
        if ((int) $state['next_index'] < count((array) $state['site_ids'])) {
            $this->enqueue_next($state);
            return;
        }
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql', true);
        update_option(self::OPTION, $state, false);
        do_action('linkvagt_scheduled_batch_completed', (string) $state['run_id'], array_map('intval', (array) $state['scan_ids']));
    }

    public function status(): array
    {
        $state = $this->state();
        $next = wp_next_scheduled(self::HOOK);
        $settings = $this->settings();
        return [
            'enabled' => true,
            'frequency' => 'weekly',
            'day' => $settings['day'],
            'time' => $settings['time'],
            'next_run' => $next ? gmdate('Y-m-d H:i:s', $next) : null,
            'worker_url' => Scanner::instance()->worker_url(),
            'current' => $state ?: null,
        ];
    }

    private function enqueue_next(array $state): void
    {
        global $wpdb;
        $index = (int) $state['next_index'];
        $site_id = (int) $state['site_ids'][$index];
        $now = current_time('mysql', true);
        $wpdb->insert(Schema::table('scans'), [
            'site_id' => $site_id,
            'mode' => 'site',
            'target_url' => null,
            'status' => 'queued',
            'scan_origin' => 'scheduled',
            'scheduled_run' => (string) $state['run_id'],
            'created_by' => null,
            'created_at' => $now,
        ]);
        $scan_id = (int) $wpdb->insert_id;
        if (!$scan_id) {
            return;
        }
        $wpdb->insert(Schema::table('jobs'), [
            'scan_id' => $scan_id,
            'job_type' => 'discover',
            'status' => 'queued',
            'payload' => '{}',
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $state['scan_ids'][] = $scan_id;
        $state['next_index'] = $index + 1;
        update_option(self::OPTION, $state, false);
        Scanner::instance()->wake();
    }

    private function state(): array
    {
        $state = get_option(self::OPTION, []);
        return is_array($state) ? $state : [];
    }

    /** @return list<int> */
    private function due_site_ids(): array
    {
        global $wpdb;
        $sites = Schema::table('sites');
        $scans = Schema::table('scans');
        $rows = $wpdb->get_results(
            "SELECT w.id,w.auto_frequency,w.auto_next_date,
                    (SELECT MAX(s.completed_at) FROM {$scans} s
                     WHERE s.site_id=w.id AND s.scan_origin='scheduled' AND s.status='completed') AS last_auto_scan
             FROM {$sites} w
             WHERE w.auto_frequency IN ('weekly','biweekly','monthly')
             ORDER BY COALESCE(w.auto_next_date,'1000-01-01'),w.name",
            ARRAY_A
        ) ?: [];
        $today = (new \DateTimeImmutable('now', wp_timezone()))->format('Y-m-d');
        $now = time();
        $intervals = [
            'weekly' => WEEK_IN_SECONDS,
            'biweekly' => 2 * WEEK_IN_SECONDS,
            'monthly' => 30 * DAY_IN_SECONDS,
        ];
        $due = [];
        foreach ($rows as $row) {
            if (!empty($row['auto_next_date'])) {
                if ((string) $row['auto_next_date'] <= $today) {
                    $due[] = (int) $row['id'];
                }
                continue;
            }
            $last = !empty($row['last_auto_scan']) ? strtotime((string) $row['last_auto_scan'] . ' UTC') : false;
            $frequency = (string) $row['auto_frequency'];
            if ($last === false || $last <= $now - $intervals[$frequency]) {
                $due[] = (int) $row['id'];
            }
        }
        return $due;
    }

    private function advance_next_date(int $site_id, string $status): void
    {
        global $wpdb;
        $sites = Schema::table('sites');
        $site = $wpdb->get_row($wpdb->prepare(
            "SELECT auto_frequency,auto_next_date FROM {$sites} WHERE id=%d",
            $site_id
        ), ARRAY_A);
        if (!$site || $site['auto_frequency'] === 'manual') {
            return;
        }
        $timezone = wp_timezone();
        $today = new \DateTimeImmutable('today', $timezone);
        if ($status !== 'completed') {
            $next = $today->modify('+1 day');
        } else {
            $base = !empty($site['auto_next_date'])
                ? new \DateTimeImmutable((string) $site['auto_next_date'], $timezone)
                : $today;
            while ($base <= $today) {
                $base = match ((string) $site['auto_frequency']) {
                    'weekly' => $base->modify('+7 days'),
                    'biweekly' => $base->modify('+14 days'),
                    'monthly' => $base->modify('+1 month'),
                    default => $today->modify('+7 days'),
                };
            }
            $next = $base;
        }
        $wpdb->update($sites, [
            'auto_next_date' => $next->format('Y-m-d'),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $site_id]);
    }

    private function settings(): array
    {
        $settings = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $day = sanitize_key((string) ($settings['day'] ?? 'monday'));
        $time = (string) ($settings['time'] ?? '02:00');
        return [
            'day' => array_key_exists($day, $this->weekdays()) ? $day : 'monday',
            'time' => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '02:00',
        ];
    }

    private function weekdays(): array
    {
        return [
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
        ];
    }

    private function next_run_timestamp(): int
    {
        $settings = $this->settings();
        $timezone = wp_timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        [$hour, $minute] = array_map('intval', explode(':', $settings['time']));
        $days = ($this->weekdays()[$settings['day']] - (int) $now->format('w') + 7) % 7;
        $candidate = $now->setTime($hour, $minute, 0);
        if ($days === 0 && $candidate <= $now) {
            $days = 7;
        }
        if ($days > 0) {
            $candidate = $candidate->modify("+{$days} days");
        }
        return $candidate->getTimestamp();
    }

}
