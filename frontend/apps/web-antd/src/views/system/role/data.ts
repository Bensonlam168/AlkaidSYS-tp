import type { VbenFormSchema } from '#/adapter/form';
import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { SystemRoleApi } from '#/api/system/role';

import { z } from '#/adapter/form';
import { $t } from '#/locales';

export function useFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.role.roleName'),
      rules: 'required',
    },
    {
      component: 'Input',
      componentProps: {
        placeholder: $t('system.role.slugPlaceholder'),
      },
      fieldName: 'slug',
      label: $t('system.role.slug'),
      rules: z
        .string()
        .min(1, { message: $t('system.role.slugRequired') })
        .regex(/^[a-z0-9_-]+$/, { message: $t('system.role.slugFormat') }),
    },
    {
      component: 'Textarea',
      componentProps: {
        rows: 3,
      },
      fieldName: 'description',
      label: $t('system.role.remark'),
    },
  ];
}

export function useGridFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.role.roleName'),
    },
    {
      component: 'Input',
      fieldName: 'slug',
      label: $t('system.role.slug'),
    },
  ];
}

export function useColumns<T = SystemRoleApi.SystemRole>(
  onActionClick: OnActionClickFn<T>,
): VxeTableGridOptions['columns'] {
  return [
    {
      field: 'id',
      title: $t('system.role.id'),
      width: 80,
    },
    {
      field: 'name',
      title: $t('system.role.roleName'),
      width: 200,
    },
    {
      field: 'slug',
      title: $t('system.role.slug'),
      width: 150,
    },
    {
      field: 'description',
      minWidth: 200,
      title: $t('system.role.remark'),
    },
    {
      cellRender: {
        name: 'CellTag',
        props: ({ row }: { row: SystemRoleApi.SystemRole }) => ({
          color: row.is_system ? 'blue' : 'default',
          text: row.is_system
            ? $t('system.role.systemRole')
            : $t('system.role.customRole'),
        }),
      },
      field: 'is_system',
      title: $t('system.role.type'),
      width: 100,
    },
    {
      field: 'permissions',
      title: $t('system.role.permissions'),
      width: 120,
      cellRender: {
        name: 'CellTag',
        props: ({ row }: { row: SystemRoleApi.SystemRole }) => ({
          color: 'green',
          text: `${row.permissions?.length || 0} ${$t('system.role.permissionCount')}`,
        }),
      },
    },
    {
      field: 'created_at',
      title: $t('system.role.createTime'),
      width: 180,
    },
    {
      align: 'center',
      cellRender: {
        attrs: {
          nameField: 'name',
          nameTitle: $t('system.role.name'),
          onClick: onActionClick,
        },
        name: 'CellOperation',
      },
      field: 'operation',
      fixed: 'right',
      title: $t('system.role.operation'),
      width: 130,
    },
  ];
}
