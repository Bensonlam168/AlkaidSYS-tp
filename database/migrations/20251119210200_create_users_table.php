<?php

use think\migration\Migrator;

/**
 * Create Users Table Migration | 创建用户表迁移
 *
 * Creates the users table for user management.
 * 为用户管理创建用户表。
 */
class CreateUsersTable extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function change(): void
    {
        $table = $this->table('users', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Users table | 用户表',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
                'comment' => 'User ID | 用户ID',
            ])
            ->addColumn('tenant_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Tenant ID | 租户ID',
            ])
            ->addColumn('username', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'Username | 用户名',
            ])
            ->addColumn('email', 'string', [
                'limit' => 100,
                'null' => false,
                'comment' => 'Email address | 邮箱地址',
            ])
            ->addColumn('password', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => 'Password hash | 密码哈希',
            ])
            ->addColumn('name', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => 'Real name | 真实姓名',
            ])
            ->addColumn('avatar', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Avatar URL | 头像URL',
            ])
            ->addColumn('phone', 'string', [
                'limit' => 20,
                'null' => true,
                'comment' => 'Phone number | 电话号码',
            ])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'inactive', 'locked'],
                'default' => 'active',
                'comment' => 'User status | 用户状态',
            ])
            ->addColumn('email_verified_at', 'timestamp', [
                'null' => true,
                'comment' => 'Email verified at | 邮箱验证时间',
            ])
            ->addColumn('last_login_at', 'timestamp', [
                'null' => true,
                'comment' => 'Last login at | 最后登录时间',
            ])
            ->addColumn('last_login_ip', 'string', [
                'limit' => 45,
                'null' => true,
                'comment' => 'Last login IP | 最后登录IP',
            ])
            ->addColumn('created_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => 'Created at | 创建时间',
            ])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'comment' => 'Updated at | 更新时间',
            ])
            ->addIndex(['tenant_id'], ['name' => 'idx_tenant_id'])
            ->addIndex(['username'], ['name' => 'idx_username'])
            ->addIndex(['email'], ['name' => 'idx_email'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['tenant_id', 'username'], ['unique' => true, 'name' => 'idx_tenant_username'])
            ->addIndex(['tenant_id', 'email'], ['unique' => true, 'name' => 'idx_tenant_email'])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_users_tenant_id'
            ])
            ->create();
    }
}
