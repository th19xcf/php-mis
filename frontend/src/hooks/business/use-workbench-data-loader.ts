import { ref, watch, onMounted, onActivated, onDeactivated } from 'vue';
import type { Ref, ComputedRef } from 'vue';
import type { GridApi } from 'ag-grid-community';

import { fetchWorkbenchPage, fetchWorkbenchPageData } from '@/service/api/workbench';
import type { WorkbenchStore } from './use-workbench-grid-state';
import { WORKBENCH_CONFIG } from '@/config/workbench';

interface UseWorkbenchDataLoaderOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  functionCode: ComputedRef<string>;
  params: ComputedRef<string>;
  workbenchStore: WorkbenchStore;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
  checkScrollPosition: () => void;
  
  // 外部传入的状态
  pageMeta: Ref<Api.Workbench.PageMeta | null>;
  serverRows: Ref<Api.Workbench.QueryRecord[]>;
  total: Ref<number>;
  totalCount: Ref<number>;
  loadedCount: Ref<number>;
  loading: Ref<boolean>;
  isInitialChunkLoaded: Ref<boolean>;
  isChunkLoading: Ref<boolean>;
  isInitialLoading: Ref<boolean>;
  isRestoringPage: Ref<boolean>;
  isRestoringSelection: Ref<boolean>;
  page: Ref<number>;
  pageSize: Ref<number>;
  selectedField: Ref<string>;
  selectedOperator: Ref<string>;
  selectedValue: Ref<string>;
  conditionVisible: Ref<boolean>;
  quickKeyword: Ref<string>;
}

const CHUNK_SIZE = WORKBENCH_CONFIG.PAGINATION.CHUNK_SIZE;
const PAGE_SIZE_OPTIONS = WORKBENCH_CONFIG.PAGINATION.PAGE_SIZE_OPTIONS;

const loadingLocks = new Map<string, boolean>();

interface QueryFilter {
  fieldKey: string;
  operator: string;
  value: string;
}

export function useWorkbenchDataLoader(options: UseWorkbenchDataLoaderOptions) {
  const {
    workbenchStore,
    pageMeta,
    serverRows,
    total,
    totalCount,
    loadedCount,
    loading,
    isInitialChunkLoaded,
    isChunkLoading,
    isInitialLoading,
    isRestoringPage,
    isRestoringSelection,
    page,
    pageSize,
    selectedField,
    selectedOperator,
    selectedValue,
    conditionVisible,
    quickKeyword
  } = options;

  const loadedFunctionCode = ref<string>('');
  const loadedParams = ref<string>('');
  const isDataLoaded = ref(false);

  function createTimer(label: string) {
    const start = performance.now();
    return {
      end: () => {
        const duration = performance.now() - start;
        console.log(`[性能计时] ${label}: ${duration.toFixed(2)}ms`);
      },
      elapsed: () => performance.now() - start
    };
  }

  function logger(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
    const prefix = `[${timestamp}] [DATA-LOADER] [${method.toUpperCase()}]`;
    
    if (data !== undefined) {
      console.log(`${prefix} ${message}`, data);
    } else {
      console.log(`${prefix} ${message}`);
    }
  }

  function isGuidColumn(field: string, headerName: string): boolean {
    const guidPatterns = ['guid', 'uuid', 'id', '主键', '唯一标识'];
    return guidPatterns.some(
      pattern => field.toLowerCase().includes(pattern) || headerName.toLowerCase().includes(pattern)
    );
  }

  function parseDrillParams(drillParamsStr: string): { filters: QueryFilter[]; conditionSql: string } {
    const filters: QueryFilter[] = [];
    let conditionSql = '';

    if (!drillParamsStr) {
      return { filters, conditionSql };
    }

    try {
      const drillParams = JSON.parse(drillParamsStr);
      const rawDrillCondition = drillParams['钻取条件'] || '';

      if (rawDrillCondition) {
        conditionSql = rawDrillCondition;
        for (const [key, value] of Object.entries(drillParams)) {
          if (key === '钻取字段' || key === '钻取条件' || key === '字段选择') continue;
          const variable = `$${key}`;
          const valueStr = typeof value === 'string' ? value : JSON.stringify(value);
          conditionSql = conditionSql.replace(
            new RegExp(variable.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
            valueStr
          );
        }
        conditionSql = conditionSql.replace(/`/g, "'");
      }

      const drillFieldsStr = drillParams['钻取字段'] || '';
      const nlArr = drillFieldsStr.split(';').filter((f: string) => f.trim());

      for (const field of nlArr) {
        const trimmedField = field.trim();
        if (trimmedField && drillParams[trimmedField] !== undefined && drillParams[trimmedField] !== '') {
          filters.push({
            fieldKey: trimmedField,
            operator: 'equals',
            value: String(drillParams[trimmedField])
          });
        }
      }
    } catch {
      // 解析钻取参数失败，忽略
    }

    return { filters, conditionSql };
  }

  async function loadRemainingData(
    functionCode: string,
    params: string,
    meta: Api.Workbench.PageMeta,
    filters: QueryFilter[],
    drillConditionSql: string,
    totalRecords: number
  ) {
    const bgTimer = createTimer('后台加载总耗时');
    const firstChunkSize = serverRows.value.length;
    const remainingCount = totalRecords - firstChunkSize;

    if (remainingCount <= 0) {
      console.log('[性能] 数据已全部加载');
      bgTimer.end();
      return;
    }

    const PAGE_SIZE = 5000;
    const CONCURRENT_REQUESTS = Math.max(3, Math.min(6, Math.ceil(remainingCount / PAGE_SIZE / 4)));

    const chunksNeeded = Math.ceil(remainingCount / PAGE_SIZE);
    const totalOffsets = Array.from({ length: chunksNeeded }, (_, i) => firstChunkSize + i * PAGE_SIZE);
    let nextChunkIndex = 0;
    let activeRequests = 0;
    let loadedRows = 0;

    const chunkRecordsMap = new Map<number, Api.Workbench.QueryRecord[]>();
    let nextMergeOffset = firstChunkSize;

    const mergeReadyPages = () => {
      const mergedRows: Api.Workbench.QueryRecord[] = [];
      while (chunkRecordsMap.has(nextMergeOffset)) {
        const rows = chunkRecordsMap.get(nextMergeOffset) || [];
        chunkRecordsMap.delete(nextMergeOffset);
        mergedRows.push(...rows);
        nextMergeOffset += PAGE_SIZE;
      }

      if (mergedRows.length > 0) {
        const updateTimer = createTimer('更新UI');
        serverRows.value = [...serverRows.value, ...mergedRows];
        updateTimer.end();
      }
    };

    await new Promise<void>(resolve => {
      const schedule = () => {
        while (activeRequests < CONCURRENT_REQUESTS && nextChunkIndex < totalOffsets.length) {
          const offset = totalOffsets[nextChunkIndex++];
          const current = Math.floor(offset / PAGE_SIZE) + 1;
          activeRequests += 1;
          const pageTimer = createTimer(`加载分片 offset=${offset}, size=${PAGE_SIZE}`);

          fetchWorkbenchPageData(functionCode, {
            current,
            size: PAGE_SIZE,
            offset,
            fetchTotal: false,
            filters,
            drillCondition: drillConditionSql || undefined
          })
            .then(result => {
              pageTimer.end();
              if (result.error) {
                console.error('[性能] 加载分片失败, offset=', offset, ', 错误:', result.error);
                chunkRecordsMap.set(offset, []);
                return;
              }

              const records = result.data.records;
              loadedRows += records.length;
              loadedCount.value = firstChunkSize + loadedRows;
              chunkRecordsMap.set(offset, records);
              mergeReadyPages();
            })
            .finally(async () => {
              activeRequests -= 1;

              if (nextChunkIndex >= totalOffsets.length && activeRequests === 0) {
                console.log('[性能] 后台加载完成，总数据量:', firstChunkSize + loadedRows, '期望:', totalRecords);
                workbenchStore.setCache(functionCode, params, {
                  pageMeta: meta,
                  serverRows: serverRows.value,
                  total: totalRecords,
                  isDataLoaded: true
                });

                isChunkLoading.value = false;

                const cacheTimer = createTimer('更新缓存');
                workbenchStore.setCache(functionCode, params, {
                  pageMeta: meta,
                  serverRows: serverRows.value,
                  total: totalRecords,
                  isDataLoaded: true
                });
                cacheTimer.end();

                bgTimer.end();
                resolve();
              } else {
                schedule();
              }
            });
        }
      };

      schedule();
    });
  }

  async function loadPage() {
    const totalTimer = createTimer('loadPage 总耗时');
    const functionCode = options.functionCode.value.trim();
    const params = options.params.value.trim();

    logger('info', `========== loadPage 开始 ==========`);
    logger('info', `functionCode: "${functionCode}"`);
    logger('info', `params: "${params}"`);
    logger('info', `当前时间戳: ${performance.now().toFixed(1)}ms`);

    if (!functionCode) {
      logger('warn', 'functionCode 为空，跳过加载');
      pageMeta.value = null;
      serverRows.value = [];
      total.value = 0;
      logger('info', `========== loadPage 结束（空 functionCode）==========`);
      return;
    }

    isInitialLoading.value = true;
    logger('debug', `设置 isInitialLoading = true`);

    const cached = workbenchStore.getCache(functionCode, params);
    const isCacheComplete = cached && cached.isDataLoaded && cached.serverRows.length === cached.total;
    
    logger('info', `缓存检查结果: 命中=${!!cached}, 完整=${isCacheComplete}`);
    if (cached) {
      logger('debug', `缓存数据: total=${cached.total}, serverRows.length=${cached.serverRows?.length || 0}, isDataLoaded=${cached.isDataLoaded}`);
    }

    if (isCacheComplete) {
      logger('info', `========== 开始缓存恢复 ==========`);
      logger('info', `缓存数据量: ${cached.serverRows.length} 条`);
      const cacheTimer = createTimer('📦 缓存恢复总耗时');

      const step1Timer = createTimer('  [缓存-1] 恢复基本状态');
      logger('info', `步骤1: 恢复基本状态`);
      pageMeta.value = cached.pageMeta;
      total.value = cached.total;
      totalCount.value = cached.total;
      loadedCount.value = cached.total;
      isInitialChunkLoaded.value = true;
      loading.value = false;
      loadedFunctionCode.value = functionCode;
      loadedParams.value = params;
      isDataLoaded.value = true;
      step1Timer.end();
      logger('debug', `步骤1完成: pageMeta=${pageMeta.value?.title || 'null'}, total=${total.value}`);

      const step2Timer = createTimer('  [缓存-2] 恢复 UI 状态');
      logger('info', `步骤2: 恢复 UI 状态`);
      const cachedUIState = workbenchStore.getUIState(functionCode, params);
      if (cachedUIState) {
        logger('debug', `缓存的 UI 状态:`, cachedUIState);
        conditionVisible.value = cachedUIState.conditionVisible;
        quickKeyword.value = cachedUIState.quickKeyword;
        selectedField.value = cachedUIState.selectedField;
        selectedOperator.value = cachedUIState.selectedOperator || 'contains';
        selectedValue.value = cachedUIState.selectedValue;
      } else {
        logger('warn', '未找到缓存的 UI 状态');
      }

      const cachedPage = workbenchStore.getPage(functionCode, params);
      const cachedPageSize = workbenchStore.getPageSize(functionCode, params);
      if (cachedPage > 1 || cachedPageSize !== PAGE_SIZE_OPTIONS[0]) {
        logger('info', `恢复分页状态: page=${cachedPage}, pageSize=${cachedPageSize}`);
        isRestoringPage.value = true;
        page.value = cachedPage;
        pageSize.value = cachedPageSize;
      }
      step2Timer.end();

      const step3Timer = createTimer('  [缓存-3] 恢复表格数据');
      logger('info', `步骤3: 恢复表格数据`);
      if (cached.serverRows.length > CHUNK_SIZE) {
        logger('info', `大数据量分片恢复: 总数=${cached.serverRows.length}, 先显示前 ${CHUNK_SIZE} 条`);
        const firstChunk = cached.serverRows.slice(0, CHUNK_SIZE);
        serverRows.value = firstChunk;
        logger('debug', `已设置第一片数据，长度=${serverRows.value.length}`);
        step3Timer.end();

        requestAnimationFrame(() => {
          const step4Timer = createTimer('  [缓存-4] 恢复剩余数据');
          logger('info', `步骤4: 恢复剩余数据`);
          setTimeout(() => {
            serverRows.value = cached.serverRows;
            isInitialLoading.value = false;
            step4Timer.end();
            cacheTimer.end();
            totalTimer.end();
            logger('info', `缓存恢复完成 ✅, 总耗时=${totalTimer.elapsed().toFixed(2)}ms`);
            logger('info', `========== loadPage 结束（缓存恢复）==========`);
          }, 100);
        });
      } else {
        serverRows.value = cached.serverRows;
        isInitialLoading.value = false;
        logger('debug', `小数据量直接恢复: ${serverRows.value.length} 条`);
        step3Timer.end();
        cacheTimer.end();
        totalTimer.end();
        logger('info', `缓存恢复完成 ✅（小数据量直接恢复）, 总耗时=${totalTimer.elapsed().toFixed(2)}ms`);
        logger('info', `========== loadPage 结束（缓存恢复）==========`);
      }

      setTimeout(() => {
        const selectTimer = createTimer('  [缓存-5] 恢复行选择状态');
        const cachedSelectedRows = workbenchStore.getSelectedRows(functionCode, params);
        if (cachedSelectedRows.length > 0 && options.gridApi.value && !options.gridApi.value.isDestroyed()) {
          isRestoringSelection.value = true;
          const rowsToRestore = cachedSelectedRows.slice(0, 10);
          const guidSet = new Set(rowsToRestore.filter((r: any) => r.GUID).map((r: any) => r.GUID));
          const idSet = new Set(rowsToRestore.filter((r: any) => r.id).map((r: any) => r.id));

          options.gridApi.value.forEachNode(node => {
            const rowData = node.data;
            if (!rowData) return;
            const isSelected = (rowData.GUID && guidSet.has(rowData.GUID)) || (rowData.id && idSet.has(rowData.id));
            if (isSelected) {
              node.setSelected(true);
            }
          });
          isRestoringSelection.value = false;
        }
        isRestoringPage.value = false;
        selectTimer.end();
        options.checkScrollPosition();
      }, 200);

      return;
    }

    loading.value = true;
    logger('debug', `设置 loading = true`);

    logger('info', `========== 开始网络请求 ==========`);
    const { filters, conditionSql: drillConditionSql } = parseDrillParams(params);
    logger('debug', `解析钻取参数完成: filters=${JSON.stringify(filters)}, drillConditionSql="${drillConditionSql}"`);

    logger('info', `步骤1: 获取页面元数据`);
    const metaTimer = createTimer('获取页面元数据');
    logger('debug', `调用 fetchWorkbenchPage("${functionCode}")`);
    const pageResult = await fetchWorkbenchPage(functionCode);
    metaTimer.end();
    logger('info', `获取页面元数据完成，耗时=${metaTimer.elapsed().toFixed(2)}ms`);

    if (pageResult.error) {
      const errorMsg = '工作台初始化失败';
      logger('error', `${errorMsg}:`, pageResult.error);
      options.notify('error', errorMsg);
      loading.value = false;
      logger('info', `========== loadPage 结束（元数据获取失败）==========`);
      return;
    }

    const data = pageResult.data;
    pageMeta.value = data.meta;
    page.value = 1;
    pageSize.value = PAGE_SIZE_OPTIONS[0];
    selectedField.value = data.meta.conditions[0]?.fieldKey || '';
    selectedValue.value = '';
    logger('debug', `页面元数据: pageName=${data.meta.title}, conditionsCount=${data.meta.conditions?.length || 0}`);

    logger('info', `步骤2: 获取第一页数据`);
    const firstPageTimer = createTimer('获取第一页数据');
    logger('debug', `调用 fetchWorkbenchPageData(), size=${CHUNK_SIZE}, fetchTotal=true`);
    const firstPageResult = await fetchWorkbenchPageData(functionCode, {
      current: 1,
      size: CHUNK_SIZE,
      fetchTotal: true,
      filters,
      drillCondition: drillConditionSql || undefined
    });
    firstPageTimer.end();
    logger('info', `获取第一页数据完成，耗时=${firstPageTimer.elapsed().toFixed(2)}ms`);

    if (firstPageResult.error) {
      const errorMsg = '获取数据失败';
      logger('error', `${errorMsg}:`, firstPageResult.error);
      options.notify('error', errorMsg);
      loading.value = false;
      logger('info', `========== loadPage 结束（数据获取失败）==========`);
      return;
    }

    const firstPageData = firstPageResult.data;
    total.value = firstPageData.total;
    totalCount.value = firstPageData.total;
    logger('info', `获取数据成功: total=${firstPageData.total}, records.length=${firstPageData.records.length}, hasMore=${firstPageData.hasMore}`);

    logger('info', `步骤3: 首屏渲染`);
    const renderTimer = createTimer('首屏渲染');
    serverRows.value = firstPageData.records;
    loadedCount.value = firstPageData.records.length;
    isInitialChunkLoaded.value = true;
    loading.value = false;
    renderTimer.end();
    logger('info', `首屏渲染完成，耗时=${renderTimer.elapsed().toFixed(2)}ms`);

    logger('info', `步骤4: 保存到缓存`);
    workbenchStore.setCache(functionCode, params, {
      pageMeta: data.meta,
      serverRows: firstPageData.records,
      total: firstPageData.total,
      isDataLoaded: false
    });
    logger('debug', `缓存已保存: serverRows.length=${firstPageData.records.length}`);

    totalTimer.end();

    if (firstPageData.hasMore) {
      logger('info', `步骤5: 后台加载剩余数据，总数=${firstPageData.total}`);
      isChunkLoading.value = true;
      logger('debug', `设置 isChunkLoading = true`);

      setTimeout(() => {
        logger('debug', `启动后台加载: loadRemainingData()`);
        loadRemainingData(functionCode, params, data.meta, filters, drillConditionSql, firstPageData.total);
      }, 500);
    } else {
      logger('info', `数据量较小，已全部加载，标记缓存为完整`);
      workbenchStore.setCache(functionCode, params, {
        pageMeta: data.meta,
        serverRows: firstPageData.records,
        total: firstPageData.total,
        isDataLoaded: true
      });
    }

    setTimeout(() => {
      logger('debug', `调用 checkScrollPosition()`);
      options.checkScrollPosition();
    }, 100);

    setTimeout(() => {
      logger('info', `步骤6: 调整列宽度`);
      const api = options.gridApi.value;
      if (!api || api.isDestroyed()) {
        logger('warn', `gridApi 为空或已销毁，跳过列宽度调整`);
        return;
      }

      const columnState = api.getColumnState();
      if (!columnState || !Array.isArray(columnState)) {
        logger('warn', `columnState 无效，跳过列宽度调整`);
        return;
      }

      const allColIds = columnState
        .map((state: any) => state.colId)
        .filter((colId: string) => {
          if (colId === 'ag-Grid-SelectionColumn') return false;

          const column = api.getColumn(colId);
          if (column) {
            const def = column.getColDef();
            if (isGuidColumn(String(def.field || ''), String(def.headerName || def.field || ''))) {
              return false;
            }
          }

          return true;
        });

      if (allColIds.length > 0) {
        api.autoSizeColumns(allColIds, false);

        const maxWidth = 300;
        allColIds.forEach((colId: string) => {
          const column = api.getColumn(colId);
          if (column) {
            const currentWidth = column.getActualWidth();
            if (currentWidth > maxWidth) {
              api.setColumnWidths([{ key: colId, newWidth: maxWidth }]);
            }
          }
        });
        logger('debug', `列宽度调整完成: ${allColIds.length} 列`);
      } else {
        logger('warn', `没有需要调整宽度的列`);
      }
      isInitialLoading.value = false;
      logger('debug', `设置 isInitialLoading = false`);
    }, 300);

    logger('info', `loadPage 主流程完成，总耗时=${totalTimer.elapsed().toFixed(2)}ms`);
    logger('info', `========== loadPage 结束（网络请求）==========`);
  }

  function checkAndLoadData() {
    const currentFunctionCode = options.functionCode.value;
    const currentParams = options.params.value;
    const lockKey = `${currentFunctionCode}_${currentParams}`;
    const cached = workbenchStore.getCache(currentFunctionCode, currentParams);
    const isCacheComplete = cached && cached.isDataLoaded && cached.serverRows.length === cached.total;

    logger('info', `========== checkAndLoadData 开始 ==========`);
    logger('info', `currentFunctionCode: "${currentFunctionCode}"`);
    logger('info', `currentParams: "${currentParams}"`);
    logger('info', `lockKey: "${lockKey}"`);
    logger('info', `当前状态: isDataLoaded=${isDataLoaded.value}, loadedFunctionCode="${loadedFunctionCode.value}"`);
    logger('info', `缓存状态: 命中=${!!cached}, 完整=${isCacheComplete}`);

    if (!currentFunctionCode) {
      logger('warn', 'functionCode 为空，跳过加载');
      logger('info', `========== checkAndLoadData 结束（空 functionCode）==========`);
      return;
    }

    if (loadingLocks.get(lockKey) && !isCacheComplete) {
      logger('warn', `${lockKey} 正在加载中，跳过重复请求`);
      logger('info', `========== checkAndLoadData 结束（重复请求）==========`);
      return;
    }

    const shouldLoad =
      !isDataLoaded.value || currentFunctionCode !== loadedFunctionCode.value || currentParams !== loadedParams.value;
    logger('info', `是否需要加载: ${shouldLoad}`);
    if (!shouldLoad) {
      logger('debug', `原因: isDataLoaded=${isDataLoaded.value}, loadedFunctionCode="${loadedFunctionCode.value}", loadedParams="${loadedParams.value}"`);
    }

    if (shouldLoad) {
      logger('info', `开始加载数据`);
      logger('debug', `设置加载锁: ${lockKey}`);
      loadingLocks.set(lockKey, true);
      loadedFunctionCode.value = currentFunctionCode;
      loadedParams.value = currentParams;
      logger('debug', `调用 loadPage()`);
      loadPage();
      isDataLoaded.value = true;
      setTimeout(() => {
        logger('debug', `释放加载锁: ${lockKey}`);
        loadingLocks.delete(lockKey);
      }, 500);
    } else {
      logger('info', '数据已加载且未变化，跳过');
    }
    logger('info', `========== checkAndLoadData 结束 ==========`);
  }

  onMounted(() => {
    const functionCode = options.functionCode.value;
    const params = options.params.value;
    logger('info', `========== onMounted ==========`);
    logger('info', `functionCode: "${functionCode}"`);
    logger('info', `params: "${params}"`);
    logger('info', `时间戳: ${performance.now().toFixed(1)}ms`);
    checkAndLoadData();
  });

  onActivated(() => {
    const functionCode = options.functionCode.value;
    logger('info', `========== onActivated ==========`);
    logger('info', `functionCode: "${functionCode}"`);
    logger('info', `isDataLoaded: ${isDataLoaded.value}`);
    logger('info', `时间戳: ${performance.now().toFixed(1)}ms`);
  });

  onDeactivated(() => {
    const functionCode = options.functionCode.value;
    logger('info', `========== onDeactivated ==========`);
    logger('info', `functionCode: "${functionCode}"`);
    logger('info', `时间戳: ${performance.now().toFixed(1)}ms`);
  });

  watch(
    () => ({ code: options.functionCode.value, params: options.params.value }),
    (newVal, oldVal) => {
      const newFunctionCode = newVal.code;
      const newParams = newVal.params;
      const oldFunctionCode = oldVal.code;
      const oldParams = oldVal.params;

      if (newFunctionCode === oldFunctionCode && newParams !== oldParams) {
        logger('info', `========== 钻取事件 ==========`);
        logger('info', `functionCode: "${newFunctionCode}"`);
        logger('info', `params 变化: "${oldParams}" -> "${newParams}"`);
        loadedFunctionCode.value = newFunctionCode;
        loadedParams.value = newParams;
        isDataLoaded.value = false;
        logger('debug', `调用 loadPage() 重新加载数据`);
        loadPage();
        isDataLoaded.value = true;
      }
    },
    { deep: true }
  );

  return {
    loadPage,
    isDataLoaded,
    loadedFunctionCode,
    loadedParams
  };
}