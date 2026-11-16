<?php
/**
 * Material Design 3 三栏个人博客主题 · Swup 无刷新导航 · 动态取色 · 说说/相册/豆瓣/番剧/友链
 * macOS 风格代码高亮 · lightgallery 灯箱 · 集成音乐歌单播放器 · 深色模式 · 图片代理缓存 · 全站响应式
 *
 * @package TriM3
 * @author 薄荷
 * @link https://github.com/asplun/TriM3
 * @version 1.2.7
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>
<?php
/* ================= /category/ 分类总览页（自定义路由 category_root） ================= */
$triIsCatRoot = (string) ($this->parameter->type ?? '') === 'category_root';
if ($triIsCatRoot):
$triCatRootWidget = Typecho_Widget::widget('Widget_Metas_Category_List');
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <header class="single-header page-header-hero">
      <span class="page-title-badge"><i class="ri-folder-2-line"></i>分类总览</span>
      <h1 class="single-title">分类</h1>
      <span class="page-title-rule"></span>
      <div class="single-meta">
        <span><i class="ri-archive-line"></i>文章分类总览</span>
      </div>
    </header>
    <div class="cat-grid">
      <?php while ($triCatRootWidget->next()): ?>
      <a class="cat-card tri3-reveal main-reveal" href="<?php $triCatRootWidget->permalink(); ?>">
        <span class="cat-card-icon"><i class="ri-folder-2-line"></i></span>
        <span class="cat-card-body">
          <span class="cat-card-name"><?php $triCatRootWidget->name(); ?></span>
          <?php if ((string) $triCatRootWidget->description !== ''): ?>
          <span class="cat-card-desc"><?php $triCatRootWidget->description(); ?></span>
          <?php endif; ?>
        </span>
        <span class="cat-card-count"><?php $triCatRootWidget->count(); ?> 篇</span>
      </a>
      <?php endwhile; ?>
    </div>
  </main>

  <?php $this->need('sidebar.php'); ?>
  <!-- 页面专属脚本：分类总览无说说交互，不加载 moment.min.js -->

  

  

  

</div>
<?php $this->need('footer.php'); ?>
<?php return; endif; ?>

<?php
$triLogged = $this->user->hasLogin();
$triLoginName = $triLogged ? (string) $this->user->name : '';
$triLoginMail = $triLogged ? (string) $this->user->mail : '';
/* 登录后头像：统一用博主头像源（后台头像优先，未设置则后台邮箱走 Cravatar）。
   避免登录账号邮箱在 Cravatar 无头像时显示默认 identicon 几何图，而非博主头像 */
$triLoginAvatar = $triLogged ? tri3_avatar_url(96) : '';
$triLoginApi = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . $this->options->theme . '/api/login-api.php';
$triMomentApi = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . $this->options->theme . '/api/moment-api.php';
$triMomentAdmin = $triLogged && $this->user->pass('administrator');
/* 首页文章列表模式：original=原样（文章列表）/ moment=说说模式 / fusion=融合模式（文章+说说按时间线） */
$triHomeMode = tri_opt('homeListMode', 'original');
/* moment-card / post-moment-card 依赖的博主信息变量（与 page-moment.php 同名） */
$avatarUrl = tri3_avatar_url(96);
$authorName = $triAuthor = tri_opt('authorName', (string) $this->options->title);
$isAdmin = $triMomentAdmin;
$triIsAdmin = $triMomentAdmin;
/* 头像播放器数据：Meting API（平台+资源ID 自动拉取）或自定义歌单（每行 封面URL | 标题 | 音频URL） */
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
        /* Meting 模式：解析歌单链接/ID（兼容旧 metingId 直填） */
        $triMetParsed = tri3_player_source();
        $triMetingServer = $triMetParsed['server'];
        $triMetingType = $triMetParsed['type'];
        $triMetingId = $triMetParsed['id'];
        $triPlayerOn = $triMetingId !== '';
    }
}
/* Meting API 地址：后台填写则用之；留空默认本站内置代理 */
$triMetingApi = trim((string) tri_opt('metingApi', ''));
if ($triMetingApi === '') {
    $triMetingApi = (string) ($this->options->themeUrl ?? '');
    if ($triMetingApi !== '' && substr($triMetingApi, -1) !== '/') { $triMetingApi .= '/'; }
    $triMetingApi .= 'api/meting-api.php';
}
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <?php
    $triHasBanner = $this->is('index') && tri_switch('bannerEnable');
    $triActionsHtml = '';
    if (tri_switch('rssEnable')) $triActionsHtml .= '<a class="icon-btn" href="' . htmlspecialchars((string) $this->options->feedUrl) . '" aria-label="RSS"><i class="ri-rss-line"></i></a>';
    if (tri_opt('weibo')) $triActionsHtml .= '<a class="icon-btn" href="' . htmlspecialchars((string) tri_opt('weibo')) . '" target="_blank" rel="noopener" aria-label="微博"><i class="ri-weibo-line"></i></a>';
    if (tri_opt('wechat')) $triActionsHtml .= '<a class="icon-btn" href="weixin://" aria-label="微信"><i class="ri-wechat-line"></i></a>';
    if (tri_opt('github')) $triActionsHtml .= '<a class="icon-btn" href="' . htmlspecialchars((string) $this->options->github) . '" target="_blank" rel="noopener" aria-label="GitHub"><i class="ri-github-line"></i></a>';
    if (tri_opt('email')) $triActionsHtml .= '<a class="icon-btn" href="mailto:' . htmlspecialchars((string) $this->options->email) . '" aria-label="邮箱"><i class="ri-mail-line"></i></a>';
    $triLoginBtnHtml = '<button class="icon-btn tri-login-entry" type="button" aria-label="' . ($triLogged ? '用户菜单' : '登录') . '">'
      . ($triLogged && $triLoginAvatar !== ''
        ? '<img class="tri-login-avatar" src="' . htmlspecialchars($triLoginAvatar) . '" alt="' . htmlspecialchars($triLoginName) . '">'
        : '<i class="ri-user-line"></i>')
      . '</button>';
    /* Banner 消息入口始终展示；通知内容仅登录后可读取。 */
    $triNotifyBtnHtml = '<button class="icon-btn tri-notify-entry" type="button" aria-label="消息通知" title="消息通知" aria-haspopup="dialog" aria-expanded="false"><i class="ri-notification-3-line"></i><span class="tri-notify-dot" aria-hidden="true"></span></button>';
    /* 登录态：banner 右上角「说说」发布按钮（点击弹窗发布） */
    $triSayBtnHtml = $triLogged
      ? '<button class="icon-btn tri-say-entry" type="button" aria-label="发布说说" title="发布说说"><i class="ri-quill-pen-line"></i></button>'
      : '';
    ?>
    <?php if ($triHasBanner): $bannerUrls = tri_banner_urls(); ?>
    <section class="hero-slider" aria-label="Banner 轮播" data-interval="<?php echo (int) tri_opt('bannerInterval', '5'); ?>">
      <div class="slider-track">
        <?php $bi = 0; foreach ($bannerUrls as $burl): $bi++; ?>
        <div class="slide<?php echo $bi === 1 ? ' is-active' : ''; ?>">
          <?php if (is_numeric($burl)): ?><img src="<?php $this->options->themeUrl('assets/banner-' . $burl . '.jpg'); ?>" alt=""<?php echo $bi === 1 ? ' fetchpriority="high"' : ' loading="lazy"'; ?>>
          <?php else: $burl_sig = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($burl) : $burl; $burl_set = function_exists('tri3_img_srcset') ? tri3_img_srcset($burl_sig, array(640, 960, 1240)) : ''; ?><img src="<?php echo $burl_sig; ?>"<?php if ($burl_set !== '') { echo ' srcset="' . htmlspecialchars($burl_set) . '" sizes="100vw"'; } ?> alt=""<?php echo $bi === 1 ? ' fetchpriority="high"' : ' loading="lazy" fetchpriority="low"'; ?>><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="slider-dots" role="tablist">
        <?php $bi = 0; foreach ($bannerUrls as $burl): $bi++; ?>
        <button class="dot<?php echo $bi === 1 ? ' is-active' : ''; ?>" type="button" aria-label="第 <?php echo $bi; ?> 张"></button>
        <?php endforeach; ?>
      </div>
      <?php if (tri_switch('bannerSearch', '1')): ?>
      <div class="tri-search-corner" data-controller="search">
        <button class="icon-btn tri-search-entry" type="button" aria-label="搜索" data-action="click->search#toggle"><i class="ri-search-line"></i></button>
        <form class="tri-search-expand" method="get" action="<?php $this->options->siteUrl(); ?>" role="search" data-no-swup>
          <input type="text" name="s" placeholder="搜索文章…" autocomplete="off" aria-label="搜索关键词">
        </form>
      </div>
      <?php endif; ?>
      <div class="tri-login-corner"><?php echo $triSayBtnHtml . $triNotifyBtnHtml . $triLoginBtnHtml; ?></div>
      <?php if ($triActionsHtml !== ''): ?><div class="profile-actions"><?php echo $triActionsHtml; ?></div><?php endif; ?>
      <?php if ($triPlayerOn): ?>
      <canvas class="player-vis" width="85" height="46" aria-hidden="true"></canvas>
      <div class="player-progress" role="progressbar" aria-label="播放进度" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div class="player-progress-bar"></div>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
    $stat2 = Typecho_Widget::widget('Widget_Stat');
    $triAuthor = tri_opt('authorName', (string) $this->options->title);
    $triBio = tri_opt('authorBio', (string) $this->options->description);
    ?>
    <div class="profile-card<?php echo $triHasBanner ? ' has-banner' : ''; ?>">
      <span class="profile-avatar<?php echo $triPlayerOn ? ' tri-player-avatar' : ''; ?>">
        <?php $triProfileAv = tri3_avatar_url(104); ?>
        <?php if ($triProfileAv !== ''): ?>
          <img src="<?php echo $triProfileAv; ?>" alt="<?php echo $triAuthor; ?>" fetchpriority="high">
        <?php else: ?>
          <span class="profile-avatar-fallback"><?php echo trim3_first_char($triAuthor); ?></span>
        <?php endif; ?>
        <?php if ($triPlayerOn): ?>
        <span class="player-hover-play" aria-hidden="true"><i class="ri-play-fill"></i></span>
        <span class="player-controls">
          <button type="button" class="player-ctrl player-prev" aria-label="上一曲"><i class="ri-skip-back-line"></i></button>
          <button type="button" class="player-ctrl player-toggle" aria-label="播放/暂停"><i class="ri-play-fill"></i></button>
          <button type="button" class="player-ctrl player-next" aria-label="下一曲"><i class="ri-skip-forward-line"></i></button>
        </span>
        <?php endif; ?>
      </span>
      <div class="profile-info">
        <div class="profile-top">
          <h3 class="profile-name"><?php echo $triAuthor; ?></h3>
          <?php if ($triPlayerOn): ?>
          <div class="player-side" aria-label="播放器控制">
            <div class="player-vol-pop" hidden>
              <input type="range" min="0" max="100" step="1" value="70" class="player-vol-slider" aria-label="音量">
              <span class="player-vol-num">70</span>
            </div>
            <button type="button" class="player-vol" aria-label="音量" title="音量"><i class="ri-volume-up-line"></i></button>
            <button type="button" class="player-plist" aria-label="播放列表" title="播放列表"><i class="ri-play-list-2-line"></i></button>
            <button type="button" class="player-lyric" aria-label="歌词" title="歌词"><i class="ri-file-music-line"></i></button>
            <div class="player-plist-pop" hidden>
              <div class="player-plist-head"><i class="ri-play-list-2-line"></i><span>播放列表</span></div>
              <div class="player-plist-inner"><ul class="player-plist-items"></ul></div>
            </div>
          </div>
          <?php endif; ?>
          <?php if (!$triHasBanner): ?><div class="profile-actions"><?php echo $triLoginBtnHtml . $triActionsHtml; ?></div><?php endif; ?>
        </div>
      </div>
      <p class="profile-bio"><?php echo $triBio; ?></p>
      <?php $triSign = trim((string) tri_opt('authorSignature', '')); ?>
      <?php if ($triSign !== ''): ?><p class="profile-bio profile-sign"><?php echo htmlspecialchars($triSign, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
      <?php if (trim((string) tri_opt('profileExtras')) !== ''): ?>
      <div class="profile-extras">
        <?php foreach (preg_split('/\r\n|\r|\n/', (string) tri_opt('profileExtras')) as $triLine): ?>
          <?php $triLine = trim($triLine); if ($triLine === '') continue; ?>
          <span class="profile-extra"><?php echo $triLine; ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php $triStatsMode = trim((string) tri_opt('statsMode', 'default')); ?>
      <?php if ($triStatsMode === 'custom'): ?>
        <?php $triCustomStats = (string) tri_opt('statsCustomHtml', ''); ?>
        <?php if (trim($triCustomStats) !== ''): ?>
        <div class="profile-stats-custom"><?php echo $triCustomStats; ?></div>
        <?php endif; ?>
      <?php elseif ($triStatsMode !== 'none'): ?>
        <?php $triShowLikes = tri_switch('showLikes', '1'); $triShowComments = tri_switch('showComments', '1'); $triShowViews = tri_switch('showViews', '1'); ?>
        <?php if ($triShowLikes || $triShowComments || $triShowViews): ?>
        <div class="profile-stats">
          <?php if ($triShowLikes): ?>
          <span class="profile-stat"><b class="tri3-count" data-count="<?php echo (int) trim((string) $this->options->likesNum); ?>"><?php echo number_format((int) trim((string) $this->options->likesNum)); ?></b><em>点赞</em></span>
          <?php endif; ?>
          <?php if ($triShowComments): ?>
          <span class="profile-stat"><b class="tri3-count" data-count="<?php $stat2->publishedCommentsNum(); ?>"><?php $stat2->publishedCommentsNum(); ?></b><em>评论</em></span>
          <?php endif; ?>
          <?php if ($triShowViews): ?>
          <span class="profile-stat"><b class="tri3-count" data-count="<?php echo (int) trim((string) $this->options->viewsNum); ?>"><?php echo number_format((int) trim((string) $this->options->viewsNum)); ?></b><em>访问</em></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php $triNotice = trim((string) tri_opt('noticeText', '')); ?>
    <?php if ($triNotice !== ''): ?>
    <div class="tri-notice tri3-reveal main-reveal">
      <span class="tri-notice-ic"><i class="ri-notification-3-line"></i></span>
      <div class="tri-notice-text"><?php echo function_exists('tri3_sign_content_imgs') ? tri3_sign_content_imgs($triNotice) : $triNotice; ?></div>
    </div>
    <?php endif; ?>

    <?php $this->need('component/home-menu.php'); ?>

    <?php
    /* 融合/说说模式用独立分页参数 hp：Typecho 核心对文章 totalPage 越界的 page=N 会返回 404，
       而 hp 参数 Typecho 不识别、不会触发越界 404，分页完全由主题接管 */
    $triPageNo = $triHomeMode === 'original'
        ? max(1, (int) $this->request->get('page', 1))
        : max(1, (int) $this->request->get('hp', 1));
    $triPageSize = max(1, (int) $this->options->pageSize);
    $triHomeMode = (string) $triHomeMode;
    ?>

    <div class="home-list <?php echo 'home-list-' . htmlspecialchars(tri_opt('postCardStyle', 'original')); ?> <?php if ($triHomeMode === 'posts' && tri_opt('postCardStyle', 'original') === 'grid') echo 'grid-layout'; ?>" id="tri3-home-list" data-tri3-type="<?php echo $triHomeMode === 'original' ? 'posts' : $triHomeMode; ?>" data-tri3-page="<?php echo $triPageNo; ?>">
    <?php
    /* 融合/说说模式用独立分页参数 hp：Typecho 核心对文章 totalPage 越界的 page=N 会返回 404，
       而 hp 参数 Typecho 不识别、不会触发越界 404，分页完全由主题接管 */
    if ($triHomeMode === 'moment') {
        /* ============ 说说模式：首页直接展示说说流 ============ */
        $triMoments = tri_moment_list($triPageNo, $triPageSize, $triIsAdmin, 'all', '');
        $triMomentTotal = tri_moment_count($triIsAdmin, 'all', '');
        $triMomentTotalPage = max(1, (int) ceil($triMomentTotal / $triPageSize));
        foreach ($triMoments as $m) {
            $GLOBALS['__tri_item'] = null;
            include __DIR__ . '/component/moment-card.php';
        }
        if (empty($triMoments)) {
            echo '<div class="moment-empty"><i class="ri-quill-pen-line"></i>还没有说说，来发布第一条吧～</div>';
        }
        tri3_pagination($triPageNo, $triMomentTotalPage, '?hp=', '#tri3-home-list', array('type' => 'moment'));
    } elseif ($triHomeMode === 'fusion') {
        /* ============ 融合模式：文章 + 说说 按时间线混合 ============ */

        $triFuseItems = tri_fusion_items($triIsAdmin);
        $triFuseTotal = count($triFuseItems);
        $triFuseTotalPage = max(1, (int) ceil($triFuseTotal / $triPageSize));
        $triFuseSlice = array_slice($triFuseItems, ($triPageNo - 1) * $triPageSize, $triPageSize);
        foreach ($triFuseSlice as $triFi) {
            if ($triFi['type'] === 'post') {
                $GLOBALS['__tri_fuse_post'] = $triFi;
                include __DIR__ . '/component/post-moment-card.php';
            } else {
                $m = $triFi['moment'];
                include __DIR__ . '/component/moment-card.php';
            }
        }
        if (empty($triFuseSlice)) {
            echo '<div class="moment-empty"><i class="ri-quill-pen-line"></i>还没有内容，来发布第一条吧～</div>';
        }
        tri3_pagination($triPageNo, $triFuseTotalPage, '?hp=', '#tri3-home-list', array('type' => 'fusion'));
    } else {
        /* ============ 原样：现有文章列表 ============ */
        while ($this->next()): ?>
    <?php $GLOBALS['__tri_item'] = $this; $this->need('component/list-item.php'); ?>
    <?php endwhile;
        tri3_pagination($triPageNo, max(1, (int) ceil($this->getTotal() / $triPageSize)), '', '#tri3-home-list', array('type' => 'posts'));
    }
    ?>
    </div>
  </main>

  <?php $this->need('sidebar.php'); ?>
  <script src="<?php $this->options->themeUrl('assets/js/moment.min.js'); ?>?v=1.2.5" defer></script>
  <!-- 页面专属脚本（两个布局分支各一份，实际只会渲染其中一个），必须留在 .layout 容器内 -->


  <!-- Pjax 需携带的全局状态脚本（必须位于 .layout 容器内，换页时随容器一并替换） -->
  <script>
  window.TRI3_LOGIN = {logged: <?php echo $triLogged ? 'true' : 'false'; ?>, name: <?php echo json_encode($triLoginName); ?>, avatar: <?php echo json_encode($triLoginAvatar); ?>, adminUrl: <?php echo json_encode((string) $this->options->adminUrl); ?>, loginApi: <?php echo json_encode($triLoginApi); ?>};
  window.TRI3_MOMENT = {api: <?php echo json_encode($triMomentApi); ?>, isAdmin: <?php echo $triMomentAdmin ? 'true' : 'false'; ?>};
  window.TRI3_NOTIFY = {api: <?php echo json_encode(rtrim((string) $this->options->themeUrl, '/') . '/api/notification-api.php'); ?>, viewsApi: <?php echo json_encode(rtrim((string) $this->options->themeUrl, '/') . '/api/widget-api.php'); ?>, today: <?php echo (int) (tri_widgets_views()['today'] ?? 0); ?>};
  </script>
  <script data-home-list-api="<?php echo htmlspecialchars(rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . (string) $this->options->theme . '/api/list-api.php'); ?>" data-home-mode="<?php echo htmlspecialchars((string) $triHomeMode, ENT_QUOTES, 'UTF-8'); ?>"></script>
</div>
<div class="tri-dialog-mask" hidden>  
</div>
<?php $this->need('footer.php'); ?>
