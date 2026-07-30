import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { db, globalExcludedDomains, json, markFindingSourceResolved, restoreFindingSource, row, rows, run, siteFromRow } from './db.js';
import { explainLinkError, scanQueue } from './scanner.js';
import { getMailSettings, sendMail, sendScanReport } from './mail.js';
import { assertPublicUrl, csvEscape, normalizeDomain, normalizeUrl, readBody } from './utils.js';
import { decryptSecret, encryptSecret } from './secrets.js';
import { contentHash, replaceAnchors, WordPressClient } from './wordpress.js';
import { backupStatus, createBackup, verifyLatestBackup } from './backup.js';

const PORT = Number(process.env.PORT || 4173);
const HOST = process.env.HOST || '127.0.0.1';
const PUBLIC_DIR = resolve('public');
const sentReports = new Set();

function send(response, status, data, headers = {}) {
  const body = data == null ? '' : JSON.stringify(data);
  response.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store', ...headers });
  response.end(body);
}

function parseId(pathname, pattern) {
  const match = pathname.match(pattern);
  return match ? Number(match[1]) : null;
}

async function validateSite(input, existing = {}) {
  const baseUrl = await assertPublicUrl(input.base_url ?? existing.base_url);
  const sitemapValues = Array.isArray(input.sitemap_urls) ? input.sitemap_urls : (existing.sitemap_urls || []);
  const sitemapUrls = [];
  for (const value of sitemapValues.filter(Boolean)) sitemapUrls.push(await assertPublicUrl(value));
  return {
    name: String(input.name ?? existing.name ?? new URL(baseUrl).hostname).trim().slice(0, 120),
    baseUrl,
    sitemapUrls,
    maxPages: Math.max(1, Math.min(100_000, Number(input.max_pages ?? existing.max_pages ?? 5000))),
    crawlFallback: input.crawl_fallback == null ? Boolean(existing.crawl_fallback ?? true) : Boolean(input.crawl_fallback)
  };
}

function findingList(scanId, filters = {}) {
  const conditions = ['f.scan_id = :scanId', 'f.resolved_at IS NULL'];
  const params = { scanId };
  if (filters.category && filters.category !== 'all') {
    conditions.push('f.category = :category');
    params.category = filters.category;
  }
  if (filters.search) {
    conditions.push('(f.destination_url LIKE :search OR f.final_url LIKE :search)');
    params.search = `%${filters.search}%`;
  }
  const findings = rows(`SELECT f.* FROM findings f WHERE ${conditions.join(' AND ')}
    ORDER BY CASE f.category WHEN 'broken' THEN 1 WHEN 'redirect' THEN 2 WHEN 'warning' THEN 3 ELSE 4 END, f.destination_url LIMIT 5000`, params);
  const rules = rows(`SELECT * FROM ignore_rules WHERE site_id = (SELECT site_id FROM scans WHERE id = :scanId)
    AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)`, { scanId });
  return findings.map((finding) => {
    const sources = rows('SELECT source_url, link_text FROM finding_sources WHERE finding_id = :id ORDER BY source_url', { id: finding.id });
    const matchingRules = rules.filter((rule) => rule.destination_url === finding.destination_url && (rule.source_url === '' || sources.some((source) => source.source_url === rule.source_url)));
    return {
      ...finding,
      redirect_chain: json(finding.redirect_chain),
      sources,
      ignored: matchingRules.length > 0,
      ignore_rule_id: matchingRules[0]?.id || null
    };
  }).filter((finding) => filters.includeIgnored || !finding.ignored);
}

function wordpressConnection(siteId, requireVerified = true) {
  const connection = row('SELECT * FROM wordpress_connections WHERE site_id = :siteId', { siteId });
  if (!connection) throw new Error('WordPress er ikke forbundet til hjemmesiden');
  if (requireVerified && !connection.verified_at) throw new Error('WordPress-forbindelsen skal testes før linkrettelser');
  return { ...connection, appPassword: decryptSecret(connection.app_password) };
}

function wordpressClient(siteId, requireVerified = true) {
  const site = siteFromRow(row('SELECT * FROM sites WHERE id = :id', { id: siteId }));
  if (!site) throw new Error('Hjemmesiden findes ikke');
  return { site, client: new WordPressClient(site, wordpressConnection(siteId, requireVerified)) };
}

function findingSource(findingId, sourceUrl) {
  const finding = row('SELECT * FROM findings WHERE id = :id', { id: findingId });
  if (!finding) throw new Error('Linkfundet findes ikke');
  const source = row('SELECT * FROM finding_sources WHERE finding_id = :findingId AND source_url = :sourceUrl', { findingId, sourceUrl });
  if (!source) throw new Error('Linket blev ikke fundet paa den valgte kildeside');
  return { finding, source };
}

function rawContent(post) {
  if (typeof post?.content?.raw !== 'string') throw new Error('WordPress udleverede ikke redigerbart indhold');
  return post.content.raw;
}

async function prepareWordpressChange(findingId, sourceUrl, newUrl, newAnchorText) {
  const { finding, source } = findingSource(findingId, sourceUrl);
  const validatedNewUrl = await assertPublicUrl(newUrl);
  const urlChanged = normalizeUrl(validatedNewUrl) !== normalizeUrl(finding.destination_url);
  const requestedAnchorText = String(newAnchorText || '').trim().slice(0, 500);
  const anchorTextChanged = Boolean(requestedAnchorText && requestedAnchorText !== String(source.link_text || '').trim());
  if (!urlChanged && !anchorTextChanged) throw new Error('Hverken link eller ankertekst er ændret');
  const { client } = wordpressClient(finding.site_id);
  const post = await client.findEditablePost(sourceUrl);
  const original = rawContent(post);
  const replacement = replaceAnchors(original, finding.destination_url, validatedNewUrl, anchorTextChanged ? requestedAnchorText : null, post.link);
  if (!replacement.count) throw new Error('Linket findes ikke i WordPress-sidens almindelige indhold');
  if (replacement.formattedAnchorCount) throw new Error('Ankerteksten indeholder formatering eller et ikon og kan derfor ikke ændres sikkert automatisk');
  return {
    finding, source, client, post, original, replacement,
    newUrl: validatedNewUrl,
    newAnchorText: anchorTextChanged ? requestedAnchorText : source.link_text || '',
    anchorTextChanged,
    urlChanged
  };
}

async function api(request, response, url) {
  const { pathname, searchParams } = url;

  if (request.method === 'GET' && pathname === '/api/summary') {
    const totals = row('SELECT COUNT(*) AS sites FROM sites');
    const latest = rows(`SELECT s.*, sites.name AS site_name FROM scans s JOIN sites ON sites.id = s.site_id
      WHERE s.details_retained = 1 OR s.status != 'completed'
      ORDER BY s.id DESC LIMIT 12`);
    const current = row("SELECT * FROM scans WHERE status = 'running' ORDER BY id LIMIT 1");
    const issueTotals = row(`SELECT
      COALESCE(SUM(broken_count),0) AS broken,
      COALESCE(SUM(redirect_count),0) AS redirects,
      COALESCE(SUM(warning_count),0) AS warnings
      FROM scans WHERE id IN (SELECT MAX(id) FROM scans WHERE status = 'completed' AND mode = 'site' GROUP BY site_id)`);
    return send(response, 200, { ...totals, ...issueTotals, latest, current });
  }

  if (request.method === 'GET' && pathname === '/api/sites') {
    const sites = rows(`SELECT sites.*,
      scans.id AS latest_scan_id, scans.status AS latest_scan_status,
      scans.broken_count, scans.redirect_count, scans.warning_count, scans.links_checked,
      EXISTS(SELECT 1 FROM wordpress_connections wp WHERE wp.site_id = sites.id) AS wordpress_configured,
      EXISTS(SELECT 1 FROM wordpress_connections wp WHERE wp.site_id = sites.id AND wp.verified_at IS NOT NULL) AS wordpress_connected
      FROM sites LEFT JOIN scans ON scans.id = (SELECT id FROM scans WHERE site_id = sites.id AND mode = 'site' ORDER BY id DESC LIMIT 1)
      ORDER BY sites.name`).map(siteFromRow);
    return send(response, 200, sites);
  }

  if (request.method === 'POST' && pathname === '/api/sites') {
    const data = await validateSite(await readBody(request));
    const result = run(`INSERT INTO sites
      (name, base_url, sitemap_urls, max_pages, crawl_fallback)
      VALUES (:name, :baseUrl, :sitemapUrls, :maxPages, :crawlFallback)`, {
      ...data,
      sitemapUrls: JSON.stringify(data.sitemapUrls),
      crawlFallback: Number(data.crawlFallback)
    });
    return send(response, 201, siteFromRow(row('SELECT * FROM sites WHERE id = :id', { id: Number(result.lastInsertRowid) })));
  }

  const siteId = parseId(pathname, /^\/api\/sites\/(\d+)$/);
  if (siteId && request.method === 'PUT') {
    const existing = siteFromRow(row('SELECT * FROM sites WHERE id = :id', { id: siteId }));
    if (!existing) return send(response, 404, { error: 'Hjemmesiden findes ikke' });
    const data = await validateSite(await readBody(request), existing);
    run(`UPDATE sites SET name=:name, base_url=:baseUrl, sitemap_urls=:sitemapUrls,
      max_pages=:maxPages, crawl_fallback=:crawlFallback,
      updated_at=CURRENT_TIMESTAMP WHERE id=:id`, {
      id: siteId, ...data, sitemapUrls: JSON.stringify(data.sitemapUrls),
      crawlFallback: Number(data.crawlFallback)
    });
    return send(response, 200, siteFromRow(row('SELECT * FROM sites WHERE id = :id', { id: siteId })));
  }
  if (siteId && request.method === 'DELETE') {
    run('DELETE FROM sites WHERE id = :id', { id: siteId });
    return send(response, 204, null);
  }

  const scanSiteId = parseId(pathname, /^\/api\/sites\/(\d+)\/scan$/);
  if (scanSiteId && request.method === 'POST') {
    const site = siteFromRow(row('SELECT * FROM sites WHERE id = :id', { id: scanSiteId }));
    if (!site) return send(response, 404, { error: 'Hjemmesiden findes ikke' });
    const body = await readBody(request);
    let targetUrl = null;
    let mode = 'site';
    if (['page', 'link'].includes(body.mode) && !body.target_url) throw new Error('URL mangler');
    if (body.target_url) {
      targetUrl = await assertPublicUrl(normalizeUrl(body.target_url, site.base_url));
      mode = body.mode === 'link' ? 'link' : 'page';
      if (mode === 'page' && new URL(targetUrl).origin !== new URL(site.base_url).origin) throw new Error('Enkeltsiden skal ligge paa det valgte website');
    }
    const duplicate = row("SELECT id FROM scans WHERE site_id = :siteId AND status IN ('queued','running')", { siteId: scanSiteId });
    if (duplicate) return send(response, 409, { error: 'Der er allerede en scanning i koe eller i gang', scan_id: duplicate.id });
    const scanId = scanQueue.enqueue(scanSiteId, { mode, targetUrl });
    return send(response, 202, { scan_id: scanId });
  }

  if (request.method === 'GET' && pathname === '/api/scans') {
    const site = Number(searchParams.get('site_id'));
    const visibleScans = "WHERE (scans.details_retained = 1 OR scans.status != 'completed')";
    const scans = site
      ? rows(`SELECT * FROM scans ${visibleScans} AND scans.site_id = :site ORDER BY id DESC LIMIT 100`, { site })
      : rows(`SELECT scans.*, sites.name AS site_name FROM scans JOIN sites ON sites.id=scans.site_id ${visibleScans} ORDER BY scans.id DESC LIMIT 100`);
    return send(response, 200, scans);
  }

  if (request.method === 'GET' && pathname === '/api/findings') {
    const scanId = Number(searchParams.get('scan_id'));
    if (!scanId) return send(response, 400, { error: 'scan_id mangler' });
    const scan = row('SELECT details_retained FROM scans WHERE id = :id', { id: scanId });
    if (!scan) return send(response, 404, { error: 'Scanningen findes ikke' });
    if (!scan.details_retained) return send(response, 410, { error: 'Kun oversigten fra denne scanning er gemt' });
    return send(response, 200, findingList(scanId, {
      category: searchParams.get('category'),
      search: searchParams.get('search'),
      includeIgnored: searchParams.get('include_ignored') === '1'
    }));
  }

  if (request.method === 'GET' && pathname === '/api/diagnostics') {
    const siteId = Number(searchParams.get('site_id')) || null;
    const limit = Math.max(10, Math.min(250, Number(searchParams.get('limit')) || 100));
    const siteCondition = siteId ? 'AND f.site_id = :siteId' : '';
    const eventSiteCondition = siteId ? 'AND d.site_id = :siteId' : '';
    const params = siteId ? { siteId } : {};
    const findings = rows(`SELECT
        'finding-' || f.id AS id, f.site_id, sites.name AS site_name, f.scan_id,
        CASE WHEN f.category = 'broken' THEN 'error' ELSE 'warning' END AS severity,
        'link_check' AS event_type, f.destination_url AS url,
        f.error_type, f.status_code, f.error_message AS stored_message,
        f.attempt_count, f.verification_status, f.last_seen_at AS created_at
      FROM findings f
      JOIN sites ON sites.id = f.site_id
      JOIN scans ON scans.id = f.scan_id
      WHERE f.resolved_at IS NULL
        AND (f.category = 'warning' OR f.error_type IS NOT NULL)
        AND scans.details_retained = 1 ${siteCondition}
      ORDER BY f.id DESC LIMIT :limit`, { ...params, limit });
    const events = rows(`SELECT
        'event-' || d.id AS id, d.site_id, sites.name AS site_name, d.scan_id,
        d.severity, d.event_type, d.url, NULL AS error_type, NULL AS status_code,
        d.message AS stored_message, NULL AS attempt_count, NULL AS verification_status,
        d.created_at
      FROM diagnostic_events d
      LEFT JOIN sites ON sites.id = d.site_id
      WHERE 1=1 ${eventSiteCondition}
      ORDER BY d.id DESC LIMIT :limit`, { ...params, limit });
    const items = [...findings, ...events]
      .map((item) => ({
        ...item,
        message: item.stored_message || explainLinkError(item.error_type, item.status_code)
      }))
      .sort((left, right) => String(right.created_at).localeCompare(String(left.created_at)))
      .slice(0, limit)
      .map(({ stored_message, ...item }) => item);
    const counts = items.reduce((totals, item) => {
      totals[item.severity] = (totals[item.severity] || 0) + 1;
      return totals;
    }, { error: 0, warning: 0 });
    return send(response, 200, { items, counts });
  }

  if (request.method === 'POST' && pathname === '/api/ignore') {
    const body = await readBody(request);
    if (!body.site_id || !body.destination_url) return send(response, 400, { error: 'Website og link er paakraevet' });
    const result = run(`INSERT INTO ignore_rules (site_id, destination_url, source_url, reason, expires_at)
      VALUES (:siteId, :destination, :source, :reason, :expires)
      ON CONFLICT(site_id, destination_url, source_url) DO UPDATE SET reason=excluded.reason, expires_at=excluded.expires_at`, {
      siteId: Number(body.site_id), destination: body.destination_url, source: body.source_url || '',
      reason: String(body.reason || '').slice(0, 500) || null, expires: body.expires_at || null
    });
    return send(response, 201, { id: Number(result.lastInsertRowid) });
  }

  const ignoreId = parseId(pathname, /^\/api\/ignore\/(\d+)$/);
  if (ignoreId && request.method === 'DELETE') {
    run('DELETE FROM ignore_rules WHERE id = :id', { id: ignoreId });
    return send(response, 204, null);
  }

  const wordpressSiteId = parseId(pathname, /^\/api\/sites\/(\d+)\/wordpress$/);
  if (wordpressSiteId && request.method === 'GET') {
    const connection = row('SELECT username, verified_at, created_at, updated_at FROM wordpress_connections WHERE site_id = :siteId', { siteId: wordpressSiteId });
    return send(response, 200, connection ? { ...connection, connected: true, app_password: '********' } : { connected: false });
  }
  if (wordpressSiteId && request.method === 'PUT') {
    if (!row('SELECT id FROM sites WHERE id = :id', { id: wordpressSiteId })) return send(response, 404, { error: 'Hjemmesiden findes ikke' });
    const body = await readBody(request);
    const username = String(body.username || '').trim();
    const current = row('SELECT * FROM wordpress_connections WHERE site_id = :siteId', { siteId: wordpressSiteId });
    const suppliedPassword = String(body.app_password || '').trim();
    if (!username) throw new Error('WordPress-brugernavn mangler');
    if (!current && (!suppliedPassword || suppliedPassword === '********')) throw new Error('WordPress-applikationskodeord mangler');
    const currentPassword = current ? decryptSecret(current.app_password) : null;
    const passwordChanged = Boolean(suppliedPassword && suppliedPassword !== '********' && suppliedPassword !== currentPassword);
    const encryptedPassword = passwordChanged
      ? encryptSecret(suppliedPassword)
      : current.app_password;
    const credentialsChanged = !current || current.username !== username || passwordChanged;
    const verifiedAt = credentialsChanged ? null : current.verified_at;
    run(`INSERT INTO wordpress_connections (site_id, username, app_password, verified_at)
      VALUES (:siteId, :username, :password, :verifiedAt)
      ON CONFLICT(site_id) DO UPDATE SET username=excluded.username, app_password=excluded.app_password,
      verified_at=excluded.verified_at, updated_at=CURRENT_TIMESTAMP`, { siteId: wordpressSiteId, username, password: encryptedPassword, verifiedAt });
    return send(response, 200, { connected: true, username, app_password: '********', verified_at: verifiedAt });
  }
  if (wordpressSiteId && request.method === 'DELETE') {
    run('DELETE FROM wordpress_connections WHERE site_id = :siteId', { siteId: wordpressSiteId });
    return send(response, 204, null);
  }

  const wordpressTestId = parseId(pathname, /^\/api\/sites\/(\d+)\/wordpress\/test$/);
  if (wordpressTestId && request.method === 'POST') {
    const { client } = wordpressClient(wordpressTestId, false);
    const user = await client.testConnection();
    run('UPDATE wordpress_connections SET verified_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE site_id=:siteId', { siteId: wordpressTestId });
    return send(response, 200, { ok: true, user: user.name });
  }

  const wordpressChangesId = parseId(pathname, /^\/api\/sites\/(\d+)\/wordpress\/changes$/);
  if (wordpressChangesId && request.method === 'GET') {
    return send(response, 200, rows(`SELECT id, source_url, post_title, old_url, new_url, replacement_count,
      old_anchor_text, new_anchor_text, anchor_replacement_count, url_changed,
      status, error_message, applied_at, undone_at, created_at FROM link_changes
      WHERE site_id=:siteId ORDER BY id DESC LIMIT 30`, { siteId: wordpressChangesId }));
  }

  if (request.method === 'POST' && pathname === '/api/wordpress/preview') {
    const body = await readBody(request);
    const prepared = await prepareWordpressChange(Number(body.finding_id), String(body.source_url || ''), String(body.new_url || ''), body.new_anchor_text);
    return send(response, 200, {
      post_id: prepared.post.id,
      post_type: prepared.post.postType,
      post_title: prepared.post.title?.raw || prepared.post.title?.rendered || prepared.post.link,
      source_url: prepared.post.link,
      old_url: prepared.finding.destination_url,
      new_url: prepared.newUrl,
      replacement_count: prepared.replacement.count,
      old_anchor_text: prepared.source.link_text || '',
      new_anchor_text: prepared.newAnchorText,
      anchor_text_changed: prepared.anchorTextChanged,
      anchor_replacement_count: prepared.replacement.anchorTextCount,
      url_changed: prepared.urlChanged,
      before_hash: contentHash(prepared.original)
    });
  }

  if (request.method === 'POST' && pathname === '/api/wordpress/apply') {
    const body = await readBody(request);
    const prepared = await prepareWordpressChange(Number(body.finding_id), String(body.source_url || ''), String(body.new_url || ''), body.new_anchor_text);
    const beforeHash = contentHash(prepared.original);
    if (!body.before_hash || body.before_hash !== beforeHash) throw new Error('Siden er aendret siden forhåndsvisningen. Kontroller ændringen igen');
    try {
      await createBackup('pre-wordpress');
    } catch (error) {
      throw new Error(`Linkrettelsen blev afbrudt, fordi sikkerhedsbackuppen fejlede: ${error.message}`);
    }
    const insert = run(`INSERT INTO link_changes
      (site_id, finding_id, source_url, post_type, post_id, post_title, old_url, new_url,
       backup_content, changed_content, before_hash, replacement_count, source_link_text,
       old_anchor_text, new_anchor_text, anchor_replacement_count, url_changed, status)
      VALUES (:siteId, :findingId, :sourceUrl, :postType, :postId, :title, :oldUrl, :newUrl,
       :backup, :changed, :beforeHash, :count, :sourceLinkText,
       :oldAnchorText, :newAnchorText, :anchorCount, :urlChanged, 'pending')`, {
      siteId: prepared.finding.site_id,
      findingId: prepared.finding.id,
      sourceUrl: body.source_url,
      postType: prepared.post.postType,
      postId: prepared.post.id,
      title: prepared.post.title?.raw || prepared.post.title?.rendered || null,
      oldUrl: prepared.finding.destination_url,
      newUrl: prepared.newUrl,
      backup: prepared.original,
      changed: prepared.replacement.content,
      beforeHash,
      count: prepared.replacement.count,
      sourceLinkText: prepared.source.link_text || null,
      oldAnchorText: prepared.source.link_text || null,
      newAnchorText: prepared.newAnchorText || null,
      anchorCount: prepared.replacement.anchorTextCount,
      urlChanged: Number(prepared.urlChanged)
    });
    const changeId = Number(insert.lastInsertRowid);
    let updateAttempted = false;
    try {
      const fresh = await prepared.client.getPost(prepared.post.postType, prepared.post.id);
      if (contentHash(rawContent(fresh)) !== beforeHash) throw new Error('Siden blev ændret i WordPress under godkendelsen');
      updateAttempted = true;
      await prepared.client.updateContent(prepared.post.postType, prepared.post.id, prepared.replacement.content);
      const verified = await prepared.client.getPost(prepared.post.postType, prepared.post.id);
      const verifiedContent = rawContent(verified);
      if (verifiedContent !== prepared.replacement.content) throw new Error('WordPress gemte ikke præcis den godkendte ændring');
      const afterHash = contentHash(verifiedContent);
      db.exec('BEGIN');
      try {
        run(`UPDATE link_changes SET status='applied', after_hash=:afterHash, applied_at=CURRENT_TIMESTAMP WHERE id=:id`, { id: changeId, afterHash });
        if (prepared.urlChanged) markFindingSourceResolved(changeId, prepared.finding, prepared.source);
        db.exec('COMMIT');
      } catch (localError) {
        db.exec('ROLLBACK');
        throw localError;
      }
      return send(response, 200, {
        id: changeId,
        status: 'applied',
        replacement_count: prepared.replacement.count,
        anchor_replacement_count: prepared.replacement.anchorTextCount
      });
    } catch (error) {
      let status = 'failed';
      let message = String(error.message || error);
      if (updateAttempted) {
        try {
          await prepared.client.updateContent(prepared.post.postType, prepared.post.id, prepared.original);
          const restored = await prepared.client.getPost(prepared.post.postType, prepared.post.id);
          if (contentHash(rawContent(restored)) !== beforeHash) throw new Error('Backup kunne ikke verificeres');
          status = 'rolled_back';
          message += ' Originalen blev automatisk gendannet.';
        } catch (rollbackError) {
          message += ` Automatisk gendannelse fejlede: ${rollbackError.message}`;
        }
      }
      run('UPDATE link_changes SET status=:status, error_message=:message WHERE id=:id', { id: changeId, status, message: message.slice(0, 2000) });
      throw new Error(message);
    }
  }

  const undoChangeId = parseId(pathname, /^\/api\/wordpress\/changes\/(\d+)\/undo$/);
  if (undoChangeId && request.method === 'POST') {
    const change = row("SELECT * FROM link_changes WHERE id=:id AND status='applied'", { id: undoChangeId });
    if (!change) throw new Error('Ændringen findes ikke eller er allerede fortrudt');
    const { client } = wordpressClient(change.site_id);
    const current = await client.getPost(change.post_type, change.post_id);
    if (contentHash(rawContent(current)) !== change.after_hash) throw new Error('Siden er ændret efter linkrettelsen og kan derfor ikke fortrydes automatisk');
    let localTransaction = false;
    try {
      await client.updateContent(change.post_type, change.post_id, change.backup_content);
      const restored = await client.getPost(change.post_type, change.post_id);
      if (contentHash(rawContent(restored)) !== change.before_hash) throw new Error('WordPress kunne ikke verificere det gendannede indhold');
      db.exec('BEGIN');
      localTransaction = true;
      restoreFindingSource(change);
      run("UPDATE link_changes SET status='undone', undone_at=CURRENT_TIMESTAMP WHERE id=:id", { id: undoChangeId });
      db.exec('COMMIT');
      localTransaction = false;
    } catch (error) {
      if (localTransaction) db.exec('ROLLBACK');
      try { await client.updateContent(change.post_type, change.post_id, change.changed_content); } catch {}
      throw new Error(`${error.message}. Det rettede indhold blev forsøgt gendannet`);
    }
    return send(response, 200, { id: undoChangeId, status: 'undone' });
  }

  if (request.method === 'GET' && pathname === '/api/export.csv') {
    const scanId = Number(searchParams.get('scan_id'));
    const scan = row('SELECT details_retained FROM scans WHERE id = :id', { id: scanId });
    if (!scan) return send(response, 404, { error: 'Scanningen findes ikke' });
    if (!scan.details_retained) return send(response, 410, { error: 'Detaljerne fra denne scanning er ikke længere gemt' });
    const findings = findingList(scanId, { includeIgnored: true });
    const lines = [['Type', 'Status', 'Link', 'Slutadresse', 'Kildesider', 'Ignoreret', 'Foerst set']];
    for (const finding of findings) lines.push([
      finding.category, finding.status_code || finding.error_type || '', finding.destination_url,
      finding.final_url || '', finding.sources.map((source) => source.source_url).join('\n'), finding.ignored ? 'Ja' : 'Nej', finding.first_seen_at
    ]);
    const csv = `\uFEFF${lines.map((line) => line.map(csvEscape).join(';')).join('\r\n')}`;
    response.writeHead(200, { 'content-type': 'text/csv; charset=utf-8', 'content-disposition': `attachment; filename="linkvagt-scan-${scanId}.csv"` });
    return response.end(csv);
  }

  if (request.method === 'GET' && pathname === '/api/settings/mail') return send(response, 200, getMailSettings());
  if (request.method === 'PUT' && pathname === '/api/settings/mail') {
    const body = await readBody(request);
    const current = getMailSettings(true);
    const allowed = ['mail_enabled', 'mail_include_clean', 'mail_to', 'mail_from', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_helo'];
    const statement = db.prepare('INSERT INTO settings (key,value) VALUES (:key,:value) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    for (const key of allowed) {
      if (body[key] == null || (key === 'smtp_password' && body[key] === '********')) continue;
      statement.run({ key, value: String(body[key]) });
    }
    return send(response, 200, getMailSettings());
  }
  if (request.method === 'POST' && pathname === '/api/settings/mail/test') {
    const config = getMailSettings(true);
    await sendMail(config, { subject: 'LinkVagt testmail', html: '<h1>LinkVagt virker</h1><p>SMTP-forbindelsen er konfigureret korrekt.</p>' });
    return send(response, 200, { ok: true });
  }

  if (request.method === 'GET' && pathname === '/api/settings/exclusions') {
    return send(response, 200, { excluded_domains: globalExcludedDomains() });
  }
  if (request.method === 'PUT' && pathname === '/api/settings/exclusions') {
    const body = await readBody(request);
    const values = Array.isArray(body.excluded_domains) ? body.excluded_domains : [];
    const domains = [];
    for (const value of values.filter(Boolean)) {
      const domain = normalizeDomain(value);
      if (!domain) throw new Error(`Ugyldigt ekskluderet domaene: ${value}`);
      if (!domains.includes(domain)) domains.push(domain);
    }
    run(`INSERT INTO settings (key, value) VALUES ('excluded_domains', :value)
      ON CONFLICT(key) DO UPDATE SET value=excluded.value`, { value: JSON.stringify(domains) });
    return send(response, 200, { excluded_domains: domains });
  }

  if (request.method === 'GET' && pathname === '/api/backups') {
    return send(response, 200, backupStatus());
  }
  if (request.method === 'POST' && pathname === '/api/backups') {
    return send(response, 201, await createBackup('manual'));
  }
  if (request.method === 'POST' && pathname === '/api/backups/verify') {
    return send(response, 200, await verifyLatestBackup());
  }

  return send(response, 404, { error: 'API-ruten findes ikke' });
}

const mime = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };

async function serveStatic(response, pathname) {
  const requestPath = pathname === '/' ? '/index.html' : pathname;
  const filePath = normalize(join(PUBLIC_DIR, requestPath));
  if (!filePath.startsWith(PUBLIC_DIR)) return send(response, 403, { error: 'Afvist' });
  try {
    const content = await readFile(filePath);
    response.writeHead(200, { 'content-type': mime[extname(filePath)] || 'application/octet-stream', 'cache-control': 'no-cache' });
    response.end(content);
  } catch {
    const content = await readFile(join(PUBLIC_DIR, 'index.html'));
    response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    response.end(content);
  }
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url, `http://${request.headers.host || 'localhost'}`);
  try {
    if (url.pathname.startsWith('/api/')) await api(request, response, url);
    else await serveStatic(response, url.pathname);
  } catch (error) {
    const status = error?.code === 'SQLITE_CONSTRAINT_UNIQUE' ? 409 : 400;
    send(response, status, { error: String(error.message || error) });
  }
});

scanQueue.on('update', async (scanId) => {
  const scan = row('SELECT status, mode FROM scans WHERE id = :id', { id: scanId });
  if (scan?.status !== 'completed' || sentReports.has(scanId)) return;
  sentReports.add(scanId);
  if (scan.mode === 'site') {
    try { await createBackup('post-scan'); } catch (error) { console.error(`Backup efter scanning fejlede: ${error.message}`); }
  }
  try { await sendScanReport(scanId); } catch (error) { console.error(`Mailrapport kunne ikke sendes: ${error.message}`); }
});

try { await createBackup('startup', { force: false }); } catch (error) { console.error(`Opstartsbackup fejlede: ${error.message}`); }
scanQueue.start();
server.listen(PORT, HOST, () => console.log(`LinkVagt koerer paa http://${HOST}:${PORT}`));

function shutdown() {
  scanQueue.stop();
  server.close(() => { db.close(); process.exit(0); });
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
