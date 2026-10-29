import type { VbenFormSchema } from '#/adapter/form';
import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { SystemUserApi } from '#/api/system/user';

import { z } from '#/adapter/form';
import { $t } from '#/locales';

/**
 * User form schema | 用户表单配置
 */
export function useFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'username',
      label: $t('system.user.username'),
      rules: z
        .string()
        .min(3, { message: $t('system.user.usernameMinLength') })
        .max(50, { message: $t('system.user.usernameMaxLength') })
        .regex(/^[a-zA-Z0-9_]+$/, { message: $t('system.user.usernameFormat') }),
    },
    {
      component: 'Input',
      fieldName: 'email',
      label: $t('system.user.email'),
      rules: z.string().email({ message: $t('system.user.emailInvalid') }),
    },
    {
      component: 'Input',
      componentProps: {
        type: 'password',
        placeholder: $t('system.user.passwordPlaceholder'),
      },
      fieldName: 'password',
      label: $t('system.user.password'),
      dependencies: {
        if: (values) => !values.id,
        triggerFields: ['id'],
      },
      rules: z
        .string()
        .min(6, { message: $t('system.user.passwordMinLength') }),
    },
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.user.name'),
    },
    {
      component: 'Input',
      fieldName: 'phone',
      label: $t('system.user.phone'),
    },
    {
      component: 'Select',
      componentProps: {
        options: [
          { label: $t('system.user.statusActive'), value: 'active' },
          { label: $t('system.user.statusInactive'), value: 'inactive' },
          { label: $t('system.user.statusLocked'), value: 'locked' },
        ],
      },
      defaultValue: 'active',
      fieldName: 'status',
      label: $t('system.user.status'),
    },
  ];
}

/**
 * Grid search form schema | 表格搜索表单配置
 */
export function useGridFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'keyword',
      label: $t('system.user.keyword'),
      componentProps: {
        placeholder: $t('system.user.keywordPlaceholder'),
      },
    },
    {
      component: 'Select',
      componentProps: {
        allowClear: true,
        options: [
          { label: $t('system.user.statusActive'), value: 'active' },
          { label: $t('system.user.statusInactive'), value: 'inactive' },
          { label: $t('system.user.statusLocked'), value: 'locked' },
        ],
        placeholder: $t('system.user.statusPlaceholder'),
      },
      fieldName: 'status',
      label: $t('system.user.status'),
    },
  ];
}

/**
 * Table columns | 表格列配置
 */
export function useColumns<T = SystemUserApi.SystemUser>(
  onActionClick: OnActionClickFn<T>,
  onStatusChange?: (row: T, status: SystemUserApi.UserStatus) => void,
): VxeTableGridOptions['columns'] {
  return [
    {
      field: 'id',
      title: $t('system.user.id'),
      width: 80,
    },
    {
      field: 'username',
      title: $t('system.user.username'),
      width: 150,
    },
    {
      field: 'email',
      title: $t('system.user.email'),
      width: 200,
    },
    {
      field: 'name',
      title: $t('system.user.name'),
      width: 120,
    },
    {
      field: 'phone',
      title: $t('system.user.phone'),
      width: 130,
    },
    {
      cellRender: {
        name: 'CellTag',
        props: ({ row }: { row: SystemUserApi.SystemUser }) => {
          const statusMap: Record<
            SystemUserApi.UserStatus,
            { color: string; text: string }
          > = {
            active: {
              color: 'success',
              text: $t('system.user.statusActive'),
            },
            inactive: {
              color: 'default',
              text: $t('system.user.statusInactive'),
            },
            locked: {
              color: 'error',
              text: $t('system.user.statusLocked'),
            },
          };
          const status = statusMap[row.status] || statusMap.inactive;
          return {
            color: status.color,
            text: status.text,
          };
        },
      },
      field: 'status',
      title: $t('system.user.status'),
      width: 100,
    },
    {
      field: 'created_at',
      title: $t('system.user.createTime'),
      width: 180,
    },
    {
      align: 'center',
      cellRender: {
        attrs: {
          nameField: 'username',
          nameTitle: $t('system.user.title'),
          onClick: onActionClick,
        },
        name: 'CellOperation',
        options: [
          { code: 'edit', text: $t('common.edit') },
          { code: 'roles', text: $t('system.user.assignRoles'), more: true },
          { code: 'resetPassword', text: $t('system.user.resetPassword'), more: true },
          { code: 'delete', text: $t('common.delete'), danger: true },
        ],
      },
      field: 'operation',
      fixed: 'right',
      title: $t('system.user.operation'),
      width: 150,
    },
  ];
}

/**
 * Password reset form schema | 密码重置表单配置
 */
export function useResetPasswordSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      componentProps: {
        type: 'password',
        placeholder: $t('system.user.newPasswordPlaceholder'),
      },
      fieldName: 'new_password',
      label: $t('system.user.newPassword'),
      help: $t('system.user.newPasswordHelp'),
      rules: z
        .string()
        .min(6, { message: $t('system.user.passwordMinLength') })
        .optional(),
    },
  ];
}
