<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Support;

use app\middleware\RateLimit;
use think\Request;

/**
 * RateLimit 受测方法探针（仅测试用）
 *
 * 为什么需要它：`resolveClientIp()` / `ipMatchesCidr()` / `resolveIdentifier()` 都是 protected，
 * 而它们是 ER-09 的核心判定逻辑。用子类把 protected 暴露成 public，可以：
 *   1. 断言**真实实现**，而不是在测试里复制一份算法（复制出来的算法永远自证清白）；
 *   2. 不必在每个用例里写 ReflectionMethod 样板，也不会因为方法改名悄悄跳过断言。
 *
 * 刻意不重写任何被测逻辑：本类只做可见性放宽。
 */
final class RateLimitProbe extends RateLimit
{
    /**
     * 暴露 resolveClientIp()
     */
    public function clientIpForTest(Request $request): string
    {
        return $this->resolveClientIp($request);
    }

    /**
     * 暴露 ipMatchesCidr()
     */
    public function cidrForTest(string $ip, string $cidr): bool
    {
        return $this->ipMatchesCidr($ip, $cidr);
    }

    /**
     * 暴露 resolveIdentifier()
     */
    public function identifierForTest(string $scope, Request $request): ?string
    {
        return $this->resolveIdentifier($scope, $request);
    }
}
