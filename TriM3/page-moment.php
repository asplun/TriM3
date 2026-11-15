<?php
/**
 * 说说
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$this->need('header.php');

$isAdmin  = $this->user->hasLogin() && $this->user->pass('administrator');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$triCatSlug = trim((string) ($_GET['cat'] ?? ''));
/* 每页条数与 list-api.php 保持一致（后台 pageSize），否则 AJAX 加载更多会分页错位 */
$triPageSize = max(1, (int) $this->options->pageSize);
$moments  = tri_moment_list($page, $triPageSize, $isAdmin, 'all', $triCatSlug);
$total    = tri_moment_count($isAdmin, 'all', $triCatSlug);
$totalPage = max(1, (int) ceil($total / $triPageSize));
$avatarUrl = tri3_avatar_url(96);
$authorName = tri_opt('authorName', '博主');
/* Typecho 1.3 themeUrl($path) 单参数是 echo 而非 return，不能赋值，需拼接绝对 URL */
$apiUrl = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . (string) $this->options->theme . '/api/moment-api.php';

$triLogged = $this->user->hasLogin();
$triLoginName = $triLogged ? (string) $this->user->name : '';
$triLoginAvatar = $triLogged ? tri3_avatar_url(96) : '';
$triLoginApi = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . $this->options->theme . '/api/login-api.php';
$triCommentApi = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . $this->options->theme . '/api/comment-api.php';
$triCommentCid = (int) $this->cid;
$triRequireMail = (bool) ($this->options->commentsRequireMail ?? false);
?>


<div class="layout">
<style>
  .moment-page { padding: 4px 0 12px; }
  .moment-page-title { font-size: 22px; font-weight: 700; margin: 0 0 4px; color: var(--md-sys-color-on-surface); }
  .moment-page-sub { font-size: 13px; color: var(--md-sys-color-on-surface-variant); margin: 0 0 16px; }
  .moment-empty { padding: 48px 16px; text-align: center; color: var(--md-sys-color-on-surface-variant); font-size: 13px; }
  .moment-empty i { display: block; font-size: 28px; margin-bottom: 8px; color: var(--md-sys-color-outline); }
        .mc-cat-select{border:1px solid var(--md-sys-color-outline-variant,#cac4d0);border-radius:999px;padding:4px 10px;font-size:12px;background:var(--md-sys-color-surface,#fff);color:var(--md-sys-color-on-surface,#1b1b1f);font-family:inherit;outline:none;cursor:pointer}
        .mc-cat-select:focus{border-color:var(--md-sys-color-primary,#3D5AFE)}
  </style>
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <div class="moment-page">
        <span class="page-title-badge"><i class="ri-chat-3-line"></i>说说</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
      <style>
        .moment-page-subrow{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .moment-page-subrow .moment-page-sub{margin:0}
        .moment-admin-link{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--md-sys-color-primary,#3D5AFE);
          background:var(--md-sys-color-primary-container,rgba(61,90,254,.1));padding:4px 14px;border-radius:999px;text-decoration:none;font-weight:600;transition:.15s}
        .moment-admin-link:hover{filter:brightness(1.08)}
      </style>
      <header class="single-header page-header-hero banner-reveal moment-hero">
        <div class="moment-page-subrow">
          <p class="moment-page-sub">共 <?php echo $total; ?> 条 · 碎语闲言</p>
          <?php if ($isAdmin): ?>
          <a class="moment-admin-link" href="<?php $this->options->themeUrl('admin/moments.html'); ?>" target="_blank">
            <i class="ri-settings-3-line"></i> 后台管理
          </a>
          <?php endif; ?>
        </div>
      </header>

      <?php if ($isAdmin): ?>
      <div class="moment-composer">
        <img class="mc-avatar" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="<?php echo htmlspecialchars($authorName); ?>">
        <div class="mc-body">
          <textarea id="moment-content" class="mc-input" placeholder="说点什么…" maxlength="2000" rows="2"></textarea>
          <div class="mc-imgbox" id="moment-imgbox" style="display:none;">
            <textarea id="moment-images" class="mc-imgs" placeholder="图片链接，每行一个（最多 9 张）"></textarea>
          </div>
          <div class="mc-imgbox" id="moment-musicbox" style="display:none;">
            <div class="mc-media-row">
              <select id="moment-music-server" class="mc-imgs mc-select" aria-label="音乐平台">
                <option value="netease">网易云音乐</option>
                <option value="tencent">QQ 音乐</option>
              </select>
              <input type="text" id="moment-music-id" class="mc-imgs mc-input-sm" placeholder="歌曲 ID（数字）" aria-label="歌曲ID">
              <button type="button" id="moment-music-preview" class="mc-ico" title="预览" aria-label="预览歌曲"><i class="ri-magic-line"></i></button>
            </div>
            <div id="moment-music-pv" class="mc-music-pv" style="display:none;"></div>
            <input type="hidden" id="moment-music-title"><input type="hidden" id="moment-music-artist"><input type="hidden" id="moment-music-pic">
          </div>
          <div class="mc-imgbox" id="moment-videobox" style="display:none;">
            <input type="text" id="moment-video-src" class="mc-imgs mc-input-sm" placeholder="视频直链 URL（mp4 等）" aria-label="视频地址">
            <input type="text" id="moment-video-poster" class="mc-imgs mc-input-sm" placeholder="封面图 URL（可选）" aria-label="视频封面">
          </div>
          <div class="mc-imgbox" id="moment-livebox" style="display:none;">
            <div class="mc-media-row">
              <input type="text" id="moment-live-img" class="mc-imgs mc-input-sm" placeholder="实况图片 URL（静态帧）" aria-label="实况图片">
              <button type="button" id="moment-live-upload" class="mc-ico" title="上传实况图片（无图床时存本机）" aria-label="上传实况图片"><i class="ri-upload-2-line"></i></button>
            </div>
            <div class="mc-media-row">
              <input type="text" id="moment-live-video" class="mc-imgs mc-input-sm" placeholder="实况视频 URL（短视频直链）" aria-label="实况视频">
              <button type="button" id="moment-live-video-upload" class="mc-ico" title="上传实况视频（自动提取关键帧作封面）" aria-label="上传实况视频"><i class="ri-video-upload-line"></i></button>
            </div>
          </div>
          <div class="mc-imgbox" id="moment-bilibox" style="display:none;">
            <input type="text" id="moment-bili-bvid" class="mc-imgs mc-input-sm" placeholder="B 站视频 BV 号或链接（如 BV1xx411c7mD）" aria-label="B站视频">
          </div>
          <div class="mc-toolbar">
            <div class="mc-left">
              <button type="button" class="mc-ico" id="moment-emoji-toggle" title="表情" aria-label="表情"><i class="ri-emotion-line"></i></button>
              <?php if (trim((string) Helper::options()->albumApiUrl) !== ''): ?>
              <button type="button" class="mc-ico" id="moment-upload-toggle" title="上传图片到图床" aria-label="上传图片"><i class="ri-upload-2-line"></i></button>
              <?php endif; ?>
              <span class="mc-ico-group">
                <button type="button" class="mc-ico" id="moment-img-toggle" title="添加图片" aria-label="添加图片"><i class="ri-image-add-line"></i></button>
                <button type="button" class="mc-ico mc-ico-ins" id="moment-ins-img" title="插入图片到文字（自动代入）" aria-label="插入图片到文字"><i class="ri-corner-down-right-line"></i></button>
              </span>
              <span class="mc-ico-group">
                <button type="button" class="mc-ico" id="moment-music-toggle" title="添加音乐" aria-label="添加音乐"><i class="ri-music-2-line"></i></button>
                <button type="button" class="mc-ico mc-ico-ins" id="moment-ins-music" title="插入音乐到文字（自动代入）" aria-label="插入音乐到文字"><i class="ri-corner-down-right-line"></i></button>
              </span>
              <span class="mc-ico-group">
                <button type="button" class="mc-ico" id="moment-video-toggle" title="添加视频" aria-label="添加视频"><i class="ri-video-add-line"></i></button>
                <button type="button" class="mc-ico mc-ico-ins" id="moment-ins-video" title="插入视频到文字（自动代入）" aria-label="插入视频到文字"><i class="ri-corner-down-right-line"></i></button>
              </span>
              <span class="mc-ico-group">
                <button type="button" class="mc-ico" id="moment-live-toggle" title="添加实况图" aria-label="添加实况图"><i class="ri-camera-lens-line"></i></button>
                <button type="button" class="mc-ico mc-ico-ins" id="moment-ins-live" title="插入实况到文字（自动代入）" aria-label="插入实况到文字"><i class="ri-corner-down-right-line"></i></button>
              </span>
              <span class="mc-ico-group">
                <button type="button" class="mc-ico" id="moment-bili-toggle" title="添加B站视频" aria-label="添加B站视频"><i class="ri-bilibili-line"></i></button>
                <button type="button" class="mc-ico mc-ico-ins" id="moment-ins-bili" title="插入B站视频到文字（自动代入）" aria-label="插入B站视频到文字"><i class="ri-corner-down-right-line"></i></button>
              </span>
            </div>
            <div class="mc-right">
              <span class="mc-opts">
                <label class="mc-private"><input type="checkbox" id="moment-private"> 私密</label>
                <select id="moment-cat" class="mc-cat-select" aria-label="分类">
                  <option value="">未分类</option>
                  <?php
                  /* 说说分类独立于文章分类（metas type=moment_cat） */
                  foreach (tri_moment_cats() as $triCat) {
                      echo '<option value="' . htmlspecialchars((string) $triCat['slug']) . '">' . htmlspecialchars((string) $triCat['name']) . '</option>';
                  }
                  ?>
                </select>
              </span>
              <button type="button" id="moment-publish" class="mc-submit">发布</button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="moment-list" id="moment-list">
        <?php if (empty($moments)): ?>
          <div class="moment-empty"><i class="ri-quill-pen-line"></i>还没有说说，来发布第一条吧～</div>
        <?php endif; ?>
        <?php foreach ($moments as $m): ?>
          <?php include __DIR__ . '/component/moment-card.php'; ?>
        <?php endforeach; ?>
      </div>

      <?php tri3_pagination($page, $totalPage, '?page=', '#moment-list', array('type' => 'moment')); ?>
      <?php if (!empty($_GET['tri3_ajax'])): exit; endif; ?>
    </div>
  </main>

  <?php $this->need('sidebar.php'); ?>
  <script src="<?php $this->options->themeUrl('assets/js/moment.min.js'); ?>?v=1.2.5" defer></script>
  <!-- 页面专属脚本，只能有一份，且必须留在 .layout 容器内：Swup 换页时随容器一并执行 -->

  

  


  <!-- 无刷新需携带的全局状态脚本（必须位于 .layout 容器内，换页时随容器一并替换） -->
  <script>
  window.TRI3_MOMENT = {
    api: <?php echo json_encode($apiUrl); ?>,
    isAdmin: <?php echo $isAdmin ? 'true' : 'false'; ?>
  };
  window.TRI3_LOGIN = {logged: <?php echo $triLogged ? 'true' : 'false'; ?>, name: <?php echo json_encode($triLoginName); ?>, avatar: <?php echo json_encode($triLoginAvatar); ?>, adminUrl: <?php echo json_encode((string) $this->options->adminUrl); ?>, loginApi: <?php echo json_encode($triLoginApi); ?>};
  window.TRI3_CMT = {
    api: <?php echo json_encode($triCommentApi); ?>,
    cid: <?php echo $triCommentCid; ?>,
    logged: <?php echo $triLogged ? 'true' : 'false'; ?>,
    requireMail: <?php echo $triRequireMail ? 'true' : 'false'; ?>
  };
  </script>
</div>

<div class="tri-dialog-mask" hidden>  
</div>
<?php $this->need('footer.php'); ?>
