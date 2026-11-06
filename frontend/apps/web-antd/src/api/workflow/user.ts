import type { WorkflowTaskApi } from './task';

import { requestClient } from '#/api/request';

import { toGridPage } from './task';

/**
 * 工作流用户目录 API | Workflow user directory API
 *
 * 端点是 `GET /v1/workflow/users`（`WorkflowTaskController::users()`，权限码 `workflow_task.read`），
 * 存在的意义只有一个：**转交 / 委派要「选人」**。
 *
 * ## 为什么是「工作流」自己的用户目录，而不是用户模块的接口
 *
 * 改造前工作流模块没有任何用户数据源，前端只能让用户手输数字 ID —— 等于把最容易出错的
 * 内部主键暴露给操作者。后端把这个目录放在任务域的控制器里，理由见
 * `route/workflow.php` 的「Workflow User Directory」段注释：用途与授权都归属任务域，
 * 权限码复用 `workflow_task.read`，避免「谁能看租户用户列表」出现两套授权口径。
 *
 * ## 返回字段是被后端**白名单**收窄过的
 *
 * `WorkflowUserDirectoryRepository::search()` 只回 `id` / `name` / `username`，
 * `password` / `email` / `phone` 之类一个都不返回。前端不要假设这里有更多字段，
 * 也不要为了「显示好看」去别的接口补齐（那会绕开这份白名单）。
 */
export namespace WorkflowUserApi {
  /**
   * 用户目录项 | Directory entry
   *
   * `name`（姓名）与 `username`（登录名）都可能为空：老数据/仅导入账号的情形，
   * 展示层需要按 `name → username → id` 的顺序兜底（见 `views/workflow/todo/modules/approval.vue`）。
   */
  export interface WorkflowUser {
    /** 用户ID（转交 `target_user` / 委派 `delegate_to` 的取值） */
    id: number;
    /** 姓名 */
    name?: string;
    /** 登录名 */
    username?: string;
    /** 兜住后端将来新增的列（不影响类型检查） */
    [key: string]: any;
  }

  /**
   * 用户目录查询参数 | Directory query
   *
   * 与列表端点同口径：**下划线** `page` / `page_size`（最大 100），`keyword` 模糊匹配
   * `name` / `username`。
   */
  export interface WorkflowUserQuery {
    /** 关键字（匹配 `name` / `username`，空串等价于不过滤） */
    keyword?: string;
    /** 页码，从 1 开始 */
    page?: number;
    /** 每页条数（下划线命名，后端上限 100） */
    page_size?: number;
  }
}

/**
 * 本租户用户目录 | List tenant users for assignee pickers
 *
 * `GET /v1/workflow/users`（权限码 `workflow_task.read`）
 *
 * @param params `keyword` / `page` / `page_size`
 * @returns 已映射成 `{ items, total }` 的分页结构（与 `getTaskList` 同一份映射，见 `toGridPage`）
 */
async function searchWorkflowUsers(
  params: WorkflowUserApi.WorkflowUserQuery = {},
) {
  const result = await requestClient.get<
    WorkflowTaskApi.Paginated<WorkflowUserApi.WorkflowUser>
  >('/v1/workflow/users', { params });

  return toGridPage(result);
}

export { searchWorkflowUsers };
