# WP to Cloudflare Workers

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.3%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

将 WordPress 网站转换为静态 HTML 并部署到 **Cloudflare Workers 静态资源（Static Assets）**（替代已弃用的 Pages）。支持**内网后端与公网静态前端分离**架构，并通过 Worker 处理表单、评论、邮件等动态功能，让站点在获得 CDN 级速度与安全性的同时保留互动能力。

> **v1.6 起：从 Cloudflare Pages 迁移到 Cloudflare Workers。** Cloudflare 官方已宣布用 Workers 静态资源取代 Pages。除静态托管外，Workers 还能运行服务端代码，因此本插件新增了「Worker 动态后端」：表单/评论提交由 Worker 接收并暂存到 D1、发送通知邮件，WordPress 通过定时任务出站拉取入库——内网 WordPress 无需暴露公网。

## 🎯 核心特性

- **全站静态导出** - 自动收集所有页面、文章、产品、分类等
- **资源自动收集** - CSS、JS、图片、字体等资源自动提取和重写路径
- **一键部署** - 直接部署到 Cloudflare Workers，无需手动上传
- **增量更新** - 基于内容哈希的智能增量上传，节省带宽
- **🆕 Worker 动态后端** - 表单/评论提交 → D1 暂存 → 邮件通知 → WordPress 出站拉取
- **🆕 边缘邮件发送** - 支持 AWS SES、通用 HTTP API（Resend 等）、SMTP（587/465）
- **🆕 提交留档** - 发出的邮件内容与状态一并存入 D1，发送失败也可拉取信息人工补救
- **WooCommerce 支持** - 完整支持产品页面和分类页面导出
- **Elementor 兼容** - 自动处理 Elementor 构建的页面和轮播组件
- **SEO 友好** - 自动生成 sitemap、robots.txt，重写 canonical URL
- **代码注入** - 支持注入统计代码（Google Analytics、GTM 等）
- **WordPress 痕迹清理** - 自动移除 WordPress 特征，提升安全性

## 📋 系统要求

- WordPress 6.0+
- PHP 7.3+（推荐 8.0+）
- Cloudflare 账户（免费版即可，Workers 静态资源与 D1 免费额度足够中小站点）

## 🚀 快速开始

### 1. 安装插件

```bash
# 方式一：上传 ZIP 包
# 下载 wp-to-cf-x.x.x.zip，在 WordPress 后台上传安装

# 方式二：手动安装
# 将插件文件夹复制到 wp-content/plugins/wp-to-cf/
```

### 2. 配置 Cloudflare API（自动上传需要）

> 如果只使用 ZIP 下载手动上传，可跳过此步骤。

1. 登录 [Cloudflare Dashboard](https://dash.cloudflare.com/) → 我的个人资料 → [API 令牌](https://dash.cloudflare.com/profile/api-tokens)
2. 点击「创建令牌」→ 选择「创建自定义令牌」
3. 设置以下权限：

| 权限 | 访问级别 | 用途 |
|------|----------|------|
| 帐户 → Workers 脚本 | 编辑 | 部署 Worker 与静态资源（必需） |
| 帐户 → D1 | 编辑 | 创建数据库、暂存表单/评论（启用动态后端时需要） |
| 区域 → 区域 | 读取 | 获取域名列表（可选） |

4. 账户资源：包括 → 所有帐户（或选择特定账户）
5. 区域资源：包括 → 所有区域（或选择特定区域）
6. 点击「继续以显示摘要」→「创建令牌」→ 复制令牌

### 3. 插件配置

1. 进入 WordPress 后台 → WP to CF
2. **Cloudflare 配置**标签：填写 Account ID、API Token、Worker 名称
3. 点击「验证并获取列表」确认配置正确
4. 设置 Production Domain（用于 SEO 标签重写）
5. （可选）配置代码注入

### 4. 导出部署

**方式一：自动上传（推荐）**
1. 点击「全量上传」或「增量上传」按钮
2. 等待导出和部署完成
3. 访问你的 Worker 域名（`*.workers.dev` 或自定义域名）查看站点

**方式二：手动上传**
1. 点击「导出为 ZIP」按钮下载 ZIP
2. 使用 Wrangler 或控制台部署静态资源

### 5. 绑定自定义域名（首次部署后）

1. 进入 Cloudflare 控制台 → Workers 和 Pages → 选择您的 Worker
2. 点击「设置」→「域和路由」→「添加」→「自定义域」
3. 输入您的域名（如 example.com 或 www.example.com）
4. 如果域名已在 Cloudflare，DNS 记录会自动配置；否则按提示添加 CNAME 记录

> 域名绑定只需操作一次，后续部署会自动更新。

## 🧩 Worker 动态后端（表单 / 评论 / 邮件）

静态站点本身无法处理表单提交，本插件在部署的 Worker 中内置了动态接口，配合 Cloudflare D1 完成收集与回传。

### 数据流

```
访客（静态站表单/评论）
      │  POST /__wptocf/submit | /__wptocf/comment
      ▼
Cloudflare Worker ──写入──▶ D1 (submissions, pending)
      │                         └─ 表单类：发送通知邮件(SES/HTTP/SMTP) → emails 表留档
      ▼
内网 WordPress ──定时出站──▶ GET /__wptocf/pull（Bearer 密钥）
      └─ 建评论 / 存表单提交 ──▶ POST /__wptocf/ack（标记已消费）
```

### 启用步骤

1. **Cloudflare 配置**标签：确保 Token 具备 **Workers 脚本:编辑** + **D1:编辑** 权限。
2. **Worker 后端**标签：
   - 勾选「启用 Worker 后端」
   - 点「创建并建表」自动创建 D1 数据库并初始化表结构（或选择已有库后点「初始化表结构」）
   - 选择邮件发送方式并填写配置（推荐 AWS SES）
   - 填写通知收件人 / 发件人
   - 保存（首次保存会自动生成 pull 鉴权密钥）
3. 回到导出页执行**「全量上传」**，将最新 Worker（含路由、D1 绑定、密钥、邮件配置）部署上去。

### 邮件发送方式

| 方式 | 说明 |
|------|------|
| **AWS SES** | 通过 HTTPS API（SigV4 签名）发送，稳定、不受端口限制（推荐） |
| **HTTP API** | 通用 Bearer 接口，兼容 Resend 等 |
| **SMTP** | 支持 587（STARTTLS）/ 465（TLS）；**不支持 25 端口**（Cloudflare 封锁） |

> 密钥（SES Secret、API Key、SMTP 密码、Turnstile 密钥、pull 密钥）在 WordPress 侧 AES 加密存储，部署时作为 Worker `secret_text` 绑定同步，Cloudflare 侧加密保管。

### 防垃圾（可选）

在 **Worker 后端**标签填写 Turnstile Site Key / Secret Key 后，Worker 会校验人机验证。需要你自行在表单页面放置 Turnstile 组件。

## 🏗️ 架构说明

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress (内网/本地)                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │ 内容管理     │  │ 主题/插件    │  │ WP to CF 插件       │  │
│  │ - 文章/页面 │  │ - Elementor │  │ - 静态导出/部署     │  │
│  │ - 产品      │  │ - WooCommerce│ │ - 定时出站拉取提交  │  │
│  └─────────────┘  └─────────────┘  └─────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
              │ 静态文件上传              ▲ GET /pull（出站）
              ▼                          │ POST /ack
┌─────────────────────────────────────────────────────────────┐
│                  Cloudflare Worker (公网)                    │
│  ┌───────────────────────────┐  ┌────────────────────────┐  │
│  │ 静态资源 (Static Assets)  │  │ 动态接口 /__wptocf/*   │  │
│  │ - HTML / CSS / JS         │  │ - /submit  /comment    │  │
│  │ - 图片 / 字体             │  │ - /pull    /ack        │  │
│  │ - sitemap.xml, robots.txt │  │ - 邮件发送(SES/HTTP/SMTP)│ │
│  └───────────────────────────┘  └───────────┬────────────┘  │
│                                              ▼               │
│                                    ┌──────────────────┐      │
│                                    │ Cloudflare D1     │      │
│                                    │ submissions/emails│      │
│                                    └──────────────────┘      │
│  ✅ 全球 CDN 加速  ✅ DDoS 防护  ✅ 免费 SSL               │
└─────────────────────────────────────────────────────────────┘
```

## 📁 导出文件结构

```
/
├── index.html                    # 首页
├── about/index.html              # 页面
├── blog/index.html               # 博客列表
├── blog/post-slug/index.html     # 文章
├── product/item-name/index.html  # WooCommerce 产品
├── product-category/xxx/index.html # 产品分类
├── assets/js/form-bridge.js      # 表单/评论提交桥接脚本
├── css/ ...                      # 样式
├── js/ ...                       # 脚本
├── images/ ...                   # 图片资源
├── fonts/ ...                    # 字体文件
├── sitemap_index.xml             # Sitemap 索引
├── sitemap-*.xml                 # 子 Sitemap
└── robots.txt                    # 爬虫规则
```

## ⚙️ 功能详解

### 静态导出

- **页面收集** - 自动收集所有公开的文章、页面、产品、分类
- **资源提取** - 从 HTML 中提取所有 CSS、JS、图片、字体引用
- **路径重写** - 将 `/wp-content/uploads/...` 重写为 `/images/...`
- **内容清理** - 移除 WordPress 管理相关的脚本和样式

### 部署到 Workers

- **直接上传（Direct Upload）** - 提交 manifest → 分批上传资源 → 部署 Worker 脚本
- **资源路由** - 启用 `html_handling`（`/about` → `/about/index.html`）与 `not_found_handling`（自定义 `/404.html`）
- **增量去重** - 未变化的文件通过 manifest 自动跳过上传

### 增量上传

- **内容哈希** - 基于文件内容计算 SHA256 哈希
- **智能对比** - 只上传有变化的文件
- **缓存管理** - 本地缓存已导出文件的哈希值

### WordPress 痕迹清理

自动移除以下内容：
- `<meta name="generator">` 标签
- WordPress REST API 链接
- wp-emoji 相关脚本
- wp-includes/js 目录下的脚本
- WooCommerce AJAX 脚本
- Elementor 配置脚本
- Contact Form 7 配置

### SEO 优化

- **Sitemap 生成** - 自动收集并处理 sitemap
- **robots.txt** - 自动生成包含 sitemap 链接的 robots.txt
- **Canonical URL** - 重写为生产域名
- **Open Graph** - 重写 og:url 和 twitter:url

### 代码注入

支持在以下位置注入自定义代码：
- `</head>` 前 - 适合 Google Analytics、GTM 头部代码
- `<body>` 后 - 适合 GTM noscript 标签
- `</body>` 前 - 适合统计脚本、聊天插件

## 🔧 高级配置

### 缓存管理

- **全量上传** - 使用缓存 + 上传所有变化的文件
- **增量上传** - 仅上传变化的文件（需要先有缓存）
- **清空缓存** - 强制下次全量重新生成

### 日志调试

启用 WordPress 调试日志：

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

日志位置：`wp-content/debug.log`

## 📦 文件结构

```
wp-to-cf/
├── wp-to-cf.php                 # 主插件文件
├── uninstall.php                # 卸载清理
├── admin/
│   ├── class-settings-page.php  # 设置页面类
│   └── views/
│       ├── settings-page.php    # Tab 框架
│       ├── tab-cloudflare.php   # Cloudflare 配置
│       ├── tab-backend.php      # 🆕 Worker 后端（D1/邮件/密钥）
│       └── ...
├── assets/js/
│   └── form-bridge.js           # 前端表单/评论提交桥接
└── includes/
    ├── class-core.php           # 核心类
    ├── class-site-exporter.php  # 静态导出器
    ├── class-cloudflare-api.php # Cloudflare Workers API 客户端
    ├── class-submission-sync.php# 提交拉取/入库（含 Worker 源）
    ├── worker/
    │   └── worker.js            # 🆕 部署的 Worker 脚本（静态资源+动态接口+邮件）
    └── utils/
        ├── class-logger.php     # 日志工具
        └── class-crypto.php     # 加密工具
```

## 🐛 常见问题

### Q: 从 Pages 升级到 Workers 需要做什么？
A: 将 API Token 权限从「Cloudflare Pages:编辑」改为「Workers 脚本:编辑」（启用动态后端再加「D1:编辑」），重新验证并「全量上传」即可。

### Q: 表单提交没反应 / 404？
A: 确认已启用 Worker 后端并配置 D1，且执行过「全量上传」（`form-bridge.js` 与 Worker 路由需一起部署）。可在「Worker 后端」标签点「测试连接」检查。

### Q: 邮件没收到？
A: 到「表单提交」查看记录；D1 的 `emails` 表会留档发送状态与错误。SES 需完成域名/发件人验证；SMTP 仅支持 587/465。

### Q: 部署后页面样式丢失？
A: 检查浏览器控制台是否有 404。清空缓存后重新全量上传。

### Q: 部署失败，网络错误？
A: 插件已内置重试机制（最多 3 次）。如持续失败，检查 API Token 权限与 Worker 名称。

## 📝 更新日志

### v1.6.1 (2026-07-31)
- 🌐 **多语言导出加固**:`/{lang}/` URL 展开尊重语言的 `enabled` 开关、跳过源语言冗余副本、防止重复前缀
- 🌐 **hreflang 绝对化**:将 `<link rel="alternate" hreflang>` 重写为生产域名绝对 URL(Google 要求 hreflang 为绝对地址),改善与 GML AI SEO 翻译模块的多语言 SEO 协同
- 🔧 **Worker SMTP 客户端重构**:修复 STARTTLS 升级后读写器/连接释放的问题,单函数流程更稳健(587 STARTTLS / 465 TLS)
- ⚠️ 代码注入面板新增"避免与 SEO 插件重复配置统计代码"的提示

### v1.6.0 (2026-07-30)
- 🚀 **从 Cloudflare Pages 迁移到 Cloudflare Workers 静态资源**
  - 采用 Workers Direct Upload 流程（manifest → 分批上传 → 部署脚本）
  - 保留增量去重；配置 `html_handling` / `not_found_handling` 复刻 Pages 路由行为
  - 自动尝试启用 `workers.dev` 子域名
- ✨ **新增 Worker 动态后端**
  - 表单/评论提交由 Worker 接收，暂存到 Cloudflare D1
  - 新增 `/__wptocf/submit`、`/comment`、`/pull`、`/ack`、`/health` 接口
  - WordPress 通过定时任务出站拉取入库，内网无需暴露公网
- ✨ **边缘邮件发送**：支持 AWS SES（SigV4）、通用 HTTP API、SMTP（587/465）
  - 发出的邮件内容与状态存入 D1，失败可拉取信息人工补救
- ✨ 新增「Worker 后端」设置页：D1 创建/建表、邮件后端、Turnstile、拉取密钥（加密存储）
- 🔒 敏感凭证以 Worker `secret_text` 绑定同步，Cloudflare 侧加密
- 📚 更新权限说明（Workers 脚本 + D1）与文档

### v1.5.0 (2026-02-09)
- ✨ 新增表单提交管理功能（独立页面、分组显示、查看/删除）
- ✨ 评论回流 WordPress 原生评论管理，表单显示在插件独立页面
- ✨ 插件国际化支持（英文默认 + zh_CN 语言包）
- ✨ 表单服务集成优化（form.huwencai.com、Getform/Forminit）
- 🔧 分块 AJAX 部署（解决共享主机超时），修复背景图路径重写

### v1.3.0 (2026-02-05)
- ✨ 脚本清理规则配置、作者/日期归档分页收集
- 🔧 优化 WooCommerce 产品分页，修复 Elementor 轮播显示

### v1.2.5-alpha2 (2026-02-04)
- ✨ API 令牌/域名绑定指南、凭证验证与项目下拉选择

### v1.2.0 (2026-02-02)
- ✨ 全新静态站点导出器、WooCommerce 支持、sitemap/robots 生成

## 📄 许可证

GPL v2 or later

## 👤 作者

- **网站**: [huwencai.com](https://huwencai.com)
- **GitHub**: [hwc0212/wp-to-cf](https://github.com/hwc0212/wp-to-cf)

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

---

**Made with ❤️ for WordPress**
