<?php

declare(strict_types=1);

namespace App\Agents\Security;

use App\Contracts\AntigravityAgentInterface;
use App\Contracts\SkillInterface;
use App\Skills\SecuritySkillSet;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Capi Guard — Antigravity Security Agent.
 *
 * Implements the Plan-and-Execute lifecycle defined by AntigravityAgentInterface.
 * This agent orchestrates the three security skills (vulnerabilityScan,
 * analyzeAuthFlow, applySecurityPatch) in response to Copilot skill invocations.
 *
 * Lifecycle:
 *   1. bootstrap() — validates Sanctum context and loads prior session memory.
 *   2. plan()      — decomposes the goal into an ordered list of skill steps.
 *   3. execute()   — dispatches each step, accumulating findings into AgentState.
 *   4. reflect()   — persists the delta to AgentMemory, fires post-run commands.
 *
 * High-Concurrency Note:
 *   Each HTTP request instantiates a separate agent instance. State is isolated
 *   per AgentState value object — no shared mutable state at the class level.
 *   AgentMemory uses Laravel Cache (Redis recommended for multi-server setups).
 */
final class SecurityAgent implements AntigravityAgentInterface
{
    private AgentMemory $memory;

    /** Artisan commands to run after a patch is applied. */
    private array $postPatchCommands;

    public function __construct(
        private readonly SecuritySkillSet $skillSet,
    ) {
        $this->postPatchCommands = config('security-agent.artisan_after_patch', [
            'optimize:clear',
            'config:clear',
        ]);
    }

    // -----------------------------------------------------------------------
    // Phase 1 — Bootstrap
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Verifies that memory is accessible and loads any prior state for the
     * session, enabling continuity across chained Copilot skill calls.
     */
    public function bootstrap(AgentMemory $memory): void
    {
        $this->memory = $memory;

        Log::channel('security-agent')->info('Agent bootstrapped', [
            'session_id' => $memory->loadState()['session_id'] ?? 'new-session',
        ]);
    }

    // -----------------------------------------------------------------------
    // Phase 2 — Plan
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Decomposes a structured or natural-language goal into ordered skill steps.
     *
     * Goal format examples:
     *   "scan:app/Http/Controllers"
     *   "auth:UserController"
     *   "patch:CVE-2024-1234:app/Http/Controllers/UserController.php"
     *   "full-audit:app/"  → runs all three skills in sequence
     *
     * @return array<int, array{skill: string, params: array<string, mixed>}>
     */
    public function plan(string $goal, AgentState $state): array
    {
        $steps = [];

        // Full-audit shorthand: run all skills across the given path.
        if (str_starts_with($goal, 'full-audit:')) {
            $path = substr($goal, 11);
            $steps[] = ['skill' => 'vulnerabilityScan', 'params' => ['path' => $path]];
            $steps[] = ['skill' => 'analyzeAuthFlow',   'params' => ['controller' => $path]];
            return $steps;
        }

        // Selective goal — parse "verb:arg1:arg2" notation.
        $parts = explode(':', $goal, 3);

        $steps[] = match ($parts[0]) {
            'scan'  => ['skill' => 'vulnerabilityScan', 'params' => ['path' => $parts[1] ?? 'app/']],
            'auth'  => ['skill' => 'analyzeAuthFlow',   'params' => ['controller' => $parts[1] ?? '']],
            'patch' => ['skill' => 'applySecurityPatch', 'params' => [
                            'cveId'    => $parts[1] ?? '',
                            'filePath' => $parts[2] ?? '',
                        ]],
            default => throw new \InvalidArgumentException("Unknown goal verb: {$parts[0]}"),
        };

        return $steps;
    }

    // -----------------------------------------------------------------------
    // Phase 3 — Execute
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Dispatches each planned step to the matching skill via SecuritySkillSet.
     * Produces a new immutable AgentState containing accumulated findings.
     *
     * Each skill invocation is wrapped in a try/catch — a failing skill is
     * recorded as an error finding without aborting the remaining steps.
     */
    public function execute(array $steps, AgentState $state): AgentState
    {
        $current = $state->with(steps: $steps);

        foreach ($steps as $step) {
            $skillName = $step['skill'];
            $params    = $step['params'];

            try {
                $skill  = $this->skillSet->resolve($skillName);
                $result = $skill->invoke($params, $current);
            } catch (\Throwable $e) {
                $result = [
                    'error'   => $e->getMessage(),
                    'summary' => "skill:{$skillName} failed",
                ];

                Log::channel('security-agent')->error('Skill execution failed', [
                    'skill'      => $skillName,
                    'params'     => $params,
                    'error'      => $e->getMessage(),
                    'session_id' => $state->sessionId,
                ]);
            }

            $current = $current->withFinding($skillName, $result);
        }

        return $current;
    }

    // -----------------------------------------------------------------------
    // Phase 4 — Reflect
    // -----------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Persists the post-execution state delta to AgentMemory and fires any
     * configured Artisan commands (e.g., after applySecurityPatch).
     */
    public function reflect(AgentState $before, AgentState $after): void
    {
        try {
            $this->memory->saveState($after->toArray());

            // Determine if a patch was applied this cycle.
            $patchApplied = isset($after->findings['applySecurityPatch'])
                && !isset($after->findings['applySecurityPatch']['error']);

            if ($patchApplied) {
                $this->runPostPatchCommands();
            }

            Log::channel('security-agent')->info('Agent reflect complete', [
                'session_id'   => $after->sessionId,
                'before_hash'  => $before->hash(),
                'after_hash'   => $after->hash(),
                'patch_applied'=> $patchApplied,
            ]);
        } catch (\Throwable $e) {
            // reflect() must never throw — swallow and log.
            Log::channel('security-agent')->error('Reflect phase failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Public helper: run the full Plan-and-Execute cycle in one call.
    // -----------------------------------------------------------------------

    /**
     * Convenience method that runs the full lifecycle for a given goal.
     *
     * Usage from AgentController:
     *   $after = $agent->run($goal, $initialState, $memory);
     */
    public function run(string $goal, AgentState $initialState, AgentMemory $memory): AgentState
    {
        $this->bootstrap($memory);
        $steps  = $this->plan($goal, $initialState);
        $after  = $this->execute($steps, $initialState);
        $this->reflect($initialState, $after);

        return $after;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function runPostPatchCommands(): void
    {
        $binary = base_path('artisan');

        foreach ($this->postPatchCommands as $command) {
            $process = new Process(['php', $binary, ...(explode(' ', $command))]);
            $process->setTimeout(60);
            $process->run();

            Log::channel('security-agent')->info("Post-patch artisan: php artisan {$command}", [
                'exit_code' => $process->getExitCode(),
                'output'    => $process->getOutput(),
            ]);
        }
    }
}
