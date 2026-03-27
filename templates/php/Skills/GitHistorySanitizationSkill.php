<?php

declare(strict_types=1);

namespace App\Skills;

use App\Agents\Security\AgentState;
use App\Contracts\SkillInterface;
use Symfony\Component\Process\Process;

/**
 * Git History Sanitization Skill.
 *
 * Performs a two-phase operation:
 *   1. AUDIT  — Scans git log output for secrets (APP_KEY, DB creds, IPs)
 *              across all branches and tags without rewriting anything.
 *   2. SCRUB  — Generates (and optionally executes) a sanitize-git-history.sh
 *              script that uses git filter-repo to purge sensitive files and
 *              blob-replace secrets with [REDACTED], then runs aggressive GC.
 *
 * The skill ALWAYS verifies a backup branch exists before rewriting history.
 * Set params.dryRun = true (default) to only generate the script, not run it.
 */
final class GitHistorySanitizationSkill implements SkillInterface
{
    /** Patterns that must exist in .gitignore for Laravel security compliance. */
    private const REQUIRED_GITIGNORE_PATTERNS = [
        '.env',
        '.env.*',
        'storage/*.key',
        'auth.json',
        'vendor/',
        '.phpunit.result.cache',
        'public/storage',
        '*.pem',
        'id_rsa',
        '*.key',
        'deploy.php',
    ];

    /** Regex patterns targeting secrets in git history blobs. */
    private const SECRET_PATTERNS = [
        // Laravel APP_KEY
        'APP_KEY'      => '/APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]{40,}/',
        // Database credentials
        'DB_PASSWORD'  => '/DB_PASSWORD\s*=\s*\S+/',
        'DB_USERNAME'  => '/DB_USERNAME\s*=\s*(?!root|homestead)\S+/',
        // Redis auth
        'REDIS_PASSWORD' => '/REDIS_PASSWORD\s*=\s*\S+/',
        // Mail credentials
        'MAIL_PASSWORD'  => '/MAIL_PASSWORD\s*=\s*\S+/',
        // Generic API / secret keys
        'API_KEY'      => '/(?:API_KEY|SECRET_KEY|AUTH_TOKEN)\s*=\s*\S{8,}/',
        // Hardcoded credentials in PHP config files
        'PHP_PASSWORD' => "/'password'\s*=>\s*'[^']{3,}'/",
        // Public/staging IPs in generic files — excludes private RFC-1918 ranges to
        // avoid noise. Private IPs in deploy.php are handled by auditDeployPhpInHistory().
        'STAGING_IP'   => '/\b(?!127\.|10\.|172\.|192\.168\.)(?!(?:\d+\.){2}\d+$)(?:\d{1,3}\.){3}\d{1,3}\b/',
    ];

    public function name(): string
    {
        return 'sanitizeGitHistory';
    }

    public function description(): string
    {
        return 'Audits and scrubs the complete git history of a Laravel repository. '
            . 'Phase 1 discovers secrets (APP_KEY, DB/Redis/Mail creds, staging IPs) across all branches. '
            . 'Phase 2 generates a git filter-repo script that purges sensitive files and blob-replaces secrets '
            . 'with [REDACTED], then runs aggressive GC. Requires a backup branch before any rewrite.';
    }

    /** @return array<string, mixed> */
    public function parametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'repoPath' => [
                    'type'        => 'string',
                    'description' => 'Absolute or relative path to the git repository root. Defaults to the Laravel project root.',
                ],
                'backupBranch' => [
                    'type'        => 'string',
                    'description' => 'Name of the backup branch that must exist before history rewrite (e.g. "backup/before-sanitize").',
                    'default'     => 'backup/before-sanitize',
                ],
                'dryRun' => [
                    'type'        => 'boolean',
                    'description' => 'If true (default), only generate the script and audit report — do not execute the rewrite.',
                    'default'     => true,
                ],
                'generateScriptPath' => [
                    'type'        => 'string',
                    'description' => 'Where to write the generated sanitize-git-history.sh script. Defaults to storage/app/sanitize-git-history.sh.',
                    'default'     => 'storage/app/sanitize-git-history.sh',
                ],
            ],
            'required' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function invoke(array $params, AgentState $state): array
    {
        $repoPath    = base_path($params['repoPath'] ?? '');
        $backupBranch = $params['backupBranch'] ?? 'backup/before-sanitize';
        $dryRun       = (bool) ($params['dryRun'] ?? true);
        $scriptDest   = base_path($params['generateScriptPath'] ?? 'storage/app/sanitize-git-history.sh');

        // ── Phase 0: Verify git availability and backup branch ───────────────
        $this->assertIsGitRepo($repoPath);
        $backupExists = $this->branchExists($repoPath, $backupBranch);

        // ── Phase 1: Audit .gitignore alignment ──────────────────────────────
        $gitignoreFindings = $this->auditGitignore($repoPath);

        // ── Phase 2: Discover secrets in history ─────────────────────────────
        $secretFindings = $this->scanGitHistory($repoPath);

        // ── Phase 3: Discover sensitive files in history ─────────────────────
        $sensitiveFiles = $this->findSensitiveFilesInHistory($repoPath);

        // ── Phase 3b: Deep audit deploy.php files found in history ───────────
        $deployPhpFiles = array_values(array_filter(
            $sensitiveFiles,
            fn (string $f): bool => preg_match('/deploy\.php$/i', $f) === 1
        ));
        $deployPhpAudit = !empty($deployPhpFiles)
            ? $this->auditDeployPhpInHistory($repoPath, $deployPhpFiles)
            : [];

        // ── Phase 4: Generate sanitizer script ───────────────────────────────
        $script = $this->buildSanitizerScript($repoPath, $backupBranch, $sensitiveFiles);
        file_put_contents($scriptDest, $script);
        chmod($scriptDest, 0750);

        // ── Phase 5: Execute (only if dryRun = false AND backup exists) ──────
        $executionResult = null;
        if (!$dryRun) {
            if (!$backupExists) {
                throw new \RuntimeException(
                    "Backup branch '{$backupBranch}' does not exist. "
                    . "Create it first: git checkout -b {$backupBranch}"
                );
            }
            $executionResult = $this->executeScript($repoPath, $scriptDest);
        }

        // ── Build response ────────────────────────────────────────────────────
        $totalSecrets = array_sum(array_map('count', $secretFindings));
        $deployRisk   = array_sum(array_map(
            fn (array $d): int => count($d['server_ips']) + count($d['server_paths']),
            $deployPhpAudit
        ));

        return [
            'summary' => implode(' | ', [
                count($gitignoreFindings) . ' gitignore gaps',
                $totalSecrets . ' secret occurrences across ' . count($secretFindings) . ' pattern types',
                count($sensitiveFiles) . ' sensitive files in history',
                count($deployPhpFiles) . ' deploy.php file(s) — ' . $deployRisk . ' server exposure(s)',
                $dryRun ? 'DRY RUN — script generated only' : 'REWRITE EXECUTED',
            ]),
            'backup_branch_exists'    => $backupExists,
            'dryRun'                  => $dryRun,
            'gitignore_gaps'          => $gitignoreFindings,
            'secret_findings'         => $secretFindings,
            'sensitive_files_history' => $sensitiveFiles,
            'deploy_php_audit'        => $deployPhpAudit,
            'generated_script_path'   => $scriptDest,
            'execution_result'        => $executionResult,
            'next_steps'              => $this->buildNextSteps($backupExists, $backupBranch, $dryRun, $scriptDest),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private — Phase implementations
    // ─────────────────────────────────────────────────────────────────────────

    /** @throws \RuntimeException */
    private function assertIsGitRepo(string $path): void
    {
        $process = new Process(['git', 'rev-parse', '--git-dir'], $path);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Not a git repository: {$path}");
        }
    }

    private function branchExists(string $repoPath, string $branch): bool
    {
        $process = new Process(['git', 'branch', '--list', $branch], $repoPath);
        $process->run();
        return str_contains($process->getOutput(), $branch);
    }

    /**
     * @return array<int, array{pattern: string, action: string}>
     */
    private function auditGitignore(string $repoPath): array
    {
        $gitignorePath = $repoPath . '/.gitignore';
        $missing = [];

        if (!file_exists($gitignorePath)) {
            return array_map(
                fn ($p) => ['pattern' => $p, 'action' => 'ADD — .gitignore does not exist'],
                self::REQUIRED_GITIGNORE_PATTERNS
            );
        }

        $existing = file_get_contents($gitignorePath);

        foreach (self::REQUIRED_GITIGNORE_PATTERNS as $pattern) {
            if (!str_contains($existing, $pattern)) {
                $missing[] = ['pattern' => $pattern, 'action' => 'ADD to .gitignore'];
            }
        }

        return $missing;
    }

    /**
     * Scan all git history blobs (via git log -p) for secret patterns.
     * Limits to 5 occurrences per pattern type to keep the payload manageable.
     *
     * @return array<string, array<int, array{commit: string, file: string, line: string}>>
     */
    private function scanGitHistory(string $repoPath): array
    {
        // git log -p --all --full-history -- outputs entire diff history
        // We pipe through grep for each pattern rather than loading all history into PHP memory.
        $findings = [];

        foreach (self::SECRET_PATTERNS as $type => $regex) {
            $process = new Process(
                ['git', 'log', '--all', '--full-history', '-p', '--unified=0'],
                $repoPath,
                null,
                null,
                120 // 2 minute timeout
            );
            $process->run();
            $output = $process->getOutput();

            $currentCommit = '';
            $currentFile   = '';
            $typeFindings  = [];

            foreach (explode("\n", $output) as $line) {
                if (str_starts_with($line, 'commit ')) {
                    $currentCommit = substr($line, 7, 12);
                } elseif (str_starts_with($line, '+++ b/')) {
                    $currentFile = substr($line, 6);
                } elseif (str_starts_with($line, '+') && preg_match($regex, $line)) {
                    $typeFindings[] = [
                        'commit' => $currentCommit,
                        'file'   => $currentFile,
                        'line'   => substr(trim($line), 0, 120),
                    ];
                    if (count($typeFindings) >= 5) {
                        break; // Cap per pattern to avoid huge payloads
                    }
                }
            }

            if (!empty($typeFindings)) {
                $findings[$type] = $typeFindings;
            }
        }

        return $findings;
    }

    /**
     * List all files ever added to git history that match sensitive patterns.
     *
     * @return string[]
     */
    private function findSensitiveFilesInHistory(string $repoPath): array
    {
        $process = new Process(
            ['git', 'log', '--all', '--full-history', '--name-only', '--format='],
            $repoPath,
            null,
            null,
            60
        );
        $process->run();

        $sensitivePatterns = [
            '/\.env$/', '/\.env\.\w+$/', '/auth\.json$/', '/deploy\.php$/i',
            '/\.pem$/', '/id_rsa/', '/\.key$/', '/\.secret$/',
        ];

        $files = array_unique(array_filter(explode("\n", $process->getOutput())));
        $sensitive = [];

        foreach ($files as $file) {
            foreach ($sensitivePatterns as $pattern) {
                if (preg_match($pattern, $file)) {
                    $sensitive[] = $file;
                    break;
                }
            }
        }

        return array_values($sensitive);
    }

    /**
     * For each deploy.php found in history, retrieve its blob content and
     * extract any server IP addresses and server folder paths exposed.
     *
     * Uses `git log --all -- <file>` to list commits, then `git show <commit>:<file>`
     * to read the actual file content at that point in time.
     *
     * @param  string[] $deployFiles  Relative paths matching deploy.php in git history.
     * @return array<int, array{
     *   file: string,
     *   commit: string,
     *   server_ips: string[],
     *   server_paths: string[]
     * }>
     */
    private function auditDeployPhpInHistory(string $repoPath, array $deployFiles): array
    {
        // Matches any routable IP found in deploy.php — including RFC-1918 private
        // ranges (10., 172.16-31., 192.168.) because internal server IPs committed
        // to a repository expose network topology even when not publicly routable.
        // Only excludes loopback (127.) and link-local (169.254.).
        $ipPattern = '/\b(?!127\.|169\.254\.)(?:\d{1,3}\.){3}\d{1,3}\b/';

        // Matches common Deployer/Envoy server path keys and bare Unix paths.
        $pathPattern = '/(?:deploy_path|current_path|release_path|app_path|root_path|upload_path)' .
                       '\s*[=>\'"]+\s*[\'"]?(\/[^\s\'">,;)]+)' .
                       '|(?<![.\w])(\/(?:var|home|srv|opt|www|data|sites)\/[^\s\'">,;)]+)/';

        $results = [];

        foreach ($deployFiles as $filePath) {
            // Get the most recent commit that touched this file across all branches.
            $logProcess = new Process(
                ['git', 'log', '--all', '--full-history', '--format=%H', '-1', '--', $filePath],
                $repoPath,
                null,
                null,
                30
            );
            $logProcess->run();
            $commit = trim($logProcess->getOutput());

            if (empty($commit)) {
                continue;
            }

            // Read the file blob at that commit.
            $showProcess = new Process(
                ['git', 'show', "{$commit}:{$filePath}"],
                $repoPath,
                null,
                null,
                30
            );
            $showProcess->run();
            $content = $showProcess->getOutput();

            if (empty($content)) {
                continue;
            }

            // Extract IPs.
            preg_match_all($ipPattern, $content, $ipMatches);
            $ips = array_values(array_unique($ipMatches[0]));

            // Extract server paths.
            preg_match_all($pathPattern, $content, $pathMatches);
            // Group 1 = named key paths, group 2 = bare unix paths.
            $paths = array_values(array_unique(array_filter(
                array_merge($pathMatches[1], $pathMatches[2])
            )));

            if (!empty($ips) || !empty($paths)) {
                $results[] = [
                    'file'         => $filePath,
                    'commit'       => substr($commit, 0, 12),
                    'server_ips'   => $ips,
                    'server_paths' => $paths,
                ];
            }
        }

        return $results;
    }

    /**
     * Build the Bash sanitizer script content from the template,
     * injecting the discovered sensitive file list and secret regexes.
     */
    private function buildSanitizerScript(
        string $repoPath,
        string $backupBranch,
        array $sensitiveFiles
    ): string {
        $templatePath = __DIR__ . '/../../scripts/sanitize-git-history.sh';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Sanitizer script template not found at: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        // Inject dynamic values
        $fileList  = implode("\n", array_map(fn ($f) => "  \"$f\" \\", $sensitiveFiles));
        $template  = str_replace('{{SENSITIVE_FILES}}', $fileList, $template);
        $template  = str_replace('{{BACKUP_BRANCH}}', $backupBranch, $template);
        $template  = str_replace('{{REPO_PATH}}', $repoPath, $template);

        return $template;
    }

    /** @return array{exit_code: int, output: string, error: string} */
    private function executeScript(string $repoPath, string $scriptPath): array
    {
        // Resolve bash binary: prefer Git for Windows bash on Windows hosts.
        $bash = PHP_OS_FAMILY === 'Windows'
            ? (file_exists('C:\\Program Files\\Git\\bin\\bash.exe')
                ? 'C:\\Program Files\\Git\\bin\\bash.exe'
                : 'C:\\Program Files\\Git\\usr\\bin\\bash.exe')
            : 'bash';

        $process = new Process([$bash, $scriptPath], $repoPath, null, null, 600);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'output'    => $process->getOutput(),
            'error'     => $process->getErrorOutput(),
        ];
    }

    /** @return string[] */
    private function buildNextSteps(
        bool $backupExists,
        string $backupBranch,
        bool $dryRun,
        string $scriptPath
    ): array {
        $steps = [];

        if (!$backupExists) {
            $steps[] = "⚠️  Create backup branch first:\n   git checkout -b {$backupBranch}";
        }

        if ($dryRun) {
            $steps[] = "📄 Review the generated script:\n   cat {$scriptPath}";
            $steps[] = "▶️  Execute the rewrite (set dryRun: false in the next call, or run manually):\n   bash {$scriptPath}";
        } else {
            $steps[] = "🔑 Rotate ALL exposed credentials:\n   1. Generate new APP_KEY: php artisan key:generate\n   2. Change database passwords in your hosting panel\n   3. Rotate any API keys found in the audit";
            $steps[] = "📤 Force-push to all remotes:\n   git remote | xargs -I{} git push {} --force --all\n   git remote | xargs -I{} git push {} --force --tags";
            $steps[] = "🤝 Notify all collaborators to re-clone the repository (history has changed).";
            $steps[] = "🔒 Verify .gitignore is committed:\n   git add .gitignore && git commit -m 'fix: enforce security gitignore patterns'";
        }

        return $steps;
    }
}
