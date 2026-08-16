<script setup lang="ts">
import { createTextVNode, defineComponent } from 'vue';
import { useDialog, useLoadingBar, useMessage, useNotification } from 'naive-ui';

defineOptions({
  name: 'AppProvider'
});

/**
 * 给 naive-ui message 实例加一层包装：弹窗显示的同时在浏览器控制台输出
 * 便于用户在 F12 控制台查看历史弹窗信息
 *
 * @param msg  useMessage() 返回的原生 message 实例
 * @return     包装后的 message 实例
 */
function wrapMessageWithConsole(msg: ReturnType<typeof useMessage>) {
  const levels = ['success', 'warning', 'error', 'info'] as const;
  const consoleMethod: Record<string, 'log' | 'warn' | 'error' | 'info'> = {
    success: 'log',
    warning: 'warn',
    error: 'error',
    info: 'info'
  };
  const result: any = { ...msg };
  for (const level of levels) {
    const original = msg[level].bind(msg);
    result[level] = (content: any, ...rest: any[]) => {
      const contentStr = typeof content === 'string' ? content : JSON.stringify(content);
      const tag = `[NAIVE-UI][${level.toUpperCase()}]`;
      (console as any)[consoleMethod[level]](`${tag} ${new Date().toLocaleTimeString()}  ${contentStr}`, ...rest);
      return original(content as any, ...rest);
    };
  }
  // loading/destroy 等其他方法保持原样透传
  return result;
}

/**
 * 给 naive-ui dialog / notification 实例加一层包装：弹窗显示的同时在控制台输出
 */
function wrapDialogWithConsole(dialog: ReturnType<typeof useDialog>) {
  const result: any = { ...dialog };
  const levels = ['info', 'success', 'warning', 'error'] as const;
  for (const level of levels) {
    const original = (dialog as any)[level]?.bind(dialog);
    if (!original) continue;
    result[level] = (...args: any[]) => {
      const arg0 = args[0] || {};
      const title = typeof arg0 === 'string' ? arg0 : (arg0.title || '');
      const content = typeof arg0 === 'object' ? (arg0.content || arg0.message || '') : '';
      console[level === 'error' ? 'error' : level === 'warning' ? 'warn' : 'log'](
        `[NAIVE-UI][DIALOG-${level.toUpperCase()}] ${new Date().toLocaleTimeString()}  title=${title}  content=${String(content)}`
      );
      return original(...args);
    };
  }
  // warning / confirm 等原函数也透传
  return result;
}

function wrapNotificationWithConsole(n: ReturnType<typeof useNotification>) {
  const result: any = { ...n };
  const levels = ['info', 'success', 'warning', 'error'] as const;
  for (const level of levels) {
    const original = (n as any)[level]?.bind(n);
    if (!original) continue;
    result[level] = (...args: any[]) => {
      const arg0 = args[0] || {};
      const title = typeof arg0 === 'string' ? arg0 : (arg0.title || '');
      const content = typeof arg0 === 'object' ? (arg0.content || arg0.description || '') : '';
      console[level === 'error' ? 'error' : level === 'warning' ? 'warn' : 'log'](
        `[NAIVE-UI][NOTIFY-${level.toUpperCase()}] ${new Date().toLocaleTimeString()}  title=${title}  content=${String(content)}`
      );
      return original(...args);
    };
  }
  return result;
}

const ContextHolder = defineComponent({
  name: 'ContextHolder',
  setup() {
    function register() {
      window.$loadingBar = useLoadingBar();
      window.$dialog = wrapDialogWithConsole(useDialog());
      window.$message = wrapMessageWithConsole(useMessage());
      window.$notification = wrapNotificationWithConsole(useNotification());
    }

    register();

    return () => createTextVNode();
  }
});
</script>

<template>
  <NLoadingBarProvider>
    <NDialogProvider>
      <NNotificationProvider>
        <NMessageProvider>
          <ContextHolder />
          <slot></slot>
        </NMessageProvider>
      </NNotificationProvider>
    </NDialogProvider>
  </NLoadingBarProvider>
</template>

<style scoped></style>
