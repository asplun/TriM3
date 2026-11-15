<?php
/**
 * 说说卡片（X.com 风格：头像在左 + 内容在右 + 底部操作行）
 * 列表参考 TriM3 首页文章卡：无卡底、细分隔线、精致留白
 * 变量来自 page-moment.php：$m / $avatarUrl / $authorName / $isAdmin
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
/* 防御兜底：任何调用路径未传入这些变量时不告警（正常路径由 page-moment.php / list-api.php 提供） */
$isAdmin   = $isAdmin ?? false;
$avatarUrl = $avatarUrl ?? '';
$authorName = $authorName ?? '';
$mText = trim((string) $m['text']);
$mGa   = isset($m['ga']) ? array_slice((array) $m['ga'], 0, 9) : [];
$mLike = (int) ($m['like'] ?? 0);
$mTime = date('Y-m-d H:i', (int) $m['created']);
$mCid  = (int) $m['cid'];
/* 图文混排优先：正文已含某媒体标记时，独立媒体块一律不渲染（双卡终极兜底） */
$hasTagImg   = strpos($mText, '[img]') !== false;
$hasTagMusic = strpos($mText, '[music]') !== false;
$hasTagVideo = strpos($mText, '[video]') !== false;
$hasTagLive  = strpos($mText, '[live]') !== false;
$hasTagBili  = strpos($mText, '[bili]') !== false;
$mMusic = !empty($m['music']) && !empty($m['music']['id']) ? $m['music'] : null;
$mVideo = !empty($m['video']) && !empty($m['video']['src']) ? $m['video'] : null;
$mLive  = !empty($m['live']) && (!empty($m['live']['img']) || !empty($m['live']['video'])) ? $m['live'] : null;
$mBili  = !empty($m['bili']) && !empty($m['bili']['bvid']) ? $m['bili'] : null;
?>
<article class="moment-card<?php echo !empty($m['sticky']) ? ' is-sticky' : ''; ?><?php echo !empty($m['isPrivate']) ? ' is-private' : ''; ?> tri3-reveal main-reveal" data-cid="<?php echo $mCid; ?>">
  <img class="mc-avatar" src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
  <div class="mc-body">
    <div class="mc-meta">
      <span class="mc-name"><?php echo htmlspecialchars($authorName); ?></span>
      <time class="mc-time" datetime="<?php echo date('c', (int) $m['created']); ?>"><?php echo $mTime; ?></time>
      <?php if (!empty($m['sticky'])): ?><span class="mc-badge"><i class="ri-pushpin-2-fill"></i>置顶</span><?php endif; ?>
      <?php if (!empty($m['isPrivate'])): ?><span class="mc-badge mc-badge-private"><i class="ri-lock-line"></i>私密</span><?php endif; ?>
      <?php
      if (!empty($m['cat'])) {
          static $triCatNames = null;
          if ($triCatNames === null) {
              $triCatNames = [];
              try {
                  foreach (tri_moment_cats() as $triCc) {
                      $triCatNames[(string) $triCc['slug']] = (string) $triCc['name'];
                  }
              } catch (Throwable $e) {}
          }
          $triCatName = $triCatNames[$m['cat']] ?? $m['cat'];
          echo '<span class="mc-badge mc-badge-cat"><i class="ri-price-tag-3-line"></i>' . htmlspecialchars($triCatName) . '</span>';
      }
      ?>
    </div>

    <?php if ($mText !== ''): ?>
    <div class="mc-text"><?php echo function_exists('tri3_sign_content_imgs') ? tri3_sign_content_imgs(tri3_render_moment_text($mText, $mCid)) : tri3_render_moment_text($mText, $mCid); ?></div>
    <?php endif; ?>

    <?php if ($mGa && !$hasTagImg): ?>
    <div class="mc-gallery gallery-n-<?php echo count($mGa); ?>">
      <?php
        /* v1.2.76：按张数给出显示宽度 */
        $mGaN = count($mGa);
        $mGaSizes = $mGaN >= 3 ? '(max-width:640px) 30vw, 200px' : ($mGaN === 2 ? '(max-width:640px) 46vw, 300px' : '(max-width:640px) 92vw, 620px');
      ?>
      <?php foreach ($mGa as $img): $mImg = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url((string) ($img['thumb'] ?? $img['src'] ?? ''), 600) : (string) ($img['thumb'] ?? $img['src'] ?? '');
            $mSet = function_exists('tri3_img_srcset') ? tri3_img_srcset($mImg, array(320, 480, 600)) : ''; ?>
        <a class="moment-img" data-fancybox="moment-<?php echo $mCid; ?>" href="<?php echo htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url((string) ($img['src'] ?? '')) : (string) ($img['src'] ?? '')); ?>" rel="noopener">
          <img data-src="<?php echo htmlspecialchars($mImg); ?>"<?php echo tri3_img_size_attr($mImg); ?><?php if ($mSet !== '') { echo ' data-srcset="' . htmlspecialchars($mSet) . '" data-sizes="' . $mGaSizes . '"'; } ?> loading="lazy" decoding="async" alt="">
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($mMusic && !$hasTagMusic): $mPic = (string) ($mMusic['pic'] ?? ''); ?>
    <div class="mc-music" data-cid="<?php echo $mCid; ?>" data-server="<?php echo htmlspecialchars((string) ($mMusic['server'] ?? 'netease')); ?>" data-id="<?php echo htmlspecialchars((string) $mMusic['id']); ?>" data-title="<?php echo htmlspecialchars((string) ($mMusic['title'] ?? '')); ?>" data-artist="<?php echo htmlspecialchars((string) ($mMusic['artist'] ?? '')); ?>" data-pic="<?php echo htmlspecialchars($mPic); ?>">
      <div class="mc-music-cover">
        <?php if ($mPic !== ''): ?><img src="<?php echo htmlspecialchars(function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($mPic, 400) : $mPic); ?>" alt=""<?php echo tri3_img_priority_attr(); ?> decoding="async"><?php else: ?><i class="ri-music-2-fill"></i><?php endif; ?>
      </div>
      <div class="mc-music-meta">
        <span class="mc-music-title"><?php echo htmlspecialchars((string) ($mMusic['title'] ?? '未知歌曲')); ?></span>
        <span class="mc-music-artist"><?php echo htmlspecialchars((string) ($mMusic['artist'] ?? '未知歌手')); ?></span>
      </div>
      <button type="button" class="mc-music-play" aria-label="播放这首音乐"><i class="ri-play-fill"></i></button>
    </div>
    <?php endif; ?>

    <?php if ($mVideo && !$hasTagVideo): ?>
    <div class="mc-video" data-cid="<?php echo $mCid; ?>">
      <video class="mc-video-el" preload="metadata" playsinline webkit-playsinline x5-playsinline x5-video-player-type="h5" disablepictureinpicture controlslist="nodownload noplaybackrate nofullscreen" src="<?php echo htmlspecialchars((string) $mVideo['src']); ?>"<?php if (!empty($mVideo['poster'])): ?> poster="<?php echo htmlspecialchars((string) $mVideo['poster']); ?>"<?php endif; ?>></video>
      <div class="mc-video-mask"><button type="button" class="mc-video-play" aria-label="播放视频"><i class="ri-play-fill"></i></button></div>
      <div class="mc-video-bar" hidden>
        <button type="button" class="mc-vb-toggle" aria-label="播放/暂停"><i class="ri-pause-fill"></i></button>
        <div class="mc-vb-progress"><span class="mc-vb-fill"></span></div>
        <span class="mc-vb-time">0:00 / 0:00</span>
        <button type="button" class="mc-vb-full" aria-label="全屏"><i class="ri-fullscreen-line"></i></button>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($mLive && !$hasTagLive): ?>
    <div class="mc-live" data-cid="<?php echo $mCid; ?>">
      <?php if (!empty($mLive['img'])): ?>
      <img class="mc-live-img" src="<?php echo htmlspecialchars(function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url((string) $mLive['img'], 800) : (string) $mLive['img']); ?>" alt=""<?php echo tri3_img_priority_attr(); ?> decoding="async">
      <?php endif; ?>
      <?php if (!empty($mLive['video'])): ?>
      <video class="mc-live-video" loop muted playsinline webkit-playsinline x5-playsinline x5-video-player-type="h5" disablepictureinpicture preload="metadata" src="<?php echo htmlspecialchars((string) $mLive['video']); ?>"></video>
      <?php endif; ?>
      <span class="mc-live-badge"><i class="ri-video-line"></i>实况</span>
    </div>
    <?php endif; ?>

    <?php if ($mBili && !$hasTagBili): ?>
    <?php echo tri3_bili_player_html((string) $mBili['bvid'], $mCid); ?>
    <?php endif; ?>

    <div class="mc-actions">
      <div class="mc-actions-left">
        <button type="button" class="mc-act moment-like" data-cid="<?php echo $mCid; ?>">
          <i class="ri-heart-3-line"></i><span class="moment-like-num"><?php echo $mLike; ?></span>
        </button>
        <button type="button" class="mc-act moment-cmt" data-cid="<?php echo $mCid; ?>" aria-label="评论">
          <i class="ri-chat-3-line"></i><span class="moment-cmt-num"><?php echo (int) ($m['cmt'] ?? 0); ?></span>
        </button>
      </div>
      <?php if ($isAdmin): ?>
      <div class="mc-actions-right">
        <button type="button" class="mc-act moment-edit" data-cid="<?php echo $mCid; ?>"><i class="ri-edit-line"></i>编辑</button>
        <button type="button" class="mc-act moment-stick" data-cid="<?php echo $mCid; ?>"><i class="ri-pushpin-2-line"></i><?php echo !empty($m['sticky']) ? '取消置顶' : '置顶'; ?></button>
        <button type="button" class="mc-act mc-act-danger moment-del" data-cid="<?php echo $mCid; ?>"><i class="ri-delete-bin-line"></i>删除</button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</article>
