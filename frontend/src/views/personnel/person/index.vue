<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useMessage } from 'naive-ui';
import type { TreeOption } from 'naive-ui';
import {
  fetchPersonTree,
  fetchPersonDetail,
  fetchUpdatePerson,
  fetchMergePerson,
  fetchPersonDedup
} from '@/service/api';

const message = useMessage();

// 左侧树
const treeData = ref<TreeOption[]>([]);
const treeLoading = ref(false);
const searchKeyword = ref('');
const expandedKeys = ref<string[]>([]);

// 右侧详情
const currentPerson = ref<Api.Person.PersonDetail | null>(null);
const detailLoading = ref(false);
const isEditing = ref(false);
const editForm = ref<Record<string, any>>({});
const submitting = ref(false);

// 合并弹窗
const mergeVisible = ref(false);
const mergeForm = ref({ sourceCode: '', targetCode: '' });
const mergeSubmitting = ref(false);
const mergePreview = ref<string>('');

// 可编辑字段（与后端 PERSON_FIELDS 一致）
const editableFields = [
  { key: '姓名', label: '姓名', type: 'input' },
  { key: '手机号码', label: '手机号码', type: 'input' },
  { key: '身份证号', label: '身份证号', type: 'input' },
  { key: '性别', label: '性别', type: 'input' },
  { key: '年龄', label: '年龄', type: 'input' },
  { key: '学校', label: '学校', type: 'input' },
  { key: '专业', label: '专业', type: 'input' },
  { key: '学历', label: '学历', type: 'input' },
  { key: '现住址', label: '现住址', type: 'input' },
  { key: '工作履历', label: '工作履历', type: 'textarea' },
  { key: '属地', label: '属地', type: 'input' }
];

// 只读字段
const readonlyFields = computed(() => {
  if (!currentPerson.value) return [];
  return [
    { label: '人员编码', value: currentPerson.value.人员编码 },
    { label: '操作时间', value: currentPerson.value.操作时间 || '-' },
    { label: '操作人员', value: currentPerson.value.操作人员 || '-' }
  ];
});

// 树搜索过滤
const filteredTreeData = computed(() => {
  if (!searchKeyword.value) return treeData.value;
  const kw = searchKeyword.value.trim();

  const filterNode = (nodes: TreeOption[]): TreeOption[] => {
    const result: TreeOption[] = [];
    for (const node of nodes) {
      if (node.children && node.children.length > 0) {
        const filteredChildren = filterNode(node.children);
        if (filteredChildren.length > 0 || String(node.label || '').includes(kw)) {
          result.push({ ...node, children: filteredChildren });
        }
      } else if (String(node.label || '').includes(kw)) {
        result.push(node);
      }
    }
    return result;
  };

  return filterNode(treeData.value);
});

async function loadTree() {
  treeLoading.value = true;
  try {
    const { data } = await fetchPersonTree();
    if (data) {
      treeData.value = data as unknown as TreeOption[];
      // 默认展开第一个属地
      if (data.length > 0) {
        expandedKeys.value = [data[0].key];
      }
    }
  } catch {
    message.error('主档列表加载失败');
  } finally {
    treeLoading.value = false;
  }
}

async function handleSelect(keys: string[], options: Array<TreeOption | null>) {
  const option = options?.[0];
  if (!option) return;
  const person = (option as any).person as Api.Person.PersonDetail | undefined;
  if (!person) {
    return; // 属地分组节点
  }
  await loadDetail(person.人员编码);
}

async function loadDetail(code: string) {
  detailLoading.value = true;
  isEditing.value = false;
  try {
    const { data } = await fetchPersonDetail(code);
    if (data) {
      currentPerson.value = data;
    }
  } catch {
    message.error('主档详情加载失败');
  } finally {
    detailLoading.value = false;
  }
}

function startEdit() {
  if (!currentPerson.value) return;
  editForm.value = {};
  for (const f of editableFields) {
    editForm.value[f.key] = (currentPerson.value as any)[f.key] ?? '';
  }
  isEditing.value = true;
}

function cancelEdit() {
  isEditing.value = false;
}

async function submitEdit() {
  if (!currentPerson.value) return;
  submitting.value = true;
  try {
    const { error } = await fetchUpdatePerson({
      code: currentPerson.value.人员编码,
      ...editForm.value
    });
    if (!error) {
      message.success('主档修改成功');
      isEditing.value = false;
      await loadDetail(currentPerson.value.人员编码);
      await loadTree();
    }
  } finally {
    submitting.value = false;
  }
}

// 合并功能
function openMerge() {
  if (!currentPerson.value) {
    message.warning('请先在左侧选择一个人员主档作为源');
    return;
  }
  mergeForm.value = { sourceCode: currentPerson.value.人员编码, targetCode: '' };
  mergePreview.value = '';
  mergeVisible.value = true;
}

async function checkMergeTarget() {
  const targetCode = mergeForm.value.targetCode.trim();
  if (!targetCode) {
    message.warning('请输入目标人员编码');
    return;
  }
  try {
    const { data } = await fetchPersonDetail(targetCode);
    if (data) {
      mergePreview.value = `目标主档：${data.姓名}（${data.人员编码}）手机：${data.手机号码 || '-'}`;
    }
  } catch {
    mergePreview.value = `未找到编码 ${targetCode} 对应的主档`;
  }
}

async function submitMerge() {
  const { sourceCode, targetCode } = mergeForm.value;
  if (!sourceCode || !targetCode) {
    message.warning('源编码和目标编码不能为空');
    return;
  }
  if (sourceCode === targetCode) {
    message.warning('源编码与目标编码不能相同');
    return;
  }
  mergeSubmitting.value = true;
  try {
    const { error } = await fetchMergePerson({ sourceCode, targetCode });
    if (!error) {
      message.success('合并成功');
      mergeVisible.value = false;
      currentPerson.value = null;
      await loadTree();
    }
  } finally {
    mergeSubmitting.value = false;
  }
}

// 查重演示（合并前核对）
async function handleDedup() {
  if (!currentPerson.value) return;
  const p = currentPerson.value;
  try {
    const { data } = await fetchPersonDedup({
      姓名: p.姓名,
      手机号码: p.手机号码,
      身份证号: p.身份证号 || undefined
    });
    if (data) {
      if (data.level === 'none') {
        message.info('未发现疑似重复主档');
      } else {
        const names = data.matches.map(m => `${m.姓名}(${m.人员编码})`).join('、');
        message.warning(`发现${data.level === 'hard' ? '硬命中' : '疑似'}重复：${names}`);
      }
    }
  } catch {
    message.error('查重失败');
  }
}

onMounted(() => {
  loadTree();
});
</script>

<template>
  <div class="person-archive-page">
    <div class="left-panel">
      <div class="search-bar">
        <n-input v-model:value="searchKeyword" placeholder="搜索姓名/编码" size="small" clearable />
      </div>
      <n-spin :show="treeLoading">
        <n-tree
          block-line
          :data="filteredTreeData"
          :expanded-keys="expandedKeys"
          :pattern="searchKeyword"
          key-field="key"
          label-field="label"
          children-field="children"
          :on-update:expanded-keys="(keys: string[]) => (expandedKeys = keys)"
          :on-update:selected-keys="handleSelect"
          selectable
        />
      </n-spin>
    </div>

    <div class="right-panel">
      <n-empty v-if="!currentPerson && !detailLoading" description="请从左侧选择人员主档" class="empty-hint" />

      <n-spin :show="detailLoading" v-else>
        <template v-if="currentPerson">
          <div class="detail-header">
            <div class="person-title">
              <span class="name">{{ currentPerson.姓名 }}</span>
              <n-tag size="small" type="info">{{ currentPerson.人员编码 }}</n-tag>
              <n-tag v-if="currentPerson.合并至" size="small" type="warning">已合并至 {{ currentPerson.合并至 }}</n-tag>
            </div>
            <n-space>
              <n-button size="small" @click="handleDedup">查重</n-button>
              <n-button size="small" type="warning" @click="openMerge">合并</n-button>
              <n-button v-if="!isEditing" size="small" type="primary" @click="startEdit">编辑</n-button>
              <template v-else>
                <n-button size="small" @click="cancelEdit">取消</n-button>
                <n-button size="small" type="primary" :loading="submitting" @click="submitEdit">保存</n-button>
              </template>
            </n-space>
          </div>

          <!-- 只读信息 -->
          <n-descriptions :column="3" size="small" bordered class="readonly-desc">
            <n-descriptions-item v-for="f in readonlyFields" :key="f.label" :label="f.label">
              {{ f.value }}
            </n-descriptions-item>
          </n-descriptions>

          <!-- 编辑表单 -->
          <n-form v-if="isEditing" label-placement="left" label-width="90" class="edit-form">
            <n-grid :cols="2" :x-gap="16">
              <n-form-item-gi v-for="f in editableFields" :key="f.key" :label="f.label">
                <n-input
                  v-if="f.type === 'input'"
                  v-model:value="editForm[f.key]"
                  :placeholder="`输入${f.label}`"
                />
                <n-input
                  v-else-if="f.type === 'textarea'"
                  v-model:value="editForm[f.key]"
                  type="textarea"
                  :rows="2"
                  :placeholder="`输入${f.label}`"
                />
              </n-form-item-gi>
            </n-grid>
          </n-form>

          <!-- 只读展示 -->
          <n-descriptions v-else :column="2" size="small" bordered>
            <n-descriptions-item v-for="f in editableFields" :key="f.key" :label="f.label">
              {{ (currentPerson as any)[f.key] || '-' }}
            </n-descriptions-item>
          </n-descriptions>
        </template>
      </n-spin>
    </div>

    <!-- 合并弹窗 -->
    <n-modal v-model:show="mergeVisible" preset="dialog" title="重档合并" positive-text="确认合并" negative-text="取消" :loading="mergeSubmitting" @positive-click="submitMerge">
      <n-space vertical>
        <n-alert type="warning" :show-icon="true">
          源主档将被置无效（合并至=目标编码），其下游邀约/面试/培训/在职记录的人员编码将全部改为目标编码。此操作可回溯（hr_audit_log 有记录）。
        </n-alert>
        <n-form-item label="源编码">
          <n-input v-model:value="mergeForm.sourceCode" disabled />
        </n-form-item>
        <n-form-item label="目标编码">
          <n-input v-model:value="mergeForm.targetCode" placeholder="输入合并保留方的编码" />
        </n-form-item>
        <n-button size="small" @click="checkMergeTarget">核对目标主档</n-button>
        <div v-if="mergePreview" class="merge-preview">{{ mergePreview }}</div>
      </n-space>
    </n-modal>
  </div>
</template>

<style scoped lang="scss">
.person-archive-page {
  display: flex;
  height: 100%;
  overflow: hidden;
  gap: 8px;

  .left-panel {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid var(--n-border-color);
    padding-right: 8px;
    overflow: auto;

    .search-bar {
      margin-bottom: 8px;
    }
  }

  .right-panel {
    flex: 1;
    overflow: auto;
    padding: 8px 4px;

    .empty-hint {
      margin-top: 120px;
    }

    .detail-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;

      .person-title {
        display: flex;
        align-items: center;
        gap: 8px;

        .name {
          font-size: 18px;
          font-weight: 600;
        }
      }
    }

    .readonly-desc {
      margin-bottom: 12px;
    }

    .edit-form {
      margin-top: 12px;
    }

    .merge-preview {
      padding: 6px 10px;
      background: var(--n-color-embedded);
      border-radius: 4px;
      font-size: 13px;
    }
  }
}
</style>
