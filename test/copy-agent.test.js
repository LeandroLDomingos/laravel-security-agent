import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { copyAgent } from '../src/copy-agent.js';

function tmpDir() {
    return mkdtempSync(join(tmpdir(), 'cg-agent-'));
}

test('copyAgent creates SecurityAgent.php in app/Agents/Security/', () => {
    const dir = tmpDir();
    try {
        const result = copyAgent(dir);
        assert.equal(result.skipped, false);
        assert.ok(existsSync(join(dir, 'app', 'Agents', 'Security', 'SecurityAgent.php')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent creates AgentState.php in app/Agents/Security/', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        assert.ok(existsSync(join(dir, 'app', 'Agents', 'Security', 'AgentState.php')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent creates SecuritySkillSet.php in app/Skills/', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        assert.ok(existsSync(join(dir, 'app', 'Skills', 'SecuritySkillSet.php')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent creates all three skill files', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        assert.ok(existsSync(join(dir, 'app', 'Skills', 'VulnerabilityScanSkill.php')));
        assert.ok(existsSync(join(dir, 'app', 'Skills', 'AuthFlowSkill.php')));
        assert.ok(existsSync(join(dir, 'app', 'Skills', 'PatchApplySkill.php')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent creates ZeroTrustMiddleware.php in app/Http/Middleware/', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        assert.ok(existsSync(join(dir, 'app', 'Http', 'Middleware', 'ZeroTrustMiddleware.php')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent creates manifest.json in .github/', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        assert.ok(existsSync(join(dir, '.github', 'manifest.json')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent creates config/security-agent.php', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        assert.ok(existsSync(join(dir, 'config', 'security-agent.php')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent skips when files exist and overwrite is false', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        const result = copyAgent(dir, false);
        assert.equal(result.skipped, true);
        assert.equal(result.copied.length, 0);
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyAgent overwrites when overwrite is true', () => {
    const dir = tmpDir();
    try {
        copyAgent(dir);
        const result = copyAgent(dir, true);
        assert.equal(result.skipped, false);
        assert.ok(result.copied.length > 0);
    } finally {
        rmSync(dir, { recursive: true });
    }
});
