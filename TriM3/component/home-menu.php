<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 首页横向菜单（参考 sigge.cn 顶部导航）
 *
 * 行为（与 sigge 一致）：
 *  - 菜单高亮跟随当前页：在首页「首页」加粗；在文章/分类页「文章」加粗
 *  - 点「文章」→ 跳转到文章列表（Pjax 无刷新），菜单自然切换为「首页 + 文章 + 分类列表」
 *  - 点分类 → 进入分类页（Pjax 无刷新）
 *
 * 后台可配置：每行 名称|链接|类型（link 普通链接 / posts 文章分类导航）
 *  posts 项的链接留空时自动指向第一个分类；也可指定（如 page:archives）
 */
$triMenuItems = tri3_menu_items();
if (empty($triMenuItems)) {
    return;
}
/* 过滤解析失败（空链接）的普通项 */
$triMenuItems = array_values(array_filter($triMenuItems, function ($m) {
    return $m['type'] === 'posts' || $m['url'] !== '';
}));
if (empty($triMenuItems)) {
    return;
}
$triMenuCatName = '';
$triPostsUrl = '';
foreach ($triMenuItems as $triMi) {
    if ($triMi['type'] === 'posts') {
        $triMenuCatName = $triMi['name'];
        $triPostsUrl = $triMi['url'];
        break;
    }
}
$triMenuCats = array();
/* 说说分类（独立于文章分类，metas type=moment_cat） */
$triMomCats = array();
if (function_exists('tri_moment_cats')) {
    foreach (tri_moment_cats() as $triMc) {
        $triMomCats[] = [
            'slug'  => (string) $triMc['slug'],
            'name'  => (string) $triMc['name'],
            'count' => (int) $triMc['count'],
        ];
    }
}
/* 说说入口菜单项（type=link 且链接含 moment）：点「说说」→ 菜单切为「首页 + 说说 + 说说分类」 */
$triMomentsName = '';
$triMomentsUrl = '';
foreach ($triMenuItems as $triMi) {
    if ($triMi['url'] === '') { continue; }
    $triMHrefPath = parse_url($triMi['url'], PHP_URL_PATH) ?? '';
    $triMIsMoment = ($triMi['type'] === 'moment')
        || ($triMi['type'] === 'link' && strpos(basename(rtrim($triMHrefPath, '/')), 'moment') !== false);
    if ($triMIsMoment) {
        $triMomentsName = (string) $triMi['name'];
        $triMomentsUrl = (string) $triMi['url'];
        break;
    }
}
/* 后台「首页菜单显示的分类」：复选框勾选（数组）或旧版文本（每行 slug），兼容两种格式；空 = 全部分类
 * 输出顺序遵循后台配置顺序（支持拖拽排序） */
$triMenuCatFilter = tri3_menu_cat_filter();
if ($triMenuCatName !== '') {
    $this->widget('Widget_Metas_Category_List')->to($triMenuCatList);
    if ($triMenuCatList->have()) {
        $triCatMap = array();
        while ($triMenuCatList->next()) {
            $triCSlug = (string) $triMenuCatList->slug;
            if (!empty($triMenuCatFilter) && !in_array($triCSlug, $triMenuCatFilter, true)) {
                continue;
            }
            $triCatMap[$triCSlug] = array(
                'name'  => (string) $triMenuCatList->name,
                'slug'  => $triCSlug,
                'url'   => (string) $triMenuCatList->permalink,
                'count' => (int) $triMenuCatList->count,
            );
        }
        if (!empty($triMenuCatFilter)) {
            /* 按后台配置顺序输出（拖拽后的顺序） */
            foreach ($triMenuCatFilter as $triCSlug) {
                $triCSlug = trim((string) $triCSlug);
                if ($triCSlug !== '' && isset($triCatMap[$triCSlug])) {
                    $triMenuCats[] = $triCatMap[$triCSlug];
                }
            }
            /* 兜底：配置里没有、但存在且勾选的新分类追加到末尾 */
            foreach ($triCatMap as $triCSlug => $triCat) {
                if (!in_array($triCSlug, $triMenuCatFilter, true)) {
                    $triMenuCats[] = $triCat;
                }
            }
        } else {
            $triMenuCats = array_values($triCatMap);
        }
    }
}
/* posts 项链接：配置了用配置；否则优先指向「文章汇总」单页（page-articles.php，slug=blog），分类作兜底 */
if ($triPostsUrl === '') {
    $triArticlesPage = tri3_page_url('blog');
    if ($triArticlesPage !== '') {
        $triPostsUrl = $triArticlesPage;
    } elseif (!empty($triMenuCats)) {
        $triPostsUrl = $triMenuCats[0]['url'];
    }
}
$triCurrent = trim((string) $this->request->getPathInfo(), '/');
/* 当前是否处于文章汇总单页（blog.html）：菜单切换为「首页+文章+分类」，与说说同逻辑 */
$triInArticles = false;
if ($triPostsUrl !== '') {
    $triPostsPath = parse_url($triPostsUrl, PHP_URL_PATH) ?? '';
    $triPostsBase = basename(rtrim($triPostsPath, '/'));
    if ($triPostsBase !== '' && strpos($triCurrent, rtrim($triPostsBase, '.html')) !== false) {
        $triInArticles = true;
    }
}
/* 当前是否处于分类页 或 文章汇总单页 */
$triInCat = $this->is('category') || $triInArticles;
$triCurrentCat = '';
if ($triInCat && isset($this->archive->slug)) {
    $triCurrentCat = (string) $this->archive->slug;
}
/* 当前 URL 是否命中某菜单链接（首页链接命中 / 与 /index.php） */
function tri3_menu_is_active(string $href, string $current): bool
{
    $href = rtrim($href, '/');
    $cur = rtrim($current, '/');
    if ($href === '') {
        return false;
    }
    $hrefPath = parse_url($href, PHP_URL_PATH) ?? '';
    if ($hrefPath === '' || $hrefPath === '/' || $hrefPath === '/index.php' || $hrefPath === '/index.php/') {
        /* 首页：当前路径为空或只有 index.php 时命中（在首页时「首页」高亮） */
        return $cur === '' || $cur === 'index.php' || $cur === 'index.php/';
    }
    if ($cur === '') {
        return false;
    }
    return strpos($cur, $hrefPath) === 0;
}

/* 判断菜单链接是否为站点首页 */
function tri3_menu_is_home(string $href): bool
{
    $href = rtrim($href, '/');
    if ($href === '') {
        return false;
    }
    $hrefPath = parse_url($href, PHP_URL_PATH) ?? '';
    return $hrefPath === '' || $hrefPath === '/' || $hrefPath === '/index.php' || $hrefPath === '/index.php/';
}
/* 首页列表模式（服务端权威值）：随「首页」菜单项输出 data-home-mode，
   navigate 点首页时直接读取，避免依赖当前 DOM 的 home-mode 脚本（非首页/Pjax 瞬态可能缺失） */
$triHomeModeJs = tri_opt('homeListMode', 'original');
if ($triHomeModeJs !== 'fusion' && $triHomeModeJs !== 'moment') {
    $triHomeModeJs = 'posts';
}
?>
<nav class="home-menu" aria-label="首页菜单">
  <!-- 默认态：非分类页显示全部菜单项，当前页高亮 -->
  <div class="home-menu-inner" data-menu-default<?php echo $triInCat ? ' hidden' : ''; ?>>
    <?php foreach ($triMenuItems as $triMi): ?>
      <?php if ($triMi['type'] === 'posts'): ?>
        <?php $triIsActive = $triInCat; ?>
        <a data-no-swup class="home-menu-item is-posts<?php echo $triIsActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($triPostsUrl); ?>" data-posts-link data-tri3-type="page" data-tri3-slug="blog" data-tri3-local>
          <?php echo htmlspecialchars($triMi['name']); ?>
        </a>
      <?php else: ?>
        <?php $triHref = $triMi['url']; ?>
        <?php $triIsActive = tri3_menu_is_active($triHref, $triCurrent); ?>
        <?php
        /* 站内独立页/说说/首页：标记局部切换类型（banner 与个人信息不动，仅下方列表区刷新；
           首页也标记 data-tri3-local，避免被 Pjax 捕获阶段整页加载导致回到顶部） */
        $triLinkData = '';
        $triNoSwup = ' data-no-swup';
        $triHrefPath = parse_url($triHref, PHP_URL_PATH) ?? '';
        $triHrefBase = basename(rtrim($triHrefPath, '/'));
        if (tri3_menu_is_home($triHref)) {
            $triLinkData = ' data-tri3-local data-home-mode="' . $triHomeModeJs . '"';
        } elseif (strpos($triHrefPath, '/category/') === false) {
            if (strpos($triHrefBase, 'moment') !== false) {
                $triLinkData = ' data-tri3-type="moment" data-moments-link data-tri3-local';
            } elseif (strpos($triHrefBase, '.html') !== false) {
                $triHrefSlug = substr($triHrefBase, 0, -5);
                /* 音乐页由 page-music.php 独立模板渲染（播放器），页面本身没有正文内容，
                   不能交给首页列表的局部渲染（Tri3HomeList.navigate），否则只会渲染出空正文。
                   改走 Swup 的局部换页（div.layout 整体替换），保留「不跳转」的体验。 */
                if ($triHrefSlug !== 'music') {
                    $triLinkData = ' data-tri3-type="page" data-tri3-slug="' . htmlspecialchars($triHrefSlug) . '" data-tri3-local';
                } else {
                    $triNoSwup = '';
                }
            }
        }
        ?>
        <a<?php echo $triNoSwup; ?> class="home-menu-item<?php echo $triIsActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($triHref); ?>"<?php echo $triLinkData; ?>><?php echo htmlspecialchars($triMi['name']); ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- 分类态：仅分类页显示「首页 + 文章(高亮) + 分类列表」 -->
  <?php if ($triMenuCatName !== '' && !empty($triMenuCats)): ?>
  <div class="home-menu-inner" data-menu-posts<?php echo $triInCat ? '' : ' hidden'; ?>>
    <?php foreach ($triMenuItems as $triMi): ?>
      <?php if ($triMi['url'] !== '' && tri3_menu_is_home($triMi['url'])): ?>
        <?php $triHref = $triMi['url']; ?>
        <?php $triIsActive = tri3_menu_is_active($triHref, $triCurrent); ?>
        <a data-no-swup class="home-menu-item<?php echo $triIsActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($triHref); ?>" data-tri3-local data-home-mode="<?php echo $triHomeModeJs; ?>"><?php echo htmlspecialchars($triMi['name']); ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <a data-no-swup class="home-menu-item is-active is-posts" href="<?php echo htmlspecialchars($triPostsUrl); ?>" data-posts-link data-tri3-local>
      <?php echo htmlspecialchars($triMenuCatName); ?>
    </a>
    <?php foreach ($triMenuCats as $triC): ?>
      <a data-no-swup class="home-menu-item<?php echo $triInCat && $triCurrentCat !== '' && $triCurrentCat === $triC['slug'] ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($triC['url']); ?>" data-tri3-local><?php echo htmlspecialchars($triC['name']); ?><?php if ($triC['count'] > 0): ?><span class="home-menu-count"><?php echo $triC['count']; ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- 说说分类态：点「说说」→「首页 + 说说(高亮) + 说说分类列表」 -->
  <?php if ($triMomentsName !== '' && !empty($triMomCats)): ?>
  <div class="home-menu-inner" data-menu-moments hidden>
    <?php foreach ($triMenuItems as $triMi): ?>
      <?php if ($triMi['url'] !== '' && tri3_menu_is_home($triMi['url'])): ?>
        <?php $triHref = $triMi['url']; ?>
        <?php $triIsActive = tri3_menu_is_active($triHref, $triCurrent); ?>
        <a data-no-swup class="home-menu-item<?php echo $triIsActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($triHref); ?>" data-tri3-local data-home-mode="<?php echo $triHomeModeJs; ?>"><?php echo htmlspecialchars($triMi['name']); ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <a data-no-swup class="home-menu-item is-active is-posts" href="<?php echo htmlspecialchars($triMomentsUrl); ?>" data-moments-link data-tri3-type="moment" data-tri3-local>
      <?php echo htmlspecialchars($triMomentsName); ?>
    </a>
    <?php foreach ($triMomCats as $triC): ?>
      <a data-no-swup class="home-menu-item" href="<?php echo htmlspecialchars($triMomentsUrl . '?cat=' . urlencode($triC['slug'])); ?>" data-tri3-type="moment" data-tri3-cat="<?php echo htmlspecialchars($triC['slug']); ?>" data-tri3-local>
        <?php echo htmlspecialchars($triC['name']); ?><?php if ($triC['count'] > 0): ?><span class="home-menu-count"><?php echo $triC['count']; ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</nav>
