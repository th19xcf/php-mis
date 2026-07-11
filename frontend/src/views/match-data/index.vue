<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { NSpin, NAlert, NSpace, NButton, NCard, NInput } from 'naive-ui';

import MatchTablePanel from './components/MatchTablePanel.vue';
import MatchBottomBar from './components/MatchBottomBar.vue';
import { useMatchStore } from '@/hooks/business/use-match-store';
import { fetchMatchPage, type MatchMeta } from '@/service/api/match';
import { useThemeStore } from '@/store/modules/theme';

const props = defineProps<{
  meta: {
    functionCode: string;
    module?: string;
    title?: string;
  };
}>();

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);

const store = useMatchStore();

const loading = ref(true);
const error = ref('');
const functionCode = ref('');
const pageTitle = ref('');
const aModule = ref('');
const bModule = ref('');
const aFunctionCode = ref('');
const bFunctionCode = ref('');
const aConfig = ref<any>(null);
const bConfig = ref<any>(null);

const aTableRef = ref<InstanceType<typeof MatchTablePanel> | null>(null);
const bTableRef = ref<InstanceType<typeof MatchTablePanel> | null>(null);
const aQuickKeyword = ref('');
const bQuickKeyword = ref('');

const splitRatio = ref(0.5);
const isDragging = ref(false);
const matchBodyRef = ref<HTMLElement | null>(null);

function startDrag(e: MouseEvent) {
  e.preventDefault();
  isDragging.value = true;
  document.addEventListener('mousemove', onDrag);
  document.addEventListener('mouseup', stopDrag);
}

function onDrag(e: MouseEvent) {
  if (!isDragging.value || !matchBodyRef.value) return;
  const rect = matchBodyRef.value.getBoundingClientRect();
  const offset = e.clientY - rect.top;
  const ratio = offset / rect.height;
  splitRatio.value = Math.min(0.85, Math.max(0.15, ratio));
}

function stopDrag() {
  isDragging.value = false;
  document.removeEventListener('mousemove', onDrag);
  document.removeEventListener('mouseup', stopDrag);
}

onBeforeUnmount(() => {
  document.removeEventListener('mousemove', onDrag);
  document.removeEventListener('mouseup', stopDrag);
});

async function init() {
  loading.value = true;
  error.value = '';

  try {
    const funcCode = String(props.meta.functionCode || '').trim();
    if (!funcCode) {
      throw new Error('未指定功能编码');
    }

    functionCode.value = funcCode;

    const pageResponse = await fetchMatchPage(funcCode);
    const pageData = pageResponse.data;
    if (!pageData) {
      throw new Error('接口返回数据为空');
    }

    const meta = pageData.meta;
    pageTitle.value = meta.title || '数据匹配';

    aModule.value = meta.aModule;
    bModule.value = meta.bModule;
    aFunctionCode.value = meta.aFunctionCode || '';
    bFunctionCode.value = meta.bFunctionCode || '';
    aConfig.value = meta.aConfig;
    bConfig.value = meta.bConfig;

    store.loadData(
      funcCode,
      meta.aModule,
      meta.bModule,
      meta.aConfig,
      meta.bConfig,
      meta.aColumns,
      meta.bColumns,
      meta.aMatchCols,
      meta.bMatchCols,
      pageData.aData.rows,
      pageData.bData.rows,
      meta.matchConditions
    );
  } catch (err: any) {
    error.value = err.message || '初始化失败';
    console.error('匹配页面初始化失败:', err);
  } finally {
    loading.value = false;
  }
}

watch(() => props.meta, () => {
  init();
}, { deep: true });

const aMatchedCount = computed(() => {
  return store.aData.value.rows.filter(row => row.__matched).length;
});

const bMatchedCount = computed(() => {
  return store.bData.value.rows.filter(row => row.__matched).length;
});

const aProgress = computed(() => {
  const total = store.aData.value.rows.length;
  if (total === 0) return 0;
  return Math.round((aMatchedCount.value / total) * 100);
});

const bProgress = computed(() => {
  const total = store.bData.value.rows.length;
  if (total === 0) return 0;
  return Math.round((bMatchedCount.value / total) * 100);
});

async function handleARefresh() {
  try {
    await store.refreshSide('A');
  } catch (err: any) {
    console.error('A 侧刷新失败:', err);
  }
}

async function handleBRefresh() {
  try {
    await store.refreshSide('B');
  } catch (err: any) {
    console.error('B 侧刷新失败:', err);
  }
}

async function handleBuild() {
  try {
    await store.buildRelation();
    // 建立匹配影响两侧关系，需同时刷新
    await init();
  } catch (err: any) {
    console.error('建立匹配失败:', err);
  }
}

async function handleRevoke() {
  try {
    await store.revokeRelation();
    // 撤销匹配影响两侧关系，需同时刷新
    await init();
  } catch (err: any) {
    console.error('撤销匹配失败:', err);
  }
}

function handleChangeDisplayFilter(value: 'all' | 'matched' | 'unmatched') {
  store.displayFilter.value = value;
}

function handleUpdateConditions(indices: number[]) {
  store.updateSelectedConditions(indices);
}

function handleAReset() {
  aQuickKeyword.value = '';
  aTableRef.value?.clearSelection();
  aTableRef.value?.clearFilter();
  aTableRef.value?.clearSort();
}

function handleBReset() {
  bQuickKeyword.value = '';
  bTableRef.value?.clearSelection();
  bTableRef.value?.clearFilter();
  bTableRef.value?.clearSort();
}

function handleAOpenPinColumn() {
  aTableRef.value?.openPinColumnSelector();
}

function handleAOpenFieldSelector() {
  aTableRef.value?.openFieldSelector();
}

function handleBOpenPinColumn() {
  bTableRef.value?.openPinColumnSelector();
}

function handleBOpenFieldSelector() {
  bTableRef.value?.openFieldSelector();
}

function handleAScrollToMatched() {
  aTableRef.value?.scrollToMatched();
}

function handleBScrollToMatched() {
  bTableRef.value?.scrollToMatched();
}

const aDisplayedCount = computed(() => {
  let rows = store.aData.value.rows;
  if (store.displayFilter.value === 'unmatched') {
    rows = rows.filter(row => !row.__matched);
  } else if (store.displayFilter.value === 'matched') {
    rows = rows.filter(row => row.__matched);
  }
  if (aQuickKeyword.value) {
    const kw = aQuickKeyword.value.toLowerCase();
    rows = rows.filter(row => {
      return Object.values(row).some(v => String(v ?? '').toLowerCase().includes(kw));
    });
  }
  return rows.length;
});

const bDisplayedCount = computed(() => {
  let rows = store.bData.value.rows;
  if (store.displayFilter.value === 'unmatched') {
    rows = rows.filter(row => !row.__matched);
  } else if (store.displayFilter.value === 'matched') {
    rows = rows.filter(row => row.__matched);
  }
  if (bQuickKeyword.value) {
    const kw = bQuickKeyword.value.toLowerCase();
    rows = rows.filter(row => {
      return Object.values(row).some(v => String(v ?? '').toLowerCase().includes(kw));
    });
  }
  return rows.length;
});

onMounted(() => {
  init();
});
</script>

<template>
  <div class="match-data-page" :class="{ 'system-dark': isDarkMode }">
    <div class="match-body">
      <NSpin v-if="loading" size="large" class="match-loading">
        <div style="height: 300px;"></div>
      </NSpin>

      <div v-else ref="matchBodyRef" class="match-sections-wrapper" :class="{ 'is-dragging': isDragging }">
        <NCard
          class="match-section-card match-section-a"
          :bordered="false"
          :content-style="{ padding: '0' }"
          :style="{ flex: `${splitRatio} 1 0` }"
        >
          <template #header>
            <div class="match-card-header">
              <div class="match-card-header-left">
                <span class="match-section-badge badge-a">A</span>
                <span class="section-title">{{ aModule }}</span>
                <span class="section-function-code">{{ aFunctionCode }}</span>
              </div>
              <div class="match-card-header-right-group">
                <div class="match-card-header-center">
                  <NSpace size="small" wrap justify="end">
                    <NButton size="small" type="default" @click="handleARefresh">刷新</NButton>
                    <NButton size="small" type="default" @click="handleAReset">重置</NButton>
                    <NButton size="small" type="default" @click="handleAOpenPinColumn">固定列</NButton>
                    <NButton size="small" type="default" @click="handleAOpenFieldSelector">字段选择</NButton>
                    <NButton size="small" type="default" @click="handleAScrollToMatched" :disabled="store.aSelectedKeys.value.length === 0">
                      定位匹配
                    </NButton>
                    <NInput
                      v-model:value="aQuickKeyword"
                      size="small"
                      placeholder="快速检索"
                      style="width: 180px;"
                      :clearable="true"
                    />
                    <span class="match-count-text">
                      已选 {{ store.aSelectedKeys.value.length }} 行 · 共 {{ aDisplayedCount }} 条
                    </span>
                  </NSpace>
                </div>
                <div class="match-card-header-right">
                  {{ aMatchedCount }}/{{ store.aData.value.rows.length }} 已匹配 · {{ aProgress }}%
                </div>
              </div>
            </div>
          </template>
          <MatchTablePanel
            ref="aTableRef"
            side="A"
            :data="store.aData.value"
            :display-filter="store.displayFilter.value"
            :selected-keys="store.aSelectedKeys.value"
            :matched-keys="store.aMatchedKeys"
            :quick-keyword="aQuickKeyword"
            :candidate-keys="store.aCandidateKeys"
            @update:selected="store.updateASelected"
            @set-grid-api="store.setAGridApi"
          />
        </NCard>

        <div class="match-splitter" @mousedown="startDrag"></div>

        <NCard
          class="match-section-card match-section-b"
          :bordered="false"
          :content-style="{ padding: '0' }"
          :style="{ flex: `${1 - splitRatio} 1 0` }"
        >
          <template #header>
            <div class="match-card-header">
              <div class="match-card-header-left">
                <span class="match-section-badge badge-b">B</span>
                <span class="section-title">{{ bModule }}</span>
                <span class="section-function-code">{{ bFunctionCode }}</span>
              </div>
              <div class="match-card-header-right-group">
                <div class="match-card-header-center">
                  <NSpace size="small" wrap justify="end">
                    <NButton size="small" type="default" @click="handleBRefresh">刷新</NButton>
                    <NButton size="small" type="default" @click="handleBReset">重置</NButton>
                    <NButton size="small" type="default" @click="handleBOpenPinColumn">固定列</NButton>
                    <NButton size="small" type="default" @click="handleBOpenFieldSelector">字段选择</NButton>
                    <NButton size="small" type="default" @click="handleBScrollToMatched" :disabled="store.bSelectedKeys.value.length === 0">
                      定位匹配
                    </NButton>
                    <NInput
                      v-model:value="bQuickKeyword"
                      size="small"
                      placeholder="快速检索"
                      style="width: 180px;"
                      :clearable="true"
                    />
                    <span class="match-count-text">
                      已选 {{ store.bSelectedKeys.value.length }} 行 · 共 {{ bDisplayedCount }} 条
                    </span>
                  </NSpace>
                </div>
                <div class="match-card-header-right">
                  {{ bMatchedCount }}/{{ store.bData.value.rows.length }} 已匹配 · {{ bProgress }}%
                </div>
              </div>
            </div>
          </template>
          <MatchTablePanel
            ref="bTableRef"
            side="B"
            :data="store.bData.value"
            :display-filter="store.displayFilter.value"
            :selected-keys="store.bSelectedKeys.value"
            :matched-keys="store.bMatchedKeys"
            :quick-keyword="bQuickKeyword"
            :candidate-keys="store.bCandidateKeys"
            @update:selected="store.updateBSelected"
            @set-grid-api="store.setBGridApi"
          />
        </NCard>
      </div>
    </div>

    <NAlert v-if="error" type="error" :show-icon="true" class="match-error">
      {{ error }}
    </NAlert>

    <MatchBottomBar
      :a-data="store.aData.value"
      :b-data="store.bData.value"
      :a-selected-keys="store.aSelectedKeys.value"
      :b-selected-keys="store.bSelectedKeys.value"
      :a-matched-keys="store.aMatchedKeys"
      :b-matched-keys="store.bMatchedKeys"
      :display-filter="store.displayFilter.value"
      :match-conditions="store.matchConditions.value"
      :selected-condition-indices="store.selectedConditionIndices.value"
      @change-display-filter="handleChangeDisplayFilter"
      @update-conditions="handleUpdateConditions"
      @build="handleBuild"
      @revoke="handleRevoke"
    />
  </div>
</template>

<style lang="scss" scoped>
.match-data-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #f5f5f5;
  overflow: hidden;
}

.match-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-height: 400px;
  padding: 4px 8px;
  gap: 0;
}

.match-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
}

.match-sections-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-height: 0;
  gap: 0;
}

.match-sections-wrapper.is-dragging {
  user-select: none;
  cursor: ns-resize;
}

.match-section-card {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-height: 100px;
  border-radius: 6px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
}

.match-section-card :deep(.n-card-header) {
  padding: 4px 12px;
  border-bottom: 1px solid #f0f0f0;
}

.match-card-header {
  display: flex;
  align-items: center;
  width: 100%;
  gap: 12px;
  min-height: 28px;
}

.match-card-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
  min-width: 0;
  max-width: 40%;
  white-space: nowrap;
  overflow: hidden;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #333;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.section-function-code {
  font-size: 12px;
  color: #999;
  font-family: 'Courier New', monospace;
  padding: 2px 6px;
  background: #f5f5f5;
  border-radius: 4px;
  flex-shrink: 0;
}

.match-card-header-right-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
  margin-left: auto;
}

.match-card-header-center {
  display: flex;
  justify-content: flex-end;
  min-width: 0;

  :deep(.n-space) {
    justify-content: flex-end;
    gap: 6px !important;
  }
}

.match-card-header-right {
  flex-shrink: 0;
  font-size: 13px;
  color: #666;
  white-space: nowrap;
}

.match-count-text {
  font-size: 13px;
  color: #999;
  white-space: nowrap;
}

.match-section-card :deep(.n-card__content) {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-height: 0;
  padding: 0;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #333;
}

.match-section-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  color: #fff;

  &.badge-a {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  }

  &.badge-b {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
  }
}

.match-section-a {
  margin-bottom: 0;
}

.match-section-b {
  margin-top: 0;
}

.match-splitter {
  height: 6px;
  background: #f5f5f5;
  cursor: ns-resize;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0;

  &:hover {
    background: #e8e8e8;
  }

  &::after {
    content: '';
    width: 36px;
    height: 3px;
    background: #d9d9d9;
    border-radius: 2px;
    opacity: 0.6;
  }
}

.match-error {
  margin: 16px;
  flex-shrink: 0;
}

.system-dark {
  &.match-data-page {
    background: #1a1a1a;
  }

  .match-section-card {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
  }

  .match-section-card :deep(.n-card-header) {
    border-bottom-color: #3a3a3a;
  }

  .section-title {
    color: #e0e0e0;
  }

  .section-function-code {
    color: #888;
    background: #333;
  }

  .match-card-header-right {
    color: #aaa;
  }

  .match-count-text {
    color: #888;
  }

  .match-splitter {
    background: #1a1a1a;

    &:hover {
      background: #333;
    }

    &::after {
      background: #555;
    }
  }

  :deep(.match-bottom-bar) {
    background: #2a2a2a;
    border-top-color: #3a3a3a;
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.3);
    color: #e0e0e0;
  }
}
</style>
