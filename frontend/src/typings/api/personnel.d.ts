declare namespace Api {
  namespace Train {
    interface TrainTreeNode {
      id: string;
      guid?: string;
      name?: string;
      value: string;
      type: string;
      num?: number;
      items?: TrainTreeNode[];
    }

    interface TrainDetail {
      GUID: string;
      姓名: string;
      身份证号: string;
      手机号码: string;
      属地: string;
      培训业务: string;
      培训状态: string;
      培训批次: string;
      培训老师: string;
      培训开始日期: string;
      预计完成日期: string;
      培训完成日期: string;
      培训离开日期: string;
      培训离开原因: string;
      培训天数: string;
    }

    interface TrainUpdateParams {
      guid: string;
      [key: string]: any;
    }

    interface TrainBatchUpdateParams {
      guids: string[];
      [key: string]: any;
    }

    interface TrainTransferParams {
      guids: string[];
      培训状态: string;
      岗位类型?: string;
      结算类型?: string;
      培训结束日期?: string;
      培训离开原因?: string;
      入职次数?: number;
    }

    interface TrainOptions {
      region: Array<{ value: string; label: string }>;
      trainBiz: Array<{ value: string; label: string }>;
      trainStatus: Array<{ value: string; label: string }>;
      positionType: Array<{ value: string; label: string }>;
      settlementType: Array<{ value: string; label: string }>;
    }
  }

  namespace Employee {
    interface EmployeeTreeNode {
      id: string;
      guid?: string;
      name?: string;
      value: string;
      type: string;
      num?: number;
      items?: EmployeeTreeNode[];
    }

    interface EmployeeDetail {
      GUID: string;
      姓名: string;
      身份证号: string;
      手机号码: string;
      属地: string;
      员工状态: string;
      部门名称: string;
      班组: string;
      岗位名称: string;
      岗位类型: string;
      结算类型: string;
      工号1: string;
      培训开始日期: string;
      培训完成日期: string;
      一阶段日期: string;
      二阶段日期: string;
      离职日期: string;
      离职原因: string;
    }

    interface EmployeeUpdateParams {
      guid: string;
      [key: string]: any;
    }

    interface EmployeeBatchUpdateParams {
      guids: string[];
      [key: string]: any;
    }

    interface EmployeeOptions {
      region: Array<{ value: string; label: string }>;
      status: Array<{ value: string; label: string }>;
      positionType: Array<{ value: string; label: string }>;
      settlementType: Array<{ value: string; label: string }>;
    }
  }
}
