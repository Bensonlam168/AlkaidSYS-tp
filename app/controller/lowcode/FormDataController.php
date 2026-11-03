<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses;
use app\constant\ErrorCode;
use app\exception\ForbiddenException;
use think\App;
use think\exception\HttpResponseException;
use think\Request;
use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use think\Response;

/**
 * Form Data Controller | 表单数据控制器
 *
 * Manages form data submission and retrieval.
 * 管理表单数据提交和检索。
 *
 * ## 异常处理：与 Collection / Field / Relationship 三个控制器同一套（预防性接入）
 *
 * 本类当前的四个动作（index / read / save / delete）**都不触达运行时 DDL 闸门**：
 * `FormDataManager` 只做两件事 —— 解析表单绑定的 Collection（`collectionManager->get()`，纯读缓存/元数据）
 * 与对已存在的物理表执行 INSERT/SELECT/UPDATE/DELETE；它从不调用
 * `SchemaBuilder::createTable()/addColumn()/dropTable()/dropColumn()`，
 * 因此今天走不到「`getMessage()` 恒为空」的 `HttpResponseException` 分支。
 *
 * 仍然显式接上共享 trait `app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses`：
 * 「表单数据」与「动态表结构」是邻接能力，将来一旦在写入路径上加「缺表自动建 / 缺列自动补」，
 * 闸门就会立刻出现在这条调用链上，而只取 `getMessage()` 的 catch 会把它**静默**降级成
 * 「HTTP 400 + 空 message」（缺陷 B 的完整成因见该 trait 的类注释）。
 *
 * 接入顺序（`save` 有三层 catch，语义必须保持）：
 *   1. `HttpResponseException`：运行时 DDL 被拒 → 共享 trait 的 403 / 5003 + 可读原因；
 *   2. `ForbiddenException`：**保持原样**——它自带显式 message（「租户无权访问该数据」等）与
 *      2002 业务码 + 403 状态，语义比通用分支更精确，因此**不**并入 trait 的通用分支；
 *   3. `\Exception`：`describeExceptionMessage()` —— message 非空时**逐字节不变**。
 *
 * 业务异常的对外响应因此与接入前完全一致；回归由
 * `tests/Feature/Lowcode/FormApiBusinessExceptionTest` 逐条钉住。
 *
 * @package app\controller\lowcode
 */
class FormDataController extends ApiController
{
    use RuntimeDdlDeniedResponses;

    protected FormDataManager $manager;

    public function __construct(App $app, FormDataManager $manager)
    {
        parent::__construct($app);
        $this->manager = $manager;
    }

    /**
     * List form data | 获取表单数据列表
     *
     * @param string $formName Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function index(string $name, Request $request): Response
    {
        // Get query parameters | 获取查询参数
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['pageSize'] ?? 20);

        // Validate pagination parameters | 验证分页参数
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Extract filters (exclude pagination params) | 提取筛选条件（排除分页参数）
        $filters = array_diff_key($params, array_flip(['page', 'pageSize']));

        // Validate filters parameter | 验证筛选参数
        $filtersValidation = $this->validateFilters($filters);
        if (!$filtersValidation['valid']) {
            return $this->validationError($filtersValidation['errors']);
        }

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            // 参数顺序：表单名、租户ID（必选）、筛选条件、分页参数、站点ID
            // 调整为 PHP 8.2+ 兼容签名：必选参数在可选参数之前
            $result = $this->manager->list($name, $tenantId, $filters, $page, $pageSize, $siteId);
            return $this->paginate($result['list'], $result['total'], $result['page'], $result['pageSize']);
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
     * Get single form data record | 获取单条表单数据记录
     *
     * @param string $name Form name | 表单名称
     * @param int $id Data ID | 数据ID
     * @param Request $request
     * @return Response
     */
    public function read(string $name, int $id, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $data = $this->manager->get($name, $id, $tenantId, $siteId);
            if (!$data) {
                return $this->notFound('Data not found');
            }
            return $this->success($data);
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
     * Save form data (Create/Update) | 保存表单数据（创建/更新）
     *
     * @param string $formName Form name | 表单名称
     * @param Request $request
     * @return Response
     */
    public function save(string $name, Request $request): Response
    {
        $params = $request->post();

        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $id = $this->manager->save($name, $params, $tenantId, $siteId);
            return $this->success(['id' => $id], 'Data saved successfully');
        } catch (HttpResponseException $e) {
            // 预防性接线（详见类注释）：运行环境禁止运行时 DDL 时，SchemaBuilder 抛的
            // HttpResponseException 自身 message 为空，不能让客户端只看到「没有原因的错误」。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (ForbiddenException $e) {
            // 保持原样：本异常自带显式 message 与 2002 业务码，语义比通用分支更精确
            return $this->error($e->getMessage(), ErrorCode::FORBIDDEN, [], 403);
        } catch (\Exception $e) {
            // message 非空时与改造前逐字节相同；仅在为空时回落到异常类名
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /**
     * Delete form data | 删除表单数据
     *
     * @param string $formName Form name | 表单名称
     * @param int $id Data ID | 数据ID
     * @param Request $request
     * @return Response
     */
    public function delete(string $name, int $id, Request $request): Response
    {
        // Get tenant and site ID from request context | 从请求上下文获取租户和站点ID
        $tenantId = $request->tenantId();
        $siteId = $request->siteId();

        try {
            $deleted = $this->manager->delete($name, $id, $tenantId, $siteId);

            if (!$deleted) {
                return $this->notFound('Data not found or delete failed');
            }

            return $this->success(null, 'Data deleted successfully');
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
