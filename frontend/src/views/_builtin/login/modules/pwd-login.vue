<script setup lang="ts">
import { computed, reactive } from 'vue';
import { useAuthStore } from '@/store/modules/auth';
import { useRouterPush } from '@/hooks/common/router';
import { useNaiveForm } from '@/hooks/common/form';
import { $t } from '@/locales';

defineOptions({
  name: 'PwdLogin'
});

const authStore = useAuthStore();
const { toggleLoginModule } = useRouterPush();
const { formRef, validate } = useNaiveForm();

interface FormModel {
  region: string | null;
  userName: string;
  password: string;
}

const model: FormModel = reactive({
  region: null,
  userName: '',
  password: ''
});

const regionOptions = [
  { label: '北京总公司', value: '北京总公司' },
  { label: '河北分公司', value: '河北分公司' },
  { label: '四川分公司', value: '四川分公司' },
  { label: '河南分公司', value: '河南分公司' },
  { label: '内蒙古分公司', value: '内蒙古分公司' },
  { label: '新疆分公司-乌鲁木齐', value: '新疆分公司-乌鲁木齐' },
  { label: '新疆分公司-和田', value: '新疆分公司-和田' },
  { label: '海南分公司', value: '海南分公司' }
];

const rules = computed<Record<keyof FormModel, App.Global.FormRule[]>>(() => {
  return {
    region: [{ required: true, message: $t('page.login.common.regionRequired'), trigger: 'change' }],
    userName: [],
    password: []
  };
});

async function handleSubmit() {
  await validate();
  await authStore.login(model.userName, model.password, model.region || undefined);
}
</script>

<template>
  <NForm ref="formRef" :model="model" :rules="rules" size="large" :show-label="false" @keyup.enter="handleSubmit">
    <NFormItem path="region">
      <NSelect
        v-model:value="model.region"
        :options="regionOptions"
        :placeholder="$t('page.login.common.regionPlaceholder')"
      />
    </NFormItem>
    <NFormItem path="userName">
      <NInput v-model:value="model.userName" :placeholder="$t('page.login.common.userNamePlaceholder')" />
    </NFormItem>
    <NFormItem path="password">
      <NInput
        v-model:value="model.password"
        type="password"
        show-password-on="click"
        :placeholder="$t('page.login.common.passwordPlaceholder')"
      />
    </NFormItem>
    <NSpace vertical :size="24">
      <div class="flex-y-center justify-between">
        <NCheckbox>{{ $t('page.login.pwdLogin.rememberMe') }}</NCheckbox>
        <NButton quaternary @click="toggleLoginModule('reset-pwd')">
          {{ $t('page.login.pwdLogin.forgetPassword') }}
        </NButton>
      </div>
      <NButton type="primary" size="large" round block :loading="authStore.loginLoading" @click="handleSubmit">
        {{ $t('common.confirm') }}
      </NButton>
    </NSpace>
  </NForm>
</template>

<style scoped></style>
