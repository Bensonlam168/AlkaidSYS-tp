<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\FieldManager;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use think\App;
use think\exception\HttpResponseException;
use think\Response;

/**
 * Field API Controller | 字段API控制器
 *
 * RESTful API for Field management.
 * 字段管理的RESTful API。
 *
 * ## 路由变量与动作参数必须同名（缺陷 #2 修复时暴露）
 *
 * ThinkPHP 的 `Dispatch::bindParams()` 是按**参数名**把路由变量注入动作参数的：
 * 路由 `DELETE v1/lowcode/collections/:name/fields/:field` 提供的变量是
 * `name` / `field`，而本类原先的形参叫 `$collectionName` / `$fieldName` ——
 * 名字对不上，框架直接抛 `InvalidArgumentException: method param miss:collectionName`
 * （HTTP 500）。也就是说这条路由**在被父级 `:name` 遮蔽期间从未真正跑通过**，
 * 遮蔽一修好就立刻暴露成 500。
 *
 * 修法选「改形参名」而不是「改路由变量名」：
 *   - 路由（及其自动生成的 `docs/technical-specs/api/route-reference.md`）是**对外契约**，
 *     URL 形状不应因为内部变量命名而变；
 *   - 同一个模块里 `CollectionController` / `FormSchemaController` / `FormDataController`
 *     的形参本来就叫 `$name`，改名后模块内部一致；
 *   - 形参名是纯实现细节，没有调用方依赖它（本仓库没有按命名实参调用这些动作的地方）。
 *
 * ## 另一个同一路由上的缺陷：`$this->request` 为 null
 *
 * 原构造函数没有接收 `App`，也没有调用 `parent::__construct($app)`，
 * 而 `BaseController::$request` 只在父构造函数里赋值 —— 于是
 * `$this->request->tenantId()` 会抛 `Error: Call to a member function tenantId() on null`。
 * `RelationshipController` 一直是正确写法（接收 `App` 并调父构造函数），本类补齐。
 *
 * ## 运行时 DDL 闸门与空 message（缺陷 B 的同类修复）
 *
 * 本类的两个动作都会真跑 DDL，因而都会撞上 `SchemaBuilder::assertCanRunDDL()`：
 *   - `save`   → `FieldManager::addField()` → `addColumn`（`add_column`）
 *   - `delete` → `FieldManager::removeField()` → `dropColumn`（`drop_column`）
 *
 * 闸门拦截时 `SchemaBuilder` 抛 `think\exception\HttpResponseException`。该异常
 * **继承 `RuntimeException`**（⇒ `instanceof \Exception` 为真，`catch (\Exception)` 抓得到，
 * 不会变成 500），但构造函数没有调用父类 ⇒ `getMessage()` 恒为空串。
 * 于是原来的 `catch (\Exception $e) { return $this->error($e->getMessage()); }`
 * 把「403 + 5003 + 明确原因」降级成「400 + 空 message」。
 *
 * 修法与 `CollectionController` 完全一致，实现集中在
 * `app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses` trait，本类不再自带副本。
 *
 * @package app\controller\lowcode
 */
class FieldController extends ApiController
{
    use RuntimeDdlDeniedResponses;

    protected CollectionManager $collectionManager;
    protected FieldManager $fieldManager;

    /**
     * Constructor | 构造函数
     *
     * 必须接收 `App` 并调用父构造函数：`$this->request` / `$this->langService`
     * 都由 `ApiController::__construct()` 注入，缺了它整个控制器不可用。
     */
    public function __construct(
        App $app,
        CollectionManager $collectionManager,
        FieldManager $fieldManager
    ) {
        parent::__construct($app);
        $this->collectionManager = $collectionManager;
        $this->fieldManager = $fieldManager;
    }

    /**
     * Add field to collection | 添加字段到Collection
     *
     * POST /v1/lowcode/collections/:name/fields
     *
     * @param string $name Collection name | Collection名称（与路由变量 `:name` 同名）
     * @return Response
     */
    public function save(string $name): Response
    {
        $data = $this->request->post();

        // Validate request | 验证请求
        $validation = $this->validate($data, [
            'name' => 'require|alphaDash|max:100',
            'type' => 'require|in:' . implode(',', FieldFactory::getTypes()),
        ]);

        if ($validation !== true) {
            return $this->error($validation);
        }

        try {
            $tenantId = $this->request->tenantId();
            // 站点维度只作为事件 payload 的可选元数据往下传（FieldManager 不做站点过滤），
            // 因此这里用可空版本：没带 X-Site-ID 就如实传 null，不伪造「显式站点 0」。
            $siteId = method_exists($this->request, 'siteIdOrNull') ? $this->request->siteIdOrNull() : null;

            // Create field | 创建字段
            $field = FieldFactory::create(
                $data['type'],
                $data['name'],
                $data['options'] ?? []
            );

            // Add field to collection (tenant scoped) | 在当前租户范围内为Collection添加字段
            $this->fieldManager->addField($name, $field, $tenantId, $siteId);

            return $this->success($field->toArray(), 'Field added successfully');
        } catch (HttpResponseException $e) {
            // 加列会真跑 DDL（add_column）。运行环境不允许运行时 DDL 时，
            // SchemaBuilder 抛的 HttpResponseException 自身 message 为空，
            // 不能让客户端只看到「没有原因的错误」——统一走共享 trait 的可读响应。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /**
     * Remove field from collection | 从Collection移除字段
     *
     * DELETE /v1/lowcode/collections/:name/fields/:field
     *
     * @param string $name Collection name | Collection名称（与路由变量 `:name` 同名）
     * @param string $field Field name | 字段名称（与路由变量 `:field` 同名）
     * @return Response
     */
    public function delete(string $name, string $field): Response
    {
        try {
            $tenantId = $this->request->tenantId();
            // 同上：仅事件元数据，允许为 null（未提供站点维度）
            $siteId = method_exists($this->request, 'siteIdOrNull') ? $this->request->siteIdOrNull() : null;

            $this->fieldManager->removeField($name, $field, $tenantId, $siteId);

            return $this->success(null, 'Field removed successfully');
        } catch (HttpResponseException $e) {
            // 删列会真跑 DDL（drop_column），同 save()：拦截原因必须回传给调用方。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }
}
