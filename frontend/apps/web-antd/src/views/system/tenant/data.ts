import type { VbenFormSchema } from '#/adapter/form';
import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { SystemTenantApi } from '#/api/system/tenant';

import { z } from '#/adapter/form';
import { $t } from '#/locales';

/**
 * Form schema for tenant create/edit
 */
export function useFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.tenant.name'),
      rules: 'required',
      componentProps: {
        placeholder: $t('system.tenant.namePlaceholder'),
      },
    },
    {
      component: 'Input',
      fieldName: 'slug',
      label: $t('system.tenant.slug'),
      rules: z
        .string()
        .min(1, { message: $t('system.tenant.slugRequired') })
        .regex(/^[a-z0-9_-]+$/, { message: $t('system.tenant.slugFormat') }),
      componentProps: {
        placeholder: $t('system.tenant.slugPlaceholder'),
      },
    },
    {
      component: 'Input',
      fieldName: 'domain',
      label: $t('system.tenant.domain'),
      componentProps: {
        placeholder: $t('system.tenant.domainPlaceholder'),
      },
    },
    {
      component: 'Select',
      componentProps: {
        options: [
          { label: $t('system.tenant.statusActive'), value: 'active' },
          { label: $t('system.tenant.statusInactive'), value: 'inactive' },
          { label: $t('system.tenant.statusSuspended'), value: 'suspended' },
        ],
      },
      defaultValue: 'active',
      fieldName: 'status',
      label: $t('system.tenant.status'),
    },
    {
      component: 'InputNumber',
      fieldName: 'max_sites',
      label: $t('system.tenant.maxSites'),
      defaultValue: 1,
      componentProps: {
        min: 1,
        max: 100,
        placeholder: $t('system.tenant.maxSitesPlaceholder'),
      },
    },
    {
      component: 'InputNumber',
      fieldName: 'max_users',
      label: $t('system.tenant.maxUsers'),
      defaultValue: 10,
      componentProps: {
        min: 1,
        max: 10000,
        placeholder: $t('system.tenant.maxUsersPlaceholder'),
      },
    },
    {
      component: 'DatePicker',
      fieldName: 'expires_at',
      label: $t('system.tenant.expiresAt'),
      componentProps: {
        showTime: true,
        format: 'YYYY-MM-DD HH:mm:ss',
        placeholder: $t('system.tenant.expiresAtPlaceholder'),
      },
    },
  ];
}

/**
 * Grid search form schema
 */
export function useGridFormSchema(): VbenFormSchema[] {
  return [
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.tenant.name'),
    },
    {
      component: 'Input',
      fieldName: 'slug',
      label: $t('system.tenant.slug'),
    },
    {
      component: 'Select',
      componentProps: {
        allowClear: true,
        options: [
          { label: $t('system.tenant.statusActive'), value: 'active' },
          { label: $t('system.tenant.statusInactive'), value: 'inactive' },
          { label: $t('system.tenant.statusSuspended'), value: 'suspended' },
        ],
      },
      fieldName: 'status',
      label: $t('system.tenant.status'),
    },
    {
      component: 'Input',
      fieldName: 'domain',
      label: $t('system.tenant.domain'),
    },
  ];
}

/**
 * Table columns configuration
 */
export function useColumns<T = SystemTenantApi.SystemTenant>(
  onActionClick: OnActionClickFn<T>,
  onStatusChange?: (
    newStatus: SystemTenantApi.TenantStatus,
    row: T,
  ) => PromiseLike<boolean | undefined>,
): VxeTableGridOptions['columns'] {
  return [
    {
      field: 'id',
      title: 'ID',
      width: 80,
    },
    {
      field: 'name',
      title: $t('system.tenant.name'),
      minWidth: 150,
    },
    {
      field: 'slug',
      title: $t('system.tenant.slug'),
      width: 150,
    },
    {
      field: 'domain',
      title: $t('system.tenant.domain'),
      width: 180,
    },
    {
      cellRender: {
        attrs: {
          beforeChange: onStatusChange,
          options: [
            {
              class: 'bg-green-500',
              label: $t('system.tenant.statusActive'),
              value: 'active',
            },
            {
              class: 'bg-gray-500',
              label: $t('system.tenant.statusInactive'),
              value: 'inactive',
            },
            {
              class: 'bg-red-500',
              label: $t('system.tenant.statusSuspended'),
              value: 'suspended',
            },
          ],
        },
        name: onStatusChange ? 'CellSwitch' : 'CellTag',
      },
      field: 'status',
      title: $t('system.tenant.status'),
      width: 120,
    },
    {
      field: 'max_sites',
      title: $t('system.tenant.maxSites'),
      width: 100,
    },
    {
      field: 'max_users',
      title: $t('system.tenant.maxUsers'),
      width: 100,
    },
    {
      field: 'expires_at',
      title: $t('system.tenant.expiresAt'),
      width: 180,
    },
    {
      field: 'created_at',
      title: $t('system.tenant.createdAt'),
      width: 180,
    },
    {
      align: 'center',
      cellRender: {
        attrs: {
          nameField: 'name',
          nameTitle: $t('system.tenant.title'),
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
