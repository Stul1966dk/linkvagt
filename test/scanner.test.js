import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const testDir = mkdtempSync(join(tmpdir(), 'linkvagt-'));
process.env.LINKVAGT_DB = join(testDir, 'test.db');
process.env.LINKVAGT_KEY_FILE = join(testDir, 'test.key');

const { checkLink, classifyRequestError, extractLinks, extractSitemapLocations, verifyLink } = await import('../src/scanner.js');
const { isExcludedUrl, isPrivateIp, normalizeDomain, normalizeUrl } = await import('../src/utils.js');
const { db, markFindingSourceResolved, pruneSupersededScanDetails, restoreFindingSource, row, run } = await import('../src/db.js');
const { contentHash, replaceAnchorHrefs, replaceAnchors } = await import('../src/wordpress.js');
const { decryptSecret, encryptSecret } = await import('../src/secrets.js');

test.after(() => {
  db.close();
  rmSync(testDir, { recursive: true, force: true });
});

test('normaliserer links og fjerner fragmenter', () => {
  assert.equal(normalizeUrl('/side#afsnit', 'https://example.dk/start'), 'https://example.dk/side');
  assert.equal(normalizeUrl('mailto:test@example.dk', 'https://example.dk'), null);
});

test('udtraekker links, relative adresser og linktekst fra HTML', () => {
  const html = `<nav><a href="/produkt?a=1&amp;b=2"><strong>Se</strong> produkt</a></nav>
    <a href='https://andet.dk/x#top'>Eksternt</a><a href="mailto:x@y.dk">Mail</a>`;
  assert.deepEqual(extractLinks(html, 'https://example.dk/forside'), [
    { destination: 'https://example.dk/produkt?a=1&b=2', text: 'Se produkt' },
    { destination: 'https://andet.dk/x', text: 'Eksternt' }
  ]);
});

test('udtraekker adresser fra sitemap og sitemap-indeks', () => {
  const xml = `<?xml version="1.0"?><urlset><url><loc>https://example.dk/</loc></url>
    <url><loc>https://example.dk/a&amp;b</loc></url></urlset>`;
  assert.deepEqual(extractSitemapLocations(xml, 'https://example.dk/sitemap.xml'), [
    'https://example.dk/', 'https://example.dk/a&b'
  ]);
});

test('dobbelttjekker HEAD 404 med GET foer link markeres doedt', async () => {
  const originalFetch = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, method: options.method });
    const status = options.method === 'HEAD' ? 404 : 200;
    return new Response(options.method === 'HEAD' ? null : '<html></html>', { status });
  };

  try {
    const result = await checkLink('https://example.dk/findes');
    assert.equal(result.category, 'ok');
    assert.equal(result.status, 200);
    assert.deepEqual(calls.map((call) => call.method), ['HEAD', 'GET']);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('bekraefter en stabil 404 med to separate kontroller', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async (_url, options = {}) => {
    calls += 1;
    return new Response(options.method === 'HEAD' ? null : 'Ikke fundet', { status: 404 });
  };
  try {
    const result = await verifyLink('https://example.dk/mangler', { retryDelays: [0, 0] });
    assert.equal(result.category, 'broken');
    assert.equal(result.verification, 'confirmed');
    assert.equal(result.attempts, 2);
    assert.equal(calls, 4);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('frikender en midlertidig serverfejl hvis linket virker ved genkontrol', async () => {
  const originalFetch = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    return new Response(calls < 3 ? 'Midlertidig fejl' : 'OK', { status: calls < 3 ? 503 : 200 });
  };
  try {
    const result = await verifyLink('https://example.dk/ustabil', { retryDelays: [0, 0] });
    assert.equal(result.category, 'ok');
    assert.equal(result.verification, 'recovered');
    assert.equal(result.attempts, 3);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('skelner mellem DNS, timeout og afvist forbindelse', () => {
  assert.equal(classifyRequestError({ cause: { code: 'ENOTFOUND' } }), 'dns_error');
  assert.equal(classifyRequestError({ name: 'TimeoutError' }), 'timeout');
  assert.equal(classifyRequestError({ cause: { code: 'ECONNREFUSED' } }), 'connection_refused');
});

test('identificerer private netvaerksadresser', () => {
  assert.equal(isPrivateIp('127.0.0.1'), true);
  assert.equal(isPrivateIp('192.168.1.8'), true);
  assert.equal(isPrivateIp('172.20.0.1'), true);
  assert.equal(isPrivateIp('8.8.8.8'), false);
});

test('normaliserer og matcher ekskluderede domaener', () => {
  assert.equal(normalizeDomain('https://www.Partner-Ads.dk/path'), 'partner-ads.dk');
  assert.equal(normalizeDomain('*.partner-ads.dk'), 'partner-ads.dk');
  assert.equal(normalizeDomain('ikke et domaene'), null);
  assert.equal(isExcludedUrl('https://partner-ads.dk/click?id=1', ['partner-ads.dk']), true);
  assert.equal(isExcludedUrl('https://tracking.partner-ads.dk/x', ['partner-ads.dk']), true);
  assert.equal(isExcludedUrl('https://notpartner-ads.dk/', ['partner-ads.dk']), false);
});

test('retter kun matchende href-vaerdier i links', () => {
  const original = '<p><a class="x" href="https://old.dk/a?x=1&amp;y=2">Link</a> https://old.dk/a?x=1&amp;y=2</p><a href="/andet">Andet</a>';
  const result = replaceAnchorHrefs(original, 'https://old.dk/a?x=1&y=2', 'https://new.dk/side?a=1&b=2', 'https://example.dk/post');
  assert.equal(result.count, 1);
  assert.equal(result.content, '<p><a class="x" href="https://new.dk/side?a=1&amp;b=2">Link</a> https://old.dk/a?x=1&amp;y=2</p><a href="/andet">Andet</a>');
  assert.notEqual(contentHash(original), contentHash(result.content));
});

test('retter almindelig ankertekst uden at aendre anden HTML', () => {
  const original = `<p><A class="cta" HREF = 'https://old.dk/'>Gammel &amp; tekst</A></p>`;
  const result = replaceAnchors(original, 'https://old.dk/', 'https://new.dk/', 'Ny & bedre tekst', 'https://example.dk/');
  assert.equal(result.count, 1);
  assert.equal(result.anchorTextCount, 1);
  assert.equal(result.formattedAnchorCount, 0);
  assert.equal(result.content, `<p><A class="cta" HREF = 'https://new.dk/'>Ny &amp; bedre tekst</A></p>`);
});

test('markerer formateret ankertekst som usikker og bevarer formateringen', () => {
  const original = '<a href="https://old.dk/"><strong>Gammel tekst</strong></a>';
  const result = replaceAnchors(original, 'https://old.dk/', 'https://new.dk/', 'Ny tekst', 'https://example.dk/');
  assert.equal(result.formattedAnchorCount, 1);
  assert.equal(result.anchorTextCount, 0);
  assert.match(result.content, /<strong>Gammel tekst<\/strong>/);
});

test('krypterer WordPress-kodeord lokalt', () => {
  const encrypted = encryptSecret('hemmeligt kodeord');
  assert.notEqual(encrypted, 'hemmeligt kodeord');
  assert.equal(decryptSecret(encrypted), 'hemmeligt kodeord');
});

test('skjuler foerst et rettet fund naar sidste kildeside er fjernet', () => {
  const siteId = Number(run("INSERT INTO sites (name, base_url) VALUES ('Test', 'https://test.example/')").lastInsertRowid);
  const scanId = Number(run(`INSERT INTO scans (site_id, mode, status, redirect_count)
    VALUES (:siteId, 'site', 'completed', 1)`, { siteId }).lastInsertRowid);
  const findingId = Number(run(`INSERT INTO findings (scan_id, site_id, destination_url, category)
    VALUES (:scanId, :siteId, 'https://old.example/', 'redirect')`, { scanId, siteId }).lastInsertRowid);
  run("INSERT INTO finding_sources (finding_id, source_url, link_text) VALUES (:findingId, 'https://test.example/a', 'A')", { findingId });
  run("INSERT INTO finding_sources (finding_id, source_url, link_text) VALUES (:findingId, 'https://test.example/b', 'B')", { findingId });
  const finding = row('SELECT * FROM findings WHERE id=:id', { id: findingId });

  assert.equal(markFindingSourceResolved(null, finding, { source_url: 'https://test.example/a' }), false);
  assert.equal(row('SELECT resolved_at FROM findings WHERE id=:id', { id: findingId }).resolved_at, null);
  assert.equal(markFindingSourceResolved(null, finding, { source_url: 'https://test.example/b' }), true);
  assert.ok(row('SELECT resolved_at FROM findings WHERE id=:id', { id: findingId }).resolved_at);
  assert.equal(row('SELECT redirect_count FROM scans WHERE id=:id', { id: scanId }).redirect_count, 0);

  assert.equal(restoreFindingSource({ finding_id: findingId, source_url: 'https://test.example/a', source_link_text: 'A' }), true);
  assert.equal(row('SELECT resolved_at FROM findings WHERE id=:id', { id: findingId }).resolved_at, null);
  assert.equal(row('SELECT redirect_count FROM scans WHERE id=:id', { id: scanId }).redirect_count, 1);
  assert.equal(restoreFindingSource({ finding_id: findingId, source_url: 'https://test.example/b', source_link_text: 'B' }), false);
});

test('bevarer kun detaljer fra seneste scanning med samme omfang', () => {
  const siteId = Number(run("INSERT INTO sites (name, base_url) VALUES ('Historik', 'https://history.example/')").lastInsertRowid);
  const oldScanId = Number(run(`INSERT INTO scans (site_id, mode, status, broken_count)
    VALUES (:siteId, 'site', 'completed', 1)`, { siteId }).lastInsertRowid);
  const oldFindingId = Number(run(`INSERT INTO findings (scan_id, site_id, destination_url, category)
    VALUES (:scanId, :siteId, 'https://broken.example/', 'broken')`, { scanId: oldScanId, siteId }).lastInsertRowid);
  const pageScanId = Number(run(`INSERT INTO scans (site_id, mode, target_url, status)
    VALUES (:siteId, 'page', 'https://history.example/page', 'completed')`, { siteId }).lastInsertRowid);
  run(`INSERT INTO findings (scan_id, site_id, destination_url, category)
    VALUES (:scanId, :siteId, 'https://page.example/', 'ok')`, { scanId: pageScanId, siteId });
  run(`INSERT INTO ignore_rules (site_id, destination_url)
    VALUES (:siteId, 'https://ignored.example/')`, { siteId });
  run(`INSERT INTO link_changes
    (site_id, finding_id, source_url, post_type, post_id, old_url, new_url, backup_content, before_hash, status)
    VALUES (:siteId, :findingId, 'https://history.example/', 'pages', 1, 'https://broken.example/',
      'https://fixed.example/', '<p>backup</p>', 'hash', 'applied')`, { siteId, findingId: oldFindingId });
  const newScanId = Number(run(`INSERT INTO scans (site_id, mode, status)
    VALUES (:siteId, 'site', 'completed')`, { siteId }).lastInsertRowid);
  run(`INSERT INTO findings (scan_id, site_id, destination_url, category)
    VALUES (:scanId, :siteId, 'https://current.example/', 'ok')`, { scanId: newScanId, siteId });

  assert.equal(pruneSupersededScanDetails(newScanId), 1);
  assert.equal(row('SELECT details_retained FROM scans WHERE id=:id', { id: oldScanId }).details_retained, 0);
  assert.equal(row('SELECT broken_count FROM scans WHERE id=:id', { id: oldScanId }).broken_count, 1);
  assert.equal(row('SELECT id FROM findings WHERE id=:id', { id: oldFindingId }), undefined);
  assert.ok(row('SELECT id FROM findings WHERE scan_id=:id', { id: newScanId }));
  assert.ok(row('SELECT id FROM findings WHERE scan_id=:id', { id: pageScanId }));
  assert.ok(row('SELECT id FROM ignore_rules WHERE site_id=:siteId', { siteId }));
  const change = row('SELECT finding_id, backup_content FROM link_changes WHERE site_id=:siteId', { siteId });
  assert.equal(change.finding_id, null);
  assert.equal(change.backup_content, '<p>backup</p>');
});
