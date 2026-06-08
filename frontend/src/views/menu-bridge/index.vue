<script setup lang="ts">
import { computed, onActivated, ref, watch, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';

import { useThemeStore } from '@/store/modules/theme';
import { useTabStore } from '@/store/modules/tab';
import { getServiceBaseURL } from '@/utils/service';
import { recordTabSwitchEnd } from '@/utils/common';
import GenericQueryWorkbench from './modules/generic-query-workbench.vue';

defineOptions({
  name: 'MenuBridge'
});

const route = useRoute();
const themeStore = useThemeStore();
const tabStore = useTabStore();
const isDarkMode = computed(() => themeStore.darkMode);
// workbench 由路由级 KeepAlive 保活，内部实例不再额外加 key
const meta = computed(() => {
  const routeMeta = (route.meta || {}) as Record<string, unknown>;

  const functionCode = String(route.query.functionCode || routeMeta.functionCode || '');
  const module = String(route.query.module || routeMeta.module || '');
  const rawParams = route.query.params || routeMeta.params || '';
  const params = typeof rawParams === 'string' ? rawParams : JSON.stringify(rawParams);
  const menu1 = String(route.query.menu1 || routeMeta.menu1 || '');
  const menu2 = String(route.query.menu2 || routeMeta.menu2 || '');
  const frontendRoute = String(route.query.frontendRoute || routeMeta.frontendRoute || '');

  return {
    ...routeMeta,
    functionCode,
    module,
    params,
    menu1,
    menu2,
    frontendRoute,
    title: menu2 || routeMeta.title || '动态菜单页面'
  };
});

const iframeLoaded = ref(false);
const activeView = ref<'workbench' | 'legacy' | 'native'>('workbench');

const nativeComponentMap: Record<string, any> = {
  dept: defineAsyncComponent(() => import('@/views/system/dept/index.vue')),
  store: defineAsyncComponent(() => import('@/views/personnel/invitation/index.vue')),
  interview: defineAsyncComponent(() => import('@/views/personnel/interview/index.vue')),
  train: defineAsyncComponent(() => import('@/views/personnel/train/index.vue')),
  employee: defineAsyncComponent(() => import('@/views/personnel/employee/index.vue')),
  contract: defineAsyncComponent(() => import('@/views/contract/index.vue')),
  'room-status': defineAsyncComponent(() => import('@/views/room-status/index.vue'))
};

const isNativeFunction = computed(() => {
  const routeName = String(meta.value.frontendRoute || '').trim();
  return routeName && nativeComponentMap[routeName];
});

const currentNativeComponent = computed(() => {
  const routeName = String(meta.value.frontendRoute || '').trim();
  return nativeComponentMap[routeName] || null;
});

const isNativeOnlyFunction = computed(() => {
  return true;
});

// 移除 watch，只在 onActivated 中处理

onActivated(() => {
  activeView.value = 'workbench';
  iframeLoaded.value = false;

  // 延迟记录切换完成，等待数据加载
  setTimeout(() => {
    recordTabSwitchEnd();
  }, 300);
});

// 监听 route.query.menu2 变化，更新 Tab 标签（处理钻取场景）
watch(
  () => String(route.query.menu2 || '').trim(),
  menu2 => {
    if (menu2) {
      setTimeout(() => {
        tabStore.setTabLabel(menu2);
      }, 100);
    }
  },
  { immediate: true }
);

const isHttpProxy = import.meta.env.DEV && import.meta.env.VITE_HTTP_PROXY === 'Y';
const { baseURL } = getServiceBaseURL(import.meta.env, isHttpProxy);

const legacyUrl = computed(() => {
  const modulePath = String(meta.value.module || '')
    .trim()
    .replace(/^\/+|\/+$/g, '');
  const functionCode = String(meta.value.functionCode || '').trim();
  const params = String(meta.value.params || '').trim();
  const menu1 = String(meta.value.menu1 || '').trim();

  if (!modulePath || !functionCode) {
    return '';
  }

  const segments = [modulePath, encodeURIComponent(functionCode)];

  if (params) {
    segments.push(encodeURIComponent(params));
  }

  const query = menu1 ? `?func=${encodeURIComponent(menu1)}` : '';

  return `${baseURL}/${segments.join('/')}${query}`;
});

function handleIframeLoad() {
  iframeLoaded.value = true;
}
</script>

<template>
  <div class="menu-bridge-page" :class="{ 'system-dark': isDarkMode }">
    <NCard :bordered="false" :content-style="{ padding: '1px 8px 6px' }" class="bridge-card rounded-16px shadow-sm">
      <NAlert v-if="!legacyUrl && !isNativeFunction" type="warning" class="mb-16px">
        当前菜单缺少 module 或 functionCode 配置，无法生成功能页地址。请补齐 def_function 中的 功能模块、功能编码、参数
        配置。
      </NAlert>

      <NDescriptions v-else-if="!isNativeOnlyFunction" :column="2" bordered size="small" class="mb-16px">
        <NDescriptionsItem label="functionCode">{{ String(meta.functionCode || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="module">{{ String(meta.module || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="menu1">{{ String(meta.menu1 || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="menu2">{{ String(meta.menu2 || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="params">{{ String(meta.params || '') }}</NDescriptionsItem>
        <NDescriptionsItem label="legacyUrl">{{ legacyUrl }}</NDescriptionsItem>
      </NDescriptions>

      <div class="bridge-content-region">
        <!-- 原生 Vue 组件渲染 - 使用 keep-alive 缓存 -->
        <template v-if="isNativeFunction">
          <KeepAlive>
            <component :is="currentNativeComponent" />
          </KeepAlive>
        </template>

        <!-- 通用查询工作台 - 使用 KeepAlive 缓存组件，避免数据互相干扰 -->
        <GenericQueryWorkbench
          v-else-if="activeView === 'workbench' && meta.functionCode"
          :meta="meta"
          :native-only="isNativeOnlyFunction"
          :dynamic-like="false"
        />

        <!-- iframe 旧版页面 -->
        <div v-else-if="legacyUrl && !isNativeOnlyFunction" class="iframe-shell">
          <div v-if="!iframeLoaded" class="iframe-loading">
            <NSpin size="large" />
            <div class="mt-12px text-13px text-#6b7280">功能页加载中</div>
          </div>
          <iframe
            id="legacyMenuFrame"
            class="legacy-frame"
            sandbox="allow-forms allow-scripts allow-same-origin allow-popups allow-downloads"
            :src="legacyUrl"
            @load="handleIframeLoad"
          ></iframe>
        </div>
      </div>
    </NCard>
  </div>
</template>

<style scoped>
.menu-bridge-page {
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.menu-bridge-page.system-dark {
  --bridge-dark-bg: rgb(var(--container-bg-color));
  background: var(--bridge-dark-bg) !important;
}

.bridge-card {
  background: #fff;
  height: 100%;
}

.menu-bridge-page.system-dark .bridge-card {
  background: var(--bridge-dark-bg);
}

.menu-bridge-page.system-dark :deep(.bridge-card) {
  --n-color: var(--bridge-dark-bg);
  --n-color-embedded: var(--bridge-dark-bg);
  --n-color-modal: var(--bridge-dark-bg);
}

.menu-bridge-page.system-dark :deep(.bridge-card .n-card-content),
.menu-bridge-page.system-dark :deep(.bridge-card .n-card__content),
.menu-bridge-page.system-dark :deep(.bridge-card .n-card-header) {
  background: var(--bridge-dark-bg) !important;
}

.bridge-content-region {
  position: relative;
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.menu-bridge-page.system-dark .bridge-content-region {
  background: var(--bridge-dark-bg);
}

.iframe-shell {
  position: relative;
  height: 100%;
  min-height: 0;
  overflow: hidden;
  border: 1px solid #d7e3d6;
  border-radius: 16px;
  background: #fff;
}

.iframe-loading {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: linear-gradient(180deg, rgba(247, 250, 247, 0.96), rgba(255, 255, 255, 0.98));
}

.legacy-frame {
  width: 100%;
  height: 100%;
  border: 0;
  background: #fff;
}
</style>
