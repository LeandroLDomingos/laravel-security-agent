<?php

declare(strict_types=1);

namespace App\Skills;

use App\Agents\Security\AgentState;
use App\Contracts\SkillInterface;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;

/**
 * Auth Flow Analysis Skill.
 *
 * Resolves a controller (by class name or file path) and inspects every
 * public method using PHP Reflection to detect missing authorization calls:
 *   - $this->authorize()
 *   - Gate::authorize()
 *   - $this->authorizeForUser()
 *
 * This skill is read-only — it never modifies files.
 */
final class AuthFlowSkill implements SkillInterface
{
    public function name(): string
    {
        return 'analyzeAuthFlow';
    }

    public function description(): string
    {
        return 'Inspects a Laravel controller\'s public methods for missing '
            . '$this->authorize() / Gate::authorize() calls. '
            . 'Returns a per-method authorization gap report.';
    }

    /** @return array<string, mixed> */
    public function parametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'controller' => [
                    'type'        => 'string',
                    'description' => 'Controller class name (e.g., "UserController") or relative path '
                        . '(e.g., "app/Http/Controllers/UserController.php").',
                ],
            ],
            'required' => ['controller'],
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @return array{
     *   summary: string,
     *   controller: string,
     *   methods: array<int, array{method: string, has_authorization: bool, gaps: string[]}>
     * }
     */
    public function invoke(array $params, AgentState $state): array
    {
        $input = $params['controller'] ?? throw new \InvalidArgumentException('Parameter "controller" is required.');

        [$className, $filePath] = $this->resolveController($input);

        if (!class_exists($className)) {
            throw new \RuntimeException("Controller class '{$className}' not found. Ensure it is autoloaded.");
        }

        $reflection  = new ReflectionClass($className);
        $publicMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => !$m->isConstructor() && $m->getDeclaringClass()->getName() === $className,
        );

        $source   = File::exists($filePath) ? (file($filePath, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $results  = [];
        $gapCount = 0;

        /** @var ReflectionMethod $method */
        foreach ($publicMethods as $method) {
            $startLine    = ($method->getStartLine() ?: 1) - 1;
            $endLine      = ($method->getEndLine()   ?: 1) - 1;
            $methodSource = implode("\n", array_slice($source, $startLine, $endLine - $startLine + 1));

            $hasAuth = $this->detectAuthorizationCall($methodSource);
            $gaps    = $this->identifyGaps($method->getName(), $methodSource, $hasAuth);

            if (!empty($gaps)) {
                $gapCount++;
            }

            $results[] = [
                'method'            => $method->getName(),
                'has_authorization' => $hasAuth,
                'gaps'              => $gaps,
            ];
        }

        return [
            'summary'    => "{$gapCount} of " . count($results) . ' public methods have authorization gaps',
            'controller' => $className,
            'methods'    => $results,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve a controller name/path input to [FQCN, absoluteFilePath].
     *
     * @return array{0: string, 1: string}
     */
    private function resolveController(string $input): array
    {
        // Input is a file path (ends in .php or contains /)
        if (str_ends_with($input, '.php') || str_contains($input, '/')) {
            $absPath   = base_path(ltrim($input, '/'));
            $className = $this->classNameFromPath($absPath);
            return [$className, $absPath];
        }

        // Input is a bare class name — search in app/Http/Controllers/
        $candidates = [
            "App\\Http\\Controllers\\{$input}",
            "App\\Http\\Controllers\\Api\\{$input}",
            "App\\Http\\Controllers\\Admin\\{$input}",
        ];

        foreach ($candidates as $fqcn) {
            $path = base_path(str_replace(['App\\', '\\'], ['app/', '/'], $fqcn) . '.php');
            if (File::exists($path)) {
                return [$fqcn, $path];
            }
        }

        // Fallback: try the first candidate and let it fail gracefully.
        $fqcn = $candidates[0];
        $path = base_path(str_replace(['App\\', '\\'], ['app/', '/'], $fqcn) . '.php');
        return [$fqcn, $path];
    }

    private function classNameFromPath(string $absPath): string
    {
        // Convert absolute path to PSR-4 class name using Laravel's base path.
        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $absPath);
        $relative = str_replace(['/', '\\'], '\\', $relative);
        $relative = preg_replace('/\.php$/', '', $relative) ?? $relative;

        // Map "app\" → "App\"
        return ucfirst($relative);
    }

    private function detectAuthorizationCall(string $methodSource): bool
    {
        return (bool) preg_match(
            '/(\$this->authorize\s*\(|Gate::authorize\s*\(|\$this->authorizeForUser\s*\(|->can\s*\(|->cannot\s*\()/i',
            $methodSource
        );
    }

    /**
     * @return string[]
     */
    private function identifyGaps(string $methodName, string $source, bool $hasAuth): array
    {
        $gaps = [];
        $destructiveMethods = ['store', 'update', 'destroy', 'delete', 'edit', 'create'];

        if (!$hasAuth && in_array(strtolower($methodName), $destructiveMethods, true)) {
            $gaps[] = "Method '{$methodName}' performs a write/delete operation but has no authorization check.";
        }

        if (!$hasAuth && preg_match('/\$this->authorize|\bauthorize\b/i', $source) === 0) {
            if (preg_match('/\$request->user\(\)|auth\(\)->user\(\)/i', $source)) {
                $gaps[] = "Method '{$methodName}' uses the authenticated user but does not call authorize() or a Policy.";
            }
        }

        return $gaps;
    }
}
