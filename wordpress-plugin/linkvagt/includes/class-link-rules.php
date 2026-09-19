<?php

declare(strict_types=1);

namespace LinkVagt;

/**
 * Ignorerings- og godkendelsesregler for links.
 *
 * En regel har en type:
 *  - "ignore": linket er et kendt problem, som ikke skal tælle med.
 *  - "ok":     linket virker, men scanneren kan ikke se det (fx en server med
 *              ufuldstændig certifikatkæde). Reglen husker det svar, der blev
 *              godkendt, og gælder ikke længere, hvis linket begynder at svare
 *              anderledes — så en ægte fejl senere alligevel bliver vist.
 *
 * Resultatet gemmes på fundet (findings.override), så scanningens tællere,
 * oversigten og mails udelader tilsidesatte fund.
 */
final class Link_Rules
{
    public const KINDS = ['ignore', 'ok'];

    /** @var array<int, array<string, list<array<string,mixed>>>> */
    private static array $cache = [];

    /** Aktive regler for en hjemmeside, grupperet efter destinations-hash. */
    public static function for_site(int $site_id, bool $fresh = false): array
    {
        if (!$fresh && isset(self::$cache[$site_id])) {
            return self::$cache[$site_id];
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::table('ignore_rules') . '
             WHERE site_id=%d AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY id',
            $site_id
        ), ARRAY_A) ?: [];
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['destination_hash']][] = $row;
        }
        return self::$cache[$site_id] = $grouped;
    }

    public static function forget(int $site_id): void
    {
        unset(self::$cache[$site_id]);
    }

    /** Scannerens svar i kort form, fx "404" eller "http_request_failed". */
    public static function signature(array $finding): string
    {
        $status = (int) ($finding['status_code'] ?? 0);
        return $status > 0 ? (string) $status : (string) ($finding['error_type'] ?? '');
    }

    /**
     * Afgør, hvilke regler der rammer et fund.
     *
     * @param list<array{source_url:string}> $sources
     * @return array{override: ?string, rule_id: ?int, source_rules: array<string, array<string,mixed>>}
     */
    public static function evaluate(array $rules_by_destination, array $finding, array $sources): array
    {
        $none = ['override' => null, 'rule_id' => null, 'source_rules' => []];
        if (($finding['category'] ?? '') === 'ok') {
            return $none;
        }
        $rules = $rules_by_destination[hash('sha256', (string) $finding['destination_url'])] ?? [];
        $signature = self::signature($finding);
        $rules = array_values(array_filter($rules, static function (array $rule) use ($signature): bool {
            $expected = (string) ($rule['expected_signature'] ?? '');
            return ($rule['kind'] ?? 'ignore') !== 'ok' || $expected === '' || $expected === $signature;
        }));
        if (!$rules) {
            return $none;
        }

        foreach ($rules as $rule) {
            if ((string) $rule['source_url'] === '') {
                return ['override' => self::override_for((string) $rule['kind']), 'rule_id' => (int) $rule['id'], 'source_rules' => []];
            }
        }

        // Regler for enkelte kildesider tilsidesætter kun fundet, når hver
        // eneste kildeside er dækket. Ellers er linket stadig et problem på
        // de øvrige sider, og kun de dækkede kilder markeres.
        $by_source = [];
        foreach ($rules as $rule) {
            $by_source[(string) $rule['source_hash']] ??= $rule;
        }
        $source_rules = [];
        foreach ($sources as $source) {
            $hash = hash('sha256', (string) $source['source_url']);
            if (isset($by_source[$hash])) {
                $source_rules[$hash] = $by_source[$hash];
            }
        }
        if (!$sources || count($source_rules) < count($sources)) {
            return ['override' => null, 'rule_id' => null, 'source_rules' => $source_rules];
        }
        $kinds = array_unique(array_map(static fn (array $rule): string => (string) $rule['kind'], $source_rules));
        $first = reset($source_rules);
        return [
            'override' => self::override_for($kinds === ['ok'] ? 'ok' : 'ignore'),
            'rule_id' => (int) $first['id'],
            'source_rules' => $source_rules,
        ];
    }

    /**
     * Vurderer alle bevarede fund for en destination på ny, fx efter at en
     * regel er oprettet eller fjernet, og opdaterer de berørte tællere.
     */
    public static function apply_to_destination(int $site_id, string $destination_url): void
    {
        global $wpdb;
        self::forget($site_id);
        $finding_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT f.id FROM ' . Schema::table('findings') . ' f
             INNER JOIN ' . Schema::table('scans') . ' s ON s.id=f.scan_id
             WHERE f.site_id=%d AND f.destination_url=%s AND s.details_retained=1',
            $site_id,
            $destination_url
        )) ?: [];
        self::refresh_findings(array_map('intval', $finding_ids));
    }

    /** Vurderer alle bevarede fund på en hjemmeside på ny. Bruges ved opgradering. */
    public static function apply_to_site(int $site_id): void
    {
        global $wpdb;
        self::forget($site_id);
        $finding_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT f.id FROM ' . Schema::table('findings') . ' f
             INNER JOIN ' . Schema::table('scans') . ' s ON s.id=f.scan_id
             WHERE f.site_id=%d AND f.category<>%s AND s.details_retained=1',
            $site_id,
            'ok'
        )) ?: [];
        self::refresh_findings(array_map('intval', $finding_ids));
    }

    /** @param list<int> $finding_ids */
    public static function refresh_findings(array $finding_ids): void
    {
        global $wpdb;
        $findings = Schema::table('findings');
        $sources_table = Schema::table('finding_sources');
        $scans_to_recount = [];
        foreach (array_chunk($finding_ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,scan_id,site_id,destination_url,category,status_code,error_type,override,override_rule_id
                 FROM {$findings} WHERE id IN ({$placeholders})",
                ...$chunk
            ), ARRAY_A) ?: [];
            $sources_by_finding = [];
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT finding_id,source_url FROM {$sources_table} WHERE finding_id IN ({$placeholders})",
                ...$chunk
            ), ARRAY_A) ?: [] as $source) {
                $sources_by_finding[(int) $source['finding_id']][] = $source;
            }
            foreach ($rows as $row) {
                $result = self::evaluate(
                    self::for_site((int) $row['site_id']),
                    $row,
                    $sources_by_finding[(int) $row['id']] ?? []
                );
                if (self::store($row, $result)) {
                    $scans_to_recount[(int) $row['scan_id']] = true;
                }
            }
        }
        foreach (array_keys($scans_to_recount) as $scan_id) {
            self::recount_scan($scan_id);
        }
    }

    /** Gemmer en ny vurdering på fundet. Returnerer true, hvis den ændrede sig. */
    public static function store(array $finding, array $result): bool
    {
        $override = $result['override'];
        $rule_id = $override ? $result['rule_id'] : null;
        if (($finding['override'] ?? null) === $override && (int) ($finding['override_rule_id'] ?? 0) === (int) $rule_id) {
            return false;
        }
        global $wpdb;
        $wpdb->update(Schema::table('findings'), [
            'override' => $override,
            'override_rule_id' => $rule_id,
        ], ['id' => (int) $finding['id']]);
        return true;
    }

    /**
     * Genberegner en scannings problemtællere ud fra dens fund. Scanninger,
     * hvis detaljer er ryddet op, har ingen fund at tælle og røres ikke.
     */
    public static function recount_scan(int $scan_id): void
    {
        global $wpdb;
        $findings = Schema::table('findings');
        $count = static fn (string $category): string => $wpdb->prepare(
            "(SELECT COUNT(*) FROM {$findings}
              WHERE scan_id=%d AND category=%s AND resolved_at IS NULL AND override IS NULL)",
            $scan_id,
            $category
        );
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('scans') . '
             SET broken_count=' . $count('broken') . ',
                 redirect_count=' . $count('redirect') . ',
                 warning_count=' . $count('warning') . '
             WHERE id=%d AND details_retained=1',
            $scan_id
        ));
    }

    private static function override_for(string $kind): string
    {
        return $kind === 'ok' ? 'ok' : 'ignored';
    }
}
