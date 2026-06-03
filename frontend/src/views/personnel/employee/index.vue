<script setup lang="ts">
import { ref, computed, watch, onMounted, h } from 'vue';
import { useRoute } from 'vue-router';
import type { TreeOption } from 'naive-ui';
import { useMessage } from 'naive-ui';
import { useEmployeeStore } from '@/store/modules/employee';
import {
  fetchUpdateEmployee,
  fetchBatchUpdateEmployee,
  fetchDeleteEmployee
} from '@/service/api';
import { useSplitter } from '@/hooks/business/use-splitter';
import { useTreeCheck } from '@/hooks/business/use-tree-check';
import { useWorkbenchFields } from '@/hooks/business/use-workbench-fields';
import { useDangerConfirm } from '@/hooks/business/use-danger-confirm';

const route = useRoute();
const message = useMessage();
const employeeStore = useEmployeeStore();
const { confirmDelete, confirmBatch } = useDangerConfirm();

const functionCode = computed(() => {
  return String(route.query.functionCode || route.meta?.functionCode || '');
});

const treeData = computed(() => employeeStore.treeData);
const selectedGuids = computed(() => employeeStore.selectedGuids);
const employeeDetail = computed(() => employeeStore.employeeDetail);
const options = computed(() => employeeStore.options);

const { leftWidth, isResizing, startResize } = useSplitter({
  defaultWidth: 320,
  minWidth: 200,
  maxWidth: 600,
  storageKey: 'employee-splitter-width'
});

const isEditingDetail = ref(false);
const isBatchMode = ref(false);
const submitting = ref(false);

const searchKeyword = ref('');
const filteredTreeData = ref<TreeOption[]>([]);
const expandedKeys = ref<string[]>([]);

const editDetailForm = ref<Record<string, any>>({});

const batchForm = ref({
  生效日期: new Date().toISOString().split('T')[0],
  部门名称: '',
  班组: '',
  员工状态: '',
  一阶段日期: undefined as undefined | string,
  二阶段日期: undefined as undefined | string,
  离职日期: undefined as undefined | string,
  离职原因: ''
});

const { addFields, detailFields, loadFields } = useWorkbenchFields();

const { handleCheck } = useTreeCheck<Api.Employee.EmployeeTreeNode>({
  setCheckedKeys: employeeStore.setCheckedKeys,
  setSelectedGuids: employeeStore.setSelectedGuids
});

async function loadTree() {
  await employeeStore.fetchTree();
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;
  const key = keys[0];
  const node = optionNodes.find(n => n?.key === key);
  if (node) {
    const data = node.data as Api.Employee.EmployeeTreeNode;
    if (data.type === 'person' && data.guid) {
      employeeStore.fetchDetail(data.guid);
    } else {
      employeeStore.setEmployeeDetail(null);
    }
  }
}

async function startEditDetail() {
  if (!employeeDetail.value) {
    message.warning('请先选择要编辑的人员');
    return;
  }

  if (!addFields.value || addFields.value.length === 0) {
    await loadFields(functionCode.value);
  }

  const form: Record<string, any> = {};
  
  Object.keys(employeeDetail.value as Record<string, any>).forEach((key: string) => {
    form[key] = (employeeDetail.value as Record<string, any>)[key] ?? '';
  });
  
  detailFields.value.forEach(field => {
    if (field.editable) {
      const addField = addFields.value.find(f => f.columnName === field.columnName);
      if (addField?.fieldType === '日期') {
        if (!form[field.columnName] || form[field.columnName] === '' || form[field.columnName] === '0000-00-00') {
          form[field.columnName] = undefined;
        }
      } else if (form[field.columnName] === undefined || form[field.columnName] === null) {
        form[field.columnName] = '';
      }
    }
  });
  
  editDetailForm.value = form;
  isEditingDetail.value = true;
}

function cancelEditDetail() {
  isEditingDetail.value = false;
  editDetailForm.value = {};
}

async function handleEditDetail() {
  if (!employeeDetail.value) return;
  submitting.value = true;
  const params = {
    guid: employeeDetail.value.GUID,
    ...editDetailForm.value
  };
  const { error } = await fetchUpdateEmployee(params);
  submitting.value = false;
  if (!error) {
    message.success('修改成功');
    isEditingDetail.value = false;
    await employeeStore.fetchDetail(employeeDetail.value.GUID);
    await loadTree();
  }
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
    一阶段日期: undefined,
    二阶段日期: undefined,
    离职日期: undefined,
    离职原因: ''
  };
  isBatchMode.value = true;
}

function cancelBatchMode() {
  isBatchMode.value = false;
}

async function handleBatch() {
  if (!batchForm.value.生效日期) {
    message.error('生效日期不能为空');
    return;
  }

  const confirmed = await confirmBatch('修改', selectedGuids.value.length);
  if (!confirmed) return;

  submitting.value = true;
  const { error } = await fetchBatchUpdateEmployee({ guids: selectedGuids.value, ...batchForm.value });
  submitting.value = false;
  if (!error) {
    message.success('批量修改成功');
    isBatchMode.value = false;
    employeeStore.setCheckedKeys([]);
    employeeStore.setSelectedGuids([]);
    await loadTree();
  }
}

function handleDelete() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择人员');
    return;
  }

  confirmDelete(selectedGuids.value.length, '人员').then(async (confirmed) => {
    if (!confirmed) return;

    const { error } = await fetchDeleteEmployee(selectedGuids.value);
    if (!error) {
      message.success('删除成功');
      employeeStore.setSelectedGuids([]);
      employeeStore.setCheckedKeys([]);
      await loadTree();
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

function filterTreeData(nodes: TreeOption[], keyword: string): { nodes: TreeOption[]; expanded: string[] } {
  const expanded: string[] = [];
  const lowerKeyword = keyword.toLowerCase();

  function filterNode(node: TreeOption): TreeOption | null {
    const label = (node.label as string) || '';
    const match = label.toLowerCase().includes(lowerKeyword);

    const filteredChildren: TreeOption[] = [];
    if (node.children) {
      for (const child of node.children as TreeOption[]) {
        const filtered = filterNode(child);
        if (filtered) {
          filteredChildren.push(filtered);
        }
      }
    }

    if (match || filteredChildren.length > 0) {
      if (filteredChildren.length > 0) {
        expanded.push(node.key as string);
      }
      return {
        ...node,
        children: filteredChildren.length > 0 ? filteredChildren : node.children
      };
    }

    return null;
  }

  const filtered = nodes.map(node => filterNode(node)).filter((n): n is TreeOption => n !== null);
  return { nodes: filtered, expanded };
}

function handleSearch() {
  if (!searchKeyword.value.trim()) {
    filteredTreeData.value = treeData.value;
    expandedKeys.value = [];
    return;
  }

  const { nodes, expanded } = filterTreeData(treeData.value, searchKeyword.value);
  filteredTreeData.value = nodes;
  expandedKeys.value = expanded;
}

function clearSearch() {
  searchKeyword.value = '';
  filteredTreeData.value = treeData.value;
  expandedKeys.value = [];
}

watch(searchKeyword, (newValue) => {
  if (!newValue.trim()) {
    filteredTreeData.value = treeData.value;
    expandedKeys.value = [];
  } else {
    const { nodes, expanded } = filterTreeData(treeData.value, newValue);
    filteredTreeData.value = nodes;
    expandedKeys.value = expanded;
  }
});

function handleExpandedKeysChange(keys: string[]) {
  expandedKeys.value = keys;
}

watch([isEditingDetail, isBatchMode], (newValues) => {
  if (newValues.every(v => !v) && employeeDetail.value) {
    employeeStore.fetchDetail(employeeDetail.value.GUID);
  }
});

watch(treeData, (newData) => {
  if (!searchKeyword.value.trim()) {
    filteredTreeData.value = newData;
  }
});

onMounted(async () => {
  await loadTree();
  await employeeStore.fetchOptions();
  
  filteredTreeData.value = treeData.value;
  
  await loadFields(functionCode.value);
  
  addFields.value.forEach(field => {
    if (field.columnName === '员工状态') {
      field.objectOptions = options.value?.status || [];
    }
  });
});
</script>

<template>
  <div class="employee-container">
    <div class="employee-panel employee-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <div class="flex items-center gap-12px">
          <span class="text-lg font-600">在职人员</span>
          <NTag v-if="functionCode" type="success" size="small">{{ functionCode }}</NTag>
        </div>
        <NButton size="small" @click="loadTree">
          <template #icon><icon-mdi-refresh /></template>
          刷新
        </NButton>
      </div>
      <div class="panel-content">
        <div class="mb-2">
          <NInput
            v-model:value="searchKeyword"
            placeholder="搜索人员或分类..."
            clearable
            @keyup.enter="handleSearch"
            @clear="clearSearch"
          >
            <template #suffix>
              <NButton text size="small" @click="handleSearch">
                <template #icon>
                  <icon-mdi-magnify />
                </template>
              </NButton>
            </template>
          </NInput>
        </div>
        <NTree
          :data="filteredTreeData"
          :render-prefix="renderPrefix"
          checkable
          cascade
          selectable
          block-line
          block-node
          :checked-keys="employeeStore.checkedKeys"
          :expanded-keys="expandedKeys"
          default-expand-all
          @update:checked-keys="handleCheck"
          @update:selected-keys="handleSelect"
          @update:expanded-keys="handleExpandedKeysChange"
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
          <NButton type="info" size="small" @click="openBatchModal">
            <template #icon><icon-mdi-pencil /></template>
            批量修改
          </NButton>
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon><icon-mdi-delete /></template>
            删除
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
            <tbody>
              <tr>
                <td class="w-32">生效日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="batchForm.生效日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td>部门名称</td>
                <td><NInput v-model:value="batchForm.部门名称" placeholder="请输入部门名称" size="small" /></td>
              </tr>
              <tr>
                <td>班组</td>
                <td><NInput v-model:value="batchForm.班组" placeholder="请输入班组" size="small" /></td>
              </tr>
              <tr>
                <td>员工状态</td>
                <td>
                  <NSelect v-model:value="batchForm.员工状态" :options="options?.status || []" placeholder="请选择员工状态" size="small" />
                </td>
              </tr>
              <tr>
                <td>一阶段日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="batchForm.一阶段日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                    clearable
                  />
                </td>
              </tr>
              <tr>
                <td>二阶段日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="batchForm.二阶段日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                    clearable
                  />
                </td>
              </tr>
              <tr>
                <td>离职日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="batchForm.离职日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                    clearable
                  />
                </td>
              </tr>
              <tr>
                <td>离职原因</td>
                <td><NInput v-model:value="batchForm.离职原因" placeholder="请输入离职原因" size="small" /></td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 编辑模式 -->
        <div v-else-if="isEditingDetail" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">在职信息</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleEditDetail">保存</NButton>
              <NButton size="small" @click="cancelEditDetail">取消</NButton>
            </NSpace>
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
                  <template v-if="field.editable">
                    <template v-for="addField in addFields" :key="addField.columnName">
                      <template v-if="addField.columnName === field.columnName">
                        <NSelect
                          v-if="addField.objectOptions && addField.objectOptions.length > 0"
                          v-model:value="editDetailForm[field.columnName]"
                          :options="addField.objectOptions"
                          size="small"
                          clearable
                        />
                        <NDatePicker
                          v-else-if="addField.fieldType === '日期'"
                          v-model:formatted-value="editDetailForm[field.columnName]"
                          value-format="yyyy-MM-dd"
                          type="date"
                          size="small"
                          class="w-full"
                        />
                        <NInput
                          v-else
                          v-model:value="editDetailForm[field.columnName]"
                          size="small"
                        />
                      </template>
                    </template>
                  </template>
                  <template v-else>
                    <span :class="{ 'text-gray-400': !editDetailForm[field.columnName] }">
                      {{ editDetailForm[field.columnName] || '-' }}
                    </span>
                  </template>
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 详情模式 -->
        <div v-else-if="employeeDetail" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">在职信息</span>
            <NButton 
              type="primary" 
              size="small" 
              :disabled="!employeeDetail || !employeeStore.selectedGuids.includes(String(employeeDetail.GUID))"
              @click="startEditDetail"
            >
              <template #icon><icon-mdi-pencil /></template>
              编辑
            </NButton>
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
                  <template v-if="field.columnName === '员工状态'">
                    <NTag :type="employeeDetail.员工状态 === '在职' ? 'success' : 'error'" size="small">
                      {{ employeeDetail[field.columnName as keyof typeof employeeDetail] || '-' }}
                    </NTag>
                  </template>
                  <template v-else>
                    <span :class="{ 'text-gray-400': !employeeDetail[field.columnName as keyof typeof employeeDetail] }">
                      {{ employeeDetail[field.columnName as keyof typeof employeeDetail] || '-' }}
                    </span>
                  </template>
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
  border-color: rgba(255, 255, 255, 0.09);
}
html.dark .panel-content {
  background: rgb(24, 24, 28);
}
</style>
