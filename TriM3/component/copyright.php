<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 文章页版权声明卡片（复刻 Fluid 风格：标签 → 标题 → 原文链接 → 作者/发布于/许可协议 三列 → CC 水印）
 * 调用：<?php $this->need('component/copyright.php'); ?>
 */
$triCoAuthorName = trim((string) ($this->author->screenName ?? $this->author->name ?? ''));
$triCoLink = (string) $this->permalink;
$triCoDate = date('Y年n月j日', (int) $this->created);
?>
<section class="copyright-card" itemscope itemtype="https://schema.org/CreativeWork">
  <meta itemprop="datePublished" content="<?php echo date('c', $this->created); ?>">
  <meta itemprop="url" content="<?php echo $triCoLink; ?>">

  <?php if ($this->tags): ?>
  <div class="copyright-tags"><?php $this->tags(' ', true, ''); ?></div>
  <?php endif; ?>

  <h3 class="copyright-post-title" itemprop="name">
    <a href="<?php echo $triCoLink; ?>"><?php $this->title(); ?></a>
  </h3>
  <a class="copyright-post-link" href="<?php echo $triCoLink; ?>" target="_blank" rel="bookmark"><?php echo $triCoLink; ?></a>

  <div class="copyright-divider"></div>

  <div class="copyright-info-row">
    <div class="ci-item">
      <span class="ci-label">作者</span>
      <span class="ci-value" itemprop="author"><?php echo $triCoAuthorName; ?></span>
    </div>
    <div class="ci-item">
      <span class="ci-label">发布于</span>
      <span class="ci-value"><time datetime="<?php echo date('c', $this->created); ?>"><?php echo $triCoDate; ?></time></span>
    </div>
    <div class="ci-item">
      <span class="ci-label">许可协议</span>
      <span class="ci-value ci-license"><i class="ri-creative-commons-line"></i>CC BY-NC-SA 4.0</span>
    </div>
  </div>
</section>
