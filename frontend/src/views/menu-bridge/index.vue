<script setup lang="ts">
import { computed, onActivated, ref, watch, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';

import { useThemeStore } from '@/store/modules/theme';
import { useTabStore } from '@/store/modules/tab';
import { getServiceBaseURL } from '@/utils/service';
import GenericQueryWorkbench from './modules/generic-query-workbench.vue';

defineOptions({
  name: 'MenuBridge'
});

const route = useRoute();
const themeStore = useThemeStore();
const tabStore = useTabStore();
const isDarkMode = computed(() => themeStore.darkMode);
// cacheScopeKey 只基于 functionCode 和 params，确保切换标签页时缓存能命中
const workbenchCacheScopeKey = computed(() => {
  const functionCode = String(route.query.functionCode || route.meta?.functionCode || '');
  const rawParams = route.query.params || route.meta?.params || '';
  const params = typeof rawParams === 'string' ? rawParams : JSON.stringify(rawParams);
  return `${functionCode}_${params}`;
});

const meta = computed(() => {
  const routeMeta = (route.meta || {}) as Record<string, unknown>;

  // 优先使用 query 参数（钻取时传递），其次使用 route.meta
  const functionCode = String(route.query.functionCode || routeMeta.functionCode || '');
  const module = String(route.query.module || routeMeta.module || '');
  const rawParams = route.query.params || routeMeta.params || '';
  // 确保 params 是字符串，如果是对象则转成 JSON
  const params = typeof rawParams === 'string' ? rawParams : JSON.stringify(rawParams);
  const menu1 = String(route.query.menu1 || routeMeta.menu1 || '');
  const menu2 = String(route.query.menu2 || routeMeta.menu2 || '');

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
const activeView = ref<'workbench' | 'legacy' | 'native'>('workbench');
const currentFunctionCode = computed(() => String(meta.value.functionCode || '').trim());

// 检查当前路由是否是激活的标签页（防止刷新时旧路由的数据加载）
const isCurrentRouteActive = computed(() => {
  // 如果 tabs 还没有初始化（长度为0），认为不是激活状态
  if (tabStore.tabs.length === 0) {
    return false;
  }

  // 获取当前激活的标签页
  const currentTab = tabStore.tabs.find(tab => tab.id === tabStore.activeTabId);
  if (!currentTab) {
    return false;
  }

  // 如果当前标签页是首页，检查当前路由是否也是首页
  if (currentTab.id === tabStore.homeTab?.id) {
    return route.fullPath === currentTab.fullPath;
  }

  // 如果当前标签页不是首页，检查当前路由的 functionCode 是否匹配
  // 从标签页的 fullPath 中解析 functionCode
  const routeFunctionCode = String(route.query.functionCode || '');
  const tabUrl = new URL(currentTab.fullPath, window.location.origin);
  const tabFunctionCode = String(tabUrl.searchParams.get('functionCode') || '');
  return routeFunctionCode === tabFunctionCode;
});

// 原生 Vue 组件映射表 - 功能编码 -> 组件路径
const nativeComponentMap: Record<string, any> = {
  // 1010 部门管理
  '1010': defineAsyncComponent(() => import('@/views/system/dept/index.vue')),
  // 2015 邀约人员维护
  '2015': defineAsyncComponent(() => import('@/views/personnel/store/index.vue')),
  // 2025 面试人员维护
  '2025': defineAsyncComponent(() => import('@/views/personnel/interview/index.vue')),
  // 2035 培训人员维护
  '2035': defineAsyncComponent(() => import('@/views/personnel/train/index.vue')),
  // 2045 在职人员维护
  '2045': defineAsyncComponent(() => import('@/views/personnel/employee/index.vue')),
  // contract 合同管理
  contract: defineAsyncComponent(() => import('@/views/contract/index.vue'))
};

// 判断当前功能是否使用原生 Vue 组件
const isNativeFunction = computed(() => {
  const funcCode = currentFunctionCode.value;
  return funcCode && nativeComponentMap[funcCode];
});

// 获取当前功能的原生组件
const currentNativeComponent = computed(() => {
  const funcCode = currentFunctionCode.value;
  return nativeComponentMap[funcCode] || null;
});

const isNativeOnlyFunction = computed(() => {
  return true;
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

// 监听 route.query.menu2 变化，更新 Tab 标签（处理钻取场景）
watch(
  () => String(route.query.menu2 || '').trim(),
  menu2 => {
    if (menu2) {
      setTimeout(() => {
        console.log('[menu-bridge] Setting tab label to:', menu2);
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
    <NCard :bordered="false" :content-style="{ padding: '6px 8px' }" class="bridge-card rounded-16px shadow-sm">
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
        <KeepAlive v-else-if="activeView === 'workbench' && meta.functionCode && isCurrentRouteActive">
          <GenericQueryWorkbench
            :key="workbenchCacheScopeKey"
            :meta="meta"
            :native-only="isNativeOnlyFunction"
            :dynamic-like="false"
          />
        </KeepAlive>

        <!-- 当路由不匹配时显示空内容（防止显示旧内容） -->
        <div v-else-if="activeView === 'workbench' && meta.functionCode && !isCurrentRouteActive" class="empty-content">
          <!-- 空内容，等待路由匹配 -->
        </div>

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
