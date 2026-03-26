#!/usr/bin/env node
import {
    intro, outro, confirm,
    spinner, note, cancel, isCancel
} from '@clack/prompts';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { copySecurity } from '../src/copy-security.js';
import { copyCopilot } from '../src/copy-copilot.js';
import { applyGitignore } from '../src/update-gitignore.js';
import { installHook } from '../src/install-hook.js';
import { copyAgent } from '../src/copy-agent.js';

const cwd = process.cwd();

intro('🐾 Capi Guard — Laravel Security Agent');

// Warn if not running at a Laravel project root
if (!existsSync(join(cwd, 'composer.json'))) {
    note(
        'composer.json not found.\nMake sure you run this at the root of your Laravel project.',
        'Warning'
    );
}

const options = ['claude', 'copilot', 'gitignore', 'hook', 'agent'];

const s = spinner();

// --- SECURITY.md (Claude Code) ---
if (options.includes('claude')) {
    const dest = join(cwd, 'SECURITY.md');
    let overwrite = false;

    if (existsSync(dest)) {
        const answer = await confirm({
            message: 'SECURITY.md already exists. Overwrite?',
            initialValue: false,
        });
        if (isCancel(answer)) { cancel('Cancelled.'); process.exit(0); }
        overwrite = answer;
    }

    s.start('Copying SECURITY.md...');
    const result = copySecurity(cwd, overwrite);
    s.stop(result.skipped
        ? 'SECURITY.md kept (not overwritten)'
        : '✔  SECURITY.md installed');
}

// --- copilot-instructions.md (GitHub Copilot) ---
if (options.includes('copilot')) {
    const dest = join(cwd, '.github', 'copilot-instructions.md');
    let overwrite = false;

    if (existsSync(dest)) {
        const answer = await confirm({
            message: '.github/copilot-instructions.md already exists. Overwrite?',
            initialValue: false,
        });
        if (isCancel(answer)) { cancel('Cancelled.'); process.exit(0); }
        overwrite = answer;
    }

    s.start('Copying copilot-instructions.md...');
    const result = copyCopilot(cwd, overwrite);
    s.stop(result.skipped
        ? '.github/copilot-instructions.md kept (not overwritten)'
        : '✔  .github/copilot-instructions.md installed');
}

// --- .gitignore ---
if (options.includes('gitignore')) {
    s.start('Updating .gitignore...');
    const result = applyGitignore(cwd);
    s.stop(result.added > 0
        ? `✔  .gitignore updated (${result.added} entries added)`
        : '✔  .gitignore already up to date');
}

// --- pre-commit hook ---
if (options.includes('hook')) {
    s.start('Installing pre-commit hook...');
    const result = installHook(cwd);
    s.stop(result.gitNotFound
        ? '⚠  .git not found — initialize git first, then run this again'
        : '✔  pre-commit hook installed at .git/hooks/pre-commit');
}

// --- Antigravity Agent (PHP) ---
if (options.includes('agent')) {
    let overwrite = false;
    const agentEntry = join(cwd, 'app', 'Agents', 'Security', 'SecurityAgent.php');

    if (existsSync(agentEntry)) {
        const answer = await confirm({
            message: 'app/Agents/Security/ already exists. Overwrite agent files?',
            initialValue: false,
        });
        if (isCancel(answer)) { cancel('Cancelled.'); process.exit(0); }
        overwrite = answer;
    }

    s.start('Scaffolding Antigravity agent classes...');
    const result = copyAgent(cwd, overwrite);
    s.stop(result.skipped
        ? 'Agent files kept (not overwritten)'
        : `✔  Antigravity agent installed (${result.copied.length} files → app/Agents/, app/Skills/, app/Http/, config/, .github/)`);
}

const nextSteps = [];
if (options.includes('claude'))  nextSteps.push('Claude Code: say "audit security" or "run SECURITY.md"');
if (options.includes('copilot')) nextSteps.push('Copilot: .github/copilot-instructions.md is loaded automatically');
if (options.includes('agent'))   nextSteps.push(
    'Antigravity Agent:\n' +
    '  1. Add route: Route::post("/api/agent/invoke", AgentController::class)->middleware(["auth:sanctum", ZeroTrustMiddleware::class]);\n' +
    '  2. Issue a Sanctum token with ability "agent:invoke"\n' +
    '  3. Register .github/manifest.json in your Copilot Extension settings\n' +
    '  4. Optionally run: php artisan vendor:publish --tag=security-agent-config'
);

if (nextSteps.length > 0) {
    note(nextSteps.join('\n'), 'Next step');
}

const capybara = `
　　　　　/)─―ヘ
　　　＿／　　　　＼
　／　　　　●　　　●丶
　｜　　　　　　　▼　|
　｜　　　　　　　亠ノ
　 U￣U￣￣￣￣U￣U

  Thanks for installing Capi Guard! 🐾
  If this helped you, please give us a star:
  ★  https://github.com/LeandroLDomingos/laravel-security-agent
`;

console.log(capybara);

outro('Done! Capi Guard is watching. 🐾');
