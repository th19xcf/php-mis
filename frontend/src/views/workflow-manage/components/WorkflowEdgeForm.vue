<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import {
  fetchWorkflowEdgeCreate,
  fetchWorkflowEdgeUpdate
} from '@/service/api/workflow';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  defId: number;
  edge: Record<string, any> | null;
  nodes: Array<Record<string, any>>; // 同流程下的全部节点,用于下拉选择
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessageWithConsole();

/** 单条结构化条件（多条件之间为 AND 关系） */
interface ConditionRow {
  field: string;
  op: string;
  value: string;
  value2: string; // 仅 between 使用（区间上限）
}

const formData = ref({
  源节点编码: '',
  目标节点编码: '',
  条件描述: '',
  排序: 0
});

const conditions = ref<ConditionRow[]>([]);

// 支持的操作符（与后端 WorkflowConditionMatcher 对齐）
const opOptions = [
  { label: '等于 (=)', value: '=' },
  { label: '不等于 (!=)', value: '!=' },
  { label: '大于 (>)', value: '>' },
  { label: '大于等于 (>=)', value: '>=' },
  { label: '小于 (<)', value: '<' },
  { label: '小于等于 (<=)', value: '<=' },
  { label: '在集合中 (in)', value: 'in' },
  { label: '区间 (between)', value: 'between' },
  { label: '包含匹配 (like)', value: 'like' },
  { label: '为空 (isnull)', value: 'isnull' },
  { label: '不为空 (notnull)', value: 'notnull' }
];

// 节点选项(显示编码+名称)
const nodeOptions = computed(() => {
  if (!props.nodes || props.nodes.length === 0) return [];
  return props.nodes.map((n) => ({
    label: `${n.节点编码} (${n.节点名称 || '-'})`,
    value: n.节点编码
  }));
});

/** 数值字符串转数字（保持其他值原样），用于条件值类型化 */
function typedValue(v: string): any {
  return /^-?\d+(\.\d+)?$/.test(v) ? Number(v) : v;
}

/** 将 匹配条件 JSON 解析为条件行（编辑回显） */
function parseMatchCondition(raw: any): ConditionRow[] {
  let obj: Record<string, any> | null = null;
  if (raw) {
    if (typeof raw === 'string') {
      try {
        obj = JSON.parse(raw);
      } catch {
        obj = null;
      }
    } else if (typeof raw === 'object') {
      obj = raw;
    }
  }
  const rows: ConditionRow[] = [];
  if (!obj) return rows;
  for (const [field, rule] of Object.entries(obj)) {
    if (rule !== null && typeof rule === 'object' && !Array.isArray(rule)) {
      // 操作符对象：{">=":100,"<":500}
      for (const [op, operand] of Object.entries(rule as Record<string, any>)) {
        if (op === 'between' && Array.isArray(operand)) {
          rows.push({ field, op, value: String(operand[0] ?? ''), value2: String(operand[1] ?? '') });
        } else if (op === 'in' && Array.isArray(operand)) {
          rows.push({ field, op, value: operand.join(','), value2: '' });
        } else {
          rows.push({ field, op, value: operand === null ? '' : String(operand), value2: '' });
        }
      }
    } else if (Array.isArray(rule)) {
      // 标量数组视为 IN 集合
      rows.push({ field, op: 'in', value: rule.join(','), value2: '' });
    } else {
      // 标量：等值匹配
      rows.push({ field, op: '=', value: rule === null ? '' : String(rule), value2: '' });
    }
  }
  return rows;
}

/** 将条件行构建为 匹配条件 JSON（同字段多操作符合并为一个对象）；无有效条件返回 null（默认流转） */
function buildMatchCondition(): Record<string, any> | null {
  const result: Record<string, any> = {};
  for (const c of conditions.value) {
    const field = c.field.trim();
    if (!field || !c.op) continue;

    if (c.op === 'between') {
      const lo = c.value.trim();
      const hi = c.value2.trim();
      if (!lo || !hi) {
        throw new Error(`条件「${field}」的区间需要填写下限和上限`);
      }
      result[field] = { ...(result[field] || {}), between: [typedValue(lo), typedValue(hi)] };
      continue;
    }

    if (c.op === 'in') {
      const list = c.value
        .split(',')
        .map((s) => s.trim())
        .filter((s) => s !== '');
      if (list.length === 0) {
        throw new Error(`条件「${field}」的集合值不能为空`);
      }
      result[field] = { ...(result[field] || {}), in: list.map(typedValue) };
      continue;
    }

    if (c.op === 'isnull' || c.op === 'notnull') {
      result[field] = { ...(result[field] || {}), [c.op]: null };
      continue;
    }

    const v = c.value.trim();
    if (!v) {
      throw new Error(`条件「${field}」的值不能为空`);
    }
    result[field] = { ...(result[field] || {}), [c.op]: typedValue(v) };
  }
  return Object.keys(result).length > 0 ? result : null;
}

watch(
  () => props.visible,
  (val) => {
    if (!val) return;
    if (props.mode === 'edit' && props.edge) {
      formData.value = {
        源节点编码: props.edge.源节点编码 || '',
        目标节点编码: props.edge.目标节点编码 || '',
        条件描述: props.edge.条件描述 || '',
        排序: Number(props.edge.排序) || 0
      };
      conditions.value = parseMatchCondition(props.edge.匹配条件);
    } else {
      formData.value = {
        源节点编码: '',
        目标节点编码: '',
        条件描述: '',
        排序: 0
      };
      conditions.value = [];
    }
  },
  { immediate: true }
);

function handleClose() {
  emit('update:visible', false);
}

function addCondition() {
  conditions.value.push({ field: '', op: '=', value: '', value2: '' });
}

function removeCondition(idx: number) {
  conditions.value.splice(idx, 1);
}

async function handleSubmit() {
  if (!formData.value.源节点编码) {
    message.error('请选择源节点');
    return;
  }
  if (!formData.value.目标节点编码) {
    message.error('请选择目标节点');
    return;
  }
  if (formData.value.源节点编码 === formData.value.目标节点编码) {
    message.error('源节点和目标节点不能相同');
    return;
  }

  let matchCondition: Record<string, any> | null;
  try {
    matchCondition = buildMatchCondition();
  } catch (e: any) {
    message.error(e?.message || '条件配置有误');
    return;
  }

  try {
    const payload: Record<string, any> = {
      源节点编码: formData.value.源节点编码,
      目标节点编码: formData.value.目标节点编码,
      匹配条件: matchCondition,
      条件描述: formData.value.条件描述.trim() || null,
      排序: Number(formData.value.排序) || 0
    };

    if (props.mode === 'create') {
      await fetchWorkflowEdgeCreate({ 流程定义ID: props.defId, ...payload } as any);
      message.success('连线创建成功');
    } else if (props.edge) {
      await fetchWorkflowEdgeUpdate({ edgeId: props.edge.GUID, ...payload } as any);
      message.success('连线更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    :title="mode === 'create' ? '新增连线' : '编辑连线'"
    style="width: 640px"
    :bordered="false"
    size="huge"
    @update:show="(v: boolean) => emit('update:visible', v)"
  >
    <NSpace vertical :size="16">
      <NGrid :cols="2" :x-gap="16" :y-gap="12" responsive="screen">
        <NGi>
          <div class="form-item">
            <label class="form-label">源节点 <span class="required">*</span></label>
            <NSelect
              v-model:value="formData.源节点编码"
              :options="nodeOptions"
              placeholder="选择源节点"
              :disabled="nodeOptions.length === 0"
              filterable
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">目标节点 <span class="required">*</span></label>
            <NSelect
              v-model:value="formData.目标节点编码"
              :options="nodeOptions"
              placeholder="选择目标节点"
              :disabled="nodeOptions.length === 0"
              filterable
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">排序</label>
            <NInputNumber
              v-model:value="formData.排序"
              :min="0"
              placeholder="0 表示自动追加"
              style="width: 100%"
            />
          </div>
        </NGi>
      </NGrid>

      <div class="form-item">
        <label class="form-label">流转条件（多条件之间为"且"，不添加任何条件表示默认流转）</label>
        <div v-for="(c, idx) in conditions" :key="idx" class="condition-row">
          <NInput v-model:value="c.field" placeholder="变量名，如 合同金额" class="cond-field" />
          <NSelect v-model:value="c.op" :options="opOptions" class="cond-op" />
          <NInput
            v-if="c.op === 'in'"
            v-model:value="c.value"
            placeholder="多个值用英文逗号分隔"
            class="cond-value"
          />
          <template v-else-if="c.op === 'between'">
            <NInput v-model:value="c.value" placeholder="下限（含）" class="cond-value" />
            <NInput v-model:value="c.value2" placeholder="上限（含）" class="cond-value" />
          </template>
          <NInput
            v-else-if="c.op !== 'isnull' && c.op !== 'notnull'"
            v-model:value="c.value"
            placeholder="值"
            class="cond-value"
          />
          <NButton size="small" quaternary type="error" @click="removeCondition(idx)">删除</NButton>
        </div>
        <NButton size="small" dashed type="primary" @click="addCondition">+ 添加条件</NButton>
        <div class="form-tip">
          <NIcon size="14"><icon-mdi-information-outline /></NIcon>
          <span>变量名对应发起流程时的流程变量（如 合同类型、合同金额）；多分支场景按排序先后匹配</span>
        </div>
      </div>

      <div class="form-item">
        <label class="form-label">条件描述</label>
        <NInput v-model:value="formData.条件描述" placeholder="如:金额大于100万" />
      </div>
    </NSpace>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleClose">取消</NButton>
        <NButton type="primary" @click="handleSubmit">确定</NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped lang="scss">
.form-item {
  display: flex;
  flex-direction: column;
  gap: 6px;

  .form-label {
    font-size: 13px;
    color: #666;

    .required {
      color: #ff4d4f;
    }
  }

  .form-tip {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #999;
    line-height: 1.4;
  }
}

.condition-row {
  display: flex;
  align-items: center;
  gap: 8px;

  .cond-field {
    width: 150px;
    flex-shrink: 0;
  }

  .cond-op {
    width: 140px;
    flex-shrink: 0;
  }

  .cond-value {
    flex: 1;
    min-width: 0;
  }
}

.system-dark .form-item {
  .form-label {
    color: #b0b0b0;
  }

  .form-tip {
    color: #888;
  }
}
</style>
