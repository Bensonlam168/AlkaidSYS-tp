<script lang="ts" setup>
import type { SystemRoleApi } from '#/api/system/role';

import { computed, nextTick, ref } from 'vue';

import { useVbenDrawer } from '@vben/common-ui';

import { Checkbox, Collapse, Spin } from 'ant-design-vue';

import { useVbenForm } from '#/adapter/form';
import {
  createRole,
  getAllPermissions,
  updateRole,
} from '#/api/system/role';
import { $t } from '#/locales';

import { useFormSchema } from '../data';

const emits = defineEmits(['success']);

const formData = ref<SystemRoleApi.SystemRole>();
const id = ref<number>();

const [Form, formApi] = useVbenForm({
  schema: useFormSchema(),
  showDefaultActions: false,
});

// Permission state | 权限状态
const permissions = ref<SystemRoleApi.Permission[]>([]);
const groupedPermissions = ref<Record<string, SystemRoleApi.Permission[]>>({});
const loadingPermissions = ref(false);
const selectedPermissions = ref<number[]>([]);

const [Drawer, drawerApi] = useVbenDrawer({
  async onConfirm() {
    const { valid } = await formApi.validate();
    if (!valid) return;
    const values = await formApi.getValues();
    drawerApi.lock();

    // Include selected permissions | 包含选中的权限
    const submitData = {
      ...values,
      permissions: selectedPermissions.value,
    };

    try {
      if (id.value) {
        await updateRole(id.value, submitData);
      } else {
        await createRole(submitData);
      }
      emits('success');
      drawerApi.close();
    } catch {
      drawerApi.unlock();
    }
  },

  async onOpenChange(isOpen) {
    if (isOpen) {
      const data = drawerApi.getData<SystemRoleApi.SystemRole>();
      formApi.resetForm();
      selectedPermissions.value = [];

      if (data && data.id) {
        formData.value = data;
        id.value = data.id;
        selectedPermissions.value = data.permissions || [];
      } else {
        formData.value = undefined;
        id.value = undefined;
      }

      // Load permissions if not loaded | 如果未加载则加载权限
      if (permissions.value.length === 0) {
        await loadPermissions();
      }

      // Wait for Vue to flush DOM updates | 等待 Vue 刷新 DOM 更新
      await nextTick();
      if (data && data.id) {
        formApi.setValues(data);
      }
    }
  },
});

async function loadPermissions() {
  loadingPermissions.value = true;
  try {
    const res = await getAllPermissions();
    permissions.value = res.list;
    groupedPermissions.value = res.grouped;
  } finally {
    loadingPermissions.value = false;
  }
}

const getDrawerTitle = computed(() => {
  return formData.value?.id
    ? $t('common.edit', $t('system.role.title'))
    : $t('common.create', $t('system.role.title'));
});

// Check if permission is selected | 检查权限是否被选中
function isPermissionSelected(permissionId: number): boolean {
  return selectedPermissions.value.includes(permissionId);
}

// Toggle permission selection | 切换权限选择
function togglePermission(permissionId: number, checked: boolean) {
  if (checked) {
    if (!selectedPermissions.value.includes(permissionId)) {
      selectedPermissions.value.push(permissionId);
    }
  } else {
    const index = selectedPermissions.value.indexOf(permissionId);
    if (index > -1) {
      selectedPermissions.value.splice(index, 1);
    }
  }
}

// Check if all permissions in group are selected | 检查组内所有权限是否全选
function isGroupAllSelected(group: SystemRoleApi.Permission[]): boolean {
  return group.every((p) => selectedPermissions.value.includes(p.id));
}

// Check if some permissions in group are selected | 检查组内是否有部分选中
function isGroupIndeterminate(group: SystemRoleApi.Permission[]): boolean {
  const selectedCount = group.filter((p) =>
    selectedPermissions.value.includes(p.id),
  ).length;
  return selectedCount > 0 && selectedCount < group.length;
}

// Toggle all permissions in group | 切换组内所有权限
function toggleGroupAll(group: SystemRoleApi.Permission[], checked: boolean) {
  for (const permission of group) {
    if (checked) {
      if (!selectedPermissions.value.includes(permission.id)) {
        selectedPermissions.value.push(permission.id);
      }
    } else {
      const index = selectedPermissions.value.indexOf(permission.id);
      if (index > -1) {
        selectedPermissions.value.splice(index, 1);
      }
    }
  }
}

// Get resource display name | 获取资源显示名称
function getResourceName(resource: string): string {
  const resourceNames: Record<string, string> = {
    casbin: $t('system.role.resources.casbin'),
    collections: $t('system.role.resources.collections'),
    forms: $t('system.role.resources.forms'),
    other: $t('system.role.resources.other'),
    permission: $t('system.role.resources.permission'),
    role: $t('system.role.resources.role'),
    tenant: $t('system.role.resources.tenant'),
    user: $t('system.role.resources.user'),
  };
  return resourceNames[resource] || resource;
}
</script>
<template>
  <Drawer :title="getDrawerTitle">
    <Form />

    <!-- Permissions Section | 权限部分 -->
    <div class="mt-4">
      <div class="text-base font-medium mb-2">
        {{ $t('system.role.permissions') }}
      </div>
      <Spin :spinning="loadingPermissions">
        <Collapse v-if="Object.keys(groupedPermissions).length > 0">
          <template
            v-for="(group, resource) in groupedPermissions"
            :key="resource"
          >
            <Collapse.Panel :header="getResourceName(resource as string)">
              <template #extra>
                <Checkbox
                  :checked="isGroupAllSelected(group)"
                  :indeterminate="isGroupIndeterminate(group)"
                  @change="
                    (e: any) => toggleGroupAll(group, e.target.checked)
                  "
                  @click.stop
                >
                  {{ $t('common.selectAll') }}
                </Checkbox>
              </template>
              <div class="grid grid-cols-2 gap-2">
                <div v-for="permission in group" :key="permission.id">
                  <Checkbox
                    :checked="isPermissionSelected(permission.id)"
                    @change="
                      (e: any) =>
                        togglePermission(permission.id, e.target.checked)
                    "
                  >
                    <span class="font-medium">{{ permission.name }}</span>
                    <span
                      v-if="permission.description"
                      class="text-gray-500 text-xs ml-1"
                    >
                      ({{ permission.description }})
                    </span>
                  </Checkbox>
                </div>
              </div>
            </Collapse.Panel>
          </template>
        </Collapse>
        <div v-else class="text-gray-500 text-center py-4">
          {{ $t('system.role.noPermissions') }}
        </div>
      </Spin>
    </div>
  </Drawer>
</template>
