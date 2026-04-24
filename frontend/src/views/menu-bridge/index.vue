<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { useThemeStore } from '@/store/modules/theme';
import { useTabStore } from '@/store/modules/tab';
import { getServiceBaseURL } from '@/utils/service';
import GenericQueryWorkbench from './modules/generic-query-workbench.vue';

const route = useRoute();
const themeStore = useThemeStore();
const tabStore = useTabStore();
const isDarkMode = computed(() => themeStore.darkMode);

// 从 sessionStorage 读取钻取参数（如果有）
const drillParams = (() => {
  try {
    const stored = sessionStorage.getItem('drillParams');
    if (stored) {
      sessionStorage.removeItem('drillParams');
      return JSON.parse(stored);
    }
  } catch {
    // ignore parse errors
  }
  return null;
})();

const meta = computed(() => {
  const routeMeta = (route.meta || {}) as Record<string, unknown>;

  // 优先使用 drillParams（钻取参数），其次使用 query 参数
  const functionCode = drillParams?.functionCode || String(route.query.functionCode || routeMeta.functionCode || '');
  const module = drillParams?.module || String(route.query.module || routeMeta.module || '');
  const rawParams = drillParams?.params || route.query.params || routeMeta.params || '';
  // 确保 params 是字符串，如果是对象则转成 JSON
  const params = typeof rawParams === 'string' ? rawParams : JSON.stringify(rawParams);
  const menu1 = drillParams?.menu1 || String(route.query.menu1 || routeMeta.menu1 || '');
  const menu2 = drillParams?.menu2 || String(route.query.menu2 || routeMeta.menu2 || '');

  return {
    ...routeMeta,
    functionCode,
    module,
    params,
    menu1,
    menu2,
    title: menu2 || routeMeta.title || '动态菜单页面'
  };
});
const iframeLoaded = ref(false);
const activeView = ref<'workbench' | 'legacy'>('workbench');
const currentFunctionCode = computed(() => String(meta.value.functionCode || '').trim());

const isNativeOnlyFunction = computed(() => {
  return currentFunctionCode.value !== '111';
});

watch(
  currentFunctionCode,
  () => {
    // Reset to unified Vue layout when switching between dynamic-menu functions.
    activeView.value = 'workbench';
    iframeLoaded.value = false;
  },
  { immediate: true }
);

onActivated(() => {
  // Keep-alive may restore the previous legacy tab state for the same route.
  // Always re-enter with unified Vue layout to keep dynamic-menu pages consistent.
  activeView.value = 'workbench';
  iframeLoaded.value = false;
});

onMounted(() => {
  // 如果有钻取参数，延迟更新 Tab 标签标题，确保 Tab 已创建
  if (drillParams?.menu2) {
    setTimeout(() => {
      console.log('[menu-bridge] Setting tab label to:', drillParams.menu2);
      tabStore.setTabLabel(drillParams.menu2);
    }, 100);
  } else {
    console.log('[menu-bridge] drillParams is null, sessionStorage:', sessionStorage.getItem('drillParams'));
  }
});

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
    <NCard :bordered="false" :content-style="{ padding: '6px 8px' }" class="bridge-card rounded-16px shadow-sm">
      <NAlert v-if="!legacyUrl" type="warning" class="mb-16px">
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
        <GenericQueryWorkbench
          v-if="activeView === 'workbench'"
          :meta="meta"
          :native-only="isNativeOnlyFunction"
          :dynamic-like="false"
        />

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
