// src/update-gitignore.js
import { existsSync, readFileSync, appendFileSync } from 'node:fs';
import { join } from 'node:path';

export const DEFAULT_ENTRIES = [
    '',
    '# laravel-security-agent (Capi Guard)',
    '.env',
    '.env.*',
    '!.env.example',
    'deploy.php',
    'deployer.php',
    '*.pem',
    '*.key',
    '*.p12',
    '*.pfx',
    'id_rsa',
    'id_ed25519',
];

export function buildLinesToAdd(existingContent, entries) {
    return entries.filter(entry => {
        // Always include structural lines (blank lines and comments)
        if (entry === '' || entry.startsWith('#')) return true;
        const lines = existingContent.split('\n').map(l => l.trim());
        return !lines.includes(entry.trim());
    });
}

export function applyGitignore(destDir, entries = DEFAULT_ENTRIES) {
    const path = join(destDir, '.gitignore');
    const existing = existsSync(path) ? readFileSync(path, 'utf8') : '';
    const toAdd = buildLinesToAdd(existing, entries);

    // Only proceed if there are real entries to add (ignore blank lines and comments)
    const meaningful = toAdd.filter(e => e !== '' && !e.startsWith('#'));
    if (meaningful.length === 0) return { added: 0, created: false };

    const created = !existsSync(path);
    const linesToWrite = (created && toAdd[0] === '') ? toAdd.slice(1) : toAdd;
    const prefix = created ? '' : '\n';
    appendFileSync(path, prefix + linesToWrite.join('\n') + '\n');
    return { added: meaningful.length, created };
}
