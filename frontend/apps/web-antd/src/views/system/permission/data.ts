import type { VbenFormSchema } from '#/adapter/form';
import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { SystemPermissionApi } from '#/api/system/permission';

import { z } from '#/adapter/form';
import { $t } from '#/locales';

/**
 * Form schema for permission create/edit | 权限创建/编辑表单模式
 */
export function useFormSchema(): VbenFormSchema[] {
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
      componentProps: {
        placeholder: $t('system.permission.slugPlaceholder'),
      },
      fieldName: 'slug',
      label: $t('system.permission.slug'),
      rules: z
        .string()
        .min(1, { message: $t('system.permission.slugRequired') })
        .regex(/^[a-z0-9_.]+$/, { message: $t('system.permission.slugFormat') }),
    },
    {
      component: 'Input',
      fieldName: 'resource',
      label: $t('system.permission.resource'),
      componentProps: {
        placeholder: $t('system.permission.resourcePlaceholder'),
      },
    },
    {
      component: 'Input',
      fieldName: 'action',
      label: $t('system.permission.action'),
      componentProps: {
        placeholder: $t('system.permission.actionPlaceholder'),
      },
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
