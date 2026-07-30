import { createHash } from 'node:crypto';
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync
} from 'node:fs';
import { join, resolve } from 'node:path';
import { createInterface } from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { backup as sqliteBackup, DatabaseSync } from 'node:sqlite';

const root = resolve('.');
const backupRoot = process.env.LINKVAGT_BACKUP_DIR || join(root, 'Backup');
const dataRoot = join(root, 'data');
const databaseFile = process.env.LINKVAGT_DB || join(dataRoot, 'linkvagt.db');
const keyFile = process.env.LINKVAGT_KEY_FILE || join(dataRoot, 'linkvagt.key');

function checksum(file) {
  return createHash('sha256').update(readFileSync(file)).digest('hex');
}

function manifestEntries() {
  return readdirSync(backupRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && existsSync(join(backupRoot, entry.name, 'manifest.json')))
    .map((entry) => {
      const directory = join(backupRoot, entry.name);
      const manifest = JSON.parse(readFileSync(join(directory, 'manifest.json'), 'utf8'));
      return { directory, name: entry.name, manifest };
    })
    .sort((left, right) => String(right.manifest.created_at).localeCompare(String(left.manifest.created_at)));
}

function verifyEntry(entry) {
  for (const [name, expected] of Object.entries(entry.manifest.checksums || {})) {
    const file = join(entry.directory, name);
    if (!existsSync(file) || checksum(file) !== expected) throw new Error(`${name} kunne ikke verificeres`);
  }
  const candidate = new DatabaseSync(join(entry.directory, 'linkvagt.db'), { readOnly: true });
  try {
    const integrity = candidate.prepare('PRAGMA integrity_check').get().integrity_check;
    if (integrity !== 'ok') throw new Error(`SQLite-integritetskontrollen fejlede: ${integrity}`);
    const connections = Number(candidate.prepare('SELECT COUNT(*) AS count FROM wordpress_connections').get().count);
    if (connections && !existsSync(join(entry.directory, 'linkvagt.key'))) {
      throw new Error('Backuppen indeholder WordPress-forbindelser, men krypteringsnøglen mangler');
    }
  } finally {
    candidate.close();
  }
}

async function createEmergencyBackup() {
  const name = `before-restore-${new Date().toISOString().replace(/\.\d{3}Z$/, 'Z').replaceAll(':', '-')}`;
  const directory = join(backupRoot, name);
  mkdirSync(directory, { recursive: true });
  const current = new DatabaseSync(databaseFile);
  try {
    await sqliteBackup(current, join(directory, 'linkvagt.db'));
  } finally {
    current.close();
  }
  const files = ['linkvagt.db'];
  if (existsSync(keyFile)) {
    copyFileSync(keyFile, join(directory, 'linkvagt.key'));
    files.push('linkvagt.key');
  }
  const manifest = {
    version: 1,
    created_at: new Date().toISOString(),
    reason: 'manual',
    integrity: 'ok',
    key_included: files.includes('linkvagt.key'),
    checksums: Object.fromEntries(files.map((name) => [name, checksum(join(directory, name))]))
  };
  writeFileSync(join(directory, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
  return directory;
}

const entries = manifestEntries();
if (!entries.length) {
  console.error('Der findes ingen verificerede backups, som kan gendannes.');
  process.exit(1);
}

console.log('\nTilgængelige LinkVagt-backups:\n');
entries.slice(0, 20).forEach((entry, index) => {
  console.log(`${index + 1}. ${new Date(entry.manifest.created_at).toLocaleString('da-DK')} · ${entry.manifest.reason} · ${entry.name}`);
});

const prompt = createInterface({ input, output });
const answer = await prompt.question('\nVælg nummeret på den backup, der skal gendannes (eller Enter for at annullere): ');
const selectedIndex = Number(answer) - 1;
if (!answer || !Number.isInteger(selectedIndex) || !entries[selectedIndex]) {
  console.log('Gendannelsen blev annulleret.');
  prompt.close();
  process.exit(2);
}

const selected = entries[selectedIndex];
console.log('\nKontrollerer den valgte backup...');
verifyEntry(selected);
console.log('Backuppen er godkendt.');
const confirmation = await prompt.question('Skriv GENDAN for at erstatte den aktive database: ');
prompt.close();
if (confirmation !== 'GENDAN') {
  console.log('Gendannelsen blev annulleret.');
  process.exit(2);
}

const emergency = await createEmergencyBackup();
rmSync(`${databaseFile}-wal`, { force: true });
rmSync(`${databaseFile}-shm`, { force: true });
copyFileSync(join(selected.directory, 'linkvagt.db'), databaseFile);
if (existsSync(join(selected.directory, 'linkvagt.key'))) copyFileSync(join(selected.directory, 'linkvagt.key'), keyFile);

const restored = new DatabaseSync(databaseFile, { readOnly: true });
try {
  const integrity = restored.prepare('PRAGMA integrity_check').get().integrity_check;
  if (integrity !== 'ok') throw new Error(`Den gendannede database fejlede kontrollen: ${integrity}`);
} finally {
  restored.close();
}

console.log(`\nLinkVagt er gendannet fra ${selected.name}.`);
console.log(`Den tidligere database er gemt i ${emergency}.`);

