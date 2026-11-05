<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Entities;

use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\model\IProcessDefine;

/**
 * 工作流定义实体（持久化）
 *
 * 对应表：`workflow_definitions`
 * （权威定义见 database/migrations/20251216000001_create_workflow_tables.php）。
 *
 * 引擎对定义的唯一「可执行」诉求是把定义体转成 `ProcessModel`，而引擎自己的入口
 * `ProcessEngines::execute()` 读的是 `$processDefine->getData('content')`
 * （见 src/Core/ProcessEngines.php:200），`ModelParser::parse()` 吃的就是它。
 * 因此本实体提供**引擎视角键名** `content`，指向我们的 `nodes_config` 列，
 * 并把格式问题在这里一次性拦掉：
 *
 * ⚠️ 定义体格式（本轮实测结论，不是猜测）
 * - `nodes_config` 的**实际存储形状**是「节点表」：`{节点键: {id, name}}`，
 *   见 database/migrations/20251216000003_insert_initial_data.php:140-177 与
 *   plugins/workflow-engine/migrations/20251216000003_insert_initial_data.php:51-91；
 *   **不含连线（edges）也不含节点类型（type）**；
 * - `bpmn_xml` 存的是**真 BPMN 2.0 XML**（startEvent/userTask/endEvent + sequenceFlow），
 *   但本插件**没有 BPMN 解析器**（全仓 grep 无实现）；
 * - 引擎 `ModelParser::parse()` 需要的是 **LogicFlow 结构**
 *   `{nodes:[{id,type:'ingenious:task',x,y,properties:{...},text:{value}}], edges:[{id,sourceNodeId,targetNodeId,properties:{}}]}`，
 *   见 src/parser/ModelParser.php:44-70 与 src/parser/AbstractINodeParser.php:30-91。
 *
 * 三种表示互不相通，且连线在 API 层就被丢弃（见
 * docs/report/production-readiness-analysis-2026-10-31.md §2.3「R9」与 §4.2「C9」）。
 * 因此本轮**只实现可机械判定的那一种**：`nodes_config` 本身就是 LogicFlow 结构时直接交给引擎；
 * 否则抛 LFlowException 并说明缺什么 —— **不猜节点顺序、不编造连线**，
 * 也不返回一个「看起来能跑」的空模型（空模型会让引擎在后面以更难懂的方式崩）。
 *
 * @package Alkaid\Workflow\Engine\Entities
 */
class ProcessDefineEntity extends AbstractRowEntity implements IProcessDefine
{
    /**
     * 引擎视角的定义体键名（非表列）
     */
    public const CONTENT_KEY = 'content';

    /**
     * 表列：JSON 列
     *
     * @return string[]
     */
    protected function jsonColumns(): array
    {
        return ['nodes_config', 'triggers_config', 'variables_schema'];
    }

    /**
     * 引擎视角键名 => 表列名
     */
    protected function columnFor(string $key): string
    {
        return match ($key) {
            // 引擎把定义主键叫 process_define_id（见 ProcessEngines:198、StartSubProcessHandler:39）
            'process_define_id' => 'id',
            'state'             => 'status',
            default             => $key,
        };
    }

    /**
     * 读取列值（含引擎需要的派生值）
     *
     * @param string $column 列名
     * @return mixed
     */
    protected function readColumn(string $column): mixed
    {
        if ($column === self::CONTENT_KEY) {
            return $this->logicFlowDocument();
        }

        return parent::readColumn($column);
    }

    /**
     * 定义体是否已经是引擎可直接解析的 LogicFlow 结构
     */
    public function hasEngineReadableContent(): bool
    {
        $config = $this->attributes['nodes_config'] ?? null;

        return is_array($config)
            && isset($config['nodes'], $config['edges'])
            && is_array($config['nodes'])
            && is_array($config['edges']);
    }

    /**
     * 定义显示名（引擎用于标题、事件文案）
     */
    public function getDisplayName(): string
    {
        return (string) ($this->attributes['display_name'] ?? $this->attributes['name'] ?? '');
    }

    /**
     * 取出定义体（LogicFlow JSON 字符串）
     *
     * @throws LFlowException 定义体不是 LogicFlow 结构时
     */
    private function logicFlowDocument(): string
    {
        if ($this->hasEngineReadableContent()) {
            $document = json_encode($this->attributes['nodes_config'], JSON_UNESCAPED_UNICODE);

            if ($document !== false) {
                return $document;
            }
        }

        throw new LFlowException(sprintf(
            '工作流定义 #%s 的定义体无法转换为引擎模型：nodes_config 当前是「节点表」结构（{节点键:{id,name}}），'
            . '不含连线(edges)与节点类型(type)，而引擎 ModelParser 需要 LogicFlow 结构'
            . ' {nodes:[{id,type,x,y,properties,text}], edges:[{id,sourceNodeId,targetNodeId,properties}]}；'
            . 'bpmn_xml 列另有真 BPMN 2.0 XML，但本插件没有 BPMN 解析器。'
            . '定义体三种表示互不通（docs/report/production-readiness-analysis-2026-10-31.md §2.3 / 冲突 C9），'
            . '需先定案定义体格式并回填数据后才能执行该定义。',
            (string) ($this->attributes['id'] ?? '-')
        ));
    }
}
