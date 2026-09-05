import { describe, it, expect, vi } from 'vitest';

// mock service 层，避免拉起 axios 实例与环境变量依赖
vi.mock('@/service/api/workbench', () => ({
  fetchWorkbenchPageWithData: vi.fn(),
  fetchWorkbenchPageData: vi.fn()
}));

import { assignRowNumbers, assignRowNumbersByOffset } from '../use-workbench-data-loader';

type Row = Api.Workbench.QueryRecord;

function makeRows(count: number): Row[] {
  return Array.from({ length: count }, () => ({}) as Row);
}

describe('assignRowNumbers（按页码分配全局序号）', () => {
  it('首屏：第 1 页序号从 1 开始连续递增', () => {
    const rows = makeRows(5);
    assignRowNumbers(rows, 1, 200);
    expect(rows.map(r => r['序号'])).toEqual([1, 2, 3, 4, 5]);
  });

  it('翻页：序号以 (current-1)*size 为基数偏移', () => {
    const rows = makeRows(3);
    assignRowNumbers(rows, 3, 200);
    expect(rows.map(r => r['序号'])).toEqual([401, 402, 403]);
  });

  it('每页大小 5000：第 2 页从 5001 开始', () => {
    const rows = makeRows(2);
    assignRowNumbers(rows, 2, 5000);
    expect(rows.map(r => r['序号'])).toEqual([5001, 5002]);
  });

  it('空数组不报错', () => {
    expect(() => assignRowNumbers([], 1, 200)).not.toThrow();
  });

  it('原地修改：返回值无关，直接写入 records 的序号字段', () => {
    const rows = makeRows(2);
    const ret = assignRowNumbers(rows, 1, 200);
    expect(ret).toBeUndefined();
    expect(rows[0]['序号']).toBe(1);
  });
});

describe('assignRowNumbersByOffset（分片加载按实际偏移分配序号）', () => {
  it('历史 bug 场景：首屏 200 条后 offset=200，序号应从 201 开始（非页码换算）', () => {
    // 若错误地用页码换算（floor(200/5000)+1=1 再回算 offset=0），会得到 1 开始的重复序号
    const rows = makeRows(3);
    assignRowNumbersByOffset(rows, 200);
    expect(rows.map(r => r['序号'])).toEqual([201, 202, 203]);
  });

  it('offset=0 等价于首屏从 1 开始', () => {
    const rows = makeRows(3);
    assignRowNumbersByOffset(rows, 0);
    expect(rows.map(r => r['序号'])).toEqual([1, 2, 3]);
  });

  it('大偏移：分片到 10000 时从 10001 开始', () => {
    const rows = makeRows(2);
    assignRowNumbersByOffset(rows, 10000);
    expect(rows.map(r => r['序号'])).toEqual([10001, 10002]);
  });

  it('空数组不报错', () => {
    expect(() => assignRowNumbersByOffset([], 5000)).not.toThrow();
  });

  it('与 assignRowNumbers 的连续性：首屏 assignRowNumbers + 后续分片 assignRowNumbersByOffset 序号不重复不跳号', () => {
    const first = makeRows(200);
    assignRowNumbers(first, 1, 200);
    const second = makeRows(200);
    assignRowNumbersByOffset(second, 200);

    const all = [...first, ...second].map(r => Number(r['序号']));
    // 1..400 连续无重复
    expect(all).toEqual(Array.from({ length: 400 }, (_, i) => i + 1));
  });
});
