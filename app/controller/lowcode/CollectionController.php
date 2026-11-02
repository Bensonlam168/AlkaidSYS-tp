<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses;
use think\App;
use think\Request;
use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use think\exception\HttpResponseException;
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
    // 「运行时 DDL 被拒」的对外响应统一由该 trait 提供：本类、FieldController 与
    // RelationshipController 都走同一道 SchemaBuilder 闸门，不允许各写一份文案。
    use RuntimeDdlDeniedResponses;

    protected CollectionManager $collectionManager;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app, CollectionManager $collectionManager)
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
            // Resolve tenant/site from request context | 从请求上下文解析租户/站点
            $tenantId = $request->tenantId();
            $siteId = $request->siteId();

            // Build collection | 构建Collection
            $collection = new Collection($data['name'], [
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'table_name' => $data['table_name'] ?? null,
                // Persist as tenant-scoped metadata | 将元数据保存到当前租户/站点
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
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
        } catch (HttpResponseException $e) {
            // 建表同样走运行时 DDL 闸门，拦截时的对外文案与删除接口一致（缺陷 B）
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    public function update(string $name, Request $request): Response
    {
        $data = $request->put();

        // Get tenant ID from request context (set by TenantIdentify/Auth middleware)
        // 从请求上下文获取租户ID（由 TenantIdentify/Auth 中间件设置）
        // NOTE: 租户信息仅来自 Request 上下文，不信任请求体中的 tenant_id 字段。
        $tenantId = $request->tenantId();
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
        } catch (HttpResponseException $e) {
            // update() 当前不改表结构，理论上不会触达运行时 DDL 闸门；
            // 保留同级处理是为了让本控制器对 HttpResponseException 的处置**一致**——
            // 将来若 update 补上 DDL 逻辑，不会又退回「空 message」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    public function delete(string $name, Request $request): Response
    {
        try {
            $data = $request->delete();
            $dropTable = (bool)($data['drop_table'] ?? true);

            // Get tenant ID from request context (set by TenantIdentify/Auth middleware)
            // 从请求上下文获取租户ID（由 TenantIdentify/Auth 中间件设置）
            $tenantId = $request->tenantId();

            $this->collectionManager->delete($name, $dropTable, $tenantId);

            return $this->success(null, 'Collection deleted successfully');
        } catch (HttpResponseException $e) {
            // 缺陷 B：删除集合默认 `drop_table = true`，运行期要真删物理表。
            // 一旦运行环境不允许运行时 DDL，`SchemaBuilder` 抛的是 HttpResponseException，
            // 只取 getMessage() 会得到空串，客户端只能看到「没有原因的错误」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /*
     * 上一轮曾在这里内联实现 runtimeDdlDeniedResponse() / runtimeDdlDeniedHint() /
     * describeExceptionMessage() 三个方法。它们与 FieldController / RelationshipController
     * 需要的是**同一套**语义（同样被 SchemaBuilder 的运行时 DDL 闸门拦下），
     * 所以已提取到 `app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses` trait，
     * 由本类顶部的 `use` 语句引入，不再保留第二份实现。
     */
}
