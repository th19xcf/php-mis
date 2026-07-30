<script setup lang="ts">
import { ref, watch, computed, reactive } from 'vue';
import { useMessage } from 'naive-ui';
import { useContractV2Store } from '@/store/modules/contract-v2';
import {
  fetchContractV2UploadDocument,
  fetchContractV2DeleteDocument,
  fetchContractV2DownloadDocument
} from '@/service/api/contract-v2';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  contract: Api.ContractV2.ContractDetail | null;
  inline?: boolean;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
  openEditor: [docId: number, docName: string];
}>();

const message = useMessage();
const contractV2Store = useContractV2Store();

const loading = computed(() => contractV2Store.loading);

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

const contractFiles = ref<Api.ContractV2.ContractDocument[]>([]);
const approvalFiles = ref<Api.ContractV2.ContractDocument[]>([]);
const uploading = ref(false);
const mainFileInput = ref<HTMLInputElement | null>(null);
const approvalFileInput = ref<HTMLInputElement | null>(null);

function triggerUpload(docType: 'MAIN' | 'APPROVAL_FORM') {
  const input = docType === 'MAIN' ? mainFileInput.value : approvalFileInput.value;
  input?.click();
}

// 可在线编辑的文件格式
const editableExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

function isEditableDoc(doc: Api.ContractV2.ContractDocument): boolean {
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
    }
  },
  { immediate: true }
);

function handleClose() {
  emit('update:visible', false);
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
      await contractV2Store.createContract(formData.value as any);
      message.success('创建成功');
    } else {
      if (!props.contract) return;
      await contractV2Store.updateContract({
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

const options = computed(() => contractV2Store.options);

const currentContractNo = computed(() => {
  if (props.mode === 'edit' && props.contract) {
    return props.contract.合同编号;
  }
  return contractV2Store.currentContract?.合同编号 || '';
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
    const result = await fetchContractV2UploadDocument({
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
    contractV2Store.loadContractDetail(currentContractNo.value);
  } catch (e: any) {
    message.error(e?.message || '上传失败');
  } finally {
    uploading.value = false;
    target.value = '';
  }
}

async function handleDeleteFile(doc: Api.ContractV2.ContractDocument, docType: 'MAIN' | 'APPROVAL_FORM') {
  try {
    await fetchContractV2DeleteDocument(doc.GUID);
    if (docType === 'MAIN') {
      contractFiles.value = contractFiles.value.filter(d => d.GUID !== doc.GUID);
    } else {
      approvalFiles.value = approvalFiles.value.filter(d => d.GUID !== doc.GUID);
    }
    message.success('删除成功');
    contractV2Store.loadContractDetail(currentContractNo.value);
  } catch (e: any) {
    message.error(e?.message || '删除失败');
  }
}

function handleDownload(doc: Api.ContractV2.ContractDocument) {
  if (isEditableDoc(doc)) {
    emit('openEditor', doc.GUID, doc.文档名称);
  } else {
    doDownload(doc);
  }
}

// 编辑文件：打开 OnlyOffice 编辑器
function handleEditFile(doc: Api.ContractV2.ContractDocument) {
  emit('openEditor', doc.GUID, doc.文档名称);
}

// 通用下载：带 Authorization 头的 fetch 请求，避免 JWT 过滤器返回 JSON 错误
async function doDownload(doc: Api.ContractV2.ContractDocument) {
  try {
    const { blob, filename } = await fetchContractV2DownloadDocument(doc.GUID);
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
function handleExportFile(doc: Api.ContractV2.ContractDocument) {
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
  <div v-else-if="!inline && visible" class="modal-overlay" @click.self="handleClose">
    <div class="modal-container">
      <div class="modal-header">
        <h3>{{ mode === 'create' ? '新建合同' : '编辑合同' }}</h3>
        <button class="close-btn" @click="handleClose">×</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-item">
            <label>合同名称 <span class="required">*</span></label>
            <input v-model="formData.合同名称" placeholder="请输入合同名称" />
          </div>
          <div class="form-item">
            <label>合同类型</label>
            <select v-model="formData.合同类型">
              <option value="">请选择</option>
              <option v-for="opt in options.合同类型" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>甲方名称 <span class="required">*</span></label>
            <input v-model="formData.甲方名称" placeholder="请输入甲方名称" />
          </div>
          <div class="form-item">
            <label>甲方联系人</label>
            <input v-model="formData.甲方联系人" placeholder="请输入甲方联系人" />
          </div>
          <div class="form-item">
            <label>甲方电话</label>
            <input v-model="formData.甲方电话" placeholder="请输入甲方电话" />
          </div>
          <div class="form-item">
            <label>乙方名称 <span class="required">*</span></label>
            <input v-model="formData.乙方名称" placeholder="请输入乙方名称" />
          </div>
          <div class="form-item">
            <label>乙方联系人</label>
            <input v-model="formData.乙方联系人" placeholder="请输入乙方联系人" />
          </div>
          <div class="form-item">
            <label>乙方电话</label>
            <input v-model="formData.乙方电话" placeholder="请输入乙方电话" />
          </div>
          <div class="form-item">
            <label>合同金额</label>
            <input type="number" v-model.number="formData.合同金额" placeholder="请输入合同金额" />
          </div>
          <div class="form-item">
            <label>付款方式</label>
            <select v-model="formData.付款方式">
              <option value="">请选择</option>
              <option v-for="opt in options.付款方式" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>币别</label>
            <select v-model="formData.币别">
              <option value="">请选择</option>
              <option v-for="opt in options.币别" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>汇率</label>
            <input type="number" v-model.number="formData.汇率" step="0.0001" placeholder="请输入汇率" />
          </div>
          <div class="form-item">
            <label>签订日期</label>
            <input type="date" v-model="formData.签订日期" />
          </div>
          <div class="form-item">
            <label>开始日期</label>
            <input type="date" v-model="formData.开始日期" />
          </div>
          <div class="form-item">
            <label>结束日期</label>
            <input type="date" v-model="formData.结束日期" />
          </div>
          <div class="form-item full">
            <label>备注</label>
            <textarea v-model="formData.备注" rows="3" placeholder="请输入备注"></textarea>
          </div>

          <div class="form-item full">
            <label>合同文件</label>
            <div class="file-upload-section">
              <div v-if="mode === 'create'" class="upload-tip">
                请先保存合同基础信息后再上传文件
              </div>
              <div v-else class="upload-area">
                <label class="upload-btn">
                  <input
                    type="file"
                    :disabled="uploading"
                    @change="e => handleFileUpload(e, 'MAIN')"
                  />
                  <span>{{ uploading ? '上传中...' : '+ 上传合同文件' }}</span>
                </label>
                <div class="file-list">
                  <div
                    v-for="file in contractFiles"
                    :key="file.GUID"
                    class="file-item"
                  >
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

          <div class="form-item full">
            <label>合同审批表</label>
            <div class="file-upload-section">
              <div v-if="mode === 'create'" class="upload-tip">
                请先保存合同基础信息后再上传文件
              </div>
              <div v-else class="upload-area">
                <label class="upload-btn">
                  <input
                    type="file"
                    :disabled="uploading"
                    @change="e => handleFileUpload(e, 'APPROVAL_FORM')"
                  />
                  <span>{{ uploading ? '上传中...' : '+ 上传审批表' }}</span>
                </label>
                <div class="file-list">
                  <div
                    v-for="file in approvalFiles"
                    :key="file.GUID"
                    class="file-item"
                  >
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
      <div class="modal-footer">
        <button class="btn btn-default" @click="handleClose">取消</button>
        <button class="btn btn-primary" :disabled="loading" @click="handleSubmit">
          {{ loading ? '提交中...' : '确定' }}
        </button>
      </div>
    </div>
  </div>
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

.upload-tip {
  padding: 12px;
  text-align: center;
  color: #999;
  font-size: 13px;
  background: #fafafa;
  border: 1px dashed #d9d9d9;
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
      background: #fafafa;
      border-radius: 4px;
      gap: 10px;

      .file-name {
        flex: 1;
        color: #333;
        font-size: 13px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .file-size {
        color: #999;
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

.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-container {
  width: 720px;
  max-height: 85vh;
  background: #fff;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid #f0f0f0;

  h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }

  .close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #999;
    line-height: 1;

    &:hover {
      color: #333;
    }
  }
}

.modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;

  .form-item {
    display: flex;
    flex-direction: column;
    gap: 6px;

    &.full {
      grid-column: span 2;
    }

    label {
      font-size: 13px;
      color: #666;

      .required {
        color: #ff4d4f;
      }
    }

    input,
    select,
    textarea {
      padding: 8px 12px;
      border: 1px solid #d9d9d9;
      border-radius: 4px;
      font-size: 14px;
      outline: none;
      transition: border-color 0.2s;

      &:focus {
        border-color: #1890ff;
      }
    }

    textarea {
      resize: vertical;
      font-family: inherit;
    }
  }
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
  border-top: 1px solid #f0f0f0;
}

.btn {
  padding: 8px 20px;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  border: none;
  transition: all 0.2s;

  &.btn-primary {
    background: #1890ff;
    color: #fff;

    &:hover {
      background: #40a9ff;
    }

    &:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  }

  &.btn-default {
    background: #fff;
    color: #333;
    border: 1px solid #d9d9d9;

    &:hover {
      border-color: #1890ff;
      color: #1890ff;
    }
  }
}
</style>
