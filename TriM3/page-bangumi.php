<?php
/**
 * B 站番剧
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/lib/helpers.php';
$this->need('header.php'); ?>

<?php
require_once __DIR__ . '/lib/bangumi.php';

$triBgmData = tri_bangumi_get();
$triBgmItems = !empty($triBgmData['ok']) ? $triBgmData['items'] : array();
$triBgmTotal = isset($triBgmData['total']) ? (int) $triBgmData['total'] : count($triBgmItems);

/* 状态统计 + 年份分组 + 类型聚合 */
$triBgmStatusCount = array('1' => 0, '2' => 0, '3' => 0);
$triBgmYears = array();
$triBgmGenres = array();
foreach ($triBgmItems as $triIt) {
    $triSt = isset($triIt['status']) && isset($triBgmStatusCount[$triIt['status']]) ? $triIt['status'] : '2';
    $triBgmStatusCount[$triSt]++;
    $triY = $triIt['year'] !== '' ? $triIt['year'] : '未标注';
    $triBgmYears[$triY][] = $triIt;
    foreach ($triIt['styles'] as $triG) {
        $triBgmGenres[$triG] = isset($triBgmGenres[$triG]) ? $triBgmGenres[$triG] + 1 : 1;
    }
}
krsort($triBgmYears, SORT_STRING);
arsort($triBgmGenres);

/* 前台显示开关 */
$triShowSearch = tri_switch('biliShowSearch', '1');
$triShowFilter = tri_switch('biliShowFilter', '1');
$triShowRemark = tri_switch('biliShowRemark', '1');
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
        <span class="page-title-badge"><i class="ri-tv-2-line"></i>番剧</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
    <article class="md-card douban-card bgm-card">
      <header class="single-header page-header-hero banner-reveal">
        <div class="single-meta">
          <span><i class="ri-user-line"></i><?php $this->author(); ?></span>
          <span><i class="ri-play-list-2-line"></i>共 <?php echo isset($triBgmTotal) ? (int)$triBgmTotal : 0; ?> 部追番</span>
        </div>
      </header>
  <?php include __DIR__ . '/component/bangumi-body.php'; ?>


    <?php if ($this->allow('comment')) $this->need('comments.php'); ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
