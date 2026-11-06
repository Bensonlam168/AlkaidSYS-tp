<script lang="ts" setup>
import type {
  OnActionClickParams,
  VxeTableGridOptions,
} from '#/adapter/vxe-table';
import type { WorkflowTaskApi } from '#/api/workflow/task';

import { Page } from '@vben/common-ui';

import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { getPendingTasks } from '#/api/workflow/task';
import { $t } from '#/locales';

import { useColumns } from './data';

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
const [Grid] = useVbenVxeGrid({
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
 * 当前只有「查看/审批」一个动作，且审批抽屉由下一个包实现。
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
 * 打开审批抽屉（占位）
 *
 * TODO(下一个包)：打开审批抽屉，按任务ID拉取 `GET /v1/workflow/tasks/{id}` 与
 * `GET /v1/workflow/tasks/{id}/form` 渲染表单，并接 approve / reject / transfer /
 * delegate / complete 等写动作（权限码 `workflow_task.write`）。
 *
 * 本次刻意不引入任何不存在的抽屉组件：只留入口 + 日志，避免首屏出现「点了没反应」的假死按钮。
 *
 * @param row 当前任务行
 */
function openApproval(row: WorkflowTaskApi.WorkflowTask) {
  // TODO(下一个包)：打开审批抽屉
  console.warn(
    '[workflow/todo] 审批抽屉尚未实现，任务ID：',
    row.id,
    '实例ID：',
    row.instance_id,
  );
}
</script>
<template>
  <Page auto-content-height>
    <Grid :table-title="$t('workflow.todo.title')" />
  </Page>
</template>
