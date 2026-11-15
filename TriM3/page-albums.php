<?php
/**
 * 相册
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
?>
<?php $this->need('header.php'); ?>
<!-- v1.2.86：album.min.css 已由 header.php 按 $triPageKey 输出，此处去重 -->
<?php require_once __DIR__ . '/lib/album.php'; ?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
        <span class="page-title-badge"><i class="ri-gallery-line"></i>相册</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
    <?php
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
      $triAlbumBase = $this->permalink;
    ?>

    <?php if ($triAlbumId > 0 && $triAlbum): ?>
      <?php
        $triPhotos = tri_photo_list($triAlbumId);
        $triLayoutAll = ['grid', 'masonry', 'mosaic', 'list', 'slide'];
        $triLayout = isset($_GET['layout']) && in_array($_GET['layout'], $triLayoutAll, true) ? $_GET['layout'] : '';
        if ($triLayout === '') {
            $triLayout = trim((string) Helper::options()->albumDefaultLayout);
        }
        if (!in_array($triLayout, $triLayoutAll, true)) {
            $triLayout = 'grid';
        }
      ?>
      <section class="album-detail" data-album="<?php echo $triAlbumId; ?>">
        <header class="album-head">
          <a class="album-back" href="<?php echo htmlspecialchars($triAlbumBase); ?>"><i class="ri-arrow-left-line"></i>全部相册</a>
          <div class="album-head-main">
            <h1 class="album-title"><?php echo htmlspecialchars($triAlbum['name']); ?></h1>
            <?php if ($triAlbum['description'] !== ''): ?>
              <p class="album-desc"><?php echo htmlspecialchars($triAlbum['description']); ?></p>
            <?php endif; ?>
            <span class="album-meta"><i class="ri-image-2-line"></i><?php echo count($triPhotos); ?> 张图片</span>
          </div>
        </header>

        <div class="album-toolbar">
          <div class="seg" role="group" aria-label="布局">
            <button type="button" class="seg-btn<?php echo $triLayout === 'grid' ? ' is-active' : ''; ?>" data-layout="grid" title="网格"><i class="ri-layout-grid-line"></i></button>
            <button type="button" class="seg-btn<?php echo $triLayout === 'masonry' ? ' is-active' : ''; ?>" data-layout="masonry" title="瀑布流"><i class="ri-layout-masonry-line"></i></button>
            <button type="button" class="seg-btn<?php echo $triLayout === 'mosaic' ? ' is-active' : ''; ?>" data-layout="mosaic" title="拼图"><i class="ri-collage-line"></i></button>
            <button type="button" class="seg-btn<?php echo $triLayout === 'list' ? ' is-active' : ''; ?>" data-layout="list" title="列表"><i class="ri-list-unordered"></i></button>
            <button type="button" class="seg-btn<?php echo $triLayout === 'slide' ? ' is-active' : ''; ?>" data-layout="slide" title="幻灯片"><i class="ri-slideshow-line"></i></button>
          </div>
          <div class="seg" role="group" aria-label="排序">
            <button type="button" class="seg-btn is-active" data-sort="new" title="最新优先"><i class="ri-arrow-down-line"></i>最新</button>
            <button type="button" class="seg-btn" data-sort="old" title="最早"><i class="ri-arrow-up-line"></i>最早</button>
            <button type="button" class="seg-btn" data-sort="title" title="按标题"><i class="ri-sort-alphabet-asc"></i>标题</button>
          </div>
        </div>

        <?php if ($triPhotos): ?>
          <div class="album-mosaic is-<?php echo $triLayout; ?>" data-layout="<?php echo $triLayout; ?>" data-default-layout="<?php echo $triLayout; ?>">
            <?php foreach ($triPhotos as $triP): ?>
              <figure class="album-photo tri3-reveal main-reveal" data-pid="<?php echo $triP['pid']; ?>"
                data-created="<?php echo $triP['created']; ?>"
                data-title="<?php echo htmlspecialchars($triP['title']); ?>">
                <a class="album-photo-btn" href="<?php echo htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($triP['url']) : $triP['url']); ?>" data-fancybox="album-<?php echo $triAlbumId; ?>" data-title="<?php echo htmlspecialchars($triP['title']); ?>" aria-label="查看大图">
                  <?php $triPImg = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url'], 800) : ($triP['thumb'] !== '' ? $triP['thumb'] : $triP['url']); $triPSet = function_exists('tri3_img_srcset') ? tri3_img_srcset($triPImg, array(320, 480, 640, 960)) : ''; $triSizesPx = array('grid' => 240, 'masonry' => 240, 'mosaic' => 300, 'list' => 260, 'slide' => 320); $triPSize = '(max-width:560px) 44vw, ' . (isset($triSizesPx[$triLayout]) ? $triSizesPx[$triLayout] : 240) . 'px'; ?>
                  <img <?php echo tri3_img_lazy_attrs($triPImg, $triPSet, $triPSize); ?> alt="<?php echo htmlspecialchars($triP['title']); ?>"
                    onerror="this.src=this.closest('.album-photo-btn').getAttribute('href')">
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
      </section>
    <?php else: ?>
      <?php
        $triAlbums = tri_album_list();
        $triTotal = count($triAlbums);
      ?>
      <section class="album-index">
        <header class="single-header page-header-hero banner-reveal">
          <div class="single-meta">
            <span><i class="ri-image-2-line"></i><?php echo $triTotal; ?> 个相册</span>
          </div>
        </header>

        <?php if ($triAlbums): ?>
          <div class="album-index-grid">
            <?php foreach ($triAlbums as $triA): ?>
              <a class="album-index-card tri3-reveal main-reveal" href="<?php echo htmlspecialchars($triAlbumBase . (strpos($triAlbumBase, '?') !== false ? '&' : '?') . 'album=' . $triA['aid']); ?>">
                <div class="album-index-cover">
                  <?php if ($triA['cover'] !== ''): ?>
                    <?php $triCovImg = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triA['cover'], 800) : $triA['cover']; $triCovSet = function_exists('tri3_img_srcset') ? tri3_img_srcset($triCovImg, array(240, 360, 480, 640)) : ''; ?>
                    <img <?php echo tri3_img_lazy_attrs($triCovImg, $triCovSet, '(max-width:560px) 44vw, 310px'); ?> alt="<?php echo htmlspecialchars($triA['name']); ?>">
                  <?php else: ?>
                    <span class="ph"><i class="ri-image-line"></i></span>
                  <?php endif; ?>
                  <span class="album-index-cnt"><i class="ri-image-2-line"></i><?php echo $triA['count']; ?></span>
                </div>
                <div class="album-index-info">
                  <h3><?php echo htmlspecialchars($triA['name']); ?></h3>
                  <?php if ($triA['description'] !== ''): ?>
                    <p><?php echo htmlspecialchars($triA['description']); ?></p>
                  <?php endif; ?>
                  <span class="album-index-more">查看相册<i class="ri-arrow-right-s-line"></i></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="album-empty"><i class="ri-gallery-line"></i><p>还没有相册，去后台「相册管理」创建吧</p></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
  <script src="<?php $this->options->themeUrl('assets/js/album.min.js'); ?>?v=1.2.5" defer></script>
  <!-- 页面专属脚本，只能有一份，且必须留在 .layout 容器内：Swup 换页时随容器一并执行 -->

  

  

  
</div>
<?php $this->need('footer.php'); ?>
