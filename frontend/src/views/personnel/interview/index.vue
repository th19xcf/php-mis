<script setup lang="ts">
import { ref, onMounted, h, computed } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { fetchAddInterview, fetchUpdateInterview, fetchDeleteInterview, fetchTransferInterview } from '@/service/api';
import { useInterviewStore } from '@/store/modules/interview';

const dialog = useDialog();
const message = useMessage();
const interviewStore = useInterviewStore();

const treeData = computed(() => interviewStore.treeData);
const selectedGuids = computed(() => interviewStore.selectedGuids);
const interviewDetail = computed(() => interviewStore.interviewDetail);
const options = computed(() => interviewStore.options);

const leftWidth = ref(320);
const minLeftWidth = 200;
const maxLeftWidth = 600;
const isResizing = ref(false);

const showAddModal = ref(false);
const showEditModal = ref(false);
const showTransferModal = ref(false);
const submitting = ref(false);

const addForm = ref({
  姓名: '',
  身份证号: '',
  手机号码: '',
  属地: '',
  招聘渠道: '',
  渠道类型: '',
  渠道名称: '',
  信息来源: '',
  实习结束日期: '',
  面试业务: '',
  面试岗位: '',
  面试日期: new Date().toISOString().split('T')[0],
  面试结果: '',
  面试人: '',
  预约培训日期: '',
  住宿: '',
  备注说明: ''
});

const editForm = ref({
  guid: '',
  姓名: '',
  手机号码: '',
  属地: '',
  招聘渠道: '',
  面试日期: '',
  面试结果: '',
  面试人: '',
  预约培训日期: '',
  住宿: '',
  备注说明: ''
});

const transferForm = ref({
  参培信息: '',
  培训业务: '',
  培训批次: '',
  培训老师: '',
  培训开始日期: '',
  预计完成日期: ''
});

function startResize(e: MouseEvent) {
  isResizing.value = true;
  document.body.style.cursor = 'col-resize';
  document.body.style.userSelect = 'none';

  const startX = e.clientX;
  const startWidth = leftWidth.value;

  function onMouseMove(moveEvent: MouseEvent) {
    if (!isResizing.value) return;
    const delta = moveEvent.clientX - startX;
    const newWidth = Math.max(minLeftWidth, Math.min(maxLeftWidth, startWidth + delta));
    leftWidth.value = newWidth;
  }

  function onMouseUp() {
    isResizing.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

async function loadTree() {
  await interviewStore.refreshTree();
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;

  const guids: string[] = [];

  function collectPeople(nodes: (TreeOption | null)[]) {
    for (const node of nodes) {
      if (!node) continue;
      const data = node.data as Api.Interview.InterviewTreeNode;
      if (data.type === 'person' && data.guid) {
        guids.push(data.guid);
      }
      if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  for (const key of keys) {
    const node = optionNodes.find(n => n?.key === key);
    if (node) {
      const data = node.data as Api.Interview.InterviewTreeNode;
      if (data.type === 'person' && data.guid) {
        guids.push(data.guid);
      } else if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  interviewStore.setSelectedGuids(guids);

  if (guids.length === 1) {
    interviewStore.loadInterviewDetail(guids[0]);
  } else {
    interviewStore.interviewDetail = null;
  }
}

function openAddModal() {
  addForm.value = {
    姓名: '',
    身份证号: '',
    手机号码: '',
    属地: '',
    招聘渠道: '',
    渠道类型: '',
    渠道名称: '',
    信息来源: '',
    实习结束日期: '',
    面试业务: '',
    面试岗位: '',
    面试日期: new Date().toISOString().split('T')[0],
    面试结果: '',
    面试人: '',
    预约培训日期: '',
    住宿: '',
    备注说明: ''
  };
  showAddModal.value = true;
}

function openEditModal() {
  if (!interviewDetail.value) {
    message.warning('请先选择要编辑的人员');
    return;
  }

  editForm.value = {
    guid: interviewDetail.value.GUID,
    姓名: interviewDetail.value.姓名,
    手机号码: interviewDetail.value.手机号码,
    属地: interviewDetail.value.属地,
    招聘渠道: interviewDetail.value.招聘渠道,
    面试日期: interviewDetail.value.面试日期,
    面试结果: interviewDetail.value.面试结果,
    面试人: interviewDetail.value.面试人,
    预约培训日期: interviewDetail.value.预约培训日期,
    住宿: interviewDetail.value.住宿,
    备注说明: interviewDetail.value.备注说明
  };
  showEditModal.value = true;
}

function openTransferModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择要转入培训的人员');
    return;
  }

  transferForm.value = {
    参培信息: '',
    培训业务: '',
    培训批次: '',
    培训老师: '',
    培训开始日期: new Date().toISOString().split('T')[0],
    预计完成日期: ''
  };
  showTransferModal.value = true;
}

async function handleAdd() {
  if (!addForm.value.姓名.trim()) {
    message.error('姓名不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddInterview(addForm.value);
  submitting.value = false;

  if (!error) {
    message.success('新增面试信息成功');
    showAddModal.value = false;
    await loadTree();
  }
}

async function handleEdit() {
  if (!editForm.value.姓名.trim()) {
    message.error('姓名不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchUpdateInterview(editForm.value);
  submitting.value = false;

  if (!error) {
    message.success('修改面试信息成功');
    showEditModal.value = false;
    await loadTree();
    if (selectedGuids.value.length === 1) {
      await interviewStore.loadInterviewDetail(selectedGuids.value[0]);
    }
  }
}

async function handleTransfer() {
  if (!transferForm.value.参培信息) {
    message.error('请选择参培信息');
    return;
  }

  submitting.value = true;
  const { error } = await fetchTransferInterview({
    guids: selectedGuids.value,
    ...transferForm.value
  });
  submitting.value = false;

  if (!error) {
    message.success('转入培训成功');
    showTransferModal.value = false;
    interviewStore.clearSelection();
    await loadTree();
  }
}

function handleDelete() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择要删除的人员');
    return;
  }

  dialog.warning({
    title: '确认删除',
    content: `确定要删除选中的 ${selectedGuids.value.length} 条记录吗？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const { error } = await fetchDeleteInterview(selectedGuids.value);
      if (!error) {
        message.success('删除成功');
        interviewStore.clearSelection();
        await loadTree();
      }
    }
  });
}

function renderPrefix({ option }: { option: TreeOption }) {
  const data = option.data as Api.Interview.InterviewTreeNode;
  const icons: Record<string, string> = {
    root: '👥',
    region: '🏢',
    result: '📋',
    train: '📚',
    date: '📆',
    channel: '📢',
    person: '👤'
  };
  return h('span', { class: 'mr-1' }, icons[data.type] || '📄');
}

onMounted(() => {
  interviewStore.loadTreeData();
  interviewStore.loadOptions();
});
</script>

<template>
  <div class="interview-container">
    <div class="interview-panel interview-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">面试人员</span>
        <NButton size="small" @click="loadTree">
          <template #icon>
            <icon-mdi-refresh />
          </template>
          刷新
        </NButton>
      </div>
      <div class="panel-content">
        <NTree
          :data="treeData"
          :render-prefix="renderPrefix"
          selectable
          block-line
          block-node
          multiple
          :expanded-keys="interviewStore.expandedKeys"
          default-expand-all
          @update:selected-keys="handleSelect"
          @update:expanded-keys="interviewStore.setExpandedKeys"
        />
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="interview-panel interview-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">人员信息</span>
        <NSpace>
          <NButton type="primary" size="small" @click="openAddModal">
            <template #icon>
              <icon-mdi-plus />
            </template>
            新增
          </NButton>
          <NButton type="info" size="small" @click="openEditModal">
            <template #icon>
              <icon-mdi-pencil />
            </template>
            修改
          </NButton>
          <NButton type="warning" size="small" @click="openTransferModal">
            <template #icon>
              <icon-mdi-arrow-right />
            </template>
            转培训
          </NButton>
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon>
              <icon-mdi-delete />
            </template>
            删除
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <div v-if="interviewDetail" class="space-y-4">
          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="姓名">
              {{ interviewDetail.姓名 }}
            </NDescriptionsItem>
            <NDescriptionsItem label="手机号码">
              {{ interviewDetail.手机号码 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="身份证号">
              {{ interviewDetail.身份证号 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="属地">
              {{ interviewDetail.属地 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="招聘渠道">
              {{ interviewDetail.招聘渠道 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="面试日期">
              {{ interviewDetail.面试日期 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="面试人">
              {{ interviewDetail.面试人 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="面试结果">
              <NTag :type="interviewDetail.面试结果 === '通过' ? 'success' : 'default'" size="small">
                {{ interviewDetail.面试结果 || '-' }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="参培信息">
              {{ interviewDetail.参培信息 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="预约培训日期">
              {{ interviewDetail.预约培训日期 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="面试业务">
              {{ interviewDetail.面试业务 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="面试岗位">
              {{ interviewDetail.面试岗位 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="住宿">
              {{ interviewDetail.住宿 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="备注说明" :span="2">
              {{ interviewDetail.备注说明 || '-' }}
            </NDescriptionsItem>
          </NDescriptions>
        </div>

        <NEmpty v-else description="请选择左侧人员查看详情" class="py-20" />
      </div>
    </div>

    <NModal v-model:show="showAddModal" title="新增面试信息" preset="card" class="w-150" :mask-closable="false">
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="姓名" required>
          <NInput v-model:value="addForm.姓名" placeholder="请输入姓名" />
        </NFormItem>
        <NFormItem label="手机号码">
          <NInput v-model:value="addForm.手机号码" placeholder="请输入手机号码" />
        </NFormItem>
        <NFormItem label="属地">
          <NSelect v-model:value="addForm.属地" :options="options?.region || []" placeholder="请选择属地" clearable />
        </NFormItem>
        <NFormItem label="招聘渠道">
          <NSelect
            v-model:value="addForm.招聘渠道"
            :options="options?.channel || []"
            placeholder="请选择招聘渠道"
            clearable
          />
        </NFormItem>
        <NFormItem label="面试结果">
          <NSelect
            v-model:value="addForm.面试结果"
            :options="options?.interviewResult || []"
            placeholder="请选择面试结果"
            clearable
          />
        </NFormItem>
        <NFormItem label="面试日期">
          <NDatePicker
            v-model:formatted-value="addForm.面试日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="预约培训日期">
          <NDatePicker
            v-model:formatted-value="addForm.预约培训日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showAddModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleAdd">确认</NButton>
        </NSpace>
      </template>
    </NModal>

    <NModal v-model:show="showEditModal" title="修改面试信息" preset="card" class="w-150" :mask-closable="false">
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="姓名" required>
          <NInput v-model:value="editForm.姓名" placeholder="请输入姓名" />
        </NFormItem>
        <NFormItem label="手机号码">
          <NInput v-model:value="editForm.手机号码" placeholder="请输入手机号码" />
        </NFormItem>
        <NFormItem label="属地">
          <NSelect v-model:value="editForm.属地" :options="options?.region || []" placeholder="请选择属地" clearable />
        </NFormItem>
        <NFormItem label="招聘渠道">
          <NSelect
            v-model:value="editForm.招聘渠道"
            :options="options?.channel || []"
            placeholder="请选择招聘渠道"
            clearable
          />
        </NFormItem>
        <NFormItem label="面试结果">
          <NSelect
            v-model:value="editForm.面试结果"
            :options="options?.interviewResult || []"
            placeholder="请选择面试结果"
            clearable
          />
        </NFormItem>
        <NFormItem label="预约培训日期">
          <NDatePicker
            v-model:formatted-value="editForm.预约培训日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showEditModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleEdit">确认</NButton>
        </NSpace>
      </template>
    </NModal>

    <NModal v-model:show="showTransferModal" title="转入培训" preset="card" class="w-120" :mask-closable="false">
      <NAlert type="info" class="mb-4">已选择 {{ selectedGuids.length }} 人</NAlert>
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="参培信息" required>
          <NSelect
            v-model:value="transferForm.参培信息"
            :options="options?.trainStatus || []"
            placeholder="请选择参培信息"
          />
        </NFormItem>
        <NFormItem label="培训业务">
          <NSelect
            v-model:value="transferForm.培训业务"
            :options="options?.trainBiz || []"
            placeholder="请选择培训业务"
            clearable
          />
        </NFormItem>
        <NFormItem label="培训批次">
          <NInput v-model:value="transferForm.培训批次" placeholder="请输入培训批次" />
        </NFormItem>
        <NFormItem label="培训老师">
          <NInput v-model:value="transferForm.培训老师" placeholder="请输入培训老师" />
        </NFormItem>
        <NFormItem label="培训开始日期">
          <NDatePicker
            v-model:formatted-value="transferForm.培训开始日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="预计完成日期">
          <NDatePicker
            v-model:formatted-value="transferForm.预计完成日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showTransferModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleTransfer">确认</NButton>
        </NSpace>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
:deep(.n-tree-node-content) {
  padding: 4px 0;
}

.interview-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.interview-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}

.interview-panel-left {
  flex-shrink: 0;
}

.interview-panel-right {
  flex: 1;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid #e8e8e8;
  flex-shrink: 0;
  background: #fafafa;
}

.panel-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  min-height: 0;
}

.resize-splitter {
  width: 8px;
  cursor: col-resize;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.2s;
  flex-shrink: 0;
}

.resize-splitter:hover {
  background-color: rgba(0, 0, 0, 0.04);
}

.resize-splitter.is-resizing {
  background-color: rgba(0, 0, 0, 0.08);
}

.resize-line {
  width: 2px;
  height: 24px;
  border-radius: 1px;
  background-color: #d9d9d9;
  transition: background-color 0.2s;
}

.resize-splitter:hover .resize-line,
.resize-splitter.is-resizing .resize-line {
  background-color: #1890ff;
}

html.dark .interview-panel {
  background: rgb(24, 24, 28);
  border-color: rgba(255, 255, 255, 0.09);
}

html.dark .panel-header {
  background: rgb(36, 36, 40);
  border-color: rgba(255, 255, 255, 0.09);
}

html.dark .panel-content {
  background: rgb(24, 24, 28);
}

html.dark .resize-splitter:hover {
  background-color: rgba(255, 255, 255, 0.06);
}

html.dark .resize-splitter.is-resizing {
  background-color: rgba(255, 255, 255, 0.1);
}

html.dark .resize-line {
  background-color: #555;
}

html.dark .resize-splitter:hover .resize-line,
html.dark .resize-splitter.is-resizing .resize-line {
  background-color: #40a9ff;
}
</style>
