import type { Ref } from 'vue';
import { fetchWorkbenchPageData } from '@/service/api/workbench';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchDataFetchAllOptions {
  getFunctionCode: () => string;
  selectedField: Ref<string>;
  selectedOperator: Ref<string>;
  selectedValue: Ref<string>;
  page: Ref<number>;
  total: Ref<number>;
  serverRows: Ref<Api.Workbench.QueryRecord[]>;
  loading: Ref<boolean>;
  notify: (type: NotifyType, message: string) => void;
}

interface QueryFilter {
  fieldKey: string;
  operator: string;
  value: string;
}

const DEFAULT_PAGE_SIZE = 5000;

/**
 * 分页拉取全量数据（用于导出等需要全量数据的场景）
 *  - 每页 5000 条
 *  - 每累计 10000 条主动让出时间片，避免阻塞主线程
 */
export function useWorkbenchDataFetchAll(options: UseWorkbenchDataFetchAllOptions) {
  async function fetchAllRows(fnCode: string, filters: QueryFilter[], drillConditionSql?: string) {
    const allRows: Api.Workbench.QueryRecord[] = [];
    let current = 1;
    let hasMore = true;

    while (hasMore) {
      const result = await fetchWorkbenchPageData(fnCode, {
        current,
        size: DEFAULT_PAGE_SIZE,
        fetchTotal: current === 1,
        filters,
        drillCondition: drillConditionSql || undefined
      });

      if (result.error) {
        break;
      }

      allRows.push(...result.data.records);
      hasMore = result.data.hasMore;
      current++;

      if (allRows.length % 10000 === 0) {
        await new Promise(resolve => setTimeout(resolve, 10));
      }
    }

    return allRows;
  }

  /**
   * 应用当前筛选条件并拉取全量数据，更新 serverRows / total / page。
   *  - 若未填筛选值，则不传 filters
   *  - loading 由本函数控制
   */
  async function queryPage() {
    const fnCode = options.getFunctionCode();
    if (!fnCode) return;

    const filters =
      options.selectedField.value && options.selectedValue.value.trim()
        ? [
            {
              fieldKey: options.selectedField.value,
              operator: options.selectedOperator.value,
              value: options.selectedValue.value.trim()
            }
          ]
        : [];

    options.loading.value = true;
    const allRows = await fetchAllRows(fnCode, filters);
    if (!allRows) {
      options.loading.value = false;
      return;
    }

    options.serverRows.value = allRows;
    options.total.value = allRows.length;
    options.page.value = 1;
    options.loading.value = false;
  }

  return { queryPage };
}
