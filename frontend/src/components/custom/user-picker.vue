<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import { NModal, NTree, NInput, NCheckbox, NButton, NSpace, NEmpty, NSpin, NAvatar, NTag } from 'naive-ui';
import { fetchTodoUserOptions, fetchTodoDeptTree, type TodoUserOption, type DeptTreeNode } from '@/service/api/oa-todo';

interface UserPickerProps {
  show: boolean;
  multiple?: boolean;
  title?: string;
  modelValue?: string[];
}

const props = withDefaults(defineProps<UserPickerProps>(), {
  multiple: false,
  title: '选择人员',
  modelValue: () => []
});

const emit = defineEmits<{
  (e: 'update:show', val: boolean): void;
  (e: 'update:modelValue', val: string[]): void;
  (e: 'confirm', val: TodoUserOption[]): void;
}>();

// ============ 部门树 ============
const deptTree = ref<DeptTreeNode[]>([]);
const deptLoading = ref(false);
const selectedDeptCode = ref<string>('');
const deptSearch = ref('');
const expandedKeys = ref<string[]>([]);

function filterDeptTree(nodes: DeptTreeNode[], keyword: string): DeptTreeNode[] {
  if (!keyword) return nodes;
  const result: DeptTreeNode[] = [];
  for (const node of nodes) {
    const children = node.children ? filterDeptTree(node.children, keyword) : [];
    if (
      node.部门名称.includes(keyword) ||
      node.部门全称.includes(keyword) ||
      children.length > 0
    ) {
      result.push({ ...node, children });
    }
  }
  return result;
}

const filteredTree = computed(() => filterDeptTree(deptTree.value, deptSearch.value));

// 搜索时自动展开所有匹配层级；清空搜索后展开根节点
watch(
  () => deptSearch.value,
  (keyword) => {
    if (keyword) {
      expandedKeys.value = collectKeysWithChildren(filteredTree.value);
    } else {
      expandedKeys.value = deptTree.value.length > 0 ? [deptTree.value[0].部门编码] : [];
    }
  }
);

function collectKeysWithChildren(nodes: DeptTreeNode[]): string[] {
  const keys: string[] = [];
  for (const node of nodes) {
    if (node.children && node.children.length > 0) {
      keys.push(node.部门编码);
      keys.push(...collectKeysWithChildren(node.children));
    }
  }
  return keys;
}

async function loadDeptTree() {
  deptLoading.value = true;
  try {
    const res = await fetchTodoDeptTree();
    if (res.data) {
      deptTree.value = res.data;
      // 默认展开根节点
      expandedKeys.value = deptTree.value.length > 0 ? [deptTree.value[0].部门编码] : [];
    }
  } catch {
    deptTree.value = [];
  } finally {
    deptLoading.value = false;
  }
}

function handleDeptSelect(keys: string[]) {
  selectedDeptCode.value = keys[0] || '';
  loadUsers();
}

function handleExpandedKeysChange(keys: string[]) {
  expandedKeys.value = keys;
}

// ============ 人员列表 ============
const userList = ref<TodoUserOption[]>([]);
const userLoading = ref(false);
const userSearch = ref('');
const checkedUsers = ref<Map<string, TodoUserOption>>(new Map());

// 头像颜色（根据姓名生成）
const avatarColors = [
  '#1677ff', '#52c41a', '#faad14', '#f5222d', '#722ed1',
  '#13c2c2', '#eb2f96', '#fa8c16', '#2f54eb', '#a0d911'
];
function getAvatarColor(name: string): string {
  if (!name) return '#1677ff';
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    hash = name.charCodeAt(i) + ((hash << 5) - hash);
  }
  return avatarColors[Math.abs(hash) % avatarColors.length];
}

function getAvatarText(name: string): string {
  if (!name) return '?';
  return name.charAt(0);
}

async function loadUsers() {
  userLoading.value = true;
  try {
    const res = await fetchTodoUserOptions(userSearch.value, selectedDeptCode.value);
    if (res.data) userList.value = res.data;
  } catch {
    userList.value = [];
  } finally {
    userLoading.value = false;
  }
}

// 搜索防抖
let searchTimer: ReturnType<typeof setTimeout> | null = null;
function handleUserSearch(val: string) {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    loadUsers();
  }, 300);
}

// ============ 选择逻辑 ============
const selectedUserList = computed(() => Array.from(checkedUsers.value.values()));

function toggleUser(user: TodoUserOption) {
  const key = user.工号;
  if (checkedUsers.value.has(key)) {
    checkedUsers.value.delete(key);
  } else {
    if (!props.multiple) {
      checkedUsers.value.clear();
    }
    checkedUsers.value.set(key, user);
  }
  checkedUsers.value = new Map(checkedUsers.value);
}

function removeUser(workId: string) {
  checkedUsers.value.delete(workId);
  checkedUsers.value = new Map(checkedUsers.value);
}

function isChecked(workId: string): boolean {
  return checkedUsers.value.has(workId);
}

function isAllChecked(): boolean {
  if (userList.value.length === 0) return false;
  return userList.value.every(u => checkedUsers.value.has(u.工号));
}

function toggleAll() {
  if (isAllChecked()) {
    userList.value.forEach(u => checkedUsers.value.delete(u.工号));
  } else {
    if (!props.multiple) {
      // 单选模式下不处理全选
      return;
    }
    userList.value.forEach(u => checkedUsers.value.set(u.工号, u));
  }
  checkedUsers.value = new Map(checkedUsers.value);
}

// ============ 弹窗控制 ============
watch(
  () => props.show,
  async (val) => {
    if (val) {
      // 初始化选中
      checkedUsers.value = new Map();
      if (props.modelValue.length > 0) {
        // 加载用户详情（用于回显）
        try {
          const res = await fetchTodoUserOptions('', '');
          if (res.data) {
            res.data.forEach(u => {
              if (props.modelValue.includes(u.工号)) {
                checkedUsers.value.set(u.工号, u);
              }
            });
          }
        } catch {
          /* ignore */
        }
      }
      checkedUsers.value = new Map(checkedUsers.value);

      if (deptTree.value.length === 0) {
        loadDeptTree();
      }
      loadUsers();
    }
  }
);

function handleCancel() {
  emit('update:show', false);
}

function handleConfirm() {
  const users = selectedUserList.value;
  emit('update:modelValue', users.map(u => u.工号));
  emit('confirm', users);
  emit('update:show', false);
}

// 树节点渲染
function renderDeptLabel({ option }: { option: DeptTreeNode }) {
  return option.部门名称;
}
</script>

<template>
  <NModal
    :show="show"
    preset="card"
    :title="title"
    style="width: 720px"
    :mask-closable="false"
    @update:show="(v) => emit('update:show', v)"
  >
    <div class="user-picker">
      <!-- 左侧部门树 -->
      <div class="picker-left">
        <NInput
          v-model:value="deptSearch"
          size="small"
          placeholder="搜索部门"
          clearable
          style="margin-bottom: 8px"
        />
        <NSpin :show="deptLoading">
          <div class="tree-wrapper">
            <NTree
              v-if="filteredTree.length > 0"
              :data="filteredTree as any"
              key-field="部门编码"
              label-field="部门名称"
              children-field="children"
              selectable
              block-line
              block-node
              :selected-keys="selectedDeptCode ? [selectedDeptCode] : []"
              :expanded-keys="expandedKeys"
              @update:selected-keys="handleDeptSelect"
              @update:expanded-keys="handleExpandedKeysChange"
            />
            <NEmpty v-else description="暂无部门" size="small" />
          </div>
        </NSpin>
      </div>

      <!-- 右侧人员列表 -->
      <div class="picker-right">
        <div class="picker-toolbar">
          <NInput
            v-model:value="userSearch"
            size="small"
            placeholder="搜索工号/姓名"
            clearable
            @update:value="handleUserSearch"
          />
          <div class="picker-count">共 {{ userList.length }} 人</div>
        </div>

        <NSpin :show="userLoading">
          <div class="user-list">
            <div v-if="userList.length === 0 && !userLoading" class="empty-tip">
              暂无人员
            </div>
            <div v-else class="user-list-inner">
              <!-- 全选（仅多选模式） -->
              <div v-if="multiple" class="user-item all-item" @click="toggleAll">
                <NCheckbox :checked="isAllChecked()" />
                <span class="all-label">全选</span>
              </div>
              <div
                v-for="user in userList"
                :key="user.工号"
                class="user-item"
                :class="{ checked: isChecked(user.工号) }"
                @click="toggleUser(user)"
              >
                <NCheckbox :checked="isChecked(user.工号)" />
                <NAvatar
                  :size="28"
                  round
                  :style="{ backgroundColor: getAvatarColor(user.姓名) }"
                >
                  {{ getAvatarText(user.姓名) }}
                </NAvatar>
                <div class="user-info">
                  <div class="user-name">{{ user.姓名 }}</div>
                  <div class="user-dept">{{ user.员工部门全称 || user.工号 }}</div>
                </div>
              </div>
            </div>
          </div>
        </NSpin>
      </div>
    </div>

    <!-- 已选人员 -->
    <div v-if="selectedUserList.length > 0" class="selected-bar">
      <span class="selected-label">已选 {{ selectedUserList.length }} 人：</span>
      <div class="selected-tags">
        <NTag
          v-for="user in selectedUserList"
          :key="user.工号"
          round
          closable
          @close="removeUser(user.工号)"
        >
          {{ user.姓名 }}
        </NTag>
      </div>
    </div>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleCancel">取消</NButton>
        <NButton type="primary" @click="handleConfirm">
          确定{{ multiple ? `（已选 ${selectedUserList.length}）` : '' }}
        </NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped>
/* 弹窗主体：自身限高，保证整个弹窗（含标题栏/已选区/底部按钮）不超出视口 */
.user-picker {
  display: flex;
  height: 420px;
  max-height: max(220px, calc(90vh - 310px));
  gap: 12px;
  overflow: hidden;
}

/* NSpin 内部包裹层参与 flex 布局，否则 user-list / tree-wrapper 的 flex:1 不生效 */
.picker-left :deep(.n-spin-container),
.picker-left :deep(.n-spin-content),
.picker-right :deep(.n-spin-container),
.picker-right :deep(.n-spin-content) {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.picker-left {
  width: 220px;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--divider-color, #e5e7eb);
  padding-right: 12px;
  min-height: 0;
}

.tree-wrapper {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.picker-right {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.picker-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  flex-shrink: 0;
}

.picker-count {
  font-size: 12px;
  color: rgb(var(--base-text-color) / 0.55);
  white-space: nowrap;
}

.user-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  border: 1px solid rgb(var(--base-text-color) / 0.15);
  border-radius: 6px;
}

.user-list-inner {
  padding: 4px;
}

.empty-tip {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: rgb(var(--base-text-color) / 0.55);
  font-size: 13px;
}

.user-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.15s;
}

.user-item:hover {
  background: rgb(var(--primary-color) / 0.08);
}

.user-item.checked {
  background: rgb(var(--primary-color) / 0.16);
}

.user-item.all-item {
  border-bottom: 1px solid rgb(var(--base-text-color) / 0.15);
  margin-bottom: 4px;
  font-weight: 600;
}

.all-label {
  font-size: 13px;
}

.user-info {
  flex: 1;
  min-width: 0;
}

.user-name {
  font-size: 13px;
  font-weight: 500;
  color: rgb(var(--base-text-color));
}

.user-dept {
  font-size: 11px;
  color: rgb(var(--base-text-color) / 0.55);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.selected-bar {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid rgb(var(--base-text-color) / 0.15);
  display: flex;
  align-items: flex-start;
  gap: 8px;
  max-height: 84px;
  overflow-y: auto;
}

.selected-label {
  font-size: 13px;
  color: rgb(var(--base-text-color) / 0.55);
  white-space: nowrap;
  padding-top: 2px;
}

.selected-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  flex: 1;
}
</style>
