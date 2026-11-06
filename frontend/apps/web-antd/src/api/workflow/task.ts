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

  /**
   * 任务表单里的单个字段 | One field of a task form
   *
   * 字段名与 `Infrastructure\Lowcode\Schema\SchemaParser::buildComponents()` 的输出**逐字对应**
   * （该输出即 `GET /v1/lowcode/forms/{name}?with_parsed=1` 的 `data.parsed.components`）：
   * 后端把 JSON Schema 的 `x-component` / `x-component-props` / `x-decorator` 等
   * 摊平成下面这些键，前端不需要再自己解析 JSON Schema。
   */
  export interface TaskFormComponent {
    /** 字段名（提交时 `form_data` 的键） */
    field: string;
    /** 字段标题（表单 label） */
    title: string;
    /** 渲染组件名，取值形如 `Input` / `Select` / `DatePicker`（映射见 `views/workflow/todo/modules/schema-form.ts`） */
    component: string;
    /** 装饰器组件名，一般是 `FormItem`（本包不使用，保留以对齐契约） */
    decorator: string;
    /** 组件参数（原样透传给 Vben 表单的 `componentProps`） */
    componentProps: Record<string, any>;
    /** 装饰器参数（本包不使用，保留以对齐契约） */
    decoratorProps: Record<string, any>;
    /** 是否必填（后端由 JSON Schema 的 `required` 数组换算） */
    required: boolean;
    /** 数据类型：`string` / `number` / ... */
    type: string;
    /** 枚举值域（后端由 JSON Schema 的 `enum` 透出，非枚举字段为空数组） */
    enum: any[];
  }

  /**
   * 任务表单 | Task form
   *
   * 契约以 `WorkflowTaskService::form()` 的 docblock 为准（`app/controller/workflow/WorkflowTaskController::getForm`）：
   * `resolved = false` 时 `reason` 一定非空，**不会**静默返回空 schema 让前端自己猜。
   */
  export interface TaskForm {
    /** 任务ID */
    task_id: number;
    /** 低代码表单名（未解析到时为 null） */
    form_name: null | string;
    /** 字段列表（未解析到时为 null） */
    form_schema: null | TaskFormComponent[];
    /** 后端校验规则（与低代码表单详情的 `validator.rules` 同源） */
    form_rules: Record<string, any>;
    /** 后端校验文案（与低代码表单详情的 `validator.messages` 同源） */
    form_messages: Record<string, any>;
    /** 已提交的表单值（`workflow_tasks.form_data` 解码后的对象） */
    form_data: Record<string, any>;
    /** 是否解析到表单模板 */
    resolved: boolean;
    /**
     * 未解析到的原因 | Why the form is unresolved
     *
     * `resolved = true` 时为 null；否则是下列之一（`WorkflowTaskFormResolver::REASON_*`）：
     * - `node_has_no_form`：节点**本来就没配**表单 —— 正常情形，可直接审批；
     * - `form_not_found`：节点配了表单名，但低代码表单不存在 —— 配置异常；
     * - `definition_not_found`：实例的流程定义版本行缺失 —— 配置异常；
     * - `instance_not_found`：实例缺失 —— 数据异常；
     * - `node_not_found`：节点配置里找不到该节点 —— 配置异常。
     */
    reason: null | string;
  }

  /**
   * 审批动作的公共入参 | Shared payload of task actions
   *
   * `comment` 一律可选：`WorkflowTaskService` 只对「转交/委派的目标用户」做必填校验
   * （`handOver()` 里 `target_user` / `delegate_to`），驳回也没有「理由必填」的要求。
   * 前端不自造后端没有的必填语义，否则会出现「后端接受、前端拦住」的假拦截。
   */
  export interface TaskActionPayload {
    /** 处理意见 / 审批意见（可选） */
    comment?: string;
  }

  /**
   * 同意入参 | Approve payload
   *
   * `POST /v1/workflow/tasks/{id}/approve`：`form_data` 可选，但**一旦带上就会先过后端表单校验**
   * （不合规 → 422，任务状态与 `form_data` 一个字节都不动）。
   */
  export interface ApproveTaskPayload extends TaskActionPayload {
    /** 表单值（表单类动作才需要） */
    form_data?: Record<string, any>;
  }

  /**
   * 驳回入参 | Reject payload
   *
   * `POST /v1/workflow/tasks/{id}/reject`：只有 `comment`（可选）。
   * ⚠️ 后端**没有**「驳回理由必填」，前端不加。
   */
  export type RejectTaskPayload = TaskActionPayload;

  /**
   * 完成入参 | Complete payload
   *
   * `POST /v1/workflow/tasks/{id}/complete`：`output` / `comment` 都可选。
   */
  export interface CompleteTaskPayload extends TaskActionPayload {
    /** 处理输出（落到任务 `result` 上，由引擎写入） */
    output?: Record<string, any>;
    /** 表单值（表单类动作才需要） */
    form_data?: Record<string, any>;
  }

  /**
   * 提交表单入参 | Submit form payload
   *
   * `POST /v1/workflow/tasks/{id}/form`：**`form_data` 必填且非空**，
   * 否则控制器直接 `validationError(['form_data' => ...])` 返回 422
   * （判定条件是 `!isset || !is_array || === []`）。
   */
  export interface SubmitTaskFormPayload extends TaskActionPayload {
    /** 表单值（非空对象） */
    form_data: Record<string, any>;
  }

  /**
   * 提交表单的结果 | Submit form result
   *
   * 控制器 `submitForm()` 只回这几个字段（不是整个任务行）。
   */
  export interface SubmitTaskFormResult {
    /** 任务ID */
    task_id: null | number;
    /** 提交后的表单值 */
    form_data: Record<string, any>;
    /** 提交后的任务状态 */
    status: null | string;
    /** 本次推进产生的新任务 */
    next_tasks: Array<Record<string, any>>;
    /**
     * 表单校验结论 | Form validation verdict
     *
     * `validated = false` + `needs_attention = true` 表示「模板缺失、后端已跳过校验」，
     * 前端据此提示运维，而不是把它当成一次正常提交悄悄放过。无表单时为 null。
     */
    form_validation: null | Record<string, any>;
  }

  /**
   * 转交入参 | Transfer payload
   *
   * `POST /v1/workflow/tasks/{id}/transfer`：**`target_user` 必填**（后端 `handOver()` 校验），
   * 且目标用户必须存在于当前租户，也不能是自己。
   */
  export interface TransferTaskPayload extends TaskActionPayload {
    /** 目标用户ID（本租户用户目录里的 `id`） */
    target_user: number;
  }

  /**
   * 委派入参 | Delegate payload
   *
   * `POST /v1/workflow/tasks/{id}/delegate`：**`delegate_to` 必填**；
   * 与转交的唯一可落库差异是原受理人仍留在候选集里。
   */
  export interface DelegateTaskPayload extends TaskActionPayload {
    /** 被委派人用户ID */
    delegate_to: number;
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
 *
 * @remarks
 * 导出是为了让 `./user.ts`（选人目录，同样是 `{ list, total, page, page_size, total_pages }`
 * 信封）复用同一份映射，而不是各自抄一遍 —— 两处抄两份，改适配器时就会漏改一处。
 */
export function toGridPage<T>(
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

/**
 * 任务表单 | Get task form
 *
 * `GET /v1/workflow/tasks/{id}/form`（权限码 `workflow_task.read`）
 *
 * 返回 `form_schema`（可渲染的字段列表）+ `form_rules` / `form_messages`（后端校验规则与文案）
 * + `form_data`（已提交值）+ `resolved` / `reason`（模板是否解析到、没解析到的原因）。
 *
 * 表单模板不是存在任务行上，而是沿
 * `task → instance.definition_id（具体版本行）→ nodes_config → 节点 properties.form → lowcode_forms`
 * 解析出来的，因此老实例读到的一定是它启动时那一版模板。
 *
 * @param id 任务ID
 */
async function getTaskForm(id: number) {
  return requestClient.get<WorkflowTaskApi.TaskForm>(
    `/v1/workflow/tasks/${id}/form`,
  );
}

/**
 * 提交任务表单 | Submit task form
 *
 * `POST /v1/workflow/tasks/{id}/form`（权限码 `workflow_task.write`）
 *
 * ⚠️ 语义是**推进流程**（`executeProcessTask()`），不是「暂存草稿」。
 * `form_data` 必填且非空（后端 422 兜底），提交前还会按后端表单规则校验，不过则 422 + `data.errors`。
 *
 * @param id 任务ID
 * @param payload `form_data`（非空）+ 可选 `comment`
 */
async function submitTaskForm(
  id: number,
  payload: WorkflowTaskApi.SubmitTaskFormPayload,
) {
  return requestClient.post<WorkflowTaskApi.SubmitTaskFormResult>(
    `/v1/workflow/tasks/${id}/form`,
    payload,
  );
}

/**
 * 同意 | Approve task
 *
 * `POST /v1/workflow/tasks/{id}/approve`（权限码 `workflow_task.write`）
 *
 * 走引擎 `executeProcessTask()`：任务状态、`result`、审批意见与事件都由引擎写；
 * 返回值额外带 `next_tasks`（本次推进产生的新任务）。
 * 带上 `form_data` 时会先过后端表单校验（不合规 422，任务一个字节不动）。
 *
 * @param id 任务ID
 * @param payload 可选 `comment` / `form_data`
 */
async function approveTask(
  id: number,
  payload: WorkflowTaskApi.ApproveTaskPayload = {},
) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/approve`,
    payload,
  );
}

/**
 * 驳回 | Reject task
 *
 * `POST /v1/workflow/tasks/{id}/reject`（权限码 `workflow_task.write`）
 *
 * 走引擎 `executeAndJumpToEnd()`：驳回 = 当前任务结束 + 跳到结束节点，实例进入驳回终态
 * （`cancelled`），任务行被纠正为 `rejected`。
 *
 * @param id 任务ID
 * @param payload 可选 `comment`（后端**不要求**驳回理由必填）
 */
async function rejectTask(
  id: number,
  payload: WorkflowTaskApi.RejectTaskPayload = {},
) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/reject`,
    payload,
  );
}

/**
 * 完成任务 | Complete task
 *
 * `POST /v1/workflow/tasks/{id}/complete`（权限码 `workflow_task.write`）
 *
 * `output` 落到任务 `result` 上，`comment` 是处理意见，两者都可选。
 *
 * @param id 任务ID
 * @param payload 可选 `output` / `comment` / `form_data`
 */
async function completeTask(
  id: number,
  payload: WorkflowTaskApi.CompleteTaskPayload = {},
) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/complete`,
    payload,
  );
}

/**
 * 转交任务 | Transfer task
 *
 * `POST /v1/workflow/tasks/{id}/transfer`（权限码 `workflow_task.write`）
 *
 * 落库形态是「`assignee` 改成目标用户 + 原受理人移出 `candidate_users` + `task_transferred` 事件」，
 * 任务状态并不会变成 `transferred`。`target_user` 必填，且不能是自己。
 *
 * @param id 任务ID
 * @param payload `target_user`（必填）+ 可选 `comment`
 */
async function transferTask(
  id: number,
  payload: WorkflowTaskApi.TransferTaskPayload,
) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/transfer`,
    payload,
  );
}

/**
 * 委派任务 | Delegate task
 *
 * `POST /v1/workflow/tasks/{id}/delegate`（权限码 `workflow_task.write`）
 *
 * 与转交的唯一可落库差异：原受理人**保留在 `candidate_users`**（委派不等于彻底交出去）。
 * `delegate_to` 必填，且不能是自己。
 *
 * @param id 任务ID
 * @param payload `delegate_to`（必填）+ 可选 `comment`
 */
async function delegateTask(
  id: number,
  payload: WorkflowTaskApi.DelegateTaskPayload,
) {
  return requestClient.post<WorkflowTaskApi.WorkflowTask>(
    `/v1/workflow/tasks/${id}/delegate`,
    payload,
  );
}

export {
  approveTask,
  claimTask,
  completeTask,
  delegateTask,
  getPendingTasks,
  getTask,
  getTaskForm,
  getTaskList,
  rejectTask,
  submitTaskForm,
  transferTask,
  unclaimTask,
};
