import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { WorkflowTaskApi } from '#/api/workflow/task';

import { $t } from '#/locales';

/**
 * 待办列表（`./list.vue`）的列定义与状态选项 | Columns & status options
 *
 * 本文件**不含筛选 schema**：`GET /v1/workflow/tasks/pending` 只读取 `page` / `page_size`
 * （`WorkflowTaskController::pending()` 已把候选判定下推到 SQL，入参里没有任何筛选），
 * 挂一个后端不认的筛选框，等于给用户一个「点了没用」的搜索栏。
 * 筛选 schema 留给将来的「全部任务」页（消费 `getTaskList`，支持
 * `status` / `assignee` / `instance_id`）落地时再引入 —— 按当时的真实契约写，
 * 而不是现在先摆一份「当前无法被触发、还会随契约漂移」的代码。
 */

/**
 * 任务状态下拉/标签选项 | Task status options
 *
 * 取值与文案严格对齐后端真实状态（见 `WorkflowTaskRepository::STATUSES`）：
 * `pending` / `claimed` / `escalated` / `completed` / `rejected` / `cancelled`。
 * 未列出的取值（后端若新增状态）由 `CellTag` 渲染器**原样透传**显示，
 * 不会被错误映射成某个已知状态。
 */
export function getTaskStatusOptions(): Array<{
  color: string;
  label: string;
  value: WorkflowTaskApi.TaskStatus;
}> {
  return [
    {
      color: 'processing',
      label: $t('workflow.task.statusPending'),
      value: 'pending',
    },
    {
      color: 'blue',
      label: $t('workflow.task.statusClaimed'),
      value: 'claimed',
    },
    {
      color: 'warning',
      label: $t('workflow.task.statusEscalated'),
      value: 'escalated',
    },
    {
      color: 'success',
      label: $t('workflow.task.statusCompleted'),
      value: 'completed',
    },
    {
      color: 'error',
      label: $t('workflow.task.statusRejected'),
      value: 'rejected',
    },
    {
      color: 'default',
      label: $t('workflow.task.statusCancelled'),
      value: 'cancelled',
    },
  ];
}

/**
 * 受理人显示文本 | Assignee display text
 *
 * 为什么需要兜底：`POST /v1/workflow/tasks/{id}/claim` 只把**用户ID**写进 `workflow_tasks.assignee`，
 * 并不会回填 `assignee_name`（姓名由引擎按节点配置/宿主写入，未必有值）。
 * 若这一列直接读 `assignee_name`，已认领任务就会显示成空白单元格 —— 用户看到的是
 * 「这单没人处理」，而事实上受理人就是他自己。这是本页可感知的缺陷，所以在展示层兜住。
 *
 * 展示优先级（不改后端、不改契约类型，只在单元格渲染时降级）：
 * 1. `姓名（ID）`：两者都有，保留原始 ID 便于复制核对/报障；
 * 2. `姓名`：只有姓名；
 * 3. `ID`：只有 ID（未回填姓名的常见情形）；
 * 4. `—`：既无姓名也无 ID（未认领任务）。
 *
 * 导出原因：审批抽屉（`./modules/approval.vue`）的「受理人」项要显示同一个口径，
 * 两处各写一份的话，列表显示「张三（12）」而抽屉显示空白，用户会以为是两个任务。
 *
 * @param row 当前任务行
 * @returns 单元格展示文本（永不为空串）
 */
export function formatAssignee(row: WorkflowTaskApi.WorkflowTask): string {
  const name = row.assignee_name?.trim() ?? '';
  const id = row.assignee;
  const hasId = id !== null && id !== undefined;

  if (name && hasId) {
    return `${name}（${id}）`;
  }
  if (name) {
    return name;
  }
  if (hasId) {
    return String(id);
  }
  return '—';
}

/**
 * 表格列配置 | Table columns
 *
 * 列字段全部取 `workflow_tasks` 的真实列名（**不是** `name` / `assignee_id` 之类的想象名）：
 * - 任务名 → `title`（表里没有 `name` 列）
 * - 节点   → `node_name`（`node_id` 是机器可读的节点标识，不适合直接给用户看）
 * - 受理人 → `assignee_name` + `assignee`（见 `formatAssignee()`：姓名未必回填，需按 ID 兜底）
 *
 * @param onActionClick 操作列点击回调（由页面组装）
 */
export function useColumns<T = WorkflowTaskApi.WorkflowTask>(
  onActionClick: OnActionClickFn<T>,
): VxeTableGridOptions['columns'] {
  return [
    {
      field: 'title',
      minWidth: 200,
      title: $t('workflow.task.title'),
    },
    {
      field: 'instance_id',
      title: $t('workflow.task.instanceId'),
      width: 120,
    },
    {
      field: 'node_name',
      title: $t('workflow.task.node'),
      width: 160,
    },
    {
      cellRender: { name: 'CellTag', options: getTaskStatusOptions() },
      field: 'status',
      title: $t('workflow.task.status'),
      width: 100,
    },
    {
      // 受理人兜底渲染：`claim` 只写 `assignee`（用户ID），`assignee_name` 未必回填，
      // 直接读姓名会让已认领任务显示空白列（详见 `formatAssignee()` 的注释）。
      field: 'assignee_name',
      formatter: ({ row }) => formatAssignee(row),
      title: $t('workflow.task.assignee'),
      width: 160,
    },
    {
      field: 'created_at',
      title: $t('workflow.task.createdAt'),
      width: 180,
    },
    {
      align: 'center',
      cellRender: {
        attrs: {
          // delete 类确认框会读 nameField；本列表没有删除动作，仅保持与其它页一致的形状
          nameField: 'title',
          nameTitle: $t('workflow.task.title'),
          onClick: onActionClick,
        },
        name: 'CellOperation',
        options: [{ code: 'approve', text: $t('workflow.task.actionApprove') }],
      },
      field: 'operation',
      fixed: 'right',
      title: $t('workflow.task.operation'),
      width: 120,
    },
  ];
}
