<script setup lang="ts">
import { ref, onMounted, h, computed } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { fetchAddDept, fetchUpdateDept, fetchDeleteDept, fetchDeptOptions } from '@/service/api';
import { useDeptStore } from '@/store/modules/dept';

const dialog = useDialog();
const message = useMessage();
const deptStore = useDeptStore();

const treeData = computed(() => deptStore.treeData);
const selectedGuid = computed(() => deptStore.selectedGuid);
const deptDetail = computed(() => deptStore.deptDetail);
const isAddingMode = computed(() => deptStore.isAddingMode);
const isEditingMode = computed(() => deptStore.isEditingMode);
const addForm = computed({
  get: () => deptStore.addForm,
  set: (val) => deptStore.setAddForm(val)
});
const editForm = computed({
  get: () => deptStore.editForm,
  set: (val) => deptStore.setEditForm(val)
});

const leftWidth = ref(320);
const minLeftWidth = 200;
const maxLeftWidth = 600;
const isResizing = ref(false);

const submitting = ref(false);

const regionOptions = ref<{ label: string; value: string }[]>([]);

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
    localStorage.setItem('dept-splitter-width', String(leftWidth.value));
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

async function loadDeptTree() {
  await deptStore.refreshTree();
}

async function handleSelect(keys: string[]) {
  if (keys.length === 0) return;
  await deptStore.loadDeptDetail(keys[0]);
}

function openAddModal() {
  if (!deptDetail.value) {
    message.warning('请先选择上级部门');
    return;
  }

  deptStore.setAddForm({
    parentCode: deptDetail.value.部门编码 || '',
    parentName: deptDetail.value.部门名称 || '',
    deptName: '',
    leader: '',
    region: '',
    budgetFullName: '',
    effectiveDate: new Date().toISOString().split('T')[0]
  });
  deptStore.setAddingMode(true);
}

function cancelAddMode() {
  deptStore.clearAddState();
}

async function saveAddMode() {
  if (!addForm.value.deptName?.trim()) {
    message.error('部门名称不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddDept({
    parentCode: addForm.value.parentCode,
    deptName: addForm.value.deptName,
    leader: addForm.value.leader,
    region: addForm.value.region,
    budgetFullName: addForm.value.budgetFullName,
    effectiveDate: addForm.value.effectiveDate || new Date().toISOString().split('T')[0]
  });
  submitting.value = false;

  if (!error) {
    message.success('新增部门成功');
    deptStore.clearAddState();
    await loadDeptTree();
  }
}

function startEditMode() {
  if (!deptDetail.value) {
    message.warning('请先选择要编辑的部门');
    return;
  }

  deptStore.setEditForm({
    guid: deptDetail.value.GUID,
    deptName: deptDetail.value.部门名称 || '',
    leader: deptDetail.value.负责人 || '',
    region: deptDetail.value.属地 || '',
    budgetFullName: deptDetail.value.预算表部门全称 || ''
  });
  deptStore.setEditingMode(true);
}

function cancelEditMode() {
  deptStore.clearEditState();
}

async function saveEditMode() {
  if (!editForm.value.deptName?.trim()) {
    message.error('部门名称不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchUpdateDept({
    guid: editForm.value.guid,
    deptName: editForm.value.deptName,
    leader: editForm.value.leader,
    region: editForm.value.region,
    budgetFullName: editForm.value.budgetFullName
  });
  submitting.value = false;

  if (!error) {
    message.success('修改部门信息成功');
    deptStore.clearEditState();
    await loadDeptTree();
    if (selectedGuid.value) {
      await deptStore.loadDeptDetail(selectedGuid.value);
    }
  }
}

function handleDelete() {
  if (!deptDetail.value) {
    message.warning('请先选择要删除的部门');
    return;
  }

  if (deptDetail.value.有无下级部门 === '有') {
    message.error('该部门存在下级部门，不能删除');
    return;
  }

  dialog.warning({
    title: '确认删除',
    content: `确定要删除部门 "${deptDetail.value.部门名称}" 吗？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const { error } = await fetchDeleteDept(deptDetail.value!.GUID);
      if (!error) {
        message.success('删除部门成功');
        deptStore.clearSelection();
        await loadDeptTree();
      }
    }
  });
}

function renderPrefix({ option }: { option: TreeOption }) {
  const node = option.data as Api.Dept.DeptTreeNode;
  const hasChildren = node.hasChildren === '有' || (option.children && option.children.length > 0);

  return h(
    'span',
    {
      class: 'mr-1'
    },
    hasChildren ? '📁' : '📄'
  );
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('dept-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  deptStore.loadTreeData();
  const { data } = await fetchDeptOptions();
  if (data) {
    regionOptions.value = data.region || [];
  }
});
</script>

<template>
  <div class="dept-container">
    <div class="dept-panel dept-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <div class="flex items-center gap-12px">
          <span class="text-lg font-600">部门架构</span>
          <NTag type="success" size="small">1010</NTag>
        </div>
        <NButton size="small" @click="loadDeptTree">
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
          :selected-keys="selectedGuid ? [selectedGuid] : []"
          :expanded-keys="deptStore.expandedKeys"
          @update:selected-keys="handleSelect"
          @update:expanded-keys="deptStore.setExpandedKeys"
        />
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="dept-panel dept-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">部门信息</span>
        <NSpace>
          <NButton type="primary" size="small" @click="openAddModal">
            <template #icon>
              <icon-mdi-plus />
            </template>
            新增下级
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
        <div v-if="isAddingMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">新增下级部门</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="saveAddMode">保存</NButton>
              <NButton size="small" @click="cancelAddMode">取消</NButton>
            </NSpace>
          </div>
          <NTable size="small" :single-line="false">
            <thead>
              <tr>
                <th class="w-32">列名</th>
                <th class="w-24">是否可新增</th>
                <th>列值</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>上级部门</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>{{ addForm.parentName }}</td>
              </tr>
              <tr>
                <td>
                  部门名称
                  <span class="text-red-500 ml-1">*</span>
                </td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <NInput v-model:value="addForm.deptName" placeholder="请输入部门名称" size="small" />
                </td>
              </tr>
              <tr>
                <td>负责人</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <NInput v-model:value="addForm.leader" placeholder="请输入负责人" size="small" />
                </td>
              </tr>
              <tr>
                <td>属地</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <NSelect
                    v-model:value="addForm.region"
                    :options="regionOptions || []"
                    placeholder="请选择属地"
                    size="small"
                    clearable
                  />
                </td>
              </tr>
              <tr>
                <td>预算表全称</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <NInput v-model:value="addForm.budgetFullName" placeholder="请输入预算表部门全称" size="small" />
                </td>
              </tr>
              <tr>
                <td>生效日期</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="addForm.effectiveDate"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <div v-else-if="deptDetail" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">部门信息</span>
            <div>
              <NButton
                v-if="!isEditingMode"
                type="primary"
                size="small"
                @click="startEditMode"
              >
                <template #icon>
                  <icon-mdi-pencil />
                </template>
                编辑
              </NButton>
              <NSpace v-else>
                <NButton type="primary" size="small" :loading="submitting" @click="saveEditMode">保存</NButton>
                <NButton size="small" @click="cancelEditMode">取消</NButton>
              </NSpace>
            </div>
          </div>
          <NTable size="small" :single-line="false">
            <thead>
              <tr>
                <th class="w-32">列名</th>
                <th class="w-24">是否可修改</th>
                <th>列值</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>部门编码</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>{{ deptDetail.部门编码 }}</td>
              </tr>
              <tr>
                <td>部门名称</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <template v-if="isEditingMode">
                    <NInput v-model:value="editForm.deptName" placeholder="请输入部门名称" size="small" />
                  </template>
                  <template v-else>{{ deptDetail.部门名称 }}</template>
                </td>
              </tr>
              <tr>
                <td>部门全称</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>{{ deptDetail.部门全称 }}</td>
              </tr>
              <tr>
                <td>部门级别</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>{{ deptDetail.部门级别 }}级</td>
              </tr>
              <tr>
                <td>负责人</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <template v-if="isEditingMode">
                    <NInput v-model:value="editForm.leader" placeholder="请输入负责人" size="small" />
                  </template>
                  <template v-else>{{ deptDetail.负责人 || '-' }}</template>
                </td>
              </tr>
              <tr>
                <td>上级部门编码</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>{{ deptDetail.上级部门编码 || '-' }}</td>
              </tr>
              <tr>
                <td>有无下级</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>
                  <NTag :type="deptDetail.有无下级部门 === '有' ? 'success' : 'default'" size="small">
                    {{ deptDetail.有无下级部门 }}
                  </NTag>
                </td>
              </tr>
              <tr>
                <td>属地</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <template v-if="isEditingMode">
                    <NSelect
                      v-model:value="editForm.region"
                      :options="regionOptions || []"
                      placeholder="请选择属地"
                      size="small"
                      clearable
                    />
                  </template>
                  <template v-else>{{ deptDetail.属地 || '-' }}</template>
                </td>
              </tr>
              <tr>
                <td>预算表部门全称</td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <template v-if="isEditingMode">
                    <NInput v-model:value="editForm.budgetFullName" placeholder="请输入预算表部门全称" size="small" />
                  </template>
                  <template v-else>{{ deptDetail.预算表部门全称 || '-' }}</template>
                </td>
              </tr>
              <tr>
                <td>记录开始日期</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>{{ deptDetail.记录开始日期 || '-' }}</td>
              </tr>
              <tr>
                <td>记录结束日期</td>
                <td><NTag type="default" size="small">否</NTag></td>
                <td>{{ deptDetail.记录结束日期 || '-' }}</td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <NEmpty v-else description="请选择左侧部门查看详情" class="py-20" />
      </div>
    </div>
  </div>
</template>

<style scoped>
:deep(.n-tree-node-content) {
  padding: 4px 0;
}

.dept-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.dept-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}

.dept-panel-left {
  flex-shrink: 0;
}

.dept-panel-right {
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

html.dark .dept-panel {
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
