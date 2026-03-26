<?php

declare(strict_types=1);

namespace App\Agents\Security;

use Illuminate\Support\Facades\Cache;

/**
 * Agent Memory Store.
 *
 * Lightweight, session-scoped key-value store that persists findings
 * across multiple invocations within the same agent session.
 *
 * Default implementation uses Laravel's Cache facade (file/redis/database —
 * whatever is configured in CACHE_DRIVER). Swap the store()/recall() bodies
 * to use any other Laravel-compatible backend without changing the interface.
 *
 * Lifetime is configurable via config('security-agent.memory_ttl') — defaults
 * to 3600 seconds (one hour), matching a typical Copilot session window.
 */
final class AgentMemory
{
    private const KEY_PREFIX = 'antigravity:agent:memory:';

    public function __construct(
        private readonly string $sessionId,
        private readonly int    $ttl = 3600,
    ) {}

    /**
     * Persist a value under the given key for this session.
     *
     * @param  string $key    Namespaced key (e.g., 'findings', 'last_scan_path').
     * @param  mixed  $value  Any serializable value.
     */
    public function store(string $key, mixed $value): void
    {
        Cache::put($this->cacheKey($key), $value, $this->ttl);
    }

    /**
     * Retrieve a value stored under the given key, or return $default.
     *
     * @param  string $key     Namespaced key.
     * @param  mixed  $default Returned if the key is absent or expired.
     */
    public function recall(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->cacheKey($key), $default);
    }

    /**
     * Check whether a key is present in memory.
     */
    public function has(string $key): bool
    {
        return Cache::has($this->cacheKey($key));
    }

    /**
     * Remove a specific key from memory.
     */
    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    /**
     * Flush all memory entries for this session.
     * Called automatically by SecurityAgent::reflect() on terminal state.
     */
    public function flush(): void
    {
        // Laravel Cache does not support prefix-based flush natively;
        // we track keys stored during this session and delete them individually.
        $index = Cache::get($this->cacheKey('__index__'), []);
        foreach ($index as $key) {
            Cache::forget($this->cacheKey($key));
        }
        Cache::forget($this->cacheKey('__index__'));
    }

    /**
     * Load a full AgentState snapshot previously persisted by reflect().
     */
    public function loadState(): ?array
    {
        return $this->recall('state_snapshot');
    }

    /**
     * Persist the current AgentState for cross-invocation continuity.
     *
     * @param  array<string, mixed> $stateArray  Result of AgentState::toArray().
     */
    public function saveState(array $stateArray): void
    {
        $this->store('state_snapshot', $stateArray);
        $this->trackKey('state_snapshot');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function cacheKey(string $key): string
    {
        return self::KEY_PREFIX . $this->sessionId . ':' . $key;
    }

    private function trackKey(string $key): void
    {
        $index = Cache::get($this->cacheKey('__index__'), []);
        if (!in_array($key, $index, true)) {
            $index[] = $key;
            Cache::put($this->cacheKey('__index__'), $index, $this->ttl);
        }
    }
}
