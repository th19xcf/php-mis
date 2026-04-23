<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();
const pageTitle = ref('');

onMounted(() => {
  // 从路由参数或查询参数中获取页面信息
  pageTitle.value = (route.meta.title as string) || '功能页面';
  console.log('Dynamic page loaded:', route.name, route.path);
});
</script>

<template>
  <div class="p-24px">
    <NCard :title="pageTitle" class="min-h-500px">
      <div class="text-center py-50px">
        <div class="text-48px mb-24px">📄</div>
        <h2 class="text-24px font-bold mb-16px">{{ pageTitle }}</h2>
        <p class="text-gray-500">功能页面：{{ route.name }}</p>
        <p class="text-gray-400 mt-8px">路径：{{ route.path }}</p>
      </div>
    </NCard>
  </div>
</template>

<style scoped></style>
