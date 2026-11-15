<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <article class="md-card">
      <?php
        /* 按页面别名自动匹配图标 */
        $triPageSlug = (string) $this->slug;
        $triPageIcon = 'ri-file-text-line';
        if (strpos($triPageSlug, 'about') !== false || strpos($triPageSlug, 'intro') !== false) {
            $triPageIcon = 'ri-user-smile-line';
        } elseif (strpos($triPageSlug, 'link') !== false || strpos($triPageSlug, 'friend') !== false) {
            $triPageIcon = 'ri-links-line';
        } elseif (strpos($triPageSlug, 'moment') !== false || strpos($triPageSlug, 'shuo') !== false) {
            $triPageIcon = 'ri-chat-3-line';
        } elseif (strpos($triPageSlug, 'music') !== false) {
            $triPageIcon = 'ri-music-2-line';
        }
      ?>
      <header class="single-header page-header-hero banner-reveal">
        <span class="page-title-badge"><i class="<?php echo $triPageIcon; ?>"></i>独立页面</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
        <div class="single-meta">
          <span><i class="ri-user-line"></i><?php $this->author(); ?></span>
          <span><i class="ri-chat-3-line"></i><?php echo tri3_comment_count($this); ?> 条评论</span>
        </div>
      </header>
      <div class="single-body post-content main-reveal">
        <?php tri3_joe_content($this); ?>
      </div>
    </article>

    <?php if ($this->allow('comment')) $this->need('comments.php'); ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
