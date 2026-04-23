<script setup lang="ts">
import { useAuthStore } from '@/store/modules/auth';

const authStore = useAuthStore();
</script>

<template>
  <NCard :bordered="false" class="card-wrapper">
    <NSpace vertical :size="12">
      <div class="text-lg font-600">{{ $t('route.system_role') }}</div>
      <NText depth="3">二级菜单示例页面：系统管理 / 角色管理</NText>
      <NDescriptions :column="1" bordered size="small">
        <NDescriptionsItem label="roles">
          {{ authStore.userInfo.roles.join(', ') || '-' }}
        </NDescriptionsItem>
      </NDescriptions>
    </NSpace>
  </NCard>
</template>
