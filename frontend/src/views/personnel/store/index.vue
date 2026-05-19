<script setup lang="ts">
import { ref, onMounted, h, computed } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { fetchAddStore, fetchUpdateStore, fetchDeleteStore, fetchTransferStore } from '@/service/api';
import { useStoreStore } from '@/store/modules/store';

const dialog = useDialog();
const message = useMessage();
const storeStore = useStoreStore();

const treeData = computed(() => storeStore.treeData);
const selectedGuids = computed(() => storeStore.selectedGuids);
const storeDetail = computed(() => storeStore.storeDetail);
const options = computed(() => storeStore.options);

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
  邀约次数: '1',
  性别: '',
  年龄: '',
  学校: '',
  专业: '',
  现住址: '',
  工作履历: '',
  渠道类型: '',
  招聘渠道: '',
  渠道名称: '',
  属地: '',
  部门名称: '',
  邀约业务: '',
  邀约岗位: '',
  工作地点: '',
  邀约日期: new Date().toISOString().split('T')[0],
  邀约人: '',
  邀约结果: '',
  预约面试日期: ''
});

const editForm = ref({
  guid: '',
  姓名: '',
  手机号码: '',
  邀约次数: '',
  学校: '',
  专业: '',
  现住址: '',
  工作履历: '',
  渠道类型: '',
  招聘渠道: '',
  渠道名称: '',
  属地: '',
  部门名称: '',
  邀约业务: '',
  邀约岗位: '',
  工作地点: '',
  邀约日期: '',
  邀约人: '',
  邀约结果: '',
  预约面试日期: ''
});

const transferForm = ref({
  面试结果: '',
  面试日期: '',
  面试人: '',
  预约培训日期: '',
  住宿: '',
  通勤方式: '',
  通勤时间: ''
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
    localStorage.setItem('store-splitter-width', String(leftWidth.value));
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

async function loadTree() {
  await storeStore.refreshTree();
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;

  const selectedPeople: string[] = [];
  const guids: string[] = [];

  function collectPeople(nodes: (TreeOption | null)[]) {
    for (const node of nodes) {
      if (!node) continue;
      const data = node.data as Api.Store.StoreTreeNode;
      if (data.type === 'person' && data.guid) {
        guids.push(data.guid);
        selectedPeople.push(data.name || '');
      }
      if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  for (const key of keys) {
    const node = optionNodes.find(n => n?.key === key);
    if (node) {
      const data = node.data as Api.Store.StoreTreeNode;
      if (data.type === 'person' && data.guid) {
        guids.push(data.guid);
        selectedPeople.push(data.name || '');
      } else if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  storeStore.setSelectedGuids(guids);

  if (guids.length === 1) {
    storeStore.loadStoreDetail(guids[0]);
  } else {
    storeStore.storeDetail = null;
  }
}

function openAddModal() {
  addForm.value = {
    姓名: '',
    身份证号: '',
    手机号码: '',
    邀约次数: '1',
    性别: '',
    年龄: '',
    学校: '',
    专业: '',
    现住址: '',
    工作履历: '',
    渠道类型: '',
    招聘渠道: '',
    渠道名称: '',
    属地: '',
    部门名称: '',
    邀约业务: '',
    邀约岗位: '',
    工作地点: '',
    邀约日期: new Date().toISOString().split('T')[0],
    邀约人: '',
    邀约结果: '',
    预约面试日期: ''
  };
  showAddModal.value = true;
}

function openEditModal() {
  if (!storeDetail.value) {
    message.warning('请先选择要编辑的人员');
    return;
  }

  editForm.value = {
    guid: storeDetail.value.GUID,
    姓名: storeDetail.value.姓名,
    手机号码: storeDetail.value.手机号码,
    邀约次数: storeDetail.value.邀约次数,
    学校: storeDetail.value.学校,
    专业: storeDetail.value.专业,
    现住址: storeDetail.value.现住址,
    工作履历: storeDetail.value.工作履历,
    渠道类型: storeDetail.value.渠道类型,
    招聘渠道: storeDetail.value.招聘渠道,
    渠道名称: storeDetail.value.渠道名称,
    属地: storeDetail.value.属地,
    部门名称: storeDetail.value.部门名称,
    邀约业务: storeDetail.value.邀约业务,
    邀约岗位: storeDetail.value.邀约岗位,
    工作地点: storeDetail.value.工作地点,
    邀约日期: storeDetail.value.邀约日期,
    邀约人: storeDetail.value.邀约人,
    邀约结果: storeDetail.value.邀约结果,
    预约面试日期: storeDetail.value.预约面试日期
  };
  showEditModal.value = true;
}

function openTransferModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择要转入面试的人员');
    return;
  }

  transferForm.value = {
    面试结果: '',
    面试日期: new Date().toISOString().split('T')[0],
    面试人: '',
    预约培训日期: '',
    住宿: '',
    通勤方式: '',
    通勤时间: ''
  };
  showTransferModal.value = true;
}

async function handleAdd() {
  if (!addForm.value.姓名.trim()) {
    message.error('姓名不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddStore(addForm.value);
  submitting.value = false;

  if (!error) {
    message.success('新增邀约信息成功');
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
  const { error } = await fetchUpdateStore(editForm.value);
  submitting.value = false;

  if (!error) {
    message.success('修改邀约信息成功');
    showEditModal.value = false;
    await loadTree();
    if (selectedGuids.value.length === 1) {
      await storeStore.loadStoreDetail(selectedGuids.value[0]);
    }
  }
}

async function handleTransfer() {
  if (!transferForm.value.面试结果) {
    message.error('请选择面试结果');
    return;
  }

  submitting.value = true;
  const { error } = await fetchTransferStore({
    guids: selectedGuids.value,
    ...transferForm.value
  });
  submitting.value = false;

  if (!error) {
    message.success('转入面试成功');
    showTransferModal.value = false;
    storeStore.clearSelection();
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
      const { error } = await fetchDeleteStore(selectedGuids.value);
      if (!error) {
        message.success('删除成功');
        storeStore.clearSelection();
        await loadTree();
      }
    }
  });
}

function renderPrefix({ option }: { option: TreeOption }) {
  const data = option.data as Api.Store.StoreTreeNode;
  const icons: Record<string, string> = {
    root: '👥',
    region: '🏢',
    result: '📋',
    interview: '📅',
    date: '📆',
    channel: '📢',
    person: '👤'
  };
  return h('span', { class: 'mr-1' }, icons[data.type] || '📄');
}

onMounted(() => {
  const savedWidth = localStorage.getItem('store-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  storeStore.loadTreeData();
  storeStore.loadOptions();
});
</script>

<template>
  <div class="store-container">
    <div class="store-panel store-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">邀约人员</span>
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
          :expanded-keys="storeStore.expandedKeys"
          default-expand-all
          @update:selected-keys="handleSelect"
          @update:expanded-keys="storeStore.setExpandedKeys"
        />
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="store-panel store-panel-right">
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
            转面试
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
        <div v-if="storeDetail" class="space-y-4">
          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="姓名">
              {{ storeDetail.姓名 }}
            </NDescriptionsItem>
            <NDescriptionsItem label="手机号码">
              {{ storeDetail.手机号码 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="身份证号">
              {{ storeDetail.身份证号 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="性别">
              {{ storeDetail.性别 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="年龄">
              {{ storeDetail.年龄 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="邀约次数">
              {{ storeDetail.邀约次数 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="属地">
              {{ storeDetail.属地 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="招聘渠道">
              {{ storeDetail.招聘渠道 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="邀约日期">
              {{ storeDetail.邀约日期 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="邀约人">
              {{ storeDetail.邀约人 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="邀约结果">
              <NTag :type="storeDetail.邀约结果 === '通过' ? 'success' : 'default'" size="small">
                {{ storeDetail.邀约结果 || '-' }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="面试信息">
              {{ storeDetail.面试信息 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="预约面试日期" :span="2">
              {{ storeDetail.预约面试日期 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="学校">
              {{ storeDetail.学校 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="专业">
              {{ storeDetail.专业 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="现住址" :span="2">
              {{ storeDetail.现住址 || '-' }}
            </NDescriptionsItem>
          </NDescriptions>
        </div>

        <NEmpty v-else description="请选择左侧人员查看详情" class="py-20" />
      </div>
    </div>

    <NModal v-model:show="showAddModal" title="新增邀约信息" preset="card" class="w-150" :mask-closable="false">
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="姓名" required>
          <NInput v-model:value="addForm.姓名" placeholder="请输入姓名" />
        </NFormItem>
        <NFormItem label="手机号码">
          <NInput v-model:value="addForm.手机号码" placeholder="请输入手机号码" />
        </NFormItem>
        <NFormItem label="身份证号">
          <NInput v-model:value="addForm.身份证号" placeholder="请输入身份证号" />
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
        <NFormItem label="邀约结果">
          <NSelect
            v-model:value="addForm.邀约结果"
            :options="options?.result || []"
            placeholder="请选择邀约结果"
            clearable
          />
        </NFormItem>
        <NFormItem label="邀约日期">
          <NDatePicker
            v-model:formatted-value="addForm.邀约日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="预约面试日期">
          <NDatePicker
            v-model:formatted-value="addForm.预约面试日期"
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

    <NModal v-model:show="showEditModal" title="修改邀约信息" preset="card" class="w-150" :mask-closable="false">
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
        <NFormItem label="邀约结果">
          <NSelect
            v-model:value="editForm.邀约结果"
            :options="options?.result || []"
            placeholder="请选择邀约结果"
            clearable
          />
        </NFormItem>
        <NFormItem label="预约面试日期">
          <NDatePicker
            v-model:formatted-value="editForm.预约面试日期"
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

    <NModal v-model:show="showTransferModal" title="转入面试" preset="card" class="w-120" :mask-closable="false">
      <NAlert type="info" class="mb-4">已选择 {{ selectedGuids.length }} 人</NAlert>
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="面试结果" required>
          <NSelect
            v-model:value="transferForm.面试结果"
            :options="options?.interviewResult || []"
            placeholder="请选择面试结果"
          />
        </NFormItem>
        <NFormItem label="面试日期">
          <NDatePicker
            v-model:formatted-value="transferForm.面试日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="面试人">
          <NInput v-model:value="transferForm.面试人" placeholder="请输入面试人" />
        </NFormItem>
        <NFormItem label="预约培训日期">
          <NDatePicker
            v-model:formatted-value="transferForm.预约培训日期"
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

.store-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.store-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}

.store-panel-left {
  flex-shrink: 0;
}

.store-panel-right {
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

html.dark .store-panel {
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
