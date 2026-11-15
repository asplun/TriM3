<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>
<?php
/* 文章页悬浮导航（目录）：后台写文章页自定义字段 tri3_toc 勾选，默认开启 */
$triTocOn = '1';
try {
    $triTocOn = (string) ($this->fields->tri3_toc ?? '1');
} catch (Throwable $e) {}
if ($triTocOn === '') { $triTocOn = '1'; }
$triTocShow = $triTocOn === '1';
/* 有右栏 → TOC 由 sidebar.php 渲染在右栏顶部；无右栏 → 大框外 fixed 悬浮 */
$triTocInSidebar = $triTocShow && tri_show_right();
if ($triTocShow) {
    $GLOBALS['__tri_toc_render'] = true;
}
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <article class="md-card">
      <header class="single-header post-hero banner-reveal">
        <?php $triFirstCat = $this->categories ? reset($this->categories) : null; ?>
        <nav class="post-hero-top" aria-label="面包屑">
          <a class="post-crumb" href="<?php $this->options->siteUrl(); ?>"><i class="ri-home-4-line"></i>首页</a>
          <i class="post-crumb-arrow ri-arrow-right-s-line"></i>
          <?php if ($triFirstCat): ?>
          <a class="post-crumb" href="<?php echo htmlspecialchars($triFirstCat['permalink']); ?>"><?php echo htmlspecialchars($triFirstCat['name']); ?></a>
          <?php else: ?>
          <span class="post-crumb">文章</span>
          <?php endif; ?>
        </nav>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <div class="single-meta post-meta">
          <span class="post-meta-author">
            <img class="post-meta-avatar" src="<?php echo htmlspecialchars(tri3_avatar_url(64)); ?>" alt="">
            <a href="<?php $this->author->permalink(); ?>"><?php $this->author(); ?></a>
          </span>
          <i class="post-meta-dot"></i>
          <span><i class="ri-calendar-line"></i><?php $this->date('Y-m-d H:i'); ?></span>
          <i class="post-meta-dot"></i>
          <span><i class="ri-book-open-line"></i><?php echo tri3_read_time($this); ?></span>
          <i class="post-meta-dot"></i>
          <span><i class="ri-chat-3-line"></i><?php echo tri3_comment_count($this); ?> 条评论</span>
        </div>
      </header>
      <div class="single-body post-content main-reveal">
        <?php tri3_joe_content($this); ?>
      </div>
      <?php if ($this->tags): ?>
      <div class="single-tags tri3-reveal main-reveal">
        <?php foreach ($this->tags as $triTag): ?>
        <a class="post-tag single-tag" href="<?php echo htmlspecialchars((string) ($triTag['permalink'] ?? '#')); ?>"><i class="ri-price-tag-3-line"></i><?php echo htmlspecialchars((string) ($triTag['name'] ?? '')); ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php $this->need('component/copyright.php'); ?>
    </article>

    <nav class="post-nav tri3-reveal main-reveal">
      <?php $triPrevP = tri3_adjacent_post($this, 'prev'); ?>
      <div class="post-nav-cell post-nav-prev">
        <?php if ($triPrevP): ?>
        <a class="post-nav-card" href="<?php echo $triPrevP['permalink']; ?>" title="<?php echo htmlspecialchars((string) $triPrevP['title']); ?>">
          <i class="post-nav-arrow ri-arrow-left-line"></i>
          <span class="post-nav-title"><?php echo htmlspecialchars((string) $triPrevP['title']); ?></span>
        </a>
        <?php else: ?>
        <span class="post-nav-empty">没有更早的文章了</span>
        <?php endif; ?>
      </div>
      <?php $triNextP = tri3_adjacent_post($this, 'next'); ?>
      <div class="post-nav-cell post-nav-next">
        <?php if ($triNextP): ?>
        <a class="post-nav-card" href="<?php echo $triNextP['permalink']; ?>" title="<?php echo htmlspecialchars((string) $triNextP['title']); ?>">
          <span class="post-nav-title"><?php echo htmlspecialchars((string) $triNextP['title']); ?></span>
          <i class="post-nav-arrow ri-arrow-right-line"></i>
        </a>
        <?php else: ?>
        <span class="post-nav-empty">没有更新的文章了</span>
        <?php endif; ?>
      </div>
    </nav>

    <?php if ($this->allow('comment')) $this->need('comments.php'); ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
  <?php /* 无右栏：只在 layout 内留一个标记，真正的目录由 main.js 注入到 body（见 syncTocAside）。
     不能直接放 aside 在 layout 里：.layout 有 backdrop-filter，会形成 fixed 包含块，导致目录错位并跟随页面滚动。 */ ?>
  <?php if ($triTocShow && !$triTocInSidebar): ?>
  <div data-tri3-toc-marker hidden></div>
  <?php endif; ?>
</div>


<?php $this->need('footer.php'); ?>
