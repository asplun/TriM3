<?php
/**
 * 豆瓣卡片正文（单点渲染）
 *
 * 模板 page-douban.php 与站内局部渲染 api/list-api.php 共用，避免两处各维护一份
 * 100+ 行的豆瓣标记。调用约定：包含前需准备好
 *   $triDbData / $triDbTypes / $triDbMeta / $triStatusMeta
 *   $triShowSearch / $triShowFilter / $triShowRemark / $triShowRating
 */
if (!isset($triDbData) || !is_array($triDbData)) { $triDbData = array(); }
if (!isset($triDbTypes) || !is_array($triDbTypes)) { $triDbTypes = array(); }
if (!isset($triDbMeta) || !is_array($triDbMeta)) { $triDbMeta = array(); }
if (!isset($triStatusMeta) || !is_array($triStatusMeta)) { $triStatusMeta = array(); }
if (!isset($triShowFilter)) { $triShowFilter = true; }
if (!isset($triShowRemark)) { $triShowRemark = true; }
if (!isset($triShowRating)) { $triShowRating = true; }
if (!isset($triShowSearch)) { $triShowSearch = true; }
?>
      <?php if (trim((string) tri_opt('doubanUid', '')) === ''): ?>
        <div class="douban-empty">
          <i class="ri-user-settings-line"></i>
          <p>尚未在后台设置豆瓣用户 ID</p>
          <small>主题设置 → ⑩ 豆瓣 → 豆瓣用户 ID</small>
        </div>
      <?php else: ?>
        <?php if (!empty($triShowSearch)): ?>
        <div class="douban-search">
          <i class="ri-search-line"></i>
          <input type="text" class="douban-search-input" placeholder="搜索标记：书名 / 片名 / 作者…" data-douban-search>
        </div>
        <?php endif; ?>

        <?php if (!empty($triDbData) && is_array($triDbData) && count($triDbData) > 1): ?>
        <div class="douban-tabs" role="tablist">
          <?php if (!empty($triDbData) && is_array($triDbData)): foreach ($triDbData as $triT => $triD): ?>
            <button type="button" class="douban-tab<?php echo $triT === $triDbTypes[0] ? ' is-active' : ''; ?>" data-douban-tab="<?php echo $triT; ?>">
              <i class="<?php echo $triDbMeta[$triT]['icon']; ?>"></i><?php echo $triDbMeta[$triT]['name']; ?>
              <span class="douban-tab-count"><?php echo !empty($triD['ok']) ? count($triD['items']) : 0; ?></span>
            </button>
          <?php endforeach; endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($triDbData) && is_array($triDbData)): foreach ($triDbData as $triT => $triD): ?>
        <section class="douban-sec<?php echo $triT === $triDbTypes[0] ? ' is-active' : ''; ?>" data-douban-sec="<?php echo $triT; ?>" data-douban-type="<?php echo $triT; ?>">
          <?php if (empty($triD['ok'])): ?>
            <div class="douban-empty">
              <i class="ri-cloud-off-line"></i>
              <p><?php echo isset($triD['msg']) ? htmlspecialchars($triD['msg']) : '获取失败'; ?></p>
              <small>检查后台豆瓣设置，或稍后刷新（数据有缓存）</small>
            </div>
          <?php else: ?>
            <?php
              /* 状态与类型筛选（前端过滤用 data-status / data-genres） */
              $triStatusCount = array('wish' => 0, 'doing' => 0, 'done' => 0);
              $triGenreSet = array();
              foreach ($triD['items'] as $triIt) {
                  $triStatusCount[tri_douban_status_key($triIt['status'])]++;
                  foreach (($triIt['genres'] ?? array()) as $triG) { $triGenreSet[$triG] = true; }
              }
              ksort($triGenreSet);
            ?>
            <?php if ($triShowFilter): ?>
            <div class="douban-filters">
              <div class="douban-statusbar">
                <button type="button" class="douban-filter is-active" data-douban-status="">全部 <span class="douban-filter-count"><?php echo count($triD['items']); ?></span></button>
                <?php foreach (array('wish', 'doing', 'done') as $triS): ?>
                  <?php if ($triStatusCount[$triS] > 0): ?>
                  <button type="button" class="douban-filter" data-douban-status="<?php echo $triS; ?>">
                    <?php echo $triStatusMeta[$triS][$triT] ?? ''; ?> <span class="douban-filter-count"><?php echo $triStatusCount[$triS]; ?></span>
                  </button>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
              <?php if (count($triGenreSet) > 1): ?>
              <div class="douban-genrebar">
                <span class="douban-genre-label"><i class="ri-price-tag-3-line"></i></span>
                <?php foreach (array_keys($triGenreSet) as $triG): ?>
                  <button type="button" class="douban-genre" data-douban-genre="<?php echo htmlspecialchars($triG); ?>"><?php echo htmlspecialchars($triG); ?></button>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            
            <?php
              /* 按年份分组（标记时间取年份；无年份归入「未标注」组） */
              $triYears = array();
              foreach ($triD['items'] as $triIt) {
                  $triY = '';
                  if ($triIt['date'] !== '') { $triY = substr($triIt['date'], 0, 4); }
                  if ($triY === '') { $triY = '未标注'; }
                  $triYears[$triY][] = $triIt;
              }
              krsort($triYears, SORT_STRING);
            ?>
            <?php if (count($triYears) > 1): ?>
            <div class="douban-years">
              <?php foreach (array_keys($triYears) as $triY): ?>
                <span class="douban-year-anchor" data-year="<?php echo htmlspecialchars($triY); ?>"><?php echo htmlspecialchars($triY); ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="douban-groups" data-douban-grid>
              <?php foreach ($triYears as $triY => $triIts): ?>
              <div class="douban-group" data-douban-group="<?php echo htmlspecialchars($triY); ?>">
                <div class="douban-yearhead">
                  <span class="douban-yearhead-num"><?php echo htmlspecialchars($triY); ?></span>
                  <span class="douban-yearhead-count"><?php echo count($triIts); ?> 条</span>
                  <span class="douban-yearhead-line"></span>
                </div>
                <div class="douban-grid">
                <?php foreach ($triIts as $triIt): ?>
                <a class="douban-item tri3-reveal main-reveal" href="<?php echo htmlspecialchars($triIt['url'] !== '' ? $triIt['url'] : '#'); ?>" target="_blank" rel="noopener nofollow"
                   data-status="<?php echo tri_douban_status_key($triIt['status']); ?>"
                   data-genres="<?php echo htmlspecialchars(implode('|', $triIt['genres'] ?? array())); ?>"
                   data-title="<?php echo htmlspecialchars(mb_strtolower($triIt['title'] . ' ' . $triIt['author'])); ?>">
                  <div class="douban-cover">
                      <?php
                        /* 缩略图：网格卡片实际显示约 133x177px，与番剧页保持一致 */
                        $triCov = (string) $triIt['cover'];
                        if ($triCov !== '' && function_exists('tri3_img_proxy_url')) { $triCov = tri3_img_proxy_url($triCov, 320, 'auto'); }
                      ?>
                      <?php if ($triCov !== ''): ?>
                      <img <?php echo tri3_img_lazy_attrs($triCov); ?> alt="<?php echo htmlspecialchars($triIt['title']); ?>" onerror="this.closest('.douban-cover').classList.add('is-fallback');">
                    <?php endif; ?>
                    <span class="douban-status"><i class="ri-bookmark-line"></i><?php echo tri_douban_status_text($triIt['status']); ?></span>
                    <?php if ($triShowRating && $triIt['rating'] > 0): ?>
                    <span class="douban-rate"><i class="ri-star-fill"></i><?php echo number_format($triIt['rating'], 1); ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="douban-info">
                    <h3 class="douban-title" title="<?php echo htmlspecialchars($triIt['title']); ?>"><?php echo htmlspecialchars($triIt['title']); ?></h3>
                    <?php if ($triShowRating && $triIt['rating'] > 0): ?>
                    <div class="douban-rateline" title="豆瓣评分 <?php echo number_format($triIt['rating'], 1); ?>">
                      <span class="douban-starbar"><i style="width:<?php echo min(100, $triIt['rating'] * 10); ?>%"></i></span>
                      <span class="douban-starnum"><?php echo number_format($triIt['rating'], 1); ?></span>
                      <?php if ($triIt['rating_count'] > 0): ?><span class="douban-starcnt"><?php echo $triIt['rating_count'] > 10000 ? round($triIt['rating_count'] / 10000, 1) . '万' : $triIt['rating_count']; ?>人评</span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($triShowRemark): $triAbs = $triIt['comment'] !== '' ? $triIt['comment'] : $triIt['card_subtitle']; ?>
                      <?php if ($triAbs !== ''): ?>
                      <p class="douban-comment"><?php echo htmlspecialchars($triAbs); ?></p>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </a>
                <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </section>
          <?php endif; ?>

        <?php endforeach; endif; ?>
      <?php endif; ?>
    