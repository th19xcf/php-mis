<script setup lang="ts">
import { computed, ref, reactive } from 'vue';
import type { VNode } from 'vue';
import { useAuthStore } from '@/store/modules/auth';
import { useRouterPush } from '@/hooks/common/router';
import { useSvgIcon } from '@/hooks/common/icon';
import { fetchChangePassword } from '@/service/api/auth';
import {  } from 'naive-ui';
import { $t } from '@/locales';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';

defineOptions({
  name: 'UserAvatar'
});

const authStore = useAuthStore();
const { routerPushByKey, toLogin } = useRouterPush();
const { SvgIconVNode } = useSvgIcon();
const message = useMessageWithConsole();

function loginOrRegister() {
  toLogin();
}

// 修改密码弹窗状态
const showPwdModal = ref(false);
const pwdLoading = ref(false);

const pwdForm = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
});

function openPwdModal() {
  pwdForm.oldPassword = '';
  pwdForm.newPassword = '';
  pwdForm.confirmPassword = '';
  showPwdModal.value = true;
}

function handlePwdSubmit() {
  if (!pwdForm.oldPassword) {
    message.warning('请输入旧密码');
    return;
  }
  if (!pwdForm.newPassword) {
    message.warning('请输入新密码');
    return;
  }
  if (pwdForm.newPassword.length < 6) {
    message.warning('新密码长度不能少于6位');
    return;
  }
  if (pwdForm.newPassword !== pwdForm.confirmPassword) {
    message.warning('两次输入的新密码不一致');
    return;
  }
  if (pwdForm.oldPassword === pwdForm.newPassword) {
    message.warning('新密码不能与旧密码相同');
    return;
  }

  pwdLoading.value = true;
  fetchChangePassword(pwdForm.oldPassword, pwdForm.newPassword)
    .then(() => {
      message.success('密码修改成功，请重新登录');
      showPwdModal.value = false;
      // 密码修改成功后，强制退出并跳转登录页
      setTimeout(() => {
        authStore.resetStore({ clearBusinessCache: true });
        toLogin();
      }, 1500);
    })
    .catch((err: any) => {
      message.error(err?.msg || '密码修改失败');
    })
    .finally(() => {
      pwdLoading.value = false;
    });
}

type DropdownKey = 'logout';

type DropdownOption =
  | {
      key: DropdownKey;
      label: string;
      icon?: () => VNode;
    }
  | {
      type: 'divider';
      key: string;
    };

const options = computed(() => {
  const opts: DropdownOption[] = [
    {
      label: $t('common.logout'),
      key: 'logout',
      icon: SvgIconVNode({ icon: 'ph:sign-out', fontSize: 18 })
    }
  ];

  return opts;
});

function logout() {
  window.$dialog?.info({
    title: $t('common.tip'),
    content: $t('common.logoutConfirm'),
    positiveText: $t('common.confirm'),
    negativeText: $t('common.cancel'),
    onPositiveClick: () => {
      // 主动退出：清理业务缓存（globalTabs / lastLoginUserId），避免换号场景缓存污染
      authStore.resetStore({ clearBusinessCache: true });
    }
  });
}

function handleDropdown(key: DropdownKey) {
  if (key === 'logout') {
    logout();
  } else {
    // If your other options are jumps from other routes, they will be directly supported here
    routerPushByKey(key);
  }
}
</script>

<template>
  <div class="flex items-center gap-4">
    <!-- 修改密码按钮 -->
    <ButtonIcon
      v-if="authStore.isLogin"
      icon="mdi:shield-account"
      tooltip-content="修改密码"
      @click="openPwdModal"
    />

    <NButton v-if="!authStore.isLogin" quaternary @click="loginOrRegister">
      {{ $t('page.login.common.loginOrRegister') }}
    </NButton>
    <NDropdown v-else placement="bottom" trigger="click" :options="options" @select="handleDropdown">
      <div>
        <ButtonIcon>
          <SvgIcon icon="ph:user-circle" class="text-icon-large" />
          <span class="text-16px font-medium">{{ authStore.userInfo.userName }}</span>
        </ButtonIcon>
      </div>
    </NDropdown>
  </div>

  <!-- 修改密码弹窗 -->
  <NModal
    v-model:show="showPwdModal"
    preset="dialog"
    :title="'修改密码'"
    :positive-text="'确认修改'"
    :negative-text="'取消'"
    :loading="pwdLoading"
    :mask-closable="false"
    @positive-click="handlePwdSubmit"
    @negative-click="showPwdModal = false"
  >
    <NForm :model="pwdForm" label-placement="left" label-width="100px" require-mark-placement="right-hanging">
      <NFormItem label="旧密码" required>
        <NInput v-model:value="pwdForm.oldPassword" type="password" show-password-on="click" placeholder="请输入旧密码" />
      </NFormItem>
      <NFormItem label="新密码" required>
        <NInput v-model:value="pwdForm.newPassword" type="password" show-password-on="click" placeholder="请输入新密码（至少6位）" />
      </NFormItem>
      <NFormItem label="确认新密码" required>
        <NInput v-model:value="pwdForm.confirmPassword" type="password" show-password-on="click" placeholder="请再次输入新密码" />
      </NFormItem>
    </NForm>
  </NModal>
</template>

<style scoped></style>
