<script setup lang="ts">
import { computed } from 'vue';
import { useAuth } from '@/hooks/business/auth';
import { useAuthStore } from '@/store/modules/auth';

const authStore = useAuthStore();
const { hasAuth } = useAuth();

const roleText = computed(() => authStore.userInfo.roles.join(', ') || '-');
const buttonText = computed(() => authStore.userInfo.buttons.join(', ') || '-');
</script>

<template>
  <NSpace vertical :size="16">
    <NCard :bordered="false" class="card-wrapper">
      <NSpace vertical :size="12">
        <div class="text-lg font-600">{{ $t('page.permissionDemo.title') }}</div>
        <NText depth="3">{{ $t('page.permissionDemo.desc') }}</NText>

        <NDescriptions :column="1" bordered size="small" label-placement="left">
          <NDescriptionsItem :label="$t('page.permissionDemo.visibleRoles')">
            <NText>{{ roleText }}</NText>
          </NDescriptionsItem>
          <NDescriptionsItem :label="$t('page.permissionDemo.visibleButtons')">
            <NText>{{ buttonText }}</NText>
          </NDescriptionsItem>
        </NDescriptions>

        <NSpace>
          <NButton v-if="hasAuth('dashboard:view')" type="info">
            {{ $t('page.permissionDemo.btnView') }}
          </NButton>
          <NButton v-if="hasAuth('system:user:add')" type="success">
            {{ $t('page.permissionDemo.btnAdd') }}
          </NButton>
          <NButton v-if="hasAuth('system:user:edit')" type="warning">
            {{ $t('page.permissionDemo.btnEdit') }}
          </NButton>
          <NButton v-if="hasAuth('system:user:delete')" type="error">
            {{ $t('page.permissionDemo.btnDelete') }}
          </NButton>
        </NSpace>

        <NAlert v-if="!authStore.userInfo.buttons.length" type="warning">
          {{ $t('page.permissionDemo.noButtonAuth') }}
        </NAlert>
      </NSpace>
    </NCard>
  </NSpace>
</template>
