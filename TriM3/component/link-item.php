<?php
/**
 * 友链条目（单点渲染）
 * page-links.php 与 api/list-api.php 的友链模板共用。
 * 调用前需准备：$triLink（img/url/name/desc），$triShot（截图代理 URL）。
 * 截图走 tri3_img_lazy_attrs()：前两张优先加载、其余懒加载（与全站图片策略一致）。
 */
$triLink = isset($triLink) && is_array($triLink) ? $triLink : array();
$triImg  = isset($triLink['img'])  ? (string) $triLink['img']  : '';
$triUrl  = isset($triLink['url'])  ? (string) $triLink['url']  : '';
$triName = isset($triLink['name']) ? (string) $triLink['name'] : '';
$triDesc = isset($triLink['desc']) ? (string) $triLink['desc'] : '';
$triDomain = (string) parse_url($triUrl, PHP_URL_HOST);
if ($triDomain === '') { $triDomain = $triUrl; }
$triFirst = function_exists('mb_substr') ? mb_substr($triName, 0, 1) : substr($triName, 0, 1);
if (!isset($triShot)) { $triShot = ''; }
$triShotAttr = $triShot !== '' ? 'src="' . htmlspecialchars($triShot) . '" loading="eager" decoding="async"' : '';
?>
<a class="link-bar tri3-reveal main-reveal" href="<?php echo htmlspecialchars($triUrl); ?>" target="_blank" rel="noopener nofollow">
            <img class="link-bar-bg" <?php echo $triShotAttr; ?> alt="<?php echo htmlspecialchars($triName); ?> 网站截图" data-shot-retries="0" onload="if(this.naturalWidth>1){this.parentNode.classList.remove('is-fallback');}else{this.parentNode.classList.add('is-fallback');var n=parseInt(this.dataset.shotRetries||'0',10);if(n<3){this.dataset.shotRetries=String(n+1);var el=this;setTimeout(function(){el.src='<?php echo htmlspecialchars($triShot, ENT_QUOTES); ?>'+(<?php echo strpos($triShot, '?') === false ? "'?'" : "'&'"; ?>)+'shot_retry='+Date.now();},2500);}}" onerror="this.parentNode.classList.add('is-fallback');var n=parseInt(this.dataset.shotRetries||'0',10);if(n<3){this.dataset.shotRetries=String(n+1);var el=this;setTimeout(function(){el.src='<?php echo htmlspecialchars($triShot, ENT_QUOTES); ?>'+(<?php echo strpos($triShot, '?') === false ? "'?'" : "'&'"; ?>)+'shot_retry='+Date.now();},2500);}">
            <div class="link-bar-fallback"><i class="ri-global-line"></i><span><?php echo htmlspecialchars($triDomain); ?></span></div>
            <div class="link-bar-shade"></div>
            <div class="link-bar-content">
              <span class="link-bar-avatar">
                <?php if ($triImg !== ''): ?>
                <img <?php echo tri3_img_lazy_attrs($triImg); ?> alt="<?php echo htmlspecialchars($triName); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <span class="link-bar-avatar-fallback" style="display:none"><?php echo htmlspecialchars($triFirst); ?></span>
                <?php else: ?>
                <span class="link-bar-avatar-fallback"><?php echo htmlspecialchars($triFirst); ?></span>
                <?php endif; ?>
              </span>
              <span class="link-bar-info">
                <span class="link-bar-name"><?php echo htmlspecialchars($triName); ?></span>
                <?php if ($triDesc !== ''): ?>
                <span class="link-bar-desc"><?php echo htmlspecialchars($triDesc); ?></span>
                <?php endif; ?>
              </span>
            </div>
          </a>