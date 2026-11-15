<?php
/**
 * 友情链接
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php'); ?>

<div class="layout">
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>

  <main class="col-main">
        <span class="page-title-badge"><i class="ri-links-line"></i>友情链接</span>
        <h1 class="single-title"><?php $this->title(); ?></h1>
        <span class="page-title-rule"></span>
    <article class="md-card">
      <header class="single-header page-header-hero banner-reveal">
      </header>

      <?php
      $triApply = trim((string) tri_opt('friendApply', ''));
      if ($triApply !== ''):
      ?>
      <div class="links-apply"><?php echo $triApply; ?></div>
      <?php endif; ?>

      <div class="links-page">
        <?php
        $triFriends = trim((string) tri_opt('friends', ''));
        $triLinkCount = 0;
        /* 1) 解析友链并按分组聚合 */
        $triGroupsMap = array();   // 分组名 => 链接数组（插入序）
        $triGroupOrder = array();  // 分组出现顺序
        if ($triFriends !== '') {
            $triRows = array();
            if ($triFriends[0] === '[') {
                /* 可视化编辑器输出的 JSON */
                $triJson = json_decode($triFriends, true);
                if (is_array($triJson)) {
                    foreach ($triJson as $triLink) {
                        if (!is_array($triLink)) { continue; }
                        $triRows[] = array(
                            'img'  => isset($triLink['img']) ? trim((string) $triLink['img']) : '',
                            'url'  => isset($triLink['url']) ? trim((string) $triLink['url']) : '',
                            'name' => isset($triLink['name']) ? trim((string) $triLink['name']) : '',
                            'desc' => isset($triLink['desc']) ? trim((string) $triLink['desc']) : '',
                            'grp'  => isset($triLink['group']) ? trim((string) $triLink['group']) : '',
                        );
                    }
                }
            } else {
                /* 兼容旧格式：每行 头像URL, 站点URL, 名称, 描述, 分组名 */
                foreach (explode("\n", str_replace("\r\n", "\n", $triFriends)) as $triRow) {
                    $triRow = trim($triRow);
                    if ($triRow === '') { continue; }
                    $parts = explode(',', $triRow);
                    if (count($parts) < 3) { continue; }
                    $triRows[] = array(
                        'img'  => trim($parts[0]),
                        'url'  => trim($parts[1]),
                        'name' => trim($parts[2]),
                        'desc' => isset($parts[3]) ? trim($parts[3]) : '',
                        'grp'  => isset($parts[4]) ? trim($parts[4]) : '',
                    );
                }
            }
            foreach ($triRows as $triRow) {
                $triImg  = $triRow['img'];
                $triUrl  = $triRow['url'];
                $triName = $triRow['name'];
                $triDesc = $triRow['desc'];
                $triGrp  = $triRow['grp'];
                if ($triGrp === '') { $triGrp = '默认分组'; }
                if ($triUrl === '' || $triName === '') { continue; }
                if (!isset($triGroupsMap[$triGrp])) {
                    $triGroupsMap[$triGrp] = array();
                    $triGroupOrder[] = $triGrp;
                }
                $triGroupsMap[$triGrp][] = array(
                    'img' => $triImg, 'url' => $triUrl, 'name' => $triName, 'desc' => $triDesc,
                );
                $triLinkCount++;
            }
        }
        /* 2) 分组顺序：后台 friendGroups 优先，未列出的分组排最后 */
        $triCfgGroups = array();
        $triCfgRaw = trim((string) tri_opt('friendGroups', ''));
        if ($triCfgRaw !== '') {
            foreach (explode("\n", str_replace("\r\n", "\n", $triCfgRaw)) as $triGLine) {
                $triGLine = trim($triGLine);
                if ($triGLine !== '') { $triCfgGroups[] = $triGLine; }
            }
        }
        if (!empty($triCfgGroups)) {
            $triOrder = $triCfgGroups;
            foreach ($triGroupOrder as $triG) {
                if (!in_array($triG, $triOrder, true)) { $triOrder[] = $triG; }
            }
            $triGroupOrder = $triOrder;
        }
        if ($triLinkCount > 0):
          foreach ($triGroupOrder as $triGrpKey):
            if (!isset($triGroupsMap[$triGrpKey])) { continue; }
            $triShowTitle = count($triGroupOrder) > 1;
        ?>
        <?php if ($triShowTitle): ?>
        <h2 class="links-group-title"><i class="ri-price-tag-3-line"></i><?php echo htmlspecialchars($triGrpKey); ?></h2>
        <?php endif; ?>
        <div class="links-grid">
          <?php foreach ($triGroupsMap[$triGrpKey] as $triLink):
              $triImg = $triLink['img'];
              $triUrl = $triLink['url'];
              $triName = $triLink['name'];
              $triDesc = $triLink['desc'];
              $triDomain = (string) parse_url($triUrl, PHP_URL_HOST);
              if ($triDomain === '') { $triDomain = $triUrl; }
              $triFirst = function_exists('mb_substr') ? mb_substr($triName, 0, 1) : substr($triName, 0, 1);
              /* 网站截图：走主题 shot-api 缓存代理（服务端缓存 N 天，超期自动重拉） */
              $triShot = rtrim((string) $this->options->siteUrl, '/') . '/usr/themes/' . $this->options->theme . '/api/shot-api.php?url=' . urlencode($triUrl) . '&v=1.2.5';
          ?>
          <?php include __DIR__ . '/component/link-item.php'; ?>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="links-empty">
          <i class="ri-link-m"></i>
          <p>暂无友链，去后台「设置外观 → ⑨ 友链」添加吧</p>
        </div>
        <?php endif; ?>
      </div>

      <div class="single-body post-content">
        <?php $this->content(); ?>
      </div>
    </article>

    <?php if ($this->allow('comment')) $this->need('comments.php'); ?>
  </main>

  <?php $this->need('sidebar.php'); ?>
</div>
<?php $this->need('footer.php'); ?>
