<script lang="ts" setup>
import type {
  OnActionClickParams,
  VxeTableGridOptions,
} from '#/adapter/vxe-table';
import type { SystemUserApi } from '#/api/system/user';

import { computed, ref } from 'vue';

import { Page, useVbenDrawer, useVbenModal } from '@vben/common-ui';
import { Plus } from '@vben/icons';

import { Button, message, Modal } from 'ant-design-vue';

import { useVbenForm } from '#/adapter/form';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import {
  deleteUser,
  getUserList,
  resetUserPassword,
  updateUserStatus,
} from '#/api/system/user';
import { $t } from '#/locales';

import { useColumns, useGridFormSchema, useResetPasswordSchema } from './data';
import Form from './modules/form.vue';
import RoleAssign from './modules/role-assign.vue';

// Form drawer for create/edit user
const [FormDrawer, formDrawerApi] = useVbenDrawer({
  connectedComponent: Form,
  destroyOnClose: true,
});

// Role assignment drawer
const [RoleAssignDrawer, roleAssignDrawerApi] = useVbenDrawer({
  connectedComponent: RoleAssign,
  destroyOnClose: true,
});

// Reset password modal
const resetPasswordUserId = ref<number>();
const resetPasswordUsername = ref<string>();
const [ResetPasswordForm, resetPasswordFormApi] = useVbenForm({
  schema: useResetPasswordSchema(),
  showDefaultActions: false,
});

const [ResetPasswordModal, resetPasswordModalApi] = useVbenModal({
  async onConfirm() {
    if (!resetPasswordUserId.value) return;

    resetPasswordModalApi.lock();
    try {
      const values = await resetPasswordFormApi.getValues();
      const result = await resetUserPassword(
        resetPasswordUserId.value,
        values.new_password || undefined,
      );

      // Show the new password to the user
      Modal.success({
        title: $t('system.user.resetPasswordSuccess'),
        content: `${$t('system.user.newPasswordIs')}: ${result.new_password}`,
      });

      resetPasswordModalApi.close();
    } catch {
      resetPasswordModalApi.unlock();
    }
  },
  onOpenChange(isOpen) {
    if (!isOpen) {
      resetPasswordUserId.value = undefined;
      resetPasswordUsername.value = undefined;
      resetPasswordFormApi.resetForm();
    }
  },
});

const resetPasswordModalTitle = computed(() =>
  $t('system.user.resetPasswordFor', {
    username: resetPasswordUsername.value || '',
  }),
);

// Grid configuration
const [Grid, gridApi] = useVbenVxeGrid({
  formOptions: {
    schema: useGridFormSchema(),
    submitOnChange: true,
  },
  gridOptions: {
    columns: useColumns(onActionClick),
    height: 'auto',
    keepSource: true,
    pagerConfig: {
      enabled: true,
    },
    proxyConfig: {
      ajax: {
        query: async ({ page }, formValues) => {
          const result = await getUserList({
            page: page.currentPage,
            page_size: page.pageSize,
            ...formValues,
          });
          return {
            items: result.items,
            total: result.total,
          };
        },
      },
      response: {
        list: 'items',
        total: 'total',
      },
    },
    rowConfig: {
      keyField: 'id',
    },
    toolbarConfig: {
      custom: true,
      export: false,
      refresh: true,
      search: true,
      zoom: true,
    },
  } as VxeTableGridOptions<SystemUserApi.SystemUser>,
});

function onActionClick({
  code,
  row,
}: OnActionClickParams<SystemUserApi.SystemUser>) {
  switch (code) {
    case 'delete': {
      onDelete(row);
      break;
    }
    case 'edit': {
      onEdit(row);
      break;
    }
    case 'roles': {
      onAssignRoles(row);
      break;
    }
    case 'resetPassword': {
      onResetPassword(row);
      break;
    }
    default: {
      break;
    }
  }
}

function confirm(content: string, title: string) {
  return new Promise<void>((resolve, reject) => {
    Modal.confirm({
      content,
      onCancel() {
        reject();
      },
      onOk() {
        resolve();
      },
      title,
    });
  });
}

function onEdit(row: SystemUserApi.SystemUser) {
  formDrawerApi.setData(row).open();
}

function onAssignRoles(row: SystemUserApi.SystemUser) {
  roleAssignDrawerApi.setData(row).open();
}

function onResetPassword(row: SystemUserApi.SystemUser) {
  resetPasswordUserId.value = row.id;
  resetPasswordUsername.value = row.username;
  resetPasswordModalApi.open();
}

async function onDelete(row: SystemUserApi.SystemUser) {
  try {
    await confirm(
      $t('system.user.deleteConfirm', { username: row.username }),
      $t('common.confirmTitle'),
    );
  } catch {
    return;
  }

  const hideLoading = message.loading({
    content: $t('ui.actionMessage.deleting', [row.username]),
    duration: 0,
    key: 'action_process_msg',
  });

  deleteUser(row.id)
    .then(() => {
      message.success({
        content: $t('ui.actionMessage.deleteSuccess', [row.username]),
        key: 'action_process_msg',
      });
      onRefresh();
    })
    .catch(() => {
      hideLoading();
    });
}

function onRefresh() {
  gridApi.query();
}

function onCreate() {
  formDrawerApi.setData({}).open();
}
</script>
<template>
  <Page auto-content-height>
    <FormDrawer @success="onRefresh" />
    <RoleAssignDrawer @success="onRefresh" />
    <ResetPasswordModal :title="resetPasswordModalTitle">
      <ResetPasswordForm />
    </ResetPasswordModal>
    <Grid :table-title="$t('system.user.list')">
      <template #toolbar-tools>
        <Button type="primary" @click="onCreate">
          <Plus class="size-5" />
          {{ $t('ui.actionTitle.create', [$t('system.user.entity')]) }}
        </Button>
      </template>
    </Grid>
  </Page>
</template>
