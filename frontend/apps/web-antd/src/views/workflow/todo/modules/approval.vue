<script lang="ts" setup>
import type { WorkflowTaskApi } from '#/api/workflow/task';
import type { WorkflowUserApi } from '#/api/workflow/user';

import { computed, nextTick, ref } from 'vue';

import { useVbenDrawer } from '@vben/common-ui';

import {
  Alert,
  Button,
  Descriptions,
  DescriptionsItem,
  Dropdown,
  message,
  Select,
  Spin,
  Tag,
  Textarea,
} from 'ant-design-vue';

import { useVbenForm } from '#/adapter/form';
import {
  approveTask,
  claimTask,
  completeTask,
  delegateTask,
  getTask,
  getTaskForm,
  rejectTask,
  submitTaskForm,
  transferTask,
  unclaimTask,
} from '#/api/workflow/task';
import { searchWorkflowUsers } from '#/api/workflow/user';
import { $t } from '#/locales';

import { formatAssignee, getTaskStatusOptions } from '../data';
import { toVbenFormSchema } from './schema-form';

/**
 * 审批抽屉 | Workflow task approval drawer
 *
 * 范式与 `views/system/role/modules/form.vue` 一致：`useVbenDrawer` + `useVbenForm` +
 * 表单自带的按钮条关掉（`showDefaultActions: false`），动作按钮由本组件自己排布 ——
 * 审批有 8 个动作，默认的「取消/确定」两颗按钮装不下，而且每个动作的可用条件不同。
 *
 * ## 数据来源（两个端点并行拉，失败各自降级）
 *
 * - `GET /v1/workflow/tasks/{id}`：任务行 + `history`（本包只用到任务行）；
 * - `GET /v1/workflow/tasks/{id}/form`：`form_schema`（字段列表）+ `form_rules` / `form_messages`
 *   （后端校验）+ `form_data`（回显）+ `resolved` / `reason`（模板是否解析到、为什么没解析到）；
 * - `GET /v1/workflow/users`（`page_size=100`）：转交 / 委派的选人目录。
 *
 * 为什么用 `Promise.allSettled` 而不是 `Promise.all`：选人目录失败（例如某租户没配这个权限）
 * 不该连带审批界面整块白掉 —— 审批本身不需要选人。`Promise.all` 会让「一个人挂了全体挂」。
 * 每个请求的具体错误文案仍由 `api/request.ts` 的 `errorMessageResponseInterceptor` toast，
 * 这里**不重复弹一次**，只把「任务本身没拿到」这件事显示成一个明确的 Alert。
 *
 * ## 错误处理约定
 *
 * - 拦截器已 toast `message`，本组件不再弹第二条 toast；
 * - **额外**捕获 422 的字段级错误（`error.response.data.data.errors`，形如 `{字段: 文案}`），
 *   按 schema 的 `title` 翻译成用户看得懂的字段名，渲染成 `Alert type="error"` 的列表 ——
 *   只弹一句「校验失败」等于让用户自己猜是哪一项不合规。
 */
const emits = defineEmits(['success']);

/**
 * 选人目录一次拉多少条
 *
 * 后端 `page_size` 上限就是 100（`ApiController::validatePagination()`），一次拉满可避免
 * 「转交时目标用户不在前 20 条里就选不到」这种最容易被当成 bug 的行为。
 */
const USER_PAGE_SIZE = 100;

/**
 * 抽屉里能发起的动作 | Actions available in the drawer
 *
 * 与后端端点一一对应（`route/workflow.php` 的 `v1/workflow/tasks/:id/*`）：
 * `approve` / `reject` / `complete` / `form`（提交表单）是审批类动作，
 * `claim` / `unclaim` / `transfer` / `delegate` 是任务归属类动作。
 *
 * 其中「同意 / 完成」在后端是「带了 `form_data` 就校验、不带就跳过」，所以只在有表单时才带；
 * 「提交表单」在后端**必须**带非空 `form_data`（否则控制器直接 422）。
 */
type ActionKey =
  | 'approve'
  | 'claim'
  | 'complete'
  | 'delegate'
  | 'form'
  | 'reject'
  | 'transfer'
  | 'unclaim';

/** 加载中（任务详情 / 表单 / 选人目录三者并行期间） */
const loading = ref(false);
/** 任务行（`GET /v1/workflow/tasks/{id}`） */
const task = ref<WorkflowTaskApi.WorkflowTask>();
/** 任务表单（`GET /v1/workflow/tasks/{id}/form`） */
const formMeta = ref<WorkflowTaskApi.TaskForm>();
/** 选人目录（转交 / 委派） */
const users = ref<WorkflowUserApi.WorkflowUser[]>([]);
/** 审批意见（可选，后端不要求必填） */
const comment = ref('');
/** 选中的目标用户ID（转交 `target_user` / 委派 `delegate_to` 共用） */
const targetUserId = ref<number>();
/** 422 字段级错误（`{字段名, 字段标题, 文案}`） */
const submitErrors = ref<
  Array<{ field: string; label: string; message: string }>
>([]);
/** 任务详情是否加载失败（表单失败不算，见 {@link isBrokenForm}） */
const loadFailed = ref(false);

/**
 * 表单实例
 *
 * schema 先给空数组：模板与字段都来自后端，`setup()` 时还没拿到，只能在抽屉打开后用
 * `formApi.setState({ schema })` 灌进去。`showDefaultActions: false` 关掉表单自带的
 * 重置/提交按钮 —— 提交动作由抽屉底部统一排布。
 */
const [Form, formApi] = useVbenForm({
  schema: [],
  showDefaultActions: false,
});

const [Drawer, drawerApi] = useVbenDrawer({
  async onOpenChange(isOpen) {
    if (!isOpen) {
      return;
    }

    const taskId = drawerApi.getData<{ taskId?: number }>()?.taskId;
    resetState();

    if (!taskId) {
      // 理论上不会发生（列表页一定会 setData({ taskId })），但缺了就不该发请求
      loadFailed.value = true;
      return;
    }

    loading.value = true;
    try {
      const [taskResult, formResult, usersResult] = await Promise.allSettled([
        getTask(taskId),
        getTaskForm(taskId),
        searchWorkflowUsers({ page_size: USER_PAGE_SIZE }),
      ]);

      if (taskResult.status === 'fulfilled') {
        task.value = taskResult.value;
      } else {
        loadFailed.value = true;
      }

      if (formResult.status === 'fulfilled') {
        formMeta.value = formResult.value;
        await applyFormSchema(formResult.value);
      }

      if (usersResult.status === 'fulfilled') {
        users.value = usersResult.value.items;
      }
    } catch (error) {
      // 兜底：请求失败已由 allSettled 各自降级，这里接住「灌表单」等本地步骤的异常，
      // 否则会变成未捕获的 Promise 拒绝（`onOpenChange` 是同步调用的，没人 await 它）
      console.error('[workflow/todo] 审批抽屉初始化失败', error);
    } finally {
      loading.value = false;
    }
  },
});

/**
 * 抽屉自身是否处于「提交中」| Whether the drawer is locked
 *
 * 动作按钮**不在**抽屉的内容区（内容区在 `submitting` 时被加了 `pointer-events-none`），
 * 而是在 `#footer` 插槽里 —— 插槽内容不会自动被禁用，所以必须显式读这个状态。
 * 否则 `lock()` 之后按钮仍可点击，连点两下就是两条审批请求
 * （`lock()` 与按钮的 disabled 是两道不同的闸，缺一不可）。
 */
const submitting = drawerApi.useStore((state) => state.submitting ?? false);

/** 加载中或提交中：底部动作区统一按这个状态禁用（两个来源任一为真都不该再发请求） */
const busy = computed(() => loading.value || submitting.value);

/** 关闭时清空本地状态（`destroyOnClose` 也会重建组件，这里只是双保险） */
function resetState() {
  loading.value = false;
  loadFailed.value = false;
  task.value = undefined;
  formMeta.value = undefined;
  users.value = [];
  comment.value = '';
  targetUserId.value = undefined;
  submitErrors.value = [];
  formApi.setState({ schema: [] });
}

/**
 * 把后端 schema 灌进表单，并回显已提交的值
 *
 * 顺序是硬性的：先 `setState({ schema })`（此时表单字段才第一次渲染），
 * `nextTick()` 等字段挂载，再 `setValues()`。反过来做的话，`setValues` 会作用在
 * 一个还没有字段的表单上，值被静默丢弃，用户看到的是空表单。
 */
async function applyFormSchema(form: WorkflowTaskApi.TaskForm) {
  const schema = toVbenFormSchema(form.form_schema, form.form_data);
  if (schema.length === 0) {
    return;
  }

  formApi.setState({ schema });
  await nextTick();
  await formApi.setValues(form.form_data ?? {});
}

/** 后端字段列表（空数组 = 本节点没有可渲染的字段） */
const fields = computed(() => formMeta.value?.form_schema ?? []);
/** 是否存在可渲染的表单 */
const hasForm = computed(() => fields.value.length > 0);
/** 表单未解析到的原因（`resolved = true` 时为 null） */
const formReason = computed(() => formMeta.value?.reason ?? null);

/**
 * 「本节点没配表单」—— 正常情形
 *
 * 只有 `node_has_no_form` 属于「设计如此」：节点本来就不需要填东西，可以直接审批。
 */
const isNodeWithoutForm = computed(
  () => !hasForm.value && formReason.value === 'node_has_no_form',
);

/**
 * 「表单配置异常」—— 需要运维介入的情形
 *
 * 其余四个原因（`form_not_found` / `definition_not_found` / `instance_not_found` /
 * `node_not_found`）都说明配置或数据坏了。后端刻意**不拦**审批（只记 WARN），
 * 前端因此也不禁用审批按钮 —— 否则一次表单配置失误会让在跑的流程**全部卡死**。
 */
const isBrokenForm = computed(
  () =>
    !hasForm.value &&
    formReason.value !== null &&
    formReason.value !== 'node_has_no_form',
);

/** 原因码 → 可读文案（含原始码，便于报障时对照日志） */
const formReasonText = computed(() => {
  const reason = formReason.value;
  if (!reason) {
    return '';
  }

  const known: Record<string, string> = {
    definition_not_found: $t('workflow.approval.reasonDefinitionNotFound'),
    form_not_found: $t('workflow.approval.reasonFormNotFound'),
    instance_not_found: $t('workflow.approval.reasonInstanceNotFound'),
    node_not_found: $t('workflow.approval.reasonNodeNotFound'),
  };

  return $t('workflow.approval.formUnresolvedReason', [
    known[reason] ?? reason,
    reason,
  ]);
});

/** 状态下拉/标签配置（与列表页同一份，避免两处颜色/文案不一致） */
const statusOptions = computed(() => getTaskStatusOptions());
/** 当前状态对应的标签配置（后端出现未知状态时为 undefined，下面按原始字符串透传） */
const statusOption = computed(() =>
  statusOptions.value.find((item) => item.value === task.value?.status),
);

/** 受理人展示文本（与列表页同一口径：姓名（ID）→ 姓名 → ID → —） */
const assigneeText = computed(() =>
  task.value ? formatAssignee(task.value) : '—',
);

/** 是否已有受理人（`assignee` 为 null/undefined 即未认领） */
const hasAssignee = computed(
  () => task.value?.assignee !== null && task.value?.assignee !== undefined,
);

/** 可认领：待处理 + 尚无受理人（后端同样要求「我在候选范围内」） */
const canClaim = computed(
  () => task.value?.status === 'pending' && !hasAssignee.value,
);

/** 可取消认领：只有 `claimed` 状态能归还（后端同口径） */
const canUnclaim = computed(() => task.value?.status === 'claimed');

/** 是否已在选人框里选中目标用户 */
const hasTargetUser = computed(() => typeof targetUserId.value === 'number');

/** 选人下拉的选项（姓名 → 登录名 → ID 兜底） */
const userOptions = computed(() =>
  users.value.map((user) => ({
    label: formatUserLabel(user),
    value: user.id,
  })),
);

/**
 * 用户展示名 | User display label
 *
 * `name` / `username` 都可能为空（老数据、仅导入账号），所以三级兜底；
 * 带上 ID 是为了后端报「不能转给自己」之类的错误时能对上号。
 */
function formatUserLabel(user: WorkflowUserApi.WorkflowUser): string {
  const name = user.name?.trim() ?? '';
  const username = user.username?.trim() ?? '';

  if (name && username && name !== username) {
    return `${name}（${username} / ${user.id}）`;
  }
  if (name) {
    return `${name}（${user.id}）`;
  }
  if (username) {
    return `${username}（${user.id}）`;
  }
  return String(user.id);
}

/** 选人框本地过滤（选项是一次性拉满的目录，后端不再提供远端搜索） */
function filterUserOption(input: string, option: any): boolean {
  const keyword = input.trim().toLowerCase();
  if (!keyword) {
    return true;
  }
  return String(option?.label ?? '')
    .toLowerCase()
    .includes(keyword);
}

/** 「更多」下拉里的动作（顺序即展示顺序） */
const moreActions = computed(() => {
  const actions: Array<ActionKey> = ['complete'];
  // 提交表单只在有表单时出现 —— 没有表单就没什么可提交的（后端也会 422）
  if (hasForm.value) {
    actions.push('form');
  }
  actions.push('transfer', 'delegate');
  if (canClaim.value) {
    actions.push('claim');
  }
  if (canUnclaim.value) {
    actions.push('unclaim');
  }
  return actions;
});

/** 「更多」下拉的菜单项（转交/委派未选人时置灰） */
const moreMenuItems = computed(() =>
  moreActions.value.map((key) => ({
    disabled: needsTargetUser(key) && !hasTargetUser.value,
    key,
    label: actionLabel(key),
  })),
);

/** 动作按钮文案 */
function actionLabel(key: ActionKey): string {
  const labels: Record<ActionKey, string> = {
    approve: $t('workflow.approval.actionApprove'),
    claim: $t('workflow.approval.actionClaim'),
    complete: $t('workflow.approval.actionComplete'),
    delegate: $t('workflow.approval.actionDelegate'),
    form: $t('workflow.approval.actionSubmitForm'),
    reject: $t('workflow.approval.actionReject'),
    transfer: $t('workflow.approval.actionTransfer'),
    unclaim: $t('workflow.approval.actionUnclaim'),
  };

  return labels[key];
}

/** 该动作是否必须先选目标用户 */
function needsTargetUser(key: ActionKey): boolean {
  return key === 'transfer' || key === 'delegate';
}

/**
 * 字段名 → 字段标题（422 错误列表里用标题而不是 `f_amount` 这类原始键）
 *
 * 标题来源与表单渲染完全一致（同一份 `form_schema` 的 `title`），
 * 因此用户看到的字段名与他在表单里看到的那一行是同一个词。
 */
const fieldTitles = computed(() => {
  const map: Record<string, string> = {};
  for (const field of fields.value) {
    map[field.field] = field.title || field.field;
  }
  return map;
});

/**
 * 从请求异常里取 422 的字段级错误
 *
 * 后端统一响应是 `{code, message, data, timestamp}`（`ApiController::error()`），
 * 校验失败时 `data.errors` 是 `{字段: 文案}`；文案可能是数组（多规则命中），拼成一行。
 * 取不到（网络错误 / 403 / 500）就返回空数组 —— 这类错误拦截器已经 toast 过，
 * 这里再显示一遍只会制造噪音。
 */
function extractSubmitErrors(
  error: any,
): Array<{ field: string; label: string; message: string }> {
  const response = error?.response;
  const errors = response?.data?.data?.errors;

  if (response?.status !== 422 || !errors || typeof errors !== 'object') {
    return [];
  }

  return Object.entries(errors).map(([field, rawMessage]) => ({
    field,
    label: fieldTitles.value[field] ?? field,
    message: Array.isArray(rawMessage)
      ? rawMessage.join('；')
      : String(rawMessage),
  }));
}

/**
 * 取当前表单值（校验不过返回 null，调用方据此中止）
 *
 * - 没有表单：返回空对象（同意/完成不带 `form_data` 也能过，后端视其为「无表单」）；
 * - 校验不过：返回 null，不发请求（前端先拦一道，省一次 422 往返）；
 * - 去掉 `undefined`：`axios` 序列化会丢键，留着只会让「看起来传了、其实没传」更难排查。
 */
async function resolveFormData(): Promise<null | Record<string, any>> {
  if (!hasForm.value) {
    return {};
  }

  const { valid } = await formApi.validate();
  if (!valid) {
    return null;
  }

  const values = await formApi.getValues();
  const formData: Record<string, any> = {};
  for (const [key, value] of Object.entries(values ?? {})) {
    if (value !== undefined) {
      formData[key] = value;
    }
  }

  return formData;
}

/** 审批意见（去空白；空串等价于「没填」，后端本来也把 `comment` 当可选） */
function commentPayload(): { comment?: string } {
  const text = comment.value.trim();
  return text ? { comment: text } : {};
}

/** 有值才带 `form_data`（后端对「带了但为空」也会走校验分支，没必要撞） */
function withFormData(
  base: Record<string, any>,
  formData: Record<string, any>,
): Record<string, any> {
  return Object.keys(formData).length > 0
    ? { ...base, form_data: formData }
    : base;
}

/**
 * 统一的提交包装：锁定抽屉 → 调接口 → 成功关闭 / 失败解锁并展示字段级错误
 *
 * `drawerApi.lock()` 期间抽屉内容被 spinner 覆盖且不可点击，这是防重复提交的唯一手段
 * （`lock()` 内部置 `submitting: true`，`close()` 会把它复位）。
 */
async function submit(action: () => Promise<unknown>) {
  submitErrors.value = [];
  drawerApi.lock();

  try {
    await action();
    emits('success');
    drawerApi.close();
  } catch (error) {
    submitErrors.value = extractSubmitErrors(error);
    drawerApi.unlock();
  }
}

/** 同意 */
async function onApprove() {
  const formData = await resolveFormData();
  const taskId = task.value?.id;
  if (formData === null || !taskId) {
    return;
  }

  await submit(() =>
    approveTask(taskId, withFormData(commentPayload(), formData)),
  );
}

/** 驳回（后端没有「理由必填」，这里也不校验） */
async function onReject() {
  const taskId = task.value?.id;
  if (!taskId) {
    return;
  }

  await submit(() => rejectTask(taskId, commentPayload()));
}

/** 「更多」下拉点击分发 */
function onMoreAction(info: { key: number | string }) {
  const key = info.key as ActionKey;

  if (!moreActions.value.includes(key)) {
    return;
  }

  if (needsTargetUser(key) && !hasTargetUser.value) {
    // 菜单项本来就是 disabled，这里再兜一道：disabled 只挡鼠标，不挡键盘/程序触发
    message.warning($t('workflow.approval.targetUserRequired'));
    return;
  }

  onAction(key);
}

/** 执行「更多」里的某个动作 */
async function onAction(key: ActionKey) {
  switch (key) {
    case 'claim': {
      await onClaim();
      break;
    }
    case 'complete': {
      await onComplete();
      break;
    }
    case 'delegate': {
      await onHandOver('delegate');
      break;
    }
    case 'form': {
      await onSubmitForm();
      break;
    }
    case 'transfer': {
      await onHandOver('transfer');
      break;
    }
    case 'unclaim': {
      await onUnclaim();
      break;
    }
    default: {
      break;
    }
  }
}

/** 完成 */
async function onComplete() {
  const formData = await resolveFormData();
  const taskId = task.value?.id;
  if (formData === null || !taskId) {
    return;
  }

  await submit(() =>
    completeTask(taskId, withFormData(commentPayload(), formData)),
  );
}

/** 提交表单（后端要求 `form_data` 非空，前端先拦一次） */
async function onSubmitForm() {
  const formData = await resolveFormData();
  const taskId = task.value?.id;
  if (formData === null || !taskId) {
    return;
  }

  if (Object.keys(formData).length === 0) {
    message.warning($t('workflow.approval.formDataRequired'));
    return;
  }

  await submit(() =>
    submitTaskForm(taskId, { ...commentPayload(), form_data: formData }),
  );
}

/** 领取（无请求体，操作人身份只取 JWT） */
async function onClaim() {
  const taskId = task.value?.id;
  if (!taskId) {
    return;
  }

  await submit(() => claimTask(taskId));
}

/** 取消领取（无请求体） */
async function onUnclaim() {
  const taskId = task.value?.id;
  if (!taskId) {
    return;
  }

  await submit(() => unclaimTask(taskId));
}

/** 转交 / 委派（两者只差后端语义与字段名） */
async function onHandOver(kind: 'delegate' | 'transfer') {
  const taskId = task.value?.id;
  const target = targetUserId.value;

  if (!taskId) {
    return;
  }

  if (target === undefined) {
    message.warning($t('workflow.approval.targetUserRequired'));
    return;
  }

  await submit(() =>
    kind === 'transfer'
      ? transferTask(taskId, { ...commentPayload(), target_user: target })
      : delegateTask(taskId, { ...commentPayload(), delegate_to: target }),
  );
}
</script>
<template>
  <Drawer :title="$t('workflow.approval.title')">
    <Spin :spinning="loading">
      <div class="flex flex-col gap-4">
        <!-- 任务详情加载失败：表单解析异常另行提示（见下方的 warning 警告块） -->
        <Alert
          v-if="loadFailed"
          show-icon
          type="error"
          :message="$t('workflow.approval.loadFailed')"
        />

        <!-- 任务信息（列名与列表页同一套 workflow.task.* 文案） -->
        <Descriptions :column="1" bordered size="small">
          <DescriptionsItem :label="$t('workflow.task.title')">
            {{ task?.title ?? '—' }}
          </DescriptionsItem>
          <DescriptionsItem :label="$t('workflow.task.node')">
            {{ task?.node_name ?? '—' }}
          </DescriptionsItem>
          <DescriptionsItem :label="$t('workflow.task.status')">
            <Tag :color="statusOption?.color">
              {{ statusOption?.label ?? task?.status ?? '—' }}
            </Tag>
          </DescriptionsItem>
          <DescriptionsItem :label="$t('workflow.task.assignee')">
            {{ assigneeText }}
          </DescriptionsItem>
          <DescriptionsItem :label="$t('workflow.task.instanceId')">
            {{ task?.instance_id ?? '—' }}
          </DescriptionsItem>
          <DescriptionsItem :label="$t('workflow.task.createdAt')">
            {{ task?.created_at ?? '—' }}
          </DescriptionsItem>
        </Descriptions>

        <!-- 422 字段级错误：后端 message 已由请求拦截器 toast，这里补「哪一项不合规」 -->
        <Alert
          v-if="submitErrors.length > 0"
          show-icon
          type="error"
          :message="$t('workflow.approval.submitFailed')"
        >
          <template #description>
            <ul class="list-disc pl-4">
              <li v-for="item in submitErrors" :key="item.field">
                {{ item.label }}：{{ item.message }}
              </li>
            </ul>
          </template>
        </Alert>

        <!-- 表单区：有模板就渲染；没有模板时按 reason 区分「正常」与「配置异常」 -->
        <div v-if="hasForm" class="flex flex-col gap-2">
          <div class="text-base font-medium">
            {{ $t('workflow.approval.formTitle') }}
          </div>
          <Form />
        </div>
        <Alert
          v-else-if="isNodeWithoutForm"
          show-icon
          type="info"
          :message="$t('workflow.approval.nodeHasNoForm')"
        />
        <Alert
          v-else-if="isBrokenForm"
          show-icon
          type="warning"
          :description="formReasonText"
          :message="$t('workflow.approval.formUnresolved')"
        />

        <!-- 审批意见（可选，后端不要求必填） -->
        <div class="flex flex-col gap-2">
          <div class="text-base font-medium">
            {{ $t('workflow.approval.comment') }}
          </div>
          <Textarea
            v-model:value="comment"
            :maxlength="500"
            :placeholder="$t('workflow.approval.commentPlaceholder')"
            :rows="3"
          />
        </div>

        <!-- 选人：转交 / 委派共用；未选人时两个动作置灰并给出中文提示 -->
        <div class="flex flex-col gap-2">
          <div class="text-base font-medium">
            {{ $t('workflow.approval.targetUser') }}
          </div>
          <Select
            v-model:value="targetUserId"
            allow-clear
            class="w-full"
            :filter-option="filterUserOption"
            :options="userOptions"
            :placeholder="$t('workflow.approval.targetUserPlaceholder')"
            show-search
          />
          <div v-if="!hasTargetUser" class="text-xs text-gray-500">
            {{ $t('workflow.approval.targetUserRequired') }}
          </div>
        </div>
      </div>
    </Spin>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-end gap-2">
        <Button :disabled="busy" @click="drawerApi.close()">
          {{ $t('workflow.approval.actionClose') }}
        </Button>
        <Dropdown
          :disabled="busy"
          :menu="{ items: moreMenuItems, onClick: onMoreAction }"
          placement="topRight"
          trigger="click"
        >
          <Button :disabled="busy">
            {{ $t('workflow.approval.actionMore') }}
          </Button>
        </Dropdown>
        <Button danger :disabled="busy" @click="onReject">
          {{ $t('workflow.approval.actionReject') }}
        </Button>
        <Button
          :disabled="busy"
          :loading="submitting"
          type="primary"
          @click="onApprove"
        >
          {{ $t('workflow.approval.actionApprove') }}
        </Button>
      </div>
    </template>
  </Drawer>
</template>
