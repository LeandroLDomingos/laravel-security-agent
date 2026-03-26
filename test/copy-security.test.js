// test/copy-security.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, existsSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { shouldCopy } from '../src/copy-security.js';

test('shouldCopy returns true when file does not exist', () => {
    const dir = mkdtempSync(join(tmpdir(), 'sec-test-'));
    const dest = join(dir, 'SECURITY.md');
    assert.equal(shouldCopy(dest, false), true);
});

test('shouldCopy returns false when file exists and overwrite is false', () => {
    const dir = mkdtempSync(join(tmpdir(), 'sec-test-'));
    const dest = join(dir, 'SECURITY.md');
    writeFileSync(dest, 'existing content');
    assert.equal(shouldCopy(dest, false), false);
});

test('shouldCopy returns true when file exists and overwrite is true', () => {
    const dir = mkdtempSync(join(tmpdir(), 'sec-test-'));
    const dest = join(dir, 'SECURITY.md');
    writeFileSync(dest, 'existing content');
    assert.equal(shouldCopy(dest, true), true);
});
