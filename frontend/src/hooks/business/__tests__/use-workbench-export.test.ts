import { describe, it, expect, vi } from 'vitest';

// mock 请求层，避免拉起 axios 实例链
vi.mock('@/service/request/shared', () => ({
  getAuthorization: vi.fn(() => 'Bearer test-token')
}));

import {
  collectColumnFilters,
  collectMergeColumns,
  buildExportFilters,
  convertAgGridTextFilterToBackend,
  convertAgGridNumberFilterToBackend,
  convertAgGridDateFilterToBackend
} from '../use-workbench-export';

/** 构造 mock gridApi：filterModel 可控 */
function makeGridApi(filterModel: Record<string, any> = {}) {
  return {
    isDestroyed: () => false,
    getFilterModel: () => filterModel
  } as any;
}

describe('collectColumnFilters（收集列筛选转后端格式）', () => {
  it('gridApi 为 null 时返回空数组', () => {
    expect(collectColumnFilters(null)).toEqual([]);
  });

  it('gridApi 已销毁时返回空数组', () => {
    const api = { isDestroyed: () => true, getFilterModel: () => ({}) } as any;
    expect(collectColumnFilters(api)).toEqual([]);
  });

  it('无筛选条件时返回空数组', () => {
    expect(collectColumnFilters(makeGridApi({}))).toEqual([]);
  });

  it('文本单条件 contains：转换为 { fieldKey, operator, value }', () => {
    const api = makeGridApi({
      姓名: { filterType: 'text', type: 'contains', filter: '张' }
    });
    expect(collectColumnFilters(api)).toEqual([{ fieldKey: '姓名', operator: 'contains', value: '张' }]);
  });

  it('数值 inRange：拆为 >= min 和 <= max 两条（后端 AND 连接）', () => {
    const api = makeGridApi({
      年龄: { filterType: 'number', type: 'inRange', filter: 20, filterTo: 30 }
    });
    expect(collectColumnFilters(api)).toEqual([
      { fieldKey: '年龄', operator: 'greaterThanOrEqual', value: '20' },
      { fieldKey: '年龄', operator: 'lessThanOrEqual', value: '30' }
    ]);
  });

  it('日期 inRange：同数值逻辑拆两条', () => {
    const api = makeGridApi({
      日期: { filterType: 'date', type: 'inRange', filter: '2024-01-01', filterTo: '2024-01-31' }
    });
    expect(collectColumnFilters(api)).toEqual([
      { fieldKey: '日期', operator: 'greaterThanOrEqual', value: '2024-01-01' },
      { fieldKey: '日期', operator: 'lessThanOrEqual', value: '2024-01-31' }
    ]);
  });

  it('多列筛选：全部收集且保持列顺序', () => {
    const api = makeGridApi({
      姓名: { filterType: 'text', type: 'equals', filter: '李四' },
      年龄: { filterType: 'number', type: 'greaterThan', filter: 25 }
    });
    const result = collectColumnFilters(api);
    expect(result).toHaveLength(2);
    expect(result[0]).toEqual({ fieldKey: '姓名', operator: 'equals', value: '李四' });
    expect(result[1]).toEqual({ fieldKey: '年龄', operator: 'greaterThan', value: '25' });
  });

  it('未知 filterType 的列被跳过', () => {
    const api = makeGridApi({
      set: { filterType: 'set', values: ['a', 'b'] }
    });
    expect(collectColumnFilters(api)).toEqual([]);
  });
});

describe('convertAgGridTextFilterToBackend（文本筛选转换）', () => {
  it('OR 组合两条条件：合并为 fieldOrFilter', () => {
    const model = {
      filterType: 'text',
      operator: 'OR',
      condition1: { type: 'contains', filter: '张' },
      condition2: { type: 'contains', filter: '李' }
    };
    expect(convertAgGridTextFilterToBackend('姓名', model)).toEqual([
      {
        fieldOrFilter: {
          fieldKey: '姓名',
          conditions: [
            { operator: 'contains', value: '张' },
            { operator: 'contains', value: '李' }
          ]
        }
      }
    ]);
  });

  it('AND 组合两条条件：展开为多条独立 filter', () => {
    const model = {
      filterType: 'text',
      operator: 'AND',
      condition1: { type: 'startsWith', filter: 'A' },
      condition2: { type: 'endsWith', filter: 'Z' }
    };
    expect(convertAgGridTextFilterToBackend('编号', model)).toEqual([
      { fieldKey: '编号', operator: 'startsWith', value: 'A' },
      { fieldKey: '编号', operator: 'endsWith', value: 'Z' }
    ]);
  });

  it('空值筛选 blank：映射为 isNull 且 value 为空字符串', () => {
    const model = { filterType: 'text', type: 'blank' };
    expect(convertAgGridTextFilterToBackend('备注', model)).toEqual([
      { fieldKey: '备注', operator: 'isNull', value: '' }
    ]);
  });

  it('非空筛选 notBlank：映射为 isNotNull', () => {
    const model = { filterType: 'text', type: 'notBlank' };
    expect(convertAgGridTextFilterToBackend('备注', model)).toEqual([
      { fieldKey: '备注', operator: 'isNotNull', value: '' }
    ]);
  });

  it('filter 为空字符串的条件被跳过', () => {
    const model = { filterType: 'text', type: 'contains', filter: '' };
    expect(convertAgGridTextFilterToBackend('姓名', model)).toEqual([]);
  });

  it('非 text 类型返回空数组', () => {
    expect(convertAgGridTextFilterToBackend('姓名', { filterType: 'number', type: 'equals', filter: 1 })).toEqual([]);
    expect(convertAgGridTextFilterToBackend('姓名', null)).toEqual([]);
  });
});

describe('convertAgGridNumberFilterToBackend（数值筛选转换）', () => {
  it('inRange 只有 min：仅生成 >= 一条', () => {
    const model = { filterType: 'number', type: 'inRange', filter: 100, filterTo: undefined };
    expect(convertAgGridNumberFilterToBackend('年龄', model)).toEqual([
      { fieldKey: '年龄', operator: 'greaterThanOrEqual', value: '100' }
    ]);
  });

  it('inRange 只有 max：仅生成 <= 一条', () => {
    const model = { filterType: 'number', type: 'inRange', filter: undefined, filterTo: 200 };
    expect(convertAgGridNumberFilterToBackend('年龄', model)).toEqual([
      { fieldKey: '年龄', operator: 'lessThanOrEqual', value: '200' }
    ]);
  });

  it('数值单条件：value 转字符串', () => {
    const model = { filterType: 'number', type: 'lessThan', filter: 50 };
    expect(convertAgGridNumberFilterToBackend('年龄', model)).toEqual([
      { fieldKey: '年龄', operator: 'lessThan', value: '50' }
    ]);
  });
});

describe('convertAgGridDateFilterToBackend（日期筛选转换）', () => {
  it('日期单条件 equals', () => {
    const model = { filterType: 'date', type: 'equals', filter: '2024-06-01' };
    expect(convertAgGridDateFilterToBackend('日期', model)).toEqual([
      { fieldKey: '日期', operator: 'equals', value: '2024-06-01' }
    ]);
  });
});

describe('buildExportFilters（导出筛选组合：与页面显示对齐）', () => {
  const baseOpts = {
    gridApi: null,
    selectedField: '',
    selectedOperator: 'contains',
    selectedValue: '',
    quickKeyword: ''
  };

  it('全空输入返回空数组', () => {
    expect(buildExportFilters(baseOpts)).toEqual([]);
  });

  it('三者同时存在：列筛选优先，其后条件面板，最后快速检索', () => {
    const gridApi = makeGridApi({
      姓名: { filterType: 'text', type: 'contains', filter: '张' }
    });
    const result = buildExportFilters({
      gridApi,
      selectedField: '年龄',
      selectedOperator: 'greaterThan',
      selectedValue: ' 25 ',
      quickKeyword: ' 关键词 '
    });
    expect(result).toEqual([
      { fieldKey: '姓名', operator: 'contains', value: '张' },
      { fieldKey: '年龄', operator: 'greaterThan', value: '25' },
      { globalSearch: '关键词' }
    ]);
  });

  it('gridApi 已销毁时跳过列筛选（不报错）', () => {
    const destroyed = { isDestroyed: () => true, getFilterModel: () => ({}) } as any;
    const result = buildExportFilters({ ...baseOpts, gridApi: destroyed, quickKeyword: 'kw' });
    expect(result).toEqual([{ globalSearch: 'kw' }]);
  });

  it('条件面板：selectedValue 空白（trim 后为空）时不加入', () => {
    const result = buildExportFilters({ ...baseOpts, selectedField: '年龄', selectedValue: '   ' });
    expect(result).toEqual([]);
  });

  it('条件面板：仅有 selectedValue 而 selectedField 为空时不加入', () => {
    const result = buildExportFilters({ ...baseOpts, selectedValue: '25' });
    expect(result).toEqual([]);
  });

  it('快速检索：空白（trim 后为空）时不加入', () => {
    const result = buildExportFilters({ ...baseOpts, quickKeyword: '   ' });
    expect(result).toEqual([]);
  });
});

describe('collectMergeColumns（收集跨行合并列）', () => {
  function makeGridApiWithColumns(columns: Array<Record<string, any>>) {
    return {
      isDestroyed: () => false,
      getColumns: () => columns.map(col => ({ getColDef: () => col }))
    } as any;
  }

  it('spanRows=true 的可见列被收集', () => {
    const api = makeGridApiWithColumns([
      { field: '序号' },
      { field: '部门', spanRows: true },
      { field: '姓名', spanRows: true, hide: false }
    ]);
    expect(collectMergeColumns(api)).toEqual(['部门', '姓名']);
  });

  it('隐藏列与无 spanRows 列被排除', () => {
    const api = makeGridApiWithColumns([
      { field: '部门', spanRows: true, hide: true },
      { field: '姓名' },
      { spanRows: true }
    ]);
    expect(collectMergeColumns(api)).toEqual([]);
  });

  it('gridApi 为 null 或已销毁返回空数组', () => {
    expect(collectMergeColumns(null)).toEqual([]);
    expect(collectMergeColumns({ isDestroyed: () => true } as any)).toEqual([]);
  });
});
