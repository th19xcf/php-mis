# MIS 前端服务

基于 Vue 3 + Soybean Admin + Naive UI + AG Grid 的管理信息系统前端。

## 环境要求

| 软件 | 版本 |
|------|------|
| Node.js | 20.19+ |
| pnpm | 10.5+ |

## 快速开始

```bash
# 1. 安装依赖
cd d:\code\php\mis\frontend
pnpm install

# 2. 启动开发服务器
pnpm dev          # 默认连接 test 环境
pnpm dev:prod     # 连接 prod 环境

# 3. 生产构建
pnpm build        # 生产环境构建
pnpm build:test   # 测试环境构建

# 4. 预览构建产物
pnpm preview
```

## 技术栈

| 技术 | 版本 | 用途 |
|------|------|------|
| Vue | 3.5+ | 前端框架 |
| Naive UI | 2.44+ | UI 组件库 |
| AG Grid | 35+ | 数据表格 |
| ECharts | 6.0 | 图表 |
| Pinia | 3.0+ | 状态管理 |
| Vue Router | 5.0+ | 路由 |
| Vite | 8.0+ | 构建工具 |
| TypeScript | 6.0+ | 类型系统 |
| UnoCSS | 66+ | 原子化 CSS |
| ECharts | 6.0 | 数据可视化 |

## 目录结构

```
src/
├── views/                       # 页面
│   ├── _builtin/                #   内置页面
│   │   ├── login/               #     登录页
│   │   ├── 403/                 #     无权限
│   │   ├── 404/                 #     未找到
│   │   └── 500/                 #     服务器错误
│   ├── menu-bridge/             #   工作台桥接页（核心）
│   │   ├── index.vue            #     入口
│   │   ├── constants.ts         #     常量
│   │   ├── generic-query-workbench.vue  # 通用查询工作台
│   │   ├── modules/
│   │   │   ├── components/      #     工作台子组件
│   │   │   │   ├── WorkbenchToolbar.vue       # 工具栏
│   │   │   │   ├── WorkbenchImport.vue        # 导入
│   │   │   │   ├── WorkbenchUpdateForm.vue     # 编辑表单
│   │   │   │   ├── WorkbenchAddForm.vue        # 新增表单
│   │   │   │   ├── WorkbenchPopupSelect.vue    # 弹窗选择
│   │   │   │   ├── WorkbenchComment.vue        # 批注
│   │   │   │   ├── WorkbenchRightPanel.vue     # 右侧面板
│   │   │   │   └── ...
│   │   │   └── styles/
│   │   └── generic-query-workbench.scss
│   ├── match-data/              #   数据匹配
│   │   ├── index.vue            #     入口
│   │   └── components/
│   │       ├── MatchTablePanel.vue  # 表格面板
│   │       └── MatchBottomBar.vue   # 底部操作栏
│   ├── home/                    #   首页
│   ├── system/                  #   系统管理
│   │   ├── user/                #     用户管理
│   │   ├── role/                #     角色管理
│   │   └── dept/                #     部门管理
│   ├── personnel/               #   人事管理
│   │   ├── employee/            #     员工
│   │   ├── interview/           #     面试
│   │   ├── invitation/          #     邀请
│   │   └── train/               #     培训
│   ├── contract/                #   合同管理
│   └── common/                  #   通用页面
│
├── hooks/
│   ├── business/                #   业务 hooks
│   │   ├── use-workbench-*.ts   #     工作台系列（20+ hooks）
│   │   ├── use-match-store.ts   #     数据匹配状态管理
│   │   ├── auth.ts              #     认证
│   │   ├── use-table-edit.ts    #     表格编辑
│   │   ├── use-workbench-chart*.ts #  图表
│   │   └── ...
│   └── common/                  #   通用 hooks
│       ├── echarts.ts           #     ECharts 封装
│       ├── form.ts              #     表单
│       ├── table.ts             #     表格
│       └── use-loading.ts       #     加载状态
│
├── service/
│   ├── api/                     #   API 接口定义
│   │   ├── auth.ts              #     认证
│   │   ├── workbench.ts         #     工作台
│   │   ├── match.ts             #     数据匹配
│   │   ├── route.ts             #     路由
│   │   ├── dept.ts              #     部门
│   │   └── ...
│   └── request/                 #   Axios 封装
│       ├── index.ts             #     请求实例
│       ├── shared.ts            #     共享配置
│       └── type.ts              #     类型定义
│
├── store/modules/               #   Pinia 状态管理
│   ├── auth/                    #     认证状态
│   ├── route/                   #     路由状态
│   ├── tab/                     #     标签页
│   ├── theme/                   #     主题
│   ├── workbench/               #     工作台
│   └── ...
│
├── layouts/                     #   布局组件
│   ├── base-layout/             #     基础布局
│   └── modules/                 #     布局模块
│       ├── global-header/       #       顶栏
│       ├── global-sider/        #       侧栏
│       ├── global-menu/         #       菜单
│       ├── global-tab/          #       标签页
│       ├── global-content/      #       内容区
│       └── theme-drawer/        #       主题设置
│
├── components/                  #   公共组件
│   ├── advanced/                #     高级组件
│   ├── common/                  #     通用组件
│   └── custom/                  #     自定义组件
│
├── router/                      #   路由配置
│   ├── elegant/                 #     自动路由
│   ├── guard/                   #     路由守卫
│   └── routes/                  #     路由定义
│
├── utils/                       #   工具函数
│   ├── common.ts                #     通用工具
│   ├── menu-bridge.ts           #     工作台桥接
│   ├── performance-trace.ts     #     性能追踪
│   ├── logger.ts                #     日志
│   └── storage.ts               #     本地存储
│
├── styles/                      #   全局样式
├── typings/                     #   TypeScript 类型
├── locales/                     #   国际化
└── theme/                       #   主题预设
```

## 核心模块说明

### 工作台（menu-bridge）

系统核心模块，通过后端元数据配置驱动，动态渲染 AG Grid 表格。

- **入口**：`menu-bridge/index.vue` 接收路由参数 `functionCode`
- **工作台**：`generic-query-workbench.vue` 整合数据查询、编辑、导入导出、图表
- **Hooks**：`use-workbench-*.ts` 系列（20+ hooks）拆分各功能逻辑
- **组件**：`modules/components/` 下的 Workbench* 组件

### 数据匹配（match-data）

A/B 双表匹配工具，支持候选高亮、建立/撤销匹配关系。

- **状态管理**：`use-match-store.ts`
- **表格**：`MatchTablePanel.vue`（AG Grid 外部筛选）
- **操作栏**：`MatchBottomBar.vue`（匹配条件、显示筛选、建立/撤销）

### 认证流程

```
登录 → auth.ts → store/auth → 存 token 到 LocalStorage
请求 → request/index.ts → Axios 拦截器注入 Bearer Token
过期 → refreshToken 自动续签
登出 → 清除 token + 调用后端黑名单
```

## 环境变量

| 文件 | 用途 |
|------|------|
| `.env` | 公共环境变量 |
| `.env.test` | 测试环境（`pnpm dev` 默认） |
| `.env.prod` | 生产环境 |

关键变量：
- `VITE_API_BASE_URL`：后端 API 地址
- `VITE_APP_TITLE`：应用标题

## 常用命令

```bash
pnpm dev              # 启动开发服务器（test 环境）
pnpm dev:prod         # 启动开发服务器（prod 环境）
pnpm build            # 生产构建
pnpm build:test       # 测试环境构建
pnpm preview          # 预览构建产物
pnpm lint             # 代码检查 + 自动修复
pnpm typecheck        # TypeScript 类型检查
pnpm gen-route        # 生成路由文件
```
