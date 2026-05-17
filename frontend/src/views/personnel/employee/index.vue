<script setup lang="ts">
import { ref, onMounted, h } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import {
  fetchUpdateEmployee,
  fetchBatchUpdateEmployee,
  fetchDeleteEmployee,
  fetchEmployeeOptions,
  fetchEmployeeTree,
  fetchEmployeeDetail
} from '@/service/api';

const dialog = useDialog();
const message = useMessage();

const treeData = ref<TreeOption[]>([]);
const selectedGuids = ref<string[]>([]);
const employeeDetail = ref<Api.Employee.EmployeeDetail | null>(null);
const expandedKeys = ref<string[]>([]);
const options = ref<Api.Employee.EmployeeOptions | null>(null);

const leftWidth = ref(320);
const isResizing = ref(false);

const showEditModal = ref(false);
const showBatchModal = ref(false);
const submitting = ref(false);

const editForm = ref({
  guid: '',
  生效日期: '',
  部门名称: '',
  班组: '',
  员工状态: '',
  一阶段日期: '',
  二阶段日期: '',
  离职日期: '',
  离职原因: ''
});
const batchForm = ref({
  生效日期: '',
  部门名称: '',
  班组: '',
  员工状态: '',
  一阶段日期: '',
  二阶段日期: '',
  离职日期: '',
  离职原因: ''
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
    localStorage.setItem('employee-splitter-width', String(leftWidth.value));
  }
  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

async function loadTree() {
  const { data } = await fetchEmployeeTree();
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
  else employeeDetail.value = null;
}

function collectGuids(node: TreeOption | null, guids: string[]) {
  if (!node) return;
  const data = node.data as Api.Employee.EmployeeTreeNode;
  if (data.type === 'person' && data.guid) guids.push(data.guid);
  if (node.children) node.children.forEach(c => collectGuids(c, guids));
}

async function loadDetail(guid: string) {
  const { data } = await fetchEmployeeDetail(guid);
  if (data) employeeDetail.value = data;
}

function openEditModal() {
  if (!employeeDetail.value) {
    message.warning('请先选择人员');
    return;
  }
  editForm.value = {
    guid: employeeDetail.value.GUID,
    生效日期: new Date().toISOString().split('T')[0],
    部门名称: employeeDetail.value.部门名称,
    班组: employeeDetail.value.班组,
    员工状态: employeeDetail.value.员工状态,
    一阶段日期: employeeDetail.value.一阶段日期,
    二阶段日期: employeeDetail.value.二阶段日期,
    离职日期: employeeDetail.value.离职日期,
    离职原因: employeeDetail.value.离职原因
  };
  showEditModal.value = true;
}

function openBatchModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }
  batchForm.value = {
    生效日期: new Date().toISOString().split('T')[0],
    部门名称: '',
    班组: '',
    员工状态: '',
    一阶段日期: '',
    二阶段日期: '',
    离职日期: '',
    离职原因: ''
  };
  showBatchModal.value = true;
}

async function handleEdit() {
  if (!editForm.value.生效日期) {
    message.error('生效日期不能为空');
    return;
  }
  submitting.value = true;
  const { error } = await fetchUpdateEmployee(editForm.value);
  submitting.value = false;
  if (!error) {
    message.success('修改成功');
    showEditModal.value = false;
    loadTree();
  }
}

async function handleBatch() {
  if (!batchForm.value.生效日期) {
    message.error('生效日期不能为空');
    return;
  }
  submitting.value = true;
  const { error } = await fetchBatchUpdateEmployee({ guids: selectedGuids.value, ...batchForm.value });
  submitting.value = false;
  if (!error) {
    message.success('批量修改成功');
    showBatchModal.value = false;
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
      const { error } = await fetchDeleteEmployee(selectedGuids.value);
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
    dept: '🏛️',
    team: '👥',
    person: '👤'
  };
  return h('span', { class: 'mr-1' }, icons[(option.data as Api.Employee.EmployeeTreeNode).type] || '📄');
}

function convertToTreeOptions(nodes: Api.Employee.EmployeeTreeNode[]): TreeOption[] {
  return nodes.map(n => ({
    key: n.id,
    label: n.value,
    data: n,
    children: n.items?.length ? convertToTreeOptions(n.items) : undefined
  }));
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('employee-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= 200 && width <= 600) {
      leftWidth.value = width;
    }
  }
  loadTree();
  const { data } = await fetchEmployeeOptions();
  if (data) options.value = data;
});
</script>

<template>
  <div class="employee-container">
    <div class="employee-panel employee-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">在职人员</span>
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
    <div class="employee-panel employee-panel-right">
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
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon><icon-mdi-delete /></template>
            删除
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <div v-if="employeeDetail" class="space-y-4">
          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="姓名">{{ employeeDetail.姓名 }}</NDescriptionsItem>
            <NDescriptionsItem label="工号">{{ employeeDetail.工号1 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="属地">{{ employeeDetail.属地 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="员工状态">
              <NTag :type="employeeDetail.员工状态 === '在职' ? 'success' : 'error'" size="small">
                {{ employeeDetail.员工状态 || '-' }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="部门名称">{{ employeeDetail.部门名称 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="班组">{{ employeeDetail.班组 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="岗位名称">{{ employeeDetail.岗位名称 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="岗位类型">{{ employeeDetail.岗位类型 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="结算类型">{{ employeeDetail.结算类型 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="培训完成日期">{{ employeeDetail.培训完成日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="一阶段日期">{{ employeeDetail.一阶段日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="二阶段日期">{{ employeeDetail.二阶段日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="离职日期">{{ employeeDetail.离职日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="离职原因">{{ employeeDetail.离职原因 || '-' }}</NDescriptionsItem>
          </NDescriptions>
        </div>
        <NEmpty v-else description="请选择左侧人员查看详情" class="py-20" />
      </div>
    </div>

    <NModal v-model:show="showEditModal" title="修改人员信息" preset="card" class="w-120">
      <NForm label-placement="left" label-width="100">
        <NFormItem label="生效日期" required>
          <NDatePicker
            v-model:formatted-value="editForm.生效日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="部门名称"><NInput v-model:value="editForm.部门名称" /></NFormItem>
        <NFormItem label="班组"><NInput v-model:value="editForm.班组" /></NFormItem>
        <NFormItem label="员工状态">
          <NSelect v-model:value="editForm.员工状态" :options="options?.status || []" clearable />
        </NFormItem>
        <NFormItem label="一阶段日期">
          <NDatePicker
            v-model:formatted-value="editForm.一阶段日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="二阶段日期">
          <NDatePicker
            v-model:formatted-value="editForm.二阶段日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="离职日期">
          <NDatePicker
            v-model:formatted-value="editForm.离职日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="离职原因"><NInput v-model:value="editForm.离职原因" /></NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showEditModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleEdit">确认</NButton>
        </NSpace>
      </template>
    </NModal>

    <NModal v-model:show="showBatchModal" title="批量修改人员信息" preset="card" class="w-120">
      <NAlert type="info" class="mb-4">已选择 {{ selectedGuids.length }} 人</NAlert>
      <NForm label-placement="left" label-width="100">
        <NFormItem label="生效日期" required>
          <NDatePicker
            v-model:formatted-value="batchForm.生效日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="部门名称"><NInput v-model:value="batchForm.部门名称" /></NFormItem>
        <NFormItem label="班组"><NInput v-model:value="batchForm.班组" /></NFormItem>
        <NFormItem label="员工状态">
          <NSelect v-model:value="batchForm.员工状态" :options="options?.status || []" clearable />
        </NFormItem>
        <NFormItem label="一阶段日期">
          <NDatePicker
            v-model:formatted-value="batchForm.一阶段日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="二阶段日期">
          <NDatePicker
            v-model:formatted-value="batchForm.二阶段日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="离职日期">
          <NDatePicker
            v-model:formatted-value="batchForm.离职日期"
            value-format="yyyy-MM-dd"
            type="date"
            class="w-full"
          />
        </NFormItem>
        <NFormItem label="离职原因"><NInput v-model:value="batchForm.离职原因" /></NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showBatchModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleBatch">确认</NButton>
        </NSpace>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.employee-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}
.employee-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}
.employee-panel-left {
  flex-shrink: 0;
}
.employee-panel-right {
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
html.dark .employee-panel {
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
