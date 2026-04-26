declare namespace Api {
  namespace Comment {
    interface FieldInfo {
      name: string;
      type: string;
      comment: string;
      isKeyField: boolean;
      sourceColumn: string;
    }

    interface FieldsData {
      fields: FieldInfo[];
      keyFields: string;
    }

    interface ListPayload {
      keyFields: Record<string, string | number>;
    }

    interface CommentRecord {
      id: number;
      操作人员: string;
      [key: string]: string | number | null;
    }

    interface ListData {
      records: CommentRecord[];
      total: number;
    }

    interface AddPayload {
      keyFields: Record<string, string | number>;
      data: Record<string, string | number>;
    }
  }
}
