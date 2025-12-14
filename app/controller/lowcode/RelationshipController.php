<?php

declare(strict_types=1);

namespace app\controller\lowcode;

use app\controller\ApiController;
use Domain\Lowcode\Collection\Model\Relationship;
use Domain\Lowcode\Collection\Enum\RelationType;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\RelationshipManager;
use think\App;
use think\Response;

/**
 * Relationship API Controller | 关系API控制器
 *
 * RESTful API for Relationship management.
 * 关系管理的RESTful API。
 *
 * @package app\controller\lowcode
 */
class RelationshipController extends ApiController
{
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
     * POST /api/lowcode/collections/{collectionName}/relationships
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
            'type' => 'require|in:' . implode(',', RelationType::all()),
            'target_collection' => 'require|max:100',
        ]);

        if ($validation !== true) {
            return $this->error($validation);
        }

        try {
            $collection = $this->collectionManager->get($collectionName);

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

            // Add relationship to collection | 添加关系到Collection
            $this->relationshipManager->addRelationship($collectionName, $relationship);

            return $this->success($relationship->toArray(), 'Relationship added successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Remove relationship from collection | 从Collection移除关系
     *
     * DELETE /api/lowcode/collections/{collectionName}/relationships/{relationshipName}
     *
     * @param string $collectionName Collection name | Collection名称
     * @param string $relationshipName Relationship name | 关系名称
     * @return Response
     */
    public function delete(string $collectionName, string $relationshipName): Response
    {
        try {
            $dropPivotTable = (bool)$this->request->delete('drop_pivot_table', true);
            $this->relationshipManager->removeRelationship(
                $collectionName,
                $relationshipName,
                $dropPivotTable
            );

            return $this->success(null, 'Relationship removed successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
