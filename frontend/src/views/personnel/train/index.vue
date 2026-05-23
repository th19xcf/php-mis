<script setup lang="ts">
import { ref, computed, watch, onMounted, h } from 'vue';
import { useRoute } from 'vue-router';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { useTrainStore } from '@/store/modules/train';
import {
  fetchUpdateTrain,
  fetchBatchUpdateTrain,
  fetchDeleteTrain,
  fetchTransferTrain
} from '@/service/api';

const route = useRoute();
const dialog = useDialog();
const message = useMessage();
const trainStore = useTrainStore();

const functionCode = computed(() => route.params.code || '2035');

const treeData = computed(() => trainStore.treeData);
const selectedGuids = computed(() => trainStore.selectedGuids);
const trainDetail = computed(() => trainStore.trainDetail);
const options = computed(() => trainStore.options);

const leftWidth = ref(320);
const isResizing = ref(false);

const isEditingDetail = ref(false);
const isBatchMode = ref(false);
const isTransferMode = ref(false);
const submitting = ref(false);

const editDetailForm = ref<Record<string, any>>({});
const batchForm = ref({
  培训业务: '',
  培训批次: '',
  培训老师: '',
  培训开始日期: new Date().toISOString().split('T')[0],
  预计完成日期: undefined,
  培训天数: ''
});
const transferForm = ref({
  培训状态: '',
  岗位类型: '',
  结算类型: '',
  培训结束日期: undefined,
  培训离开原因: '',
  入职次数: 1
});

const detailFields = computed(() => {
  const fields = [
    { columnName: 'GUID', fieldName: 'GUID', editable: false },
    { columnName: '姓名', fieldName: '姓名', editable: false },
    { columnName: '身份证号', fieldName: '身份证号', editable: false },
    { columnName: '手机号码', fieldName: '手机号码', editable: false },
    { columnName: '属地', fieldName: '属地', editable: false },
    { columnName: '培训状态', fieldName: '培训状态', editable: false },
    { columnName: '培训业务', fieldName: '培训业务', editable: true },
    { columnName: '培训批次', fieldName: '培训批次', editable: true },
    { columnName: '培训老师', fieldName: '培训老师', editable: true },
    { columnName: '培训开始日期', fieldName: '培训开始日期', editable: true },
    { columnName: '预计完成日期', fieldName: '预计完成日期', editable: true },
    { columnName: '培训完成日期', fieldName: '培训完成日期', editable: false },
    { columnName: '培训天数', fieldName: '培训天数', editable: false },
    { columnName: '岗位类型', fieldName: '岗位类型', editable: false },
    { columnName: '结算类型', fieldName: '结算类型', editable: false },
    { columnName: '入职次数', fieldName: '入职次数', editable: false },
    { columnName: '培训离开原因', fieldName: '培训离开原因', editable: false },
    { columnName: '创建时间', fieldName: '创建时间', editable: false },
    { columnName: '操作时间', fieldName: '操作时间', editable: false }
  ];
  return fields;
});

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
  await trainStore.refreshTree();
}

function handleCheck(keys: string[], optionNodes: (TreeOption | null)[]) {
  const guids: string[] = [];

  function collectPeople(nodes: (TreeOption | null)[]) {
    for (const node of nodes) {
      if (!node) continue;
      const data = node.data as Api.Train.TrainTreeNode;
      if (data.type === 'person' && data.guid) {
        if (!guids.includes(data.guid)) {
          guids.push(data.guid);
        }
      }
      if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  for (const key of keys) {
    const node = optionNodes.find(n => n?.key === key);
    if (node) {
      const data = node.data as Api.Train.TrainTreeNode;
      if (data.type === 'person' && data.guid) {
        if (!guids.includes(data.guid)) {
          guids.push(data.guid);
        }
      } else if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  trainStore.setCheckedKeys(keys);
  trainStore.setSelectedGuids(guids);
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;

  const key = keys[0];
  const node = optionNodes.find(n => n?.key === key);
  if (node) {
    const data = node.data as Api.Train.TrainTreeNode;
    if (data.type === 'person' && data.guid) {
      trainStore.loadTrainDetail(data.guid);
    } else {
      trainStore.trainDetail = null;
    }
  }
}

function startEditDetail() {
  if (!trainDetail.value) {
    message.warning('请先选择人员');
    return;
  }

  editDetailForm.value = {};
  detailFields.value.forEach(field => {
    editDetailForm.value[field.fieldName] = trainDetail.value?.[field.fieldName] || '';
  });
  isEditingDetail.value = true;
}

function cancelEditDetail() {
  isEditingDetail.value = false;
}

async function handleEditDetail() {
  submitting.value = true;
  const { error } = await fetchUpdateTrain({
    guid: editDetailForm.value.GUID,
    培训业务: editDetailForm.value.培训业务,
    培训批次: editDetailForm.value.培训批次,
    培训老师: editDetailForm.value.培训老师,
    培训开始日期: editDetailForm.value.培训开始日期,
    预计完成日期: editDetailForm.value.预计完成日期
  });
  submitting.value = false;

  if (!error) {
    message.success('修改培训信息成功');
    isEditingDetail.value = false;
    await loadTree();
    if (editDetailForm.value.GUID) {
      await trainStore.loadTrainDetail(editDetailForm.value.GUID);
    }
  }
}

function openBatchModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }

  batchForm.value = {
    培训业务: '',
    培训批次: '',
    培训老师: '',
    培训开始日期: new Date().toISOString().split('T')[0],
    预计完成日期: undefined,
    培训天数: ''
  };
  isBatchMode.value = true;
}

function cancelBatchMode() {
  isBatchMode.value = false;
}

async function handleBatch() {
  submitting.value = true;
  const { error } = await fetchBatchUpdateTrain({
    guids: selectedGuids.value,
    ...batchForm.value
  });
  submitting.value = false;

  if (!error) {
    message.success('批量修改成功');
    isBatchMode.value = false;
    trainStore.clearSelection();
    await loadTree();
  }
}

function openTransferModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }

  transferForm.value = {
    培训状态: '',
    岗位类型: '',
    结算类型: '',
    培训结束日期: undefined,
    培训离开原因: '',
    入职次数: 1
  };
  isTransferMode.value = true;
}

function cancelTransferMode() {
  isTransferMode.value = false;
}

async function handleTransfer() {
  if (!transferForm.value.培训状态) {
    message.error('请选择培训状态');
    return;
  }

  submitting.value = true;
  const { error } = await fetchTransferTrain({
    guids: selectedGuids.value,
    ...transferForm.value
  });
  submitting.value = false;

  if (!error) {
    message.success('操作成功');
    isTransferMode.value = false;
    trainStore.clearSelection();
    await loadTree();
  }
}

function handleDelete() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }

  dialog.warning({
    title: '确认删除',
    content: `确定要删除选中的 ${selectedGuids.value.length} 条记录吗？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const { error } = await fetchDeleteTrain(selectedGuids.value);
      if (!error) {
        message.success('删除成功');
        trainStore.clearSelection();
        await loadTree();
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

watch([isEditingDetail, isBatchMode, isTransferMode], (newValues) => {
  if (newValues.every(v => !v) && trainDetail.value) {
    trainStore.loadTrainDetail(trainDetail.value.GUID);
  }
});

onMounted(async () => {
  const savedWidth = localStorage.getItem('train-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= 200 && width <= 600) {
      leftWidth.value = width;
    }
  }
  loadTree();
  await trainStore.loadOptions();
});
</script>

<template>
  <div class="train-container">
    <div class="train-panel train-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <div class="flex items-center gap-12px">
          <span class="text-lg font-600">培训人员</span>
          <NTag type="success" size="small">{{ functionCode }}</NTag>
        </div>
        <NButton size="small" @click="loadTree">
          <template #icon><icon-mdi-refresh /></template>
          刷新
        </NButton>
      </div>
      <div class="panel-content">
        <NTree
          :data="treeData"
          :render-prefix="renderPrefix"
          checkable
          cascade
          selectable
          block-line
          block-node
          :checked-keys="trainStore.checkedKeys"
          :expanded-keys="trainStore.expandedKeys"
          default-expand-all
          @update:checked-keys="handleCheck"
          @update:selected-keys="handleSelect"
          @update:expanded-keys="trainStore.setExpandedKeys"
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
          <NButton type="info" size="small" @click="openBatchModal">
            <template #icon><icon-mdi-pencil /></template>
            批量修改
          </NButton>
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon><icon-mdi-delete /></template>
            删除
          </NButton>
          <NButton type="warning" size="small" @click="openTransferModal">
            <template #icon><icon-mdi-arrow-right /></template>
            转在职
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <!-- 批量修改模式 -->
        <div v-if="isBatchMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">批量修改 (已选择 {{ selectedGuids.length }} 人)</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleBatch">确认</NButton>
              <NButton size="small" @click="cancelBatchMode">取消</NButton>
            </NSpace>
          </div>
          <NTable size="small" :single-line="false">
            <thead>
              <tr>
                <th class="w-32">列名</th>
                <th>列值</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>培训业务</td>
                <td>
                  <NSelect v-model:value="batchForm.培训业务" :options="options?.trainBiz || []" placeholder="请选择培训业务" size="small" />
                </td>
              </tr>
              <tr>
                <td>培训批次</td>
                <td>
                  <NInput v-model:value="batchForm.培训批次" placeholder="请输入培训批次" size="small" />
                </td>
              </tr>
              <tr>
                <td>培训老师</td>
                <td>
                  <NInput v-model:value="batchForm.培训老师" placeholder="请输入培训老师" size="small" />
                </td>
              </tr>
              <tr>
                <td>培训开始日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="batchForm.培训开始日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td>预计完成日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="batchForm.预计完成日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td>培训天数</td>
                <td>
                  <NInput v-model:value="batchForm.培训天数" placeholder="请输入培训天数" size="small" />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 转在职模式 -->
        <div v-else-if="isTransferMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">转在职 (已选择 {{ selectedGuids.length }} 人)</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleTransfer">确认</NButton>
              <NButton size="small" @click="cancelTransferMode">取消</NButton>
            </NSpace>
          </div>
          <NTable size="small" :single-line="false">
            <thead>
              <tr>
                <th class="w-32">列名</th>
                <th>列值</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  培训状态
                  <span class="text-red-500 ml-1">*</span>
                </td>
                <td>
                  <NSelect v-model:value="transferForm.培训状态" :options="options?.trainStatus || []" placeholder="请选择培训状态" size="small" />
                </td>
              </tr>
              <tr>
                <td>岗位类型</td>
                <td>
                  <NSelect v-model:value="transferForm.岗位类型" :options="options?.positionType || []" placeholder="请选择岗位类型" size="small" />
                </td>
              </tr>
              <tr>
                <td>结算类型</td>
                <td>
                  <NSelect v-model:value="transferForm.结算类型" :options="options?.settlementType || []" placeholder="请选择结算类型" size="small" />
                </td>
              </tr>
              <tr>
                <td>培训结束日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="transferForm.培训结束日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td>培训离开原因</td>
                <td>
                  <NInput v-model:value="transferForm.培训离开原因" placeholder="请输入培训离开原因" size="small" />
                </td>
              </tr>
              <tr>
                <td>入职次数</td>
                <td>
                  <NInputNumber v-model:value="transferForm.入职次数" :min="1" class="w-full" />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 编辑模式 -->
        <div v-else-if="isEditingDetail" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">培训信息</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleEditDetail">保存</NButton>
              <NButton size="small" @click="cancelEditDetail">取消</NButton>
            </NSpace>
          </div>
          <NTable size="small" :single-line="false">
            <thead>
              <tr>
                <th class="w-32">列名</th>
                <th class="w-16">是否可修改</th>
                <th>列值</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="field in detailFields" :key="field.fieldName">
                <td>{{ field.columnName }}</td>
                <td>
                  <NTag v-if="field.editable" type="success" size="small">是</NTag>
                  <NTag v-else type="default" size="small">否</NTag>
                </td>
                <td>
                  <template v-if="field.editable">
                    <NInput
                      v-if="field.fieldName !== '培训开始日期' && field.fieldName !== '预计完成日期'"
                      v-model:value="editDetailForm[field.fieldName]"
                      :placeholder="`请输入${field.columnName}`"
                      size="small"
                    />
                    <NDatePicker
                      v-else
                      v-model:formatted-value="editDetailForm[field.fieldName]"
                      value-format="yyyy-MM-dd"
                      type="date"
                      size="small"
                      class="w-full"
                    />
                  </template>
                  <template v-else>
                    <span :class="{ 'text-gray-400': !editDetailForm[field.fieldName] }">
                      {{ editDetailForm[field.fieldName] || '-' }}
                    </span>
                  </template>
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 详情模式 -->
        <div v-else-if="trainDetail" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">培训信息</span>
            <NButton type="info" size="small" :disabled="!trainStore.selectedGuids.includes(String(trainDetail.GUID))" @click="startEditDetail">
              <template #icon><icon-mdi-pencil /></template>
              编辑
            </NButton>
          </div>
          <NTable size="small" :single-line="false">
            <thead>
              <tr>
                <th class="w-32">列名</th>
                <th class="w-16">是否可修改</th>
                <th>列值</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="field in detailFields" :key="field.fieldName">
                <td>{{ field.columnName }}</td>
                <td>
                  <NTag v-if="field.editable" type="success" size="small">是</NTag>
                  <NTag v-else type="default" size="small">否</NTag>
                </td>
                <td>
                  <span v-if="field.fieldName === '培训状态'" :class="trainDetail.培训状态 === '通过' ? 'text-green-500' : ''">
                    {{ trainDetail[field.fieldName] || '-' }}
                  </span>
                  <span v-else :class="{ 'text-gray-400': !trainDetail[field.fieldName] }">
                    {{ trainDetail[field.fieldName] || '-' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <NEmpty v-else description="请选择左侧人员查看详情" class="py-20" />
      </div>
    </div>
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
  border-color: rgba(255, 255, 255, 0.09);
}
html.dark .panel-content {
  background: rgb(24, 24, 28);
}
</style>