<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Mock;

use Alkaid\Workflow\Engine\interface\services\ITaskActorDirectory;

/**
 * 内存版人员目录（无数据库的解析规则测试用）
 *
 * 为什么需要它：插件自身的 PHPUnit 配置不连库（见 `plugins/workflow-engine/phpunit.xml` 的注释），
 * 而参与者解析的核心价值在「配置 → 用户 id」这套规则上，必须能脱离真库逐条断言。
 * 于是把「怎么查人」抽成 {@see ITaskActorDirectory}，测试注入本实现，
 * 生产用 `DefaultTaskActorDirectory`（读 users / roles / user_roles / depts）。
 *
 * 键的形态统一是 `"{租户ID}:{业务键}"`，因此**跨租户**场景可以直接构造：
 * 同一个角色 slug 在租户 2 有、租户 1 没有；同一个用户 id 只属于某个租户。
 *
 * @package Alkaid\Workflow\Engine\Tests\Mock
 */
class InMemoryTaskActorDirectory implements ITaskActorDirectory
{
    /**
     * @param array<string, array{id:int,slug:string,name?:string}> $roles       "tenant:slug" => 角色行
     * @param array<string, int[]>                                 $roleMembers "tenant:roleId" => 用户ID列表
     * @param array<string, string>                                $userNames   "tenant:id" => 姓名
     * @param array<string, array{id:int,name:string}>              $depts       "tenant:id" => 部门行
     */
    public function __construct(
        private readonly array $roles = [],
        private readonly array $roleMembers = [],
        private readonly array $userNames = [],
        private readonly array $depts = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function findRoleBySlug(int $tenantId, string $slug): ?array
    {
        $role = $this->roles[$tenantId . ':' . $slug] ?? null;

        return $role === null ? null : $role;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserIdsByRoleId(int $tenantId, int $roleId): array
    {
        return $this->roleMembers[$tenantId . ':' . $roleId] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function findDeptById(int $tenantId, int $deptId): ?array
    {
        return $this->depts[$tenantId . ':' . $deptId] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUserNames(int $tenantId, array $userIds): array
    {
        $names = [];

        foreach ($userIds as $userId) {
            $key = $tenantId . ':' . (int) $userId;

            if (array_key_exists($key, $this->userNames)) {
                $names[(int) $userId] = $this->userNames[$key];
            }
        }

        return $names;
    }
}
