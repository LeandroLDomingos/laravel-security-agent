import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { copyCopilot } from '../src/copy-copilot.js';

function tmpDir() {
    return mkdtempSync(join(tmpdir(), 'cg-copilot-'));
}

test('copyCopilot creates .github/copilot-instructions.md', () => {
    const dir = tmpDir();
    try {
        const result = copyCopilot(dir);
        assert.equal(result.skipped, false);
        assert.ok(existsSync(join(dir, '.github', 'copilot-instructions.md')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyCopilot creates .github dir if it does not exist', () => {
    const dir = tmpDir();
    try {
        assert.ok(!existsSync(join(dir, '.github')));
        copyCopilot(dir);
        assert.ok(existsSync(join(dir, '.github')));
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyCopilot skips when file exists and overwrite is false', () => {
    const dir = tmpDir();
    try {
        copyCopilot(dir);
        const result = copyCopilot(dir, false);
        assert.equal(result.skipped, true);
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('copyCopilot overwrites when overwrite is true', () => {
    const dir = tmpDir();
    try {
        copyCopilot(dir);
        const result = copyCopilot(dir, true);
        assert.equal(result.skipped, false);
    } finally {
        rmSync(dir, { recursive: true });
    }
});
