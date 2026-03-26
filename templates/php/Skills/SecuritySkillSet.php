<?php

declare(strict_types=1);

namespace App\Skills;

use App\Contracts\SkillInterface;
use Illuminate\Support\ServiceContainer;

/**
 * Security Skill Set — the callable skill façade.
 *
 * Acts as the single routing layer between the AntigravityAgent and the
 * concrete skill implementations. Resolves skills by name and ensures
 * each is properly bound through the Laravel service container.
 *
 * To add a new skill:
 *   1. Implement SkillInterface in a new class.
 *   2. Register it in the $skills map below.
 *   3. Add it to manifest.json.
 */
final class SecuritySkillSet
{
    /**
     * @var array<string, class-string<SkillInterface>>
     */
    private array $skills = [
        'vulnerabilityScan'    => VulnerabilityScanSkill::class,
        'analyzeAuthFlow'      => AuthFlowSkill::class,
        'applySecurityPatch'   => PatchApplySkill::class,
        'sanitizeGitHistory'   => GitHistorySanitizationSkill::class,
    ];

    public function __construct(
        private readonly \Illuminate\Contracts\Container\Container $container,
    ) {}

    /**
     * Resolve a skill instance by name.
     *
     * @throws \InvalidArgumentException  If the skill name is not registered.
     */
    public function resolve(string $name): SkillInterface
    {
        if (!array_key_exists($name, $this->skills)) {
            throw new \InvalidArgumentException(
                "Unknown skill '{$name}'. Available: " . implode(', ', array_keys($this->skills))
            );
        }

        /** @var SkillInterface */
        return $this->container->make($this->skills[$name]);
    }

    /**
     * Return all registered skills as SkillInterface instances.
     * Used to generate the Copilot manifest dynamically.
     *
     * @return array<string, SkillInterface>
     */
    public function all(): array
    {
        return array_map(
            fn (string $class): SkillInterface => $this->container->make($class),
            $this->skills,
        );
    }

    /**
     * Check whether a skill name is registered.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->skills);
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->skills);
    }
}
