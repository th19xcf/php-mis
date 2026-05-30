import { nextTick, onMounted, onUnmounted, ref } from 'vue';

export function useToolbarScroll() {
  const toolbarScrollRef = ref<HTMLDivElement | null>(null);
  const showLeftArrow = ref(false);
  const showRightArrow = ref(false);
  let resizeObserver: ResizeObserver | null = null;

  function checkScrollPosition() {
    nextTick(() => {
      if (!toolbarScrollRef.value) return;
      const { scrollWidth, clientWidth } = toolbarScrollRef.value;
      const hasOverflow = scrollWidth > clientWidth + 5;
      showLeftArrow.value = hasOverflow;
      showRightArrow.value = hasOverflow;
    });
  }

  function scrollToolbar(direction: 'left' | 'right') {
    if (!toolbarScrollRef.value) return;
    const scrollAmount = 150;
    const targetScrollLeft =
      direction === 'left'
        ? toolbarScrollRef.value.scrollLeft - scrollAmount
        : toolbarScrollRef.value.scrollLeft + scrollAmount;

    toolbarScrollRef.value.scrollTo({
      left: targetScrollLeft,
      behavior: 'smooth'
    });
  }

  onMounted(() => {
    setTimeout(() => {
      checkScrollPosition();
    }, 100);

    window.addEventListener('resize', checkScrollPosition);

    if (toolbarScrollRef.value && typeof ResizeObserver !== 'undefined') {
      resizeObserver = new ResizeObserver(() => {
        checkScrollPosition();
      });
      resizeObserver.observe(toolbarScrollRef.value);
    }
  });

  onUnmounted(() => {
    window.removeEventListener('resize', checkScrollPosition);
    if (resizeObserver) {
      resizeObserver.disconnect();
      resizeObserver = null;
    }
  });

  return {
    toolbarScrollRef,
    showLeftArrow,
    showRightArrow,
    checkScrollPosition,
    scrollToolbar
  };
}
