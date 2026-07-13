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
 * 从请求错误中提取可读的提示信息
 * 优先取后端业务返回的 msg 字段；若无则回退到 AxiosError.message；再无则使用默认提示
 */
function extractErrorMessage(error: any, fallback: string): string {
  const backendMsg = error?.response?.data?.msg;
  if (typeof backendMsg === 'string' && backendMsg.trim() !== '') {
    return backendMsg;
  }
  return error?.message || fallback;
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
        options.notify('error', extractErrorMessage(error, '执行数据整理失败'));
        return;
      }

      if (data?.success) {
        options.notify('success', data.message || '数据整理执行成功');
        await options.loadPage();
      } else {
        options.notify('error', data?.message || '执行数据整理失败');
      }
    } catch (err) {
      options.notify('error', extractErrorMessage(err, '执行数据整理失败'));
      console.error('数据整理执行错误:', err);
    } finally {
      options.loading.value = false;
    }
  }

  return { handleUpkeep };
}
