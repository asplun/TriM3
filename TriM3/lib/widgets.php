<?php
/**
 * TriM3 小组件系统：组件注册表 + 渲染函数
 *
 * 配置存储：typecho_options 表 theme:TriM3 JSON 内 widgetsLeft / widgetsRight
 * 结构：[{ "id": 唯一id, "type": 组件类型, "settings": {字段=>值} }, ...]
 *
 * 管理：admin/widgets.html（拖拽）+ api/widget-api.php（读写）
 * 渲染：sidebar.php（右栏）/ component/nav-left.php（左栏）
 */

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }
/* 引入番剧/豆瓣数据层（小组件渲染时需要） */
if (file_exists(__DIR__ . '/bangumi.php')) { require_once __DIR__ . '/bangumi.php'; }
if (file_exists(__DIR__ . '/douban.php')) { require_once __DIR__ . '/douban.php'; }

/* ============================================================
 * 组件注册表（后台据此生成设置表单）
 * ============================================================ */
function tri_widgets_defs(): array
{
    static $triDefs = null;
    if ($triDefs !== null) { return $triDefs; }

    $triDefs = array(
        'weather' => array(
            'name' => '本周天气', 'icon' => 'ri-calendar-check-line', 'group' => '信息',
            'desc' => '当前天气 + 7 日预报（文字版，兼容和风新版 API，支持访客 IP 自动定位）',
            'fields' => array(
                array('key' => 'city', 'label' => '城市', 'type' => 'text', 'default' => '', 'ph' => '留空则按访客 IP 自动定位（需填高德 Key）'),
                array('key' => 'apikey', 'label' => '和风 API Key', 'type' => 'text', 'default' => '', 'ph' => '新版控制台创建的 API Key'),
                array('key' => 'host', 'label' => 'API Host', 'type' => 'text', 'default' => '', 'ph' => '新版必填：控制台-设置里的 API Host，如 xyz.qweatherapi.com；留空走旧版 devapi'),
                array('key' => 'amapkey', 'label' => '高德 Key（IP 定位）', 'type' => 'text', 'default' => '', 'ph' => '城市留空时用于定位访客所在城市，高德开放平台申请'),
                array('key' => 'locid', 'label' => 'Location ID', 'type' => 'text', 'default' => '', 'ph' => '可选，如 101160801；留空按城市解析'),
            ),
        ),
        'todayweather' => array(
            'name' => '今日天气', 'icon' => 'ri-sun-line', 'group' => '信息',
            'desc' => '仅当天天气：温度、体感、湿度、风、日出日落（兼容和风新版 API，支持 IP 自动定位）',
            'fields' => array(
                array('key' => 'city', 'label' => '城市', 'type' => 'text', 'default' => '', 'ph' => '留空则按访客 IP 自动定位（需填高德 Key）'),
                array('key' => 'apikey', 'label' => '和风 API Key', 'type' => 'text', 'default' => '', 'ph' => '新版控制台创建的 API Key'),
                array('key' => 'host', 'label' => 'API Host', 'type' => 'text', 'default' => '', 'ph' => '新版必填：控制台-设置里的 API Host，如 xyz.qweatherapi.com；留空走旧版 devapi'),
                array('key' => 'amapkey', 'label' => '高德 Key（IP 定位）', 'type' => 'text', 'default' => '', 'ph' => '城市留空时用于定位访客所在城市，高德开放平台申请'),
                array('key' => 'locid', 'label' => 'Location ID', 'type' => 'text', 'default' => '', 'ph' => '可选，如 101160801；留空按城市解析'),
            ),
        ),
        'views' => array(
            'name' => '访客浏览', 'icon' => 'ri-eye-2-line', 'group' => '信息',
            'desc' => '今日访客与总访问量统计',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '访客统计'),
            ),
        ),
        'bloginfo' => array(
            'name' => '博客信息', 'icon' => 'ri-information-line', 'group' => '信息',
            'desc' => '站点名称、简介、Logo 与订阅入口（可自定义；留空依次读取主题后台设置 → Typecho 站点设置）',
            'fields' => array(
                array('key' => 'title', 'label' => '名称', 'type' => 'text', 'default' => '', 'ph' => '留空使用主题 SEO 站点名称'),
                array('key' => 'logo', 'label' => 'Logo 图片地址', 'type' => 'text', 'default' => '', 'ph' => '留空使用站点 Logo / 头像'),
                array('key' => 'desc', 'label' => '简介', 'type' => 'text', 'default' => '', 'ph' => '留空使用主题 SEO 描述 / 个人简介'),
                array('key' => 'rss', 'label' => '订阅链接', 'type' => 'text', 'default' => '', 'ph' => '留空使用站点 RSS 地址'),
            ),
        ),
        'stats' => array(
            'name' => '站点统计', 'icon' => 'ri-bar-chart-line', 'group' => '信息',
            'desc' => '文章 / 评论 / 分类 / 标签数量',
            'fields' => array(),
        ),
        'social' => array(
            'name' => '博主社交', 'icon' => 'ri-share-line', 'group' => '信息',
            'desc' => '复用后台「② 社交链接」设置展示图标',
            'fields' => array(
                array('key' => 'size', 'label' => '图标大小', 'type' => 'radio', 'default' => 'm', 'options' => array('s' => '小', 'm' => '中', 'l' => '大')),
            ),
        ),
        'hitokoto' => array(
            'name' => '一言', 'icon' => 'ri-double-quotes-l', 'group' => '信息',
            'desc' => '随机一句话（hitokoto.cn）',
            'fields' => array(
                array('key' => 'cat', 'label' => '分类', 'type' => 'text', 'default' => '', 'ph' => '留空随机，如 a=动画 / b=漫画 / c=游戏 / d=文学'),
            ),
        ),
        'poem' => array(
            'name' => '今日诗词', 'icon' => 'ri-quill-pen-line', 'group' => '信息',
            'desc' => '每日一诗（jinrishici.com）',
            'fields' => array(),
        ),
        'mikecard' => array(
            'name' => '特色文本卡', 'icon' => 'ri-bubble-chart-line', 'group' => '文本',
            'desc' => '大标题 + 副文 + 装饰的风格卡片',
            'fields' => array(
                array('key' => 'emoji', 'label' => '装饰图标', 'type' => 'text', 'default' => '✧', 'ph' => 'Emoji 或任意字符'),
                array('key' => 'title', 'label' => '大标题', 'type' => 'text', 'default' => '你好，世界'),
                array('key' => 'sub', 'label' => '副文', 'type' => 'text', 'default' => '欢迎来到我的小站'),
                array('key' => 'tone', 'label' => '色调', 'type' => 'select', 'default' => 'primary', 'options' => array('primary' => '主色', 'violet' => '紫', 'blue' => '蓝', 'green' => '绿', 'orange' => '橙', 'pink' => '粉')),
            ),
        ),
        'textbox' => array(
            'name' => '文本框', 'icon' => 'ri-text', 'group' => '文本',
            'desc' => '符合主题风格的自定义文本（支持 HTML）',
            'fields' => array(
                array('key' => 'title', 'label' => '标题（可空）', 'type' => 'text', 'default' => ''),
                array('key' => 'html', 'label' => '内容（支持 HTML）', 'type' => 'textarea', 'default' => '', 'rows' => 5),
            ),
        ),
        'bangumi' => array(
            'name' => '番剧追踪', 'icon' => 'ri-tv-2-line', 'group' => '内容',
            'desc' => '从 B 站同步追番进度，可自定义前台展示哪些分类',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '正在追番'),
                array('key' => 'cats', 'label' => '展示分类', 'type' => 'multicheck', 'default' => '2', 'options' => array('1' => '想看', '2' => '在看', '3' => '看过')),
                array('key' => 'count', 'label' => '展示数量', 'type' => 'number', 'default' => '6', 'ph' => '最多显示几部番剧'),
                array('key' => 'showcover', 'label' => '显示封面', 'type' => 'radio', 'default' => '1', 'options' => array('1' => '是', '0' => '否')),
            ),
        ),
        'douban' => array(
            'name' => '豆瓣观影', 'icon' => 'ri-book-open-line', 'group' => '内容',
            'desc' => '豆瓣书影音展示，可自定义前台展示哪些类型',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '最近看过'),
                array('key' => 'type', 'label' => '展示类型', 'type' => 'multicheck', 'default' => 'movie', 'options' => array('book' => '书', 'movie' => '电影', 'music' => '音乐')),
                array('key' => 'count', 'label' => '每种类型展示数量', 'type' => 'number', 'default' => '4', 'ph' => '每种类型最多显示几条'),
                array('key' => 'showcover', 'label' => '显示封面', 'type' => 'radio', 'default' => '1', 'options' => array('1' => '是', '0' => '否')),
            ),
        ),
        'html' => array(
            'name' => '自定义 HTML', 'icon' => 'ri-code-s-slash-line', 'group' => '文本',
            'desc' => '完全自定义的 HTML 代码',
            'fields' => array(
                array('key' => 'code', 'label' => 'HTML 代码', 'type' => 'textarea', 'default' => '', 'rows' => 6, 'ph' => '直接输出，可放 iframe / 广告 / 脚本'),
            ),
        ),
        'posts' => array(
            'name' => '文章展示', 'icon' => 'ri-article-line', 'group' => '博客',
            'desc' => '最新文章列表',
            'fields' => array(
                array('key' => 'num', 'label' => '数量', 'type' => 'number', 'default' => '6'),
                array('key' => 'showdate', 'label' => '显示日期', 'type' => 'radio', 'default' => '1', 'options' => array('1' => '显示', '0' => '隐藏')),
            ),
        ),
        'comments' => array(
            'name' => '最新评论', 'icon' => 'ri-chat-3-line', 'group' => '博客',
            'desc' => '最新评论列表',
            'fields' => array(
                array('key' => 'num', 'label' => '数量', 'type' => 'number', 'default' => '6'),
            ),
        ),
        'archive' => array(
            'name' => '归档', 'icon' => 'ri-archive-line', 'group' => '博客',
            'desc' => '按月归档列表',
            'fields' => array(
                array('key' => 'num', 'label' => '显示最近月数', 'type' => 'number', 'default' => '12'),
            ),
        ),
        'tags' => array(
            'name' => '标签云', 'icon' => 'ri-price-tag-3-line', 'group' => '博客',
            'desc' => '标签云',
            'fields' => array(
                array('key' => 'num', 'label' => '数量', 'type' => 'number', 'default' => '20'),
            ),
        ),
        'menu' => array(
            'name' => '导航菜单', 'icon' => 'ri-menu-line', 'group' => '博客',
            'desc' => '自定义链接菜单',
            'fields' => array(
                array('key' => 'items', 'label' => '链接（每行：名称|网址）', 'type' => 'textarea', 'default' => '', 'rows' => 5, 'ph' => '首页|/&#10;关于|/about.html'),
            ),
        ),
        'album' => array(
            'name' => '相册组件', 'icon' => 'ri-gallery-line', 'group' => '多媒体',
            'desc' => '展示指定相册的图片',
            'fields' => array(
                array('key' => 'aid', 'label' => '相册 ID', 'type' => 'number', 'default' => '0', 'ph' => '相册管理中的数字 ID'),
                array('key' => 'max', 'label' => '显示张数', 'type' => 'number', 'default' => '9'),
            ),
        ),
        'image' => array(
            'name' => '单张图片', 'icon' => 'ri-image-line', 'group' => '多媒体',
            'desc' => '一张图片（可带链接）',
            'fields' => array(
                array('key' => 'url', 'label' => '图片地址', 'type' => 'text', 'default' => ''),
                array('key' => 'link', 'label' => '点击跳转（可空）', 'type' => 'text', 'default' => ''),
                array('key' => 'alt', 'label' => '描述', 'type' => 'text', 'default' => ''),
            ),
        ),
        'media' => array(
            'name' => '视频 / 音频', 'icon' => 'ri-play-circle-line', 'group' => '多媒体',
            'desc' => '嵌入视频或音频',
            'fields' => array(
                array('key' => 'embed', 'label' => 'iframe 嵌入代码', 'type' => 'textarea', 'default' => '', 'rows' => 3, 'ph' => 'B 站 / YouTube 分享嵌入代码'),
                array('key' => 'audio', 'label' => '音频文件地址', 'type' => 'text', 'default' => '', 'ph' => 'mp3/ogg，填此项时优先显示播放器'),
            ),
        ),
        'topic' => array(
            'name' => '专题推荐', 'icon' => 'ri-star-smile-line', 'group' => '博客',
            'desc' => '推荐专题 / 站点链接',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '专题推荐'),
                array('key' => 'items', 'label' => '链接（每行：名称|网址|描述）', 'type' => 'textarea', 'default' => '', 'rows' => 5, 'ph' => '博客|https://…|记录与分享'),
            ),
        ),
        'countdown' => array(
            'name' => '自定义倒计时', 'icon' => 'ri-hourglass-line', 'group' => '时钟',
            'desc' => '倒计时到指定日期',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '距离目标还有'),
                array('key' => 'target', 'label' => '目标时间', 'type' => 'text', 'default' => '2026-12-31 00:00:00', 'ph' => '格式：2026-12-31 00:00:00'),
            ),
        ),
        'flipclock' => array(
            'name' => '翻页时钟', 'icon' => 'ri-time-line', 'group' => '时钟',
            'desc' => '翻页效果的数字时钟',
            'fields' => array(
                array('key' => 'sec', 'label' => '显示秒', 'type' => 'radio', 'default' => '1', 'options' => array('1' => '显示', '0' => '隐藏')),
            ),
        ),
        'calendar' => array(
            'name' => '简约日历', 'icon' => 'ri-calendar-line', 'group' => '时钟',
            'desc' => '当月日历，今日高亮',
            'fields' => array(),
        ),
        'dayprogress' => array(
            'name' => '日进度', 'icon' => 'ri-sun-line', 'group' => '时钟',
            'desc' => '今天已过百分比',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '今日进度'),
            ),
        ),
        'weekprogress' => array(
            'name' => '周进度', 'icon' => 'ri-calendar-check-line', 'group' => '时钟',
            'desc' => '本周已过百分比',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '本周进度'),
            ),
        ),
        'yearprogress' => array(
            'name' => '年进度', 'icon' => 'ri-calendar-2-line', 'group' => '时钟',
            'desc' => '今年已过百分比',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '今年进度'),
            ),
        ),
        'customprogress' => array(
            'name' => '自定义进度', 'icon' => 'ri-percent-line', 'group' => '时钟',
            'desc' => '手动设置进度百分比',
            'fields' => array(
                array('key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '自定义进度'),
                array('key' => 'pct', 'label' => '进度百分比（0-100）', 'type' => 'number', 'default' => '50'),
                array('key' => 'label', 'label' => '进度文字', 'type' => 'text', 'default' => '', 'ph' => '如：项目完成度'),
            ),
        ),
    );
    return $triDefs;
}

/* ============================================================
 * 读取/写入配置
 * ============================================================ */
/* 读取组件配置：优先独立 options 行（widgetsLeft/Right），回退 theme:TriM3 JSON 行（旧数据） */
function tri_widgets_opt_arr(string $key): array
{
    /* 1. Helper::options 属性（Typecho 加载全部 options 行；可能是 JSON 字符串或数组） */
    $o = Helper::options();
    $v = isset($o->{$key}) ? $o->{$key} : null;
    if (is_array($v)) {
        return array_values($v);
    }
    $s = is_string($v) ? trim($v) : '';
    if ($s !== '') {
        $d = json_decode($s, true);
        if (is_array($d)) { return array_values($d); }
    }
    try {
        $db = Typecho\Db::get();
        /* 2. 独立行直查 */
        $row = $db->fetchRow($db->select()->from('table.options')
            ->where('name = ?', $key)->where('user = ?', 0)->limit(1));
        if ($row && trim((string) $row['value']) !== '') {
            $d = json_decode(trim((string) $row['value']), true);
            if (is_array($d)) { return array_values($d); }
        }
        /* 3. 回退 theme:TriM3 JSON 行（旧版存储） */
        $jRow = $db->fetchRow($db->select()->from('table.options')
            ->where('name = ?', 'theme:TriM3')->where('user = ?', 0)->limit(1));
        if ($jRow) {
            $j = json_decode((string) $jRow['value'], true);
            if (is_array($j) && isset($j[$key]) && is_array($j[$key])) {
                return array_values($j[$key]);
            }
        }
    } catch (Throwable $e) { /* ignore */ }
    return array();
}

function tri_widgets_get(): array
{
    static $triCfg = null;
    if ($triCfg !== null) { return $triCfg; }
    $triCfg = array(
        'left' => tri_widgets_opt_arr('widgetsLeft'),
        'right' => tri_widgets_opt_arr('widgetsRight'),
    );
    return $triCfg;
}

function tri_widgets_has_ip_weather(): bool
{
    $triCfg = tri_widgets_get();
    foreach (['left', 'right'] as $triSide) {
        foreach ($triCfg[$triSide] ?? [] as $triWidget) {
            if (!is_array($triWidget) || !in_array((string) ($triWidget['type'] ?? ''), ['weather', 'todayweather'], true)) {
                continue;
            }
            $triSettings = is_array($triWidget['settings'] ?? null) ? $triWidget['settings'] : [];
            if (trim((string) ($triSettings['city'] ?? '')) === ''
                && trim((string) ($triSettings['amapkey'] ?? '')) !== '') {
                return true;
            }
        }
    }
    return false;
}

function tri_widgets_ip_weather_variant(): string
{
    static $variant = null;
    if ($variant !== null) {
        return $variant;
    }

    $variant = '';
    $triCfg = tri_widgets_get();
    $payload = [];
    foreach (['left', 'right'] as $triSide) {
        foreach ($triCfg[$triSide] ?? [] as $triWidget) {
            if (!is_array($triWidget) || !in_array((string) ($triWidget['type'] ?? ''), ['weather', 'todayweather'], true)) {
                continue;
            }
            $triSettings = is_array($triWidget['settings'] ?? null) ? $triWidget['settings'] : [];
            if (trim((string) ($triSettings['city'] ?? '')) !== ''
                || trim((string) ($triSettings['amapkey'] ?? '')) === '') {
                continue;
            }

            $triCity = tri_widgets_amap_city((string) $triSettings['amapkey']);
            if ($triCity === '') {
                $variant = 'unknown';
                return $variant;
            }
            $payload[] = ['settings' => $triSettings, 'city' => $triCity];
        }
    }

    if (!empty($payload)) {
        $variant = md5((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    return $variant;
}

function tri_widgets_render_list(string $triSide): string
{
    $triCfg = tri_widgets_get();
    $triList = isset($triCfg[$triSide]) && is_array($triCfg[$triSide]) ? $triCfg[$triSide] : array();
    $triOut = '';
    $triRenderers = tri_widgets_renderers();
    foreach ($triList as $triW) {
        if (!is_array($triW)) { continue; }
        $triType = (string) ($triW['type'] ?? '');
        if (!isset($triRenderers[$triType])) { continue; }
        $triSettings = isset($triW['settings']) && is_array($triW['settings']) ? $triW['settings'] : array();
        $triHtml = '';
        try {
            $triHtml = call_user_func($triRenderers[$triType], $triSettings, $triSide);
        } catch (Throwable $e) {
            $triHtml = ''; /* 单个组件异常不拖垮整页渲染 */
        }
        if ($triHtml === '') { continue; }
        $triOut .= '<div class="tw-item tri3-reveal" data-tw-type="' . htmlspecialchars($triType) . '">' . $triHtml . '</div>' . "\n";
    }
    return $triOut;
}

/* ============================================================
 * 渲染函数
 * ============================================================ */
function tri_widgets_renderers(): array
{
    static $triR = null;
    if ($triR !== null) { return $triR; }

    $triS = function (array $s, string $k, string $d = '') { return isset($s[$k]) && trim((string) $s[$k]) !== '' ? trim((string) $s[$k]) : $d; };
    $triN = function (array $s, string $k, int $d) { return isset($s[$k]) && is_numeric($s[$k]) ? (int) $s[$k] : $d; };

    $triR = array();

    /* ---- 今日天气 + 7 日预报（支持访客 IP 定位） ---- */
    $triR['weather'] = function (array $s) use ($triS, $triN) {
        $triCity = $triS($s, 'city', '');
        $triKey = $triS($s, 'apikey', '');
        $triHost = $triS($s, 'host', '');
        $triAmap = $triS($s, 'amapkey', '');
        if ($triKey === '') {
            return tri_widgets_shell('weather', 'ri-calendar-check-line', '本周天气', '<p class="tw-empty">请在小组件设置中填写和风 API Key</p>');
        }
        if ($triCity === '') {
            if ($triAmap === '') {
                return tri_widgets_shell('weather', 'ri-calendar-check-line', '本周天气', '<p class="tw-empty">城市留空时请在设置中填写高德 Key 以自动定位</p>');
            }
            $triCity = tri_widgets_amap_city($triAmap);
            if ($triCity === '') {
                return tri_widgets_shell('weather', 'ri-calendar-check-line', '本周天气', '<p class="tw-empty">自动定位失败，请检查高德 Key 或在设置中填写城市</p>');
            }
        }
        $triLoc = $triS($s, 'locid', '');
        $triData = tri_widgets_weather($triKey, $triLoc !== '' ? $triLoc : $triCity, $triHost);
        if ($triData === null) {
            $triMsg = '天气数据获取失败：请检查和风 API Key 与 API Host 是否正确';
            return tri_widgets_shell('weather', 'ri-calendar-check-line', '本周天气', '<p class="tw-empty">' . $triMsg . '</p>');
        }
        $triNow = $triData['now'];
        $triDays = $triData['days'];
        $triIcon = tri_widgets_weather_icon($triNow['icon']);
        $triHtml = '<div class="tw-weather-now"><span class="tw-weather-icon">' . tri_widgets_wa_html($triNow['icon']) . '</span>'
            . '<span class="tw-weather-temp">' . $triNow['temp'] . '°</span>'
            . '<span class="tw-weather-text">' . htmlspecialchars($triNow['text']) . ' · ' . htmlspecialchars($triCity) . '</span></div>'
            . '<ul class="tw-weather-days">';
        foreach ($triDays as $triD) {
            $triHtml .= '<li><span class="tw-wd-day">' . $triD['week'] . '</span>'
                . '<span class="tw-wd-icon">' . tri_widgets_wa_html($triD['icon']) . '</span>'
                . '<span class="tw-wd-text">' . htmlspecialchars($triD['text']) . '</span>'
                . '<span class="tw-wd-temp">' . $triD['tempMin'] . '~' . $triD['tempMax'] . '°</span></li>';
        }
        $triHtml .= '</ul>';
        return tri_widgets_shell('weather', 'ri-calendar-check-line', '本周天气 · ' . htmlspecialchars($triCity), $triHtml);
    };

    /* ---- 今日天气（仅当天，丰富信息） ---- */
    $triR['todayweather'] = function (array $s) use ($triS, $triN) {
        $triCity = $triS($s, 'city', '');
        $triKey = $triS($s, 'apikey', '');
        $triHost = $triS($s, 'host', '');
        $triAmap = $triS($s, 'amapkey', '');
        if ($triKey === '') {
            return tri_widgets_shell('todayweather', 'ri-sun-line', '今日天气', '<p class="tw-empty">请在小组件设置中填写和风 API Key</p>');
        }
        if ($triCity === '') {
            if ($triAmap === '') {
                return tri_widgets_shell('todayweather', 'ri-sun-line', '今日天气', '<p class="tw-empty">城市留空时请在设置中填写高德 Key 以自动定位</p>');
            }
            $triCity = tri_widgets_amap_city($triAmap);
            if ($triCity === '') {
                return tri_widgets_shell('todayweather', 'ri-sun-line', '今日天气', '<p class="tw-empty">自动定位失败，请检查高德 Key 或在设置中填写城市</p>');
            }
        }
        $triLoc = $triS($s, 'locid', '');
        $triData = tri_widgets_weather($triKey, $triLoc !== '' ? $triLoc : $triCity, $triHost);
        if ($triData === null) {
            return tri_widgets_shell('todayweather', 'ri-sun-line', '今日天气', '<p class="tw-empty">天气数据获取失败：请检查和风 API Key 与 API Host 是否正确</p>');
        }
        $triNow = $triData['now'];
        $triToday = isset($triData['days'][0]) ? $triData['days'][0] : array();
        $triIcon = tri_widgets_weather_icon($triNow['icon']);
        $triRange = '';
        if (!empty($triToday['tempMin']) || !empty($triToday['tempMax'])) {
            $triRange = $triToday['tempMin'] . '~' . $triToday['tempMax'] . '°';
        }
        $triHtml = '<div class="tw-today">'
            . '<div class="tw-today-main">'
            . '<span class="tw-today-icon">' . tri_widgets_wa_html($triNow['icon']) . '</span>'
            . '<div class="tw-today-temp"><b>' . $triNow['temp'] . '°</b>'
            . '<span>' . htmlspecialchars($triNow['text']) . ' · ' . htmlspecialchars($triCity) . '</span></div>'
            . '</div>'
            . '<ul class="tw-today-info">'
            . '<li><i class="ri-temp-cold-line"></i><span><em>体感</em>' . ($triNow['feels'] !== '' ? $triNow['feels'] . '°' : '--') . '</span></li>'
            . '<li><i class="ri-drop-line"></i><span><em>湿度</em>' . ($triNow['hum'] !== '' ? $triNow['hum'] . '%' : '--') . '</span></li>'
            . '<li><i class="ri-windy-line"></i><span><em>风力</em>' . ($triNow['wind'] !== '' ? $triNow['wind'] . '级' : '--') . '</span></li>'
            . ($triRange !== '' ? '<li><i class="ri-arrow-up-down-line"></i><span><em>气温</em>' . $triRange . '</span></li>' : '')
            . (isset($triToday['sunrise']) && $triToday['sunrise'] !== '' ? '<li><i class="ri-sun-line"></i><span><em>日出</em>' . $triToday['sunrise'] . '</span></li>' : '')
            . (isset($triToday['sunset']) && $triToday['sunset'] !== '' ? '<li><i class="ri-moon-line"></i><span><em>日落</em>' . $triToday['sunset'] . '</span></li>' : '')
            . '</ul></div>';
        return tri_widgets_shell('todayweather', 'ri-sun-line', '今日天气 · ' . htmlspecialchars($triCity), $triHtml);
    };

    /* ---- 访客浏览 ---- */
    $triR['views'] = function (array $s) use ($triS) {
        $triV = tri_widgets_views();
        $triHtml = '<div class="tw-views"><div class="tw-views-item"><span class="tw-views-num">' . $triV['today'] . '</span><span class="tw-views-label">今日访问</span></div>'
            . '<div class="tw-views-item"><span class="tw-views-num">' . $triV['total'] . '</span><span class="tw-views-label">总访问</span></div></div>';
        return tri_widgets_shell('views', 'ri-eye-2-line', $triS($s, 'title', '访客统计'), $triHtml);
    };

    /* ---- 博客信息（可自定义，留空读站点设置） ---- */
    $triR['bloginfo'] = function (array $s) use ($triS) {
        $triTitle = $triS($s, 'title', '');
        if ($triTitle === '') { $triTitle = trim((string) tri_opt('seoSiteName', '')); }
        if ($triTitle === '') { $triTitle = (string) Helper::options()->title; }
        $triDesc = $triS($s, 'desc', '');
        if ($triDesc === '') { $triDesc = trim((string) tri_opt('seoSiteDesc', '')); }
        if ($triDesc === '') { $triDesc = trim((string) tri_opt('authorBio', '')); }
        if ($triDesc === '') { $triDesc = (string) Helper::options()->description; }
        $triRss = $triS($s, 'rss', (string) Helper::options()->feedUrl);
        $triAv = tri3_avatar_url(64);
        $triLogo = $triS($s, 'logo', trim((string) tri_opt('logoUrl', '')));
        $triImg = $triLogo !== '' ? $triLogo : $triAv;
        $triHtml = '<div class="tw-bloginfo"><div class="tw-bloginfo-head">'
            . ($triImg !== '' ? '<span class="tw-bloginfo-logo"><img src="' . htmlspecialchars($triImg) . '" alt="" loading="lazy" decoding="async"></span>' : '<span class="tw-bloginfo-logo tw-bloginfo-fallback">' . htmlspecialchars(mb_substr($triTitle, 0, 1)) . '</span>')
            . '<div class="tw-bloginfo-meta"><b>' . htmlspecialchars($triTitle) . '</b><span>' . htmlspecialchars($triDesc) . '</span></div></div>'
            . ($triRss !== '' ? '<a class="tw-bloginfo-rss" href="' . htmlspecialchars($triRss) . '" target="_blank" rel="noopener"><i class="ri-rss-line"></i>订阅更新</a>' : '')
            . '</div>';
        return $triHtml;
    };

    /* ---- 站点统计 ---- */
    $triR['stats'] = function () {
        $triStat = Typecho_Widget::widget('Widget_Stat');
        $triTags = 0;
        try { $triTags = (int) Typecho_Widget::widget('Widget_Metas_Tag_Cloud', 'sort=count')->count; } catch (Throwable $e) { $triTags = 0; }
        $triHtml = '<div class="tw-stats">'
            . '<div class="tw-stat"><b>' . $triStat->publishedPostsNum . '</b><span>文章</span></div>'
            . '<div class="tw-stat"><b>' . $triStat->publishedCommentsNum . '</b><span>评论</span></div>'
            . '<div class="tw-stat"><b>' . $triStat->categoriesNum . '</b><span>分类</span></div>'
            . '<div class="tw-stat"><b>' . $triTags . '</b><span>标签</span></div></div>';
        return tri_widgets_shell('stats', 'ri-bar-chart-line', '站点统计', $triHtml);
    };

    /* ---- 博主社交 ---- */
    $triR['social'] = function (array $s) use ($triS) {
        $triSize = $triS($s, 'size', 'm');
        $triLinks = array();
        $triG = trim((string) tri_opt('github', ''));
        $triW = trim((string) tri_opt('weibo', ''));
        $triX = trim((string) tri_opt('wechat', ''));
        $triE = trim((string) tri_opt('email', ''));
        if ($triG !== '') { $triLinks[] = array('ri-github-line', $triG, 'GitHub'); }
        if ($triW !== '') { $triLinks[] = array('ri-weibo-line', $triW, '微博'); }
        if ($triX !== '') { $triLinks[] = array('ri-wechat-line', $triX, '微信'); }
        if ($triE !== '') { $triLinks[] = array('ri-mail-line', 'mailto:' . $triE, '邮箱'); }
        if (tri_switch('rssEnable', '1')) { $triLinks[] = array('ri-rss-line', (string) Helper::options()->feedUrl, 'RSS'); }
        if (empty($triLinks)) {
            return tri_widgets_shell('social', 'ri-share-line', '博主社交', '<p class="tw-empty">请在后台「② 社交链接」填写账号</p>');
        }
        $triHtml = '<div class="tw-social tw-social-' . $triSize . '">';
        foreach ($triLinks as $triL) {
            $triHtml .= '<a href="' . htmlspecialchars($triL[1]) . '" target="_blank" rel="noopener" title="' . htmlspecialchars($triL[2]) . '"><i class="' . $triL[0] . '"></i></a>';
        }
        $triHtml .= '</div>';
        return tri_widgets_shell('social', 'ri-share-line', '博主社交', $triHtml);
    };

    /* ---- 一言 ---- */
    $triR['hitokoto'] = function (array $s) use ($triS) {
        $triCat = $triS($s, 'cat', '');
        $triHtml = '<div class="tw-quote" data-tw-quote="hitokoto" data-tw-cat="' . htmlspecialchars($triCat) . '"><p class="tw-quote-text"></p><span class="tw-quote-src"></span></div>';
        return tri_widgets_shell('hitokoto', 'ri-double-quotes-l', '一言', $triHtml);
    };

    /* ---- 今日诗词 ---- */
    $triR['poem'] = function () {
        $triHtml = '<div class="tw-quote" data-tw-quote="poem"><p class="tw-quote-text"></p><span class="tw-quote-src"></span></div>';
        return tri_widgets_shell('poem', 'ri-quill-pen-line', '今日诗词', $triHtml);
    };

    /* ---- 特色文本卡 ---- */
    $triR['mikecard'] = function (array $s) use ($triS) {
        $triHtml = '<div class="tw-mike tw-mike-' . $triS($s, 'tone', 'primary') . '">'
            . '<span class="tw-mike-emoji">' . htmlspecialchars($triS($s, 'emoji', '✧')) . '</span>'
            . '<b class="tw-mike-title">' . htmlspecialchars($triS($s, 'title', '你好，世界')) . '</b>'
            . '<p class="tw-mike-sub">' . htmlspecialchars($triS($s, 'sub', '欢迎来到我的小站')) . '</p></div>';
        return $triHtml;
    };

    /* ---- 文本框 ---- */
    $triR['textbox'] = function (array $s) use ($triS) {
        $triTitle = $triS($s, 'title', '');
        $triHtml = isset($s['html']) ? (string) $s['html'] : '';
        if ($triTitle !== '') {
            return tri_widgets_shell('textbox', 'ri-text', $triTitle, $triHtml);
        }
        return '<div class="tw-textbox">' . $triHtml . '</div>';
    };

    /* ---- 自定义 HTML ---- */
    $triR['html'] = function (array $s) {
        $code = isset($s['code']) ? (string) $s['code'] : '';
        $code = function_exists('tri3_sign_content_imgs') ? tri3_sign_content_imgs($code) : $code;
        return $code !== '' ? '<div class="tw-html">' . $code . '</div>' : '';
    };

    /* ---- 文章展示 ---- */
    $triR['posts'] = function (array $s) use ($triS, $triN) {
        $triNum = $triN($s, 'num', 6);
        $triShow = $triS($s, 'showdate', '1') !== '0';
        ob_start();
        $triRecent = Typecho_Widget::widget('Widget_Contents_Post_Recent', 'pageSize=' . max(1, min(20, $triNum)));
        if ($triRecent->have()) {
            echo '<ul class="tw-list">';
            while ($triRecent->next()) {
                echo '<li><a href="' . $triRecent->permalink . '">' . $triRecent->title;
                if ($triShow) { echo '<span class="tw-list-date">' . $triRecent->date('m-d') . '</span>'; }
                echo '</a></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="tw-empty">暂无文章</p>';
        }
        $triBody = ob_get_clean();
        return tri_widgets_shell('posts', 'ri-article-line', '最新文章', $triBody);
    };

    /* ---- 最新评论 ---- */
    $triR['comments'] = function (array $s) use ($triN) {
        $triNum = $triN($s, 'num', 6);
        /* 说说入口 URL（首页菜单中 type=moment 或链接含 moment 的项），供说说评论跳转 */
        $triMomentsUrl = '';
        if (function_exists('tri3_menu_items')) {
            foreach (tri3_menu_items() as $triMi) {
                if (($triMi['url'] ?? '') === '') { continue; }
                $triMHrefPath = parse_url((string) $triMi['url'], PHP_URL_PATH) ?? '';
                $triIsMoment = (($triMi['type'] ?? '') === 'moment')
                    || (($triMi['type'] ?? '') === 'link' && strpos(basename(rtrim($triMHrefPath, '/')), 'moment') !== false);
                if ($triIsMoment) {
                    $triMomentsUrl = (string) $triMi['url'];
                    break;
                }
            }
        }
        ob_start();
        $triC = Typecho_Widget::widget('Widget_Comments_Recent', 'pageSize=' . max(1, min(20, $triNum)));
        if ($triC->have()) {
            echo '<ul class="tw-list tw-list-comments">';
            while ($triC->next()) {
                $triCAv = tri3_gravatar_url((string) $triC->mail, 32);
                /* 评论所属内容：post/page 走文章 permalink；moment（说说）走说说入口 + 锚点 */
                $triHref = '';
                $triCType = '';
                try {
                    $triParent = $triC->parentContent;
                    $triCType = (string) $triParent->type;
                    if (in_array($triCType, array('post', 'page'), true)) {
                        $triHref = (string) $triParent->permalink;
                    }
                } catch (\Throwable $e) {
                    $triCType = '';
                }
                if ($triHref === '') {
                    $triHref = $triMomentsUrl !== '' ? $triMomentsUrl : (string) Helper::options()->siteUrl;
                }
                /* 说说评论：附带 mom_cid 参数，说说页据此自动打开该说说评论弹窗并定位 */
                if ($triCType === 'moment') {
                    $triHref .= (strpos($triHref, '?') === false ? '?' : '&') . 'mom_cid=' . (int) $triC->cid;
                }
                $triHref .= '#comment-' . (int) $triC->coid;
                echo '<li><a href="' . htmlspecialchars($triHref) . '"><span class="tw-c-avatar">'
                    . ($triCAv !== '' ? '<img src="' . htmlspecialchars($triCAv) . '" alt="" loading="lazy" decoding="async">' : '<i class="ri-user-line"></i>')
                    . '</span><span class="tw-c-body"><b>' . htmlspecialchars($triC->author) . '</b><span class="tw-c-text">' . htmlspecialchars(trim(mb_substr(strip_tags((string) $triC->content), 0, 30))) . '</span></span></a></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="tw-empty">暂无评论</p>';
        }
        $triBody = ob_get_clean();
        return tri_widgets_shell('comments', 'ri-chat-3-line', '最新评论', $triBody);
    };

    /* ---- 归档：月份折叠列表（点击展开，懒加载） ---- */
    $triR['archive'] = function (array $s) use ($triN) {
        $triNum = $triN($s, 'num', 6);
        ob_start();
        $triPosts = Typecho_Widget::widget('Widget_Contents_Post_Recent', 'pageSize=' . max(1, min(80, $triNum * 6)));
        if ($triPosts->have()) {
            $triGroups = array();
            while ($triPosts->next()) {
                $triTs = (int) $triPosts->created;
                $triYm = date('Y-m', $triTs);
                if (!isset($triGroups[$triYm])) { $triGroups[$triYm] = array('label' => date('Y年m月', $triTs), 'n' => 0); }
                $triGroups[$triYm]['n']++;
            }
            echo '<ul class="tw-list tw-archive">';
            $triI = 0;
            foreach ($triGroups as $triYm => $triG) {
                if ($triI++ >= $triNum) { break; }
                echo '<li class="tw-archive-month" data-tw-ym="' . $triYm . '">'
                    . '<b class="tw-archive-month-title"><i class="ri-arrow-right-s-line"></i>' . $triG['label'] . '</b>'
                    . '<span class="tw-archive-count">' . $triG['n'] . ' 篇</span>'
                    . '<ul class="tw-archive-posts" hidden></ul></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="tw-empty">暂无归档</p>';
        }
        $triBody = ob_get_clean();
        return tri_widgets_shell('archive', 'ri-archive-line', '归档', $triBody);
    };

    /* ---- 标签云 ---- */
    $triR['tags'] = function (array $s) use ($triN) {
        $triNum = $triN($s, 'num', 20);
        ob_start();
        $triTags = Typecho_Widget::widget('Widget_Metas_Tag_Cloud', array('sort' => 'count', 'ignoreZeroCount' => 1, 'limit' => max(1, min(50, $triNum))));
        if ($triTags->have()) {
            echo '<div class="tw-tags">';
            while ($triTags->next()) {
                echo '<a class="chip" href="' . $triTags->permalink . '">' . $triTags->name . '</a>';
            }
            echo '</div>';
        } else {
            echo '<p class="tw-empty">暂无标签</p>';
        }
        $triBody = ob_get_clean();
        return tri_widgets_shell('tags', 'ri-price-tag-3-line', '标签', $triBody);
    };

    /* ---- 导航菜单 ---- */
    $triR['menu'] = function (array $s) {
        $triLines = isset($s['items']) ? preg_split('/\r\n|\r|\n/', (string) $s['items']) : array();
        $triHtml = '<ul class="tw-menu">';
        $triAny = false;
        foreach ($triLines as $triLine) {
            $triLine = trim($triLine);
            if ($triLine === '') { continue; }
            $triParts = explode('|', $triLine);
            $triName = trim($triParts[0] ?? '');
            $triUrl = trim($triParts[1] ?? '#');
            if ($triName === '') { continue; }
            $triAny = true;
            $triHtml .= '<li><a href="' . htmlspecialchars($triUrl) . '" target="_blank" rel="noopener"><i class="ri-arrow-right-s-line"></i>' . htmlspecialchars($triName) . '</a></li>';
        }
        if (!$triAny) { $triHtml .= '<li class="tw-empty">请填写链接</li>'; }
        $triHtml .= '</ul>';
        return tri_widgets_shell('menu', 'ri-menu-line', '导航菜单', $triHtml);
    };

    /* ---- 相册组件 ---- */
    $triR['album'] = function (array $s) use ($triN) {
        $triAid = $triN($s, 'aid', 0);
        $triMax = max(1, min(30, $triN($s, 'max', 9)));
        if ($triAid <= 0) {
            return tri_widgets_shell('album', 'ri-gallery-line', '相册', '<p class="tw-empty">请填写相册 ID</p>');
        }
        if (!function_exists('tri_album_photos')) {
            require_once __DIR__ . '/album.php';
        }
        $triPhotos = function_exists('tri_album_photos') ? tri_album_photos($triAid) : array();
        if (empty($triPhotos)) {
            return tri_widgets_shell('album', 'ri-gallery-line', '相册', '<p class="tw-empty">相册暂无图片</p>');
        }
        $triPhotos = array_slice($triPhotos, 0, $triMax);
        $triHtml = '<div class="tw-album-grid">';
        foreach ($triPhotos as $triP) {
            $triUrl = (string) ($triP['url'] ?? '');
            if ($triUrl === '') { continue; }
            $triHtml .= '<a href="' . htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($triUrl) : $triUrl) . '" data-fancybox="widget-album-' . $triAid . '" data-caption="' . htmlspecialchars((string) ($triP['title'] ?? '')) . '" rel="noopener"><img src="' . htmlspecialchars(function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triUrl, 600) : $triUrl) . '" alt="" loading="lazy" decoding="async"></a>';
        }
        $triHtml .= '</div>';
        return tri_widgets_shell('album', 'ri-gallery-line', '相册', $triHtml);
    };

    /* ---- 单张图片 ---- */
    $triR['image'] = function (array $s) use ($triS) {
        $triUrl = $triS($s, 'url', '');
        if ($triUrl === '') { return ''; }
        $triLink = $triS($s, 'link', '');
        $triAlt = $triS($s, 'alt', '');
        $triImg = '<img src="' . htmlspecialchars(function_exists('tri3_img_proxy_url') ? tri3_img_proxy_url($triUrl, 600) : $triUrl) . '" alt="' . htmlspecialchars($triAlt) . '" loading="lazy" decoding="async">';
        $triImg = $triLink !== ''
            ? '<a href="' . htmlspecialchars($triLink) . '" target="_blank" rel="noopener">' . $triImg . '</a>'
            : '<a href="' . htmlspecialchars(function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($triUrl) : $triUrl) . '" data-fancybox="widget-image" rel="noopener">' . $triImg . '</a>';
        return '<div class="tw-image">' . $triImg . '</div>';
    };

    /* ---- 视频 / 音频 ---- */
    $triR['media'] = function (array $s) use ($triS) {
        $triAudio = $triS($s, 'audio', '');
        $triEmbed = isset($s['embed']) ? trim((string) $s['embed']) : '';
        if ($triAudio !== '') {
            return '<div class="tw-media"><audio controls preload="none" src="' . htmlspecialchars($triAudio) . '"></audio></div>';
        }
        if ($triEmbed !== '') {
            return '<div class="tw-media tw-media-embed">' . $triEmbed . '</div>';
        }
        return '';
    };

    /* ---- 专题推荐 ---- */
    $triR['topic'] = function (array $s) use ($triS) {
        $triTitle = $triS($s, 'title', '专题推荐');
        $triLines = isset($s['items']) ? preg_split('/\r\n|\r|\n/', (string) $s['items']) : array();
        $triHtml = '<ul class="tw-topic">';
        $triAny = false;
        foreach ($triLines as $triLine) {
            $triLine = trim($triLine);
            if ($triLine === '') { continue; }
            $triParts = explode('|', $triLine);
            $triName = trim($triParts[0] ?? '');
            $triUrl = trim($triParts[1] ?? '#');
            $triDesc = trim($triParts[2] ?? '');
            if ($triName === '') { continue; }
            $triAny = true;
            $triHtml .= '<li><a href="' . htmlspecialchars($triUrl) . '" target="_blank" rel="noopener"><span class="tw-topic-ic"><i class="ri-external-link-line"></i></span><span class="tw-topic-body"><b>' . htmlspecialchars($triName) . '</b>' . ($triDesc !== '' ? '<span>' . htmlspecialchars($triDesc) . '</span>' : '') . '</span></a></li>';
        }
        if (!$triAny) { $triHtml .= '<li class="tw-empty">请填写推荐链接</li>'; }
        $triHtml .= '</ul>';
        return tri_widgets_shell('topic', 'ri-star-smile-line', $triTitle, $triHtml);
    };

    /* ---- 自定义倒计时 ---- */
    $triR['countdown'] = function (array $s) use ($triS) {
        $triTitle = $triS($s, 'title', '距离目标还有');
        $triTarget = $triS($s, 'target', '');
        if ($triTarget === '') {
            return tri_widgets_shell('countdown', 'ri-hourglass-line', $triTitle, '<p class="tw-empty">请在设置中填写目标时间（如 2027-01-01 00:00:00）</p>');
        }
        $triTs = strtotime($triTarget);
        if ($triTs === false && preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/', $triTarget, $triM)) {
            $triTs = strtotime(sprintf('%04d-%02d-%02d', (int) $triM[1], (int) $triM[2], (int) $triM[3]));
        }
        if ($triTs === false) { return tri_widgets_shell('countdown', 'ri-hourglass-line', $triTitle, '<p class="tw-empty">时间格式错误，示例：2027-01-01 00:00:00</p>'); }
        $triHtml = '<div class="tw-countdown" data-tw-target="' . $triTs . '000">'
            . '<div class="tw-cd-nums">'
            . '<span class="tw-cd-cell"><span class="tw-cd-num" data-unit="d">--</span><em>天</em></span>'
            . '<span class="tw-cd-cell"><span class="tw-cd-num" data-unit="h">--</span><em>时</em></span>'
            . '<span class="tw-cd-cell"><span class="tw-cd-num" data-unit="m">--</span><em>分</em></span>'
            . '<span class="tw-cd-cell"><span class="tw-cd-num" data-unit="s">--</span><em>秒</em></span></div></div>';
        return tri_widgets_shell('countdown', 'ri-hourglass-line', $triTitle, $triHtml);
    };

    /* ---- 翻页时钟 ---- */
    $triR['flipclock'] = function (array $s) use ($triS) {
        $triSec = $triS($s, 'sec', '1') === '1';
        $triHtml = '<div class="tw-flipclock" data-tw-sec="' . ($triSec ? '1' : '0') . '">'
            . '<div class="tw-fc" data-fc="h1"><b>0</b><b>0</b></div>'
            . '<div class="tw-fc" data-fc="h2"><b>0</b><b>0</b></div><em>:</em>'
            . '<div class="tw-fc" data-fc="m1"><b>0</b><b>0</b></div>'
            . '<div class="tw-fc" data-fc="m2"><b>0</b><b>0</b></div>'
            . ($triSec ? '<em>:</em><div class="tw-fc" data-fc="s1"><b>0</b><b>0</b></div><div class="tw-fc" data-fc="s2"><b>0</b><b>0</b></div>' : '')
            . '</div>';
        return tri_widgets_shell('flipclock', 'ri-time-line', '时钟', $triHtml);
    };

    /* ---- 简约日历 ---- */
    $triR['calendar'] = function () {
        $triHtml = '<div class="tw-calendar" data-tw-calendar></div>';
        return tri_widgets_shell('calendar', 'ri-calendar-line', '日历', $triHtml);
    };

    /* ---- 日 / 周 / 年进度 ---- */
    $triR['dayprogress'] = function (array $s) use ($triS) {
        return tri_widgets_progress($triS($s, 'title', '今日进度'), 'day');
    };
    $triR['weekprogress'] = function (array $s) use ($triS) {
        return tri_widgets_progress($triS($s, 'title', '本周进度'), 'week');
    };
    $triR['yearprogress'] = function (array $s) use ($triS) {
        return tri_widgets_progress($triS($s, 'title', '今年进度'), 'year');
    };
    /* ---- 自定义进度（手动百分比） ---- */
    $triR['customprogress'] = function (array $s) use ($triS, $triN) {
        $triPct = max(0, min(100, $triN($s, 'pct', 50)));
        $triLabel = $triS($s, 'label', '');
        $triHtml = '<div class="tw-progress"><div class="tw-progress-bar"><i style="width:' . $triPct . '%"></i></div><b>' . $triPct . '%</b></div>';
        if ($triLabel !== '') {
            $triHtml .= '<div class="tw-progress-label">' . htmlspecialchars($triLabel) . '</div>';
        }
        return tri_widgets_shell('customprogress', 'ri-percent-line', $triS($s, 'title', '自定义进度'), $triHtml);
    };

    /* ---- 番剧追踪小组件 ---- */
    $triR['bangumi'] = function (array $s) use ($triS, $triN) {
        $triTitle = $triS($s, 'title', '正在追番');
        $triCats  = $triS($s, 'cats', '2');
        $triCount = max(1, min(20, $triN($s, 'count', 6)));
        $triShowCover = $triS($s, 'showcover', '1') === '1';
        $triCatArr = array_filter(array_map('trim', explode(',', $triCats)));
        if (empty($triCatArr)) { $triCatArr = array('2'); }

        if (!function_exists('tri_bangumi_get')) {
            return tri_widgets_shell('bangumi', 'ri-tv-2-line', $triTitle, '<p class="tw-empty">番剧模块未加载</p>');
        }
        $triData = tri_bangumi_get();
        if (empty($triData['ok'])) {
            return tri_widgets_shell('bangumi', 'ri-tv-2-line', $triTitle, '<p class="tw-empty">' . htmlspecialchars($triData['msg'] ?? '暂无数据') . '</p>');
        }
        $triItems = array();
        foreach ($triData['items'] as $triIt) {
            if (!in_array((string) ($triIt['status'] ?? '2'), $triCatArr)) { continue; }
            $triItems[] = $triIt;
            if (count($triItems) >= $triCount) { break; }
        }
        if (empty($triItems)) {
            return tri_widgets_shell('bangumi', 'ri-tv-2-line', $triTitle, '<p class="tw-empty">该分类暂无番剧</p>');
        }
        $triBody = '<div class="tw-bangumi-list">';
        foreach ($triItems as $triIt) {
            $triCoverSrc = (string) ($triIt['cover'] ?? '');
            /* 缩略图：小组件封面实际只显示 48x64px，走图片代理输出 128px WebP 缩略图，原图保留供灯箱 */
            if ($triCoverSrc !== '' && function_exists('tri3_img_proxy_url')) {
                $triCoverSrc = tri3_img_proxy_url($triCoverSrc, 128, 'auto');
            }
            $triCover = $triShowCover ? '<div class="tw-bangumi-cover"><img src="' . htmlspecialchars($triCoverSrc) . '" loading="lazy" decoding="async" alt=""></div>' : '';
            $triBody .= '<a class="tw-bangumi-item" href="' . htmlspecialchars($triIt['url'] ?? '#') . '" target="_blank" rel="noopener">'
                . $triCover
                . '<div class="tw-bangumi-info"><div class="tw-bangumi-title">' . htmlspecialchars($triIt['title'] ?? '') . '</div>'
                . '<div class="tw-bangumi-meta">' . htmlspecialchars($triIt['new_ep'] ?? '') . '</div></div>'
                . '</a>';
        }
        $triBody .= '</div>';
        return tri_widgets_shell('bangumi', 'ri-tv-2-line', $triTitle, $triBody);
    };

    /* ---- 豆瓣观影小组件 ---- */
    $triR['douban'] = function (array $s) use ($triS, $triN) {
        $triTitle = $triS($s, 'title', '最近看过');
        $triTypes = $triS($s, 'type', 'movie');
        $triCount = max(1, min(20, $triN($s, 'count', 4)));
        $triShowCover = $triS($s, 'showcover', '1') === '1';
        $triTypeArr = array_filter(array_map('trim', explode(',', $triTypes)));
        if (empty($triTypeArr)) { $triTypeArr = array('movie'); }

        if (!function_exists('tri_douban_get')) {
            return tri_widgets_shell('douban', 'ri-book-open-line', $triTitle, '<p class="tw-empty">豆瓣模块未加载</p>');
        }
        $triTypeNames = array('book' => '读过的书', 'movie' => '看过的电影', 'music' => '听过的音乐');
        $triBody = '';
        $triHas = false;
        foreach ($triTypeArr as $triT) {
            if (!isset($triTypeNames[$triT])) { continue; }
            $triData = tri_douban_get($triT);
            if (empty($triData['ok']) || empty($triData['items'])) { continue; }
            $triHas = true;
            $triItems = array_slice($triData['items'], 0, $triCount);
            $triBody .= '<div class="tw-douban-group"><div class="tw-douban-label">' . htmlspecialchars($triTypeNames[$triT]) . '</div><div class="tw-douban-list">';
            foreach ($triItems as $triIt) {
                $triCoverSrc = (string) ($triIt['cover'] ?? '');
                /* 缩略图：小组件封面实际只显示 48x64px，走图片代理输出 128px WebP 缩略图 */
                if ($triCoverSrc !== '' && function_exists('tri3_img_proxy_url')) {
                    $triCoverSrc = tri3_img_proxy_url($triCoverSrc, 128, 'auto');
                }
                $triCover = $triShowCover ? '<div class="tw-douban-cover"><img src="' . htmlspecialchars($triCoverSrc) . '" loading="lazy" decoding="async" alt=""></div>' : '';
                $triBody .= '<a class="tw-douban-item" href="' . htmlspecialchars($triIt['url'] ?? '#') . '" target="_blank" rel="noopener">'
                    . $triCover
                    . '<div class="tw-douban-info"><div class="tw-douban-title">' . htmlspecialchars($triIt['title'] ?? '') . '</div>'
                    . '<div class="tw-douban-meta">' . htmlspecialchars($triIt['date'] ?? '') . '</div></div>'
                    . '</a>';
            }
            $triBody .= '</div></div>';
        }
        if (!$triHas) {
            return tri_widgets_shell('douban', 'ri-book-open-line', $triTitle, '<p class="tw-empty">暂无豆瓣数据</p>');
        }
        return tri_widgets_shell('douban', 'ri-book-open-line', $triTitle, $triBody);
    };
    return $triR;
}

/* ============================================================
 * 工具函数
 * ============================================================ */
/* ============================================================
 * v1.2.24 延迟刷新队列
 * 外部 API 一律不在渲染路径里等待：页面直接返回已有缓存（多旧都先用），
 * 真正的联网刷新排到响应发出之后执行。
 * ============================================================ */
function tri_widgets_defer($triKey, callable $triFn)
{
    if (!isset($GLOBALS['tri_defer_queue']) || !is_array($GLOBALS['tri_defer_queue'])) {
        $GLOBALS['tri_defer_queue'] = array();
    }
    $GLOBALS['tri_defer_queue'][$triKey] = $triFn;
    if (empty($GLOBALS['tri_defer_armed'])) {
        $GLOBALS['tri_defer_armed'] = true;
        register_shutdown_function(function () {
            if (empty($GLOBALS['tri_defer_queue'])) { return; }
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            @ignore_user_abort(true);
            @set_time_limit(30);
            foreach ($GLOBALS['tri_defer_queue'] as $triFn) {
                try { $triFn(); } catch (Throwable $triE) { }
            }
            $GLOBALS['tri_defer_queue'] = array();
        });
    }
}

function tri_widgets_shell(string $triType, string $triIcon, string $triTitle, string $triBody): string
{
    return '<div class="tw-card"><div class="tw-card-head"><i class="' . $triIcon . '"></i><span>' . $triTitle . '</span></div>'
        . '<div class="tw-card-body">' . $triBody . '</div></div>';
}

function tri_widgets_progress(string $triTitle, string $triKind): string
{
    return '<div class="tw-card"><div class="tw-card-head"><i class="ri-progress-3-line"></i><span>' . htmlspecialchars($triTitle) . '</span></div>'
        . '<div class="tw-card-body"><div class="tw-progress" data-tw-progress="' . $triKind . '">'
        . '<div class="tw-progress-bar"><i></i></div><b>0%</b></div></div></div>';
}

/* 天气：和风 API（兼容新版 API Host + X-QW-Api-Key + gzip，与旧版 devapi） + 缓存 */
/* 渲染用：只读已有缓存，绝不联网；过期/缺失就把刷新交给延迟队列 */
function tri_widgets_weather(string $triKey, string $triLoc, string $triHost = ''): ?array
{
    $triFile = __DIR__ . '/../cache/weather/' . md5($triKey . '|' . $triHost . '|' . $triLoc) . '.json';
    $triRedisKey = 'weather-' . md5($triKey . '|' . $triHost . '|' . $triLoc);
    $triRedisData = tri_redis_get($triRedisKey, 'weather');
    if (is_array($triRedisData) && !empty($triRedisData['now'])) {
        return $triRedisData;
    }

    $triC = is_file($triFile) ? json_decode(@file_get_contents($triFile), true) : null;
    $triHas = is_array($triC) && !empty($triC['now']);
    if ($triHas && (time() - filemtime($triFile)) < 2700) {
        tri_redis_set($triRedisKey, $triC, 'weather', 2700);
        return $triC;
    }
    tri_widgets_defer('weather:' . $triFile, function () use ($triKey, $triLoc, $triHost) {
        $triData = tri_widgets_weather_refresh($triKey, $triLoc, $triHost);
        if (is_array($triData)) {
            tri_redis_set('weather-' . md5($triKey . '|' . $triHost . '|' . $triLoc), $triData, 'weather', 2700);
        }
    });
    return $triHas ? $triC : null;
}

/* 真正联网取数：只在延迟队列里执行 */
function tri_widgets_weather_refresh(string $triKey, string $triLoc, string $triHost = ''): ?array
{
    $triDir = __DIR__ . '/../cache/weather';
    if (!is_dir($triDir)) { @mkdir($triDir, 0755, true); }
    $triFile = $triDir . '/' . md5($triKey . '|' . $triHost . '|' . $triLoc) . '.json';

    /* 新版：API Host 含 qweatherapi.com（控制台生成）；旧版：空 host 或 devapi.qweather.com */
    $triHost = trim($triHost);
    $triIsNew = $triHost !== '' && strpos($triHost, 'qweatherapi.com') !== false;
    $triGeoHost = $triIsNew ? $triHost : 'geoapi.qweather.com';
    $triApiHost = $triIsNew ? $triHost : 'devapi.qweather.com';

    $triGet = function (string $u, string $triHdr = '') {
        $triCtx = stream_context_create(array(
            'http' => array(
                'timeout' => 2,   /* 外部 API 不得拖慢首字节 */
                'ignore_errors' => true,
                'header' => "User-Agent: TriM3/1.0\r\nAccept: application/json\r\nAccept-Encoding: gzip\r\n" . ($triHdr !== '' ? $triHdr . "\r\n" : ''),
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $triB = @file_get_contents($u, false, $triCtx);
        if ($triB === false) { return ''; }
        if (function_exists('gzdecode')) {
            $triG = @gzdecode($triB);
            if ($triG !== false) { return $triG; }
        }
        return $triB;
    };

    /* 认证：新版请求头 X-QW-Api-Key；旧版 query key= */
    $triAuth = '';
    $triQ = 'key=' . rawurlencode($triKey);
    if ($triIsNew) {
        $triAuth = 'X-QW-Api-Key: ' . $triKey;
        $triQ = '';
    }

    /* 解析 Location ID */
    $triId = $triLoc;
    if (!preg_match('/^\d{9}$/', $triId)) {
        $triGeoPath = $triIsNew ? '/geo/v2/city/lookup' : '/v2/city/lookup';
        $triGeo = json_decode($triGet('https://' . $triGeoHost . $triGeoPath . '?location=' . rawurlencode($triLoc) . ($triQ !== '' ? '&' . $triQ : ''), $triAuth), true);
        if (isset($triGeo['code']) && $triGeo['code'] === '200' && !empty($triGeo['location'][0]['id'])) {
            $triId = (string) $triGeo['location'][0]['id'];
        } else {
            return null;
        }
    }

    $triNow = json_decode($triGet('https://' . $triApiHost . '/v7/weather/now?location=' . $triId . ($triQ !== '' ? '&' . $triQ : ''), $triAuth), true);
    $tri7d = json_decode($triGet('https://' . $triApiHost . '/v7/weather/7d?location=' . $triId . ($triQ !== '' ? '&' . $triQ : ''), $triAuth), true);
    if (!isset($triNow['code']) || $triNow['code'] !== '200' || empty($triNow['now'])) {
        return null;
    }
    $triResult = array(
        'now' => array(
            'temp' => (string) ($triNow['now']['temp'] ?? ''),
            'text' => (string) ($triNow['now']['text'] ?? ''),
            'icon' => (string) ($triNow['now']['icon'] ?? ''),
            'feels' => (string) ($triNow['now']['feelsLike'] ?? ''),
            'hum' => (string) ($triNow['now']['humidity'] ?? ''),
            'wind' => trim((string) ($triNow['now']['windDir'] ?? '') . ' ' . (string) ($triNow['now']['windScale'] ?? '')),
        ),
        'days' => array(),
    );
    if (isset($tri7d['code']) && $tri7d['code'] === '200' && !empty($tri7d['daily'])) {
        $triWeeks = array('周日', '周一', '周二', '周三', '周四', '周五', '周六');
        foreach (array_slice($tri7d['daily'], 0, 7) as $triD) {
            $triTs = strtotime((string) $triD['fxDate']);
            $triResult['days'][] = array(
                'week' => $triTs !== false ? $triWeeks[(int) date('w', $triTs)] : '',
                'text' => (string) ($triD['textDay'] ?? ''),
                'icon' => (string) ($triD['iconDay'] ?? ''),
                'tempMin' => (string) ($triD['tempMin'] ?? ''),
                'tempMax' => (string) ($triD['tempMax'] ?? ''),
                'sunrise' => (string) ($triD['sunrise'] ?? ''),
                'sunset' => (string) ($triD['sunset'] ?? ''),
            );
        }
    }
    tri3_atomic_write($triFile, json_encode($triResult, JSON_UNESCAPED_UNICODE));
    return $triResult;
}

/* 高德 IP 定位：返回访客所在城市（无则空串），按 IP 缓存 30 分钟 */
/* 渲染用：只读已有缓存，绝不联网 */
function tri_widgets_amap_city(string $triKey): string
{
    $triIp = function_exists('tri3_public_client_ip') ? tri3_public_client_ip() : '';
    if ($triIp === '') { return ''; }

    $triRedisKey = 'amap-' . md5($triIp);
    $triRedisCity = tri_redis_get($triRedisKey, 'geo');
    if (is_string($triRedisCity) && $triRedisCity !== '') { return $triRedisCity; }

    $triFile = __DIR__ . '/../cache/amap/' . md5($triIp) . '.json';
    $triC = is_file($triFile) ? json_decode(@file_get_contents($triFile), true) : null;
    $triCity = (is_array($triC) && !empty($triC['city'])) ? (string) $triC['city'] : '';
    if ($triCity !== '' && (time() - filemtime($triFile)) < 2700) {
        tri_redis_set($triRedisKey, $triCity, 'geo', 2700);
        return $triCity;
    }
    tri_widgets_defer('amap:' . $triFile, function () use ($triKey, $triIp, $triFile, $triRedisKey) {
        $triCity = tri_widgets_amap_refresh($triKey, $triIp, $triFile);
        if ($triCity !== '') {
            tri_redis_set($triRedisKey, $triCity, 'geo', 2700);
            tri_redis_invalidate(array('page'), 'weather location refreshed');
        }
    });
    return '';
}

/* 真正联网取数：只在延迟队列里执行 */
function tri_widgets_amap_refresh(string $triKey, string $triIp, string $triFile): string
{
    $triDir = dirname($triFile);
    if (!is_dir($triDir)) { @mkdir($triDir, 0755, true); }

    $triCtx = stream_context_create(array(
        'http' => array('timeout' => 2, 'ignore_errors' => true, 'header' => "User-Agent: TriM3/1.0\r\n"),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $triB = @file_get_contents('https://restapi.amap.com/v3/ip?key=' . rawurlencode($triKey) . '&ip=' . rawurlencode($triIp), false, $triCtx);
    $triJ = $triB !== false ? json_decode($triB, true) : null;
    $triCity = '';
    if (is_array($triJ) && isset($triJ['status']) && (string) $triJ['status'] === '1') {
        $triC = $triJ['city'] ?? '';
        if (is_array($triC)) { $triC = ''; }
        $triC = trim((string) $triC);
        if ($triC === '' || $triC === '[]') {
            $triP = $triJ['province'] ?? '';
            if (is_array($triP)) { $triP = ''; }
            $triC = trim((string) $triP);
        }
        if ($triC !== '' && $triC !== '[]') {
            $triCity = str_replace(array('市辖区', '省直辖'), '', $triC);
        }
    }
    if ($triCity !== '') {
        @file_put_contents($triFile, json_encode(array('city' => $triCity, 'ip' => $triIp), JSON_UNESCAPED_UNICODE));
    }
    return $triCity;
}

/* 和风天气代码 -> 动画类型 */
function tri_widgets_weather_anim(string $triCode): string
{
    $triCode = (string) $triCode;
    if (in_array($triCode, array('100', '900'), true)) { return 'sun'; }
    if (in_array($triCode, array('150', '151', '152', '153'), true)) { return 'moon'; }
    if (in_array($triCode, array('101', '102', '103'), true)) { return 'partly'; }
    if ($triCode === '104') { return 'cloud'; }
    if ((int) $triCode >= 300 && (int) $triCode < 400) { return 'rain'; }
    if ((int) $triCode >= 400 && (int) $triCode < 500) { return 'snow'; }
    if ((int) $triCode >= 500 && (int) $triCode < 507) { return 'fog'; }
    if ((int) $triCode >= 507) { return 'wind'; }
    return 'cloud';
}

/* 天气动画 HTML（纯 CSS 动效） */
function tri_widgets_wa_html(string $triCode): string
{
    $triK = tri_widgets_weather_anim($triCode);
    $triH = '<span class="tw-wa">';
    switch ($triK) {
        case 'sun':
            $triH .= '<i class="tw-wa-sun"></i>';
            break;
        case 'moon':
            $triH .= '<i class="tw-wa-moon"></i><i class="tw-wa-star"></i><i class="tw-wa-star tw-wa-star2"></i>';
            break;
        case 'cloud':
            $triH .= '<i class="tw-wa-cloud"></i><i class="tw-wa-cloud tw-wa-cloud2"></i>';
            break;
        case 'partly':
            $triH .= '<i class="tw-wa-sun tw-wa-sm"></i><i class="tw-wa-cloud tw-wa-cs"></i>';
            break;
        case 'rain':
            $triH .= '<i class="tw-wa-cloud"></i><span class="tw-wa-rain"><i></i><i></i><i></i></span>';
            break;
        case 'snow':
            $triH .= '<i class="tw-wa-cloud"></i><span class="tw-wa-snow"><i></i><i></i><i></i></span>';
            break;
        case 'fog':
            $triH .= '<span class="tw-wa-fog"><i></i><i></i><i></i></span>';
            break;
        case 'wind':
            $triH .= '<span class="tw-wa-wind"><i></i><i></i><i></i></span>';
            break;
        default:
            $triH .= '<i class="tw-wa-cloud"></i>';
    }
    return $triH . '</span>';
}

/* 和风天气代码 -> remixicon */
function tri_widgets_weather_icon(string $triCode): string
{
    $triMap = array(
        '100' => 'ri-sun-line', '101' => 'ri-moon-cloudy-line', '102' => 'ri-cloudy-2-line', '103' => 'ri-sun-cloudy-line',
        '104' => 'ri-cloudy-line', '150' => 'ri-moon-line', '151' => 'ri-moon-cloudy-line', '152' => 'ri-moon-cloudy-line',
        '153' => 'ri-moon-cloudy-line', '300' => 'ri-rainy-line', '301' => 'ri-rainy-line', '302' => 'ri-thunderstorms-line',
        '303' => 'ri-thunderstorms-line', '304' => 'ri-thunderstorms-line', '305' => 'ri-showers-line', '306' => 'ri-showers-line',
        '307' => 'ri-showers-line', '308' => 'ri-heavy-showers-line', '309' => 'ri-drizzle-line', '310' => 'ri-rainy-line',
        '311' => 'ri-showers-line', '312' => 'ri-heavy-showers-line', '313' => 'ri-showers-line', '314' => 'ri-heavy-showers-line',
        '315' => 'ri-rainy-line', '316' => 'ri-rainy-line', '317' => 'ri-rainy-line', '318' => 'ri-rainy-line',
        '350' => 'ri-showers-line', '399' => 'ri-rainy-line', '400' => 'ri-snowy-line', '401' => 'ri-snowy-line',
        '402' => 'ri-snowy-line', '403' => 'ri-snowy-line', '404' => 'ri-snowy-line', '405' => 'ri-snowy-line',
        '406' => 'ri-snowy-line', '407' => 'ri-snowy-line', '408' => 'ri-snowy-line', '409' => 'ri-snowy-line',
        '410' => 'ri-snowy-line', '499' => 'ri-snowy-line', '500' => 'ri-foggy-line', '501' => 'ri-foggy-line',
        '502' => 'ri-foggy-line', '503' => 'ri-foggy-line', '504' => 'ri-foggy-line', '507' => 'ri-windy-line',
        '508' => 'ri-windy-line', '509' => 'ri-windy-line', '510' => 'ri-windy-line', '511' => 'ri-windy-line',
        '512' => 'ri-windy-line', '513' => 'ri-windy-line', '514' => 'ri-windy-line', '515' => 'ri-windy-line',
    );
    return $triMap[$triCode] ?? 'ri-cloudy-line';
}

/* 访客统计：读取（渲染用） */
function tri_widgets_views(): array
{
    $triFile = __DIR__ . '/../cache/views.json';
    $triData = array('today' => 0, 'total' => 0, 'date' => date('Y-m-d'));
    if (is_file($triFile)) {
        /* 共享锁读取，避免撞上写侧 ftruncate 的瞬间读到空文件（前端会闪 0） */
        $triD = null;
        for ($triTry = 0; $triTry < 2; $triTry++) {
            $triFp = @fopen($triFile, 'rb');
            if (!$triFp) { break; }
            @flock($triFp, LOCK_SH);
            $triRaw = (string) stream_get_contents($triFp);
            @flock($triFp, LOCK_UN);
            @fclose($triFp);
            $triD = json_decode($triRaw, true);
            if (is_array($triD)) { break; }
        }
        if (is_array($triD)) { $triData = array_merge($triData, $triD); }
    }
    if ($triData['date'] !== date('Y-m-d')) {
        $triData['today'] = 0;
        $triData['date'] = date('Y-m-d');
    }
    return $triData;
}

/* 访客统计：+1（API 调用，带锁）
 * 前端每次进站都会调本接口，是否 +1 由服务端判定：同一设备（cookie）或同一 IP
 * 在一个自然日内只算一次，今日访问与总访问同时 +1。
 * 旧版靠前端 sessionStorage 去重，标签页/会话被浏览器还原后永远不再上报。
 * 站点在 CDN 后面：真实访客 IP 取 X-Forwarded-For 首段（腾讯云 CDN 透传），
 * 回退 X-Real-IP，再回退 REMOTE_ADDR。 */
function tri_widgets_views_add(): void
{
    $triDayKey = 'tri3_visit_day';
    $triToday = date('Y-m-d');
    $triCookieSeen = isset($_COOKIE[$triDayKey]) && (string) $_COOKIE[$triDayKey] === $triToday;
    /* v1.2.9：统一走 tri3_client_ip()（XFF → X-Real-IP → REMOTE_ADDR），不再各处重复实现 */
    $triIp = function_exists('tri3_client_ip') ? tri3_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $triIpH = $triIp !== '' ? substr(sha1($triIp . '|' . $triToday . '|tri3-views'), 0, 16) : '';
    $triNewUv = false;
    $triFile = __DIR__ . '/../cache/views.json';
    $triDir = dirname($triFile);
    if (!is_dir($triDir)) { @mkdir($triDir, 0755, true); }
    $triFp = @fopen($triFile, 'c+');
    if ($triFp === false) { return; }
    flock($triFp, LOCK_EX);
    $triData = array('today' => 0, 'total' => 0, 'date' => date('Y-m-d'), 'ips' => array());
    $triRaw = stream_get_contents($triFp);
    if ($triRaw !== false && trim($triRaw) !== '') {
        $triD = json_decode($triRaw, true);
        if (is_array($triD)) { $triData = array_merge($triData, $triD); }
    }
    if ($triData['date'] !== $triToday) {
        $triData['today'] = 0;
        $triData['date'] = $triToday;
        $triData['ips'] = array();
    }
    if (!isset($triData['ips']) || !is_array($triData['ips'])) { $triData['ips'] = array(); }
    $triSeen = $triCookieSeen || ($triIpH !== '' && in_array($triIpH, $triData['ips'], true));
    if (!$triSeen) {
        $triData['today']++;
        $triData['total']++;
        if ($triIpH !== '') {
            $triData['ips'][] = $triIpH;
            if (count($triData['ips']) > 20000) { $triData['ips'] = array_slice($triData['ips'], 5000); }
        }
        $triNewUv = true;
    }
    /* 只在真的计入新访客时落盘：views.json 含最多 2 万条 IP 哈希（约 400KB），
       每次浏览都整文件重写是纯浪费，而且会与读侧产生竞争。 */
    if ($triNewUv) {
        ftruncate($triFp, 0);
        rewind($triFp);
        fwrite($triFp, json_encode($triData));
    }
    flock($triFp, LOCK_UN);
    fclose($triFp);
    if ($triNewUv && !headers_sent()) {
        @setcookie($triDayKey, $triToday, array(
            'expires'  => strtotime('tomorrow 04:00'),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
}
