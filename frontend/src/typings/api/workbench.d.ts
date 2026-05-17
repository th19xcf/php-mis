declare namespace Api {
  namespace Workbench {
    interface ToolbarMeta {
      comment: boolean;
      add: boolean;
      edit: boolean;
      batchEdit: boolean;
      delete: boolean;
      import: boolean;
      export: boolean;
      tableEdit: boolean;
      debugSql: boolean;
      upkeep: boolean;
    }

    interface ColumnMeta {
      field: string;
      title: string;
      type: string;
      width: number;
      hidden: boolean;
      editable: boolean;
      required: boolean;
      sortable: boolean;
      // 提示和异常显示相关配置
      hintCondition?: string;
      hintStyle?: string;
      errorCondition?: string;
      errorStyle?: string;
      // 颜色标注相关配置
      colorMarkEnabled?: boolean;
    }

    interface ConditionMeta {
      label: string;
      fieldKey: string;
      fieldName: string;
      queryName: string;
      type: string;
      required: boolean;
      filterable: boolean;
    }

    interface PageMeta {
      functionCode: string;
      title: string;
      menu1: string;
      menu2: string;
      module: string;
      params: string;
      mode: string;
      queryModule: string;
      fieldModule: string;
      commentModule: string;
      toolbar: ToolbarMeta;
      conditions: ConditionMeta[];
      columns: ColumnMeta[];
      supportsStoredProcedure: boolean;
      fallbackHint: string;
    }

    interface QueryRecord {
      [key: string]: string | number | null;
    }

    interface PageData extends Api.Common.PaginatingQueryRecord<QueryRecord> {
      meta: PageMeta;
    }

    interface QueryPayload {
      current?: number;
      size?: number;
      all?: boolean;
      filters?: Array<{
        fieldKey: string;
        operator: 'contains' | 'equals' | 'startsWith';
        value: string;
      }>;
      drillCondition?: string;
    }

    interface DrillOption {
      label: string;
      value: string;
      functionCode: string;
      module?: string;
      drillFields?: string;
      drillCondition?: string;
      menu1?: string;
      menu2?: string;
    }

    interface DrillData {
      options: DrillOption[];
      debug?: {
        functionCode?: string;
        queryModule?: string;
        drillModule?: string;
        userAuthCount?: number;
      };
    }

    interface ImportColumn {
      columnName: string;
      fieldName: string;
      queryName: string;
      columnOrder: number;
      columnType: string;
      checkType: string;
      importType: string;
    }

    interface ImportColumnsData {
      columns: ImportColumn[];
    }

    interface ImportError {
      row: number;
      errors: string[];
      data: Record<string, any>;
    }

    interface ImportResult {
      success: boolean;
      message: string;
      total: number;
      successCount: number;
      errorCount: number;
      errors: ImportError[];
    }

    interface AddField {
      columnName: string;
      fieldName: string;
      fieldType: string;
      required: boolean;
      defaultValue: string;
      objectName: string;
      editable: boolean;
      inputType?: 'text' | 'popup' | 'select';
      objectOptions?: Array<{ label: string; value: string }>;
    }

    interface AddFieldsData {
      fields: AddField[];
      debug?: {
        functionCode?: string;
        fieldModule?: string;
        columnsCount?: number;
      };
    }

    interface AddResult {
      success: boolean;
      message: string;
    }

    interface DeleteResult {
      success: boolean;
      message: string;
      deletedCount: number;
    }

    interface UpdateFieldsResult {
      fields: any[];
      currentData: Record<string, any>;
    }

    interface UpdateResult {
      success: boolean;
      message: string;
      updatedCount: number;
    }

    interface PopupGridItem {
      表项: string;
      级别: number;
      取值: string;
    }

    interface PopupData {
      popupGrid: PopupGridItem[];
      popupObj: Record<string, any>;
      maxLevel: number;
    }

    // 懒加载级联选择类型
    interface PopupLevel {
      name: string;
      level: number;
      initialValue: string;
    }

    interface PopupLevelsData {
      levels: PopupLevel[];
      maxLevel: number;
    }

    interface PopupLevelItem {
      code: string;
      name: string;
      fullName: string;
      hasChildren: boolean;
    }

    interface PopupLevelData {
      items: PopupLevelItem[];
      level: number;
    }

    interface DebugData {
      functionCode: string;
      queryTable: string;
      queryWhere: string;
      queryGroup: string;
      queryOrder: string;
      mode: string;
      selectParts: string[];
      whereParts: string[];
      countSql: string | null;
      querySql: string;
      userAuth: {
        companyId: string;
        userWorkId: string;
        roleCodes: string[];
        locationAuth: string;
        deptCodeAuth: string[];
        deptNameAuth: string[];
        debugAuth: boolean;
      };
      functionAuth: {
        module: string;
        params: string;
        deptAuthCond: string;
        locationAuthCond: string;
      };
      columns: Array<{
        列名: string;
        查询名: string;
        字段名: string;
      }>;
    }
  }
}
