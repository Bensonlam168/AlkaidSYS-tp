<?php

declare(strict_types=1);

namespace app\provider;

use think\Service;

/**
 * Session environment guard service provider
 *
 * Ensures that production-like environments always use Redis-backed session storage
 * via cache store 'redis'.
 */
class SessionEnvironmentGuardService extends Service
{
    public function boot(): void
    {
        $env = strtolower((string) env('APP_ENV', 'production'));
        $prodLikeEnvs = ['production', 'prod', 'stage', 'staging'];

        if (!in_array($env, $prodLikeEnvs, true)) {
            return;
        }

        $config = config('session');

        $type = $config['type'] ?? null;
        $store = $config['store'] ?? null;

        if ($type !== 'cache' || $store !== 'redis') {
            throw new \RuntimeException(sprintf(
                "Session configuration misconfigured: in %s environment session.type must be 'cache' and session.store must be 'redis', got type='%s', store='%s'",
                $env,
                (string) $type,
                (string) $store
            ));
        }
    }
}

