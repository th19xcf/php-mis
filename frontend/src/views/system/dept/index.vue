<script setup lang="ts">
import { ref, onMounted, h, computed } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { fetchAddDept, fetchUpdateDept, fetchDeleteDept, fetchDeptOptions } from '@/service/api';
import { fetchPopupLevels, fetchPopupLevelData } from '@/service/api/workbench';
import { useWorkbenchFields } from '@/hooks/business/use-workbench-fields';
import { usePersonnelEditFormInit } from '@/hooks/business/use-personnel-edit-form-init';
import { useDeptStore } from '@/store/modules/dept';

const dialog = useDialog();
const message = useMessage();
const deptStore = useDeptStore();

const treeData = computed(() => deptStore.treeData);
const selectedGuid = computed(() => deptStore.selectedGuid);
const deptDetail = computed(() => deptStore.deptDetail);
const isAddingMode = computed(() => deptStore.isAddingMode);
const isEditingMode = computed(() => deptStore.isEditingMode);

// 字段配置：从 def_query_column 读取
const FUNCTION_CODE = '1010';
const { addFields, detailFields, loadFields } = useWorkbenchFields();
const { buildEditForm } = usePersonnelEditFormInit();

// 动态表单数据（key 为中文字段名）
const addFormDynamic = ref<Record<string, any>>({});
const editFormDynamic = ref<Record<string, any>>({});

const leftWidth = ref(320);
const minLeftWidth = 200;
const maxLeftWidth = 600;
const isResizing = ref(false);

const submitting = ref(false);

const regionOptions = ref<{ label: string; value: string }[]>([]);

// 弹窗选择相关状态
const popupVisible = ref(false);
const popupLoading = ref(false);
const popupLevels = ref<Api.Workbench.PopupLevel[]>([]);
const popupMaxLevel = ref(1);
const popupCascaderOptions = ref<any[]>([]);
const popupSelectedValue = ref<string | null>(null);
const popupSelectedOption = ref<any>(null);
const popupTargetField = ref<'add' | 'edit'>('add');
const popupColumnName = ref<string>('预算表部门全称');

// 弹窗对象名称（来自 def_query_column 配置：赋值类型=弹窗, 对象=预算部门^全称）
const POPUP_OBJECT_NAME = '预算部门^全称';

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

async function openAddModal() {
  if (!deptDetail.value) {
    message.warning('请先选择上级部门');
    return;
  }

  // 确保字段配置已加载
  if (!addFields.value.length) {
    await loadFields(FUNCTION_CODE);
  }

  // 从 addFields 初始化表单
  const initialForm: Record<string, any> = {};
  addFields.value.forEach(field => {
    if (field.fieldType === '日期') {
      initialForm[field.columnName] = new Date().toISOString().split('T')[0];
    } else if (field.defaultValue !== undefined && field.defaultValue !== '') {
      initialForm[field.columnName] = field.defaultValue;
    } else {
      initialForm[field.columnName] = '';
    }
  });
  addFormDynamic.value = initialForm;
  deptStore.setAddingMode(true);
}

function cancelAddMode() {
  deptStore.clearAddState();
}

async function saveAddMode() {
  // 校验必填字段
  const requiredField = addFields.value.find(
    field => field.required && !addFormDynamic.value[field.columnName]?.toString().trim()
  );
  if (requiredField) {
    message.error(`${requiredField.fieldName}不能为空`);
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddDept({
    ...addFormDynamic.value,
    parentCode: deptDetail.value?.部门编码 || ''
  } as Api.Dept.DeptAddParams);
  submitting.value = false;

  if (!error) {
    message.success('新增部门成功');
    deptStore.clearAddState();
    await loadDeptTree();
  }
}

async function startEditMode() {
  if (!deptDetail.value) {
    message.warning('请先选择要编辑的部门');
    return;
  }

  if (!addFields.value.length) {
    await loadFields(FUNCTION_CODE);
  }

  editFormDynamic.value = buildEditForm(
    deptDetail.value as Record<string, any>,
    addFields.value,
    detailFields.value
  );
  deptStore.setEditingMode(true);
}

function cancelEditMode() {
  deptStore.clearEditState();
}

async function saveEditMode() {
  // 校验必填字段
  const requiredField = detailFields.value.find(
    field => field.editable && !editFormDynamic.value[field.columnName]?.toString().trim()
  );
  if (requiredField) {
    message.error(`${requiredField.fieldName}不能为空`);
    return;
  }

  submitting.value = true;
  const { error } = await fetchUpdateDept({
    ...editFormDynamic.value,
    guid: deptDetail.value!.GUID
  } as Api.Dept.DeptUpdateParams);
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

// 打开弹窗选择
async function openPopupSelect(target: 'add' | 'edit', columnName: string = '预算表部门全称') {
  popupTargetField.value = target;
  popupColumnName.value = columnName;
  popupVisible.value = true;
  popupLoading.value = true;
  popupSelectedValue.value = null;
  popupSelectedOption.value = null;

  try {
    const { data } = await fetchPopupLevels(FUNCTION_CODE, POPUP_OBJECT_NAME);
    if (data) {
      popupLevels.value = data.levels || [];
      popupMaxLevel.value = data.maxLevel || 1;

      // 加载第一级数据
      const levelData = await fetchPopupLevelData(FUNCTION_CODE, POPUP_OBJECT_NAME, 1, '');
      if (levelData.data) {
        popupCascaderOptions.value = levelData.data.items.map(item => ({
          label: item.name,
          value: item.fullName || item.code,
          fullName: item.fullName,
          level: 1,
          isLeaf: !item.hasChildren
        }));
      }
    }
  } catch {
    message.error('加载弹窗数据失败');
  } finally {
    popupLoading.value = false;
  }
}

// 懒加载子节点
async function handleLoadCascaderChildren(node: any): Promise<void> {
  const parentCode = node.fullName || node.value;
  const { data } = await fetchPopupLevelData(FUNCTION_CODE, POPUP_OBJECT_NAME, node.level + 1, parentCode);
  if (data) {
    node.children = data.items.map(item => ({
      label: item.name,
      value: item.fullName || item.code,
      fullName: item.fullName,
      level: node.level + 1,
      isLeaf: !item.hasChildren
    }));
  }
}

// 处理选择值变化
function handlePopupValueChange(value: string | null, option: any) {
  popupSelectedValue.value = value;
  popupSelectedOption.value = option;
}

// 替换值
function handlePopupReplace() {
  if (popupSelectedOption.value) {
    const fullName = popupSelectedOption.value.fullName || popupSelectedOption.value.label;
    if (popupTargetField.value === 'add') {
      addFormDynamic.value[popupColumnName.value] = fullName;
    } else {
      editFormDynamic.value[popupColumnName.value] = fullName;
    }
  }
  popupVisible.value = false;
}

// 添加值（追加）
function handlePopupAppend() {
  if (popupSelectedOption.value) {
    const fullName = popupSelectedOption.value.fullName || popupSelectedOption.value.label;
    if (popupTargetField.value === 'add') {
      const current = addFormDynamic.value[popupColumnName.value] || '';
      addFormDynamic.value[popupColumnName.value] = current ? `${current},${fullName}` : fullName;
    } else {
      const current = editFormDynamic.value[popupColumnName.value] || '';
      editFormDynamic.value[popupColumnName.value] = current ? `${current},${fullName}` : fullName;
    }
  }
  popupVisible.value = false;
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
  // 加载字段配置（从 def_query_column 读取）
  await loadFields(FUNCTION_CODE);
  // 属地下拉选项由后端 def_query_column 配置驱动（赋值类型=固定值, 对象=属地）
  // fetchDeptOptions 仅用于树形结构等场景，不再强制注入 regionOptions
  const { data } = await fetchDeptOptions();
  if (data) {
    regionOptions.value = data.region || [];
    // 兼容：若后端未返回属地下拉，则用前端选项注入 addFields
    if (regionOptions.value.length) {
      addFields.value.forEach(field => {
        if (field.columnName === '属地') {
          field.objectOptions = regionOptions.value;
        }
      });
    }
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
        <!-- 新增模式：从 def_query_column.可新增=1 渲染 -->
        <div v-if="isAddingMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">新增下级部门</span>
            <span class="text-sm text-gray-500">
              上级部门：{{ deptDetail?.部门名称 }} ({{ deptDetail?.部门编码 }})
            </span>
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
              <tr v-for="field in addFields" :key="field.columnName">
                <td>
                  {{ field.fieldName }}
                  <span v-if="field.required" class="text-red-500 ml-1">*</span>
                </td>
                <td><NTag type="success" size="small">是</NTag></td>
                <td>
                  <!-- 弹窗选择 -->
                  <template v-if="field.inputType === 'popup'">
                    <NInput
                      :value="addFormDynamic[field.columnName]"
                      placeholder="请选择"
                      size="small"
                      readonly
                    >
                      <template #suffix>
                        <NButton text type="primary" size="tiny" @click="openPopupSelect('add', field.columnName)">
                          <template #icon>
                            <icon-mdi-magnify />
                          </template>
                          选择
                        </NButton>
                      </template>
                    </NInput>
                  </template>
                  <!-- 下拉选择（固定值/多选） -->
                  <NSelect
                    v-else-if="field.objectOptions && field.objectOptions.length > 0"
                    v-model:value="addFormDynamic[field.columnName]"
                    :options="field.objectOptions"
                    size="small"
                    clearable
                  />
                  <!-- 日期选择 -->
                  <NDatePicker
                    v-else-if="field.fieldType === '日期'"
                    v-model:formatted-value="addFormDynamic[field.columnName]"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                  <!-- 文本输入 -->
                  <NInput v-else v-model:value="addFormDynamic[field.columnName]" size="small" />
                </td>
              </tr>
              <tr v-if="!addFields.length">
                <td colspan="3" class="text-center text-gray-400">未配置可新增字段</td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 详情/编辑模式：从 def_query_column 全部列渲染 -->
        <div v-else-if="deptDetail" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">部门信息</span>
            <div>
              <NButton v-if="!isEditingMode" type="primary" size="small" @click="startEditMode">
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
              <tr v-for="field in detailFields" :key="field.columnName">
                <td>{{ field.fieldName }}</td>
                <td>
                  <NTag :type="field.editable ? 'success' : 'default'" size="small">
                    {{ field.editable ? '是' : '否' }}
                  </NTag>
                </td>
                <td>
                  <!-- 编辑模式 + 可修改字段 -->
                  <template v-if="isEditingMode && field.editable">
                    <template v-for="addField in addFields" :key="addField.columnName">
                      <template v-if="addField.columnName === field.columnName">
                        <!-- 弹窗选择 -->
                        <NInput
                          v-if="addField.inputType === 'popup'"
                          :value="editFormDynamic[field.columnName]"
                          placeholder="请选择"
                          size="small"
                          readonly
                        >
                          <template #suffix>
                            <NButton
                              text
                              type="primary"
                              size="tiny"
                              @click="openPopupSelect('edit', field.columnName)"
                            >
                              <template #icon>
                                <icon-mdi-magnify />
                              </template>
                              选择
                            </NButton>
                          </template>
                        </NInput>
                        <!-- 下拉选择 -->
                        <NSelect
                          v-else-if="addField.objectOptions && addField.objectOptions.length > 0"
                          v-model:value="editFormDynamic[field.columnName]"
                          :options="addField.objectOptions"
                          size="small"
                          clearable
                        />
                        <!-- 日期选择 -->
                        <NDatePicker
                          v-else-if="addField.fieldType === '日期'"
                          v-model:formatted-value="editFormDynamic[field.columnName]"
                          value-format="yyyy-MM-dd"
                          type="date"
                          size="small"
                          class="w-full"
                        />
                        <!-- 文本输入 -->
                        <NInput v-else v-model:value="editFormDynamic[field.columnName]" size="small" />
                      </template>
                    </template>
                    <!-- detailFields 中可修改但 addFields 中无配置的字段：用文本输入兜底 -->
                    <NInput
                      v-if="!addFields.some(f => f.columnName === field.columnName)"
                      v-model:value="editFormDynamic[field.columnName]"
                      size="small"
                    />
                  </template>
                  <!-- 非编辑模式 或 不可修改字段：显示文本 -->
                  <template v-else>
                    <template v-if="field.columnName === '有无下级部门'">
                      <NTag :type="deptDetail[field.columnName] === '有' ? 'success' : 'default'" size="small">
                        {{ deptDetail[field.columnName] || '-' }}
                      </NTag>
                    </template>
                    <template v-else>
                      {{ deptDetail[field.columnName] ?? '-' }}
                    </template>
                  </template>
                </td>
              </tr>
              <tr v-if="!detailFields.length">
                <td colspan="3" class="text-center text-gray-400">未配置字段</td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <NEmpty v-else description="请选择左侧部门查看详情" class="py-20" />
      </div>
    </div>
  </div>

  <!-- 弹窗选择组件 -->
  <NModal
    :show="popupVisible"
    preset="card"
    title="选择预算表部门全称"
    class="w-600px"
    :mask-closable="false"
    @update:show="popupVisible = $event"
  >
    <NSpin :show="popupLoading">
      <NSpace vertical :size="16">
        <NFormItem label="选择路径">
          <NCascader
            :value="popupSelectedValue"
            :options="popupCascaderOptions"
            :on-load="handleLoadCascaderChildren"
            remote
            expand-trigger="click"
            placeholder="请选择"
            clearable
            @update:value="handlePopupValueChange"
          />
        </NFormItem>

        <div v-if="popupLevels.length" class="popup-levels-hint">
          <NText depth="3">
            共 {{ popupMaxLevel }} 级：
            <span v-for="(level, index) in popupLevels" :key="level.level">
              {{ level.name }}
              <span v-if="index < popupLevels.length - 1">→</span>
            </span>
          </NText>
        </div>
        <NEmpty v-else-if="!popupLoading" description="暂无数据" />

        <NSpace justify="end">
          <NButton @click="popupVisible = false">取消</NButton>
          <NButton :disabled="!popupSelectedValue" @click="handlePopupReplace">替换</NButton>
          <NButton type="primary" :disabled="!popupSelectedValue" @click="handlePopupAppend">添加</NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
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

.popup-levels-hint {
  padding: 8px 0;
  font-size: 12px;
}
</style>
