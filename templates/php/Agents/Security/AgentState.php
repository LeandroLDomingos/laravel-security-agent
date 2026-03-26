<?php

declare(strict_types=1);

namespace App\Agents\Security;

/**
 * Immutable Agent State DTO.
 *
 * Represents a complete snapshot of the Antigravity agent's execution context
 * at any point in the plan → execute cycle.
 *
 * Because this is a readonly class (PHP 8.2+), cloning with modified fields
 * uses the `with()` helper, which produces a new instance without mutating the original.
 * This enforces the immutability required by the Antigravity Plan-and-Execute pattern.
 *
 * Lifecycle tracking:
 *   - Each execute() phase produces a NEW AgentState via with().
 *   - reflect() compares before/after to compute deltas for the audit log.
 */
final readonly class AgentState
{
    /**
     * @param  string                          $userId      Authenticated user ID (from Sanctum token).
     * @param  string                          $sessionId   Unique invocation session ID (UUID v4).
     * @param  string                          $goal        High-level goal description for this run.
     * @param  array<int, array<string,mixed>> $steps       Planned steps (output of plan() phase).
     * @param  array<string, mixed>            $findings    Accumulated findings from executed skills.
     * @param  array<int, array<string,mixed>> $auditLog    Chronological log of all skill invocations.
     * @param  \DateTimeImmutable              $createdAt   When this state snapshot was created.
     */
    public function __construct(
        public readonly string             $userId,
        public readonly string             $sessionId,
        public readonly string             $goal,
        public readonly array              $steps    = [],
        public readonly array              $findings = [],
        public readonly array              $auditLog = [],
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    /**
     * Produce a new AgentState with the given fields overridden.
     *
     * This is the only way to "mutate" state — it always returns a fresh instance.
     *
     * Usage:
     *   $next = $state->with(findings: [...$state->findings, 'newKey' => $result]);
     *
     * @param  array<string, mixed> $overrides  Fields to override (named-argument style).
     */
    public function with(
        ?string             $userId    = null,
        ?string             $sessionId = null,
        ?string             $goal      = null,
        ?array              $steps     = null,
        ?array              $findings  = null,
        ?array              $auditLog  = null,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            userId:    $userId    ?? $this->userId,
            sessionId: $sessionId ?? $this->sessionId,
            goal:      $goal      ?? $this->goal,
            steps:     $steps     ?? $this->steps,
            findings:  $findings  ?? $this->findings,
            auditLog:  $auditLog  ?? $this->auditLog,
            createdAt: $createdAt ?? $this->createdAt,
        );
    }

    /**
     * Append a new finding to the findings map and return a new state.
     *
     * @param  string               $skill   Skill name that produced the finding.
     * @param  array<string, mixed> $result  Finding payload from SkillInterface::invoke().
     */
    public function withFinding(string $skill, array $result): self
    {
        return $this->with(
            findings: array_merge($this->findings, [$skill => $result]),
            auditLog: array_merge($this->auditLog, [[
                'skill'     => $skill,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'summary'   => $result['summary'] ?? 'completed',
            ]]),
        );
    }

    /**
     * Compute a stable hash of the current state for integrity verification.
     *
     * Used by AntigravityAgent::reflect() to detect tampering between phases.
     */
    public function hash(): string
    {
        return hash('sha256', serialize([
            $this->userId,
            $this->sessionId,
            $this->goal,
            $this->findings,
            $this->auditLog,
        ]));
    }

    /**
     * Serialize to array for JSON responses and AgentMemory storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId,
            'session_id' => $this->sessionId,
            'goal'       => $this->goal,
            'steps'      => $this->steps,
            'findings'   => $this->findings,
            'audit_log'  => $this->auditLog,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'hash'       => $this->hash(),
        ];
    }
}
