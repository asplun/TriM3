# TriM3

一个面向 Typecho 的 Material Design 3 三栏响应式个人博客主题。TriM3 以个人博客的日常使用为中心，提供说说、相册、友链、豆瓣、番剧、归档和音乐播放器等页面能力，并通过 Swup 实现站内无刷新导航。

## 项目组成

- TriM3：Typecho 主题本体，包含模板、组件、API、前端资源和后台配置页面。
- TriM3Core：必需配套插件，提供 Joe 编辑器集成与原生评论过滤桥。必须与 TriM3 主题一起安装。
- TriRedisCache：配套 Redis 缓存插件，提供对象缓存、匿名页面缓存和管理状态面板；启用主题缓存集成时需安装。

## 特性

- Material Design 3 风格三栏布局、响应式移动端和深色模式
- 说说、相册、友链、豆瓣、番剧、归档与音乐页面
- Swup 站内无刷新导航，播放器跨页面保持
- 图片代理、图片缓存、懒加载和失败兜底
- 评论通知、访问统计、登录和后台可视化设置
- Meting 音乐接口与可配置的主题色、轮播图和页面模块

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

当前版本：1.2.6。版本号同时写入主题头部、资源版本参数、主题信息和发布包名称。每次发布使用 vMAJOR.MINOR.PATCH Git tag，并在主题目录 README 的变更记录中记录兼容性、数据库影响和回滚方式。

### v1.2.6

- 修复友链截图代理对 `thum.io` 目标 URL 的错误编码，恢复截图缓存生成。
- 友链截图首次生成期间保留可见兜底，并自动重试，避免卡片背景长期透明。
- 主题与动态友链片段的截图资源参数同步更新。

## 许可

本项目使用 PolyForm Noncommercial License 1.0.0，属于限制商业用途的源码许可，并非 OSI 定义的开源许可证。个人研究、学习、业余项目等非商业用途按许可条款使用；商业使用需事先取得版权所有者的单独授权。

Typecho、Predis 以及主题中携带的第三方字体、库和媒体资源仍分别适用其原始许可。请查看 `THIRD-PARTY-NOTICES.md` 及各组件附带的许可文件。

## 免责声明

这是个人项目，按现状提供。部署前请备份 Typecho 文件、数据库和运行缓存；主题内置接口涉及登录、评论和外部音乐服务，生产环境应结合自身站点安全策略审查后再启用。

## 相关

- 项目演示与更新信息以仓库发布页为准。
- Typecho：https://typecho.org/
- PolyForm Licenses：https://polyformproject.org/licenses/noncommercial/1.0.0/
