<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();

const title = computed(() => String(route.meta?.title || '动态菜单页面'));
const meta = computed(() => route.meta as Record<string, unknown>);
</script>

<template>
  <NCard :bordered="false" class="card-wrapper">
    <NSpace vertical :size="12">
      <div class="text-lg font-600">{{ title }}</div>
      <NAlert type="info">
        该页面由后端菜单动态生成路由。你可以在此接入真实业务组件或根据参数跳转旧系统页面。
      </NAlert>
      <NDescriptions :column="1" bordered size="small">
        <NDescriptionsItem label="route.name">{{ String(route.name || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="path">{{ route.fullPath }}</NDescriptionsItem>
        <NDescriptionsItem label="functionCode">{{ String(meta.functionCode || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="menu1">{{ String(meta.menu1 || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="menu2">{{ String(meta.menu2 || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="module">{{ String(meta.module || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="params">{{ String(meta.params || '') }}</NDescriptionsItem>
      </NDescriptions>
    </NSpace>
  </NCard>
</template>
