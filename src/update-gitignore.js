// src/update-gitignore.js
import { existsSync, readFileSync, appendFileSync } from 'node:fs';
import { join } from 'node:path';

export const DEFAULT_ENTRIES = [
    '',
    '# ── Capi Guard — security rules ──────────────────────────────────────',
    '',
    '# Environment',
    '# .env.example is safe: commit it with key names but empty values',
    '# (APP_KEY=, DB_PASSWORD=) — never commit the real .env',
    '.env',
    '.env.*',
    '!.env.example',
    '',
    '# Deployment — often contain server IPs, SSH passwords, sudo commands',
    'deploy.php',
    'deployer.php',
    'deployer.json',
    '.deploy/',
    '',
    '# Cryptographic keys and certificates',
    '*.pem',
    '*.key',
    '*.p12',
    '*.pfx',
    '*.jks',
    '*.keystore',
    'id_rsa',
    'id_rsa.pub',
    'id_ed25519',
    'id_ed25519.pub',
    '',
    '# Credential and token stores',
    'auth.json',
    '',
    '# Docker local overrides — often include server IPs and passwords',
    'docker-compose.override.yml',
    'docker-compose.local.yml',
    '',
    '# Database dumps — contain raw user data and credentials',
    '*.sql',
    '*.sql.gz',
    '*.dump',
    '',
    '# Laravel runtime artifacts',
    '/.phpunit.result.cache',
    '/storage/debugbar/',
];

export function buildLinesToAdd(existingContent, entries) {
    const existingLines = new Set(existingContent.split('\n').map(l => l.trim()));
    return entries.filter(entry => {
        if (entry === '') return true; // always keep blank lines for formatting
        return !existingLines.has(entry.trim()); // deduplicate comments and entries alike
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
