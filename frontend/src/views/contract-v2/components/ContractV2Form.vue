<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useMessage } from 'naive-ui';
import { useContractV2Store } from '@/store/modules/contract-v2';
import {
  fetchContractV2UploadDocument,
  fetchContractV2DeleteDocument,
  getContractV2DownloadUrl
} from '@/service/api/contract-v2';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  contract: Api.ContractV2.ContractDetail | null;
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
  }
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
    const url = getContractV2DownloadUrl(doc.GUID);
    window.open(url, '_blank');
  }
}
</script>

<template>
  <div v-if="visible" class="modal-overlay" @click.self="handleClose">
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
                    <span class="file-name" :class="{ editable: isEditableDoc(file) }" @click="handleDownload(file)">
                      {{ file.文档名称 }}
                      <span v-if="isEditableDoc(file)" class="edit-hint">编辑</span>
                    </span>
                    <span class="file-size">{{ formatFileSize(file.文件大小) }}</span>
                    <button class="file-delete" @click="handleDeleteFile(file, 'MAIN')">删除</button>
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
                    <span class="file-name" :class="{ editable: isEditableDoc(file) }" @click="handleDownload(file)">
                      {{ file.文档名称 }}
                      <span v-if="isEditableDoc(file)" class="edit-hint">编辑</span>
                    </span>
                    <span class="file-size">{{ formatFileSize(file.文件大小) }}</span>
                    <button class="file-delete" @click="handleDeleteFile(file, 'APPROVAL_FORM')">删除</button>
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

.file-upload-section {
  .upload-tip {
    padding: 20px;
    text-align: center;
    color: #999;
    font-size: 13px;
    background: #fafafa;
    border: 1px dashed #d9d9d9;
    border-radius: 4px;
  }

  .upload-area {
    .upload-btn {
      display: inline-block;
      padding: 8px 16px;
      border: 1px dashed #1890ff;
      border-radius: 4px;
      color: #1890ff;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.2s;
      margin-bottom: 12px;

      &:hover {
        border-color: #40a9ff;
        color: #40a9ff;
        background: #e6f7ff;
      }

      input {
        display: none;
      }
    }

    .file-list {
      display: flex;
      flex-direction: column;
      gap: 8px;

      .file-item {
        display: flex;
        align-items: center;
        padding: 8px 12px;
        background: #fafafa;
        border-radius: 4px;
        gap: 12px;

        .file-name {
          flex: 1;
          color: #1890ff;
          font-size: 14px;
          cursor: pointer;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;

          &:hover {
            text-decoration: underline;
          }

          &.editable {
            color: #52c41a;
          }

          .edit-hint {
            display: inline-block;
            margin-left: 6px;
            padding: 0 6px;
            font-size: 11px;
            color: #52c41a;
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            border-radius: 2px;
            vertical-align: middle;
          }
        }

        .file-size {
          color: #999;
          font-size: 12px;
          flex-shrink: 0;
        }

        .file-delete {
          background: none;
          border: none;
          color: #ff4d4f;
          font-size: 13px;
          cursor: pointer;
          padding: 2px 8px;
          flex-shrink: 0;

          &:hover {
            text-decoration: underline;
          }
        }
      }
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
