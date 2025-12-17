<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Repository;

use think\facade\Db;

/**
 * 插件包仓储 | 用于存储扫描得到的插件包清单
 */
class PluginPackageRepository
{
    protected string $table = 'alkaid_plugin_packages';

    /**
     * 保存或更新插件包
     *
     * @param array{
     *   plugin_key:string,
     *   name?:string,
     *   version?:string,
     *   category?:string|null,
     *   app_key?:string|null,
     *   main_class:string,
     *   base_path:string,
     *   manifest_path:string,
     *   manifest_hash?:string|null,
     *   manifest:array
     * } $data
     */
    public function upsert(array $data): void
    {
        $key = $data['plugin_key'];
        $exists = Db::name($this->table)->where('plugin_key', $key)->find();

        $row = [
            'plugin_key' => $key,
            'name' => $data['name'] ?? $key,
            'version' => $data['version'] ?? '0.0.0',
            'category' => $data['category'] ?? null,
            'app_key' => $data['app_key'] ?? null,
            'main_class' => $data['main_class'],
            'base_path' => $data['base_path'],
            'manifest_path' => $data['manifest_path'],
            'manifest_hash' => $data['manifest_hash'] ?? null,
            'manifest' => json_encode($data['manifest'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($exists) {
            Db::name($this->table)->where('plugin_key', $key)->update($row);
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            Db::name($this->table)->insert($row);
        }
    }

    /**
     * 获取所有插件包
     *
     * @return array<int, array<string,mixed>>
     */
    public function all(): array
    {
        $rows = Db::name($this->table)->select()->toArray();
        return array_map(function ($row) {
            $row['manifest'] = is_string($row['manifest']) ? json_decode($row['manifest'], true) : ($row['manifest'] ?? []);
            return $row;
        }, $rows);
    }

    public function findByKey(string $pluginKey): ?array
    {
        $row = Db::name($this->table)
            ->where('plugin_key', $pluginKey)
            ->find();

        if (!$row) {
            return null;
        }

        $row['manifest'] = is_string($row['manifest']) ? json_decode($row['manifest'], true) : ($row['manifest'] ?? []);
        return $row;
    }
}

