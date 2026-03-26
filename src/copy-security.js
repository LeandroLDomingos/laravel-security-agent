// src/copy-security.js
import { existsSync, copyFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const TEMPLATE = join(__dirname, '../templates/SECURITY.md');

export function shouldCopy(dest, overwrite) {
    if (!existsSync(dest)) return true;
    return overwrite;
}

export function copySecurity(destDir, overwrite = false) {
    const dest = join(destDir, 'SECURITY.md');
    if (!shouldCopy(dest, overwrite)) return { skipped: true };
    copyFileSync(TEMPLATE, dest);
    return { skipped: false, path: dest };
}
