import { ref } from 'vue';

import { fetchPopupLevelData, fetchPopupLevels } from '@/service/api/workbench';

interface UseWorkbenchPopupCascaderOptions {
  getFunctionCode: () => string;
  /**
   * 获取指定字段在当前活动表单中的原值。
   * 「添加」模式会把新值拼到原值之后（"," 分隔），需要由父组件提供原值。
   * 父组件应基于 rightPanelMode（add / update / batch）从对应表单数据中读取。
   */
  getCurrentValue: (fieldName: string) => string;
  /**
   * 确认选择
   * @param mode  'replace' 替换：直接覆盖字段值
   *               'append'  添加：在原值后追加新值（用 "," 分隔）
   */
  onConfirmSelection: (fieldName: string, value: string, mode: 'replace' | 'append') => void;
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

  function handleLoadCascaderChildren(option: any, resolve: () => void) {
    const functionCode = options.getFunctionCode();
    const objectName = popupField.value?.objectName;

    if (!functionCode || !objectName) {
      resolve();
      return Promise.resolve();
    }

    const nextLevel = option.level + 1;
    if (nextLevel > popupMaxLevel.value) {
      option.isLeaf = true;
      resolve();
      return Promise.resolve();
    }

    const parentCode = option.fullName || option.value;
    // 必须在 fetch 完成、把 children / isLeaf 全部写到 option 之后才调用 resolve，
    // 这样 NCascader 才会把节点登记为已加载的叶子节点，末级节点也才能被点击选中。
    fetchPopupLevelData(functionCode, objectName, nextLevel, parentCode)
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
      })
      .finally(() => {
        resolve();
      });
  }

  function handleCascaderValueChange(value: string | null, _option: any) {
    // 必须把 cascader 选中的值同步回 popupSelectedValue，
    // 否则：1) 顶部输入框的"请选择"占位符不会更新；
    //      2) confirmPopupSelection 内部判空直接 return，导致选不中。
    popupSelectedValue.value = value;
  }

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

  /**
   * 替换：直接把 cascader 选中的 fullName 写回字段
   */
  function replacePopupSelection() {
    if (!popupField.value || !popupSelectedValue.value) return;

    const selectedOption = findCascaderOption(popupCascaderOptions.value, popupSelectedValue.value);
    if (selectedOption) {
      const selectedLabel = selectedOption.fullName || selectedOption.label;
      options.onConfirmSelection(popupField.value.fieldName, selectedLabel, 'replace');
    }

    popupVisible.value = false;
  }

  /**
   * 添加：把 cascader 选中的 fullName 拼到原值之后（用 "," 分隔）
   * - 原值为空时退化为替换
   * - 原值与新值已重复时不重复添加（去重）
   */
  function appendPopupSelection() {
    if (!popupField.value || !popupSelectedValue.value) return;

    const selectedOption = findCascaderOption(popupCascaderOptions.value, popupSelectedValue.value);
    if (!selectedOption) {
      popupVisible.value = false;
      return;
    }

    const selectedLabel = selectedOption.fullName || selectedOption.label;
    const currentValue = String(options.getCurrentValue(popupField.value.fieldName) || '').trim();
    let finalValue: string;
    if (!currentValue) {
      finalValue = selectedLabel;
    } else {
      // 按 "," 拆原值去重后再拼，避免出现 "A,B,A"
      const parts = currentValue
        .split(',')
        .map(part => part.trim())
        .filter(Boolean);
      if (parts.includes(selectedLabel)) {
        finalValue = parts.join(',');
      } else {
        parts.push(selectedLabel);
        finalValue = parts.join(',');
      }
    }

    options.onConfirmSelection(popupField.value.fieldName, finalValue, 'append');
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
    replacePopupSelection,
    appendPopupSelection
  };
}
