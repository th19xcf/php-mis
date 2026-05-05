<script setup lang="ts">
import { ref, onMounted, h, computed } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { fetchAddDept, fetchUpdateDept, fetchDeleteDept } from '@/service/api';
import { useDeptStore } from '@/store/modules/dept';

const dialog = useDialog();
const message = useMessage();
const deptStore = useDeptStore();

// 状态
const treeData = computed(() => deptStore.treeData);
const selectedGuid = computed(() => deptStore.selectedGuid);
const deptDetail = computed(() => deptStore.deptDetail);

// 左侧宽度（像素）
const leftWidth = ref(320);
const minLeftWidth = 200;
const maxLeftWidth = 600;
const isResizing = ref(false);

// 开始拖动
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

// 弹窗状态
const showAddModal = ref(false);
const showEditModal = ref(false);
const submitting = ref(false);

// 新增表单
const addForm = ref({
  parentCode: '',
  parentName: '',
  deptName: '',
  leader: '',
  region: '',
  effectiveDate: new Date().toISOString().split('T')[0]
});

// 编辑表单
const editForm = ref({
  guid: '',
  deptName: '',
  leader: '',
  region: '',
  budgetFullName: ''
});

// 属地选项
const regionOptions = [
  { label: '北京总公司', value: '北京总公司' },
  { label: '河北分公司', value: '河北分公司' },
  { label: '四川分公司', value: '四川分公司' },
  { label: '河南分公司', value: '河南分公司' }
];

// 加载部门树
async function loadDeptTree() {
  await deptStore.refreshTree();
}

// 选择节点
async function handleSelect(keys: string[]) {
  if (keys.length === 0) return;
  await deptStore.loadDeptDetail(keys[0]);
}

// 打开新增弹窗
function openAddModal() {
  if (!deptDetail.value) {
    message.warning('请先选择上级部门');
    return;
  }

  addForm.value = {
    parentCode: deptDetail.value.部门编码,
    parentName: deptDetail.value.部门名称,
    deptName: '',
    leader: '',
    region: '',
    effectiveDate: new Date().toISOString().split('T')[0]
  };
  showAddModal.value = true;
}

// 打开编辑弹窗
function openEditModal() {
  if (!deptDetail.value) {
    message.warning('请先选择要编辑的部门');
    return;
  }

  editForm.value = {
    guid: deptDetail.value.GUID,
    deptName: deptDetail.value.部门名称,
    leader: deptDetail.value.负责人 || '',
    region: deptDetail.value.属地 || '',
    budgetFullName: deptDetail.value.预算表部门全称 || ''
  };
  showEditModal.value = true;
}

// 提交新增
async function handleAdd() {
  if (!addForm.value.deptName.trim()) {
    message.error('部门名称不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddDept({
    parentCode: addForm.value.parentCode,
    deptName: addForm.value.deptName,
    leader: addForm.value.leader,
    region: addForm.value.region,
    effectiveDate: addForm.value.effectiveDate
  });
  submitting.value = false;

  if (!error) {
    message.success('新增部门成功');
    showAddModal.value = false;
    await loadDeptTree();
    deptStore.clearSelection();
  }
}

// 提交编辑
async function handleEdit() {
  if (!editForm.value.deptName.trim()) {
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
    showEditModal.value = false;
    await loadDeptTree();
    if (selectedGuid.value) {
      await deptStore.loadDeptDetail(selectedGuid.value);
    }
  }
}

// 删除部门
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

// 渲染树节点前缀图标
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

onMounted(() => {
  deptStore.loadTreeData();
});
</script>

<template>
  <div class="dept-container">
    <!-- 左侧树形结构 -->
    <div class="dept-panel dept-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">部门结构</span>
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

    <!-- 拖动条 -->
    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <!-- 右侧详情 -->
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
          <NButton type="info" size="small" @click="openEditModal">
            <template #icon>
              <icon-mdi-pencil />
            </template>
            编辑
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
        <div v-if="deptDetail" class="space-y-4">
          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="部门编码">
              {{ deptDetail.部门编码 }}
            </NDescriptionsItem>
            <NDescriptionsItem label="部门名称">
              {{ deptDetail.部门名称 }}
            </NDescriptionsItem>
            <NDescriptionsItem label="部门全称" :span="2">
              {{ deptDetail.部门全称 }}
            </NDescriptionsItem>
            <NDescriptionsItem label="部门级别">{{ deptDetail.部门级别 }}级</NDescriptionsItem>
            <NDescriptionsItem label="负责人">
              {{ deptDetail.负责人 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="上级部门编码">
              {{ deptDetail.上级部门编码 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="有无下级">
              <NTag :type="deptDetail.有无下级部门 === '有' ? 'success' : 'default'" size="small">
                {{ deptDetail.有无下级部门 }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="属地">
              {{ deptDetail.属地 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="预算表部门全称" :span="2">
              {{ deptDetail.预算表部门全称 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="记录开始日期">
              {{ deptDetail.记录开始日期 || '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="记录结束日期">
              {{ deptDetail.记录结束日期 || '-' }}
            </NDescriptionsItem>
          </NDescriptions>
        </div>

        <NEmpty v-else description="请选择左侧部门查看详情" class="py-20" />
      </div>
    </div>

    <!-- 新增弹窗 -->
    <NModal v-model:show="showAddModal" title="新增下级部门" preset="card" class="w-120" :mask-closable="false">
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="上级部门">
          <NInput v-model:value="addForm.parentName" disabled />
        </NFormItem>
        <NFormItem label="部门名称" required>
          <NInput v-model:value="addForm.deptName" placeholder="请输入部门名称" />
        </NFormItem>
        <NFormItem label="负责人">
          <NInput v-model:value="addForm.leader" placeholder="请输入负责人" />
        </NFormItem>
        <NFormItem label="属地">
          <NSelect v-model:value="addForm.region" :options="regionOptions" placeholder="请选择属地" clearable />
        </NFormItem>
        <NFormItem label="生效日期">
          <NDatePicker
            v-model:formatted-value="addForm.effectiveDate"
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

    <!-- 编辑弹窗 -->
    <NModal v-model:show="showEditModal" title="编辑部门信息" preset="card" class="w-120" :mask-closable="false">
      <NForm label-placement="left" label-width="100" require-mark-placement="right-hanging">
        <NFormItem label="部门名称" required>
          <NInput v-model:value="editForm.deptName" placeholder="请输入部门名称" />
        </NFormItem>
        <NFormItem label="负责人">
          <NInput v-model:value="editForm.leader" placeholder="请输入负责人" />
        </NFormItem>
        <NFormItem label="属地">
          <NSelect v-model:value="editForm.region" :options="regionOptions" placeholder="请选择属地" clearable />
        </NFormItem>
        <NFormItem label="预算表全称">
          <NInput v-model:value="editForm.budgetFullName" placeholder="请输入预算表部门全称" />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showEditModal = false">取消</NButton>
          <NButton type="primary" :loading="submitting" @click="handleEdit">确认</NButton>
        </NSpace>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
:deep(.n-tree-node-content) {
  padding: 4px 0;
}

/* 容器 - 使用绝对定位确保高度 */
.dept-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

/* 面板容器 */
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

/* 面板头部 */
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid #e8e8e8;
  flex-shrink: 0;
  background: #fafafa;
}

/* 面板内容区域 - 可滚动 */
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

/* 深色模式适配 - 使用 html.dark 选择器 */
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
