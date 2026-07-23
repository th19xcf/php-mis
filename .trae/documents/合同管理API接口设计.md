# 合同管理 API 接口设计

> 文档编号：MIS-API-CONTRACT-001
> 版本：v1.0
> 编制日期：2026-07-23
> 适用范围：`e:\code\php\mis`（backend: CodeIgniter 4.7.2 / PHP 8.5.4；frontend: Vue 3 + Vite + Soybean Admin）
> 关联文档：[合同管理模块系统性升级实施计划.md](./合同管理模块系统性升级实施计划.md)

---

## 目录

- [1. 引言](#1-引言)
  - [1.1 目的](#11-目的)
  - [1.2 范围](#12-范围)
  - [1.3 设计原则](#13-设计原则)
  - [1.4 参考资料](#14-参考资料)
- [2. 通用约定](#2-通用约定)
  - [2.1 请求规范](#21-请求规范)
  - [2.2 统一响应格式](#22-统一响应格式)
  - [2.3 错误码规范](#23-错误码规范)
  - [2.4 X-Server-Trace 响应头规范](#24-x-server-trace-响应头规范)
  - [2.5 分页约定](#25-分页约定)
  - [2.6 鉴权约定](#26-鉴权约定)
  - [2.7 命名约定](#27-命名约定)
- [3. 工作流引擎 API](#3-工作流引擎-api)
  - [3.1 流程定义管理](#31-流程定义管理)
  - [3.2 流程实例管理](#32-流程实例管理)
  - [3.3 任务处理](#33-任务处理)
  - [3.4 审批意见管理](#34-审批意见管理)
  - [3.5 抄送管理](#35-抄送管理)
  - [3.6 待办查询](#36-待办查询)
- [4. 合同模块 API](#4-合同模块-api)
  - [4.1 合同基础](#41-合同基础)
  - [4.2 合同待办](#42-合同待办)
  - [4.3 合同文档管理](#43-合同文档管理)
- [5. OnlyOffice API](#5-onlyoffice-api)
- [6. 权限审计 API](#6-权限审计-api)
- [7. 请求/响应示例](#7-请求响应示例)
- [8. 数据模型](#8-数据模型)
- [9. 错误码表](#9-错误码表)
- [10. 接口依赖关系](#10-接口依赖关系)
- [11. 接口版本与变更管理](#11-接口版本与变更管理)

---

## 1. 引言

### 1.1 目的

本文档定义合同管理模块系统性升级后端的全部 HTTP 接口契约，作为前后端联调、接口评审、自动化测试用例编写的唯一依据。文档覆盖四大子域：通用工作流引擎、合同模块重构、OnlyOffice 应用层集成、权限审计扩展。

### 1.2 范围

| 子域 | 路由前缀 | 接口数 | 说明 |
|---|---|---|---|
| 工作流引擎 | `/workflow/*`、`/workflow-todo/*` | 21 | 流程定义/实例/任务/意见/抄送/待办 |
| 合同模块 | `/contract/*`、`/contract-doc/*` | 23 | 重写 4 + 保留 8 + 新增 2 + 文档 9 |
| OnlyOffice | `/onlyoffice/*` | 5 | 配置/回调/下载/历史/历史数据 |
| 权限审计 | `/permission-audit/*` | 3 | 列表/详情/导出 |
| **合计** | — | **52** | |

> 注：现有 `GET /contract/flow`（旧流程时间线）将被工作流实例详情接口取代，标记为废弃，详见第 11 章。

### 1.3 设计原则

1. **RESTful 风格**：资源用名词、操作用 HTTP 方法、路径分层级表达从属关系（`workflow/definition/list`、`contract-doc/version/list/{docId}`）。
2. **统一响应封装**：所有接口返回 `{code, msg, data}` 结构，HTTP 状态码恒为 200（业务错误通过 `code` 区分），仅鉴权失败/参数解析失败使用 4xx/5xx。
3. **版本化**：路径前缀隐含版本，废弃接口保留一个发布周期；新增接口遵循 `资源/动作` 命名，避免 `/v2/` 显式版本前缀（与现有 `workbench/*` 风格一致）。
4. **安全**：用户接口强制 JWT 双 Token 鉴权；OnlyOffice 回调独立验签不走用户 JWT；写操作校验按钮级 + 数据级权限。
5. **可观测**：每个响应附带 `X-Request-Id`（traceId，前后端日志串联）；非生产环境或 debug 用户附带 `X-Server-Trace`（分段耗时 + 慢查询）；写操作落审计日志。
6. **字段惯例**：业务字段沿用项目惯例使用中文字段名（如 `合同编号`、`合同状态`），与 `def_user`/`def_function` 等表风格一致；路径与参数键使用 kebab/camelCase 分别约定（见 2.7）。

### 1.4 参考资料

- [合同管理模块系统性升级实施计划.md](./合同管理模块系统性升级实施计划.md)
- CodeIgniter 4.7.2 官方文档（Router / Filters / Controllers）
- OnlyOffice Document Server 集成文档（JWT 签名 / 回调状态机）
- 项目规则：`.trae/rules/project_rules.md`
- 现状代码：`BaseApiController.php`、`ContractApi.php`、`JwtAuthFilter.php`、`Config/Filters.php`、`Config/Routes.php`、`frontend/src/service/request/index.ts`

---

## 2. 通用约定

### 2.1 请求规范

| 项 | 约定 |
|---|---|
| 协议 | HTTPS（生产）/ HTTP（本地开发） |
| 基础路径 | 后端根，前端通过 Vite 代理前缀转发（见 `frontend/.env` 的 `VITE_SERVICE_BASE_URL`） |
| HTTP 方法 | `GET` 查询、`POST` 写入/动作、路径段承载资源 ID（`detail/{id}`） |
| Content-Type | `application/json; charset=UTF-8`（POST/PUT 请求体）；GET 无请求体 |
| Authorization | `Bearer <accessToken>`（JWT 双 Token 中的 access token） |
| X-Request-Id | 可选，前端发起时传入 UUID，后端原样回写；未传则后端生成 `trace-<16hex>` |
| 字符编码 | UTF-8 |
| 时区 | Asia/Shanghai（`date('Y-m-d H:i:s')`） |

### 2.2 统一响应格式

所有接口 HTTP 状态码恒为 `200 OK`，业务结果通过响应体 `code` 字段表达。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": { }
}
```

**失败响应**：

```json
{
  "code": "2003",
  "msg": "当前状态不允许修改",
  "data": null
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| code | string | 业务码，`"0000"` 表示成功，其余为错误码（见第 9 章） |
| msg | string | 中文提示信息，可直接展示给用户 |
| data | any | 业务数据，成功时为对象/数组/分页结构，失败时为 `null` |

**响应头**：

| 响应头 | 说明 |
|---|---|
| `X-Request-Id` | traceId，请求级唯一，前后端日志串联；由后端 `BaseApiController` 注入 |
| `X-Server-Time-Ms` | 服务端总耗时（毫秒），仅 `success()` 传入 `serverElapsedMs > 0` 时输出 |
| `X-Server-Trace` | 性能分段追踪 JSON，见 2.4 |

> 说明：`traceId` 通过 `X-Request-Id` 响应头暴露，不放在响应体内，避免污染业务 `data`。

### 2.3 错误码规范

错误码采用 4 位字符串（与现状 `ApiCode` 一致），分段划分：

| 段 | 范围 | 含义 |
|---|---|---|
| 0000 | `"0000"` | 成功 |
| 1xxx | 1001-1999 | 通用错误（参数/认证/权限/资源/业务） |
| 2xxx | 2001-2999 | 工作流引擎错误 |
| 3xxx | 3001-3999 | 合同模块错误 |
| 4xxx | 4001-4999 | 文档/OnlyOffice 错误 |
| 5xxx | 5000-5999 | 服务器/权限审计错误 |
| 8xxx/9xxx | 8888/9999 | 遗留鉴权码（保留兼容） |

HTTP 状态码与业务码映射：

| HTTP | 触发场景 | 业务码示例 |
|---|---|---|
| 200 | 业务成功/业务失败 | 0000 / 1001 / 2003 |
| 401 | JWT 缺失或失效（`JwtAuthFilter` 直接返回，未走 `success()`） | 8888 / 9999 |
| 500 | 服务端未捕获异常 | — |

> 鉴权失败（401）由 `JwtAuthFilter` 在 Controller 之前拦截，响应体仅含 `{code, msg}`，无 `X-Request-Id`（Filter 阶段尚未生成 traceId）。

### 2.4 X-Server-Trace 响应头规范

`X-Server-Trace` 是后端性能诊断头，由 `BaseApiController::success()` 注入，值为 JSON 字符串。

**输出条件**（安全考虑，避免泄露 SQL 结构）：
- 非生产环境（`ENVIRONMENT !== 'production'`），或
- 生产环境下 `SessionUserContext->isDebugEnabled() === true`（JWT debug 授权用户）

**结构示例**：

```json
{
  "requireLogin": 1.23,
  "contextBuild": 0.85,
  "contextCacheHit": false,
  "loadUserAuthorization": 12.40,
  "queryTotal": 8.71,
  "queryRecords": 15.62,
  "total": 42.31,
  "sqlTrace": [
    { "sql": "U0VMRUNUIENPVU5UKCopIEZST00gZGVmX2NvbnRyYWN0...", "ms": 62.4 }
  ]
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| requireLogin / contextBuild / contextCacheHit | number / boolean | 鉴权与上下文构建耗时 / 缓存命中 |
| loadUserAuthorization / loadFunctionAuthorization | number | 权限数据加载耗时 |
| queryTotal / queryRecords | number | COUNT 统计 / 数据查询耗时 |
| total | number | 服务端总耗时（毫秒） |
| sqlTrace | array | SQL 执行追踪，`sql` 字段为 base64 编码（UTF-8 文本经 base64），仅记录耗时 > 50ms 的慢查询 |

**慢查询过滤**：`Mcommon::getSqlTrace()` 仅返回执行耗时 > 50ms 的 SQL，避免响应头体积膨胀。

**前端解析**：`frontend/src/service/request/index.ts` 在 `import.meta.env.DEV` 下解析该头，对 `sqlTrace[i].sql` 做 base64 解码后输出中文分段耗时日志到浏览器控制台。

### 2.5 分页约定

列表查询接口统一使用以下分页结构：

**请求参数**（GET query 或 POST body）：

| 参数 | 类型 | 必填 | 默认 | 说明 |
|---|---|---|---|---|
| page | int | 否 | 1 | 页码，从 1 开始 |
| pageSize | int | 否 | 20 | 每页条数，建议上限 200 |

**响应 `data` 结构**：

```json
{
  "rows": [ ],
  "total": 156,
  "page": 1,
  "pageSize": 20
}
```

> 现有 `contract/list` 返回 `{list, total, page, pageSize}`，重构后统一为 `rows`（与 `workbench/queryPaged` 惯例对齐）。前端类型定义见第 8 章 `PageResult<T>`。

### 2.6 鉴权约定

**JWT 双 Token 机制**：
- access token：短期，放 `Authorization: Bearer <token>` 头
- refresh token：长期，用于刷新 access token（`POST /auth/refreshToken`）
- `JwtAuthFilter` 校验 access token，黑名单（`TokenBlacklistService`）拦截已登出 token

**需鉴权路由**（在 `Config/Filters.php` 的 `jwt.before` 白名单中）：

| 路由前缀 | 状态 | 说明 |
|---|---|---|
| `workflow/*` | 新增白名单 | 全部需用户 JWT |
| `workflow-todo/*` | 新增白名单 | 全部需用户 JWT |
| `contract/*` | 已在白名单 | 全部需用户 JWT |
| `contract-doc/*` | 新增白名单 | 全部需用户 JWT |
| `onlyoffice/config/*`、`onlyoffice/history/*`、`onlyoffice/history-data/*` | 新增白名单 | 需用户 JWT |
| `onlyoffice/callback` | 排除 | 由 OnlyOffice 调用，走 OnlyOffice JWT 验签（`ONLYOFFICE_JWT_SECRET`），不走用户 JWT |
| `onlyoffice/download` | 排除 | 通过 query 携带签名票据（短期 JWT），不走用户 JWT |
| `permission-audit/*` | 新增白名单 | 需用户 JWT + 权限审计查看权限 |

**OnlyOffice 回调验签**：`OnlyOfficeApi::callback()` 从 `Authorization: Bearer <token>` 头或请求体 `token` 字段读取，用 `OnlyOfficeJwtService::verify()` 校验 HS256 签名，验签失败返回 HTTP 401。

### 2.7 命名约定

| 范畴 | 约定 | 示例 |
|---|---|---|
| URL 路径 | 全小写，多词用 `-` 连接（kebab-case） | `workflow-todo/list`、`contract-doc/version/list`、`permission-audit/list` |
| 路径段资源 ID | `(:segment)`，UUID/GUID/数字均可 | `detail/{guid}`、`detail/{id}` |
| 业务字段名（JSON body / DB 字段） | 中文名（项目惯例，与 `def_user`/`def_contract_master` 一致） | `合同编号`、`合同状态`、`甲方名称` |
| 系统参数键（分页/筛选/排序） | camelCase | `page`、`pageSize`、`startDate`、`endDate`、`orderBy` |
| 枚举值 | 全大写英文常量 | `DRAFT`、`PENDING`、`APPROVED`、`SIGNED`、`ARCHIVED` |
| 时间格式 | `YYYY-MM-DD HH:mm:ss`（日期字段为 `YYYY-MM-DD`） | `2026-07-23 14:30:00` |

---

## 3. 工作流引擎 API

通用鉴权：全部需用户 JWT。控制器：`WorkflowApi`（定义/实例/任务/意见/抄送）+ `WorkflowTodoApi`（待办查询）。

### 3.1 流程定义管理

#### 3.1.1 GET /workflow/definition/list

| 项 | 值 |
|---|---|
| 描述 | 分页查询流程定义列表 |
| 鉴权 | 是 |
| 权限码 | `workflow-design:view` |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page | int | 否 | 页码，默认 1 |
| pageSize | int | 否 | 每页条数，默认 20 |
| 流程编码 | string | 否 | 模糊匹配 |
| 流程名称 | string | 否 | 模糊匹配 |
| 业务类型 | string | 否 | 如 `contract` |
| 状态 | string | 否 | `ENABLED`/`DISABLED` |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "id": 1,
        "流程编码": "CONTRACT_APPROVAL",
        "流程名称": "合同审批流程",
        "版本": 3,
        "业务类型": "contract",
        "状态": "ENABLED",
        "节点数": 5,
        "创建人": "张三",
        "创建时间": "2026-07-23 10:00:00"
      }
    ],
    "total": 1,
    "page": 1,
    "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

#### 3.1.2 GET /workflow/definition/detail/{id}

| 项 | 值 |
|---|---|
| 描述 | 查询流程定义详情（含节点与连线） |
| 鉴权 | 是 |
| 权限码 | `workflow-design:view` |

**路径参数**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| id | int | 是 | 流程定义 ID |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "id": 1,
    "流程编码": "CONTRACT_APPROVAL",
    "流程名称": "合同审批流程",
    "版本": 3,
    "业务类型": "contract",
    "状态": "ENABLED",
    "节点列表": [
      {
        "id": 11,
        "节点编码": "START",
        "节点名称": "开始",
        "节点类型": "START",
        "审批人类型": null,
        "审批人配置": null,
        "会签或签": null,
        "超时规则": null,
        "顺序": 1
      },
      {
        "id": 12,
        "节点编码": "DEPT_APPROVAL",
        "节点名称": "部门审核",
        "节点类型": "APPROVAL",
        "审批人类型": "DEPT",
        "审批人配置": "{\"level\":1}",
        "会签或签": "COUNTERSIGN",
        "超时规则": "{\"hours\":48,\"action\":\"NOTIFY\"}",
        "顺序": 2
      }
    ],
    "连线列表": [
      { "id": 21, "源节点ID": 11, "目标节点ID": 12, "条件表达式": null },
      { "id": 22, "源节点ID": 12, "目标节点ID": 13, "条件表达式": "金额>100000" }
    ]
  }
}
```

**错误码**：2001 流程定义不存在 / 1004 资源不存在。

#### 3.1.3 POST /workflow/definition/save

| 项 | 值 |
|---|---|
| 描述 | 保存流程定义（新增或更新，含节点/连线级联保存，版本自增） |
| 鉴权 | 是 |
| 权限码 | `workflow-design:edit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| id | int | 否 | 为空表示新增，否则更新并版本+1 |
| 流程编码 | string | 是 | 唯一 |
| 流程名称 | string | 是 | |
| 业务类型 | string | 是 | `contract` 等 |
| 节点列表 | array | 是 | 见数据模型 `WorkflowNode` |
| 连线列表 | array | 是 | 见数据模型 `WorkflowEdge` |

**成功响应**：

```json
{ "code": "0000", "msg": "保存成功", "data": { "id": 1, "版本": 4 } }
```

**错误码**：1001 参数错误（节点列表为空）/ 1003 无权限 / 2008 审批人指派为空 / 5000 服务器错误。

#### 3.1.4 POST /workflow/definition/toggle/{id}

| 项 | 值 |
|---|---|
| 描述 | 启用/停用流程定义（停用后不可发起新实例，已有实例不受影响） |
| 鉴权 | 是 |
| 权限码 | `workflow-design:edit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 状态 | string | 是 | `ENABLED`/`DISABLED` |

**成功响应**：`{ "code": "0000", "msg": "操作成功", "data": null }`

**错误码**：2001 流程定义不存在 / 2002 流程已停用（重复停用）。

---

### 3.2 流程实例管理

#### 3.2.1 GET /workflow/instance/list

| 项 | 值 |
|---|---|
| 描述 | 分页查询流程实例列表 |
| 鉴权 | 是 |
| 权限码 | `workflow-instance:view` |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page / pageSize | int | 否 | 分页 |
| 流程定义ID | int | 否 | |
| 业务类型 | string | 否 | |
| 业务ID | string | 否 | 合同 GUID 等 |
| 状态 | string | 否 | `RUNNING`/`COMPLETED`/`TERMINATED`/`SUSPENDED`/`EXCEPTION` |
| 发起人 | string | 否 | 工号 |
| 开始时间起 / 开始时间止 | string | 否 | 发起时间范围 |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "id": 1001,
        "流程定义ID": 1,
        "流程名称": "合同审批流程",
        "版本": 3,
        "业务类型": "contract",
        "业务ID": "abc-123-guid",
        "状态": "RUNNING",
        "发起人": "张三",
        "发起人工号": "EMP001",
        "当前节点": "DEPT_APPROVAL",
        "发起时间": "2026-07-23 09:00:00",
        "结束时间": null
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

#### 3.2.2 GET /workflow/instance/detail/{id}

| 项 | 值 |
|---|---|
| 描述 | 查询流程实例详情（含节点流转图、任务列表、意见列表） |
| 鉴权 | 是 |
| 权限码 | `workflow-instance:view` |

**路径参数**：`id` int，实例 ID。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "id": 1001,
    "流程定义ID": 1,
    "业务类型": "contract",
    "业务ID": "abc-123-guid",
    "状态": "RUNNING",
    "发起人": "张三",
    "发起时间": "2026-07-23 09:00:00",
    "当前节点": "DEPT_APPROVAL",
    "节点流转": [
      { "节点编码": "START", "节点名称": "开始", "状态": "COMPLETED", "处理人": "张三", "处理时间": "2026-07-23 09:00:05" },
      { "节点编码": "DEPT_APPROVAL", "节点名称": "部门审核", "状态": "RUNNING", "处理人": "李四", "处理时间": null }
    ],
    "任务列表": [ ],
    "意见列表": [ ]
  }
}
```

**错误码**：2003 实例不存在 / 1004 资源不存在。

#### 3.2.3 POST /workflow/instance/start

| 项 | 值 |
|---|---|
| 描述 | 发起流程实例（合同模块通过 `ContractWorkflowService` 内部调用，亦可直接调用） |
| 鉴权 | 是 |
| 权限码 | `workflow-instance:add` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 流程定义ID | int | 是 | |
| 业务类型 | string | 是 | `contract` |
| 业务ID | string | 是 | 合同 GUID |
| 业务变量 | object | 否 | 条件表达式求值用，如 `{ "金额": 200000 }` |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "流程已发起",
  "data": { "实例ID": 1001, "当前节点": "DEPT_APPROVAL" }
}
```

**错误码**：2001 流程定义不存在 / 2002 流程已停用 / 2008 审批人指派为空（实例标记 EXCEPTION）/ 3003 工作流实例创建失败 / 5000 服务器错误。

#### 3.2.4 POST /workflow/instance/terminate/{id}

| 项 | 值 |
|---|---|
| 描述 | 终止流程实例（仅发起人或管理员可操作，未完成任务自动标记"已跳过"） |
| 鉴权 | 是 |
| 权限码 | `workflow-instance:edit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 终止原因 | string | 是 | |

**成功响应**：`{ "code": "0000", "msg": "流程已终止", "data": null }`

**错误码**：2003 实例不存在 / 1003 无权限（非发起人）/ 2007 流程已结束（不可终止）。

---

### 3.3 任务处理

#### 3.3.1 POST /workflow/task/approve

| 项 | 值 |
|---|---|
| 描述 | 同意审批任务，推进至下一节点（会签需全部同意，或签任一同意即推进） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:approve` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 任务ID | int | 是 | |
| 审批意见 | string | 是 | |
| 附件 | array | 否 | 附件 URL 列表 |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "审批通过",
  "data": {
    "任务ID": 5001,
    "下一节点": "FINANCE_APPROVAL",
    "实例状态": "RUNNING"
  }
}
```

**错误码**：2004 任务不存在 / 2005 无审批权限（当前用户非任务处理人）/ 2006 任务已处理 / 2007 流程已结束 / 2008 审批人指派为空 / 2009 转签深度超限。

#### 3.3.2 POST /workflow/task/reject

| 项 | 值 |
|---|---|
| 描述 | 拒绝审批任务，流程实例终止，回调更新业务状态（合同 → REJECTED） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:approve` |

**请求体**：同 `task/approve`。

**成功响应**：

```json
{ "code": "0000", "msg": "已拒绝", "data": { "任务ID": 5001, "实例状态": "TERMINATED" } }
```

**错误码**：2004/2005/2006/2007。

#### 3.3.3 POST /workflow/task/transfer

| 项 | 值 |
|---|---|
| 描述 | 转签（将当前任务转给他人处理，原处理人退出，新处理人成为待办） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:approve` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 任务ID | int | 是 | |
| 转签人工号 | string | 是 | 新处理人工号 |
| 转签原因 | string | 是 | |

**成功响应**：

```json
{ "code": "0000", "msg": "转签成功", "data": { "任务ID": 5001, "新处理人": "王五" } }
```

**错误码**：2004/2005/2006/2009 转签深度超限（默认 ≤ 3 层）/ 1004 目标用户不存在。

#### 3.3.4 POST /workflow/task/countersign

| 项 | 值 |
|---|---|
| 描述 | 加签（在当前节点追加审批人，会签需等待新审批人完成） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:approve` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 任务ID | int | 是 | |
| 加签人工号列表 | array | 是 | 一个或多个工号 |
| 加签类型 | string | 是 | `BEFORE`（前加签）/ `AFTER`（后加签） |
| 加签原因 | string | 是 | |

**成功响应**：

```json
{ "code": "0000", "msg": "加签成功", "data": { "新增任务数": 2 } }
```

**错误码**：2004/2005/2006/2009 加签层级超限 / 1004 目标用户不存在。

#### 3.3.5 POST /workflow/task/withdraw/{id}

| 项 | 值 |
|---|---|
| 描述 | 撤回任务（仅发起人可撤回，且仅当下一节点任务尚未处理时） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:approve` |

**路径参数**：`id` int，任务 ID。

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 撤回原因 | string | 是 | |

**成功响应**：

```json
{ "code": "0000", "msg": "撤回成功", "data": { "回退至节点": "DEPT_APPROVAL" } }
```

**错误码**：2004 任务不存在 / 1003 无权限（非发起人）/ 2006 任务已处理（下一节点已处理，不可撤回）/ 2007 流程已结束。

---

### 3.4 审批意见管理

#### 3.4.1 GET /workflow/task/opinion/{id}

| 项 | 值 |
|---|---|
| 描述 | 查询任务的审批意见列表（按时间倒序） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:view` |

**路径参数**：`id` int，任务 ID。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": [
    {
      "意见ID": 8001,
      "任务ID": 5001,
      "合同ID": "abc-123-guid",
      "意见内容": "金额核实无误，同意",
      "意见类型": "AGREE",
      "是否最终": true,
      "操作人": "李四",
      "操作人工号": "EMP002",
      "操作时间": "2026-07-23 10:30:00",
      "附件": []
    }
  ]
}
```

**错误码**：2004 任务不存在。

#### 3.4.2 POST /workflow/task/opinion/edit

| 项 | 值 |
|---|---|
| 描述 | 编辑审批意见（仅意见提交人本人可编辑，且任务所在流程未结束时） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:edit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 意见ID | int | 是 | |
| 意见内容 | string | 是 | 修改后内容 |
| 修改原因 | string | 否 | |

**成功响应**：`{ "code": "0000", "msg": "修改成功", "data": null }`

**错误码**：1004 意见不存在 / 1003 无权限（非本人）/ 2007 流程已结束（不可修改）。

#### 3.4.3 GET /workflow/task/opinion/export

| 项 | 值 |
|---|---|
| 描述 | 导出审批意见 Excel（复用 Workbench ExportService 模式，异步任务） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:export` |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 实例ID | int | 否 | 按实例导出 |
| 业务类型 | string | 否 | 如 `contract` |
| 开始时间起 / 开始时间止 | string | 否 | 操作时间范围 |

**成功响应**（返回异步导出任务）：

```json
{ "code": "0000", "msg": "导出任务已创建", "data": { "任务ID": "export-abc", "状态查询路径": "/workbench/export-status/export-abc" } }
```

**错误码**：1001 参数错误（未指定任何范围）/ 1003 无权限。

---

### 3.5 抄送管理

#### 3.5.1 POST /workflow/cc/read/{id}

| 项 | 值 |
|---|---|
| 描述 | 标记抄送记录为已读 |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:view` |

**路径参数**：`id` int，抄送记录 ID。

**成功响应**：`{ "code": "0000", "msg": "已标记已读", "data": null }`

**错误码**：1004 抄送记录不存在 / 1003 无权限（非抄送人）。

---

### 3.6 待办查询

控制器 `WorkflowTodoApi`，全部 GET，全部需用户 JWT。

#### 3.6.1 GET /workflow-todo/list

| 项 | 值 |
|---|---|
| 描述 | 我的待办任务列表（当前用户为处理人且任务状态为待办） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:view` |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page / pageSize | int | 否 | 分页 |
| 业务类型 | string | 否 | 如 `contract` |
| 流程名称 | string | 否 | 模糊匹配 |
| 发起人 | string | 否 | |
| 发起时间起 / 发起时间止 | string | 否 | |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "任务ID": 5001,
        "实例ID": 1001,
        "流程名称": "合同审批流程",
        "业务类型": "contract",
        "业务ID": "abc-123-guid",
        "业务摘要": "HT202607230001-采购合同",
        "节点名称": "部门审核",
        "发起人": "张三",
        "发起时间": "2026-07-23 09:00:00",
        "到达时间": "2026-07-23 09:00:10",
        "任务类型": "APPROVAL"
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

#### 3.6.2 GET /workflow-todo/done

| 项 | 值 |
|---|---|
| 描述 | 我的已办任务列表（当前用户已处理的任务） |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:view` |

**请求参数**：同 3.6.1，额外支持 `处理时间起 / 处理时间止`。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "任务ID": 4999,
        "实例ID": 1000,
        "业务摘要": "HT202607220008-服务合同",
        "节点名称": "部门审核",
        "处理动作": "AGREE",
        "处理意见": "同意",
        "处理时间": "2026-07-22 16:00:00"
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

#### 3.6.3 GET /workflow-todo/cc

| 项 | 值 |
|---|---|
| 描述 | 我的抄送列表 |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:view` |

**请求参数**：同 3.6.1，额外支持 `已读状态`（`READ`/`UNREAD`）。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "抄送ID": 7001,
        "实例ID": 1001,
        "业务摘要": "HT202607230001-采购合同",
        "节点名称": "财务审核",
        "抄送人": "张三",
        "抄送时间": "2026-07-23 11:00:00",
        "已读": false
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

#### 3.6.4 GET /workflow-todo/initiated

| 项 | 值 |
|---|---|
| 描述 | 我发起的流程实例列表 |
| 鉴权 | 是 |
| 权限码 | `workflow-todo:view` |

**请求参数**：同 3.6.1。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "实例ID": 1001,
        "流程名称": "合同审批流程",
        "业务类型": "contract",
        "业务ID": "abc-123-guid",
        "业务摘要": "HT202607230001-采购合同",
        "状态": "RUNNING",
        "当前节点": "部门审核",
        "当前处理人": "李四",
        "发起时间": "2026-07-23 09:00:00"
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

---

## 4. 合同模块 API

通用鉴权：全部需用户 JWT。控制器：`ContractApi`（基础+待办）+ `ContractDocApi`（文档）。

### 4.1 合同基础

#### 4.1.1 GET /contract/list（重写）

| 项 | 值 |
|---|---|
| 描述 | 分页查询合同列表，支持条件筛选（补全现有未生效的筛选） |
| 鉴权 | 是 |
| 权限码 | `contract:view` |
| 数据权限 | 注入属地/部门/工号过滤（`AuthorizationService`） |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page / pageSize | int | 否 | 分页 |
| 合同编号 | string | 否 | 模糊匹配 |
| 合同名称 | string | 否 | 模糊匹配 |
| 合同状态 | string | 否 | 枚举，多值逗号分隔 |
| 合同类型 | string | 否 | |
| 甲方名称 | string | 否 | 模糊匹配 |
| 乙方名称 | string | 否 | 模糊匹配 |
| 金额下限 / 金额上限 | number | 否 | 金额范围 |
| 签订日期起 / 签订日期止 | string | 否 | 日期范围 |
| 到期起 / 到期止 | string | 否 | 结束日期范围 |
| orderBy | string | 否 | 如 `开始操作时间 desc` |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "GUID": "abc-123-guid",
        "合同编号": "HT202607230001",
        "合同名称": "采购合同",
        "合同类型": "采购",
        "合同金额": 200000,
        "甲方名称": "甲公司",
        "乙方名称": "乙公司",
        "签订日期": "2026-07-23",
        "开始日期": "2026-07-23",
        "结束日期": "2027-07-22",
        "合同状态": "PENDING",
        "工作流实例ID": 1001,
        "当前流程节点": "DEPT_APPROVAL",
        "操作人员": "张三",
        "开始操作时间": "2026-07-23 09:00:00"
      }
    ],
    "total": 156, "page": 1, "pageSize": 20
  }
}
```

**X-Server-Trace 分段**：`buildContext` / `queryTotal` / `queryRecords` / `total`。

**错误码**：1001 参数错误（金额下限 > 上限）/ 5000 服务器错误。

> 兼容说明：现有接口返回 `{list, total, page, pageSize}`，重构后改为 `{rows, total, page, pageSize}`。前端 `fetchContractList` 同步更新取值字段。

#### 4.1.2 POST /contract/submit（重写）

| 项 | 值 |
|---|---|
| 描述 | 提交合同审批，启动工作流实例（`ContractWorkflowService::submit` → `WorkflowInstanceService::start`） |
| 鉴权 | 是 |
| 权限码 | `contract:submit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| GUID | string | 是 | 合同 GUID |
| 流程定义ID | int | 否 | 指定流程，为空则取业务类型 `contract` 的启用流程 |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "提交审核成功",
  "data": {
    "合同状态": "PENDING",
    "工作流实例ID": 1001,
    "当前流程节点": "DEPT_APPROVAL"
  }
}
```

**错误码**：3001 合同不存在 / 3002 合同状态不允许此操作（非 DRAFT/REJECTED）/ 3003 工作流实例创建失败 / 2001 流程定义不存在 / 2002 流程已停用。

#### 4.1.3 POST /contract/approve（重写）

| 项 | 值 |
|---|---|
| 描述 | 合同审批通过，委托 `WorkflowTaskService::taskApprove`，工作流回调更新合同状态 |
| 鉴权 | 是 |
| 权限码 | `contract:approve` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| GUID | string | 是 | |
| 任务ID | int | 是 | 当前待办任务 ID |
| 审核意见 | string | 是 | |
| 附件 | array | 否 | |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "审批通过",
  "data": {
    "合同状态": "APPROVING",
    "下一节点": "FINANCE_APPROVAL",
    "实例状态": "RUNNING"
  }
}
```

**错误码**：3001 合同不存在 / 2004 任务不存在 / 2005 无审批权限 / 2006 任务已处理 / 2007 流程已结束 / 2008 审批人指派为空。

#### 4.1.4 POST /contract/reject（重写）

| 项 | 值 |
|---|---|
| 描述 | 合同审批拒绝，委托 `WorkflowTaskService::taskReject`，流程终止回调合同状态 → REJECTED |
| 鉴权 | 是 |
| 权限码 | `contract:approve` |

**请求体**：同 4.1.3。

**成功响应**：

```json
{ "code": "0000", "msg": "已拒绝", "data": { "合同状态": "REJECTED", "实例状态": "TERMINATED" } }
```

**错误码**：3001/2004/2005/2006/2007。

#### 4.1.5 GET /contract/detail/{guid}（保留）

| 项 | 值 |
|---|---|
| 描述 | 查询合同详情 |
| 鉴权 | 是 |
| 权限码 | `contract:view` |

**路径参数**：`guid` string。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "GUID": "abc-123-guid",
    "合同编号": "HT202607230001",
    "合同名称": "采购合同",
    "合同类型": "采购",
    "合同金额": 200000,
    "甲方名称": "甲公司", "甲方联系人": "王经理", "甲方电话": "13800000000",
    "乙方名称": "乙公司", "乙方联系人": "李经理", "乙方电话": "13900000000",
    "签订日期": "2026-07-23", "开始日期": "2026-07-23", "结束日期": "2027-07-22",
    "付款方式": "INSTALLMENT", "付款节点": "",
    "合同状态": "PENDING",
    "工作流实例ID": 1001,
    "当前流程节点": "DEPT_APPROVAL",
    "版本号": 1,
    "备注": ""
  }
}
```

**错误码**：3001 合同不存在 / 1004 资源不存在。

#### 4.1.6 POST /contract/create（保留）

| 项 | 值 |
|---|---|
| 描述 | 新建合同（状态 DRAFT） |
| 鉴权 | 是 |
| 权限码 | `contract:add` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 合同名称 | string | 是 | |
| 甲方名称 | string | 是 | |
| 乙方名称 | string | 是 | |
| 合同类型 | string | 否 | |
| 合同金额 | number | 否 | 默认 0 |
| 甲方联系人 / 甲方电话 | string | 否 | |
| 乙方联系人 / 乙方电话 | string | 否 | |
| 签订日期 / 开始日期 / 结束日期 | string | 否 | |
| 付款方式 / 付款节点 / 备注 | string | 否 | |

**成功响应**：

```json
{ "code": "0000", "msg": "创建合同成功", "data": { "GUID": "abc-123-guid", "合同编号": "HT202607230001" } }
```

**错误码**：1001 参数错误（必填缺失）/ 5000 服务器错误。

#### 4.1.7 POST /contract/update（保留）

| 项 | 值 |
|---|---|
| 描述 | 修改合同（仅 DRAFT/REJECTED 状态可改） |
| 鉴权 | 是 |
| 权限码 | `contract:edit` |

**请求体**：`GUID`（必填）+ 可更新字段（同 create 可选项）。

**成功响应**：`{ "code": "0000", "msg": "更新合同成功", "data": null }`

**错误码**：3001 合同不存在 / 3002 合同状态不允许此操作 / 1001 参数错误。

#### 4.1.8 POST /contract/delete（保留）

| 项 | 值 |
|---|---|
| 描述 | 逻辑删除合同（仅 DRAFT/REJECTED，置删除标识） |
| 鉴权 | 是 |
| 权限码 | `contract:delete` |

**请求体**：`{ "GUID": "abc-123-guid" }`

**成功响应**：`{ "code": "0000", "msg": "删除合同成功", "data": null }`

**错误码**：3001 合同不存在 / 3002 合同状态不允许此操作。

#### 4.1.9 POST /contract/sign（保留）

| 项 | 值 |
|---|---|
| 描述 | 合同签署（APPROVED/SIGNING → SIGNED，写签署记录） |
| 鉴权 | 是 |
| 权限码 | `contract:sign` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| GUID | string | 是 | |
| 签署公司 | string | 否 | |

**成功响应**：`{ "code": "0000", "msg": "签署成功", "data": { "合同状态": "SIGNED" } }`

**错误码**：3001 合同不存在 / 3002 合同状态不允许此操作。

#### 4.1.10 POST /contract/archive（保留）

| 项 | 值 |
|---|---|
| 描述 | 合同归档（SIGNED → ARCHIVED） |
| 鉴权 | 是 |
| 权限码 | `contract:archive` |

**请求体**：`{ "GUID": "abc-123-guid" }`

**成功响应**：`{ "code": "0000", "msg": "归档成功", "data": { "合同状态": "ARCHIVED" } }`

**错误码**：3001 合同不存在 / 3002 合同状态不允许此操作。

#### 4.1.11 GET /contract/options（保留）

| 项 | 值 |
|---|---|
| 描述 | 获取下拉选项（合同类型/状态/付款方式） |
| 鉴权 | 是 |
| 权限码 | `contract:view` |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "合同类型": [ { "value": "采购", "label": "采购" } ],
    "合同状态": [
      { "value": "DRAFT", "label": "草稿" },
      { "value": "PENDING", "label": "待审核" }
    ],
    "付款方式": [ { "value": "FULL", "label": "一次性付款" } ]
  }
}
```

**错误码**：通用 5000。

#### 4.1.12 GET /contract/stats（保留）

| 项 | 值 |
|---|---|
| 描述 | 合同统计（总数/待审核/已审核/已签署/即将到期） |
| 鉴权 | 是 |
| 权限码 | `contract:view` |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": { "总数": 156, "待审核": 8, "已审核": 20, "已签署": 100, "即将到期": 5 }
}
```

**错误码**：通用 5000。

> 废弃接口：`GET /contract/flow`（旧流程时间线）由 `GET /workflow/instance/detail/{id}`（按合同业务ID查询实例）取代，保留一个发布周期，详见第 11 章。

---

### 4.2 合同待办

#### 4.2.1 GET /contract/todo

| 项 | 值 |
|---|---|
| 描述 | 我的合同待办（`workflow-todo/list` 的合同专用视图，业务类型固定为 contract） |
| 鉴权 | 是 |
| 权限码 | `contract:view` |

**请求参数**：同 3.6.1（不含业务类型）。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "任务ID": 5001,
        "GUID": "abc-123-guid",
        "合同编号": "HT202607230001",
        "合同名称": "采购合同",
        "合同金额": 200000,
        "节点名称": "部门审核",
        "发起人": "张三",
        "到达时间": "2026-07-23 09:00:10"
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

#### 4.2.2 GET /contract/initiated

| 项 | 值 |
|---|---|
| 描述 | 我发起的合同列表（`workflow-todo/initiated` 的合同专用视图） |
| 鉴权 | 是 |
| 权限码 | `contract:view` |

**请求参数**：同 3.6.1（不含业务类型）。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "实例ID": 1001,
        "GUID": "abc-123-guid",
        "合同编号": "HT202607230001",
        "合同名称": "采购合同",
        "状态": "RUNNING",
        "当前节点": "部门审核",
        "当前处理人": "李四",
        "发起时间": "2026-07-23 09:00:00"
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

---

### 4.3 合同文档管理

控制器 `ContractDocApi`，全部需用户 JWT。文档与合同为 1:N 关系。

#### 4.3.1 GET /contract-doc/list/{contractId}

| 项 | 值 |
|---|---|
| 描述 | 查询合同下的文档列表 |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view` |

**路径参数**：`contractId` string，合同 GUID。

**请求参数**（query）：`page / pageSize / 文档名称`。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "文档ID": 2001,
        "合同ID": "abc-123-guid",
        "文档名称": "采购合同正文.docx",
        "文档Key": "doc-abc-123-v3",
        "文件类型": "docx",
        "当前版本": 3,
        "编辑模式": "EDIT",
        "创建人": "张三",
        "创建时间": "2026-07-23 09:30:00",
        "最后修改时间": "2026-07-23 14:00:00"
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：3001 合同不存在 / 1004 资源不存在。

#### 4.3.2 POST /contract-doc/create

| 项 | 值 |
|---|---|
| 描述 | 创建合同文档（关联 OnlyOffice 文件 Key） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:add` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 合同ID | string | 是 | 合同 GUID |
| 文档名称 | string | 是 | |
| 文档类型 | string | 是 | `docx`/`xlsx`/`pptx` |
| 文件Key | string | 是 | OnlyOffice 文件 Key（首次由前端生成或上传得到） |
| 编辑模式 | string | 否 | `EDIT`/`VIEW`，默认 `EDIT` |

**成功响应**：

```json
{ "code": "0000", "msg": "创建成功", "data": { "文档ID": 2001 } }
```

**错误码**：1001 参数错误 / 3001 合同不存在 / 5000 服务器错误。

#### 4.3.3 GET /contract-doc/config/{docId}

| 项 | 值 |
|---|---|
| 描述 | 获取 OnlyOffice 编辑器配置（含 JWT 签名，供前端 `DocsAPI.DocEditor` 直接使用） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view`（编辑模式需 `contract-doc:edit`） |

**路径参数**：`docId` int，文档 ID。

**请求参数**（query）：`mode`（`edit`/`view`，默认按权限决定）。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "config": {
      "documentType": "word",
      "document": {
        "title": "采购合同正文.docx",
        "url": "http://mis-backend.example.com/onlyoffice/download?docId=2001&token=xxx",
        "key": "doc-abc-123-v3",
        "permissions": { "edit": true, "download": true, "print": false, "comment": true, "review": true }
      },
      "editorConfig": {
        "mode": "edit",
        "lang": "zh-CN",
        "callbackUrl": "http://mis-backend.example.com/onlyoffice/callback",
        "user": { "id": "EMP001", "name": "张三" },
        "customization": { "forcesave": true, "autosave": true }
      },
      "token": "<OnlyOffice JWT, HS256>"
    },
    "权限": { "view": true, "edit": true, "download": true, "print": false }
  }
}
```

**错误码**：4001 文档不存在 / 4002 权限不足 / 5000 服务器错误。

> 备注：`token` 字段为 OnlyOffice JWT，由 `OnlyOfficeJwtService::sign()` 用 `ONLYOFFICE_JWT_SECRET` 签名；`permissions` 根据用户操作级权限构建。

#### 4.3.4 GET /contract-doc/version/list/{docId}

| 项 | 值 |
|---|---|
| 描述 | 查询文档版本列表（按版本号倒序） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view` |

**路径参数**：`docId` int。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": [
    {
      "版本ID": 3003,
      "文档ID": 2001,
      "版本号": 3,
      "文件Key": "doc-abc-123-v3",
      "创建人": "张三",
      "创建时间": "2026-07-23 14:00:00",
      "版本说明": "补充违约条款",
      "是否标记": true,
      "版本大小": 102400
    },
    { "版本ID": 3002, "版本号": 2, "是否标记": false }
  ]
}
```

**错误码**：4001 文档不存在 / 1004 资源不存在。

#### 4.3.5 POST /contract-doc/version/mark

| 项 | 值 |
|---|---|
| 描述 | 标记/取消标记版本（标记版本用于关键节点快照） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:edit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 版本ID | int | 是 | |
| 是否标记 | bool | 是 | |

**成功响应**：`{ "code": "0000", "msg": "标记成功", "data": null }`

**错误码**：4004 版本不存在 / 4002 权限不足。

#### 4.3.6 POST /contract-doc/version/rollback

| 项 | 值 |
|---|---|
| 描述 | 回溯至指定版本（创建新版本，内容回退至历史版本） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:edit` |

**请求体**：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 文档ID | int | 是 | |
| 目标版本ID | int | 是 | 回退到的历史版本 |
| 回溯原因 | string | 是 | |

**成功响应**：

```json
{ "code": "0000", "msg": "回溯成功", "data": { "新版本号": 4, "文件Key": "doc-abc-123-v4" } }
```

**错误码**：4001 文档不存在 / 4004 版本不存在 / 4005 版本回溯失败 / 4002 权限不足。

#### 4.3.7 GET /contract-doc/version/compare

| 项 | 值 |
|---|---|
| 描述 | 版本对比（返回供 OnlyOffice history 组件对比的元数据） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view` |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| 文档ID | int | 是 | |
| 源版本ID | int | 是 | |
| 目标版本ID | int | 是 | |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "源版本": { "版本号": 2, "文件Key": "doc-abc-123-v2", "创建时间": "2026-07-23 11:00:00" },
    "目标版本": { "版本号": 3, "文件Key": "doc-abc-123-v3", "创建时间": "2026-07-23 14:00:00" },
    "对比地址": "http://mis-backend.example.com/onlyoffice/history/2001"
  }
}
```

**错误码**：4001 文档不存在 / 4004 版本不存在 / 1001 参数错误（源=目标）。

#### 4.3.8 GET /contract-doc/oplog/{docId}

| 项 | 值 |
|---|---|
| 描述 | 查询文档操作留痕（打开/编辑/保存/下载/版本创建/版本回溯） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view` |

**路径参数**：`docId` int。

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page / pageSize | int | 否 | 分页 |
| 操作类型 | string | 否 | `OPEN`/`EDIT`/`SAVE`/`DOWNLOAD`/`VERSION_CREATE`/`VERSION_ROLLBACK` |
| 操作人 | string | 否 | 工号 |
| 开始时间起 / 开始时间止 | string | 否 | 操作时间范围 |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "日志ID": 9001,
        "文档ID": 2001,
        "操作类型": "SAVE",
        "操作人": "张三",
        "操作人工号": "EMP001",
        "操作时间": "2026-07-23 14:00:00",
        "操作详情": "forceSave 触发版本创建 v3",
        "IP": "192.168.1.10",
        "设备": "Mozilla/5.0..."
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：4001 文档不存在 / 1004 资源不存在。

#### 4.3.9 GET /contract-doc/oplog/export

| 项 | 值 |
|---|---|
| 描述 | 导出文档操作留痕 Excel（异步任务） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:export` |

**请求参数**（query）：同 4.3.8 筛选条件。

**成功响应**：

```json
{ "code": "0000", "msg": "导出任务已创建", "data": { "任务ID": "export-oplog-xxx" } }
```

**错误码**：1001 参数错误（未指定文档 ID）/ 1003 无权限。

---

## 5. OnlyOffice API

控制器 `OnlyOfficeApi`。

### 5.1 GET /onlyoffice/config/{docId}

| 项 | 值 |
|---|---|
| 描述 | 获取编辑器配置（与 `contract-doc/config/{docId}` 业务封装，本接口为通用入口） |
| 鉴权 | 是（用户 JWT） |
| 权限码 | 按业务模块（合同文档需 `contract-doc:view`） |

**路径参数**：`docId` int。

**请求参数**（query）：`mode`（`edit`/`view`）。

**成功响应**：见 4.3.3 的 `data.config` 结构（含 `token` JWT 字段）。

**错误码**：4001 文档不存在 / 4002 权限不足 / 5000 服务器错误。

> 备注：配置由 `OnlyOfficeConfigBuilder` 构建，`permissions` 根据用户操作级权限动态生成；`callbackUrl` = `ONLYOFFICE_CALLBACK_BASE_URL` + `/onlyoffice/callback`。

### 5.2 POST /onlyoffice/callback

| 项 | 值 |
|---|---|
| 描述 | OnlyOffice Document Server 回调（保存状态通知），独立验签，不走用户 JWT |
| 鉴权 | 否（用户 JWT），是（OnlyOffice JWT 验签） |

**请求体**（OnlyOffice 标准）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| status | int | 是 | 0=未保存/1=编辑中/2=准备保存/3=保存出错/4=无变化关闭/6=强制保存/7=强制保存出错 |
| key | string | 是 | 文档 Key |
| url | string | 否 | status=2/6 时文档下载地址 |
| users | array | 否 | 协同编辑用户列表 |
| token | string | 否 | OnlyOffice JWT（也可放 Authorization 头） |

**成功响应**（HTTP 200，固定 body）：

```json
{ "error": 0 }
```

**错误码**：4003 回调验签失败（HTTP 401，body `{ "error": 1 }`）/ 5000 服务器错误（`{ "error": 1 }`）。

**处理逻辑**：
- status=2 或 6：从 `url` 下载文档 → 存储到 `ONLYOFFICE_DOC_STORAGE_PATH` → 创建 `def_contract_doc_version` 记录 → 写 `def_contract_doc_op_log` → 更新 `def_contract_doc.当前版本`
- status=4：协同编辑关闭，无版本变化
- 全程写入 `def_doc_callback_log`（载荷/处理结果/耗时）

### 5.3 GET /onlyoffice/download

| 项 | 值 |
|---|---|
| 描述 | 文档下载（OnlyOffice Document Server 拉取文件用，或用户手动下载） |
| 鉴权 | 否（用户 JWT），是（query 携带短期签名票据） |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| docId | int | 是 | |
| versionId | int | 否 | 为空下载当前版本 |
| token | string | 是 | 短期 JWT 票据（由 `config` 接口签发，含 docId 与下载权限） |

**成功响应**：二进制文件流（`Content-Type: application/octet-stream` 或对应 MIME）。

**错误码**：4001 文档不存在 / 4002 权限不足（票据无效或过期）/ 4004 版本不存在。

> 备注：Document Server 通过 `config.document.url` 携带 token 拉取，应用层校验票据签名 + 有效期。

### 5.4 GET /onlyoffice/history/{docId}

| 项 | 值 |
|---|---|
| 描述 | 获取文档版本历史（供 OnlyOffice 编辑器 history 组件渲染） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view` |

**路径参数**：`docId` int。

**成功响应**（OnlyOffice history 标准结构）：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "currentVersion": 3,
    "history": [
      {
        "version": 1,
        "key": "doc-abc-123-v1",
        "created": "2026-07-23 09:30:00",
        "user": { "id": "EMP001", "name": "张三" },
        "changes": "changes-xxx.json"
      },
      { "version": 2, "key": "doc-abc-123-v2" },
      { "version": 3, "key": "doc-abc-123-v3" }
    ]
  }
}
```

**错误码**：4001 文档不存在 / 1004 资源不存在。

### 5.5 GET /onlyoffice/history-data/{versionId}

| 项 | 值 |
|---|---|
| 描述 | 获取指定版本的数据（供编辑器 history 组件加载版本对比内容） |
| 鉴权 | 是 |
| 权限码 | `contract-doc:view` |

**路径参数**：`versionId` int。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "version": 2,
    "key": "doc-abc-123-v2",
    "url": "http://mis-backend.example.com/onlyoffice/download?docId=2001&versionId=3002&token=xxx",
    "changesUrl": "changes-xxx.json",
    "previous": { "key": "doc-abc-123-v1", "url": "http://.../download?docId=2001&versionId=3001&token=yyy" }
  }
}
```

**错误码**：4004 版本不存在 / 4002 权限不足。

---

## 6. 权限审计 API

控制器 `PermissionAuditApi`，全部需用户 JWT + `permission-audit:view` 权限码。

### 6.1 GET /permission-audit/list

| 项 | 值 |
|---|---|
| 描述 | 分页查询权限变更审计记录 |
| 鉴权 | 是 |
| 权限码 | `permission-audit:view` |

**请求参数**（query）：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page / pageSize | int | 否 | 分页 |
| 变更类型 | string | 否 | `ROLE_ASSIGN`/`USER_ASSIGN`/`MENU_ASSIGN`/`WORKFLOW_NODE_ASSIGN` |
| 操作人 | string | 否 | 工号 |
| 目标对象 | string | 否 | 如角色编码/用户工号/菜单编码 |
| 开始时间起 / 开始时间止 | string | 否 | 操作时间范围 |

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "rows": [
      {
        "审计ID": 6001,
        "变更类型": "ROLE_ASSIGN",
        "操作人": "管理员",
        "操作人工号": "ADMIN",
        "操作时间": "2026-07-23 15:00:00",
        "目标对象": "ROLE_FINANCE",
        "目标名称": "财务角色",
        "变更摘要": "新增合同审批权限",
        "变更明细数": 3
      }
    ],
    "total": 1, "page": 1, "pageSize": 20
  }
}
```

**错误码**：通用 1001/5000。

### 6.2 GET /permission-audit/detail/{id}

| 项 | 值 |
|---|---|
| 描述 | 查询审计详情（含变更前后 JSON） |
| 鉴权 | 是 |
| 权限码 | `permission-audit:view` |

**路径参数**：`id` int，审计 ID。

**成功响应**：

```json
{
  "code": "0000",
  "msg": "Success",
  "data": {
    "审计ID": 6001,
    "变更类型": "ROLE_ASSIGN",
    "操作人": "管理员",
    "操作时间": "2026-07-23 15:00:00",
    "目标对象": "ROLE_FINANCE",
    "变更前JSON": { "权限列表": ["contract:view"] },
    "变更后JSON": { "权限列表": ["contract:view", "contract:approve"] },
    "变更明细": [
      { "字段名": "权限列表", "旧值": "contract:view", "新值": "contract:view,contract:approve" }
    ]
  }
}
```

**错误码**：5001 审计记录不存在 / 1004 资源不存在。

### 6.3 GET /permission-audit/export

| 项 | 值 |
|---|---|
| 描述 | 导出审计记录 Excel（异步任务） |
| 鉴权 | 是 |
| 权限码 | `permission-audit:export` |

**请求参数**：同 6.1 筛选条件。

**成功响应**：

```json
{ "code": "0000", "msg": "导出任务已创建", "data": { "任务ID": "export-audit-xxx" } }
```

**错误码**：1001 参数错误 / 1003 无权限。

---

## 7. 请求/响应示例

### 7.1 创建并提交合同审批（完整流程示例）

**步骤 1：创建合同**

```bash
curl -X POST 'https://mis-backend.example.com/contract/create' \
  -H 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...' \
  -H 'Content-Type: application/json; charset=UTF-8' \
  -H 'X-Request-Id: req-abc-123' \
  -d '{
    "合同名称": "采购合同",
    "甲方名称": "甲公司",
    "乙方名称": "乙公司",
    "合同金额": 200000,
    "合同类型": "采购",
    "签订日期": "2026-07-23"
  }'
```

成功响应（含 X-Server-Trace 头示例）：

```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=UTF-8
X-Request-Id: req-abc-123
X-Server-Time-Ms: 42.31
X-Server-Trace: {"requireLogin":1.23,"contextBuild":0.85,"contextCacheHit":false,"loadUserAuthorization":12.4,"queryTotal":8.71,"queryRecords":15.62,"total":42.31,"sqlTrace":[{"sql":"SU5TRVJUIElOVE8gZGVmX2NvbnRyYWN0Li4u","ms":62.4}]}

{
  "code": "0000",
  "msg": "创建合同成功",
  "data": {
    "GUID": "abc-123-guid",
    "合同编号": "HT202607230001"
  }
}
```

**步骤 2：提交审批**

```bash
curl -X POST 'https://mis-backend.example.com/contract/submit' \
  -H 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...' \
  -H 'Content-Type: application/json; charset=UTF-8' \
  -d '{ "GUID": "abc-123-guid" }'
```

成功响应：

```json
{
  "code": "0000",
  "msg": "提交审核成功",
  "data": { "合同状态": "PENDING", "工作流实例ID": 1001, "当前流程节点": "DEPT_APPROVAL" }
}
```

**步骤 3：审批人处理待办**

```bash
curl -X POST 'https://mis-backend.example.com/contract/approve' \
  -H 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...' \
  -H 'Content-Type: application/json; charset=UTF-8' \
  -d '{
    "GUID": "abc-123-guid",
    "任务ID": 5001,
    "审核意见": "金额核实无误，同意"
  }'
```

成功响应：

```json
{
  "code": "0000",
  "msg": "审批通过",
  "data": { "合同状态": "APPROVING", "下一节点": "FINANCE_APPROVAL", "实例状态": "RUNNING" }
}
```

### 7.2 失败响应示例

**参数缺失**：

```bash
curl -X POST 'https://mis-backend.example.com/contract/create' \
  -H 'Authorization: Bearer eyJxxx...' \
  -H 'Content-Type: application/json; charset=UTF-8' \
  -d '{ "合同名称": "采购合同" }'
```

```json
{ "code": "1001", "msg": "甲方名称不能为空", "data": null }
```

**无审批权限**：

```json
{ "code": "2005", "msg": "无审批权限", "data": null }
```

**合同状态不允许操作**：

```json
{ "code": "3002", "msg": "当前状态不允许修改", "data": null }
```

**JWT 失效**（`JwtAuthFilter` 拦截，HTTP 401）：

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json; charset=UTF-8

{ "code": "9999", "msg": "token无效或已过期" }
```

### 7.3 X-Server-Trace 头完整示例

```http
X-Server-Trace: {"requireLogin":1.23,"contextBuild":0.85,"contextCacheHit":true,"loadUserAuthorization":12.4,"loadFunctionAuthorization":3.21,"loadQueryConfig":0.5,"loadColumns":2.1,"queryTotal":8.71,"queryRecords":15.62,"total":44.62,"sqlTrace":[{"sql":"U0VMRUNUIEdVSUQsIGLvvIg...","ms":62.4},{"sql":"U0VMRUNUIENPVU5UKCopIEZST00g...","ms":51.2}]}
```

> `sqlTrace` 中 `sql` 为 base64 编码的 UTF-8 文本；前端 `service/request/index.ts` 在 DEV 环境用 `atob` + `TextDecoder` 解码后输出控制台日志。

### 7.4 OnlyOffice 回调示例

```bash
curl -X POST 'https://mis-backend.example.com/onlyoffice/callback' \
  -H 'Authorization: Bearer <OnlyOffice JWT>' \
  -H 'Content-Type: application/json; charset=UTF-8' \
  -d '{
    "status": 2,
    "key": "doc-abc-123-v3",
    "url": "https://onlyoffice.example.com/cache/files/doc-abc-123-v3/output.docx",
    "users": ["EMP001"]
  }'
```

成功响应：

```json
{ "error": 0 }
```

验签失败响应（HTTP 401）：

```json
{ "error": 1 }
```

---

## 8. 数据模型

供前端 `typings/api/` 使用，TypeScript 接口定义。

### 8.1 通用类型

```typescript
/** 统一响应封装 */
interface ApiResponse<T = unknown> {
  code: string;
  msg: string;
  data: T;
}

/** 分页结果 */
interface PageResult<T> {
  rows: T[];
  total: number;
  page: number;
  pageSize: number;
}

/** 分页请求参数 */
interface PageParams {
  page?: number;
  pageSize?: number;
}
```

### 8.2 工作流类型

```typescript
/** 流程定义 */
interface WorkflowDefinition {
  id: number;
  流程编码: string;
  流程名称: string;
  版本: number;
  业务类型: string;
  状态: 'ENABLED' | 'DISABLED';
  节点列表: WorkflowNode[];
  连线列表: WorkflowEdge[];
  创建人: string;
  创建时间: string;
}

/** 节点类型 */
type WorkflowNodeType = 'START' | 'APPROVAL' | 'CC' | 'END';

/** 审批人类型 */
type AssigneeType = 'ROLE' | 'DEPT' | 'SUPERIOR' | 'USER';

/** 会签或签 */
type CountersignType = 'COUNTERSIGN' | 'ORSIGN';

/** 流程节点 */
interface WorkflowNode {
  id?: number;
  节点编码: string;
  节点名称: string;
  节点类型: WorkflowNodeType;
  审批人类型: AssigneeType | null;
  审批人配置: string | null; // JSON 字符串，如 '{"level":1}' / '{"roleCode":"ROLE_FIN"}'
  会签或签: CountersignType | null;
  超时规则: string | null; // JSON: '{"hours":48,"action":"NOTIFY"}'
  顺序: number;
}

/** 流程连线 */
interface WorkflowEdge {
  id?: number;
  源节点ID: number;
  目标节点ID: number;
  条件表达式: string | null; // 如 '金额>100000'
}

/** 实例状态 */
type WorkflowInstanceStatus = 'RUNNING' | 'COMPLETED' | 'TERMINATED' | 'SUSPENDED' | 'EXCEPTION';

/** 流程实例 */
interface WorkflowInstance {
  id: number;
  流程定义ID: number;
  流程名称: string;
  版本: number;
  业务类型: string;
  业务ID: string;
  状态: WorkflowInstanceStatus;
  发起人: string;
  发起人工号: string;
  当前节点: string;
  发起时间: string;
  结束时间: string | null;
}

/** 任务类型 */
type WorkflowTaskType = 'APPROVAL' | 'CC' | 'ADDSIGN' | 'TRANSFER';

/** 任务状态 */
type WorkflowTaskStatus = 'PENDING' | 'COMPLETED' | 'REJECTED' | 'SKIPPED';

/** 审批任务 */
interface WorkflowTask {
  任务ID: number;
  实例ID: number;
  节点名称: string;
  处理人: string;
  处理人工号: string;
  状态: WorkflowTaskStatus;
  任务类型: WorkflowTaskType;
  处理意见: string | null;
  处理时间: string | null;
  到达时间: string;
}

/** 意见类型 */
type OpinionType = 'AGREE' | 'REJECT' | 'TRANSFER' | 'ADDSIGN';

/** 审批意见 */
interface WorkflowOpinion {
  意见ID: number;
  任务ID: number;
  合同ID: string;
  意见内容: string;
  意见类型: OpinionType;
  是否最终: boolean;
  操作人: string;
  操作人工号: string;
  操作时间: string;
  附件: string[];
}

/** 抄送记录 */
interface WorkflowCc {
  抄送ID: number;
  实例ID: number;
  节点名称: string;
  抄送人: string;
  抄送时间: string;
  已读: boolean;
}
```

### 8.3 合同类型

```typescript
/** 合同状态 */
type ContractStatus =
  | 'DRAFT' | 'PENDING' | 'APPROVING' | 'APPROVED' | 'REJECTED'
  | 'SIGNING' | 'SIGNED' | 'ARCHIVED' | 'EXECUTING' | 'TERMINATED' | 'EXPIRED';

/** 合同 */
interface Contract {
  GUID: string;
  合同编号: string;
  合同名称: string;
  合同类型: string;
  合同金额: number;
  甲方名称: string;
  甲方联系人?: string;
  甲方电话?: string;
  乙方名称: string;
  乙方联系人?: string;
  乙方电话?: string;
  签订日期: string;
  开始日期: string;
  结束日期: string;
  付款方式: 'FULL' | 'INSTALLMENT' | 'PREPAY' | 'POSTPAY';
  付款节点?: string;
  合同状态: ContractStatus;
  工作流实例ID: number | null;
  当前流程节点: string | null;
  版本号: number;
  备注?: string;
  操作人员: string;
  开始操作时间: string;
}

/** 合同文档 */
interface ContractDoc {
  文档ID: number;
  合同ID: string;
  文档名称: string;
  文档Key: string;
  文件类型: 'docx' | 'xlsx' | 'pptx';
  当前版本: number;
  编辑模式: 'EDIT' | 'VIEW';
  创建人: string;
  创建时间: string;
  最后修改时间: string;
}

/** 文档版本 */
interface ContractDocVersion {
  版本ID: number;
  文档ID: number;
  版本号: number;
  文件Key: string;
  创建人: string;
  创建时间: string;
  版本说明: string;
  是否标记: boolean;
  版本大小: number;
}

/** 操作类型 */
type DocOpType = 'OPEN' | 'EDIT' | 'SAVE' | 'DOWNLOAD' | 'VERSION_CREATE' | 'VERSION_ROLLBACK';

/** 文档操作留痕 */
interface ContractDocOpLog {
  日志ID: number;
  文档ID: number;
  操作类型: DocOpType;
  操作人: string;
  操作人工号: string;
  操作时间: string;
  操作详情: string;
  IP: string;
  设备: string;
}

/** 合同审批意见（与工作流意见关联） */
interface ContractApprovalOpinion {
  意见ID: number;
  任务ID: number;
  合同ID: string;
  意见内容: string;
  意见类型: OpinionType;
  是否最终: boolean;
  操作人: string;
  操作时间: string;
  附件: string[];
}
```

### 8.4 权限审计类型

```typescript
/** 变更类型 */
type AuditChangeType = 'ROLE_ASSIGN' | 'USER_ASSIGN' | 'MENU_ASSIGN' | 'WORKFLOW_NODE_ASSIGN';

/** 权限审计 */
interface PermissionAudit {
  审计ID: number;
  变更类型: AuditChangeType;
  操作人: string;
  操作人工号: string;
  操作时间: string;
  目标对象: string;
  目标名称: string;
  变更摘要: string;
  变更明细数: number;
}

/** 审计明细 */
interface PermissionAuditDetail {
  审计ID: number;
  变更类型: AuditChangeType;
  操作人: string;
  操作时间: string;
  目标对象: string;
  变更前JSON: Record<string, unknown>;
  变更后JSON: Record<string, unknown>;
  变更明细: Array<{
    字段名: string;
    旧值: string;
    新值: string;
  }>;
}
```

---

## 9. 错误码表

### 9.1 通用错误码

| 错误码 | 常量名 | 含义 | 触发场景 |
|---|---|---|---|
| 0000 | SUCCESS | 成功 | 正常响应 |
| 1001 | PARAM_ERROR | 参数错误 | 必填缺失/格式非法/范围非法 |
| 1002 | AUTH_UNAUTHORIZED | 未认证 | JWT 缺失（HTTP 401） |
| 1003 | AUTH_FORBIDDEN | 无权限 | 鉴权通过但无操作权限（按钮/数据级） |
| 1004 | NOT_FOUND | 资源不存在 | 路径 ID 对应记录不存在 |
| 1005 | BUSINESS_ERROR | 业务错误 | 通用业务规则校验失败 |
| 5000 | SERVER_ERROR | 服务器错误 | 未捕获异常 |
| 8888 | AUTH_UNAUTHORIZED_LEGACY | 未登录（遗留） | JwtAuthFilter token 缺失 |
| 9999 | AUTH_TOKEN_EXPIRED | token 失效 | token 过期或黑名单 |

> 兼容说明：现有 `ApiCode` 常量 `PARAM_ERROR='2001'`、`NOT_FOUND='2002'`、`BUSINESS_ERROR='2003'` 为历史值。本次升级将 2xxx 段重新分配给工作流模块，新增 `PARAM_ERROR='1001'`/`NOT_FOUND='1004'`/`BUSINESS_ERROR='1005'` 作为通用码，历史常量保留为别名在新模块中不再使用，旧模块（workbench/employee 等）保持原值不改动，避免回归。

### 9.2 工作流引擎错误码

| 错误码 | 含义 | 触发场景 |
|---|---|---|
| 2001 | 流程定义不存在 | definition/detail、instance/start 找不到定义 |
| 2002 | 流程已停用 | 对 DISABLED 定义发起实例或重复停用 |
| 2003 | 实例不存在 | instance/detail、instance/terminate 找不到实例 |
| 2004 | 任务不存在 | task/approve 等找不到任务 |
| 2005 | 无审批权限 | 当前用户非任务处理人 |
| 2006 | 任务已处理 | 任务状态非 PENDING |
| 2007 | 流程已结束 | 实例状态为 COMPLETED/TERMINATED 时操作 |
| 2008 | 审批人指派为空 | AssigneeResolver 解析处理人列表为空 |
| 2009 | 转签深度超限 | 转签/加签层级超过配置（默认 ≤ 3） |

### 9.3 合同模块错误码

| 错误码 | 含义 | 触发场景 |
|---|---|---|
| 3001 | 合同不存在 | detail/update/delete 等找不到合同 |
| 3002 | 合同状态不允许此操作 | 状态机校验失败（如 DRAFT 之外不允许删除） |
| 3003 | 工作流实例创建失败 | submit 时工作流启动异常 |

### 9.4 文档/OnlyOffice 错误码

| 错误码 | 含义 | 触发场景 |
|---|---|---|
| 4001 | 文档不存在 | config/version 等找不到文档 |
| 4002 | 权限不足 | 无编辑/下载/导出权限 |
| 4003 | 回调验签失败 | OnlyOffice callback JWT 校验失败 |
| 4004 | 版本不存在 | version/mark、version/rollback 找不到版本 |
| 4005 | 版本回溯失败 | rollback 处理异常 |

### 9.5 权限审计错误码

| 错误码 | 含义 | 触发场景 |
|---|---|---|
| 5001 | 审计记录不存在 | permission-audit/detail 找不到记录 |

---

## 10. 接口依赖关系

### 10.1 合同审批全流程依赖

```mermaid
flowchart TD
    A["前端调用<br/>POST /contract/create"] --> B["合同状态=DRAFT"]
    B --> C["前端调用<br/>POST /contract/submit"]
    C --> D["ContractWorkflowService<br/>调用 POST /workflow/instance/start"]
    D --> E["WorkflowEngine 创建<br/>首个审批任务"]
    E --> F["审批人待办<br/>GET /workflow-todo/list"]
    F --> G["审批人处理<br/>POST /contract/approve"]
    G --> H{审批结果}
    H -- 同意且非最后节点 --> I["推进下一节点<br/>创建新任务"]
    I --> F
    H -- 同意且最后节点 --> J["流程完成<br/>回调合同状态=APPROVED"]
    H -- 拒绝 --> K["流程终止<br/>回调合同状态=REJECTED"]
    J --> L["前端调用<br/>POST /contract/sign"]
    L --> M["合同状态=SIGNED"]
    M --> N["前端调用<br/>POST /contract/archive"]
    N --> O["合同状态=ARCHIVED"]
    K --> P["发起人可修改后重新 submit"]
```

### 10.2 文档协作与版本依赖

```mermaid
flowchart TD
    A["前端打开文档<br/>GET /contract-doc/config/{docId}"] --> B["后端返回<br/>含 JWT 的 OnlyOffice 配置"]
    B --> C["前端 new DocsAPI.DocEditor<br/>加载编辑器"]
    C --> D["Document Server 拉取文件<br/>GET /onlyoffice/download?token=xxx"]
    D --> E["用户协同编辑"]
    E --> F["触发 forceSave<br/>POST /onlyoffice/callback status=6"]
    F --> G["后端下载文档存储<br/>创建版本记录"]
    G --> H["写操作留痕<br/>def_contract_doc_op_log"]
    H --> I["更新文档当前版本"]
    I --> J["用户查看历史<br/>GET /onlyoffice/history/{docId}"]
    J --> K["用户对比版本<br/>GET /onlyoffice/history-data/{versionId}"]
    K --> L{是否回溯}
    L -- 是 --> M["POST /contract-doc/version/rollback"]
    M --> N["创建新版本<br/>内容回退"]
    L -- 否 --> O["继续编辑"]
```

### 10.3 系统模块依赖关系

```mermaid
flowchart LR
    subgraph 前端
        FE_VUE["Vue 3 + Soybean Admin"]
    end
    subgraph 后端
        BASE["BaseApiController<br/>统一响应/性能追踪"]
        AUTH["JwtAuthFilter<br/>双Token鉴权"]
        WF["Workflow 子域<br/>Engine/Service/Api"]
        CT["Contract 子域<br/>Service/Api"]
        OO["OnlyOffice 子域<br/>Jwt/Config/Callback"]
        PA["PermissionAudit 子域<br/>Service/Api"]
        DB[(MySQL<br/>def_workflow_*<br/>def_contract_*<br/>def_permission_audit)]
    end
    subgraph 外部
        DOCSVR["OnlyOffice<br/>Document Server"]
    end

    FE_VUE -->|HTTP/JWT| AUTH
    AUTH --> BASE
    BASE --> WF
    BASE --> CT
    BASE --> OO
    BASE --> PA
    CT -->|调用| WF
    CT -->|关联| OO
    WF --> DB
    CT --> DB
    OO --> DB
    PA --> DB
    DOCSVR -->|回调| OO
    OO -->|下载文件| DOCSVR
```

### 10.4 待办与任务流转时序

```mermaid
sequenceDiagram
    participant 发起人
    participant 前端
    participant 后端
    participant 工作流引擎
    participant 审批人

    发起人->>前端: 提交合同审批
    前端->>后端: POST /contract/submit
    后端->>工作流引擎: instance/start
    工作流引擎-->>后端: 实例ID + 当前节点
    后端-->>前端: 合同状态=PENDING
    工作流引擎->>工作流引擎: 创建审批任务(处理人=李四)
    审批人->>前端: 打开待办
    前端->>后端: GET /workflow-todo/list
    后端-->>前端: 待办任务列表
    审批人->>前端: 同意
    前端->>后端: POST /workflow/task/approve
    后端->>工作流引擎: task/approve
    工作流引擎->>工作流引擎: 会签判断/节点推进
    工作流引擎->>后端: 回调 onTaskCompleted
    后端->>后端: ContractWorkflowService 更新合同状态
    后端-->>前端: 审批结果
```

---

## 11. 接口版本与变更管理

### 11.1 旧接口兼容策略

| 接口 | 变更类型 | 兼容策略 |
|---|---|---|
| `GET /contract/list` | 重写（补全筛选 + 分页字段 `list`→`rows`） | 路径不变；前端同步更新取值字段；旧字段 `list` 在一个发布周期内同时返回，标记 deprecated |
| `POST /contract/submit` | 重写（启动工作流实例） | 路径不变；请求体增加可选 `流程定义ID`；响应新增 `工作流实例ID` |
| `POST /contract/approve` / `reject` | 重写（委托工作流） | 路径不变；请求体新增 `任务ID`（必填） |
| `GET /contract/flow` | 废弃 | 由 `GET /workflow/instance/detail/{id}` 取代；保留一个发布周期返回旧结构，响应头加 `Deprecation: true` |

### 11.2 新增接口版本化策略

- **不引入显式 `/v2/` 前缀**：与现有 `workbench/*`、`auth/*` 风格保持一致，路径语义本身表达资源。
- **破坏性变更**通过新动作路径承接，例如若 `contract/create` 需返回不同结构，新增 `contract/create-v2` 而非修改原接口。
- **字段新增**一律视为非破坏性（前端忽略未知字段），通过 `X-Request-Id` 关联日志追溯。
- **字段移除**视为破坏性，须先标记 deprecated 一个发布周期。

### 11.3 接口废弃流程

1. **标记阶段**（1 个发布周期）：
   - 接口继续工作，响应头增加 `Deprecation: true` 与 `Sunset: <ISO日期>`（RFC 8594）
   - 接口文档标注"已废弃，替代接口为 XXX"
   - 前端控制台输出 warning 日志（DEV 环境）
2. **下线阶段**：
   - 超过 Sunset 日期后，接口返回 `1005 业务错误`（`msg: 接口已下线，请使用 XXX`）
   - 同时在监控告警中统计残留调用方
3. **删除阶段**：
   - 确认无残留调用后，从 `Routes.php` 移除路由，从控制器移除方法

### 11.4 变更记录

| 版本 | 日期 | 变更内容 |
|---|---|---|
| v1.0 | 2026-07-23 | 首版发布，定义 52 个接口、错误码表、数据模型、依赖关系 |

---

## 附录 A：路由清单速查

| 子域 | 方法 | 路径 | 鉴权 | 控制器方法 |
|---|---|---|---|---|
| 工作流 | GET | /workflow/definition/list | 是 | WorkflowApi::definitionList |
| 工作流 | GET | /workflow/definition/detail/{id} | 是 | WorkflowApi::definitionDetail |
| 工作流 | POST | /workflow/definition/save | 是 | WorkflowApi::definitionSave |
| 工作流 | POST | /workflow/definition/toggle/{id} | 是 | WorkflowApi::definitionToggle |
| 工作流 | GET | /workflow/instance/list | 是 | WorkflowApi::instanceList |
| 工作流 | GET | /workflow/instance/detail/{id} | 是 | WorkflowApi::instanceDetail |
| 工作流 | POST | /workflow/instance/start | 是 | WorkflowApi::instanceStart |
| 工作流 | POST | /workflow/instance/terminate/{id} | 是 | WorkflowApi::instanceTerminate |
| 工作流 | POST | /workflow/task/approve | 是 | WorkflowApi::taskApprove |
| 工作流 | POST | /workflow/task/reject | 是 | WorkflowApi::taskReject |
| 工作流 | POST | /workflow/task/transfer | 是 | WorkflowApi::taskTransfer |
| 工作流 | POST | /workflow/task/countersign | 是 | WorkflowApi::taskCountersign |
| 工作流 | POST | /workflow/task/withdraw/{id} | 是 | WorkflowApi::taskWithdraw |
| 工作流 | GET | /workflow/task/opinion/{id} | 是 | WorkflowApi::taskOpinion |
| 工作流 | POST | /workflow/task/opinion/edit | 是 | WorkflowApi::taskOpinionEdit |
| 工作流 | GET | /workflow/task/opinion/export | 是 | WorkflowApi::taskOpinionExport |
| 工作流 | POST | /workflow/cc/read/{id} | 是 | WorkflowApi::ccRead |
| 待办 | GET | /workflow-todo/list | 是 | WorkflowTodoApi::list |
| 待办 | GET | /workflow-todo/done | 是 | WorkflowTodoApi::done |
| 待办 | GET | /workflow-todo/cc | 是 | WorkflowTodoApi::cc |
| 待办 | GET | /workflow-todo/initiated | 是 | WorkflowTodoApi::initiated |
| 合同 | GET | /contract/list | 是 | ContractApi::list |
| 合同 | GET | /contract/detail/{guid} | 是 | ContractApi::detail |
| 合同 | POST | /contract/create | 是 | ContractApi::create |
| 合同 | POST | /contract/update | 是 | ContractApi::update |
| 合同 | POST | /contract/delete | 是 | ContractApi::delete |
| 合同 | POST | /contract/submit | 是 | ContractApi::submit |
| 合同 | POST | /contract/approve | 是 | ContractApi::approve |
| 合同 | POST | /contract/reject | 是 | ContractApi::reject |
| 合同 | POST | /contract/sign | 是 | ContractApi::sign |
| 合同 | POST | /contract/archive | 是 | ContractApi::archive |
| 合同 | GET | /contract/options | 是 | ContractApi::options |
| 合同 | GET | /contract/stats | 是 | ContractApi::stats |
| 合同 | GET | /contract/todo | 是 | ContractApi::todo |
| 合同 | GET | /contract/initiated | 是 | ContractApi::initiated |
| 文档 | GET | /contract-doc/list/{contractId} | 是 | ContractDocApi::list |
| 文档 | POST | /contract-doc/create | 是 | ContractDocApi::create |
| 文档 | GET | /contract-doc/config/{docId} | 是 | ContractDocApi::config |
| 文档 | GET | /contract-doc/version/list/{docId} | 是 | ContractDocApi::versionList |
| 文档 | POST | /contract-doc/version/mark | 是 | ContractDocApi::versionMark |
| 文档 | POST | /contract-doc/version/rollback | 是 | ContractDocApi::versionRollback |
| 文档 | GET | /contract-doc/version/compare | 是 | ContractDocApi::versionCompare |
| 文档 | GET | /contract-doc/oplog/{docId} | 是 | ContractDocApi::oplog |
| 文档 | GET | /contract-doc/oplog/export | 是 | ContractDocApi::oplogExport |
| OnlyOffice | GET | /onlyoffice/config/{docId} | 是 | OnlyOfficeApi::config |
| OnlyOffice | POST | /onlyoffice/callback | 否（OnlyOffice 验签） | OnlyOfficeApi::callback |
| OnlyOffice | GET | /onlyoffice/download | 否（票据签名） | OnlyOfficeApi::download |
| OnlyOffice | GET | /onlyoffice/history/{docId} | 是 | OnlyOfficeApi::history |
| OnlyOffice | GET | /onlyoffice/history-data/{versionId} | 是 | OnlyOfficeApi::historyData |
| 审计 | GET | /permission-audit/list | 是 | PermissionAuditApi::list |
| 审计 | GET | /permission-audit/detail/{id} | 是 | PermissionAuditApi::detail |
| 审计 | GET | /permission-audit/export | 是 | PermissionAuditApi::export |

---

## 附录 B：环境变量配置

| 变量 | 说明 |
|---|---|
| ONLYOFFICE_URL | OnlyOffice Document Server 地址 |
| ONLYOFFICE_JWT_SECRET | OnlyOffice JWT 签名密钥（HS256） |
| ONLYOFFICE_CALLBACK_BASE_URL | 后端公网回调基址 |
| ONLYOFFICE_VERSION_ON_FORCE_SAVE | forceSave 是否创建版本快照（默认 true） |
| ONLYOFFICE_DOC_STORAGE_PATH | 文档存储路径（默认 `WRITEPATH/uploads/onlyoffice/`） |
