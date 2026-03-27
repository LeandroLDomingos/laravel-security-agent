// test/install-hook.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, existsSync, mkdirSync, statSync, readFileSync } from 'node:fs';
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

test('pre-commit regex does not block variable names without values (false positive fix)', () => {
    const dir = mkdtempSync(join(tmpdir(), 'hook-test-'));
    mkdirSync(join(dir, '.git', 'hooks'), { recursive: true });
    installHook(dir);
    const hook = readFileSync(join(dir, '.git', 'hooks', 'pre-commit'), 'utf8');

    // Verify the hook uses the precise credential pattern (requires a value after =)
    assert.ok(
        hook.includes('DB_PASSWORD\\s*=\\s*\\S+'),
        'hook must require a value after DB_PASSWORD= (not block bare variable names)'
    );
    assert.ok(
        hook.includes('APP_KEY\\s*=\\s*base64:'),
        'hook must require the base64: prefix after APP_KEY= (not block bare variable names)'
    );

    // Verify the old over-broad pattern is NOT present
    assert.ok(
        !hook.match(/grep.*iE.*"[^"]*\(DB_PASSWORD\|APP_KEY\)/),
        'hook must not use the old broad pattern that blocks any mention of DB_PASSWORD or APP_KEY'
    );
});
