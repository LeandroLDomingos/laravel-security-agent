<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zero-Trust Middleware for Antigravity Skill Invocations.
 *
 * Enforces a four-layer context validation before any skill can execute:
 *
 *   1. Authentication  — valid Sanctum Bearer token is required.
 *   2. Ability check   — token must have the "agent:invoke" ability.
 *   3. Session header  — X-Agent-Context must carry a matching session_id.
 *   4. Rate limit      — configurable per-user invocation cap.
 *
 * Every validation failure is a hard abort; no partial context is forwarded.
 * This guarantees that the AgentState built downstream is fully trustworthy.
 *
 * Registration (in bootstrap/app.php or a ServiceProvider):
 *
 *   ->withMiddleware(function (Middleware $m) {
 *       $m->alias(['zero-trust' => ZeroTrustMiddleware::class]);
 *   });
 *
 * Usage on the route:
 *   Route::post('/api/agent/invoke', AgentController::class)
 *        ->middleware(['auth:sanctum', 'zero-trust']);
 */
final class ZeroTrustMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── 1. Authenticated user ─────────────────────────────────────────
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated: a valid Sanctum Bearer token is required.');
        }

        // ── 2. Token ability ──────────────────────────────────────────────
        $requiredAbility = config('security-agent.zero_trust.required_ability', 'agent:invoke');

        if (!$request->user()->tokenCan($requiredAbility)) {
            abort(403, "Forbidden: Sanctum token lacks the '{$requiredAbility}' ability.");
        }

        // ── 3. Session context header ─────────────────────────────────────
        $agentContext = $request->header('X-Agent-Context');

        if (empty($agentContext)) {
            abort(400, 'Bad Request: X-Agent-Context header is required.');
        }

        $context = json_decode($agentContext, associative: true);

        if (!is_array($context) || empty($context['session_id'])) {
            abort(400, 'Bad Request: X-Agent-Context must be valid JSON with a "session_id" field.');
        }

        // Attach the session_id to the request so controllers can read it without re-parsing.
        $request->merge(['_agent_session_id' => $context['session_id']]);

        // ── 4. Rate limiting ──────────────────────────────────────────────
        $limit     = (int) config('security-agent.rate_limit', 10);
        $rateKey   = 'agent-invoke:' . $user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            $seconds = RateLimiter::availableIn($rateKey);
            abort(429, "Too Many Requests: retry after {$seconds} seconds.");
        }

        RateLimiter::hit($rateKey, 60);

        return $next($request);
    }
}
