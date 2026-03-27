<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Agents\Security\AgentMemory;
use App\Agents\Security\AgentState;
use App\Agents\Security\SecurityAgent;
use App\Http\Requests\SkillInvocationRequest;
use App\Skills\SecuritySkillSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Agent Controller — HTTP entry-point for Copilot skill invocations.
 *
 * Route (add to routes/api.php):
 *
 *   use App\Http\Controllers\AgentController;
 *   use App\Http\Middleware\ZeroTrustMiddleware;
 *
 *   Route::post('/agent/invoke', AgentController::class)
 *        ->middleware(['auth:sanctum', ZeroTrustMiddleware::class])
 *        ->name('agent.invoke');
 *
 * Request → Response lifecycle:
 *   1. ZeroTrustMiddleware validates auth, ability, session header, rate limit.
 *   2. SkillInvocationRequest validates and normalizes the payload.
 *   3. AgentController builds an initial AgentState, wires a SecurityAgent, and runs it.
 *   4. Returns the final AgentState as JSON (200) or error (4xx/5xx).
 */
final class AgentController extends Controller
{
    public function __construct(
        private readonly SecurityAgent    $agent,
        private readonly SecuritySkillSet $skillSet,
    ) {}

    /**
     * Handle a single Copilot skill invocation.
     *
     * @__invoke makes this a single-action controller compatible with
     * Route::post('/agent/invoke', AgentController::class).
     */
    public function __invoke(SkillInvocationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $skill     = $validated['skill'];
        $params    = $request->skillParams();
        $sessionId = $request->input('_agent_session_id', (string) Str::uuid());
        $userId    = (string) $request->user()->getKey();

        // Derive goal string from skill name and params (or use explicit goal).
        $goal = $validated['goal'] ?? $this->deriveGoal($skill, $params);

        // Build the initial immutable AgentState.
        $initialState = new AgentState(
            userId:    $userId,
            sessionId: $sessionId,
            goal:      $goal,
        );

        // Bootstrap memory for this session.
        $memory = new AgentMemory(
            sessionId: $sessionId,
            ttl:       (int) config('security-agent.memory_ttl', 3600),
        );

        // Run the full Plan-and-Execute lifecycle.
        $finalState = $this->agent->run($goal, $initialState, $memory);

        return response()->json([
            'status'     => 'success',
            'skill'      => $skill,
            'session_id' => $sessionId,
            'state_hash' => $finalState->hash(),
            'findings'   => $finalState->findings,
            'audit_log'  => $finalState->auditLog,
        ], 200);
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $params
     */
    private function deriveGoal(string $skill, array $params): string
    {
        return match ($skill) {
            'vulnerabilityScan'  => 'scan:'     . ($params['path']       ?? 'app/'),
            'analyzeAuthFlow'    => 'auth:'     . ($params['controller'] ?? ''),
            'applySecurityPatch' => 'patch:'    . ($params['cveId'] ?? '') . ':' . ($params['filePath'] ?? ''),
            'sanitizeGitHistory' => 'sanitize:' . base64_encode(json_encode($params)),
            default              => $skill,
        };
    }
}
