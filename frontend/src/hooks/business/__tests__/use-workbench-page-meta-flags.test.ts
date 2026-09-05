import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useWorkbenchPageMetaFlags } from '../use-workbench-page-meta-flags';

/** 构造最小 PageMeta fixture */
function makePageMeta(overrides: Partial<Api.Workbench.PageMeta> = {}): Api.Workbench.PageMeta {
  return {
    functionCode: '2025',
    title: '面试人员',
    menu1: '人事',
    menu2: '面试',
    module: 'interview',
    params: '',
    fieldModule: '',
    commentModule: '',
    chartModule: '',
    dataTable: 'ee_interview',
    primaryKey: '面试编号',
    toolbar: {
      comment: false,
      add: false,
      edit: false,
      batchEdit: false,
      delete: false,
      import: false,
      export: false,
      tableEdit: false
    } as Api.Workbench.ToolbarMeta,
    conditions: [],
    columns: [],
    supportsStoredProcedure: false,
    fallbackHint: '',
    ...overrides
  } as Api.Workbench.PageMeta;
}

describe('useWorkbenchPageMetaFlags（pageMeta 派生标记）', () => {
  it('pageMeta 为 null：所有标记均为空/false', () => {
    const { hasTableEditAuth, colorMarkEnabledColumns, hasColorMarkEnabledColumns, hasMergeableColumns, gridReady, hasChartEnabled } =
      useWorkbenchPageMetaFlags({ pageMeta: ref(null) });

    expect(hasTableEditAuth.value).toBe(false);
    expect(colorMarkEnabledColumns.value).toEqual([]);
    expect(hasColorMarkEnabledColumns.value).toBe(false);
    expect(hasMergeableColumns.value).toBe(false);
    expect(gridReady.value).toBe(false);
    expect(hasChartEnabled.value).toBe(false);
  });

  it('pageMeta 加载后 gridReady 为 true（响应式联动）', () => {
    const pageMeta = ref<Api.Workbench.PageMeta | null>(null);
    const { gridReady } = useWorkbenchPageMetaFlags({ pageMeta });
    expect(gridReady.value).toBe(false);
    pageMeta.value = makePageMeta();
    expect(gridReady.value).toBe(true);
  });

  it('hasTableEditAuth：toolbar.tableEdit === true 才为 true', () => {
    const withAuth = useWorkbenchPageMetaFlags({
      pageMeta: ref(makePageMeta({ toolbar: { tableEdit: true } as any }))
    });
    const withoutAuth = useWorkbenchPageMetaFlags({
      pageMeta: ref(makePageMeta({ toolbar: { tableEdit: false } as any }))
    });
    expect(withAuth.hasTableEditAuth.value).toBe(true);
    expect(withoutAuth.hasTableEditAuth.value).toBe(false);
  });

  it('colorMarkEnabledColumns：仅收集 colorMarkEnabled 列并映射为 label/value', () => {
    const pageMeta = ref(
      makePageMeta({
        columns: [
          { field: '姓名', title: '姓名', colorMarkEnabled: true },
          { field: '年龄', title: '年龄', colorMarkEnabled: false },
          { field: '部门', colorMarkEnabled: true }, // 无 title 时回退 field
          { field: '备注' }
        ] as any[]
      })
    );
    const { colorMarkEnabledColumns, hasColorMarkEnabledColumns } = useWorkbenchPageMetaFlags({ pageMeta });

    expect(colorMarkEnabledColumns.value).toEqual([
      { label: '姓名', value: '姓名' },
      { label: '部门', value: '部门' }
    ]);
    expect(hasColorMarkEnabledColumns.value).toBe(true);
  });

  it('hasMergeableColumns：存在任一 canMerge=true 列即为 true', () => {
    const merged = useWorkbenchPageMetaFlags({
      pageMeta: ref(makePageMeta({ columns: [{ field: 'a', canMerge: true }, { field: 'b' }] as any[] }))
    });
    const notMerged = useWorkbenchPageMetaFlags({
      pageMeta: ref(makePageMeta({ columns: [{ field: 'a' }, { field: 'b', canMerge: false }] as any[] }))
    });
    expect(merged.hasMergeableColumns.value).toBe(true);
    expect(notMerged.hasMergeableColumns.value).toBe(false);
  });

  it('hasChartEnabled：chartModule 非空字符串才为 true', () => {
    const enabled = useWorkbenchPageMetaFlags({ pageMeta: ref(makePageMeta({ chartModule: 'chart_2025' })) });
    const emptyStr = useWorkbenchPageMetaFlags({ pageMeta: ref(makePageMeta({ chartModule: '' })) });
    expect(enabled.hasChartEnabled.value).toBe(true);
    expect(emptyStr.hasChartEnabled.value).toBe(false);
  });
});
