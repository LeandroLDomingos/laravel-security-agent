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
 * @param {string} [homeDir]    Override home directory (for testing).
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
