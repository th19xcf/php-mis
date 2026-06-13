<script setup lang="ts">
import { NModal, NSpin, NSpace, NButton, NFormItem, NCascader, NEmpty, NText } from 'naive-ui';

defineProps<{
  visible: boolean;
  loading: boolean;
  field: any;
  selectedValue: string | null;
  cascaderOptions: any[];
  levels: any[];
  maxLevel: number;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  'update:selectedValue': [value: string | null, option: any];
  /**
   * 「替换」按钮：直接把 cascader 选中的值写回字段
   */
  replace: [];
  /**
   * 「添加」按钮：把 cascader 选中的值拼到原值之后（"," 分隔）
   */
  append: [];
  /**
   * 加载子节点
   * - node: 待展开的节点
   * - resolve: 父组件在子节点加载完成后必须调用，否则 NCascader 不会把
   *   该节点登记为"已加载完成"，导致末级节点无法被选中
   */
  loadChildren: [node: any, resolve: () => void];
}>();

/**
 * Naive UI 的 NCascader 在 remote 模式下，on-load 必须返回一个 Promise，
 * 并且要等到父组件真正把 children 写到 option 上、且 isLeaf 状态确定下来
 * 之后再 resolve，NCascader 才会正确把节点登记为已加载的叶子节点，
 * 进而支持点击选中。
 *
 * 因此这里把 emit 改成携带 resolve 回调，由父组件在数据回填后调用。
 */
function handleLoadChildren(node: any): Promise<void> {
  return new Promise<void>(resolve => {
    emit('loadChildren', node, resolve);
  });
}

function handleValueChange(value: string | null, option: any) {
  emit('update:selectedValue', value, option);
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    :title="field?.columnName || '选择'"
    class="w-600px"
    :mask-closable="false"
    @update:show="emit('update:visible', $event)"
  >
    <NSpin :show="loading">
      <NSpace vertical :size="16">
        <NFormItem label="选择路径">
          <NCascader
            :value="selectedValue"
            :options="cascaderOptions"
            :on-load="handleLoadChildren"
            remote
            expand-trigger="click"
            placeholder="请选择"
            clearable
            @update:value="handleValueChange"
          />
        </NFormItem>

        <div v-if="levels.length" class="popup-levels-hint">
          <NText depth="3">
            共 {{ maxLevel }} 级：
            <span v-for="(level, index) in levels" :key="level.level">
              {{ level.name }}
              <span v-if="index < levels.length - 1">→</span>
            </span>
          </NText>
        </div>
        <NEmpty v-else description="暂无数据" />

        <NSpace justify="end">
          <NButton @click="emit('update:visible', false)">取消</NButton>
          <NButton :disabled="!selectedValue" @click="emit('replace')">替换</NButton>
          <NButton type="primary" :disabled="!selectedValue" @click="emit('append')">添加</NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
</template>

<style scoped>
.popup-levels-hint {
  padding: 8px 0;
  font-size: 12px;
}
</style>
