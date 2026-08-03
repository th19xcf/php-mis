<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useMessage, useDialog } from 'naive-ui';
import {
  fetchWorkflowNodeCreate,
  fetchWorkflowNodeUpdate,
  fetchWorkflowTemplateCreate
} from '@/service/api/workflow';
import WorkflowNodeTemplateSelector from './WorkflowNodeTemplateSelector.vue';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  defId: number;
  businessType?: string;
  node: Record<string, any> | null;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessage();
const dialog = useDialog();

const formData = ref({
  节点编码: '',
  节点名称: '',
  节点类型: 'APPROVAL',
  审批人类型: '' as string,
  审批人配置: '' as string,
  会签或签: 'OR',
  超时天数: 0,
  超时处理: 'NOTIFY',
  排序: 0
});

// 模板选择器弹窗
const showTemplateSelector = ref(false);

// "保存为模板"子弹窗状态
const showSaveAsTemplate = ref(false);
const templateFormData = ref({
  模板编码: '',
  模板名称: '',
  适用业务类型: '',
  模板说明: ''
});

const nodeTypeOptions = [
  { label: '开始(START)', value: 'START' },
  { label: '审批(APPROVAL)', value: 'APPROVAL' },
  { label: '抄送(CC)', value: 'CC' },
  { label: '结束(END)', value: 'END' }
];

const approverTypeOptions = [
  { label: '不配置(留空)', value: '' },
  { label: '角色(ROLE)', value: 'ROLE' },
  { label: '部门(DEPT)', value: 'DEPT' },
  { label: '上级(SUPERIOR)', value: 'SUPERIOR' },
  { label: '指定人(ASSIGN)', value: 'ASSIGN' },
  { label: '发起人(SPONSOR)', value: 'SPONSOR' }
];

const signModeOptions = [
  { label: '或签(任一同意即推进)', value: 'OR' },
  { label: '会签(全部同意才推进)', value: 'AND' }
];

const timeoutActionOptions = [
  { label: '通知(NOTIFY)', value: 'NOTIFY' },
  { label: '自动同意(AUTO_APPROVE)', value: 'AUTO_APPROVE' },
  { label: '自动拒绝(AUTO_REJECT)', value: 'AUTO_REJECT' }
];

// 是否需要审批人配置(START/END 不需要)
const needApprover = computed(() => {
  const t = formData.value.节点类型;
  return t === 'APPROVAL' || t === 'CC';
});

// 审批人配置输入提示(根据审批人类型)
const approverConfigPlaceholder = computed(() => {
  switch (formData.value.审批人类型) {
    case 'ROLE':
      return 'JSON 数组,如:["R-APPROVER","R-MANAGER"]\n或逗号分隔:R-APPROVER,R-MANAGER';
    case 'DEPT':
      return '单个部门编码字符串,如:"D001"\n留空时自动使用发起人部门';
    case 'ASSIGN':
      return 'JSON 数组(工号),如:["E001","E002"]\n或逗号分隔:E001,E002';
    case 'SUPERIOR':
    case 'SPONSOR':
      return '此类型无需配置,留空即可';
    default:
      return '请先选择审批人类型';
  }
});

// 审批人配置是否禁用
const approverConfigDisabled = computed(() => {
  const t = formData.value.审批人类型;
  return t === 'SUPERIOR' || t === 'SPONSOR' || t === '';
});

function parseApproverConfig(value: any): string {
  if (value === null || value === undefined || value === '') return '';
  if (typeof value === 'string') return value;
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

watch(
  () => props.visible,
  (val) => {
    if (!val) return;
    if (props.mode === 'edit' && props.node) {
      formData.value = {
        节点编码: props.node.节点编码 || '',
        节点名称: props.node.节点名称 || '',
        节点类型: props.node.节点类型 || 'APPROVAL',
        审批人类型: props.node.审批人类型 || '',
        审批人配置: parseApproverConfig(props.node.审批人配置),
        会签或签: props.node.会签或签 || 'OR',
        超时天数: Number(props.node.超时天数) || 0,
        超时处理: props.node.超时处理 || 'NOTIFY',
        排序: Number(props.node.排序) || 0
      };
    } else {
      formData.value = {
        节点编码: '',
        节点名称: '',
        节点类型: 'APPROVAL',
        审批人类型: '',
        审批人配置: '',
        会签或签: 'OR',
        超时天数: 0,
        超时处理: 'NOTIFY',
        排序: 0
      };
    }
  },
  { immediate: true }
);

function handleClose() {
  emit('update:visible', false);
}

function buildPayload() {
  const payload: Record<string, any> = {
    节点编码: formData.value.节点编码.trim(),
    节点名称: formData.value.节点名称.trim(),
    节点类型: formData.value.节点类型,
    会签或签: formData.value.会签或签,
    超时天数: Number(formData.value.超时天数) || 0,
    超时处理: formData.value.超时处理,
    排序: Number(formData.value.排序) || 0
  };

  // 审批人类型:空字符串转 null
  const approverType = formData.value.审批人类型;
  payload.审批人类型 = approverType ? approverType : null;

  // 审批人配置:根据类型处理(START/END/SUPERIOR/SPONSOR 留空)
  payload.审批人配置 = approverConfigDisabled.value
    ? null
    : formData.value.审批人配置.trim();

  return payload;
}

async function handleSubmit() {
  if (!formData.value.节点编码.trim()) {
    message.error('请输入节点编码');
    return;
  }
  if (!formData.value.节点名称.trim()) {
    message.error('请输入节点名称');
    return;
  }

  try {
    const payload = buildPayload();
    if (props.mode === 'create') {
      await fetchWorkflowNodeCreate({ 流程定义ID: props.defId, ...payload } as any);
      message.success('节点创建成功');
    } else if (props.node) {
      await fetchWorkflowNodeUpdate({ nodeId: props.node.GUID, ...payload } as any);
      message.success('节点更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

// ============ 模板相关 ============

function handleOpenTemplateSelector() {
  showTemplateSelector.value = true;
}

function handleTemplateSelect(template: Record<string, any>) {
  // 从模板导入字段(节点编码、节点名称、排序 不覆盖,由用户填写)
  formData.value.节点类型 = template.节点类型 || 'APPROVAL';
  formData.value.审批人类型 = template.审批人类型 || '';
  formData.value.审批人配置 = parseApproverConfig(template.审批人配置);
  formData.value.会签或签 = template.会签或签 || 'OR';
  formData.value.超时天数 = Number(template.超时天数) || 0;
  formData.value.超时处理 = template.超时处理 || 'NOTIFY';
  message.success(`已从模板「${template.模板名称}」导入配置`);
}

function handleOpenSaveAsTemplate() {
  // 预填:模板编码 = 节点编码(若节点编码已填),模板名称 = 节点名称
  templateFormData.value = {
    模板编码: formData.value.节点编码.trim() || '',
    模板名称: formData.value.节点名称.trim() || '',
    适用业务类型: props.businessType || '',
    模板说明: ''
  };
  showSaveAsTemplate.value = true;
}

async function handleSaveAsTemplate() {
  if (!templateFormData.value.模板编码.trim()) {
    message.error('请输入模板编码');
    return;
  }
  if (!templateFormData.value.模板名称.trim()) {
    message.error('请输入模板名称');
    return;
  }

  try {
    const approverType = formData.value.审批人类型 || null;
    const approverConfig = approverConfigDisabled.value
      ? null
      : formData.value.审批人配置.trim();

    await fetchWorkflowTemplateCreate({
      模板编码: templateFormData.value.模板编码.trim(),
      模板名称: templateFormData.value.模板名称.trim(),
      节点类型: formData.value.节点类型,
      审批人类型: approverType as any,
      审批人配置: approverConfig as any,
      会签或签: formData.value.会签或签,
      超时天数: Number(formData.value.超时天数) || 0,
      超时处理: formData.value.超时处理,
      适用业务类型: templateFormData.value.适用业务类型.trim() || null,
      模板说明: templateFormData.value.模板说明.trim() || null
    } as any);
    message.success('模板保存成功');
    showSaveAsTemplate.value = false;
  } catch (e: any) {
    message.error(e?.message || '保存模板失败');
  }
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    :title="mode === 'create' ? '新增节点' : '编辑节点'"
    style="width: 640px"
    :bordered="false"
    size="huge"
    @update:show="(v: boolean) => emit('update:visible', v)"
  >
    <template #header-extra>
      <NSpace :size="8">
        <NButton size="small" quaternary type="info" @click="handleOpenTemplateSelector">
          <template #icon><icon-mdi-folder-search-outline /></template>
          从模板导入
        </NButton>
        <NButton size="small" quaternary type="warning" @click="handleOpenSaveAsTemplate">
          <template #icon><icon-mdi-content-save-outline /></template>
          保存为模板
        </NButton>
      </NSpace>
    </template>

    <NSpace vertical :size="16">
      <NGrid :cols="2" :x-gap="16" :y-gap="12" responsive="screen">
        <NGi>
          <div class="form-item">
            <label class="form-label">节点编码 <span class="required">*</span></label>
            <NInput
              v-model:value="formData.节点编码"
              placeholder="如:start、mgr、approve1"
              :disabled="mode === 'edit'"
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">节点名称 <span class="required">*</span></label>
            <NInput v-model:value="formData.节点名称" placeholder="如:开始、部门经理审批" />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">节点类型 <span class="required">*</span></label>
            <NSelect v-model:value="formData.节点类型" :options="nodeTypeOptions" />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">会签/或签</label>
            <NSelect
              v-model:value="formData.会签或签"
              :options="signModeOptions"
              :disabled="!needApprover"
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">审批人类型</label>
            <NSelect
              v-model:value="formData.审批人类型"
              :options="approverTypeOptions"
              :disabled="!needApprover"
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">超时天数</label>
            <NInputNumber
              v-model:value="formData.超时天数"
              :min="0"
              :max="365"
              placeholder="0 表示不超时"
              style="width: 100%"
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">超时处理</label>
            <NSelect v-model:value="formData.超时处理" :options="timeoutActionOptions" />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">排序</label>
            <NInputNumber
              v-model:value="formData.排序"
              :min="0"
              placeholder="0 表示自动追加末尾"
              style="width: 100%"
            />
          </div>
        </NGi>
      </NGrid>

      <div class="form-item">
        <label class="form-label">审批人配置</label>
        <NInput
          v-model:value="formData.审批人配置"
          type="textarea"
          :rows="4"
          :placeholder="approverConfigPlaceholder"
          :disabled="approverConfigDisabled"
          class="json-input"
        />
        <div class="form-tip" v-if="needApprover && formData.审批人类型">
          <NIcon size="14"><icon-mdi-information-outline /></NIcon>
          <span v-if="formData.审批人类型 === 'ROLE'">查 def_role_group 表,匹配角色编码字段</span>
          <span v-else-if="formData.审批人类型 === 'DEPT'">单个部门编码字符串(如 "D001"),留空自动用发起人部门</span>
          <span v-else-if="formData.审批人类型 === 'ASSIGN'">按工号指定,JSON 数组或逗号分隔</span>
          <span v-else-if="formData.审批人类型 === 'SUPERIOR'">当前为占位实现,硬编码返回 admin(慎用)</span>
          <span v-else-if="formData.审批人类型 === 'SPONSOR'">直接使用发起人作为审批人</span>
        </div>
      </div>
    </NSpace>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleClose">取消</NButton>
        <NButton type="primary" @click="handleSubmit">确定</NButton>
      </NSpace>
    </template>

    <!-- 模板选择器弹窗 -->
    <WorkflowNodeTemplateSelector
      v-model:visible="showTemplateSelector"
      :business-type="businessType"
      @select="handleTemplateSelect"
    />

    <!-- 保存为模板子弹窗 -->
    <NModal
      v-model:show="showSaveAsTemplate"
      preset="card"
      title="保存为节点模板"
      style="width: 480px"
      :bordered="false"
      size="medium"
    >
      <NSpace vertical :size="12">
        <div class="form-item">
          <label class="form-label">模板编码 <span class="required">*</span></label>
          <NInput v-model:value="templateFormData.模板编码" placeholder="如:finance_approve" />
        </div>
        <div class="form-item">
          <label class="form-label">模板名称 <span class="required">*</span></label>
          <NInput v-model:value="templateFormData.模板名称" placeholder="如:财务审批" />
        </div>
        <div class="form-item">
          <label class="form-label">适用业务类型</label>
          <NInput v-model:value="templateFormData.适用业务类型" placeholder="如:CONTRACT,EXPENSE(逗号分隔,留空表示通用)" />
        </div>
        <div class="form-item">
          <label class="form-label">模板说明</label>
          <NInput v-model:value="templateFormData.模板说明" type="textarea" :rows="2" placeholder="选填" />
        </div>
        <div class="form-tip">
          <NIcon size="14"><icon-mdi-information-outline /></NIcon>
          <span>模板将保存当前节点的类型/审批人/会签模式/超时配置,不保存节点编码/名称/排序</span>
        </div>
      </NSpace>

      <template #footer>
        <NSpace justify="end">
          <NButton @click="showSaveAsTemplate = false">取消</NButton>
          <NButton type="primary" @click="handleSaveAsTemplate">保存模板</NButton>
        </NSpace>
      </template>
    </NModal>
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

.json-input :deep(textarea) {
  font-family: 'Consolas', 'Monaco', monospace;
  font-size: 12px;
}

// 暗黑模式
.system-dark .form-item {
  .form-label {
    color: #b0b0b0;
  }

  .form-tip {
    color: #888;
  }
}
</style>
