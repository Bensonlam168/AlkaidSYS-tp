<?php

declare(strict_types=1);

return [
    // 表命名策略：shared = 共享表（lc_{name}）；tenant_prefixed = 租户前缀表（lc_t{tenant_id}_{name}）
    'table_naming_strategy' => env('LOWCODE_TABLE_NAMING_STRATEGY', 'shared'),
];
