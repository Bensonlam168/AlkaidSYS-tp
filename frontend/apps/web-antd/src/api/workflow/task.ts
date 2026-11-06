import { requestClient } from '#/api/request';

/**
 * 工作流任务 API 契约层 | Workflow Task API contract
 *
 * 端点、路径、入参名全部以 `route/workflow.php`、`app/controller/workflow/WorkflowTaskController.php`
 * 与 `infrastructure/Workflow/Repository/WorkflowTaskRepository.php` 为准：
 * - 分页字段是**下划线** `page` / `page_size`（后端 `ApiController::paginate()` 同口径），
 *   写成 `pageSize` 会被后端忽略并回落到默认 20 条；
 * - 写动作（claim / unclaim）**无请求体**，操作人身份只取 JWT 的 `sub`，
 *   因此这里不传任何 `user_id` / `operator` 字段（传了也不会被读取）。
 *
 * ## 权限码（本模块后端权限码）
 *
 * - `workflow_task.read`：`GET /v1/workflow/tasks`、`GET /v1/workflow/tasks/pending`、
 *   `GET /v1/workflow/tasks/:id`、`GET /v1/workflow/tasks/:id/form`、`GET /v1/workflow/users`
 * - `workflow_task.write`：`claim` / `unclaim` / `approve` / `reject` / `complete` /
 *   `transfer` / `delegate` / `submitForm` 等写动作
 *
 * ## 前端为什么只做注释、不挂权限码门禁
 *
 * 本包**不**新增任何权限码级可见性机制，理由有两条，都是事实而非取舍：
 * 1. **路由级没有权限码门禁**：`@vben/utils` 的 `hasAuthority()` 只把 `meta.authority`
 *    与 `generateAccess({ roles })` 传入的 `roles`（`router/guard.ts` 里是 `role_slugs`）求交集，
 *    没有「按权限码过滤路由」的分支，详见 `router/routes/modules/workflow.ts` 头部说明；
 * 2. **组件级虽有权限码机制，但权限码格式对不上**：`@vben/access` 的 `hasAccessByCodes()` /
 *    `v-access:code` 比对的是 `accessStore.accessCodes`，该值来自 `GET /v1/auth/me` 的
 *    `permissions`；而 `PermissionService::getUserPermissionsFromDatabase()` 拼的是
 *    `resource:action`（**冒号**，如 `workflow_task:read`），与本模块权限码的点号写法
 *    （`workflow_task.read`）不一致。直接写点号权限码会导致门禁恒为「无权限」。
 *
 * 结论：真正的鉴权始终在后端（`app\middleware\Permission`，越权返回 2002），
 * 前端这里只把权限码记在注释里备查，不自造第二套机制。
 */
export namespace WorkflowTaskApi {
  /**
   * 任务状态 | Task status
   *
   * 取值来自后端真实约束，而不是猜测：
   * - `WorkflowTaskRepository::STATUSES`（列表接口允许筛选的取值域）：
   *   `pending` / `claimed` / `completed` / `rejected` / `cancelled` / `escalated`
   * - `WorkflowTaskRepository::DOING_STATUSES`（「在办」，待办列表只会出现这三种）：
   *   `pending` / `claimed` / `escalated`
   *
   * `transferred` 只出现在 `WorkflowTaskController` 的状态常量里：转办的真实落库形态是
   * 「`assignee` 换成目标用户 + 原受理人移出 `candidate_users` + `task_transferred` 事件」，
   * 任务状态并不会变成 `transferred`（见 `WorkflowTaskRepository` 类注释），故不纳入本联合类型。
   */
  export type TaskStatus =
    | 'cancelled'
    | 'claimed'
    | 'completed'
    | 'escalated'
    | 'pending'
    | 'rejected';

  /**
   * 分页信封 | Pagination envelope
   *
   * 后端 `ApiController::paginate()` 的原样返回（`code` / `message` / `data` 外壳已由
   * `requestClient` 的响应拦截器剥离，这里拿到的是 `data` 部分）。
   */
  export interface Paginated<T> {
    list: T[];
    total: number;
    page: number;
    page_size: number;
    total_pages: number;
  }

  /**
   * 网格分页结构 | Grid pagination shape
   *
   * 即 `{ items, total }`，与 vxe-table 适配器的响应映射一致
   * （`src/adapter/vxe-table.ts` 的 `proxyConfig.response = { result: 'items', total: 'total', list: 'items' }`）。
   */
  export interface GridPage<T> {
    items: T[];
    total: number;
  }

  /**
   * 任务对象 | Workflow task
   *
   * 字段按 `workflow_tasks` 表的真实列声明为**可选**（列清单见 `WorkflowTaskRepository` 类注释）：
   * - 列表端点返回整行；详情端点（`read`）额外带 `history`；
   * - 任务对象在不同端点/不同引擎版本下可能有缺列，缺列时不应让列表渲染炸掉；
   * - `[key: string]: any` 与 `SystemRoleApi.SystemRole` 同风格，兜住未声明的列。
   */
  export interface WorkflowTask {
    [key: string]: any;
    /** 任务ID（主键，网格的 `rowConfig.keyField`） */
    id: number;
    /**
     * 任务标题（`workflow_tasks.title`）——列表里的「任务名」就是这一列
     */
    title?: string;
    /**
     * 任务名（容错字段，仅作兼容）
     *
     * ⚠️ `workflow_tasks` **没有** `name` 列，任务名的真实列是 `title`；
     * 保留 `name` 只为兜住可能出现别名的响应，业务代码请优先读 `title`。
     */
    name?: string;
    /** 所属实例ID（列类型为 string(50)，后端按字符串存，故两种形态都容错） */
    instance_id?: number | string;
    /** 节点ID */
    node_id?: string;
    /** 节点名称（人类可读的「节点」列） */
    node_name?: string;
    /** 任务类型：`user_task` / `system_task` / `service_task` */
    task_type?: string;
    /** 任务子类型：`approval` / `countersign` / `notify` */
    task_sub_type?: string;
    /** 任务描述 */
    description?: string;
    /**
     * 任务状态
     *
     * 声明的取值域见 {@link TaskStatus}；这里刻意用 `string` 接收，
     * 后端若出现未列出的取值（例如控制器常量里的 `transferred`），界面按原始字符串透传展示，
     * 而不是把任务「静默丢弃」或错误映射成某个已知状态。
     */
    status?: string;
    /** 优先级（1-10） */
    priority?: number;
    /** 受理人用户ID（未认领时为 null） */
    assignee?: null | number;
    /** 受理人姓名 */
    assignee_name?: string;
    /** 候选用户ID集合（引擎写字符串数组，人工数据可能是数字数组） */
    candidate_users?: Array<number | string>;
    /** 候选组引用集合（`role:<slug>` 或 `<slug>`） */
    candidate_groups?: string[];
    /** 表单数据（json 列，后端已解码成对象） */
    form_data?: Record<string, any>;
    /** 处理结果（json 列） */
    result?: Record<string, any>;
    /** 处理/审批意见 */
    comment?: string;
    /** 到期时间 */
    due_date?: null | string;
    /** 完成时间 */
    completed_at?: null | string;
    /** 创建时间 */
    created_at?: string;
    /** 更新时间 */
    updated_at?: string;
    /** 处理历史（仅详情端点 `GET /v1/workflow/tasks/{id}` 返回） */
    history?: Array<Record<string, any>>;
  }

  /**
   * 我的待办查询参数 | Pending task query
   *
   * `WorkflowTaskController::pending()` 只读取 `page` / `page_size`
   * （用户身份取 JWT，候选判定在 SQL 里完成），**没有**筛选参数：
   * 传 `status` / `instance_id` 之类不会被后端使用，因此这里也不声明。
   */
  export interface PendingTaskParams {
    /** 页码，从 1 开始 */
    page?: number;
    /** 每页条数（下划线命名，不是 pageSize） */
    page_size?: number;
  }

  /**
   * 全部任务查询参数 | All task query
   *
   * 对应 `GET /v1/workflow/tasks`，筛选名与控制器 `index()` 的取参一致。
   */
  export interface TaskListParams extends PendingTaskParams {
    /** 任务状态，取值域见 {@link TaskStatus}（后端只接受取值域内的值，其余忽略） */
    status?: string;
    /** 受理人用户ID（整数） */
    assignee?: number;
    /** 所属实例ID（整数） */
    instance_id?: number;
  }
}

/**
 * 后端分页信封 → 网格分页结构 | Map pagination envelope to grid shape
 *
 * 为什么这层映射放在 api 层，而不是留到视图里：
 * - 后端统一用 snake_case 的 `{ list, total, page, page_size, total_pages }`
 *   （`ApiController::paginate()`，见 `app/controller/ApiController.php`）；
 * - vxe-table 适配器把 `proxyConfig.response` 配成了
 *   `{ result: 'items', total: 'total', list: 'items' }`（`src/adapter/vxe-table.ts`），
 *   即网格只认 `{ items, total }`；
 * - 这里是两套命名约定的**唯一交界**。散落到每个视图里，意味着将来改适配器要改所有列表页；
 *   收进 api 层后，视图只关心「拿到的就是网格能吃的形状」。
 *
 * @param result 后端原始分页信封
 * @returns 网格可直接消费的 `{ items, total }`
 */
function toGridPage<T>(
  result: WorkflowTaskApi.Paginated<T>,
): WorkflowTaskApi.GridPage<T> {
  return {
    items: result?.list ?? [],
    total: result?.total ?? 0,
  };
}

/**
 * 我的待办 | Get pending tasks of current user
 *
 * `GET /v1/workflow/tasks/pending`（权限码 `workflow_task.read`）
 *
 * 「待办」= 在办状态（`pending` / `claimed` / `escalated`）+（受理人是我 或 我在候选用户里
 * 或 我的角色命中候选组）。判定下推到 SQL 后再分页，因此 `total` 与 `items` 一定同口径。
 *
 * @param params 仅分页参数
 */
async function getPendingTasks(params: WorkflowTaskApi.PendingTaskParams = {}) {
  const result = await requestClient.get<
    WorkflowTaskApi.Paginated<WorkflowTaskApi.WorkflowTask>
  >('/v1/workflow/tasks/pending', { params });

  return toGridPage(result);
}

/**
 * 全部任务 | List all workflow tasks
 *
 * `GET /v1/workflow/tasks`（权限码 `workflow_task.read`）
 *
 * 与待办的区别：这里返回**同租户内**所有任务（不限受理人），
 * 支持 `status` / `assignee` / `instance_id` 筛选。
 *
 * @param params 分页 + 可选筛选
 */
async function getTaskList(params: WorkflowTaskApi.TaskListParams = {}) {
  const result = await requestClient.get<
    WorkflowTaskApi.Paginated<WorkflowTaskApi.WorkflowTask>
  >('/v1/workflow/tasks', { params });

  return toGridPage(result);
}

/**
 * 任务详情 | Get task detail
 *
 * `GET /v1/workflow/tasks/{id}`（权限码 `workflow_task.read`）
 *
 * 返回任务行 + `history`（该任务的宿主动作事件）。只做租户级校验：
 * 同租户内的任务详情可见，「谁能操作」由各动作端点自己把关。
 *
 * @param id 任务ID
 */
async function getTask(id: number) {
  return requestClient.get<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}`,
  );
}

/**
 * 领取任务 | Claim task
 *
 * `POST /v1/workflow/tasks/{id}/claim`（权限码 `workflow_task.write`）
 *
 * 无请求体。操作人只取 JWT 的 `sub`；后端会校验「是本租户任务 + 状态为 pending +
 * 尚未被受理 + 我确实在候选范围内」，不满足则返回业务错误码。
 *
 * @param id 任务ID
 */
async function claimTask(id: number) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/claim`,
  );
}

/**
 * 取消领取 | Unclaim task
 *
 * `POST /v1/workflow/tasks/{id}/unclaim`（权限码 `workflow_task.write`）
 *
 * 无请求体。只有受理人本人、且任务处于 `claimed` 状态才能归还；归还后任务回到 `pending`。
 *
 * @param id 任务ID
 */
async function unclaimTask(id: number) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/unclaim`,
  );
}

export { claimTask, getPendingTasks, getTask, getTaskList, unclaimTask };
