// test/deploy-gemini.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { deployGemini } from '../src/deploy-gemini.js';

function makeFakeHome() {
    const dir = join(tmpdir(), `capi-test-gemini-${Date.now()}`);
    mkdirSync(dir, { recursive: true });
    return dir;
}

test('deployGemini creates GEMINI.md when it does not exist', () => {
    const fakeHome = makeFakeHome();
    const result = deployGemini(fakeHome);
    const dest = join(fakeHome, '.gemini', 'GEMINI.md');
    assert.equal(result.skipped, false);
    assert.equal(result.path, dest);
    assert.ok(existsSync(dest));
    rmSync(fakeHome, { recursive: true });
});

test('deployGemini written file contains capi-guard marker', () => {
    const fakeHome = makeFakeHome();
    deployGemini(fakeHome);
    const dest = join(fakeHome, '.gemini', 'GEMINI.md');
    const content = readFileSync(dest, 'utf8');
    assert.ok(content.includes('<!-- capi-guard -->'));
    rmSync(fakeHome, { recursive: true });
});

test('deployGemini skips when marker already present and overwrite is false', () => {
    const fakeHome = makeFakeHome();
    deployGemini(fakeHome); // first install
    const result = deployGemini(fakeHome); // second install
    assert.equal(result.skipped, true);
    rmSync(fakeHome, { recursive: true });
});

test('deployGemini appends to existing GEMINI.md without deleting prior content', () => {
    const fakeHome = makeFakeHome();
    const geminiDir = join(fakeHome, '.gemini');
    mkdirSync(geminiDir, { recursive: true });
    const dest = join(geminiDir, 'GEMINI.md');
    writeFileSync(dest, '# Existing Instructions\n\nSome prior content.\n');
    deployGemini(fakeHome);
    const content = readFileSync(dest, 'utf8');
    assert.ok(content.includes('# Existing Instructions'));
    assert.ok(content.includes('<!-- capi-guard -->'));
    rmSync(fakeHome, { recursive: true });
});

test('deployGemini overwrites (removes old block and re-appends) when overwrite is true', () => {
    const fakeHome = makeFakeHome();
    deployGemini(fakeHome);
    const result = deployGemini(fakeHome, true);
    const dest = join(fakeHome, '.gemini', 'GEMINI.md');
    const content = readFileSync(dest, 'utf8');
    // Should appear exactly once
    const count = (content.match(/<!-- capi-guard -->/g) || []).length;
    assert.equal(result.skipped, false);
    assert.equal(count, 1);
    rmSync(fakeHome, { recursive: true });
});
