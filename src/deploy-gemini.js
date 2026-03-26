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
 * @param {string}  [homeDir]   Override home directory (for testing).
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
