import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const testDir = mkdtempSync(join(tmpdir(), 'linkvagt-backup-'));
process.env.LINKVAGT_DB = join(testDir, 'data', 'test.db');
process.env.LINKVAGT_KEY_FILE = join(testDir, 'data', 'test.key');
process.env.LINKVAGT_BACKUP_DIR = join(testDir, 'Backup');

const { db, run } = await import('../src/db.js');
const { backupStatus, createBackup, verifyLatestBackup } = await import('../src/backup.js');

test.after(() => {
  db.close();
  rmSync(testDir, { recursive: true, force: true });
});

test('opretter og verificerer en komplet backup', async () => {
  writeFileSync(process.env.LINKVAGT_KEY_FILE, Buffer.alloc(32, 7));
  run("INSERT INTO sites (name, base_url) VALUES ('Backup test', 'https://backup.example/')");

  const created = await createBackup('manual');
  assert.equal(created.integrity, 'ok');
  assert.equal(created.counts.sites, 1);
  assert.equal(created.key_included, true);

  const status = backupStatus();
  assert.equal(status.count, 1);
  assert.equal(status.latest.reason, 'manual');
  assert.equal(status.latest.checksums['linkvagt.key'].length, 64);

  const verified = await verifyLatestBackup();
  assert.equal(verified.ok, true);
  assert.equal(readFileSync(join(process.env.LINKVAGT_BACKUP_DIR, status.latest.name, 'linkvagt.key')).length, 32);
});

