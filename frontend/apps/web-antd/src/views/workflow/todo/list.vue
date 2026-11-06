<script lang="ts" setup>
import type {
  OnActionClickParams,
  VxeTableGridOptions,
} from '#/adapter/vxe-table';
import type { WorkflowTaskApi } from '#/api/workflow/task';

import { Page, useVbenDrawer } from '@vben/common-ui';

import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { getPendingTasks } from '#/api/workflow/task';
import { $t } from '#/locales';

import { useColumns } from './data';
import Approval from './modules/approval.vue';

/**
 * 我的待办列表 | My pending workflow tasks
 *
 * 数据源固定为 `GET /v1/workflow/tasks/pending`（权限码 `workflow_task.read`），
 * 语义是「在办 + 我是受理人/候选范围内的候选组命中」。
 *
 * 三件刻意没做的事：
 * 1. **没有「新建任务」按钮**：工作流任务由引擎按流程定义生成，不存在人工新建的入口；
 * 2. **没有搜索栏**：`pending` 端点只接受 `page` / `page_size`，挂了筛选框也筛不动
 *    （筛选 schema 留给将来的「全部任务」页，见 `./data.ts` 文件头注释）；
 * 3. **没有权限码门禁**：本包按「只做注释」处理，理由见 `api/workflow/task.ts` 头部的权限码说明。
 */

/**
 * 审批抽屉 | Approval drawer
 *
 * `connectedComponent` 把抽屉外壳留在列表页、内容组件（`./modules/approval.vue`）按需挂载，
 * 抽屉本体不重复实现；`destroyOnClose` 保证每次打开都是一份干净状态（表单 schema、选人、
 * 审批意见都不复用上一次的），否则「上一个任务的字段」会漏到下一个任务上。
 */
const [ApprovalDrawer, approvalDrawerApi] = useVbenDrawer({
  connectedComponent: Approval,
  destroyOnClose: true,
});

const [Grid, gridApi] = useVbenVxeGrid({
  gridOptions: {
    columns: useColumns(onActionClick),
    height: 'auto',
    keepSource: true,
    proxyConfig: {
      ajax: {
        // 分页参数要转换命名：网格给的是 `currentPage` / `pageSize`，
        // 后端只认下划线的 `page` / `page_size`（`ApiController::paginate()` 同口径）。
        // 返回值已由 api 层映射成 `{ items, total }`（vxe 适配器约定的形状），这里不再加工。
        query: async ({ page }) => {
          return await getPendingTasks({
            page: page.currentPage,
            page_size: page.pageSize,
          });
        },
      },
    },
    rowConfig: {
      keyField: 'id',
    },
    toolbarConfig: {
      custom: true,
      export: false,
      refresh: true,
      zoom: true,
    },
  } as VxeTableGridOptions<WorkflowTaskApi.WorkflowTask>,
});

/**
 * 操作列点击分发
 *
 * 「查看/审批」打开同一个抽屉：后端没有「只读详情」与「审批」两套端点，
 * 详情 + 表单 + 动作都在同一个抽屉里完成，拆成两个入口只会让用户多点一次。
 */
function onActionClick({
  code,
  row,
}: OnActionClickParams<WorkflowTaskApi.WorkflowTask>) {
  switch (code) {
    case 'approve': {
      openApproval(row);
      break;
    }
    default: {
      break;
    }
  }
}

/**
 * 打开审批抽屉
 *
 * 只传任务ID：抽屉内部自己去拉 `GET /v1/workflow/tasks/{id}` 与
 * `GET /v1/workflow/tasks/{id}/form`，避免「列表行的字段」与「详情字段」两套口径
 * （列表行是分页查询的结果，字段未必与详情端点一致）。
 *
 * @param row 当前任务行
 */
function openApproval(row: WorkflowTaskApi.WorkflowTask) {
  approvalDrawerApi.setData({ taskId: row.id }).open();
}

/**
 * 审批成功后刷新列表
 *
 * 审批会改变任务状态（同意/驳回后任务离开在办集合、领取/取消领取改受理人与状态），
 * 不刷新的话用户看到的是「刚审批完，任务还挂在待办里」。
 */
function onApprovalSuccess() {
  gridApi.query();
}
</script>
<template>
  <Page auto-content-height>
    <ApprovalDrawer @success="onApprovalSuccess" />
    <Grid :table-title="$t('workflow.todo.title')" />
  </Page>
</template>
