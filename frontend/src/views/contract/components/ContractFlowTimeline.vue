<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { useContractStore } from '@/store/modules/contract';

const props = defineProps<{
  contractNo: string;
}>();

const contractStore = useContractStore();

const flowDetail = ref<Api.Workflow.WorkflowInstance | null>(null);
const loading = ref(false);

async function loadFlowDetail() {
  if (!props.contractNo) return;

  loading.value = true;
  try {
    const result = await contractStore.loadContractDetail(props.contractNo);
    if (result) {
      const myInstances = contractStore.myContracts;
      const matchedInstance = myInstances.find(
        (inst: any) => inst.业务ID === props.contractNo
      );
      if (matchedInstance && matchedInstance.GUID) {
        flowDetail.value = await contractStore.loadFlowDetail(matchedInstance.GUID);
      }
    }
  } catch {
    // Error loading flow detail
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.contractNo,
  () => {
    loadFlowDetail();
  }
);

onMounted(() => {
  loadFlowDetail();
});
</script>

<template>
  <div class="flow-timeline">
    <div v-if="loading" class="loading">加载中...</div>
    <div v-else-if="!flowDetail || !flowDetail.timeline || flowDetail.timeline.length === 0" class="empty">
      暂无流程记录
    </div>
    <div v-else class="timeline">
      <div
        v-for="(item, index) in flowDetail.timeline"
        :key="index"
        class="timeline-item"
        :class="{ last: index === flowDetail.timeline!.length - 1 }"
      >
        <div class="timeline-dot">
          <span
            v-if="item.action === '同意'"
            class="dot success"
          ></span>
          <span
            v-else-if="item.action === '拒绝'"
            class="dot error"
          ></span>
          <span
            v-else-if="item.action === '撤回'"
            class="dot warning"
          ></span>
          <span v-else class="dot default"></span>
        </div>
        <div class="timeline-content">
          <div class="timeline-header">
            <span class="operator">{{ item.operatorName || item.operator }}</span>
            <span class="action">{{ item.action }}</span>
          </div>
          <div class="timeline-time">{{ item.time }}</div>
          <div v-if="item.remark" class="timeline-remark">{{ item.remark }}</div>
        </div>
      </div>
    </div>
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
</style>
