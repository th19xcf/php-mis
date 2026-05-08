# MIS 项目全面分析计划

## 项目概述

MIS 系统是一个前后端分离的管理信息系统，采用 **CodeIgniter 4 + Vue 3 (SoybeanAdmin)** 技术栈。

### 技术架构
| 层级 | 技术栈 | 版本 |
|------|--------|------|
| 前端 | Vue 3 + TypeScript + Vite | Vue 3.5.32, Vite 8.0.8 |
| 前端 UI | Naive UI + UnoCSS | Naive UI 2.44.1 |
| 前端状态 | Pinia + Vue Router | Pinia 3.0.4, Vue Router 5.0.4 |
| 后端 | PHP + CodeIgniter 4 | PHP 8.1+, CI4 |
| 认证 | JWT (firebase/php-jwt) | ^7.0 |
| 数据库 | MySQL | - |

---

## 分析目标

1. **架构评估**：评估当前架构的合理性和可扩展性
2. **代码质量**：分析代码规范、可维护性和潜在风险
3. **安全风险**：识别安全漏洞和防护缺陷
4. **性能分析**：识别性能瓶颈和优化点
5. **文档一致性**：确保文档与代码实现保持一致
6. **工程化**：评估构建、测试、部署流程

---

## 实施步骤

### 第一阶段：基线盘点

**目标**：建立项目基线，了解整体结构

**任务清单**：
- [ ] 确认技术栈版本和依赖
- [ ] 梳理目录结构和关键文件
- [ ] 记录运行命令和开发流程
- [ ] 识别首批待核验项

**产出物**：`analysis/01-基线盘点.md`

---

### 第二阶段：核心链路核验

**目标**：核验关键业务流程的前后端映射

**任务清单**：
- [ ] 登录流程：login → getUserInfo → 动态路由初始化
- [ ] Token 续签：refreshToken 机制
- [ ] 数据查询：workbench/query 链路
- [ ] 数据钻取：workbench/drill 链路
- [ ] 权限控制：路由守卫与接口鉴权

**产出物**：`analysis/02-核心链路核验.md`

---

### 第三阶段：风险识别与台账

**目标**：识别安全风险并建立台账

**任务清单**：
- [ ] **R-001 (高)**：调试登录入口安全 - `Auth.php`
- [ ] **R-002 (高)**：API 鉴权过滤器配置 - `Filters.php`
- [ ] **R-003 (中)**：CORS 分层配置 - `Cors.php`
- [ ] **R-004 (中)**：错误码统一管理
- [ ] **R-005 (中)**：数据库连接策略 - `Database.php`

**产出物**：`analysis/03-风险台账.md`

---

### 第四阶段：优化路线图

**目标**：制定分阶段优化计划

**任务清单**：
- [ ] 按优先级排序风险项
- [ ] 制定立刻/短期/中期实施计划
- [ ] 明确依赖关系和交付物
- [ ] 设定验收指标

**产出物**：`analysis/04-优化路线图.md`

---

### 第五阶段：文档一致性检查

**目标**：确保文档与代码一致

**任务清单**：
- [ ] 核对环境搭建文档 vs 代码实际
- [ ] 核对 API 文档完整性
- [ ] 核对错误码字典
- [ ] 核对部署文档

**产出物**：`analysis/05-文档一致性清单.md`

---

### 第六阶段：错误码契约与回归测试

**目标**：统一前后端错误码并建立回归测试

**任务清单**：
- [ ] 创建后端错误码常量：`ApiCode.php`
- [ ] 创建前端错误码配置：`service-code.ts`
- [ ] 编写后端回归测试：`AuthContractTest.php`
- [ ] 编写契约文档

**产出物**：
- `backend/app/Constants/ApiCode.php`
- `frontend/src/constants/service-code.ts`
- `backend/tests/unit/AuthContractTest.php`
- `analysis/07-错误码契约与回归测试.md`

---

### 第七阶段：安全加固实施

**目标**：修复高优先级安全风险

**任务清单**：
- [ ] **修复 R-001**：加固调试登录入口
  - 增加环境变量开关控制
  - 生产环境默认禁用
  - 增加审计日志
- [ ] **修复 R-002**：配置全局 API 鉴权过滤器
  - 创建 AuthFilter
  - 配置 frame/* 和 workbench/* 路由组鉴权
  - 排除公开接口
- [ ] **修复 R-003**：完善 CORS 分层配置
  - 按环境(dev/test/prod)配置 allowedOrigins
  - 配置 headers 和 methods
- [ ] **修复 R-005**：规范数据库连接策略
  - 统一默认连接策略
  - 增加启动自检

**产出物**：修复后的代码文件

---

### 第八阶段：性能优化分析

**目标**：识别性能瓶颈并提出优化建议

**任务清单**：
- [ ] 分析 SQL 查询性能
- [ ] 分析 API 响应时间
- [ ] 分析前端资源加载
- [ ] 提出缓存策略建议

**产出物**：`analysis/08-性能优化建议.md`

---

## 关键文件清单

### 后端核心文件
- `backend/app/Controllers/Auth.php` - 认证控制器
- `backend/app/Controllers/Frame.php` - 旧版框架控制器
- `backend/app/Controllers/Workbench.php` - 新版工作台控制器
- `backend/app/Config/Routes.php` - 路由配置
- `backend/app/Config/Filters.php` - 过滤器配置
- `backend/app/Config/Cors.php` - 跨域配置
- `backend/app/Config/Database.php` - 数据库配置
- `backend/app/Constants/ApiCode.php` - 错误码常量

### 前端核心文件
- `frontend/src/constants/service-code.ts` - 服务码配置
- `frontend/src/service/request/index.ts` - 请求拦截
- `frontend/src/store/modules/auth/index.ts` - 认证状态
- `frontend/src/router/guard/index.ts` - 路由守卫
- `frontend/src/views/menu-bridge/` - 动态菜单桥接

---

## 验收标准

1. **文档完整性**：所有分析阶段产出物完整
2. **风险修复率**：高优先级风险 100% 修复
3. **测试覆盖率**：核心链路回归测试覆盖
4. **代码质量**：通过类型检查和代码规范检查
5. **文档一致性**：文档与代码实现保持一致

---

## 风险评估

| 风险 | 影响 | 缓解措施 |
|------|------|----------|
| 分析范围过大 | 时间超预期 | 分阶段实施，优先核心链路 |
| 安全修复影响现有功能 | 回归风险 | 充分测试，小步提交 |
| 文档维护成本高 | 文档过时 | 建立文档更新机制 |

---

*计划创建日期：2026-04-24*  
*计划版本：v1.0*
