<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>
<?php
$triLogged = $this->user->hasLogin();
$triIsAdmin = $triLogged && $this->user->pass('administrator');
/* Typecho 1.3：分类上下文 $this 即 archive；分类名/描述需查 metas 表（slug 来自路由） */
$triCatName = '';
$triCatDesc = '';
$triCatSlug = trim((string) $this->request->get('slug', ''));
try {
    if ($triCatSlug !== '') {
        $triCatDb = Typecho_Db::get();
        $triCatRow = $triCatDb->fetchRow(
            $triCatDb->select('name', 'description')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->where('slug = ?', $triCatSlug)
                ->limit(1)
        );
        if ($triCatRow) {
            $triCatName = trim((string) $triCatRow['name']);
            $triCatDesc = trim((string) ($triCatRow['description'] ?? ''));
        }
    }
} catch (Throwable $e) {}
if ($triCatName === '') {
    ob_start();
    $this->archiveTitle(['category' => '%s'], '', '');
    $triCatName = trim(strip_tags(ob_get_clean()));
}
$triCatCount = (int) $this->getTotal();
$triPageNo = max(1, (int) $this->request->get('page', 1));
$triPageSize = max(1, (int) $this->options->pageSize);
$triTotalPage = max(1, (int) ceil($triCatCount / $triPageSize));
?>
<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
    <header class="single-header page-header-hero banner-reveal">
      <span class="page-title-badge"><i class="ri-folder-2-line"></i>分类</span>
      <h1 class="single-title"><?php echo htmlspecialchars($triCatName); ?></h1>
      <span class="page-title-rule"></span>
      <div class="single-meta">
        <?php if ($triCatDesc !== ''): ?>
        <span class="single-meta-desc"><i class="ri-quill-pen-line"></i><?php echo htmlspecialchars($triCatDesc); ?></span>
        <?php endif; ?>
        <span><i class="ri-file-list-3-line"></i><?php echo $triCatCount; ?> 篇文章</span>
      </div>
    </header>

    <div class="post-list" id="tri3-cat-list">
      <?php if ($this->have()): ?>
        <?php while ($this->next()): ?>
          <?php $GLOBALS['__tri_item'] = $this; $this->need('component/list-item.php'); ?>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="moment-empty"><i class="ri-inbox-archive-line"></i>该分类下还没有文章</div>
      <?php endif; ?>
    </div>

    <?php
    if ($triTotalPage > 1) {
        tri3_pagination($triPageNo, $triTotalPage, '', '#tri3-cat-list', array('type' => 'posts', 'cat' => $triCatSlug));
    }
    ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
