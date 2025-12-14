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
