<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php if (function_exists('tri3_ri_buf_start')) { tri3_ri_buf_start(); } ?>

<?php

/* CDN 域名：取自后台「图床设置」的 qiniuDomain。用于 <link rel=preconnect> 预连接
 * 与前端图片 403 重试；未配置时为空 —— 输出层整体跳过，换域名安装无需改代码。 */
$triPreHost = preg_replace('#^https?://#', '', rtrim(trim((string) tri_opt('qiniuDomain', '')), '/'));

/* 日夜模式：darkMode=1 强制暗，=2 强制亮，=0 自动(18:00-06:00 暗)。
 * 在 PHP 层决定，直接输出 class，避免 FOUC 和依赖 JS 时间判断。 */
$triDarkModeOpt = (string) tri_opt('darkMode', '0');
$triHtmlClass = 'sr';
if ($triDarkModeOpt === '1') {
    $triHtmlClass .= ' dark';
} elseif ($triDarkModeOpt === '2') {
    /* forced light: no dark */
} else {
    $triHour = (int) date('G');
    if ($triHour >= 18 || $triHour < 6) {
        $triHtmlClass .= ' dark';
    }
}
if (!defined('TRI_REDIS_PAGE_VARIANT')) {
    define('TRI_REDIS_PAGE_VARIANT', $triHtmlClass === 'sr dark' ? 'dark' : 'light');
}
?>
<!DOCTYPE html>

<script>document.documentElement.classList.add("sr");</script>
<script>/* v1.2.86：首条歌词等非关键请求延后到交互/空闲，避免每页加载时占用 Meting 接口 */
window.triDeferFetch=function(u){return new Promise(function(res,rej){var armed=false;
var go=function(){fetch(u).then(res,rej);};
var arm=function(){if(armed){return;}armed=true;if('requestIdleCallback' in window){requestIdleCallback(go,{timeout:4000});}else{setTimeout(go,2500);}};
['pointerdown','keydown','touchstart','scroll','mousemove'].forEach(function(e){window.addEventListener(e,arm,{once:true,passive:true});});
setTimeout(arm,6000);});};</script>

<html class="<?php echo htmlspecialchars($triHtmlClass, ENT_QUOTES, 'UTF-8'); ?>" lang="zh-CN"<?php if ($triPreHost !== '') { echo ' data-tri-cdn="' . htmlspecialchars($triPreHost, ENT_QUOTES, 'UTF-8') . '"'; } ?>>

<head>

<meta charset="<?php $this->options->charset(); ?>">

<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

<!-- Swup 预取（hover 预取）关闭：鼠标扫过链接即整页 fetch，网络一抖就刷错误日志，

     而这些预取失败对页面没有任何影响。用 Swup 官方开关关闭，导航逻辑不受影响。 -->





<!-- 轻量级兼容性补丁：仅在缺失时添加，不影响现代浏览器 -->

<script>

(function(){

if(!Element.prototype.closest){Element.prototype.closest=function(s){var el=this;do{if(el.matches(s))return el;el=el.parentElement;}while(el!==null);return null;};}

if(!Element.prototype.matches){Element.prototype.matches=Element.prototype.msMatchesSelector||Element.prototype.webkitMatchesSelector;}

if(!Array.prototype.includes){Array.prototype.includes=function(v){return this.indexOf(v)!==-1;};}

if(!Array.prototype.find){Array.prototype.find=function(fn){for(var i=0;i<this.length;i++){if(fn(this[i],i))return this[i];}return undefined;};}

if(!Object.entries){Object.entries=function(o){var r=[];for(var k in o){if(o.hasOwnProperty(k))r.push([k,o[k]]);}return r;};}

if(!Object.assign){Object.assign=function(t){for(var i=1;i<arguments.length;i++){var s=arguments[i];for(var k in s){if(s.hasOwnProperty(k))t[k]=s[k];}}return t;};}

if(window.Promise&&!Promise.prototype.finally){Promise.prototype.finally=function(cb){return this.then(function(v){cb();return v;},function(e){cb();throw e;});};}

if(!('IntersectionObserver' in window)){window.IntersectionObserver=function(cb){this.observe=function(el){if(el.dataset&&el.dataset.src){el.src=el.dataset.src;}};this.unobserve=function(){};this.disconnect=function(){};};}

if(typeof window.CustomEvent!=='function'){window.CustomEvent=function(t,p){p=p||{bubbles:false,cancelable:false,detail:null};var e=document.createEvent('CustomEvent');e.initCustomEvent(t,p.bubbles,p.cancelable,p.detail);return e;};}

})();

</script>

<meta name="generator" content="Typecho">

<?php

/* ============================================================

 * TriM3 SEO 优化（后台「外观设置 → SEO 优化」）

 * 站点名称/描述/关键词 + Canonical + Open Graph + Twitter Card

 * + JSON-LD 结构化数据 + 搜索引擎站点验证 + 自定义 head 代码

 * ============================================================ */

$triSeoOn = tri_opt('seoEnable', '1') === '1';

$triSiteName = tri_opt('seoSiteName');

if ($triSiteName === '') { $triSiteName = (string) $this->options->title; }

$triSiteDesc = tri_opt('seoSiteDesc');

if ($triSiteDesc === '') { $triSiteDesc = (string) $this->options->description; }

$triSiteKw = tri_opt('seoSiteKeywords');

if ($triSiteKw === '') { $triSiteKw = (string) $this->options->keywords; }

$triSiteUrl = tri_opt('seoSiteUrl');

if ($triSiteUrl === '') { $triSiteUrl = rtrim((string) $this->options->siteUrl, '/'); }

/* 页面级描述：文章/页面取摘要，分类取描述，其余回退站点描述 */

$triPageDesc = '';

$triIsPostOrPage = $this->is('post') || $this->is('page');

if ($triIsPostOrPage) {

    try { $triPageDesc = trim((string) $this->description); } catch (Throwable $e) { $triPageDesc = ''; }

    if ($triPageDesc === '') { $triPageDesc = $triSiteDesc; }

} elseif ($this->is('category') || $this->is('tag')) {

    try { $triPageDesc = trim((string) $this->getDescription()); } catch (Throwable $e) { $triPageDesc = ''; }

    if ($triPageDesc === '') { $triPageDesc = $triSiteDesc; }

} else {

    $triPageDesc = $triSiteDesc;

}

/* Canonical：首页=站点地址，其余=当前页永久链接 */

$triCanonical = '';

if ($this->is('index')) {

    $triCanonical = $triSiteUrl . '/';

} else {

    try { $triCanonical = (string) $this->permalink; } catch (Throwable $e) { $triCanonical = ''; }

}

/* Open Graph 图片：文章自定义封面 → 站点头像/Logo → 主题默认头像 */

$triOgImage = trim((string) tri_opt_raw('avatarUrl'));

if ($triOgImage === '') { $triOgImage = trim((string) tri_opt_raw('logoUrl')); }

try {

    if ($triIsPostOrPage && $this->fields && trim((string) ($this->fields->cover ?? '')) !== '') {

        $triOgImage = trim((string) $this->fields->cover);

    } elseif ($triIsPostOrPage && $this->fields && trim((string) ($this->fields->thumb ?? '')) !== '') {

        $triOgImage = trim((string) $this->fields->thumb);

    }

} catch (Throwable $e) {}

if ($triOgImage === '') { $triOgImage = $triSiteUrl . '/usr/themes/' . (string) $this->options->theme . '/assets/avatar.png'; }

$triOgType = $this->is('post') ? 'article' : ($this->is('page') ? 'article' : 'website');

?>

<title><?php $this->archiveTitle(array('category' => _t('分类 %s'), 'search' => _t('搜索 %s'), 'tag' => _t('标签 %s'), 'author' => _t('作者 %s')), '', ' - '); ?><?php echo htmlspecialchars($triSiteName); ?></title>

<?php if ($triSeoOn): ?>

<?php if ($triPageDesc !== ''): ?><meta name="description" content="<?php echo htmlspecialchars(mb_substr($triPageDesc, 0, 160)); ?>"><?php endif; ?>

<?php if ($triSiteKw !== ''): ?><meta name="keywords" content="<?php echo htmlspecialchars($triSiteKw); ?>"><?php endif; ?>

<?php if ($triSeoOn && tri_opt('seoCanonical', '1') === '1' && $triCanonical !== ''): ?><link rel="canonical" href="<?php echo htmlspecialchars($triCanonical); ?>"><?php endif; ?>

<?php if (tri_opt('seoBaiduVer') !== ''): ?><meta name="baidu-site-verification" content="<?php echo htmlspecialchars(tri_opt('seoBaiduVer')); ?>"><?php endif; ?>

<?php if (tri_opt('seoGoogleVer') !== ''): ?><meta name="google-site-verification" content="<?php echo htmlspecialchars(tri_opt('seoGoogleVer')); ?>"><?php endif; ?>

<?php if (tri_opt('seoBingVer') !== ''): ?><meta name="msvalidate.01" content="<?php echo htmlspecialchars(tri_opt('seoBingVer')); ?>"><?php endif; ?>

<?php if ($triSeoOn && tri_opt('seoOg', '1') === '1'): ?>

<meta property="og:site_name" content="<?php echo htmlspecialchars($triSiteName); ?>">

<meta property="og:type" content="<?php echo $triOgType; ?>">

<meta property="og:title" content="<?php echo htmlspecialchars($triIsPostOrPage ? (string) $this->title : $triSiteName); ?>">

<?php if ($triPageDesc !== ''): ?><meta property="og:description" content="<?php echo htmlspecialchars(mb_substr($triPageDesc, 0, 200)); ?>"><?php endif; ?>

<?php if ($triCanonical !== ''): ?><meta property="og:url" content="<?php echo htmlspecialchars($triCanonical); ?>"><?php endif; ?>

<meta property="og:image" content="<?php echo htmlspecialchars($triOgImage); ?>">

<?php endif; ?>

<?php if (tri_opt('seoTwitter', '1') === '1'): ?>

<meta name="twitter:card" content="summary_large_image">

<meta name="twitter:title" content="<?php echo htmlspecialchars($triIsPostOrPage ? (string) $this->title : $triSiteName); ?>">

<?php if ($triPageDesc !== ''): ?><meta name="twitter:description" content="<?php echo htmlspecialchars(mb_substr($triPageDesc, 0, 200)); ?>"><?php endif; ?>

<meta name="twitter:image" content="<?php echo htmlspecialchars($triOgImage); ?>">

<?php endif; ?>

<?php

/* JSON-LD 结构化数据 */

if ($triSeoOn && tri_opt('seoJsonLd', '1') === '1') {

    $triLd = null;

    try {

        if ($this->is('index')) {

            $triLd = array(

                '@context' => 'https://schema.org',

                '@graph' => array(

                    array('@type' => 'WebSite', 'name' => $triSiteName, 'url' => $triSiteUrl . '/', 'description' => $triSiteDesc),

                    array('@type' => 'Organization', 'name' => $triSiteName, 'url' => $triSiteUrl . '/', 'logo' => array('@type' => 'ImageObject', 'url' => $triOgImage)),

                ),

            );

        } elseif ($this->is('search')) {

            $triLd = array(

                '@context' => 'https://schema.org',

                '@type' => 'SearchResultsPage',

                'name' => _t('搜索') . ' - ' . $triSiteName,

                'url' => $triCanonical,

            );

        } elseif ($this->is('category') || $this->is('tag') || $this->is('author')) {

            $triLd = array(

                '@context' => 'https://schema.org',

                '@type' => 'CollectionPage',

                'name' => (string) $this->getArchiveTitle(),

                'description' => $triPageDesc,

                'url' => $triCanonical,

                'isPartOf' => array('@type' => 'WebSite', 'name' => $triSiteName, 'url' => $triSiteUrl . '/'),

            );

        } elseif ($triIsPostOrPage) {

            $triLd = array(

                '@context' => 'https://schema.org',

                '@type' => $this->is('post') ? 'Article' : 'WebPage',

                'headline' => (string) $this->title,

                'description' => mb_substr($triPageDesc, 0, 200),

                'image' => $triOgImage,

                'datePublished' => date('c', (int) $this->created),

                'dateModified' => date('c', (int) $this->modified),

                'author' => array('@type' => 'Person', 'name' => (string) $this->author->name),

                'publisher' => array('@type' => 'Organization', 'name' => $triSiteName, 'logo' => array('@type' => 'ImageObject', 'url' => $triOgImage)),

                'url' => $triCanonical,

                'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => $triCanonical),

            );

        }

    } catch (Throwable $e) { $triLd = null; }

    if ($triLd !== null) {

        echo '<script type="application/ld+json">' . json_encode($triLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    }

}

?>

<?php $triHeaderHtml = trim((string) tri_opt_raw('seoHeaderHtml')); if ($triHeaderHtml !== ''): ?>

<?php echo $triHeaderHtml; ?>

<?php endif; ?>

<?php else: ?>

<meta name="description" content="<?php $this->options->description(); ?>">

<meta name="keywords" content="<?php $this->options->keywords(); ?>">

<?php endif; ?>

<?php if (defined('TRI3_IS_404')): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>


<?php $triFavChar = trim3_first_char((string) $this->options->title);

$triFavP = tri_opt('primaryColor', '#6750a4');

$triFavT = tri_opt('tertiaryColor', '#e2558f'); ?>

<?php $triFavUrl = trim((string) tri_opt_raw('faviconUrl')); ?>

<?php if ($triFavUrl !== ''): ?>

<link rel="icon" href="<?php echo htmlspecialchars($triFavUrl); ?>">

<?php else: ?>

<!-- MD3 渐变 squircle favicon -->

<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%23<?php echo ltrim($triFavP, '#'); ?>'/%3E%3Cstop offset='1' stop-color='%23<?php echo ltrim($triFavT, '#'); ?>'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='20' fill='url(%23g)'/%3E%3Ctext x='32' y='42' font-family='Arial, sans-serif' font-size='30' font-weight='800' fill='%23fff' text-anchor='middle'%3E<?php echo rawurlencode($triFavChar); ?>%3C/text%3E%3C/svg%3E">

<?php endif; ?>

<!-- OPPO Sans 4.0 分包（fontTools 分组：常规 400 / 加粗 700 各 84 片，unicode-range 按需下载）。
     批 2 #5 统计结论：全主题只用到这一个字体族 "OPPO Sans 4.0"，且只用 400 / 700 两个字重 ——
     主字体栈在本文件下方 :root 的 --md-sys-font-family（$triFontStack，默认栈以 'OPPO Sans 4.0' 打头），
     该变量在 main-v153.min.css:199 经 font-family:var(--md-sys-font-family) 落到全站正文；
     main-v153.min.css 里出现的 500/600/800 按 CSS 字体匹配规则归到 400/700。
     故这两张表整体都被引用，不存在可安全删除的 @font-face（删错会静默回退系统字体）。
     使用点清单 / 未引用条目统计见 agent-workflow/solo-bridge/outbox/trim3-font-preload.md。 -->

<!-- v1.2.87（批 2 #5）：两张字体表改为非阻塞加载。
     依据：这两张表只含 @font-face（且 font-display:swap，已保证先用系统字体渲染），不参与布局，
     却一直在关键路径上同步阻塞；改为 media="print" 让浏览器不把它们算进首屏关键路径，onload 后切回 all，
     noscript 兜底 JS 关闭场景。首屏关键路径字体 CSS 字节 145,780 B → 0 B；
     字体 woff2 分片本就由 unicode-range 按需下载，不受影响。字体文件与其它样式表均未改动。 -->
<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/fonts/opposans/regular/regular.css'); ?>?v=1.2.5" media="print" onload="this.media='all';this.onload=null;">
<noscript><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/fonts/opposans/regular/regular.css'); ?>?v=1.2.5"></noscript>

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/fonts/opposans/bold/bold.css'); ?>?v=1.2.5" media="print" onload="this.media='all';this.onload=null;">
<noscript><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/fonts/opposans/bold/bold.css'); ?>?v=1.2.5"></noscript>

<!-- 预加载常用字分片（覆盖页面 99% 文字），消除文字逐个渲染 -->

<!-- 字体通过 CSS @font-face 加载，无需 preload -->

<!-- v1.2.86：加粗字不再 preload（它在关键路径上占了 581KB，改为常规优先级加载，正文先行） -->

<?php
/* 首页首张轮播图预加载：轮播图是首屏 LCP，提前到 <head> 里发起请求，
   避免排在文章缩略图之后。取值逻辑与 index.php 的 banner 段落完全一致
   （数字走主题内置图，其余走 CDN 签名）。仅首页且开启 banner 时输出。 */
if ($this->is('index') && tri_switch('bannerEnable') && function_exists('tri_banner_urls')) {
    $triB1List = tri_banner_urls();
    $triB1 = isset($triB1List[0]) ? $triB1List[0] : '';
    if ($triB1 !== '') {
        if (is_numeric($triB1)) {
            ob_start(); $this->options->themeUrl('assets/banner-' . (int) $triB1 . '.jpg'); $triB1Href = ob_get_clean();
        } else {
            $triB1Href = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($triB1) : $triB1;
            /* v1.2.85： banner 的 <img> 带 srcset（640/960/1240，sizes=100vw），浏览器会挑其中
             * 更小的一档，而 preload 只报默认宽度那一张 → 预载的图没人用，控制台报
             * "preloaded but not used"。这里补上完全相同的候选集，让预载与 img 落到同一张。 */
            if (function_exists('tri3_img_srcset')) {
                $triB1Set = tri3_img_srcset($triB1Href, array(640, 960, 1240));
            }
        }
    }
}
?>
<?php /* 性能：连接预热必须在首个资源请求之前，否则对首图 preload 无效 */ ?>
<link rel="preconnect" href="https://cn.cravatar.com">
<link rel="dns-prefetch" href="https://weavatar.com">
<link rel="dns-prefetch" href="https://cravatar.cn">
<link rel="dns-prefetch" href="https://q1.qlogo.cn">
<?php if ($triPreHost !== ''): ?><link rel="preconnect" href="https://<?php echo htmlspecialchars($triPreHost, ENT_QUOTES, 'UTF-8'); ?>" crossorigin>
<link rel="dns-prefetch" href="https://<?php echo htmlspecialchars($triPreHost, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
<?php if (!empty($triB1Href)): ?><link rel="preload" as="image" href="<?php echo htmlspecialchars($triB1Href, ENT_QUOTES, 'UTF-8'); ?>"<?php if (!empty($triB1Set)) { echo ' imagesrcset="' . htmlspecialchars($triB1Set, ENT_QUOTES, 'UTF-8') . '" imagesizes="100vw"'; } ?> fetchpriority="high"><?php endif; ?>

<?php

/* ---- 性能优化（零滞后方案）----

 * CSS：全量同步加载（样式立即可用，无按需注入延迟、无功能风险）。

 *     体积经 Gzip + 缓存控制，全部页面级样式（相册/豆瓣/番剧/友链/灯箱/说说/文章）同步就绪。

 * JS：全部 defer（不阻塞解析，DOMContentLoaded 前按序执行完毕，功能与同步加载完全一致）。

 * 图标库：前台默认子集（css 157KB→22KB、字体 184KB→33KB）；

 *    后台配置（导航/手机菜单/首页菜单/小组件/友链）出现子集外图标时自动切完整版。

 * 字体：OPPO Sans 粗粒度分包（常用/次常用/扩展三片），首屏预加载常用字片。

 * 图片：首图/头像 fetchpriority=high，其余 loading=lazy。

 */

$triIsPostOrPage = $this->is('post') || $this->is('page');

/* ---- 页面身份标记：body 属性输出页面级标识（供按页条件加载/样式） ---- */

$triPageKey = 'home';

if ($this->is('post')) {

    $triPageKey = 'post';

} elseif ($this->is('page')) {

    $triPageKey = 'page';

    $triTplName = (string) ($this->template ?? '');

    if (strpos($triTplName, 'album') !== false) { $triPageKey = 'album'; }

    elseif (strpos($triTplName, 'moment') !== false) { $triPageKey = 'moment'; }

    elseif (strpos($triTplName, 'douban') !== false) { $triPageKey = 'douban'; }

    elseif (strpos($triTplName, 'bangumi') !== false) { $triPageKey = 'bangumi'; }

    elseif (strpos($triTplName, 'links') !== false) { $triPageKey = 'links'; }

    elseif (strpos($triTplName, 'archive') !== false) { $triPageKey = 'archive'; }

    elseif (strpos($triTplName, 'music') !== false) { $triPageKey = 'music'; }

} elseif ($this->is('category')) {

    $triPageKey = 'category';

} elseif ($this->is('archive')) {

    $triPageKey = 'archive';

} elseif ($this->is('search')) {

    $triPageKey = 'search';

}

$triHomeMode = $this->is('index') ? tri_opt('homeListMode', 'original') : '';

?>

<!-- Remixicon 图标库（子集模式 / 完整模式自动切换） -->

<?php if (function_exists('tri3_ri_need_full') && tri3_ri_need_full()): ?>

<!-- 配置里出现子集外的图标（或子集清单缺失）时退回全量字体；
     此前该分支只有注释、不加载任何 CSS → 全站图标全部变空白 -->
<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/remixicon.min.css'); ?>?v=1.2.5">

<?php else: ?>

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/remixicon-subset.min.css'); ?>?v=1.2.5">

<?php endif; ?>

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/lightgallery-v3.min.css'); ?>?v=1.2.5">
<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/main-v154.min.css'); ?>?v=1.2">

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/motion.min.css'); ?>?v=1.2.5">

<!-- 首屏可见组件样式（同步，避免 FOUC）：登录按钮 / 播放器 / 侧栏小组件 -->

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/login.min.css'); ?>?v=1.2.5">

<?php if (tri_switch('playerEnable', '1')): ?>

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/player.min.css'); ?>?v=1.2.5">

<?php endif; ?>

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/widgets.min.css'); ?>?v=1.2.5">

<!-- 文章/单页内容排版（全站同步，Pjax 进文章无样式闪烁） -->

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/joe-content.min.css'); ?>?v=1.2.5">

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/lib/prism/prism.min.css'); ?>?v=1.2.5">

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/code-block.min.css'); ?>?v=1.2.5">

<?php if ($triPageKey === 'home' || $triPageKey === 'moment' || $triPageKey === 'post'): ?>
<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/moment-v2.min.css'); ?>?v=1.2.5">
<?php endif; ?>

<!-- 页面级样式全量同步（零滞后）：相册 / 豆瓣 / 番剧 / 友链 / 灯箱 -->

<!-- 手机端精致化（M3 · 酷安式底栏；最后加载覆盖） -->

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/mobile-polish.min.css'); ?>?v=1.2.5">

<!-- 灯箱全局样式 -->



<!-- 页面级CSS按需加载 -->

<?php if ($this->is('page') && isset($triPageKey)): ?>

<?php if ($triPageKey === 'album'): ?><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/album.min.css'); ?>?v=1.2.5"><?php endif; ?>

<?php if ($triPageKey === 'douban' || $triPageKey === 'bangumi'): ?><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/douban.min.css'); ?>?v=1.2.5"><?php endif; ?>

<?php if ($triPageKey === 'bangumi'): ?><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/bangumi.min.css'); ?>?v=1.2.5"><?php endif; ?>

<?php if ($triPageKey === 'links'): ?><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/links.min.css'); ?>?v=1.2.5"><?php endif; ?>
<?php if ($triPageKey === 'music'): ?><link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/page-music.min.css'); ?>?v=1.3.3"><?php endif; ?>

<?php endif; ?>

<!-- 连接预热已前移至首图 preload 之前（v1.2.5 性能修复） -->

<?php

/* ---- 后台外观设置注入 MD3 CSS 变量：用户显式设置优先，未设置按 MD3 从主色/背景色自动推导 ---- */

$triPrimary = tri_opt('primaryColor', '#6750a4');

$triBgColor = tri_opt_raw('bgColor');

if ($triBgColor === '') { $triBgColor = '#f8f5fc'; }

/* 主色跟随背景色适配：未显式设置主色时从背景色推导（背景为主驱动，整套配色联动）；背景也未设置时用 MD3 默认紫 */

if ($triPrimary === '') { $triPrimary = tri3_primary_from_bg($triBgColor); }

$triPal = tri3_md3_palette($triPrimary, $triBgColor);

$triSecondary = tri_opt_raw('secondaryColor');

if ($triSecondary === '') { $triSecondary = $triPal['secondary']; }

$triTertiary = tri_opt('tertiaryColor', '#e2558f');

if ($triTertiary === '') { $triTertiary = $triPal['tertiary']; }

$triPrimaryContainer = tri_opt_raw('primaryContainerColor');

if ($triPrimaryContainer === '') { $triPrimaryContainer = $triPal['primaryContainer']; }

$triOnPrimaryContainer = tri_opt_raw('onPrimaryContainerColor');

if ($triOnPrimaryContainer === '') { $triOnPrimaryContainer = $triPal['onPrimaryContainer']; }

$triOnPrimary = tri_opt_raw('onPrimaryColor');

if ($triOnPrimary === '') { $triOnPrimary = $triPal['onPrimary']; }

$triRadius    = tri_opt('radiusScale', 'm');

$triBannerH   = (int) tri_opt('bannerHeight', '200');

$triGradOn    = tri_switch('bgGradient');

/* 渐变方案：auto 淡彩（跟随主色） / primary 主色同系 / bg 背景同系 / calm 柔和中性 */

$triGradStyle = tri_opt('bgGradStyle', 'auto');

if ($triGradStyle === 'primary') {

    $triPh = tri3_hex_to_hsl($triPrimary);

    $triGrad = array(

        tri3_hsl_to_hex($triPh[0], 0.35, 0.93),

        tri3_hsl_to_hex(fmod($triPh[0] + 35, 360), 0.28, 0.94),

        tri3_hsl_to_hex(fmod($triPh[0] - 30, 360), 0.25, 0.94),

        tri3_hsl_to_hex($triPh[0], 0.18, 0.96),

    );

} elseif ($triGradStyle === 'bg') {

    $triBh = tri3_hex_to_hsl($triBgColor);

    $triGrad = array(

        tri3_hsl_to_hex($triBh[0], min(0.5, $triBh[1] * 0.9), 0.96),

        tri3_hsl_to_hex(fmod($triBh[0] + 25, 360), min(0.42, $triBh[1] * 0.7), 0.96),

        tri3_hsl_to_hex(fmod($triBh[0] - 20, 360), min(0.38, $triBh[1] * 0.6), 0.96),

        tri3_hsl_to_hex($triBh[0], min(0.3, $triBh[1] * 0.4), 0.98),

    );

} elseif ($triGradStyle === 'calm') {

    $triGrad = array('#f7f5f2', '#f2f6f4', '#f4f3f7', '#f6f7f8');

} else {

    $triGrad = array($triPal['grad1'], $triPal['grad2'], $triPal['grad3'], $triPal['grad4']);

}

/* 全站字体：用户自定义优先，默认 OPPO Sans 4.0 分包字体 */

$triFontRaw = tri_opt('siteFont', '');

if (trim((string) $triFontRaw) !== '') {

    $triFontStack = trim((string) $triFontRaw);

} else {

    $triFontStack = "'OPPO Sans 4.0', 'HarmonyOS Sans SC', 'MiSans', 'PingFang SC', 'Microsoft YaHei', sans-serif";

}

?>

<style>

:root{

  --md-sys-font-family: <?php echo $triFontStack; ?>;

  --md-sys-color-primary: <?php echo $triPrimary; ?>;

  --md-sys-color-on-primary: <?php echo $triOnPrimary; ?>;

  --md-sys-color-primary-container: <?php echo $triPrimaryContainer; ?>;

  --md-sys-color-on-primary-container: <?php echo $triOnPrimaryContainer; ?>;

  --md-sys-color-secondary: <?php echo $triSecondary; ?>;

  --md-sys-color-secondary-container: <?php echo $triPal['secondaryContainer']; ?>;

  --md-sys-color-on-secondary-container: <?php echo $triPal['onSecondaryContainer']; ?>;

  --md-sys-color-tertiary: <?php echo $triTertiary; ?>;

  --md-sys-color-background: <?php echo $triPal['background']; ?>;

  --md-sys-color-on-background: <?php echo $triPal['onBackground']; ?>;

  --md-sys-color-surface: <?php echo $triPal['surface']; ?>;

  --md-sys-color-on-surface: <?php echo $triPal['onSurface']; ?>;

  --md-sys-color-surface-variant: <?php echo $triPal['surfaceVariant']; ?>;

  --md-sys-color-on-surface-variant: <?php echo $triPal['onSurfaceVariant']; ?>;

  --md-sys-color-surface-container-lowest: <?php echo $triPal['surfaceLowest']; ?>;

  --md-sys-color-surface-container-low: <?php echo $triPal['surfaceLow']; ?>;

  --md-sys-color-surface-container: <?php echo $triPal['surfaceContainer']; ?>;

  --md-sys-color-surface-container-high: <?php echo $triPal['surfaceHigh']; ?>;

  --md-sys-color-surface-container-highest: <?php echo $triPal['surfaceHighest']; ?>;

  --md-sys-color-outline: <?php echo $triPal['outline']; ?>;

  --md-sys-color-outline-variant: <?php echo $triPal['outlineVariant']; ?>;

<?php if ($triRadius === 's'): ?>

  --md-sys-shape-corner-small:4px;--md-sys-shape-corner-medium:8px;--md-sys-shape-corner-large:12px;--md-sys-shape-corner-extra-large:20px;

<?php elseif ($triRadius === 'l'): ?>

  --md-sys-shape-corner-small:12px;--md-sys-shape-corner-medium:16px;--md-sys-shape-corner-large:20px;--md-sys-shape-corner-extra-large:32px;

<?php endif; ?>

<?php if ($triBannerH > 0 && $triBannerH !== 200): ?>

  --tri-banner-height:<?php echo $triBannerH; ?>px;

<?php endif; ?>

<?php

$triLeftW = (float) tri_opt('leftWidth', 0);

$triMainW = (float) tri_opt('mainWidth', 0);

$triRightW = (float) tri_opt('rightWidth', 0);

if ($triLeftW >= 80 && $triLeftW <= 800): ?>

  --md-layout-col-left:<?php echo (int) round($triLeftW); ?>px;

<?php endif; ?>

<?php if ($triMainW >= 320 && $triMainW <= 1200): ?>

  --md-layout-col-main:<?php echo (int) round($triMainW); ?>px;

<?php endif; ?>

<?php if ($triRightW >= 80 && $triRightW <= 800): ?>

  --md-layout-col-right:<?php echo (int) round($triRightW); ?>px;

<?php endif; ?>

}

@media (max-width: 1280px) {

  :root { --md-layout-col-left: 68px !important; }

}

<?php if ($triGradOn): ?>

body{background-color:var(--md-sys-color-background);}

body::before{background-image:

  radial-gradient(1100px 720px at 0% 0%, color-mix(in srgb, var(--md-sys-color-primary) 35%, transparent), transparent 72%),

  radial-gradient(1100px 720px at 100% 0%, color-mix(in srgb, var(--md-sys-color-tertiary) 30%, transparent), transparent 72%),

  radial-gradient(720px 520px at 30% 108%, color-mix(in srgb, var(--md-sys-color-primary) 25%, transparent), transparent 66%),

  radial-gradient(720px 520px at 85% 112%, color-mix(in srgb, var(--md-sys-color-secondary) 25%, transparent), transparent 66%);}

<?php endif; ?>

<?php if (!tri_switch('glassEffect')): ?>.layout{backdrop-filter:none;-webkit-backdrop-filter:none;background:rgba(255,255,255,.96);}<?php endif; ?>

<?php if (!$triGradOn): ?>body::before{display:none;}body{background-color:<?php echo $triBgColor; ?>;}<?php endif; ?>

<?php if (!tri_switch('leftFixed')): ?>.col-left-inner{position:static;max-height:none;overflow:visible;}<?php endif; ?>

<?php if (tri_opt('darkMode', '0') === '1'): ?><script>document.documentElement.classList.add('dark');localStorage.setItem('trim3-theme','dark');</script><?php endif; ?>

<?php if (tri_opt('darkMode', '0') === '2'): ?><script>document.documentElement.classList.remove('dark');localStorage.setItem('trim3-theme','light');</script><?php endif; ?>

<?php
/* PHP :root 输出了自定义颜色的浅/亮色 token，夜间模式必须在此之后再用
 * html.dark 覆盖一遍，否则 PHP :root 的亮色变量会压过外部 CSS 的 html.dark。
 * 仅当 darkMode=0（自动）时输出；=1/=2 走上面的 JS 硬切。 */
if (tri_opt('darkMode', '0') === '0') {
    $triHour = (int) date('G');
    if ($triHour >= 18 || $triHour < 6) {
        echo 'html.dark{--md-sys-color-primary:#d0bcff;--md-sys-color-on-primary:#381e72;--md-sys-color-primary-container:#4f378b;--md-sys-color-on-primary-container:#eaddff;--md-sys-color-secondary:#bec9ff;--md-sys-color-on-secondary:#102e6d;--md-sys-color-secondary-container:#2a4a99;--md-sys-color-on-secondary-container:#d7e3ff;--md-sys-color-tertiary:#ffb1c8;--md-sys-color-on-tertiary:#5c0a2e;--md-sys-color-tertiary-container:#933a62;--md-sys-color-on-tertiary-container:#ffd9e4;--md-sys-color-error:#f2b8b5;--md-sys-color-on-error:#601410;--md-sys-color-background:#141218;--md-sys-color-on-background:#e6e1e9;--md-sys-color-surface:#141218;--md-sys-color-on-surface:#e6e1e9;--md-sys-color-surface-variant:#49454f;--md-sys-color-on-surface-variant:#cac4d0;--md-sys-color-surface-container-lowest:#0f0d13;--md-sys-color-surface-container-low:#1c1b20;--md-sys-color-surface-container:#201f24;--md-sys-color-surface-container-high:#2b2930;--md-sys-color-surface-container-highest:#36343b;--md-sys-color-outline:#948f99;--md-sys-color-outline-variant:#49454f;--md-sys-color-shadow:#000;--md-sys-color-scrim:#000;}';
    }
}
?>

</style>



<?php $this->header(); ?>

<?php $triPjaxOn = tri_opt('pjaxEnable', '1') === '1'; ?>

<!-- Swup配置 -->

<!-- v1.2.63：Swup 已退役 -->



<?php if ($triPjaxOn): ?>

<!-- Swup：全站即时页面切换 -->

<!-- v1.2.59：v1.2.52 加入的诊断探针已移除。取证已完成；
       它包装 window.fetch、每次请求写 sessionStorage，且错误分支引用了
       超出作用域的 NL，会抛 ReferenceError。 -->

<script type="module">

  /* v1.2.63：Swup 已退役，导航交给 Swup（见 assets/js/tri-swup.js）。 */

/* v1.2.58：import 之后的所有初始化只执行一次。
   内联脚本被 Swup 重新插入就会重新执行（data-swup-eval 对内联脚本不起作用），
   监听注册每多一份，一次点击就会多走一套逻辑，把 Swup 在途的导航挤成一次 499
   （客户端断开）→ Failed to fetch → 整页刷新。
   注意：import 必须留在模块顶层，不能包进块里。 */
if (!window.__triAppInit) {
  window.__triAppInit = 1;

  /* v1.2.63：进度条由主题自己的 #tri-nav-spinner 负责。 */





  history.scrollRestoration = 'manual';

  /* v1.2.57：只启动一次。此前该内联模块每被 Swup 重新执行一次就会再 start 一次，
     导致 Swup 的点击观察器成倍安装 —— 一次点击提出多个访问、互相取消，
     最终表现为 TypeError: Failed to fetch 与整页刷新。 */
  /* v1.2.63：Swup 构造时自动接管，无需 start。 */

/* tri3:render scroll handled in tri3:render below */

  var _srInstance = null;





  window.triNavComplete = function() {

    /* 只负责导航 UI 收尾（转圈 / 滚动位置）。
       组件初始化只保留一条路径：Swup 的 page:view → TriRunHooks() + tri3:render。
       这里不再派发 tri3:render、不再调用 TriM3Init，否则会与本文件的
       tri3:render 监听器互相调用，直接爆栈（RangeError: Maximum call stack size exceeded）。 */

    _triNavDone = true;

    if (window._triPositionSpinner) window._triPositionSpinner();

    var sp = document.getElementById('tri-nav-spinner');

    if (sp) sp.classList.remove('show');

  };



  var _triNavDone = false;

  var _triSavedY = 0;

  /* 创建导航下方小转圈 */

  if (!document.getElementById('tri-nav-spinner')) {

    var sp = document.createElement('div');

    sp.id = 'tri-nav-spinner';


    sp.className = 'tri-nav-spinner';

    document.body.appendChild(sp);

    window._triPositionSpinner = function() {

      var menu = document.querySelector('.home-menu');

      if (!menu) return;

      var rect = menu.getBoundingClientRect();

      sp.style.top = (rect.bottom + 6) + 'px';

    };

    window._triPositionSpinner();

    window.addEventListener('resize', window._triPositionSpinner);

  }

  document.addEventListener('swup:visit:start', function() {

    _triSavedY = window.scrollY;

    document.getElementById('tri-nav-spinner')?.classList.add('show');

  });

  document.addEventListener('tri3:render', function() {

    window.triNavComplete();

    _triNavDone = true;

    if (window._triPositionSpinner) window._triPositionSpinner();

    var sp = document.getElementById('tri-nav-spinner');

    if (sp) sp.classList.remove('show');

    setTimeout(function() {

      window.scrollTo(0, 0);

    });

  });

  /* ===== 全局 Swup 优化：before-cache 清理 + 错误处理 ===== */

  document.addEventListener('swup:visit:start', function() {

    /* 关闭所有打开的浮层/菜单/模态，避免缓存页面时残留状态 */

    document.querySelectorAll('.is-open, .is-active, .show, .open').forEach(function(el) {

      if (!el.closest('main.col-main, #tri3-side-dyn')) return; /* v1.2.63：容器之外的常驻元素保留状态 */

      el.classList.remove('is-open', 'is-active', 'show', 'open');

    });

    /* 收起移动端抽屉 */

    var drawer = document.getElementById('drawer');

    if (drawer) drawer.classList.remove('is-open');

    /* 取消所有正在进行的 fetch 请求（Swup 会自动处理，这里仅清理自定义请求） */

  });



  /* 入场显示：reveal.js 把「导航后重跑」暴露成 TriHooks.revealObserver，但换页后的触发时机
     有概率落到 DOM 替换之前/之后而错过，首屏元素就会停在 opacity:0，直到用户滚动才补上
     （表现：回到首页过几秒才出现，而网络面板没有任何请求）。
     这里只做一件事——在渲染完成后调用主题自己的实现，不重复它的逻辑。 */
  function _triRevealKick() {
    try { if (window.TriHooks && window.TriHooks.revealObserver) { window.TriHooks.revealObserver(); } } catch (e) {}
  }
  if (!window.__triRevealKickBound) {
    window.__triRevealKickBound = true;
    document.addEventListener('tri3:render', function () { window.__triVisitUrl = ''; _triRevealKick(); setTimeout(_triRevealKick, 120); });
    document.addEventListener('swup:content:replace', _triRevealKick);
    document.addEventListener('tri3:render', _triRevealKick);
    document.addEventListener('swup:visit:start', _triRevealKick);
  }
  /* 导航请求确实失败时提示一次。重放 visit / 强收进度条 / 整页兜底跳转这些叠加逻辑
   已全部移除：那套东西自己会再造出一次访问，反而把 Swup 卡在 started 上。
   真正的收尾交给 Swup（AbortError 也会正常终结 visit）。 */
  /* TriM3 v1.2.39 诊断探针：只记录现场，不拦截、不重试、不改写请求。
     复现后执行 sessionStorage.getItem('__triDiag') 读取。 */
  /* v1.2.63：v1.2.39 的导航诊断探针（包装 window.fetch、写 sessionStorage）已随 Swup 一并移除。 */

  /* v1.2.63：Swup 的 fetch 失败会直接中止本次访问（页面保持原状，不再整页刷新），这里只安静提示一次。 */
  document.addEventListener('swup:fetch:error', function() {
    var now = Date.now();
    if (now - (window.__triToastAt || 0) < 20000) return;
    window.__triToastAt = now;
    if (window.TriMsg && window.TriMsg.show) { window.TriMsg.show('加载失败，请重试'); }
  });

/* Swup 访问失败（网络错误等） */

  document.addEventListener('swup:visit:start', function(event) {
    window._triVisitStart = performance.now();
    try { window.__triVisitUrl = (event.detail && event.detail.visit && event.detail.visit.to && event.detail.visit.to.url) || ''; } catch (e) {}
  });



  /* v1.2.63：点当前页链接由 Swup 的 linkToSelf:'scroll' 原生处理（只回顶、不发请求）。 */



  /* 直接刷新时 DOMContentLoaded 后也初始化一次（Swup render 可能在 defer 脚本就绪前触发） */

  document.addEventListener('DOMContentLoaded', function() {

    if (!_triNavDone) { window.triNavComplete(); }

  });



  /* 链接预取已移除：CDN 会限制并发连接数，预取请求会和真正的导航抢额度，
   反而让导航自身被拒（ERR_HTTP2_SERVER_REFUSED_STREAM）。导航交给 Swup 按需发起。 */



  /* v1.2.63：Swup 不拦截表单提交，multipart 交给浏览器原生处理。 */



  function syncNavActive(url) {

    var cur = '/';

    try { cur = new URL(url, location.href).pathname.replace(/\/+$/, '') || '/'; } catch(e) {}

    document.querySelectorAll('.col-left .nav-link').forEach(function(link) {

      var href = link.getAttribute('href') || '';

      var p = '';

      try { p = new URL(href, location.origin).pathname.replace(/\/+$/, '') || '/'; } catch(e) { p = href; }

      var active = (p === '/') ? (cur === '/') : (p !== '#' && p !== '' && cur.indexOf(p) === 0);

      link.classList.toggle('active', active);

    });

  }

  /* v1.2.63：改为对外暴露，由 tri-swup.js 在 swup:page:view 里调用。 */
  window.TriSyncNav = syncNavActive;


}
</script>

<?php endif; ?>



<script>

/* 页面专属资源动态加载（独立执行，不依赖 main.js） */

(function() {
  /* v1.2.51：本块挂 document 级导航监听，只应注册一次。 */
  if (window.__triPageResLoader) { return; }
  window.__triPageResLoader = 1;


  var loaded = new Set();

/* v1.2.90：页面地址 -> 样式表。地址由 tri3_page_url() 按页面模板反查得到。 */
  var tri3PageCssMap = <?php
    $tri3CssPages = array(
        'moment'  => array('moment-v2.min.css?v=1.2.5'),
        'album'   => array('album.min.css?v=1.2.5'),
        'douban'  => array('douban.min.css?v=1.2.5'),
        'bangumi' => array('douban.min.css?v=1.2.5', 'bangumi.min.css?v=1.2.5'),
        'links'   => array('links.min.css?v=1.2.5'),
    );
    $tri3CssMap = array();
    foreach ($tri3CssPages as $tri3Key => $tri3Css) {
        $tri3Url = tri3_page_url($tri3Key);
        if ($tri3Url === '') { continue; }
        $tri3Path = parse_url($tri3Url, PHP_URL_PATH);
        if (!$tri3Path) { continue; }
        $tri3CssMap[rtrim($tri3Path, '/') ?: '/'] = $tri3Css;
    }
    echo json_encode($tri3CssMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ?>;

    function loadCSS(href) {

    /* 判据是"文档里是否已有这条 link"，而不是内存里的 Set：
       Swup 换页会增删 head 里的 link，内存标记会和实际 DOM 脱节。 */
    var ls = document.head.querySelectorAll('link[rel=stylesheet]');

    for (var i = 0; i < ls.length; i++) { if (ls[i].getAttribute('href') === href) return; }

    var l = document.createElement('link');

    l.rel = 'stylesheet';

    l.href = href;

    document.head.appendChild(l);

  }

  function loadPage(url) {

    if (!url || typeof url !== 'string') return;

    /* 页面 -> 样式表 的映射键，是主题按「哪个页面用了哪个模板」反查出的真实地址，
       不写死页面 slug（单页名称由站点自定义，改 slug 不影响这里）。 */
    var path = url.replace(/^https?:\/\/[^/]+/, '').split('?')[0].split('#')[0];

    path = path.replace(/\/+$/, '') || '/';

    var css = tri3PageCssMap[path];

    if (!css) return;

    var base = '<?php $this->options->themeUrl('assets/css/'); ?>';

    css.forEach(function(c) { loadCSS(base + c); });

  }

window.Tri3PageCss = loadPage;

  /* v1.2.63：换页补样式由 tri-swup.js 在 swup:page:view 里调用 Tri3PageCss。 */

  /* 首次进入也要判断一次（Swup 之外的整页加载）。 */
  loadPage(location.href);

})();

</script>







<script>



/* Standalone theme toggle - in header so it persists across Swup navigation */

(function(){
  /* v1.2.51：document 级 click 只注册一次；换页重跑会叠加成多份，
     导致一次点击被多套逻辑处理、多个访问互相取消 -> Failed to fetch -> 整页刷新。 */
  if (window.__triThemeToggle) { return; }
  window.__triThemeToggle = 1;


  function applyTheme(theme){

    var root = document.documentElement;

    if(theme==='dark') root.classList.add('dark'); else root.classList.remove('dark');

    try{ localStorage.setItem('trim3-theme', theme); }catch(e){}

    document.querySelectorAll('#theme-toggle-fab, #theme-toggle, #theme-toggle-m').forEach(function(b){

      var i = b.querySelector('i');

      if(i) i.className = theme==='dark' ? 'ri-sun-line' : 'ri-moon-line';

    });

    if (window.TriHooks && typeof window.TriHooks.dynamicColor === 'function') {
      try { window.TriHooks.dynamicColor(); } catch (e) {}
    }

  }

  document.addEventListener('click', function(e){

    var btn = e.target && e.target.closest ? e.target.closest('#theme-toggle-fab, #theme-toggle, #theme-toggle-m') : null;

    if(btn){

      var isDark = document.documentElement.classList.contains('dark');

      applyTheme(isDark ? 'light' : 'dark');

    }

  });

  /* 用户手动切换后，后续页面优先使用手动选择（覆盖 PHP 的时间判断）。
   * 如果 localStorage 里有 trim3-theme，则用它；否则保留 PHP 输出的自动结果。 */
  document.addEventListener('tri3:render', function(){
    try {
      var manual = localStorage.getItem('trim3-theme');
      if (manual === 'dark' || manual === 'light') {
        applyTheme(manual);
      }
    } catch(e) {}
  });

  /* PHP 已按 18:00-06:00 自动输出 dark class，不再用 JS 覆盖。
   * 用户手动点切换时写入 localStorage 仅作当前会话记录，不影响后续页面。 */

  /* 自动跟随系统日夜主题；用户手动切换后不再覆盖 */
  if (window.matchMedia) {
    var themeMedia = window.matchMedia('(prefers-color-scheme: dark)');
    var onSystemThemeChange = function(e) {
      var hasManualTheme = false;
      try { hasManualTheme = !!localStorage.getItem('trim3-theme'); } catch (err) {}
      if (!hasManualTheme) {
        applyTheme(e.matches ? 'dark' : 'light');
      }
    };
    if (themeMedia.addEventListener) {
      themeMedia.addEventListener('change', onSystemThemeChange);
    } else if (themeMedia.addListener) {
      themeMedia.addListener(onSystemThemeChange);
    }
  }

})();





</script>



<style>

.page-title-badge { margin-top: 40px; }

.single-header { padding: 10px 8px 20px; }

.profile-stats { margin: -10px 0 -20px 0; }

.profile-card { padding: 18px 20px 10px; }

.tri-notice { margin: 0 0 12px; }

.col-left, .col-left .nav-link, .home-menu, .home-menu a, .home-menu-item { pointer-events: auto !important; }

@media (max-width: 560px) { .col-main:has(.hero-slider) { padding-top: 190px !important; } }

@media (max-width: 768px) {

  .hero-slider { height: 190px; }

  .col-main:has(.hero-slider) { padding-top: 190px; }

  .profile-name { font-size: 16px; }

  .profile-bio { font-size: 12px; }

  .profile-card { font-size: 12px; }

  .profile-stat b { font-size: 14px; }

  .profile-stat span { font-size: 10px; }

  .profile-avatar { width: 100px !important; height: 100px !important; }

  .profile-card.has-banner .profile-avatar { top: -50px !important; left: 16px; }

  .profile-card.has-banner .profile-info { margin-left: 112px !important; }

}

</style>

<!-- v1.2.57：这里的链接预取已移除。横向导航前 3 项的第一个就是站点根，
       每次渲染都预取一次，会和 Swup 真正的导航请求抢连接额度，
       反而让导航自身被拒。导航交给 Swup 按需发起。 -->



<script>

/* Service Worker：自 v1.2.57 起不再注册，仅清理历史遗留的注册与缓存。
   实测结论（保留备查）：SW 在控时从任意页面点回首页约 4 成概率整页刷新；
   原因是 Swup 的导航请求为 mode=cors + Accept:text/html，并不等于 navigate，
   SW 的 fetch 守卫拦不住它，请求在 SW 接管/激活期间被取消，Swup 收到
   TypeError: Failed to fetch，按官方兜底 reload({reason:"request_failed"}) 整页重载。
   静态资源缓存的收益小于该代价，故不注册；同时把旧注册与旧缓存清掉。 */
if ('serviceWorker' in navigator && !window.__triSwBound) {
  window.__triSwBound = 1;
  window.addEventListener('load', function () {
    try {
      if (navigator.serviceWorker.getRegistrations) {
        navigator.serviceWorker.getRegistrations().then(function (rs) {
          for (var i = 0; i < rs.length; i++) {
            try { rs[i].unregister(); } catch (e) {}
          }
        }).catch(function () {});
      }
    } catch (e) {}
    try {
      if (window.caches && caches.keys) {
        caches.keys().then(function (ks) {
          ks.forEach(function (k) { try { caches.delete(k); } catch (e) {} });
        }).catch(function () {});
      }
    } catch (e) {}
  });
}
</script>

<?php if (tri_opt('dynamicColor', '0') === '1'): ?><script src="<?php $this->options->themeUrl('assets/js/dynamic-color.min.js'); ?>?v=1.2.5" defer></script><?php endif; ?>

<style>

/* 入场位移动画 */

/* 入场动画由 JS 控制 */

</style>

<script>



</script>

<style>

/* oyiso 式主内容卡片滑入动画 */

/* 入场动画由 JS 控制，CSS 不再自动跑 */

</style>





<style>

/* 懒加载图片淡入效果 */

img.img-lazy { opacity: 0; filter: blur(8px); transform: scale(1.015); transition: opacity 0.45s ease, filter 0.45s ease, transform 0.45s ease; }

img.is-loaded { opacity: 1; filter: none; transform: none; }
img.img-fallback { opacity: 1; filter: none; transform: none; }
@media (prefers-reduced-motion: reduce) { img.img-lazy { filter: none; transform: none; } }

</style>

<?php if (tri_switch('playerEnable', '1')): ?>
<script id="triplayer-js" src="<?php $this->options->themeUrl('assets/js/player-v3-1790265431.min.js'); ?>?v=1.2.5&fix=20260928" defer></script>
<?php endif; ?>

<?php if ($triPjaxOn): ?>
<!-- v1.2.63：Swup 导航（自托管），defer 保证容器已就绪后启动；关闭 pjax 时不再下载 -->
<script src="<?php $this->options->themeUrl('assets/js/swup.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/swup-head-plugin.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/swup-body-class-plugin.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/swup-preload-plugin.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/tri-swup.js'); ?>?v=1.2.7" defer></script>
<?php endif; ?>

<link rel="stylesheet" href="<?php $this->options->themeUrl('assets/css/notifications.css'); ?>?v=1.2.5">
</head>

<?php

$triBodyClassMap = array('home'=>'page-index','moment'=>'page-moment','album'=>'page-album','post'=>'page-post','page'=>'page-page','archive'=>'page-archive','404'=>'page-404','error'=>'page-404');

$triPageKey = $triPageKey ?? 'home';
/* $this->need() 内部是 require（Widget_Archive::need），每次调用都是独立的方法局部作用域，
   header.php 与 footer.php 之间不共享普通变量；用 $GLOBALS 让 footer.php 能读到页面类型。 */
$GLOBALS['__tri_page_key'] = $triPageKey;

$triBodyClass = $triBodyClassMap[$triPageKey] ?? ('page-' . $triPageKey);

?><body data-controller="tri3" data-page="<?php echo htmlspecialchars($triPageKey); ?>" class="<?php echo $triBodyClass; ?><?php if (tri_switch('navNarrow', '0')) echo ' nav-narrow'; ?><?php if (!tri_show_left()) echo ' layout-no-left'; ?><?php if (!tri_show_right()) echo ' layout-no-right'; ?>"<?php echo $this->is('category') ? ' data-in-category="1"' : ''; ?>>

<?php

/* 头像播放器全局配置：所有页面注入（刷新到非首页时 player.js 也能初始化，Pjax 进入首页后可直接交互） */

$triPlayerList = array();

$triPlayerOn = false;

if (tri_switch('playerEnable', '1')) {

    $triPlaylistRaw = trim((string) tri_opt('playlist', ''));

    if ($triPlaylistRaw !== '') {

        foreach (preg_split('/\r\n|\r|\n/', $triPlaylistRaw) as $triLine) {

            $triLine = trim($triLine);

            if ($triLine === '') continue;

            $triParts = explode('|', $triLine);

            $triPlayerList[] = array(

                'cover'  => trim($triParts[0] ?? ''),

                'title'  => trim($triParts[1] ?? ''),

                'src'    => trim($triParts[2] ?? ''),

                'artist' => trim($triParts[3] ?? ''),

            );

        }

        $triPlayerList = array_values(array_filter($triPlayerList, function ($p) { return $p['src'] !== ''; }));

    }

    $triSource = (string) tri_opt('playerSource', 'meting');

    if ($triSource === 'manual') {

        $triPlayerOn = !empty($triPlayerList);

    } else {

        /* Meting 模式：统一解析（明确「平台+类型+ID」优先，其次歌单链接/ID，兼容旧 metingId） */

        $triMetParsed = tri3_player_source();

        $triMetingServer = $triMetParsed['server'];

        $triMetingType = $triMetParsed['type'];

        $triMetingId = $triMetParsed['id'];

        $triPlayerOn = $triMetingId !== '';

    }

}

$triMetingApi = trim((string) tri_opt('metingApi', ''));

$triThemeBase = (string) ($this->options->themeUrl ?? '');

if ($triMetingApi === '') {

    $triMetingApi = $triThemeBase;

    if ($triMetingApi !== '' && substr($triMetingApi, -1) !== '/') { $triMetingApi .= '/'; }

    $triMetingApi .= 'api/meting-api.php';

} elseif ($triThemeBase !== '' && (strpos($triMetingApi, rtrim($triThemeBase, '/') . '/meting-api.php') !== false

    || preg_match('#/usr/themes/[^/]+/meting-api\.php$#', $triMetingApi))) {

    /* 兼容旧配置：指向主题根目录的 meting-api.php（文件实际在 api/ 下）自动修正；

       用户自配的第三方 Meting API（如 api.injahow.cn）不受影响 */

    $triMetingApi = rtrim($triThemeBase, '/') . '/api/meting-api.php';

}

?>

<script>

window.TRI3_THEME_URL = '<?php $this->options->themeUrl(); ?>';
window.TRI3_PLAYER = window.TRI3_PLAYER || {enabled: <?php echo $triPlayerOn ? 'true' : 'false'; ?>, list: <?php echo json_encode($triPlayerList); ?>, volume: <?php echo json_encode((string) tri_opt('playerVolume', '0.7')); ?>, lyric: <?php echo json_encode((string) tri_opt('playerLyric', '')); ?>, lyricDefault: <?php echo tri_checked('playerLyricDefault') ? 'true' : 'false'; ?>, source: <?php echo json_encode((string) tri_opt('playerSource', 'meting')); ?>, meting: {api: <?php echo json_encode($triMetingApi); ?>, server: <?php echo json_encode($triMetingServer ?? 'netease'); ?>, type: <?php echo json_encode($triMetingType ?? 'playlist'); ?>, id: <?php echo json_encode($triMetingId ?? ''); ?>}};

window.TRI3_UPLOAD = {api: '<?php $this->options->themeUrl('api/album-api.php?do=upload'); ?>', enabled: true};

</script>



<!-- 顶栏（平板 / 手机端显示） -->

<header class="app-bar header-reveal">

  <button class="icon-btn" id="drawer-toggle" aria-label="打开菜单"><i class="ri-menu-2-line"></i></button>

  <a class="app-bar-brand" href="<?php $this->options->siteUrl(); ?>">

    <?php $triBarAv = tri3_avatar_url(32); ?>

    <?php if ($triBarAv !== ''): ?>

      <img src="<?php echo $triBarAv; ?>" alt="">

    <?php else: ?>

      <span class="app-bar-mark"><?php echo trim3_first_char((string) $this->options->title); ?></span>

    <?php endif; ?>

    <?php $this->options->title(); ?>

  </a>

  <div class="app-bar-actions">

    <button class="icon-btn" id="theme-toggle-m" aria-label="切换深色模式"><i class="ri-moon-line"></i></button>

  </div>

</header>
