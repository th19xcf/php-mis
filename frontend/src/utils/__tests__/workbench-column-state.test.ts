import { describe, it, expect } from 'vitest';
import { hasSuspiciousNarrowColumnState } from '../workbench-column-state';

/** 构造列状态：selection 列与 ag-grid 内置列默认排除 */
function col(colId: string, width: number) {
  return { colId, width };
}

describe('hasSuspiciousNarrowColumnState（窄列状态检测）', () => {
  it('空数组 / 非数组返回 false', () => {
    expect(hasSuspiciousNarrowColumnState([])).toBe(false);
    expect(hasSuspiciousNarrowColumnState(undefined as any)).toBe(false);
    expect(hasSuspiciousNarrowColumnState(null as any)).toBe(false);
  });

  it('全部数据列窄（宽度<=80 占比 100%）：触发', () => {
    const state = [col('姓名', 50), col('年龄', 60), col('部门', 80)];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(true);
  });

  it('70% 数据列窄：恰好达到阈值触发', () => {
    // 10 列中 7 列窄
    const state = [
      col('a', 50), col('b', 50), col('c', 50), col('d', 50), col('e', 50),
      col('f', 50), col('g', 50),
      col('h', 200), col('i', 200), col('j', 200)
    ];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(true);
  });

  it('低于 70%（如 60%）不触发', () => {
    // 10 列中 6 列窄
    const state = [
      col('a', 50), col('b', 50), col('c', 50), col('d', 50), col('e', 50), col('f', 50),
      col('g', 200), col('h', 200), col('i', 200), col('j', 200)
    ];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(false);
  });

  it('selection 列与 ag-grid 内置前缀列不计入统计', () => {
    // 仅 ag-Grid-SelectionColumn 窄，数据列全部正常 → 不触发
    const state = [
      col('ag-Grid-SelectionColumn', 40),
      col('姓名', 200), col('年龄', 200)
    ];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(false);
  });

  it('selection 列窄 + 数据列也全窄：仍按数据列占比触发', () => {
    const state = [
      col('ag-Grid-SelectionColumn', 40),
      col('姓名', 50), col('年龄', 50)
    ];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(true);
  });

  it('全部为内置列（无数据列）：不触发', () => {
    const state = [col('ag-Grid-SelectionColumn', 40), col('ag-Grid-AutoWidth', 40)];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(false);
  });

  it('colId 为空的列不计入数据列', () => {
    const state = [col('', 50), col('姓名', 200)];
    expect(hasSuspiciousNarrowColumnState(state)).toBe(false);
  });

  it('宽度缺失 / 0 / 非有限数不算窄列', () => {
    const state = [
      col('a', 0), col('b', NaN), col('c', undefined as any), col('d', 50)
    ];
    // 4 列中仅 1 列有效窄列（25%），不触发
    expect(hasSuspiciousNarrowColumnState(state)).toBe(false);
  });
});
