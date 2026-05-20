declare namespace Api {
  namespace Dept {
    interface DeptTreeNode {
      id: string;
      guid?: string;
      name?: string;
      value: string;
      label?: string;
      deptName?: string;
      deptCode?: string;
      hasChildren?: string;
      children?: DeptTreeNode[];
    }

    interface DeptDetail {
      GUID: string;
      名称: string;
      部门编码?: string;
      部门名称?: string;
      部门全称?: string;
      部门级别?: string;
      父级部门: string;
      上级部门编码?: string;
      负责人?: string;
      属地?: string;
      预算表部门全称?: string;
      有无下级部门?: string;
      记录开始日期?: string;
      记录结束日期?: string;
      排序: number;
      状态: string;
      创建时间: string;
      更新时间: string;
    }

    interface DeptAddParams {
      parentCode: string;
      deptName: string;
      leader?: string;
      region?: string;
      budgetFullName?: string;
      effectiveDate?: string;
      名称?: string;
      父级部门?: string;
      排序?: number;
      状态?: string;
    }

    interface DeptAddResult {
      GUID: string;
    }

    interface DeptUpdateParams {
      guid: string;
      deptName: string;
      leader?: string;
      region?: string;
      budgetFullName?: string;
      名称?: string;
      parentCode?: string;
      父级部门?: string;
      排序?: number;
      状态?: string;
    }

    interface DeptUpdateResult {
      GUID: string;
    }

    interface DeptDeleteResult {
      GUID: string;
    }

    interface DeptOption {
      value: string;
      label: string;
    }

    interface DeptOptions {
      dept: DeptOption[];
      region: DeptOption[];
    }
  }
}
