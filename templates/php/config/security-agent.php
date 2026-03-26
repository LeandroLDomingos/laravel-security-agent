<?php

declare(strict_types=1);

/**
 * Capi Guard — Security Agent Configuration
 *
 * Publish to your Laravel project:
 *   php artisan vendor:publish --tag=security-agent-config
 *
 * Or copy this file manually to config/security-agent.php.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Agent Memory TTL
    |--------------------------------------------------------------------------
    | How long (in seconds) the agent persists session state in the Laravel
    | cache. Defaults to one hour — matching a typical Copilot session window.
    |
    */
    'memory_ttl' => (int) env('AGENT_MEMORY_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | Maximum number of skill invocations per user per 60-second window.
    | Enforced by ZeroTrustMiddleware using Laravel's RateLimiter facade.
    |
    */
    'rate_limit' => (int) env('AGENT_RATE_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | Zero-Trust Settings
    |--------------------------------------------------------------------------
    | The Sanctum token ability that must be present on every skill call.
    | Issue tokens with: $user->createToken('copilot', ['agent:invoke'])
    |
    */
    'zero_trust' => [
        'required_ability' => env('AGENT_REQUIRED_ABILITY', 'agent:invoke'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan Commands After Patch
    |--------------------------------------------------------------------------
    | Run these Artisan commands automatically after applySecurityPatch
    | successfully modifies a file. Add 'test --filter SecurityTest' to run
    | the test suite post-patch (slower but recommended for CI environments).
    |
    */
    'artisan_after_patch' => [
        'optimize:clear',
        'config:clear',
        // 'test --filter SecurityTest',  // uncomment to run tests post-patch
    ],

    /*
    |--------------------------------------------------------------------------
    | Patch Registry
    |--------------------------------------------------------------------------
    | CVE-ID → patch definition mapping consumed by PatchApplySkill.
    |
    | Each entry has:
    |   description  : human-readable explanation of the fix
    |   replacements : array of { search, replace, regex? } operations
    |                  applied in order with str_replace (or preg_replace if regex: true)
    |
    | Add entries for your project-specific CVEs or known Laravel CVEs here.
    |
    */
    'patch_registry' => [

        // Example: force APP_DEBUG off in a production .env
        'CVE-EXAMPLE-001' => [
            'description'  => 'Ensure APP_DEBUG is set to false in production.',
            'replacements' => [
                [
                    'search'  => 'APP_DEBUG=true',
                    'replace' => 'APP_DEBUG=false',
                    'regex'   => false,
                ],
            ],
        ],

        // Example: replace $guarded=[] with explicit $fillable
        'CVE-EXAMPLE-002' => [
            'description'  => 'Replace $guarded = [] with $fillable to prevent mass assignment.',
            'replacements' => [
                [
                    'search'  => '/protected\s+\$guarded\s*=\s*\[\s*\];/',
                    'replace' => "protected \$fillable = []; // TODO: list explicitly fillable fields",
                    'regex'   => true,
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Channel
    |--------------------------------------------------------------------------
    | The Laravel logging channel used by SecurityAgent and its skills.
    | Add a 'security-agent' channel in config/logging.php, or set this to
    | 'stack' / 'daily' to use the default application log.
    |
    */
    'log_channel' => env('AGENT_LOG_CHANNEL', 'stack'),

];
