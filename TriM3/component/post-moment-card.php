<?php
/**
 * 文章图文动态卡片（融合模式首页使用）
 *
 * 样式与说说卡片（moment-card）同体系：博主头像在左 + 内容在右。
 * - 标题：正文首个 Markdown「# 标题」行，带 # 前缀可跳转（无则用文章标题）
 * - 正文：仅纯文字（短代码不识别），字数后台可调（默认 123）
 * - 图片：识别为与说说一致的功能图集（横向滚动 + 灯箱），展示在文字下方
 * 依赖外部变量：$GLOBALS['__tri_fuse_post']（数组）：
 *   title / permalink / created / excerpt / imgs[] / cat / cmt
 * 以及 $triAuthor / $avatarUrl（博主信息）
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
$triP = isset($GLOBALS['__tri_fuse_post']) ? $GLOBALS['__tri_fuse_post'] : null;
if (!$triP) {
    return;
}
$pfTitle = trim((string) ($triP['title'] ?? ''));
$pfLink  = (string) ($triP['permalink'] ?? '#');
$pfTime  = date('Y-m-d H:i', (int) ($triP['created'] ?? 0));
$pfExcerpt = trim((string) ($triP['excerpt'] ?? ''));
$pfImgs  = isset($triP['imgs']) ? array_slice((array) $triP['imgs'], 0, 9) : [];
$pfCat   = trim((string) ($triP['cat'] ?? ''));
$pfCmt   = (int) ($triP['cmt'] ?? 0);
$pfAuthor = isset($triAuthor) ? (string) $triAuthor : '博主';
$pfAvatar = isset($avatarUrl) ? (string) $avatarUrl : '';
?>
<article class="moment-card post-fuse-card tri3-reveal main-reveal">
  <img class="mc-avatar" src="<?php echo htmlspecialchars($pfAvatar); ?>" alt="" loading="lazy">
  <div class="mc-body">
    <div class="mc-meta">
      <span class="mc-name"><?php echo htmlspecialchars($pfAuthor); ?></span>
      <time class="mc-time" datetime="<?php echo date('c', (int) ($triP['created'] ?? 0)); ?>"><?php echo $pfTime; ?></time>
      <?php if ($pfCat !== ''): ?><span class="mc-badge mc-badge-cat"><i class="ri-folder-line"></i><?php echo htmlspecialchars($pfCat); ?></span><?php endif; ?>
    </div>
    <p class="pf-content"><a class="pf-head" href="<?php echo $pfLink; ?>" title="<?php echo htmlspecialchars($pfTitle); ?>">#<?php echo htmlspecialchars($pfTitle); ?></a><?php if ($pfExcerpt !== ''): ?><?php echo htmlspecialchars($pfExcerpt); ?><?php endif; ?></p>
    <?php if ($pfImgs): ?>
    <div class="mc-gallery gallery-n-<?php echo count($pfImgs); ?> pf-gallery">
      <?php
        /* v1.2.76：按张数给出显示宽度，浏览器自选合适档位 */
        $pfN = count($pfImgs);
        $pfSizes = $pfN >= 3 ? '(max-width:640px) 30vw, 200px' : ($pfN === 2 ? '(max-width:640px) 46vw, 300px' : '(max-width:640px) 92vw, 620px');
      ?>
      <?php foreach ($pfImgs as $img): $pfImg = function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url((string) $img, 600) : (string) $img;
            $pfSet = function_exists('tri3_img_srcset') ? tri3_img_srcset($pfImg, array(320, 480, 600)) : ''; ?>
        <a class="moment-img" href="<?php echo htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url((string) $img) : (string) $img); ?>" data-fancybox="post-<?php echo substr(md5($pfLink), 0, 8); ?>" rel="noopener">
          <img data-src="<?php echo htmlspecialchars($pfImg); ?>"<?php echo tri3_img_size_attr($pfImg); ?><?php if ($pfSet !== '') { echo ' data-srcset="' . htmlspecialchars($pfSet) . '" data-sizes="' . $pfSizes . '"'; } ?> loading="lazy" decoding="async" alt="">
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="pf-foot">
      <a class="pf-readmore" href="<?php echo $pfLink; ?>">阅读全文<i class="ri-arrow-right-line"></i></a>
      <span class="pf-stats"><i class="ri-chat-3-line"></i><?php echo $pfCmt; ?> 评论</span>
    </div>
  </div>
</article>
