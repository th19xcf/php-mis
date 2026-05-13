import { ref } from 'vue';

import { fetchPopupLevelData, fetchPopupLevels } from '@/service/api/workbench';

interface UseWorkbenchPopupCascaderOptions {
  getFunctionCode: () => string;
  onConfirmSelection: (fieldName: string, value: string) => void;
  notifyError: (message: string) => void;
}

export function useWorkbenchPopupCascader(options: UseWorkbenchPopupCascaderOptions) {
  const popupVisible = ref(false);
  const popupLoading = ref(false);
  const popupField = ref<any>(null);
  const popupLevels = ref<Api.Workbench.PopupLevel[]>([]);
  const popupMaxLevel = ref(1);
  const popupCascaderOptions = ref<any[]>([]);
  const popupSelectedValue = ref<string | null>(null);

  async function handleOpenPopup(field: any) {
    popupField.value = field;
    popupVisible.value = true;
    popupLoading.value = true;
    popupSelectedValue.value = null;
    popupCascaderOptions.value = [];
    popupLevels.value = [];
    popupMaxLevel.value = 1;

    try {
      const functionCode = options.getFunctionCode();
      if (!functionCode || !field.objectName) {
        popupLoading.value = false;
        return;
      }

      const { data: levelsData, error: levelsError } = await fetchPopupLevels(functionCode, field.objectName);
      if (levelsError) {
        options.notifyError(levelsError.message || '获取弹窗级别配置失败');
        popupLoading.value = false;
        return;
      }

      popupLevels.value = levelsData.levels;
      popupMaxLevel.value = levelsData.maxLevel;

      const { data: levelData, error: levelError } = await fetchPopupLevelData(functionCode, field.objectName, 1, '');
      if (levelError) {
        options.notifyError(levelError.message || '获取弹窗数据失败');
        popupLoading.value = false;
        return;
      }

      popupCascaderOptions.value = levelData.items.map(item => {
        return {
          label: item.name,
          value: item.code || item.name,
          code: item.code,
          fullName: item.fullName,
          level: 1,
          isLeaf: !item.hasChildren
        };
      });
    } catch (e: any) {
      options.notifyError(e.message || '获取弹窗数据失败');
    } finally {
      popupLoading.value = false;
    }
  }

  function handleLoadCascaderChildren(option: any) {
    const functionCode = options.getFunctionCode();
    const objectName = popupField.value?.objectName;

    if (!functionCode || !objectName) {
      return Promise.resolve();
    }

    const nextLevel = option.level + 1;
    if (nextLevel > popupMaxLevel.value) {
      option.isLeaf = true;
      return Promise.resolve();
    }

    const parentCode = option.fullName || option.value;
    return fetchPopupLevelData(functionCode, objectName, nextLevel, parentCode)
      .then(({ data, error }) => {
        if (error) {
          options.notifyError(error.message || '加载子节点失败');
          return;
        }

        option.children = data.items.map((item: any) => {
          const isLastLevel = nextLevel >= popupMaxLevel.value;
          return {
            label: item.name,
            value: item.code || item.name,
            code: item.code,
            fullName: item.fullName,
            level: nextLevel,
            isLeaf: !item.hasChildren || isLastLevel
          };
        });
      })
      .catch((e: any) => {
        options.notifyError(e.message || '加载子节点失败');
      });
  }

  function handleCascaderValueChange(_value: string | null, _option: any) {}

  function findCascaderOption(optionsList: any[], value: string): any | null {
    for (const option of optionsList) {
      if (option.value === value) {
        return option;
      }
      if (option.children) {
        const found = findCascaderOption(option.children, value);
        if (found) return found;
      }
    }
    return null;
  }

  function confirmPopupSelection() {
    if (!popupField.value || !popupSelectedValue.value) return;

    const selectedOption = findCascaderOption(popupCascaderOptions.value, popupSelectedValue.value);
    if (selectedOption) {
      const selectedLabel = selectedOption.fullName || selectedOption.label;
      options.onConfirmSelection(popupField.value.fieldName, selectedLabel);
    }

    popupVisible.value = false;
  }

  return {
    popupVisible,
    popupLoading,
    popupField,
    popupLevels,
    popupMaxLevel,
    popupCascaderOptions,
    popupSelectedValue,
    handleOpenPopup,
    handleLoadCascaderChildren,
    handleCascaderValueChange,
    confirmPopupSelection
  };
}