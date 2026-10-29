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

const [Form, formApi] = useVbenForm({
  schema: useFormSchema(),
  showDefaultActions: false,
});

const [Drawer, drawerApi] = useVbenDrawer({
  async onConfirm() {
    const { valid } = await formApi.validate();
    if (!valid) return;
    const values = await formApi.getValues();
    drawerApi.lock();

    try {
      if (id.value) {
        await updatePermission(id.value, values);
      } else {
        await createPermission(values);
      }
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

    <!-- Slug Format Tip | Slug 格式提示 -->
    <div class="mt-4 p-3 bg-blue-50 rounded text-sm text-blue-700">
      <div class="font-medium mb-1">{{ $t('system.permission.slugTip') }}</div>
      <ul class="list-disc pl-4 space-y-1">
        <li>{{ $t('system.permission.slugTipFormat') }}</li>
        <li>{{ $t('system.permission.slugTipExample') }}</li>
      </ul>
    </div>
  </Drawer>
</template>
