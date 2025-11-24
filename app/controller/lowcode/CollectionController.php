<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use think\Request;
use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use think\Response;

/**
 * Collection API Controller | Collection API控制器
 *
 * RESTful API for Collection management.
 * Collection管理的RESTful API。
 *
 * @package app\controller\lowcode
 */
class CollectionController extends ApiController
{
    protected CollectionManager $collectionManager;

    /**
     * 初始化控制器，注入 CollectionManager
     */
    protected function initialize()
    {
        parent::initialize();
        $this->collectionManager = $this->app->make(CollectionManager::class);
    }


    /**
     * Constructor | 构造函数
     */
    /**
     * Constructor | 构造函数
    public function __construct(\think\App $app, CollectionManager $collectionManager)
    {
        parent::__construct($app);
        $this->collectionManager = $collectionManager;
    }

    /**
     * List collections | 列出Collections
     *
     * GET /api/lowcode/collections
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        // Get query parameters | 获取查询参数
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);
        $filters = $params['filters'] ?? [];

        // Validate pagination parameters | 验证分页参数
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Validate filters parameter | 验证筛选参数
        $filtersValidation = $this->validateFilters($filters);
        if (!$filtersValidation['valid']) {
            return $this->validationError($filtersValidation['errors']);
        }

        // Get tenant ID from request context (set by TenantIdentify/Auth middleware)
        // 从请求上下文获取租户ID（由 TenantIdentify/Auth 中间件设置）
        $tenantId = $request->tenantId();

        $result = $this->collectionManager->list($tenantId, $filters, $page, $pageSize);

        return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
    }

    /**
     * Get collection details | 获取Collection详情
     *
     * GET /api/lowcode/collections/{name}
     *
     * @param string $name Collection name | Collection名称
     * @return Response
     */
    public function read(string $name, Request $request): Response
    {
        // Get tenant ID from request context (set by TenantIdentify/Auth middleware)
        // 从请求上下文获取租户ID（由 TenantIdentify/Auth 中间件设置）
        $tenantId = $request->tenantId();
        $collection = $this->collectionManager->get($name, $tenantId);

        if (!$collection) {
            return $this->notFound('Collection not found');
        }

        return $this->success($collection->toArray());
    }

    /**
     * Create collection | 创建Collection
     *
     * POST /api/lowcode/collections
     *
     * @return Response
     */
    public function save(Request $request): Response
    {
        $data = $request->post();

        // Validate request | 验证请求
        if (empty($data['name']) || empty($data['title']) || empty($data['fields'])) {
            return $this->error('Name, title and fields are required');
        }

        if (!is_array($data['fields']) || empty($data['fields'])) {
            return $this->error('Fields must be a non-empty array');
        }

        try {
            // Build collection | 构建Collection
            $collection = new Collection($data['name'], [
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'table_name' => $data['table_name'] ?? null,
            ]);

            // Add fields | 添加字段
            foreach ($data['fields'] as $fieldData) {
                if (!isset($fieldData['type']) || !isset($fieldData['name'])) {
                    return $this->error('Field type and name are required');
                }

                $field = FieldFactory::create(
                    $fieldData['type'],
                    $fieldData['name'],
                    $fieldData['options'] ?? []
                );
                $collection->addField($field);
            }

            // Create collection | 创建Collection
            $this->collectionManager->create($collection);

            return $this->success($collection->toArray(), 'Collection created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Update collection | 更新Collection
     *
     * PUT /api/lowcode/collections/{name}
     *
     * @param string $name Collection name | Collection名称
     * @return Response
     */
    public function update(string $name, Request $request): Response
    {
        $data = $request->put();
        $tenantId = $data['tenant_id'] ?? 1;
        $collection = $this->collectionManager->get($name, $tenantId);

        if (!$collection) {
            return $this->notFound('Collection not found');
        }

        try {
            // Update collection properties | 更新Collection属性
            if (isset($data['title'])) {
                $collection->setTitle($data['title']);
            }

            if (isset($data['description'])) {
                $collection->setDescription($data['description']);
            }

            // Update collection | 更新Collection
            $this->collectionManager->update($collection);

            return $this->success($collection->toArray(), 'Collection updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Delete collection | 删除Collection
     *
     * DELETE /api/lowcode/collections/{name}
     *
     * @param string $name Collection name | Collection名称
     * @return Response
     */
    public function delete(string $name, Request $request): Response
    {
        try {
            $data = $request->delete();
            $dropTable = (bool)($data['drop_table'] ?? true);
            $this->collectionManager->delete($name, $dropTable);

            return $this->success(null, 'Collection deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
