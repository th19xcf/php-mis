import { ref, h } from 'vue';
import type { Ref } from 'vue';
import { useRouter } from 'vue-router';
import type { GridApi } from 'ag-grid-community';
import { NRadio, NRadioGroup, NButton } from 'naive-ui';

import { fetchWorkbenchDrill } from '@/service/api/workbench';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchDrillDialogOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  loading: Ref<boolean>;
  notify: (type: NotifyType, message: string, data?: unknown) => void;
}

interface PreparedDrillOption {
  label: string;
  value: string;
  functionCode: string;
  module: string;
  drillFields: string;
  drillCondition: string;
  menu1: string;
  menu2: string;
  raw: Api.Workbench.DrillOption;
}

/**
 * 数据钻取（表格行 → 跳转新功能页）
 *
 * 完整复刻旧版 Vgrid_aggrid.php 中的数据钻取流程：
 *   1. 必须先选择 1 条记录
 *   2. 调用 /workbench/drill 获取该 functionCode 下的钻取选项
 *   3. 弹出单选按钮对话框让用户选择
 *   4. 根据 drillFields 从选中行取值，构造跳转参数后 router.push 到 /menu-bridge
 */
export function useWorkbenchDrillDialog(options: UseWorkbenchDrillDialogOptions) {
  const router = useRouter();

  function getSelectedRow(): Api.Workbench.QueryRecord | null {
    const rows = options.gridApi.value?.getSelectedRows() || [];
    if (rows.length === 0) {
      options.notify('warning', '请先选择要钻取的记录');
      return null;
    }
    if (rows.length > 1) {
      options.notify('warning', '只能选择 1 条记录');
      return null;
    }
    return rows[0];
  }

  function collectVisibleColumns(): string[] {
    const api = options.gridApi.value;
    if (!api) return [];
    const visible: string[] = [];
    const columns = api.getColumns() || [];
    for (const col of columns) {
      const colId = col.getColId();
      if (colId === 'ag-Grid-SelectionColumn') continue;
      if (colId === '序号') continue;
      if (!col.isVisible()) continue;
      visible.push(colId);
    }
    return visible;
  }

  function buildSendObject(
    drillItem: Api.Workbench.DrillOption,
    selectedRow: Api.Workbench.QueryRecord
  ): Record<string, any> | null {
    const sendObj: Record<string, any> = {};
    const drillFieldsStr = drillItem.drillFields || '';
    const fields = drillFieldsStr.split(';').filter(f => f.trim());

    let hasValidField = false;
    for (const field of fields) {
      const trimmed = field.trim();
      if (trimmed && selectedRow[trimmed] !== undefined && selectedRow[trimmed] !== '') {
        sendObj[trimmed] = selectedRow[trimmed];
        hasValidField = true;
      }
    }

    if (!hasValidField) {
      options.notify('warning', '钻取字段为空，无法钻取');
      return null;
    }

    sendObj['钻取字段'] = drillItem.drillFields || '';
    sendObj['钻取条件'] = drillItem.drillCondition || '';
    sendObj['字段选择'] = collectVisibleColumns();
    return sendObj;
  }

  function navigateToDrillTarget(drillItem: Api.Workbench.DrillOption, sendObj: Record<string, any>) {
    router.push({
      path: '/menu-bridge',
      query: {
        functionCode: drillItem.functionCode,
        module: drillItem.module || '',
        menu1: drillItem.menu1 || '',
        menu2: drillItem.menu2 || '',
        params: JSON.stringify(sendObj)
      }
    });
  }

  function showDrillOptionsDialog(options_: PreparedDrillOption[], selectedRow: Api.Workbench.QueryRecord) {
    const selectedValue = ref<string>(options_[0]?.value || '');
    const selectedOption = ref<PreparedDrillOption | null>(options_[0] || null);

    let dialogInstance: any = null;

    const handleConfirm = () => {
      if (!selectedOption.value) {
        options.notify('warning', '请选择钻取条件');
        return;
      }
      const drillItem = selectedOption.value.raw;
      const sendObj = buildSendObject(drillItem, selectedRow);
      if (!sendObj) return;
      if (dialogInstance) {
        dialogInstance.destroy();
      }
      navigateToDrillTarget(drillItem, sendObj);
    };

    const renderContent = () =>
      h('div', { style: { display: 'flex', flexDirection: 'column', minHeight: '250px' } }, [
        h(
          NRadioGroup,
          {
            value: selectedValue.value,
            'onUpdate:value': (val: string) => {
              selectedValue.value = val;
              selectedOption.value = options_.find(o => o.value === val) || null;
            },
            style: { flex: 1, overflow: 'auto', padding: '16px' }
          },
          {
            default: () =>
              options_.map(opt =>
                h(
                  NRadio,
                  {
                    value: opt.value,
                    style: { display: 'flex', marginBottom: '8px', alignItems: 'center' }
                  },
                  { default: () => opt.label }
                )
              )
          }
        ),
        h(
          'div',
          {
            style: {
              display: 'flex',
              justifyContent: 'flex-end',
              padding: '16px',
              borderTop: '1px solid #3d4f60'
            }
          },
          h(
            NButton,
            {
              type: 'primary',
              onClick: handleConfirm
            },
            { default: () => '确定' }
          )
        )
      ]);

    dialogInstance = window.$dialog?.info({
      title: '选择钻取条件',
      style: { width: '350px', minHeight: '300px' },
      content: renderContent
    });
  }

  function showEmptyDrillHint(data: Api.Workbench.DrillData) {
    const drillModule = data.debug?.drillModule || 'empty';
    const queryModule = data.debug?.queryModule || 'empty';
    if (drillModule && drillModule !== 'empty' && drillModule !== queryModule) {
      options.notify('warning', `钻取模块 [${drillModule}] 在 def_drill_config 表中未找到配置`);
    } else if (queryModule && queryModule !== 'empty') {
      options.notify('warning', `查询模块 [${queryModule}] 未配置钻取模块，且 def_drill_config 表中也无对应配置`);
    } else {
      options.notify('warning', '当前功能未配置钻取模块，请联系管理员');
    }
  }

  /**
   * 打开数据钻取对话框
   *  - 自动校验选中记录
   *  - 拉取钻取选项后弹出选择框
   *  - 选中后构造跳转参数并 router.push
   */
  async function openDataDrill() {
    const selectedRow = getSelectedRow();
    if (!selectedRow) return;

    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    options.loading.value = true;
    try {
      const { data, error } = await fetchWorkbenchDrill(functionCode, {});
      options.loading.value = false;
      if (error) {
        options.notify('error', '获取钻取选项失败', error);
        return;
      }
      if (!data) {
        options.notify('error', '获取钻取选项失败');
        return;
      }

      if (data.options && data.options.length > 0) {
        const prepared: PreparedDrillOption[] = data.options.map((opt: Api.Workbench.DrillOption, index: number) => ({
          label: opt.label,
          value: `${opt.functionCode}_${index}`,
          functionCode: opt.functionCode,
          module: opt.module || '',
          drillFields: opt.drillFields || '',
          drillCondition: opt.drillCondition || '',
          menu1: opt.menu1 || '',
          menu2: opt.menu2 || '',
          raw: opt
        }));
        showDrillOptionsDialog(prepared, selectedRow);
      } else {
        showEmptyDrillHint(data);
      }
    } catch (err) {
      options.loading.value = false;
      options.notify('error', '钻取操作失败', err);
    }
  }

  return { openDataDrill };
}
