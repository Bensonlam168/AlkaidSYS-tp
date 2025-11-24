<?php

declare(strict_types=1);

namespace app\provider;

use think\Service;

/**
 * Cache environment guard service provider | 
 *
 * Ensures that production-like environments always use Redis cache driver.
 */
class CacheEnvironmentGuardService extends Service
{
    public function boot(): void
    {
        $env = strtolower((string) env('APP_ENV', 'production'));
        $prodLikeEnvs = ['production', 'prod', 'stage', 'staging'];

        if (!in_array($env, $prodLikeEnvs, true)) {
            return;
        }

        $defaultStore = config('cache.default');
        if ($defaultStore !== 'redis') {
            throw new \RuntimeException(sprintf(
                "Cache driver misconfigured: in %s environment cache.default must be 'redis', got '%s'",
                $env,
                (string) $defaultStore
            ));
        }
    }
}

