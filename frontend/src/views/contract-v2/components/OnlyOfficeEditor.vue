<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { useMessage } from 'naive-ui';

const props = defineProps<{
  documentId: number;
  editorConfig?: Record<string, any>;
  height?: string;
}>();

const emit = defineEmits<{
  ready: [];
  documentReady: [];
  documentStateChange: [event: any];
  error: [error: any];
}>();

const message = useMessage();
const editorContainerRef = ref<HTMLDivElement | null>(null);
let docEditor: any = null;

const isLoading = ref(true);
const loadError = ref('');

async function loadEditor() {
  if (!editorContainerRef.value || !props.documentId) return;

  isLoading.value = true;
  loadError.value = '';

  try {
    const { fetchOnlyOfficeConfig } = await import('@/service/api/onlyoffice');
    const result = await fetchOnlyOfficeConfig(props.documentId);
    const config = (result as any)?.data || result;

    if (!config || !config.document || !config.editorUrl) {
      loadError.value = '无法加载文档编辑器配置';
      isLoading.value = false;
      return;
    }

    if (!(window as any).DocsAPI && !document.getElementById('onlyoffice-api-script')) {
      await loadScript(config.editorUrl + '/web-apps/apps/api/documents/api.js');
    }

    if (docEditor) {
      docEditor.destroy();
      docEditor = null;
    }

    const editorConfig = {
      document: config.document,
      documentType: config.documentType || 'word',
      editorConfig: config.editorConfig || {},
      width: '100%',
      height: props.height || '100%',
      events: {
        onReady: () => {
          emit('ready');
          isLoading.value = false;
        },
        onDocumentReady: () => {
          emit('documentReady');
        },
        onDocumentStateChange: (event: any) => {
          emit('documentStateChange', event);
        },
        onError: (event: any) => {
          console.error('OnlyOffice error:', event);
          emit('error', event);
          loadError.value = event?.data?.message || '文档编辑器加载出错';
        }
      },
      ...(props.editorConfig || {})
    };

    docEditor = new (window as any).DocsAPI.DocEditor(
      editorContainerRef.value,
      editorConfig
    );
  } catch (e: any) {
    console.error('Failed to load OnlyOffice editor:', e);
    loadError.value = e?.message || '文档编辑器加载失败';
    isLoading.value = false;
    emit('error', e);
  }
}

function loadScript(url: string): Promise<void> {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.id = 'onlyoffice-api-script';
    script.src = url;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Failed to load OnlyOffice API script'));
    document.head.appendChild(script);
  });
}

function getEditor() {
  return docEditor;
}

function save() {
  if (docEditor && typeof docEditor.saveAs === 'function') {
    docEditor.saveAs();
  }
}

watch(
  () => props.documentId,
  (newId) => {
    if (newId) {
      loadEditor();
    }
  }
);

onMounted(() => {
  if (props.documentId) {
    loadEditor();
  }
});

onBeforeUnmount(() => {
  if (docEditor) {
    docEditor.destroy();
    docEditor = null;
  }
});

defineExpose({
  getEditor,
  save
});
</script>

<template>
  <div class="onlyoffice-editor-wrapper">
    <div v-if="isLoading" class="editor-loading">
      <div class="spinner"></div>
      <p>文档编辑器加载中...</p>
    </div>
    <div v-else-if="loadError" class="editor-error">
      <p class="error-text">{{ loadError }}</p>
      <button class="retry-btn" @click="loadEditor">重新加载</button>
    </div>
    <div ref="editorContainerRef" class="editor-container" :style="{ height: height || '600px' }"></div>
  </div>
</template>

<style scoped lang="scss">
.onlyoffice-editor-wrapper {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 400px;

  .editor-loading {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #fafafa;
    z-index: 10;

    .spinner {
      width: 40px;
      height: 40px;
      border: 3px solid #e8e8e8;
      border-top-color: #1890ff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-bottom: 16px;
    }

    p {
      margin: 0;
      color: #666;
      font-size: 14px;
    }
  }

  .editor-error {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #fafafa;
    z-index: 10;

    .error-text {
      color: #ff4d4f;
      font-size: 14px;
      margin-bottom: 16px;
    }

    .retry-btn {
      padding: 8px 20px;
      background: #1890ff;
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 14px;

      &:hover {
        background: #40a9ff;
      }
    }
  }

  .editor-container {
    width: 100%;
    height: 100%;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
