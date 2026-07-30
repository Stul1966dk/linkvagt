import tls from 'node:tls';
import { row, rows } from './db.js';
import { escapeHtml } from './utils.js';

function readResponse(socket) {
  return new Promise((resolve, reject) => {
    let buffer = '';
    const timeout = setTimeout(() => finish(new Error('SMTP svarede ikke')), 15_000);
    function finish(error, value) {
      clearTimeout(timeout);
      socket.off('data', onData);
      socket.off('error', onError);
      error ? reject(error) : resolve(value);
    }
    function onError(error) { finish(error); }
    function onData(chunk) {
      buffer += chunk.toString('utf8');
      const lines = buffer.split(/\r?\n/).filter(Boolean);
      const last = lines.at(-1) || '';
      if (/^\d{3} /.test(last)) {
        const code = Number(last.slice(0, 3));
        if (code >= 400) finish(new Error(`SMTP-fejl: ${last}`));
        else finish(null, { code, text: buffer });
      }
    }
    socket.on('data', onData);
    socket.once('error', onError);
  });
}

async function command(socket, value, expected) {
  socket.write(`${value}\r\n`);
  const response = await readResponse(socket);
  if (expected && !expected.includes(response.code)) throw new Error(`Uventet SMTP-svar ${response.code}`);
  return response;
}

export async function sendMail(config, { subject, html }) {
  if (!config.smtp_host || !config.smtp_user || !config.smtp_password || !config.mail_to) {
    throw new Error('SMTP-indstillingerne er ikke komplette');
  }
  const port = Number(config.smtp_port || 465);
  const socket = tls.connect({ host: config.smtp_host, port, servername: config.smtp_host, rejectUnauthorized: true });
  await new Promise((resolve, reject) => {
    socket.once('secureConnect', resolve);
    socket.once('error', reject);
  });
  try {
    await readResponse(socket);
    await command(socket, `EHLO ${config.smtp_helo || 'linkvagt.local'}`, [250]);
    await command(socket, 'AUTH LOGIN', [334]);
    await command(socket, Buffer.from(config.smtp_user).toString('base64'), [334]);
    await command(socket, Buffer.from(config.smtp_password).toString('base64'), [235]);
    const from = config.mail_from || config.smtp_user;
    await command(socket, `MAIL FROM:<${from}>`, [250]);
    await command(socket, `RCPT TO:<${config.mail_to}>`, [250, 251]);
    await command(socket, 'DATA', [354]);
    const message = [
      `From: LinkVagt <${from}>`,
      `To: ${config.mail_to}`,
      `Subject: =?UTF-8?B?${Buffer.from(subject).toString('base64')}?=`,
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'Content-Transfer-Encoding: 8bit',
      '',
      html.replace(/^\./gm, '..'),
      '.'
    ].join('\r\n');
    await command(socket, message, [250]);
    await command(socket, 'QUIT', [221]);
  } finally {
    socket.end();
  }
}

export function getMailSettings(includeSecret = false) {
  const settings = Object.fromEntries(rows("SELECT key, value FROM settings WHERE key LIKE 'smtp_%' OR key LIKE 'mail_%'").map((item) => [item.key, item.value]));
  if (!includeSecret && settings.smtp_password) settings.smtp_password = '********';
  return settings;
}

export async function sendScanReport(scanId) {
  const scan = row(`SELECT scans.*, sites.name AS site_name, sites.base_url
    FROM scans JOIN sites ON sites.id = scans.site_id WHERE scans.id = :id`, { id: scanId });
  if (!scan || scan.status !== 'completed') return;
  const config = getMailSettings(true);
  if (config.mail_enabled !== '1') return;
  const findings = rows(`SELECT destination_url, final_url, status_code, category, error_type
    FROM findings WHERE scan_id = :scanId AND category != 'ok' ORDER BY category, destination_url LIMIT 100`, { scanId });
  if (!findings.length && config.mail_include_clean !== '1') return;
  const table = findings.map((finding) => `<tr><td>${escapeHtml(finding.category)}</td><td>${escapeHtml(finding.status_code || finding.error_type || '-')}</td><td><a href="${escapeHtml(finding.destination_url)}">${escapeHtml(finding.destination_url)}</a></td><td>${escapeHtml(finding.final_url || '-')}</td></tr>`).join('');
  await sendMail(config, {
    subject: `LinkVagt: ${scan.broken_count} døde links på ${scan.site_name}`,
    html: `<h1>Linkrapport for ${escapeHtml(scan.site_name)}</h1><p>${scan.links_checked} links kontrolleret. ${scan.broken_count} døde, ${scan.redirect_count} redirects og ${scan.warning_count} advarsler.</p><table border="1" cellpadding="7" cellspacing="0"><thead><tr><th>Type</th><th>Status</th><th>Link</th><th>Slutadresse</th></tr></thead><tbody>${table || '<tr><td colspan="4">Ingen problemer fundet</td></tr>'}</tbody></table>`
  });
}
