<div align="center">

# TriM3

### 给 Typecho 的三栏个人博客主题

以阅读为中心，收好文章、日常与兴趣；桌面舒展，手机也好用。

[在线预览](https://abohe.cn/) · [下载 v1.2.6](https://github.com/asplun/TriM3/releases/tag/v1.2.6) · [安装说明](#安装)

[![Typecho](https://img.shields.io/badge/Typecho-1.3-2b6cb0?style=flat-square)](https://typecho.org/)
[![Release](https://img.shields.io/github/v/release/asplun/TriM3?style=flat-square)](https://github.com/asplun/TriM3/releases)
[![License](https://img.shields.io/badge/license-TriM3%20Noncommercial-6875a5?style=flat-square)](https://raw.githubusercontent.com/asplun/TriM3/main/LICENSE)

</div>

TriM3 是一款面向个人博客和独立站的 Typecho 主题。它采用 Material Design 3 视觉语言，以清晰的三栏布局呈现文章，也为说说、相册、友链、豆瓣、番剧和音乐留出各自的位置。站内切换由 Swup 驱动，桌面与移动端使用同一套内容和配置。

## 项目组件

- `TriM3`：主题本体。
- `TriM3Core`：必需插件，提供编辑器集成与原生评论过滤桥；必须和主题一起安装。
- `TriRedisCache`：Redis 对象缓存与匿名页面缓存插件，需要缓存功能时再启用。

## 你会用到的功能

- 三栏桌面布局、移动端适配与深色模式
- 文章、说说、相册、友链、豆瓣、番剧、归档和音乐页面
- Swup 无刷新导航，播放器可跨页面延续
- 图片代理与缓存、懒加载、友链网站截图
- 评论通知、访问统计、登录入口和可视化主题设置
- 可配置页面模块、主题色与轮播内容


## 环境要求

- Typecho 1.3 或兼容版本
- PHP 8.1+，推荐 PHP 8.5
- 使用 TriRedisCache 时需要 Redis 6+，可选 PHP Redis 扩展或插件提供的 Predis

## 安装

1. 将 `TriM3/` 复制到 Typecho 的 `usr/themes/`。
2. 将 `TriM3Core/` 复制到 `usr/plugins/` 并启用；它是主题必需插件。
3. 将 `TriRedisCache/` 复制到 `usr/plugins/`；需要 Redis 缓存功能时启用并配置。
4. 在 Typecho 后台启用 TriM3，并按需创建主题页面。
5. 首次上线前检查 API 的站点路径、HTTPS 和跨域策略。

主题与 TriM3Core 必须成套安装；TriRedisCache 为可选缓存插件。

主题目录中的 `cache/` 与 `assets/cache/` 仅用于运行时数据，不应提交到版本库。

## 版本

当前版本：1.2.8。版本号同时写入主题头部、资源版本参数、主题信息和发布包名称。每次发布使用 vMAJOR.MINOR.PATCH Git tag，并在主题目录 README 的变更记录中记录兼容性、数据库影响和回滚方式。

### v1.2.8

- 修复 CDN 环境下天气定位误读内网/代理 IP 的问题，改为筛选转发链中的公网 IP。
- 定位成功后主动清理页面缓存，避免天气城市长期停留在旧状态。
- 按访客 IP 定位天气的页面禁止进入 CDN 公共缓存，避免缓存“定位失败”或其他访客城市。

## 许可

本项目使用 `TriM3 Noncommercial License 1.0`，这是项目自有的非商业软件许可，不设到期日。它允许个人使用、学习研究、业余项目和非营利活动中的使用、修改与分发，但明确禁止将本项目用于商业网站、付费产品、广告营利、SaaS、托管服务或其他商业用途。商业使用必须事先取得版权所有者的书面授权。

这不是 OSI 定义的开源许可证。使用、修改或分发前，请完整阅读仓库中的 `LICENSE` 文件。

Typecho、Predis 以及主题中携带的第三方字体、库和媒体资源仍分别适用其原始许可。请查看 `THIRD-PARTY-NOTICES.md` 及各组件附带的许可文件。

## 免责声明

这是个人项目，按现状提供。部署前请备份 Typecho 文件、数据库和运行缓存；主题内置接口涉及登录、评论和外部音乐服务，生产环境应结合自身站点安全策略审查后再启用。

## 相关

- 项目演示与更新信息以仓库发布页为准。
- Typecho：https://typecho.org/
- TriM3 Noncommercial License：https://raw.githubusercontent.com/asplun/TriM3/main/LICENSE
