<?php
/**
 * 豆瓣书影音
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/lib/helpers.php';
$this->need('header.php'); ?>

<?php
require_once __DIR__ . '/lib/douban.php';

/* 后台开关的展示类型（电影置首） */
$triDbTypes = tri_checked_arr('doubanTypes');
if (empty($triDbTypes)) { $triDbTypes = array('movie', 'book', 'music'); }
$triDbOrder = array('movie', 'book', 'music');
usort($triDbTypes, function ($triA, $triB) use ($triDbOrder) {
    $triIa = array_search($triA, $triDbOrder, true);
    $triIb = array_search($triB, $triDbOrder, true);
    return ($triIa === false ? 9 : $triIa) - ($triIb === false ? 9 : $triIb);
});

$triDbMeta = array(
    'movie' => array('name' => '电影', 'icon' => 'ri-film-line',    'hint' => '想看 / 在看 / 看过'),
    'book'  => array('name' => '读书', 'icon' => 'ri-book-2-line',  'hint' => '想读 / 在读 / 读过'),
    'music' => array('name' => '音乐', 'icon' => 'ri-music-2-line', 'hint' => '想听 / 在听 / 听过'),
);
$triStatusMeta = array(
    'wish'  => array('book' => '想读', 'movie' => '想看', 'music' => '想听'),
    'doing' => array('book' => '在读', 'movie' => '在看', 'music' => '在听'),
    'done'  => array('book' => '读过', 'movie' => '看过', 'music' => '听过'),
);

$triDbData = array();
$triDbTotal = 0;
foreach ($triDbTypes as $triT) {
    if (!isset($triDbMeta[$triT])) { continue; }
    $triDbData[$triT] = tri_douban_get($triT);
    if (!empty($triDbData[$triT]['ok'])) { $triDbTotal += count($triDbData[$triT]['items']); }
}

/* 前台显示开关 */
$triShowFilter = tri_switch('doubanShowFilter', '1');   /* 状态/类型筛选 */
$triShowRemark = tri_switch('doubanShowRemark', '1');   /* 短评/摘要 */
$triShowRating = tri_switch('doubanShowRating', '1');   /* 评分 */
$triShowSearch = tri_switch('doubanShowSearch', '1');   /* 搜索框 */
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
        <span class="page-title-badge"><i class="ri-finder-line"></i>豆瓣</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
    <article class="md-card douban-card">
      <header class="single-header page-header-hero banner-reveal">
        <div class="single-meta">
          <span><i class="ri-user-line"></i><?php $this->author(); ?></span>
          <span><i class="ri-folder-2-line"></i>共 <?php echo isset($triDbTotal) ? (int)$triDbTotal : 0; ?> 条标记</span>
        </div>
      </header>

<?php include __DIR__ . '/component/douban-body.php'; ?></article>

    <?php if ($this->allow('comment')) $this->need('comments.php'); ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
