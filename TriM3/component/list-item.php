<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
$triItem = isset($GLOBALS['__tri_item']) ? $GLOBALS['__tri_item'] : null;
if (!$triItem) { return; }
$triThumb = '';
if (preg_match('/<img[^>]+src=["\']([^"\']+)/i', $triItem->content, $triThumbMatch)) {
  $triThumb = $triThumbMatch[1];
}
$triCat = '';
if ($triItem->categories) {
  foreach ($triItem->categories as $triC) {
    if (isset($triC['name'])) { $triCat = $triC['name']; break; }
  }
}
$triTags = '';
if ($triItem->tags) {
  $tagArr = array();
  foreach ($triItem->tags as $triT) {
    if (isset($triT['name'])) { $tagArr[] = $triT['name']; }
  }
  $triTags = implode(' ', $tagArr);
}
$triCardStyle = tri_opt('postCardStyle', 'original');
ob_start(); $triItem->permalink(); $triPerma = ob_get_clean();
$triTitle = $triItem->title;
$triExcerpt = function_exists('tri3_sign_content_imgs') ? tri3_sign_content_imgs(tri3_post_excerpt($triItem, tri_excerpt_len())) : tri3_post_excerpt($triItem, tri_excerpt_len());
$triImgProxy = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triThumb, 600) : $triThumb;
/* v1.2.76：同一张图给多档宽度，桌面 1x 不再拿 600px */
$triImgSet = function_exists('tri3_img_srcset') ? tri3_img_srcset($triImgProxy, array(320, 480, 600)) : '';
$triImgSizes = '(max-width:640px) 92vw, 320px';
$triComments = tri3_comment_count((int) $triItem->cid);
$triLikes = function_exists('tri_post_like_count') ? tri_post_like_count((int) $triItem->cid) : 0;
?>
<?php if ($triCardStyle === 'grid'): ?>
<article class="post-card post-card-grid tri3-reveal main-reveal">
  <a class="pc-thumb" href="<?php echo htmlspecialchars($triPerma); ?>" aria-label="阅读">
    <?php if ($triThumb !== ''): ?>
      <img <?php echo tri3_img_lazy_attrs($triImgProxy, $triImgSet, $triImgSizes); ?> alt="">
    <?php else: ?>
      <span class="pc-thumb-fallback"><i class="ri-article-line"></i></span>
    <?php endif; ?>
    <span class="pc-badge pc-date"><i class="ri-calendar-line"></i><?php $triItem->date('Y-m-d'); ?></span>
    <span class="pc-badge pc-comments"><i class="ri-chat-3-line"></i><?php echo $triComments; ?></span>
    <span class="pc-badge pc-cat"><?php if ($triCat !== ''): ?><?php echo htmlspecialchars($triCat); ?><?php endif; ?><?php if ($triTags !== ''): ?> <?php echo htmlspecialchars($triTags); ?><?php endif; ?></span>
  </a>
  <div class="pc-info">
    <h2 class="post-title"><a href="<?php echo htmlspecialchars($triPerma); ?>" title="<?php echo htmlspecialchars($triTitle); ?>"><?php echo $triTitle; ?></a></h2>
    <p class="post-excerpt"><?php echo $triExcerpt; ?></p>
  </div>
</article>
<?php else: ?>
<article class="post-card post-card-<?php echo htmlspecialchars($triCardStyle); ?> tri3-reveal main-reveal">
  <div class="pc-top-row">
    <span class="pc-cat"><?php if ($triCat !== ''): ?><i class="ri-folder-line"></i><?php echo htmlspecialchars($triCat); ?><?php endif; ?></span>
    <span class="pc-date"><?php $triItem->date('Y-m-d'); ?></span>
    <a class="pc-share" href="<?php echo htmlspecialchars($triPerma); ?>" aria-label="阅读"><i class="ri-arrow-right-up-line"></i></a>
  </div>
  <div class="pc-content">
    <div class="pc-text">
      <h2 class="post-title"><a href="<?php echo htmlspecialchars($triPerma); ?>" title="<?php echo htmlspecialchars($triTitle); ?>"><?php echo $triTitle; ?></a></h2>
      <p class="post-excerpt"><?php echo $triExcerpt; ?></p>
      <div class="pc-stats"><span><i class="ri-chat-3-line"></i><?php echo $triComments; ?></span><span><i class="ri-heart-3-line"></i><?php echo $triLikes; ?></span></div>
    </div>
    <a class="pc-thumb" href="<?php echo htmlspecialchars($triPerma); ?>" aria-label="阅读">
      <?php if ($triThumb !== ''): ?>
        <img <?php echo tri3_img_lazy_attrs($triImgProxy, $triImgSet, $triImgSizes); ?> alt="">
      <?php else: ?>
        <span class="pc-thumb-fallback"><i class="ri-article-line"></i></span>
      <?php endif; ?>
    </a>
  </div>
</article>
<?php endif; ?>
