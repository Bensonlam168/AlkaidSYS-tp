<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use app\controller\lowcode\Concerns\RuntimeDdlDeniedResponses;
use Domain\Lowcode\Collection\Model\Relationship;
use Domain\Lowcode\Collection\Enum\RelationType;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\RelationshipManager;
use think\App;
use think\exception\HttpResponseException;
use think\Response;

/**
 * Relationship API Controller | 关系API控制器
 *
 * RESTful API for Relationship management.
 * 关系管理的RESTful API。
 *
 * ## 路由变量与动作参数必须同名（缺陷 #2 修复时暴露）
 *
 * ThinkPHP 的 `Dispatch::bindParams()` 按**参数名**注入路由变量：
 * 路由 `DELETE v1/lowcode/collections/:name/relationships/:relationship` 提供的是
 * `name` / `relationship`，而本类原先的形参叫 `$collectionName` / `$relationshipName`，
 * 名字对不上 → 框架抛 `InvalidArgumentException: method param miss:collectionName`（HTTP 500）。
 * 也就是说这条路由在「被父级 `:name` 遮蔽」期间从未真正跑通过，遮蔽修好后立刻暴露成 500。
 *
 * 修法选「改形参名」而不是「改路由变量名」：路由（及其自动生成的
 * `docs/technical-specs/api/route-reference.md`）是对外契约，URL 形状不应因此改变；
 * 且同一模块里 `CollectionController` / `FormSchemaController` 的形参本来就叫 `$name`。
 *
 * ## 运行时 DDL 闸门与空 message（缺陷 B 的同类修复）
 *
 * 本类的两个动作都可能真跑 DDL，因而都可能撞上 `SchemaBuilder::assertCanRunDDL()`：
 *   - `save`   → `RelationshipManager::addRelationship()` → 建中间表（`create_table`）
 *   - `delete` → `RelationshipManager::removeRelationship()` → 删中间表（`drop_table`）
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
class RelationshipController extends ApiController
{
    use RuntimeDdlDeniedResponses;

    protected CollectionManager $collectionManager;
    protected RelationshipManager $relationshipManager;

    /**
     * Constructor | 构造函数
     */
    public function __construct(
        App $app,
        CollectionManager $collectionManager,
        RelationshipManager $relationshipManager
    ) {
        parent::__construct($app);
        $this->collectionManager = $collectionManager;
        $this->relationshipManager = $relationshipManager;
    }

    /**
     * Add relationship to collection | 添加关系到Collection
     *
     * POST /v1/lowcode/collections/:name/relationships
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
            'type' => 'require|in:' . implode(',', RelationType::all()),
            'target_collection' => 'require|max:100',
        ]);

        if ($validation !== true) {
            return $this->error($validation);
        }

        try {
            $tenantId = $this->request->tenantId();
            // 站点维度只作为事件 payload 的可选元数据往下传（RelationshipManager 不做站点过滤），
            // 因此这里用可空版本：没带 X-Site-ID 就如实传 null，不伪造「显式站点 0」。
            $siteId = method_exists($this->request, 'siteIdOrNull') ? $this->request->siteIdOrNull() : null;

            $collection = $this->collectionManager->get($name, $tenantId);

            if (!$collection) {
                // Use unified API response format for not found | 使用统一未找到响应格式
                return $this->notFound('Collection not found');
            }

            // Create relationship | 创建关系
            $relationship = new Relationship(
                $collection->getId(),
                $data['name'],
                [
                    'type' => $data['type'],
                    'target_collection' => $data['target_collection'],
                    'foreign_key' => $data['foreign_key'] ?? null,
                    'local_key' => $data['local_key'] ?? 'id',
                    'options' => $data['options'] ?? [],
                ]
            );

            // Add relationship to collection (tenant scoped) | 在当前租户范围内为Collection添加关系
            $this->relationshipManager->addRelationship($name, $relationship, $tenantId, $siteId);

            return $this->success($relationship->toArray(), 'Relationship added successfully');
        } catch (HttpResponseException $e) {
            // belongs_to_many 关系会真建中间表（create_table）。运行环境不允许运行时 DDL 时，
            // SchemaBuilder 抛的 HttpResponseException 自身 message 为空，
            // 不能让客户端只看到「没有原因的错误」——统一走共享 trait 的可读响应。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }

    /**
     * Remove relationship from collection | 从Collection移除关系
     *
     * DELETE /v1/lowcode/collections/:name/relationships/:relationship
     *
     * @param string $name Collection name | Collection名称（与路由变量 `:name` 同名）
     * @param string $relationship Relationship name | 关系名称（与路由变量 `:relationship` 同名）
     * @return Response
     */
    public function delete(string $name, string $relationship): Response
    {
        try {
            $tenantId = $this->request->tenantId();
            // 同上：仅事件元数据，允许为 null（未提供站点维度）
            $siteId = method_exists($this->request, 'siteIdOrNull') ? $this->request->siteIdOrNull() : null;

            $dropPivotTable = (bool)$this->request->delete('drop_pivot_table', true);
            $this->relationshipManager->removeRelationship(
                $name,
                $relationship,
                $dropPivotTable,
                $tenantId,
                $siteId
            );

            return $this->success(null, 'Relationship removed successfully');
        } catch (HttpResponseException $e) {
            // 删中间表会真跑 DDL（drop_table），同 save()：拦截原因必须回传给调用方。
            return $this->runtimeDdlDeniedResponse($e);
        } catch (\Exception $e) {
            return $this->error($this->describeExceptionMessage($e));
        }
    }
}
