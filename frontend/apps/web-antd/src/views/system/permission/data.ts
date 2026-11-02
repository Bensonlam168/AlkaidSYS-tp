import type { VbenFormSchema } from '#/adapter/form';
import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { SystemPermissionApi } from '#/api/system/permission';

import { z } from '#/adapter/form';
import { $t } from '#/locales';

/**
 * 权限码三要素（后端视为**不可变**）
 *
 * `PUT /v1/admin/permissions/:id` 携带与当前值不同的 `slug` / `resource` / `action`
 * 会直接返回 **422**：权限码是权限校验与 Casbin 策略的锚点，改它等于换成另一个权限，
 * 必须走迁移（新增一条 + 迁移角色绑定），而不是原地改名。
 *
 * 因此前端在**编辑态**把这三个字段置灰（见 {@link useFormSchema}），
 * 只允许改 `name` / `description` —— 后端对「提交值与当前值相同」是幂等放行的，
 * 所以置灰后照旧整对象回传不会触发 422。
 *
 * @type {readonly string[]}
 */
export const PERMISSION_CODE_FIELDS = ['slug', 'resource', 'action'] as const;

/**
 * 权限表单 schema 的构建选项 | Options of the permission form schema
 */
export interface PermissionFormSchemaOptions {
  /**
   * 是否处于编辑态（新建 = false）
   *
   * 刻意收**函数**而不是布尔值：schema 只在 `setup()` 时构建一次，
   * 而抽屉每次打开都会切换新建/编辑。函数形式让 `form-field.vue` 的
   * `componentProps` 计算属性在每次求值时都读到最新的编辑态
   * （它内部会追踪这里读取的响应式依赖，从而驱动 `disabled` 实时生效）。
   */
  isEditMode?: () => boolean;
}

/**
 * Form schema for permission create/edit | 权限创建/编辑表单模式
 *
 * 编辑态：`slug` / `resource` / `action` 置灰（不可变，见 {@link PERMISSION_CODE_FIELDS}）；
 * 新建态：五个字段全部可编辑（越界的权限码由后端 403 拦截，错误信息由请求层正常展示）。
 */
export function useFormSchema(
  options: PermissionFormSchemaOptions = {},
): VbenFormSchema[] {
  const isEditMode = options.isEditMode ?? (() => false);

  /**
   * 不可变字段的公共 props（编辑态置灰 + 悬停说明）
   *
   * 用函数形式交给渲染层：`computedProps = computed(() => componentProps(values, api))`，
   * 这样 `isEditMode()` 变化时 `disabled` 会跟着变，无需重建 schema。
   */
  const immutableProps = (placeholder: string) => () => ({
    disabled: isEditMode(),
    placeholder,
    // 置灰后原生 title 仍可见：告诉用户「为什么不能改」，而不是只给一个灰框
    title: isEditMode() ? $t('system.permission.immutableTip') : undefined,
  });

  /**
   * slug 的格式规则：新建态严格，编辑态跳过格式部分
   *
   * 【为什么编辑态不校验格式】
   *   编辑态下 slug 是**置灰不可改**的，它的值来自服务端；而库里存在历史遗留的
   *   冒号格式权限码（实测 `lowcode:read` / `lowcode:write` / `lowcode:delete`，id 21–23）。
   *   若编辑态仍按 `resource.action` 校验，用户连「只改显示名」都保存不了 ——
   *   而那条历史数据的正确处置方式是迁移（改名 + 重绑角色），不该由编辑表单承担。
   *   新建态照旧严格校验：前端先拦一道，后端还有一道（越界码返回 403）。
   */
  const slugRules = z
    .string()
    .min(1, { message: $t('system.permission.slugRequired') })
    .refine((value) => isEditMode() || /^[a-z0-9_.]+$/.test(value), {
      message: $t('system.permission.slugFormat'),
    });

  return [
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.permission.name'),
      rules: 'required',
      componentProps: {
        placeholder: $t('system.permission.namePlaceholder'),
      },
    },
    {
      component: 'Input',
      componentProps: immutableProps($t('system.permission.slugPlaceholder')),
      fieldName: 'slug',
      label: $t('system.permission.slug'),
      rules: slugRules,
    },
    {
      component: 'Input',
      fieldName: 'resource',
      label: $t('system.permission.resource'),
      componentProps: immutableProps(
        $t('system.permission.resourcePlaceholder'),
      ),
    },
    {
      component: 'Input',
      fieldName: 'action',
      label: $t('system.permission.action'),
      componentProps: immutableProps($t('system.permission.actionPlaceholder')),
    },
    {
      component: 'Textarea',
      componentProps: {
        rows: 3,
      },
      fieldName: 'description',
      label: $t('system.permission.description'),
    },
  ];
}

/**
 * Grid search form schema | 网格搜索表单模式
 */
export function useGridFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.permission.name'),
    },
    {
      component: 'Input',
      fieldName: 'slug',
      label: $t('system.permission.slug'),
    },
    {
      component: 'Input',
      fieldName: 'resource',
      label: $t('system.permission.resource'),
    },
    {
      component: 'Input',
      fieldName: 'action',
      label: $t('system.permission.action'),
    },
  ];
}

/**
 * Table columns configuration | 表格列配置
 */
export function useColumns<T = SystemPermissionApi.SystemPermission>(
  onActionClick: OnActionClickFn<T>,
): VxeTableGridOptions['columns'] {
  return [
    {
      field: 'id',
      title: 'ID',
      width: 80,
    },
    {
      field: 'name',
      title: $t('system.permission.name'),
      minWidth: 150,
    },
    {
      field: 'slug',
      title: $t('system.permission.slug'),
      width: 180,
      cellRender: {
        name: 'CellTag',
        props: ({ row }: { row: SystemPermissionApi.SystemPermission }) => ({
          color: 'blue',
          text: row.slug,
        }),
      },
    },
    {
      field: 'resource',
      title: $t('system.permission.resource'),
      width: 120,
      cellRender: {
        name: 'CellTag',
        props: ({ row }: { row: SystemPermissionApi.SystemPermission }) => ({
          color: row.resource ? 'green' : 'default',
          text: row.resource || '-',
        }),
      },
    },
    {
      field: 'action',
      title: $t('system.permission.action'),
      width: 100,
      cellRender: {
        name: 'CellTag',
        props: ({ row }: { row: SystemPermissionApi.SystemPermission }) => ({
          color: row.action ? 'orange' : 'default',
          text: row.action || '-',
        }),
      },
    },
    {
      field: 'description',
      title: $t('system.permission.description'),
      minWidth: 200,
    },
    {
      field: 'created_at',
      title: $t('system.permission.createdAt'),
      width: 180,
    },
    {
      align: 'center',
      cellRender: {
        attrs: {
          nameField: 'name',
          nameTitle: $t('system.permission.title'),
          onClick: onActionClick,
        },
        name: 'CellOperation',
      },
      field: 'operation',
      fixed: 'right',
      title: $t('common.operation'),
      width: 130,
    },
  ];
}
