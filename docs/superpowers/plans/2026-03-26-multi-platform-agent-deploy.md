# Multi-Platform Agent Deploy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upon running `npx laravel-security-agent`, automatically deploy Capi Guard into Claude Code (as a user-level skill), GitHub Copilot (via `.github/agents/`), and Google Antigravity/Gemini CLI (via `~/.gemini/GEMINI.md`).

**Architecture:** Each deployment target gets its own `src/deploy-*.js` module that reads from a new template file and writes to the appropriate user-global or project-local directory. The existing `bin/index.js` calls all three deployers sequentially after project-level files are installed. Templates follow each platform's native format (Claude Code SKILL.md frontmatter, Gemini GEMINI.md append with idempotency marker, Copilot agents already covered by `copyAgent()`).

**Tech Stack:** Node.js ESM, `node:fs`, `node:os` (homedir), `node:path`, `node:url`, `node --test` (built-in test runner), `@clack/prompts` (already installed)

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `templates/claude-skill/SKILL.md` | Capi Guard skill content for Claude Code |
| Create | `templates/gemini.md` | Capi Guard instructions block for Gemini/Antigravity |
| Create | `src/deploy-claude-skill.js` | Copies SKILL.md → `~/.claude/skills/capi-guard/SKILL.md` |
| Create | `src/deploy-gemini.js` | Appends gemini.md → `~/.gemini/GEMINI.md` (idempotent via marker) |
| Modify | `bin/index.js` | Call the two new deployers + show next steps |
| Create | `test/deploy-claude-skill.test.js` | Tests for deployClaudeSkill() |
| Create | `test/deploy-gemini.test.js` | Tests for deployGemini() |
| Modify | `package.json` | Add new test files to `test` and `prepublishOnly` scripts |

GitHub Copilot (`.github/agents/*.agent.md`) is already deployed by the existing `copyAgent()`. No new module needed — only `bin/index.js` next-steps messaging needs updating.

---

### Task 1: Create the Claude Code skill template

**Files:**
- Create: `templates/claude-skill/SKILL.md`

- [ ] **Step 1: Write the template file**

```markdown
---
name: capi-guard
description: Laravel security audit agent for Capi Guard. Invoke to scan for vulnerabilities, analyze auth flows, and apply CVE patches.
---

# Capi Guard 🐾 — Laravel Security Skill

You are **Capi Guard**, a security audit agent for Laravel projects.

## LANGUAGE

Detect the language of the user's message and respond entirely in that language.

## REQUIRED BEHAVIOR

1. NEVER modify code without asking the user first.
2. For each issue found, present:
   - File and line where the problem is
   - Why it is a risk (category: IDOR, SQL Injection, Mass Assignment, XSS, CSRF, etc.)
   - What you intend to do to fix it
   - Wait for explicit approval before editing.
3. If you find multiple issues, list ALL of them first, then ask which ones to fix and in what order.
4. After each fix, show the diff and confirm.

## SECURITY CATEGORIES TO AUDIT

- **IDOR** — direct object references without ownership checks
- **SQL Injection** — raw DB queries with user input
- **Mass Assignment** — missing `$fillable` / `$guarded` on models
- **XSS** — unescaped `{!! !!}` in Blade, `v-html` in Vue
- **CSRF** — missing `@csrf` on forms, unprotected POST routes
- **Authorization** — missing `$this->authorize()` in controllers
- **File Upload** — missing MIME validation, storing in public/
- **Rate Limiting** — public endpoints without throttle middleware
- **Credentials in Code** — hardcoded secrets, keys committed to git
- **Security Headers** — missing CSP, X-Frame-Options, HSTS
- **Git Secrets** — `.env`, `deploy.php`, SSH keys in history

## MANDATORY WORKFLOW

When invoked for any security task, execute **in this order**:

### 1. Reconnaissance
- List affected controllers
- Map related routes in `routes/web.php` and `routes/api.php`
- Identify models, policies, and middleware in scope

### 2. Static Analysis
- Scan each in-scope file for the categories above
- Note: file path, line number, category, severity (critical / important / suggestion)

### 3. Report
- Present ALL findings before touching any file
- Group by severity: critical → important → suggestion
- For each finding: location, risk explanation, proposed fix

### 4. Await Approval
- Ask which issues to fix and in what order
- Do NOT proceed until the user confirms

### 5. Apply Fixes
- Fix one issue at a time
- Show a diff after each change
- Confirm before moving to the next

## CALLABLE SKILLS

When deeper analysis is needed, you can invoke the Capi Guard PHP backend via `POST /api/agent/invoke` (requires a Sanctum Bearer token with the `agent:invoke` ability). Available skills:

- **`vulnerabilityScan`** `{ path: string }` — static analysis across 13 security categories on the given path (e.g. `"app/Http/Controllers"`). Returns findings sorted by severity.
- **`analyzeAuthFlow`** `{ controller: string }` — inspects a controller's public methods via PHP Reflection, detecting missing `$this->authorize()` or `Gate::authorize()` calls.
- **`applySecurityPatch`** `{ cveId: string, filePath: string }` — looks up the CVE in `config/security-agent.php`, creates a timestamped backup, applies the patch, and runs `php artisan optimize:clear`.
- **`sanitizeGitHistory`** `{ backupBranch?: string, dryRun?: boolean, repoPath?: string, generateScriptPath?: string }` — two-phase audit and scrub of secrets across full git history. Defaults to dry-run; always verifies a backup branch before rewriting.

See `.github/manifest.json` for the full OpenAPI spec.

## SCOPE

Act **only** on:
- PHP files in `app/`, `routes/`, `config/`, `bootstrap/`
- `.env.example` (never `.env`)
- Blade templates in `resources/views/`
- Vue/JS files only for `v-html` checks

Do NOT alter:
- Already-executed migrations
- `vendor/`
- Test files (read-only)
```

- [ ] **Step 2: Verify the file exists and has correct frontmatter**

```bash
head -5 templates/claude-skill/SKILL.md
```
Expected output:
```
---
name: capi-guard
description: Laravel security audit agent for Capi Guard. Invoke to scan for vulnerabilities, analyze auth flows, and apply CVE patches.
---
```

- [ ] **Step 3: Commit**

```bash
git add templates/claude-skill/SKILL.md
git commit -m "feat: add Claude Code skill template for Capi Guard"
```

---

### Task 2: Create the Gemini/Antigravity template

**Files:**
- Create: `templates/gemini.md`

- [ ] **Step 1: Write the template file**

The file must begin with the idempotency marker so `deploy-gemini.js` can detect if it was already appended.

```markdown
<!-- capi-guard -->
## Capi Guard 🐾 — Laravel Security Agent

You have the Capi Guard security skill installed for this project.

When the user asks about security, vulnerabilities, or anything related to the Laravel project's safety:

1. **Never modify code without asking first.**
2. Scan for: IDOR, SQL Injection, Mass Assignment, XSS, CSRF, missing authorization, file upload risks, rate limiting gaps, hardcoded credentials, and secrets in git history.
3. Present ALL findings before touching any file. Group by severity.
4. Wait for explicit approval before applying any fix.
5. Show a diff after each change and confirm before continuing.

**Scope:** `app/`, `routes/`, `config/`, `bootstrap/`, `resources/views/`, `.env.example`. Never touch `vendor/` or `.env`.

For deeper analysis, call the Capi Guard PHP backend via `POST /api/agent/invoke` (Sanctum Bearer token, ability `agent:invoke`):

- **`vulnerabilityScan`** `{ path }` — static analysis across 13 categories
- **`analyzeAuthFlow`** `{ controller }` — finds missing `authorize()` calls via PHP Reflection
- **`applySecurityPatch`** `{ cveId, filePath }` — applies CVE patches with backup + Artisan post-run
- **`sanitizeGitHistory`** `{ backupBranch?, dryRun?, repoPath?, generateScriptPath? }` — audits and scrubs secrets from git history

Full spec: `.github/manifest.json`.
<!-- /capi-guard -->
```

- [ ] **Step 2: Verify the marker is present**

```bash
grep "capi-guard" templates/gemini.md
```
Expected: two lines with `<!-- capi-guard -->` and `<!-- /capi-guard -->`

- [ ] **Step 3: Commit**

```bash
git add templates/gemini.md
git commit -m "feat: add Gemini/Antigravity template for Capi Guard"
```

---

### Task 3: Build `src/deploy-claude-skill.js` with tests (TDD)

**Files:**
- Create: `test/deploy-claude-skill.test.js`
- Create: `src/deploy-claude-skill.js`

- [ ] **Step 1: Write the failing tests**

```js
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
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node --test test/deploy-claude-skill.test.js
```
Expected: FAIL — `Cannot find module '../src/deploy-claude-skill.js'`

- [ ] **Step 3: Write the implementation**

```js
// src/deploy-claude-skill.js
import { existsSync, copyFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const SKILL_TEMPLATE = join(__dirname, '../templates/claude-skill/SKILL.md');

/**
 * Deploy the Capi Guard skill to the user's Claude Code skills directory.
 *
 * @param {string} [homeDir]   Override home directory (for testing).
 * @param {boolean} [overwrite] Whether to overwrite an existing skill file.
 * @returns {{ skipped: boolean, path: string }}
 */
export function deployClaudeSkill(homeDir = homedir(), overwrite = false) {
    const skillDir = join(homeDir, '.claude', 'skills', 'capi-guard');
    const dest = join(skillDir, 'SKILL.md');

    if (existsSync(dest) && !overwrite) {
        return { skipped: true, path: dest };
    }

    mkdirSync(skillDir, { recursive: true });
    copyFileSync(SKILL_TEMPLATE, dest);
    return { skipped: false, path: dest };
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
node --test test/deploy-claude-skill.test.js
```
Expected: 4 tests pass, 0 failures

- [ ] **Step 5: Commit**

```bash
git add src/deploy-claude-skill.js test/deploy-claude-skill.test.js
git commit -m "feat: deploy-claude-skill — install Capi Guard into ~/.claude/skills/"
```

---

### Task 4: Build `src/deploy-gemini.js` with tests (TDD)

**Files:**
- Create: `test/deploy-gemini.test.js`
- Create: `src/deploy-gemini.js`

- [ ] **Step 1: Write the failing tests**

```js
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
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
node --test test/deploy-gemini.test.js
```
Expected: FAIL — `Cannot find module '../src/deploy-gemini.js'`

- [ ] **Step 3: Write the implementation**

```js
// src/deploy-gemini.js
import { existsSync, readFileSync, writeFileSync, appendFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const GEMINI_TEMPLATE = join(__dirname, '../templates/gemini.md');

const OPEN_MARKER  = '<!-- capi-guard -->';
const CLOSE_MARKER = '<!-- /capi-guard -->';

/**
 * Append (or re-append) the Capi Guard block to the user's ~/.gemini/GEMINI.md.
 *
 * Idempotent: if the marker is already present and overwrite is false, skip.
 * When overwrite is true, the old block is stripped and the fresh template is appended.
 *
 * @param {string}  [homeDir]  Override home directory (for testing).
 * @param {boolean} [overwrite]
 * @returns {{ skipped: boolean, path: string }}
 */
export function deployGemini(homeDir = homedir(), overwrite = false) {
    const geminiDir = join(homeDir, '.gemini');
    const dest = join(geminiDir, 'GEMINI.md');
    const template = readFileSync(GEMINI_TEMPLATE, 'utf8');

    const existing = existsSync(dest) ? readFileSync(dest, 'utf8') : '';

    if (existing.includes(OPEN_MARKER)) {
        if (!overwrite) return { skipped: true, path: dest };

        // Strip old block (everything between markers, inclusive)
        const stripped = existing.replace(
            new RegExp(`${OPEN_MARKER}[\\s\\S]*?${CLOSE_MARKER}`, 'g'),
            ''
        ).trimEnd();

        mkdirSync(geminiDir, { recursive: true });
        writeFileSync(dest, stripped + '\n' + template + '\n');
        return { skipped: false, path: dest };
    }

    mkdirSync(geminiDir, { recursive: true });
    const prefix = existing && !existing.endsWith('\n') ? '\n' : '';
    appendFileSync(dest, prefix + template + '\n');
    return { skipped: false, path: dest };
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
node --test test/deploy-gemini.test.js
```
Expected: 5 tests pass, 0 failures

- [ ] **Step 5: Commit**

```bash
git add src/deploy-gemini.js test/deploy-gemini.test.js
git commit -m "feat: deploy-gemini — install Capi Guard into ~/.gemini/GEMINI.md"
```

---

### Task 5: Wire up `bin/index.js`

**Files:**
- Modify: `bin/index.js`

- [ ] **Step 1: Add imports at the top of `bin/index.js`**

After the existing imports, add:

```js
import { deployClaudeSkill } from '../src/deploy-claude-skill.js';
import { deployGemini } from '../src/deploy-gemini.js';
```

- [ ] **Step 2: Add the Claude Code deploy block after the existing `hook` block (around line 103)**

Insert after the `// --- pre-commit hook ---` block:

```js
// --- Claude Code skill ---
{
    const dest = join(homedir(), '.claude', 'skills', 'capi-guard', 'SKILL.md');
    let overwrite = false;

    if (existsSync(dest)) {
        const answer = await confirm({
            message: '~/.claude/skills/capi-guard/SKILL.md already exists. Overwrite?',
            initialValue: false,
        });
        if (isCancel(answer)) { cancel('Cancelled.'); process.exit(0); }
        overwrite = answer;
    }

    s.start('Deploying Capi Guard skill to Claude Code...');
    const result = deployClaudeSkill(undefined, overwrite);
    s.stop(result.skipped
        ? '~/.claude/skills/capi-guard/SKILL.md kept (not overwritten)'
        : '✔  Capi Guard skill installed at ~/.claude/skills/capi-guard/SKILL.md');
}

// --- Gemini / Google Antigravity ---
{
    const dest = join(homedir(), '.gemini', 'GEMINI.md');
    let overwrite = false;

    if (existsSync(dest) && readFileSync(dest, 'utf8').includes('<!-- capi-guard -->')) {
        const answer = await confirm({
            message: '~/.gemini/GEMINI.md already has Capi Guard block. Overwrite?',
            initialValue: false,
        });
        if (isCancel(answer)) { cancel('Cancelled.'); process.exit(0); }
        overwrite = answer;
    }

    s.start('Deploying Capi Guard to Gemini / Google Antigravity...');
    const result = deployGemini(undefined, overwrite);
    s.stop(result.skipped
        ? '~/.gemini/GEMINI.md kept (not overwritten)'
        : '✔  Capi Guard block appended to ~/.gemini/GEMINI.md');
}
```

- [ ] **Step 3: Add `homedir` and `readFileSync` imports at the top of `bin/index.js`**

Replace:
```js
import { existsSync } from 'node:fs';
import { join } from 'node:path';
```
With:
```js
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { homedir } from 'node:os';
```

- [ ] **Step 4: Update the `nextSteps` section at the bottom of `bin/index.js`**

Replace the existing `nextSteps` block (starting at `const nextSteps = []`) with:

```js
const nextSteps = [
    'Claude Code: the "capi-guard" skill is now available — just ask Claude to audit security',
    'Gemini CLI: Capi Guard instructions are active in ~/.gemini/GEMINI.md',
    'Copilot: .github/copilot-instructions.md and .github/agents/ are installed automatically',
];

if (options.includes('agent')) {
    nextSteps.push(
        'Antigravity Agent (PHP backend):\n' +
        '  1. Add route: Route::post("/api/agent/invoke", AgentController::class)->middleware(["auth:sanctum", ZeroTrustMiddleware::class]);\n' +
        '  2. Issue a Sanctum token with ability "agent:invoke"\n' +
        '  3. Register .github/manifest.json in your Copilot Extension settings\n' +
        '  4. Optionally run: php artisan vendor:publish --tag=security-agent-config'
    );
}

if (nextSteps.length > 0) {
    note(nextSteps.join('\n'), 'Next steps');
}
```

- [ ] **Step 5: Run the full test suite to verify nothing broke**

```bash
node --test test/copy-security.test.js test/update-gitignore.test.js test/install-hook.test.js test/copy-copilot.test.js test/copy-agent.test.js test/deploy-claude-skill.test.js test/deploy-gemini.test.js
```
Expected: All tests pass, 0 failures

- [ ] **Step 6: Commit**

```bash
git add bin/index.js
git commit -m "feat: wire Claude Code + Gemini deployers into install CLI"
```

---

### Task 6: Update `package.json` test scripts

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Update the `test` and `prepublishOnly` scripts**

In `package.json`, replace:
```json
"test": "node --test test/copy-security.test.js test/update-gitignore.test.js test/install-hook.test.js test/copy-copilot.test.js test/copy-agent.test.js",
"prepublishOnly": "node --test test/copy-security.test.js test/update-gitignore.test.js test/install-hook.test.js test/copy-copilot.test.js test/copy-agent.test.js"
```
With:
```json
"test": "node --test test/copy-security.test.js test/update-gitignore.test.js test/install-hook.test.js test/copy-copilot.test.js test/copy-agent.test.js test/deploy-claude-skill.test.js test/deploy-gemini.test.js",
"prepublishOnly": "node --test test/copy-security.test.js test/update-gitignore.test.js test/install-hook.test.js test/copy-copilot.test.js test/copy-agent.test.js test/deploy-claude-skill.test.js test/deploy-gemini.test.js"
```

- [ ] **Step 2: Run `npm test` to confirm everything passes end-to-end**

```bash
npm test
```
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add package.json
git commit -m "chore: add new deploy tests to npm test and prepublishOnly scripts"
```

---

## Self-Review

**Spec coverage check:**
- ✅ Claude Code deployment → `deploy-claude-skill.js` + `templates/claude-skill/SKILL.md`
- ✅ GitHub Copilot deployment → already handled by `copyAgent()` which installs `.github/agents/*.agent.md` and `copilot-instructions.md`; next-steps messaging updated
- ✅ Google Antigravity/Gemini deployment → `deploy-gemini.js` + `templates/gemini.md`
- ✅ All deploy functions called in `bin/index.js`
- ✅ Overwrite prompts consistent with existing code style
- ✅ Tests cover: fresh install, skip-if-exists, overwrite, content verification, append-without-destroying-prior-content

**Placeholder scan:** None found — all steps contain real code.

**Type consistency:**
- `deployClaudeSkill(homeDir?, overwrite?)` — consistent across Task 3 and Task 5
- `deployGemini(homeDir?, overwrite?)` — consistent across Task 4 and Task 5
- Return shapes `{ skipped, path }` — consistent with existing `copySecurity()` / `copyCopilot()` pattern
