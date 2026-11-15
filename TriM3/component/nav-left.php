<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $avatar = tri3_avatar_url(46); ?>
<?php if (tri_show_left()): ?>
<aside class="col-left" id="drawer">
  <button class="icon-btn drawer-close" id="drawer-close" aria-label="关闭菜单"><i class="ri-close-line"></i></button>

  <div class="col-left-inner">
    <div class="brand">
      <?php $logoUrl = tri_opt('logoUrl'); ?>
      <?php if ($logoUrl !== ''): ?>
        <a class="brand-logo" href="<?php $this->options->siteUrl(); ?>"><img src="<?php echo $logoUrl; ?>" alt="<?php $this->options->title(); ?>"></a>
      <?php elseif ($avatar !== ''): ?>
        <a class="brand-logo" href="<?php $this->options->siteUrl(); ?>"><img src="<?php echo $avatar; ?>" alt="<?php $this->options->title(); ?>"></a>
      <?php else: ?>
        <a class="brand-logo brand-logo-fallback" href="<?php $this->options->siteUrl(); ?>"><?php echo trim3_first_char((string) $this->options->title); ?></a>
      <?php endif; ?>
    </div>

    <nav>
      <div class="nav-block">
        <ul class="nav-list">
          <?php
          $triNavRaw = trim((string) tri_opt('navItems', ''));
          $triNavItems = array();
          if ($triNavRaw !== '') {
            $triNavDecoded = function_exists('tri3_menu_decode') ? tri3_menu_decode($triNavRaw) : $triNavRaw;
            if (is_array($triNavDecoded)) {
              foreach ($triNavDecoded as $triItem) {
                if (!is_array($triItem)) continue;
                $triNavItems[] = array(
                  'name' => trim((string) ($triItem['name'] ?? '')),
                  'url'  => trim((string) ($triItem['url'] ?? '#')),
                  'icon' => trim((string) ($triItem['icon'] ?? '')),
                );
              }
            } else {
              foreach (preg_split('/\r\n|\r|\n/', $triNavDecoded) as $triLine) {
                $triLine = trim($triLine); if ($triLine === '') continue;
                $triParts = explode('|', $triLine);
                $triNavItems[] = array(
                  'name' => trim($triParts[0] ?? ''),
                  'url'  => trim($triParts[1] ?? '#'),
                  'icon' => (isset($triParts[2]) && trim($triParts[2]) !== '') ? trim($triParts[2]) : tri3_nav_icon($triParts[0] ?? ''),
                );
              }
            }
          } else {
            $triNavItems = array(
              array('name' => '首页', 'url' => (string) $this->options->siteUrl, 'icon' => 'ri-home-5-line'),
              array('name' => 'RSS 订阅', 'url' => (string) $this->options->feedUrl, 'icon' => 'ri-rss-line'),
            );
          }
          foreach ($triNavItems as $triNav):
            if ($triNav['name'] === '' && ($triNav['url'] === '#' || $triNav['url'] === '')) continue;
          ?>
          <li><a class="nav-link<?php if ($this->is('index') && $triNav['url'] === (string) $this->options->siteUrl) echo ' active'; ?>" href="<?php echo htmlspecialchars($triNav['url']); ?>" data-name="<?php echo htmlspecialchars($triNav['name']); ?>"><i class="<?php echo htmlspecialchars($triNav['icon'] !== '' ? $triNav['icon'] : tri3_nav_icon($triNav['name'])); ?>"></i><span class="nav-text"><?php echo htmlspecialchars($triNav['name']); ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </nav>

    <?php if (function_exists('tri_widgets_render_list')): ?>
      <?php $triLeftWidgets = tri_widgets_render_list('left'); ?>
      <?php if ($triLeftWidgets !== ''): ?>
      <div class="col-left-widgets tw-side-left">
        <?php echo $triLeftWidgets; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</aside>
<?php endif; ?>
