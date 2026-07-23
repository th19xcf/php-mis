# OnlyOffice 集成技术方案

> 编制日期：2026-07-23
> 适用范围：`e:\code\php\mis`（backend: CodeIgniter 4.7.2 / PHP 8.5.4；frontend: Vue 3 + Vite + Soybean Admin）
> 文档定位：合同管理模块系统性升级实施计划 阶段 5（OnlyOffice 应用层集成）的设计依据与实施指南
> 前置文档：[合同管理模块系统性升级实施计划.md](file:///e:/code/php/mis/.trae/documents/合同管理模块系统性升级实施计划.md)

---

## 目录

- [一、引言](#一引言)
- [二、集成架构](#二集成架构)
- [三、部署前提](#三部署前提)
- [四、数据交互流程](#四数据交互流程)
- [五、JWT 签名与验签机制](#五jwt-签名与验签机制)
- [六、编辑器配置详解](#六编辑器配置详解)
- [七、回调状态处理](#七回调状态处理)
- [八、版本管理机制](#八版本管理机制)
- [九、操作留痕设计](#九操作留痕设计)
- [十、异常处理机制](#十异常处理机制)
- [十一、安全设计](#十一安全设计)
- [十二、性能优化](#十二性能优化)
- [十三、与现有系统集成点](#十三与现有系统集成点)
- [十四、测试策略](#十四测试策略)
- [十五、部署与运维](#十五部署与运维)

---

## 一、引言

### 1.1 目的

本方案描述 MIS 系统（合同管理模块）集成 OnlyOffice Document Server 实现 Office 文档在线协同编辑、版本管理、操作留痕的整体技术方案。范围限定于"应用层集成"，即：

- 不包含 OnlyOffice Document Server 本身的部署与运维；
- 仅实现前端编辑器组件、后端 JWT 签名/验签、回调处理、版本快照、操作留痕、权限控制等应用层逻辑；
- 与合同模块、权限系统、审计日志、缓存系统的对接方式也在此明确。

### 1.2 范围

| 范围内 | 范围外 |
|---|---|
| 后端 `app/Libraries/OnlyOffice/`、`app/Services/OnlyOffice/`、`app/Controllers/OnlyOfficeApi.php` | OnlyOffice Document Server 部署、Docker 镜像、Apache 反代配置 |
| 前端 `components/onlyoffice/OnlyOfficeEditor.vue` 通用组件 | OnlyOffice 源码改造、插件开发 |
| 数据库表 `def_contract_doc`、`def_contract_doc_version`、`def_contract_doc_op_log`、`def_doc_callback_log` | 文档转换服务（Document Converter）单独调用 |
| JWT 签名/验签、回调幂等、版本快照、操作留痕 | 移动端适配（仅 PC 端） |
| 与权限系统/审计日志/缓存系统整合 | 数字签名、电子签章（仅在线编辑与版本管理） |

### 1.3 OnlyOffice Document Server 简介

OnlyOffice Document Server 是 Ascensio System SIA 出品的在线办公套件服务端，提供文本文档（Word）、电子表格（Excel）、演示文稿（PowerPoint）的在线编辑、协同、审阅、表单填写能力。核心特性：

- **协同编辑**：多用户实时同步，基于 OT（Operational Transformation）算法；
- **格式兼容**：原生支持 OOXML（docx/xlsx/pptx），同时支持 odt/ods/odp/csv/rtf/html/txt；
- **版本历史**：通过回调机制保存版本快照，编辑器内置版本对比组件；
- **JWT 安全**：支持 HS256 JWT 签名，保证配置不可篡改、回调可验签；
- **回调机制**：编辑完成、强制保存、关闭文档等事件由 Document Server 主动回调后端；
- **集成方式**：前端通过 `<script src="ONLYOFFICE_URL/web-apps/apps/api/documents/api.js">` 加载 `DocsAPI`，调用 `new DocsAPI.DocEditor(elementId, config)` 初始化。

### 1.4 参考资料

- OnlyOffice 官方 API 文档：[https://api.onlyoffice.com/editors/](https://api.onlyoffice.com/editors/)
- 配置参数：[https://api.onlyoffice.com/editors/config/](https://api.onlyoffice.com/editors/config/)
- 回调处理器：[https://api.onlyoffice.com/editors/callback](https://api.onlyoffice.com/editors/callback)
- JWT 签名：[https://api.onlyoffice.com/editors/signature/](https://api.onlyoffice.com/editors/signature/)
- 版本历史：[https://api.onlyoffice.com/editors/history](https://api.onlyoffice.com/editors/history)
- 权限：[https://api.onlyoffice.com/editors/config/permissions](https://api.onlyoffice.com/editors/config/permissions)
- 协同编辑：[https://api.onlyoffice.com/editors/coedit](https://api.onlyoffice.com/editors/coedit)
- 强制保存：[https://api.onlyoffice.com/editors/save/forcesave](https://api.onlyoffice.com/editors/save/forcesave)

---

## 二、集成架构

### 2.1 整体架构

```mermaid
flowchart LR
    subgraph Browser[用户浏览器]
        Vue[Vue3 编辑器组件<br/>OnlyOfficeEditor.vue]
        DocsAPI[DocsAPI<br/>动态加载 api.js]
    end

    subgraph DocServer[OnlyOffice Document Server]
        EditorCore[编辑器内核]
        CoEdit[协同引擎]
        CallbackHandler[回调触发器]
        StorageDS[文档暂存]
    end

    subgraph MISBackend[MIS 后端 CI4]
        OnlyOfficeApi[OnlyOfficeApi 控制器]
        ConfigBuilder[OnlyOfficeConfigBuilder]
        JwtService[OnlyOfficeJwtService]
        CallbackService[OnlyOfficeCallbackService]
        VersionService[OnlyOfficeVersionService]
        DownloadService[OnlyOfficeDownloadService]
        DB[(MySQL<br/>def_contract_doc<br/>def_contract_doc_version<br/>def_doc_callback_log)]
        FileStore[文件存储<br/>ONLYOFFICE_DOC_STORAGE_PATH]
    end

    Vue -->|1. 请求编辑器配置| OnlyOfficeApi
    OnlyOfficeApi --> ConfigBuilder
    ConfigBuilder --> JwtService
    JwtService -->|签名| OnlyOfficeApi
    OnlyOfficeApi -->|2. 返回带 JWT 配置| Vue
    Vue --> DocsAPI
    DocsAPI -->|3. 初始化 DocEditor| EditorCore
    EditorCore -->|4. 请求文档内容| DownloadService
    DownloadService -->|5. 返回文件流| EditorCore
    EditorCore --> CoEdit
    CoEdit -->|协同同步| EditorCore
    CallbackHandler -->|6. 回调保存| CallbackService
    CallbackService -->|验签| JwtService
    CallbackService -->|下载新版本| VersionService
    VersionService --> FileStore
    VersionService --> DB
    CallbackService --> DB
    CallbackService -->|7. 返回 error:0| CallbackHandler
```

### 2.2 组件职责划分

| 组件 | 位置 | 职责 | 依赖 |
|---|---|---|---|
| `OnlyOfficeEditor.vue` | 前端通用组件 | 动态加载 `api.js`、初始化 `DocsAPI.DocEditor`、监听编辑器事件、提供版本/留痕面板入口 | `@/service/api/onlyoffice` |
| `fetchEditorConfig` | 前端 API | 调用 `GET /onlyoffice/config/{docId}` 获取带 JWT 的配置 | axios |
| `OnlyOfficeApi` | 后端控制器 | 入口路由分发（config/callback/download/history/history-data）、参数校验、响应封装 | Service 层 |
| `OnlyOfficeConfigBuilder` | 后端 Library | 构建 `documentType`/`document`/`editorConfig`/`permissions`/`customization` 配置 | `AuthorizationService`、`OnlyOfficeConfig` |
| `OnlyOfficeJwtService` | 后端 Library | HS256 签名（用于配置）+ 验签（用于回调） | `ONLYOFFICE_JWT_SECRET` |
| `OnlyOfficeCallbackService` | 后端 Service | 处理 status=0/1/2/3/4/6/7 回调、幂等校验、调度版本创建与留痕 | `OnlyOfficeJwtService`、`OnlyOfficeVersionService`、`OnlyOfficeDownloadService` |
| `OnlyOfficeVersionService` | 后端 Service | 版本快照创建、版本标记、版本回溯、版本清理 | `def_contract_doc_version`、文件存储 |
| `OnlyOfficeDownloadService` | 后端 Service | 文档下载（含权限校验、签名校验、流式输出） | `AuthorizationService`、`OnlyOfficeJwtService` |
| `OnlyOfficeConfig` | 后端 Config | 封装 `.env` 读取（URL/JWT_SECRET/CALLBACK_BASE_URL/STORAGE_PATH） | CI4 Config |

### 2.3 网络拓扑

```mermaid
flowchart TB
    subgraph UserNet[用户网络]
        UserBrowser[用户浏览器]
    end

    subgraph MISNet[MIS 系统网络]
        MISBackendNode[MIS 后端<br/>CI4 + Apache + PHP<br/>Windows Server]
        MySQLNode[(MySQL)]
        FileStorageNode[文件存储<br/>本地磁盘/NAS]
    end

    subgraph OnlyOfficeNet[OnlyOffice 网络域]
        DocServerNode[Document Server<br/>监听 80/443]
    end

    UserBrowser -->|HTTPS<br/>前端页面 + api.js| DocServerNode
    UserBrowser -->|HTTPS<br/>REST API| MISBackendNode
    DocServerNode -->|HTTP/HTTPS<br/>下载文档 url| MISBackendNode
    DocServerNode -->|HTTP/HTTPS<br/>POST callback| MISBackendNode
    MISBackendNode -->|TCP 3306| MySQLNode
    MISBackendNode -->|读写文件| FileStorageNode
    MISBackendNode -.可选.<- DocServerNode
```

**网络互通要求**（关键）：

| 方向 | 协议 | 端口 | 用途 | 必需性 |
|---|---|---|---|---|
| 用户浏览器 → Document Server | HTTPS | 443 | 加载 api.js、WebSocket 协同 | 必需 |
| 用户浏览器 → MIS 后端 | HTTPS | 443 | 获取配置、版本历史、留痕 | 必需 |
| Document Server → MIS 后端 | HTTP/HTTPS | 80/443 | 下载文档（document.url）、回调（callbackUrl） | 必需 |
| MIS 后端 → Document Server | HTTP/HTTPS | 80/443 | 可选：健康检查、服务端代理 api.js | 可选 |

**关键约束**：Document Server 与 MIS 后端必须**双向网络可达**。Document Server 需要能访问 MIS 后端的 `/onlyoffice/download`（拉取文档内容）与 `/onlyoffice/callback`（推送保存事件）。若 Document Server 部署在公网而 MIS 后端在内网，需通过反向代理或 VPN 打通。

---

## 三、部署前提

### 3.1 OnlyOffice Document Server 部署要求

| 项 | 要求 | 说明 |
|---|---|---|
| 版本 | 8.0+ | 建议使用 LTS 版本；旧版本可能不支持部分 customization 字段 |
| 部署形态 | Docker / DEB/RPM 包 / Helm | 由运维负责，应用层不关心 |
| 域名/地址 | `ONLYOFFICE_URL` 配置 | 必须可被用户浏览器访问（HTTPS 推荐） |
| JWT 启用 | 必须开启 | 与 `ONLYOFFICE_JWT_SECRET` 保持一致，否则配置将被拒 |
| 协同服务端口 | 80/443 | 默认监听，需保证 WebSocket 升级可用 |
| 存储 | 内部存储 | 编辑中的临时文档由 Document Server 自行管理，应用层不参与 |
| 健康检查端点 | `/healthcheck` | 返回 `true` 表示服务可用 |

### 3.2 后端环境要求

| 项 | 要求 | 校验方式 |
|---|---|---|
| PHP 版本 | 8.5.4+（与项目一致） | `php -v` |
| PHP 扩展 | `openssl`、`curl`、`mbstring`、`json`、`fileinfo` | `php -m` |
| Composer 依赖 | `firebase/php-jwt` ^6.x | 用于 HS256 签名/验签；无则用 `hash_hmac` 自实现 |
| 文件存储路径 | `ONLYOFFICE_DOC_STORAGE_PATH` 可读写 | `is_writable()` |
| 网络可达 | 后端可访问 `ONLYOFFICE_URL/healthcheck` | `curl` |
| 网络可达 | Document Server 可访问后端 `/onlyoffice/download`、`/onlyoffice/callback` | 由运维确认 |

### 3.3 配置项清单

#### 3.3.1 后端 `.env` 配置

新增到 `backend/.env.production.example` 与 `backend/env`：

```ini
# ------------------------------------------------------------------
# OnlyOffice Document Server
# ------------------------------------------------------------------
# Document Server 访问地址（浏览器与后端均需可达）
ONLYOFFICE_URL=http://onlyoffice.example.com

# JWT 签名密钥（HS256），必须与 Document Server 的 jwt.secret 一致
ONLYOFFICE_JWT_SECRET=your-secret-key-please-change-in-production

# 后端回调基址（Document Server 必须可达），用于拼装 callbackUrl 与 download url
ONLYOFFICE_CALLBACK_BASE_URL=http://mis-backend.example.com

# forceSave 时是否创建版本快照（status=6）
ONLYOFFICE_VERSION_ON_FORCE_SAVE=true

# 文档版本快照存储路径（绝对路径或 WRITEPATH 相对路径）
ONLYOFFICE_DOC_STORAGE_PATH=WRITEPATH/uploads/onlyoffice/

# 回调地址白名单（逗号分隔的 IP/CIDR），留空则不校验
ONLYOFFICE_CALLBACK_WHITELIST=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16

# 文档下载链接有效期（秒）
ONLYOFFICE_DOWNLOAD_URL_TTL=300

# 版本保留数量（超过则清理最早版本，0 表示不清理）
ONLYOFFICE_VERSION_KEEP=50
```

#### 3.3.2 后端 `app/Config/OnlyOffice.php`

```php
<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class OnlyOffice extends BaseConfig
{
    /** Document Server 访问地址 */
    public string $url = '';
    /** JWT 签名密钥 */
    public string $jwtSecret = '';
    /** 回调基址 */
    public string $callbackBaseUrl = '';
    /** forceSave 是否创建版本 */
    public bool $versionOnForceSave = true;
    /** 文档存储路径 */
    public string $docStoragePath = '';
    /** 回调白名单 */
    public array $callbackWhitelist = [];
    /** 下载链接有效期（秒） */
    public int $downloadUrlTtl = 300;
    /** 版本保留数量 */
    public int $versionKeep = 50;

    public function __construct()
    {
        parent::__construct();
        $this->url               = env('ONLYOFFICE_URL', '');
        $this->jwtSecret         = env('ONLYOFFICE_JWT_SECRET', '');
        $this->callbackBaseUrl   = env('ONLYOFFICE_CALLBACK_BASE_URL', '');
        $this->versionOnForceSave= filter_var(env('ONLYOFFICE_VERSION_ON_FORCE_SAVE', 'true'), FILTER_VALIDATE_BOOL);
        $this->docStoragePath    = env('ONLYOFFICE_DOC_STORAGE_PATH', WRITEPATH . 'uploads/onlyoffice/');
        $this->callbackWhitelist = array_filter(explode(',', (string) env('ONLYOFFICE_CALLBACK_WHITELIST', '')));
        $this->downloadUrlTtl    = (int) env('ONLYOFFICE_DOWNLOAD_URL_TTL', 300);
        $this->versionKeep       = (int) env('ONLYOFFICE_VERSION_KEEP', 50);

        // 路径规整
        if (str_starts_with($this->docStoragePath, 'WRITEPATH')) {
            $this->docStoragePath = str_replace('WRITEPATH', WRITEPATH, $this->docStoragePath);
        }
        if (! str_ends_with($this->docStoragePath, DIRECTORY_SEPARATOR)) {
            $this->docStoragePath .= DIRECTORY_SEPARATOR;
        }
    }
}
```

#### 3.3.3 前端 `.env` 配置

`frontend/.env.production` 与 `.env.development` 新增：

```ini
# OnlyOffice Document Server 地址（浏览器加载 api.js 使用）
VITE_ONLYOFFICE_URL=http://onlyoffice.example.com
```

---

## 四、数据交互流程

### 4.1 打开文档流程

```mermaid
sequenceDiagram
    autonumber
    participant U as 用户浏览器
    participant V as OnlyOfficeEditor.vue
    participant B as MIS 后端 OnlyOfficeApi
    participant CB as OnlyOfficeConfigBuilder
    participant JWT as OnlyOfficeJwtService
    participant DS as Document Server

    U->>V: 点击"编辑合同文档"
    V->>V: 动态加载 api.js（按需）
    V->>B: GET /onlyoffice/config/{docId}<br/>Authorization: Bearer {userJwt}
    B->>B: 校验用户 JWT + 文档归属
    B->>CB: buildConfig(docId, userId, mode)
    CB->>CB: 查询 def_contract_doc + 当前版本
    CB->>CB: 生成 document.key（首次或版本变更）
    CB->>CB: 拼装 document.url（带签名 token）
    CB->>CB: 拼装 callbackUrl
    CB->>CB: 根据用户权限构建 permissions
    CB-->>B: 返回完整 config 数组
    B->>JWT: sign(config)
    JWT-->>B: token（HS256）
    B-->>V: 200 OK<br/>{config, token}
    V->>V: config.token = token
    V->>DS: new DocsAPI.DocEditor(el, config)
    DS->>DS: 校验 JWT 签名
    DS->>B: GET /onlyoffice/download?token={signedUrl}
    B->>B: 校验签名 + 权限 + TTL
    B-->>DS: 文件流（docx/xlsx/pptx）
    DS->>DS: 加载文档到编辑器内核
    DS-->>V: onDocumentReady
    V-->>U: 编辑器就绪
    B->>B: 写入 def_contract_doc_op_log（打开事件）
```

**关键点**：

1. `document.key` 是文档版本唯一标识，**首次打开时生成，版本变更后必须重新生成**，否则 Document Server 会从缓存返回旧内容；
2. `document.url` 必须由 Document Server 可达的地址（用 `ONLYOFFICE_CALLBACK_BASE_URL` 拼装），并附加签名 token 防盗链；
3. `config.token` 是整个 config 的 JWT 签名，前端不再单独传递各字段 token；
4. 用户 JWT 仅用于 `/onlyoffice/config` 鉴权，回调与下载走独立的 OnlyOffice JWT 验签。

### 4.2 协同编辑流程

```mermaid
sequenceDiagram
    autonumber
    participant U1 as 用户 A 浏览器
    participant U2 as 用户 B 浏览器
    participant DS as Document Server
    participant B as MIS 后端

    Note over U1,U2: 同一 document.key 进入协同
    U1->>DS: DocEditor(configA) user=A
    U2->>DS: DocEditor(configB) user=B
    DS->>DS: 同 key 检测到多连接<br/>建立协同会话
    DS-->>U1: 同步 B 在线 + 光标
    DS-->>U2: 同步 A 在线 + 光标
    U1->>DS: 输入字符
    DS->>DS: OT 变换
    DS-->>U2: 推送变更
    U2->>DS: 输入字符
    DS-->>U1: 推送变更
    DS->>B: POST /onlyoffice/callback<br/>status=1（编辑中），users=[A,B]
    B->>B: 记录 def_doc_callback_log
    B-->>DS: {"error":0}
    Note over DS: 协同期间文档暂存于 DS 内部
```

**关键点**：协同冲突由 Document Server 内部 OT 算法处理，应用层**不参与冲突解决**，仅记录协同开始/结束事件。

### 4.3 回调保存流程（status=2）

```mermaid
sequenceDiagram
    autonumber
    participant U as 用户浏览器
    participant DS as Document Server
    participant B as OnlyOfficeApi
    participant JWT as OnlyOfficeJwtService
    participant CB as OnlyOfficeCallbackService
    participant VS as OnlyOfficeVersionService
    participant FS as 文件存储
    participant DB as MySQL

    U->>DS: 关闭文档 / 触发保存
    DS->>DS: 收集所有未保存变更
    DS->>B: POST /onlyoffice/callback<br/>Authorization: Bearer {token}<br/>{key, status:2, url, users, ...}
    B->>JWT: verify(Authorization Bearer)
    JWT-->>B: 验签结果
    alt 验签失败
        B-->>DS: 401 Unauthorized
    else 验签通过
        B->>CB: handle(payload)
        CB->>CB: 幂等校验（def_doc_callback_log.key+status）
        CB->>DB: 写入 def_doc_callback_log（处理中）
        CB->>VS: createVersion(docId, url, users[0])
        VS->>VS: 从 url 下载文档内容（curl）
        VS->>VS: 校验文件大小/类型
        VS->>FS: 写入 {docId}/{version}_{timestamp}.docx
        VS->>DB: INSERT def_contract_doc_version<br/>(版本号=当前版本+1, 文件Key, 创建人)
        VS->>DB: UPDATE def_contract_doc SET 当前版本=新版本
        VS->>DB: INSERT def_contract_doc_op_log（版本创建）
        VS-->>CB: 返回新版本号
        CB->>DB: UPDATE def_doc_callback_log（成功）
        CB-->>B: 成功
        B-->>DS: {"error": 0}
    end
    DS->>DS: 标记文档已保存
    DS-->>U: 关闭确认
```

### 4.4 强制保存流程（forceSave / status=6）

```mermaid
sequenceDiagram
    autonumber
    participant U as 用户
    participant V as OnlyOfficeEditor.vue
    participant DS as Document Server
    participant B as MIS 后端
    participant VS as VersionService

    alt 手动 forceSave
        U->>V: 点击"保存版本"按钮
        V->>DS: editor.serviceCommand({command:'forcesave', params:{}})
    else 定时 forceSave
        DS->>DS: 定时器触发（customization.forcesave=true）
    end
    DS->>DS: 准备当前文档快照
    DS->>B: POST /onlyoffice/callback<br/>{status:6, url, forcesavetype}
    B->>B: 验签 + 幂等校验
    alt ONLYOFFICE_VERSION_ON_FORCE_SAVE=true
        B->>VS: createVersion（同 status=2 流程）
        VS-->>B: 新版本号
    else 不创建版本
        B->>B: 仅记录 def_doc_callback_log
    end
    B-->>DS: {"error": 0}
    DS-->>V: 保存完成事件
    V-->>U: 提示"版本已保存"
```

**forcesavetype 取值**：

| 值 | 含义 |
|---|---|
| 0 | 手动触发（用户点击保存按钮，编辑器未关闭） |
| 1 | 定时触发（customization.forcesave 定时） |
| 2 | 表单提交触发 |
| 3 | 协同退出触发 |

### 4.5 版本历史流程

```mermaid
sequenceDiagram
    autonumber
    participant V as OnlyOfficeEditor.vue
    participant B as MIS 后端
    participant DS as Document Server

    V->>V: 用户点击"版本历史"
    V->>DS: 触发 events.onRequestHistory
    V->>B: GET /onlyoffice/history/{docId}
    B->>B: 查询 def_contract_doc_version 列表
    B-->>V: {currentVersion, history:[{version, created, user, ...}]}
    V->>DS: editor.refreshHistory(historyData)
    DS->>DS: 渲染历史列表
    V->>V: 用户点击某版本
    V->>DS: 触发 events.onRequestHistoryData
    V->>B: GET /onlyoffice/history-data/{versionId}
    B->>B: 读取该版本文件 Key 与文件路径
    B->>B: 拼装 version.url（带签名）+ previous.url（上一版本）
    B-->>V: {version, key, url, previous:{key, url}}
    V->>DS: editor.setHistoryData(historyData)
    DS->>DS: 加载对比视图
    DS-->>V: 渲染左右对比
```

### 4.6 版本回溯流程

```mermaid
sequenceDiagram
    autonumber
    participant U as 用户
    participant V as OnlyOfficeEditor.vue
    participant B as MIS 后端
    participant VS as VersionService
    participant DB as MySQL
    participant FS as 文件存储

    U->>V: 在版本历史中选择"回溯到此版本"
    V->>B: POST /contract-doc/version/rollback<br/>{versionId}
    B->>B: 校验用户权限（编辑权限）
    B->>VS: rollback(versionId)
    VS->>DB: 读取目标版本文件路径与内容
    VS->>FS: 复制目标版本文件为新文件<br/>{docId}/{newVersion}_{timestamp}.docx
    VS->>DB: INSERT def_contract_doc_version<br/>(版本号=当前版本+1, 备注="回溯自 v{n}", 创建人)
    VS->>DB: UPDATE def_contract_doc SET 当前版本=新版本, 文件Key=新Key
    VS->>DB: INSERT def_contract_doc_op_log（版本回溯）
    VS-->>B: 新版本号
    B-->>V: 200 OK {newVersion}
    V->>V: 提示"回溯成功，重新打开编辑器"
    V->>V: 重新请求配置（document.key 变更）
```

**关键设计**：版本回溯**不删除任何历史版本**，而是创建一个新版本（内容为目标版本的拷贝），保证可追溯性。

---

## 五、JWT 签名与验签机制

### 5.1 签名算法

- **算法**：HS256（HMAC-SHA256）
- **密钥**：`ONLYOFFICE_JWT_SECRET`（与 Document Server 的 `jwt.secret` 配置严格一致）
- **依赖**：优先使用 `firebase/php-jwt` ^6.x；若未引入则用 `hash_hmac('sha256', ...)` 自实现（参考 OnlyOffice 官方 PHP 示例）

### 5.2 签名载荷结构

配置签名的 payload 即完整的 config 对象（顶层字段必须包含 `document`、`editorConfig`、`documentType`），示例如下：

```json
{
  "documentType": "word",
  "document": {
    "fileType": "docx",
    "key": "contract_1001_v3_1721712000",
    "title": "合同-2026-0001.docx",
    "url": "http://mis-backend.example.com/onlyoffice/download?token=xxx",
    "permissions": {
      "download": true,
      "edit": true,
      "print": true,
      "review": true,
      "comment": true,
      "fillForms": true,
      "modifyFilter": true,
      "modifyContentControl": true,
      "copy": true
    },
    "versionType": "default"
  },
  "editorConfig": {
    "mode": "edit",
    "lang": "zh-CN",
    "callbackUrl": "http://mis-backend.example.com/onlyoffice/callback",
    "user": {
      "id": "u1001",
      "name": "张三"
    },
    "customization": {
      "autosave": true,
      "forcesave": true,
      "coAuthoring": true,
      "chat": true,
      "comment": true,
      "compactHeader": false,
      "toolbarNoTabs": false,
      "zoom": 100,
      "spellcheck": true
    }
  }
}
```

**签名流程**：

1. `OnlyOfficeConfigBuilder::build()` 产出上述关联数组；
2. `OnlyOfficeJwtService::sign($config)` 调用 `Firebase\JWT\JWT::encode($config, $secret, 'HS256')`；
3. 返回的 token 字符串赋给 `config.token`；
4. 前端将该 token 与 config 一并传给 `DocsAPI.DocEditor`。

### 5.3 前端传递 token

前端**不**单独构造 token，直接使用后端返回的 `config.token`：

```typescript
// frontend/src/components/onlyoffice/OnlyOfficeEditor.vue
const resp = await fetchEditorConfig(props.docId);
const config = resp.data.config;
config.token = resp.data.token; // 整体签名
// 仅当 document/editorConfig 单独需要时（可选），后端会在对应节点附 token
// config.document.token = resp.data.documentToken;
// config.editorConfig.token = resp.data.editorConfigToken;
editor.value = new DocsAPI.DocEditor(elementId.value, config);
```

**注意**：OnlyOffice 支持"整体签名"与"分别签名"两种模式，本方案统一采用**整体签名**（`config.token`），简化实现。

### 5.4 回调验签流程

OnlyOffice Document Server 在调用 `callbackUrl` 时，将整个 payload 用同一密钥签名，并通过两种方式传递 token：

1. **HTTP 头**：`Authorization: Bearer <token>`（推荐，Document Server 默认行为）
2. **请求体字段**：payload 中包含 `token` 字段（兜底）

`OnlyOfficeApi::callback()` 验签逻辑：

```php
public function callback(): ResponseInterface
{
    $rawBody = $this->request->getBody() ?? '';
    $payload = json_decode($rawBody, true) ?: [];

    // 1. 优先从 Authorization 头取 token，其次从 payload.token 取
    $authHeader = $this->request->getHeaderLine('Authorization');
    $token = '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    } elseif (isset($payload['token'])) {
        $token = $payload['token'];
        // 注意：当 token 在 payload 内时，验签载荷应为 payload.token 解码后的对象
        $payload = $this->jwtService->decode($token);
    }

    if ($token === '' || ! $this->jwtService->verify($token)) {
        return $this->failUnauthorized('Invalid OnlyOffice JWT');
    }

    // 2. 幂等校验
    // 3. 调度 CallbackService
    $result = $this->callbackService->handle($payload);
    return $this->respond(['error' => $result ? 0 : 1]);
}
```

**验签流程图**：

```mermaid
flowchart TD
    A[收到回调] --> B{Authorization Bearer 存在?}
    B -- 是 --> C[取 Bearer token]
    B -- 否 --> D{payload.token 存在?}
    D -- 是 --> E[取 payload.token<br/>payload = decode token]
    D -- 否 --> F[返回 401]
    C --> G[JWT::decode 验签]
    E --> G
    G -- 失败 --> F
    G -- 成功 --> H[载荷合法性校验<br/>key/status 必填]
    H -- 失败 --> I[返回 400]
    H -- 成功 --> J[幂等校验]
    J -- 已处理 --> K[返回 error:0 跳过]
    J -- 未处理 --> L[调度 CallbackService]
    L --> M{处理结果}
    M -- 成功 --> N[返回 error:0]
    M -- 失败 --> O[返回 error:1 触发重试]
```

### 5.5 密钥管理

| 项 | 要求 |
|---|---|
| 密钥长度 | ≥ 32 字节（HS256 推荐长度） |
| 密钥生成 | `openssl rand -base64 48` |
| 密钥存储 | `.env` 文件（不入版本库），生产环境通过运维下发 |
| 密钥同步 | 与 Document Server 的 `/etc/onlyoffice/documentserver/local.json` 中 `services.coAuthoring.secret.inbox.string` / `outbox.string` / `session.string` 保持一致 |
| 密钥轮换 | 建议每 6 个月轮换一次，轮换需停机同步两端 |
| 密钥泄露 | 立即轮换，排查 `def_doc_callback_log` 异常记录 |

---

## 六、编辑器配置详解

### 6.1 documentType

| 值 | 适用文件 | 对应扩展名 |
|---|---|---|
| `word` | 文本文档 | docx/doc/docxf/oform/txt/rtf/html/odt |
| `cell` | 电子表格 | xlsx/xls/csv/ods |
| `slide` | 演示文稿 | pptx/ppt/ppsx/odp |

`OnlyOfficeConfigBuilder` 根据文档扩展名自动推断 `documentType`。

### 6.2 document 配置

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `fileType` | string | 是 | 文件扩展名（无点），如 `docx` |
| `key` | string | 是 | 文档版本唯一标识，**修改后强制重新加载**，长度 ≤ 20，仅字母数字 `_` `-` |
| `title` | string | 是 | 文件名（含扩展名），用于显示与下载命名 |
| `url` | string | 是（编辑模式） | 文档下载地址，Document Server 必须可达 |
| `permissions` | object | 是 | 权限对象（见 6.4） |
| `versionType` | enum | 否 | `default`/`previous`/`last`，默认 `default`，用于版本对比 |

**document.key 生成规则**：

```
{业务前缀}_{docId}_v{当前版本号}_{版本生成时间戳}
```

示例：`contract_1001_v3_1721712000`

**关键约束**：每次保存创建新版本后，必须生成新 key，否则 Document Server 返回缓存内容。

### 6.3 editorConfig 配置

| 字段 | 类型 | 说明 |
|---|---|---|
| `mode` | enum | `edit`/`view`，由用户权限决定 |
| `lang` | string | 语言代码，如 `zh-CN`/`en-US`，从 `def_user.语言` 读取或默认 `zh-CN` |
| `callbackUrl` | string | 回调地址，由 `ONLYOFFICE_CALLBACK_BASE_URL` + `/onlyoffice/callback` 拼装 |
| `user.id` | string | 当前用户 ID（脱敏后的字符串） |
| `user.name` | string | 当前用户姓名 |
| `user.group` | string | 当前用户部门（可选） |
| `customization` | object | 个性化配置（见 6.5） |
| `embedded.toolbarDocked` | enum | 嵌入模式工具栏位置（`top`/`bottom`/`right`/`left`），仅在嵌入模式使用 |

### 6.4 permissions 配置

| 字段 | 默认 | 说明 |
|---|---|---|
| `view` | true | 是否允许查看（仅查看模式仍可看） |
| `edit` | true | 是否允许编辑内容 |
| `download` | true | 是否允许下载 |
| `print` | true | 是否允许打印 |
| `comment` | true | 是否允许评论 |
| `review` | true | 是否允许审阅（仅 word 有意义） |
| `fillForms` | true | 是否允许填写表单 |
| `modifyFilter` | true | 是否允许修改筛选（仅 cell） |
| `modifyContentControl` | true | 是否允许修改内容控件（仅 word） |
| `copy` | true | 是否允许复制 |
| `reviewGroups` | array | 允许审阅的用户组 |
| `commentGroups` | object | 评论分组权限 |

### 6.5 customization 配置

| 字段 | 默认 | 说明 |
|---|---|---|
| `autosave` | true | 自动保存 |
| `forcesave` | true | 启用 forceSave（触发 status=6 回调） |
| `coAuthoring` | true | 协同编辑开关 |
| `chat` | true | 聊天面板 |
| `comment` | true | 评论功能 |
| `compactHeader` | false | 紧凑头部 |
| `toolbarNoTabs` | false | 工具栏不分类 |
| `zoom` | 100 | 初始缩放 |
| `spellcheck` | true | 拼写检查 |
| `showReviewChanges` | false | 显示审阅变更 |
| `help` | true | 显示帮助 |
| `about` | true | 显示关于 |
| `feedback` | true | 显示反馈 |
| `logo` | object | 自定义 logo |

### 6.6 角色权限映射表

| 角色编码 | mode | edit | download | print | comment | review | fillForms | 说明 |
|---|---|---|---|---|---|---|---|---|
| `contract:view` | view | false | true | true | true | false | false | 仅查看 + 评论 |
| `contract:edit` | edit | true | true | true | true | true | true | 全权限编辑 |
| `contract:approve` | edit | true | true | true | true | true | false | 审批人可编辑+审阅，不可填表单 |
| `contract:sign` | edit | true | true | true | false | true | true | 签署人可编辑+填表单 |
| `contract:archive` | view | false | false | false | false | false | false | 归档后只读，不可下载/打印 |
| `contract:admin` | edit | true | true | true | true | true | true | 管理员全权限 |

`OnlyOfficeConfigBuilder::buildPermissions(string $roleCode)` 内部维护此映射表，并通过 `AuthorizationService::hasPermission()` 校验用户实际持有的角色编码。

---

## 七、回调状态处理

### 7.1 完整状态表

| status | 含义 | url 字段 | 触发时机 | 后端处理 |
|---|---|---|---|---|
| 0 | 未保存 | 无 | 编辑器加载完成 | 记录 `def_doc_callback_log`，无其他操作 |
| 1 | 编辑中 | 无 | 协同期间，每 10s（默认） | 记录协同用户列表，无版本操作 |
| 2 | 准备保存 | 有 | 文档关闭、强制保存、协同退出 | **创建版本**（下载 url → 存储 → 写库 → 留痕） |
| 3 | 保存出错 | 无 | Document Server 内部异常 | 记录错误日志，告警 |
| 4 | 无变化关闭 | 无 | 文档关闭且未修改 | 仅记录关闭事件 |
| 6 | 强制保存 | 有 | 手动 forceSave 或定时 forceSave | 根据 `ONLYOFFICE_VERSION_ON_FORCE_SAVE` 决定是否创建版本 |
| 7 | 强制保存出错 | 无 | forceSave 失败 | 记录错误日志，告警 |

### 7.2 status=2 保存流程详解

```mermaid
flowchart TD
    A[收到 status=2 回调] --> B[验签 + 幂等校验]
    B --> C{def_doc_callback_log<br/>是否已存在 key+status=2 成功记录?}
    C -- 是 --> D[返回 error:0 跳过]
    C -- 否 --> E[INSERT def_doc_callback_log 处理中]
    E --> F[从 payload.url 下载文档]
    F --> G{下载成功?}
    G -- 否 --> H[UPDATE log 失败 + 返回 error:1]
    G -- 是 --> I[校验文件大小/类型/MIME]
    I --> J[生成新版本号 = 当前版本 + 1]
    J --> K[写文件到 storage: {docId}/{version}_{ts}.{ext}]
    K --> L[INSERT def_contract_doc_version]
    L --> M[UPDATE def_contract_doc.当前版本 + 文件Key + document.key]
    M --> N[INSERT def_contract_doc_op_log 版本创建]
    N --> O{版本数 > versionKeep?}
    O -- 是 --> P[清理最早版本文件 + 标记 def_contract_doc_version.已清理]
    O -- 否 --> Q[跳过清理]
    P --> R[UPDATE def_doc_callback_log 成功]
    Q --> R
    R --> S[返回 error:0]
```

### 7.3 status=6 forceSave 详解

```php
// OnlyOfficeCallbackService 节选
private function handleForceSave(array $payload): bool
{
    if (! $this->config->versionOnForceSave) {
        // 仅记录日志，不创建版本
        $this->logCallback($payload, 'forcesave_skipped');
        return true;
    }

    // forcesavetype: 0=手动 1=定时 2=表单 3=协同退出
    $forceSaveType = $payload['forcesavetype'] ?? 0;
    $remark = match ($forceSaveType) {
        0 => '手动保存',
        1 => '定时保存',
        2 => '表单提交保存',
        3 => '协同退出保存',
        default => 'forceSave',
    };

    return $this->createVersion($payload, $remark);
}
```

### 7.4 status=4 关闭无变化处理

```php
private function handleCloseNoChanges(array $payload): bool
{
    // 仅记录操作留痕（关闭事件），不创建版本
    $this->opLogService->record([
        '文档ID'   => $payload['docId'] ?? null,
        '操作类型' => '关闭',
        '操作详情' => json_encode(['key' => $payload['key'], 'users' => $payload['users'] ?? []], JSON_UNESCAPED_UNICODE),
        '操作人'   => $payload['users'][0] ?? 'unknown',
    ], isCallback: true);
    return true;
}
```

### 7.5 回调幂等性设计

OnlyOffice Document Server 在收到非 `{"error": 0}` 响应时会**自动重试**（默认 3 次，间隔递增）。为防止重复处理同一回调导致版本重复创建，采用以下幂等策略：

1. **唯一键**：`def_doc_callback_log` 表的 `(文档Key, status, forcesavetype)` 联合唯一索引；
2. **处理前预检**：`CallbackService::handle()` 入口先查询是否已存在 `成功` 状态的同 key+status 记录，若存在直接返回 `true`；
3. **乐观锁**：处理前 `INSERT IGNORE` 写入一条 `处理中` 记录，若影响行数 0 表示已被其他进程接管，直接返回；
4. **版本号单调递增**：`def_contract_doc_version.版本号` 由 `SELECT MAX(版本号)+1 FOR UPDATE` 保证并发安全；
5. **文件名带时间戳**：避免不同回调覆盖同一文件。

**幂等校验 SQL**：

```sql
-- 唯一索引
ALTER TABLE def_doc_callback_log ADD UNIQUE KEY uk_key_status (文档Key, status, forcesavetype);

-- 处理前预检
SELECT 处理结果 FROM def_doc_callback_log
WHERE 文档Key = ? AND status = ? AND forcesavetype = ?
AND 处理结果 = '成功' LIMIT 1;
```

---

## 八、版本管理机制

### 8.1 版本号生成规则

- **规则**：自增整数，从 1 开始，每次保存/回溯 +1
- **生成方式**：`SELECT MAX(版本号) FOR UPDATE` + 1，保证并发安全
- **不使用时间戳**：时间戳在并发场景下可能冲突，自增更可读
- **回溯版本**：回溯创建的新版本号 = 当前最大版本号 + 1（不是被回溯版本号 + 1），保证单调递增

### 8.2 版本快照存储策略

**文件路径命名规则**：

```
{ONLYOFFICE_DOC_STORAGE_PATH}/{docId}/{version}_{timestamp}_{shortUuid}.{ext}
```

示例：

```
/var/www/mis/backend/writable/uploads/onlyoffice/1001/3_1721712000_a1b2c3d4.docx
```

**为什么用全量存储而非增量**：

| 方案 | 优点 | 缺点 | 结论 |
|---|---|---|---|
| 全量存储 | 实现简单、回溯快、独立可读 | 存储占用高 | **本方案采用** |
| 增量存储（diff/patch） | 节省存储 | 实现复杂、回溯需重放、依赖顺序 | 不采用 |
| 全量 + 增量混合 | 平衡 | 实现复杂度极高 | 不采用 |

**理由**：合同文档通常 < 10MB，全量存储成本可控，且 OnlyOffice 回调提供的 `url` 本身就是完整文件，无需额外计算 diff。

### 8.3 版本标记

- **目的**：用户可手动标记"重要版本"（如"法务审核版"、"终版"），便于检索
- **字段**：`def_contract_doc_version.是否标记` (boolean) + `def_contract_doc_version.版本说明` (varchar 500)
- **API**：`POST /contract-doc/version/mark` `{versionId, remark}`
- **限制**：每个文档最多标记 10 个版本（防止滥用）

### 8.4 版本对比

- **机制**：OnlyOffice 编辑器内置版本对比组件，前端通过 `events.onRequestHistory` 与 `events.onRequestHistoryData` 触发
- **后端职责**：返回版本列表 + 每个版本的 `url`（带签名）+ 上一版本的 `previous.url`
- **对比方式**：左右分栏显示当前版本与历史版本，差异高亮
- **不支持跨版本对比**：仅支持相邻版本或与当前版本对比（OnlyOffice 限制）

### 8.5 版本回溯

- **不删除原版本**：回溯 = 创建新版本（内容为目标版本拷贝）
- **document.key 变更**：回溯后必须生成新 key，否则 Document Server 返回旧缓存
- **操作留痕**：记录"版本回溯"事件，包含源版本号与目标版本号
- **回溯限制**：归档状态的合同不允许回溯（业务规则）

### 8.6 版本清理策略

- **触发时机**：每次创建新版本后检查
- **保留规则**：保留最近 `ONLYOFFICE_VERSION_KEEP`（默认 50）个版本 + 所有"标记"版本
- **清理动作**：
  1. `SELECT COUNT(*) FROM def_contract_doc_version WHERE 文档ID = ? AND 是否标记 = 0`
  2. 若超过 `versionKeep`，按版本号升序删除最早的未标记版本
  3. 删除对应文件，标记 `def_contract_doc_version.已清理 = 1` + `文件路径 = NULL`
  4. 记录"版本清理"操作留痕

### 8.7 数据模型

```mermaid
erDiagram
    def_contract_doc ||--o{ def_contract_doc_version : "1:N"
    def_contract_doc ||--o{ def_contract_doc_op_log : "1:N"
    def_contract_doc ||--o{ def_doc_callback_log : "1:N"

    def_contract_doc {
        bigint 主键ID PK
        bigint 合同ID FK
        varchar 文档Key UK "OnlyOffice document.key"
        varchar 文件Key "当前版本文件标识"
        int 当前版本
        varchar 编辑模式 "edit/view"
        json 权限配置
        datetime 创建时间
        datetime 更新时间
    }

    def_contract_doc_version {
        bigint 主键ID PK
        bigint 文档ID FK
        int 版本号
        varchar 文件Key "OnlyOffice file key"
        varchar 文件路径 "相对 storage 路径"
        varchar 文件类型 "docx/xlsx/pptx"
        bigint 文件大小
        bigint 创建人ID
        datetime 创建时间
        varchar 版本说明
        boolean 是否标记
        boolean 已清理
    }

    def_contract_doc_op_log {
        bigint 主键ID PK
        bigint 文档ID FK
        varchar 操作类型 "打开/编辑/保存/下载/版本创建/版本回溯/版本标记/版本清理/关闭"
        text 操作详情 JSON
        bigint 操作人ID
        datetime 操作时间
        varchar 操作人IP
        varchar 设备 "PC/Mobile"
        varchar UserAgent
        boolean 是否回调触发
    }

    def_doc_callback_log {
        bigint 主键ID PK
        bigint 文档ID FK
        varchar 文档Key
        int status "0/1/2/3/4/6/7"
        int forcesavetype
        text 载荷 "完整回调 JSON"
        varchar 处理结果 "处理中/成功/失败"
        text 错误信息
        int 处理耗时毫秒
        datetime 接收时间
        datetime 处理完成时间
    }
```

---

## 九、操作留痕设计

### 9.1 留痕事件类型

| 事件类型 | 触发方式 | 触发时机 | 备注 |
|---|---|---|---|
| 打开 | 前端 | 用户打开编辑器（onDocumentReady） | 记录打开模式 |
| 编辑 | 前端 | 用户开始编辑（onDocumentStateChange，state=true） | 仅首次编辑记录 |
| 保存 | 后端回调 | status=2/6 成功 | 关联版本号 |
| 下载 | 后端 | `/onlyoffice/download` 调用 | 仅记录通过 OnlyOffice 下载的 |
| 版本创建 | 后端回调 | 新版本写入 def_contract_doc_version | 关联版本号 |
| 版本回溯 | 后端 | `POST /contract-doc/version/rollback` | 关联源版本与目标版本 |
| 版本标记 | 后端 | `POST /contract-doc/version/mark` | 关联版本号 |
| 版本清理 | 后端 | 自动清理触发 | 关联被清理版本号列表 |
| 关闭 | 后端回调 | status=4 | 无变化关闭 |
| 协同开始 | 后端回调 | status=1 且首次出现该 key | 记录协同用户列表 |
| 协同结束 | 后端回调 | status=2 且 users 多于 1 | 记录协同用户列表 |

### 9.2 留痕记录字段

| 字段 | 类型 | 说明 |
|---|---|---|
| 主键ID | bigint | 自增主键 |
| 文档ID | bigint | 关联 def_contract_doc |
| 操作类型 | varchar(32) | 见 9.1 |
| 操作详情 | text | JSON 格式，含 key/users/version 等上下文 |
| 操作人ID | bigint | 当前用户 ID（回调触发时取 payload.users[0]） |
| 操作时间 | datetime | 服务器时间 |
| 操作人IP | varchar(45) | IPv4/IPv6 |
| 设备 | varchar(16) | PC/Mobile |
| UserAgent | varchar(500) | 浏览器 UA |
| 是否回调触发 | boolean | true=后端回调，false=前端事件 |

### 9.3 留痕记录时机

```mermaid
flowchart LR
    subgraph 前端触发
        F1[打开编辑器] --> F2[POST /onlyoffice/oplog<br/>type=打开]
        F3[开始编辑] --> F4[POST /onlyoffice/oplog<br/>type=编辑]
    end
    subgraph 后端回调触发
        B1[status=2 保存成功] --> B2[INSERT oplog<br/>type=保存+版本创建]
        B3[status=4 关闭] --> B4[INSERT oplog<br/>type=关闭]
        B5[status=6 forceSave] --> B6[INSERT oplog<br/>type=保存+版本创建]
    end
    subgraph 后端 API 触发
        A1[POST /rollback] --> A2[INSERT oplog<br/>type=版本回溯]
        A3[POST /mark] --> A4[INSERT oplog<br/>type=版本标记]
        A5[GET /download] --> A6[INSERT oplog<br/>type=下载]
    end
```

**双轨制设计**：

- **前端事件**：用户主动操作（打开/编辑）由前端 `OnlyOfficeEditor.vue` 监听编辑器事件并调用 `POST /onlyoffice/oplog` 记录；
- **后端回调**：保存/版本/关闭等由 `OnlyOfficeCallbackService` 在处理回调时直接写入；
- **后端 API**：下载/回溯/标记等由对应 Controller 在业务处理后写入。

**冲突避免**：同一事件（如"保存"）仅由后端记录，前端不重复记录。

### 9.4 留痕查询与导出

- **查询 API**：`GET /contract-doc/oplog/{docId}` 支持按时间/操作类型/操作人筛选，分页返回
- **导出 API**：`GET /contract-doc/oplog/export?docId=&start=&end=&type=` 导出 Excel（复用 Workbench `ExportService` 模式）
- **导出格式**：包含文档标题、操作类型、操作人姓名（JOIN `def_user`）、操作时间、操作详情（格式化）、IP、设备
- **权限**：仅持有 `contract:audit` 权限的角色可查询/导出留痕

---

## 十、异常处理机制

### 10.1 回调失败重试

| 项 | 说明 |
|---|---|
| 重试方 | OnlyOffice Document Server 内置 |
| 重试次数 | 默认 3 次 |
| 重试间隔 | 指数退避（默认 5s/10s/20s，可配置） |
| 后端响应 | `{"error": 0}` 表示成功，`{"error": 1}` 或非 200 触发重试 |
| 后端幂等 | 通过 `def_doc_callback_log` 唯一索引保证（见 7.5） |
| 重试失败 | Document Server 标记文档保存失败，用户重新打开时尝试恢复 |

### 10.2 版本冲突处理

**冲突场景**：document.key 未变更，但后端版本已更新（如并发回调）。

**处理策略**：

1. **每次保存后强制变更 key**：`OnlyOfficeVersionService::createVersion()` 完成后，更新 `def_contract_doc.文档Key` 为新值（`{前缀}_{docId}_v{新版本}_{新时间戳}`）；
2. **下次打开使用新 key**：`OnlyOfficeConfigBuilder::build()` 总是读取最新的 `文档Key`；
3. **协同冲突由 DS 处理**：同一 key 多用户编辑时，Document Server 内部 OT 解决，应用层不参与。

### 10.3 文档损坏处理

| 阶段 | 校验 | 失败处理 |
|---|---|---|
| 下载 | HTTP 状态码 = 200、Content-Type 匹配、文件大小 > 0 | 重试 3 次，仍失败则告警 |
| 落盘 | `finfo::file()` 校验 MIME、扩展名匹配 | 拒绝写入，记录错误日志 |
| 读取 | `is_readable()`、文件大小匹配数据库记录 | 标记版本为"损坏"，回退到上一可用版本 |
| 备份 | 每次写入新版本时，旧版本文件保留 | 不覆盖原文件，损坏可回溯 |

### 10.4 Document Server 不可达处理

```mermaid
flowchart TD
    A[前端打开编辑器] --> B[请求 /onlyoffice/config]
    B --> C{后端能否 ping<br/>ONLYOFFICE_URL/healthcheck?}
    C -- 否 --> D[返回降级配置<br/>mode=view, 不可编辑]
    D --> E[前端显示只读提示<br/>"文档服务暂不可用，已切换只读模式"]
    C -- 是 --> F[正常返回编辑配置]
    F --> G[前端初始化 DocEditor]
    G --> H{DocEditor 加载成功?}
    H -- 否 --> I[捕获 onError<br/>显示"文档服务连接失败"]
    H -- 是 --> J[正常使用]
```

**降级策略**：

- 后端 `/onlyoffice/config` 入口先调用 `OnlyOfficeHealthCheck::isAvailable()`（缓存 30s）；
- 不可用时返回 `mode=view` + `customization.toolbarNoTabs=true`，并在响应中附加 `degraded: true` 标记；
- 前端检测到 `degraded` 后显示"文档服务暂不可用，已切换只读模式"提示。

### 10.5 验签失败处理

```php
// OnlyOfficeApi::callback 节选
if (! $this->jwtService->verify($token)) {
    log_message('error', 'OnlyOffice callback JWT verification failed: ' . $rawBody);
    // 记录安全事件到 sys_sql_log + 告警
    $this->securityLog('onlyoffice_callback_jwt_failed', [
        'ip' => $this->request->getIPAddress(),
        'body' => $rawBody,
    ]);
    return $this->failUnauthorized('Invalid OnlyOffice JWT');
}
```

- **响应**：HTTP 401 + `{"error": 1}`
- **日志**：记录原始 body（脱敏后）、来源 IP
- **告警**：连续 3 次验签失败触发告警（可能是密钥不一致或被攻击）

### 10.6 下载失败处理

```mermaid
flowchart TD
    A[VersionService::createVersion] --> B[curl GET url]
    B --> C{HTTP 200?}
    C -- 否 --> D{重试次数 < 3?}
    D -- 是 --> E[等待 1s 重试]
    E --> B
    D -- 否 --> F[抛 DownloadException]
    C -- 是 --> G[校验大小/MIME]
    G -- 失败 --> F
    G -- 成功 --> H[写入文件]
    F --> I[UPDATE def_doc_callback_log 失败]
    I --> J[返回 error:1 触发 DS 重试]
    J --> K[告警通知运维]
```

**重试参数**：

- 最大重试次数：3
- 重试间隔：1s/2s/4s（指数退避）
- 超时：单次 30s
- 告警：连续 3 次失败触发邮件/IM 告警

### 10.7 异常处理汇总表

| 异常 | 检测 | 响应 | 后续动作 |
|---|---|---|---|
| 验签失败 | JWT::decode 抛异常 | 401 + error:1 | 记录安全日志、告警 |
| 幂等冲突 | 唯一索引冲突 | error:0 跳过 | 无 |
| 下载失败 | HTTP 非 200 / 超时 | error:1 | 重试、告警 |
| 文件损坏 | MIME/大小校验 | error:1 | 拒绝写入、告警 |
| 数据库失败 | PDOException | error:1 | 回滚事务、告警 |
| DS 不可达 | healthcheck 失败 | 降级 view 模式 | 提示用户 |
| 版本号冲突 | FOR UPDATE 锁 | 重试一次 | 仍失败则 error:1 |
| 存储空间不足 | disk_free_space 检查 | error:1 | 告警、暂停保存 |

---

## 十一、安全设计

### 11.1 访问控制

| 资源 | 校验方式 | 实现 |
|---|---|---|
| `/onlyoffice/config/{docId}` | 用户 JWT + 文档归属 | `AuthorizationService::checkUserJwt()` + `ContractDocService::assertOwnsDoc()` |
| `/onlyoffice/download` | OnlyOffice JWT 签名 + TTL + 文档归属 | `OnlyOfficeJwtService::verify()` + URL 签名校验 |
| `/onlyoffice/callback` | OnlyOffice JWT 签名 + IP 白名单 | `OnlyOfficeJwtService::verify()` + `IpWhitelistFilter` |
| `/onlyoffice/history/*` | 用户 JWT + 文档归属 | 同 config |
| `/contract-doc/version/*` | 用户 JWT + 文档归属 + 编辑权限 | `AuthorizationService::hasPermission('contract:edit')` |
| `/contract-doc/oplog/*` | 用户 JWT + 审计权限 | `AuthorizationService::hasPermission('contract:audit')` |

**文档归属校验**：

```php
// ContractDocService
public function assertOwnsDoc(int $userId, int $docId): void
{
    $doc = $this->docModel->find($docId);
    if (! $doc) {
        throw businessError('文档不存在');
    }
    $contract = $this->contractModel->find($doc['合同ID']);
    if (! $contract) {
        throw businessError('合同不存在');
    }
    // 复用 AuthorizationService 行级数据权限
    if (! $this->auth->canAccessRow($userId, $contract)) {
        throw businessError('无权访问该文档');
    }
}
```

### 11.2 防篡改

- **配置防篡改**：整个 config 由 `OnlyOfficeJwtService::sign()` 签名，Document Server 校验签名后才接受配置；
- **回调防篡改**：Document Server 用同一密钥签名回调，后端验签后才处理；
- **下载链接防篡改**：`document.url` 与 `version.url` 携带签名 token，包含 docId + versionId + 过期时间，后端校验签名 + TTL；
- **document.key 防篡改**：由后端生成并签名，前端无法伪造。

### 11.3 敏感信息脱敏

| 信息 | 脱敏方式 |
|---|---|
| 文档内容 | 不入日志，仅记录文件路径与大小 |
| 回调 payload | `def_doc_callback_log.载荷` 仅保留 key/status/url/users/forcesavetype，剔除可能的 changes 字段 |
| 用户 JWT | 不入日志（仅记录用户 ID） |
| OnlyOffice JWT | 不入日志（仅记录验签结果） |
| User-Agent | 截断至 200 字符 |
| 错误堆栈 | 不返回前端，仅写入 `writable/logs/` |

### 11.4 回调地址白名单

```php
// app/Config/Filters.php
public array $filters = [
    'onlyofficeCallbackWhitelist' => [
        'before' => ['onlyoffice/callback'],
    ],
];

// app/Filters/OnlyOfficeCallbackWhitelist.php
public function before(RequestInterface $request, ...): mixed
{
    $ip = $request->getIPAddress();
    $whitelist = config('OnlyOffice')->callbackWhitelist;
    if (empty($whitelist)) {
        return null; // 未配置则放行（开发环境）
    }
    foreach ($whitelist as $cidr) {
        if ($this->ipInRange($ip, $cidr)) {
            return null;
        }
    }
    return service('response')->setStatusCode(403, 'IP not in whitelist');
}
```

### 11.5 文件存储安全

| 风险 | 防护 |
|---|---|
| 路径遍历 | 文件名仅由 `docId + version + timestamp + uuid` 生成，拒绝任何用户输入拼接路径 |
| 越权访问 | 文件不通过 Web 直接访问，仅通过 `/onlyoffice/download` 接口（带签名校验） |
| 文件类型伪造 | 落盘前 `finfo::file()` 校验 MIME，扩展名与 MIME 必须匹配 |
| 文件名冲突 | 文件名包含 timestamp + shortUuid，避免并发覆盖 |
| 存储目录暴露 | `ONLYOFFICE_DOC_STORAGE_PATH` 不在 Web 根目录下，使用 `WRITEPATH` 子目录 |
| 上传恶意文件 | OnlyOffice 回调的 url 仅来自 Document Server，不接受用户上传 |

---

## 十二、性能优化

### 12.1 动态加载 api.js

```typescript
// frontend/src/components/onlyoffice/OnlyOfficeEditor.vue
let apiLoaded = false;
let apiLoading: Promise<void> | null = null;

function loadApi(): Promise<void> {
  if (apiLoaded) return Promise.resolve();
  if (apiLoading) return apiLoading;

  apiLoading = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `${import.meta.env.VITE_ONLYOFFICE_URL}/web-apps/apps/api/documents/api.js`;
    script.async = true;
    script.onload = () => {
      apiLoaded = true;
      resolve();
    };
    script.onerror = () => reject(new Error('Failed to load OnlyOffice api.js'));
    document.head.appendChild(script);
  });
  return apiLoading;
}

onMounted(async () => {
  await loadApi();
  await initEditor();
});
```

**优点**：api.js 约 300KB，仅在打开编辑器时加载，不影响首屏性能。

### 12.2 文档 key 缓存策略

- **缓存内容**：`def_contract_doc` 行数据（含 `文档Key`、`当前版本`、`编辑模式`、`权限配置`）
- **缓存键**：`onlyoffice:doc:{docId}`
- **缓存 TTL**：1800s（与项目 `ContextService` 一致）
- **表指纹**：`information_schema.TABLES.UPDATE_TIME` where TABLE_NAME='def_contract_doc'
- **失效时机**：版本创建/回溯/标记后调用 `invalidateConfigCache('onlyoffice:doc:{docId}')`
- **实现**：复用 `MetadataCache`

```php
// OnlyOfficeConfigBuilder
public function build(int $docId, int $userId, string $mode): array
{
    $cache = Services::metadataCache();
    $cacheKey = "onlyoffice:doc:{$docId}";
    $doc = $cache->get($cacheKey, 'def_contract_doc');
    if (! $doc) {
        $doc = $this->docModel->find($docId);
        $cache->save($cacheKey, $doc, 'def_contract_doc');
    }
    // 构建 config...
}
```

### 12.3 回调处理异步化

**现状**：回调处理需下载文件、写库、写日志，单次 500ms~2s。

**策略**：

1. **同步返回**：收到回调后立即返回 `{"error": 0}`，**仅记录 `def_doc_callback_log` 处理中**；
2. **异步处理**：通过 `Services::queue()` 或 CI4 闭包队列将下载/版本创建任务入队；
3. **兜底**：若队列不可用则降级为同步处理（小项目可接受）。

**注意**：OnlyOffice 文档建议同步处理回调（避免版本号错乱），本方案**默认同步处理**，仅在文档 > 5MB 时启用异步。

### 12.4 版本文件存储优化

- **全量存储**：每个版本独立文件，便于回溯与并行读取
- **目录分片**：按 `docId` 分目录，避免单目录文件数过多
- **大文件警告**：单文件 > 20MB 记录告警（可能是误传附件）
- **定期清理**：根据 `ONLYOFFICE_VERSION_KEEP` 自动清理早期版本
- **存储压缩**：暂不启用压缩（OnlyOffice 不支持读取压缩文件），未来可考虑块存储 + 冷热分层

### 12.5 hrtime 计时与 X-Server-Trace

遵循项目惯例：

```php
// OnlyOfficeApi::callback
$t0 = hrtime(true);
$payload = $this->request->getJSON(true);
$t1 = hrtime(true);
$verified = $this->jwtService->verify($token);
$t2 = hrtime(true);
$result = $this->callbackService->handle($payload);
$t3 = hrtime(true);

$this->setServerTrace([
    'parse' => ($t1 - $t0) / 1e6,
    'verify' => ($t2 - $t1) / 1e6,
    'handle' => ($t3 - $t2) / 1e6,
], $slowQueries); // 仅 >50ms 慢查询，SQL base64 编码
```

`def_doc_callback_log.处理耗时毫秒` 字段记录总耗时，便于慢回调排查。

---

## 十三、与现有系统集成点

### 13.1 与合同模块集成

```mermaid
flowchart LR
    CH[ContractDocService<br/>合同文档业务] -->|调用| CB[OnlyOfficeConfigBuilder]
    CH -->|查询版本| VS[OnlyOfficeVersionService]
    CH -->|记录留痕| OL[OpLogService]
    CB -->|读取| DOC[def_contract_doc]
    CB -->|权限| AUTH[AuthorizationService]
```

**集成方式**：

- `ContractDocService` 是合同模块的 Service，调用 `OnlyOfficeConfigBuilder` 构建配置，调用 `OnlyOfficeVersionService` 查询版本；
- `ContractDocService` 不直接操作 OnlyOffice 相关表，统一通过 `OnlyOfficeVersionService` 与 `OnlyOfficeCallbackService`；
- 合同归档时，`ContractService::archive()` 调用 `OnlyOfficeConfigBuilder::setArchived(docId)` 将文档标记为只读。

### 13.2 与权限系统整合

```php
// OnlyOfficeConfigBuilder::buildPermissions
protected function buildPermissions(int $userId, int $docId): array
{
    $role = $this->auth->getUserRoleForDoc($userId, $docId);
    $mapping = [
        'contract:view'   => ['view' => true, 'edit' => false, 'download' => true, 'print' => true, 'comment' => true, 'review' => false, 'fillForms' => false],
        'contract:edit'   => ['view' => true, 'edit' => true,  'download' => true, 'print' => true, 'comment' => true, 'review' => true,  'fillForms' => true],
        'contract:approve'=> ['view' => true, 'edit' => true,  'download' => true, 'print' => true, 'comment' => true, 'review' => true,  'fillForms' => false],
        'contract:sign'   => ['view' => true, 'edit' => true,  'download' => true, 'print' => true, 'comment' => false,'review' => true,  'fillForms' => true],
        'contract:archive'=> ['view' => true, 'edit' => false, 'download' => false,'print' => false,'comment' => false,'review' => false, 'fillForms' => false],
        'contract:admin'  => ['view' => true, 'edit' => true,  'download' => true, 'print' => true, 'comment' => true, 'review' => true,  'fillForms' => true],
    ];
    return $mapping[$role] ?? $mapping['contract:view'];
}
```

- `AuthorizationService` 注入 `OnlyOfficeConfigBuilder`，提供用户角色解析与行级数据权限；
- `editorConfig.mode` 由 `edit`/`view` 自动推导（无 `edit` 权限则强制 `view`）。

### 13.3 与审计日志整合

| 日志表 | 用途 | 写入方 |
|---|---|---|
| `sys_sql_log` | 通用 SQL 日志（项目惯例） | `Mcommon::sql_log()` 自动 |
| `def_contract_doc_op_log` | 文档操作留痕（结构化） | `OnlyOfficeCallbackService` + `OnlyOfficeApi` |
| `def_doc_callback_log` | OnlyOffice 回调原始日志 | `OnlyOfficeCallbackService` |
| `def_permission_audit` | 权限变更审计（阶段 6） | 阶段 6 实现 |

**关系**：`sys_sql_log` 是底层 SQL 追踪；`def_contract_doc_op_log` 是业务层留痕；`def_doc_callback_log` 是 OnlyOffice 集成层专属日志。三者互补，不重复。

### 13.4 与缓存系统整合

| 缓存键 | 内容 | TTL | 失效时机 |
|---|---|---|---|
| `onlyoffice:doc:{docId}` | 文档配置 + 当前版本 | 1800s | 版本创建/回溯/标记 |
| `onlyoffice:health` | Document Server 健康状态 | 30s | 自动过期 |
| `onlyoffice:permissions:{userId}:{docId}` | 用户对该文档的权限 | 1800s | 权限变更（阶段 6） |
| `onlyoffice:versions:{docId}` | 版本列表（首页） | 60s | 版本创建/清理 |

**实现**：复用 `MetadataCache`，所有缓存键带表指纹校验，避免脏读。

### 13.5 与路由/过滤器整合

`app/Config/Filters.php` 调整：

```php
// jwt 过滤范围追加 onlyoffice/config、onlyoffice/history、contract-doc/*、permission-audit/*
public array $globals = [
    'before' => [
        'csrf' => ['except' => ['onlyoffice/callback']], // 回调走 JWT 验签，不走 CSRF
    ],
];

// onlyoffice/callback 与 onlyoffice/download 排除用户 JWT，改走 OnlyOffice JWT + IP 白名单
$filters = [
    'jwt' => [
        'before' => [
            'contract/*', 'contract-doc/*', 'workflow/*', 'workflow-todo/*',
            'onlyoffice/config/*', 'onlyoffice/history/*', 'onlyoffice/history-data/*',
            'permission-audit/*',
        ],
    ],
    'onlyofficeCallbackWhitelist' => [
        'before' => ['onlyoffice/callback'],
    ],
    'onlyofficeDownloadVerify' => [
        'before' => ['onlyoffice/download'],
    ],
];
```

---

## 十四、测试策略

### 14.1 单元测试

目录：`backend/tests/unit/`

| 测试类 | 覆盖范围 | 关键用例 |
|---|---|---|
| `OnlyOfficeJwtServiceTest` | JWT 签名/验签 | 正常签名 + 验签通过、篡改 payload 验签失败、过期 token 验签失败、空 token 验签失败 |
| `OnlyOfficeConfigBuilderTest` | 配置构建 | 不同 documentType 推断、不同角色 permissions 映射、document.key 生成规则、callbackUrl 拼装、降级模式（DS 不可达） |
| `OnlyOfficeCallbackServiceTest` | 回调处理 | status 0/1/2/3/4/6/7 全覆盖、幂等校验、下载失败重试、版本号并发安全、forcesavetype 分支 |
| `OnlyOfficeVersionServiceTest` | 版本管理 | 版本创建、版本标记、版本回溯（不删除原版本）、版本清理（保留标记版本）、文件路径生成 |
| `OnlyOfficeDownloadServiceTest` | 文档下载 | 签名校验、TTL 校验、权限校验、文件不存在处理、流式输出 |
| `ContractDocServiceTest` | 合同文档业务 | 文档归属校验、与 OnlyOfficeConfigBuilder 集成、归档后只读 |

### 14.2 集成测试

目录：`backend/tests/integration/`

| 测试类 | 覆盖范围 |
|---|---|
| `OnlyOfficeCallbackIntegrationTest` | 回调 → 下载 → 创建版本 → 写留痕 全流程；幂等重试；并发回调 |
| `OnlyOfficeVersionIntegrationTest` | 版本创建 → 列表查询 → 对比数据 → 回溯 → 清理 全流程 |
| `OnlyOfficeSecurityIntegrationTest` | 验签失败、IP 白名单、文档归属、权限映射 |
| `ContractDocIntegrationTest` | 合同文档从创建到归档全生命周期，含 OnlyOffice 集成 |

### 14.3 Mock Document Server 测试（未部署时）

```php
// backend/tests/mock/OnlyOfficeMockServer.php
class OnlyOfficeMockServer
{
    public function serveConfig(): array { /* 返回模拟配置 */ }
    public function serveDownload(): string { /* 返回测试 docx 内容 */ }
    public function triggerCallback(string $callbackUrl, array $payload, string $jwtSecret): void
    {
        $token = JWT::encode($payload, $jwtSecret, 'HS256');
        $ch = curl_init($callbackUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
```

**用例**：

- 模拟 status=2 回调，验证版本创建；
- 模拟 status=6 回调，验证 forceSave；
- 模拟验签失败的回调（错误密钥），验证 401；
- 模拟并发回调（同 key 同 status），验证幂等。

### 14.4 联调测试（部署后）

| 场景 | 验证点 |
|---|---|
| 单用户打开编辑 | 配置获取、文档加载、关闭保存、版本创建 |
| 双用户协同编辑 | 实时同步、协同状态记录、协同退出保存 |
| forceSave | 手动 forceSave 触发版本、定时 forceSave 触发版本 |
| 版本历史 | 列表展示、版本对比、回溯 |
| 权限映射 | 不同角色 permissions 生效（编辑/下载/打印/评论） |
| 异常 | DS 不可达降级、回调验签失败 401、下载失败重试 |
| 留痕 | 打开/编辑/保存/下载/版本事件均有记录 |

---

## 十五、部署与运维

### 15.1 环境变量配置清单

| 变量 | 必填 | 示例 | 说明 |
|---|---|---|---|
| `ONLYOFFICE_URL` | 是 | `http://onlyoffice.example.com` | Document Server 地址 |
| `ONLYOFFICE_JWT_SECRET` | 是 | `openssl rand -base64 48` 生成 | JWT 密钥 |
| `ONLYOFFICE_CALLBACK_BASE_URL` | 是 | `http://mis-backend.example.com` | 后端回调基址 |
| `ONLYOFFICE_VERSION_ON_FORCE_SAVE` | 否 | `true` | forceSave 是否创建版本 |
| `ONLYOFFICE_DOC_STORAGE_PATH` | 否 | `WRITEPATH/uploads/onlyoffice/` | 文档存储路径 |
| `ONLYOFFICE_CALLBACK_WHITELIST` | 否 | `10.0.0.0/8,172.16.0.0/12` | 回调 IP 白名单 |
| `ONLYOFFICE_DOWNLOAD_URL_TTL` | 否 | `300` | 下载链接 TTL（秒） |
| `ONLYOFFICE_VERSION_KEEP` | 否 | `50` | 版本保留数量 |
| `VITE_ONLYOFFICE_URL`（前端） | 是 | `http://onlyoffice.example.com` | 前端 api.js 加载地址 |

### 15.2 Document Server 健康检查

```php
// app/Libraries/OnlyOffice/OnlyOfficeHealthCheck.php
class OnlyOfficeHealthCheck
{
    public function isAvailable(): bool
    {
        $cache = Services::cache();
        $cached = $cache->get('onlyoffice:health');
        if ($cached !== null) {
            return (bool) $cached;
        }

        $url = rtrim(config('OnlyOffice')->url, '/') . '/healthcheck';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $available = ($code === 200 && trim($resp) === 'true');
        $cache->save('onlyoffice:health', $available, 30);
        return $available;
    }
}
```

**监控**：

- 运维通过 Prometheus / Grafana 监控 `/healthcheck`；
- 应用层 30s 缓存，避免每次请求都探测；
- 健康检查失败触发降级（见 10.4）。

### 15.3 回调日志监控

**SQL 监控脚本**（可接入运维 BI）：

```sql
-- 最近 1 小时回调失败统计
SELECT status, COUNT(*) AS cnt
FROM def_doc_callback_log
WHERE 接收时间 > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY status;

-- 回调处理慢日志（> 5s）
SELECT 文档Key, status, 处理耗时毫秒, 错误信息, 接收时间
FROM def_doc_callback_log
WHERE 处理耗时毫秒 > 5000
ORDER BY 接收时间 DESC
LIMIT 100;

-- 验签失败（安全事件）
SELECT 接收时间, 载荷, 错误信息
FROM def_doc_callback_log
WHERE 处理结果 = '验签失败'
ORDER BY 接收时间 DESC;
```

### 15.4 版本存储空间监控

```php
// app/Commands/OnlyOfficeStorageStats.php
public function run(array $params): void
{
    $path = config('OnlyOffice')->docStoragePath;
    $totalSize = 0;
    $fileCount = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $totalSize += $file->getSize();
            $fileCount++;
        }
    }
    $freeSpace = disk_free_space($path);

    log_message('info', sprintf(
        'OnlyOffice storage: %d files, %.2f MB used, %.2f MB free',
        $fileCount,
        $totalSize / 1024 / 1024,
        $freeSpace / 1024 / 1024
    ));

    if ($freeSpace < 1024 * 1024 * 1024) { // < 1GB
        log_message('error', 'OnlyOffice storage low: ' . $freeSpace);
        // 告警
    }
}
```

**执行**：通过 crontab 每日执行 `php spark onlyoffice:storage-stats`。

### 15.5 故障排查指南

| 现象 | 可能原因 | 排查步骤 |
|---|---|---|
| 编辑器加载失败，显示空白 | api.js 加载失败 / DS 不可达 | 1. 浏览器控制台查看 script.onerror；2. `curl ONLYOFFICE_URL/healthcheck`；3. 检查 VITE_ONLYOFFICE_URL 配置 |
| 编辑器显示"签名无效" | JWT 密钥不一致 | 1. 检查后端 `ONLYOFFICE_JWT_SECRET`；2. 检查 DS `local.json` 中 `secret`；3. 确认两端密钥一致 |
| 回调 401 | 验签失败 | 1. 查看 `def_doc_callback_log` 处理结果；2. 检查密钥；3. 检查 token 是否在 Authorization 头 |
| 回调 403 | IP 白名单 | 1. 检查 `ONLYOFFICE_CALLBACK_WHITELIST`；2. 确认 DS 出口 IP |
| 保存后版本未创建 | 回调未到达 / 处理失败 | 1. 查 `def_doc_callback_log` 是否有记录；2. 查 `def_contract_doc_op_log` 是否有版本创建；3. 查 DS 日志是否发送回调 |
| 版本号跳号 | 并发回调 / 幂等跳过 | 1. 查 `def_doc_callback_log` 是否有重复 key+status；2. 确认是否多个用户同时关闭 |
| 协同不同步 | DS 协同服务异常 | 1. 检查 WebSocket 代理（Apache mod_proxy_wstunnel 配置）；2. 检查 DS 日志 |
| 文档打开是旧版本 | document.key 未变更 | 1. 查 `def_contract_doc.文档Key` 是否更新；2. 查 `OnlyOfficeVersionService::createVersion()` 是否更新 key |
| 下载链接过期 | TTL 太短 | 1. 调整 `ONLYOFFICE_DOWNLOAD_URL_TTL`；2. 检查 DS 与后端网络延迟 |
| 存储空间不足 | 版本未清理 | 1. 检查 `ONLYOFFICE_VERSION_KEEP`；2. 执行 `php spark onlyoffice:cleanup-versions` |

---

## 附录 A：路由汇总

```php
// app/Config/Routes.php 新增
$routes->group('onlyoffice', static function ($routes) {
    $routes->get('config/(:segment)', 'OnlyOfficeApi::config/$1');
    $routes->post('callback', 'OnlyOfficeApi::callback');
    $routes->get('download', 'OnlyOfficeApi::download');
    $routes->get('history/(:segment)', 'OnlyOfficeApi::history/$1');
    $routes->get('history-data/(:segment)', 'OnlyOfficeApi::historyData/$1');
    $routes->post('oplog', 'OnlyOfficeApi::oplog'); // 前端事件留痕
});
```

## 附录 B：文件清单

### 后端

```
app/
├── Config/
│   └── OnlyOffice.php                                    # 配置封装
├── Controllers/
│   └── OnlyOfficeApi.php                                 # API 控制器
├── Filters/
│   ├── OnlyOfficeCallbackWhitelist.php                   # 回调 IP 白名单
│   └── OnlyOfficeDownloadVerify.php                      # 下载签名校验
├── Libraries/
│   └── OnlyOffice/
│       ├── OnlyOfficeJwtService.php                      # JWT 签名/验签
│       ├── OnlyOfficeConfigBuilder.php                   # 配置构建
│       └── OnlyOfficeHealthCheck.php                     # 健康检查
├── Services/
│   └── OnlyOffice/
│       ├── OnlyOfficeCallbackService.php                 # 回调处理
│       ├── OnlyOfficeVersionService.php                  # 版本管理
│       └── OnlyOfficeDownloadService.php                 # 文档下载
├── Models/
│   ├── ContractDocModel.php
│   ├── ContractDocVersionModel.php
│   ├── ContractDocOpLogModel.php
│   └── DocCallbackLogModel.php
├── Database/Migrations/
│   ├── 2026-07-23-200002_CreateContractDoc.php
│   ├── 2026-07-23-200003_CreateContractDocVersion.php
│   ├── 2026-07-23-200004_CreateContractDocOpLog.php
│   └── 2026-07-23-400001_CreateDocCallbackLog.php
└── Commands/
    ├── OnlyOfficeStorageStats.php                        # 存储统计
    └── OnlyOfficeCleanupVersions.php                     # 版本清理
```

### 前端

```
src/
├── components/onlyoffice/
│   ├── OnlyOfficeEditor.vue                              # 通用编辑器组件
│   ├── OnlyOfficeVersionPanel.vue                        # 版本面板
│   └── OnlyOfficeOpLogPanel.vue                          # 留痕面板
├── service/api/
│   └── onlyoffice.ts                                     # API 封装
└── typings/api/
    └── onlyoffice.d.ts                                   # 类型定义
```

---

## 附录 C：典型配置示例

### C.1 完整编辑器配置（带 JWT）

```json
{
  "documentType": "word",
  "document": {
    "fileType": "docx",
    "key": "contract_1001_v3_1721712000",
    "title": "合同-2026-0001.docx",
    "url": "http://mis-backend.example.com/onlyoffice/download?token=eyJ0eXA...",
    "permissions": {
      "download": true,
      "edit": true,
      "print": true,
      "review": true,
      "comment": true,
      "fillForms": true,
      "modifyFilter": true,
      "modifyContentControl": true,
      "copy": true
    }
  },
  "editorConfig": {
    "mode": "edit",
    "lang": "zh-CN",
    "callbackUrl": "http://mis-backend.example.com/onlyoffice/callback",
    "user": {
      "id": "u1001",
      "name": "张三",
      "group": "法务部"
    },
    "customization": {
      "autosave": true,
      "forcesave": true,
      "coAuthoring": true,
      "chat": true,
      "comment": true,
      "compactHeader": false,
      "toolbarNoTabs": false,
      "zoom": 100,
      "spellcheck": true
    }
  },
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJkb2N1bWVudFR5cGUiOiJ3b3JkIiwiZG9jdW1lbnQiOnsiZmlsZVR5cGUiOiJkb2N4Iiwia2V5IjoiY29udHJhY3RfMTAwMV92M18xNzIxNzEyMDAwIiwidGl0bGUiOiLlhbnmg7DlnLDmnYLkuI3lj7EtMDAwMS5kb2N4IiwidXJsIjoiaHR0cDovL21pcy1iYWNrZW5kLmV4YW1wbGUuY29tL29ubHlvZmZpY2UvZG93bmxvYWQ_dG9rZW49ZXlKMGVYQWkiLCJwZXJtaXNzaW9ucyI6eyJkb3dubG9hZCI6dHJ1ZSwiZWRpdCI6dHJ1ZSwicHJpbnQiOnRydWUsInJldmlldyI6dHJ1ZSwiY29tbWVudCI6dHJ1ZSwiZmlsbEZvcm1zIjp0cnVlLCJtb2RpZnlGaWx0ZXIiOnRydWUsIm1vZGlmeUNvbnRlbnRDb250cm9sIjp0cnVlLCJjb3B5Ijp0cnVlfX0sImVkaXRvckNvbmZpZyI6eyJtb2RlIjoiZWRpdCIsImxhbmciOiJ6aC1DTiIsImNhbGxiYWNrVXJsIjoiaHR0cDovL21pcy1iYWNrZW5kLmV4YW1wbGUuY29tL29ubHlvZmZpY2UvY2FsbGJhY2siLCJ1c2VyIjp7ImlkIjoidTEwMDEiLCJuYW1lIjoi5byg5LiJIiwiZ3JvdXAiOiLms6jlhpnlm6IifSwiY3VzdG9taXphdGlvbiI6eyJhdXRvc2F2ZSI6dHJ1ZSwiZm9yY2VzYXZlIjp0cnVlLCJjb0F1dGhvcmluZyI6dHJ1ZSwiY2hhdCI6dHJ1ZSwiY29tbWVudCI6dHJ1ZSwiY29tcGFjdEhlYWRlciI6ZmFsc2UsInRvb2xiYXJOb1RhYnMiOmZhbHNlLCJ6b29tIjoxMDAsInNwZWxsY2hlY2siOnRydWV9fX0.signature"
}
```

### C.2 回调请求示例

```http
POST /onlyoffice/callback HTTP/1.1
Host: mis-backend.example.com
Content-Type: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJrZXkiOiJjb250cmFjdF8xMDAxX3YzXzE3MjE3MTIwMDAiLCJzdGF0dXMiOjIsInVybCI6Imh0dHA6Ly9vbmx5b2ZmaWNlLmV4YW1wbGUuY29tL2NhY2hlL2ZpbGVzL2V5SjAiLCJ1c2VycyI6WyJ1MTAwMSIsInU1MDAxIl0sImZvcmNlc2F2ZXR5cGUiOjB9.signature

{
  "key": "contract_1001_v3_1721712000",
  "status": 2,
  "url": "http://onlyoffice.example.com/cache/files/eyJ0eXAi/output.docx",
  "users": ["u1001", "u5001"],
  "forcesavetype": 0
}
```

### C.3 版本历史响应示例

```json
{
  "currentVersion": 3,
  "history": [
    {
      "version": 1,
      "key": "contract_1001_v1_1721700000",
      "created": "2026-07-23 10:00:00",
      "user": { "id": "u1001", "name": "张三" },
      "changes": null,
      "serverVersion": "8.0.0"
    },
    {
      "version": 2,
      "key": "contract_1001_v2_1721705000",
      "created": "2026-07-23 12:00:00",
      "user": { "id": "u5001", "name": "李四" },
      "changes": [{ "author": "李四", "created": "2026-07-23 12:00:00" }],
      "serverVersion": "8.0.0"
    },
    {
      "version": 3,
      "key": "contract_1001_v3_1721712000",
      "created": "2026-07-23 14:00:00",
      "user": { "id": "u1001", "name": "张三" },
      "changes": [{ "author": "张三", "created": "2026-07-23 14:00:00" }],
      "serverVersion": "8.0.0"
    }
  ]
}
```

### C.4 版本数据响应示例

```json
{
  "version": 3,
  "key": "contract_1001_v3_1721712000",
  "url": "http://mis-backend.example.com/onlyoffice/download?token=eyJ0eXA...&versionId=3",
  "previous": {
    "version": 2,
    "key": "contract_1001_v2_1721705000",
    "url": "http://mis-backend.example.com/onlyoffice/download?token=eyJ0eXA...&versionId=2"
  },
  "changes": null
}
```

---

## 附录 D：术语表

| 术语 | 说明 |
|---|---|
| Document Server | OnlyOffice 文档服务端，负责文档编辑、协同、转换 |
| DocsAPI | OnlyOffice 前端 JavaScript API，通过 api.js 加载 |
| document.key | 文档版本唯一标识，变更后强制重新加载 |
| callback | Document Server 主动调用后端的事件通知机制 |
| forceSave | 强制保存，不关闭文档的情况下触发版本快照 |
| 协同编辑 | 多用户同时编辑同一文档，基于 OT 算法实时同步 |
| 版本快照 | 某一时刻文档的完整副本，用于历史追溯 |
| 版本回溯 | 将文档内容恢复到历史版本（通过创建新版本实现，不删除原版本） |
| 幂等性 | 同一回调重复处理不会产生副作用 |
| JWT | JSON Web Token，本方案中用于配置签名与回调验签 |
| OT | Operational Transformation，操作变换算法，协同编辑的核心 |

---

> 本文档为 OnlyOffice 应用层集成的设计与实施依据，编码实现需严格遵循。如遇 OnlyOffice 官方文档与本方案冲突，以官方文档为准并同步更新本方案。
