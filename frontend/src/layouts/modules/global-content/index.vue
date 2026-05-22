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
    <component
      :is="Component"
      v-if="appStore.reloadFlag && String(route.path || '').startsWith('/dynamic-menu/')"
      :key="route.fullPath"
      :class="{ 'p-16px': showPadding }"
      class="flex-grow bg-layout transition-300"
    />

    <KeepAlive v-else :include="routeStore.cacheRoutes" :exclude="routeStore.excludeCacheRoutes">
      <component
        :is="Component"
        v-if="appStore.reloadFlag"
        :key="tabStore.getTabIdByRoute(route)"
        :class="{ 'p-16px': showPadding }"
        class="flex-grow bg-layout transition-300"
      />
    </KeepAlive>
  </RouterView>
</template>

<style></style>
