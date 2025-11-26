<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Generator;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;

/**
 * Controller Generator | 控制器生成器
 *
 * Generates controller code for a Collection.
 * 为Collection生成控制器代码。
 *
 * @package Infrastructure\Lowcode\Generator
 */
class ControllerGenerator
{
    /**
     * Generate controller code | 生成控制器代码
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @param array $options Generation options | 生成选项
     * @return string Generated file path | 生成的文件路径
     */
    public function generate(CollectionInterface $collection, array $options = []): string
    {
        $name = $collection->getName();
        $className = $this->getClassName($name);
        $namespace = $options['namespace'] ?? 'app\\controller\\lowcode';
        $tableName = $collection->getTableName();

        $code = $this->generateCode($className, $namespace, $name, $tableName, $collection);

        $filePath = $this->getFilePath($className, $options);
        $this->writeFile($filePath, $code);

        return $filePath;
    }

    /**
     * Get class name from collection name | 从Collection名称获取类名
     *
     * @param string $name Collection name | Collection名称
     * @return string Class name | 类名
     */
    protected function getClassName(string $name): string
    {
        // Convert snake_case to PascalCase | 将snake_case转换为PascalCase
        $parts = explode('_', $name);
        $parts = array_map('ucfirst', $parts);
        return implode('', $parts) . 'Controller';
    }

    /**
     * Generate controller code | 生成控制器代码
     *
     * @param string $className Class name | 类名
     * @param string $namespace Namespace | 命名空间
     * @param string $collectionName Collection name | Collection名称
     * @param string $tableName Table name | 表名
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @return string Generated code | 生成的代码
     */
    protected function generateCode(string $className, string $namespace, string $collectionName, string $tableName, CollectionInterface $collection): string
    {
        $fields = $this->getFieldList($collection);
        $fillable = $this->getFillableFields($collection);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use app\controller\ApiController;
use app\Request;
use think\Response;
use think\facade\Db;

/**
 * {$className} | {$collectionName}控制器
 *
 * Auto-generated CRUD controller for {$collectionName} collection.
 * 自动生成的{$collectionName}集合CRUD控制器。
 *
 * @package {$namespace}
 */
class {$className} extends ApiController
{
    /**
     * List {$collectionName} records | 列出{$collectionName}记录
     *
     * @param Request \$request Request instance | 请求实例
     * @return Response JSON response | JSON响应
     */
    public function index(Request \$request): Response
    {
        \$tenantId = \$request->tenantId();
        \$siteId = \$request->siteId();
        \$page = (int) \$request->get('page', 1);
        \$pageSize = (int) \$request->get('page_size', 20);

        \$query = Db::name('{$tableName}')
            ->where('tenant_id', \$tenantId)
            ->where('site_id', \$siteId);

        // Apply filters | 应用过滤器
        \$filters = \$request->get('filters', []);
        if (!empty(\$filters)) {
            foreach (\$filters as \$field => \$value) {
                if (in_array(\$field, [{$fillable}], true)) {
                    \$query->where(\$field, \$value);
                }
            }
        }

        // Apply sorting | 应用排序
        \$sortBy = \$request->get('sort_by', 'id');
        \$sortOrder = \$request->get('sort_order', 'desc');
        \$query->order(\$sortBy, \$sortOrder);

        \$total = \$query->count();
        \$list = \$query->page(\$page, \$pageSize)->select()->toArray();

        return \$this->paginate(\$list, \$total, \$page, \$pageSize);
    }

    /**
     * Get single {$collectionName} record | 获取单个{$collectionName}记录
     *
     * @param Request \$request Request instance | 请求实例
     * @param int \$id Record ID | 记录ID
     * @return Response JSON response | JSON响应
     */
    public function read(Request \$request, int \$id): Response
    {
        \$tenantId = \$request->tenantId();
        \$siteId = \$request->siteId();

        \$record = Db::name('{$tableName}')
            ->where('id', \$id)
            ->where('tenant_id', \$tenantId)
            ->where('site_id', \$siteId)
            ->find();

        if (!\$record) {
            return \$this->error('Record not found', 404);
        }

        return \$this->success(\$record);
    }

    /**
     * Create {$collectionName} record | 创建{$collectionName}记录
     *
     * @param Request \$request Request instance | 请求实例
     * @return Response JSON response | JSON响应
     */
    public function create(Request \$request): Response
    {
        \$tenantId = \$request->tenantId();
        \$siteId = \$request->siteId();
        \$data = \$request->post();

        // Filter allowed fields | 过滤允许的字段
        \$allowedFields = [{$fillable}];
        \$data = array_intersect_key(\$data, array_flip(\$allowedFields));

        // Add tenant and site context | 添加租户和站点上下文
        \$data['tenant_id'] = \$tenantId;
        \$data['site_id'] = \$siteId;
        \$data['created_at'] = date('Y-m-d H:i:s');
        \$data['updated_at'] = date('Y-m-d H:i:s');

        \$id = Db::name('{$tableName}')->insertGetId(\$data);

        return \$this->success(['id' => \$id], 'Record created successfully', 201);
    }

    /**
     * Update {$collectionName} record | 更新{$collectionName}记录
     *
     * @param Request \$request Request instance | 请求实例
     * @param int \$id Record ID | 记录ID
     * @return Response JSON response | JSON响应
     */
    public function update(Request \$request, int \$id): Response
    {
        \$tenantId = \$request->tenantId();
        \$siteId = \$request->siteId();
        \$data = \$request->post();

        // Check if record exists | 检查记录是否存在
        \$exists = Db::name('{$tableName}')
            ->where('id', \$id)
            ->where('tenant_id', \$tenantId)
            ->where('site_id', \$siteId)
            ->find();

        if (!\$exists) {
            return \$this->error('Record not found', 404);
        }

        // Filter allowed fields | 过滤允许的字段
        \$allowedFields = [{$fillable}];
        \$data = array_intersect_key(\$data, array_flip(\$allowedFields));
        \$data['updated_at'] = date('Y-m-d H:i:s');

        Db::name('{$tableName}')
            ->where('id', \$id)
            ->where('tenant_id', \$tenantId)
            ->where('site_id', \$siteId)
            ->update(\$data);

        return \$this->success(null, 'Record updated successfully');
    }

    /**
     * Delete {$collectionName} record | 删除{$collectionName}记录
     *
     * @param Request \$request Request instance | 请求实例
     * @param int \$id Record ID | 记录ID
     * @return Response JSON response | JSON响应
     */
    public function delete(Request \$request, int \$id): Response
    {
        \$tenantId = \$request->tenantId();
        \$siteId = \$request->siteId();

        // Check if record exists | 检查记录是否存在
        \$exists = Db::name('{$tableName}')
            ->where('id', \$id)
            ->where('tenant_id', \$tenantId)
            ->where('site_id', \$siteId)
            ->find();

        if (!\$exists) {
            return \$this->error('Record not found', 404);
        }

        Db::name('{$tableName}')
            ->where('id', \$id)
            ->where('tenant_id', \$tenantId)
            ->where('site_id', \$siteId)
            ->delete();

        return \$this->success(null, 'Record deleted successfully');
    }
}

PHP;
    }

    /**
     * Get field list from collection | 从Collection获取字段列表
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @return string Field list | 字段列表
     */
    protected function getFieldList(CollectionInterface $collection): string
    {
        $fields = [];
        foreach ($collection->getFields() as $field) {
            $fields[] = $field->getName();
        }
        return implode(', ', $fields);
    }

    /**
     * Get fillable fields from collection | 从Collection获取可填充字段
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @return string Fillable fields | 可填充字段
     */
    protected function getFillableFields(CollectionInterface $collection): string
    {
        $fields = [];
        foreach ($collection->getFields() as $field) {
            $fields[] = "'{$field->getName()}'";
        }
        return implode(', ', $fields);
    }

    /**
     * Get file path for controller | 获取控制器文件路径
     *
     * @param string $className Class name | 类名
     * @param array $options Generation options | 生成选项
     * @return string File path | 文件路径
     */
    protected function getFilePath(string $className, array $options): string
    {
        $basePath = $options['base_path'] ?? app()->getRootPath() . 'app/controller/lowcode';
        return $basePath . '/' . $className . '.php';
    }

    /**
     * Write file | 写入文件
     *
     * @param string $filePath File path | 文件路径
     * @param string $content File content | 文件内容
     * @return void
     */
    protected function writeFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $content);
    }
}
