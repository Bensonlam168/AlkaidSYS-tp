<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Service;

use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerManager;
use Infrastructure\I18n\LanguageService;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Repository\WorkflowDefinitionRepository;
use Infrastructure\Workflow\Repository\WorkflowEventLogRepository;
use Infrastructure\Workflow\Repository\WorkflowTriggerRepository;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * 工作流触发器应用服务（触发器控制器的唯一业务入口）
 *
 * ## 分层位置
 *
 * 控制器只做「取参 → 调本服务 → 包统一响应」；本服务负责「入参校验 + 归属校验 + 调引擎 + 落库 + 留痕」；
 * 数据访问一律走 `Infrastructure\Workflow\Repository\*`。
 *
 * ## 与旧 Mock 的差异（**读之前先看这里**）
 *
 * 旧实现把触发器与「日志」都放在控制器静态数组里，并从 `config` 里取一堆**库里并不存在**的列。
 * 真库只有 `id / definition_id / tenant_id / name / type / config / is_active / created_at /
 * updated_at / created_by`，因此：
 *
 * | 对外字段 | 真库来源 | 说明 |
 * | -------- | -------- | ---- |
 * | `status`（enabled/disabled） | `is_active`（1/0） | 只有一列，两个名字是同一件事 |
 * | `description` | `config._alkaid_meta.description` | 表上没有 `description` 列 |
 * | `webhook_key` / `webhook_url` | `config._alkaid_meta.webhook_key` | 表上没有列；url 由 key 现场拼 |
 * | `execution_count` / `last_execution_*` | 从 `workflow_event_logs` **派生** | 不落计数器 |
 * | `config` | `config`（已剔除保留子键） | 对外的 `config` 只有引擎配置 |
 *
 * ## 日志为什么写 `workflow_event_logs`（而不是新表）
 *
 * 见 {@see WorkflowEventLogRepository::TRIGGER_EVENT_TYPES} 的类注释。要点：
 * `instance_id` 可空 + 表语义通用 + 执行类事件带真实实例ID + 管理类事件 `instance_id = NULL`
 * 且不进任何实例轨迹。
 *
 * ## webhook 的鉴权为什么在宿主侧做
 *
 * 引擎 `WebhookTrigger::validateSignature()` 是对 `json_encode($context->getData())` 计算 HMAC，
 * 而外部系统签的通常是**原始请求体**。两者对「字段顺序 / 空白 / 数字格式」敏感，
 * 用引擎那套校验真实回调会大量误杀。因此：
 * - 方法白名单、IP 白名单、`X-Webhook-Signature`（HMAC-SHA256 over raw body）在宿主侧校验；
 * - 引擎的 `shouldTrigger()` **不**在 webhook 路径上调用（避免二次校验给出假否定），
 *   但仍用 `TriggerManager::getTriggerType()` 确认类型已注册 —— 未注册的类型不该被放行。
 *
 * @package Infrastructure\Workflow\Service
 */
class WorkflowTriggerService
{
    /**
     * webhook 公开地址前缀（与 `route/workflow.php` 的实际路由一致）
     */
    public const WEBHOOK_URL_PREFIX = '/v1/workflow/triggers/webhook/';

    /**
     * 旧日志词汇 → 真库事件类型的映射
     *
     * 对外仍返回大写 `event`（旧契约），同时给出真实 `event_type`，让调用方能平滑迁移。
     *
     * @var array<string, string>
     */
    public const LEGACY_EVENT_NAMES = [
        'trigger_created'  => 'CREATED',
        'trigger_updated'  => 'UPDATED',
        'trigger_enabled'  => 'ENABLED',
        'trigger_disabled' => 'DISABLED',
        'trigger_tested'   => 'TESTED',
        'trigger_deleted'  => 'DELETED',
        'trigger_fired'    => 'EXECUTED',
        'trigger_failed'   => 'FAILED',
    ];

    private ?LanguageService $lang;

    private ?WorkflowTriggerRepository $triggers;

    private ?WorkflowDefinitionRepository $definitions;

    private ?WorkflowEventLogRepository $events;

    private ?WorkflowInstanceService $instances;

    private ?TriggerManager $manager;

    /**
     * @param LanguageService|null              $lang        语言服务（控制器传入同一实例；null 时惰性新建）
     * @param WorkflowTriggerRepository|null    $triggers    触发器仓储
     * @param WorkflowDefinitionRepository|null $definitions 定义仓储（创建前校验定义归属）
     * @param WorkflowEventLogRepository|null   $events      事件仓储（写入/读取触发器日志）
     * @param WorkflowInstanceService|null      $instances   实例服务（webhook / test 真启动实例）
     * @param TriggerManager|null               $manager     引擎触发器管理器（校验类型与配置）
     */
    public function __construct(
        ?LanguageService $lang = null,
        ?WorkflowTriggerRepository $triggers = null,
        ?WorkflowDefinitionRepository $definitions = null,
        ?WorkflowEventLogRepository $events = null,
        ?WorkflowInstanceService $instances = null,
        ?TriggerManager $manager = null
    ) {
        $this->lang        = $lang;
        $this->triggers    = $triggers;
        $this->definitions = $definitions;
        $this->events      = $events;
        $this->instances   = $instances;
        $this->manager     = $manager;
    }

    // =====================================================================
    // 读
    // =====================================================================

    /**
     * 触发器列表（强制租户过滤 + 分页下推 + 执行统计派生）
     *
     * @param array<string, mixed> $filters definition_id / type / status / active / name
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $result = $this->triggers()->search($tenantId, $filters, $page, $pageSize);

        $ids   = array_map(static fn (array $row): int => (int) $row['id'], $result['list']);
        $stats = $this->events()->statsByTriggerIds($tenantId, $ids);

        return [
            'list'  => array_map(
                fn (array $row): array => $this->present($row, $stats[(int) $row['id']] ?? null),
                $result['list']
            ),
            'total' => $result['total'],
        ];
    }

    /**
     * 触发器详情（含最近 10 条日志，与旧契约的 `recent_logs` 字段对齐）
     *
     * @return array<string, mixed>
     */
    public function detail(int $tenantId, int $id): array
    {
        $row = $this->requireOwned($tenantId, $id);

        $stats = $this->events()->statsByTriggerIds($tenantId, [(int) $row['id']]);
        $data  = $this->present($row, $stats[(int) $row['id']] ?? null);

        $logs = $this->events()->listByTriggerId($tenantId, (int) $row['id'], 1, 10);

        // 旧契约 `recent_logs` 是「最近 10 条，按时间正序」（Mock 用 array_slice(-10)）
        $data['recent_logs'] = array_map(
            fn (array $entry): array => $this->presentLog($entry),
            array_reverse($logs['list'])
        );

        return $data;
    }

    /**
     * 触发器日志（分页，按时间倒序）
     *
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function logs(int $tenantId, int $id, int $page, int $pageSize, ?string $eventType = null): array
    {
        $row = $this->requireOwned($tenantId, $id);

        $result = $this->events()->listByTriggerId($tenantId, (int) $row['id'], $page, $pageSize, $eventType);

        return [
            'list'  => array_map(fn (array $entry): array => $this->presentLog($entry), $result['list']),
            'total' => $result['total'],
        ];
    }

    // =====================================================================
    // 写（管理）
    // =====================================================================

    /**
     * 创建触发器
     *
     * @param array<string, mixed> $payload definition_id / name / type / config / description / enabled
     * @return array<string, mixed>
     */
    public function create(int $tenantId, ?int $userId, array $payload): array
    {
        $definitionId = $this->requireDefinitionId($payload['definition_id'] ?? null);

        // 定义必须属于本租户：跨租户建触发器等于给别人的流程开一个外部入口
        $this->requireOwnedDefinition($tenantId, $definitionId);

        $name = $this->normalizeName($payload['name'] ?? null);
        $type = $this->normalizeType($payload['type'] ?? null);

        $config = $this->normalizeConfig($payload['config'] ?? []);
        $this->assertEngineConfigValid($type, $config);

        // 启用状态：缺省 true（旧契约），显式 false 才禁用
        $enabled = $this->toBool($payload['enabled'] ?? true) ? 1 : 0;

        $meta = [
            WorkflowTriggerRepository::META_DESCRIPTION => $this->normalizeDescription($payload['description'] ?? null),
        ];

        // webhook 类型必须有可用的访问密钥：没有它这条触发器永远收不到回调
        if ($type === WorkflowTriggerRepository::TYPE_WEBHOOK) {
            $meta[WorkflowTriggerRepository::META_WEBHOOK_KEY] = $this->normalizeWebhookKey(
                $payload['webhook_key'] ?? null
            );
        }

        $id = $this->insertOrConflict([
            'definition_id' => $definitionId,
            'tenant_id'     => $tenantId,
            'name'          => $name,
            'type'          => $type,
            'config'        => $this->mergeMeta($config, $meta),
            'is_active'     => $enabled,
            'created_by'    => $userId,
        ], $name);

        $row = $this->requireOwned($tenantId, $id);

        $this->log($tenantId, null, $row, 'trigger_created', [
            'type'    => $type,
            'enabled' => $enabled === 1,
        ], $userId);

        return $this->present($row, null);
    }

    /**
     * 更新触发器（可改 name / description / config / type / enabled）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $tenantId, ?int $userId, int $id, array $payload): array
    {
        $current = $this->requireOwned($tenantId, $id);

        $patch = [];
        $meta  = $current['_meta'];

        if (array_key_exists('name', $payload)) {
            $patch['name'] = $this->normalizeName($payload['name']);

            if ($patch['name'] !== (string) $current['name']
                && $this->triggers()->existsName($tenantId, $patch['name'], (int) $current['id'])
            ) {
                throw WorkflowActionException::conflicting(
                    sprintf('触发器名「%s」在本租户下已存在 | trigger name already exists', $patch['name']),
                    ['name' => $patch['name']]
                );
            }
        }

        // 类型变更要重新校验既有配置：把 manual 改成 schedule 而配置里没有 cron，等于造一条永远不会触发的触发器
        $type = array_key_exists('type', $payload)
            ? $this->normalizeType($payload['type'])
            : (string) $current['type'];

        $config = $current['config'];

        if (array_key_exists('config', $payload)) {
            $config = $this->normalizeConfig($payload['config']);
        }

        $this->assertEngineConfigValid($type, $config);

        if (array_key_exists('config', $payload) || array_key_exists('type', $payload)) {
            $patch['config'] = $config;
            $patch['type']   = $type;
        }

        if (array_key_exists('description', $payload)) {
            $meta[WorkflowTriggerRepository::META_DESCRIPTION] = $this->normalizeDescription($payload['description']);
        }

        if ($type === WorkflowTriggerRepository::TYPE_WEBHOOK
            && empty($meta[WorkflowTriggerRepository::META_WEBHOOK_KEY])
        ) {
            // 由别的类型改成 webhook 时补一个密钥（原本就有则保留，避免已发出的回调地址失效）
            $meta[WorkflowTriggerRepository::META_WEBHOOK_KEY] = $this->generateWebhookKey();
        }

        if (array_key_exists('enabled', $payload)) {
            $patch['is_active'] = $this->toBool($payload['enabled']) ? 1 : 0;
        }

        if ($patch === [] && $meta === $current['_meta']) {
            throw WorkflowActionException::invalid([
                'payload' => '没有可更新的字段 | no updatable field provided',
            ]);
        }

        // 元数据始终回写（`_alkaid_meta` 可能被补了 webhook_key）
        $patch['config'] = $this->mergeMeta($config, $meta);

        $this->triggers()->updateForTenant($tenantId, (int) $current['id'], $patch);

        $row = $this->requireOwned($tenantId, (int) $current['id']);

        $this->log($tenantId, null, $row, 'trigger_updated', [
            'changed' => array_keys($patch),
        ], $userId);

        return $this->present($row, null);
    }

    /**
     * 删除触发器
     *
     * 为什么允许硬删：触发器没有「实例」概念（实例挂在定义上，不挂触发器），
     * 删掉它不会级联删除任何业务数据。历史日志**保留**（审计需要「这个入口曾经存在过」），
     * 只是日志端点在触发器不存在后返回 404。
     *
     * @return array{deleted_id: int}
     */
    public function delete(int $tenantId, ?int $userId, int $id): array
    {
        $current = $this->requireOwned($tenantId, $id);

        // 先留痕再删：删掉之后 `trigger_id` 指向的行没了，但事件行本身仍然可读
        $this->log($tenantId, null, $current, 'trigger_deleted', [
            'name' => (string) $current['name'],
        ], $userId);

        $this->triggers()->deleteForTenant($tenantId, (int) $current['id']);

        return ['deleted_id' => (int) $current['id']];
    }

    /**
     * 启用 / 禁用触发器
     *
     * @return array<string, mixed>
     */
    public function setEnabled(int $tenantId, ?int $userId, int $id, bool $enabled): array
    {
        $current = $this->requireOwned($tenantId, $id);

        $this->triggers()->updateForTenant($tenantId, (int) $current['id'], [
            'is_active' => $enabled ? 1 : 0,
        ]);

        $row = $this->requireOwned($tenantId, (int) $current['id']);

        $this->log(
            $tenantId,
            null,
            $row,
            $enabled ? 'trigger_enabled' : 'trigger_disabled',
            ['from' => (int) $current['is_active']],
            $userId
        );

        return $this->present($row, null);
    }

    // =====================================================================
    // 测试 / webhook
    // =====================================================================

    /**
     * 测试触发器（dry-run 默认 true）
     *
     * 「测试」必须真跑引擎的判断逻辑，而不是回一句 `would_trigger: true`：
     * 1. 类型必须已注册（`TriggerManager::getTriggerType()`）；
     * 2. 配置必须过 `validateConfig()`（失败时把 `getValidationErrors()` 原样带回）；
     * 3. `shouldTrigger()` 用测试数据求值 —— 这是唯一能告诉用户「这条配置到底会不会触发」的方式。
     *
     * 为了让 `shouldTrigger()` 有意义，必须先 `register()`：WebhookTrigger 等实现会检查
     * 「工作流是否已注册」，不注册的话答案永远是 false（那是个假否定，比不测更糟）。
     *
     * @param array<string, mixed> $payload test_data / dry_run
     * @return array<string, mixed>
     */
    public function test(int $tenantId, ?int $userId, int $id, array $payload): array
    {
        $row = $this->requireOwned($tenantId, $id);

        $type     = (string) $row['type'];
        $config   = $row['config'];
        $testData = $payload['test_data'] ?? [];
        $dryRun   = $this->toBool($payload['dry_run'] ?? true);

        if (!is_array($testData)) {
            throw WorkflowActionException::invalid([
                'test_data' => 'test_data 必须是对象 | test_data must be an object',
            ]);
        }

        $instance = $this->manager()->getTriggerType($type);

        if ($instance === null) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.unknown_trigger_type') . '：' . $type,
                ['type' => $type]
            );
        }

        if (!$instance->validateConfig($config)) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.trigger_config_invalid'),
                ['validation_errors' => $instance->getValidationErrors()]
            );
        }

        $definitionId = (int) $row['definition_id'];

        try {
            // 注册到「本次请求专属」的实例上：不注册，webhook/schedule 类条件必然返回 false
            $instance->register((string) $definitionId, $config);
        } catch (InvalidArgumentException $e) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.trigger_config_invalid'),
                ['validation_errors' => [$e->getMessage()]]
            );
        }

        $context = (new TriggerContext($type, $this->sourceOf($type)))
            ->setWorkflowId((string) $definitionId)
            ->setTenantId($tenantId)
            ->setUserId($userId)
            ->setData($testData);

        $conditionsMet = $instance->shouldTrigger($context);

        $result = [
            'trigger_id'   => (int) $row['id'],
            'trigger_type' => $type,
            'test_data'    => $testData,
            'dry_run'      => $dryRun,
            'validation'   => [
                'config_valid'    => true,
                'conditions_met'  => $conditionsMet,
            ],
            'would_trigger' => $conditionsMet,
            'message'       => $conditionsMet
                ? $this->t('workflow.trigger_test_would_fire')
                : $this->t('workflow.trigger_test_would_not_fire'),
        ];

        $instanceId = null;

        if (!$dryRun && $conditionsMet) {
            if ((int) $row['is_active'] !== 1) {
                // 非 dry-run 但触发器被禁用：这不是「测试失败」，而是「你测的这条根本不会执行」
                throw WorkflowActionException::conflict(
                    $this->t('workflow.trigger_disabled'),
                    ['trigger_id' => (int) $row['id'], 'is_active' => (int) $row['is_active']]
                );
            }

            $instanceId = $this->startInstance($tenantId, $row, $testData);

            $result['triggered']   = true;
            $result['instance_id'] = $instanceId;
            $result['message']     = $this->t('workflow.trigger_test_fired');
        }

        $this->log($tenantId, $instanceId, $row, $instanceId === null ? 'trigger_tested' : 'trigger_fired', [
            'dry_run'        => $dryRun,
            'conditions_met' => $conditionsMet,
            'test_mode'      => true,
        ], $userId);

        return $result;
    }

    /**
     * 处理 webhook 回调（无登录用户）
     *
     * @param string               $key     webhook 访问密钥（来自路径）
     * @param array<string, mixed> $context method / ip / user_agent / signature / raw_body
     * @param array<string, mixed> $payload 解析后的回调体（JSON 或表单）
     * @return array<string, mixed>
     */
    public function handleWebhook(string $key, array $context, array $payload): array
    {
        $tenantId = (int) ($context['tenant_id'] ?? 0);

        if ($tenantId <= 0) {
            // 没有租户上下文就无从定位触发器；不能退化成「全局搜索 key」
            throw WorkflowActionException::notFound($this->t('workflow.webhook_not_found'));
        }

        $row = $this->triggers()->findByWebhookKey($tenantId, $key);

        if ($row === null) {
            throw WorkflowActionException::notFound($this->t('workflow.webhook_not_found'));
        }

        if ((int) $row['is_active'] !== 1) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.trigger_disabled'),
                ['trigger_id' => (int) $row['id']]
            );
        }

        $config = $row['config'];

        // 1）HTTP 方法白名单（宿主侧；引擎的 allowed_methods 语义一致但取不到原始方法）
        $allowedMethods = $this->stringList($config['http_methods'] ?? $config['allowed_methods'] ?? ['POST']);

        if ($allowedMethods !== [] && !in_array((string) $context['method'], $allowedMethods, true)) {
            throw WorkflowActionException::methodNotAllowed(
                $this->t('workflow.method_not_allowed'),
                ['allowed_methods' => $allowedMethods]
            );
        }

        // 2）IP 白名单
        $allowedIps = $this->stringList($config['allowed_ips'] ?? []);
        $clientIp   = (string) ($context['ip'] ?? '');

        if ($allowedIps !== [] && !in_array($clientIp, $allowedIps, true)) {
            throw WorkflowActionException::forbidden($this->t('workflow.ip_not_allowed'));
        }

        // 3）签名（对**原始请求体**做 HMAC-SHA256，与外部系统的通用做法一致）
        $secret = isset($config['secret']) && is_scalar($config['secret']) ? (string) $config['secret'] : '';

        if ($secret !== '') {
            $expected = 'sha256=' . hash_hmac('sha256', (string) ($context['raw_body'] ?? ''), $secret);
            $provided = (string) ($context['signature'] ?? '');

            if ($provided === '' || !hash_equals($expected, $provided)) {
                throw WorkflowActionException::unauthorized($this->t('workflow.invalid_signature'));
            }
        }

        // 4）负载映射（宿主扩展 `payload_mapping` 优先；引擎 `data_mapping` 兼容）
        $mapping = [];

        if (is_array($config['payload_mapping'] ?? null)) {
            $mapping = $config['payload_mapping'];
        } elseif (is_array($config['data_mapping'] ?? null)) {
            $mapping = $config['data_mapping'];
        }

        $variables = $mapping === [] ? $payload : $this->applyPayloadMapping($payload, $mapping);

        // 5）真启动实例
        try {
            $instanceId = $this->startInstance($tenantId, $row, $variables);
        } catch (Throwable $e) {
            $this->safeLog($tenantId, null, $row, 'trigger_failed', [
                'reason' => $e instanceof WorkflowActionException ? $e->getPublicMessage() : 'internal error',
                'ip'     => $clientIp,
            ], null, $clientIp, (string) ($context['user_agent'] ?? ''));

            throw $e;
        }

        $this->safeLog($tenantId, $instanceId, $row, 'trigger_fired', [
            'method'       => (string) $context['method'],
            'ip'           => $clientIp,
            'payload_size' => strlen((string) ($context['raw_body'] ?? '')),
            'source'       => 'webhook',
        ], null, $clientIp, (string) ($context['user_agent'] ?? ''));

        return [
            'triggered'     => true,
            'instance_id'   => $instanceId,
            'definition_id' => (int) $row['definition_id'],
            'trigger_id'    => (int) $row['id'],
            'variables'     => $variables,
        ];
    }

    // =====================================================================
    // 内部：入参
    // =====================================================================

    /**
     * 读取并校验触发器租户归属（404 / 403 严格区分）
     *
     * @return array<string, mixed>
     */
    private function requireOwned(int $tenantId, int $id): array
    {
        if ($id <= 0) {
            throw WorkflowActionException::notFound($this->t('workflow.trigger_not_found'));
        }

        $row = $this->triggers()->findByIdUnscoped($id);

        if ($row === null) {
            throw WorkflowActionException::notFound($this->t('workflow.trigger_not_found'));
        }

        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            throw WorkflowActionException::forbidden($this->t('workflow.access_denied'));
        }

        return $row;
    }

    /**
     * 读取并校验定义租户归属（触发器必须挂在「自己的」定义上）
     *
     * @return array<string, mixed>
     */
    private function requireOwnedDefinition(int $tenantId, int $definitionId): array
    {
        $row = $this->definitions()->findByIdUnscoped($definitionId);

        if ($row === null) {
            throw WorkflowActionException::invalid([
                'definition_id' => $this->t('workflow.definition_not_found'),
            ]);
        }

        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            throw WorkflowActionException::forbidden($this->t('workflow.access_denied'));
        }

        return $row;
    }

    /**
     * `definition_id` 必须是数字主键（旧 Mock 的 `wf_xxx` 字符串在真库上没有意义）
     */
    private function requireDefinitionId(mixed $raw): int
    {
        if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
            throw WorkflowActionException::invalid([
                'definition_id' => $this->t('workflow.definition_id_required')
                    . ' | definition_id must be a numeric primary key',
            ]);
        }

        return (int) $raw;
    }

    /**
     * 触发器名归一化（列宽 100，非空）
     */
    private function normalizeName(mixed $raw): string
    {
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            throw WorkflowActionException::invalid([
                'name' => $this->t('workflow.trigger_name_required'),
            ]);
        }

        $name = trim((string) $raw);

        if (mb_strlen($name) > 100) {
            throw WorkflowActionException::invalid([
                'name' => 'name 长度不能超过 100 | name too long',
            ]);
        }

        return $name;
    }

    /**
     * 类型归一化（必须落在 `TYPE_*` 取值域）
     */
    private function normalizeType(mixed $raw): string
    {
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            throw WorkflowActionException::invalid([
                'type' => $this->t('workflow.trigger_type_required'),
            ]);
        }

        $type = trim((string) $raw);

        if (!in_array($type, WorkflowTriggerRepository::TYPES, true)) {
            throw WorkflowActionException::invalid([
                'type' => $this->t('workflow.invalid_trigger_type')
                    . '（' . implode(' / ', WorkflowTriggerRepository::TYPES) . '）',
            ]);
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConfig(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (!is_array($raw)) {
            throw WorkflowActionException::invalid([
                'config' => 'config 必须是对象 | config must be an object',
            ]);
        }

        // 保留子键由服务独占维护，客户端传入的一律丢弃（避免伪造 webhook_key）
        unset($raw[WorkflowTriggerRepository::META_KEY]);

        return $raw;
    }

    private function normalizeDescription(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return mb_substr(trim((string) $raw), 0, 1000);
    }

    /**
     * webhook 密钥：不给就生成一个（32 位十六进制，与路由 pattern `[a-f0-9]{32}` 一致）
     */
    private function normalizeWebhookKey(mixed $raw): string
    {
        if (is_scalar($raw) && preg_match('/^[a-f0-9]{32}$/', (string) $raw) === 1) {
            return (string) $raw;
        }

        return $this->generateWebhookKey();
    }

    private function generateWebhookKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 调引擎校验配置（失败 → 400，附引擎的 `getValidationErrors()`）
     *
     * @param array<string, mixed> $config
     */
    private function assertEngineConfigValid(string $type, array $config): void
    {
        $instance = $this->manager()->getTriggerType($type);

        if ($instance === null) {
            throw WorkflowActionException::invalid([
                'type' => $this->t('workflow.unknown_trigger_type') . '：' . $type,
            ]);
        }

        if (!$instance->validateConfig($config)) {
            throw WorkflowActionException::invalid([
                'config' => $this->t('workflow.trigger_config_invalid'),
                'validation_errors' => implode('; ', $instance->getValidationErrors()),
            ]);
        }
    }

    // =====================================================================
    // 内部：落库 / 留痕
    // =====================================================================

    /**
     * 插入并把唯一键冲突翻译成 409（口径同 `WorkflowDefinitionService`）
     *
     * @param array<string, mixed> $row
     */
    private function insertOrConflict(array $row, string $name): int
    {
        try {
            return $this->triggers()->insert($row);
        } catch (Throwable $e) {
            if ($this->isDuplicateKey($e)) {
                throw WorkflowActionException::conflicting(
                    sprintf('触发器名「%s」在本租户下已存在（并发写入） | duplicate trigger name', $name),
                    ['name' => $name]
                );
            }

            throw WorkflowActionException::internal('保存工作流触发器', $e);
        }
    }

    /**
     * 判定异常是否为唯一键冲突（口径同 `WorkflowDefinitionService::isDuplicateKey()`）
     */
    private function isDuplicateKey(Throwable $e): bool
    {
        if ($e instanceof PDOException && (string) ($e->errorInfo[0] ?? $e->getCode()) === '23000') {
            return true;
        }

        if (method_exists($e, 'getData')) {
            try {
                $data = $e->getData();
                $info = is_array($data['PDO Error Info'] ?? null) ? $data['PDO Error Info'] : [];

                if ((string) ($info['SQLSTATE'] ?? '') === '23000') {
                    return true;
                }
            } catch (Throwable) {
                // getData 不可用：退化为消息文本判定
            }
        }

        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), '1062');
    }

    /**
     * 写一条触发器事件（失败不阻断主流程）
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     */
    private function log(
        int $tenantId,
        ?int $instanceId,
        array $row,
        string $eventType,
        array $data,
        ?int $userId
    ): void {
        $this->safeLog($tenantId, $instanceId, $row, $eventType, $data, $userId, null, null);
    }

    /**
     * 写触发器事件的安全包装
     *
     * 审计旁路绝不能让主流程失败：`event_logs` 的写失败（磁盘满 / 表锁）不该把一次成功的
     * 触发器创建变成 500 —— 那会让用户重试并制造重复行。
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     */
    private function safeLog(
        int $tenantId,
        ?int $instanceId,
        array $row,
        string $eventType,
        array $data,
        ?int $userId,
        ?string $ip,
        ?string $userAgent
    ): void {
        try {
            $this->events()->appendTriggerEvent(
                $tenantId,
                $instanceId,
                (int) $row['id'],
                isset($row['definition_id']) ? (int) $row['definition_id'] : null,
                $eventType,
                $data,
                $userId,
                $ip,
                $userAgent
            );
        } catch (Throwable) {
            // 静默：审计旁路失败不得影响业务结果
        }
    }

    /**
     * 真正启动一个实例（webhook / 非 dry-run 测试共用）
     *
     * 操作人身份：webhook 没有登录用户，取「触发器创建人 → 定义创建人」这条链上的真实 `users.id`。
     * 为什么不写死一个系统账号：`workflow_instances.created_by` 与 `workflow_tasks.assignee`
     * 都带 `users.id` 外键，写 0/NULL 会让实例没有发起人；而引擎的参与者判定也需要一个真主体。
     * 两个来源都为空（创建人已被删除）时明确报错，不伪造身份。
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $variables
     */
    private function startInstance(int $tenantId, array $row, array $variables): int
    {
        $userId = isset($row['created_by']) && (int) $row['created_by'] > 0 ? (int) $row['created_by'] : null;

        if ($userId === null) {
            $definition = $this->definitions()->findByIdUnscoped((int) $row['definition_id']);
            $fallback   = isset($definition['created_by']) ? (int) $definition['created_by'] : 0;
            $userId     = $fallback > 0 ? $fallback : null;
        }

        if ($userId === null) {
            throw WorkflowActionException::conflict(
                '触发器与其定义都没有可用的创建人（原创建人已被删除），无法以系统身份启动实例 | '
                . 'no usable operator for this trigger',
                ['trigger_id' => (int) $row['id']]
            );
        }

        $instance = $this->instances()->start($tenantId, $userId, [
            'definition_id' => (int) $row['definition_id'],
            'variables'     => $variables,
            // 引擎用 auto_gen_title 作为实例标题；带上触发器名便于在实例列表里辨认来源
            'title'         => mb_substr('触发器：' . (string) $row['name'], 0, 200),
        ]);

        return (int) $instance['id'];
    }

    // =====================================================================
    // 内部：输出
    // =====================================================================

    /**
     * 对外呈现一行触发器（**白名单**，绝不 `return $row`）
     *
     * `$row` 里有 `_meta`（内部元数据）与 `config._alkaid_meta`（已摘除），
     * 直接返回会把内部结构漏给客户端。
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $stats 执行统计（来自事件表；null = 不查/查不到）
     * @return array<string, mixed>
     */
    private function present(array $row, ?array $stats): array
    {
        $webhookKey = isset($row['webhook_key']) && is_string($row['webhook_key']) ? $row['webhook_key'] : null;

        return [
            'id'                    => (int) $row['id'],
            'definition_id'         => (int) $row['definition_id'],
            'tenant_id'             => (int) $row['tenant_id'],
            'name'                  => (string) $row['name'],
            'description'           => (string) ($row['description'] ?? ''),
            'type'                  => (string) $row['type'],
            'config'                => is_array($row['config'] ?? null) ? $row['config'] : [],
            'status'                => (string) $row['status'],
            'is_active'             => (int) $row['is_active'],
            'webhook_key'           => $webhookKey,
            'webhook_url'           => $webhookKey === null ? null : self::WEBHOOK_URL_PREFIX . $webhookKey,
            // 派生字段：事件表是唯一真相，不落计数器（见 WorkflowEventLogRepository::statsByTriggerIds）
            'execution_count'       => (int) ($stats['execution_count'] ?? 0),
            'last_execution_at'     => $stats['last_execution_at'] ?? null,
            'last_execution_status' => $stats['last_execution_status'] ?? null,
            'created_at'            => $row['created_at'] ?? null,
            'updated_at'            => $row['updated_at'] ?? null,
            'created_by'            => $row['created_by'] ?? null,
        ];
    }

    /**
     * 对外呈现一条日志
     *
     * 保留旧字段 `event`（大写词汇）与 `timestamp`（ISO 8601），同时给出真实 `event_type`
     * 与 `instance_id`（管理类事件为 null）。
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function presentLog(array $entry): array
    {
        $eventType = (string) ($entry['event_type'] ?? '');
        $data      = is_array($entry['event_data'] ?? null) ? $entry['event_data'] : [];

        $createdAt = $entry['created_at'] ?? null;
        $timestamp = is_string($createdAt) && $createdAt !== ''
            ? (string) date('c', (int) strtotime($createdAt))
            : null;

        return [
            'id'          => (int) $entry['id'],
            'trigger_id'  => (int) ($data['trigger_id'] ?? 0),
            'event'       => self::LEGACY_EVENT_NAMES[$eventType] ?? strtoupper($eventType),
            'event_type'  => $eventType,
            'user_id'     => $entry['user_id'] ?? null,
            'data'        => $data,
            'timestamp'   => $timestamp,
            'instance_id' => isset($entry['instance_id']) && $entry['instance_id'] !== null
                ? (int) $entry['instance_id']
                : null,
        ];
    }

    // =====================================================================
    // 内部：杂项
    // =====================================================================

    /**
     * 合并宿主元数据到 `config`（保留子键由服务独占写）
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function mergeMeta(array $config, array $meta): array
    {
        $meta = array_filter($meta, static fn (mixed $value): bool => $value !== null && $value !== '');

        unset($config[WorkflowTriggerRepository::META_KEY]);

        if ($meta !== []) {
            $config[WorkflowTriggerRepository::META_KEY] = $meta;
        }

        return $config;
    }

    /**
     * 引擎类型 → `TriggerContext` 的 source 常量
     */
    private function sourceOf(string $type): string
    {
        return match ($type) {
            WorkflowTriggerRepository::TYPE_MANUAL        => TriggerContext::SOURCE_MANUAL,
            WorkflowTriggerRepository::TYPE_WEBHOOK       => TriggerContext::SOURCE_WEBHOOK,
            WorkflowTriggerRepository::TYPE_SCHEDULE      => TriggerContext::SOURCE_SCHEDULE,
            WorkflowTriggerRepository::TYPE_DATA_EVENT    => TriggerContext::SOURCE_DATA_EVENT,
            default                                       => TriggerContext::SOURCE_API,
        };
    }

    /**
     * 把 mixed 归一成「字符串列表」（IP / 方法白名单）
     *
     * @return string[]
     */
    private function stringList(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            return [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $result[] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * 应用负载映射（`target_key => source_path` 或 `target_key => {path, default, transform}`）
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function applyPayloadMapping(array $payload, array $mapping): array
    {
        $result = [];

        foreach ($mapping as $targetKey => $source) {
            if (is_string($source)) {
                $result[$targetKey] = $this->getNestedValue($payload, $source);

                continue;
            }

            if (!is_array($source)) {
                continue;
            }

            $path  = isset($source['path']) && is_string($source['path']) ? $source['path'] : '';
            $value = $path === '' ? null : $this->getNestedValue($payload, $path);

            if ($value === null && array_key_exists('default', $source)) {
                $value = $source['default'];
            }

            if (isset($source['transform']) && is_string($source['transform'])) {
                $value = $this->transformValue($value, $source['transform']);
            }

            $result[$targetKey] = $value;
        }

        return $result;
    }

    /**
     * 点分路径取值（`a.b.0.c`）
     *
     * @param array<string, mixed> $array
     */
    private function getNestedValue(array $array, string $path): mixed
    {
        $value = $array;

        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * 映射值变换（沿用旧 Mock 的取值域）
     */
    private function transformValue(mixed $value, string $transform): mixed
    {
        return match ($transform) {
            'string'          => (string) $value,
            'int', 'integer'  => (int) $value,
            'float'           => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'json'            => json_encode($value),
            'uppercase'       => strtoupper((string) $value),
            'lowercase'       => strtolower((string) $value),
            'trim'            => trim((string) $value),
            default           => $value,
        };
    }

    /**
     * 把 true/false/1/0/'1'/'true' 归一成布尔
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private function t(string $key): string
    {
        $this->lang ??= new LanguageService();

        return $this->lang->trans($key);
    }

    private function triggers(): WorkflowTriggerRepository
    {
        return $this->triggers ??= new WorkflowTriggerRepository();
    }

    private function definitions(): WorkflowDefinitionRepository
    {
        return $this->definitions ??= new WorkflowDefinitionRepository();
    }

    private function events(): WorkflowEventLogRepository
    {
        return $this->events ??= new WorkflowEventLogRepository();
    }

    private function instances(): WorkflowInstanceService
    {
        return $this->instances ??= new WorkflowInstanceService($this->lang);
    }

    private function manager(): TriggerManager
    {
        return $this->manager ??= new TriggerManager();
    }
}
