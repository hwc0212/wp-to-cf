# WP to Cloudflare Pages

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.3%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

将 WordPress 网站转换为静态 HTML 并部署到 Cloudflare Pages。支持**内网后端与公网静态前端分离**架构，让你的 WordPress 站点获得 CDN 级别的访问速度和安全性。

## 🎯 核心特性

- **全站静态导出** - 自动收集所有页面、文章、产品、分类等
- **资源自动收集** - CSS、JS、图片、字体等资源自动提取和重写路径
- **一键部署** - 直接部署到 Cloudflare Pages，无需手动上传
- **增量更新** - 基于内容哈希的智能增量上传，节省带宽
- **WooCommerce 支持** - 完整支持产品页面和分类页面导出
- **Elementor 兼容** - 自动处理 Elementor 构建的页面和轮播组件
- **SEO 友好** - 自动生成 sitemap、robots.txt，重写 canonical URL
- **代码注入** - 支持注入统计代码（Google Analytics、GTM 等）
- **WordPress 痕迹清理** - 自动移除 WordPress 特征，提升安全性

## 📋 系统要求

- WordPress 6.0+
- PHP 7.3+（推荐 8.0+）
- Cloudflare 账户（免费版即可）

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

| 权限 | 访问级别 |
|------|----------|
| 帐户 → Cloudflare Pages | 编辑 |
| 区域 → 区域 | 读取（可选，用于获取域名列表） |

4. 账户资源：包括 → 所有帐户（或选择特定账户）
5. 区域资源：包括 → 所有区域（或选择特定区域）
6. 点击「继续以显示摘要」→「创建令牌」→ 复制令牌

### 3. 插件配置

1. 进入 WordPress 后台 → WP to CF
2. 填写 Cloudflare API 配置（Account ID、API Token、Project Name）
3. 点击「验证并获取列表」确认配置正确
4. 设置 Production Domain（用于 SEO 标签重写）
5. （可选）配置代码注入

### 4. 导出部署

**方式一：自动上传（推荐）**
1. 点击「全量上传」或「增量上传」按钮
2. 等待导出和部署完成
3. 访问你的 Cloudflare Pages 域名查看静态站点

**方式二：手动上传**
1. 点击「导出为 ZIP」按钮
2. 下载生成的 ZIP 文件
3. 在 Cloudflare Pages 控制台手动上传

### 5. 绑定自定义域名（首次部署后）

1. 进入 Cloudflare 控制台 → Workers 和 Pages → 选择您的项目
2. 点击「自定义域」选项卡 → 「设置自定义域」
3. 输入您的域名（如 example.com 或 www.example.com）
4. 如果域名已在 Cloudflare，DNS 记录会自动配置
5. 如果域名在其他服务商，按提示添加 CNAME 记录指向 xxx.pages.dev

> 域名绑定只需操作一次，后续部署会自动更新到绑定的域名。

## 🏗️ 架构说明

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress (内网/本地)                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │ 内容管理     │  │ 主题/插件    │  │ WP to CF 插件       │  │
│  │ - 文章      │  │ - Elementor │  │ - 静态导出          │  │
│  │ - 页面      │  │ - WooCommerce│ │ - 资源收集          │  │
│  │ - 产品      │  │ - 其他插件   │  │ - 一键部署          │  │
│  └─────────────┘  └─────────────┘  └─────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼ 静态文件上传
┌─────────────────────────────────────────────────────────────┐
│                  Cloudflare Pages (公网)                     │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 静态文件                                              │   │
│  │ - HTML 页面 (index.html)                             │   │
│  │ - CSS 样式 (/css/*.css)                              │   │
│  │ - JS 脚本 (/js/*.js)                                 │   │
│  │ - 图片资源 (/images/*.webp)                          │   │
│  │ - 字体文件 (/fonts/*.woff2)                          │   │
│  │ - sitemap.xml, robots.txt                           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
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
├── css/
│   ├── style.css                 # 主题样式
│   ├── elementor.css             # Elementor 样式
│   └── ...
├── js/
│   ├── main.js                   # 主题脚本
│   └── ...
├── images/
│   ├── logo.webp                 # 图片资源
│   └── ...
├── fonts/
│   └── ...                       # 字体文件
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
- Cloudflare Turnstile 脚本（静态站点不需要）

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
├── index.php                    # 安全文件
├── admin/
│   ├── class-settings-page.php  # 设置页面类
│   ├── manual-cache-warmup.php  # 缓存预热
│   └── views/
│       └── settings-page.php    # 设置页面模板
└── includes/
    ├── class-core.php           # 核心类
    ├── class-activator.php      # 激活处理
    ├── class-site-exporter.php  # 静态导出器
    ├── class-cloudflare-api.php # Cloudflare API
    ├── class-cache-manager.php  # 缓存管理
    ├── class-export-cache.php   # 导出缓存
    ├── class-code-injector.php  # 代码注入
    ├── class-wordpress-debloat.php # WP 精简
    ├── class-url-transformer.php   # URL 转换
    ├── class-html-generator.php    # HTML 生成
    ├── class-asset-collector.php   # 资源收集
    ├── class-intranet-sanitizer.php # 内网脱敏
    └── utils/
        ├── class-logger.php     # 日志工具
        └── class-crypto.php     # 加密工具
```

## 🐛 常见问题

### Q: 部署后页面样式丢失？
A: 检查浏览器控制台是否有 404 错误。可能是某些 CSS 文件未被正确收集。清空缓存后重新全量上传。

### Q: 图片显示不出来？
A: 确认图片路径是否正确重写。检查 `/images/` 目录下是否有对应文件。

### Q: Elementor 轮播不工作？
A: 静态站点上 Elementor JS 可能不完全执行。插件已注入基础 CSS 修复，但复杂交互可能受限。

### Q: 部署失败，网络错误？
A: 插件已内置重试机制（最多 3 次）。如果持续失败，检查 Cloudflare API 配置是否正确。

### Q: 如何更新单个页面？
A: 目前需要全量重新导出。增量更新功能基于文件哈希，会自动跳过未变化的文件。

## 📝 更新日志

### v1.5.0 (2024-02-09)
- ✨ 新增表单提交管理功能
  - 新增"表单提交"页面，独立查看表单提交记录
  - 支持查看详情、删除记录
  - 按表单 ID 分组显示
- ✨ 评论与表单分离处理
  - 评论回流到 WordPress 原生评论管理
  - 表单提交显示在插件独立页面
  - 评论不发送邮件通知
- ✨ 插件国际化支持
  - 英文为默认语言
  - 包含中文语言包（zh_CN）
  - 自动根据 WordPress 站点语言切换
- ✨ 表单服务集成优化
  - 支持 form.huwencai.com 表单服务
  - 支持 Getform/Forminit 表单服务
  - 定时自动同步表单提交和评论
- 🔧 优化表单邮件功能（Cloudflare Workers）
  - 邮件包含提交页面 URL、用户 IP、表单数据
  - 自动提取表单邮箱作为回复地址
  - 字段名使用英文格式（首字母大写）
- 📚 新增国际化配置文档

### v1.3.0 (2026-02-05)
- ✨ 新增脚本清理规则配置（用户可自定义）
- ✨ 新增作者归档页及分页收集
- ✨ 新增日期归档页（年/月）及分页收集
- ✨ 修复博客列表页分页收集（支持自定义博客页面 URL）
- ✨ 修复分类/标签分页计数（使用 term->count）
- 🔧 移除不必要的 CSS hack，让 JS 正常执行
- 🔧 优化 WooCommerce 产品分页计算
- 🐛 修复 Elementor 轮播显示问题

### v1.2.6-alpha (2026-02-04)
- ✨ 新增 API 令牌设置指南（详细权限配置说明）
- ✨ 新增自定义域名绑定指南
- ✨ 新增凭证验证功能（验证并获取项目/域名列表）
- ✨ Project Name 支持下拉选择已有项目或创建新项目
- ✨ Production Domain 支持下拉选择已有域名
- 🔧 优化 API 验证逻辑，减少所需权限
- 🔧 移除环境健康状态面板，简化界面
- 🔧 权限说明改为中文，更易理解

### v1.2.5-alpha2 (2026-02-04)
- ✨ 添加代码注入功能（统计代码、GTM）
- ✨ 移除 Elementor/WooCommerce 配置脚本
- ✨ 增强 Elementor 轮播 CSS 修复
- 🐛 修复代码注入未在导出器中调用的问题
- 🐛 修复缓存统计显示问题

### v1.2.5-alpha1 (2026-02-03)
- ✨ 添加 Cloudflare API 重试机制
- ✨ 添加缓存管理详细统计
- ✨ 增量上传按钮状态控制
- 🐛 修复全量上传不更新缓存的问题
- 🐛 修复 canonical URL 和 SEO meta 标签重写

### v1.2.0 (2026-02-02)
- ✨ 全新静态站点导出器
- ✨ 支持 WooCommerce 产品导出
- ✨ 自动 sitemap 和 robots.txt 生成
- ✨ WordPress 痕迹自动清理
- 🐛 修复 PHP 7.3 兼容性问题

## 📄 许可证

GPL v2 or later

## 👤 作者

- **网站**: [huwencai.com](https://huwencai.com)
- **GitHub**: [hwc0212/wp-to-cf](https://github.com/hwc0212/wp-to-cf)

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

---

**Made with ❤️ for WordPress**
