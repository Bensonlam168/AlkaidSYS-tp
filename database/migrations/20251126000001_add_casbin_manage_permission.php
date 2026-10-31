<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\facade\Db;

/**
 * 添加 Casbin 管理权限
 * Add Casbin Manage Permission
 *
 * 为手动刷新 Casbin 策略 API 添加所需的权限。
 * Add required permission for manual Casbin policy reload API.
 *
 * 【版本号为什么是 14 位 20251126000001（R43 修复）】
 * 本文件原名 `20251125_add_casbin_manage_permission.php`，版本号只有 8 位（20251125）。
 * Phinx 按版本号**数值**排序执行（20,251,125 ≪ 20,251,119,000,001），
 * 于是这条迁移排在全部 14 位时间戳迁移**之前**，成为新库的第一条迁移；
 * 而它的 up() 写入 permissions 表，该表要到 20251119210400_create_permissions_table.php
 * 才被创建 —— 全新数据库上执行 `php think migrate:run` 必然在第一行就报
 * 1146 Table 'xxx.permissions' doesn't exist。
 *
 * 因此改为 14 位版本号 20251126000001：
 *   - 长度与其余迁移一致，排序按时间先后，不再插队；
 *   - 时间点取 2025-11-26（紧随同批 lowcode 多租户迁移 20251125000001/20251125001000 之后），
 *     保留「它其实发生在 2025-11-26」的真实时序，而不是简单挪到链尾；
 *   - 大于 20251119210400（permissions 表创建），因此新库执行时依赖已就绪。
 *
 * 类名保持 AddCasbinManagePermission 不变：Phinx 由「文件名去掉版本号前缀后的部分」映射类名，
 * 前缀长度从 8 位变 14 位不影响映射结果。
 *
 * 对既有库安全：本迁移 up() 本身幂等（先按 slug 查存在即跳过），
 * 改名后既有库会把它当成一条「未应用」的迁移再执行一次，实际只打印跳过日志。
 */

class AddCasbinManagePermission extends Migrator
{
    /**
     * 执行迁移
     * Run migration
     */
    public function up(): void
    {
        // 检查权限是否已存在
        // Check if permission already exists
        $exists = Db::table('permissions')
            ->where('slug', 'casbin.manage')
            ->find();

        if ($exists) {
            $this->output->writeln('<info>Permission casbin.manage already exists, skipping...</info>');
            return;
        }

        // 插入权限
        // Insert permission
        Db::table('permissions')->insert([
            'name' => 'Casbin Manage',
            'slug' => 'casbin.manage',
            'resource' => 'casbin',
            'action' => 'manage',
            'description' => 'Manage Casbin authorization engine, including manual policy reload',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->output->writeln('<info>Permission casbin.manage created successfully</info>');
    }

    /**
     * 回滚迁移
     * Rollback migration
     */
    public function down(): void
    {
        // 删除权限
        // Delete permission
        Db::table('permissions')
            ->where('slug', 'casbin.manage')
            ->delete();

        $this->output->writeln('<info>Permission casbin.manage deleted</info>');
    }
}
