<script setup lang="ts">
import { ref, computed } from 'vue';
import { NButton, NAlert, NSpace, NModal, NCheckboxGroup, NCheckbox, NDropdown, useThemeVars } from 'naive-ui';

import type { MatchModuleData } from '@/hooks/business/use-match-store';
import type { MatchCondition, MatchColumn } from '@/service/api/match';

type DisplayFilter = 'all' | 'matched' | 'unmatched' | 'candidate';

interface Props {
  aData: MatchModuleData;
  bData: MatchModuleData;
  aSelectedKeys: string[];
  bSelectedKeys: string[];
  aMatchedKeys: Map<string, string[]>;
  bMatchedKeys: Map<string, string[]>;
  displayFilter: DisplayFilter;
  isSaving?: boolean;
  matchConditions?: MatchCondition[];
  selectedConditionIndices?: number[];
}

const props = defineProps<Props>();

const themeVars = useThemeVars();

const emit = defineEmits<{
  build: [];
  revoke: [];
  changeDisplayFilter: [value: DisplayFilter];
  updateConditions: [indices: number[]];
}>();

const displayFilterOptions: { label: string; value: DisplayFilter }[] = [
  { label: '全部', value: 'all' },
  { label: '已匹配', value: 'matched' },
  { label: '未匹配', value: 'unmatched' },
  { label: '满足条件', value: 'candidate' }
];

const displayFilterLabel = computed(() => {
  const item = displayFilterOptions.find(o => o.value === props.displayFilter);
  return item ? item.label : '全部';
});

const displayFilterMenuOptions = displayFilterOptions.map(o => ({
  label: o.label,
  key: o.value
}));

function handleDisplayFilterSelect(key: string) {
  emit('changeDisplayFilter', key as DisplayFilter);
}

const conditionModalVisible = ref(false);
const tempSelectedIndices = ref<number[]>([]);

function openConditionModal() {
  tempSelectedIndices.value = [...(props.selectedConditionIndices || [])];
  conditionModalVisible.value = true;
}

function confirmConditions() {
  emit('updateConditions', tempSelectedIndices.value);
  conditionModalVisible.value = false;
}

const previewText = computed(() => {
  if (props.aSelectedKeys.length === 0 && props.bSelectedKeys.length === 0) {
    return '请在上方选择要匹配的记录';
  }

  const aLabels: string[] = [];
  for (const key of props.aSelectedKeys) {
    const row = props.aData.rows.find(r => String(r[props.aData.matchCols.key]) === key);
    if (row) {
      const label = props.aData.matchCols.label 
        ? String(row[props.aData.matchCols.label] ?? key)
        : key;
      aLabels.push(label);
    }
  }

  const bLabels: string[] = [];
  for (const key of props.bSelectedKeys) {
    const row = props.bData.rows.find(r => String(r[props.bData.matchCols.key]) === key);
    if (row) {
      const label = props.bData.matchCols.label 
        ? String(row[props.bData.matchCols.label] ?? key)
        : key;
      bLabels.push(label);
    }
  }

  return `${aLabels.join(', ')} ↔ ${bLabels.join(', ')}`;
});

const amountDiff = computed(() => {
  if (!props.aData.matchCols.amount || !props.bData.matchCols.amount) {
    return null;
  }

  let aTotal = 0;
  for (const key of props.aSelectedKeys) {
    const row = props.aData.rows.find(r => String(r[props.aData.matchCols.key]) === key);
    if (row) {
      aTotal += Number(row[props.aData.matchCols.amount] ?? 0);
    }
  }

  let bTotal = 0;
  for (const key of props.bSelectedKeys) {
    const row = props.bData.rows.find(r => String(r[props.bData.matchCols.key]) === key);
    if (row) {
      bTotal += Number(row[props.bData.matchCols.amount] ?? 0);
    }
  }

  const diff = aTotal - bTotal;
  return { aTotal, bTotal, diff, hasDiff: Math.abs(diff) > 0.001 };
});

/**
 * 构建字段名 -> 显示标签（字段别名/列名）的映射，用于底部工具栏友好显示
 */
function buildFieldLabelMap(columns: MatchColumn[]): Map<string, string> {
  const map = new Map<string, string>();
  for (const col of columns) {
    const fieldName = String(col['字段名'] ?? '');
    if (!fieldName) continue;
    const alias = String(col['字段别名'] ?? '');
    const colName = String(col['列名'] ?? '');
    const label = alias || colName || fieldName;
    map.set(fieldName, label);
  }
  return map;
}

const aFieldLabelMap = computed(() => buildFieldLabelMap(props.aData.columns));
const bFieldLabelMap = computed(() => buildFieldLabelMap(props.bData.columns));

interface CalcFieldPair {
  aField: string;
  bField: string;
  aLabel: string;
  bLabel: string;
  aTotal: number;
  bTotal: number;
  relation: '>' | '<' | '=';
  matched: boolean;
}

/**
 * 计算字段配对汇总比较
 * A/B 两侧计算字段按位置一一对应，逐对求勾选记录的合计并比较，等于则满足条件（高亮）
 * 未配置任何计算字段时返回 null，模板不渲染该区域
 */
const calcFieldsCompare = computed<CalcFieldPair[] | null>(() => {
  const aFields = props.aData.calcFields || [];
  const bFields = props.bData.calcFields || [];
  if (aFields.length === 0 && bFields.length === 0) {
    return null;
  }

  const maxLen = Math.max(aFields.length, bFields.length);
  const pairs: CalcFieldPair[] = [];

  for (let i = 0; i < maxLen; i++) {
    const aField = aFields[i] || '';
    const bField = bFields[i] || '';
    if (!aField && !bField) continue;

    let aTotal = 0;
    if (aField) {
      for (const key of props.aSelectedKeys) {
        const row = props.aData.rows.find(r => String(r[props.aData.matchCols.key]) === key);
        if (row) {
          aTotal += Number(row[aField] ?? 0) || 0;
        }
      }
    }

    let bTotal = 0;
    if (bField) {
      for (const key of props.bSelectedKeys) {
        const row = props.bData.rows.find(r => String(r[props.bData.matchCols.key]) === key);
        if (row) {
          bTotal += Number(row[bField] ?? 0) || 0;
        }
      }
    }

    const diff = aTotal - bTotal;
    const relation: '>' | '<' | '=' = Math.abs(diff) < 0.001 ? '=' : (diff > 0 ? '>' : '<');

    pairs.push({
      aField,
      bField,
      aLabel: aField ? (aFieldLabelMap.value.get(aField) || aField) : '—',
      bLabel: bField ? (bFieldLabelMap.value.get(bField) || bField) : '—',
      aTotal,
      bTotal,
      relation,
      matched: relation === '='
    });
  }

  return pairs;
});

/** 是否有任何勾选记录（用于整体状态提示：未勾选时显示"未选择"） */
const hasSelection = computed(() => {
  return props.aSelectedKeys.length > 0 || props.bSelectedKeys.length > 0;
});

/** 是否所有配对均满足等于条件（用于整体满足状态提示） */
const calcFieldsAllMatched = computed(() => {
  const pairs = calcFieldsCompare.value;
  if (!pairs || pairs.length === 0) return false;
  return pairs.every(p => p.matched);
});

const canBuild = computed(() => {
  if (props.aSelectedKeys.length === 0 || props.bSelectedKeys.length === 0) {
    return false;
  }

  const pairs = calcFieldsCompare.value;
  if (!pairs || pairs.length === 0) {
    return true;
  }

  return pairs.every(p => p.matched);
});

const canRevoke = computed(() => {
  if (props.aSelectedKeys.length === 0 && props.bSelectedKeys.length === 0) {
    return false;
  }

  if (props.aSelectedKeys.length > 0) {
    for (const key of props.aSelectedKeys) {
      const targets = props.aMatchedKeys.get(key) || [];
      if (targets.length > 0) {
        return true;
      }
    }
  }

  if (props.bSelectedKeys.length > 0) {
    for (const key of props.bSelectedKeys) {
      const targets = props.bMatchedKeys.get(key) || [];
      if (targets.length > 0) {
        return true;
      }
    }
  }

  return false;
});
</script>

<template>
  <div class="match-bottom-bar">
    <NSpace wrap align="center" class="w-full">
      <div class="flex-1 match-bottom-info">
        <NAlert
          v-if="amountDiff && amountDiff.hasDiff"
          type="warning"
          :show-icon="true"
          size="small"
        >
          金额差额：{{ amountDiff.aTotal }} vs {{ amountDiff.bTotal }}（差额 {{ amountDiff.diff.toFixed(2) }}）
        </NAlert>
        <div v-if="calcFieldsCompare" class="calc-fields-compare">
          <span
            v-if="!hasSelection"
            class="calc-overall-tag calc-overall-tag-empty"
          >
            未选择
          </span>
          <span
            v-else
            class="calc-overall-tag"
            :class="calcFieldsAllMatched ? 'calc-overall-tag-matched' : 'calc-overall-tag-pending'"
          >
            {{ calcFieldsAllMatched ? '✓ 全部满足' : '对比中' }}
          </span>
          <div
            v-for="(pair, idx) in calcFieldsCompare"
            :key="idx"
            class="calc-pair"
            :class="{ 'calc-pair-matched': pair.matched }"
          >
            <span class="calc-side">
              <span class="calc-label">{{ pair.aLabel }}</span>
              <span class="calc-value">{{ pair.aTotal.toFixed(2) }}</span>
            </span>
            <span class="calc-rel" :class="pair.matched ? 'calc-rel-eq' : 'calc-rel-neq'">{{ pair.relation }}</span>
            <span class="calc-side">
              <span class="calc-label">{{ pair.bLabel }}</span>
              <span class="calc-value">{{ pair.bTotal.toFixed(2) }}</span>
            </span>
          </div>
        </div>
        <span
          class="text-sm match-preview-text"
          :class="{ 'match-preview-empty': !hasSelection }"
          :style="hasSelection
            ? { color: themeVars.textColor1 }
            : { color: themeVars.textColor3 }"
        >{{ previewText }}</span>
      </div>

      <NSpace align="center">
        <NDropdown
          trigger="click"
          :options="displayFilterMenuOptions"
          @select="handleDisplayFilterSelect"
        >
          <NButton size="small" type="default">
            显示数据-{{ displayFilterLabel }}
          </NButton>
        </NDropdown>

        <NButton
          v-if="matchConditions && matchConditions.length > 0"
          type="default"
          size="small"
          @click="openConditionModal"
        >
          匹配条件{{ selectedConditionIndices && selectedConditionIndices.length > 0 ? `(${selectedConditionIndices.length})` : '' }}
        </NButton>

        <NButton
          type="primary"
          size="small"
          :disabled="!canBuild || isSaving"
          :loading="isSaving"
          @click="emit('build')"
        >
          建立匹配
        </NButton>

        <NButton
          type="default"
          size="small"
          :disabled="!canRevoke || isSaving"
          :loading="isSaving"
          @click="emit('revoke')"
        >
          撤销匹配
        </NButton>
      </NSpace>
    </NSpace>

    <NModal
      v-model:show="conditionModalVisible"
      preset="card"
      title="选择匹配条件"
      style="width: 500px;"
      :bordered="false"
    >
      <NCheckboxGroup v-model:value="tempSelectedIndices">
        <NSpace vertical>
          <NCheckbox
            v-for="(cond, idx) in matchConditions"
            :key="idx"
            :value="idx"
            :label="cond.text"
          />
        </NSpace>
      </NCheckboxGroup>
      <template #footer>
        <NSpace justify="end">
          <NButton size="small" @click="conditionModalVisible = false">取消</NButton>
          <NButton size="small" type="primary" @click="confirmConditions">确定</NButton>
        </NSpace>
      </template>
    </NModal>
  </div>
</template>

<style lang="scss" scoped>
.match-bottom-bar {
  min-height: 36px;
  padding: 4px 12px;
  margin: 8px 8px 0;
  display: flex;
  align-items: center;
  box-sizing: border-box;
  background: #fff;
  border-radius: 6px;
  border-top: 1px solid #f0f0f0;
  box-shadow: 0 -1px 4px rgba(0, 0, 0, 0.04);
}

.match-bottom-info {
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: flex-start;
  flex: 1;
  min-width: 0;
  min-height: 28px;
  gap: 8px;

  :deep(.n-alert) {
    flex-shrink: 0;
    margin-bottom: 0;
  }
}

/* 计算字段配对汇总比较 */
.calc-fields-compare {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  margin-bottom: 0;
}

.match-bottom-info > .match-preview-text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.calc-overall-tag {
  display: inline-flex;
  align-items: center;
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  line-height: 18px;
}

.calc-overall-tag-matched {
  background: #f6ffed;
  color: #389e0d;
  border: 1px solid #b7eb8f;
}

.calc-overall-tag-pending {
  background: #fff7e6;
  color: #d46b08;
  border: 1px solid #ffd591;
}

.calc-overall-tag-empty {
  background: #f5f5f5;
  color: #999;
  border: 1px solid #d9d9d9;
}

.calc-pair {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 8px;
  border-radius: 4px;
  background: #fafafa;
  border: 1px solid #e8e8e8;
  font-size: 12px;
  line-height: 20px;
}

.calc-pair-matched {
  background: #f6ffed;
  border: 1px solid #b7eb8f;
}

.calc-side {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.calc-label {
  color: #666;
}

.calc-value {
  font-weight: 600;
  color: #333;
}

.calc-rel {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 16px;
  font-weight: 700;
  font-size: 13px;
}

.calc-rel-eq {
  color: #389e0d;
}

.calc-rel-neq {
  color: #1890ff;
}
</style>
