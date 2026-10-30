<?php

declare(strict_types=1);

namespace app\controller\workflow;

use app\controller\ApiController;
use think\App;
use think\Request;
use think\Response;
use Alkaid\Workflow\Engine\Triggers\TriggerManager;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;

/**
 * Workflow Trigger API Controller | 工作流触发器 API 控制器
 *
 * RESTful API for workflow trigger management.
 * 工作流触发器管理的 RESTful API。
 *
 * Endpoints:
 * - GET    /workflow/triggers                      - List triggers | 列出触发器
 * - GET    /workflow/triggers/types                - Get available trigger types | 获取可用触发器类型
 * - GET    /workflow/triggers/{id}                 - Get trigger details | 获取触发器详情
 * - POST   /workflow/triggers                      - Create trigger | 创建触发器
 * - PUT    /workflow/triggers/{id}                 - Update trigger | 更新触发器
 * - DELETE /workflow/triggers/{id}                 - Delete trigger | 删除触发器
 * - POST   /workflow/triggers/{id}/enable          - Enable trigger | 启用触发器
 * - POST   /workflow/triggers/{id}/disable         - Disable trigger | 禁用触发器
 * - POST   /workflow/triggers/{id}/test            - Test trigger | 测试触发器
 * - POST   /workflow/triggers/webhook/{key}        - Webhook endpoint | Webhook 端点
 * - GET    /workflow/triggers/{id}/logs            - Get trigger logs | 获取触发器日志
 *
 * @package app\controller\workflow
 */
class WorkflowTriggerController extends ApiController
{
    /**
     * Trigger status constants
     */
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    /**
     * Trigger type constants
     */
    public const TYPE_MANUAL = 'manual';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_SCHEDULE = 'schedule';
    public const TYPE_DATA_EVENT = 'data_event';

    /**
     * In-memory storage for demo
     *
     * @var array
     */
    protected static array $triggers = [];

    /**
     * Trigger logs storage
     *
     * @var array
     */
    protected static array $triggerLogs = [];

    /**
     * TriggerManager instance
     *
     * @var TriggerManager|null
     */
    protected ?TriggerManager $triggerManager = null;

    /**
     * Constructor
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->triggerManager = new TriggerManager();
    }

    /**
     * List triggers | 列出触发器
     *
     * GET /workflow/triggers
     *
     * Query Parameters:
     * - page: int
     * - page_size: int
     * - definition_id: string (filter by workflow definition)
     * - type: string (manual|webhook|schedule|data_event)
     * - status: string (enabled|disabled)
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);

        // Validate pagination
        $paginationValidation = $this->validatePagination($page, $pageSize);
        if (!$paginationValidation['valid']) {
            return $this->validationError($paginationValidation['errors']);
        }

        // Get tenant ID
        $tenantId = $request->tenantId();

        // Filter triggers
        $allTriggers = array_values(self::$triggers);

        // Apply tenant filter
        // 缺陷 P3-A：租户过滤必须严格匹配。DB 层 `workflow_triggers.tenant_id` 是 NOT NULL，
        // 不存在「全局触发器」，原实现的 `|| (... ?? null) === null` 是死分支，
        // 却会让「缺少 tenant_id 的脏数据」对所有租户可见（fail-open）。
        // 注：`app\Request::tenantId()` 返回 int（恒 >= 1），故不再需要 null 包裹判断。
        $allTriggers = array_filter($allTriggers, fn($trigger) => ($trigger['tenant_id'] ?? null) === $tenantId);

        // Apply type filter
        if (!empty($params['type'])) {
            $allTriggers = array_filter($allTriggers, fn($trigger) =>
                $trigger['type'] === $params['type']
            );
        }

        // Apply status filter
        if (!empty($params['status'])) {
            $allTriggers = array_filter($allTriggers, fn($trigger) =>
                $trigger['status'] === $params['status']
            );
        }

        // Apply definition filter
        if (!empty($params['definition_id'])) {
            $allTriggers = array_filter($allTriggers, fn($trigger) =>
                $trigger['definition_id'] === $params['definition_id']
            );
        }

        // Re-index and sort
        $allTriggers = array_values($allTriggers);
        usort($allTriggers, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        $total = count($allTriggers);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($allTriggers, $offset, $pageSize);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * Get available trigger types | 获取可用触发器类型
     *
     * GET /workflow/triggers/types
     *
     * @param Request $request
     * @return Response
     */
    public function types(Request $request): Response
    {
        $types = [
            [
                'type' => self::TYPE_MANUAL,
                'name' => $this->langService->trans('workflow.trigger_type_manual'),
                'description' => $this->langService->trans('workflow.trigger_type_manual_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'allowed_users' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'allowed_roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'require_confirmation' => ['type' => 'boolean', 'default' => false],
                    ],
                ],
            ],
            [
                'type' => self::TYPE_WEBHOOK,
                'name' => $this->langService->trans('workflow.trigger_type_webhook'),
                'description' => $this->langService->trans('workflow.trigger_type_webhook_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'secret' => ['type' => 'string'],
                        'allowed_ips' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'http_methods' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['POST']],
                        'payload_mapping' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'type' => self::TYPE_SCHEDULE,
                'name' => $this->langService->trans('workflow.trigger_type_schedule'),
                'description' => $this->langService->trans('workflow.trigger_type_schedule_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cron_expression' => ['type' => 'string'],
                        'timezone' => ['type' => 'string', 'default' => 'UTC'],
                        'start_date' => ['type' => 'string', 'format' => 'date-time'],
                        'end_date' => ['type' => 'string', 'format' => 'date-time'],
                        'max_executions' => ['type' => 'integer'],
                    ],
                    'required' => ['cron_expression'],
                ],
            ],
            [
                'type' => self::TYPE_DATA_EVENT,
                'name' => $this->langService->trans('workflow.trigger_type_data_event'),
                'description' => $this->langService->trans('workflow.trigger_type_data_event_desc'),
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'collection' => ['type' => 'string'],
                        'event_type' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                        'field_conditions' => ['type' => 'array'],
                        'filter_expression' => ['type' => 'string'],
                    ],
                    'required' => ['collection', 'event_type'],
                ],
            ],
        ];

        return $this->success([
            'types' => $types,
            'count' => count($types),
        ]);
    }

    /**
     * Get trigger details | 获取触发器详情
     *
     * GET /workflow/triggers/{id}
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function read(string $id, Request $request): Response
    {
        $trigger = self::$triggers[$id] ?? null;

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId();
        if ($tenantId !== null && isset($trigger['tenant_id']) && $trigger['tenant_id'] !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        // Include recent logs
        $trigger['recent_logs'] = array_slice(self::$triggerLogs[$id] ?? [], -10);

        return $this->success($trigger);
    }

    /**
     * Create trigger | 创建触发器
     *
     * POST /workflow/triggers
     *
     * Request Body:
     * {
     *   "definition_id": "wf_xxx",
     *   "name": "My Trigger",
     *   "type": "webhook|manual|schedule|data_event",
     *   "config": {...},
     *   "enabled": true
     * }
     *
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        $data = $request->post();

        // Validate required fields
        $errors = $this->validateTriggerData($data);
        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        // Get tenant ID
        $tenantId = $request->tenantId();
        $userId = $request->userId() ?? 'system';

        // Generate trigger ID and webhook key
        $triggerId = 'trig_' . uniqid();
        $webhookKey = bin2hex(random_bytes(16));

        // Create trigger
        $trigger = [
            'id' => $triggerId,
            'definition_id' => $data['definition_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'type' => $data['type'],
            'config' => $data['config'] ?? [],
            'status' => ($data['enabled'] ?? true) ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'webhook_key' => $data['type'] === self::TYPE_WEBHOOK ? $webhookKey : null,
            'webhook_url' => $data['type'] === self::TYPE_WEBHOOK
                ? "/workflow/triggers/webhook/{$webhookKey}"
                : null,
            'execution_count' => 0,
            'last_execution_at' => null,
            'last_execution_status' => null,
            'created_by' => $userId,
            'created_at' => (new \DateTimeImmutable())->format('c'),
            'updated_at' => (new \DateTimeImmutable())->format('c'),
            'tenant_id' => $tenantId,
        ];

        // Store trigger
        self::$triggers[$triggerId] = $trigger;

        // Add log entry
        $this->addTriggerLog($triggerId, 'CREATED', $userId, ['config' => $data['config'] ?? []]);

        return $this->success($trigger, $this->langService->trans('workflow.trigger_created'), 0, 201);
    }

    /**
     * Update trigger | 更新触发器
     *
     * PUT /workflow/triggers/{id}
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function update(string $id, Request $request): Response
    {
        $trigger = self::$triggers[$id] ?? null;

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId();
        if ($tenantId !== null && isset($trigger['tenant_id']) && $trigger['tenant_id'] !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $data = $request->put();
        $userId = $request->userId() ?? 'system';

        // Update allowed fields
        if (isset($data['name'])) {
            $trigger['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $trigger['description'] = $data['description'];
        }
        if (isset($data['config'])) {
            $trigger['config'] = $data['config'];
        }
        if (isset($data['enabled'])) {
            $trigger['status'] = $data['enabled'] ? self::STATUS_ENABLED : self::STATUS_DISABLED;
        }

        $trigger['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$triggers[$id] = $trigger;

        // Add log entry
        $this->addTriggerLog($id, 'UPDATED', $userId, ['changes' => $data]);

        return $this->success($trigger, $this->langService->trans('workflow.trigger_updated'));
    }

    /**
     * Delete trigger | 删除触发器
     *
     * DELETE /workflow/triggers/{id}
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function delete(string $id, Request $request): Response
    {
        $trigger = self::$triggers[$id] ?? null;

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId();
        if ($tenantId !== null && isset($trigger['tenant_id']) && $trigger['tenant_id'] !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        unset(self::$triggers[$id]);
        unset(self::$triggerLogs[$id]);

        return $this->success(null, $this->langService->trans('workflow.trigger_deleted'));
    }

    /**
     * Enable trigger | 启用触发器
     *
     * POST /workflow/triggers/{id}/enable
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function enable(string $id, Request $request): Response
    {
        return $this->setTriggerStatus($id, $request, true);
    }

    /**
     * Disable trigger | 禁用触发器
     *
     * POST /workflow/triggers/{id}/disable
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function disable(string $id, Request $request): Response
    {
        return $this->setTriggerStatus($id, $request, false);
    }

    /**
     * Test trigger | 测试触发器
     *
     * POST /workflow/triggers/{id}/test
     *
     * Request Body:
     * {
     *   "test_data": {...},
     *   "dry_run": true
     * }
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function test(string $id, Request $request): Response
    {
        $trigger = self::$triggers[$id] ?? null;

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId();
        if ($tenantId !== null && isset($trigger['tenant_id']) && $trigger['tenant_id'] !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $data = $request->post();
        $testData = $data['test_data'] ?? [];
        $dryRun = $data['dry_run'] ?? true;
        $userId = $request->userId() ?? 'system';

        try {
            // Create test context
            $context = new TriggerContext(
                $trigger['type'],
                $trigger['definition_id'],
                $testData,
                $tenantId,
                $userId
            );

            // Validate the trigger configuration
            $triggerInstance = $this->triggerManager->getTriggerType($trigger['type']);
            if ($triggerInstance === null) {
                return $this->error(
                    $this->langService->trans('workflow.unknown_trigger_type'),
                    400,
                    ['type' => $trigger['type']],
                    400
                );
            }

            // Validate configuration
            $validationResult = $triggerInstance->validateConfig($trigger['config']);
            if (!$validationResult['valid']) {
                return $this->error(
                    $this->langService->trans('workflow.trigger_config_invalid'),
                    400,
                    ['validation_errors' => $validationResult['errors']],
                    400
                );
            }

            // Check conditions
            $conditionsMet = $triggerInstance->checkConditions($context, $trigger['config']);

            // Build test result
            $result = [
                'trigger_id' => $id,
                'trigger_type' => $trigger['type'],
                'test_data' => $testData,
                'dry_run' => $dryRun,
                'validation' => [
                    'config_valid' => true,
                    'conditions_met' => $conditionsMet,
                ],
                'would_trigger' => $conditionsMet,
                'message' => $conditionsMet
                    ? $this->langService->trans('workflow.trigger_test_would_fire')
                    : $this->langService->trans('workflow.trigger_test_would_not_fire'),
            ];

            // If not dry run and conditions met, actually trigger
            if (!$dryRun && $conditionsMet && $trigger['status'] === self::STATUS_ENABLED) {
                $result['triggered'] = true;
                $result['instance_id'] = 'inst_test_' . uniqid();
                $result['message'] = $this->langService->trans('workflow.trigger_test_fired');

                // Update trigger stats
                $trigger['execution_count']++;
                $trigger['last_execution_at'] = (new \DateTimeImmutable())->format('c');
                $trigger['last_execution_status'] = 'success';
                self::$triggers[$id] = $trigger;

                // Add log entry
                $this->addTriggerLog($id, 'EXECUTED', $userId, [
                    'test_mode' => true,
                    'data' => $testData,
                ]);
            }

            // Add log entry for test
            $this->addTriggerLog($id, 'TESTED', $userId, [
                'dry_run' => $dryRun,
                'conditions_met' => $conditionsMet,
            ]);

            return $this->success($result);
        } catch (\Exception $e) {
            return $this->error(
                $this->langService->trans('workflow.trigger_test_failed') . ': ' . $e->getMessage(),
                500,
                [],
                500
            );
        }
    }

    /**
     * Webhook endpoint | Webhook 端点
     *
     * POST /workflow/triggers/webhook/{key}
     *
     * @param string $key Webhook key
     * @param Request $request
     * @return Response
     */
    public function webhook(string $key, Request $request): Response
    {
        // Find trigger by webhook key
        $trigger = null;
        foreach (self::$triggers as $t) {
            if (($t['webhook_key'] ?? null) === $key) {
                $trigger = $t;
                break;
            }
        }

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.webhook_not_found'));
        }

        // Check if enabled
        if ($trigger['status'] !== self::STATUS_ENABLED) {
            return $this->error(
                $this->langService->trans('workflow.trigger_disabled'),
                400,
                [],
                400
            );
        }

        // Validate webhook configuration
        $config = $trigger['config'] ?? [];

        // Check allowed methods
        $allowedMethods = $config['http_methods'] ?? ['POST'];
        if (!in_array($request->method(), $allowedMethods, true)) {
            return $this->error(
                $this->langService->trans('workflow.method_not_allowed'),
                405,
                ['allowed_methods' => $allowedMethods],
                405
            );
        }

        // Check allowed IPs
        $allowedIps = $config['allowed_ips'] ?? [];
        if (!empty($allowedIps)) {
            $clientIp = $request->ip();
            if (!in_array($clientIp, $allowedIps, true)) {
                return $this->forbidden($this->langService->trans('workflow.ip_not_allowed'));
            }
        }

        // Verify signature if secret is set
        if (!empty($config['secret'])) {
            $signature = $request->header('X-Webhook-Signature');
            $payload = $request->getContent();
            $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $config['secret']);

            if (!hash_equals($expectedSignature, $signature ?? '')) {
                return $this->error(
                    $this->langService->trans('workflow.invalid_signature'),
                    401,
                    [],
                    401
                );
            }
        }

        // Get payload
        $payload = $request->post() ?: json_decode($request->getContent(), true) ?: [];

        // Apply payload mapping if configured
        $variables = $payload;
        if (!empty($config['payload_mapping'])) {
            $variables = $this->applyPayloadMapping($payload, $config['payload_mapping']);
        }

        try {
            // Create instance (in production, this would actually start a workflow)
            $instanceId = 'inst_' . uniqid();

            // Update trigger stats
            $trigger['execution_count']++;
            $trigger['last_execution_at'] = (new \DateTimeImmutable())->format('c');
            $trigger['last_execution_status'] = 'success';
            self::$triggers[$trigger['id']] = $trigger;

            // Add log entry
            $this->addTriggerLog($trigger['id'], 'WEBHOOK_RECEIVED', 'webhook', [
                'method' => $request->method(),
                'ip' => $request->ip(),
                'payload_size' => strlen($request->getContent()),
                'instance_id' => $instanceId,
            ]);

            return $this->success([
                'triggered' => true,
                'instance_id' => $instanceId,
                'definition_id' => $trigger['definition_id'],
                'variables' => $variables,
            ], $this->langService->trans('workflow.webhook_processed'), 0, 200);
        } catch (\Exception $e) {
            // Update trigger stats for failure
            $trigger['last_execution_at'] = (new \DateTimeImmutable())->format('c');
            $trigger['last_execution_status'] = 'failed';
            self::$triggers[$trigger['id']] = $trigger;

            // Add log entry
            $this->addTriggerLog($trigger['id'], 'WEBHOOK_FAILED', 'webhook', [
                'error' => $e->getMessage(),
            ]);

            return $this->error(
                $this->langService->trans('workflow.webhook_processing_failed'),
                500,
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * Get trigger logs | 获取触发器日志
     *
     * GET /workflow/triggers/{id}/logs
     *
     * @param string $id Trigger ID
     * @param Request $request
     * @return Response
     */
    public function logs(string $id, Request $request): Response
    {
        $trigger = self::$triggers[$id] ?? null;

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId();
        if ($tenantId !== null && isset($trigger['tenant_id']) && $trigger['tenant_id'] !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $params = $request->get();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 50);

        $logs = self::$triggerLogs[$id] ?? [];
        $logs = array_reverse($logs); // Most recent first

        $total = count($logs);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($logs, $offset, $pageSize);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * Set trigger status | 设置触发器状态
     *
     * @param string $id
     * @param Request $request
     * @param bool $enabled
     * @return Response
     */
    protected function setTriggerStatus(string $id, Request $request, bool $enabled): Response
    {
        $trigger = self::$triggers[$id] ?? null;

        if ($trigger === null) {
            return $this->notFound($this->langService->trans('workflow.trigger_not_found'));
        }

        // Check tenant access
        $tenantId = $request->tenantId();
        if ($tenantId !== null && isset($trigger['tenant_id']) && $trigger['tenant_id'] !== $tenantId) {
            return $this->forbidden($this->langService->trans('workflow.access_denied'));
        }

        $userId = $request->userId() ?? 'system';
        $newStatus = $enabled ? self::STATUS_ENABLED : self::STATUS_DISABLED;

        $trigger['status'] = $newStatus;
        $trigger['updated_at'] = (new \DateTimeImmutable())->format('c');
        self::$triggers[$id] = $trigger;

        // Add log entry
        $this->addTriggerLog($id, $enabled ? 'ENABLED' : 'DISABLED', $userId, []);

        $message = $enabled
            ? $this->langService->trans('workflow.trigger_enabled')
            : $this->langService->trans('workflow.trigger_disabled');

        return $this->success($trigger, $message);
    }

    /**
     * Validate trigger data | 验证触发器数据
     *
     * @param array $data
     * @return array Validation errors
     */
    protected function validateTriggerData(array $data): array
    {
        $errors = [];

        if (empty($data['definition_id'])) {
            $errors['definition_id'] = $this->langService->trans('workflow.definition_id_required');
        }

        if (empty($data['name'])) {
            $errors['name'] = $this->langService->trans('workflow.trigger_name_required');
        }

        if (empty($data['type'])) {
            $errors['type'] = $this->langService->trans('workflow.trigger_type_required');
        } else {
            $validTypes = [self::TYPE_MANUAL, self::TYPE_WEBHOOK, self::TYPE_SCHEDULE, self::TYPE_DATA_EVENT];
            if (!in_array($data['type'], $validTypes, true)) {
                $errors['type'] = $this->langService->trans('workflow.invalid_trigger_type');
            }
        }

        // Type-specific validation
        if ($data['type'] === self::TYPE_SCHEDULE && empty($data['config']['cron_expression'])) {
            $errors['config.cron_expression'] = $this->langService->trans('workflow.cron_expression_required');
        }

        if ($data['type'] === self::TYPE_DATA_EVENT) {
            if (empty($data['config']['collection'])) {
                $errors['config.collection'] = $this->langService->trans('workflow.collection_required');
            }
            if (empty($data['config']['event_type'])) {
                $errors['config.event_type'] = $this->langService->trans('workflow.event_type_required');
            }
        }

        return $errors;
    }

    /**
     * Apply payload mapping | 应用负载映射
     *
     * @param array $payload
     * @param array $mapping
     * @return array
     */
    protected function applyPayloadMapping(array $payload, array $mapping): array
    {
        $result = [];

        foreach ($mapping as $targetKey => $sourceKey) {
            if (is_string($sourceKey)) {
                // Simple mapping: target_key => source_key
                $result[$targetKey] = $this->getNestedValue($payload, $sourceKey);
            } elseif (is_array($sourceKey)) {
                // Advanced mapping with transformation
                $value = $this->getNestedValue($payload, $sourceKey['path'] ?? '');
                if (isset($sourceKey['default']) && $value === null) {
                    $value = $sourceKey['default'];
                }
                if (isset($sourceKey['transform'])) {
                    $value = $this->transformValue($value, $sourceKey['transform']);
                }
                $result[$targetKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Get nested value from array | 从数组获取嵌套值
     *
     * @param array $array
     * @param string $path Dot-notation path
     * @return mixed
     */
    protected function getNestedValue(array $array, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Transform value | 转换值
     *
     * @param mixed $value
     * @param string $transform
     * @return mixed
     */
    protected function transformValue(mixed $value, string $transform): mixed
    {
        return match ($transform) {
            'string' => (string)$value,
            'int', 'integer' => (int)$value,
            'float' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'json' => json_encode($value),
            'uppercase' => strtoupper((string)$value),
            'lowercase' => strtolower((string)$value),
            'trim' => trim((string)$value),
            default => $value,
        };
    }

    /**
     * Add trigger log entry | 添加触发器日志记录
     *
     * @param string $triggerId
     * @param string $event
     * @param int|string $userId 操作用户ID（来自 JWT 时为 int，系统/未认证操作时为 'system'）
     * @param array $data
     * @return void
     */
    protected function addTriggerLog(string $triggerId, string $event, int|string $userId, array $data): void
    {
        if (!isset(self::$triggerLogs[$triggerId])) {
            self::$triggerLogs[$triggerId] = [];
        }

        self::$triggerLogs[$triggerId][] = [
            'id' => 'log_' . uniqid(),
            'trigger_id' => $triggerId,
            'event' => $event,
            'user_id' => $userId,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
