declare namespace Api {
  namespace Person {
    /** 主档树节点（与 2015 InvitationTreeNode 同结构：id/value/items，叶子含 guid/type/data） */
    interface PersonTreeNode {
      id: string;
      guid?: string;
      value: string;
      type: 'region' | 'year' | 'month' | 'person' | string;
      num?: number;
      items?: PersonTreeNode[];
      data?: Record<string, unknown>;
    }

    /** 主档详情（含 GUID 定位键；渠道字段为招聘事件属性，归阶段表） */
    interface PersonDetail {
      GUID: number;
      人员编码: string;
      姓名: string;
      手机号码: string;
      身份证号: string;
      性别: string;
      年龄: number | string;
      现住址: string;
      学校: string;
      专业: string;
      学历: string;
      工作履历: string;
      属地: string;
      合并至: string;
      操作记录?: string;
      操作来源?: string;
      操作人员?: string;
      操作时间?: string;
      开始操作时间?: string;
      [key: string]: unknown;
    }

    /** 新增参数（姓名/手机必填，其余字段可选；查重决策 person_code / force_new 二选一） */
    interface PersonAddParams {
      姓名: string;
      手机号码: string;
      身份证号?: string;
      性别?: string;
      年龄?: string | number;
      学校?: string;
      专业?: string;
      学历?: string;
      现住址?: string;
      工作履历?: string;
      属地?: string;
      /** 查重确认后挂接既有人员主档的人员编码（与 force_new 二选一） */
      person_code?: string;
      /** 查重确认后强制新建人员主档（与 person_code 二选一） */
      force_new?: boolean;
    }

    /** 修改参数（GUID 定位，其它字段按需传入） */
    interface PersonUpdateParams {
      guid: string | number;
      [key: string]: unknown;
    }

    /** 删除参数 */
    interface PersonDeleteParams {
      guids: Array<string | number>;
    }

    /** 合并参数 */
    interface PersonMergeParams {
      sourceCode: string;
      targetCode: string;
    }

    /** 查重参数 */
    interface PersonDedupParams {
      姓名: string;
      手机号码: string;
      身份证号?: string;
    }

    /** 查重命中的疑似档案行 */
    interface PersonDedupMatch {
      人员编码: string;
      姓名: string;
      身份证号: string | null;
      手机号码: string;
      性别: string | null;
      属地: string | null;
    }

    /** 查重结果：hard=证件号精确命中；soft=姓名+手机号疑似需确认；none=无命中 */
    interface PersonDedupResult {
      level: 'hard' | 'soft' | 'none';
      matches: PersonDedupMatch[];
      person: PersonDedupMatch | null;
    }

    /** 下拉选项 */
    interface PersonOptions {
      region: Array<{ value: string; label: string }>;
      gender: Array<{ value: string; label: string }>;
      education: Array<{ value: string; label: string }>;
    }
  }
}
