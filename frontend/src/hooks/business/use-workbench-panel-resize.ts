import { ref, onMounted } from 'vue';
import type { Ref } from 'vue';

export interface PanelResizeOptions {
  /** 容器 ref，用于拿 clientWidth */
  containerRef: Ref<HTMLElement | null>;
  /** 默认宽度（百分比，0-100） */
  defaultPercent?: number;
  /** 最小宽度（百分比） */
  minPercent?: number;
  /** 最大宽度（百分比） */
  maxPercent?: number;
  /** localStorage 存储键 */
  storageKey: string;
  /** 拖动过程中的回调（例如 chartResize） */
  onResize?: () => void;
}

/**
 * 工作台分栏拖动组合式函数
 *  - 维护一个百分比宽度 leftPanelWidth
 *  - 提供 startResize 鼠标拖动入口
 *  - onMounted 自动从 localStorage 恢复
 *  - 拖动过程中调用 onResize 让上层同步图表尺寸
 */
export function useWorkbenchPanelResize(options: PanelResizeOptions) {
  const { containerRef, defaultPercent = 55, minPercent = 15, maxPercent = 70, storageKey, onResize } = options;

  const leftPanelWidth = ref(defaultPercent);
  const isResizing = ref(false);

  function loadSavedWidth() {
    try {
      const saved = localStorage.getItem(storageKey);
      if (saved) {
        const value = parseFloat(saved);
        if (Number.isFinite(value) && value >= minPercent && value <= maxPercent) {
          leftPanelWidth.value = value;
        }
      }
    } catch {
      // localStorage 可能不可用（隐私模式），忽略
    }
  }

  function startResize(e: MouseEvent) {
    isResizing.value = true;
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';

    const startX = e.clientX;
    const containerWidth = containerRef.value?.clientWidth || window.innerWidth;
    const startLeftWidth = leftPanelWidth.value;

    function handleMouseMove(moveEvent: MouseEvent) {
      if (!isResizing.value) return;
      const deltaX = moveEvent.clientX - startX;
      const deltaPercent = (deltaX / containerWidth) * 100;
      const newLeftWidth = Math.max(minPercent, Math.min(maxPercent, startLeftWidth + deltaPercent));
      leftPanelWidth.value = newLeftWidth;
      onResize?.();
    }

    function handleMouseUp() {
      isResizing.value = false;
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
      try {
        localStorage.setItem(storageKey, String(leftPanelWidth.value));
      } catch {
        // 忽略
      }
    }

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
  }

  onMounted(() => {
    loadSavedWidth();
  });

  return {
    leftPanelWidth,
    isResizing,
    startResize
  };
}
