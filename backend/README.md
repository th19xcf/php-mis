# MIS 后端服务

基于 CodeIgniter 4 + PHP 8.1+ 的管理信息系统后端。

## 环境要求

| 软件 | 版本 |
|------|------|
| PHP | 8.1+ |
| Composer | 2.9+ |
| MySQL | 8.0+（远程） |
| Apache | 2.4+ |

### PHP 扩展依赖

- `mysqli`（数据库连接）
- `mbstring`（多字节字符串）
- `json`
- `fileinfo`（文件类型检测，可选，有扩展名回退）

## 快速开始

```bash
# 1. 安装依赖
cd d:\code\php\mis\backend
composer install

# 2. 配置环境变量
cp env .env
# 编辑 .env 配置数据库连接信息

# 3. 启动开发服务器
php spark serve --port 8080
```

## 目录结构

```
app/
├── Config/                  # 配置文件
│   ├── Routes.php           #   路由定义（所有 API 路由在此注册）
│   ├── Database.php         #   数据库连接配置
│   ├── Cache.php            #   缓存配置（FileHandler）
│   ├── Filters.php          #   过滤器注册（JwtAuthFilter）
│   ├── Cors.php             #   跨域配置
│   └── TokenBlacklist.php   #   Token 黑名单配置
│
├── Controllers/             # 控制器（HTTP 入口）
│   ├── Auth.php             #   认证：login/logout/getUserInfo/refreshToken
│   ├── BaseApiController.php #  API 基类（AuthorizationService 单例等）
│   ├── Workbench.php        #   工作台入口控制器
│   ├── Workbench/           #   工作台子控制器
│   │   ├── WorkbenchChartController.php   # 图表
│   │   ├── WorkbenchEditController.php    # 编辑
│   │   ├── WorkbenchImportController.php  # 导入
│   │   └── WorkbenchPopupController.php   # 弹窗
│   ├── MatchApi.php         #   数据匹配（建立/撤销匹配关系）
│   ├── CacheController.php  #   缓存管理（invalidate/status）
│   ├── Route.php            #   用户路由/菜单
│   ├── Comment.php          #   批注
│   ├── DeptApi.php          #   部门管理
│   ├── EmployeeApi.php      #   员工管理
│   ├── ContractApi.php      #   合同管理
│   ├── InterviewApi.php     #   面试管理
│   ├── InvitationApi.php    #   邀请管理
│   └── TrainApi.php         #   培训管理
│
├── Models/                  # 数据模型
│   ├── AuthModel.php        #   认证（用户验证、角色查询、菜单数据）
│   └── Mcommon.php          #   通用模型（SQL 执行、请求级缓存、sql_log）
│
├── Libraries/               # 自定义类库
│   ├── AuthorizationService.php # 权限服务：加载赋权字段、批量加载
│   ├── MetadataCache.php    #   元数据缓存：表级 TTL + 反向索引精准删除
│   ├── JwtTokenService.php  #   JWT 生成/验证/解码
│   ├── SessionUserContext.php # 用户上下文（从 JWT 注入，非 Session）
│   ├── TokenBlacklistService.php # Token 黑名单（登出失效）
│   ├── ContextCacheService.php # 上下文缓存
│   └── ApiExceptionHandler.php # 全局异常处理（标准化 JSON 错误）
│
├── Services/                # 业务服务层
│   ├── Workbench/           #   工作台业务
│   │   ├── ContextService.php    # 上下文：列配置加载
│   │   ├── QueryService.php      # 数据查询（含 SQL 超时 30s）
│   │   ├── EditService.php       # 编辑服务
│   │   ├── BatchEditService.php  # 批量编辑
│   │   ├── ImportService.php     # Excel 导入（字段长度校验）
│   │   ├── ExportService.php     # Excel 导出
│   │   ├── ChartService.php      # 图表数据
│   │   ├── ChartDrillService.php # 图表钻取
│   │   ├── PopupService.php      # 弹窗选择配置
│   │   └── FieldConfigService.php # 字段配置
│   ├── RouteService.php     #   路由/菜单
│   └── Employee/EmployeeService.php
│
├── Filters/
│   └── JwtAuthFilter.php    #   JWT 认证过滤器（全局拦截）
│
├── Exceptions/              # 自定义异常
│   ├── AuthException.php    #   认证异常
│   ├── BusinessException.php #  业务异常
│   └── ValidationException.php # 验证异常
│
├── Constants/
│   └── ApiCode.php          #   API 状态码枚举
│
└── Traits/
    ├── AuditFieldsTrait.php      # 审计字段
    └── ChartColumnConfigTrait.php # 图表列配置
```

## 关键服务说明

### 认证流程

```
请求 → JwtAuthFilter → SessionUserContext::setJwtUser()
     → Controller → 业务逻辑
```

- **登录**：`Auth::login()` → `AuthModel::verifyUser()` → 计算赋权 → 生成 JWT
- **鉴权**：`JwtAuthFilter` 拦截除 `auth/login`、`auth/refreshToken` 外的所有路由
- **用户信息**：通过 `SessionUserContext`（非 Session 直接访问），从 JWT payload 读取

### 元数据缓存（MetadataCache）

- 基于 **FileHandler**（非 Redis），通过 `Config\Cache` 配置
- 按表名设置不同 TTL（3600s-86400s）
- 通过反向索引实现精准删除（`invalidateTable`），避免全站冷启动
- 缓存失效 API：`POST /cache/invalidate-table`

### 通用模型（Mcommon）

- `select($sql)`：执行 SQL 查询，支持请求级静态缓存（`$requestCache`）
- `quote($value)`：SQL 值转义
- `sql_log($action, $sql, $remark)`：SQL 审计日志

### API 响应规范

```php
// 主业务 API
$this->success($data, 'msg');    // {code: "0000", data: {...}, msg: "msg"}
$this->businessError('msg');     // {code: "B0001", msg: "msg"}

// 外部/Demo 服务
{status: "200", result: {...}, message: "msg"}
```

## 配置文件说明

| 文件 | 用途 |
|------|------|
| `.env` | 环境变量（数据库、CI_ENVIRONMENT）— 不纳入 Git |
| `.env.example` | 环境变量模板 |
| `app/Config/Routes.php` | API 路由定义 |
| `app/Config/Database.php` | 数据库连接（从 .env 读取） |
| `app/Config/Cache.php` | 缓存驱动配置（默认 file） |
| `app/Config/Filters.php` | 过滤器注册（JwtAuthFilter） |

## 常用命令

```bash
# 启动开发服务器
php spark serve --port 8080

# 运行测试
composer test

# 生产部署优化
composer install --optimize-autoloader --no-dev
```
