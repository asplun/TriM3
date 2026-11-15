<?php
/**
 * TriM3 主题函数入口
 *
 * 文件结构：
 *   lib/helpers.php  —— 前台工具函数（tri_opt / tri_switch / 头像 / 导航图标 / Banner）
 *   lib/admin.php    —— 后台设置面板（themeConfig，6 Tab 可视化设置）
 *   assets/css/main.min.css —— 前台主样式（Material Design 3）
 *   assets/css/admin.min.css —— 后台面板样式
 *   assets/js/main-v153.min.js —— 前台交互脚本
 *   assets/js/admin.min.js —— 后台面板交互脚本（assets/js/icons.js 为图标库）
 *   component/          —— 组件模板（文章列表 / 左栏导航）
 *
 * @package TriM3
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/* ============================================================
 * 列表每页文章数目（后台「外观设置 → 布局与导航 → 列表每页文章数目」）
 * 覆盖首页 / 分类 / 标签 / 归档 / 搜索 / 作者等所有文章列表分页大小；
 * 填 0 或留空时自动跟随 Typecho「设置→阅读→每页文章数目」。
 * ============================================================ */
function themeInit($archive): void
{
    /* 让 api/img.php 的域名白名单与后台开关（img-hosts.php）保持最新：
       该文件只在内容变化时写盘，这里触发一次即可，顺带覆盖后台改过设置后的下一次访问。 */
    if (function_exists('tri3_img_allow_hosts')) {
        tri3_img_allow_hosts();
    }

    if (function_exists('tri_widgets_ip_weather_variant')) {
        $triRedisVariant = tri_widgets_ip_weather_variant();
        /* 城市未知时不再整页跳过 Redis 页缓存（那样等于让所有非常见 IP 的访客
           都吃不到缓存）。改为不设置 variant，页面按默认城市渲染并正常缓存。 */
        if ($triRedisVariant !== '' && $triRedisVariant !== 'unknown' && !defined('TRI_REDIS_PAGE_VARIANT')) {
            define('TRI_REDIS_PAGE_VARIANT', $triRedisVariant);
        }
    }

    $triPs = (int) tri_opt('pageSize', 0);
    if ($triPs <= 0) {
        return;
    }
    if ($archive->is('index') || $archive->is('category') || $archive->is('tag')
        || $archive->is('search') || $archive->is('author') || $archive->is('archive')) {
        $archive->parameter->pageSize = $triPs;
    }
}

function themeConfigHandle($settings, $isInit): void
{
    if (!$isInit && function_exists('tri_redis_invalidate')) {
        tri_redis_invalidate(
            ['page', 'fusion', 'moments', 'albums', 'remote', 'weather', 'geo', 'comments', 'widgets'],
            'theme settings updated'
        );
    }
}

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/redis-cache.php';
require_once __DIR__ . '/lib/moment.php';
require_once __DIR__ . '/lib/widgets.php';
require_once __DIR__ . '/lib/joe-short.php';
require_once __DIR__ . '/lib/admin.php';

/* ============================================================
 * 后台「写文章/写页面」编辑器：自定义字段勾选「文章悬浮导航」
 *
 * 通过 Typecho 自定义字段机制（fields[tri3_toc]）保存，
 * 前台 post.php 读取 $this->fields->tri3_toc 决定是否渲染目录导航。
 * 挂在 admin/write-post.php 与 admin/write-page.php 的 option 区块。
 * ============================================================ */
function tri3_admin_toc_field($triPost): void
{
    /* 已存在字段值回显（1=开启；未设置默认开启） */
    $triTocOn = '1';
    try {
        if ($triPost && method_exists($triPost, 'getFieldItems')) {
            foreach ($triPost->getFieldItems() as $triF) {
                if (($triF['name'] ?? '') === 'tri3_toc') {
                    $triTocOn = (string) ($triF['str_value'] ?? $triF['value'] ?? '');
                    break;
                }
            }
        }
    } catch (Throwable $e) {}
    if ($triTocOn === '') { $triTocOn = '1'; }
    echo '<section class="typecho-post-option" style="margin-top:12px;">'
        . '<label class="typecho-label" style="display:block;margin-bottom:6px;">文章页悬浮导航</label>'
        . '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">'
        . '<input type="checkbox" name="fields[tri3_toc]" value="1"'
        . ($triTocOn === '1' ? ' checked' : '') . '> 显示文章目录悬浮导航</label>'
        . '<p style="margin:6px 0 0;color:#999;font-size:12px;">开启后在右栏顶部或大框外右侧显示文章目录；手机端隐藏，点击按钮展开</p>'
        . '</section>';
}
if (defined('__TYPECHO_ADMIN__') && !defined('__TRI3_TOC_HOOK__')) {
    define('__TRI3_TOC_HOOK__', 1);
    try {
        \Typecho\Plugin::factory('admin/write-post.php')->option = 'tri3_admin_toc_field';
        \Typecho\Plugin::factory('admin/write-page.php')->option = 'tri3_admin_toc_field';
    } catch (Throwable $e) {}
}

/* ============================================================
 * 后台「写文章/写页面」编辑器：图床上传按钮
 *
 * 后台配置了图床 API 后，编辑器工具栏追加「上传图片」按钮，
 * 选择本地图片（支持多选）→ POST api/album-api.php?do=upload（图床/本机自适应）→
 * 上传完成后图片以缩略图列表展示，点击缩略图把 Markdown ![](url) 插入编辑器光标处。
 * ============================================================ */
function tri3_admin_editor_upload(): void
{
    try {
        /* 图床未配置时也可上传到本机（album-api.php 后端自适应） */
        $triSite = rtrim((string) Helper::options()->siteUrl, '/');
        $triTheme = trim((string) Helper::options()->theme, '/');
        if ($triTheme === '') { $triTheme = 'TriM3'; }
        $triUploadUrl = $triSite . '/usr/themes/' . $triTheme . '/api/album-api.php?do=upload';
    } catch (Throwable $e) {
        return;
    }
    $triJsonUrl = json_encode($triUploadUrl, JSON_UNESCAPED_SLASHES);
    echo <<<TRIHTML
<script>
(function () {
    if (window.__tri3UpBound) { return; }
    window.__tri3UpBound = 1;
    var API = {$triJsonUrl};
    var bar = document.getElementById('wmd-button-bar') || document.querySelector('.wmd-button-row');
    if (!bar) {
        /* Joe 等第三方编辑器替换了原生工具栏：在 textarea 上方注入按钮行 */
        var ta0 = document.getElementById('text');
        if (ta0) {
            bar = document.createElement('div');
            bar.style.cssText = 'text-align:right;margin-bottom:6px;';
            ta0.parentNode.insertBefore(bar, ta0);
        }
    }
    if (!bar) { return; }
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = '上传图片到图床';
    btn.style.cssText = 'display:inline-flex;align-items:center;gap:4px;margin:6px 0 6px 8px;padding:5px 12px;font-size:12px;color:#fff;background:var(--tri-primary,#3366ff);border:0;border-radius:999px;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);';
    btn.onmouseover = function () { btn.style.opacity = '.85'; };
    btn.onmouseout = function () { btn.style.opacity = '1'; };
    bar.appendChild(btn);
    /* 图片列表容器（多图上传后显示缩略图，点击插入编辑器光标处） */
    var upBox = null;
    function ensureUpBox() {
        if (upBox) { return upBox; }
        upBox = document.createElement('div');
        upBox.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 2px;';
        btn.parentNode.insertBefore(upBox, btn.nextSibling);
        return upBox;
    }
    function insImg(url) {
        var ta = document.getElementById('text');
        if (!ta) { return; }
        var v = ta.value, s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
        var tag = '![](' + url + ')';
        ta.value = v.substring(0, s) + tag + v.substring(e);
        ta.selectionStart = ta.selectionEnd = s + tag.length;
        ta.focus();
        if (typeof ta.dispatchEvent === 'function') {
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    function newItem(fname) {
        var it = document.createElement('span');
        it.title = fname || '';
        it.style.cssText = 'position:relative;display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:12px;overflow:hidden;background:rgba(128,128,128,.12);cursor:pointer;flex:0 0 auto;transition:transform .15s ease,box-shadow .15s ease;';
        it.innerHTML = '<i class="ri-loader-4-line" style="font-size:18px;color:#6750a4;animation:triUpRot 1s linear infinite"></i>';
        return it;
    }
    function markDone(it, url) {
        it.innerHTML = '<img src="' + url + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block"><i class="ri-close-circle-fill" style="position:absolute;top:2px;right:2px;font-size:15px;color:#fff;background:rgba(0,0,0,.55);border-radius:50%;padding:1px;opacity:0;transition:opacity .15s ease"></i>';
        it.style.background = 'transparent';
        it.addEventListener('mouseenter', function () {
            var d = it.querySelector('.ri-close-circle-fill');
            if (d) { d.style.opacity = '1'; }
            it.style.transform = 'scale(1.06)';
            it.style.boxShadow = '0 3px 10px rgba(0,0,0,.18)';
        });
        it.addEventListener('mouseleave', function () {
            var d = it.querySelector('.ri-close-circle-fill');
            if (d) { d.style.opacity = '0'; }
            it.style.transform = '';
            it.style.boxShadow = '';
        });
        it.addEventListener('click', function (ev) {
            if (ev.target.classList && ev.target.classList.contains('ri-close-circle-fill')) { it.remove(); return; }
            insImg(url);
            if (window.toast) { toast('已插入编辑器'); }
        });
    }
    var upStyle = document.createElement('style');
    upStyle.textContent = '@keyframes triUpRot{to{transform:rotate(360deg)}}';
    document.head.appendChild(upStyle);
    btn.onclick = function () {
        var inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = 'image/*';
        inp.multiple = true;
        inp.onchange = function () {
            inp.remove();
            var files = Array.prototype.slice.call(inp.files || []);
            if (!files.length) { return; }
            ensureUpBox();
            var ot = btn.textContent;
            btn.disabled = true;
            btn.textContent = '上传中…';
            var i = 0;
            (function next() {
                if (i >= files.length) {
                    btn.disabled = false;
                    btn.textContent = ot;
                    return;
                }
                var f = files[i];
                var idx = i + 1;
                i += 1;
                var fd = new FormData();
                fd.append('file', f);
                btn.textContent = '上传中 ' + idx + '/' + files.length;
                var it = newItem(f.name);
                upBox.appendChild(it);
                fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.ok) {
                            var u = j.url || (j.urls && j.urls[0]) || '';
                            if (!u) { it.remove(); if (window.toast) { toast('上传返回地址为空'); } next(); return; }
                            markDone(it, u);
                            if (window.toast && idx === files.length) { toast('全部上传完成'); }
                        } else {
                            it.remove();
                            if (window.toast) { toast((j && j.msg) || '上传失败'); }
                        }
                        next();
                    })
                    .catch(function () {
                        it.remove();
                        if (window.toast) { toast('上传失败，请检查图床配置'); }
                        next();
                    });
            })();
        };
        document.body.appendChild(inp);
        inp.click();
    };
})();
</script>
TRIHTML;
}
if (defined('__TYPECHO_ADMIN__') && !defined('__TRI3_UP_HOOK__')) {
    define('__TRI3_UP_HOOK__', 1);
    try {
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = 'tri3_admin_editor_upload';
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = 'tri3_admin_editor_upload';
        \Typecho\Plugin::factory('admin/common.php')->bottom = 'tri3_admin_editor_upload';
    } catch (Throwable $e) {}
}

/* ============================================================
 * 主题数据库自检（根治「切换主题/新库后自建表或列缺失」）
 *
 * Typecho 切换主题不会自动执行建表语句。本主题相比原生 Typecho
 * 新增了：
 *   1) typecho_comments 表的 qq 列（评论 QQ 头像 + 邮件通知）
 *   2) albums / photos 相册表
 * 过去依赖各功能「懒加载建表」，遇到旧库 / WordPress 迁移站 /
 * 全新数据库时，评论提交等路径会在首次使用时因缺列直接 500。
 *
 * 这里在主题每次加载（前台 / 后台 / 独立 API 入口）幂等自检：
 * 已初始化后在主题 options 写入 tri3DbInit=1 标记，之后每个请求
 * 仅一次 options 查询即跳过，几乎零开销。自检失败不阻塞页面
 * （原有懒加载建表逻辑仍兜底）。
 * ============================================================ */
function tri3_db_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (!defined('__TYPECHO_ROOT_DIR__') || !class_exists('Typecho_Db')) {
            return;
        }
        if (tri_opt('tri3DbInit', '0') === '1') {
            return;
        }

        $db = Typecho_Db::get();
        if (!$db) {
            return;
        }
        $triRow = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'theme:TriM3'));
        if ($triRow) {
            $triJ = json_decode((string) ($triRow['value'] ?? ''), true);
            if (!is_array($triJ)) {
                $triJ = array();
            }
        } else {
            $triJ = array();
        }

        /* 1) 评论表补 qq 列（幂等 ALTER，已存在则跳过） */
        tri3_ensure_qq_column();

        /* 2) 相册 albums / photos 表（幂等建表） */
        require_once __DIR__ . '/lib/album.php';
        tri_album_ensure_tables();

        /* 3) 写标记，避免之后每请求重复自检 */
        $triJ['tri3DbInit'] = '1';
        $triJson = json_encode($triJ, JSON_UNESCAPED_UNICODE);
        if ($triRow) {
            $db->query($db->update('table.options')->rows(array('value' => $triJson))
                ->where('name = ?', 'theme:TriM3'));
        } else {
            /* 行不存在时 update 影响 0 行 → 标记永远落库不了，每请求都重跑 SHOW COLUMNS / 建表 */
            $db->query($db->insert('table.options')->rows(array(
                'name'  => 'theme:TriM3',
                'user'  => 0,
                'value' => $triJson,
            )));
        }
    } catch (Throwable $e) {
        /* 自检失败不阻塞页面：懒加载建表逻辑继续兜底 */
    }
}
tri3_db_ensure();

require_once __DIR__ . '/lib/imgcache-auto.php';
