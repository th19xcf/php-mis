<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { useMessage } from 'naive-ui';

declare global {
  interface Window {
    DocsAPI?: {
      DocEditor: new (container: HTMLElement, config: Record<string, any>) => any;
    };
  }
}

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
  console.log('[OnlyOfficeEditor] loadEditor started, documentId:', props.documentId);
  
  if (!editorContainerRef.value) {
    console.error('[OnlyOfficeEditor] editorContainerRef is null');
    loadError.value = '编辑器容器不存在';
    isLoading.value = false;
    return;
  }
  
  if (!props.documentId) {
    console.error('[OnlyOfficeEditor] documentId is empty');
    loadError.value = '文档ID为空';
    isLoading.value = false;
    return;
  }

  console.log('[OnlyOfficeEditor] container element:', editorContainerRef.value);
  console.log('[OnlyOfficeEditor] container clientHeight:', editorContainerRef.value.clientHeight);

  isLoading.value = true;
  loadError.value = '';

  try {
    console.log('[OnlyOfficeEditor] Step 1: Fetching OnlyOffice config from backend...');
    const { fetchOnlyOfficeConfig } = await import('@/service/api/onlyoffice');
    const result = await fetchOnlyOfficeConfig(props.documentId);
    console.log('[OnlyOfficeEditor] Step 1 completed: Config response:', result);
    
    const config = (result as any)?.data || result;
    console.log('[OnlyOfficeEditor] Parsed config:', config);

    if (!config) {
      console.error('[OnlyOfficeEditor] Step 1 failed: config is null/undefined');
      loadError.value = '无法加载文档编辑器配置：配置为空';
      isLoading.value = false;
      return;
    }

    if (!config.document) {
      console.error('[OnlyOfficeEditor] Step 1 failed: config.document is missing');
      loadError.value = '无法加载文档编辑器配置：缺少文档信息';
      isLoading.value = false;
      return;
    }

    if (!config.editorUrl) {
      console.error('[OnlyOfficeEditor] Step 1 failed: config.editorUrl is missing');
      loadError.value = '无法加载文档编辑器配置：缺少编辑器地址';
      isLoading.value = false;
      return;
    }

    console.log('[OnlyOfficeEditor] Step 2: Loading OnlyOffice API script from:', config.editorUrl);
    
    if (!(window as any).DocsAPI && !document.getElementById('onlyoffice-api-script')) {
      console.log('[OnlyOfficeEditor] Step 2: Script not loaded yet, loading...');
      await loadScript(config.editorUrl + '/web-apps/apps/api/documents/api.js');
      console.log('[OnlyOfficeEditor] Step 2 completed: API script loaded');
    } else {
      console.log('[OnlyOfficeEditor] Step 2: API script already loaded');
    }

    if (!(window as any).DocsAPI) {
      console.error('[OnlyOfficeEditor] Step 2 failed: window.DocsAPI is still undefined');
      loadError.value = 'OnlyOffice API 脚本加载失败';
      isLoading.value = false;
      return;
    }

    console.log('[OnlyOfficeEditor] Step 3: DocsAPI available:', !!window.DocsAPI);

    if (docEditor) {
      console.log('[OnlyOfficeEditor] Destroying existing editor...');
      if (typeof docEditor.destroyEditor === 'function') {
        docEditor.destroyEditor();
      }
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
          console.log('[OnlyOfficeEditor] Event: onReady fired');
          emit('ready');
          isLoading.value = false;
        },
        onDocumentReady: () => {
          console.log('[OnlyOfficeEditor] Event: onDocumentReady fired');
          emit('documentReady');
        },
        onDocumentStateChange: (event: any) => {
          console.log('[OnlyOfficeEditor] Event: onDocumentStateChange', event);
          emit('documentStateChange', event);
        },
        onError: (event: any) => {
          console.error('[OnlyOfficeEditor] Event: onError', event);
          emit('error', event);
          loadError.value = event?.data?.message || '文档编辑器加载出错';
          isLoading.value = false;
        },
        onOutdatedVersion: () => {
          console.warn('[OnlyOfficeEditor] Event: onOutdatedVersion');
        },
        onLicenseChecked: (event: any) => {
          console.log('[OnlyOfficeEditor] Event: onLicenseChecked', event);
        }
      },
      ...(props.editorConfig || {})
    };

    console.log('[OnlyOfficeEditor] Step 4: Creating DocEditor with config:', {
      documentTitle: editorConfig.document.title,
      documentUrl: editorConfig.document.url,
      editorUrl: config.editorUrl,
      documentType: editorConfig.documentType,
      height: editorConfig.height,
      containerHeight: editorContainerRef.value.clientHeight
    });

    docEditor = new (window as any).DocsAPI.DocEditor(
      editorContainerRef.value,
      editorConfig
    );

    console.log('[OnlyOfficeEditor] Step 4 completed: DocEditor instance created:', !!docEditor);
    
    setTimeout(() => {
      if (isLoading.value && docEditor) {
        console.warn('[OnlyOfficeEditor] Warning: Editor still loading after 5 seconds');
        console.log('[OnlyOfficeEditor] Current docEditor:', docEditor);
        console.log('[OnlyOfficeEditor] isLoading:', isLoading.value);
      }
    }, 5000);

    setTimeout(() => {
      if (isLoading.value && docEditor) {
        console.error('[OnlyOfficeEditor] Error: Editor still loading after 15 seconds');
        console.log('[OnlyOfficeEditor] Check if OnlyOffice server is reachable:', config.editorUrl);
        console.log('[OnlyOfficeEditor] Check if download URL is accessible:', editorConfig.document.url);
        loadError.value = '文档编辑器加载超时，请检查网络连接或 OnlyOffice 服务状态';
        isLoading.value = false;
      }
    }, 15000);

  } catch (e: any) {
    console.error('[OnlyOfficeEditor] Exception caught:', e);
    console.error('[OnlyOfficeEditor] Error stack:', e?.stack);
    loadError.value = e?.message || '文档编辑器加载失败';
    isLoading.value = false;
    emit('error', e);
  }
}

function loadScript(url: string): Promise<void> {
  return new Promise((resolve, reject) => {
    console.log('[OnlyOfficeEditor] loadScript: Creating script element for:', url);
    const script = document.createElement('script');
    script.id = 'onlyoffice-api-script';
    script.src = url;
    script.onload = () => {
      console.log('[OnlyOfficeEditor] loadScript: Script loaded successfully');
      console.log('[OnlyOfficeEditor] loadScript: window.DocsAPI after load:', !!window.DocsAPI);
      resolve();
    };
    script.onerror = (event) => {
      console.error('[OnlyOfficeEditor] loadScript: Script load failed', event);
      console.error('[OnlyOfficeEditor] loadScript: Check if OnlyOffice server is accessible:', url);
      reject(new Error('Failed to load OnlyOffice API script from: ' + url));
    };
    document.head.appendChild(script);
    console.log('[OnlyOfficeEditor] loadScript: Script tag appended to document');
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
    if (typeof docEditor.destroyEditor === 'function') {
      docEditor.destroyEditor();
    }
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
