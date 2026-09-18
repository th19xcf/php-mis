<script setup lang="ts">
import { ref, onMounted, computed, watch, toRef } from 'vue';
import type { TreeOption } from 'naive-ui';
import {  } from 'naive-ui';
import { useRoute } from 'vue-router';
import {
  fetchAddInterview,
  fetchUpdateInterview,
  fetchDeleteInterview,
  fetchTransferInterview,
  fetchTransferPositionInterview,
  fetchInterviewDebugTree
} from '@/service/api';
import { useInterviewStore } from '@/store/modules/interview';
import { useSplitter } from '@/hooks/business/use-splitter';
import { useTreeCheck } from '@/hooks/business/use-tree-check';
import { useWorkbenchFields } from '@/hooks/business/use-workbench-fields';
import { useDangerConfirm } from '@/hooks/business/use-danger-confirm';
import { usePersonnelTreeSearch } from '@/hooks/business/use-personnel-tree-search';
import { usePersonnelTreeIcon } from '@/hooks/business/use-personnel-tree-icon';
import { usePersonnelEditFormInit } from '@/hooks/business/use-personnel-edit-form-init';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import { useWorkbenchImport } from '@/hooks/business/use-workbench-import';
import { fetchWorkbenchPage } from '@/service/api/workbench';
import { useThemeStore } from '@/store/modules/theme';
import { WorkbenchImport } from '@/views/menu-bridge/modules/components';
import type { GridApi } from 'ag-grid-community';

const message = useMessageWithConsole();
const route = useRoute();
const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);
const interviewStore = useInterviewStore();
const { confirmDelete, confirmTransfer, confirm: confirmAction } = useDangerConfirm();

const functionCode = computed(() => {
  return String(route.query.functionCode || route.meta?.functionCode || '2016');
});

// 导入按钮可见性：与通用工作台 toolbar.import 同一逻辑（导入授权=1 且已配置导入模块）
const canImport = ref(false);

// 调试按钮可见性：与 pageMeta.toolbar.debugSql 同源（def_user.调试赋权=1 或代理登录）
const canDebug = ref(false);

const treeData = computed(() => interviewStore.treeData);
const selectedGuids = computed(() => interviewStore.selectedGuids);
const interviewDetail = computed(() => interviewStore.interviewDetail);
const options = computed(() => interviewStore.options);

// 历史投递链：detail 接口随人员详情返回（同人员编码的全部流程实例）
const applicationHistory = computed<Api.Interview.ApplicationHistoryItem[]>(
  () => (interviewDetail.value?.applicationHistory as Api.Interview.ApplicationHistoryItem[]) || []
);

const { leftWidth, isResizing, startResize } = useSplitter({
  defaultWidth: 320,
  minWidth: 200,
  maxWidth: 600,
  storageKey: 'interview-splitter-width'
});

const isAddingMode = ref(false);
const isEditingDetail = ref(false);
const isTransferMode = ref(false);
const isSecondInterviewMode = ref(false);
const isTransferPositionMode = ref(false);
const submitting = ref(false);

const addFormDynamic = ref<Record<string, any>>({});

const editDetailForm = ref<Record<string, any>>({});

const transferPositionForm = ref({
  建议岗位: '',
  转投说明: ''
});

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

const { addFields, detailFields, loadFields } = useWorkbenchFields();

const { handleCheck } = useTreeCheck<Api.Interview.InterviewTreeNode>({
  setCheckedKeys: interviewStore.setCheckedKeys,
  setSelectedGuids: interviewStore.setSelectedGuids
});

// 公共：左侧树搜索/过滤/展开（使用 store 持久化的 searchKeyword/expandedKeys）
const { filteredTreeData, handleSearch, clearSearch, handleExpandedKeysChange } =
  usePersonnelTreeSearch(treeData, {
    searchKeyword: toRef(interviewStore, 'searchKeyword'),
    expandedKeys: toRef(interviewStore, 'expandedKeys')
  });

// 公共：树节点图标
const renderPrefix = usePersonnelTreeIcon({
  root: '👥',
  region: '🏢',
  result: '📋',
  train: '📚',
  date: '📆',
  channel: '📢',
  person: '👤'
});

// 公共：编辑表单规范化
const { buildEditForm } = usePersonnelEditFormInit();

async function loadTree() {
  await interviewStore.refreshTree();
}

// 调试：打印左侧面试树加载的完整 SQL + 分段耗时到浏览器控制台
// 权限：canDebug = pageMeta.toolbar.debugSql（def_user.调试赋权=1 或代理登录）
async function handleDebugTree() {
  try {
    const { data } = await fetchInterviewDebugTree();
    if (!data) {
      message.error('调试信息为空');
      return;
    }
    console.group('%c[调试] 面试树 SQL 追踪', 'color: #fa8c16; font-weight: bold');
    console.log('%cSQL 语句：', 'color: #1890ff; font-weight: bold');
    console.log(data.sql);
    console.log('%c权限条件（属地）：', 'color: #52c41a; font-weight: bold');
    console.log(data.locationAuthzCondition);
    console.log('%c用户级属地赋权：', 'color: #13c2c2; font-weight: bold');
    console.log(data.userLocationAuth || '(空)');
    console.log('%c部门授权条件：', 'color: #eb2f96; font-weight: bold');
    console.log(data.deptAuthzCondition || '(空 → 走属地授权)');
    console.log('%c分段耗时（ms）：', 'color: #722ed1; font-weight: bold');
    console.table(data.timing);
    console.log('查询行数：', data.rowCount);
    console.log('树节点数：', data.treeNodeCount);
    console.groupEnd();
    message.success('调试信息已输出到控制台（F12 查看）');
  } catch {
    message.error('调试信息获取失败');
  }
}

// 导入功能：复用通用工作台导入弹窗与流程（后端 /workbench/import 接口按功能码通用）
// 本页面无 ag-grid 表格，gridApi 仅作为 hook 参数占位（仅在后端无导入列配置时模板下载兜底会用到）
const gridApi = ref<GridApi<Api.Workbench.QueryRecord> | null>(null);

const {
  importVisible,
  importLoading,
  importPreviewData,
  importError,
  importSuccess,
  importSoftRows,
  fileInputRef,
  importPreviewColumns,
  handleImport,
  triggerFileInput,
  handleFileSelect,
  confirmImport,
  downloadImportTemplate,
  resetImportPreview
} = useWorkbenchImport({
  gridApi,
  getFunctionCode: () => functionCode.value,
  getParams: () => '',
  getMenu1: () => '',
  getMenu2: () => '',
  reloadPage: () => {
    loadTree();
  },
  clearCache: () => {},
  notify: (type, msg) => message[type](msg)
});

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
  await loadFields(functionCode.value);

  const initialForm: Record<string, any> = {};
  addFields.value.forEach(field => {
    if (field.fieldType === '日期') {
      initialForm[field.columnName] =
        field.columnName === '预约培训日期' ? undefined : new Date().toISOString().split('T')[0];
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
  const requiredField = addFields.value.find(
    field => field.required && !addFormDynamic.value[field.columnName]?.trim()
  );
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
    await loadFields(functionCode.value);
  }

  editDetailForm.value = buildEditForm(
    interviewDetail.value as Record<string, any>,
    addFields.value,
    detailFields.value
  );
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

  const confirmed = await confirmTransfer('培训', selectedGuids.value.length, transferForm.value.参培信息);
  if (!confirmed) return;

  submitting.value = true;
  const { error } = await fetchTransferInterview({
    guids: selectedGuids.value,
    参培信息: transferForm.value.参培信息!,
    培训业务: transferForm.value.培训业务,
    培训批次: transferForm.value.培训批次,
    培训老师: transferForm.value.培训老师,
    培训开始日期: transferForm.value.培训开始日期,
    预计完成日期: transferForm.value.预计完成日期
  });
  submitting.value = false;

  if (!error) {
    message.success('转入培训成功');
    isTransferMode.value = false;
    interviewStore.clearSelection();
    await loadTree();
  }
}

function openTransferPositionModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择要转面其他岗位的人员');
    return;
  }

  transferPositionForm.value = {
    建议岗位: '',
    转投说明: ''
  };
  isTransferPositionMode.value = true;
}

function cancelTransferPositionMode() {
  isTransferPositionMode.value = false;
}

/**
 * 转面其他岗位确认提交（方案A：转投=新实例+血缘关联）
 *
 * 后端组合动作：旧面试行写转面结论 → 旧实例终止（转投其他岗位）→
 * 发新候选人码 → 新建邀约行与新实例（邀约岗位=建议岗位）→ 血缘回填。
 * 在途实例冲突时后端返回 needConfirm，二次确认后带 force 重提。
 */
async function handleTransferPositionConfirm() {
  if (!transferPositionForm.value.建议岗位) {
    message.error('请选择建议岗位');
    return;
  }
  if (!transferPositionForm.value.转投说明.trim()) {
    message.error('转投说明不能为空');
    return;
  }

  const confirmed = await confirmAction({
    title: '确认转面其他岗位',
    content: `确定要将选中的 ${selectedGuids.value.length} 名人员转面至"${transferPositionForm.value.建议岗位}"吗？转面后原流程终止，并在邀约列表生成新投递记录。`,
    dangerLevel: 'high',
    confirmText: '确认转面',
    cancelText: '取消'
  });
  if (!confirmed) return;

  submitting.value = true;
  const { error, response } = await fetchTransferPositionInterview({
    guids: selectedGuids.value,
    建议岗位: transferPositionForm.value.建议岗位,
    转投说明: transferPositionForm.value.转投说明.trim()
  });
  submitting.value = false;

  if (!error) {
    message.success('转面成功，已在邀约列表生成新投递');
    isTransferPositionMode.value = false;
    interviewStore.clearSelection();
    await loadTree();
    return;
  }

  // 在途实例二次确认：同人员编码存在其他未终止投递流程，确认后带 force 重提
  const bizData = (
    response?.data as {
      data?: {
        needConfirm?: boolean;
        confirmType?: string;
        matches?: Array<{ 候选人编码: string; 当前阶段: string; 邀约岗位: string }>;
      };
    } | undefined
  )?.data;
  if (bizData?.confirmType === 'activeInstance' && Array.isArray(bizData.matches) && bizData.matches.length > 0) {
    const lines = bizData.matches
      .map(m => `${m.候选人编码}（${m.当前阶段}${m.邀约岗位 ? `·${m.邀约岗位}` : ''}）`)
      .join('、');
    const goOn = await confirmAction({
      title: '存在进行中的投递流程',
      content: `选中人员存在未终止的投递流程：${lines}。确认继续转面后原流程将并行保留，请确认是否继续。`,
      dangerLevel: 'medium',
      confirmText: '继续转面',
      cancelText: '取消'
    });
    if (!goOn) return;

    submitting.value = true;
    const { error: forceError } = await fetchTransferPositionInterview({
      guids: selectedGuids.value,
      建议岗位: transferPositionForm.value.建议岗位,
      转投说明: transferPositionForm.value.转投说明.trim(),
      force: true
    });
    submitting.value = false;

    if (!forceError) {
      message.success('转面成功，已在邀约列表生成新投递');
      isTransferPositionMode.value = false;
      interviewStore.clearSelection();
      await loadTree();
    }
  }
}

function handleDelete() {
  if (selectedGuids.value.length === 0) {
    message.warning('请选择要删除的人员');
    return;
  }

  confirmDelete(selectedGuids.value.length, '人员').then(async confirmed => {
    if (!confirmed) return;

    const { error } = await fetchDeleteInterview(selectedGuids.value);
    if (!error) {
      message.success('删除成功');
      interviewStore.clearSelection();
      await loadTree();
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

onMounted(async () => {
  // 拉取功能权限，控制导入按钮显示（toolbar.import = 导入授权 && 导入模块已配置）
  // 同时拉取调试按钮可见性（toolbar.debugSql = 调试赋权 或代理登录）
  try {
    const { data } = await fetchWorkbenchPage(functionCode.value);
    canImport.value = data?.meta?.toolbar?.import === true;
    canDebug.value = data?.meta?.toolbar?.debugSql === true;
  } catch {
    canImport.value = false;
    canDebug.value = false;
  }

  if (!interviewStore.isLoaded) {
    interviewStore.loadTreeData();
  }
  if (!interviewStore.options) {
    interviewStore.loadOptions();
  }

  await loadFields(functionCode.value);

  addFields.value.forEach(field => {
    if (field.columnName === '属地') {
      field.objectOptions = options.value?.region || [];
    } else if (field.columnName === '招聘渠道') {
      field.objectOptions = options.value?.channel || [];
    } else if (field.columnName === '面试结果') {
      field.objectOptions = options.value?.interviewResult || [];
    }
  });
});

watch([isAddingMode, isEditingDetail, isTransferMode, isSecondInterviewMode, isTransferPositionMode], newValues => {
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
        <NSpace :size="8">
          <NButton v-if="canImport" size="small" @click="handleImport">
            <template #icon>
              <icon-mdi-upload />
            </template>
            导入
          </NButton>
          <NButton size="small" @click="loadTree">
            <template #icon>
              <icon-mdi-refresh />
            </template>
            刷新
          </NButton>
          <NButton v-if="canDebug" size="small" type="warning" @click="handleDebugTree">
            <template #icon>
              <icon-mdi-bug />
            </template>
            调试
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <div class="mb-2">
          <NInput
            v-model:value="interviewStore.searchKeyword"
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
          :expanded-keys="interviewStore.expandedKeys"
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
          <NButton type="primary" size="small" ghost @click="openTransferPositionModal">
            <template #icon>
              <icon-mdi-swap-horizontal />
            </template>
            转面
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
                  <NInput v-else v-model:value="addFormDynamic[field.columnName]" size="small" />
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

        <!-- 转面其他岗位模式（方案A：终止旧流程+新建邀约投递+血缘关联） -->
        <div v-else-if="isTransferPositionMode" class="space-y-4">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">转面其他岗位 (已选择 {{ selectedGuids.length }} 人)</span>
            <NSpace>
              <NButton type="primary" size="small" :loading="submitting" @click="handleTransferPositionConfirm">
                确认
              </NButton>
              <NButton size="small" @click="cancelTransferPositionMode">取消</NButton>
            </NSpace>
          </div>
          <NAlert type="info" :show-icon="true" class="mb-2">
            转面后原流程终止（终止原因：转投其他岗位），系统自动在邀约列表生成新投递记录（邀约岗位=建议岗位）。
          </NAlert>
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
                  建议岗位
                  <span class="text-red-500 ml-1">*</span>
                </td>
                <td>
                  <NSelect
                    v-model:value="transferPositionForm.建议岗位"
                    :options="options?.position || []"
                    size="small"
                    placeholder="请选择建议岗位"
                  />
                </td>
              </tr>
              <tr>
                <td>
                  转投说明
                  <span class="text-red-500 ml-1">*</span>
                </td>
                <td>
                  <NInput
                    v-model:value="transferPositionForm.转投说明"
                    type="textarea"
                    placeholder="请输入转投说明（如：沟通意愿、岗位匹配原因等）"
                    size="small"
                    :autosize="{ minRows: 2, maxRows: 6 }"
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
              <NButton type="primary" size="small" :loading="submitting" @click="handleSecondInterviewConfirm">
                确认
              </NButton>
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
                        <NInput v-else v-model:value="editDetailForm[field.columnName]" size="small" />
                      </template>
                    </template>
                  </template>
                  <template v-else>
                    <template v-if="field.columnName === '面试结果'">
                      <NTag
                        :type="
                          interviewDetail[field.columnName] === '通过'
                            ? 'success'
                            : interviewDetail[field.columnName] === '转面其他岗位'
                              ? 'warning'
                              : 'default'
                        "
                        size="small"
                      >
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

          <!-- 历史投递链（同人员编码的全部流程实例，转面血缘追溯） -->
          <div v-if="applicationHistory.length > 0" class="mt-4">
            <div class="flex items-center gap-8px mb-2">
              <span class="text-lg font-600">历史投递</span>
              <NTag size="small" :bordered="false">共 {{ applicationHistory.length }} 次</NTag>
            </div>
            <NTable size="small" :single-line="false">
              <thead>
                <tr>
                  <th>邀约日期</th>
                  <th>候选人编码</th>
                  <th>投递岗位</th>
                  <th>当前阶段</th>
                  <th>面试结果</th>
                  <th>面试人/日期</th>
                  <th>终止原因</th>
                  <th>建议岗位</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="h in applicationHistory" :key="h.候选人编码">
                  <td>{{ h.邀约日期 || '-' }}</td>
                  <td>
                    {{ h.候选人编码 }}
                    <NTag v-if="h.转自候选人编码" size="small" type="warning">转投</NTag>
                  </td>
                  <td>{{ h.邀约岗位 || '-' }}</td>
                  <td>
                    <NTag :type="h.当前阶段 === '终止' ? 'error' : 'info'" size="small">
                      {{ h.当前阶段 }}
                    </NTag>
                  </td>
                  <td>{{ h.面试结果 || '-' }}</td>
                  <td>{{ [h.面试人, h.面试日期].filter(Boolean).join(' / ') || '-' }}</td>
                  <td>{{ h.终止原因 || '-' }}</td>
                  <td>{{ h.建议岗位 || '-' }}</td>
                </tr>
              </tbody>
            </NTable>
          </div>
        </div>

        <NEmpty v-else description="请选择左侧人员查看详情" class="py-20" />
      </div>
    </div>

    <!-- 数据导入弹窗（与通用工作台 2020 导入功能一致） -->
    <WorkbenchImport
      v-model:visible="importVisible"
      :loading="importLoading"
      :preview-data="importPreviewData"
      :error="importError"
      :success="importSuccess"
      :preview-columns="importPreviewColumns"
      :is-dark-mode="isDarkMode"
      :soft-rows="importSoftRows"
      @trigger-file-input="triggerFileInput"
      @download-template="downloadImportTemplate"
      @reset="resetImportPreview"
      @confirm="confirmImport"
    >
      <template #file-input>
        <input
          ref="fileInputRef"
          type="file"
          accept=".xlsx,.xls,.csv"
          style="display:none"
          @change="handleFileSelect"
        />
      </template>
    </WorkbenchImport>
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
