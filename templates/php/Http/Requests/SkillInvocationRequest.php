<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Skill Invocation Request — strictly-typed validation for agent skill calls.
 *
 * Validates the JSON payload sent to POST /api/agent/invoke.
 * Uses Laravel FormRequest rules so validation errors are automatically
 * returned as a 422 JSON response — no controller boilerplate needed.
 *
 * Expected payload:
 * {
 *   "skill":  "vulnerabilityScan",
 *   "goal":   "scan:app/Http/Controllers",   // optional — auto-derived from skill+params
 *   "params": { "path": "app/Http/Controllers" }
 * }
 */
final class SkillInvocationRequest extends FormRequest
{
    /** All authenticated users may invoke skills (ability check is in ZeroTrustMiddleware). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string|\Illuminate\Validation\Rules\In>> */
    public function rules(): array
    {
        $skill = $this->input('skill');

        return [
            'skill'  => ['required', 'string', \Illuminate\Validation\Rule::in([
                'vulnerabilityScan',
                'analyzeAuthFlow',
                'applySecurityPatch',
                'sanitizeGitHistory',
            ])],
            'goal'   => ['nullable', 'string', 'max:500'],
            'params' => ['required', 'array'],

            // Per-skill parameter validation
            ...$this->skillParamRules($skill),
        ];
    }

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [
            'skill.in'       => 'Unknown skill. Valid values: vulnerabilityScan, analyzeAuthFlow, applySecurityPatch, sanitizeGitHistory.',
            'params.required' => 'The "params" object is required even if empty.',
        ];
    }

    /**
     * Convenience accessor — returns validated params cast to array.
     *
     * @return array<string, mixed>
     */
    public function skillParams(): array
    {
        return $this->validated()['params'] ?? [];
    }

    // -----------------------------------------------------------------------
    // Private — per-skill conditional rules
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array<int, string>>
     */
    private function skillParamRules(?string $skill): array
    {
        return match ($skill) {
            'vulnerabilityScan' => [
                'params.path' => ['required', 'string', 'max:255'],
            ],
            'analyzeAuthFlow' => [
                'params.controller' => ['required', 'string', 'max:255'],
            ],
            'applySecurityPatch' => [
                'params.cveId'    => ['required', 'string', 'regex:/^CVE-\d{4}-\d+$/i', 'max:30'],
                'params.filePath' => ['required', 'string', 'max:500'],
            ],
            'sanitizeGitHistory' => [
                'params.backupBranch'       => ['nullable', 'string', 'max:100'],
                'params.dryRun'             => ['nullable', 'boolean'],
                'params.repoPath'           => ['nullable', 'string', 'max:500'],
                'params.generateScriptPath' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };
    }
}
