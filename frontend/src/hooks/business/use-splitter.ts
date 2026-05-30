import { ref, onMounted } from 'vue';

export interface SplitterOptions {
  defaultWidth?: number;
  minWidth?: number;
  maxWidth?: number;
  storageKey?: string;
}

export function useSplitter(options: SplitterOptions = {}) {
  const { defaultWidth = 320, minWidth = 200, maxWidth = 600, storageKey } = options;

  const leftWidth = ref(defaultWidth);
  const isResizing = ref(false);

  const minLeftWidth = minWidth;
  const maxLeftWidth = maxWidth;

  function startResize(e: MouseEvent) {
    isResizing.value = true;
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';

    const startX = e.clientX;
    const startWidth = leftWidth.value;

    function onMouseMove(moveEvent: MouseEvent) {
      if (!isResizing.value) return;
      const delta = moveEvent.clientX - startX;
      const newWidth = Math.max(minLeftWidth, Math.min(maxLeftWidth, startWidth + delta));
      leftWidth.value = newWidth;
    }

    function onMouseUp() {
      isResizing.value = false;
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);

      if (storageKey) {
        localStorage.setItem(storageKey, String(leftWidth.value));
      }
    }

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
  }

  function loadSavedWidth() {
    if (storageKey) {
      const savedWidth = localStorage.getItem(storageKey);
      if (savedWidth) {
        const width = parseFloat(savedWidth);
        if (width >= minLeftWidth && width <= maxLeftWidth) {
          leftWidth.value = width;
        }
      }
    }
  }

  onMounted(() => {
    loadSavedWidth();
  });

  return {
    leftWidth,
    isResizing,
    startResize
  };
}
