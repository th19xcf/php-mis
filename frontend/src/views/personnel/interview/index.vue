<script setup lang="ts">
import { ref, onMounted, h, computed, watch } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';
import { useRoute } from 'vue-router';
import { fetchAddInterview, fetchUpdateInterview, fetchDeleteInterview, fetchTransferInterview, fetchAddFields, fetchDetailFields } from '@/service/api';
import { useInterviewStore } from '@/store/modules/interview';


const dialog = useDialog();
const message = useMessage();
const route = useRoute();
const interviewStore = useInterviewStore();

const functionCode = computed(() => {
  return String(route.query.functionCode || route.meta?.functionCode || '2016');
});

const treeData = computed(() => interviewStore.treeData);
const selectedGuids = computed(() => interviewStore.selectedGuids);
const interviewDetail = computed(() => interviewStore.interviewDetail);
const options = computed(() => interviewStore.options);

const leftWidth = ref(320);
const minLeftWidth = 200;
const maxLeftWidth = 600;
const isResizing = ref(false);

const isAddingMode = ref(false);
const isEditingDetail = ref(false);
const isTransferMode = ref(false);
const isSecondInterviewMode = ref(false);
const submitting = ref(false);

const searchKeyword = ref('');
const filteredTreeData = ref<TreeOption[]>([]);
const expandedKeys = ref<string[]>([]);

const addFormDynamic = ref<Record<string, any>>({});

const editDetailForm = ref<Record<string, any>>({});

const transferForm = ref<Record<string, string | undefined>>({
  参培信息: '',
  培训业务: '',
  培训批次: '',
  培训老师: '',
  培训开始日期: undefined,
  预计完成日期: undefined
});

const secondInterviewForm = ref({
  二次面试人: '',
  二次面试日期: new Date().toISOString().split('T')[0],
  二次面试记录: '',
  二次面试结果: '',
  预约培训日期: undefined
});

const detailFields = ref<Array<{ columnName: string; fieldName: string; editable: boolean }>>([]);
const addFields = ref<Array<{ columnName: string; fieldName: string; fieldType: string; editable: boolean; required: boolean; objectOptions: Array<{ value: string; label: string }> }>>([]);

async function loadFields() {
  const code = Array.isArray(functionCode.value) ? functionCode.value[0] : functionCode.value;
  const [addResult, detailResult] = await Promise.all([
    fetchAddFields(code),
    fetchDetailFields(code)
  ]);
  
  if (addResult.data?.fields) {
    addFields.value = addResult.data.fields.map((field: any) => ({
      columnName: field.columnName,
      fieldName: field.fieldName,
      fieldType: field.fieldType || '文本',
      editable: field.editable !== undefined ? field.editable : true,
      required: field.required || false,
      objectOptions: field.objectOptions || []
    }));
    
    addFields.value.forEach(field => {
      if (field.columnName === '属地') {
        field.objectOptions = options.value?.region || [];
      } else if (field.columnName === '招聘渠道') {
        field.objectOptions = options.value?.channel || [];
      } else if (field.columnName === '面试结果') {
        field.objectOptions = options.value?.interviewResult || [];
      }
    });
  }
  
  if (detailResult.data?.fields) {
    detailFields.value = detailResult.data.fields.map((field: any) => ({
      columnName: field.columnName,
      fieldName: field.fieldName,
      editable: field.editable !== undefined ? field.editable : false
    }));
  }
}

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
    localStorage.setItem('interview-splitter-width', String(leftWidth.value));
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

async function loadTree() {
  await interviewStore.refreshTree();
}

function handleCheck(keys: string[], optionNodes: (TreeOption | null)[]) {
  const guids: string[] = [];

  function collectPeople(nodes: (TreeOption | null)[]) {
    for (const node of nodes) {
      if (!node) continue;
      const data = node.data as Api.Interview.InterviewTreeNode;
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
      const data = node.data as Api.Interview.InterviewTreeNode;
      if (data.type === 'person' && data.guid) {
        if (!guids.includes(data.guid)) {
          guids.push(data.guid);
        }
      } else if (node.children) {
        collectPeople(node.children);
      }
    }
  }

  interviewStore.setCheckedKeys(keys);
  interviewStore.setSelectedGuids(guids);
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;

  const key = keys[0];
  const node = optionNodes.find(n => n?.key === key);
  if (node) {
    const data = node.data as Api.Interview.InterviewTreeNode;
    if (data.type === 'person' && data.guid) {
      interviewStore.loadInterviewDetail(data.guid);
    } else {
      interviewStore.interviewDetail = null;
    }
  }
}

async function openAddModal() {
  await loadFields();
  
  const initialForm: Record<string, any> = {};
  addFields.value.forEach(field => {
    if (field.fieldType === '日期') {
      initialForm[field.columnName] = field.columnName === '预约培训日期' ? undefined : new Date().toISOString().split('T')[0];
    } else {
      initialForm[field.columnName] = '';
    }
  });
  addFormDynamic.value = initialForm;
  isAddingMode.value = true;
}

function cancelAdd() {
  isAddingMode.value = false;
}

async function handleAdd() {
  const requiredField = addFields.value.find(field => field.required && !addFormDynamic.value[field.columnName]?.trim());
  if (requiredField) {
    message.error(`${requiredField.fieldName}不能为空`);
    return;
  }

  submitting.value = true;
  const { error } = await fetchAddInterview(addFormDynamic.value as any);
  submitting.value = false;

  if (!error) {
    message.success('新增面试信息成功');
    isAddingMode.value = false;
    await loadTree();
  }
}

async function startEditDetail() {
  if (!interviewDetail.value) {
    message.warning('请先选择要编辑的人员');
    return;
  }

  if (!addFields.value || addFields.value.length === 0) {
    await loadFields();
  }

  const form: Record<string, any> = {};
  
  Object.keys(interviewDetail.value as Record<string, any>).forEach((key: string) => {
    form[key] = (interviewDetail.value as Record<string, any>)[key] ?? '';
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

function cancelDetailEdit() {
  isEditingDetail.value = false;
}

async function saveDetailEdit() {
  if (!editDetailForm.value.姓名?.trim()) {
    message.error('姓名不能为空');
    return;
  }

  submitting.value = true;
  const { error } = await fetchUpdateInterview({
    guid: editDetailForm.value.GUID,
    姓名: editDetailForm.value.姓名,
    手机号码: editDetailForm.value.手机号码,
    属地: editDetailForm.value.属地,
    招聘渠道: editDetailForm.value.招聘渠道,
    面试日期: editDetailForm.value.面试日期,
    面试结果: editDetailForm.value.面试结果,
    面试人: editDetailForm.value.面试人,
    预约培训日期: editDetailForm.value.预约培训日期,
    住宿: editDetailForm.value.住宿,
    备注说明: editDetailForm.value.备注说明
  });
  submitting.value = false;

  if (!error) {
    message.success('修改面试信息成功');
    isEditingDetail.value = false;
    await loadTree();
    await interviewStore.loadInterviewDetail(editDetailForm.value.GUID);
  }
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
    预计完成日期: undefined
  };
  isTransferMode.value = true;
}

function cancelTransferMode() {
  isTransferMode.value = false;
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
    isTransferMode.value = false;
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

function handleSecondInterview() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择要进行二次面试的人员');
    return;
  }

  secondInterviewForm.value = {
    二次面试人: '',
    二次面试日期: new Date().toISOString().split('T')[0],
    二次面试记录: '',
    二次面试结果: '',
    预约培训日期: undefined
  };
  isSecondInterviewMode.value = true;
}

function cancelSecondInterviewMode() {
  isSecondInterviewMode.value = false;
}

async function handleSecondInterviewConfirm() {
  if (!secondInterviewForm.value.二次面试结果) {
    message.error('请选择二次面试结果');
    return;
  }

  submitting.value = true;
  const { error } = await fetchUpdateInterview({
    guid: interviewDetail.value?.GUID || '',
    二次面试人: secondInterviewForm.value.二次面试人,
    二次面试日期: secondInterviewForm.value.二次面试日期,
    二次面试记录: secondInterviewForm.value.二次面试记录,
    二次面试结果: secondInterviewForm.value.二次面试结果,
    预约培训日期: secondInterviewForm.value.预约培训日期
  });
  submitting.value = false;

  if (!error) {
    message.success('二次面试信息保存成功');
    isSecondInterviewMode.value = false;
    await loadTree();
    if (interviewDetail.value?.GUID) {
      await interviewStore.loadInterviewDetail(interviewDetail.value.GUID);
    }
  }
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

function filterTreeData(nodes: TreeOption[], keyword: string): { nodes: TreeOption[]; expanded: string[] } {
  const expanded: string[] = [];
  const lowerKeyword = keyword.toLowerCase();

  function filterNode(node: TreeOption): TreeOption | null {
    const data = node.data as Api.Interview.InterviewTreeNode;
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

function handleExpandedKeysChange(keys: string[]) {
  expandedKeys.value = keys;
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('interview-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  interviewStore.loadTreeData();
  interviewStore.loadOptions();

  filteredTreeData.value = treeData.value;
  
  await loadFields();
});

watch(treeData, (newData) => {
  if (!searchKeyword.value.trim()) {
    filteredTreeData.value = newData;
  }
});

watch([isAddingMode, isEditingDetail, isTransferMode, isSecondInterviewMode], (newValues) => {
  if (newValues.every(v => !v) && interviewDetail.value) {
    interviewStore.loadInterviewDetail(interviewDetail.value.GUID);
  }
});
</script>

<template>
  <div class="interview-container">
    <div class="interview-panel interview-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <div class="flex items-center gap-12px">
          <span class="text-lg font-600">面试人员</span>
          <NTag type="success" size="small">{{ functionCode }}</NTag>
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
          :checked-keys="interviewStore.checkedKeys"
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
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon>
              <icon-mdi-delete />
            </template>
            删除
          </NButton>
          <NButton type="info" size="small" @click="handleSecondInterview">
            <template #icon>
              <icon-mdi-refresh />
            </template>
            二次面试
          </NButton>
          <NButton type="warning" size="small" @click="openTransferModal">
            <template #icon>
              <icon-mdi-arrow-right />
            </template>
            培训
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <!-- 新增模式 -->
        <div v-if="isAddingMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">新增面试信息</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleAdd">保存</NButton>
              <NButton size="small" @click="cancelAdd">取消</NButton>
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
                  <NSelect
                    v-if="field.objectOptions && field.objectOptions.length > 0"
                    v-model:value="addFormDynamic[field.columnName]"
                    :options="field.objectOptions"
                    size="small"
                    :clearable="true"
                  />
                  <NDatePicker
                    v-else-if="field.fieldType === '日期'"
                    v-model:formatted-value="addFormDynamic[field.columnName]"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
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

        <!-- 转培训模式 -->
        <div v-else-if="isTransferMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">培训 (已选择 {{ selectedGuids.length }} 人)</span>
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
                <td class="font-medium">参培信息</td>
                <td>
                  <NSelect
                    v-model:value="transferForm.参培信息"
                    :options="options?.trainStatus || []"
                    size="small"
                    placeholder="请选择参培信息"
                  />
                </td>
              </tr>
              <tr>
                <td class="font-medium">培训业务</td>
                <td>
                  <NSelect
                    v-model:value="transferForm.培训业务"
                    :options="options?.trainBiz || []"
                    size="small"
                    placeholder="请选择培训业务"
                    clearable
                  />
                </td>
              </tr>
              <tr>
                <td class="font-medium">培训批次</td>
                <td>
                  <NInput v-model:value="transferForm.培训批次" size="small" placeholder="请输入培训批次" />
                </td>
              </tr>
              <tr>
                <td class="font-medium">培训老师</td>
                <td>
                  <NInput v-model:value="transferForm.培训老师" size="small" placeholder="请输入培训老师" />
                </td>
              </tr>
              <tr>
                <td class="font-medium">培训开始日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="transferForm.培训开始日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td class="font-medium">预计完成日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="transferForm.预计完成日期"
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

        <!-- 二次面试模式 -->
        <div v-else-if="isSecondInterviewMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">二次面试 (已选择 {{ selectedGuids.length }} 人)</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleSecondInterviewConfirm">确认</NButton>
              <NButton size="small" @click="cancelSecondInterviewMode">取消</NButton>
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
                <td>二次面试人</td>
                <td>
                  <NInput v-model:value="secondInterviewForm.二次面试人" placeholder="请输入二次面试人" size="small" />
                </td>
              </tr>
              <tr>
                <td>二次面试日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="secondInterviewForm.二次面试日期"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                </td>
              </tr>
              <tr>
                <td>二次面试记录</td>
                <td>
                  <NInput
                    v-model:value="secondInterviewForm.二次面试记录"
                    type="textarea"
                    placeholder="请输入二次面试记录"
                    size="small"
                    :autosize="{ minRows: 2, maxRows: 6 }"
                  />
                </td>
              </tr>
              <tr>
                <td>
                  二次面试结果
                  <span class="text-red-500 ml-1">*</span>
                </td>
                <td>
                  <NSelect
                    v-model:value="secondInterviewForm.二次面试结果"
                    :options="options?.interviewResult || []"
                    placeholder="请选择二次面试结果"
                    size="small"
                  />
                </td>
              </tr>
              <tr>
                <td>预约培训日期</td>
                <td>
                  <NDatePicker
                    v-model:formatted-value="secondInterviewForm.预约培训日期"
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

        <!-- 详情/编辑模式 -->
        <div v-else-if="interviewDetail">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">面试信息</span>
            <div>
              <NButton
                v-if="!isEditingDetail"
                type="primary"
                size="small"
                :disabled="!interviewDetail || !interviewStore.selectedGuids.includes(String(interviewDetail.GUID))"
                @click="startEditDetail"
              >
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
                  <template v-if="isEditingDetail && field.editable">
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
                    <template v-if="field.columnName === '面试结果'">
                      <NTag :type="interviewDetail[field.columnName] === '通过' ? 'success' : 'default'" size="small">
                        {{ interviewDetail[field.columnName] || '-' }}
                      </NTag>
                    </template>
                    <template v-else>
                      {{ interviewDetail[field.columnName] || '-' }}
                    </template>
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
