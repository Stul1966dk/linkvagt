import { createHash } from 'node:crypto';
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  renameSync,
  rmSync,
  statSync,
  writeFileSync
} from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { backup as sqliteBackup, DatabaseSync } from 'node:sqlite';
import { db, row } from './db.js';

const backupRoot = process.env.LINKVAGT_BACKUP_DIR || resolve('Backup');
const keyFile = process.env.LINKVAGT_KEY_FILE || resolve('data/linkvagt.key');
const DAY_MS = 24 * 60 * 60 * 1000;
let currentBackup = null;
let lastError = null;

mkdirSync(backupRoot, { recursive: true });

function timestamp(date = new Date()) {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z').replaceAll(':', '-');
}

function checksum(file) {
  return createHash('sha256').update(readFileSync(file)).digest('hex');
}

function directorySize(directory) {
  let total = 0;
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    total += entry.isDirectory() ? directorySize(path) : statSync(path).size;
  }
  return total;
}

function readManifest(directory) {
  try {
    return JSON.parse(readFileSync(join(directory, 'manifest.json'), 'utf8'));
  } catch {
    return null;
  }
}

function backupEntries() {
  return readdirSync(backupRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && !entry.name.startsWith('.'))
    .map((entry) => {
      const directory = join(backupRoot, entry.name);
      const manifest = readManifest(directory);
      return manifest ? { directory, name: entry.name, manifest } : null;
    })
    .filter(Boolean)
    .sort((left, right) => String(right.manifest.created_at).localeCompare(String(left.manifest.created_at)));
}

function weekKey(value) {
  const date = new Date(value);
  const firstDay = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
  const day = Math.floor((date - firstDay) / DAY_MS);
  return `${date.getUTCFullYear()}-${Math.floor((day + firstDay.getUTCDay()) / 7)}`;
}

function cleanOldBackups() {
  const entries = backupEntries();
  const keep = new Set();

  for (const entry of entries.filter((item) => item.manifest.reason === 'manual')) keep.add(entry.directory);
  for (const entry of entries.filter((item) => item.manifest.reason === 'pre-wordpress').slice(0, 10)) keep.add(entry.directory);

  const automatic = entries.filter((item) => ['startup', 'post-scan'].includes(item.manifest.reason));
  const daily = new Set();
  const older = [];
  for (const entry of automatic) {
    const day = String(entry.manifest.created_at).slice(0, 10);
    if (daily.size < 14 && !daily.has(day)) {
      daily.add(day);
      keep.add(entry.directory);
    } else {
      older.push(entry);
    }
  }

  const weekly = new Set();
  for (const entry of older) {
    const week = weekKey(entry.manifest.created_at);
    if (weekly.size < 8 && !weekly.has(week)) {
      weekly.add(week);
      keep.add(entry.directory);
    }
  }

  for (const entry of entries) {
    if (!keep.has(entry.directory) && entry.manifest.reason !== 'manual') {
      rmSync(entry.directory, { recursive: true, force: true });
    }
  }
}

export async function verifyBackup(directory) {
  const manifest = readManifest(directory);
  if (!manifest) throw new Error('Backupmanifestet mangler eller er ugyldigt');
  for (const [name, expected] of Object.entries(manifest.checksums || {})) {
    const file = join(directory, name);
    if (!existsSync(file) || checksum(file) !== expected) throw new Error(`Backupfilen ${name} kunne ikke verificeres`);
  }
  const backupDb = new DatabaseSync(join(directory, 'linkvagt.db'), { readOnly: true });
  try {
    const integrity = backupDb.prepare('PRAGMA integrity_check').get().integrity_check;
    if (integrity !== 'ok') throw new Error(`SQLite-integritetskontrollen fejlede: ${integrity}`);
    return { ok: true, integrity, manifest, directory };
  } finally {
    backupDb.close();
  }
}

async function performBackup(reason) {
  const createdAt = new Date();
  const safeReason = String(reason).replace(/[^a-z0-9-]/gi, '-').toLowerCase();
  const name = `${timestamp(createdAt)}_${safeReason}`;
  const temporary = join(backupRoot, `.${name}.tmp`);
  const destination = join(backupRoot, name);
  rmSync(temporary, { recursive: true, force: true });
  mkdirSync(temporary, { recursive: true });

  try {
    const databaseFile = join(temporary, 'linkvagt.db');
    await sqliteBackup(db, databaseFile);
    const files = ['linkvagt.db'];
    if (existsSync(keyFile)) {
      copyFileSync(keyFile, join(temporary, 'linkvagt.key'));
      files.push('linkvagt.key');
    }

    const backupDb = new DatabaseSync(databaseFile, { readOnly: true });
    let integrity;
    let counts;
    try {
      integrity = backupDb.prepare('PRAGMA integrity_check').get().integrity_check;
      counts = {
        sites: Number(backupDb.prepare('SELECT COUNT(*) AS count FROM sites').get().count),
        scans: Number(backupDb.prepare('SELECT COUNT(*) AS count FROM scans').get().count),
        changes: Number(backupDb.prepare('SELECT COUNT(*) AS count FROM link_changes').get().count)
      };
    } finally {
      backupDb.close();
    }
    if (integrity !== 'ok') throw new Error(`SQLite-integritetskontrollen fejlede: ${integrity}`);

    const manifest = {
      version: 1,
      created_at: createdAt.toISOString(),
      reason: safeReason,
      integrity,
      counts,
      key_included: files.includes('linkvagt.key'),
      checksums: Object.fromEntries(files.map((file) => [file, checksum(join(temporary, file))]))
    };
    writeFileSync(join(temporary, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
    renameSync(temporary, destination);
    await verifyBackup(destination);
    lastError = null;
    cleanOldBackups();
    return { ...manifest, name, directory: destination, size: directorySize(destination) };
  } catch (error) {
    rmSync(temporary, { recursive: true, force: true });
    lastError = { message: String(error.message || error), at: new Date().toISOString() };
    throw error;
  }
}

export function createBackup(reason = 'manual', { force = true } = {}) {
  if (!force && hasBackupToday()) return Promise.resolve(backupStatus().latest);
  if (currentBackup) return currentBackup;
  currentBackup = performBackup(reason).finally(() => {
    currentBackup = null;
  });
  return currentBackup;
}

export function hasBackupToday() {
  const today = new Date().toISOString().slice(0, 10);
  return backupEntries().some((entry) => String(entry.manifest.created_at).slice(0, 10) === today);
}

export function backupStatus() {
  const entries = backupEntries();
  const latest = entries[0]
    ? {
        ...entries[0].manifest,
        name: entries[0].name,
        size: directorySize(entries[0].directory)
      }
    : null;
  return {
    location: backupRoot,
    latest,
    count: entries.length,
    total_size: entries.reduce((total, entry) => total + directorySize(entry.directory), 0),
    in_progress: Boolean(currentBackup),
    last_error: lastError,
    database_counts: {
      sites: Number(row('SELECT COUNT(*) AS count FROM sites').count),
      scans: Number(row('SELECT COUNT(*) AS count FROM scans').count)
    }
  };
}

export async function verifyLatestBackup() {
  const latest = backupEntries()[0];
  if (!latest) throw new Error('Der findes endnu ingen verificerbar backup');
  const result = await verifyBackup(latest.directory);
  return { ok: result.ok, integrity: result.integrity, name: latest.name, checked_at: new Date().toISOString() };
}

