# TriM3

一个面向 Typecho 的 Material Design 3 三栏响应式个人博客主题。TriM3 以个人博客的日常使用为中心，提供说说、相册、友链、豆瓣、番剧、归档和音乐播放器等页面能力，并通过 Swup 实现站内无刷新导航。

## 项目组成

- TriM3：Typecho 主题本体，包含模板、组件、API、前端资源和后台配置页面。
- TriM3Core：主题运行所依赖的公共核心代码与约定。当前版本先以主题内的核心目录和接口为准，后续会在完成边界拆分与独立测试后补充独立发布包。
- TriRedisCache：面向 Typecho 的 Redis 缓存插件，支持 Predis 与 PHP Redis 客户端，提供页面缓存、对象缓存和管理状态查看。

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

1. 将 TriM3/ 复制到 Typecho 的 usr/themes/。
2. 将 TriRedisCache/ 复制到 usr/plugins/，在后台启用并配置 Redis。
3. 在 Typecho 后台启用 TriM3，并按需创建主题页面。
4. 首次上线前检查 api/ 接口的站点路径、HTTPS 和跨域策略。

主题目录中的 cache/ 与 assets/cache/ 仅用于运行时数据，不应提交到版本库；发布包会排除这些运行缓存。

## 版本

当前版本：1.2.5。版本号同时写入主题头部、资源版本参数、主题信息和发布包名称。每次发布使用 vMAJOR.MINOR.PATCH Git tag，并在主题目录 README 的变更记录中记录兼容性、数据库影响和回滚方式。

## 许可

本项目使用 PolyForm Noncommercial License 1.0.0。它允许个人研究、学习、业余项目以及符合许可定义的非商业组织使用、修改和分发；商业使用需要先取得版权所有者的单独授权。

Typecho、Redis、Predis、Meting 以及主题中携带的第三方字体、库和媒体资源仍分别适用其原始许可。发布前请查看 THIRD-PARTY-NOTICES.md，不要将第三方资源误认为本项目原创内容。

## 免责声明

这是个人项目，按现状提供。部署前请备份 Typecho 文件、数据库和运行缓存；主题内置接口涉及登录、评论和外部音乐服务，生产环境应结合自身站点安全策略审查后再启用。

## 相关

- 项目演示与更新信息以仓库发布页为准。
- Typecho：https://typecho.org/
- PolyForm Licenses：https://polyformproject.org/licenses/noncommercial/1.0.0/
