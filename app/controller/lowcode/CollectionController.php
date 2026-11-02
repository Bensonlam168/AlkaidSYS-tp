<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\constant\ErrorCode;
use app\controller\ApiController;
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

    /**
     * 运行时 DDL 被环境策略拒绝时的对外响应 | Response when runtime DDL is denied
     *
     * 背景（缺陷 B）：`Infrastructure\Schema\SchemaBuilder::assertCanRunDDL()` 拦截时抛
     * `think\exception\HttpResponseException`，该异常**只携带一个已构造好的 Response**，
     * 构造函数没有调用父类，因此 `getMessage()` 恒为空串。原来的 `catch (\Exception $e)`
     * + `$this->error($e->getMessage())` 于是把「403 / 5003 / 明确的 policy reason」
     * 降级成「400 + 空 message」，调用方既看不出为什么失败，也拿不到下一步该做什么。
     *
     * 这里的处理原则：
     *   - **保留**原响应的 HTTP 状态码（403）与业务码（5003），不改变既有契约；
     *   - message 换成「异常类型 + 拦截原因 + 可操作提示」，让调用方自己就能修好配置；
     *   - **只**暴露策略原因（`env` / `operation` / `reason`），不拼接堆栈、不拼接 SQL，
     *     生产环境即便走到这里也不会泄露实现细节。
     *
     * @param HttpResponseException $e 由 SchemaBuilder 抛出的响应异常
     * @return Response
     */
    protected function runtimeDdlDeniedResponse(HttpResponseException $e): Response
    {
        $origin = $e->getResponse();
        $body = $origin->getData();

        // 原响应的状态码/业务码优先；取不到时回落到 403 + 2002（权限不足）——
        // 语义上「当前环境不允许这个操作」属于「禁止」，不是「参数错误」。
        $status = $origin->getCode() > 0 ? $origin->getCode() : 403;
        $code = is_array($body) && isset($body['code']) ? (int)$body['code'] : ErrorCode::FORBIDDEN;
        $reason = is_array($body) ? (string)($body['data']['reason'] ?? '') : '';
        $operation = is_array($body) ? (string)($body['data']['operation'] ?? 'runtime_ddl') : 'runtime_ddl';

        $message = sprintf(
            '运行时 DDL 被环境策略拒绝（异常类型=%s, operation=%s, reason=%s）。%s',
            get_class($e),
            $operation === '' ? 'unknown' : $operation,
            $reason === '' ? 'unknown' : $reason,
            $this->runtimeDdlDeniedHint($reason)
        );

        return $this->error($message, $code, [], $status);
    }

    /**
     * 按拦截原因给出可操作提示 | Actionable hint per denial reason
     *
     * 原因码与 `SchemaBuilder::assertCanRunDDL()` 一一对应，新增原因时须同步这里，
     * 否则会落到 default 分支（仍可读，但少一条具体指引）。
     *
     * @param string $reason 拦截原因码
     * @return string
     */
    protected function runtimeDdlDeniedHint(string $reason): string
    {
        return match ($reason) {
            'production_environment' => '生产类环境永久禁止运行时 DDL，与 ALLOW_RUNTIME_DDL 取值无关，请改用迁移文件执行建表/删表。',
            'env_not_in_whitelist' => 'APP_ENV 必须属于白名单 dev / local / testing（白名单由 SCHEMA_RUNTIME_DDL_ALLOWED_ENVS 配置）。',
            'flag_disabled' => '请在非生产环境的 .env 中设置 ALLOW_RUNTIME_DDL = true 后重试（仅限 dev / local / testing）。',
            default => '请检查 APP_ENV 与 ALLOW_RUNTIME_DDL 配置；生产类环境请改用迁移文件。',
        };
    }

    /**
     * 取出异常的可读消息 | Readable message of an exception
     *
     * 保留原有行为：非 DDL 策略类异常仍回传自身 message（调用方排障依赖它）。
     * 唯一改动是 message 为空时回落到类名 —— 空白 message 等于把「错误」变成「无原因的错误」，
     * 而类名足以让调用方定位到异常类型，且不含堆栈与 SQL。
     *
     * @param \Exception $e 原始异常
     * @return string
     */
    protected function describeExceptionMessage(\Exception $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : get_class($e);
    }
}
