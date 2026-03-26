// test/deploy-claude-skill.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, rmSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { deployClaudeSkill } from '../src/deploy-claude-skill.js';

function makeFakeHome() {
    const dir = join(tmpdir(), `capi-test-claude-${Date.now()}`);
    mkdirSync(dir, { recursive: true });
    return dir;
}

test('deployClaudeSkill creates SKILL.md when it does not exist', () => {
    const fakeHome = makeFakeHome();
    const result = deployClaudeSkill(fakeHome);
    const dest = join(fakeHome, '.claude', 'skills', 'capi-guard', 'SKILL.md');
    assert.equal(result.skipped, false);
    assert.equal(result.path, dest);
    assert.ok(existsSync(dest));
    rmSync(fakeHome, { recursive: true });
});

test('deployClaudeSkill skips when SKILL.md already exists and overwrite is false', () => {
    const fakeHome = makeFakeHome();
    deployClaudeSkill(fakeHome); // first install
    const result = deployClaudeSkill(fakeHome); // second install, no overwrite
    assert.equal(result.skipped, true);
    rmSync(fakeHome, { recursive: true });
});

test('deployClaudeSkill overwrites when overwrite is true', () => {
    const fakeHome = makeFakeHome();
    deployClaudeSkill(fakeHome);
    const result = deployClaudeSkill(fakeHome, true);
    assert.equal(result.skipped, false);
    rmSync(fakeHome, { recursive: true });
});

test('deployClaudeSkill written file contains capi-guard name frontmatter', () => {
    const fakeHome = makeFakeHome();
    deployClaudeSkill(fakeHome);
    const dest = join(fakeHome, '.claude', 'skills', 'capi-guard', 'SKILL.md');
    const content = readFileSync(dest, 'utf8');
    assert.ok(content.includes('name: capi-guard'));
    rmSync(fakeHome, { recursive: true });
});
