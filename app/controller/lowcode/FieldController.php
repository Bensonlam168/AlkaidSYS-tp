<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\FieldManager;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use think\Response;

/**
 * Field API Controller | 字段API控制器
 *
 * RESTful API for Field management.
 * 字段管理的RESTful API。
 *
 * @package app\controller\lowcode
 */
class FieldController extends ApiController
{
    protected CollectionManager $collectionManager;
    protected FieldManager $fieldManager;

    /**
     * Constructor | 构造函数
     */
    public function __construct(
        CollectionManager $collectionManager,
        FieldManager $fieldManager
    ) {
        $this->collectionManager = $collectionManager;
        $this->fieldManager = $fieldManager;
    }

    /**
     * Add field to collection | 添加字段到Collection
     *
     * POST /api/lowcode/collections/{collectionName}/fields
     *
     * @param string $collectionName Collection name | Collection名称
     * @return Response
     */
    public function save(string $collectionName): Response
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
            $this->fieldManager->addField($collectionName, $field, $tenantId, $siteId);

            return $this->success($field->toArray(), 'Field added successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Remove field from collection | 从Collection移除字段
     *
     * DELETE /api/lowcode/collections/{collectionName}/fields/{fieldName}
     *
     * @param string $collectionName Collection name | Collection名称
     * @param string $fieldName Field name | 字段名称
     * @return Response
     */
    public function delete(string $collectionName, string $fieldName): Response
    {
        try {
            $tenantId = $this->request->tenantId();
            // 同上：仅事件元数据，允许为 null（未提供站点维度）
            $siteId = method_exists($this->request, 'siteIdOrNull') ? $this->request->siteIdOrNull() : null;

            $this->fieldManager->removeField($collectionName, $fieldName, $tenantId, $siteId);

            return $this->success(null, 'Field removed successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
