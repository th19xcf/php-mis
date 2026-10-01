# 新建流程弹窗：审批人配置/超时规则改为结构化表单

## Context

新建流程弹窗（WorkflowDefForm.vue）中「审批人配置」和「超时规则」目前是 JSON textarea，对使用者不友好。需改为结构化表单控件（下拉选择 + 数字输入），与主流审批软件（钉钉/飞书）风格一致。

这两个字段存储于 `def_workflow_definition` 表，作为流程级默认模板（实际审批人解析发生在节点级 `def_workflow_node`）。改造仅涉及前端表单交互，后端接口不变（仍接收 JSON 对象）。

## 改造范围

仅修改 **1 个文件**：
- [WorkflowDefForm.vue](file:///d:/code/php/mis/frontend/src/views/workflow-manage/components/WorkflowDefForm.vue)

同时改造弹窗模式（NModal）和内联模式（edit-table）两处。

## 审批人配置改造

### 现状
单行 JSON textarea，用户需手写 `{"nodes":[...]}` 格式。

### 方案
拆为两个表单控件，复用 WorkflowNodeForm.vue 已有的选项定义：

| 控件 | 类型 | 选项/约束 |
|---|---|---|
| 审批人类型 | NSelect | 不配置 / 角色 / 部门 / 上级 / 指定人 / 发起人 |
| 审批人配置 | NInput | 根据类型动态 placeholder；上级/发起人/不配置时禁用 |

placeholder 随类型变化（与 WorkflowNodeForm.vue `approverConfigPlaceholder` 逻辑一致）：
- 角色：`角色编码,逗号分隔,如:R-APPROVER,R-MANAGER`
- 部门：`部门编码,如:D001（留空自动用发起人部门）`
- 指定人：`工号,逗号分隔,如:E001,E002`
- 上级/发起人：禁用

### JSON 映射

**存储格式**（提交时由前端组装）：
```json
{"approverType":"角色","approverConfig":"R-APPROVER,R-MANAGER"}
```

**回显时**：解析 JSON 提取 `approverType` + `approverConfig`；若旧数据格式不匹配（如 `{nodes:[...]}`），回退为 `approverType=""` + `approverConfig=原始JSON字符串`，不丢数据。

## 超时规则改造

### 现状
单行 JSON textarea，用户需手写 `{"days":2}` 格式。

### 方案
拆为三个表单控件：

| 控件 | 类型 | 选项/约束 |
|---|---|---|
| 超时时长 | NInputNumber | min=0, max=365, placeholder="0 表示不超时" |
| 超时单位 | NSelect | 天 / 小时 / 分钟 |
| 超时处理 | NSelect | 通知 / 自动同意 / 自动拒绝 |

### JSON 映射

**存储格式**（保持后端 `calculateThreshold` 已支持的 `days`/`hours`/`minutes` 字段）：
```json
{"days":2,"action":"通知"}
{"hours":48,"action":"通知"}
{"minutes":30,"action":"通知"}
```

**回显时**：解析 JSON，按 `days`/`hours`/`minutes` 字段反推单位；提取 `action`（无则默认"通知"）。旧格式兼容：若值为 `{"days":2}` 无 action，action 默认"通知"。

## 实现步骤

### 1. script 部分

- 新增 `approverTypeOptions`、`timeoutUnitOptions`、`timeoutActionOptions` 常量（复用 WorkflowNodeForm.vue 的选项值）
- `formData` 结构调整：
  ```ts
  // 旧
  审批人配置: '' as string,
  超时规则: '' as string
  
  // 新
  审批人类型: '' as string,
  审批人配置: '' as string,
  超时时长: 0 as number,
  超时单位: '天' as string,
  超时处理: '通知' as string
  ```
- 新增 `approverConfigPlaceholder` computed（与 WorkflowNodeForm.vue 一致）
- 新增 `approverConfigDisabled` computed（上级/发起人/空时禁用）
- `watch` 回显逻辑：解析 JSON → 拆分到新字段
- `buildPayload` 提交逻辑：新字段 → 组装 JSON 对象
  - 审批人配置：`{approverType, approverConfig}` 或 null（类型为空时）
  - 超时规则：`{[unit字段]: 时长, action: 处理}` 或 null（时长为 0 时）

### 2. 弹窗模式 template

替换两个 NFormItem：
- 审批人配置 → 两个 NFormItem（审批人类型 + 审批人配置）
- 超时规则 → NSpace 横排三个控件（时长 + 单位 + 处理）

### 3. 内联模式 template

替换 edit-table 中的两个 edit-row：
- 审批人配置行 → 审批人类型行 + 审批人配置行
- 超时规则行 → 超时时长行 + 超时单位行 + 超时处理行

### 4. 样式调整

- 移除 `.json-textarea` / `.json-input` 样式（不再需要）
- 内联模式中超时三控件需横排，edit-cell-value 内用 NSpace 或 flex 布局

## 验证

1. `npx vite build` 构建通过
2. 打开新建流程弹窗，验证：
   - 审批人类型下拉可选，切换类型时 placeholder/禁用状态正确变化
   - 超时时长/单位/处理三个控件正常交互
3. 编辑已有流程定义，验证旧 JSON 数据正确回显
4. 提交后检查数据库 `def_workflow_definition` 表中 `审批人配置` 和 `超时规则` 字段为正确 JSON
