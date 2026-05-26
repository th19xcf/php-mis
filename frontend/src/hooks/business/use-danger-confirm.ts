import { ref, h } from 'vue';
import { NAlert, NInput, NText } from 'naive-ui';
import { useDialog, useMessage } from 'naive-ui';

export type DangerLevel = 'low' | 'medium' | 'high' | 'critical';

export interface DangerConfirmOptions {
  title: string;
  content: string;
  dangerLevel?: DangerLevel;
  confirmText?: string;
  cancelText?: string;
  requireInput?: boolean;
  inputPlaceholder?: string;
  inputValidator?: (value: string) => boolean;
}

export interface DangerConfirmResult {
  confirmed: boolean;
  inputValue?: string;
}

export function useDangerConfirm() {
  const dialog = useDialog();
  const message = useMessage();
  const isConfirming = ref(false);
  const inputValue = ref('');

  const dangerStyles: Record<DangerLevel, { type: 'warning' | 'error' | 'info'; size: 'small' | 'medium' | 'large' }> = {
    low: { type: 'info', size: 'small' },
    medium: { type: 'warning', size: 'small' },
    high: { type: 'warning', size: 'medium' },
    critical: { type: 'error', size: 'large' }
  };

  function confirm(options: DangerConfirmOptions): Promise<boolean> {
    return new Promise((resolve) => {
      const {
        title,
        content,
        dangerLevel = 'medium',
        confirmText = '确认',
        cancelText = '取消',
        requireInput = false,
        inputPlaceholder = '请输入',
        inputValidator
      } = options;

      isConfirming.value = false;
      inputValue.value = '';

      const dangerStyle = dangerStyles[dangerLevel];

      const contentEl = () => {
        const children: any[] = [
          h(NAlert, {
            type: dangerStyle.type,
            title: '危险操作警告',
            closable: false,
            style: { marginBottom: '16px' }
          }, () => content)
        ];

        if (requireInput) {
          children.push(
            h('div', { style: { marginTop: '16px' } }, [
              h('div', { style: { marginBottom: '8px' } }, [
                h(NText, { depth: 3 }, () => inputPlaceholder)
              ]),
              h(NInput, {
                value: inputValue.value,
                'onUpdate:value': (val: string) => {
                  inputValue.value = val;
                },
                placeholder: inputPlaceholder,
                clearable: true
              })
            ])
          );
        }

        return h('div', {}, children);
      };

      const canConfirm = () => {
        if (!requireInput) return true;
        if (inputValidator) {
          return inputValidator(inputValue.value);
        }
        return inputValue.value.trim().length > 0;
      };

      dialog.warning({
        title,
        content: contentEl,
        positiveText: confirmText,
        negativeText: cancelText,
        positiveButtonProps: {
          type: dangerLevel === 'critical' ? 'error' : 'warning',
          disabled: !canConfirm()
        },
        negativeButtonProps: {
          type: 'default'
        },
        onPositiveClick: () => {
          if (requireInput && inputValidator && !inputValidator(inputValue.value)) {
            message.error('输入验证失败，请检查输入内容');
            return;
          }
          resolve(true);
        },
        onNegativeClick: () => {
          resolve(false);
        },
        onClose: () => {
          resolve(false);
        }
      });
    });
  }

  function confirmDelete(count: number, itemName: string = '记录'): Promise<boolean> {
    return confirm({
      title: '确认删除',
      content: `确定要删除选中的 ${count} 条${itemName}吗？此操作不可恢复！`,
      dangerLevel: count > 1 ? 'critical' : 'high',
      confirmText: '确认删除',
      cancelText: '取消',
      requireInput: count > 5,
      inputPlaceholder: '请输入"确认删除"以继续',
      inputValidator: (value) => value === '确认删除'
    });
  }

  function confirmBatch(action: string, count: number): Promise<boolean> {
    return confirm({
      title: `确认批量${action}`,
      content: `确定要对选中的 ${count} 条记录执行"${action}"操作吗？`,
      dangerLevel: count > 10 ? 'high' : 'medium',
      confirmText: '确认执行',
      cancelText: '取消',
      requireInput: count > 20,
      inputPlaceholder: `请输入"确认${action}"以继续`,
      inputValidator: (value) => value === `确认${action}`
    });
  }

  function confirmTransfer(type: string, count: number, target: string): Promise<boolean> {
    return confirm({
      title: `确认转入${type}`,
      content: `确定要将选中的 ${count} 条记录转入"${target}"吗？`,
      dangerLevel: 'medium',
      confirmText: '确认转入',
      cancelText: '取消'
    });
  }

  return {
    confirm,
    confirmDelete,
    confirmBatch,
    confirmTransfer,
    isConfirming,
    inputValue
  };
}
