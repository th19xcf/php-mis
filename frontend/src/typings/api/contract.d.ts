declare namespace Api {
  namespace Contract {
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
      当前流程节点: string;
      创建人: string;
      创建时间: string;
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
      备注: string;
      合同状态: string;
      当前流程节点: string;
      合同模板ID: number;
      版本号: number;
      创建人: string;
      创建时间: string;
      更新人: string;
      更新时间: string;
      删除标识: string;
      有效标识: string;
    }

    interface ContractCreateParams {
      合同名称: string;
      合同类型?: string;
      甲方名称: string;
      甲方联系人?: string;
      甲方电话?: string;
      乙方名称: string;
      乙方联系人?: string;
      乙方电话?: string;
      合同金额?: number;
      签订日期?: string | null;
      开始日期?: string | null;
      结束日期?: string | null;
      付款方式?: string;
      备注?: string;
    }

    interface ContractUpdateParams extends ContractCreateParams {
      GUID: number;
    }

    interface ContractApproveParams {
      GUID: number;
      审核意见: string;
    }

    interface ContractRejectParams {
      GUID: number;
      审核意见: string;
    }

    interface ContractSignParams {
      GUID: number;
      签署公司: string;
    }

    interface ContractFlowRecord {
      GUID: number;
      合同编号: string;
      流程类型: string;
      流程状态: string;
      节点名称: string;
      审核人: string;
      审核人姓名: string;
      审核时间: string;
      审核意见: string;
      附件: string;
    }

    interface ContractSignRecord {
      GUID: number;
      合同编号: string;
      签署人: string;
      签署人姓名: string;
      签署公司: string;
      签署时间: string;
      签署状态: string;
      签署方式: string;
      签名图片: string;
      签署IP: string;
      签署设备: string;
    }

    interface ContractStats {
      总数: number;
      待审核: number;
      已审核: number;
      已签署: number;
      即将到期: number;
    }

    interface ContractOption {
      value: string;
      label: string;
    }

    interface ContractOptions {
      合同类型: ContractOption[];
      合同状态: ContractOption[];
      付款方式: ContractOption[];
    }

    type ContractStatus =
      | 'DRAFT'
      | 'PENDING'
      | 'APPROVING'
      | 'APPROVED'
      | 'REJECTED'
      | 'SIGNING'
      | 'SIGNED'
      | 'ARCHIVED'
      | 'EXECUTING'
      | 'TERMINATED'
      | 'EXPIRED';

    const CONTRACT_STATUS_MAP: Record<ContractStatus, string>;
  }
}
