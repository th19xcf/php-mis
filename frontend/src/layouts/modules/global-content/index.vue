<script setup lang="ts">
import { useAppStore } from '@/store/modules/app';
import { useRouteStore } from '@/store/modules/route';
import { useTabStore } from '@/store/modules/tab';

defineOptions({
  name: 'GlobalContent'
});

interface Props {
  /** Show padding for content */
  showPadding?: boolean;
}

withDefaults(defineProps<Props>(), {
  showPadding: true
});

const appStore = useAppStore();
const routeStore = useRouteStore();
const tabStore = useTabStore();
</script>

<template>
  <RouterView v-slot="{ Component, route }">
    <!-- 动态菜单路由（/dynamic-menu/xxx）同样走 KeepAlive：按 tabId（即路径）缓存实例，
         切换标签页不重新挂载；切回时的数据刷新由各页面 onActivated 负责。
         组件名 menu-bridge 已由 route store 强制加入 cacheRoutes。 -->
    <KeepAlive :include="routeStore.cacheRoutes" :exclude="routeStore.excludeCacheRoutes">
      <component
        :is="Component"
        v-if="appStore.reloadFlag"
        :key="tabStore.getTabIdByRoute(route)"
        :class="{ 'px-16px py-16px': showPadding }"
        class="flex-grow bg-layout transition-300"
      />
    </KeepAlive>
  </RouterView>
</template>

<style></style>
