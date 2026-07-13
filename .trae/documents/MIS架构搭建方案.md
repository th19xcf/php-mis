# 管理信息系统（MIS）架构搭建方案

> **文档维护说明**：本文档记录架构设计意图与实现状态。各功能模块标注 `[已实现]`、`[部分实现]`、`[规划中]`，新人可据此判断当前进度。最近更新：2026-07-11。

---

## 一、技术栈概览

| 层级 | 技术选型 | 版本要求 | 状态 |
|------|---------|---------|------|
| 前端框架 | Vue 3 + Soybean Admin | Vue 3.5+, Vite 8+ | `[已实现]` |
| 前端 UI 库 | **Naive UI**（非 Element-Plus） | naive-ui 2.44+ | `[已实现]` |
| 表格组件 | AG Grid Community | ag-grid-vue3 35+ | `[已实现]` |
| 图表组件 | ECharts | echarts 6.0 | `[已实现]` |
| 后端框架 | PHP 8.1+ + CodeIgniter 4 | CI 4.5+ | `[已实现]` |
| 数据库 | MySQL 8.0+（远程服务器） | MySQL 8.0+ | `[已实现]` |
| 缓存 | **FileHandler**（非 Redis） | CI4 文件缓存 | `[已实现]` |
| 认证 | JWT（firebase/php-jwt） | JWT v7 | `[已实现]` |
| Web 服务器 | Apache 2.4+（Windows） | Apache 2.4+ | `[已实现]` |
| 构建工具 | Vite 8+, pnpm 10+ | Node.js 20+ | `[已实现]` |
| Excel 导入导出 | PhpSpreadsheet | v5.8 | `[已实现]` |

### 与原始设计的偏差说明

| 原始设计 | 实际实现 | 偏差原因 |
|---------|---------|---------|
| Element-Plus | Naive UI | Soybean Admin 2.x 已切换至 Naive UI，组件更轻量、TS 支持更好 |
| Redis 缓存 | FileHandler 文件缓存 | Windows 环境未部署 Redis；MetadataCache 已基于 FileHandler 实现表级 TTL + 反向索引 |
| `/v1/` API 前缀 | 无前缀（如 `auth/login`） | 简化路由，CI4 路由直接按模块分组 |
| RESTful CRUD 用户 API | 元数据驱动工作台 | 实际需求是通用查询平台，非 CRUD 模式 |

---

## 二、前后端分离架构设计

### 2.1 系统架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                        客户端 (Browser)                         │
│              Vue3 + Naive UI + AG Grid + ECharts                │
└─────────────────────────────────────────────────────────────────┘
                                │
                                │ HTTP (JWT Bearer Token)
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Apache 2.4+ (反向代理)                      │
│                    端口: 80 (HTTP) / 443 (HTTPS)                 │
│              同时处理前端静态资源和服务端API请求                    │
└─────────────────────────────────────────────────────────────────┘
                                │
                    ┌───────────┴───────────┐
                    │                       │
                    ▼                       ▼
        ┌───────────────────┐   ┌───────────────────┐
        │   前端静态资源      │   │   后端API服务       │
        │   (Vue3构建产物)    │   │   (CodeIgniter)    │
        │   目录: /public/   │   │   端口: 8080       │
        └───────────────────┘   └───────────────────┘
                                        │
                                        ▼
                        ┌───────────────────────┐
                        │     MySQL 8.0+        │
                        │   (远程数据库服务器)    │
                        │   端口: 3306           │
                        └───────────────────────┘
```

### 2.2 认证流程 `[已实现]`

```
1. 前端发送 POST /auth/login（userName + password + region）
2. 后端 AuthModel::verifyUser() 验证 def_user 表
3. 计算角色赋权、属地/部门赋权（合并查询）
4. JwtTokenService 生成 accessToken（2h）+ refreshToken（7d）
5. 前端存储 token 到 LocalStorage
6. 后续请求携带 Authorization: Bearer <token>
7. JwtAuthFilter 拦截验证，SessionUserContext 注入用户上下文
```

**代理登录** `[已实现]`：用户名格式 `原用户名&代理用户名`，使用代理用户密码验证，授予调试权限但不记录日志。

---

## 三、项目目录结构

### 3.1 后端目录结构 `[已实现]`

```
backend/
├── app/
│   ├── Config/                    # 配置文件
│   │   ├── Routes.php             # 路由定义
│   │   ├── Database.php           # 数据库连接
│   │   ├── Cache.php              # 缓存配置（FileHandler）
│   │   ├── Filters.php            # 过滤器注册
│   │   ├── TokenBlacklist.php     # Token 黑名单配置
│   │   └── Cors.php               # 跨域配置
│   ├── Controllers/
│   │   ├── Auth.php               # 认证（登录/登出/getUserInfo/refreshToken）
│   │   ├── BaseApiController.php  # API 基类
│   │   ├── CacheController.php     # 缓存管理 API
│   │   ├── Workbench.php           # 工作台入口
│   │   ├── Workbench/              # 工作台子控制器
│   │   │   ├── WorkbenchChartController.php
│   │   │   ├── WorkbenchEditController.php
│   │   │   ├── WorkbenchImportController.php
│   │   │   ├── WorkbenchPopupController.php
│   │   │   └── WorkbenchResponseTrait.php
│   │   ├── MatchApi.php            # 数据匹配
│   │   ├── Route.php               # 路由/菜单
│   │   ├── Comment.php             # 批注
│   │   ├── DeptApi.php             # 部门管理
│   │   ├── EmployeeApi.php         # 员工管理
│   │   ├── ContractApi.php         # 合同管理
│   │   ├── InterviewApi.php        # 面试管理
│   │   ├── InvitationApi.php       # 邀请管理
│   │   └── TrainApi.php            # 培训管理
│   ├── Models/
│   │   ├── AuthModel.php           # 认证数据模型
│   │   └── Mcommon.php             # 通用数据模型（SQL 执行）
│   ├── Libraries/
│   │   ├── AuthorizationService.php # 权限服务（赋权字段加载）
│   │   ├── MetadataCache.php        # 元数据缓存（表级 TTL + 反向索引）
│   │   ├── JwtTokenService.php      # JWT 生成/验证
│   │   ├── SessionUserContext.php   # 用户上下文
│   │   ├── TokenBlacklistService.php # Token 黑名单
│   │   ├── ContextCacheService.php  # 上下文缓存
│   │   └── ApiExceptionHandler.php  # 全局异常处理
│   ├── Services/
│   │   ├── Workbench/              # 工作台业务服务
│   │   │   ├── ContextService.php   # 上下文（列配置加载）
│   │   │   ├── QueryService.php     # 数据查询
│   │   │   ├── EditService.php      # 编辑服务
│   │   │   ├── BatchEditService.php # 批量编辑
│   │   │   ├── ImportService.php    # 数据导入
│   │   │   ├── ExportService.php    # 数据导出
│   │   │   ├── ChartService.php     # 图表数据
│   │   │   ├── ChartDrillService.php # 图表钻取
│   │   │   ├── PopupService.php     # 弹窗选择
│   │   │   ├── FieldConfigService.php # 字段配置
│   │   │   └── WorkbenchSqlHelper.php # SQL 辅助
│   │   ├── RouteService.php         # 路由/菜单服务
│   │   └── Employee/EmployeeService.php
│   ├── Filters/
│   │   └── JwtAuthFilter.php        # JWT 认证过滤器
│   ├── Exceptions/
│   │   ├── AuthException.php
│   │   ├── BusinessException.php
│   │   └── ValidationException.php
│   ├── Constants/
│   │   └── ApiCode.php              # API 状态码枚举
│   └── Traits/
│       ├── AuditFieldsTrait.php
│       └── ChartColumnConfigTrait.php
├── public/                          # Web 根目录
├── writable/                        # 缓存、日志、上传
└── composer.json
```

### 3.2 前端目录结构 `[已实现]`

```
frontend/
├── src/
│   ├── views/                       # 页面
│   │   ├── _builtin/                # 内置页面（登录/403/404/500）
│   │   ├── menu-bridge/             # 工作台桥接页（核心）
│   │   ├── match-data/              # 数据匹配
│   │   ├── home/                    # 首页
│   │   ├── system/                  # 系统管理（用户/角色/部门）
│   │   ├── personnel/               # 人事管理
│   │   ├── contract/                # 合同管理
│   │   └── common/                  # 通用页面
│   ├── hooks/
│   │   ├── business/                # 业务 hooks
│   │   │   ├── use-workbench-*.ts   # 工作台系列 hooks
│   │   │   ├── use-match-store.ts   # 数据匹配状态管理
│   │   │   └── auth.ts              # 认证
│   │   └── common/                  # 通用 hooks
│   ├── service/
│   │   ├── api/                     # API 接口定义
│   │   └── request/                 # Axios 封装
│   ├── store/modules/               # Pinia 状态管理
│   ├── layouts/                     # 布局组件
│   ├── components/                  # 公共组件
│   ├── router/                      # 路由配置
│   ├── utils/                       # 工具函数
│   ├── styles/                      # 全局样式
│   └── typings/                     # TypeScript 类型
├── package.json
└── vite.config.ts
```

---

## 四、API 接口规范

### 4.1 响应格式 `[已实现]`

**成功响应**（主业务 API）：
```json
{
  "code": "0000",
  "data": { ... },
  "msg": "success"
}
```

**成功响应**（外部/Demo 服务）：
```json
{
  "status": "200",
  "result": { ... },
  "message": "success"
}
```

**错误响应**：
```json
{
  "code": "A1001",
  "msg": "错误描述"
}
```

> 开发环境下额外返回 `debug` 字段（文件、行号、堆栈），生产环境隐藏。

### 4.2 核心 API 路由 `[已实现]`

```
# 认证模块
POST   auth/login                   # 登录
POST   auth/logout                  # 登出
GET    auth/getUserInfo             # 获取用户信息（角色/按钮/菜单）
POST   auth/refreshToken            # 刷新令牌

# 工作台模块
GET    workbench/page/{functionCode}    # 加载页面配置+数据
POST   workbench/query                  # 数据查询
POST   workbench/edit                   # 编辑数据
POST   workbench/batchEdit              # 批量编辑
POST   workbench/import                 # 数据导入
POST   workbench/export                 # 数据导出
GET    workbench/popupConfig            # 弹窗配置
POST   workbench/chart                  # 图表数据

# 数据匹配
GET    match/page/{functionCode}        # 匹配页面数据
POST   match/build                      # 建立匹配
POST   match/revoke                     # 撤销匹配

# 路由/菜单
GET    route/getUserRoutes              # 获取用户路由

# 缓存管理
POST   cache/invalidate-table           # 清除指定表缓存
POST   cache/invalidate-all             # 清除全部缓存
GET    cache/status                     # 缓存状态

# 批注
GET    comment/list                     # 批注列表
POST   comment/save                     # 保存批注
```

---

## 五、核心功能实现状态

| 功能模块 | 状态 | 说明 |
|---------|------|------|
| JWT 认证 | `[已实现]` | accessToken(2h) + refreshToken(7d)，JwtAuthFilter 全局拦截 |
| 代理登录 | `[已实现]` | 用户名 `原用户&代理用户` 格式，代理用户需调试赋权 |
| 元数据驱动工作台 | `[已实现]` | def_function + def_query_config + view_function 配置化 |
| 数据查询 | `[已实现]` | AG Grid 展示，支持筛选/排序/分页/颜色标注/列固定 |
| 数据编辑 | `[已实现]` | 单行/批量编辑，字段类型校验，弹窗选择 |
| 数据导入 | `[已实现]` | Excel 导入，字段长度校验，临时表中转 |
| 数据导出 | `[已实现]` | Excel 导出，PhpSpreadsheet |
| 图表展示 | `[已实现]` | ECharts，支持钻取、多图联动 |
| 数据匹配 | `[已实现]` | A/B 表匹配，候选高亮，建立/撤销匹配关系 |
| 元数据缓存 | `[已实现]` | MetadataCache，表级 TTL（3600s-86400s），反向索引清理 |
| 权限控制 | `[已实现]` | 角色 → 功能编码 → 按钮权限，赋权字段（属地/部门） |
| 全局异常处理 | `[已实现]` | ApiExceptionHandler，标准化 JSON 错误响应 |
| SQL 审计日志 | `[已实现]` | sys_sql_log，可配置开关 |
| 性能追踪 | `[已实现]` | X-Server-Trace 头 + 分段耗时日志 |

---

## 六、缓存策略 `[已实现]`

实际使用 **FileHandler** 文件缓存（非 Redis），通过 MetadataCache 实现：

| 缓存对象 | TTL | 失效方式 |
|---------|-----|---------|
| def_query_column（列定义） | 3600s | 表级反向索引精准删除 |
| def_query_config（查询配置） | 7200s | 表级反向索引精准删除 |
| def_user（用户信息） | 1800s | 表级反向索引精准删除 |
| def_chart_drill_config | 3600s | 表级反向索引精准删除 |
| Token 黑名单 | 与 token 同寿 | 自动过期 |

> 注意：FileHandler 不支持 `deleteMatching`，MetadataCache 通过反向索引实现精准删除，避免全站冷启动。

---

## 七、开发环境配置

### 7.1 环境要求

| 软件 | 版本 | 说明 |
|------|------|------|
| PHP | 8.1+ | composer.json 要求 ^8.1 |
| Composer | 2.9+ | PHP 依赖管理 |
| Node.js | 20.19+ | 前端构建 |
| pnpm | 10.5+ | 前端包管理 |
| MySQL | 8.0+ | 远程服务器 |
| Apache | 2.4+ | Web 服务器 |

### 7.2 后端启动

```bash
cd d:\code\php\mis\backend
composer install
cp env .env  # 配置数据库连接
php spark serve  # 或通过 Apache 配置
```

### 7.3 前端启动

```bash
cd d:\code\php\mis\frontend
pnpm install
pnpm dev          # 开发模式（test 环境）
pnpm dev:prod     # 开发模式（prod 环境）
pnpm build        # 生产构建
pnpm build:test   # 测试环境构建
```

---

## 八、性能优化记录

### 已完成优化

| 优化项 | 时间 | 效果 |
|--------|------|------|
| 登录链路 DB 查询去冗余 | 2026-07 | login DB 往返从 7-8 次降至 4-5 次 |
| getRoleCodes 请求内记忆化 | 2026-07 | getUserInfo DB 往返从 5-6 次降至 3 次 |
| 三项赋权合并查询 | 2026-07 | login 减少 2 次 def_user 查询 |
| 元数据缓存反向索引 | - | 精准删除避免全站冷启动 |
| 请求级 SQL 缓存 | - | Mcommon 静态 $requestCache 防重复查询 |
| AG Grid 外部筛选 | 2026-07 | match-data 切换筛选不丢失选中状态 |

### 性能追踪机制

- 后端：`X-Server-Trace` 响应头 + 分段耗时日志（hrtime 纳秒精度）
- 前端：`performance-trace.ts` 工具，页面切换耗时统计

---

## 九、风险评估与解决方案

| 风险项 | 风险等级 | 应对方案 | 状态 |
|--------|---------|---------|------|
| 远程数据库延迟 | 中 | MetadataCache 文件缓存层，请求级 SQL 去重 | `[已应对]` |
| FileHandler 不支持模式删除 | 中 | 反向索引精准删除，避免 deleteAll 全站冷启动 | `[已应对]` |
| Apache 并发限制 | 低 | PHP 内置服务器仅用于开发，生产用 Apache MPM | `[已应对]` |
| Token 泄露风险 | 中 | refreshToken 机制 + Token 黑名单（登出失效） | `[已应对]` |
| 生产环境敏感信息泄露 | 中 | 生产环境隐藏 stack trace/SQL/文件路径 | `[已应对]` |
