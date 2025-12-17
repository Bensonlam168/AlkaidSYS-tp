<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Schema;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use think\facade\Db;
use think\facade\Log;

/**
 * Schema 版本管理服务
 * - 负责计算 schema_hash、维护 lowcode_schema_versions 版本记录
 * - 同步当前最新版本到 lowcode_collections / lowcode_forms 表
 */
class SchemaVersionService
{
    /**
     * 记录 Collection 的 Schema 版本
     */
    public function recordCollection(CollectionInterface $collection): array
    {
        $tenantId = $collection->getTenantId() ?? 0;
        $siteId = $collection->getSiteId() ?? 0;
        $schema = $collection->toArray();

        return $this->record('collection', $collection->getName(), $schema, $tenantId, $siteId, $collection->getId());
    }

    /**
     * 记录 Form Schema 版本
     *
     * @param array $form 表单数据（需要包含 name/schema/tenant_id/site_id）
     */
    public function recordForm(array $form): array
    {
        $tenantId = (int)($form['tenant_id'] ?? 0);
        $siteId = (int)($form['site_id'] ?? 0);
        $schema = is_array($form['schema'] ?? null) ? $form['schema'] : json_decode((string)($form['schema'] ?? '[]'), true);

        return $this->record('form', (string)$form['name'], $schema ?? [], $tenantId, $siteId, (int)($form['id'] ?? 0));
    }

    /**
     * 记录版本信息（通用）
     *
     * @return array{version:int,hash:string}
     */
    public function record(string $objectType, string $objectName, array $schema, int $tenantId = 0, int $siteId = 0, ?int $currentId = null): array
    {
        $hash = $this->computeHash($schema);

        // 查找最新版本
        $latest = Db::name('lowcode_schema_versions')
            ->where([
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
                'object_type' => $objectType,
                'object_name' => $objectName,
            ])
            ->order('version', 'desc')
            ->find();

        $nextVersion = $latest ? ((int)$latest['version'] + 1) : 1;
        $latestHash = $latest['schema_hash'] ?? null;

        // 如果哈希未变化则复用现有版本
        if ($latestHash === $hash) {
            $this->syncCurrentVersion($objectType, $objectName, $tenantId, $siteId, (int)($latest['version'] ?? 1), $hash, $currentId);
            return ['version' => (int)($latest['version'] ?? 1), 'hash' => $hash];
        }

        // 写入版本快照
        Db::name('lowcode_schema_versions')->insert([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'object_type' => $objectType,
            'object_name' => $objectName,
            'version' => $nextVersion,
            'schema_hash' => $hash,
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->syncCurrentVersion($objectType, $objectName, $tenantId, $siteId, $nextVersion, $hash, $currentId);

        return ['version' => $nextVersion, 'hash' => $hash];
    }

    /**
     * 计算 Schema 哈希（稳定排序）
     */
    public function computeHash(array $schema): string
    {
        $normalized = $this->normalize($schema);
        return hash('sha256', $normalized);
    }

    /**
     * 将 Schema 规范化为稳定的 JSON 字符串，避免键顺序导致哈希不一致。
     */
    protected function normalize(array $schema): string
    {
        $normalized = $this->sortRecursive($schema);
        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * 递归排序数组键，确保稳定哈希。
     */
    protected function sortRecursive(array $data): array
    {
        // 仅对“关联数组”做键排序，避免破坏 list 顺序
        if ($this->isAssociativeArray($data)) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }
        return $data;
    }

    /**
     * 判断是否为关联数组
     */
    protected function isAssociativeArray(array $data): bool
    {
        if ($data === []) {
            return false;
        }

        return array_keys($data) !== range(0, count($data) - 1);
    }

    /**
     * 将最新版本同步到主表（collections/forms）
     */
    protected function syncCurrentVersion(
        string $objectType,
        string $objectName,
        int $tenantId,
        int $siteId,
        int $version,
        string $hash,
        ?int $currentId
    ): void {
        try {
            if ($objectType === 'collection') {
                $query = Db::name('lowcode_collections')
                    ->where('name', $objectName)
                    ->where('tenant_id', $tenantId);
                if ($currentId) {
                    $query->where('id', $currentId);
                }
                $query->update([
                    'schema_version' => $version,
                    'schema_hash' => $hash,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } elseif ($objectType === 'form') {
                $query = Db::name('lowcode_forms')
                    ->where('name', $objectName)
                    ->where('tenant_id', $tenantId);
                if ($currentId) {
                    $query->where('id', $currentId);
                }
                $query->update([
                    'schema_version' => $version,
                    'schema_hash' => $hash,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            // 记录但不阻断主流程
            Log::error('Sync schema version failed', [
                'object_type' => $objectType,
                'object_name' => $objectName,
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
