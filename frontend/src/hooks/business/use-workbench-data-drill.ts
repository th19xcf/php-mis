import { ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import { h } from 'vue';
import { NRadioGroup, NRadio, NButton } from 'naive-ui';
import { useRouter } from 'vue-router';

import { fetchWorkbenchDrill } from '@/service/api/workbench';
import { logger } from '@/utils/logger';

type MessageType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchDataDrillOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  notify: (type: MessageType, message: string, data?: unknown) => void;
  loading: Ref<boolean>;
}

export function useWorkbenchDataDrill(options: UseWorkbenchDataDrillOptions) {
  const router = useRouter();

  function log(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    const prefix = `[${timestamp}] [DATA-DRILL] [${method.toUpperCase()}]`;

    if (data !== undefined) {
      logger.info(`${prefix} ${message}`, data);
    } else {
      logger.info(`${prefix} ${message}`);
    }
  }

  async function handleDataDrill() {
    log('info', `========== handleDataDrill 开始 ==========`);

    const selectedRows = options.gridApi.value?.getSelectedRows() || [];

    if (selectedRows.length === 0) {
      log('warn', '未选择任何记录');
      options.notify('warning', '请先选择要钻取的记录');
      return;
    }

    if (selectedRows.length > 1) {
      log('warn', `选择了 ${selectedRows.length} 条记录，超过限制`);
      options.notify('warning', '只能选择 1 条记录');
      return;
    }

    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      log('error', '功能编码为空');
      options.notify('error', '功能编码不能为空');
      return;
    }

    const selectedRow = selectedRows[0];
    log('info', `选中记录: rowId=${selectedRow.GUID || selectedRow.id || 'unknown'}`);

    options.loading.value = true;
    log('debug', `设置 loading = true`);

    try {
      log('info', `获取钻取选项: fetchWorkbenchDrill("${functionCode}")`);
      const { data, error } = await fetchWorkbenchDrill(functionCode, {});

      options.loading.value = false;
      log('debug', `设置 loading = false`);

      if (error) {
        log('error', `获取钻取选项失败:`, error);
        options.notify('error', '获取钻取选项失败', error);
        return;
      }

      if (data.options && data.options.length > 0) {
        log('info', `获取到 ${data.options.length} 个钻取选项`);
        await showDrillOptionsDialog(data.options, selectedRow);
      } else {
        handleNoDrillOptions(data);
      }
    } catch (err) {
      options.loading.value = false;
      log('error', `钻取操作异常:`, err);
      options.notify('error', '钻取操作失败', err);
    }

    log('info', `========== handleDataDrill 结束 ==========`);
  }

  function handleNoDrillOptions(data: any) {
    const drillModule = data.debug?.drillModule || 'empty';
    const queryModule = data.debug?.queryModule || 'empty';

    log('warn', `未找到钻取选项, drillModule="${drillModule}", queryModule="${queryModule}"`);

    if (drillModule && drillModule !== 'empty' && drillModule !== queryModule) {
      options.notify('warning', `钻取模块 [${drillModule}] 在 def_drill_config 表中未找到配置`);
    } else if (queryModule && queryModule !== 'empty') {
      options.notify('warning', `查询模块 [${queryModule}] 未配置钻取模块，且 def_drill_config 表中也无对应配置`);
    } else {
      options.notify('warning', '当前功能未配置钻取模块，请联系管理员');
    }
  }

  async function showDrillOptionsDialog(
    optionsList: Api.Workbench.DrillOption[],
    selectedRow: Api.Workbench.QueryRecord
  ) {
    const drillOptions = optionsList.map((opt: Api.Workbench.DrillOption, index: number) => ({
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

    const selectedOption = ref<(typeof drillOptions)[0] | null>(drillOptions[0] || null);
    const drillSelectedValue = ref<string>(drillOptions[0]?.value || '');

    const handleDrillConfirm = (selectedOpt: (typeof drillOptions)[0]) => {
      log('info', `开始钻取确认, functionCode=${selectedOpt.functionCode}`);

      const drillItem = selectedOpt.raw;
      const drillFieldsStr = drillItem.drillFields || '';
      const sendObj: Record<string, any> = {};

      const nlArr = drillFieldsStr.split(';').filter((f: string) => f.trim());
      let hasValidField = false;

      for (const field of nlArr) {
        const trimmedField = field.trim();
        if (trimmedField && selectedRow[trimmedField] !== undefined && selectedRow[trimmedField] !== '') {
          sendObj[trimmedField] = selectedRow[trimmedField];
          hasValidField = true;
        }
      }

      if (!hasValidField) {
        log('warn', '钻取字段为空');
        options.notify('warning', '钻取字段为空，无法钻取');
        return;
      }

      sendObj['钻取字段'] = drillItem.drillFields || '';
      sendObj['钻取条件'] = drillItem.drillCondition || '';

      const visibleColumns: string[] = [];
      const columns = options.gridApi.value?.getColumns() || [];
      for (const col of columns) {
        if (col.getColId() === 'ag-Grid-SelectionColumn') continue;
        if (col.getColId() === '序号') continue;
        if (!col.isVisible()) continue;
        visibleColumns.push(col.getColId());
      }
      sendObj['字段选择'] = visibleColumns;

      const targetFunctionCode = drillItem.functionCode;
      const targetModule = drillItem.module || '';
      const targetMenu1 = drillItem.menu1 || '';
      const targetMenu2 = drillItem.menu2 || '';

      log('info',
        `跳转参数: functionCode=${targetFunctionCode}, module=${targetModule}, menu1=${targetMenu1}, menu2=${targetMenu2}`
      );
      log('debug', `钻取参数:`, sendObj);

      router.push({
        path: `/menu-bridge`,
        query: {
          functionCode: targetFunctionCode,
          module: targetModule,
          menu1: targetMenu1,
          menu2: targetMenu2,
          params: JSON.stringify(sendObj)
        }
      });
    };

    const handleRadioClick = (value: string) => {
      drillSelectedValue.value = value;
      selectedOption.value = drillOptions.find((opt: any) => opt.value === value) || null;
    };

    const renderDrillDialogContent = () => {
      return h('div', { style: { display: 'flex', flexDirection: 'column', minHeight: '250px' } }, [
        h(
          NRadioGroup,
          {
            value: drillSelectedValue.value,
            'onUpdate:value': (value: string) => {
              handleRadioClick(value);
            },
            style: { flex: 1, overflow: 'auto', padding: '16px' }
          },
          {
            default: () =>
              drillOptions.map((opt: any) =>
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
            style: { display: 'flex', justifyContent: 'flex-end', padding: '16px', borderTop: '1px solid #3d4f60' }
          },
          h(
            NButton,
            {
              type: 'primary',
              onClick: () => {
                if (!selectedOption.value) {
                  options.notify('warning', '请选择钻取条件');
                  return;
                }
                if (dialogInstance) {
                  dialogInstance.destroy();
                }
                handleDrillConfirm(selectedOption.value);
              }
            },
            { default: () => '确定' }
          )
        )
      ]);
    };

    let dialogInstance: any = null;
    dialogInstance = window.$dialog?.info({
      title: '选择钻取条件',
      style: { width: '350px', minHeight: '300px' },
      content: renderDrillDialogContent
    });
  }

  return { handleDataDrill };
}
