<script setup lang="ts">
import { ref, onMounted, h } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import {
  fetchUpdateTrain,
  fetchBatchUpdateTrain,
  fetchDeleteTrain,
  fetchTransferTrain,
  fetchTrainOptions,
  fetchTrainTree,
  fetchTrainDetail
} from '@/service/api';

const dialog = useDialog();
const message = useMessage();

const treeData = ref<TreeOption[]>([]);
const selectedGuids = ref<string[]>([]);
const trainDetail = ref<Api.Train.TrainDetail | null>(null);
const expandedKeys = ref<string[]>([]);
const options = ref<Api.Train.TrainOptions | null>(null);

const leftWidth = ref(320);
const isResizing = ref(false);

const showEditModal = ref(false);
const showBatchModal = ref(false);
const showTransferModal = ref(false);
const submitting = ref(false);

const editForm = ref({ guid: '', 培训业务: '', 培训批次: '', 培训老师: '', 培训开始日期: '', 预计完成日期: '' });
const batchForm = ref({ 培训业务: '', 培训批次: '', 培训老师: '', 培训开始日期: '', 预计完成日期: '', 培训天数: '' });
const transferForm = ref({ 培训状态: '', 岗位类型: '', 结算类型: '', 培训结束日期: '', 培训离开原因: '', 入职次数: 1 });

function startResize(e: MouseEvent) {
  isResizing.value = true;
  document.body.style.cursor = 'col-resize';
  document.body.style.userSelect = 'none';
  const startX = e.clientX;
  const startWidth = leftWidth.value;
  function onMouseMove(moveEvent: MouseEvent) {
    if (!isResizing.value) return;
    leftWidth.value = Math.max(200, Math.min(600, startWidth + moveEvent.clientX - startX));
  }
  function onMouseUp() {
    isResizing.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
    localStorage.setItem('train-splitter-width', String(leftWidth.value));
  }
  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

async function loadTree() {
  const { data } = await fetchTrainTree();
  if (data) treeData.value = convertToTreeOptions(data);
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  const guids: string[] = [];
  for (const key of keys) {
    const node = optionNodes.find(n => n?.key === key);
    if (node) collectGuids(node, guids);
  }
  selectedGuids.value = guids;
  if (guids.length === 1) loadDetail(guids[0]);
  else trainDetail.value = null;
}

function collectGuids(node: TreeOption | null, guids: string[]) {
  if (!node) return;
  const data = node.data as Api.Train.TrainTreeNode;
  if (data.type === 'person' && data.guid) guids.push(data.guid);
  if (node.children) node.children.forEach(c => collectGuids(c, guids));
}

async function loadDetail(guid: string) {
  const { data } = await fetchTrainDetail(guid);
  if (data) trainDetail.value = data;
}

function openEditModal() {
  if (!trainDetail.value) {
    message.warning('请先选择人员');
    return;
  }
  editForm.value = {
    guid: trainDetail.value.GUID,
    培训业务: trainDetail.value.培训业务,
    培训批次: trainDetail.value.培训批次,
    培训老师: trainDetail.value.培训老师,
    培训开始日期: trainDetail.value.培训开始日期,
    预计完成日期: trainDetail.value.预计完成日期
  };
  showEditModal.value = true;
}

function openBatchModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }
  batchForm.value = { 培训业务: '', 培训批次: '', 培训老师: '', 培训开始日期: '', 预计完成日期: '', 培训天数: '' };
  showBatchModal.value = true;
}

function openTransferModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }
  transferForm.value = { 培训状态: '', 岗位类型: '', 结算类型: '', 培训结束日期: '', 培训离开原因: '', 入职次数: 1 };
  showTransferModal.value = true;
}

async function handleEdit() {
  submitting.value = true;
  const { error } = await fetchUpdateTrain(editForm.value);
  submitting.value = false;
  if (!error) {
    message.success('修改成功');
    showEditModal.value = false;
    loadTree();
  }
}

async function handleBatch() {
  submitting.value = true;
  const { error } = await fetchBatchUpdateTrain({ guids: selectedGuids.value, ...batchForm.value });
  submitting.value = false;
  if (!error) {
    message.success('批量修改成功');
    showBatchModal.value = false;
    loadTree();
  }
}

async function handleTransfer() {
  if (!transferForm.value.培训状态) {
    message.error('请选择培训状态');
    return;
  }
  submitting.value = true;
  const { error } = await fetchTransferTrain({ guids: selectedGuids.value, ...transferForm.value });
  submitting.value = false;
  if (!error) {
    message.success('操作成功');
    showTransferModal.value = false;
    selectedGuids.value = [];
    loadTree();
  }
}

function handleDelete() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }
  dialog.warning({
    title: '确认删除',
    content: `确定删除 ${selectedGuids.value.length} 条记录？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const { error } = await fetchDeleteTrain(selectedGuids.value);
      if (!error) {
        message.success('删除成功');
        selectedGuids.value = [];
        loadTree();
      }
    }
  });
}

function renderPrefix({ option }: { option: TreeOption }) {
  const icons: Record<string, string> = {
    root: '👥',
    region: '🏢',
    status: '📋',
    teacher: '👨‍🏫',
    date: '📆',
    person: '👤'
  };
  return h('span', { class: 'mr-1' }, icons[(option.data as Api.Train.TrainTreeNode).type] || '📄');
}

function convertToTreeOptions(nodes: Api.Train.TrainTreeNode[]): TreeOption[] {
  return nodes.map(n => ({
    key: n.id,
    label: n.value,
    data: n,
    children: n.items?.length ? convertToTreeOptions(n.items) : undefined
  }));
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('train-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= 200 && width <= 600) {
      leftWidth.value = width;
    }
  }
  loadTree();
  const { data } = await fetchTrainOptions();
  if (data) options.value = data;
});
</script>

<template>
  <div class="train-container">
    <div class="train-panel train-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">培训人员</span>
        <NButton size="small" @click="loadTree">
          <template #icon><icon-mdi-refresh /></template>
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
          :expanded-keys="expandedKeys"
          default-expand-all
          @update:selected-keys="handleSelect"
          @update:expanded-keys="expandedKeys = $event"
        />
      </div>
    </div>
    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>
    <div class="train-panel train-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">人员信息</span>
        <NSpace>
          <NButton type="info" size="small" @click="openEditModal">
            <template #icon><icon-mdi-pencil /></template>
            修改(单选)
          </NButton>
          <NButton type="info" size="small" @click="openBatchModal">
            <template #icon><icon-mdi-pencil /></template>
            修改(多选)
          </NButton>
          <NButton type="warning" size="small" @click="openTransferModal">
            <template #icon><icon-mdi-arrow-right /></template>
            转在职
          </NButton>
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon><icon-mdi-delete /></template>
            删除
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <div v-if="trainDetail" class="space-y-4">
          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="姓名">{{ trainDetail.姓名 }}</NDescriptionsItem>
            <NDescriptionsItem label="手机号码">{{ trainDetail.手机号码 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="属地">{{ trainDetail.属地 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="培训状态">
              <NTag :type="trainDetail.培训状态 === '通过' ? 'success' : 'default'" size="small">
                {{ trainDetail.培训状态 || '-' }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="培训业务">{{ trainDetail.培训业务 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="培训批次">{{ trainDetail.培训批次 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="培训老师">{{ trainDetail.培训老师 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="培训开始日期">{{ trainDetail.培训开始日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="预计完成日期">{{ trainDetail.预计完成日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="培训完成日期">{{ trainDetail.培训完成日期 || '-' }}</NDescriptionsItem>
          </NDescriptions>
        </div>
        <NEmpty v-else description="请选择左侧人员查看详情" class="py-20" />
      </div>
    </div>

    <NModal v-model:show="showEditModal" title="修改培训信息" preset="card" class="w-120">
      <NForm label-placement="left" label-width="100">
        <NFormItem label="培训业务">
          <NSelect v-model:value="editForm.培训业务" :options="options?.trainBiz || []" clearable />
        </NFormItem>
        <NFormItem label="培训批次"><NInput v-model:value="editForm.培训批次" /></NFormItem>
        <NFormItem label="培训老师"><NInput v-model:value="editForm.培训老师" /></NFormItem>
        <NFormItem label="开始日期">
          <NDatePicker
            v-model:formatted-value="editForm.培训开始日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="预计完成">
          <NDatePicker
            v-model:formatted-value="editForm.预计完成日期"
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

    <NModal v-model:show="showBatchModal" title="批量修改培训信息" preset="card" class="w-120">
      <NAlert type="info" class="mb-4">已选择 {{ selectedGuids.length }} 人</NAlert>
      <NForm label-placement="left" label-width="100">
        <NFormItem label="培训业务">
          <NSelect v-model:value="batchForm.培训业务" :options="options?.trainBiz || []" clearable />
        </NFormItem>
        <NFormItem label="培训批次"><NInput v-model:value="batchForm.培训批次" /></NFormItem>
        <NFormItem label="培训老师"><NInput v-model:value="batchForm.培训老师" /></NFormItem>
        <NFormItem label="开始日期">
          <NDatePicker
            v-model:formatted-value="batchForm.培训开始日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="预计完成">
          <NDatePicker
            v-model:formatted-value="batchForm.预计完成日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showBatchModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleBatch">确认</NButton>
        </NSpace>
      </template>
    </NModal>

    <NModal v-model:show="showTransferModal" title="更新培训状态" preset="card" class="w-120">
      <NAlert type="info" class="mb-4">已选择 {{ selectedGuids.length }} 人</NAlert>
      <NForm label-placement="left" label-width="100">
        <NFormItem label="培训状态" required>
          <NSelect v-model:value="transferForm.培训状态" :options="options?.trainStatus || []" />
        </NFormItem>
        <NFormItem label="岗位类型">
          <NSelect v-model:value="transferForm.岗位类型" :options="options?.positionType || []" clearable />
        </NFormItem>
        <NFormItem label="结算类型">
          <NSelect v-model:value="transferForm.结算类型" :options="options?.settlementType || []" clearable />
        </NFormItem>
        <NFormItem label="结束日期">
          <NDatePicker
            v-model:formatted-value="transferForm.培训结束日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="入职次数">
          <NInputNumber v-model:value="transferForm.入职次数" :min="1" class="w-full" />
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
.train-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}
.train-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}
.train-panel-left {
  flex-shrink: 0;
}
.train-panel-right {
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
  flex-shrink: 0;
}
.resize-splitter:hover {
  background-color: rgba(0, 0, 0, 0.04);
}
.resize-line {
  width: 2px;
  height: 24px;
  border-radius: 1px;
  background-color: #d9d9d9;
}
.resize-splitter:hover .resize-line {
  background-color: #1890ff;
}
html.dark .train-panel {
  background: rgb(24, 24, 28);
  border-color: rgba(255, 255, 255, 0.09);
}
html.dark .panel-header {
  background: rgb(36, 36, 40);
}
html.dark .panel-content {
  background: rgb(24, 24, 28);
}
</style>
