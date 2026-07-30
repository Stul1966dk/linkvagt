import { createHash } from 'node:crypto';
import { assertPublicUrl, normalizeUrl } from './utils.js';

const TIMEOUT_MS = 15_000;
const MAX_RESPONSE_BYTES = 6_000_000;

export function contentHash(content) {
  return createHash('sha256').update(String(content)).digest('hex');
}

function decodeAttribute(value) {
  return value.replaceAll('&amp;', '&').replaceAll('&quot;', '"').replaceAll('&#39;', "'").replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)));
}

function encodeAttribute(value, quote) {
  return String(value).replaceAll('&', '&amp;').replaceAll(quote, quote === '"' ? '&quot;' : '&#39;');
}

function encodeText(value) {
  return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

export function replaceAnchors(content, oldUrl, newUrl, newAnchorText, baseUrl) {
  let count = 0;
  let anchorTextCount = 0;
  let formattedAnchorCount = 0;
  const target = normalizeUrl(oldUrl, baseUrl);
  const html = String(content).replace(/(<a\b)([^>]*)(>)([\s\S]*?)(<\/a\s*>)/gi, (match, opening, attributes, closing, innerHtml, ending) => {
    const hrefMatch = attributes.match(/(\bhref\s*=\s*)(["'])([\s\S]*?)\2/i);
    if (!hrefMatch) return match;
    const [hrefAttribute, hrefPrefix, quote, href] = hrefMatch;
    const normalized = normalizeUrl(decodeAttribute(href), baseUrl);
    if (normalized !== target) return match;
    count += 1;
    const nextAttributes = attributes.replace(hrefAttribute, `${hrefPrefix}${quote}${encodeAttribute(newUrl, quote)}${quote}`);
    let nextInnerHtml = innerHtml;
    if (newAnchorText != null) {
      if (/<[^>]+>/.test(innerHtml)) formattedAnchorCount += 1;
      else {
        nextInnerHtml = encodeText(newAnchorText);
        anchorTextCount += 1;
      }
    }
    return `${opening}${nextAttributes}${closing}${nextInnerHtml}${ending}`;
  });
  return { content: html, count, anchorTextCount, formattedAnchorCount };
}

export function replaceAnchorHrefs(content, oldUrl, newUrl, baseUrl) {
  return replaceAnchors(content, oldUrl, newUrl, null, baseUrl);
}

function canonical(value) {
  const normalized = normalizeUrl(value);
  if (!normalized) return '';
  const url = new URL(normalized);
  return `${url.origin}${url.pathname}`.replace(/\/$/, '');
}

function apiRoot(site) {
  return new URL('/wp-json/wp/v2/', site.base_url);
}

export class WordPressClient {
  constructor(site, connection) {
    this.site = site;
    this.connection = connection;
  }

  async request(path, options = {}) {
    const url = new URL(path, apiRoot(this.site));
    if (url.origin !== new URL(this.site.base_url).origin) throw new Error('WordPress API-adressen matcher ikke hjemmesiden');
    await assertPublicUrl(url.href);
    const response = await fetch(url, {
      ...options,
      signal: AbortSignal.timeout(TIMEOUT_MS),
      headers: {
        authorization: `Basic ${Buffer.from(`${this.connection.username}:${this.connection.appPassword}`).toString('base64')}`,
        accept: 'application/json',
        ...(options.body ? { 'content-type': 'application/json' } : {}),
        ...(options.headers || {})
      }
    });
    const text = await response.text();
    if (Buffer.byteLength(text) > MAX_RESPONSE_BYTES) throw new Error('WordPress-svaret er for stort');
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { throw new Error(`WordPress svarede ugyldigt (${response.status})`); }
    if (!response.ok) throw new Error(data?.message?.replace(/<[^>]+>/g, '') || `WordPress afviste handlingen (${response.status})`);
    return data;
  }

  async testConnection() {
    const user = await this.request('users/me?context=edit&_fields=id,name,capabilities');
    const canEdit = user?.capabilities?.edit_posts || user?.capabilities?.edit_pages;
    if (!canEdit) throw new Error('WordPress-brugeren har ikke rettighed til at redigere indhold');
    return { name: user.name || this.connection.username };
  }

  async findEditablePost(sourceUrl) {
    const source = new URL(sourceUrl);
    if (source.origin !== new URL(this.site.base_url).origin) throw new Error('Kildesiden tilhoerer ikke denne hjemmeside');
    const slug = source.pathname.split('/').filter(Boolean).at(-1);
    if (!slug) throw new Error('Forsiden kan endnu ikke rettes automatisk');
    for (const type of ['pages', 'posts']) {
      const candidates = await this.request(`${type}?context=edit&slug=${encodeURIComponent(slug)}&per_page=20&_fields=id,link,modified_gmt,content,title,status`);
      const post = candidates.find((item) => canonical(item.link) === canonical(sourceUrl));
      if (post) return { ...post, postType: type };
    }
    throw new Error('Kildesiden blev ikke fundet som et almindeligt WordPress-indlaeg eller en side');
  }

  async getPost(postType, postId) {
    return this.request(`${postType}/${postId}?context=edit&_fields=id,link,modified_gmt,content,title,status`);
  }

  async updateContent(postType, postId, content) {
    return this.request(`${postType}/${postId}?context=edit`, { method: 'POST', body: JSON.stringify({ content }) });
  }
}
