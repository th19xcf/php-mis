# MIS 系统 Windows 部署计划

## 项目概述

MIS 系统是一个前后端分离的管理信息系统，需要在 Windows 环境下部署 Apache + PHP 后端和 Node.js 前端。

### 技术栈要求
| 组件 | 版本要求 |
|------|----------|
| 后端框架 | PHP 8.1+ + CodeIgniter 4 |
| 前端框架 | Node.js 20.19+ + Vue 3 + Vite |
| 包管理器 | pnpm 10.5+ |
| 数据库 | MySQL 8.0+ |
| Web服务器 | Apache 2.4+ |

---

## 第一阶段：基础环境准备

### 1.1 安装 Chocolatey（Windows 包管理器）

**目标**：安装 Chocolatey 简化后续软件安装

**步骤**：
1. 以管理员身份打开 PowerShell
2. 执行安装命令：
   ```powershell
   Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1'))
   ```
3. 验证安装：`choco --version`

**预期输出**：Chocolatey 版本号

---

### 1.2 安装基础开发工具

**目标**：安装 Git、Node.js、pnpm

**步骤**：
1. 安装 Git：
   ```powershell
   choco install git -y
   ```

2. 安装 Node.js 20 LTS：
   ```powershell
   choco install nodejs-lts --version=20.19.0 -y
   ```

3. 安装 pnpm：
   ```powershell
   npm install -g pnpm@10.5.0
   ```

4. 验证安装：
   ```powershell
   git --version
   node --version  # 应显示 v20.19.0 或更高
   pnpm --version  # 应显示 10.5.0 或更高
   ```

**预期输出**：各工具版本号

---

## 第二阶段：后端环境部署

### 2.1 安装 Apache 2.4

**目标**：安装并配置 Apache Web 服务器

**步骤**：
1. 下载 Apache Lounge Windows 版本：
   - 访问：https://www.apachelounge.com/download/
   - 下载：httpd-2.4.62-win64-VS17.zip（最新稳定版）

2. 解压到 `C:\Apache24`

3. 安装 Visual C++ Redistributable：
   - 下载并安装 VC17 运行时

4. 配置环境变量：
   ```powershell
   [Environment]::SetEnvironmentVariable("Path", $env:Path + ";C:\Apache24\bin", "Machine")
   ```

5. 安装 Apache 服务：
   ```powershell
   cd C:\Apache24\bin
   httpd.exe -k install
   ```

6. 测试配置：
   ```powershell
   httpd.exe -t
   ```

7. 启动 Apache：
   ```powershell
   httpd.exe -k start
   ```

8. 验证：浏览器访问 http://localhost，应显示 "It works!"

---

### 2.2 安装 PHP 8.3

**目标**：安装 PHP 8.3 并配置 Apache 模块

**步骤**：
1. 下载 PHP 8.3：
   - 访问：https://windows.php.net/download/
   - 下载：VS16 x64 Non Thread Safe 版本
   - 或 Zip 版本：php-8.3.10-Win32-vs16-x64.zip

2. 解压到 `C:\php8`

3. 复制 php.ini 配置文件：
   ```powershell
   cd C:\php8
   copy php.ini-development php.ini
   ```

4. 编辑 php.ini，启用必要扩展：
   ```ini
   extension_dir = "ext"
   extension=curl
   extension=fileinfo
   extension=gd
   extension=intl
   extension=mbstring
   extension=mysqli
   extension=pdo_mysql
   extension=openssl
   extension=zip
   ```

5. 配置 Apache 加载 PHP：
   - 编辑 `C:\Apache24\conf\httpd.conf`，添加：
   ```apache
   # PHP 8.3 Configuration
   LoadModule php_module "C:/php8/php8apache2_4.dll"
   AddHandler application/x-httpd-php .php
   PHPIniDir "C:/php8"
   ```

6. 添加索引文件：
   ```apache
   DirectoryIndex index.php index.html
   ```

7. 重启 Apache：
   ```powershell
   httpd.exe -k restart
   ```

8. 验证：
   - 创建 `C:\Apache24\htdocs\info.php`：
   ```php
   <?php phpinfo(); ?>
   ```
   - 浏览器访问 http://localhost/info.php，应显示 PHP 8.3 信息

---

### 2.3 安装 MySQL 8.4

**目标**：安装并配置 MySQL 数据库

**步骤**：
1. 下载 MySQL Installer：
   - 访问：https://dev.mysql.com/downloads/installer/
   - 下载：mysql-installer-community-8.4.2.msi

2. 运行安装程序，选择：
   - 安装类型：Server only
   - 版本：MySQL Server 8.4.2

3. 配置 MySQL：
   - 端口：3306
   - 认证方式：强密码认证
   - root 密码：设置强密码
   - Windows 服务名：MySQL84

4. 配置环境变量：
   ```powershell
   [Environment]::SetEnvironmentVariable("Path", $env:Path + ";C:\Program Files\MySQL\MySQL Server 8.4\bin", "Machine")
   ```

5. 验证安装：
   ```powershell
   mysql --version
   mysql -u root -p
   ```

6. 创建 MIS 数据库：
   ```sql
   CREATE DATABASE mis_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'mis_user'@'localhost' IDENTIFIED BY '强密码';
   GRANT ALL PRIVILEGES ON mis_db.* TO 'mis_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

---

### 2.4 安装 Composer

**目标**：安装 PHP 依赖管理器

**步骤**：
1. 下载并安装：
   ```powershell
   choco install composer -y
   ```

2. 验证：
   ```powershell
   composer --version
   ```

---

### 2.5 配置 MIS 后端

**目标**：部署 MIS 后端代码并配置

**步骤**：
1. 克隆或复制项目代码：
   ```powershell
   cd C:\Apache24\htdocs
   # 复制 mis 项目 backend 目录到此处
   ```

2. 安装 PHP 依赖：
   ```powershell
   cd C:\Apache24\htdocs\mis\backend
   composer install --no-dev --optimize-autoloader
   ```

3. 配置环境变量：
   - 复制 `.env.production.example` 为 `.env`
   - 编辑配置：
   ```ini
   CI_ENVIRONMENT = production
   
   # 数据库配置
   database.default.hostname = localhost
   database.default.database = mis_db
   database.default.username = mis_user
   database.default.password = 你的密码
   database.default.DBDriver = MySQLi
   
   # JWT 密钥（必须修改）
   JWT_SECRET = 你的256位随机密钥
   
   # 调试登录控制
   AUTH_DEBUG_ENABLED = false
   ```

4. 配置 Apache 虚拟主机：
   - 编辑 `C:\Apache24\conf\extra\httpd-vhosts.conf`：
   ```apache
   <VirtualHost *:80>
       ServerName mis-backend.local
       DocumentRoot "C:/Apache24/htdocs/mis/backend/public"
       
       <Directory "C:/Apache24/htdocs/mis/backend/public">
           Options Indexes FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
       
       ErrorLog "logs/mis-backend-error.log"
       CustomLog "logs/mis-backend-access.log" common
   </VirtualHost>
   ```

5. 启用重写模块和虚拟主机：
   ```apache
   LoadModule rewrite_module modules/mod_rewrite.so
   Include conf/extra/httpd-vhosts.conf
   ```

6. 创建 .htaccess 文件（如不存在）：
   - 在 `public` 目录创建：
   ```apache
   <IfModule mod_rewrite.c>
       Options +FollowSymlinks
       RewriteEngine On
       
       # 如果请求的是文件或目录，直接访问
       RewriteCond %{REQUEST_FILENAME} !-f
       RewriteCond %{REQUEST_FILENAME} !-d
       
       # 否则重写到 index.php
       RewriteRule ^(.*)$ index.php/$1 [L]
   </IfModule>
   ```

7. 设置目录权限：
   ```powershell
   # 确保 Apache 有写入权限
   icacls "C:\Apache24\htdocs\mis\backend\writable" /grant IIS_IUSRS:F /T
   icacls "C:\Apache24\htdocs\mis\backend\writable" /grant Users:F /T
   ```

8. 重启 Apache：
   ```powershell
   httpd.exe -k restart
   ```

9. 验证后端：
   - 浏览器访问：http://mis-backend.local
   - 应返回 JSON 响应或前端页面

---

## 第三阶段：前端环境部署

### 3.1 部署 MIS 前端

**目标**：构建并部署前端应用

**步骤**：
1. 进入前端目录：
   ```powershell
   cd C:\Apache24\htdocs\mis\frontend
   ```

2. 安装依赖：
   ```powershell
   pnpm install
   ```

3. 配置生产环境：
   - 复制 `.env.prod` 为 `.env.production.local`
   - 编辑配置：
   ```env
   VITE_SERVICE_BASE_URL=http://mis-backend.local
   VITE_OTHER_API_URL=http://mis-backend.local/api
   ```

4. 构建生产版本：
   ```powershell
   pnpm run build
   ```

5. 部署构建产物：
   - 将 `dist` 目录内容复制到 Apache 目录：
   ```powershell
   xcopy /E /I dist\* C:\Apache24\htdocs\mis\frontend-dist\
   ```

---

### 3.2 配置前端虚拟主机

**目标**：配置 Apache 服务前端应用

**步骤**：
1. 编辑 `C:\Apache24\conf\extra\httpd-vhosts.conf`，添加：
   ```apache
   <VirtualHost *:80>
       ServerName mis.local
       DocumentRoot "C:/Apache24/htdocs/mis/frontend-dist"
       
       <Directory "C:/Apache24/htdocs/mis/frontend-dist">
           Options Indexes FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
       
       # 前端路由支持（单页应用）
       <IfModule mod_rewrite.c>
           RewriteEngine On
           RewriteCond %{REQUEST_FILENAME} !-f
           RewriteCond %{REQUEST_FILENAME} !-d
           RewriteRule ^(.*)$ /index.html [L]
       </IfModule>
       
       ErrorLog "logs/mis-frontend-error.log"
       CustomLog "logs/mis-frontend-access.log" common
   </VirtualHost>
   ```

2. 配置 hosts 文件：
   - 编辑 `C:\Windows\System32\drivers\etc\hosts`，添加：
   ```
   127.0.0.1 mis.local
   127.0.0.1 mis-backend.local
   ```

3. 重启 Apache：
   ```powershell
   httpd.exe -k restart
   ```

4. 验证前端：
   - 浏览器访问：http://mis.local
   - 应显示登录页面

---

## 第四阶段：安全配置

### 4.1 PHP 安全配置

**目标**：加固 PHP 配置

**步骤**：
1. 编辑 `C:\php8\php.ini`：
   ```ini
   ; 禁用危险函数
   disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source
   
   ; 限制资源使用
   memory_limit = 256M
   upload_max_filesize = 10M
   post_max_size = 10M
   max_execution_time = 30
   max_input_time = 60
   max_input_vars = 3000
   
   ; 错误处理（生产环境）
   display_errors = Off
   display_startup_errors = Off
   log_errors = On
   error_log = "C:/Apache24/logs/php_errors.log"
   
   ; 会话安全
   session.cookie_httponly = 1
   session.cookie_secure = 1
   session.use_strict_mode = 1
   ```

---

### 4.2 Apache 安全配置

**目标**：加固 Apache 配置

**步骤**：
1. 编辑 `C:\Apache24\conf\httpd.conf`：
   ```apache
   # 隐藏版本信息
   ServerTokens Prod
   ServerSignature Off
   
   # 禁用目录列表
   Options -Indexes
   
   # 启用 HTTP/2（如需要）
   # LoadModule http2_module modules/mod_http2.so
   # Protocols h2 h2c http/1.1
   ```

2. 配置 SSL（生产环境必需）：
   ```powershell
   # 使用 Let's Encrypt 或自签名证书
   # 配置在 httpd-ssl.conf
   ```

---

### 4.3 MySQL 安全配置

**目标**：加固 MySQL 配置

**步骤**：
1. 运行安全脚本：
   ```powershell
   mysql_secure_installation
   ```

2. 删除匿名用户和测试数据库
3. 禁用远程 root 登录

---

## 第五阶段：验证与测试

### 5.1 功能验证清单

- [ ] 访问 http://mis.local 显示登录页面
- [ ] 使用合法账号登录成功
- [ ] 获取用户信息正常
- [ ] 动态菜单加载正常
- [ ] 数据查询功能正常
- [ ] 数据钻取功能正常
- [ ] Token 续签正常
- [ ] 页面刷新后保持登录状态

### 5.2 性能验证

- [ ] 首页加载时间 < 3秒
- [ ] API 响应时间 < 500ms
- [ ] 并发用户数 > 50（根据需求）

### 5.3 安全验证

- [ ] 调试登录入口已禁用
- [ ] API 鉴权正常工作
- [ ] SQL 注入防护有效
- [ ] XSS 防护有效

---

## 第六阶段：维护与监控

### 6.1 日志监控

**日志位置**：
- Apache 访问日志：`C:\Apache24\logs\`
- PHP 错误日志：`C:\Apache24\logs\php_errors.log`
- MySQL 日志：`C:\ProgramData\MySQL\MySQL Server 8.4\Data\`
- MIS 应用日志：`C:\Apache24\htdocs\mis\backend\writable\logs\`

### 6.2 备份策略

**数据库备份**：
```powershell
# 每日备份脚本
mysqldump -u mis_user -p mis_db > C:\backup\mis_db_%date:~0,4%%date:~5,2%%date:~8,2%.sql
```

**代码备份**：
- 使用 Git 定期提交
- 备份配置文件

### 6.3 更新维护

**PHP 更新**：
1. 下载新版本 PHP
2. 备份 php.ini
3. 替换 PHP 目录
4. 恢复配置并测试

**前端更新**：
1. 拉取最新代码
2. 重新构建：pnpm run build
3. 替换 dist 目录

---

## 常见问题排查

### Q1: Apache 无法启动
- 检查端口 80 是否被占用：`netstat -ano | findstr :80`
- 检查配置文件语法：`httpd.exe -t`
- 查看错误日志：`C:\Apache24\logs\error.log`

### Q2: PHP 页面显示源代码
- 确认 LoadModule 配置正确
- 确认 AddHandler 配置正确
- 确认 PHPIniDir 指向正确

### Q3: MySQL 连接失败
- 检查 MySQL 服务是否运行
- 检查防火墙设置
- 验证用户名密码
- 检查数据库权限

### Q4: 前端构建失败
- 确认 Node.js 版本 >= 20.19
- 确认 pnpm 版本 >= 10.5
- 删除 node_modules 重新安装
- 检查环境变量配置

---

## 附录：软件版本汇总

| 软件 | 版本 | 下载地址 |
|------|------|----------|
| Apache | 2.4.62 | https://www.apachelounge.com/download/ |
| PHP | 8.3.10 | https://windows.php.net/download/ |
| MySQL | 8.4.2 | https://dev.mysql.com/downloads/installer/ |
| Node.js | 20.19.0 LTS | https://nodejs.org/ 或 choco |
| pnpm | 10.5.0 | npm install -g pnpm |
| Composer | 最新 | https://getcomposer.org/ 或 choco |

---

*计划创建日期：2026-04-24*  
*计划版本：v1.0*
