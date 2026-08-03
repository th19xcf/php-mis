<script setup lang="ts">
import { ref, watch } from 'vue';
import { useMessage } from 'naive-ui';
import {
  fetchWorkflowTemplateList,
  fetchWorkflowTemplateDelete
} from '@/service/api/workflow';

const props = defineProps<{
  visible: boolean;
  businessType?: string; // 当前流程的业务类型,用于筛选适用模板
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  select: [template: Record<string, any>];
}>();

const message = useMessage();

const loading = ref(false);
const templateList = ref<any[]>([]);
const keyword = ref('');

async function loadTemplates() {
  loading.value = true;
  try {
    const res = await fetchWorkflowTemplateList({
      businessType: props.businessType || '',
      keyword: keyword.value.trim()
    });
    const data = (res as any)?.data || res;
    templateList.value = data?.list || [];
  } catch (e: any) {
    message.error(e?.message || '加载模板列表失败');
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val) {
      keyword.value = '';
      loadTemplates();
    }
  },
  { immediate: true }
);

function handleSelect(template: any) {
  emit('select', template);
  emit('update:visible', false);
}

function handleDelete(template: any) {
  window.$dialog?.warning({
    title: '确认删除',
    content: `确定要删除模板「${template.模板名称}」吗?已使用此模板创建的节点不受影响。`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await fetchWorkflowTemplateDelete(template.GUID);
        message.success('删除成功');
        loadTemplates();
      } catch (e: any) {
        message.error(e?.message || '删除失败');
      }
    }
  });
}

// 节点类型展示
const nodeTypeTextMap: Record<string, string> = {
  START: '开始',
  APPROVAL: '审批',
  CC: '抄送',
  END: '结束'
};

const approverTypeTextMap: Record<string, string> = {
  ROLE: '角色',
  DEPT: '部门',
  SUPERIOR: '上级',
  ASSIGN: '指定人',
  SPONSOR: '发起人'
};

function getNodeTypeText(type: string): string {
  return nodeTypeTextMap[type] || type || '-';
}

function getApproverTypeText(type?: string): string {
  if (!type) return '-';
  return approverTypeTextMap[type] || type;
}

function getConfigPreview(cfg: any): string {
  if (!cfg) return '-';
  if (typeof cfg === 'string') {
    return cfg.length > 30 ? cfg.slice(0, 30) + '...' : cfg;
  }
  try {
    const s = JSON.stringify(cfg);
    return s.length > 30 ? s.slice(0, 30) + '...' : s;
  } catch {
    return String(cfg);
  }
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    title="选择节点模板"
    style="width: 720px"
    :bordered="false"
    size="huge"
    @update:show="(v: boolean) => emit('update:visible', v)"
  >
    <NSpace vertical :size="12">
      <div class="toolbar">
        <NInput
          v-model:value="keyword"
          placeholder="按模板编码或名称搜索"
          clearable
          style="width: 280px"
          @keyup.enter="loadTemplates"
          @clear="loadTemplates"
        />
        <NButton type="primary" @click="loadTemplates">搜索</NButton>
        <NButton quaternary @click="loadTemplates">刷新</NButton>
      </div>

      <div v-loading="loading" class="template-list-wrap">
        <div v-if="templateList.length === 0 && !loading" class="empty-tip">
          <NEmpty description="暂无模板,可在节点编辑表单中点击「保存为模板」创建" size="small" />
        </div>

        <div
          v-for="tpl in templateList"
          :key="tpl.GUID"
          class="template-card"
        >
          <div class="card-header">
            <div class="card-title">
              <NTag size="small" :type="tpl.节点类型 === 'START' ? 'success' : tpl.节点类型 === 'END' ? 'error' : tpl.节点类型 === 'CC' ? 'warning' : 'info'">
                {{ getNodeTypeText(tpl.节点类型) }}
              </NTag>
              <span class="tpl-name">{{ tpl.模板名称 }}</span>
              <span class="tpl-code">({{ tpl.模板编码 }})</span>
            </div>
            <div class="card-actions">
              <NButton size="tiny" type="primary" @click="handleSelect(tpl)">选用</NButton>
              <NButton size="tiny" quaternary type="error" @click="handleDelete(tpl)">删除</NButton>
            </div>
          </div>
          <div class="card-body">
            <span class="info-item">审批人类型:<b>{{ getApproverTypeText(tpl.审批人类型) }}</b></span>
            <span class="info-item">审批模式:{{ tpl.会签或签 || '-' }}</span>
            <span class="info-item">超时:{{ tpl.超时天数 || 0 }}天({{ tpl.超时处理 || 'NOTIFY' }})</span>
          </div>
          <div class="card-config" v-if="tpl.审批人类型">
            <span class="config-label">审批人配置:</span>
            <code class="config-value">{{ getConfigPreview(tpl.审批人配置) }}</code>
          </div>
          <div class="card-meta" v-if="tpl.适用业务类型 || tpl.模板说明">
            <span v-if="tpl.适用业务类型" class="meta-item">适用:{{ tpl.适用业务类型 }}</span>
            <span v-if="tpl.模板说明" class="meta-item">{{ tpl.模板说明 }}</span>
          </div>
        </div>
      </div>
    </NSpace>
  </NModal>
</template>

<style scoped lang="scss">
.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
}

.template-list-wrap {
  max-height: 480px;
  overflow-y: auto;
  padding-right: 4px;

  .empty-tip {
    padding: 32px 0;
  }
}

.template-card {
  padding: 12px;
  background: #fafafa;
  border-radius: 6px;
  border-left: 3px solid #1890ff;
  margin-bottom: 8px;

  &:hover {
    background: #f0f7ff;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;

    .card-title {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .tpl-name {
      font-size: 14px;
      font-weight: 600;
      color: #333;
    }

    .tpl-code {
      font-size: 12px;
      color: #999;
      font-family: 'Consolas', 'Monaco', monospace;
    }

    .card-actions {
      display: flex;
      gap: 4px;
    }
  }

  .card-body {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: 12px;
    color: #666;

    .info-item b {
      color: #1890ff;
      font-weight: 500;
      margin-left: 2px;
    }
  }

  .card-config {
    margin-top: 6px;
    padding: 6px 8px;
    background: #fff;
    border-radius: 3px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 6px;

    .config-label {
      color: #999;
    }

    .config-value {
      color: #d48806;
      font-family: 'Consolas', 'Monaco', monospace;
      word-break: break-all;
    }
  }

  .card-meta {
    margin-top: 6px;
    display: flex;
    gap: 12px;
    font-size: 11px;
    color: #999;

    .meta-item {
      display: inline-flex;
      align-items: center;
    }
  }
}

// 暗黑模式
.system-dark .template-card {
  background: rgba(255, 255, 255, 0.05);

  &:hover {
    background: rgba(24, 144, 255, 0.1);
  }

  .card-header .tpl-name {
    color: #e0e0e0;
  }

  .card-body {
    color: #b0b0b0;
  }

  .card-config {
    background: rgba(0, 0, 0, 0.2);

    .config-label {
      color: #888;
    }

    .config-value {
      color: #faad14;
    }
  }

  .card-meta {
    color: #777;
  }
}
</style>
