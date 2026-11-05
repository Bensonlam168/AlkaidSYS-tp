<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\Entities\ProcessDefineEntity;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\model\IProcessDefine;
use Alkaid\Workflow\Engine\interface\services\IProcessDefineService;
use Alkaid\Workflow\Engine\model\ProcessModel;
use Alkaid\Workflow\Engine\parser\ModelParser;
use Alkaid\Workflow\Engine\Services\Support\ResolvesWorkflowTenant;

/**
 * 工作流定义服务（真实实现，落 `workflow_definitions` 表）
 *
 * ── 实现范围（引擎真正调用的 4 个方法，全部真实落库/真实解析）─────────────
 * | 方法 | 引擎调用点 |
 * | ---- | ---------- |
 * | `getById()`                 | ProcessEngines:60/198、StartSubProcessHandler:39 |
 * | `getProcessDefineByVersion()` | StartSubProcessHandler:39 |
 * | `getProcessModel()`         | EndProcessHandler:64 |
 * | `processDefineToModel()`    | ProcessEngines:66 |
 *
 * 另外补了几个**语义唯一、无歧义**的只读方法（findById / getLastByName /
 * getDefineJsonStr / getDefineJsonObject），它们是同一份数据的直接读取，
 * 不涉及任何「猜」出来的契约，因此实现而不是抛异常。
 *
 * ── 多租户 ────────────────────────────────────────────────────────────────
 * 所有查询都经 TenantScopeManager 的 `where tenant_id = ?` 作用域，
 * 没有作用域时直接抛 TenantIsolationException（见 ResolvesWorkflowTenant）。
 *
 * ── 未实现的部分 ──────────────────────────────────────────────────────────
 * `created/updated/del/list/deploy/redeploy/enable/disable/updateState` 一律抛
 * BadMethodCallException：它们要么需要 API 层 DTO 契约（本仓库 API 层是内存实现，
 * 见 app/controller/workflow/WorkflowDefinitionController.php 的 static $definitions），
 * 要么需要 BPMN/部署语义（deploy/redeploy），要么需要未定义的状态码取值（updateState）。
 * 照着签名瞎填返回「成功」比直接报错更危险。
 *
 * @package Alkaid\Workflow\Engine\Services
 */
class ProcessDefineService implements IProcessDefineService
{
    use ResolvesWorkflowTenant;

    /**
     * 本服务负责的表
     */
    private const TABLE = 'workflow_definitions';

    /**
     * 按主键读取定义
     */
    public function getById(string $processDefineId): ?IProcessDefine
    {
        $id = $this->numericId($processDefineId);

        // 非数字主键在 int unsigned 自增列上不可能存在，按「未找到」处理（见 numericId 说明）
        if ($id === null) {
            return null;
        }

        $row = $this->scoped(self::TABLE, '读取工作流定义')->find($id);

        return $row === null ? null : ProcessDefineEntity::fromRow($row);
    }

    /**
     * 按主键读取定义（与 getById 同一份查询的别名）
     *
     * 接口同时声明了 `getById()` 与 `findById()`，引擎用前者；
     * 这里作为别名实现，避免「同一个主键查询两个方法一个能用一个抛异常」的割裂。
     */
    public function findById(string $id): ?IProcessDefine
    {
        return $this->getById($id);
    }

    /**
     * 按 name + version 读取定义（子流程按版本启动时使用）
     */
    public function getProcessDefineByVersion(string $name, int|float $version): ?IProcessDefine
    {
        $row = $this->scoped(self::TABLE, '按版本读取工作流定义')->findWhere([
            'name'    => $name,
            'version' => (int) $version,
        ]);

        return $row === null ? null : ProcessDefineEntity::fromRow($row);
    }

    /**
     * 读取同名定义中版本号最大的一条
     */
    public function getLastByName(string $name): ?IProcessDefine
    {
        $row = $this->scoped(self::TABLE, '读取最新版本工作流定义')
            ->query()
            ->where('name', $name)
            ->order('version', 'desc')
            ->find();

        return $row === false || $row === null ? null : ProcessDefineEntity::fromRow($row);
    }

    /**
     * 读取定义并转成引擎流程模型
     */
    public function getProcessModel(string $processDefineId): ?ProcessModel
    {
        $processDefine = $this->getById($processDefineId);

        if ($processDefine === null) {
            return null;
        }

        return $this->processDefineToModel($processDefine);
    }

    /**
     * 定义体 => 引擎 ProcessModel
     *
     * 只做机械转换：把定义实体提供的 LogicFlow JSON 交给引擎自带的 ModelParser。
     * 格式不足时由 ProcessDefineEntity::getData('content') 抛 LFlowException 说明缺什么
     * （为什么不猜：见 ProcessDefineEntity 类注释里的三种表示互不通）。
     *
     * @throws LFlowException 定义体不是引擎可读结构，或解析后没有节点
     */
    public function processDefineToModel(IProcessDefine $processDefine): ?ProcessModel
    {
        if (!method_exists($processDefine, 'getData')) {
            throw new LFlowException(sprintf(
                '定义对象 %s 没有 getData() 方法，无法取得定义体（引擎要求 getData(\'content\') 返回 LogicFlow JSON）',
                get_debug_type($processDefine)
            ));
        }

        $content = $processDefine->getData(ProcessDefineEntity::CONTENT_KEY);

        if (!is_string($content) || $content === '') {
            throw new LFlowException(sprintf(
                '工作流定义 #%s 的定义体为空，无法转换为引擎模型',
                (string) ($processDefine->getData('id') ?? '-')
            ));
        }

        $processModel = ModelParser::parse($content);

        // ModelParser 在 nodes/edges 任一为空时**静默返回空模型**（src/parser/ModelParser.php:67-69）。
        // 空模型会让 ProcessEngines 在 `$processModel->getStart()->execute()` 处崩成
        // 「Call to a member function on null」，这里提前拦成可读错误。
        if ($processModel->getNodes() === []) {
            throw new LFlowException(sprintf(
                '工作流定义 #%s 解析后没有任何节点模型（定义体缺 nodes/edges 或节点类型无法识别）',
                (string) ($processDefine->getData('id') ?? '-')
            ));
        }

        return $processModel;
    }

    /**
     * 读取定义体 JSON 字符串（引擎视角的 content）
     */
    public function getDefineJsonStr(string $processDefineId): ?string
    {
        $processDefine = $this->getById($processDefineId);

        if (!$processDefine instanceof ProcessDefineEntity) {
            return null;
        }

        $content = $processDefine->getData(ProcessDefineEntity::CONTENT_KEY);

        return is_string($content) ? $content : null;
    }

    /**
     * 读取定义体 JSON 对象
     */
    public function getDefineJsonObject(string $processDefineId): \stdClass|string|bool
    {
        $jsonStr = $this->getDefineJsonStr($processDefineId);

        if ($jsonStr === null) {
            return false;
        }

        $decoded = json_decode($jsonStr);

        return $decoded instanceof \stdClass ? $decoded : $jsonStr;
    }

    /**
     * {@inheritdoc}
     */
    public function created(object $param): ?IProcessDefine
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的 DTO（object $param），本仓库的定义写入仍由 API 层内存实现承担'
            . '（app/controller/workflow/WorkflowDefinitionController.php 的 static $definitions），'
            . '没有可对齐的字段契约'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updated(object $param): bool
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的 DTO（object $param），且 `version` 的语义尚未拍板'
            . '（唯一键 (tenant_id,name,version) 暗示快照，但 API 层 update() 是就地改、不 bump 版本）'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function del(string|int|array $data): array
    {
        $this->unimplemented(
            __METHOD__,
            '删除语义未定：定义被实例引用时应拒绝还是级联，需要先拍板版本/引用策略'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function list(object $param): array
    {
        $this->unimplemented(
            __METHOD__,
            '入参是未定义的分页 DTO（object $param），本仓库 API 层的列表契约基于内存数组，'
            . '没有可对齐的筛选/排序字段'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function deploy($param, string $operation): bool
    {
        $this->unimplemented(
            __METHOD__,
            '部署语义依赖 BPMN 定义与部署包概念，本插件没有 BPMN 解析器'
            . '（见 ProcessDefineEntity 类注释：bpmn_xml 列存的是真 BPMN，但全仓无解析实现）'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function redeploy(string|int $processDefineId, object $inputStream, string|int $operation): bool
    {
        $this->unimplemented(
            __METHOD__,
            '重新部署同样依赖 BPMN 定义体与部署包概念，且 $inputStream 的入参结构未定义'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function enable(string|int|array $data, string|int $operation): array
    {
        $this->unimplemented(
            __METHOD__,
            '启用语义涉及 is_active 与 status 两个列的取值组合，`$operation` 的取值集合未定义，'
            . '无法确定回写哪一列'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function disable(string|int|array $data, string|int $operation): array
    {
        $this->unimplemented(
            __METHOD__,
            '停用语义涉及 is_active 与 status 两个列的取值组合，`$operation` 的取值集合未定义'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function updateState(string $processDefineId, string|int $state, string|int $operation): void
    {
        $this->unimplemented(
            __METHOD__,
            '`$state` 的取值集合未定义（表里 status 的取值域是 draft/published/archived 字符串，'
            . '而入参类型是 string|int，无法判定 1/2 之类的整型码对应哪个状态）'
        );
    }
}
