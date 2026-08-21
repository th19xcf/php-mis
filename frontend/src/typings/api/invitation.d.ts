declare namespace Api {
  namespace Invitation {
    interface InvitationTreeNode {
      id: string;
      guid?: string;
      name?: string;
      value: string;
      type: string;
      num?: number;
      items?: InvitationTreeNode[];
    }

    interface InvitationDetail {
      GUID: string;
      姓名: string;
      身份证号: string;
      手机号码: string;
      邀约次数: string;
      性别: string;
      年龄: string;
      学校: string;
      专业: string;
      现住址: string;
      工作履历: string;
      渠道类型: string;
      招聘渠道: string;
      渠道名称: string;
      属地: string;
      部门名称: string;
      邀约业务: string;
      邀约岗位: string;
      工作地点: string;
      邀约日期: string;
      邀约人: string;
      邀约结果: string;
      预约面试日期: string;
      面试信息: string;
      操作记录: string;
      操作来源: string;
      操作人员: string;
      开始操作时间: string;
      结束操作时间: string;
      操作时间: string;
      [key: string]: any;
    }

    interface InvitationAddParams {
      姓名: string;
      身份证号?: string;
      手机号码?: string;
      邀约次数?: string;
      性别?: string;
      年龄?: string;
      学校?: string;
      专业?: string;
      现住址?: string;
      工作履历?: string;
      渠道类型?: string;
      招聘渠道?: string;
      渠道名称?: string;
      属地?: string;
      部门名称?: string;
      邀约业务?: string;
      邀约岗位?: string;
      工作地点?: string;
      邀约日期?: string;
      邀约人?: string;
      邀约结果?: string;
      预约面试日期?: string;
      /** 查重确认后挂接既有人员主档的人员编码（与 force_new 二选一） */
      person_code?: string;
      /** 查重确认后强制新建人员主档（与 person_code 二选一） */
      force_new?: boolean;
    }

    /** 人员主档查重入参 */
    interface PersonDedupParams {
      姓名: string;
      手机号码: string;
      身份证号?: string;
    }

    /** 人员主档查重命中的疑似档案行 */
    interface PersonDedupMatch {
      人员编码: string;
      姓名: string;
      身份证号: string | null;
      手机号码: string;
      性别: string | null;
      属地: string | null;
    }

    /** 人员主档查重结果：hard=证件号精确命中；soft=姓名+手机号疑似需确认；none=无命中 */
    interface PersonDedupResult {
      level: 'hard' | 'soft' | 'none';
      matches: PersonDedupMatch[];
      person: PersonDedupMatch | null;
    }

    interface InvitationUpdateParams {
      guid: string;
      [key: string]: any;
    }

    interface InvitationTransferParams {
      guids: string[];
      面试结果: string;
      面试日期?: string;
      面试人?: string;
      预约培训日期?: string;
      住宿?: string;
      通勤方式?: string;
      通勤时间?: string;
    }

    interface InvitationOptions {
      region: Array<{ value: string; label: string }>;
      channel: Array<{ value: string; label: string }>;
      result: Array<{ value: string; label: string }>;
      interviewResult: Array<{ value: string; label: string }>;
    }
  }
}
