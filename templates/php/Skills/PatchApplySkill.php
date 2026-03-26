<?php

declare(strict_types=1);

namespace App\Skills;

use App\Agents\Security\AgentState;
use App\Contracts\SkillInterface;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Security Patch Apply Skill.
 *
 * Looks up a CVE ID in the patch registry (config/security-agent.php),
 * applies the corresponding transformation to the target file, and runs
 * Artisan commands to validate the change.
 *
 * Side-effect policy:
 *   - Creates a timestamped backup before modifying any file.
 *   - Records a before/after diff summary in the returned findings.
 *   - Runs `php artisan optimize:clear` post-patch (overridable via config).
 *   - Never modifies files outside of base_path().
 */
final class PatchApplySkill implements SkillInterface
{
    public function name(): string
    {
        return 'applySecurityPatch';
    }

    public function description(): string
    {
        return 'Applies a known security patch for a given CVE ID to the specified file. '
            . 'Creates a backup, applies the transformation, and runs post-patch Artisan commands.';
    }

    /** @return array<string, mixed> */
    public function parametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'cveId' => [
                    'type'        => 'string',
                    'description' => 'CVE identifier for the patch to apply (e.g., "CVE-2024-1234").',
                ],
                'filePath' => [
                    'type'        => 'string',
                    'description' => 'Relative path to the file that needs patching (e.g., "app/Http/Middleware/VerifyCsrfToken.php").',
                ],
            ],
            'required' => ['cveId', 'filePath'],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @return array{
     *   summary: string,
     *   cve_id: string,
     *   file: string,
     *   backup: string,
     *   diff_summary: string,
     *   post_patch_commands: array<int, array{command: string, exit_code: int, output: string}>
     * }
     */
    public function invoke(array $params, AgentState $state): array
    {
        $cveId    = $params['cveId']    ?? throw new \InvalidArgumentException('Parameter "cveId" is required.');
        $filePath = $params['filePath'] ?? throw new \InvalidArgumentException('Parameter "filePath" is required.');

        $absPath = base_path(ltrim($filePath, '/'));
        $this->assertPathSafe($absPath);

        if (!File::exists($absPath)) {
            throw new \RuntimeException("Target file not found: {$absPath}");
        }

        $patch = $this->getPatchDefinition($cveId);

        // 1. Backup
        $backupPath = $absPath . '.bak.' . now()->format('Ymd_His');
        File::copy($absPath, $backupPath);

        // 2. Read original
        $original = File::get($absPath);

        // 3. Apply patch
        $patched = $this->applyPatch($original, $patch);

        // 4. Write patched file
        File::put($absPath, $patched);

        // 5. Diff summary
        $diffSummary = $this->buildDiffSummary($original, $patched);

        // 6. Post-patch Artisan commands
        $postPatchResults = $this->runPostPatchCommands(
            config('security-agent.artisan_after_patch', ['optimize:clear', 'config:clear'])
        );

        return [
            'summary'             => "Patch {$cveId} applied to {$filePath}. " . count($postPatchResults) . ' post-patch commands run.',
            'cve_id'              => $cveId,
            'file'                => $filePath,
            'backup'              => str_replace(base_path(), '', $backupPath),
            'diff_summary'        => $diffSummary,
            'post_patch_commands' => $postPatchResults,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Fetch a patch definition from config/security-agent.php.
     *
     * @return array{description: string, replacements: array<int, array{search: string, replace: string}>}
     */
    private function getPatchDefinition(string $cveId): array
    {
        $registry = config('security-agent.patch_registry', []);

        if (!array_key_exists($cveId, $registry)) {
            throw new \InvalidArgumentException(
                "No patch definition found for {$cveId}. "
                . 'Add it to config/security-agent.php under patch_registry.'
            );
        }

        return $registry[$cveId];
    }

    /**
     * Apply search-and-replace transformations defined by the patch.
     *
     * @param  string                                                               $source
     * @param  array{description: string, replacements: array<int, array{search: string, replace: string}>} $patch
     */
    private function applyPatch(string $source, array $patch): string
    {
        $result = $source;

        foreach ($patch['replacements'] as $replacement) {
            if (isset($replacement['regex']) && $replacement['regex'] === true) {
                $result = preg_replace($replacement['search'], $replacement['replace'], $result) ?? $result;
            } else {
                $result = str_replace($replacement['search'], $replacement['replace'], $result);
            }
        }

        return $result;
    }

    private function buildDiffSummary(string $before, string $after): string
    {
        $beforeLines = explode("\n", $before);
        $afterLines  = explode("\n", $after);

        $added   = count(array_diff($afterLines, $beforeLines));
        $removed = count(array_diff($beforeLines, $afterLines));

        return "+{$added} / -{$removed} lines changed";
    }

    /**
     * @param  string[] $commands
     * @return array<int, array{command: string, exit_code: int, output: string}>
     */
    private function runPostPatchCommands(array $commands): array
    {
        $results = [];
        $binary  = base_path('artisan');

        foreach ($commands as $command) {
            $process = new Process(['php', $binary, ...(explode(' ', $command))]);
            $process->setTimeout(120);
            $process->run();

            $results[] = [
                'command'   => "php artisan {$command}",
                'exit_code' => $process->getExitCode() ?? -1,
                'output'    => trim($process->getOutput()),
            ];
        }

        return $results;
    }

    /**
     * Prevent path traversal — the patched file must live within base_path().
     */
    private function assertPathSafe(string $absPath): void
    {
        $realBase = realpath(base_path()) ?: base_path();
        $realPath = realpath(dirname($absPath)) ?: dirname($absPath);

        if (!str_starts_with($realPath, $realBase)) {
            throw new \RuntimeException('Path traversal detected — filePath must be within the project root.');
        }
    }
}
