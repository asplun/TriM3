<?php
/**
 * 归档
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');

/* ===== 归档数据：全部已发布文章，按 年 → 月 分组 ===== */
$triDb = Typecho_Db::get();
$triArchRows = $triDb->fetchAll(
    $triDb->select('cid', 'title', 'slug', 'created', 'text')
        ->from('table.contents')
        ->where('type = ?', 'post')
        ->where('status = ?', 'publish')
        ->order('table.contents.created', Typecho_Db::SORT_DESC)
);
/* 分类 / 标签 统计：relationships + metas */
$triArchCats = array();   // cid -> 首个分类名
$triCatStat = array();    // slug -> array(name, count)
$triTagStat = array();    // slug -> array(name, count)
try {
    $triTaxRows = $triDb->fetchAll(
        $triDb->select('r.cid', 'm.name', 'm.slug', 'm.type')
            ->from('table.relationships AS r')
            ->join('table.metas AS m', 'r.mid = m.mid', Typecho_Db::INNER_JOIN)
    );
    foreach ($triTaxRows as $triTx) {
        if ($triTx['type'] === 'category') {
            if (!isset($triArchCats[(int) $triTx['cid']])) { $triArchCats[(int) $triTx['cid']] = $triTx['name']; }
            $triS = (string) $triTx['slug'];
            if (!isset($triCatStat[$triS])) { $triCatStat[$triS] = array('name' => $triTx['name'], 'count' => 0); }
            $triCatStat[$triS]['count']++;
        } elseif ($triTx['type'] === 'tag') {
            $triS = (string) $triTx['slug'];
            if (!isset($triTagStat[$triS])) { $triTagStat[$triS] = array('name' => $triTx['name'], 'count' => 0); }
            $triTagStat[$triS]['count']++;
        }
    }
} catch (Throwable $e) {}

/* 统计与分组 */
$triArchTotal = count($triArchRows);
$triArchWords = 0;
$triYearStat = array();
$triMonthStat = array_fill(1, 12, 0);
$triArchGroup = array();
foreach ($triArchRows as $triAr) {
    $triArchWords += mb_strlen(trim(strip_tags((string) $triAr['text'])), 'UTF-8');
    $triY = (int) date('Y', (int) $triAr['created']);
    $triM = (int) date('n', (int) $triAr['created']);
    if (!isset($triYearStat[$triY])) { $triYearStat[$triY] = 0; }
    $triYearStat[$triY]++;
    $triMonthStat[$triM]++;
    if (!isset($triArchGroup[$triY])) { $triArchGroup[$triY] = array(); }
    if (!isset($triArchGroup[$triY][$triM])) { $triArchGroup[$triY][$triM] = array(); }
    $triArchGroup[$triY][$triM][] = $triAr;
}
krsort($triYearStat);
$triArchYears = count($triYearStat);
$triArchMonths = 0;
foreach ($triArchGroup as $triYg) { $triArchMonths += count($triYg); }
$triYearMax = $triYearStat ? max($triYearStat) : 1;
$triMonthMax = $triMonthStat ? max($triMonthStat) : 1;
$triTagMax = $triTagStat ? max(array_column($triTagStat, 'count')) : 1;
/* 标签云字号 12~17 */
$triTagFont = function ($c) use ($triTagMax) {
    return 12 + ($c / max(1, $triTagMax)) * 5;
};
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <?php if ($this->is('search')): ?>
    <article class="md-card archive-card">
      <header class="single-header page-header-hero">
        <span class="page-title-badge"><i class="ri-search-line"></i>搜索</span>
        <h1 class="single-title">搜索：<?php echo htmlspecialchars($this->archiveTitle); ?></h1>
        <span class="page-title-rule"></span>
      </header>
      <?php if ($this->have()): ?>
      <ul class="arch-list search-result-list">
        <?php while ($this->next()): ?>
        <li class="arch-item">
          <a href="<?php $this->permalink(); ?>">
            <span class="arch-item-dot" aria-hidden="true"></span>
            <span class="arch-item-main">
              <span class="arch-item-title"><?php $this->title(); ?></span>
              <span class="arch-item-cat"><i class="ri-calendar-line"></i><?php $this->date('Y-m-d'); ?></span>
            </span>
          </a>
        </li>
        <?php endwhile; ?>
      </ul>
      <?php $this->pageNav('上一页', '下一页'); ?>
      <?php else: ?>
      <div class="archive-empty">
        <i class="ri-search-eye-line"></i>
        <p>没有找到相关内容，换个关键词试试</p>
      </div>
      <?php endif; ?>
    </article>
    <?php else: ?>
    <article class="md-card archive-card">
      <header class="single-header page-header-hero">
        <span class="page-title-badge"><i class="ri-archive-line"></i>归档</span>
        <h1 class="single-title"><?php echo htmlspecialchars((string)$this->archiveTitle ? $this->archiveTitle : "归档"); ?></h1>
        <span class="page-title-rule"></span>
        <div class="single-meta">
          <span><i class="ri-file-list-3-line"></i><?php echo $triArchTotal; ?> 篇文章</span>
          <span><i class="ri-text-spacing"></i>约 <?php echo $triArchWords >= 10000 ? number_format($triArchWords / 10000, 1) . ' 万字' : $triArchWords . ' 字'; ?></span>
          <span><i class="ri-time-line"></i>跨越 <?php echo $triArchYears; ?> 年 · <?php echo $triArchMonths; ?> 个月</span>
        </div>
      </header>

      <?php if ($triArchTotal === 0): ?>
      <div class="archive-empty">
        <i class="ri-inbox-archive-line"></i>
        <p>还没有文章，去发布第一篇吧</p>
      </div>
      <?php else: ?>

      <!-- 年度 / 月度统计柱状图 -->
      <div class="arch-stats-grid tri3-reveal main-reveal">
        <section class="stat-panel">
          <h3 class="stat-panel-title"><i class="ri-bar-chart-grouped-line"></i>年度发布</h3>
          <div class="stat-bars">
            <?php foreach ($triYearStat as $triY => $triC): ?>
            <div class="stat-bar" title="<?php echo $triY; ?> 年 · <?php echo $triC; ?> 篇">
              <div class="stat-bar-track">
                <div class="stat-bar-fill" style="height: <?php echo max(2, round($triC / $triYearMax * 100, 1)); ?>%;">
                  <em><?php echo $triC; ?></em>
                </div>
              </div>
              <span class="stat-bar-label"><?php echo $triY; ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
        <section class="stat-panel">
          <h3 class="stat-panel-title"><i class="ri-bar-chart-line"></i>月度分布</h3>
          <div class="stat-bars">
            <?php for ($triMi = 1; $triMi <= 12; $triMi++): ?>
            <div class="stat-bar" title="<?php echo $triMi; ?> 月 · <?php echo $triMonthStat[$triMi]; ?> 篇">
              <div class="stat-bar-track">
                <div class="stat-bar-fill" style="height: <?php echo max(2, round($triMonthStat[$triMi] / $triMonthMax * 100, 1)); ?>%;">
                  <em><?php echo $triMonthStat[$triMi]; ?></em>
                </div>
              </div>
              <span class="stat-bar-label"><?php echo $triMi; ?>月</span>
            </div>
            <?php endfor; ?>
          </div>
        </section>
      </div>

      <!-- 分类统计 -->
      <?php if ($triCatStat): ?>
      <section class="arch-tax tri3-reveal main-reveal">
        <h3 class="arch-tax-title"><i class="ri-folder-2-line"></i>分类 <b class="arch-tax-total"><?php echo count($triCatStat); ?> 个</b></h3>
        <div class="arch-tax-list">
          <?php uasort($triCatStat, function ($a, $b) { return $b['count'] - $a['count']; }); ?>
          <?php foreach ($triCatStat as $triCSlug => $triCInfo): ?>
          <a class="arch-tax-chip" href="<?php echo Typecho_Router::url('category', array('slug' => $triCSlug), (string) $this->options->index); ?>">
            <i class="ri-folder-line"></i><?php echo htmlspecialchars($triCInfo['name']); ?><b><?php echo $triCInfo['count']; ?></b>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- 标签云 -->
      <?php if ($triTagStat): ?>
      <section class="arch-tax tri3-reveal main-reveal">
        <h3 class="arch-tax-title"><i class="ri-price-tag-3-line"></i>标签 <b class="arch-tax-total"><?php echo count($triTagStat); ?> 个</b></h3>
        <div class="arch-tag-cloud">
          <?php uasort($triTagStat, function ($a, $b) { return $b['count'] - $a['count']; }); ?>
          <?php foreach ($triTagStat as $triTSlug => $triTInfo): ?>
          <a class="arch-tag" style="font-size: <?php echo $triTagFont($triTInfo['count']); ?>px;" href="<?php echo Typecho_Router::url('tag', array('slug' => $triTSlug), (string) $this->options->index); ?>">
            <?php echo htmlspecialchars($triTInfo['name']); ?><b><?php echo $triTInfo['count']; ?></b>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- 时间线 -->
      <section class="arch-timeline tri3-reveal main-reveal">
        <h3 class="arch-tax-title"><i class="ri-timer-line"></i>时间线</h3>
        <?php foreach ($triArchGroup as $triYear => $triMonths): ?>
        <section class="arch-year" id="arch-<?php echo $triYear; ?>">
          <h2 class="arch-year-title">
            <span class="arch-year-num"><?php echo $triYear; ?></span>
            <span class="arch-year-rule"></span>
            <span class="arch-year-count"><?php
                $triYc = 0; foreach ($triMonths as $triMl) { $triYc += count($triMl); }
                echo $triYc; ?> 篇</span>
          </h2>
          <div class="arch-months">
            <?php foreach ($triMonths as $triMonth => $triPosts): ?>
            <section class="arch-month">
              <h3 class="arch-month-title">
                <span class="arch-month-num"><?php echo $triMonth; ?> 月</span>
                <span class="arch-month-count"><?php echo count($triPosts); ?> 篇</span>
              </h3>
              <ul class="arch-list">
                <?php foreach ($triPosts as $triP): ?>
                <?php $triPCat = isset($triArchCats[(int) $triP['cid']]) ? $triArchCats[(int) $triP['cid']] : ''; ?>
                <li class="arch-item">
                  <a href="<?php echo Typecho_Router::url('post', array('cid' => $triP['cid'], 'slug' => $triP['slug']), (string) $this->options->index); ?>">
                    <span class="arch-item-dot" aria-hidden="true"></span>
                    <span class="arch-item-main">
                      <span class="arch-item-title"><?php echo htmlspecialchars($triP['title']); ?></span>
                      <?php if ($triPCat !== ''): ?>
                      <span class="arch-item-cat"><i class="ri-price-tag-3-line"></i><?php echo htmlspecialchars($triPCat); ?></span>
                      <?php endif; ?>
                    </span>
                    <time class="arch-item-date"><?php echo date('m-d', (int) $triP['created']); ?></time>
                  </a>
                </li>
                <?php endforeach; ?>
              </ul>
            </section>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>
    </article>
    <?php endif; ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
