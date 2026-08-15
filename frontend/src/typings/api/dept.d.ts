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
      [key: string]: any;
    }

    interface DeptAddParams {
      parentCode: string;
      [key: string]: any;
    }

    interface DeptAddResult {
      GUID: string;
    }

    interface DeptUpdateParams {
      guid: string;
      [key: string]: any;
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
