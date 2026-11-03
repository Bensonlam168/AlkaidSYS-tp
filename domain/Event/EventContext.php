<?php

declare(strict_types=1);

namespace Domain\Event;

use think\Request;

/**
 * 事件上下文对象，承载 trace/租户/站点/用户 等信息。
 */
class EventContext
{
    public function __construct(
        protected ?string $traceId = null,
        protected ?int $tenantId = null,
        protected ?int $siteId = null,
        protected ?int $userId = null,
        protected array $extra = []
    ) {
    }

    /**
     * 从数组构建上下文 | Build context from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['trace_id'] ?? null,
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            isset($data['site_id']) ? (int) $data['site_id'] : null,
            isset($data['user_id']) ? (int) $data['user_id'] : null,
            $data['extra'] ?? []
        );
    }

    /**
     * 从全局请求解析上下文（无请求时降级为默认空上下文）。
     */
    public static function fromGlobals(): self
    {
        $traceId = null;
        $tenantId = null;
        $siteId = null;
        $userId = null;
        $extra = [];

        try {
            $request = request();
            if ($request instanceof Request) {
                $traceId = $request->header('X-Trace-Id') ?? ($request->header('X-Request-Id') ?? null);
                if (method_exists($request, 'getTraceId')) {
                    $traceId = $traceId ?: $request->getTraceId();
                }

                $tenantId = $request->header('X-Tenant-Id') ?? $request->param('tenant_id', null);
                $siteId = $request->header('X-Site-Id') ?? $request->param('site_id', null);
                $userId = $request->header('X-User-Id') ?? $request->param('user_id', null);
            }
        } catch (\Throwable) {
            // CLI / 测试环境下可能没有 request，忽略异常。
        }

        return new self(
            $traceId !== null ? (string) $traceId : null,
            $tenantId !== null ? (int) $tenantId : null,
            $siteId !== null ? (int) $siteId : null,
            $userId !== null ? (int) $userId : null,
            $extra
        );
    }

    /**
     * 导出为数组，便于序列化/入队。
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'tenant_id' => $this->tenantId,
            'site_id' => $this->siteId,
            'user_id' => $this->userId,
            'extra' => $this->extra,
        ];
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getSiteId(): ?int
    {
        return $this->siteId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }
}
