// test/install-hook.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, existsSync, mkdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { installHook } from '../src/install-hook.js';

test('installHook creates pre-commit file in .git/hooks/', () => {
    const dir = mkdtempSync(join(tmpdir(), 'hook-test-'));
    mkdirSync(join(dir, '.git', 'hooks'), { recursive: true });
    const result = installHook(dir);
    assert.ok(existsSync(join(dir, '.git', 'hooks', 'pre-commit')));
    assert.equal(result.installed, true);
});

test('installHook returns gitNotFound when .git does not exist', () => {
    const dir = mkdtempSync(join(tmpdir(), 'hook-test-'));
    const result = installHook(dir);
    assert.equal(result.gitNotFound, true);
});

test('installed pre-commit has execute permission', () => {
    // chmodSync is a no-op on Windows — skip on this platform
    if (process.platform === 'win32') return;
    const dir = mkdtempSync(join(tmpdir(), 'hook-test-'));
    mkdirSync(join(dir, '.git', 'hooks'), { recursive: true });
    installHook(dir);
    const mode = statSync(join(dir, '.git', 'hooks', 'pre-commit')).mode;
    assert.ok(mode & 0o100, 'should have owner execute bit set');
});
