<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses;
use Infrastructure\Lowcode\Schema\SchemaParser;
use think\App;
use think\exception\HttpResponseException;
use think\Request;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use think\Response;

/**
 * Form Schema Controller | 表单Schema控制器
 *
 * Manages form schemas via RESTful API.
 * 通过RESTful API管理表单Schema。
 *
 * ## 异常处理：与 Collection / Field / Relationship 三个控制器同一套（预防性接入）
 *
 * 本类当前的四个动作（save / update / delete / duplicate）**都不触达运行时 DDL 闸门**：
 * `FormSchemaManager` 只写 `lowcode_forms` 元数据（`repository->save()`），
 * 从不调用 `SchemaBuilder::createTable()/addColumn()/dropTable()/dropColumn()`，
 * 因此今天走不到「`getMessage()` 恒为空」的 `HttpResponseException` 分支。
 *
 * 仍然显式接上共享 trait `app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses`，理由是
 * **缺陷 B 的成因不是「忘了写 catch」，而是「写了一份只取 `getMessage()` 的 catch」**：
 * 一旦将来给表单加上「按 schema 建物理表 / 加列」的能力（低代码表单与动态表本就是邻接能力），
 * 闸门会立刻出现在这条调用链上，而下面四个 `catch (\Exception $e)` 会**静默**把它降级成
 * 「HTTP 400 + 空 message」。这是预防性的接线，不是对现状的修饰：
 *   - `catch (HttpResponseException $e)` → 共享 trait 的 `runtimeDdlDeniedResponse()`（403 / 5003 + 可读原因）；
 *   - `catch (\Exception $e)` → `describeExceptionMessage()`（message 非空时**逐字节不变**，
 *     为空时才回落到异常类名，避免「没有原因的错误」）。
 *
 * 本类的业务异常（`InvalidArgumentException`：name/title/schema/layout 校验、Form not found）
 * message 均非空，因此对外响应与接入前**完全一致**；
 * 回归由 `tests/Feature/Lowcode/FormApiBusinessExceptionTest` 逐条钉住。
 *
 * @package app\controller\lowcode
 */
class FormSchemaController extends ApiController
{
    use RuntimeDdlDeniedResponses;

    protected FormSchemaManager $manager;
    protected FormValidatorManager $validatorManager;
    protected SchemaParser $schemaParser;

    public function __construct(
        App $app,
        FormSchemaManager $manager,
        FormValidatorManager $validatorManager,
        SchemaParser $schemaParser
    ) {
        parent::__construct($app);
        $this->manager = $manager;
        $this->validatorManager = $validatorManager;
        $this->schemaParser = $schemaParser;
    }

    /**
     * List form schemas | 列出表单Schema
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        // Get query parameters | 获取查询参数
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 20);
        $filters = $request->except(['page', 'pageSize']);

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

        $result = $this->manager->list($tenantId, $filters, $page, $pageSize);

        return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
    }

    /**
     * Get form schema | 获取表单Schema
     *
     * @param string $name
     * @param Request $request
     * @return Response
     */
    public function read(string $name, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        $form = $this->manager->get($name, $tenantId, $siteId);

        if (!$form) {
            return $this->notFound('Form not found');
        }

        $withValidator = filter_var((string)$request->get('with_validator', '0'), FILTER_VALIDATE_BOOLEAN);
        $withParsed = filter_var((string)$request->get('with_parsed', '0'), FILTER_VALIDATE_BOOLEAN);
        if ($withValidator && isset($form['schema']) && is_array($form['schema'])) {
            $definition = $this->validatorManager->getDefinition(
                $form['schema'],
                [
                    'tenant_id' => $tenantId,
                    'site_id' => $siteId,
                ],
                $form['name'] ?? null
            );
            $form['validator'] = $definition;
        }
        if ($withParsed && isset($form['schema']) && is_array($form['schema'])) {
            $form['parsed'] = $this->schemaParser->parse(
                $form['schema'],
                [
                    'tenant_id' => $tenantId,
                    'site_id' => $siteId,
                ],
                $form['name'] ?? null
            );
        }

        return $this->success($form);
    }

    /**
     * Create form | 创建表单
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        $data = $request->post();

        // Inject tenant context | 注入租户上下文
        $data['tenant_id'] = $request->tenantId();
        $data['site_id'] = $request->siteId();

        try {
            $form = $this->manager->create($data);
            return $this->success($form, 'Form created successfully');
        } catch (HttpResponseException $e) {
            // 预防性接线（详见类注释）：运行环境禁止运行时 DDL 时，SchemaBuilder 抛的
            // HttpResponseException 自身 message 为空，不能让客户端只看到「没有原因的错误」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            // message 非空时与改造前逐字节相同；仅在为空时回落到异常类名
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /**
     * Update form | 更新表单
     *
     * @param string $name Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function update(string $name, Request $request): Response
    {
        // Use param() to get data from various sources (GET/POST/PUT/route vars)
        // param()可以从多种来源获取数据（GET/POST/PUT/路由变量）
        $data = $request->param();
        $data['name'] = $name; // Ensure name matches URL

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        // Verify ownership/existence | 验证所有权/存在性
        $existing = $this->manager->get($name, $tenantId, $siteId);
        if (!$existing) {
            return $this->notFound('Form not found');
        }

        // Extract ID for update
        $id = $existing['id'];

        // Prepare update data (don't include id, tenant_id, site_id as they're separate params)
        // 准备更新数据（id、tenant_id、site_id作为独立参数，不放在data中）
        unset($data['name']); // Name cannot be changed via update
        unset($data['id']);
        unset($data['tenant_id']);
        unset($data['site_id']);

        try {
            $this->manager->update($id, $data, $tenantId);
            return $this->success(null, 'Form updated successfully');
        } catch (HttpResponseException $e) {
            // 预防性接线（详见类注释）：运行环境禁止运行时 DDL 时，SchemaBuilder 抛的
            // HttpResponseException 自身 message 为空，不能让客户端只看到「没有原因的错误」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            // message 非空时与改造前逐字节相同；仅在为空时回落到异常类名
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /**
     * Delete form | 删除表单
     *
     * @param string $name Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function delete(string $name, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            // Resolve ID from name | 从名称解析ID
            $existing = $this->manager->get($name, $tenantId, $siteId);
            if (!$existing) {
                return $this->notFound('Form not found');
            }

            $this->manager->delete($existing['id'], $tenantId);
            return $this->success(null, 'Form deleted successfully');
        } catch (HttpResponseException $e) {
            // 预防性接线（详见类注释）：运行环境禁止运行时 DDL 时，SchemaBuilder 抛的
            // HttpResponseException 自身 message 为空，不能让客户端只看到「没有原因的错误」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            // message 非空时与改造前逐字节相同；仅在为空时回落到异常类名
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /**
     * Duplicate form | 复制表单
     *
     * @param string $name Source form name | 源表单名称
     * @param Request $request
     * @return Response
     */
    public function duplicate(string $name, Request $request): Response
    {
        $newName = $request->post('new_name');
        if (empty($newName)) {
            return $this->error('New name is required');
        }

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            // 原先这里直接把路由里的 `$name`（字符串）当 `$id` 传给 duplicate()，
            // 而 duplicate() 的首参是 `int $id` —— 在 declare(strict_types=1) 下必抛 TypeError，
            // 该端点实际上是 500。这里按 delete() 的写法先按名称+租户解析出真实 ID。
            $existing = $this->manager->get($name, $tenantId, $siteId);
            if (!$existing) {
                return $this->notFound('Form not found');
            }

            $duplicated = $this->manager->duplicate((int) $existing['id'], $newName, $tenantId);

            return $this->success(['id' => $duplicated['id'] ?? null], 'Form duplicated successfully');
        } catch (HttpResponseException $e) {
            // 预防性接线（详见类注释）：运行环境禁止运行时 DDL 时，SchemaBuilder 抛的
            // HttpResponseException 自身 message 为空，不能让客户端只看到「没有原因的错误」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            // message 非空时与改造前逐字节相同；仅在为空时回落到异常类名
            return $this->error($this->describeExceptionMessage($e));
        }
    }
}
