declare namespace Api {
  namespace Workbench {
    interface ToolbarMeta {
      comment: boolean;
      add: boolean;
      edit: boolean;
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
  }
}
