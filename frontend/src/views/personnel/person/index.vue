<script setup lang="ts">
import { ref, onMounted, computed, toRef, watch } from 'vue';
import type { TreeOption } from 'naive-ui';
import { useRoute } from 'vue-router';
import {
  fetchAddPerson,
  fetchUpdatePerson,
  fetchDeletePerson,
  fetchPersonDedup,
  fetchMergePerson,
  fetchAddFields,
  fetchDetailFields,
  fetchBatchEditFields
} from '@/service/api';
import { usePersonStore } from '@/store/modules/person';
import { useSplitter } from '@/hooks/business/use-splitter';
import { useTreeCheck } from '@/hooks/business/use-tree-check';
import { useDangerConfirm } from '@/hooks/business/use-danger-confirm';
import { usePersonnelTreeSearch } from '@/hooks/business/use-personnel-tree-search';
import { usePersonnelTreeIcon } from '@/hooks/business/use-personnel-tree-icon';
import { usePersonnelEditFormInit } from '@/hooks/business/use-personnel-edit-form-init';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import { fetchWorkbenchPage } from '@/service/api/workbench';
import { useThemeStore } from '@/store/modules/theme';

const message = useMessageWithConsole();
const route = useRoute();
const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);
const personStore = usePersonStore();
const { confirmDelete, confirmBatch } = useDangerConfirm();

const functionCode = computed(() => {
  return String(route.query.functionCode || route.meta?.functionCode || '2060');
});

// 导入按钮可见性：与通用工作台 toolbar.import 同一逻辑
// 调试按钮可见性：def_user.调试赋权=1 或代理登录
const canImport = ref(false);
const canDebug = ref(false);

const treeData = computed(() => personStore.treeData);
const selectedGuids = computed(() => personStore.selectedGuids);
const personDetail = computed(() => personStore.personDetail);
const options = computed(() => personStore.options);

const { leftWidth, isResizing, startResize } = useSplitter({
  defaultWidth: 320,
  minWidth: 200,
  maxWidth: 600,
  storageKey: 'person-splitter-width'
});

const submitting = ref(false);

// —— 新增 / 多条修改 / 详情编辑 共用状态 ——
const isAddingMode = computed(() => personStore.isAddingMode);
const addFormDynamic = computed({
  get: () => personStore.addFormDynamic,
  set: val => personStore.setAddFormDynamic(val)
});
const addFields = computed(() => personStore.addFields);
const detailFields = ref<Api.Workbench.DetailField[]>([]);
const isEditingDetail = ref(false);
const editDetailForm = ref<Record<string, any>>({});
const isBatchEditMode = computed(() => personStore.isBatchEditMode);
const batchEditForm = computed({
  get: () => personStore.batchEditForm,
  set: val => personStore.setBatchEditForm(val)
});
const batchEditFields = computed(() => personStore.batchEditFields);

// —— 合并 / 查重动作 ——
const mergeVisible = ref(false);
const mergeForm = ref({ sourceCode: '', targetCode: '' });
const mergeSubmitting = ref(false);
const mergePreview = ref('');

// 查重提示（详情页查重按钮）
const dedupMatches = ref<Api.Person.PersonDedupMatch[]>([]);
const dedupVisible = ref(false);

// 新增时查重软命中确认
const addDedupVisible = ref(false);
const addDedupMatches = ref<Api.Person.PersonDedupMatch[]>([]);
const addDedupChoice = ref('new');

const { handleCheck } = useTreeCheck<Api.Person.PersonTreeNode>({
  setCheckedKeys: personStore.setCheckedKeys,
  setSelectedGuids: personStore.setSelectedGuids
});

const { filteredTreeData, handleSearch, clearSearch, handleExpandedKeysChange } =
  usePersonnelTreeSearch(treeData, {
    searchKeyword: toRef(personStore, 'searchKeyword'),
    expandedKeys: toRef(personStore, 'expandedKeys')
  });

const renderPrefix = usePersonnelTreeIcon({
  root: '👥',
  region: '🏢',
  result: '📋',
  interview: '📅',
  date: '📆',
  channel: '📢',
  person: '👤'
});

const { buildEditForm } = usePersonnelEditFormInit();

async function loadTree() {
  await personStore.refreshTree();
}

function handleSelect(keys: string[], optionNodes: (TreeOption | null)[]) {
  if (keys.length === 0) return;

  const key = keys[0];
  const node = optionNodes.find(n => n?.key === key);
  if (node) {
    const data = node.data as Api.Person.PersonTreeNode;
    if (data.type === 'person' && data.guid) {
      personStore.loadPersonDetail(data.guid);
    } else {
      personStore.personDetail = null;
    }
  }
}

async function openAddModal() {
  const { data } = await fetchAddFields(functionCode.value);
  if (data?.fields) {
    personStore.setAddFields(data.fields);
    const formData: Record<string, any> = {};
    data.fields.forEach((field: Api.Workbench.AddField) => {
      if (field.fieldType === '日期') {
        formData[field.columnName] = field.defaultValue || null;
      } else {
        formData[field.columnName] = field.defaultValue || '';
      }
    });
    personStore.setAddFormDynamic(formData);
  }
  personStore.setAddingMode(true);
}

function cancelAddMode() {
  personStore.clearAddState();
}

async function saveAddMode() {
  const requiredField = addFields.value.find(
    (f: Api.Workbench.AddField) => f.required && !addFormDynamic.value[f.columnName]
  );
  if (requiredField) {
    message.error(`${requiredField.fieldName}不能为空`);
    return;
  }

  submitting.value = true;
  const { data: dedupData, error: dedupError } = await fetchPersonDedup({
    姓名: String(addFormDynamic.value['姓名'] || ''),
    手机号码: String(addFormDynamic.value['手机号码'] || ''),
    身份证号: String(addFormDynamic.value['身份证号'] || '')
  });
  submitting.value = false;
  if (dedupError) return;

  if (dedupData?.level === 'soft' && dedupData.matches?.length) {
    addDedupMatches.value = dedupData.matches;
    addDedupChoice.value = 'new';
    addDedupVisible.value = true;
    return;
  }

  await doSubmitAdd();
}

async function doSubmitAdd(extra?: { person_code?: string; force_new?: boolean }): Promise<boolean> {
  submitting.value = true;
  const { error, response } = await fetchAddPerson({
    ...addFormDynamic.value,
    ...extra
  } as Api.Person.PersonAddParams);
  submitting.value = false;

  if (!error) {
    message.success('新增人员主档成功');
    personStore.clearAddState();
    addDedupVisible.value = false;
    await loadTree();
    return true;
  }

  const bizData = (
    response?.data as { data?: { needConfirm?: boolean; matches?: Api.Person.PersonDedupMatch[] } } | undefined
  )?.data;
  if (bizData?.needConfirm && Array.isArray(bizData.matches) && bizData.matches.length > 0) {
    addDedupMatches.value = bizData.matches;
    addDedupChoice.value = 'new';
    addDedupVisible.value = true;
  }
  return false;
}

async function handleAddDedupConfirm() {
  if (!addDedupChoice.value) {
    message.warning('请选择处理方式');
    return;
  }
  const extra =
    addDedupChoice.value === 'new' ? { force_new: true } : { person_code: addDedupChoice.value };
  await doSubmitAdd(extra);
}

async function openBatchEditModal() {
  if (selectedGuids.value.length === 0) {
    message.warning('请先选择要修改的人员主档');
    return;
  }

  const { data } = await fetchBatchEditFields(functionCode.value);
  if (data?.fields) {
    const formData: Record<string, any> = {};
    data.fields.forEach((field: Api.Workbench.AddField) => {
      if (field.fieldType === '日期') {
        formData[field.columnName] = field.defaultValue || null;
      } else {
        formData[field.columnName] = field.defaultValue || '';
      }
    });
    personStore.setBatchEditFields(data.fields);
    personStore.setBatchEditForm(formData);
    personStore.setBatchEditMode(true);
  }
}

function cancelBatchEditMode() {
  personStore.clearBatchEditState();
}

async function saveBatchEditMode() {
  if (selectedGuids.value.length === 0) {
    message.warning('请先选择要修改的人员主档');
    return;
  }

  const confirmed = await confirmBatch('修改', selectedGuids.value.length);
  if (!confirmed) return;

  submitting.value = true;

  let successCount = 0;
  let failCount = 0;
  for (const guid of selectedGuids.value) {
    const { error } = await fetchUpdatePerson({
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
    personStore.clearBatchEditState();
    await loadTree();
  } else {
    message.warning(`成功 ${successCount} 条，失败 ${failCount} 条`);
  }
}

async function startEditDetail() {
  if (!personDetail.value) {
    message.warning('请先选择要编辑的人员主档');
    return;
  }

  if (!addFields.value || addFields.value.length === 0) {
    const { data } = await fetchAddFields(functionCode.value);
    if (data?.fields) {
      personStore.setAddFields(data.fields);
    }
  }

  editDetailForm.value = buildEditForm(
    personDetail.value as Record<string, any>,
    addFields.value,
    detailFields.value
  );
  isEditingDetail.value = true;
}

function cancelDetailEdit() {
  isEditingDetail.value = false;
  editDetailForm.value = {};
}

async function saveDetailEdit() {
  if (!personDetail.value) return;

  submitting.value = true;
  const { error } = await fetchUpdatePerson({
    guid: personDetail.value.GUID,
    ...editDetailForm.value
  });
  submitting.value = false;

  if (!error) {
    message.success('修改成功');
    isEditingDetail.value = false;
    await personStore.loadPersonDetail(String(personDetail.value.GUID));
  }
}

function handleDelete() {
  if (selectedGuids.value.length === 0) {
    message.warning('请先选择要删除的人员主档');
    return;
  }

  confirmDelete(selectedGuids.value.length, '人员主档').then(async confirmed => {
    if (!confirmed) return;

    const { error } = await fetchDeletePerson(selectedGuids.value);
    if (!error) {
      message.success('删除成功');
      personStore.clearSelection();
      await loadTree();
    }
  });
}

// —— 详情页：合并 / 查重 ——
function openMerge() {
  if (!personDetail.value) {
    message.warning('请先在左侧选择一个人员主档作为源');
    return;
  }
  mergeForm.value = { sourceCode: personDetail.value.人员编码, targetCode: '' };
  mergePreview.value = '';
  mergeVisible.value = true;
}

async function checkMergeTarget() {
  const targetCode = mergeForm.value.targetCode.trim();
  if (!targetCode) {
    message.warning('请输入目标人员编码');
    return;
  }
  try {
    // 主档详情接口优先按 GUID 查，再兜底按人员编码。此处通过 personDetail 的下拉核对手动复用详情接口：
    // 由于 hr_person 可能只暴露 GUID 版本，直接尝试查找（失败时由错误提示兜底）。
    // 使用 fetchPersonOptions 不便，改为调用 /person/dedup 精确命中：
    mergePreview.value = '正在核对目标主档...';
    const { data } = await fetchPersonDedup({
      姓名: '*',
      手机号码: '0',
      身份证号: targetCode
    });
    if (data?.person) {
      mergePreview.value = `目标主档：${data.person.姓名}（${data.person.人员编码}）手机：${data.person.手机号码 || '-'}`;
    } else {
      mergePreview.value = `未找到编码/证件号为 ${targetCode} 的主档，请直接输入人员编码`;
    }
  } catch (e: any) {
    mergePreview.value = `核对失败：${e?.message || '未知错误'}`;
  }
}

async function submitMerge() {
  const { sourceCode, targetCode } = mergeForm.value;
  if (!sourceCode || !targetCode.trim()) {
    message.warning('源编码和目标编码不能为空');
    return;
  }
  if (sourceCode === targetCode.trim()) {
    message.warning('源编码与目标编码不能相同');
    return;
  }
  mergeSubmitting.value = true;
  try {
    const { error } = await fetchMergePerson({ sourceCode, targetCode: targetCode.trim() });
    if (!error) {
      message.success('合并成功');
      mergeVisible.value = false;
      personStore.clearSelection();
      await loadTree();
    }
  } finally {
    mergeSubmitting.value = false;
  }
}

async function handleDedup() {
  if (!personDetail.value) return;
  const p = personDetail.value;
  try {
    const { data } = await fetchPersonDedup({
      姓名: p.姓名,
      手机号码: p.手机号码,
      身份证号: p.身份证号 || undefined
    });
    if (data) {
      if (data.level === 'none' || !data.matches?.length) {
        message.info('未发现疑似重复主档');
      } else {
        dedupMatches.value = data.matches.filter(m => m.人员编码 !== p.人员编码);
        if (dedupMatches.value.length === 0) {
          message.info('未发现其它疑似重复主档');
        } else {
          dedupVisible.value = true;
        }
      }
    }
  } catch {
    message.error('查重失败');
  }
}

// 下拉对象字段（popup）点击占位：保持与 2015 一致，后续接入通用弹窗
function handlePopupSelect(field: Api.Workbench.AddField) {
  message.info(`打开${field.fieldName}选择弹窗`);
}

onMounted(async () => {
  // 2060 首屏 4 条预加载请求：统一加 skipAuthError=true。若某个请求因为旧 PHP 进程、
  // 代理转发异常或 MetadataCache 冷启动而返回 401/8888，前端不主动 resetStore，
  // 以免把仍在队列里的其他请求连锁打挂（典型：/person/options 先 401，随即
  // /workbench/add-fields 报未登录）。真正的登录过期等用户点按钮或切路由时
  // 再由全局业务校验统一引导到登录页即可。
  const silentAuthCfg = { skipErrorToast: true, skipAuthError: true };

  // 1) 工具栏权限（import / debug）：若 2060 的工作台配置尚未落地，
  //    静默降级为 false，不阻止后续 tree / options 加载。
  try {
    const { data } = await fetchWorkbenchPage(functionCode.value, silentAuthCfg);
    canImport.value = data?.meta?.toolbar?.import === true;
    canDebug.value = data?.meta?.toolbar?.debugSql === true;
  } catch (e) {
    canImport.value = false;
    canDebug.value = false;
    // eslint-disable-next-line no-console
    console.warn(`[person] fetchWorkbenchPage(${functionCode.value}) failed，降级导入/调试按钮为隐藏`, e);
  }

  // 2) 左树 + 下拉：并行加载，各自独立失败互不阻塞
  if (!personStore.isLoaded) {
    // 兜底：loadTreeData 内部通过 fetchTree 的 {error} 返回值传递，
    // 但极端情况下若 Promise 直接 reject（如网关超时），在此吞掉异常，
    // 保证 detailFields 仍可加载，页面不至于空白。
    try {
      await personStore.loadTreeData();
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn('[person] loadTreeData failed', e);
    }
  }
  personStore.loadOptions(); // 内部已 catch，且已 skipErrorToast / skipAuthError，无需 await

  // 3) 详情/新增字段：若 def_query_config / def_query_column 尚未完全到位，
  //    降级为空数组，右侧会显示空的详情 / 新增字段面板，不阻断左树浏览。
  try {
    const { data } = await fetchDetailFields(functionCode.value, silentAuthCfg);
    if (data?.fields) {
      detailFields.value = data.fields;
    }
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn(`[person] fetchDetailFields(${functionCode.value}) failed`, e);
  }

  // 4) 新增字段：按需加载（首次点"新增"时 fetchAddFields 拉取），
  //    但为了详情编辑可直接复用 addFields，这里提前预加载一次（非阻塞）。
  try {
    const { data } = await fetchAddFields(functionCode.value, silentAuthCfg);
    if (data?.fields && !addFields.value.length) {
      personStore.setAddFields(data.fields);
    }
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn(`[person] fetchAddFields(${functionCode.value}) preload failed`, e);
  }
});
</script>

<template>
  <div class="person-container">
    <div class="person-panel person-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <div class="flex items-center gap-12px">
          <span class="text-lg font-600">人员主档</span>
          <NTag type="success" size="small">{{ functionCode }}</NTag>
        </div>
        <NSpace :size="8">
          <NButton v-if="canImport" size="small" disabled @click="message.info('主档导入将在后续版本开放')">
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
          <NButton
            v-if="canDebug"
            size="small"
            type="warning"
            @click="message.info('调试追踪（主档树）：请在浏览器 Network 面板查看 /person/tree 的 X-Server-Trace 响应头')"
          >
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
            v-model:value="personStore.searchKeyword"
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
          :checked-keys="personStore.checkedKeys"
          :expanded-keys="personStore.expandedKeys"
          @update:checked-keys="handleCheck"
          @update:selected-keys="handleSelect"
          @update:expanded-keys="handleExpandedKeysChange"
        />
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="person-panel person-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">主档信息</span>
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
          <NButton type="error" size="small" @click="handleDelete">
            <template #icon>
              <icon-mdi-delete />
            </template>
            删除
          </NButton>
        </NSpace>
      </div>
      <div class="panel-content">
        <!-- 多条修改模式 -->
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
                  <NSelect
                    v-if="field.objectOptions && field.objectOptions.length > 0"
                    v-model:value="batchEditForm[field.columnName]"
                    :options="field.objectOptions"
                    size="small"
                  />
                  <NDatePicker
                    v-else-if="field.fieldType === '日期'"
                    v-model:formatted-value="batchEditForm[field.columnName]"
                    value-format="yyyy-MM-dd"
                    type="date"
                    size="small"
                    class="w-full"
                  />
                  <NInput
                    v-else-if="field.inputType === 'popup'"
                    v-model:value="batchEditForm[field.columnName]"
                    size="small"
                    readonly
                    @click="handlePopupSelect(field)"
                  />
                  <NInput v-else v-model:value="batchEditForm[field.columnName]" size="small" />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 新增模式 -->
        <div v-else-if="isAddingMode">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">新增人员主档</span>
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
                  <NSelect
                    v-if="field.objectOptions && field.objectOptions.length > 0"
                    v-model:value="addFormDynamic[field.columnName]"
                    :options="field.objectOptions"
                    size="small"
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
                    v-else-if="field.inputType === 'popup'"
                    v-model:value="addFormDynamic[field.columnName]"
                    size="small"
                    readonly
                    @click="handlePopupSelect(field)"
                  />
                  <NInput
                    v-else-if="field.columnName === '工作履历'"
                    v-model:value="addFormDynamic[field.columnName]"
                    type="textarea"
                    size="small"
                    :autosize="{ minRows: 2, maxRows: 10 }"
                  />
                  <NInput v-else v-model:value="addFormDynamic[field.columnName]" size="small" />
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <!-- 详情/编辑模式 -->
        <div v-else-if="personDetail">
          <div class="flex justify-between items-center mb-2">
            <span class="text-lg font-600">主档信息</span>
            <div>
              <NSpace>
                <NButton size="small" type="info" @click="handleDedup">
                  <template #icon>
                    <icon-mdi-file-find-outline />
                  </template>
                  查重
                </NButton>
                <NButton size="small" type="warning" @click="openMerge">
                  <template #icon>
                    <icon-mdi-merge />
                  </template>
                  合并
                </NButton>
                <template v-if="!isEditingDetail">
                  <NButton
                    type="primary"
                    size="small"
                    :disabled="!personDetail || !personStore.selectedGuids.includes(String(personDetail.GUID))"
                    @click="startEditDetail"
                  >
                    <template #icon>
                      <icon-mdi-pencil />
                    </template>
                    编辑
                  </NButton>
                </template>
                <template v-else>
                  <NButton type="primary" size="small" :loading="submitting" @click="saveDetailEdit">保存</NButton>
                  <NButton size="small" @click="cancelDetailEdit">取消</NButton>
                </template>
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
                          v-else-if="addField.inputType === 'popup'"
                          v-model:value="editDetailForm[field.columnName]"
                          size="small"
                          readonly
                          @click="handlePopupSelect(addField)"
                        />
                        <NInput
                          v-else-if="field.columnName === '工作履历'"
                          v-model:value="editDetailForm[field.columnName]"
                          type="textarea"
                          size="small"
                          :autosize="{ minRows: 2, maxRows: 10 }"
                        />
                        <NInput v-else v-model:value="editDetailForm[field.columnName]" size="small" />
                      </template>
                    </template>
                  </template>
                  <template v-else>
                    <template v-if="field.columnName === '合并至' && personDetail[field.columnName]">
                      <NTag type="warning" size="small">已合并至 {{ personDetail[field.columnName] }}</NTag>
                    </template>
                    <template v-else-if="field.columnName === '工作履历'">
                      <span style="white-space: pre-wrap; word-break: break-all; line-height: 1.6">
                        {{ (personDetail as any)[field.columnName] || '-' }}
                      </span>
                    </template>
                    <template v-else>
                      {{ (personDetail as any)[field.columnName] || '-' }}
                    </template>
                  </template>
                </td>
              </tr>
            </tbody>
          </NTable>
        </div>

        <NEmpty v-else description="请选择左侧人员主档查看详情或点击新增" class="py-20" />
      </div>
    </div>

    <!-- 新增查重软命中确认 -->
    <NModal
      v-model:show="addDedupVisible"
      preset="card"
      title="疑似重复人员"
      class="w-160"
      :mask-closable="false"
    >
      <NAlert type="warning" :show-icon="true" class="mb-12px">
        系统中存在与本次新增人员疑似同一人的档案（姓名+手机号码匹配）。若为同一人请选择挂接既有档案；若确认不是同一人请选择新建。
      </NAlert>
      <NRadioGroup v-model:value="addDedupChoice">
        <NSpace vertical>
          <NRadio v-for="m in addDedupMatches" :key="m.人员编码" :value="m.人员编码">
            {{ m.姓名 }}｜{{ m.人员编码 }}｜{{ m.手机号码 }}｜{{ m.身份证号 || '无证件号'
            }}{{ m.属地 ? `｜${m.属地}` : '' }}
          </NRadio>
          <NRadio value="new">以上都不是，新建人员档案</NRadio>
        </NSpace>
      </NRadioGroup>
      <template #footer>
        <NSpace justify="end">
          <NButton size="small" @click="addDedupVisible = false">取消</NButton>
          <NButton type="primary" size="small" :loading="submitting" @click="handleAddDedupConfirm">
            确认提交
          </NButton>
        </NSpace>
      </template>
    </NModal>

    <!-- 详情页查重结果 -->
    <NModal v-model:show="dedupVisible" preset="card" title="疑似重复主档清单" class="w-160">
      <NAlert type="warning" :show-icon="true" class="mb-12px">
        以下主档与当前人员主档疑似重复。如需合并，请关闭本弹窗后点击「合并」按钮，输入目标编码进行合并。
      </NAlert>
      <NTable size="small" :single-line="false">
        <thead>
          <tr>
            <th>人员编码</th>
            <th>姓名</th>
            <th>手机号码</th>
            <th>身份证号</th>
            <th>属地</th>
            <th>性别</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in dedupMatches" :key="m.人员编码">
            <td>{{ m.人员编码 }}</td>
            <td>{{ m.姓名 }}</td>
            <td>{{ m.手机号码 }}</td>
            <td>{{ m.身份证号 || '-' }}</td>
            <td>{{ m.属地 || '-' }}</td>
            <td>{{ m.性别 || '-' }}</td>
          </tr>
        </tbody>
      </NTable>
      <template #footer>
        <NSpace justify="end">
          <NButton type="primary" size="small" @click="dedupVisible = false">知道了</NButton>
        </NSpace>
      </template>
    </NModal>

    <!-- 合并弹窗 -->
    <NModal
      v-model:show="mergeVisible"
      preset="dialog"
      title="重档合并"
      positive-text="确认合并"
      negative-text="取消"
      :loading="mergeSubmitting"
      @positive-click="submitMerge"
    >
      <NSpace vertical>
        <NAlert type="warning" :show-icon="true">
          源主档将被置无效（合并至=目标编码），其下游邀约/面试/培训/在职记录的人员编码将全部改为目标编码。此操作可回溯（hr_audit_log 有记录）。
        </NAlert>
        <div class="n-form-item n-form-item--left-labelled">
          <div class="n-form-item-label" style="width: 84px">源编码</div>
          <div class="n-form-item-blank">
            <NInput v-model:value="mergeForm.sourceCode" disabled />
          </div>
        </div>
        <div class="n-form-item n-form-item--left-labelled">
          <div class="n-form-item-label" style="width: 84px">目标编码</div>
          <div class="n-form-item-blank">
            <NInput v-model:value="mergeForm.targetCode" placeholder="输入合并保留方的人员编码" />
          </div>
        </div>
        <NButton size="small" @click="checkMergeTarget">核对目标主档</NButton>
        <div v-if="mergePreview" class="merge-preview">{{ mergePreview }}</div>
      </NSpace>
    </NModal>
  </div>
</template>

<style scoped>
:deep(.n-tree-node-content) {
  padding: 4px 0;
}

.person-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.person-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}

.person-panel-left {
  flex-shrink: 0;
}

.person-panel-right {
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

.merge-preview {
  padding: 6px 10px;
  background: var(--n-color-embedded, #f6f6f6);
  border-radius: 4px;
  font-size: 13px;
}

html.dark .person-panel {
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
