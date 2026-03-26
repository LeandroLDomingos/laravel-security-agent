// src/install-hook.js
import { existsSync, copyFileSync, chmodSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const TEMPLATE = join(__dirname, '../templates/pre-commit');

export function installHook(projectDir) {
    const gitHooksDir = join(projectDir, '.git', 'hooks');

    if (!existsSync(gitHooksDir)) {
        return { installed: false, gitNotFound: true };
    }

    const dest = join(gitHooksDir, 'pre-commit');
    copyFileSync(TEMPLATE, dest);
    chmodSync(dest, 0o755);

    return { installed: true, path: dest };
}
