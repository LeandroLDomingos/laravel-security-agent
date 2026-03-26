// src/copy-agent.js
import { existsSync, copyFileSync, mkdirSync, readdirSync } from 'node:fs';
import { join, relative, dirname, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const PHP_TEMPLATE_DIR    = join(__dirname, '../templates/php');
const AGENTS_TEMPLATE_DIR = join(__dirname, '../templates/agents');
const SCRIPTS_TEMPLATE_DIR = join(__dirname, '../templates/scripts');
const MANIFEST_TEMPLATE   = join(__dirname, '../templates/manifest.json');

/**
 * Map template sub-paths to destination paths within the target Laravel project.
 *
 * Template layout → Destination layout:
 *   templates/php/Contracts/         → app/Contracts/
 *   templates/php/Agents/            → app/Agents/
 *   templates/php/Skills/            → app/Skills/
 *   templates/php/Http/Controllers/  → app/Http/Controllers/
 *   templates/php/Http/Middleware/   → app/Http/Middleware/
 *   templates/php/Http/Requests/     → app/Http/Requests/
 *   templates/php/config/            → config/
 *   templates/manifest.json          → .github/manifest.json
 */
function resolveDestination(templateFile, destDir) {
    const rel = relative(PHP_TEMPLATE_DIR, templateFile); // e.g. "Agents/Security/SecurityAgent.php"

    // config/ subtree maps to project-root config/, not inside app/
    if (rel.startsWith('config' + sep) || rel.startsWith('config/')) {
        return join(destDir, rel);
    }

    return join(destDir, 'app', rel);
}

/**
 * Recursively collect all files under a directory.
 *
 * @param {string} dir
 * @returns {string[]} Absolute file paths.
 */
function collectFiles(dir) {
    const entries = readdirSync(dir, { withFileTypes: true });
    const files = [];
    for (const entry of entries) {
        const abs = join(dir, entry.name);
        if (entry.isDirectory()) {
            files.push(...collectFiles(abs));
        } else {
            files.push(abs);
        }
    }
    return files;
}

/**
 * Copy the Antigravity PHP agent classes, Copilot manifest and
 * Copilot .agent.md subagent definitions into the target Laravel project.
 *
 * Destination layout:
 *   templates/agents/*.agent.md  → .github/agents/*.agent.md
 *   templates/manifest.json      → .github/manifest.json
 *   templates/php/**             → app/** / config/**
 *
 * @param {string}  destDir   Absolute path to the Laravel project root.
 * @param {boolean} overwrite Whether to overwrite files that already exist.
 * @returns {{ skipped: boolean, copied: string[], alreadyExisted: string[] }}
 */
export function copyAgent(destDir, overwrite = false) {
    const phpFiles = collectFiles(PHP_TEMPLATE_DIR);
    const copied = [];
    const alreadyExisted = [];

    for (const src of phpFiles) {
        const dest = resolveDestination(src, destDir);

        if (existsSync(dest) && !overwrite) {
            alreadyExisted.push(dest);
            continue;
        }

        mkdirSync(dirname(dest), { recursive: true });
        copyFileSync(src, dest);
        copied.push(dest);
    }

    // Copy manifest.json → .github/manifest.json
    const githubDir = join(destDir, '.github');
    const manifestDest = join(githubDir, 'manifest.json');

    if (!existsSync(manifestDest) || overwrite) {
        mkdirSync(githubDir, { recursive: true });
        copyFileSync(MANIFEST_TEMPLATE, manifestDest);
        copied.push(manifestDest);
    } else {
        alreadyExisted.push(manifestDest);
    }

    // Copy *.agent.md → .github/agents/
    const agentsDir = join(destDir, '.github', 'agents');
    const agentFiles = collectFiles(AGENTS_TEMPLATE_DIR);

    for (const src of agentFiles) {
        const dest = join(agentsDir, src.slice(AGENTS_TEMPLATE_DIR.length + 1));

        if (existsSync(dest) && !overwrite) {
            alreadyExisted.push(dest);
            continue;
        }

        mkdirSync(dirname(dest), { recursive: true });
        copyFileSync(src, dest);
        copied.push(dest);
    }

    // Copy scripts/*.sh → storage/app/
    const storageDir = join(destDir, 'storage', 'app');
    const scriptFiles = collectFiles(SCRIPTS_TEMPLATE_DIR);

    for (const src of scriptFiles) {
        const dest = join(storageDir, src.slice(SCRIPTS_TEMPLATE_DIR.length + 1));

        if (existsSync(dest) && !overwrite) {
            alreadyExisted.push(dest);
            continue;
        }

        mkdirSync(dirname(dest), { recursive: true });
        copyFileSync(src, dest);
        copied.push(dest);
    }

    return {
        skipped: copied.length === 0,
        copied,
        alreadyExisted,
    };
}
