const state = {
  view: 'overview',
  summary: {},
  sites: [],
  scans: [],
  findings: [],
  selectedScan: null,
  selectedRepairFinding: null,
  backup: null,
  diagnostics: { items: [], counts: { error: 0, warning: 0 } },
  resultCategory: 'all',
  findingSort: { key: 'category', direction: 'asc' }
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

function escapeHtml(value = '') {
  return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
}

// REST-noncen er bundet til WordPress-sessionen og udløber efter højst 24 timer.
// En fane, der har stået åben længe — eller overlevet et nyt login — sender
// derfor en forældet nonce, og WordPress svarer "Cookie-tjek mislykkedes".
// Kernens rest-nonce-handling udsteder en ny, så længe sessionen lever.
async function refreshNonce() {
  const response = await fetch(`${window.LINKVAGT.ajaxUrl}?action=rest-nonce`, { credentials: 'same-origin' });
  const nonce = (await response.text()).trim();
  if (!response.ok || !/^[a-f0-9]{10}$/i.test(nonce)) return false;
  window.LINKVAGT.nonce = nonce;
  return true;
}

async function api(path, options = {}, retried = false) {
  const apiPath = path.startsWith('/api') ? path.slice(4) : path;
  const response = await fetch(`${window.LINKVAGT.restRoot}${apiPath}`, {
    ...options,
    headers: {
      'content-type': 'application/json',
      'X-WP-Nonce': window.LINKVAGT.nonce,
      ...(options.headers || {})
    },
    credentials: 'same-origin'
  });
  if (response.status === 403 && !retried) {
    const body = await response.clone().json().catch(() => ({}));
    if (body.code === 'rest_cookie_invalid_nonce') {
      if (await refreshNonce()) return api(path, options, true);
      // Sessionen er udløbet: siden viser selv login-skærmen ved genindlæsning.
      stopAutoRefresh();
      window.location.reload();
      throw new Error('Din session er udløbet. Log ind igen.');
    }
  }
  if (response.status === 204) return null;
  const raw = await response.text();
  let data;
  try {
    data = raw ? JSON.parse(raw) : {};
  } catch {
    const readable = raw
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<[^>]+>/g, ' ')
      .replace(/&nbsp;/gi, ' ')
      .replace(/&#039;/gi, "'")
      .replace(/&quot;/gi, '"')
      .replace(/&amp;/gi, '&')
      .replace(/\s+/g, ' ')
      .trim();
    throw new Error(readable.slice(0, 500) || `Serveren returnerede et ugyldigt svar (HTTP ${response.status})`);
  }
  if (!response.ok) throw new Error(data.message || data.error || 'Handlingen mislykkedes');
  return data;
}

function toast(message, type = '') {
  const element = document.createElement('div');
  element.className = `toast ${type}`;
  element.textContent = message;
  $('#toasts').append(element);
  setTimeout(() => element.remove(), 4200);
}

let refreshTimer = null;

function stopAutoRefresh() {
  if (refreshTimer !== null) {
    clearInterval(refreshTimer);
    refreshTimer = null;
  }
}

$('#account-email').textContent = window.LINKVAGT.currentUser?.email || '';
$('#logout-button').addEventListener('click', async () => {
  const button = $('#logout-button');
  button.disabled = true;
  button.textContent = 'Logger ud…';
  try {
    const result = await api('/auth/logout', { method: 'POST', body: '{}' });
    // Sessionen er væk, så baggrundsopdateringen skal stoppe med det samme —
    // ellers kalder den videre mod et dashboard, den ikke længere må se.
    stopAutoRefresh();
    // replace, ikke assign: det udloggede dashboard hører ikke til i historikken.
    window.location.replace(result.login_url);
  } catch (error) {
    button.disabled = false;
    button.textContent = 'Log ud';
    toast(error.message, 'error');
  }
});

function fmtDate(value, withTime = true) {
  if (!value) return 'Ingen dato';
  const date = new Date(value.endsWith?.('Z') || value.includes?.('+') ? value : `${value}Z`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('da-DK', withTime
    ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }
    : { day: '2-digit', month: 'short', year: 'numeric' }).format(date);
}

function relativeDate(value) {
  if (!value) return 'Aldrig';
  const date = new Date(value.endsWith?.('Z') ? value : `${value}Z`);
  const diff = Date.now() - date.getTime();
  if (diff < 60_000) return 'Lige nu';
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)} min. siden`;
  if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)} t. siden`;
  return fmtDate(value, false);
}

function formatBytes(value) {
  const bytes = Number(value || 0);
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const statusName = (value) => ({ completed: 'Gennemført', running: 'I gang', queued: 'I kø', failed: 'Fejlet', broken: 'Dødt', redirect: 'Redirect', warning: 'Advarsel', ok: 'Fungerer' }[value] || value);

const autoFrequencyName = (value) => ({
  weekly: 'Ugentligt',
  biweekly: 'Hver 14. dag',
  monthly: 'Månedligt',
  manual: 'Kun manuelt'
}[value] || 'Ugentligt');

function siteCell(site) {
  let host = site.base_url;
  try { host = new URL(site.base_url).hostname; } catch {}
  const next = site.auto_frequency !== 'manual' && site.auto_next_date ? ` · næste ${fmtDate(site.auto_next_date, false)}` : '';
  return `<div class="site-cell"><span class="favicon">${escapeHtml(site.name.slice(0, 1))}</span><span><strong>${escapeHtml(site.name)}</strong><small>${escapeHtml(host)} · ${escapeHtml(autoFrequencyName(site.auto_frequency))}${escapeHtml(next)}</small></span></div>`;
}

function issueCounts(item) {
  return `<div class="issue-counts"><span class="issue red">${item.broken_count || 0} døde</span><span class="issue blue">${item.redirect_count || 0} redirects</span><span class="issue amber">${item.warning_count || 0} advarsler</span></div>`;
}

function emptyRow(columns, title, copy) {
  return `<tr><td colspan="${columns}"><div class="empty"><strong>${escapeHtml(title)}</strong>${escapeHtml(copy)}</div></td></tr>`;
}

function renderMetrics() {
  const current = state.summary.current;
  const progress = scanProgress(current);
  const metrics = [
    ['Hjemmesider', state.summary.sites || 0, 'Klar til manuel scanning'],
    ['Døde links', state.summary.broken || 0, state.summary.broken ? 'Kræver opmærksomhed' : 'Ingen aktuelle fejl', state.summary.broken ? 'alert' : ''],
    ['Redirects', state.summary.redirects || 0, 'Kan opdateres til slutadresse'],
    ['Scanner', current ? `${progress.percent}%` : 'Klar', current ? progress.phase : 'Venter på næste opgave']
  ];
  $('#metrics').innerHTML = metrics.map(([label, value, note, className]) => `<div class="metric"><div class="metric-label">${label}</div><div class="metric-value">${value}</div><div class="metric-note ${className || ''}">${note}</div></div>`).join('');
  renderScanProgress(current, progress);
}

function scanProgress(scan) {
  if (!scan) return { percent: 0, phase: 'Venter på næste opgave' };
  const pagesTotal = Number(scan.pages_total || 0);
  const pagesDone = Math.min(Number(scan.pages_processed ?? scan.pages_scanned ?? 0), pagesTotal);
  const linksTotal = Number(scan.links_total || 0);
  const linksDone = Math.min(Number(scan.links_checked || 0), linksTotal);
  const pageShare = pagesTotal ? pagesDone / pagesTotal : 0;
  const linkShare = linksTotal ? linksDone / linksTotal : 0;
  const percent = Math.min(99, Math.max(1, Math.round(pageShare * 40 + linkShare * 60)));
  const phase = pagesTotal && pagesDone < pagesTotal ? 'Scanner sider og finder links' : 'Kontrollerer fundne links';
  return { percent, phase };
}

function renderScanProgress(scan, progress = scanProgress(scan)) {
  const panel = $('#scan-progress');
  panel.hidden = !scan;
  if (!scan) return;
  const site = state.sites.find((item) => item.id === scan.site_id);
  const activity = scan.activity_at ? relativeDate(scan.activity_at) : 'Lige nu';
  const activityDate = scan.activity_at ? new Date(`${scan.activity_at}Z`) : new Date();
  const stale = Date.now() - activityDate.getTime() > 3 * 60_000;
  $('#scan-progress-title').textContent = `${site?.name || scan.site_name || 'Website'} · ${progress.phase}`;
  $('#scan-progress-percent').textContent = `${progress.percent}%`;
  const failedPages = Number(scan.pages_failed || 0);
  $('#scan-progress-pages').textContent = `${scan.pages_processed ?? scan.pages_scanned ?? 0} af ${scan.pages_total || 0} sider${failedPages ? ` · ${failedPages} kunne ikke hentes` : ''}`;
  $('#scan-progress-links').textContent = `${scan.links_checked || 0} af ${scan.links_total || 0} links`;
  $('#scan-progress-activity').textContent = stale ? `Venter på næste job · sidst aktiv ${activity}` : `Arbejder · opdateret ${activity.toLowerCase()}`;
  const track = $('#scan-progress .progress-track');
  track.setAttribute('aria-valuenow', String(progress.percent));
  $('#scan-progress-bar').style.width = `${progress.percent}%`;
  panel.classList.toggle('waiting', stale);
}

function renderOverviewSites() {
  const sites = state.sites.slice(0, 8);
  $('#overview-sites').innerHTML = sites.length ? sites.map((site) => {
    const status = site.latest_scan_status === 'running' ? ['running', 'I gang'] : site.broken_count ? ['broken', 'Problemer'] : site.latest_scan_id ? ['ok', 'OK'] : site.latest_result_scan_id ? ['running', 'Delvist scannet'] : ['ok', 'Ikke scannet'];
    return `<tr>${`<td>${siteCell(site)}</td>`}<td><span class="badge ${status[0]}">${status[1]}</span></td><td>${issueCounts(site)}</td><td>${relativeDate(site.last_checked_at)}</td><td><div class="row-actions"><button class="icon-button" data-action="scan" data-id="${site.id}" title="Start scanning" aria-label="Start scanning">↻</button><button class="icon-button" data-action="open-latest" data-id="${site.id}" title="Se seneste resultat" aria-label="Se seneste resultat">→</button></div></td></tr>`;
  }).join('') : emptyRow(5, 'Ingen hjemmesider endnu', 'Tilføj den første hjemmeside for at begynde overvågningen.');
}

function renderActivity() {
  const scans = state.summary.latest || [];
  $('#recent-activity').innerHTML = scans.length ? scans.slice(0, 6).map((scan) => {
    const origin = scan.scan_origin === 'scheduled' ? 'Automatisk ugekontrol' : 'Manuel scanning';
    return `<div class="activity"><span class="activity-dot ${scan.status}"></span><div><strong>${escapeHtml(scan.site_name)}</strong><small>${origin}</small></div><span class="badge ${scan.status}">${statusName(scan.status)}</span><time>${relativeDate(scan.created_at)}</time></div>`;
  }).join('') : '<div class="empty"><strong>Ingen aktivitet</strong>Scanninger vil blive vist her.</div>';
}

function filteredSites() {
  const search = $('#site-search').value.toLowerCase();
  const filter = $('#site-status').value;
  return state.sites.filter((site) => {
    const matchesSearch = `${site.name} ${site.base_url}`.toLowerCase().includes(search);
    const matchesFilter = filter === 'all' || (filter === 'problems' && (site.broken_count || site.redirect_count || site.warning_count));
    return matchesSearch && matchesFilter;
  });
}

function renderSites() {
  const sites = filteredSites();
  $('#sites-table').innerHTML = sites.length ? sites.map((site) => `<tr><td>${siteCell(site)}</td><td>${relativeDate(site.last_checked_at)}</td><td><span class="issue red">${site.broken_count || 0}</span></td><td><span class="issue blue">${site.redirect_count || 0}</span></td><td><span class="issue amber">${site.warning_count || 0}</span></td><td><div class="row-actions"><button class="icon-button" data-action="scan" data-id="${site.id}" title="Start scanning">↻</button><button class="icon-button ${site.wordpress_connected ? 'connected' : ''}" data-action="wordpress" data-id="${site.id}" title="${site.wordpress_connected ? 'WordPress er testet' : site.wordpress_configured ? 'WordPress skal testes' : 'Forbind WordPress'}">W</button><button class="icon-button" data-action="edit" data-id="${site.id}" title="Rediger">✎</button><button class="icon-button" data-action="open-latest" data-id="${site.id}" title="Se resultat">→</button><button class="icon-button" data-action="delete" data-id="${site.id}" title="Slet">×</button></div></td></tr>`).join('') : emptyRow(6, 'Ingen match', 'Prøv at ændre søgningen eller filteret.');
}

function renderScanFilters() {
  const current = $('#scan-site-filter').value;
  $('#scan-site-filter').innerHTML = '<option value="">Alle hjemmesider</option>' + state.sites.map((site) => `<option value="${site.id}">${escapeHtml(site.name)}</option>`).join('');
  $('#scan-site-filter').value = current;
}

function renderScans() {
  const siteId = $('#scan-site-filter').value;
  const status = $('#scan-status-filter').value;
  const scans = state.scans.filter((scan) => (!siteId || String(scan.site_id) === siteId) && (status === 'all' || scan.status === status));
  $('#scans-table').innerHTML = scans.length ? scans.map((scan) => {
    const site = state.sites.find((item) => item.id === scan.site_id);
    const scanType = scan.scan_origin === 'scheduled'
      ? 'Automatisk ugekontrol'
      : ({ page: 'Enkeltside', link: 'Enkelt link', site: 'Hele websitet' }[scan.mode] || scan.mode);
    const hasDetails = scan.status === 'completed' && scan.details_retained !== 0;
    const historyNote = scan.status === 'completed' && !hasDetails ? '<small>Kun oversigt</small>' : '';
    return `<tr><td>${site ? siteCell(site) : escapeHtml(scan.site_name || 'Ukendt')}</td><td>${fmtDate(scan.started_at || scan.created_at)}</td><td>${scanType}</td><td><span class="badge ${scan.status}">${statusName(scan.status)}</span>${historyNote}${scan.error_message ? `<small title="${escapeHtml(scan.error_message)}">${escapeHtml(scan.error_message)}</small>` : ''}</td><td>${scan.links_checked || 0}</td><td>${issueCounts(scan)}</td><td><div class="row-actions">${hasDetails ? `<button class="icon-button" data-action="result" data-scan="${scan.id}" title="Se resultat">→</button>` : ''}</div></td></tr>`;
  }).join('') : emptyRow(7, 'Ingen scanninger', 'Der er ingen scanninger, der matcher filteret.');
}

function categoryBadge(finding) {
  const redirectStatus = finding.redirect_chain?.[0]?.status;
  const detail = finding.category === 'redirect' && redirectStatus
    ? `${redirectStatus} → ${finding.status_code || '?'}`
    : finding.status_code || finding.error_type || '-';
  return `<span class="badge ${finding.category}">${statusName(finding.category)} · ${escapeHtml(detail)}</span>`;
}

function redirectKind(finding) {
  try {
    const oldUrl = new URL(finding.destination_url);
    const newUrl = new URL(finding.final_url);
    if (oldUrl.protocol !== newUrl.protocol && oldUrl.host === newUrl.host && oldUrl.pathname === newUrl.pathname && oldUrl.search === newUrl.search) {
      return `${oldUrl.protocol.slice(0, -1).toUpperCase()} → ${newUrl.protocol.slice(0, -1).toUpperCase()}`;
    }
    if (oldUrl.hostname !== newUrl.hostname) return 'Nyt domæne';
    if (oldUrl.pathname !== newUrl.pathname) return 'Ny adresse';
    if (oldUrl.search !== newUrl.search) return 'Nye parametre';
    return 'Normaliseret adresse';
  } catch { return 'Ny adresse'; }
}

function sortFindings(findings) {
  const { key, direction } = state.findingSort;
  const multiplier = direction === 'asc' ? 1 : -1;
  const categoryRank = { broken: 1, redirect: 2, warning: 3, ok: 4 };
  const valueFor = (finding) => {
    if (key === 'category') return `${categoryRank[finding.category] || 9}-${finding.status_code || finding.error_type || ''}`;
    if (key === 'destination') return finding.destination_url;
    if (key === 'source') return finding.sources[0]?.source_url || '';
    if (key === 'firstSeen') return new Date(finding.first_seen_at).getTime() || 0;
    return '';
  };
  return [...findings].sort((left, right) => {
    const leftValue = valueFor(left);
    const rightValue = valueFor(right);
    const comparison = typeof leftValue === 'number'
      ? leftValue - rightValue
      : String(leftValue).localeCompare(String(rightValue), 'da', { sensitivity: 'base', numeric: true });
    if (comparison !== 0) return comparison * multiplier;
    return left.destination_url.localeCompare(right.destination_url, 'da') * multiplier;
  });
}

function renderFindingSort() {
  $$('[data-finding-sort]').forEach((button) => {
    const active = button.dataset.findingSort === state.findingSort.key;
    button.classList.toggle('active', active);
    button.querySelector('span').textContent = active ? (state.findingSort.direction === 'asc' ? '↑' : '↓') : '↕';
    const header = button.closest('th');
    if (active) header.setAttribute('aria-sort', state.findingSort.direction === 'asc' ? 'ascending' : 'descending');
    else header.removeAttribute('aria-sort');
  });
}

function renderFindings() {
  const search = $('#finding-search').value.toLowerCase();
  const showIgnored = $('#show-ignored').checked;
  const filtered = sortFindings(state.findings.filter((finding) => {
    const category = state.resultCategory === 'all' || finding.category === state.resultCategory;
    const text = `${finding.destination_url} ${finding.final_url || ''} ${finding.sources.map((source) => `${source.source_url} ${source.link_text || ''}`).join(' ')}`.toLowerCase();
    return category && text.includes(search) && (showIgnored || !finding.ignored);
  }));
  $('#findings-table').innerHTML = filtered.length ? filtered.map((finding) => {
    const sourceMarkup = (source) => `<div class="source-item"><a href="${escapeHtml(source.source_url)}" target="_blank" rel="noreferrer">${escapeHtml(source.source_url)}</a><small><strong>Ankertekst:</strong> ${escapeHtml(source.link_text || '(ingen synlig tekst)')}</small></div>`;
    const sources = finding.sources.slice(0, 2).map(sourceMarkup).join('');
    const moreSources = finding.sources.slice(2);
    const more = moreSources.length
      ? `<details class="source-more"><summary>Vis ${moreSources.length} flere kildesider</summary>${moreSources.map(sourceMarkup).join('')}</details>`
      : '';
    const verification = finding.attempt_count > 1
      ? `<small class="verification-note">${finding.verification_status === 'confirmed' ? `Bekræftet efter ${finding.attempt_count} forsøg` : finding.verification_status === 'recovered' ? `Virkede ved forsøg ${finding.attempt_count}` : `Ustabilt svar efter ${finding.attempt_count} forsøg`}</small>`
      : '';
    const redirect = finding.category === 'redirect' && finding.final_url
      ? `<small><span class="redirect-kind">${escapeHtml(redirectKind(finding))}</span>→ ${escapeHtml(finding.final_url)}</small>`
      : finding.error_type ? `<small>${escapeHtml(finding.error_message || finding.error_type.replaceAll('_', ' '))}</small>${verification}` : '';
    const selectedSite = state.sites.find((site) => site.id === finding.site_id);
    const canRepair = selectedSite?.wordpress_connected && finding.sources.length && ['broken', 'redirect'].includes(finding.category) && !finding.ignored;
    return `<tr class="${finding.ignored ? 'ignored-row' : ''}"><td>${categoryBadge(finding)}${finding.ignored ? '<small>Ignoreret</small>' : ''}</td><td><div class="destination"><strong><a href="${escapeHtml(finding.destination_url)}" target="_blank" rel="noreferrer">${escapeHtml(finding.destination_url)}</a></strong>${redirect}</div></td><td><div class="source-list">${sources}${more}</div></td><td>${fmtDate(finding.first_seen_at, false)}</td><td><div class="row-actions"><button class="button secondary" data-action="recheck" data-finding="${finding.id}">Kontrollér igen</button>${canRepair ? `<button class="button primary" data-action="repair" data-finding="${finding.id}">Ret link</button>` : ''}${finding.ignored ? `<button class="button secondary" data-action="unignore" data-rule="${finding.ignore_rule_id}">Aktivér</button>` : finding.category !== 'ok' ? `<button class="button secondary" data-action="ignore" data-finding="${finding.id}">Ignorer</button>` : ''}</div></td></tr>`;
  }).join('') : emptyRow(5, 'Ingen resultater', 'Ingen links matcher det valgte filter.');
  const counts = Object.fromEntries(['all', 'broken', 'redirect', 'warning', 'ok'].map((key) => [key, key === 'all' ? state.findings.length : state.findings.filter((finding) => finding.category === key).length]));
  for (const [key, value] of Object.entries(counts)) $(`#count-${key}`).textContent = value;
  renderFindingSort();
}

async function loadBase() {
  const [summary, sites, scans] = await Promise.all([api('/api/summary'), api('/api/sites'), api('/api/scans')]);
  state.summary = summary;
  state.sites = sites;
  state.scans = scans;
  renderMetrics(); renderOverviewSites(); renderActivity(); renderSites(); renderScanFilters(); renderScans();
  renderDiagnosticSites();
  if (state.view === 'results' && state.findings.length) renderFindings();
}

function showView(view) {
  state.view = view;
  $$('.view').forEach((element) => element.classList.toggle('active', element.id === `view-${view}`));
  $$('.nav-item').forEach((element) => element.classList.toggle('active', element.dataset.view === view));
  const names = { overview: 'Overblik', sites: 'Hjemmesider', scans: 'Scanninger', settings: 'Indstillinger', support: 'Support', results: 'Scanningsresultat' };
  $('#page-title').textContent = names[view];
  $('#add-site-button').hidden = !['overview', 'sites'].includes(view);
  $('.sidebar').classList.remove('open');
}

function openSiteDialog(site = null) {
  const form = $('#site-form');
  form.reset();
  $('#site-dialog-title').textContent = site ? 'Rediger hjemmeside' : 'Tilføj hjemmeside';
  form.elements.id.value = site?.id || '';
  form.elements.name.value = site?.name || '';
  form.elements.base_url.value = site?.base_url || '';
  form.elements.sitemap_urls.value = site?.sitemap_urls?.join('\n') || '';
  form.elements.max_pages.value = site?.max_pages ?? 5000;
  form.elements.crawl_fallback.checked = site?.crawl_fallback ?? true;
  const frequency = site?.auto_frequency || 'weekly';
  form.elements.auto_enabled.checked = frequency !== 'manual';
  form.elements.auto_frequency.value = frequency === 'manual' ? 'weekly' : frequency;
  form.elements.auto_frequency.disabled = frequency === 'manual';
  form.elements.auto_next_date.value = site?.auto_next_date || new Date().toISOString().slice(0, 10);
  form.elements.auto_next_date.disabled = frequency === 'manual';
  $('#auto-frequency-label').classList.toggle('disabled', frequency === 'manual');
  $('#site-dialog').showModal();
}

function openScanDialog(site) {
  const form = $('#scan-form');
  form.reset();
  form.elements.site_id.value = site.id;
  $('#scan-site-note').textContent = `Scanningen føjes til køen for ${site.name}.`;
  $('#target-url-label').hidden = true;
  $('#scan-dialog').showModal();
}

async function openResults(scanId) {
  const scan = state.scans.find((item) => item.id === Number(scanId)) || (state.summary.latest || []).find((item) => item.id === Number(scanId));
  if (!scan) return toast('Scanningen kunne ikke findes', 'error');
  if (scan.details_retained === 0) return toast('Kun oversigten fra denne scanning er gemt');
  const site = state.sites.find((item) => item.id === scan.site_id);
  state.selectedScan = scan;
  state.resultCategory = 'all';
  state.findings = await api(`/api/findings?scan_id=${scan.id}&include_ignored=1`);
  $('#result-site-name').textContent = site?.name || scan.site_name || '';
  $('#result-heading').textContent = `Scanning #${scan.id}`;
  $('#result-meta').textContent = `${fmtDate(scan.completed_at || scan.created_at)} · ${scan.links_checked} links kontrolleret`;
  $('#csv-link').href = `${window.LINKVAGT.exportUrl}&scan_id=${scan.id}&_wpnonce=${encodeURIComponent(window.LINKVAGT.exportNonce)}`;
  $$('.result-tabs button').forEach((button) => button.classList.toggle('active', button.dataset.category === 'all'));
  $('#finding-search').value = '';
  renderFindings();
  showView('results');
}

async function loadMailSettings() {
  const settings = await api('/api/settings/mail');
  const form = $('#mail-form');
  for (const [key, value] of Object.entries(settings)) {
    const field = form.elements[key];
    if (!field) continue;
    if (field.type === 'checkbox') field.checked = value === '1';
    else field.value = value;
  }
}

async function loadExclusionSettings() {
  const settings = await api('/api/settings/exclusions');
  $('#exclusions-form').elements.excluded_domains.value = settings.excluded_domains.join('\n');
}

function renderBackupStatus(status) {
  state.backup = status;
  const latest = status.latest;
  const stateElement = $('#backup-summary .backup-state');
  stateElement.className = `backup-state ${status.last_error ? 'error' : ''}`;
  stateElement.textContent = status.in_progress
    ? 'Backup er i gang'
    : status.last_error ? 'Seneste backupforsøg fejlede' : latest ? 'Backup fungerer' : 'Ingen backup endnu';
  $('#backup-latest').textContent = latest ? `${fmtDate(latest.created_at)} · ${latest.reason}` : 'Ingen';
  $('#backup-integrity').textContent = latest?.integrity === 'ok' ? 'Godkendt' : 'Ikke kontrolleret';
  $('#backup-count').textContent = String(status.count || 0);
  $('#backup-size').textContent = formatBytes(status.total_size);
  $('#backup-location').textContent = status.location;
  $('#backup-error').hidden = !status.last_error;
  $('#backup-error').textContent = status.last_error ? `${status.last_error.message} (${fmtDate(status.last_error.at)})` : '';
  $('#create-backup').disabled = Boolean(status.in_progress);
  $('#verify-backup').disabled = Boolean(status.in_progress || !latest);
  $('#restore-backup').disabled = Boolean(status.in_progress || !latest);
}

async function loadBackupStatus() {
  const status = await api('/api/backups');
  renderBackupStatus(status);
  return status;
}

async function loadScheduleStatus() {
  const schedule = await api('/api/settings/schedule');
  const form = $('#schedule-form');
  form.elements.day.value = schedule.day || 'monday';
  form.elements.time.value = schedule.time || '02:00';
  $('#worker-url').value = schedule.worker_url || '';
  $('#schedule-next').textContent = schedule.next_run ? fmtDate(schedule.next_run) : 'Ikke planlagt';
  const current = schedule.current;
  $('#schedule-status').textContent = current?.status === 'running'
    ? `${current.scan_ids?.length || 0} af ${current.site_ids?.length || 0} websites startet`
    : 'Aktiv';
}

$('#schedule-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  try {
    const schedule = await api('/api/settings/schedule', {
      method: 'POST',
      body: JSON.stringify({ day: form.elements.day.value, time: form.elements.time.value })
    });
    $('#schedule-next').textContent = schedule.next_run ? fmtDate(schedule.next_run) : 'Ikke planlagt';
    toast('Tidspunktet for den ugentlige scanning er gemt');
  } catch (error) {
    toast(error.message, 'error');
  }
});

$('#copy-worker-url').addEventListener('click', async () => {
  const field = $('#worker-url');
  try {
    await navigator.clipboard.writeText(field.value);
    toast('Cronadressen er kopieret');
  } catch {
    field.focus();
    field.select();
    toast('Markér og kopiér cronadressen manuelt');
  }
});

function renderDiagnostics() {
  const severity = $('#diagnostic-severity').value;
  const items = state.diagnostics.items.filter((item) => severity === 'all' || item.severity === severity);
  $('#diagnostic-errors').textContent = String(state.diagnostics.counts.error || 0);
  $('#diagnostic-warnings').textContent = String(state.diagnostics.counts.warning || 0);
  $('#diagnostic-list').innerHTML = items.length ? items.map((item) => {
    const type = item.event_type === 'job_failed' ? 'Scanning fejlede'
      : item.event_type === 'page_fetch' ? 'Side kunne ikke hentes'
        : 'Ukendt hændelse';
    return `<article class="diagnostic-item ${escapeHtml(item.severity)}">
      <div class="diagnostic-item-head"><span class="badge ${item.severity === 'error' ? 'broken' : 'warning'}">${escapeHtml(type)}</span><time>${fmtDate(item.created_at)}</time></div>
      <strong>${escapeHtml(item.site_name || 'Ukendt hjemmeside')}</strong>
      <p>${escapeHtml(item.message)}</p>
      ${item.url ? `<a href="${escapeHtml(item.url)}" target="_blank" rel="noreferrer">${escapeHtml(item.url)}</a>` : ''}
      ${item.scan_id ? `<div class="diagnostic-meta"><span>Scanning #${item.scan_id}</span></div>` : ''}
    </article>`;
  }).join('') : '<div class="empty"><strong>Ingen hændelser</strong>Der er ingen fejl eller advarsler med det valgte filter.</div>';
}

function renderDiagnosticSites() {
  const select = $('#diagnostic-site');
  const current = select.value;
  select.innerHTML = '<option value="">Alle hjemmesider</option>' + state.sites
    .map((site) => `<option value="${site.id}">${escapeHtml(site.name)}</option>`).join('');
  select.value = current;
}

async function loadDiagnostics() {
  const siteId = $('#diagnostic-site').value;
  state.diagnostics = await api(`/api/diagnostics${siteId ? `?site_id=${encodeURIComponent(siteId)}` : ''}`);
  renderDiagnostics();
}

const auditActionName = (action) => ({
  'auth.login_succeeded': 'Login gennemført',
  'auth.logout': 'Logget ud',
  'auth.state_rejected': 'Login afvist (ugyldig tilstand)',
  'auth.token_exchange_failed': 'Login fejlede (Google kunne ikke kontaktes)',
  'auth.token_exchange_rejected': 'Login afvist af Google',
  'auth.id_token_rejected': 'Login afvist (ugyldig identitet)',
  'auth.identity_rejected': 'Login afvist (ingen adgang)',
  'site.created': 'Hjemmeside oprettet',
  'site.updated': 'Hjemmeside opdateret',
  'site.deleted': 'Hjemmeside slettet',
  'scan.queued': 'Scanning sat i kø',
  'wordpress.connection_saved': 'WordPress-forbindelse gemt',
  'wordpress.connection_deleted': 'WordPress-forbindelse fjernet',
  'wordpress.change_applied': 'Link rettet i WordPress',
  'wordpress.change_undone': 'Linkrettelse fortrudt',
  'ignore.created': 'Link ignoreret',
  'ignore.deleted': 'Ignorering fjernet',
  'settings.mail_updated': 'Mailindstillinger gemt',
  'settings.test_mail_sent': 'Testmail sendt',
  'settings.exclusions_updated': 'Ekskluderinger opdateret',
  'report.sent': 'Rapport sendt',
  'report.failed': 'Rapport kunne ikke sendes',
  'batch_report.sent': 'Samlerapport sendt',
  'batch_report.failed': 'Samlerapport kunne ikke sendes'
}[action] || action);

function renderAuditLog(items) {
  $('#audit-log-list').innerHTML = items.length ? items.map((item) => {
    const subtitle = item.object_type ? `${escapeHtml(item.object_type)}${item.object_id ? ` #${item.object_id}` : ''}` : '';
    return `<div class="activity"><span class="activity-dot"></span><div><strong>${escapeHtml(auditActionName(item.action))}</strong><small>${subtitle}</small></div><time>${fmtDate(item.created_at)}</time></div>`;
  }).join('') : '<div class="empty"><strong>Ingen hændelser endnu</strong>Handlinger i LinkVagt vil blive vist her.</div>';
}

async function loadAuditLog() {
  renderAuditLog(await api('/api/audit-log'));
}

function renderWordpressHistory(changes) {
  $('#wordpress-history').innerHTML = changes.length ? `<h3>Seneste ændringer</h3>${changes.map((change) => {
    const anchorChange = change.old_anchor_text !== change.new_anchor_text && change.new_anchor_text
      ? `<small>“${escapeHtml(change.old_anchor_text || '')}” → “${escapeHtml(change.new_anchor_text)}”</small>`
      : '';
    return `<div class="history-row"><div><strong>${escapeHtml(change.post_title || change.source_url)}</strong><small>${escapeHtml(change.old_url)} → ${escapeHtml(change.new_url)}</small>${anchorChange}</div><span class="badge ${change.status === 'applied' ? 'completed' : change.status === 'failed' ? 'failed' : 'warning'}">${escapeHtml({ applied: 'Rettet', undone: 'Fortrudt', rolled_back: 'Gendannet', failed: 'Fejlet', pending: 'I gang' }[change.status] || change.status)}</span>${change.status === 'applied' ? `<button type="button" class="button secondary" data-action="undo-change" data-change="${change.id}">Fortryd</button>` : ''}</div>`;
  }).join('')}` : '';
}

async function openWordpressDialog(site) {
  const form = $('#wordpress-form');
  form.reset();
  form.elements.site_id.value = site.id;
  $('#wordpress-title').textContent = `WordPress · ${site.name}`;
  $('#wordpress-status').textContent = 'Henter forbindelse…';
  $('#wordpress-dialog').showModal();
  const [connection, changes] = await Promise.all([
    api(`/api/sites/${site.id}/wordpress`),
    api(`/api/sites/${site.id}/wordpress/changes`)
  ]);
  form.elements.username.value = connection.username || '';
  form.elements.app_password.value = connection.connected ? '********' : '';
  $('#disconnect-wordpress').hidden = !connection.connected;
  $('#wordpress-status').textContent = connection.verified_at
    ? `Forbindelsen blev senest kontrolleret ${fmtDate(connection.verified_at)}.`
    : connection.connected ? 'Forbindelsen er gemt, men skal testes.' : 'WordPress er ikke forbundet endnu.';
  renderWordpressHistory(changes);
}

function resetRepairPreview() {
  $('#repair-form').elements.before_hash.value = '';
  $('#repair-preview').hidden = true;
  $('#repair-preview').innerHTML = '';
  $('#apply-repair').disabled = true;
}

function openRepairDialog(finding) {
  const form = $('#repair-form');
  form.reset();
  form.elements.finding_id.value = finding.id;
  form.elements.old_url.value = finding.destination_url;
  form.elements.new_url.value = finding.category === 'redirect' ? finding.final_url || '' : '';
  form.elements.source_url.innerHTML = finding.sources.map((source) => `<option value="${escapeHtml(source.source_url)}">${escapeHtml(source.source_url)}</option>`).join('');
  state.selectedRepairFinding = finding;
  syncRepairAnchorText();
  resetRepairPreview();
  $('#repair-dialog').showModal();
}

function syncRepairAnchorText() {
  const form = $('#repair-form');
  const source = state.selectedRepairFinding?.sources.find((item) => item.source_url === form.elements.source_url.value);
  const anchorText = source?.link_text || '';
  form.elements.old_anchor_text.value = anchorText;
  form.elements.new_anchor_text.value = anchorText;
}

document.addEventListener('click', async (event) => {
  const closeDialog = event.target.closest('[data-close-dialog]');
  if (closeDialog) {
    closeDialog.closest('dialog')?.close();
    return;
  }
  const nav = event.target.closest('[data-view]');
  if (nav) {
    showView(nav.dataset.view);
    if (nav.dataset.view === 'settings') {
      Promise.all([loadMailSettings(), loadExclusionSettings(), loadBackupStatus(), loadScheduleStatus()]).catch((error) => toast(error.message, 'error'));
    }
    if (nav.dataset.view === 'support') {
      Promise.all([loadBackupStatus(), loadDiagnostics(), loadAuditLog()]).catch((error) => toast(error.message, 'error'));
    }
    return;
  }
  const supportTarget = event.target.closest('[data-support-target]');
  if (supportTarget) {
    const target = document.getElementById(supportTarget.dataset.supportTarget);
    $$('.support-nav button').forEach((button) => button.classList.toggle('active', button === supportTarget));
    target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  const go = event.target.closest('[data-go]');
  if (go) return showView(go.dataset.go);
  const action = event.target.closest('[data-action]');
  if (!action) return;
  const site = state.sites.find((item) => item.id === Number(action.dataset.id));
  try {
    if (action.dataset.action === 'scan') openScanDialog(site);
    if (action.dataset.action === 'wordpress') await openWordpressDialog(site);
    if (action.dataset.action === 'edit') openSiteDialog(site);
    if (action.dataset.action === 'delete' && window.confirm(`Slet ${site.name} og hele scanningshistorikken?`)) {
      await api(`/api/sites/${site.id}`, { method: 'DELETE' });
      await loadBase();
      toast('Hjemmesiden er slettet');
    }
    if (action.dataset.action === 'open-latest') {
      if (!site.latest_result_scan_id || site.latest_result_scan_status !== 'completed') toast('Hjemmesiden har endnu ikke et færdigt resultat');
      else await openResults(site.latest_result_scan_id);
    }
    if (action.dataset.action === 'result') await openResults(action.dataset.scan);
    if (action.dataset.action === 'ignore') {
      const finding = state.findings.find((item) => item.id === Number(action.dataset.finding));
      const form = $('#ignore-form');
      form.reset();
      form.elements.site_id.value = finding.site_id;
      form.elements.destination_url.value = finding.destination_url;
      form.elements.source_url.innerHTML = '<option value="">På hele hjemmesiden</option>' + finding.sources.map((source) => `<option value="${escapeHtml(source.source_url)}">Kun på ${escapeHtml(source.source_url)}</option>`).join('');
      $('#ignore-link').textContent = finding.destination_url;
      $('#ignore-dialog').showModal();
    }
    if (action.dataset.action === 'repair') {
      const finding = state.findings.find((item) => item.id === Number(action.dataset.finding));
      openRepairDialog(finding);
    }
    if (action.dataset.action === 'recheck') {
      const finding = state.findings.find((item) => item.id === Number(action.dataset.finding));
      const source = finding.sources[0]?.source_url;
      await api(`/api/sites/${finding.site_id}/scan`, {
        method: 'POST',
        body: JSON.stringify({ mode: source ? 'page' : 'link', target_url: source || finding.destination_url })
      });
      await loadBase();
      showView('scans');
      toast(source ? 'Kildesiden er sat i kø til ny kontrol' : 'Linket er sat i kø til ny kontrol');
    }
    if (action.dataset.action === 'unignore') {
      await api(`/api/ignore/${action.dataset.rule}`, { method: 'DELETE' });
      await openResults(state.selectedScan.id);
      toast('Linket overvåges igen');
    }
    if (action.dataset.action === 'undo-change') {
      if (!window.confirm('Gendan sidens indhold fra backupen før linkrettelsen?')) return;
      await api(`/api/wordpress/changes/${action.dataset.change}/undo`, { method: 'POST', body: '{}' });
      const siteId = $('#wordpress-form').elements.site_id.value;
      renderWordpressHistory(await api(`/api/sites/${siteId}/wordpress/changes`));
      toast('Linkrettelsen er fortrudt, og backupen er gendannet');
    }
  } catch (error) { toast(error.message, 'error'); }
});

$('#add-site-button').addEventListener('click', () => openSiteDialog());
$('#site-form').elements.auto_enabled.addEventListener('change', (event) => {
  const enabled = event.currentTarget.checked;
  $('#site-form').elements.auto_frequency.disabled = !enabled;
  $('#site-form').elements.auto_next_date.disabled = !enabled;
  $('#site-form').elements.auto_next_date.required = enabled;
  $('#auto-frequency-label').classList.toggle('disabled', !enabled);
  $('#auto-next-date-label').classList.toggle('disabled', !enabled);
});
$('.mobile-menu').addEventListener('click', () => $('.sidebar').classList.toggle('open'));
$('#site-search').addEventListener('input', renderSites);
$('#site-status').addEventListener('change', renderSites);
$('#scan-site-filter').addEventListener('change', renderScans);
$('#scan-status-filter').addEventListener('change', renderScans);
$('#finding-search').addEventListener('input', renderFindings);
$('#show-ignored').addEventListener('change', renderFindings);
$('#results-back').addEventListener('click', () => showView('scans'));

$$('.result-tabs button').forEach((button) => button.addEventListener('click', () => {
  state.resultCategory = button.dataset.category;
  $$('.result-tabs button').forEach((item) => item.classList.toggle('active', item === button));
  renderFindings();
}));

$$('[data-finding-sort]').forEach((button) => button.addEventListener('click', () => {
  const key = button.dataset.findingSort;
  state.findingSort = {
    key,
    direction: state.findingSort.key === key && state.findingSort.direction === 'asc' ? 'desc' : 'asc'
  };
  renderFindings();
}));

$$('#scan-form input[name="scope"]').forEach((radio) => radio.addEventListener('change', () => {
  if (!radio.checked) return;
  const needsUrl = radio.value !== 'site';
  $('#target-url-label').hidden = !needsUrl;
  $('#target-url-text').textContent = radio.value === 'link' ? 'Linkets URL' : 'Sidens URL';
  $('#scan-form').elements.target_url.required = needsUrl;
}));

$('#site-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const data = Object.fromEntries(new FormData(form));
  const id = data.id;
  data.sitemap_urls = data.sitemap_urls.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
  data.max_pages = Number(data.max_pages);
  data.crawl_fallback = form.elements.crawl_fallback.checked;
  data.auto_frequency = form.elements.auto_enabled.checked ? form.elements.auto_frequency.value : 'manual';
  data.auto_next_date = form.elements.auto_enabled.checked ? form.elements.auto_next_date.value : null;
  delete data.auto_enabled;
  delete data.id;
  try {
    await api(id ? `/api/sites/${id}` : '/api/sites', { method: 'POST', body: JSON.stringify(data) });
    $('#site-dialog').close();
    await loadBase();
    toast(id ? 'Hjemmesiden er opdateret' : 'Hjemmesiden er tilføjet');
  } catch (error) { toast(error.message, 'error'); }
});

$('#scan-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const siteId = form.elements.site_id.value;
  const scope = form.elements.scope.value;
  const target = scope === 'site' ? null : form.elements.target_url.value;
  try {
    await api(`/api/sites/${siteId}/scan`, { method: 'POST', body: JSON.stringify({ target_url: target, mode: scope }) });
    $('#scan-dialog').close();
    await loadBase();
    showView('scans');
    toast('Scanningen er føjet til køen');
  } catch (error) { toast(error.message, 'error'); }
});

$('#ignore-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(event.currentTarget));
  try {
    await api('/api/ignore', { method: 'POST', body: JSON.stringify(data) });
    $('#ignore-dialog').close();
    await openResults(state.selectedScan.id);
    toast('Linket ignoreres fremover');
  } catch (error) { toast(error.message, 'error'); }
});

async function saveWordpressConnection() {
  const form = $('#wordpress-form');
  const data = Object.fromEntries(new FormData(form));
  const siteId = data.site_id;
  delete data.site_id;
  await api(`/api/sites/${siteId}/wordpress`, { method: 'POST', body: JSON.stringify(data) });
  return siteId;
}

$('#wordpress-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    await saveWordpressConnection();
    $('#wordpress-form').elements.app_password.value = '********';
    await loadBase();
    $('#wordpress-status').textContent = 'Forbindelsen er gemt. Test den før første linkrettelse.';
    $('#disconnect-wordpress').hidden = false;
    toast('WordPress-forbindelsen er gemt');
  } catch (error) { toast(error.message, 'error'); }
});

$('#test-wordpress').addEventListener('click', async () => {
  try {
    const siteId = await saveWordpressConnection();
    const result = await api(`/api/sites/${siteId}/wordpress/test`, { method: 'POST', body: '{}' });
    $('#wordpress-form').elements.app_password.value = '********';
    $('#wordpress-status').textContent = `Forbindelsen virker som ${result.user}.`;
    await loadBase();
    toast('WordPress-forbindelsen virker');
  } catch (error) { toast(error.message, 'error'); }
});

$('#disconnect-wordpress').addEventListener('click', async () => {
  const siteId = $('#wordpress-form').elements.site_id.value;
  if (!window.confirm('Fjern den gemte WordPress-forbindelse? Ændringsloggen og backups bevares.')) return;
  try {
    await api(`/api/sites/${siteId}/wordpress`, { method: 'DELETE' });
    $('#wordpress-dialog').close();
    await loadBase();
    toast('WordPress-forbindelsen er fjernet');
  } catch (error) { toast(error.message, 'error'); }
});

$('#repair-form').addEventListener('input', resetRepairPreview);
$('#repair-form').elements.source_url.addEventListener('change', () => {
  syncRepairAnchorText();
  resetRepairPreview();
});

$('#preview-repair').addEventListener('click', async () => {
  const form = $('#repair-form');
  if (!form.reportValidity()) return;
  const data = Object.fromEntries(new FormData(form));
  try {
    const preview = await api('/api/wordpress/preview', { method: 'POST', body: JSON.stringify(data) });
    form.elements.before_hash.value = preview.before_hash;
    const urlSummary = preview.url_changed
      ? `${preview.replacement_count} linkadresse${preview.replacement_count === 1 ? '' : 'r'} ændres.`
      : 'Linkadressen beholdes.';
    const anchorSummary = preview.anchor_text_changed
      ? `${preview.anchor_replacement_count} ankertekst${preview.anchor_replacement_count === 1 ? '' : 'er'} ændres.`
      : 'Ankerteksten beholdes.';
    $('#repair-preview').innerHTML = `<strong>${escapeHtml(preview.post_title)}</strong><span>${urlSummary} ${anchorSummary}</span><small>Resten af indholdet skal være identisk efter lagring.</small>`;
    $('#repair-preview').hidden = false;
    $('#apply-repair').disabled = false;
  } catch (error) { resetRepairPreview(); toast(error.message, 'error'); }
});

$('#repair-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.elements.before_hash.value) return toast('Kontroller ændringen først', 'error');
  const data = Object.fromEntries(new FormData(form));
  $('#apply-repair').disabled = true;
  try {
    const result = await api('/api/wordpress/apply', { method: 'POST', body: JSON.stringify(data) });
    $('#repair-dialog').close();
    if (state.selectedScan) await openResults(state.selectedScan.id);
    const anchorMessage = result.anchor_replacement_count ? ` og ${result.anchor_replacement_count} ankertekst${result.anchor_replacement_count === 1 ? '' : 'er'}` : '';
    toast(`${result.replacement_count} link${result.replacement_count === 1 ? '' : 's'}${anchorMessage} blev rettet og verificeret`);
  } catch (error) { resetRepairPreview(); toast(error.message, 'error'); }
});

async function saveMailSettings(showConfirmation = true) {
  const form = $('#mail-form');
  const data = Object.fromEntries(new FormData(form));
  data.mail_enabled = form.elements.mail_enabled.checked ? '1' : '0';
  data.mail_include_clean = form.elements.mail_include_clean.checked ? '1' : '0';
  await api('/api/settings/mail', { method: 'POST', body: JSON.stringify(data) });
  if (showConfirmation) toast('Mailindstillingerne er gemt');
}

$('#mail-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  try { await saveMailSettings(); } catch (error) { toast(error.message, 'error'); }
});

$('#exclusions-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const domains = form.elements.excluded_domains.value
    .split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
  try {
    const settings = await api('/api/settings/exclusions', {
      method: 'POST',
      body: JSON.stringify({ excluded_domains: domains })
    });
    form.elements.excluded_domains.value = settings.excluded_domains.join('\n');
    toast('Ekskluderingerne er gemt for alle hjemmesider');
  } catch (error) { toast(error.message, 'error'); }
});

$('#test-mail').addEventListener('click', async () => {
  try {
    await saveMailSettings(false);
    await api('/api/settings/mail/test', { method: 'POST', body: '{}' });
    toast('Testmailen er sendt');
  } catch (error) { toast(error.message, 'error'); }
});

$('#create-backup').addEventListener('click', async () => {
  const button = $('#create-backup');
  button.disabled = true;
  button.textContent = 'Tager backup…';
  try {
    await api('/api/backups', { method: 'POST', body: '{}' });
    await loadBackupStatus();
    toast('Backuppen er oprettet og kontrolleret');
  } catch (error) {
    toast(error.message, 'error');
    await loadBackupStatus().catch(() => {});
  } finally {
    button.textContent = 'Tag backup nu';
    button.disabled = false;
  }
});

$('#verify-backup').addEventListener('click', async () => {
  const button = $('#verify-backup');
  button.disabled = true;
  try {
    await api('/api/backups/verify', { method: 'POST', body: '{}' });
    await loadBackupStatus();
    toast('Den seneste backup er godkendt');
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
});

$('#restore-backup').addEventListener('click', () => {
  $('#restore-form').reset();
  $('#restore-dialog').showModal();
});

$('#restore-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  if (!form.reportValidity()) return;
  const confirmation = form.elements.confirmation.value.trim();
  const button = $('#confirm-restore');
  button.disabled = true;
  try {
    await api('/api/backups/restore', {
      method: 'POST',
      body: JSON.stringify({ confirmation })
    });
    $('#restore-dialog').close();
    await loadBase();
    await loadBackupStatus();
    toast('LinkVagt-data er gendannet fra den seneste backup');
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
  }
});

$('#refresh-diagnostics').addEventListener('click', () => {
  loadDiagnostics().then(() => toast('Diagnostikken er opdateret')).catch((error) => toast(error.message, 'error'));
});
$('#diagnostic-site').addEventListener('change', () => loadDiagnostics().catch((error) => toast(error.message, 'error')));
$('#refresh-audit-log').addEventListener('click', () => {
  loadAuditLog().then(() => toast('Aktivitetsloggen er opdateret')).catch((error) => toast(error.message, 'error'));
});
$('#diagnostic-severity').addEventListener('change', renderDiagnostics);

loadBase().catch((error) => toast(error.message, 'error'));
refreshTimer = setInterval(() => loadBase().catch(() => {}), 8000);
