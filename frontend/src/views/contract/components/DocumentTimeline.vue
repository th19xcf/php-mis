<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { fetchDocumentTimeline } from '@/service/api/onlyoffice';

const props = defineProps<{
  contractNo: string;
}>();

const timeline = ref<any[]>([]);
const loading = ref(false);

async function loadTimeline() {
  if (!props.contractNo) return;

  loading.value = true;
  try {
    const result: any = await fetchDocumentTimeline(props.contractNo);
    const data = result?.data ?? result;
    if (Array.isArray(data)) {
      timeline.value = data;
    } else {
      timeline.value = [];
    }
  } catch {
    timeline.value = [];
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.contractNo,
  () => loadTimeline()
);

onMounted(() => loadTimeline());

function dotClass(eventType: string, status: string): string {
  if (status === 'FAILED') return 'error';
  switch (eventType) {
    case 'UPLOAD':
      return 'primary';
    case 'SAVE':
    case 'FORCE_SAVE':
    case 'FORCE_SAVE_RESULT':
      return 'success';
    case 'EDITING':
      return 'info';
    case 'SAVE_ERROR':
    case 'FORCE_SAVE_ERROR':
    case 'NOT_FOUND':
      return 'error';
    default:
      return 'default';
  }
}

function docTypeLabel(docType: string): string {
  switch (docType) {
    case 'MAIN':
      return '合同文件';
    case 'APPROVAL_FORM':
      return '审批表';
    default:
      return docType || '文档';
  }
}
</script>

<template>
  <div class="doc-timeline">
    <div v-if="loading" class="state">加载中...</div>
    <div v-else-if="timeline.length === 0" class="state">暂无修改记录</div>
    <div v-else class="timeline">
      <div
        v-for="(item, index) in timeline"
        :key="index"
        class="timeline-item"
        :class="{ last: index === timeline.length - 1 }"
      >
        <div class="timeline-dot">
          <span class="dot" :class="dotClass(item.事件类型, item.处理状态)"></span>
        </div>
        <div class="timeline-content">
          <div class="timeline-header">
            <span class="operator">{{ item.操作人 || '系统' }}</span>
            <span class="action-tag" :class="dotClass(item.事件类型, item.处理状态)">{{ item.事件名称 }}</span>
          </div>
          <div class="timeline-meta">
            <span class="doc-name" :title="item.文档名称">{{ item.文档名称 || '-' }}</span>
            <span class="doc-type">{{ docTypeLabel(item.文档类型) }}</span>
            <span v-if="item.版本号 > 0" class="version">v{{ item.版本号 }}</span>
          </div>
          <div class="timeline-time">{{ item.操作时间 }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.doc-timeline {
  .state {
    text-align: center;
    padding: 20px;
    color: rgba(0, 0, 0, 0.45);
    font-size: 14px;
  }

  .timeline {
    position: relative;
    padding-left: 8px;
  }

  .timeline-item {
    position: relative;
    padding-left: 20px;
    padding-bottom: 20px;
  }

  .timeline-item.last {
    padding-bottom: 0;
  }

  .timeline-item.last .timeline-dot::before {
    display: none;
  }

  .timeline-dot {
    position: absolute;
    left: 0;
    top: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .timeline-dot::before {
    content: '';
    position: absolute;
    left: 50%;
    top: 16px;
    width: 2px;
    height: calc(100% + 4px);
    background: #e8e8e8;
    transform: translateX(-50%);
  }

  .dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    position: relative;
    z-index: 1;
    display: inline-block;
  }

  .dot.primary { background: #1890ff; }
  .dot.success { background: #52c41a; }
  .dot.info { background: #909399; }
  .dot.error { background: #ff4d4f; }
  .dot.default { background: #1890ff; }

  .timeline-content {
    .timeline-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
    }

    .operator {
      font-size: 14px;
      font-weight: 500;
      color: rgba(0, 0, 0, 0.85);
    }

    .action-tag {
      font-size: 12px;
      padding: 2px 8px;
      border-radius: 4px;
      color: #1890ff;
      background: #e6f7ff;
    }

    .action-tag.success {
      color: #389e0c;
      background: #f6ffed;
    }

    .action-tag.info {
      color: #909399;
      background: #f4f4f5;
    }

    .action-tag.error {
      color: #cf1322;
      background: #fff2f0;
    }

    .action-tag.primary {
      color: #1890ff;
      background: #e6f7ff;
    }

    .timeline-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 4px;
      font-size: 13px;
      color: rgba(0, 0, 0, 0.65);
    }

    .doc-name {
      max-width: 200px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .doc-type {
      padding: 1px 6px;
      border-radius: 3px;
      background: #f5f5f5;
      font-size: 12px;
      color: rgba(0, 0, 0, 0.45);
    }

    .version {
      padding: 1px 6px;
      border-radius: 3px;
      background: #f0f5ff;
      font-size: 12px;
      color: #2f54eb;
    }

    .timeline-time {
      font-size: 12px;
      color: rgba(0, 0, 0, 0.45);
    }
  }
}

/* dark 模式适配 */
html.dark .doc-timeline {
  .state {
    color: rgba(255, 255, 255, 0.45);
  }

  .timeline-dot::before {
    background: rgba(255, 255, 255, 0.12);
  }

  .timeline-content {
    .operator {
      color: rgba(255, 255, 255, 0.85);
    }

    .action-tag {
      color: #69c0ff;
      background: rgba(24, 144, 255, 0.15);
    }

    .action-tag.success {
      color: #95de64;
      background: rgba(82, 196, 26, 0.15);
    }

    .action-tag.info {
      color: rgba(255, 255, 255, 0.55);
      background: rgba(255, 255, 255, 0.08);
    }

    .action-tag.error {
      color: #ff7875;
      background: rgba(255, 77, 79, 0.15);
    }

    .action-tag.primary {
      color: #69c0ff;
      background: rgba(24, 144, 255, 0.15);
    }

    .timeline-meta {
      color: rgba(255, 255, 255, 0.65);
    }

    .doc-type {
      background: rgba(255, 255, 255, 0.08);
      color: rgba(255, 255, 255, 0.45);
    }

    .version {
      background: rgba(24, 144, 255, 0.12);
      color: #85a5ff;
    }

    .timeline-time {
      color: rgba(255, 255, 255, 0.45);
    }
  }
}
</style>
