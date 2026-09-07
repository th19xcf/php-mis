<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  NBadge,
  NButton,
  NEmpty,
  NIcon,
  NPopover,
  NSpin,
  NTag,
  NButton as NBtn
} from 'naive-ui';
import { fetchMessages, fetchMessagesCount, fetchMessagesRead, type MessageItem } from '@/service/api/oa-todo';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';

defineOptions({ name: 'MessageBell' });

const router = useRouter();
const message = useMessageWithConsole();

const showPopover = ref(false);
const unread = ref(0);
const list = ref<MessageItem[]>([]);
const loading = ref(false);

/** 消息类型 → tag 颜色 */
const typeColor: Record<string, 'error' | 'warning' | 'success' | 'info' | 'default'> = {
  催办: 'error',
  转办: 'warning',
  新待办: 'success',
  已完成: 'success',
  已取消: 'default',
  评论: 'info',
  到期提醒: 'warning',
  逾期提醒: 'error'
};

async function loadCount() {
  try {
    const res = await fetchMessagesCount();
    unread.value = res.data?.count ?? 0;
  } catch {
    // 静默失败（未登录等场景）
  }
}

async function loadList() {
  loading.value = true;
  try {
    const res = await fetchMessages({ page: 1, pageSize: 10 });
    list.value = res.data?.list ?? [];
    unread.value = res.data?.unread ?? 0;
  } catch (e: any) {
    message.error(e?.message || '消息加载失败');
  } finally {
    loading.value = false;
  }
}

function handleShow(show: boolean) {
  showPopover.value = show;
  if (show) {
    loadList();
  }
}

/** 点击消息：标记已读并跳转待办中心 */
async function handleClick(item: MessageItem) {
  if (item.isRead === '0') {
    try {
      await fetchMessagesRead({ ids: [item.GUID] });
      item.isRead = '1';
      unread.value = Math.max(0, unread.value - 1);
    } catch {
      // 忽略已读失败
    }
  }
  showPopover.value = false;
  router.push('/oa-todo-center');
}

/** 全部标记已读 */
async function markAll() {
  try {
    await fetchMessagesRead({ all: true });
    list.value = list.value.map((m) => ({ ...m, isRead: '1' }));
    unread.value = 0;
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

let timer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
  loadCount();
  timer = setInterval(loadCount, 60_000);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
});
</script>

<template>
  <NPopover
    trigger="click"
    placement="bottom"
    :width="360"
    :show="showPopover"
    :show-arrow="false"
    @update:show="handleShow"
  >
    <template #trigger>
      <NBadge :value="unread" :max="99" :offset="[-4, 4]">
        <NButton quaternary circle>
          <template #icon>
            <span class="bell-icon">🔔</span>
          </template>
        </NButton>
      </NBadge>
    </template>

    <div class="msg-panel">
      <div class="msg-header">
        <span class="msg-title">站内消息</span>
        <NBtn v-if="unread > 0" text size="small" type="primary" @click="markAll">全部已读</NBtn>
      </div>
      <NSpin :show="loading">
        <div class="msg-list">
          <NEmpty v-if="list.length === 0" description="暂无消息" size="small" />
          <div
            v-for="item in list"
            :key="item.GUID"
            class="msg-item"
            :class="{ unread: item.isRead === '0' }"
            @click="handleClick(item)"
          >
            <div class="msg-item-top">
              <NTag size="small" :type="typeColor[item.type] ?? 'default'" :bordered="false">{{ item.type }}</NTag>
              <span class="msg-time">{{ item.createdAt }}</span>
            </div>
            <div class="msg-content">{{ item.title }}</div>
            <div v-if="item.content" class="msg-sub">{{ item.content }}</div>
          </div>
        </div>
      </NSpin>
    </div>
  </NPopover>
</template>

<style scoped>
.msg-panel {
  display: flex;
  flex-direction: column;
  max-height: 420px;
  margin: -8px -12px;
}

.msg-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px 6px;
  flex-shrink: 0;
}

.msg-title {
  font-weight: 600;
  font-size: 14px;
  color: rgb(var(--base-text-color));
}

.msg-list {
  overflow-y: auto;
  min-height: 80px;
  max-height: 370px;
  padding: 0 4px 6px;
}

.msg-item {
  padding: 8px;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s;
}

.msg-item:hover {
  background: rgb(var(--primary-color) / 0.08);
}

.msg-item.unread .msg-content {
  font-weight: 600;
}

.msg-item-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}

.msg-time {
  font-size: 12px;
  color: rgb(var(--base-text-color) / 0.45);
}

.msg-content {
  font-size: 13px;
  color: rgb(var(--base-text-color));
  line-height: 1.4;
  word-break: break-all;
}

.msg-sub {
  font-size: 12px;
  color: rgb(var(--base-text-color) / 0.55);
  margin-top: 2px;
}

.bell-icon {
  font-size: 16px;
  line-height: 1;
}
</style>
