<script setup lang="ts">
import { ref, computed } from 'vue';
import { NButton, NAlert, NSpace, NModal, NCheckboxGroup, NCheckbox } from 'naive-ui';

import type { MatchModuleData } from '@/hooks/business/use-match-store';
import type { MatchCondition } from '@/service/api/match';

interface Props {
  aData: MatchModuleData;
  bData: MatchModuleData;
  aSelectedKeys: string[];
  bSelectedKeys: string[];
  aMatchedKeys: Map<string, string[]>;
  bMatchedKeys: Map<string, string[]>;
  onlyUnmatched: boolean;
  isSaving?: boolean;
  matchConditions?: MatchCondition[];
  selectedConditionIndices?: number[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
  build: [];
  revoke: [];
  toggleUnmatched: [value: boolean];
  updateConditions: [indices: number[]];
}>();

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

const canBuild = computed(() => {
  return props.aSelectedKeys.length > 0 && props.bSelectedKeys.length > 0;
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
        <span class="text-sm text-gray-600">{{ previewText }}</span>
      </div>

      <NSpace align="center">
        <label class="match-bottom-checkbox">
          <NCheckbox :checked="onlyUnmatched" @update:checked="emit('toggleUnmatched', $event)" />
          <span class="text-sm">只看未匹配</span>
        </label>

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
  flex-direction: column;
  justify-content: center;
  min-height: 28px;

  :deep(.n-alert) {
    margin-bottom: 4px;
  }
}

.match-bottom-checkbox {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  line-height: 1;

  :deep(.n-checkbox) {
    display: inline-flex;
    align-items: center;
    margin: 0;
    padding: 0;
  }

  :deep(.n-checkbox-box) {
    margin: 0;
  }

  .text-sm {
    line-height: 1;
  }
}
</style>
