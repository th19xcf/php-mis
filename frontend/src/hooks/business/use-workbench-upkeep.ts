import type { Ref } from 'vue';
import { executeUpkeep } from '@/service/api/workbench';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchUpkeepOptions {
  getFunctionCode: () => string;
  loading: Ref<boolean>;
  loadPage: () => Promise<void> | void;
  notify: (type: NotifyType, message: string) => void;
}

/**
 * 工作台「数据整理」组合式函数
 *  - 调后端 /workbench/upkeep/:functionCode
 *  - 成功时调用 loadPage 重新加载
 */
export function useWorkbenchUpkeep(options: UseWorkbenchUpkeepOptions) {
  async function handleUpkeep() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    options.loading.value = true;
    try {
      const { data, error } = await executeUpkeep(functionCode);
      if (error) {
        options.notify('error', '执行数据整理失败');
        return;
      }

      if (data?.success) {
        options.notify('success', data.message || '数据整理执行成功');
        await options.loadPage();
      } else {
        options.notify('error', data?.message || '执行数据整理失败');
      }
    } catch (err) {
      options.notify('error', '执行数据整理失败');
      console.error('数据整理执行错误:', err);
    } finally {
      options.loading.value = false;
    }
  }

  return { handleUpkeep };
}
