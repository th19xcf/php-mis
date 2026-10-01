<script setup lang="ts">
import { h, ref, watch, computed, reactive } from 'vue';
import { NModal, NForm, NFormItem, NInput, NSelect, NInputNumber, NDatePicker, NSpace, NButton, useDialog } from 'naive-ui';
import { useContractStore } from '@/store/modules/contract';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import {
  fetchContractUploadDocument,
  fetchContractDeleteDocument,
  fetchContractDownloadDocument
} from '@/service/api/contract';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  contract: Api.Contract.ContractDetail | null;
  inline?: boolean;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
  openEditor: [docId: number, docName: string];
}>();

const message = useMessageWithConsole();
const dialog = useDialog();
const contractStore = useContractStore();

const loading = computed(() => contractStore.loading);

const formData = ref({
  合同名称: '',
  合同类型: '',
  甲方名称: '',
  甲方联系人: '',
  甲方电话: '',
  乙方名称: '',
  乙方联系人: '',
  乙方电话: '',
  合同金额: 0,
  签订日期: '',
  开始日期: '',
  结束日期: '',
  付款方式: '',
  币别: 'CNY',
  汇率: 1,
  备注: ''
});

const contractFiles = ref<Api.Contract.ContractDocument[]>([]);
const approvalFiles = ref<Api.Contract.ContractDocument[]>([]);
const uploading = ref(false);
const mainFileInput = ref<HTMLInputElement | null>(null);
const approvalFileInput = ref<HTMLInputElement | null>(null);

function triggerUpload(docType: 'MAIN' | 'APPROVAL_FORM') {
  const input = docType === 'MAIN' ? mainFileInput.value : approvalFileInput.value;
  input?.click();
}

// 可在线编辑的文件格式
const editableExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

function isEditableDoc(doc: Api.Contract.ContractDocument): boolean {
  const ext = (doc.文档格式 || '').toLowerCase();
  return editableExts.includes(ext);
}

const rules = {
  合同名称: { required: true, message: '请输入合同名称' },
  甲方名称: { required: true, message: '请输入甲方名称' },
  乙方名称: { required: true, message: '请输入乙方名称' }
};

watch(
  () => props.visible,
  (val) => {
    if (val) {
      if (props.mode === 'edit' && props.contract) {
        formData.value = {
          合同名称: props.contract.合同名称 || '',
          合同类型: props.contract.合同类型 || '',
          甲方名称: props.contract.甲方名称 || '',
          甲方联系人: props.contract.甲方联系人 || '',
          甲方电话: props.contract.甲方电话 || '',
          乙方名称: props.contract.乙方名称 || '',
          乙方联系人: props.contract.乙方联系人 || '',
          乙方电话: props.contract.乙方电话 || '',
          合同金额: props.contract.合同金额 || 0,
          签订日期: props.contract.签订日期 || '',
          开始日期: props.contract.开始日期 || '',
          结束日期: props.contract.结束日期 || '',
          付款方式: props.contract.付款方式 || '',
          币别: props.contract.币别 || 'CNY',
          汇率: props.contract.汇率 || 1,
          备注: props.contract.备注 || ''
        };
        const docs = props.contract.documents || [];
        contractFiles.value = docs.filter(d => d.文档类型 === 'MAIN');
        approvalFiles.value = docs.filter(d => d.文档类型 === 'APPROVAL_FORM');
      } else {
        formData.value = {
          合同名称: '',
          合同类型: '',
          甲方名称: '',
          甲方联系人: '',
          甲方电话: '',
          乙方名称: '',
          乙方联系人: '',
          乙方电话: '',
          合同金额: 0,
          签订日期: '',
          开始日期: '',
          结束日期: '',
          付款方式: '',
          币别: 'CNY',
          汇率: 1,
          备注: ''
        };
        contractFiles.value = [];
        approvalFiles.value = [];
      }
      formSnapshot.value = serializeForm();
    }
  },
  { immediate: true }
);

/** 表单快照（用于判断是否有未保存的修改） */
const formSnapshot = ref('');

function serializeForm() {
  return JSON.stringify({ ...formData.value, contractFiles: contractFiles.value, approvalFiles: approvalFiles.value });
}

function formDirty() {
  return serializeForm() !== formSnapshot.value;
}

function handleClose() {
  emit('update:visible', false);
}

/** 用户请求关闭弹窗（遮罩/关闭按钮/取消按钮）：有未保存修改时提示是否保存 */
function requestClose() {
  if (!formDirty()) {
    handleClose();
    return;
  }
  const d = dialog.warning({
    title: '未保存提示',
    content: '当前填写内容尚未保存，是否保存？',
    action: () =>
      h('div', { style: 'display: flex; gap: 8px; margin-left: auto;' }, [
        h(
          NButton,
          { size: 'small', quaternary: true, onClick: () => d.destroy() },
          { default: () => '继续编辑' }
        ),
        h(
          NButton,
          {
            size: 'small',
            onClick: () => {
              d.destroy();
              handleClose();
            }
          },
          { default: () => '不保存' }
        ),
        h(
          NButton,
          {
            size: 'small',
            type: 'primary',
            onClick: () => {
              d.destroy();
              handleSubmit();
            }
          },
          { default: () => '保存' }
        )
      ])
  });
}

async function handleSubmit() {
  if (!formData.value.合同名称) {
    message.error('请输入合同名称');
    return;
  }
  if (!formData.value.甲方名称) {
    message.error('请输入甲方名称');
    return;
  }
  if (!formData.value.乙方名称) {
    message.error('请输入乙方名称');
    return;
  }

  try {
    if (props.mode === 'create') {
      await contractStore.createContract(formData.value as any);
      message.success('创建成功');
    } else {
      if (!props.contract) return;
      await contractStore.updateContract({
        ...(formData.value as any),
        contractNo: props.contract.合同编号
      });
      message.success('更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

const options = computed(() => contractStore.options);

const currentContractNo = computed(() => {
  if (props.mode === 'edit' && props.contract) {
    return props.contract.合同编号;
  }
  return contractStore.currentContract?.合同编号 || '';
});

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

async function handleFileUpload(event: Event, docType: 'MAIN' | 'APPROVAL_FORM') {
  const target = event.target as HTMLInputElement;
  const file = target.files?.[0];
  if (!file) return;

  if (!currentContractNo.value) {
    message.warning('请先保存合同基础信息后再上传文件');
    target.value = '';
    return;
  }

  if (file.size > 50 * 1024 * 1024) {
    message.error('文件大小不能超过50MB');
    target.value = '';
    return;
  }

  uploading.value = true;
  try {
    const result = await fetchContractUploadDocument({
      contractNo: currentContractNo.value,
      docType,
      file
    });
    if (docType === 'MAIN') {
      contractFiles.value.push(result as any);
    } else {
      approvalFiles.value.push(result as any);
    }
    message.success('上传成功');
    contractStore.loadContractDetail(currentContractNo.value);
  } catch (e: any) {
    message.error(e?.message || '上传失败');
  } finally {
    uploading.value = false;
    target.value = '';
  }
}

async function handleDeleteFile(doc: Api.Contract.ContractDocument, docType: 'MAIN' | 'APPROVAL_FORM') {
  try {
    await fetchContractDeleteDocument(doc.GUID);
    if (docType === 'MAIN') {
      contractFiles.value = contractFiles.value.filter(d => d.GUID !== doc.GUID);
    } else {
      approvalFiles.value = approvalFiles.value.filter(d => d.GUID !== doc.GUID);
    }
    message.success('删除成功');
    contractStore.loadContractDetail(currentContractNo.value);
  } catch (e: any) {
    message.error(e?.message || '删除失败');
  }
}

function handleDownload(doc: Api.Contract.ContractDocument) {
  if (isEditableDoc(doc)) {
    emit('openEditor', doc.GUID, doc.文档名称);
  } else {
    doDownload(doc);
  }
}

// 编辑文件：打开 OnlyOffice 编辑器
function handleEditFile(doc: Api.Contract.ContractDocument) {
  emit('openEditor', doc.GUID, doc.文档名称);
}

// 通用下载：带 Authorization 头的 fetch 请求，避免 JWT 过滤器返回 JSON 错误
async function doDownload(doc: Api.Contract.ContractDocument) {
  try {
    const { blob, filename } = await fetchContractDownloadDocument(doc.GUID);
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  } catch (e: any) {
    message.error(e?.message || '下载失败');
  }
}

// 导出文件：带认证头的下载
function handleExportFile(doc: Api.Contract.ContractDocument) {
  doDownload(doc);
}

// 日期字符串与 timestamp 互转（NDatePicker 需要 timestamp）
function dateToTs(s: string): number | null {
  if (!s) return null;
  const t = new Date(s).getTime();
  return isNaN(t) ? null : t;
}
function tsToDate(ts: number | null): string {
  if (ts === null || ts === undefined) return '';
  const d = new Date(ts);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const 签订日期Ts = computed({ get: () => dateToTs(formData.value.签订日期), set: (v: number | null) => { formData.value.签订日期 = tsToDate(v); } });
const 开始日期Ts = computed({ get: () => dateToTs(formData.value.开始日期), set: (v: number | null) => { formData.value.开始日期 = tsToDate(v); } });
const 结束日期Ts = computed({ get: () => dateToTs(formData.value.结束日期), set: (v: number | null) => { formData.value.结束日期 = tsToDate(v); } });

// NSelect options（确保是 {label, value} 格式）
const 合同类型Options = computed(() => (options.value.合同类型 || []).map((o: any) => ({ label: o.label, value: o.value })));
const 付款方式Options = computed(() => (options.value.付款方式 || []).map((o: any) => ({ label: o.label, value: o.value })));
const 币别Options = computed(() => (options.value.币别 || []).map((o: any) => ({ label: o.label, value: o.value })));

// 暴露 submit 方法供父组件内联调用
defineExpose({
  submit: handleSubmit
});
</script>

<template>
  <!-- 内联模式（右侧面板直接编辑，参照面试人员维护的表格化布局） -->
  <div v-if="inline && visible" class="inline-form">
    <div class="edit-table">
      <div class="edit-row edit-head">
        <div class="edit-cell edit-cell-name">列名</div>
        <div class="edit-cell edit-cell-value">列值</div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">合同名称<span class="required-mark">*</span></div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.合同名称" placeholder="请输入合同名称" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">合同类型</div>
        <div class="edit-cell edit-cell-value"><NSelect v-model:value="formData.合同类型" :options="合同类型Options" placeholder="请选择" size="small" clearable /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">甲方名称<span class="required-mark">*</span></div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.甲方名称" placeholder="请输入甲方名称" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">甲方联系人</div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.甲方联系人" placeholder="请输入甲方联系人" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">甲方电话</div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.甲方电话" placeholder="请输入甲方电话" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">乙方名称<span class="required-mark">*</span></div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.乙方名称" placeholder="请输入乙方名称" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">乙方联系人</div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.乙方联系人" placeholder="请输入乙方联系人" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">乙方电话</div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.乙方电话" placeholder="请输入乙方电话" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">合同金额</div>
        <div class="edit-cell edit-cell-value"><NInputNumber v-model:value="formData.合同金额" placeholder="请输入金额" size="small" :precision="2" class="w-full" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">付款方式</div>
        <div class="edit-cell edit-cell-value"><NSelect v-model:value="formData.付款方式" :options="付款方式Options" placeholder="请选择" size="small" clearable /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">币别</div>
        <div class="edit-cell edit-cell-value"><NSelect v-model:value="formData.币别" :options="币别Options" placeholder="请选择" size="small" clearable /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">汇率</div>
        <div class="edit-cell edit-cell-value"><NInputNumber v-model:value="formData.汇率" placeholder="请输入汇率" size="small" :precision="4" :step="0.0001" class="w-full" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">签订日期</div>
        <div class="edit-cell edit-cell-value"><NDatePicker v-model:value="签订日期Ts" type="date" size="small" clearable class="w-full" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">开始日期</div>
        <div class="edit-cell edit-cell-value"><NDatePicker v-model:value="开始日期Ts" type="date" size="small" clearable class="w-full" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">结束日期</div>
        <div class="edit-cell edit-cell-value"><NDatePicker v-model:value="结束日期Ts" type="date" size="small" clearable class="w-full" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">备注</div>
        <div class="edit-cell edit-cell-value"><NInput v-model:value="formData.备注" type="textarea" :rows="2" placeholder="请输入备注" size="small" /></div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">合同文件</div>
        <div class="edit-cell edit-cell-value">
          <div v-if="mode === 'create'" class="upload-tip">请先保存合同基础信息后再上传文件</div>
          <div v-else class="upload-area">
            <NButton size="small" :loading="uploading" @click="triggerUpload('MAIN')">
              <template #icon><icon-mdi-upload /></template>
              上传合同文件
            </NButton>
            <input
              ref="mainFileInput"
              type="file"
              class="hidden-file-input"
              :disabled="uploading"
              @change="e => handleFileUpload(e, 'MAIN')"
            />
            <div class="file-list">
                <div v-for="file in contractFiles" :key="file.GUID" class="file-item">
                  <span class="file-name">{{ file.文档名称 }}</span>
                  <span class="file-size">{{ formatFileSize(file.文件大小) }}</span>
                  <div class="file-actions">
                    <NButton v-if="isEditableDoc(file)" size="tiny" quaternary type="info" @click="handleEditFile(file)">编辑</NButton>
                    <NButton size="tiny" quaternary type="primary" @click="handleExportFile(file)">导出</NButton>
                    <NButton size="tiny" quaternary type="error" @click="handleDeleteFile(file, 'MAIN')">删除</NButton>
                  </div>
                </div>
              </div>
          </div>
        </div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">合同审批表</div>
        <div class="edit-cell edit-cell-value">
          <div v-if="mode === 'create'" class="upload-tip">请先保存合同基础信息后再上传文件</div>
          <div v-else class="upload-area">
            <NButton size="small" :loading="uploading" @click="triggerUpload('APPROVAL_FORM')">
              <template #icon><icon-mdi-upload /></template>
              上传审批表
            </NButton>
            <input
              ref="approvalFileInput"
              type="file"
              class="hidden-file-input"
              :disabled="uploading"
              @change="e => handleFileUpload(e, 'APPROVAL_FORM')"
            />
            <div class="file-list">
                <div v-for="file in approvalFiles" :key="file.GUID" class="file-item">
                  <span class="file-name">{{ file.文档名称 }}</span>
                  <span class="file-size">{{ formatFileSize(file.文件大小) }}</span>
                  <div class="file-actions">
                    <NButton v-if="isEditableDoc(file)" size="tiny" quaternary type="info" @click="handleEditFile(file)">编辑</NButton>
                    <NButton size="tiny" quaternary type="primary" @click="handleExportFile(file)">导出</NButton>
                    <NButton size="tiny" quaternary type="error" @click="handleDeleteFile(file, 'APPROVAL_FORM')">删除</NButton>
                  </div>
                </div>
              </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 弹窗模式（新建合同等） -->
  <NModal
    v-else-if="!inline && visible"
    :show="visible"
    preset="card"
    :title="mode === 'create' ? '新建合同' : '编辑合同'"
    :mask-closable="false"
    @update:show="val => !val && requestClose()"
    :style="{ width: '600px' }"
  >
    <div class="contract-modal-body">
    <NForm label-placement="left" :label-width="90" class="contract-form">
      <NFormItem label="合同名称" required>
        <NInput v-model:value="formData.合同名称" placeholder="请输入合同名称" />
      </NFormItem>
      <NFormItem label="合同类型">
        <NSelect v-model:value="formData.合同类型" :options="合同类型Options" />
      </NFormItem>
      <NFormItem label="甲方名称" required>
        <NInput v-model:value="formData.甲方名称" placeholder="请输入甲方名称" />
      </NFormItem>
      <NFormItem label="甲方联系人">
        <NInput v-model:value="formData.甲方联系人" placeholder="请输入甲方联系人" />
      </NFormItem>
      <NFormItem label="甲方电话">
        <NInput v-model:value="formData.甲方电话" placeholder="请输入甲方电话" />
      </NFormItem>
      <NFormItem label="乙方名称" required>
        <NInput v-model:value="formData.乙方名称" placeholder="请输入乙方名称" />
      </NFormItem>
      <NFormItem label="乙方联系人">
        <NInput v-model:value="formData.乙方联系人" placeholder="请输入乙方联系人" />
      </NFormItem>
      <NFormItem label="乙方电话">
        <NInput v-model:value="formData.乙方电话" placeholder="请输入乙方电话" />
      </NFormItem>
      <NFormItem label="合同金额">
        <NInputNumber v-model:value="formData.合同金额" placeholder="请输入合同金额" :precision="2" style="width: 100%" />
      </NFormItem>
      <NFormItem label="付款方式">
        <NSelect v-model:value="formData.付款方式" :options="付款方式Options" />
      </NFormItem>
      <NFormItem label="币别">
        <NSelect v-model:value="formData.币别" :options="币别Options" />
      </NFormItem>
      <NFormItem label="汇率">
        <NInputNumber v-model:value="formData.汇率" placeholder="请输入汇率" :precision="4" :step="0.0001" style="width: 100%" />
      </NFormItem>
      <NFormItem label="签订日期">
        <NDatePicker v-model:value="签订日期Ts" type="date" clearable style="width: 100%" />
      </NFormItem>
      <NFormItem label="开始日期">
        <NDatePicker v-model:value="开始日期Ts" type="date" clearable style="width: 100%" />
      </NFormItem>
      <NFormItem label="结束日期">
        <NDatePicker v-model:value="结束日期Ts" type="date" clearable style="width: 100%" />
      </NFormItem>
      <NFormItem label="备注">
        <NInput v-model:value="formData.备注" type="textarea" :rows="2" placeholder="请输入备注" />
      </NFormItem>

      <NFormItem label="合同文件">
        <div class="file-upload-section">
          <div v-if="mode === 'create'" class="upload-tip">请先保存合同基础信息后再上传文件</div>
          <div v-else class="upload-area">
            <NButton size="small" :loading="uploading" @click="triggerUpload('MAIN')">
              <template #icon><icon-mdi-upload /></template>
              上传合同文件
            </NButton>
            <input ref="mainFileInput" type="file" class="hidden-file-input" :disabled="uploading" @change="e => handleFileUpload(e, 'MAIN')" />
            <div class="file-list">
              <div v-for="file in contractFiles" :key="file.GUID" class="file-item">
                <span class="file-name">{{ file.文档名称 }}</span>
                <span class="file-size">{{ formatFileSize(file.文件大小) }}</span>
                <div class="file-actions">
                  <NButton v-if="isEditableDoc(file)" size="tiny" quaternary type="info" @click="handleEditFile(file)">编辑</NButton>
                  <NButton size="tiny" quaternary type="primary" @click="handleExportFile(file)">导出</NButton>
                  <NButton size="tiny" quaternary type="error" @click="handleDeleteFile(file, 'MAIN')">删除</NButton>
                </div>
              </div>
            </div>
          </div>
        </div>
      </NFormItem>

      <NFormItem label="合同审批表">
        <div class="file-upload-section">
          <div v-if="mode === 'create'" class="upload-tip">请先保存合同基础信息后再上传文件</div>
          <div v-else class="upload-area">
            <NButton size="small" :loading="uploading" @click="triggerUpload('APPROVAL_FORM')">
              <template #icon><icon-mdi-upload /></template>
              上传审批表
            </NButton>
            <input ref="approvalFileInput" type="file" class="hidden-file-input" :disabled="uploading" @change="e => handleFileUpload(e, 'APPROVAL_FORM')" />
            <div class="file-list">
              <div v-for="file in approvalFiles" :key="file.GUID" class="file-item">
                <span class="file-name">{{ file.文档名称 }}</span>
                <span class="file-size">{{ formatFileSize(file.文件大小) }}</span>
                <div class="file-actions">
                  <NButton v-if="isEditableDoc(file)" size="tiny" quaternary type="info" @click="handleEditFile(file)">编辑</NButton>
                  <NButton size="tiny" quaternary type="primary" @click="handleExportFile(file)">导出</NButton>
                  <NButton size="tiny" quaternary type="error" @click="handleDeleteFile(file, 'APPROVAL_FORM')">删除</NButton>
                </div>
              </div>
            </div>
          </div>
        </div>
      </NFormItem>
    </NForm>
    </div>
    <template #footer>
      <NSpace justify="end">
        <NButton @click="requestClose">取消</NButton>
        <NButton type="primary" :loading="loading" @click="handleSubmit">
          {{ loading ? '提交中...' : '确定' }}
        </NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped lang="scss">
.inline-form {
  padding: 0;
}

// 表格化布局（参照面试人员维护页面的 NTable 视觉）
.edit-table {
  display: flex;
  flex-direction: column;
  border: 1px solid #e8e8e8;
  border-radius: 4px;
  overflow: hidden;
  font-size: 13px;

  .edit-row {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid #e8e8e8;
    color: #333;

    &:last-child {
      border-bottom: none;
    }

    &.edit-head {
      font-weight: 500;
      color: #333;
    }
  }

  .edit-cell {
    padding: 10px 12px;
    display: flex;
    align-items: center;
    min-height: 38px;
    line-height: 1.4;
    color: inherit;
  }

  .edit-cell-name {
    width: 110px;
    flex-shrink: 0;
    border-right: 1px solid #e8e8e8;
    color: #333;
  }

  .edit-cell-value {
    flex: 1;
    background: transparent;
    color: inherit;

    // 让 Naive UI 输入控件撑满单元格
    :deep(.n-input),
    :deep(.n-select),
    :deep(.n-date-picker),
    :deep(.n-input-number) {
      width: 100%;
    }
  }

  .required-mark {
    color: #ff4d4f;
    margin-left: 2px;
  }
}

// 暗黑模式适配
.system-dark .edit-table {
  border-color: rgba(255, 255, 255, 0.09);

  .edit-row {
    border-color: rgba(255, 255, 255, 0.09);
    color: #e0e0e0;

    &.edit-head {
      color: #e0e0e0;
    }
  }

  .edit-cell-name {
    border-color: rgba(255, 255, 255, 0.09);
    color: #e0e0e0;
  }

  // 附件列表项暗黑适配
  .file-item {
    background: rgba(255, 255, 255, 0.05);

    .file-name {
      color: rgba(255, 255, 255, 0.85);
    }

    .file-size {
      color: #888;
    }
  }

  .upload-tip {
    color: #888;
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.15);
  }
}

// 弹窗表单：左侧字段名称不换行，单行显示
.contract-form {
  :deep(.n-form-item-label__text) {
    white-space: nowrap;
  }
}

// 弹窗内容区：超出时在弹窗内滚动，不滚动主窗口
// 80vh 减去标题栏(~56px)和底部按钮区(~60px)的高度
.contract-modal-body {
  max-height: calc(80vh - 130px);
  overflow-y: auto;
  padding-right: 4px;
}

.upload-tip {
  padding: 12px;
  text-align: center;
  color: rgb(118, 124, 130);
  font-size: 13px;
  background: rgb(250, 250, 252);
  border: 1px dashed rgb(224, 224, 230);
  border-radius: 4px;
}

.upload-area {
  display: flex;
  flex-direction: column;
  gap: 8px;

  .hidden-file-input {
    display: none;
  }

  // 上传按钮统一样式，确保"上传合同文件"和"上传审批表"宽度一致
  :deep(.n-button) {
    align-self: flex-start;
    min-width: 130px;
  }

  .file-list {
    display: flex;
    flex-direction: column;
    gap: 6px;

    .file-item {
      display: flex;
      align-items: center;
      padding: 6px 10px;
      background: rgb(250, 250, 252);
      border-radius: 4px;
      gap: 10px;

      .file-name {
        flex: 1;
        color: rgb(51, 54, 57);
        font-size: 13px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .file-size {
        color: rgb(118, 124, 130);
        font-size: 12px;
        flex-shrink: 0;
      }

      // 按钮组：紧贴文件大小右侧，不被推到最右
      .file-actions {
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
        margin-left: auto;

        :deep(.n-button) {
          padding: 0 4px !important;
          margin: 0 !important;
          min-width: 0 !important;
          height: 20px;
          font-size: 12px;
        }

        :deep(.n-button + .n-button) {
          margin-left: 2px !important;
        }
      }
    }
  }
}

/* 暗色模式：上传区域适配 */
.system-dark {
  .upload-tip {
    color: rgba(255, 255, 255, 0.52);
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.15);
  }

  .upload-area .file-list .file-item {
    background: rgba(255, 255, 255, 0.05);

    .file-name {
      color: rgba(255, 255, 255, 0.85);
    }

    .file-size {
      color: rgba(255, 255, 255, 0.52);
    }
  }
}
</style>
