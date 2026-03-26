// test/update-gitignore.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { buildLinesToAdd, applyGitignore } from '../src/update-gitignore.js';

const ENTRIES = ['.env', '.env.*', '!.env.example', 'deploy.php', '*.pem', '*.key'];

test('buildLinesToAdd does not include already-present entries', () => {
    const existing = '.env\ndeploy.php\n';
    const toAdd = buildLinesToAdd(existing, ENTRIES);
    assert.ok(!toAdd.includes('.env'), 'should not duplicate .env');
    assert.ok(!toAdd.includes('deploy.php'), 'should not duplicate deploy.php');
    assert.ok(toAdd.includes('*.pem'), 'should add *.pem');
});

test('applyGitignore creates .gitignore when it does not exist', () => {
    const dir = mkdtempSync(join(tmpdir(), 'gi-test-'));
    const result = applyGitignore(dir, ENTRIES);
    const content = readFileSync(join(dir, '.gitignore'), 'utf8');
    assert.ok(content.includes('.env'));
    assert.equal(result.created, true);
});

test('applyGitignore does not duplicate existing entries', () => {
    const dir = mkdtempSync(join(tmpdir(), 'gi-test-'));
    writeFileSync(join(dir, '.gitignore'), '.env\n');
    applyGitignore(dir, ENTRIES);
    const content = readFileSync(join(dir, '.gitignore'), 'utf8');
    // Count exact line matches — '.env.*' and '!.env.example' also contain '.env'
    const lines = content.split('\n').map(l => l.trim());
    const count = lines.filter(l => l === '.env').length;
    assert.equal(count, 1, '.env should appear exactly once');
});
