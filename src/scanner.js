import { EventEmitter } from 'node:events';
import { db, globalExcludedDomains, json, pruneSupersededScanDetails, row, rows, run, siteFromRow } from './db.js';
import { assertPublicUrl, isExcludedUrl, normalizeUrl } from './utils.js';

const USER_AGENT = 'LinkVagt/1.0 (+broken-link-monitor)';
const REDIRECT_CODES = new Set([301, 302, 303, 307, 308]);
const WARNING_CODES = new Set([401, 403, 408, 425, 429]);
const MAX_REDIRECTS = 10;
const TIMEOUT_MS = Number(process.env.LINKVAGT_TIMEOUT_MS || 12_000);
const RETRY_DELAYS = [350, 1_000];

const ERROR_LABELS = {
  timeout: 'Serveren svarede ikke inden tidsgrænsen',
  dns_error: 'Domænenavnet kunne ikke slås op',
  connection_refused: 'Serveren afviste forbindelsen',
  connection_reset: 'Forbindelsen blev afbrudt under kontrollen',
  tls_error: 'Den sikre HTTPS-forbindelse kunne ikke oprettes',
  network_error: 'Serveren kunne ikke nås',
  redirect_loop: 'Linket sender LinkVagt rundt i en redirect-løkke',
  too_many_redirects: 'Linket har flere end 10 redirects'
};

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

export function classifyRequestError(error) {
  if (error?.name === 'TimeoutError' || error?.name === 'AbortError') return 'timeout';
  const code = String(error?.cause?.code || error?.code || '').toUpperCase();
  if (['ENOTFOUND', 'EAI_AGAIN'].includes(code)) return 'dns_error';
  if (code === 'ECONNREFUSED') return 'connection_refused';
  if (['ECONNRESET', 'EPIPE'].includes(code)) return 'connection_reset';
  if (code.startsWith('ERR_TLS') || code.includes('CERT')) return 'tls_error';
  return 'network_error';
}

export function explainLinkError(errorType, statusCode = null) {
  if (ERROR_LABELS[errorType]) return ERROR_LABELS[errorType];
  if (statusCode === 401) return 'Siden kræver login';
  if (statusCode === 403) return 'Serveren blokerer kontrollen eller nægter adgang';
  if (statusCode === 404) return 'Siden blev ikke fundet';
  if (statusCode === 410) return 'Siden er permanent fjernet';
  if (statusCode === 408) return 'Serveren meldte timeout';
  if (statusCode === 429) return 'Serveren begrænser antallet af forespørgsler';
  if (statusCode >= 500) return 'Serveren har en midlertidig intern fejl';
  return errorType ? errorType.replaceAll('_', ' ') : 'Ukendt fejl';
}

function decodeEntities(value = '') {
  return value
    .replaceAll('&amp;', '&')
    .replaceAll('&quot;', '"')
    .replaceAll('&#39;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)));
}

export function extractLinks(html, pageUrl) {
  const links = [];
  const anchorPattern = /<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))[^>]*>([\s\S]*?)<\/a\s*>/gi;
  let match;
  while ((match = anchorPattern.exec(html))) {
    const href = decodeEntities(match[1] ?? match[2] ?? match[3] ?? '').trim();
    const destination = normalizeUrl(href, pageUrl);
    if (!destination) continue;
    const text = decodeEntities(match[4].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()).slice(0, 300);
    links.push({ destination, text });
  }
  return links;
}

export function extractSitemapLocations(xml, sitemapUrl) {
  const locations = [];
  const pattern = /<loc(?:\s[^>]*)?>([\s\S]*?)<\/loc\s*>/gi;
  let match;
  while ((match = pattern.exec(xml))) {
    const location = normalizeUrl(decodeEntities(match[1].trim()), sitemapUrl);
    if (location) locations.push(location);
  }
  return locations;
}

async function fetchLimited(url, options = {}, maxBytes = 5_000_000) {
  await assertPublicUrl(url);
  const { discardBody = false, accept, ...fetchOptions } = options;
  const response = await fetch(url, {
    ...fetchOptions,
    redirect: 'manual',
    signal: AbortSignal.timeout(TIMEOUT_MS),
    headers: {
      'user-agent': USER_AGENT,
      accept: accept || '*/*',
      ...(options.headers || {})
    }
  });
  if (!response.body || options.method === 'HEAD') return { response, body: '' };
  if (discardBody) {
    await response.body.cancel();
    return { response, body: '' };
  }
  const reader = response.body.getReader();
  const chunks = [];
  let size = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    size += value.byteLength;
    if (size > maxBytes) {
      await reader.cancel();
      throw new Error(`Svar er stoerre end ${Math.round(maxBytes / 1_000_000)} MB`);
    }
    chunks.push(value);
  }
  return { response, body: Buffer.concat(chunks).toString('utf8') };
}

async function requestFollowingRedirects(initialUrl, method = 'HEAD', includeBody = false) {
  const chain = [];
  let current = initialUrl;
  const started = Date.now();
  for (let hop = 0; hop <= MAX_REDIRECTS; hop += 1) {
    const { response, body } = await fetchLimited(current, { method, discardBody: !includeBody }, 5_000_000);
    const location = response.headers.get('location');
    if (REDIRECT_CODES.has(response.status) && location) {
      const next = normalizeUrl(location, current);
      if (!next) throw new Error('Ugyldig redirect-destination');
      chain.push({ url: current, status: response.status, location: next });
      if (chain.some((entry) => entry.url === next)) {
        return { status: response.status, finalUrl: next, chain, responseMs: Date.now() - started, error: 'redirect_loop' };
      }
      await assertPublicUrl(next);
      current = next;
      continue;
    }
    return { status: response.status, finalUrl: current, chain, responseMs: Date.now() - started, body, headers: response.headers };
  }
  return { status: null, finalUrl: current, chain, responseMs: Date.now() - started, error: 'too_many_redirects' };
}

export async function checkLink(url) {
  let result;
  try {
    result = await requestFollowingRedirects(url, 'HEAD');
    if ([400, 403, 404, 405, 501].includes(result.status) || result.status == null) {
      result = await requestFollowingRedirects(url, 'GET');
    }
  } catch (firstError) {
    try {
      result = await requestFollowingRedirects(url, 'GET');
    } catch (error) {
      const name = classifyRequestError(error);
      return { status: null, finalUrl: url, chain: [], responseMs: null, error: name, message: explainLinkError(name), category: 'warning' };
    }
  }

  if (result.error) return { ...result, category: 'broken' };
  if (result.chain.length) {
    if (result.status >= 400) return { ...result, category: 'broken', error: `http_${result.status}` };
    return { ...result, category: 'redirect' };
  }
  if (WARNING_CODES.has(result.status) || result.status >= 500) return { ...result, category: 'warning', error: `http_${result.status}` };
  if (result.status >= 400) return { ...result, category: 'broken', error: `http_${result.status}` };
  return { ...result, category: 'ok' };
}

export async function verifyLink(url, options = {}) {
  const delays = options.retryDelays || RETRY_DELAYS;
  const results = [];
  const maxAttempts = Math.max(1, delays.length + 1);
  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const result = await checkLink(url);
    results.push(result);
    if (['ok', 'redirect'].includes(result.category)) {
      return { ...result, attempts: results.length, verification: results.length === 1 ? 'single' : 'recovered' };
    }
    const definitive = result.category === 'broken' && [404, 410].includes(result.status);
    const needsAnother = attempt === 0 || (!definitive && attempt < maxAttempts - 1);
    if (!needsAnother) break;
    await sleep(delays[Math.min(attempt, delays.length - 1)] || 0);
  }
  const result = results.at(-1);
  const consistent = results.every((entry) => entry.category === result.category
    && entry.status === result.status && entry.error === result.error);
  return {
    ...result,
    attempts: results.length,
    verification: consistent ? 'confirmed' : 'unstable',
    message: result.message || explainLinkError(result.error, result.status)
  };
}

async function getDocument(url, type = 'html') {
  const result = await requestFollowingRedirects(url, 'GET', true);
  if (result.status >= 400 || result.error) throw new Error(`Kunne ikke hente ${url} (${result.status || result.error})`);
  const contentType = result.headers?.get('content-type') || '';
  if (type === 'html' && !contentType.includes('html')) return '';
  return result.body;
}

async function sitemapPages(site) {
  const initial = site.sitemap_urls.length
    ? site.sitemap_urls
    : [normalizeUrl('/sitemap.xml', site.base_url)];
  const queue = initial.filter(Boolean);
  const visited = new Set();
  const pages = new Set();
  while (queue.length && pages.size < site.max_pages) {
    const sitemapUrl = queue.shift();
    if (visited.has(sitemapUrl)) continue;
    visited.add(sitemapUrl);
    try {
      const xml = await getDocument(sitemapUrl, 'xml');
      const locations = extractSitemapLocations(xml, sitemapUrl);
      const isIndex = /<sitemapindex\b/i.test(xml);
      for (const location of locations) {
        if (isIndex && queue.length + visited.size < 1000) queue.push(location);
        else if (new URL(location).origin === new URL(site.base_url).origin) pages.add(location);
        if (pages.size >= site.max_pages) break;
      }
    } catch {
      // A missing conventional sitemap is expected; crawl fallback handles it.
    }
  }
  return [...pages];
}

async function collectLinks(site, scan, onProgress) {
  const excludedDomains = globalExcludedDomains();
  const targetOnly = scan.mode === 'page' && scan.target_url;
  const sitemapList = targetOnly ? [scan.target_url] : await sitemapPages(site);
  const pageQueue = sitemapList.length ? sitemapList : [site.base_url];
  const allowCrawl = targetOnly ? false : (site.crawl_fallback && !sitemapList.length);
  const visited = new Set();
  const destinations = new Map();

  while (pageQueue.length && visited.size < site.max_pages) {
    const batch = [];
    while (pageQueue.length && batch.length < 6 && visited.size + batch.length < site.max_pages) {
      const pageUrl = pageQueue.shift();
      if (!visited.has(pageUrl) && !batch.includes(pageUrl)) batch.push(pageUrl);
    }
    for (const pageUrl of batch) visited.add(pageUrl);
    await Promise.all(batch.map(async (pageUrl) => {
      try {
        const html = await getDocument(pageUrl);
        for (const link of extractLinks(html, pageUrl)) {
          const isInternal = new URL(link.destination).origin === new URL(site.base_url).origin;
          if (allowCrawl && isInternal && !visited.has(link.destination)) pageQueue.push(link.destination);
          if (isExcludedUrl(link.destination, excludedDomains)) continue;
          if (!destinations.has(link.destination)) destinations.set(link.destination, []);
          destinations.get(link.destination).push({ sourceUrl: pageUrl, text: link.text });
        }
      } catch (error) {
        run(`INSERT INTO diagnostic_events
          (scan_id, site_id, severity, event_type, url, message, details)
          VALUES (:scanId, :siteId, 'warning', 'page_fetch_failed', :url, :message, :details)`, {
          scanId: scan.id,
          siteId: site.id,
          url: pageUrl,
          message: `Kildesiden kunne ikke hentes: ${String(error.message || error).slice(0, 500)}`,
          details: JSON.stringify({ error_type: classifyRequestError(error) })
        });
        if (!destinations.has(pageUrl)) destinations.set(pageUrl, [{ sourceUrl: pageUrl, text: '[selve siden]' }]);
      }
    }));
    onProgress({ pages_total: Math.max(pageQueue.length + visited.size, sitemapList.length), pages_scanned: visited.size });
  }
  return destinations;
}

async function mapConcurrent(entries, concurrency, callback) {
  let index = 0;
  const workers = Array.from({ length: Math.min(concurrency, entries.length) }, async () => {
    while (index < entries.length) {
      const current = entries[index++];
      await callback(current);
    }
  });
  await Promise.all(workers);
}

function ignored(siteId, destination, sources) {
  const rules = rows(`SELECT source_url FROM ignore_rules
    WHERE site_id = :siteId AND destination_url = :destination
      AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)`, { siteId, destination });
  return rules.some((rule) => rule.source_url === '' || sources.some((source) => source.sourceUrl === rule.source_url));
}

export class ScanQueue extends EventEmitter {
  constructor() {
    super();
    this.running = false;
    this.timer = null;
  }

  start() {
    run("UPDATE scans SET status = 'queued', started_at = NULL WHERE status = 'running'");
    this.timer = setInterval(() => this.tick(), 10_000);
    this.tick();
  }

  stop() {
    clearInterval(this.timer);
  }

  enqueue(siteId, { mode = 'site', targetUrl = null, trigger = 'manual' } = {}) {
    const result = run(`INSERT INTO scans (site_id, mode, target_url, trigger_type)
      VALUES (:siteId, :mode, :targetUrl, :trigger)`, { siteId, mode, targetUrl, trigger });
    this.tick();
    return Number(result.lastInsertRowid);
  }

  async tick() {
    if (this.running) return;
    const scan = row("SELECT * FROM scans WHERE status = 'queued' ORDER BY created_at, id LIMIT 1");
    if (!scan) return;
    this.running = true;
    try {
      await this.process(scan);
    } finally {
      this.running = false;
      queueMicrotask(() => this.tick());
    }
  }

  async process(scan) {
    const site = siteFromRow(row('SELECT * FROM sites WHERE id = :id', { id: scan.site_id }));
    if (!site) return;
    run("UPDATE scans SET status = 'running', started_at = CURRENT_TIMESTAMP WHERE id = :id", { id: scan.id });
    this.emit('update', scan.id);
    try {
      if (scan.target_url) await assertPublicUrl(scan.target_url);
      const progress = (values) => {
        const fields = Object.keys(values).map((key) => `${key} = :${key}`).join(', ');
        run(`UPDATE scans SET ${fields} WHERE id = :id`, { id: scan.id, ...values });
        this.emit('update', scan.id);
      };
      const links = scan.mode === 'link'
        ? new Map([[scan.target_url, []]])
        : await collectLinks(site, scan, progress);
      const entries = [...links.entries()];
      let checked = 0;
      await mapConcurrent(entries, 8, async ([destination, sources]) => {
        const result = await verifyLink(destination);
        const previous = row(`SELECT first_seen_at FROM findings
          WHERE site_id = :siteId AND destination_url = :destination AND category = :category
          ORDER BY id DESC LIMIT 1`, { siteId: site.id, destination, category: result.category });
        const insert = run(`INSERT INTO findings
          (scan_id, site_id, destination_url, final_url, status_code, category, error_type, error_message,
           attempt_count, verification_status, redirect_chain, response_ms, first_seen_at)
          VALUES (:scanId, :siteId, :destination, :finalUrl, :status, :category, :error, :message,
           :attempts, :verification, :chain, :responseMs, :firstSeen)`, {
          scanId: scan.id,
          siteId: site.id,
          destination,
          finalUrl: result.finalUrl,
          status: result.status,
          category: result.category,
          error: result.error || null,
          message: result.message || explainLinkError(result.error, result.status),
          attempts: result.attempts,
          verification: result.verification,
          chain: JSON.stringify(result.chain || []),
          responseMs: result.responseMs,
          firstSeen: previous?.first_seen_at || new Date().toISOString()
        });
        const findingId = Number(insert.lastInsertRowid);
        const sourceStatement = db.prepare('INSERT OR IGNORE INTO finding_sources (finding_id, source_url, link_text) VALUES (:findingId, :sourceUrl, :text)');
        for (const source of sources) sourceStatement.run({ findingId, sourceUrl: source.sourceUrl, text: source.text || null });
        checked += 1;
        if (checked % 5 === 0 || checked === entries.length) progress({ links_checked: checked });
      });

      const active = entries.filter(([destination, sources]) => !ignored(site.id, destination, sources));
      const counts = { broken: 0, redirect: 0, warning: 0 };
      for (const [destination] of active) {
        const finding = row('SELECT category FROM findings WHERE scan_id = :scanId AND destination_url = :destination', { scanId: scan.id, destination });
        if (counts[finding?.category] != null) counts[finding.category] += 1;
      }
      run(`UPDATE scans SET status = 'completed', completed_at = CURRENT_TIMESTAMP,
        links_checked = :checked, broken_count = :broken, redirect_count = :redirect, warning_count = :warning
        WHERE id = :id`, { id: scan.id, checked: entries.length, ...counts });
      pruneSupersededScanDetails(scan.id);
      if (scan.mode === 'site') {
        run(`UPDATE sites SET last_scan_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
          WHERE id = :id`, { id: site.id });
      }
    } catch (error) {
      run("UPDATE scans SET status = 'failed', completed_at = CURRENT_TIMESTAMP, error_message = :message WHERE id = :id", {
        id: scan.id,
        message: String(error.message || error).slice(0, 1000)
      });
      run(`INSERT INTO diagnostic_events
        (scan_id, site_id, severity, event_type, url, message, details)
        VALUES (:scanId, :siteId, 'error', 'scan_failed', :url, :message, :details)`, {
        scanId: scan.id,
        siteId: site.id,
        url: scan.target_url || site.base_url,
        message: String(error.message || error).slice(0, 1000),
        details: JSON.stringify({ mode: scan.mode })
      });
    }
    this.emit('update', scan.id);
  }
}

export const scanQueue = new ScanQueue();
