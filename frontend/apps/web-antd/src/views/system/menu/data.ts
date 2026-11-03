import type { Recordable } from '@vben/types';

import type { VbenFormSchema } from '#/adapter/form';
import type { OnActionClickFn, VxeTableGridOptions } from '#/adapter/vxe-table';
import type { SystemMenuApi } from '#/api/system/menu';

import { h } from 'vue';

import { IconifyIcon } from '@vben/icons';

import { z } from '#/adapter/form';
import {
  getMenuList,
  isMenuNameExists,
  isMenuPathExists,
} from '#/api/system/menu';
import { $t } from '#/locales';

/**
 * Get menu type options | 获取菜单类型选项
 */
export function getMenuTypeOptions() {
  return [
    {
      color: 'processing',
      label: $t('system.menu.typeCatalog'),
      value: 'catalog',
    },
    { color: 'default', label: $t('system.menu.typeMenu'), value: 'menu' },
    { color: 'error', label: $t('system.menu.typeButton'), value: 'button' },
    {
      color: 'success',
      label: $t('system.menu.typeEmbedded'),
      value: 'embedded',
    },
    { color: 'warning', label: $t('system.menu.typeLink'), value: 'link' },
  ];
}

/**
 * Menu form schema | 菜单表单配置
 */
export function useFormSchema(formData?: { id?: number }): VbenFormSchema[] {
  return [
    {
      component: 'RadioGroup',
      componentProps: {
        buttonStyle: 'solid',
        options: getMenuTypeOptions(),
        optionType: 'button',
      },
      defaultValue: 'menu',
      fieldName: 'type',
      formItemClass: 'col-span-2 md:col-span-2',
      label: $t('system.menu.type'),
    },
    {
      component: 'Input',
      fieldName: 'name',
      label: $t('system.menu.menuName'),
      rules: z
        .string()
        .min(2, $t('ui.formRules.minLength', [$t('system.menu.menuName'), 2]))
        .max(30, $t('ui.formRules.maxLength', [$t('system.menu.menuName'), 30]))
        .refine(
          async (value: string) => {
            return !(await isMenuNameExists(value, formData?.id));
          },
          (value) => ({
            message: $t('ui.formRules.alreadyExists', [
              $t('system.menu.menuName'),
              value,
            ]),
          }),
        ),
    },
    {
      component: 'ApiTreeSelect',
      componentProps: {
        api: getMenuList,
        class: 'w-full',
        allowClear: true,
        filterTreeNode(input: string, node: Recordable<any>) {
          if (!input || input.length === 0) {
            return true;
          }
          const title: string = node.meta?.title ?? node.name ?? '';
          if (!title) return false;
          return title.includes(input) || $t(title).includes(input);
        },
        labelField: 'meta.title',
        showSearch: true,
        treeDefaultExpandAll: true,
        valueField: 'id',
        childrenField: 'children',
      },
      fieldName: 'pid',
      label: $t('system.menu.parent'),
      renderComponentContent() {
        return {
          title({ label, meta }: { label: string; meta: Recordable<any> }) {
            const coms = [];
            if (!label) return '';
            if (meta?.icon) {
              coms.push(h(IconifyIcon, { class: 'size-4', icon: meta.icon }));
            }
            coms.push(h('span', { class: '' }, $t(label || '')));
            return h('div', { class: 'flex items-center gap-1' }, coms);
          },
        };
      },
    },
    {
      component: 'Input',
      fieldName: 'meta.title',
      label: $t('system.menu.menuTitle'),
      rules: 'required',
    },
    {
      component: 'Input',
      dependencies: {
        show: (values) => {
          return ['catalog', 'embedded', 'menu'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'path',
      label: $t('system.menu.path'),
      rules: z
        .string()
        .min(2, $t('ui.formRules.minLength', [$t('system.menu.path'), 2]))
        .max(100, $t('ui.formRules.maxLength', [$t('system.menu.path'), 100]))
        .refine(
          (value: string) => {
            return value.startsWith('/');
          },
          $t('ui.formRules.startWith', [$t('system.menu.path'), '/']),
        )
        .refine(
          async (value: string) => {
            return !(await isMenuPathExists(value, formData?.id));
          },
          (value) => ({
            message: $t('ui.formRules.alreadyExists', [
              $t('system.menu.path'),
              value,
            ]),
          }),
        ),
    },
    {
      component: 'IconPicker',
      componentProps: {
        prefix: 'carbon',
      },
      dependencies: {
        show: (values) => {
          return ['catalog', 'embedded', 'link', 'menu'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.icon',
      label: $t('system.menu.icon'),
    },
    {
      component: 'Input',
      dependencies: {
        rules: (values) => {
          return values.type === 'menu' ? 'required' : null;
        },
        show: (values) => {
          return values.type === 'menu';
        },
        triggerFields: ['type'],
      },
      fieldName: 'component',
      label: $t('system.menu.component'),
      componentProps: {
        placeholder: $t('system.menu.componentPlaceholder'),
      },
    },
    {
      component: 'Input',
      dependencies: {
        show: (values) => {
          return ['embedded', 'link'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'linkSrc',
      label: $t('system.menu.linkSrc'),
      rules: z.string().url($t('ui.formRules.invalidURL')),
    },
    {
      component: 'Input',
      dependencies: {
        rules: (values) => {
          return values.type === 'button' ? 'required' : null;
        },
        show: (values) => {
          return ['button', 'catalog', 'embedded', 'menu'].includes(
            values.type,
          );
        },
        triggerFields: ['type'],
      },
      fieldName: 'authCode',
      label: $t('system.menu.authCode'),
    },
    {
      component: 'RadioGroup',
      componentProps: {
        buttonStyle: 'solid',
        options: [
          { label: $t('common.enabled'), value: 1 },
          { label: $t('common.disabled'), value: 0 },
        ],
        optionType: 'button',
      },
      defaultValue: 1,
      fieldName: 'status',
      label: $t('system.menu.status'),
    },
    {
      component: 'InputNumber',
      componentProps: {
        min: 0,
        max: 9999,
        class: 'w-full',
      },
      defaultValue: 0,
      fieldName: 'sort',
      label: $t('system.menu.sort'),
    },
    // 高级设置分割线
    {
      component: 'Divider',
      dependencies: {
        show: (values) => {
          return !['button', 'link'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'divider1',
      formItemClass: 'col-span-2 md:col-span-2 pb-0',
      hideLabel: true,
      renderComponentContent() {
        return {
          default: () => $t('system.menu.advancedSettings'),
        };
      },
    },
    {
      component: 'Checkbox',
      dependencies: {
        show: (values) => {
          return ['menu'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.keepAlive',
      renderComponentContent() {
        return {
          default: () => $t('system.menu.keepAlive'),
        };
      },
    },
    {
      component: 'Checkbox',
      dependencies: {
        show: (values) => {
          return ['embedded', 'menu'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.affixTab',
      renderComponentContent() {
        return {
          default: () => $t('system.menu.affixTab'),
        };
      },
    },
    {
      component: 'Checkbox',
      dependencies: {
        show: (values) => {
          return !['button'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.hideInMenu',
      renderComponentContent() {
        return {
          default: () => $t('system.menu.hideInMenu'),
        };
      },
    },
    {
      component: 'Checkbox',
      dependencies: {
        show: (values) => {
          return ['catalog', 'menu'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.hideChildrenInMenu',
      renderComponentContent() {
        return {
          default: () => $t('system.menu.hideChildrenInMenu'),
        };
      },
    },
    {
      component: 'Checkbox',
      dependencies: {
        show: (values) => {
          return !['button', 'link'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.hideInBreadcrumb',
      renderComponentContent() {
        return {
          default: () => $t('system.menu.hideInBreadcrumb'),
        };
      },
    },
    {
      component: 'Checkbox',
      dependencies: {
        show: (values) => {
          return !['button', 'link'].includes(values.type);
        },
        triggerFields: ['type'],
      },
      fieldName: 'meta.hideInTab',
      renderComponentContent() {
        return {
          default: () => $t('system.menu.hideInTab'),
        };
      },
    },
  ];
}

/**
 * Table columns | 表格列配置
 */
export function useColumns(
  onActionClick: OnActionClickFn<SystemMenuApi.SystemMenu>,
): VxeTableGridOptions<SystemMenuApi.SystemMenu>['columns'] {
  return [
    {
      align: 'left',
      field: 'meta.title',
      fixed: 'left',
      slots: { default: 'title' },
      title: $t('system.menu.menuTitle'),
      treeNode: true,
      width: 250,
    },
    {
      align: 'center',
      cellRender: { name: 'CellTag', options: getMenuTypeOptions() },
      field: 'type',
      title: $t('system.menu.type'),
      width: 100,
    },
    {
      field: 'authCode',
      title: $t('system.menu.authCode'),
      width: 200,
    },
    {
      align: 'left',
      field: 'path',
      title: $t('system.menu.path'),
      width: 200,
    },
    {
      align: 'left',
      field: 'component',
      formatter: ({ row }) => {
        switch (row.type) {
          case 'catalog':
          case 'menu': {
            return row.component ?? '';
          }
          case 'embedded': {
            return row.meta?.iframeSrc ?? '';
          }
          case 'link': {
            return row.meta?.link ?? '';
          }
        }
        return '';
      },
      minWidth: 200,
      title: $t('system.menu.component'),
    },
    {
      cellRender: { name: 'CellTag' },
      field: 'status',
      title: $t('system.menu.status'),
      width: 100,
    },
    {
      align: 'right',
      cellRender: {
        attrs: {
          nameField: 'name',
          onClick: onActionClick,
        },
        name: 'CellOperation',
        options: [
          {
            code: 'append',
            text: $t('system.menu.addChild'),
          },
          'edit',
          'delete',
        ],
      },
      field: 'operation',
      fixed: 'right',
      headerAlign: 'center',
      showOverflow: false,
      title: $t('common.operation'),
      width: 200,
    },
  ];
}
