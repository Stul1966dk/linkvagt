import { isIP } from 'node:net';
import { lookup } from 'node:dns/promises';

export function normalizeUrl(value, base) {
  try {
    const url = new URL(value, base);
    if (!['http:', 'https:'].includes(url.protocol)) return null;
    url.hash = '';
    if ((url.protocol === 'https:' && url.port === '443') || (url.protocol === 'http:' && url.port === '80')) url.port = '';
    return url.href;
  } catch {
    return null;
  }
}

export function normalizeDomain(value) {
  const text = String(value || '').trim().toLowerCase();
  if (!text) return null;
  try {
    const url = new URL(text.includes('://') ? text : `https://${text.replace(/^\*\./, '')}`);
    const hostname = url.hostname.toLowerCase().replace(/\.$/, '').replace(/^www\./, '');
    if (!hostname || !hostname.includes('.') || /[^a-z0-9.-]/i.test(hostname)) return null;
    return hostname;
  } catch {
    return null;
  }
}

export function isExcludedUrl(value, domains = []) {
  try {
    const hostname = new URL(value).hostname.toLowerCase().replace(/\.$/, '').replace(/^www\./, '');
    return domains.some((domain) => hostname === domain || hostname.endsWith(`.${domain}`));
  } catch {
    return false;
  }
}

export function escapeHtml(value = '') {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

export function isPrivateIp(address) {
  if (!isIP(address)) return false;
  if (address === '::1' || address.startsWith('fc') || address.startsWith('fd') || address.startsWith('fe80:')) return true;
  const parts = address.split('.').map(Number);
  if (parts.length !== 4) return false;
  return parts[0] === 10 || parts[0] === 127 || parts[0] === 0 ||
    (parts[0] === 169 && parts[1] === 254) ||
    (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) ||
    (parts[0] === 192 && parts[1] === 168);
}

export async function assertPublicUrl(value) {
  const normalized = normalizeUrl(value);
  if (!normalized) throw new Error('URL skal begynde med http:// eller https://');
  const url = new URL(normalized);
  if (url.username || url.password) throw new Error('URL må ikke indeholde loginoplysninger');
  const addresses = await lookup(url.hostname, { all: true });
  if (!addresses.length || addresses.some(({ address }) => isPrivateIp(address))) {
    throw new Error('Lokale og private netværksadresser er ikke tilladt');
  }
  return normalized;
}

export function csvEscape(value) {
  const text = value == null ? '' : String(value);
  return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

export async function readBody(request, limit = 1_000_000) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > limit) throw new Error('Forespørgslen er for stor');
    chunks.push(chunk);
  }
  if (!chunks.length) return {};
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}
