import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

const dataFile = process.env.LINKVAGT_DB || resolve('data/linkvagt.db');
mkdirSync(dirname(dataFile), { recursive: true });

export const db = new DatabaseSync(dataFile);
db.exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');

db.exec(`
  CREATE TABLE IF NOT EXISTS sites (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    base_url TEXT NOT NULL UNIQUE,
    sitemap_urls TEXT NOT NULL DEFAULT '[]',
    excluded_domains TEXT NOT NULL DEFAULT '[]',
    max_pages INTEGER NOT NULL DEFAULT 5000,
    crawl_fallback INTEGER NOT NULL DEFAULT 1,
    last_scan_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );

  CREATE TABLE IF NOT EXISTS scans (
    id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    mode TEXT NOT NULL DEFAULT 'site',
    target_url TEXT,
    trigger_type TEXT NOT NULL DEFAULT 'manual',
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','failed')),
    pages_total INTEGER NOT NULL DEFAULT 0,
    pages_scanned INTEGER NOT NULL DEFAULT 0,
    links_checked INTEGER NOT NULL DEFAULT 0,
    broken_count INTEGER NOT NULL DEFAULT 0,
    redirect_count INTEGER NOT NULL DEFAULT 0,
    warning_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    details_retained INTEGER NOT NULL DEFAULT 1,
    started_at TEXT,
    completed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );

  CREATE TABLE IF NOT EXISTS findings (
    id INTEGER PRIMARY KEY,
    scan_id INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    destination_url TEXT NOT NULL,
    final_url TEXT,
    status_code INTEGER,
    category TEXT NOT NULL CHECK(category IN ('broken','redirect','warning','ok')),
    error_type TEXT,
    error_message TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 1,
    verification_status TEXT NOT NULL DEFAULT 'single',
    redirect_chain TEXT NOT NULL DEFAULT '[]',
    response_ms INTEGER,
    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT,
    UNIQUE(scan_id, destination_url)
  );

  CREATE TABLE IF NOT EXISTS finding_sources (
    finding_id INTEGER NOT NULL REFERENCES findings(id) ON DELETE CASCADE,
    source_url TEXT NOT NULL,
    link_text TEXT,
    PRIMARY KEY(finding_id, source_url)
  );

  CREATE TABLE IF NOT EXISTS ignore_rules (
    id INTEGER PRIMARY KEY,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    destination_url TEXT NOT NULL,
    source_url TEXT NOT NULL DEFAULT '',
    reason TEXT,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site_id, destination_url, source_url)
  );

  CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
  );

  CREATE TABLE IF NOT EXISTS wordpress_connections (
    site_id INTEGER PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
    username TEXT NOT NULL,
    app_password TEXT NOT NULL,
    verified_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );

  CREATE TABLE IF NOT EXISTS link_changes (
    id INTEGER PRIMARY KEY,
    site_id INTEGER REFERENCES sites(id) ON DELETE SET NULL,
    finding_id INTEGER REFERENCES findings(id) ON DELETE SET NULL,
    source_url TEXT NOT NULL,
    post_type TEXT NOT NULL,
    post_id INTEGER NOT NULL,
    post_title TEXT,
    old_url TEXT NOT NULL,
    new_url TEXT NOT NULL,
    backup_content TEXT NOT NULL,
    changed_content TEXT,
    before_hash TEXT NOT NULL,
    after_hash TEXT,
    replacement_count INTEGER NOT NULL DEFAULT 0,
    source_link_text TEXT,
    resolved_finding INTEGER NOT NULL DEFAULT 0,
    old_anchor_text TEXT,
    new_anchor_text TEXT,
    anchor_replacement_count INTEGER NOT NULL DEFAULT 0,
    url_changed INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL CHECK(status IN ('pending','applied','undone','rolled_back','failed')),
    error_message TEXT,
    applied_at TEXT,
    undone_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );

  CREATE TABLE IF NOT EXISTS diagnostic_events (
    id INTEGER PRIMARY KEY,
    scan_id INTEGER REFERENCES scans(id) ON DELETE CASCADE,
    site_id INTEGER REFERENCES sites(id) ON DELETE CASCADE,
    severity TEXT NOT NULL CHECK(severity IN ('info','warning','error')),
    event_type TEXT NOT NULL,
    url TEXT,
    message TEXT NOT NULL,
    details TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  );

  CREATE INDEX IF NOT EXISTS idx_scans_site_created ON scans(site_id, created_at DESC);
  CREATE INDEX IF NOT EXISTS idx_scans_status ON scans(status, created_at);
  CREATE INDEX IF NOT EXISTS idx_findings_site_category ON findings(site_id, category);
  CREATE INDEX IF NOT EXISTS idx_sources_finding ON finding_sources(finding_id);
  CREATE INDEX IF NOT EXISTS idx_link_changes_site_created ON link_changes(site_id, created_at DESC);
  CREATE INDEX IF NOT EXISTS idx_diagnostics_created ON diagnostic_events(created_at DESC);
  CREATE INDEX IF NOT EXISTS idx_diagnostics_site_created ON diagnostic_events(site_id, created_at DESC);
`);

const siteColumns = db.prepare('PRAGMA table_info(sites)').all();
if (!siteColumns.some((column) => column.name === 'excluded_domains')) {
  db.exec("ALTER TABLE sites ADD COLUMN excluded_domains TEXT NOT NULL DEFAULT '[]'");
}

const findingColumns = db.prepare('PRAGMA table_info(findings)').all();
if (!findingColumns.some((column) => column.name === 'resolved_at')) {
  db.exec('ALTER TABLE findings ADD COLUMN resolved_at TEXT');
}
if (!findingColumns.some((column) => column.name === 'error_message')) {
  db.exec('ALTER TABLE findings ADD COLUMN error_message TEXT');
}
if (!findingColumns.some((column) => column.name === 'attempt_count')) {
  db.exec("ALTER TABLE findings ADD COLUMN attempt_count INTEGER NOT NULL DEFAULT 1");
}
if (!findingColumns.some((column) => column.name === 'verification_status')) {
  db.exec("ALTER TABLE findings ADD COLUMN verification_status TEXT NOT NULL DEFAULT 'single'");
}

const scanColumns = db.prepare('PRAGMA table_info(scans)').all();
if (!scanColumns.some((column) => column.name === 'details_retained')) {
  db.exec('ALTER TABLE scans ADD COLUMN details_retained INTEGER NOT NULL DEFAULT 1');
}

const changeColumns = db.prepare('PRAGMA table_info(link_changes)').all();
if (!changeColumns.some((column) => column.name === 'source_link_text')) {
  db.exec('ALTER TABLE link_changes ADD COLUMN source_link_text TEXT');
}
if (!changeColumns.some((column) => column.name === 'resolved_finding')) {
  db.exec('ALTER TABLE link_changes ADD COLUMN resolved_finding INTEGER NOT NULL DEFAULT 0');
}
if (!changeColumns.some((column) => column.name === 'old_anchor_text')) {
  db.exec('ALTER TABLE link_changes ADD COLUMN old_anchor_text TEXT');
}
if (!changeColumns.some((column) => column.name === 'new_anchor_text')) {
  db.exec('ALTER TABLE link_changes ADD COLUMN new_anchor_text TEXT');
}
if (!changeColumns.some((column) => column.name === 'anchor_replacement_count')) {
  db.exec('ALTER TABLE link_changes ADD COLUMN anchor_replacement_count INTEGER NOT NULL DEFAULT 0');
}
if (!changeColumns.some((column) => column.name === 'url_changed')) {
  db.exec('ALTER TABLE link_changes ADD COLUMN url_changed INTEGER NOT NULL DEFAULT 1');
}

db.exec(`
  UPDATE scans AS older
  SET details_retained = 0
  WHERE older.status = 'completed'
    AND EXISTS (
      SELECT 1 FROM scans AS newer
      WHERE newer.site_id = older.site_id
        AND newer.status = 'completed'
        AND newer.mode = older.mode
        AND COALESCE(newer.target_url, '') = COALESCE(older.target_url, '')
        AND newer.id > older.id
    );
  DELETE FROM findings WHERE scan_id IN (
    SELECT id FROM scans WHERE details_retained = 0
  );
`);

const globalExclusions = db.prepare("SELECT value FROM settings WHERE key = 'excluded_domains'").get();
if (!globalExclusions) {
  const migratedDomains = new Set();
  for (const site of db.prepare('SELECT excluded_domains FROM sites').all()) {
    for (const domain of json(site.excluded_domains)) migratedDomains.add(domain);
  }
  db.prepare('INSERT INTO settings (key, value) VALUES (:key, :value)').run({
    key: 'excluded_domains',
    value: JSON.stringify([...migratedDomains].sort())
  });
}

db.exec(`UPDATE sites SET last_scan_at = (
  SELECT completed_at FROM scans
  WHERE scans.site_id = sites.id AND scans.mode = 'site' AND scans.status = 'completed'
  ORDER BY scans.id DESC LIMIT 1
) WHERE EXISTS (
  SELECT 1 FROM scans WHERE scans.site_id = sites.id AND scans.mode = 'site' AND scans.status = 'completed'
)`);

export function rows(sql, params = {}) {
  return db.prepare(sql).all(params);
}

export function row(sql, params = {}) {
  return db.prepare(sql).get(params);
}

export function run(sql, params = {}) {
  return db.prepare(sql).run(params);
}

export function json(value, fallback = []) {
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
}

export function siteFromRow(site) {
  if (!site) return site;
  return {
    ...site,
    sitemap_urls: json(site.sitemap_urls),
    excluded_domains: json(site.excluded_domains),
    crawl_fallback: Boolean(site.crawl_fallback)
  };
}

export function globalExcludedDomains() {
  const setting = row("SELECT value FROM settings WHERE key = 'excluded_domains'");
  return json(setting?.value);
}

export function pruneSupersededScanDetails(scanId) {
  const scan = row('SELECT * FROM scans WHERE id = :id', { id: scanId });
  if (!scan || scan.status !== 'completed') return 0;
  db.exec('BEGIN');
  try {
    const updated = run(`UPDATE scans SET details_retained = 0
      WHERE site_id = :siteId AND status = 'completed' AND mode = :mode
        AND COALESCE(target_url, '') = COALESCE(:targetUrl, '')
        AND id != :id AND details_retained = 1`, {
      siteId: scan.site_id,
      mode: scan.mode,
      targetUrl: scan.target_url,
      id: scan.id
    });
    run('DELETE FROM findings WHERE scan_id IN (SELECT id FROM scans WHERE details_retained = 0)');
    db.exec('COMMIT');
    return Number(updated.changes);
  } catch (error) {
    db.exec('ROLLBACK');
    throw error;
  }
}

function scanCountColumn(category) {
  return { broken: 'broken_count', redirect: 'redirect_count', warning: 'warning_count' }[category];
}

export function markFindingSourceResolved(changeId, finding, source) {
  run('DELETE FROM finding_sources WHERE finding_id=:findingId AND source_url=:sourceUrl', {
    findingId: finding.id,
    sourceUrl: source.source_url
  });
  const remaining = row('SELECT COUNT(*) AS count FROM finding_sources WHERE finding_id=:findingId', { findingId: finding.id });
  if (remaining.count > 0) return false;
  run('UPDATE findings SET resolved_at=CURRENT_TIMESTAMP WHERE id=:id', { id: finding.id });
  const countColumn = scanCountColumn(finding.category);
  if (countColumn) run(`UPDATE scans SET ${countColumn}=MAX(0, ${countColumn}-1) WHERE id=:id`, { id: finding.scan_id });
  if (changeId) run('UPDATE link_changes SET resolved_finding=1 WHERE id=:id', { id: changeId });
  return true;
}

export function restoreFindingSource(change) {
  if (!change.finding_id) return false;
  const finding = row('SELECT scan_id, category, resolved_at FROM findings WHERE id=:id', { id: change.finding_id });
  if (!finding) return false;
  run(`INSERT OR IGNORE INTO finding_sources (finding_id, source_url, link_text)
    VALUES (:findingId, :sourceUrl, :linkText)`, {
    findingId: change.finding_id,
    sourceUrl: change.source_url,
    linkText: change.source_link_text || null
  });
  if (!finding.resolved_at) return false;
  run('UPDATE findings SET resolved_at=NULL WHERE id=:id', { id: change.finding_id });
  const countColumn = scanCountColumn(finding.category);
  if (countColumn) run(`UPDATE scans SET ${countColumn}=${countColumn}+1 WHERE id=:id`, { id: finding.scan_id });
  return true;
}
