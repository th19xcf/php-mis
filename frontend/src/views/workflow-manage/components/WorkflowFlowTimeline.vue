<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { fetchWorkflowInstanceDetail } from '@/service/api/workflow';

const props = defineProps<{
  instanceId: number;
}>();

const flowDetail = ref<Api.Workflow.WorkflowInstance | null>(null);
const loading = ref(false);

async function loadFlowDetail() {
  if (!props.instanceId) return;

  loading.value = true;
  try {
    const result = await fetchWorkflowInstanceDetail(props.instanceId);
    const data = (result as any)?.data || (result as any);
    if (data) {
      flowDetail.value = data as Api.Workflow.WorkflowInstance;
    }
  } catch {
    // Error loading flow detail
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.instanceId,
  () => {
    loadFlowDetail();
  }
);

onMounted(() => {
  loadFlowDetail();
});

const actionMap: Record<string, string> = {
  APPROVE: '同意',
  REJECT: '拒绝',
  WITHDRAW: '撤回',
  START: '发起',
  END: '结束',
  TRANSFER: '转签',
  COUNTERSIGN: '加签',
  TIMEOUT: '超时处理'
};

const instanceStatusMap: Record<string, { text: string; type: 'default' | 'success' | 'warning' | 'error' | 'info' }> = {
  RUNNING: { text: '运行中', type: 'warning' },
  COMPLETED: { text: '已完成', type: 'success' },
  TERMINATED: { text: '已终止', type: 'error' },
  SUSPENDED: { text: '已挂起', type: 'info' }
};

const taskStatusMap: Record<string, { text: string; class: string }> = {
  PENDING: { text: '待处理', class: 'status-pending' },
  DONE: { text: '已处理', class: 'status-done' },
  WITHDRAWN: { text: '已撤回', class: 'status-withdrawn' },
  REJECTED: { text: '已拒绝', class: 'status-rejected' },
  SKIPPED: { text: '已跳过', class: 'status-skipped' }
};
</script>

<template>
  <div class="flow-timeline">
    <div v-if="loading" class="loading">加载中...</div>

    <template v-else-if="flowDetail">
      <!-- 实例基本信息 -->
      <div class="instance-info">
        <div class="info-row">
          <span class="info-label">实例状态</span>
          <NTag
            size="small"
            :type="(instanceStatusMap[flowDetail.实例状态]?.type || 'default') as any"
          >
            {{ instanceStatusMap[flowDetail.实例状态]?.text || flowDetail.实例状态 }}
          </NTag>
        </div>
        <div class="info-row">
          <span class="info-label">业务标题</span>
          <span class="info-value">{{ flowDetail.业务标题 || '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">当前节点</span>
          <span class="info-value">{{ flowDetail.当前节点编码 || '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">发起人</span>
          <span class="info-value">{{ flowDetail.发起人姓名 || flowDetail.发起人 || '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">发起时间</span>
          <span class="info-value">{{ flowDetail.发起时间 || '-' }}</span>
        </div>
        <div class="info-row" v-if="flowDetail.结束时间">
          <span class="info-label">结束时间</span>
          <span class="info-value">{{ flowDetail.结束时间 }}</span>
        </div>
      </div>

      <!-- 节点任务列表 -->
      <div v-if="flowDetail.tasks && flowDetail.tasks.length" class="tasks-section">
        <div class="section-title">节点任务</div>
        <div class="tasks-list">
          <div
            v-for="task in flowDetail.tasks"
            :key="task.任务ID"
            class="task-item"
            :class="taskStatusMap[task.任务状态]?.class"
          >
            <div class="task-header">
              <span class="task-node">{{ task.节点名称 }}</span>
              <NTag size="tiny" :type="task.任务状态 === 'DONE' ? 'success' : task.任务状态 === 'REJECTED' ? 'error' : 'warning'">
                {{ taskStatusMap[task.任务状态]?.text || task.任务状态 }}
              </NTag>
            </div>
            <div class="task-info">
              <span>处理人:{{ task.处理人姓名 || task.处理人 }}</span>
              <span v-if="task.处理时间">处理时间:{{ task.处理时间 }}</span>
            </div>
            <div v-if="task.处理意见" class="task-opinion">{{ task.处理意见 }}</div>
          </div>
        </div>
      </div>

      <!-- 时间线 -->
      <div v-if="flowDetail.timeline && flowDetail.timeline.length" class="timeline-section">
        <div class="section-title">操作时间线</div>
        <div class="timeline">
          <div
            v-for="(item, index) in flowDetail.timeline"
            :key="index"
            class="timeline-item"
            :class="{ last: index === flowDetail.timeline!.length - 1 }"
          >
            <div class="timeline-dot">
              <span
                v-if="item.action === 'APPROVE'"
                class="dot success"
              ></span>
              <span
                v-else-if="item.action === 'REJECT'"
                class="dot error"
              ></span>
              <span
                v-else-if="item.action === 'WITHDRAW'"
                class="dot warning"
              ></span>
              <span v-else class="dot default"></span>
            </div>
            <div class="timeline-content">
              <div class="timeline-header">
                <span class="operator">{{ item.operatorName || item.operator }}</span>
                <span class="action">{{ actionMap[item.action] || item.action }}</span>
              </div>
              <div class="timeline-time">{{ item.time }}</div>
              <div v-if="item.remark" class="timeline-remark">{{ item.remark }}</div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!flowDetail.tasks?.length && !flowDetail.timeline?.length" class="empty">
        暂无节点与操作记录
      </div>
    </template>

    <div v-else class="empty">暂无流程记录</div>
  </div>
</template>

<style scoped lang="scss">
.flow-timeline {
  .loading,
  .empty {
    text-align: center;
    padding: 20px;
    color: #999;
    font-size: 14px;
  }
}

.instance-info {
  background: #fafafa;
  border-radius: 4px;
  padding: 10px 12px;
  margin-bottom: 16px;

  .info-row {
    display: flex;
    align-items: center;
    padding: 4px 0;
    font-size: 13px;

    .info-label {
      width: 80px;
      color: #666;
      flex-shrink: 0;
    }

    .info-value {
      color: #333;
      flex: 1;
    }
  }
}

.section-title {
  font-size: 14px;
  font-weight: 500;
  color: #333;
  margin-bottom: 12px;
  padding-left: 8px;
  border-left: 3px solid #1890ff;
}

.tasks-section {
  margin-bottom: 20px;

  .tasks-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .task-item {
    padding: 10px 12px;
    background: #fafafa;
    border-radius: 4px;
    border-left: 3px solid #d9d9d9;

    &.status-done {
      border-left-color: #52c41a;
    }

    &.status-rejected {
      border-left-color: #ff4d4f;
    }

    &.status-pending {
      border-left-color: #faad14;
    }

    .task-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;

      .task-node {
        font-size: 13px;
        font-weight: 500;
        color: #333;
      }
    }

    .task-info {
      display: flex;
      gap: 16px;
      font-size: 12px;
      color: #666;
      margin-bottom: 4px;
    }

    .task-opinion {
      margin-top: 6px;
      padding: 6px 8px;
      background: #fff;
      border-radius: 3px;
      font-size: 12px;
      color: #666;
      line-height: 1.5;
    }
  }
}

.timeline-section {
  .timeline {
    position: relative;
    padding-left: 8px;
  }

  .timeline-item {
    position: relative;
    padding-left: 20px;
    padding-bottom: 20px;

    &.last {
      padding-bottom: 0;

      .timeline-dot::before {
        display: none;
      }
    }
  }

  .timeline-dot {
    position: absolute;
    left: 0;
    top: 4px;
    display: flex;
    align-items: center;
    justify-content: center;

    &::before {
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

      &.success {
        background: #52c41a;
      }

      &.error {
        background: #ff4d4f;
      }

      &.warning {
        background: #faad14;
      }

      &.default {
        background: #1890ff;
      }
    }
  }

  .timeline-content {
    .timeline-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;

      .operator {
        font-size: 14px;
        font-weight: 500;
        color: #333;
      }

      .action {
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 4px;
        background: #e6f7ff;
        color: #1890ff;
      }
    }

    .timeline-time {
      font-size: 12px;
      color: #999;
      margin-bottom: 6px;
    }

    .timeline-remark {
      font-size: 13px;
      color: #666;
      line-height: 1.5;
      padding: 8px 12px;
      background: #fafafa;
      border-radius: 4px;
    }
  }
}

// 暗黑模式适配
.system-dark {
  .instance-info {
    background: rgba(255, 255, 255, 0.05);

    .info-row {
      .info-label {
        color: #b0b0b0;
      }

      .info-value {
        color: #e0e0e0;
      }
    }
  }

  .section-title {
    color: #e0e0e0;
    border-left-color: #1890ff;
  }

  .tasks-section .task-item {
    background: rgba(255, 255, 255, 0.05);

    .task-header .task-node {
      color: #e0e0e0;
    }

    .task-info {
      color: #b0b0b0;
    }

    .task-opinion {
      background: rgba(0, 0, 0, 0.2);
      color: #b0b0b0;
    }
  }

  .timeline-section .timeline-dot::before {
    background: rgba(255, 255, 255, 0.15);
  }

  .timeline-section .timeline-content {
    .timeline-header .operator {
      color: #e0e0e0;
    }

    .timeline-remark {
      background: rgba(255, 255, 255, 0.05);
      color: #b0b0b0;
    }
  }
}
</style>
