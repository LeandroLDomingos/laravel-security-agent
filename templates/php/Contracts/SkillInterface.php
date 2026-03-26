<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Agents\Security\AgentState;

/**
 * Skill Contract — a single callable capability in the Antigravity skill-set.
 *
 * Each skill is a self-contained unit of work that:
 *   1. Declares its identity (name, description) for the Copilot manifest.
 *   2. Publishes a JSON-Schema fragment describing its accepted parameters.
 *   3. Implements the actual side-effect-free (or safely side-effecting) logic.
 *
 * Skills are stateless. All execution context flows through AgentState.
 */
interface SkillInterface
{
    /**
     * Machine-readable name used in the Copilot manifest and the plan steps.
     * Must match the "name" field in manifest.json exactly.
     *
     * Example: "vulnerabilityScan"
     */
    public function name(): string;

    /**
     * Human-readable description surfaced to the Copilot user in the UI.
     *
     * Example: "Scans a file path for Laravel security vulnerabilities."
     */
    public function description(): string;

    /**
     * JSON-Schema fragment describing the parameters this skill accepts.
     *
     * Must return a valid "parameters" object compatible with the
     * OpenAI function-calling / Copilot tool schema.
     *
     * Example:
     * [
     *   'type'       => 'object',
     *   'properties' => ['path' => ['type' => 'string', 'description' => '...']],
     *   'required'   => ['path'],
     * ]
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Execute the skill with the given parameters.
     *
     * @param  array<string, mixed> $params  Validated parameters from the Copilot invocation.
     * @param  AgentState           $state   Current immutable agent execution context.
     * @return array<string, mixed>          Result payload — merged into AgentState findings.
     *
     * @throws \InvalidArgumentException  If a required parameter is missing or invalid.
     * @throws \RuntimeException          If the skill encounters an unrecoverable error.
     */
    public function invoke(array $params, AgentState $state): array;
}
