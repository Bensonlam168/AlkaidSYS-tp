<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Service;

use think\facade\Config;

/**
 * 低代码表名生成器
 * 支持共享表与租户前缀表两种策略，默认共享表。
 */
class TableNameGenerator
{
    /**
     * 生成表名
     */
    public function generate(string $collectionName, int $tenantId = 0, int $siteId = 0): string
    {
        $strategy = Config::get('lowcode.table_naming_strategy', 'shared');
        $snakeCase = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $collectionName));

        if ($strategy === 'tenant_prefixed' && $tenantId > 0) {
            return sprintf('lc_t%d_%s', $tenantId, $snakeCase);
        }

        // 默认共享表：lc_{name}
        return 'lc_' . $snakeCase;
    }
}
