import { ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

interface ColorMarkFieldOption {
  label: string;
  value: string;
}

type ColorMarkOperator = '大于' | '小于' | '等于';
type ColorMarkColor = '白底红字' | '白底蓝字' | '黄底红色';

interface ColorMarkConfig {
  field1: string;
  operator: string;
  field2: string;
  style: Record<string, string>;
}

interface UseColorMarkOptions {
  colorMarkEnabledColumns: Ref<ColorMarkFieldOption[]>;
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
}

export function useColorMark(options: UseColorMarkOptions) {
  const colorMarkVisible = ref(false);
  const colorMarkField1 = ref('');
  const colorMarkOperator = ref<ColorMarkOperator>('大于');
  const colorMarkField2 = ref('');
  const colorMarkColor = ref<ColorMarkColor>('白底红字');
  const colorMarkConfig = ref<ColorMarkConfig | null>(null);

  function resetColorMarkState() {
    colorMarkConfig.value = null;
    colorMarkField1.value = options.colorMarkEnabledColumns.value[0]?.value || '';
    colorMarkField2.value = options.colorMarkEnabledColumns.value[0]?.value || '';
    colorMarkOperator.value = '大于';
    colorMarkColor.value = '白底红字';
  }

  function handleOpenColorMark() {
    if (options.colorMarkEnabledColumns.value.length > 0) {
      colorMarkField1.value = options.colorMarkEnabledColumns.value[0]?.value || '';
      colorMarkField2.value = options.colorMarkEnabledColumns.value[0]?.value || '';
    }
    colorMarkVisible.value = true;
  }

  function handleApplyColorMark() {
    if (!colorMarkField1.value || !colorMarkField2.value) {
      options.notify('warning', '请选择字段一和字段二');
      return;
    }

    let style: Record<string, string> = { color: 'red', fontWeight: 'bold' };
    if (colorMarkColor.value === '白底蓝字') {
      style = { color: 'blue', fontWeight: 'bold' };
    } else if (colorMarkColor.value === '黄底红色') {
      style = { backgroundColor: 'yellow', color: 'red', fontWeight: 'bold' };
    }

    colorMarkConfig.value = {
      field1: colorMarkField1.value,
      operator: colorMarkOperator.value,
      field2: colorMarkField2.value,
      style
    };

    if (options.gridApi.value) {
      options.gridApi.value.refreshCells({ force: true });
    }

    colorMarkVisible.value = false;
    options.notify('success', '颜色标注已应用');
  }

  function handleClearColorMark() {
    colorMarkConfig.value = null;
    if (options.gridApi.value) {
      options.gridApi.value.refreshCells({ force: true });
    }
    colorMarkVisible.value = false;
    options.notify('success', '颜色标注已清除');
  }

  return {
    colorMarkVisible,
    colorMarkField1,
    colorMarkOperator,
    colorMarkField2,
    colorMarkColor,
    colorMarkConfig,
    resetColorMarkState,
    handleOpenColorMark,
    handleApplyColorMark,
    handleClearColorMark
  };
}
