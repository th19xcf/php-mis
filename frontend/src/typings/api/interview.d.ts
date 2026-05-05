declare namespace Api {
  namespace Interview {
    interface InterviewTreeNode {
      id: string;
      guid?: string;
      name?: string;
      value: string;
      type: string;
      num?: number;
      items?: InterviewTreeNode[];
    }

    interface InterviewDetail {
      GUID: string;
      姓名: string;
      身份证号: string;
      手机号码: string;
      属地: string;
      招聘渠道: string;
      渠道类型: string;
      渠道名称: string;
      信息来源: string;
      实习结束日期: string;
      面试业务: string;
      面试岗位: string;
      面试日期: string;
      面试结果: string;
      面试人: string;
      预约培训日期: string;
      住宿: string;
      备注说明: string;
      参培信息: string;
      操作记录: string;
      操作来源: string;
      操作人员: string;
      开始操作时间: string;
      结束操作时间: string;
      操作时间: string;
    }

    interface InterviewAddParams {
      姓名: string;
      身份证号?: string;
      手机号码?: string;
      属地?: string;
      招聘渠道?: string;
      渠道类型?: string;
      渠道名称?: string;
      信息来源?: string;
      实习结束日期?: string;
      面试业务?: string;
      面试岗位?: string;
      面试日期?: string;
      面试结果?: string;
      面试人?: string;
      预约培训日期?: string;
      住宿?: string;
      备注说明?: string;
    }

    interface InterviewUpdateParams {
      guid: string;
      [key: string]: any;
    }

    interface InterviewTransferParams {
      guids: string[];
      参培信息: string;
      培训业务?: string;
      培训批次?: string;
      培训老师?: string;
      培训开始日期?: string;
      预计完成日期?: string;
    }

    interface InterviewOptions {
      region: Array<{ value: string; label: string }>;
      channel: Array<{ value: string; label: string }>;
      trainBiz: Array<{ value: string; label: string }>;
      interviewResult: Array<{ value: string; label: string }>;
      trainStatus: Array<{ value: string; label: string }>;
    }
  }
}
