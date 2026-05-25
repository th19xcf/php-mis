import { ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

interface ColumnOption {
  label: string;
  value: string | number;
}

interface UseWorkbenchColumnSettingsOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  fieldColumnOptions: Ref<ColumnOption[]>;
  pinColumnOptions: Ref<ColumnOption[]>;
}

export function useWorkbenchColumnSettings(options: UseWorkbenchColumnSettingsOptions) {
  const fieldColumnVisible = ref(false);
  const visibleFieldColumns = ref<string[]>([]);
  const pinColumnVisible = ref(false);
  const pinTargetFields = ref<string[]>([]);

  function handleOpenFieldColumn() {
    if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
      const visibleFields = options.gridApi.value
        .getColumnState()
        .filter(item => item.hide !== true)
        .map(item => String(item.colId));

      visibleFieldColumns.value = visibleFields;
    } else if (visibleFieldColumns.value.length === 0) {
      visibleFieldColumns.value = options.fieldColumnOptions.value.map(item => String(item.value));
    }

    fieldColumnVisible.value = true;
  }

  function handleSelectAllFieldColumns() {
    handleFieldSelectionChange(options.fieldColumnOptions.value.map(item => String(item.value)));
  }

  function handleClearFieldColumns() {
    handleFieldSelectionChange([]);
  }

  function handleFieldSelectionChange(values: Array<string | number>) {
    const normalizedValues = values.map(value => String(value));
    visibleFieldColumns.value = normalizedValues;

    if (!options.gridApi.value) {
      return;
    }

    const allColumnFields = options.fieldColumnOptions.value.map(item => String(item.value));
    const selectedSet = new Set(normalizedValues);
    const toShow = allColumnFields.filter(field => selectedSet.has(field));
    const toHide = allColumnFields.filter(field => !selectedSet.has(field));

    if (toShow.length > 0) {
      options.gridApi.value.setColumnsVisible(toShow, true);
    }

    if (toHide.length > 0) {
      options.gridApi.value.setColumnsVisible(toHide, false);
    }
  }

  function handleOpenPinColumn() {
    if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
      const pinnedLeft = options.gridApi.value
        .getColumnState()
        .filter(item => item.pinned === 'left')
        .map(item => String(item.colId));

      pinTargetFields.value = pinnedLeft;
    } else if (pinTargetFields.value.length === 0) {
      pinTargetFields.value = [];
    }

    pinColumnVisible.value = true;
  }

  function handleClearPinColumns() {
    handlePinSelectionChange([]);
  }

  function handlePinSelectionChange(values: Array<string | number>) {
    const normalizedValues = values.map(value => String(value));
    pinTargetFields.value = normalizedValues;

    if (!options.gridApi.value) {
      return;
    }

    const allColumnFields = options.pinColumnOptions.value.map(item => String(item.value));
    const selectedSet = new Set(normalizedValues);
    const toPin = allColumnFields.filter(field => selectedSet.has(field));
    const toUnpin = allColumnFields.filter(field => !selectedSet.has(field));

    if (toPin.length > 0) {
      options.gridApi.value.setColumnsPinned(toPin, 'left');
    }

    if (toUnpin.length > 0) {
      options.gridApi.value.setColumnsPinned(toUnpin, null);
    }
  }

  return {
    fieldColumnVisible,
    visibleFieldColumns,
    pinColumnVisible,
    pinTargetFields,
    handleOpenFieldColumn,
    handleSelectAllFieldColumns,
    handleClearFieldColumns,
    handleFieldSelectionChange,
    handleOpenPinColumn,
    handleClearPinColumns,
    handlePinSelectionChange
  };
}
