# 管理信息系统（MIS）架构搭建方案

## 一、技术栈概览

| 层级 | 技术选型 | 版本要求 |
|------|---------|---------|
| 前端框架 | Vue3 + SoybeanAdmin | Vue 3.4+, Element-Plus 2.5+ |
| 后端框架 | PHP 8.2+ + CodeIgniter 4.5+ | PHP 8.2+, CI4.5+ |
| 数据库 | MySQL 8.0+ (远程服务器) | MySQL 8.0+ |
| Web服务器 | Apache 2.4+ (Windows) | Apache 2.4+ |
| 构建工具 | Vite 5+, pnpm 8+ | Vite 5+, pnpm 8+ |

***

## 二、技术栈兼容性分析

### 2.1 前端技术兼容性

- **Vue3**: 现代前端框架，与SoybeanAdmin完美集成，支持Composition API
- **SoybeanAdmin**: 基于Vue3 + Element-Plus，开源免费，UI组件丰富
- **Vite**: 快速的开发服务器和构建工具，与Vue3深度集成
- **Axios**: SoybeanAdmin内置HTTP请求库，开箱即用
- **兼容性评估**: ✅ 完全兼容，推荐使用

### 2.2 HTTP请求库说明：使用 Axios（不用 Alova）

- **选择理由**: SoybeanAdmin 原生集成 Axios，开箱即用，无需额外替换
- **Alova**: 不引入，作为未来优化选项

### 2.3 后端技术兼容性

- **PHP 8.5+**: 最新稳定版本，性能大幅提升，类型系统完善
- **CodeIgniter 4.5+**: 轻量级PHP框架，学习曲线平缓，文档完善
- **Composer**: PHP依赖管理工具，与CI4无缝集成
- **兼容性评估**: ✅ 完全兼容，推荐使用

### 2.4 数据库兼容性

- **MySQL 8.0+**: 远程服务器部署，支持JSON、窗口函数等新特性
- **PDO/MySQLi**: CodeIgniter内置支持，连接远程MySQL无问题
- **兼容性评估**: ✅ 完全兼容

### 2.5 Web服务器兼容性

- **Apache 2.4+**: 已有环境，直接使用
- **mod_rewrite**: 已有环境支持
- **备注**: Apache 环境已就绪，**无需额外配置**

***

## 三、前后端分离架构设计

### 3.1 系统架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                        客户端 (Browser)                         │
│                    Vue3 + SoybeanAdmin                          │
└─────────────────────────────────────────────────────────────────┘
                                │
                                │ HTTP/HTTPS (RESTful API)
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
                        ┌───────────────────────────────┐
                        │       MySQL 8.0+              │
                        │     (远程数据库服务器)          │
                        │   端口: 3306                   │
                        └───────────────────────────────┘
```

### 3.2 前后端分离通信机制

- **CORS跨域配置**: 后端API配置允许跨域访问
- **JWT认证**: 无状态令牌认证，前后端分离标准方案
- **HTTP协议**: JSON格式数据交换

***

## 四、项目目录结构规划

### 4.1 整体目录结构

```
d:\code\php\mis/
├── backend/                    # 后端项目目录
│   ├── app/                   # 应用目录
│   │   ├── Controllers/       # 控制器
│   │   ├── Models/            # 数据模型
│   │   ├── Views/             # 视图 (如需模板)
│   │   ├── Config/            # 配置文件
│   │   ├── Filters/           # 过滤器 (认证)
│   │   ├── Helpers/           # 辅助函数
│   │   ├── Libraries/         # 自定义类库
│   │   └── ThirdParty/        # 第三方库
│   ├── public/                # Web根目录
│   │   ├── index.php          # 入口文件
│   │   └── .htaccess          # Apache重写规则
│   ├── system/                # CI4框架目录
│   ├── writable/              # 可写目录 (缓存、日志、上传)
│   ├── tests/                 # 测试目录
│   ├── composer.json          # PHP依赖
│   ├── .env                   # 环境配置
│   └── phpunit.xml            # 单元测试配置
│
├── frontend/                  # 前端项目目录
│   ├── soybean-admin/         # SoybeanAdmin项目
│   │   ├── src/
│   │   │   ├── api/           # API接口封装
│   │   │   ├── assets/        # 静态资源
│   │   │   ├── components/    # 公共组件
│   │   │   ├── composables/   # 组合式函数
│   │   │   ├── directives/    # 自定义指令
│   │   │   ├── layouts/       # 布局组件
│   │   │   ├── router/        # 路由配置
│   │   │   ├── stores/        # 状态管理
│   │   │   ├── styles/        # 全局样式
│   │   │   ├── types/         # TypeScript类型
│   │   │   ├── utils/         # 工具函数
│   │   │   ├── views/         # 页面组件
│   │   │   ├── App.vue        # 根组件
│   │   │   └── main.ts        # 入口文件
│   │   ├── .env.*             # 环境变量
│   │   ├── index.html         # HTML模板
│   │   ├── package.json       # npm依赖
│   │   ├── tsconfig.json      # TS配置
│   │   ├── vite.config.ts     # Vite配置
│   │   └── ...
│   │
├── docs/                      # 项目文档
│   ├── api/                   # API文档
│   │   └── *.md
│   └── architecture/          # 架构文档
│
└── README.md                  # 项目说明
```

### 4.2 后端详细目录结构

```
backend/
├── app/
│   ├── Controllers/
│   │   ├── Api/               # API控制器
│   │   │   ├── AuthController.php
│   │   │   ├── UserController.php
│   │   │   └── SystemController.php
│   │   └── BaseController.php
│   │
│   ├── Models/
│   │   ├── UserModel.php
│   │   └── BaseModel.php
│   │
│   ├── Config/
│   │   ├── App.php            # 应用配置
│   │   ├── Database.php       # 数据库配置
│   │   ├── Auth.php           # 认证配置
│   │   └── CORS.php           # 跨域配置
│   │
│   ├── Filters/
│   │   ├── AuthFilter.php     # 认证过滤器
│   │   └── CORSFilter.php     # 跨域过滤器
│   │
│   └── Libraries/
│       ├── JwtLib.php         # JWT库
│       └── ResponseLib.php    # 响应封装
│
└── public/
    └── index.php
```

***

## 五、API接口规范制定

### 5.1 RESTful API设计规范

#### 基础规范

- **协议**: HTTPS (生产环境)
- **编码**: UTF-8
- **内容类型**: application/json
- **认证方式**: Bearer Token (JWT)

#### URL规范

```
# 格式
https://api.example.com/v{version}/{module}/{resource}

# 示例
POST   /v1/auth/login          # 用户登录
POST   /v1/auth/logout          # 用户登出
GET    /v1/users                # 获取用户列表
GET    /v1/users/{id}           # 获取单个用户
POST   /v1/users                # 创建用户
PUT    /v1/users/{id}           # 更新用户
DELETE /v1/users/{id}           # 删除用户
```

#### 响应格式规范

**成功响应**

```json
{
  "code": 200,
  "message": "success",
  "data": { ... },
  "timestamp": 1713400000
}
```

**错误响应**

```json
{
  "code": 400,
  "message": "参数错误",
  "errors": {
    "field": ["错误信息"]
  },
  "timestamp": 1713400000
}
```

#### 状态码规范

| 状态码 | 说明 |
|--------|------|
| 200 | 成功 |
| 201 | 创建成功 |
| 400 | 请求参数错误 |
| 401 | 未授权/认证失败 |
| 403 | 禁止访问 |
| 404 | 资源不存在 |
| 422 | 数据验证失败 |
| 500 | 服务器内部错误 |

### 5.2 核心API接口设计

```php
// 认证模块
POST   /v1/auth/login         # 用户登录，返回JWT
POST   /v1/auth/logout        # 登出
GET    /v1/auth/me            # 获取当前用户信息
POST   /v1/auth/refresh       # 刷新令牌

// 用户模块
GET    /v1/users              # 用户列表 (分页)
GET    /v1/users/{id}         # 用户详情
POST   /v1/users              # 创建用户
PUT    /v1/users/{id}         # 更新用户
DELETE /v1/users/{id}         # 删除用户

// 系统模块
GET    /v1/system/menu        # 获取菜单
GET    /v1/system/config      # 获取系统配置
GET    /v1/system/dict/{type} # 获取字典数据
```

***

## 六、数据交互流程设计

### 6.1 用户认证流程

```
┌────────┐     1.登录请求      ┌────────────┐
│  前端  │ ─────────────────▶ │  后端API   │
│        │                    │            │
│        │  2.验证用户         │ 验证用户名  │
│        │ ◀───────────────── │ 密码       │
│        │                    │            │
│        │  3.生成JWT          │            │
│        │ ◀───────────────── │ 返回Token  │
└────────┘                    └────────────┘
     │
     │ 存储Token到LocalStorage
     │
     ▼
┌────────┐
│ 请求    │
│ 其他API │ ──携带Token──▶ 验证Token ──▶ 业务处理
└────────┘
```

### 6.2 前后端数据交互流程

```
┌──────────────────────────────────────────────────────────────┐
│                        前端 (Vue3)                            │
│  ┌──────────┐    ┌──────────┐    ┌──────────┐               │
│  │ Pinia    │    │  Axios   │    │ Router   │               │
│  │ Store    │◀───│ HTTP     │◀───│ Guard    │               │
│  └──────────┘    └──────────┘    └──────────┘               │
└────────────────────────────┬────────────────────────────────┘
                             │ HTTP Request (JSON)
                             ▼
┌──────────────────────────────────────────────────────────────┐
│                      Apache反向代理                           │
│                  (80端口 → 8080后端)                          │
└────────────────────────────┬────────────────────────────────┘
                             │ HTTP Request
                             ▼
┌──────────────────────────────────────────────────────────────┐
│                     后端 (CodeIgniter)                        │
│  ┌──────────┐    ┌──────────┐    ┌──────────┐               │
│  │ Filter   │───▶│Controller│───▶│ Model    │               │
│  │ 认证/跨域 │    │ 业务处理  │    │ 数据操作  │               │
│  └──────────┘    └──────────┘    └──────────┘               │
└────────────────────────────┬────────────────────────────────┘
                             │ SQL
                             ▼
┌──────────────────────────────────────────────────────────────┐
│                    MySQL (远程服务器)                          │
│                     端口: 3306                                │
└──────────────────────────────────────────────────────────────┘
```

***

## 七、开发环境配置指南

### 7.1 已有环境确认

| 软件 | 版本 | 状态 |
|------|------|------|
| Apache | 2.4.66 | ✅ 已有，直接使用 |
| PHP | 8.5.3 | ✅ 已有，直接使用 |
| Node.js | 24.14.x | ✅ 已有，直接使用 |
| npm | 11.11+ | ✅ 已有，直接使用 |
| Composer | 2.9.5 | ✅ 已有，直接使用 |
| MySQL | 8.0+ | ⚠️ 远程服务器，无需本地安装 |

**备注**: Apache 环境已就绪，**无需额外配置**。

### 7.2 环境准备清单

#### 7.2.1 安装 pnpm

SoybeanAdmin 推荐使用 pnpm 作为包管理器：

```bash
# 全局安装 pnpm
npm install -g pnpm

# 验证安装
pnpm -v
```

#### 7.2.2 配置远程MySQL

在 `backend/.env` 中配置远程数据库：

```env
database.default.hostname = 远程服务器IP
database.default.database = mis_db
database.default.username = mis_user
database.default.password = your_password
database.default.port = 3306
```

### 7.3 后端项目搭建

```bash
# 1. 创建CodeIgniter项目
cd d:\code\php\mis\backend
composer create-project codeigniter4/appstarter . "4.5.*"

# 2. 安装必要依赖
composer require codeigniter4/translations    # 中文语言包
composer require firebase/php-jwt              # JWT支持

# 3. 配置环境变量
cp env .env
```

### 7.4 前端项目搭建

```bash
# 1. 克隆SoybeanAdmin (Gitee，国内推荐)
cd d:\code\php\mis\frontend
git clone https://gitee.com/honghuangdc/soybean-admin.git

# 2. 安装依赖 (使用 pnpm)
cd soybean-admin
pnpm install

# 3. 配置API地址 (.env.development)
VITE_API_BASE_URL=http://localhost:8080/api
VITE_APP_TITLE=MIS管理信息系统

# 4. 启动开发服务器
pnpm dev
```

### 7.5 开发工具推荐

| 用途 | 工具 | 说明 |
|------|------|------|
| IDE | VS Code | 轻量级，免费 |
| API测试 | Apifox / Postman | API调试 |
| 数据库管理 | Navicat / DBeaver | MySQL客户端 |
| Git客户端 | Git | 版本控制 |
| 终端 | Windows Terminal | 现代化终端 |

***

## 八、部署流程设计

### 8.1 生产环境架构

```
┌─────────────────────────────────────────────────────────────┐
│                      用户浏览器                              │
└─────────────────────────────┬───────────────────────────────┘
                              │ HTTPS
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    Nginx/Apache (80/443)                     │
│                   负载均衡 + SSL证书                         │
└─────────────────────────────┬───────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              │               │               │
              ▼               ▼               ▼
        ┌──────────┐   ┌──────────┐   ┌──────────┐
        │ App Server│   │ App Server│   │ App Server│
        │   Node1   │   │   Node2   │   │   Node3   │
        └──────────┘   └──────────┘   └──────────┘
```

### 8.2 部署步骤

#### 8.2.1 前端部署

```bash
# 1. 构建生产版本
cd frontend/soybean-admin
pnpm build

# 2. 产物目录
# dist/ 目录下的文件部署到Apache
```

#### 8.2.2 后端部署

```bash
# 1. 依赖安装
cd backend
composer install --optimize-autoloader --no-dev

# 2. 配置生产环境
# - 设置 .env CI_ENVIRONMENT = production
# - 关闭调试模式 app_debug = false
```

***

## 九、性能优化策略

### 9.1 前端性能优化

| 优化项 | 实现方式 | 预期效果 |
|--------|---------|---------|
| 代码分割 | Vite自动拆分 | 减少首屏加载时间 |
| 懒加载路由 | Vue Router | 按需加载页面 |
| 组件缓存 | Keep-Alive | 减少组件重复渲染 |
| 图片优化 | 压缩 + CDN | 减少资源体积 |
| Gzip压缩 | 服务器配置 | 减少传输体积 |
| 请求缓存 | Axios拦截器 | 减少重复请求 |
| 错误重试 | Axios拦截器 | 提高请求成功率 |

### 9.2 后端性能优化

| 优化项 | 实现方式 | 预期效果 |
|--------|---------|---------|
| 数据库索引 | 合理建立索引 | 查询速度提升 |
| 查询缓存 | Redis缓存 | 减少数据库压力 |
| 响应压缩 | Apache gzip | 减少传输体积 |
| OPcache | PHP内置扩展 | 代码执行加速 |
| 连接池 | 数据库连接池 | 减少连接开销 |

### 9.3 数据库优化 (远程MySQL)

```sql
-- 定期分析表
ANALYZE TABLE users;

-- 优化查询
EXPLAIN SELECT * FROM users WHERE status = 1;

-- 建立合适索引
CREATE INDEX idx_status ON users(status);
CREATE INDEX idx_created_at ON users(created_at);
```

### 9.4 缓存策略

```
┌─────────────────────────────────────────────────┐
│                   请求流程                        │
├─────────────────────────────────────────────────┤
│  1. 检查Redis缓存                                │
│  2. 缓存命中 → 直接返回                          │
│  3. 缓存未命中 → 查询MySQL                       │
│  4. 写入Redis缓存                                │
│  5. 返回数据                                    │
└─────────────────────────────────────────────────┘

# 缓存过期时间建议
- 用户会话: 2小时
- 菜单数据: 24小时
- 字典数据: 24小时
- 配置信息: 1小时
```

***

## 十、版本清单 (最新稳定版)

| 软件/包 | 版本 | 说明 |
|---------|------|------|
| PHP | 8.5.3 | 最新稳定版 |
| Composer | 2.9+ | PHP包管理 |
| CodeIgniter | 4.7.0 | 最新稳定版 |
| Node.js | 24.14.x | 长期支持版 |
| pnpm | 8.7+ | Node包管理 |
| Vue | 3.5.32 | 最新稳定版 |
| Vite | 5.2+ | 最新稳定版 |
| Element-Plus | 2.7+ | 最新稳定版 |
| SoybeanAdmin | 2.5.1 | 最新稳定版 |
| MySQL | 8.0.36 | 最新稳定版 |
| Apache | 2.4.66 | 最新稳定版 |

***

## 十一、风险评估与解决方案

| 风险项 | 风险等级 | 应对方案 |
|--------|---------|---------|
| 远程数据库延迟 | 中 | 引入Redis缓存层，减少数据库查询 |
| Apache并发限制 | 低 | 配置KeepAlive，优化MPM模块 |
| 前端SEO问题 | 低 | MIS系统对SEO要求低，可忽略 |
| PHP内存限制 | 中 | 合理使用懒加载，避免大数组操作 |
| 跨域访问 | 低 | 后端配置CORS过滤器 |

***

## 十二、后续开发建议

1. **规范化开发流程**: 制定Git分支管理规范
2. **接口文档**: 使用Swagger/Scribe生成API文档
3. **代码规范**: 引入ESLint + PHPStan + StyleCI
4. **自动化测试**: 前后端分别配置单元测试
5. **CI/CD**: 配置GitHub Actions/Gitea Actions自动化部署
