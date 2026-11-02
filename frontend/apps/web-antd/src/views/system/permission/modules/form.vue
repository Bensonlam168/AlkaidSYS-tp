<script lang="ts" setup>
import type { SystemPermissionApi } from '#/api/system/permission';

import { computed, nextTick, ref } from 'vue';

import { useVbenDrawer } from '@vben/common-ui';

import { useVbenForm } from '#/adapter/form';
import { createPermission, updatePermission } from '#/api/system/permission';
import { $t } from '#/locales';

import { useFormSchema } from '../data';

const emits = defineEmits(['success']);

const formData = ref<SystemPermissionApi.SystemPermission>();
const id = ref<number>();

/**
 * 是否编辑态 | Whether the drawer is in edit mode
 *
 * 编辑态下 `slug` / `resource` / `action` 必须置灰：后端把权限码当作不可变字段，
 * 值不同会返回 422（详见 `../data.ts` 的 `PERMISSION_CODE_FIELDS`）。
 * 这里用 computed 传给 schema，抽屉切换时会重新求值。
 */
const isEditMode = computed(() => Boolean(formData.value?.id));

const [Form, formApi] = useVbenForm({
  schema: useFormSchema({ isEditMode: () => isEditMode.value }),
  showDefaultActions: false,
});

const [Drawer, drawerApi] = useVbenDrawer({
  async onConfirm() {
    const { valid } = await formApi.validate();
    if (!valid) return;
    const values = await formApi.getValues();
    drawerApi.lock();

    try {
      await (id.value
        ? updatePermission(id.value, values)
        : createPermission(values));
      emits('success');
      drawerApi.close();
    } catch {
      drawerApi.unlock();
    }
  },

  async onOpenChange(isOpen) {
    if (isOpen) {
      const data = drawerApi.getData<SystemPermissionApi.SystemPermission>();
      formApi.resetForm();

      if (data && data.id) {
        formData.value = data;
        id.value = data.id;
      } else {
        formData.value = undefined;
        id.value = undefined;
      }

      // Wait for Vue to flush DOM updates | 等待 Vue 刷新 DOM 更新
      await nextTick();
      if (data && data.id) {
        formApi.setValues(data);
      }
    }
  },
});

const getDrawerTitle = computed(() => {
  return formData.value?.id
    ? $t('common.edit', $t('system.permission.title'))
    : $t('common.create', $t('system.permission.title'));
});
</script>
<template>
  <Drawer :title="getDrawerTitle">
    <Form />

    <!-- Immutable Permission Code Tip | 权限码不可变提示（仅编辑态） -->
    <div
      v-if="isEditMode"
      class="mt-4 rounded bg-amber-50 p-3 text-sm text-amber-700"
    >
      {{ $t('system.permission.immutableTip') }}
    </div>

    <!-- Slug Format Tip | Slug 格式提示 -->
    <div class="mt-4 rounded bg-blue-50 p-3 text-sm text-blue-700">
      <div class="mb-1 font-medium">{{ $t('system.permission.slugTip') }}</div>
      <ul class="list-disc space-y-1 pl-4">
        <li>{{ $t('system.permission.slugTipFormat') }}</li>
        <li>{{ $t('system.permission.slugTipExample') }}</li>
      </ul>
    </div>
  </Drawer>
</template>
