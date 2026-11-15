<?php
/**
 * 文章
 *
 * 显示全站所有文章（倒序、分页）。独立页面模板，
 * 可配合首页菜单「文章」类型做列表区局部切换。
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$this->need('header.php');

$triPage = max(1, (int) ($_GET['page'] ?? 1));
$triSize = max(1, (int) Helper::options()->pageSize);
$triTotal = (int) \Typecho\Widget::widget('Widget_Stat')->publishedPostsNum;
$triTotalPage = max(1, (int) ceil($triTotal / $triSize));
$triArticles = \Typecho\Widget::widget('Widget_Archive@artpage_' . $triPage, array(
    'type'          => 'index',
    'pageSize'      => $triSize,
    'checkPermalink' => false,
), array('page' => $triPage));
$triArticles->to($triArticles);
?>
<style>
  .articles-page { padding: 4px 0 12px; }
  .articles-list .post-card + .post-card { margin-top: 14px; }
</style>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <div class="articles-page">
        <span class="page-title-badge"><i class="ri-article-line"></i>文章</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
      <header class="single-header page-header-hero banner-reveal">
        <div class="single-meta"><span><i class="ri-file-list-3-line"></i>共 <?php echo $triTotal; ?> 篇文章</span></div>
      </header>
      <div class="articles-list <?php echo tri_opt("postCardStyle", "original") === "grid" ? "grid-layout" : ""; ?>" id="tri3-home-list">
        <?php if ($triArticles->have()): ?>
          <?php while ($triArticles->next()): ?>
            <?php $GLOBALS['__tri_item'] = $triArticles; include __DIR__ . '/component/list-item.php'; ?>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="home-list-empty"><i class="ri-inbox-2-line"></i>这里还没有文章</div>
        <?php endif; ?>
      </div>
      <?php if ($triTotalPage > 1): ?>
        <?php tri3_pagination($triPage, $triTotalPage, '?page=', '#tri3-home-list', array('type' => 'posts')); ?>
      <?php endif; ?>
    </div>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
