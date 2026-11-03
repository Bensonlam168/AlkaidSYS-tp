<script lang="ts" setup>
import type { SystemRoleApi } from '#/api/system/role';
import type { SystemUserApi } from '#/api/system/user';

import { computed, ref } from 'vue';

import { useVbenDrawer } from '@vben/common-ui';

import { Checkbox, message, Spin, Tag } from 'ant-design-vue';

import { getRoleList } from '#/api/system/role';
import { getUser, setUserRoles } from '#/api/system/user';
import { $t } from '#/locales';

const emits = defineEmits(['success']);

const userData = ref<SystemUserApi.SystemUser>();
const roleList = ref<SystemRoleApi.SystemRole[]>([]);
const selectedRoleIds = ref<number[]>([]);
const loading = ref(false);

const [Drawer, drawerApi] = useVbenDrawer({
  async onConfirm() {
    if (!userData.value?.id) return;

    drawerApi.lock();
    try {
      await setUserRoles(userData.value.id, selectedRoleIds.value);
      message.success($t('system.user.assignRolesSuccess'));
      emits('success');
      drawerApi.close();
    } catch {
      drawerApi.unlock();
    }
  },

  async onOpenChange(isOpen) {
    if (isOpen) {
      const data = drawerApi.getData<SystemUserApi.SystemUser>();
      if (data && data.id) {
        userData.value = data;
        loading.value = true;
        try {
          // Load roles and user's current roles in parallel
          const [rolesResult, userResult] = await Promise.all([
            getRoleList({}),
            getUser(data.id),
          ]);
          roleList.value = rolesResult || [];
          // Pre-select user's current roles
          selectedRoleIds.value =
            userResult?.roles && Array.isArray(userResult.roles)
              ? userResult.roles.map((r) => r.id)
              : [];
        } catch {
          roleList.value = [];
          selectedRoleIds.value = [];
        } finally {
          loading.value = false;
        }
      }
    } else {
      userData.value = undefined;
      selectedRoleIds.value = [];
    }
  },
});

const getDrawerTitle = computed(() => {
  return $t('system.user.assignRolesToUser', {
    username: userData.value?.username || '',
  });
});

function onCheckboxChange(roleId: number, checked: boolean) {
  if (checked) {
    if (!selectedRoleIds.value.includes(roleId)) {
      selectedRoleIds.value.push(roleId);
    }
  } else {
    selectedRoleIds.value = selectedRoleIds.value.filter((id) => id !== roleId);
  }
}
</script>
<template>
  <Drawer :title="getDrawerTitle">
    <Spin :spinning="loading">
      <div class="space-y-4">
        <p class="mb-4 text-sm text-muted-foreground">
          {{ $t('system.user.selectRolesHint') }}
        </p>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div
            v-for="role in roleList"
            :key="role.id"
            class="flex cursor-pointer items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:border-primary"
            @click="
              onCheckboxChange(role.id, !selectedRoleIds.includes(role.id))
            "
          >
            <Checkbox
              :checked="selectedRoleIds.includes(role.id)"
              @click.stop
              @change="(e: any) => onCheckboxChange(role.id, e.target.checked)"
            />
            <div class="flex-1">
              <div class="flex items-center gap-2">
                <span class="font-medium">{{ role.name }}</span>
                <Tag v-if="role.is_system" color="blue">
                  {{ $t('system.role.systemRole') }}
                </Tag>
              </div>
              <p v-if="role.description" class="text-xs text-muted-foreground">
                {{ role.description }}
              </p>
            </div>
          </div>
        </div>
        <div
          v-if="roleList.length === 0 && !loading"
          class="py-8 text-center text-muted-foreground"
        >
          {{ $t('system.user.noRolesAvailable') }}
        </div>
      </div>
    </Spin>
  </Drawer>
</template>
