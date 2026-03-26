#!/usr/bin/env node
import {
    intro, outro, multiselect, confirm,
    spinner, note, cancel, isCancel
} from '@clack/prompts';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { copySecurity } from '../src/copy-security.js';
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
    initialValues: ['security', 'gitignore', 'hook'],
    options: [
        { value: 'security',  label: 'SECURITY.md',       hint: 'AI security audit agent' },
        { value: 'gitignore', label: 'Update .gitignore',  hint: 'protects deploy.php, .env, SSH keys' },
        { value: 'hook',      label: 'Pre-commit hook',    hint: 'blocks commits of sensitive files' },
    ],
});

if (isCancel(options)) {
    cancel('Installation cancelled.');
    process.exit(0);
}

const s = spinner();

// --- SECURITY.md ---
if (options.includes('security')) {
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

note(
    'Open this project in Claude Code and say:\n"audit security" or "run SECURITY.md"',
    'Next step'
);

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
