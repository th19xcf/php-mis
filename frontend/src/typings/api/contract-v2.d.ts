declare namespace Api {
  namespace ContractV2 {
    interface ContractListItem {
      GUID: number;
      合同编号: string;
      合同名称: string;
      合同类型: string;
      合同金额: number;
      甲方名称: string;
      乙方名称: string;
      签订日期: string;
      开始日期: string;
      结束日期: string;
      合同状态: string;
      所属部门编码: string;
      所属部门名称: string;
      创建人: string;
      创建人姓名: string;
      创建时间: string;
      更新人: string;
      更新时间: string;
    }

    interface ContractDetail {
      GUID: number;
      合同编号: string;
      合同名称: string;
      合同类型: string;
      合同金额: number;
      甲方名称: string;
      甲方联系人: string;
      甲方电话: string;
      乙方名称: string;
      乙方联系人: string;
      乙方电话: string;
      签订日期: string;
      开始日期: string;
      结束日期: string;
      付款方式: string;
      币别: string;
      汇率: number;
      备注: string;
      合同状态: string;
      所属部门编码: string;
      所属部门名称: string;
      版本号: number;
      创建人: string;
      创建人姓名: string;
      创建时间: string;
      更新人: string;
      更新时间: string;
      删除标识: string;
      有效标识: string;
      documents?: ContractDocument[];
    }

    interface ContractDocument {
      GUID: number;
      合同编号: string;
      文档名称: string;
      文档类型: string;
      文档格式: string;
      文件路径: string;
      文件大小: number;
      版本号: number;
      上传人: string;
      上传人姓名: string;
      上传时间: string;
    }

    interface ContractCreateParams {
      合同名称: string;
      甲方名称: string;
      乙方名称: string;
      合同类型?: string;
      合同金额?: number;
      甲方联系人?: string;
      甲方电话?: string;
      乙方联系人?: string;
      乙方电话?: string;
      签订日期?: string | null;
      开始日期?: string | null;
      结束日期?: string | null;
      付款方式?: string;
      币别?: string;
      汇率?: number;
      备注?: string;
    }

    interface ContractUpdateParams extends Partial<ContractCreateParams> {
      contractNo: string;
    }

    interface ContractStats {
      总数: number;
      草稿: number;
      审批中: number;
      已通过: number;
      已拒绝: number;
      执行中: number;
      已归档: number;
      即将到期: number;
    }

    interface ContractOptions {
      合同类型: { value: string; label: string }[];
      合同状态: { value: string; label: string }[];
      付款方式: { value: string; label: string }[];
      币别: { value: string; label: string }[];
    }
  }

  namespace Workflow {
    interface WorkflowDefinition {
      GUID: number;
      流程编码: string;
      流程名称: string;
      业务类型: string;
      版本号: number;
      流程状态: string;
      流程描述: string;
      审批人配置?: Record<string, any>;
      超时规则?: Record<string, any>;
      nodes?: WorkflowNode[];
      edges?: WorkflowEdge[];
      创建人: string;
      创建时间: string;
      更新人: string;
      更新时间: string;
    }

    interface WorkflowNode {
      GUID: number;
      流程定义ID: number;
      节点编码: string;
      节点名称: string;
      节点类型: string;
      审批模式: string;
      审批人配置: Record<string, any>;
      排序: number;
    }

    interface WorkflowEdge {
      GUID: number;
      流程定义ID: number;
      源节点编码: string;
      目标节点编码: string;
      条件表达式: string;
    }

    interface WorkflowInstance {
      GUID: number;
      流程定义ID: number;
      流程版本: number;
      业务类型: string;
      业务ID: string;
      业务标题: string;
      实例状态: string;
      当前节点编码: string;
      发起人: string;
      发起人姓名: string;
      发起时间: string;
      创建时间: string;
      结束时间: string;
      流程编码?: string;
      流程名称?: string;
      tasks?: WorkflowTask[];
      timeline?: WorkflowTimelineItem[];
      variables?: Record<string, any>;
    }

    interface WorkflowTask {
      任务ID: number;
      GUID?: number;
      节点编码: string;
      节点名称: string;
      处理人: string;
      处理人姓名: string;
      任务状态: string;
      处理结果?: string;
      处理意见?: string;
      创建时间: string;
      处理时间?: string;
      任务类型: string;
      实例ID: number;
      业务类型: string;
      业务ID: string;
      业务标题: string;
      发起人: string;
      发起人姓名: string;
      实例状态: string;
    }

    interface WorkflowTimelineItem {
      taskId: number | null;
      nodeCode: string;
      operator: string;
      operatorName: string;
      action: string;
      remark: string;
      time: string;
      ip: string;
    }

    type WorkflowInstanceStatus =
      | 'RUNNING'
      | 'COMPLETED'
      | 'TERMINATED'
      | 'SUSPENDED';

    type WorkflowTaskStatus =
      | 'PENDING'
      | 'DONE'
      | 'WITHDRAWN'
      | 'REJECTED';
  }
}
