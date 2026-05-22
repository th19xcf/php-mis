<script setup lang="ts">
import { ref, onMounted, onActivated, h, computed } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { fetchAddStore, fetchUpdateStore, fetchDeleteStore, fetchTransferStore, fetchAddFields, fetchDetailFields, fetchBatchEditFields } from '@/service/api';
import { useStoreStore } from '@/store/modules/store';
import type { AddField, DetailField } from '@/typings/api/workbench';

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

const showTransferModal = ref(false);
const submitting = ref(false);
const transferForm = ref<Record<string, any>>({});
const isTransferMode = ref(false);
// 新增模式状态从 store 获取
const isAddingMode = computed(() => storeStore.isAddingMode);
const addFormDynamic = computed({
  get: () => storeStore.addFormDynamic,
  set: (val) => storeStore.setAddFormDynamic(val)
});
const addFields = computed(() => storeStore.addFields);
const detailFields = ref<DetailField[]>([]);
const isEditingDetail = ref(false);
const editDetailForm = ref<Record<string, any>>({});
// 多条修改模式状态从 store 获取
const isBatchEditMode = computed(() => storeStore.isBatchEditMode);
const batchEditForm = computed({
  get: () => storeStore.batchEditForm,
  set: (val) => storeStore.setBatchEditForm(val)
});
const batchEditFields = computed(() => storeStore.batchEditFields);
const searchKeyword = ref('');
const filteredTreeData = ref<TreeOption[]>([]);
const expandedKeys = ref<string[]>([]);

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

function handleCheck(keys: string[], optionNodes: (TreeOption | null)[]) {
  const guids: string[] = [];
  const checkedKeySet = new Set(keys);

  // 递归遍历树，收集所有被勾选的人员节点
  function traverseAndCollect(nodes: TreeOption[]) {
    for (const node of nodes) {
      const data = node.data as Api.Store.StoreTreeNode;
      // 如果当前节点被勾选且是人员类型
      if (checkedKeySet.has(node.key as string) && data.type === 'person' && data.guid) {
        guids.push(data.guid);
      }
      // 递归处理子节点（即使父节点被勾选，也要检查子节点，因为cascade会勾选所有子节点）
      if (node.children && node.children.length > 0) {
        traverseAndCollect(node.children as TreeOption[]);
      }
    }
  }

  traverseAndCollect(treeData.value);

  storeStore.setCheckedKeys(keys);
  storeStore.setSelectedGuids(guids);
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;

  const key = keys[0];
  const node = optionNodes.find(n => n?.key === key);
  if (node) {
    const data = node.data as Api.Store.StoreTreeNode;
    if (data.type === 'person' && data.guid) {
      storeStore.loadStoreDetail(data.guid);
    } else {
      storeStore.storeDetail = null;
    }
  }
}

function handleExpandedKeysChange(keys: string[]) {
  expandedKeys.value = keys;
  storeStore.setExpandedKeys(keys);
}

async function openAddModal() {
  // 加载动态字段配置
  const { data } = await fetchAddFields('2015');
  if (data?.fields) {
    storeStore.setAddFields(data.fields);
    // 初始化表单数据
    const formData: Record<string, any> = {};
    data.fields.forEach((field: AddField) => {
      // 日期字段默认值为 null，避免 DatePicker 格式化错误
      if (field.fieldType === '日期') {
        formData[field.columnName] = field.defaultValue || null;
      } else {
        formData[field.columnName] = field.defaultValue || '';
      }
    });
    storeStore.setAddFormDynamic(formData);
  }
  storeStore.setAddingMode(true);
}

function cancelAddMode() {
  storeStore.clearAddState();
}

async function saveAddMode() {
  // 验证必填字段
  const requiredField = addFields.value.find(f => f.required && !addFormDynamic.value[f.columnName]);
  if (requiredField) {
    message.error(`${requiredField.fieldName}不能为空`);
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddStore(addFormDynamic.value);
  submitting.value = false;

  if (!error) {
    message.success('新增邀约信息成功');
    storeStore.clearAddState();
    await loadTree();
  }
}

async function openBatchEditModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请先选择要修改的人员');
    return;
  }

  // 加载批量修改字段配置
  const { data } = await fetchBatchEditFields('2015');
  if (data?.fields) {
    // 初始化表单数据（使用默认值）
    const formData: Record<string, any> = {};
    data.fields.forEach((field: AddField) => {
      if (field.fieldType === '日期') {
        formData[field.columnName] = field.defaultValue || null;
      } else {
        formData[field.columnName] = field.defaultValue || '';
      }
    });
    storeStore.setBatchEditFields(data.fields);
    storeStore.setBatchEditForm(formData);
    storeStore.setBatchEditMode(true);
  }
}

function cancelBatchEditMode() {
  storeStore.clearBatchEditState();
}

async function saveBatchEditMode() {
  if (selectedGuids.value.length === 0) {
    message.warning('请先选择要修改的人员');
    return;
  }

  submitting.value = true;

  // 批量更新每个选中的人员
  let successCount = 0;
  let failCount = 0;

  for (const guid of selectedGuids.value) {
    const { error } = await fetchUpdateStore({
      guid,
      ...batchEditForm.value
    });
    if (error) {
      failCount++;
    } else {
      successCount++;
    }
  }

  submitting.value = false;

  if (failCount === 0) {
    message.success(`成功修改 ${successCount} 条记录`);
    storeStore.clearBatchEditState();
    await loadTree();
  } else {
    message.warning(`成功 ${successCount} 条，失败 ${failCount} 条`);
  }
}

async function startEditDetail() {
  if (!storeDetail.value) {
    message.warning('请先选择要编辑的人员');
    return;
  }
  
  // 加载新增字段配置，用于编辑时显示控件
  if (!addFields.value || addFields.value.length === 0) {
    const { data } = await fetchAddFields('2015');
    if (data?.fields) {
      storeStore.setAddFields(data.fields);
    }
  }
  
  // 初始化编辑表单数据，保留原记录所有字段内容
  const formData: Record<string, any> = {};
  
  // 先将 storeDetail 中的所有字段都复制到 formData
  Object.keys(storeDetail.value).forEach(key => {
    formData[key] = storeDetail.value?.[key] ?? '';
  });
  
  // 再基于 detailFields 中可编辑字段，确保有值存在
  detailFields.value.forEach(field => {
    if (field.editable) {
      if (formData[field.columnName] === undefined || formData[field.columnName] === null) {
        formData[field.columnName] = '';
      }
    }
  });
  
  editDetailForm.value = formData;
  isEditingDetail.value = true;
}

function cancelDetailEdit() {
  isEditingDetail.value = false;
  editDetailForm.value = {};
}

async function saveDetailEdit() {
  if (!storeDetail.value) return;

  submitting.value = true;
  const { error } = await fetchUpdateStore({
    guid: storeDetail.value.GUID,
    ...editDetailForm.value
  });
  submitting.value = false;

  if (!error) {
    message.success('修改成功');
    isEditingDetail.value = false;
    await storeStore.loadStoreDetail(storeDetail.value.GUID);
  }
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
    预约培训日期: undefined,
    住宿: '',
    通勤方式: '',
    通勤时间: ''
  };
  isTransferMode.value = true;
}

function cancelTransferMode() {
  isTransferMode.value = false;
  transferForm.value = {};
}

// 处理弹窗选择
function handlePopupSelect(field: AddField) {
  // TODO: 实现弹窗选择逻辑，根据 field.objectName 打开对应弹窗
  message.info(`打开${field.fieldName}选择弹窗`);
}

async function handleAdd() {
  // 验证必填字段
  const requiredField = addFields.value.find(f => f.required && !addFormDynamic.value[f.columnName]);
  if (requiredField) {
    message.error(`${requiredField.fieldName}不能为空`);
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddStore(addFormDynamic.value);
  submitting.value = false;

  if (!error) {
    message.success('新增邀约信息成功');
    storeStore.clearAddState();
    await loadTree();
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
    cancelTransferMode();
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

// 搜索过滤树形数据
function filterTreeData(nodes: TreeOption[], keyword: string): { nodes: TreeOption[]; expanded: string[] } {
  const expanded: string[] = [];
  const lowerKeyword = keyword.toLowerCase();

  function filterNode(node: TreeOption): TreeOption | null {
    const data = node.data as Api.Store.StoreTreeNode;
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

    // 如果当前节点匹配或有子节点匹配，则保留
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

// 处理搜索
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

// 清空搜索
function clearSearch() {
  searchKeyword.value = '';
  filteredTreeData.value = treeData.value;
  expandedKeys.value = [];
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('store-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  await storeStore.loadTreeData();
  storeStore.loadOptions();

  // 初始化过滤后的树数据
  filteredTreeData.value = treeData.value;

  // 从 store 恢复展开状态
  expandedKeys.value = storeStore.expandedKeys;

  // 加载详情字段配置
  const { data } = await fetchDetailFields('2015');
  if (data?.fields) {
    detailFields.value = data.fields;
  }
});

// 组件重新激活时恢复状态（KeepAlive 缓存）
onActivated(() => {
  // 恢复展开状态
  expandedKeys.value = storeStore.expandedKeys;
  // 恢复过滤后的树数据
  filteredTreeData.value = treeData.value;
});
</script>

<template>
  <div class="store-container">
    <div class="store-panel store-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <div class="flex items-center gap-12px">
          <span class="text-lg font-600">邀约人员</span>
          <NTag type="success" size="small">2015</NTag>
        </div>
        <NButton size="small" @click="loadTree">
          <template #icon>
            <icon-mdi-refresh />
          </template>
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
          :checked-keys="storeStore.checkedKeys"
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
          <NButton type="info" size="small" @click="openBatchEditModal">
            <template #icon>
              <icon-mdi-pencil />
            </template>
            多条修改
          </NButton>
          <NButton type="warning" size="small" @click="openTransferModal">
            <template #icon>
              <icon-mdi-arrow-right />
            </template>
            面试
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
        <!-- 批量修改模式 -->
        <div v-if="isBatchEditMode">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">多条修改 (已选择 {{ selectedGuids.length }} 人)</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="saveBatchEditMode">保存</NButton>
              <NButton size="small" @click="cancelBatchEditMode">取消</NButton>
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
              <tr v-for="field in batchEditFields" :key="field.columnName">
                <td>
                  {{ field.fieldName }}
                  <span v-if="field.required" class="text-red-500 ml-1">*</span>
                </td>
                <td>
                  <!-- 下拉选择 -->
                  <NSelect
                    v-if="field.objectOptions && field.objectOptions.length > 0"
                    v-model:value="batchEditForm[field.columnName]"
                    :options="field.objectOptions"
                    size="small"
                  />
                  <!-- 日期选择 -->
                  <NDatePicker
                    v-else-if="field.fieldType === '日期'"
                    v-model:formatted-value="batchEditForm[field.columnName]"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                  <!-- 弹窗选择 -->
                  <NInput
                    v-else-if="field.inputType === 'popup'"
                    v-model:value="batchEditForm[field.columnName]"
                    size="small"
                    readonly
                    @click="handlePopupSelect(field)"
                  />
                  <!-- 文本输入 -->
                  <NInput
                    v-else
                    v-model:value="batchEditForm[field.columnName]"
                    size="small"
                  />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 面试模式 -->
        <div v-else-if="isTransferMode">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">面试 (已选择 {{ selectedGuids.length }} 人)</span>
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
                <td>住宿</td>
                <td>
                  <NSelect
                    v-model:value="transferForm.住宿"
                    :options="[
                      { value: '住宿', label: '住宿' },
                      { value: '不住宿', label: '不住宿' }
                    ]"
                    placeholder="请选择"
                    size="small"
                  />
                </td>
              </tr>
              <tr>
                <td>通勤方式</td>
                <td>
                  <NInput v-model:value="transferForm.通勤方式" placeholder="请输入通勤方式" size="small" />
                </td>
              </tr>
              <tr>
                <td>通勤时间</td>
                <td>
                  <NInput v-model:value="transferForm.通勤时间" placeholder="请输入通勤时间" size="small" />
                </td>
              </tr>
              <tr>
                <td>
                  面试结果
                  <span class="text-red-500 ml-1">*</span>
                </td>
                <td>
                  <NSelect
                    v-model:value="transferForm.面试结果"
                    :options="options?.interviewResult || []"
                    placeholder="请选择面试结果"
                    size="small"
                  />
                </td>
              </tr>
              <tr>
                <td>面试日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="transferForm.面试日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td>面试人</td>
                <td>
                  <NInput v-model:value="transferForm.面试人" placeholder="请输入面试人" size="small" />
                </td>
              </tr>
              <tr>
                <td>预约培训日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="transferForm.预约培训日期"
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

        <!-- 新增模式 -->
        <div v-else-if="isAddingMode">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">新增邀约信息</span>
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
                <td>
                  <NTag type="success" size="small">是</NTag>
                </td>
                <td>
                  <!-- 下拉选择 -->
                  <NSelect
                    v-if="field.objectOptions && field.objectOptions.length > 0"
                    v-model:value="addFormDynamic[field.columnName]"
                    :options="field.objectOptions"
                    size="small"
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
                  <!-- 弹窗选择 -->
                  <NInput
                    v-else-if="field.inputType === 'popup'"
                    v-model:value="addFormDynamic[field.columnName]"
                    size="small"
                    readonly
                    @click="handlePopupSelect(field)"
                  />
                  <!-- 文本输入 -->
                  <NInput
                    v-else
                    v-model:value="addFormDynamic[field.columnName]"
                    size="small"
                  />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 详情/编辑模式 -->
        <div v-else-if="storeDetail">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">邀约信息</span>
            <div>
              <NButton v-if="!isEditingDetail" type="primary" size="small" @click="startEditDetail">
                <template #icon>
                  <icon-mdi-pencil />
                </template>
                编辑
              </NButton>
              <NSpace v-else>
                <NButton type="primary" size="small" :loading="submitting" @click="saveDetailEdit">保存</NButton>
                <NButton size="small" @click="cancelDetailEdit">取消</NButton>
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
                  <!-- 编辑模式 -->
                  <template v-if="isEditingDetail && field.editable">
                    <!-- 获取对应的新增字段配置 -->
                    <template v-for="addField in addFields" :key="addField.columnName">
                      <template v-if="addField.columnName === field.columnName">
                        <!-- 下拉选择 -->
                        <NSelect
                          v-if="addField.objectOptions && addField.objectOptions.length > 0"
                          v-model:value="editDetailForm[field.columnName]"
                          :options="addField.objectOptions"
                          size="small"
                        />
                        <!-- 日期选择 -->
                        <NDatePicker
                          v-else-if="addField.fieldType === '日期'"
                          v-model:formatted-value="editDetailForm[field.columnName]"
                          value-format="yyyy-MM-dd"
                          type="date"
                          size="small"
                          class="w-full"
                        />
                        <!-- 弹窗选择 -->
                        <NInput
                          v-else-if="addField.inputType === 'popup'"
                          v-model:value="editDetailForm[field.columnName]"
                          size="small"
                          readonly
                          @click="handlePopupSelect(addField)"
                        />
                        <!-- 多行文本输入（工作履历） -->
                        <NInput
                          v-else-if="field.columnName === '工作履历'"
                          type="textarea"
                          v-model:value="editDetailForm[field.columnName]"
                          size="small"
                          :autosize="{ minRows: 2, maxRows: 10 }"
                        />
                        <!-- 普通文本输入 -->
                        <NInput
                          v-else
                          v-model:value="editDetailForm[field.columnName]"
                          size="small"
                        />
                      </template>
                    </template>
                  </template>
                  <!-- 查看模式 -->
                  <template v-else>
                    <template v-if="field.columnName === '邀约结果'">
                      <NTag :type="storeDetail[field.columnName] === '通过' ? 'success' : 'default'" size="small">
                        {{ storeDetail[field.columnName] || '-' }}
                      </NTag>
                    </template>
                    <template v-else-if="field.columnName === '工作履历'">
                      <span style="white-space: pre-wrap; word-break: break-all; line-height: 1.6;">
                        {{ storeDetail[field.columnName] || '-' }}
                      </span>
                    </template>
                    <template v-else>
                      {{ storeDetail[field.columnName] || '-' }}
                    </template>
                  </template>
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <NEmpty v-else description="请选择左侧人员查看详情或点击新增" class="py-20" />
      </div>
    </div>
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
