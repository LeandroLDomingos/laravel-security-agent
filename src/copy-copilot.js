// src/copy-copilot.js
import { existsSync, copyFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const TEMPLATE = join(__dirname, '../templates/copilot-instructions.md');

export function copyCopilot(destDir, overwrite = false) {
    const githubDir = join(destDir, '.github');
    const dest = join(githubDir, 'copilot-instructions.md');

    if (existsSync(dest) && !overwrite) return { skipped: true };

    if (!existsSync(githubDir)) mkdirSync(githubDir, { recursive: true });

    copyFileSync(TEMPLATE, dest);
    return { skipped: false, path: dest };
}
