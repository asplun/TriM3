<?php
/**
 * TriM3 首页列表局部刷新 AJAX 接口
 *
 * GET  type=posts|moment|page
 *   type=posts（默认）:
 *     cat=slug   分类 slug（留空 = 全部文章）
 *     page=N     页码（从 1 起）
 *   type=moment:
 *     cat=slug   说说分类 slug（留空 = 全部说说）
 *     page=N     说说分页
 *   type=page:
 *     slug=xxx   独立页面 slug（如 start-page）
 *
 * 返回：首页列表区片段（复用 component/list-item.php / component/moment-card.php 渲染，
 * 供首页菜单局部切换，banner/个人信息/边栏保持不变）。
 */

require_once dirname(__FILE__, 5) . '/config.inc.php';

if (!defined('__TYPECHO_ROOT_URL__')) {
    $triScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $triScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

\Widget\Init::alloc();

/* 主题函数（tri_opt / tri_switch / tri_excerpt_len / tri_moment_list 等） */
require_once dirname(__DIR__) . '/functions.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Tri3-List: 1');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$triType = trim((string) ($_GET['type'] ?? 'posts'));
$triSlug = trim((string) ($_GET['slug'] ?? ''));
$triCatSlug = trim((string) ($_GET['cat'] ?? ''));
$triPage = max(1, (int) ($_GET['page'] ?? 1));
/* 列表容器选择器：由调用方传入并在片段里回填，保证“加载更多”按钮链条不断。
   首页 / 文章页 / 分类页 / 说说页各自指向自己的列表容器。 */
$triListSel = trim((string) ($_GET['list'] ?? '')) ?: '#tri3-home-list';
$triApiParams = array('type' => $triType);
if ($triCatSlug !== '') { $triApiParams['cat'] = $triCatSlug; }
if ($triSlug !== '') { $triApiParams['slug'] = $triSlug; }
$triApiParams['list'] = $triListSel;

/* 列表每页条数：与 index.php 首屏（$this->options->pageSize）保持一致，
   否则加载更多/分页切片与首屏错位（重复、乱序）。 */
$triListSize = max(1, (int) Helper::options()->pageSize);

/* ================= type=moment：说说流 ================= */
if ($triType === 'moment') {
    /* moment-card.php 依赖 $isAdmin / $avatarUrl / $authorName 变量名，需与此一致 */
    $isAdmin = \Typecho\Widget::widget('Widget_User')->hasLogin()
        && \Typecho\Widget::widget('Widget_User')->pass('administrator');
    $triMoments = tri_moment_list($triPage, $triListSize, $isAdmin, 'all', $triCatSlug);
    $triTotal = tri_moment_count($isAdmin, 'all', $triCatSlug);
    $triTotalPage = max(1, (int) ceil($triTotal / $triListSize));
    $avatarUrl = tri3_avatar_url(96);
    $authorName = tri_opt('authorName', '博主');

    if (empty($triMoments)): ?>
<div class="moment-empty"><i class="ri-quill-pen-line"></i>还没有说说，来发布第一条吧～</div>
<?php endif;
    foreach ($triMoments as $m):
        include dirname(__DIR__) . '/component/moment-card.php';
    endforeach;
    if ($triTotalPage > 1):
    tri3_pagination($triPage, $triTotalPage, '?type=moment' . ($triCatSlug !== '' ? '&cat=' . urlencode($triCatSlug) : '') . '&page=', $triListSel, $triApiParams);
    endif;
    exit;
}

/* ================= type=fusion：融合模式列表（文章+说说按时间线） ================= */
if ($triType === 'fusion') {
    $isAdmin = \Typecho\Widget::widget('Widget_User')->hasLogin()
        && \Typecho\Widget::widget('Widget_User')->pass('administrator');
    $avatarUrl = tri3_avatar_url(96);
    $authorName = tri_opt('authorName', '博主');
    $triPageSizeF = $triListSize;
    $triFuseItems = tri_fusion_items($isAdmin);
    $triFuseTotalPage = max(1, (int) ceil(count($triFuseItems) / $triPageSizeF));
    $triFuseSlice = array_slice($triFuseItems, ($triPage - 1) * $triPageSizeF, $triPageSizeF);

    if (empty($triFuseSlice)): ?>
<div class="moment-empty"><i class="ri-quill-pen-line"></i>还没有内容，来发布第一条吧～</div>
<?php endif;
    foreach ($triFuseSlice as $triFi):
        if ($triFi['type'] === 'post'):
            $GLOBALS['__tri_fuse_post'] = $triFi;
            include dirname(__DIR__) . '/component/post-moment-card.php';
        else:
            $m = $triFi['moment'];
            include dirname(__DIR__) . '/component/moment-card.php';
        endif;
    endforeach;
    if ($triFuseTotalPage > 1):
    tri3_pagination($triPage, $triFuseTotalPage, '?type=fusion&page=', $triListSel, $triApiParams);
    endif;
    exit;
}

/* ================= type=page：独立页面内容 ================= */
if ($triType === 'page' && $triSlug !== '') {
    /* 注意：Typecho 1.3 的 Widget_Contents_Page_List 映射 Page\Rows，
       其 select 只含骨架字段（无 text），content() 必然为空；
       因此直接查完整行并自行渲染 Markdown。 */
    $triDb = \Typecho\Db::get();
    $triRow = $triDb->fetchRow($triDb->select()->from('table.contents')
        ->where('type = ? AND status = ? AND slug = ?', 'page', 'publish', $triSlug)
        ->limit(1));
    if ($triRow) {
        /* 安全门禁：密码保护页不得经列表接口绕过（只有管理员可以直接预览） */
        if ((string) ($triRow['password'] ?? '') !== '') {
            $triPwUser = null;
            try { $triPwUser = \Typecho\Widget::widget('Widget_User'); } catch (\Throwable $e) {}
            if (!$triPwUser || !$triPwUser->hasLogin() || !$triPwUser->pass('administrator', false)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok' => false, 'msg' => '该页面受密码保护'));
                exit;
            }
        }
        $triText = (string) ($triRow['text'] ?? '');
        $triIsMd = 0 === strpos($triText, '<!--markdown-->');
        if ($triIsMd) {
            $triText = substr($triText, 15);
            $triContent = \Utils\Markdown::convert($triText);
        } else {
            $triContent = $triText;
        }
        $triPageTitle = htmlspecialchars((string) ($triRow['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $triPageCid = (int) $triRow['cid'];
        $triPageDate = date('Y-m-d', (int) ($triRow['created'] ?? 0));
        $triPagePermalink = tri3_page_url($triSlug);
        $triPageAllowComment = (string) ($triRow['allowComment'] ?? '0');
        /* 评论分页参数（Typecho 惯例 commentPage） */
        $triPageCommentPage = max(1, (int) ($_GET['commentPage'] ?? 1));
        /* 页面模板分流：按 template 重新匹配各单页模板的最新实现 */
        $triTemplate = trim((string) ($triRow['template'] ?? ''));
        $triCmtUser = \Typecho\Widget::widget('Widget_User');
        $triAuthorName = htmlspecialchars((string) tri_opt('authorName', '博主'), ENT_QUOTES, 'UTF-8');

        /* ============ 相册模板：瀑布流懒加载显示所有图片 ============ */
        if ($triTemplate === 'page-albums.php'):
            require_once dirname(__DIR__) . '/lib/album.php';
            tri_album_ensure_tables();
            $triAlbumId = isset($_GET['album']) ? (int) $_GET['album'] : 0;
            if ($triAlbumId <= 0) {
                /* 后台设置：直接显示指定相册 */
                if (trim((string) Helper::options()->albumPageMode) === 'album') {
                    $triAlbumId = (int) Helper::options()->albumPageAid;
                }
            }
            $triAlbum = null;
            if ($triAlbumId > 0) {
                $triAlbum = tri_album_get($triAlbumId);
                if (!$triAlbum) {
                    $triAlbumId = 0;
                }
            }
            $triLayoutAll = ['grid', 'masonry', 'mosaic', 'list', 'slide'];
            $triLayout = trim((string) Helper::options()->albumDefaultLayout);
            if (!in_array($triLayout, $triLayoutAll, true)) {
                $triLayout = 'grid';
            }
            $triPageBadge = '<span class="page-title-badge"><i class="ri-gallery-line"></i>相册</span>';
            $triPageMeta = '<span><i class="ri-image-2-line"></i>瀑布流 · 懒加载</span>';
            if ($triAlbumId > 0 && $triAlbum):
                $triPhotos = tri_photo_list($triAlbumId);
                $triPhotoCnt = count($triPhotos);
                $triPageMeta = '<span><i class="ri-image-2-line"></i>' . $triPhotoCnt . ' 张图片</span>';
            else:
                $triAlbumsAll = tri_album_list();
                $triPhotoCnt = 0;
                foreach ($triAlbumsAll as $triA) { $triPhotoCnt += (int) $triA['count']; }
                $triPageMeta = '<span><i class="ri-image-2-line"></i>共 ' . $triPhotoCnt . ' 张图片</span>';
            /* 相册页专属资源：local 换页只替换容器内容、不合并 <head>，
               必须把本页样式随片段一起带过来，否则进相册页会丢样式 */
            /* tri3-local-assets */
            $tri3LocalBase = rtrim((string) Helper::options()->siteUrl, '/') . '/usr/themes/' . (string) Helper::options()->theme . '/';
            echo '<link rel="stylesheet" href="' . htmlspecialchars($tri3LocalBase . 'assets/css/album.min.css', ENT_QUOTES, 'UTF-8') . '?v=1.2.5">' . chr(10);
            echo '<script src="' . htmlspecialchars($tri3LocalBase . 'assets/js/album.min.js', ENT_QUOTES, 'UTF-8') . '?v=1.2.5" defer></script>' . chr(10);

            endif; ?>
<article class="tri3-page-single md-card tri3-reveal main-reveal">
  <header class="single-header page-header-hero banner-reveal">
    <?php echo $triPageBadge; ?>
    <h1 class="single-title"><?php echo $triPageTitle; ?></h1>
    <span class="page-title-rule"></span>
    <div class="single-meta"><?php echo $triPageMeta; ?></div>
  </header>
  <?php if ($triAlbumId > 0 && $triAlbum): ?>
  <div class="album-detail-local">
    <div class="album-local-head">
      <h2><i class="ri-image-2-line"></i><?php echo htmlspecialchars($triAlbum['name']); ?></h2>
      <?php if ($triAlbum['description'] !== ''): ?><p><?php echo htmlspecialchars($triAlbum['description']); ?></p><?php endif; ?>
      <span class="album-local-count"><?php echo $triPhotoCnt; ?> 张</span>
    </div>
    <?php if ($triPhotos): ?>
    <div class="album-mosaic is-<?php echo $triLayout; ?>" data-layout="<?php echo $triLayout; ?>" data-default-layout="<?php echo $triLayout; ?>">
      <?php foreach ($triPhotos as $triP): ?>
      <figure class="album-photo" data-pid="<?php echo (int) $triP['pid']; ?>">
        <a class="album-photo-btn" href="<?php echo htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($triP['url']) : $triP['url']); ?>" data-fancybox="album-<?php echo (int) $triAlbumId; ?>" data-title="<?php echo htmlspecialchars($triP['title']); ?>" aria-label="查看大图">
          <?php $triPT = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'], 800) : ($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url']);
                    $triPSt = function_exists('tri3_img_srcset') ? tri3_img_srcset($triPT, array(360, 540, 720, 900)) : ''; ?>
            <img data-src="<?php echo htmlspecialchars($triPT); ?>"<?php echo tri3_img_size_attr($triPT); ?><?php if ($triPSt !== '') { echo ' data-srcset="' . htmlspecialchars($triPSt) . '" data-sizes="(max-width:560px) 46vw, 320px"'; } ?>" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="<?php echo htmlspecialchars($triP['title']); ?>" decoding="async" data-fallback="<?php echo htmlspecialchars(function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'], 360) : ($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'])); ?>">
        </a>
        <?php if ($triP['title'] !== ''): ?>
        <figcaption class="album-photo-cap"><?php echo htmlspecialchars($triP['title']); ?></figcaption>
        <?php endif; ?>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="album-empty"><i class="ri-image-line"></i><p>这个相册还没有图片，去后台「相册管理」上传吧</p></div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="album-local-list">
    <?php
      $triAlbumsAll = tri_album_list();
      if (!empty($triAlbumsAll)):
          foreach ($triAlbumsAll as $triA):
              $triPhotos2 = tri_photo_list((int) $triA['aid']);
              if (empty($triPhotos2)) { continue; }
    ?>
    <section class="album-local-group">
      <h3 class="album-local-group-title"><?php echo htmlspecialchars($triA['name']); ?><span><?php echo (int) $triA['count']; ?> 张</span></h3>
      <div class="album-mosaic is-<?php echo $triLayout; ?>" data-layout="<?php echo $triLayout; ?>" data-default-layout="<?php echo $triLayout; ?>">
        <?php foreach ($triPhotos2 as $triP): ?>
        <figure class="album-photo" data-pid="<?php echo (int) $triP['pid']; ?>">
          <a class="album-photo-btn" href="<?php echo htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($triP['url']) : $triP['url']); ?>" data-fancybox="album-<?php echo (int) $triA['aid']; ?>" data-title="<?php echo htmlspecialchars($triP['title']); ?>" aria-label="查看大图">
            <?php $triPT = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'], 800) : ($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url']);
                    $triPSt = function_exists('tri3_img_srcset') ? tri3_img_srcset($triPT, array(360, 540, 720, 900)) : ''; ?>
            <img data-src="<?php echo htmlspecialchars($triPT); ?>"<?php echo tri3_img_size_attr($triPT); ?><?php if ($triPSt !== '') { echo ' data-srcset="' . htmlspecialchars($triPSt) . '" data-sizes="(max-width:560px) 46vw, 320px"'; } ?>" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="<?php echo htmlspecialchars($triP['title']); ?>" decoding="async" data-fallback="<?php echo htmlspecialchars(function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'], 360) : ($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'])); ?>">
          </a>
          <?php if ($triP['title'] !== ''): ?>
          <figcaption class="album-photo-cap"><?php echo htmlspecialchars($triP['title']); ?></figcaption>
          <?php endif; ?>
        </figure>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
          endforeach;
      else:
    ?>
    <div class="album-empty"><i class="ri-gallery-line"></i><p>还没有相册，去后台「相册管理」创建吧</p></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</article>
<?php
            try {
                include dirname(__DIR__) . '/component/page-comments.php';
            } catch (\Throwable $e) {
                echo '<!--TRICMT-ERR: ' . htmlspecialchars($e->getMessage()) . ' @ ' . htmlspecialchars((string) $e->getFile() . ':' . $e->getLine()) . '-->';
            }
            exit;
        endif;

        /* ============ 文章模板：全站文章列表（首页菜单「文章」局部切换 / 独立访问） ============ */
        if ($triTemplate === 'page-articles.php'):
            $triArtPage = max(1, (int) ($_GET['page'] ?? 1));
            $triArtSize = max(1, (int) Helper::options()->pageSize);
            $triArtTotal = (int) \Typecho\Widget::widget('Widget_Stat')->publishedPostsNum;
            $triArtTotalPage = max(1, (int) ceil($triArtTotal / $triArtSize));
            $triArtList = \Typecho\Widget::widget('Widget_Archive@artlocal_' . md5($triSlug . '_' . $triArtPage), array(
                'type'          => 'index',
                'pageSize'      => $triArtSize,
                'checkPermalink' => false,
            ), array('page' => $triArtPage));
            $triArtList->to($triArtList);
            $triPageBadge = '<span class="page-title-badge"><i class="ri-article-line"></i>文章</span>';
            $triPageMeta = '<span><i class="ri-file-list-3-line"></i>共 ' . $triArtTotal . ' 篇文章</span>';
            ?>
<article class="tri3-page-single md-card tri3-reveal main-reveal">
  <div class="articles-list">
    <?php if ($triArtList->have()): ?>
      <?php while ($triArtList->next()): ?>
        <?php $GLOBALS['__tri_item'] = $triArtList; include dirname(__DIR__) . '/component/list-item.php'; ?>
      <?php endwhile; ?>
    <?php else: ?>
      <div class="home-list-empty"><i class="ri-inbox-2-line"></i>这里还没有文章</div>
    <?php endif; ?>
  </div>
  <?php if ($triArtTotalPage > 1): ?>
    <?php tri3_pagination($triArtPage, $triArtTotalPage, '?type=page&slug=' . urlencode($triSlug) . '&page=', $triListSel, $triApiParams); ?>
  <?php endif; ?>
</article>
<?php
            exit;
        endif;

        /* ============ 友链模板：link-bar + shot-api 截图代理（与 page-links.php 一致） ============ */
        if ($triTemplate === 'page-links.php'):
            $triApply = trim((string) tri_opt('friendApply', ''));
            $triFriends = trim((string) tri_opt('friends', ''));
            $triGroupsMap = array(); $triGroupOrder = array(); $triLinkCount = 0;
            $triLinkRows = array();
            if ($triFriends !== '' && $triFriends[0] === '[') {
                /* 可视化编辑器输出的 JSON */
                $triLinkJson = json_decode($triFriends, true);
                if (is_array($triLinkJson)) {
                    foreach ($triLinkJson as $triLinkObj) {
                        if (!is_array($triLinkObj)) { continue; }
                        $triLinkRows[] = array(
                            'img'  => isset($triLinkObj['img']) ? trim((string) $triLinkObj['img']) : '',
                            'url'  => isset($triLinkObj['url']) ? trim((string) $triLinkObj['url']) : '',
                            'name' => isset($triLinkObj['name']) ? trim((string) $triLinkObj['name']) : '',
                            'desc' => isset($triLinkObj['desc']) ? trim((string) $triLinkObj['desc']) : '',
                            'grp'  => isset($triLinkObj['group']) ? trim((string) $triLinkObj['group']) : '',
                        );
                    }
                }
            } else {
                /* 兼容旧格式：头像URL, 站点URL, 名称, 描述, 分组名 */
                foreach (explode("\n", str_replace("\r\n", "\n", $triFriends)) as $triLinkRow) {
                    $triLinkRow = trim($triLinkRow);
                    if ($triLinkRow === '') { continue; }
                    $triLinkParts = explode(',', $triLinkRow);
                    if (count($triLinkParts) < 3) { continue; }
                    $triLinkRows[] = array(
                        'img'  => trim($triLinkParts[0]),
                        'url'  => trim($triLinkParts[1]),
                        'name' => trim($triLinkParts[2]),
                        'desc' => trim($triLinkParts[3] ?? ''),
                        'grp'  => trim($triLinkParts[4] ?? ''),
                    );
                }
            }
            foreach ($triLinkRows as $triLinkObj) {
                $triLImg = $triLinkObj['img'];
                $triLUrl = $triLinkObj['url'];
                $triLName = $triLinkObj['name'];
                if ($triLUrl === '' || $triLName === '') { continue; }
                $triLDesc = $triLinkObj['desc'];
                $triGrp = $triLinkObj['grp'];
                if ($triGrp === '') { $triGrp = '默认分组'; }
                    if (!isset($triGroupsMap[$triGrp])) {
                        $triGroupsMap[$triGrp] = array();
                        $triGroupOrder[] = $triGrp;
                    }
                    $triGroupsMap[$triGrp][] = array('img' => $triLImg, 'url' => $triLUrl, 'name' => $triLName, 'desc' => $triLDesc);
                    $triLinkCount++;
                }
            /* 分组顺序：后台 friendGroups 优先 */
            $triCfgGroups = array();
            $triCfgRaw = trim((string) tri_opt('friendGroups', ''));
            if ($triCfgRaw !== '') {
                foreach (explode("\n", str_replace("\r\n", "\n", $triCfgRaw)) as $triGLine) {
                    $triGLine = trim($triGLine);
                    if ($triGLine !== '') { $triCfgGroups[] = $triGLine; }
                }
            }
            if (!empty($triCfgGroups)) {
                $triOrder2 = $triCfgGroups;
                foreach ($triGroupOrder as $triG) {
                    if (!in_array($triG, $triOrder2, true)) { $triOrder2[] = $triG; }
                }
                $triGroupOrder = $triOrder2;
            }
            $triSiteUrl = rtrim((string) Helper::options()->siteUrl, '/');
            $triThemeDir = (string) Helper::options()->theme;
            $triShotBase = $triSiteUrl . '/usr/themes/' . $triThemeDir . '/api/shot-api.php?url=';
            ?>
<article class="tri3-page-single md-card tri3-reveal main-reveal">
  <header class="single-header page-header-hero banner-reveal">
    <span class="page-title-badge"><i class="ri-links-line"></i>友情链接</span>
    <h1 class="single-title"><?php echo $triPageTitle; ?></h1>
    <span class="page-title-rule"></span>
    <div class="single-meta"><span><i class="ri-link-m"></i><?php echo $triLinkCount; ?> 个站点</span></div>
  </header>
  <?php if ($triApply !== ''): ?>
  <div class="links-apply"><?php echo $triApply; ?></div>
  <?php endif; ?>
  <div class="links-page">
    <?php if ($triLinkCount > 0): ?>
      <?php foreach ($triGroupOrder as $triGrpKey):
          if (!isset($triGroupsMap[$triGrpKey])) { continue; }
          $triShowTitle = count($triGroupOrder) > 1; ?>
      <?php if ($triShowTitle): ?>
      <h2 class="links-group-title"><i class="ri-price-tag-3-line"></i><?php echo htmlspecialchars($triGrpKey); ?></h2>
      <?php endif; ?>
      <div class="links-grid">
        <?php foreach ($triGroupsMap[$triGrpKey] as $triLink):
            $triDomain = (string) parse_url($triLink['url'], PHP_URL_HOST);
            if ($triDomain === '') { $triDomain = $triLink['url']; }
            $triFirst = function_exists('mb_substr') ? mb_substr($triLink['name'], 0, 1) : substr($triLink['name'], 0, 1);
            $triShot = $triShotBase . urlencode($triLink['url']) . '&v=1.2.6'; ?>
        <?php include dirname(__DIR__) . '/component/link-item.php'; ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="links-empty">
      <i class="ri-link-m"></i>
      <p>暂无友链，去后台「设置外观 → ⑨ 友链」添加吧</p>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($triContent !== ''): ?>
  <div class="single-body post-content"><?php echo function_exists("tri3_sign_content_imgs") ? tri3_sign_content_imgs($triContent) : $triContent; ?></div>
  <?php endif; ?>
</article>
<?php
            try {
                include dirname(__DIR__) . '/component/page-comments.php';
            } catch (\Throwable $e) {
                echo '<!--TRICMT-ERR: ' . htmlspecialchars($e->getMessage()) . ' @ ' . htmlspecialchars((string) $e->getFile() . ':' . $e->getLine()) . '-->';
            }
            exit;
        endif;

        /* ============ 豆瓣模板：与 page-douban.php 一致 ============ */
        if ($triTemplate === 'page-douban.php'):
            require_once dirname(__DIR__) . '/lib/douban.php';
            /* 后台开关的展示类型（电影置首） */
            $triDbTypes = tri_checked_arr('doubanTypes');
            if (empty($triDbTypes)) { $triDbTypes = array('movie', 'book', 'music'); }
            $triDbOrder = array('movie', 'book', 'music');
            usort($triDbTypes, function ($triA, $triB) use ($triDbOrder) {
                $triIa = array_search($triA, $triDbOrder, true);
                $triIb = array_search($triB, $triDbOrder, true);
                return ($triIa === false ? 9 : $triIa) - ($triIb === false ? 9 : $triIb);
            });
            $triDbMeta = array(
                'movie' => array('name' => '电影', 'icon' => 'ri-film-line',    'hint' => '想看 / 在看 / 看过'),
                'book'  => array('name' => '读书', 'icon' => 'ri-book-2-line',  'hint' => '想读 / 在读 / 读过'),
                'music' => array('name' => '音乐', 'icon' => 'ri-music-2-line', 'hint' => '想听 / 在听 / 听过'),
            );
            $triStatusMeta = array(
                'wish'  => array('book' => '想读', 'movie' => '想看', 'music' => '想听'),
                'doing' => array('book' => '在读', 'movie' => '在看', 'music' => '在听'),
                'done'  => array('book' => '读过', 'movie' => '看过', 'music' => '听过'),
            );
            $triDbData = array();
            $triDbTotal = 0;
            foreach ($triDbTypes as $triT) {
                if (!isset($triDbMeta[$triT])) { continue; }
                $triDbData[$triT] = tri_douban_get($triT);
                if (!empty($triDbData[$triT]['ok'])) { $triDbTotal += count($triDbData[$triT]['items']); }
            }
            $triShowFilter = tri_switch('doubanShowFilter', '1');
            $triShowRemark = tri_switch('doubanShowRemark', '1');
            $triShowRating = tri_switch('doubanShowRating', '1');
            $triShowSearch = tri_switch('doubanShowSearch', '1');
            ?>
<article class="md-card douban-card">
  <header class="single-header page-header-hero banner-reveal">
    <span class="page-title-badge"><i class="ri-finder-line"></i>豆瓣</span>
    <h1 class="single-title"><?php echo $triPageTitle; ?></h1>
    <span class="page-title-rule"></span>
    <div class="single-meta"><span><i class="ri-folder-2-line"></i>共 <?php echo $triDbTotal; ?> 条标记</span></div>
  </header>
  <?php include dirname(__DIR__) . '/component/douban-body.php'; ?></article>
<?php
            try {
                include dirname(__DIR__) . '/component/page-comments.php';
            } catch (\Throwable $e) {
                echo '<!--TRICMT-ERR: ' . htmlspecialchars($e->getMessage()) . ' @ ' . htmlspecialchars((string) $e->getFile() . ':' . $e->getLine()) . '-->';
            }
            exit;
        endif;

        /* ============ 番剧模板：与 page-bangumi.php 一致 ============ */
        if ($triTemplate === 'page-bangumi.php'):
            require_once dirname(__DIR__) . '/lib/bangumi.php';
            $triBgmData = tri_bangumi_get();
            $triBgmItems = !empty($triBgmData['ok']) ? $triBgmData['items'] : array();
            $triBgmTotal = isset($triBgmData['total']) ? (int) $triBgmData['total'] : count($triBgmItems);
            $triBgmStatusCount = array('1' => 0, '2' => 0, '3' => 0);
            $triBgmYears = array();
            $triBgmGenres = array();
            foreach ($triBgmItems as $triIt) {
                $triSt = isset($triIt['status']) && isset($triBgmStatusCount[$triIt['status']]) ? $triIt['status'] : '2';
                $triBgmStatusCount[$triSt]++;
                $triY = $triIt['year'] !== '' ? $triIt['year'] : '未标注';
                $triBgmYears[$triY][] = $triIt;
                foreach ($triIt['styles'] as $triG) {
                    $triBgmGenres[$triG] = isset($triBgmGenres[$triG]) ? $triBgmGenres[$triG] + 1 : 1;
                }
            }
            krsort($triBgmYears, SORT_STRING);
            arsort($triBgmGenres);
            $triShowSearch = tri_switch('biliShowSearch', '1');
            $triShowFilter = tri_switch('biliShowFilter', '1');
            $triShowRemark = tri_switch('biliShowRemark', '1');
            ?>
<article class="md-card douban-card bgm-card">
  <header class="single-header page-header-hero banner-reveal">
    <span class="page-title-badge"><i class="ri-tv-2-line"></i>番剧</span>
    <h1 class="single-title"><?php echo $triPageTitle; ?></h1>
    <span class="page-title-rule"></span>
    <div class="single-meta"><span><i class="ri-play-list-2-line"></i>共 <?php echo $triBgmTotal; ?> 部追番</span></div>
  </header>
  <?php include dirname(__DIR__) . '/component/bangumi-body.php'; ?>

<?php
            try {
                include dirname(__DIR__) . '/component/page-comments.php';
            } catch (\Throwable $e) {
                echo '<!--TRICMT-ERR: ' . htmlspecialchars($e->getMessage()) . ' @ ' . htmlspecialchars((string) $e->getFile() . ':' . $e->getLine()) . '-->';
            }
            exit;
        endif;

        /* ============ 归档模板：统计 + 分类标签 + 时间线（与 archive.php 一致） ============ */
        if ($triTemplate === 'archive.php'):
            $triDbA = \Typecho\Db::get();
            $triArchRows = $triDbA->fetchAll(
                $triDbA->select('cid', 'title', 'slug', 'created', 'text')
                    ->from('table.contents')
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish')
                    ->order('table.contents.created', \Typecho\Db::SORT_DESC)
            );
            $triArchCats = array(); $triCatStat = array(); $triTagStat = array();
            try {
                $triTaxRows = $triDbA->fetchAll(
                    $triDbA->select('r.cid', 'm.name', 'm.slug', 'm.type')
                        ->from('table.relationships AS r')
                        ->join('table.metas AS m', 'r.mid = m.mid', \Typecho\Db::INNER_JOIN)
                );
                foreach ($triTaxRows as $triTx) {
                    if ($triTx['type'] === 'category') {
                        if (!isset($triArchCats[(int) $triTx['cid']])) { $triArchCats[(int) $triTx['cid']] = $triTx['name']; }
                        $triS = (string) $triTx['slug'];
                        if (!isset($triCatStat[$triS])) { $triCatStat[$triS] = array('name' => $triTx['name'], 'count' => 0); }
                        $triCatStat[$triS]['count']++;
                    } elseif ($triTx['type'] === 'tag') {
                        $triS = (string) $triTx['slug'];
                        if (!isset($triTagStat[$triS])) { $triTagStat[$triS] = array('name' => $triTx['name'], 'count' => 0); }
                        $triTagStat[$triS]['count']++;
                    }
                }
            } catch (\Throwable $e) {}
            $triArchTotal = count($triArchRows);
            $triArchWords = 0;
            $triYearStat = array();
            $triMonthStat = array_fill(1, 12, 0);
            $triArchGroup = array();
            foreach ($triArchRows as $triAr) {
                $triArchWords += mb_strlen(trim(strip_tags((string) $triAr['text'])), 'UTF-8');
                $triY = (int) date('Y', (int) $triAr['created']);
                $triM = (int) date('n', (int) $triAr['created']);
                if (!isset($triYearStat[$triY])) { $triYearStat[$triY] = 0; }
                $triYearStat[$triY]++;
                $triMonthStat[$triM]++;
                if (!isset($triArchGroup[$triY])) { $triArchGroup[$triY] = array(); }
                if (!isset($triArchGroup[$triY][$triM])) { $triArchGroup[$triY][$triM] = array(); }
                $triArchGroup[$triY][$triM][] = $triAr;
            }
            krsort($triYearStat);
            $triArchYears = count($triYearStat);
            $triArchMonths = 0;
            foreach ($triArchGroup as $triYg) { $triArchMonths += count($triYg); }
            $triYearMax = $triYearStat ? max($triYearStat) : 1;
            $triMonthMax = $triMonthStat ? max($triMonthStat) : 1;
            $triTagMax = $triTagStat ? max(array_column($triTagStat, 'count')) : 1;
            $triTagFont = function ($c) use ($triTagMax) {
                return 12 + ($c / max(1, $triTagMax)) * 5;
            };
            $triIndexBase = (string) Helper::options()->index;
            ?>
<article class="md-card archive-card">
  <header class="single-header page-header-hero banner-reveal">
    <span class="page-title-badge"><i class="ri-archive-line"></i>归档</span>
    <h1 class="single-title"><?php echo $triPageTitle; ?></h1>
    <span class="page-title-rule"></span>
    <div class="single-meta">
      <span><i class="ri-file-list-3-line"></i><?php echo $triArchTotal; ?> 篇文章</span>
      <span><i class="ri-text-spacing"></i>约 <?php echo $triArchWords >= 10000 ? number_format($triArchWords / 10000, 1) . ' 万字' : $triArchWords . ' 字'; ?></span>
      <span><i class="ri-time-line"></i>跨越 <?php echo $triArchYears; ?> 年 · <?php echo $triArchMonths; ?> 个月</span>
    </div>
  </header>
  <?php if ($triArchTotal === 0): ?>
  <div class="archive-empty"><i class="ri-inbox-archive-line"></i><p>还没有文章，去发布第一篇吧</p></div>
  <?php else: ?>
  <div class="arch-stats-grid">
    <section class="stat-panel">
      <h3 class="stat-panel-title"><i class="ri-bar-chart-grouped-line"></i>年度发布</h3>
      <div class="stat-bars">
        <?php foreach ($triYearStat as $triY => $triC): ?>
        <div class="stat-bar" title="<?php echo $triY; ?> 年 · <?php echo $triC; ?> 篇">
          <div class="stat-bar-track">
            <div class="stat-bar-fill" style="height: <?php echo max(2, round($triC / $triYearMax * 100, 1)); ?>%;"><em><?php echo $triC; ?></em></div>
          </div>
          <span class="stat-bar-label"><?php echo $triY; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="stat-panel">
      <h3 class="stat-panel-title"><i class="ri-bar-chart-line"></i>月度分布</h3>
      <div class="stat-bars">
        <?php for ($triMi = 1; $triMi <= 12; $triMi++): ?>
        <div class="stat-bar" title="<?php echo $triMi; ?> 月 · <?php echo $triMonthStat[$triMi]; ?> 篇">
          <div class="stat-bar-track">
            <div class="stat-bar-fill" style="height: <?php echo max(2, round($triMonthStat[$triMi] / $triMonthMax * 100, 1)); ?>%;"><em><?php echo $triMonthStat[$triMi]; ?></em></div>
          </div>
          <span class="stat-bar-label"><?php echo $triMi; ?>月</span>
        </div>
        <?php endfor; ?>
      </div>
    </section>
  </div>
  <?php if ($triCatStat): ?>
  <section class="arch-tax">
    <h3 class="arch-tax-title"><i class="ri-folder-2-line"></i>分类 <b class="arch-tax-total"><?php echo count($triCatStat); ?> 个</b></h3>
    <div class="arch-tax-list">
      <?php uasort($triCatStat, function ($a, $b) { return $b['count'] - $a['count']; }); ?>
      <?php foreach ($triCatStat as $triCSlug => $triCInfo): ?>
      <a class="arch-tax-chip" href="<?php echo \Typecho\Router::url('category', array('slug' => $triCSlug), $triIndexBase); ?>">
        <i class="ri-folder-line"></i><?php echo htmlspecialchars($triCInfo['name']); ?><b><?php echo $triCInfo['count']; ?></b>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <?php if ($triTagStat): ?>
  <section class="arch-tax">
    <h3 class="arch-tax-title"><i class="ri-price-tag-3-line"></i>标签 <b class="arch-tax-total"><?php echo count($triTagStat); ?> 个</b></h3>
    <div class="arch-tag-cloud">
      <?php uasort($triTagStat, function ($a, $b) { return $b['count'] - $a['count']; }); ?>
      <?php foreach ($triTagStat as $triTSlug => $triTInfo): ?>
      <a class="arch-tag" style="font-size: <?php echo $triTagFont($triTInfo['count']); ?>px;" href="<?php echo \Typecho\Router::url('tag', array('slug' => $triTSlug), $triIndexBase); ?>">
        <?php echo htmlspecialchars($triTInfo['name']); ?><b><?php echo $triTInfo['count']; ?></b>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
  <section class="arch-timeline">
    <h3 class="arch-tax-title"><i class="ri-timer-line"></i>时间线</h3>
    <?php foreach ($triArchGroup as $triYear => $triMonths): ?>
    <section class="arch-year" id="arch-<?php echo $triYear; ?>">
      <h2 class="arch-year-title">
        <span class="arch-year-num"><?php echo $triYear; ?></span>
        <span class="arch-year-rule"></span>
        <span class="arch-year-count"><?php $triYc = 0; foreach ($triMonths as $triMl) { $triYc += count($triMl); } echo $triYc; ?> 篇</span>
      </h2>
      <div class="arch-months">
        <?php foreach ($triMonths as $triMonth => $triPosts): ?>
        <section class="arch-month">
          <h3 class="arch-month-title">
            <span class="arch-month-num"><?php echo $triMonth; ?> 月</span>
            <span class="arch-month-count"><?php echo count($triPosts); ?> 篇</span>
          </h3>
          <ul class="arch-list">
            <?php foreach ($triPosts as $triP): ?>
            <?php $triPCat = isset($triArchCats[(int) $triP['cid']]) ? $triArchCats[(int) $triP['cid']] : ''; ?>
            <li class="arch-item">
              <a href="<?php echo \Typecho\Router::url('post', array('cid' => $triP['cid'], 'slug' => $triP['slug']), $triIndexBase); ?>">
                <span class="arch-item-dot" aria-hidden="true"></span>
                <span class="arch-item-main">
                  <span class="arch-item-title"><?php echo htmlspecialchars($triP['title']); ?></span>
                  <?php if ($triPCat !== ''): ?>
                  <span class="arch-item-cat"><i class="ri-price-tag-3-line"></i><?php echo htmlspecialchars($triPCat); ?></span>
                  <?php endif; ?>
                </span>
                <time class="arch-item-date"><?php echo date('m-d', (int) $triP['created']); ?></time>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</article>
<?php
            exit;
        endif;

        /* ============ 普通单页：page-header-hero 标题 + Joe 短代码正文 + 评论 ============ */
        /* 页面别名匹配图标（与 page.php 一致） */
        $triPageIcon = 'ri-file-text-line';
        if (strpos($triSlug, 'about') !== false || strpos($triSlug, 'intro') !== false) {
            $triPageIcon = 'ri-user-smile-line';
        } elseif (strpos($triSlug, 'link') !== false || strpos($triSlug, 'friend') !== false) {
            $triPageIcon = 'ri-links-line';
        } elseif (strpos($triSlug, 'moment') !== false || strpos($triSlug, 'shuo') !== false) {
            $triPageIcon = 'ri-chat-3-line';
        } elseif (strpos($triSlug, 'music') !== false) {
            $triPageIcon = 'ri-music-2-line';
        }
        /* Joe 短代码：正文已是 Markdown 渲染后的 HTML，再跑短代码转换（{hide} 回复可见依赖登录态） */
        $triLogged = (bool) $triCmtUser->hasLogin();
        $triContent = tri3_joe_parse($triContent, null, $triLogged);
        if (function_exists('tri3_wrap_tables')) { $triContent = tri3_wrap_tables($triContent); }
        ?>
<article class="tri3-page-single md-card tri3-reveal main-reveal">
  <header class="single-header page-header-hero banner-reveal">
    <span class="page-title-badge"><i class="<?php echo $triPageIcon; ?>"></i>独立页面</span>
    <h1 class="single-title"><?php echo $triPageTitle; ?></h1>
    <span class="page-title-rule"></span>
    <div class="single-meta">
      <span><i class="ri-user-line"></i><?php echo $triAuthorName; ?></span>
      <span><i class="ri-chat-3-line"></i>评论区</span>
    </div>
  </header>
  <div class="single-body post-content"><?php echo function_exists("tri3_sign_content_imgs") ? tri3_sign_content_imgs($triContent) : $triContent; ?></div>
</article>
<?php
        try {
            include dirname(__DIR__) . '/component/page-comments.php';
        } catch (\Throwable $e) {
            echo '<!--TRICMT-ERR: ' . htmlspecialchars($e->getMessage()) . ' @ ' . htmlspecialchars((string) $e->getFile() . ':' . $e->getLine()) . '-->';
        }
    } else { ?>
<div class="home-list-empty"><i class="ri-inbox-2-line"></i>页面不存在</div>
<?php
    }
    exit;
}

/* ================= type=posts / category：文章列表 ================= */
$triPostsSize = $triListSize;
$triList = \Typecho\Widget::widget(
    'Widget_Archive@tri3list_' . md5($triCatSlug . '_' . $triPage),
    array(
        'type' => $triCatSlug !== '' ? 'category' : 'index',
        'pageSize' => $triPostsSize,
        'checkPermalink' => false,
        'preview' => false,
    ),
    $triCatSlug !== ''
        ? array('slug' => $triCatSlug, 'page' => $triPage)
        : array('page' => $triPage)
);
$triList->to($triList);

if ($triList->have()):
    while ($triList->next()):
        $GLOBALS['__tri_item'] = $triList;
        include dirname(__DIR__) . '/component/list-item.php';
    endwhile;
else:?>
<div class="home-list-empty"><i class="ri-inbox-2-line"></i>这里还没有文章</div>
<?php
endif;

$triListTotal = (int) $triList->getTotal();
tri3_pagination($triPage, max(1, (int) ceil($triListTotal / max(1, (int) Helper::options()->pageSize))), '?type=posts&cat=' . urlencode($triCatSlug) . '&page=', $triListSel, $triApiParams);
