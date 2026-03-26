<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Agents\Security\AgentMemory;
use App\Agents\Security\AgentState;

/**
 * Antigravity Agent Lifecycle Contract.
 *
 * Implements the Plan-and-Execute pattern:
 *   bootstrap → plan → execute → reflect
 *
 * Every agent in the Antigravity swarm must implement this interface.
 * Each phase is immutable with respect to external state — side effects
 * are confined to the execute() phase and logged via reflect().
 */
interface AntigravityAgentInterface
{
    /**
     * Bootstrap phase: verify context, load prior memory for this session.
     *
     * Called once before the plan/execute cycle begins.
     * Should throw \RuntimeException if the context is invalid.
     */
    public function bootstrap(AgentMemory $memory): void;

    /**
     * Plan phase: decompose a high-level goal into an ordered list of steps.
     *
     * Returns an array of step descriptors, e.g.:
     * [
     *   ['skill' => 'vulnerabilityScan', 'params' => ['path' => 'app/']],
     *   ['skill' => 'analyzeAuthFlow',   'params' => ['controller' => 'UserController']],
     * ]
     *
     * Must be pure — no side effects allowed during planning.
     *
     * @param  string     $goal   Human-readable or structured goal string.
     * @param  AgentState $state  Current immutable agent state.
     * @return array<int, array{skill: string, params: array<string, mixed>}>
     */
    public function plan(string $goal, AgentState $state): array;

    /**
     * Execute phase: dispatch each planned step to the matching skill.
     *
     * Produces a new AgentState containing findings and an updated audit log.
     * Side effects (file edits, Artisan commands) happen exclusively here.
     *
     * @param  array<int, array{skill: string, params: array<string, mixed>}> $steps
     * @param  AgentState $state  State going into this execution cycle.
     * @return AgentState         New immutable state after execution.
     */
    public function execute(array $steps, AgentState $state): AgentState;

    /**
     * Reflect phase: persist the delta between before/after states.
     *
     * Writes findings to AgentMemory, emits audit events, and optionally
     * triggers post-execution Artisan commands (e.g., cache:clear).
     *
     * Must not throw — errors are logged and suppressed.
     */
    public function reflect(AgentState $before, AgentState $after): void;
}
