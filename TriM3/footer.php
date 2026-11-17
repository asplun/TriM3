<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>



<!-- 播放器全局浮层：歌词 + 悬浮播放器（所有页面存在，非首页播放时仍可用） -->

<?php

/* footer 独立判断（不依赖跨模板变量）：Meting 模式配置了资源 ID 或手动模式有歌单即启用 */

$triDockOn = false;

if (tri_switch('playerEnable', '1')) {

    if ((string) tri_opt('playerSource', 'meting') === 'manual') {

        $triDockOn = trim((string) tri_opt('playlist', '')) !== '';

    } else {

        /* 与 header/index 一致：统一解析（明确「平台+类型+ID」优先，其次链接/旧 metingId） */

        $triDockOn = tri3_player_source()['id'] !== '';

    }

}

?>

<?php if ($triDockOn): ?>

<div class="player-lyric-float" id="player-lyric-float" hidden aria-label="歌词">

  <div class="plf-viewport">

    <div class="plf-list"></div>

  </div>

</div>

<div class="player-dock" id="player-dock" hidden aria-label="悬浮播放器">

  <div class="dock-main">

  <button type="button" class="dock-cover" aria-label="封面"><img class="dock-cover-img" alt=""></button>

  <div class="dock-meta">

    <span class="dock-title"></span>

    <span class="dock-artist"></span>

  </div>

  <div class="dock-ctrls">

    <button type="button" class="dock-btn dock-prev" aria-label="上一曲"><i class="ri-skip-back-line"></i></button>

    <button type="button" class="dock-btn dock-play" aria-label="播放/暂停"><i class="ri-play-fill"></i></button>

    <button type="button" class="dock-btn dock-next" aria-label="下一曲"><i class="ri-skip-forward-line"></i></button>

    <button type="button" class="dock-btn dock-vol" aria-label="音量"><i class="ri-volume-up-line"></i></button>

    <button type="button" class="dock-btn dock-plist" aria-label="播放列表"><i class="ri-play-list-2-line"></i></button>

    <button type="button" class="dock-btn dock-lyric" aria-label="歌词"><i class="ri-file-music-line"></i></button>

  </div>

  </div>

  <div class="dock-progress"><div class="dock-progress-bar"></div></div>

  <div class="dock-vol-pop" hidden>

    <input type="range" min="0" max="100" step="1" value="70" class="dock-vol-slider" aria-label="音量">

    <span class="dock-vol-num">70</span>

  </div>

  <div class="dock-plist-pop" hidden>

    <div class="dock-plist-head"><i class="ri-play-list-2-line"></i><span>播放列表</span></div>

    <ul class="dock-plist-items"></ul>

  </div>

</div>

<?php endif; ?>



<!-- 右侧悬浮按钮组：目录（仅文章页，JS 按页面显隐）+ 日夜切换 + 返回顶部（贴大框右缘，统一 44px） -->

<div class="tri3-fab-stack" id="tri3-fab-stack">

  <button class="fab-btn tri3-toc-fab" type="button" data-tri3-toc-toggle aria-label="文章目录"><i class="ri-list-unordered"></i></button>

  <button class="fab-btn" id="theme-toggle-fab" aria-label="切换深色模式"><i class="ri-moon-line"></i></button>

  <button class="fab-btn" id="back-top" aria-label="回到顶部"><i class="ri-arrow-up-line"></i></button>

</div>

<!-- 抽屉遮罩 -->

<div class="drawer-scrim" id="drawer-scrim"></div>



<!-- 夜间星空流星 Canvas（仅深色模式显示，纯装饰不挡交互） -->

<canvas id="universe" aria-hidden="true"></canvas>

<script src="<?php $this->options->themeUrl('assets/js/meteor.min.js'); ?>?v=1.2.5" defer></script>



<!-- 手机端底部固定导航（后台可设置，每行：名称|链接|图标，支持可视化编辑） -->

<?php

$triMobileRaw = trim((string) tri_opt('mobileMenu', ''));

if ($triMobileRaw === '') {

    $triMobileRaw = '首页|' . (string) $this->options->siteUrl . '|ri-home-5-line' . "

" . '文章|' . (string) $this->options->siteUrl . '|ri-article-line' . "

" . '归档|' . (string) $this->options->siteUrl . '|ri-archive-line' . "

" . '留言|' . (string) $this->options->siteUrl . '|ri-chat-3-line' . "

" . '关于|' . (string) $this->options->siteUrl . '|ri-information-line';

}

/* 兼容可视化编辑器 JSON 输出 + 历史双重转义脏数据（tri3_menu_decode 统一容错） */

$triMobileDecoded = function_exists('tri3_menu_decode') ? tri3_menu_decode($triMobileRaw) : $triMobileRaw;

$triMobileItems = array();

if (is_array($triMobileDecoded)) {

    foreach ($triMobileDecoded as $triMj) {

        if (!is_array($triMj)) { continue; }

        $triMName = trim((string) ($triMj['name'] ?? ''));

        $triMUrl  = trim((string) ($triMj['url'] ?? ''));

        if ($triMName === '' || $triMUrl === '') { continue; }

        $triMIcon = trim((string) ($triMj['icon'] ?? ''));

        if ($triMIcon === '') { $triMIcon = tri3_nav_icon($triMName); }

        $triMobileItems[] = array('name' => $triMName, 'url' => $triMUrl, 'icon' => $triMIcon);

    }

} else {

    foreach (preg_split('/\r\n|\r|\n/', $triMobileDecoded) as $triMLine) {

        $triMLine = trim($triMLine);

        if ($triMLine === '') { continue; }

        $triMobileParts = explode('|', $triMLine);

        $triMName = trim($triMobileParts[0] ?? '');

        $triMUrl  = trim($triMobileParts[1] ?? '');

        if ($triMName === '' || $triMUrl === '') { continue; }

        $triMIcon = isset($triMobileParts[2]) && trim($triMobileParts[2]) !== '' ? trim($triMobileParts[2]) : tri3_nav_icon($triMName);

        $triMobileItems[] = array('name' => $triMName, 'url' => $triMUrl, 'icon' => $triMIcon);

    }

}

?>

<?php if (!empty($triMobileItems)): ?>

<?php $triMobileIsIndex = $this->is('index');

$triMobileUri = (string) $this->request->getRequestUri(); ?>

<nav class="mobile-tabbar" aria-label="手机端底部菜单">

  <span class="tri-tab-pill" aria-hidden="true"></span>

  <?php $triMobileHomeActive = false;

  foreach ($triMobileItems as $triM): ?>

    <?php $triMHrefPath = parse_url($triM['url'], PHP_URL_PATH) ?? '';

    $triMIsHome = ($triMHrefPath === '' || $triMHrefPath === '/' || $triMHrefPath === '/index.php' || $triMHrefPath === '/index.php/');

    /* active 判定：首页链接仅首页高亮且同链接只高亮首个（避免多个 tab 同时激活） */

    if ($triMIsHome) {

        $triMActive = $triMobileIsIndex && !$triMobileHomeActive;

        if ($triMActive) { $triMobileHomeActive = true; }

    } else {

        $triMActive = !$triMobileIsIndex && $triMHrefPath !== '' && strpos($triMobileUri, $triMHrefPath) !== false;

    } ?>

    <a class="mobile-tab<?php echo $triMActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($triM['url']); ?>"<?php echo $triMActive ? ' aria-current="page"' : ''; ?>>

      <i class="<?php echo htmlspecialchars($triM['icon']); ?>"></i><span><?php echo htmlspecialchars($triM['name']); ?></span>

    </a>

  <?php endforeach; ?>

</nav>

<?php endif; ?>



<footer class="site-footer">

  <?php

  $triFooter = trim((string) tri_opt('footerHtml', ''));

  if ($triFooter !== '') {

      echo function_exists('tri3_sign_content_imgs') ? tri3_sign_content_imgs($triFooter) : $triFooter;

  } else {

      echo '<p>© ' . date('Y') . ' <a href="' . htmlspecialchars($this->options->siteUrl) . '">' . htmlspecialchars($this->options->title) . '</a> · Powered by <a href="https://typecho.org" target="_blank" rel="noopener">Typecho</a> · Theme <strong>TriM3</strong></p>';

  }

  ?>

</footer>



<?php $this->footer(); ?>

<?php

/* 性能优化：脚本全量 defer

 *  - defer 保证按文档顺序执行、DOMContentLoaded 前就绪，与同步加载功能完全一致，但不阻塞解析

 *  - 核心脚本：消息 / 懒加载 / 动效 / 流星 / 主逻辑 / 登录

 *  - 功能模块：灯箱（fancybox→lightbox 保序）/ 说说 / 相册 / 播放器 / 小组件

 *  - 文章页：joe-content（joe 短代码渲染，音乐卡片联动 TriPlayer；APlayer 库由该文件按需动态加载，Pjax 由 main.js 重执行，幂等）

 */

?>

<!-- 全局注入 list-api 地址（所有页面均携带，Pjax 切换后 main.js 会动态读取，避免回退到根路径 404） -->

<script data-home-list-api="<?php echo htmlspecialchars(rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . (string) $this->options->theme . '/api/list-api.php'); ?>"></script>

<?php

/* 全局注入说说/登录配置（所有页面均携带，否则搜索等非首页页面图集左右按钮、登录态不生效） */

$triGLogged = $this->user->hasLogin();

$triGAdmin = $triGLogged && $this->user->pass('administrator');

$triGName = $triGLogged ? (string) $this->user->name : '';

$triGAvatar = $triGLogged ? tri3_avatar_url(96) : '';

$triGThemeUrl = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . (string) $this->options->theme;

?>

<script>

window.TRI3_MOMENT = {api: <?php echo json_encode($triGThemeUrl . '/api/moment-api.php'); ?>, isAdmin: <?php echo $triGAdmin ? 'true' : 'false'; ?>};

window.TRI3_CMT = {api: <?php echo json_encode($triGThemeUrl . '/api/comment-api.php'); ?>};

window.TRI3_WIDGETS = {api: <?php echo json_encode($triGThemeUrl . '/api/widget-api.php'); ?>};

window.TRI3_LOGIN = {logged: <?php echo $triGLogged ? 'true' : 'false'; ?>, name: <?php echo json_encode($triGName); ?>, avatar: <?php echo json_encode($triGAvatar); ?>, adminUrl: <?php echo json_encode((string) $this->options->adminUrl); ?>, loginApi: <?php echo json_encode($triGThemeUrl . '/api/login-api.php'); ?>};

</script>

<script src="<?php $this->options->themeUrl('assets/js/tri-msg.min.js'); ?>?v=1.2.5" defer></script>

<script src="<?php $this->options->themeUrl('assets/js/lazy.min.js'); ?>?v=1.2.5&fix=20261104" defer></script>

<script src="<?php $this->options->themeUrl('assets/js/reveal.js'); ?>?v=1.2.5" defer></script>

<script src="<?php $this->options->themeUrl('assets/js/motion.min.js'); ?>?v=1.2.5" defer></script>

<?php
/* 灯箱依赖（jQuery + lightGallery 核心/插件）：仅在可能出现图片画廊的页面加载。
   header.php 与 footer.php 是各自独立的 $this->need() 调用（Widget_Archive::need 内部
   require，方法局部作用域，互不共享普通变量），页面类型/无画廊标记改由 $GLOBALS 传递：
   $GLOBALS['__tri_page_key']（header.php 设置）、$GLOBALS['__tri_no_gallery']（404.php 设置）。
   跳过音乐页与 404，其余页面（首页/文章/说说/相册/豆瓣/番剧/归档/分类/搜索）都可能含
   .lg-trigger 图片，保留加载。 */
$triGalleryOn = empty($GLOBALS['__tri_no_gallery']) && ($GLOBALS['__tri_page_key'] ?? '') !== 'music';
?>
<?php if ($triGalleryOn): ?>
<script src="<?php $this->options->themeUrl('assets/js/jquery.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/lightgallery-v3.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/lg-thumbnail.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/lg-pager.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/lg-zoom.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/lg-fullscreen-v2.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/lg-autoplay.min.js'); ?>?v=1.2.5" defer></script>
<?php endif; ?>

<script src="<?php $this->options->themeUrl('assets/js/main-v154.min.js'); ?>?v=1.3.1&fix=20261108" defer></script>

<script src="<?php $this->options->themeUrl('assets/js/widgets.min.js'); ?>?v=1.2.5&trc=1.2.0" defer></script>

<script src="<?php $this->options->themeUrl('assets/js/login.min.js'); ?>?v=1.2.5" defer></script>
<script src="<?php $this->options->themeUrl('assets/js/notification.js'); ?>?v=1.2.5" defer></script>

<!-- 功能模块全量 defer（不阻塞解析，DOMContentLoaded 前按序就绪，无滞后；fancybox 先于 lightbox） -->

<!-- moment.min.js 已移入页面容器（见 index.php / page-moment.php），此处不再加载，避免切页前重复注册 -->

<?php if (tri_switch('playerEnable', '1')): ?>

<!-- player-v3-1790265431.min.js is loaded from header for Swup singleton persistence -->

<?php endif; ?>


<script src="<?php $this->options->themeUrl('assets/js/joe-content-v174.min.js'); ?>?v=1.2.5" defer></script>

<script src="<?php $this->options->themeUrl('assets/js/code-highlight-loader.js'); ?>?v=1.2.5" defer></script>







<script>

/* Standalone interactive handlers - independent from other JS, Swup-safe */

(function(){

  'use strict';

  /* v1.2.51：本块只往 document 上挂监听，而 document 是常驻对象；换页时这段
     内联脚本会被 Swup 重新插入并重跑，监听就成倍叠加，一次点击被多套逻辑处理，
     多个访问互相取消后 Swup 报 Failed to fetch 并 reload（表现为点回首页整页刷新）。
     这里只需注册一次。 */
  if (window.__triFooterHandlers) { return; }
  window.__triFooterHandlers = 1;



  /* Clean up leftover masks from previous page loads */

  (function(){

    var selectors = '.tri-cmt-mask, .tri-edit-mask, .tri-dialog-mask';

    var masks = document.querySelectorAll(selectors);

    for (var i = 0; i < masks.length; i++) { masks[i].remove(); }

  })();



  document.addEventListener('swup:visit:start', function(){document.documentElement.classList.add('tri-nav');});



  /* Dialog close buttons - independent */

  document.addEventListener('click', function(e){

    try {

      var closeBtn = e.target.closest('.tri-cmt-close, .tri-edit-close, .tri-say-close');

      if (closeBtn) {

        var mask = closeBtn.closest('.tri-cmt-mask, .tri-edit-mask, .tri-dialog-mask');

        if (mask) { mask.hidden = true; }

        document.body.style.overflow = '';

      }

      /* Click mask background to close */

      var mask = e.target.closest('.tri-cmt-mask, .tri-edit-mask, .tri-dialog-mask');

      if (mask && e.target === mask) {

        mask.hidden = true;

        document.body.style.overflow = '';

      }

    } catch(err) {}

  });

})();

</script>











<script>
/* v1.2.51：document 级监听只注册一次（换页重跑会叠加）。 */
if (!window.__triImgCacheWarm) {
  window.__triImgCacheWarm = 1;

/* v1.2.87：只把「当前视口内已经该出图」的图落实成 src，供 Swup 缓存快照使用。
   旧写法会把整页 img[data-src] 一次性转成 src，等于把按视口加载整条链路绕过去。 */

document.addEventListener('swup:visit:start', function() {

  var vh = window.innerHeight || 800;

  var imgs = document.querySelectorAll('img[data-src]');

  imgs.forEach(function(img) {

    if (!img.dataset || !img.dataset.src) { return; }

    var r = img.getBoundingClientRect();

    if (!(r.height > 0 && r.top < vh + 100 && r.bottom > -100)) { return; }

    if (window.Tri3Lazy && window.Tri3Lazy.loadNow) {

      try { window.Tri3Lazy.loadNow(img); return; } catch (e) {}

    }

    img.src = img.dataset.src;

    img.removeAttribute('data-src');

  });

});




}
</script>



<!-- TriPlayer persists across Swup navigation as a singleton; no continuity script needed. -->
<!-- TriM3 · Stimulus 应用入口（Swup 配套）：导航后初始化的唯一入口 -->

<script type="module" src="<?php $this->options->themeUrl('assets/js/tri-app.js'); ?>?v=1.2.6"></script>

</body>

</html>

<?php if (tri_opt('dynamicColor', '0') === '1'): ?>

<script>window.TriM3_HOME = <?php echo json_encode(rtrim($this->options->siteUrl, '/')); ?> + '/';</script>

<?php endif; ?>


<?php if (function_exists('tri3_ri_audit_output')) { tri3_ri_audit_output(); } ?>

<?php if (function_exists('tri3_imgcache_maybe_warm')) { tri3_imgcache_maybe_warm(); } ?>
