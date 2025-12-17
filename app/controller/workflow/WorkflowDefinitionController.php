<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use think\App;
use think\Request;
use think\Response;
use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use Alkaid\Workflow\Engine\Definition\WorkflowDefinition;
use Alkaid\Workflow\Engine\Definition\WorkflowValidator;
use Alkaid\Workflow\Engine\Definition\Interfaces\WorkflowDefinitionInterface;

/**
 * Workflow Definition API Controller | 工作流定义 API 控制器
 *
 * RESTful API for workflow definition management.
 * 工作流定义管理的 RESTful API。
 *
 * Endpoints:
 * - GET    /workflow/definitions         - List definitions | 列出定义
 * - GET    /workflow/definitions/{id}    - Get definition | 获取定义
 * - POST   /workflow/definitions         - Create definition | 创建定义
 * - PUT    /workflow/definitions/{id}    - Update definition | 更新定义
 * - DELETE /workflow/definitions/{id}    - Delete definition | 删除定义
 * - POST   /workflow/definitions/{id}/publish   - Publish definition | 发布定义
 * - POST   /workflow/definitions/{id}/archive   - Archive definition | 归档定义
 * - POST   /workflow/definitions/{id}/validate  - Validate definition | 验证定义
 * - POST   /workflow/definitions/{id}/clone     - Clone definition | 克隆定义
 *
 * @package app\controller\workflow
 */
class WorkflowDefinitionController extends ApiController
{
    /**
     * In-memory storage for demo (replace with repository in production)
     * 内存存储用于演示（生产环境应使用Repository）
     *
     * @var array<string, WorkflowDefinition>
     */
    protected static array $definitions = [];

    /**
     * List workflow definitions | 列出工作流定义
     *
     * GET /workflow/definitions
     *
     * Query Parameters:
     * - page: int (default: 1)
     * - page_size: int (default: 20, max: 100)
     * - status: string (draft|published|archived)
     * - type: string (business|approval|automation|integration)
     * - name: string (search by name)
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        // Get query parameters
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);

        // Validate pagination
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Get tenant ID from request
        $tenantId = $request->tenantId ?? null;

        // Build filter criteria
        $filters = [];
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['type'])) {
            $filters['type'] = $params['type'];
        }
        if (!empty($params['name'])) {
            $filters['name'] = $params['name'];
        }

        // Get definitions (in production, this would be a database query)
        $allDefinitions = array_values(self::$definitions);

        // Apply tenant filter
        if ($tenantId !== null) {
            $allDefinitions = array_filter($allDefinitions, function ($def) use ($tenantId) {
                return $def->getTenantId() === $tenantId || $def->getTenantId() === null;
            });
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $allDefinitions = array_filter($allDefinitions, function ($def) use ($filters) {
                return $def->getStatus() === $filters['status'];
            });
        }

        // Apply type filter
        if (!empty($filters['type'])) {
            $allDefinitions = array_filter($allDefinitions, function ($def) use ($filters) {
                return $def->getType() === $filters['type'];
            });
        }

        // Apply name filter (partial match)
        if (!empty($filters['name'])) {
            $allDefinitions = array_filter($allDefinitions, function ($def) use ($filters) {
                return stripos($def->getName(), $filters['name']) !== false ||
                       stripos($def->getDisplayName(), $filters['name']) !== false;
            });
        }

        // Re-index array
        $allDefinitions = array_values($allDefinitions);
        $total = count($allDefinitions);

        // Paginate
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($allDefinitions, $offset, $pageSize);

        // Transform to array format
        $list = array_map(fn($def) => $this->transformDefinitionToArray($def), $list);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * Get workflow definition details | 获取工作流定义详情
     *
     * GET /workflow/definitions/{id}
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function read(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $data = $this->transformDefinitionToArray($definition, true);

        return $this->success($data);
    }

    /**
     * Create workflow definition | 创建工作流定义
     *
     * POST /workflow/definitions
     *
     * Request Body:
     * {
     *   "name": "my_workflow",
     *   "display_name": "My Workflow",
     *   "description": "Description",
     *   "type": "approval",
     *   "nodes": [...],
     *   "connections": [...],
     *   "config": {...},
     *   "metadata": {...}
     * }
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        $data = $request->post();

        // Validate required fields
        $errors = $this->validateDefinitionData($data);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        // Get tenant ID
        $tenantId = $request->tenantId ?? null;

        try {
            // Create definition using builder
            $builder = WorkflowBuilder::create($data['name'], [
                'id' => $data['id'] ?? null,
                'tenant_id' => $tenantId,
            ]);

            // Set basic properties
            if (!empty($data['display_name'])) {
                $builder->displayName($data['display_name']);
            }
            if (!empty($data['description'])) {
                $builder->description($data['description']);
            }
            if (!empty($data['type'])) {
                $builder->type($data['type']);
            }
            if (!empty($data['config'])) {
                $builder->config($data['config']);
            }
            if (!empty($data['metadata'])) {
                foreach ($data['metadata'] as $key => $value) {
                    $builder->meta($key, $value);
                }
            }

            // Build nodes and connections if provided in raw format
            if (!empty($data['nodes'])) {
                $this->buildNodesFromData($builder, $data['nodes'], $data['connections'] ?? []);
            }

            // Get definition (skip validation for draft)
            $definition = $builder->getDefinition();

            // Store definition
            self::$definitions[$definition->getId()] = $definition;

            return $this->success(
                $this->transformDefinitionToArray($definition),
                $this->langService->trans('workflow.definition_created'),
                0,
                201
            );
        } catch (\Exception $e) {
            return $this->error(
                $this->langService->trans('workflow.creation_failed') . ': ' . $e->getMessage(),
                500,
                [],
                500
            );
        }
    }

    /**
     * Update workflow definition | 更新工作流定义
     *
     * PUT /workflow/definitions/{id}
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function update(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        // Cannot update published definitions
        if ($definition->getStatus() === WorkflowDefinitionInterface::STATUS_PUBLISHED) {
            return $this->error(
                $this->langService->trans('workflow.cannot_update_published'),
                400,
                [],
                400
            );
        }

        $data = $request->put();

        try {
            // Update properties
            if (isset($data['display_name'])) {
                $definition->setDisplayName($data['display_name']);
            }
            if (isset($data['description'])) {
                $definition->setDescription($data['description']);
            }
            if (isset($data['type'])) {
                $definition->setType($data['type']);
            }
            if (isset($data['config'])) {
                $definition->setConfig($data['config']);
            }
            if (isset($data['metadata'])) {
                foreach ($data['metadata'] as $key => $value) {
                    $definition->setMeta($key, $value);
                }
            }

            // Update timestamp
            $definition->setUpdatedAt(new \DateTimeImmutable());

            return $this->success(
                $this->transformDefinitionToArray($definition),
                $this->langService->trans('workflow.definition_updated')
            );
        } catch (\Exception $e) {
            return $this->error(
                $this->langService->trans('workflow.update_failed') . ': ' . $e->getMessage(),
                500,
                [],
                500
            );
        }
    }

    /**
     * Delete workflow definition | 删除工作流定义
     *
     * DELETE /workflow/definitions/{id}
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function delete(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        // Cannot delete published definitions (must archive first)
        if ($definition->getStatus() === WorkflowDefinitionInterface::STATUS_PUBLISHED) {
            return $this->error(
                $this->langService->trans('workflow.cannot_delete_published'),
                400,
                [],
                400
            );
        }

        unset(self::$definitions[$id]);

        return $this->success(null, $this->langService->trans('workflow.definition_deleted'));
    }

    /**
     * Publish workflow definition | 发布工作流定义
     *
     * POST /workflow/definitions/{id}/publish
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function publish(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        // Validate before publishing
        $validator = new WorkflowValidator();
        $validationResult = $validator->validate($definition);

        if (!$validationResult->isValid()) {
            return $this->error(
                $this->langService->trans('workflow.validation_failed'),
                400,
                ['validation_errors' => $validationResult->getErrors()],
                400
            );
        }

        // Publish the definition
        $definition->publish();

        return $this->success(
            $this->transformDefinitionToArray($definition),
            $this->langService->trans('workflow.definition_published')
        );
    }

    /**
     * Archive workflow definition | 归档工作流定义
     *
     * POST /workflow/definitions/{id}/archive
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function archive(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $definition->archive();

        return $this->success(
            $this->transformDefinitionToArray($definition),
            $this->langService->trans('workflow.definition_archived')
        );
    }

    /**
     * Validate workflow definition | 验证工作流定义
     *
     * POST /workflow/definitions/{id}/validate
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function validate(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $validator = new WorkflowValidator();
        $result = $validator->validate($definition);

        return $this->success([
            'valid' => $result->isValid(),
            'errors' => $result->getErrors(),
            'warnings' => $result->getWarnings(),
            'info' => $result->getInfo(),
        ]);
    }

    /**
     * Clone workflow definition | 克隆工作流定义
     *
     * POST /workflow/definitions/{id}/clone
     *
     * Request Body:
     * {
     *   "name": "new_workflow_name",
     *   "display_name": "New Workflow Name"
     * }
     *
     * @param string $id Definition ID
     * @param Request $request
     * @return Response
     */
    public function clone(string $id, Request $request): Response
    {
        $definition = self::$definitions[$id] ?? null;

        if ($definition === null) {
            return $this->notFound($this->langService->trans('workflow.definition_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId ?? null;
        if ($tenantId !== null && $definition->getTenantId() !== null && $definition->getTenantId() !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $data = $request->post();

        // Clone the definition
        $clonedData = $definition->toArray();
        $clonedData['id'] = 'wf_' . uniqid();
        $clonedData['name'] = $data['name'] ?? $definition->getName() . '_copy';
        $clonedData['display_name'] = $data['display_name'] ?? $definition->getDisplayName() . ' (Copy)';
        $clonedData['status'] = WorkflowDefinitionInterface::STATUS_DRAFT;
        $clonedData['version'] = 1;
        $clonedData['created_at'] = (new \DateTimeImmutable())->format('c');
        $clonedData['updated_at'] = $clonedData['created_at'];
        $clonedData['published_at'] = null;

        // Create cloned definition
        $cloned = WorkflowDefinition::fromArray($clonedData);

        // Store
        self::$definitions[$cloned->getId()] = $cloned;

        return $this->success(
            $this->transformDefinitionToArray($cloned),
            $this->langService->trans('workflow.definition_cloned'),
            0,
            201
        );
    }

    /**
     * Transform definition to array | 转换定义为数组
     *
     * @param WorkflowDefinition $definition
     * @param bool $includeDetails Include nodes and connections
     * @return array
     */
    protected function transformDefinitionToArray(WorkflowDefinition $definition, bool $includeDetails = false): array
    {
        $data = [
            'id' => $definition->getId(),
            'name' => $definition->getName(),
            'display_name' => $definition->getDisplayName(),
            'description' => $definition->getDescription(),
            'type' => $definition->getType(),
            'status' => $definition->getStatus(),
            'version' => $definition->getVersion(),
            'tenant_id' => $definition->getTenantId(),
            'created_at' => $definition->getCreatedAt()?->format('c'),
            'updated_at' => $definition->getUpdatedAt()?->format('c'),
            'published_at' => $definition->getPublishedAt()?->format('c'),
        ];

        if ($includeDetails) {
            $data['nodes'] = array_map(fn($node) => $node->toArray(), $definition->getNodes());
            $data['connections'] = array_map(fn($conn) => $conn->toArray(), $definition->getConnections());
            $data['config'] = $definition->getConfig();
            $data['metadata'] = $definition->getMetadata();
        }

        return $data;
    }

    /**
     * Validate definition data | 验证定义数据
     *
     * @param array $data
     * @return array Validation errors
     */
    protected function validateDefinitionData(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = $this->langService->trans('workflow.name_required');
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $data['name'])) {
            $errors['name'] = $this->langService->trans('workflow.invalid_name_format');
        }

        if (!empty($data['type'])) {
            $validTypes = [
                WorkflowDefinitionInterface::TYPE_BUSINESS,
                WorkflowDefinitionInterface::TYPE_APPROVAL,
                WorkflowDefinitionInterface::TYPE_AUTOMATION,
                WorkflowDefinitionInterface::TYPE_INTEGRATION,
            ];
            if (!in_array($data['type'], $validTypes, true)) {
                $errors['type'] = $this->langService->trans('workflow.invalid_type');
            }
        }

        return $errors;
    }

    /**
     * Build nodes from data | 从数据构建节点
     *
     * @param WorkflowBuilder $builder
     * @param array $nodes
     * @param array $connections
     * @return void
     */
    protected function buildNodesFromData(WorkflowBuilder $builder, array $nodes, array $connections): void
    {
        // This is a simplified implementation
        // In production, this would parse the full node/connection structure
        foreach ($nodes as $nodeData) {
            $type = $nodeData['type'] ?? 'task';
            $name = $nodeData['name'] ?? 'node_' . uniqid();
            $config = $nodeData['config'] ?? [];

            switch ($type) {
                case 'start':
                    $builder->start($name, $config);
                    break;
                case 'end':
                    $builder->end($name, $config);
                    break;
                case 'task':
                    $subType = $nodeData['sub_type'] ?? 'user';
                    $builder->task($name, $subType, $config);
                    break;
                case 'gateway':
                    $subType = $nodeData['sub_type'] ?? 'exclusive';
                    $builder->gateway($name, $subType, $config);
                    break;
            }
        }
    }
}
