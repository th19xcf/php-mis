<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { GLOBAL_SIDER_MENU_ID } from '@/constants/app';
import { useAppStore } from '@/store/modules/app';
import { useThemeStore } from '@/store/modules/theme';
import GlobalLogo from '../global-logo/index.vue';

defineOptions({
  name: 'GlobalSider'
});

const appStore = useAppStore();
const themeStore = useThemeStore();

const isTopHybridSidebarFirst = computed(() => themeStore.layout.mode === 'top-hybrid-sidebar-first');
const isTopHybridHeaderFirst = computed(() => themeStore.layout.mode === 'top-hybrid-header-first');
const darkMenu = computed(
  () =>
    !themeStore.darkMode && !isTopHybridSidebarFirst.value && !isTopHybridHeaderFirst.value && themeStore.sider.inverted
);
const showLogo = computed(() => themeStore.layout.mode === 'vertical');
const menuWrapperClass = computed(() => (showLogo.value ? 'flex-1-hidden' : 'h-full'));

const isDragging = ref(false);

const RESIZE_MIN_WIDTH = 180;
const RESIZE_MAX_WIDTH = 420;

const canResize = computed(
  () => themeStore.layout.mode === 'vertical' && !appStore.isMobile && !appStore.siderCollapse
);

function clampWidth(width: number) {
  return Math.min(RESIZE_MAX_WIDTH, Math.max(RESIZE_MIN_WIDTH, width));
}

function onMouseMove(event: MouseEvent) {
  if (!isDragging.value) {
    return;
  }

  themeStore.sider.width = clampWidth(event.clientX);
}

function stopResize() {
  if (!isDragging.value) {
    return;
  }

  isDragging.value = false;
  document.body.classList.remove('sider-resizing');
  document.removeEventListener('mousemove', onMouseMove);
  document.removeEventListener('mouseup', stopResize);
}

function startResize(event: MouseEvent) {
  if (!canResize.value) {
    return;
  }

  event.preventDefault();
  isDragging.value = true;
  document.body.classList.add('sider-resizing');
  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', stopResize);
}

onBeforeUnmount(() => {
  stopResize();
});
</script>

<template>
  <DarkModeContainer class="global-sider size-full flex-col-stretch shadow-sider" :inverted="darkMenu">
    <GlobalLogo
      v-if="showLogo"
      :show-title="!appStore.siderCollapse"
      :style="{ height: themeStore.header.height + 'px' }"
    />
    <div :id="GLOBAL_SIDER_MENU_ID" :class="menuWrapperClass"></div>
    <div v-if="canResize" class="sider-resize-handle" @mousedown="startResize"></div>
  </DarkModeContainer>
</template>

<style scoped>
.global-sider {
  position: relative;
}

.sider-resize-handle {
  position: absolute;
  top: 0;
  right: 0;
  width: 6px;
  height: 100%;
  cursor: ew-resize;
  background-color: transparent;
  transition: background-color 0.2s;
}

.sider-resize-handle:hover {
  background-color: rgb(100 108 255 / 18%);
}
</style>

<style>
body.sider-resizing {
  cursor: ew-resize;
  user-select: none;
}
</style>
