import { describe, it, expect } from 'vitest';
import { filterLinkedOptions, clearLinkedOptions } from '../common';
import type { LinkableField } from '../common';

/** 构造字段：fieldName 为表单键（通用工作台形态） */
function field(fieldName: string, objectName: string, options: any[] = []): LinkableField {
  return { fieldName, objectName, objectOptions: options };
}

/** 邀约业务→邀约岗位 联动场景字段集 */
function invitationFields(): LinkableField[] {
  const business = field('邀约业务', '邀约业务', [
    { label: '市府热线', value: '市府热线' },
    { label: '接诉即办', value: '接诉即办' }
  ]);
  const post = field('邀约岗位', '邀约岗位', [
    { label: '工单', value: '工单', parentName: '邀约业务', parentValue: '市府热线' },
    { label: '投诉受理', value: '投诉受理', parentName: '邀约业务', parentValue: '市府热线' },
    { label: '热线', value: '热线', parentName: '邀约业务', parentValue: '接诉即办' },
    { label: '剔除', value: '剔除', parentName: '邀约业务', parentValue: '接诉即办' }
  ]);
  return [business, post];
}

describe('filterLinkedOptions（级联选项过滤）', () => {
  it('控制字段已选：仅保留匹配 parentValue 的子级选项', () => {
    const fields = invitationFields();
    const post = fields[1];
    const form = { 邀约业务: '接诉即办' };

    const options = filterLinkedOptions(post, fields, form, f => f.fieldName);
    expect(options.map(o => o.value)).toEqual(['热线', '剔除']);
  });

  it('控制字段切换后选项随值变化', () => {
    const fields = invitationFields();
    const post = fields[1];
    const form = { 邀约业务: '市府热线' };

    const options = filterLinkedOptions(post, fields, form, f => f.fieldName);
    expect(options.map(o => o.value)).toEqual(['工单', '投诉受理']);
  });

  it('控制字段未选：仅保留未声明 parentValue 的选项（未选业务时岗位下拉为空）', () => {
    const fields = invitationFields();
    const post = fields[1];
    const form = {};

    const options = filterLinkedOptions(post, fields, form, f => f.fieldName);
    expect(options).toEqual([]);
  });

  it('普通下拉（选项无 parentName）原样返回全部选项', () => {
    const fields = invitationFields();
    const business = fields[0];

    const options = filterLinkedOptions(business, fields, {}, f => f.fieldName);
    expect(options.map(o => o.value)).toEqual(['市府热线', '接诉即办']);
  });

  it('找不到 objectName=parentName 的控制字段时不过滤', () => {
    const post = field('邀约岗位', '邀约岗位', [
      { label: '工单', value: '工单', parentName: '不存在的对象', parentValue: '市府热线' }
    ]);
    const options = filterLinkedOptions(post, [post], {}, f => f.fieldName);
    expect(options).toHaveLength(1);
  });
});

describe('clearLinkedOptions（控制字段变更后清空失效子级值）', () => {
  it('业务从接诉即办切换为市府热线：原岗位值失效被清空', () => {
    const fields = invitationFields();
    const form = { 邀约业务: '接诉即办', 邀约岗位: '热线' };

    const next = clearLinkedOptions(fields, form, '邀约业务', '市府热线', f => f.fieldName);
    expect(next.邀约岗位).toBe('');
  });

  it('业务切换后原岗位挂在新业务下：保留岗位值', () => {
    const fields = invitationFields();
    // 历史值 '工单' 挂在 '市府热线' 下，业务切到 '市府热线' 后合法 → 保留
    const form = { 邀约业务: '接诉即办', 邀约岗位: '工单' };

    const next = clearLinkedOptions(fields, form, '邀约业务', '市府热线', f => f.fieldName);
    expect(next.邀约岗位).toBe('工单');
  });

  it('无 parentValue 的公共选项在任何业务下都合法：保留岗位值', () => {
    const business = field('邀约业务', '邀约业务', [
      { label: '市府热线', value: '市府热线' },
      { label: '接诉即办', value: '接诉即办' }
    ]);
    const post = field('邀约岗位', '邀约岗位', [
      { label: '热线', value: '热线', parentName: '邀约业务', parentValue: '接诉即办' },
      { label: '通用岗位', value: '通用岗位', parentName: '邀约业务', parentValue: '' }
    ]);
    const fields = [business, post];
    const form = { 邀约业务: '接诉即办', 邀约岗位: '通用岗位' };

    const next = clearLinkedOptions(fields, form, '邀约业务', '市府热线', f => f.fieldName);
    expect(next.邀约岗位).toBe('通用岗位');
  });

  it('非控制字段变更：不触发清空', () => {
    const fields = invitationFields();
    const form = { 邀约业务: '接诉即办', 邀约岗位: '热线' };

    const next = clearLinkedOptions(fields, form, '邀约岗位', '剔除', f => f.fieldName);
    expect(next.邀约业务).toBe('接诉即办');
  });

  it('清空操作不修改原表单对象（返回副本）', () => {
    const fields = invitationFields();
    const form = { 邀约业务: '接诉即办', 邀约岗位: '热线' };

    clearLinkedOptions(fields, form, '邀约业务', '市府热线', f => f.fieldName);
    expect(form.邀约岗位).toBe('热线');
  });

  it('子级字段原本为空：保持为空不变', () => {
    const fields = invitationFields();
    const form = { 邀约业务: '接诉即办', 邀约岗位: '' };

    const next = clearLinkedOptions(fields, form, '邀约业务', '市府热线', f => f.fieldName);
    expect(next.邀约岗位).toBe('');
  });
});
