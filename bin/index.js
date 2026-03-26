#!/usr/bin/env node
import {
    intro, outro, multiselect, confirm,
    spinner, note, cancel, isCancel
} from '@clack/prompts';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { copySecurity } from '../src/copy-security.js';
import { copyCopilot } from '../src/copy-copilot.js';
import { applyGitignore } from '../src/update-gitignore.js';
import { installHook } from '../src/install-hook.js';

const cwd = process.cwd();

intro('🐾 Capi Guard — Laravel Security Agent');

// Warn if not running at a Laravel project root
if (!existsSync(join(cwd, 'composer.json'))) {
    note(
        'composer.json not found.\nMake sure you run this at the root of your Laravel project.',
        'Warning'
    );
}

const options = await multiselect({
    message: 'What would you like to install?',
    initialValues: ['claude', 'copilot', 'gitignore', 'hook'],
    options: [
        { value: 'claude',   label: 'SECURITY.md',                        hint: 'Claude Code security agent' },
        { value: 'copilot',  label: '.github/copilot-instructions.md',     hint: 'GitHub Copilot security agent' },
        { value: 'gitignore',label: 'Update .gitignore',                   hint: 'protects deploy.php, .env, SSH keys' },
        { value: 'hook',     label: 'Pre-commit hook',                     hint: 'blocks commits of sensitive files' },
    ],
});

if (isCancel(options)) {
    cancel('Installation cancelled.');
    process.exit(0);
}

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

const nextSteps = [];
if (options.includes('claude'))  nextSteps.push('Claude Code: say "audit security" or "run SECURITY.md"');
if (options.includes('copilot')) nextSteps.push('Copilot: .github/copilot-instructions.md is loaded automatically');

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
