<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/* TriM3 404 兼容模式：宝塔面板默认 error_page 404 会吞掉主题 404 输出（改显 nginx 默认页）。
 * 开启（默认）后以 200 状态输出主题 404 页，保证页面正常展示；关闭则返回标准 404 状态码。 */
define('TRI3_IS_404', true);
/* footer.php 是独立的 $this->need() 调用（独立局部作用域），普通变量不共享，
   用 $GLOBALS 传递「本页无图片容器，跳过灯箱依赖」标记 */
$GLOBALS['__tri_no_gallery'] = true;
if (trim((string) Helper::options()->tri3_404Compat) !== '0') {
    \Typecho\Response::getInstance()->setStatus(200);
}
?>
<?php $this->need('header.php'); ?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <div class="md-card tri404">
      <div class="tri404-float f1"><i class="ri-planet-line"></i></div>
      <div class="tri404-float f2"><i class="ri-send-plane-line"></i></div>
      <div class="tri404-float f3"><i class="ri-compass-discover-line"></i></div>

      <div class="tri404-code">4<span class="tri404-zero">0<i class="ri-compass-3-line"></i></span>4</div>
      <h1>页面走丢了</h1>
      <p class="tri404-desc">你访问的页面不存在或已被移动，试试搜索，或者从下面找回最近的内容。</p>

      <form class="tri404-search" method="post" action="<?php $this->options->siteUrl(); ?>" role="search">
        <i class="ri-search-line"></i>
        <input type="text" name="s" placeholder="搜索站内内容…" autocomplete="off" aria-label="搜索关键词">
        <button type="submit">搜索</button>
      </form>

      <div class="tri404-actions">
        <a class="btn btn-primary" href="<?php $this->options->siteUrl(); ?>"><i class="ri-home-5-line"></i>返回首页</a>
        <a class="btn btn-tonal" href="<?php $this->options->siteUrl(); ?>moment.html"><i class="ri-quill-pen-line"></i>看看说说</a>
      </div>

      <?php
      $tri404Recent = Typecho_Widget::widget('Widget_Contents_Post_Recent');
      if ($tri404Recent->have()):
      ?>
      <div class="tri404-recent">
        <span class="tri404-recent-t"><i class="ri-time-line"></i>也许你想找</span>
        <div class="tri404-recent-list">
          <?php while ($tri404Recent->next()): ?>
          <a class="tri404-item" href="<?php $tri404Recent->permalink(); ?>">
            <b><?php $tri404Recent->title(); ?></b>
            <span><i class="ri-calendar-line"></i><?php $tri404Recent->date('Y-m-d'); ?></span>
          </a>
          <?php endwhile; ?>
        </div>
      </div>
      <?php endif; ?>

      <span class="tri404-tip">HTTP 404 · NOT FOUND</span>
    </div>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
