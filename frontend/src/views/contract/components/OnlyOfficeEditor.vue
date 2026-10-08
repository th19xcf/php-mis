<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import {  } from 'naive-ui';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';

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

const message = useMessageWithConsole();
const editorContainerRef = ref<HTMLDivElement | null>(null);
let docEditor: any = null;

const isLoading = ref(true);
const loadError = ref('');

/**
 * 将 OnlyOffice 错误码转换为友好的中文提示和排查建议
 */
function formatOnlyOfficeError(errorCode: number | string | undefined, errorDesc: string): string {
  const code = Number(errorCode);
  const desc = errorDesc || '未知错误';

  const errorMap: Record<number, string> = {
    [-1]: '未知错误：发生了未分类的错误，请检查浏览器控制台和后端日志。',
    [-2]: '无效的配置：编辑器配置格式错误，请检查 document/editorConfig 字段。',
    [-3]: '授权错误：JWT Token 验证失败，请检查 onlyoffice.jwtSecret 配置是否一致。',
    [-4]: `下载失败：OnlyOffice 服务器无法下载文档文件。
排查建议：
1. 检查 onlyoffice.backendUrl 配置的地址是否可从 OnlyOffice 服务器访问
2. 确认 cpolar/frp 等隧道服务是否正常运行
3. 检查文档文件是否存在于后端 writable/contract_docs/ 目录
4. 查看后端 onlyoffice/download 接口的日志`,
    [-5]: '保存失败：OnlyOffice 服务器无法将编辑后的文档回传给后端。请检查 callbackUrl 是否可达。',
    [-6]: '无效的密钥：document.key 格式或长度不正确。',
    [-7]: '文件格式不支持：该文件格式无法被 OnlyOffice 编辑器打开。',
    [-8]: '文件已损坏：文件内容不完整或格式异常，请尝试重新上传。',
    [-9]: '文件过大：文件大小超出了 OnlyOffice 服务器的处理限制。',
    [-20]: 'Token 格式错误：JWT token 格式不正确，请检查 onlyoffice.jwtSecret 配置。',
    [-30]: '并发编辑限制：已有用户正在编辑此文档，请稍后再试。',
    [-50]: '许可证错误：OnlyOffice 服务器许可证无效或已过期。',
    [-51]: '许可证限制：超出了 OnlyOffice 服务器的并发连接数限制。',
    [-52]: '许可证限制：超出了 OnlyOffice 服务器的用户数限制。',
    [-100]: '无权限：您没有编辑此文档的权限。',
    [-101]: '文档已被锁定：文档正在被其他用户编辑，请稍后再试。',
    [-200]: '网络错误：无法连接到 OnlyOffice 服务器，请检查服务器地址和网络连接。',
    [-220]: '服务器错误：OnlyOffice 服务器内部错误，请联系管理员。',
    [-300]: '连接超时：与 OnlyOffice 服务器的连接超时，请稍后重试。',
    [-404]: '文档不存在：请求的文档未找到，请刷新页面后重试。',
  };

  const message = errorMap[code] || `加载失败（错误码: ${code}）：${desc}`;
  return message;
}

// 性能追踪：步骤时间戳记录
interface PerfStep {
  name: string;
  timestamp: number; // performance.now() 毫秒
}
let perfSteps: PerfStep[] = [];
let perfT0 = 0;

function perfStart(): void {
  perfSteps = [];
  perfT0 = performance.now();
  perfSteps.push({ name: '开始', timestamp: perfT0 });
}

function perfMark(name: string): void {
  perfSteps.push({ name, timestamp: performance.now() });
}

function perfEnd(status: '成功' | '失败' = '成功'): void {
  perfSteps.push({ name: '结束', timestamp: performance.now() });
  const total = perfSteps[perfSteps.length - 1].timestamp - perfT0;

  const rows: Array<{ index: number; step: string; duration: number; pct: number }> = [];
  for (let i = 1; i < perfSteps.length; i++) {
    const duration = perfSteps[i].timestamp - perfSteps[i - 1].timestamp;
    const pct = total > 0 ? (duration / total) * 100 : 0;
    rows.push({
      index: i - 1,
      step: perfSteps[i - 1].name + ' → ' + perfSteps[i].name,
      duration,
      pct
    });
  }

  console.groupCollapsed(`[OnlyOfficeEditor] 性能追踪 状态=${status} 总耗时=${total.toFixed(2)}ms`);
  console.log('%c步骤耗时明细', 'font-weight:bold');
  console.table(rows.map(r => ({
    索引: r.index,
    步骤: r.step,
    耗时ms: r.duration.toFixed(2),
    占比: r.pct.toFixed(1) + '%'
  })));

  // 排行
  const sorted = [...rows].sort((a, b) => b.duration - a.duration);
  const maxDuration = sorted[0]?.duration ?? 0;
  console.log('%c耗时排行（从慢到快）', 'font-weight:bold');
  sorted.forEach((r, idx) => {
    if (r.duration < 0.01) return;
    const barLen = maxDuration > 0 ? Math.max(1, Math.round(r.duration / maxDuration * 50)) : 0;
    const bar = '█'.repeat(barLen);
    console.log(` ${idx + 1}. ${r.step.padEnd(30)} ${r.duration.toFixed(2).padStart(10)}ms ${bar}`);
  });

  console.groupEnd();
}

async function loadEditor() {
  perfStart();
  perfMark('初始化检查');
  console.log('[OnlyOfficeEditor] loadEditor started, documentId:', props.documentId);

  if (!editorContainerRef.value) {
    console.error('[OnlyOfficeEditor] editorContainerRef is null');
    loadError.value = '编辑器容器不存在';
    isLoading.value = false;
    perfEnd('失败');
    return;
  }

  if (!props.documentId) {
    console.error('[OnlyOfficeEditor] documentId is empty');
    loadError.value = '文档ID为空';
    isLoading.value = false;
    perfEnd('失败');
    return;
  }

  console.log('[OnlyOfficeEditor] container element:', editorContainerRef.value);
  console.log('[OnlyOfficeEditor] container clientHeight:', editorContainerRef.value.clientHeight);

  isLoading.value = true;
  loadError.value = '';

  try {
    console.log('[OnlyOfficeEditor] Step 1: Fetching OnlyOffice config from backend...');
    const { fetchOnlyOfficeConfig } = await import('@/service/api/onlyoffice');
    const { useThemeStore } = await import('@/store/modules/theme');
    perfMark('动态导入API模块');
    // 由 isDarkMode 推导 uiTheme，传给后端注入 OnlyOffice customization.uiTheme
    const themeStore = useThemeStore();
    const uiTheme = themeStore.darkMode ? 'dark' : 'default';
    const result = await fetchOnlyOfficeConfig(props.documentId, uiTheme as 'dark' | 'default');
    perfMark('请求/onlyoffice/config');
    console.log('[OnlyOfficeEditor] Step 1 completed: Config response:', result);

    const config = (result as any)?.data || result;
    console.log('[OnlyOfficeEditor] Parsed config:', config);

    if (!config) {
      console.error('[OnlyOfficeEditor] Step 1 failed: config is null/undefined');
      loadError.value = '无法加载文档编辑器配置：配置为空';
      isLoading.value = false;
      perfEnd('失败');
      return;
    }

    if (!config.document) {
      console.error('[OnlyOfficeEditor] Step 1 failed: config.document is missing');
      loadError.value = '无法加载文档编辑器配置：缺少文档信息';
      isLoading.value = false;
      perfEnd('失败');
      return;
    }

    if (!config.editorUrl) {
      console.error('[OnlyOfficeEditor] Step 1 failed: config.editorUrl is missing');
      loadError.value = '无法加载文档编辑器配置：缺少编辑器地址';
      isLoading.value = false;
      perfEnd('失败');
      return;
    }

    console.log('[OnlyOfficeEditor] Step 2: Loading OnlyOffice API script from:', config.editorUrl);

    if (!(window as any).DocsAPI && !document.getElementById('onlyoffice-api-script')) {
      console.log('[OnlyOfficeEditor] Step 2: Script not loaded yet, loading...');
      await loadScript(config.editorUrl + '/web-apps/apps/api/documents/api.js');
      perfMark('加载api.js脚本');
      console.log('[OnlyOfficeEditor] Step 2 completed: API script loaded');
    } else {
      console.log('[OnlyOfficeEditor] Step 2: API script already loaded');
      perfMark('api.js已缓存跳过');
    }

    if (!(window as any).DocsAPI) {
      console.error('[OnlyOfficeEditor] Step 2 failed: window.DocsAPI is still undefined');
      loadError.value = 'OnlyOffice API 脚本加载失败';
      isLoading.value = false;
      perfEnd('失败');
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
    perfMark('销毁旧编辑器');

    const editorConfig: Record<string, any> = {
      document: config.document,
      documentType: config.documentType || 'word',
      editorConfig: config.editorConfig || {},
      width: '100%',
      height: props.height || '100%',
      events: {
        onReady: () => {
          console.log('[OnlyOfficeEditor] Event: onReady fired');
          perfMark('onReady事件');
          perfEnd('成功');
          emit('ready');
          isLoading.value = false;
        },
        onDocumentReady: () => {
          console.log('[OnlyOfficeEditor] Event: onDocumentReady fired');
          perfMark('onDocumentReady事件');
          perfEnd('成功');
          emit('documentReady');
          isLoading.value = false;
          loadError.value = '';
        },
        onDocumentStateChange: (event: any) => {
          console.log('[OnlyOfficeEditor] Event: onDocumentStateChange', event);
          emit('documentStateChange', event);
        },
        onError: (event: any) => {
          console.error('[OnlyOfficeEditor] Event: onError');
          console.error('[OnlyOfficeEditor] Error event full:', JSON.stringify(event, null, 2));
          console.error('[OnlyOfficeEditor] Error data:', event?.data);
          console.error('[OnlyOfficeEditor] Error data type:', typeof event?.data);
          if (event?.data) {
            console.error('[OnlyOfficeEditor] Error data keys:', Object.keys(event.data));
            console.error('[OnlyOfficeEditor] Error message:', event.data.message);
            console.error('[OnlyOfficeEditor] Error code:', event.data.errorCode || event.data.code);
            console.error('[OnlyOfficeEditor] Error description:', event.data.errorDescription || event.data.description);
          }
          perfMark('onError事件');
          perfEnd('失败');
          emit('error', event);

          // 根据错误码生成更友好的中文提示和排查建议
          const errorCode = event?.data?.errorCode ?? event?.data?.code;
          const errorDesc = event?.data?.errorDescription ?? event?.data?.description ?? event?.data?.message ?? '';
          loadError.value = formatOnlyOfficeError(errorCode, errorDesc);
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

    // 传递 JWT token（OnlyOffice 服务器启用 JWT 验证时必须）
    if (config.token) {
      editorConfig.token = config.token;
    }

    console.log('[OnlyOfficeEditor] Step 4: Creating DocEditor with config:', {
      documentTitle: editorConfig.document.title,
      documentUrl: editorConfig.document.url,
      editorUrl: config.editorUrl,
      documentType: editorConfig.documentType,
      height: editorConfig.height,
      containerHeight: editorContainerRef.value.clientHeight
    });
    perfMark('构建editorConfig');

    docEditor = new (window as any).DocsAPI.DocEditor(
      'onlyoffice-editor-container',
      editorConfig
    );
    perfMark('创建DocEditor实例');

    console.log('[OnlyOfficeEditor] Step 4 completed: DocEditor instance created:', !!docEditor);

    setTimeout(() => {
      if (isLoading.value && docEditor) {
        console.warn('[OnlyOfficeEditor] Warning: Editor still loading after 15 seconds (normal for .doc first load)');
      }
    }, 15000);

    setTimeout(() => {
      if (isLoading.value && docEditor) {
        console.error('[OnlyOfficeEditor] Error: Editor still loading after 60 seconds');
        loadError.value = '文档编辑器加载超时，请检查网络连接或 OnlyOffice 服务状态';
        isLoading.value = false;
        perfMark('超时(60s)');
        perfEnd('失败');
      }
    }, 60000);

  } catch (e: any) {
    console.error('[OnlyOfficeEditor] Exception caught:', e);
    console.error('[OnlyOfficeEditor] Error stack:', e?.stack);
    perfMark('异常');
    perfEnd('失败');
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
      <p class="error-text" style="white-space: pre-line; text-align: left; line-height: 1.6;">{{ loadError }}</p>
      <button class="retry-btn" @click="loadEditor">重新加载</button>
    </div>
    <div id="onlyoffice-editor-container" ref="editorContainerRef" class="editor-container" :style="{ height: height || '600px' }"></div>
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
