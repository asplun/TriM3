<?php
/**
 * 番剧卡片正文（单点渲染）
 * 模板 page-bangumi.php 与 api/list-api.php 共用，避免两份 100+ 行标记各自漂移。
 * 调用前需准备好：$triBgmData / $triBgmTypes / $triBgmTotal / $triBgmShowFilter
 */
if (!isset($triBgmData) || !is_array($triBgmData)) { $triBgmData = array(); }
if (!isset($triBgmTypes) || !is_array($triBgmTypes)) { $triBgmTypes = array(); }
if (!isset($triBgmTotal)) { $triBgmTotal = 0; }
if (!isset($triBgmShowFilter)) { $triBgmShowFilter = true; }
?>


      <?php if (!empty($triBgmData['ok'])): ?>

      <?php if ($triShowSearch): ?>
      <div class="douban-search bgm-search">
        <i class="ri-search-line"></i>
        <input type="text" class="douban-search-input bgm-search-input" placeholder="搜索追番标题…" />
      </div>
      <?php endif; ?>

      <!-- ① 状态 Tab（MD3 Tab Bar） -->
      <div class="douban-tabs bgm-tabs">
        <button type="button" class="douban-tab bgm-tab is-active" data-bgm-status="">
          <i class="ri-dashboard-3-line"></i>全部<span class="douban-tab-count bgm-tab-count"><?php echo count($triBgmItems); ?></span>
        </button>
        <button type="button" class="douban-tab bgm-tab" data-bgm-status="2">
          <i class="ri-eye-line"></i>在看<span class="douban-tab-count bgm-tab-count"><?php echo $triBgmStatusCount['2']; ?></span>
        </button>
        <button type="button" class="douban-tab bgm-tab" data-bgm-status="1">
          <i class="ri-time-line"></i>想看<span class="douban-tab-count bgm-tab-count"><?php echo $triBgmStatusCount['1']; ?></span>
        </button>
        <button type="button" class="douban-tab bgm-tab" data-bgm-status="3">
          <i class="ri-check-double-line"></i>看过<span class="douban-tab-count bgm-tab-count"><?php echo $triBgmStatusCount['3']; ?></span>
        </button>
      </div>

      <?php if ($triShowFilter): ?>
      <div class="douban-filters bgm-filters">
        <?php if (count($triBgmGenres) > 1): ?>
        <div class="douban-genrebar bgm-genrebar">
          <span class="douban-genre-label"><i class="ri-price-tag-3-line"></i></span>
          <?php $triGc = 0; foreach ($triBgmGenres as $triG => $triGn): if (++$triGc > 12) break; ?>
          <button type="button" class="douban-genre bgm-genre" data-bgm-genre="<?php echo htmlspecialchars($triG); ?>"><?php echo htmlspecialchars($triG); ?><span class="douban-filter-count"><?php echo $triGn; ?></span></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="douban-sec bgm-sec is-active">
        <?php if (count($triBgmYears) > 1): ?>
        <div class="douban-years bgm-years">
          <?php foreach ($triBgmYears as $triY => $triIts): ?>
          <button type="button" class="douban-year-anchor bgm-year-anchor" data-bgm-year="<?php echo htmlspecialchars($triY); ?>"><?php echo htmlspecialchars($triY); ?></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="douban-groups bgm-groups">
          <?php foreach ($triBgmYears as $triY => $triIts): ?>
          <section class="douban-group bgm-group" data-bgm-group="<?php echo htmlspecialchars($triY); ?>">
            <div class="douban-yearhead bgm-yearhead">
              <span class="douban-yearhead-num"><?php echo htmlspecialchars($triY); ?></span>
              <span class="douban-yearhead-count"><?php echo count($triIts); ?> 部</span>
              <span class="douban-yearhead-line"></span>
            </div>
            <div class="douban-grid bgm-grid">
              <?php foreach ($triIts as $triIt): ?>
              <?php
                $triSt = isset($triBgmStatusCount[$triIt['status']]) ? $triIt['status'] : '2';
                $triEp = $triIt['new_ep'] !== '' ? $triIt['new_ep'] : ($triIt['is_finish'] ? '全' . ($triIt['total'] ?: '?') . '话' : '连载中');
              ?>
              <a class="douban-item bgm-item tri3-reveal main-reveal" href="<?php echo htmlspecialchars($triIt['url']); ?>" target="_blank" rel="noopener"
                 data-status="<?php echo $triSt; ?>"
                 data-genres="<?php echo htmlspecialchars(implode('|', $triIt['styles'])); ?>"
                 data-title="<?php echo htmlspecialchars($triIt['title']); ?>">
                <div class="douban-cover bgm-cover">
                  <?php
                    /* 缩略图：网格卡片实际显示约 133x177px，走图片代理输出 320px WebP 缩略图 */
                    $triCov = (string) $triIt['cover'];
                    if ($triCov !== '' && function_exists('tri3_img_proxy_url')) { $triCov = tri3_img_proxy_url($triCov, 320, 'auto'); }
                  ?>
                  <?php if ($triCov !== ''): ?>
                  <img <?php echo tri3_img_lazy_attrs($triCov); ?> alt="<?php echo htmlspecialchars($triIt['title']); ?>" />
                  <?php endif; ?>
                  <span class="douban-status bgm-status"><i class="ri-<?php echo $triSt === '1' ? 'time-line' : ($triSt === '2' ? 'eye-line' : 'check-double-line'); ?>"></i><?php echo tri_bangumi_status_text($triSt); ?></span>
                  <?php if ($triIt['badge'] !== ''): ?>
                  <span class="bgm-badge"><?php echo htmlspecialchars($triIt['badge']); ?></span>
                  <?php endif; ?>
                  <?php if ($triIt['score'] > 0): ?>
                  <span class="douban-rate bgm-rate"><i class="ri-star-fill"></i><?php echo number_format($triIt['score'], 1); ?></span>
                  <?php endif; ?>
                </div>
                <div class="douban-info bgm-info">
                  <h3 class="douban-title bgm-title" title="<?php echo htmlspecialchars($triIt['title']); ?>"><?php echo htmlspecialchars($triIt['title']); ?></h3>
                  <p class="bgm-ep"><i class="ri-play-circle-line"></i><?php echo htmlspecialchars($triEp); ?><?php echo $triIt['total'] > 0 ? ' · 全' . $triIt['total'] . '话' : ''; ?></p>
                  <?php if ($triShowRemark && $triIt['evaluate'] !== ''): ?>
                  <p class="douban-comment bgm-comment"><?php echo htmlspecialchars($triIt['evaluate']); ?></p>
                  <?php endif; ?>
                </div>
              </a>
              <?php endforeach; ?>
            </div>
          </section>
          <?php endforeach; ?>
        </div>
      </div>

      <?php else: ?>
      <div class="douban-empty">
        <i class="ri-tv-2-line"></i>
        <p><?php echo htmlspecialchars($triBgmData['msg'] ?? '暂无数据'); ?></p>
        <small>请在后台「⑫ 番剧」填写 B 站 UID（个人空间链接中的数字）</small>
      </div>
      <?php endif; ?>
    </article>