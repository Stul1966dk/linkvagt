import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const keyFile = process.env.LINKVAGT_KEY_FILE || resolve('data/linkvagt.key');

function secretKey() {
  if (process.env.LINKVAGT_SECRET_KEY) {
    return Buffer.from(process.env.LINKVAGT_SECRET_KEY, 'base64');
  }
  if (!existsSync(keyFile)) {
    mkdirSync(dirname(keyFile), { recursive: true });
    writeFileSync(keyFile, randomBytes(32), { mode: 0o600 });
  }
  const key = readFileSync(keyFile);
  if (key.length !== 32) throw new Error('LinkVagts lokale krypteringsnoegle er ugyldig');
  return key;
}

export function encryptSecret(value) {
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', secretKey(), iv);
  const encrypted = Buffer.concat([cipher.update(String(value), 'utf8'), cipher.final()]);
  return [iv, cipher.getAuthTag(), encrypted].map((part) => part.toString('base64')).join('.');
}

export function decryptSecret(value) {
  const [iv, tag, encrypted] = String(value).split('.').map((part) => Buffer.from(part, 'base64'));
  if (!iv || !tag || !encrypted) throw new Error('Den gemte WordPress-adgang kan ikke laeses');
  const decipher = createDecipheriv('aes-256-gcm', secretKey(), iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(encrypted), decipher.final()]).toString('utf8');
}
