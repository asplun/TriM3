<?php
/**
 * TriM3 主题函数
 *
 * 后台设置面板（控制台 - 外观 - 设置外观）
 *   采用 Tab 卡片式分组界面：6 个分组 28 项设置，颜色字段自动转为取色器，
 *   保存后由 Typecho 原生机制处理，前台即时生效。
 *
 * @package TriM3
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 菜单/列表型设置值统一解码（navItems / mobileMenu / homeMenu / friends 等）
 *
 * 兼容三种存储形态：
 *   1. JSON 数组字符串  [{"name":"首页",...}] —— 直接返回数组
 *   2. 行格式文本      名称|链接|图标（真实换行）—— 原样返回文本
 *   3. 历史脏数据：曾被 JS JSON.stringify + PHP json_encode 双重转义
 *      （值形如 \\u9996\\u9875|http:\\/\\/...\\r\\n...），整体不是合法 JSON，
 *      逐层按 JSON 字符串字面量解码，直到得到数组或可解析文本。
 *
 * @param mixed $raw 原始设置值
 * @return array|string 数组（JSON 数组）或解码后的行格式文本
 */
function tri3_menu_decode($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $raw;
    }
    $dec = json_decode($raw, true);
    if (is_array($dec)) {
        return $dec;
    }
    /* 历史双重转义：逐层当作 JSON 字符串字面量解码（最多 4 层防止死循环） */
    $cur = $raw;
    for ($i = 0; $i < 4; $i++) {
        $try = json_decode('"' . str_replace('"', '\\"', $cur) . '"', true);
        if (!is_string($try) || $try === $cur) {
            break;
        }
        $cur = $try;
        $dec = json_decode($cur, true);
        if (is_array($dec)) {
            return $dec;
        }
        /* 已解出真实换行（行格式）即收敛 */
        if (strpos($cur, "\n") !== false || strpos($cur, "\r") !== false) {
            break;
        }
    }
    return $cur;
}

/**
 * 列表分页控件（后台 paginationStyle 控制）
 *
 * style = pager    输出「‹ 上一页 / x / y / 下一页 ›」
 * style = loadmore 输出「加载更多」按钮（点击 AJAX 追加下一页，按钮带 data 供 JS 委托）
 *
 * @param int    $page      当前页（从 1 起）
 * @param int    $totalPage 总页数
 * @param string $base      分页链接前缀（如 '?page='），留空自动从 QUERY_STRING 生成
 * @param string $listSel   列表容器选择器（loadmore 追加目标；首页 #tri3-home-list 由 Tri3HomeList 接管时可不传）
 * @param array  $apiParams list-api 片段接口参数（type/cat/slug 等）。传入后控件输出
 *                          data-api 权威地址，AJAX 一律走 list-api，不再抓整页 HTML。
 */
function tri3_list_api_url(array $params = []): string
{
    $base = rtrim((string) Helper::options()->themeUrl, '/') . '/api/list-api.php';
    if (empty($params)) {
        return $base;
    }
    return $base . '?' . http_build_query($params);
}

function tri3_pagination(int $page = 1, int $totalPage = 1, string $base = '', string $listSel = '', array $apiParams = []): void
{
    $style = tri_opt('paginationStyle', 'pager');
    $page = max(1, $page);
    $totalPage = max(1, $totalPage);
    /* 统一数据源：所有“加载更多 / 翻页”AJAX 都打 list-api 片段接口。
       list 参数让服务端在片段里回填同一个容器选择器，链条不断。 */
    if (!empty($apiParams) && $listSel !== '' && !isset($apiParams['list'])) {
        $apiParams['list'] = $listSel;
    }
    if ($base === '') {
        $qs = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
        $qs = preg_replace('/(^|&)page=\d+/', '', $qs);
        $qs = preg_replace('/(^|&)tri3_ajax=\d+/', '', $qs);
        $base = '?' . ($qs !== '' ? $qs . '&' : '') . 'page=';
    }
    $listAttr = $listSel !== '' ? ' data-list="' . htmlspecialchars($listSel, ENT_QUOTES, 'UTF-8') . '"' : '';

    if ($style === 'loadmore') {
        if ($page < $totalPage) {
            $href = $base . ($page + 1);
            $apiUrl = !empty($apiParams)
                ? tri3_list_api_url(array_merge($apiParams, array('page' => $page + 1)))
                : '';
            $apiAttr = $apiUrl !== '' ? ' data-api="' . htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') . '"' : '';
            echo '<div class="tri3-loadmore-wrap">'
                . '<button type="button" class="tri3-loadmore" data-page="' . ($page + 1) . '" data-total="' . $totalPage . '"'
                . ' data-href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
                . $apiAttr
                . ' data-infinite="1"'
                . $listAttr . '>'
                . '<i class="ri-loader-4-line"></i><span>加载更多</span>'
                . '<em>已加载 ' . $page . ' / ' . $totalPage . ' 页</em>'
                . '</button></div>';
        }
        return;
    }

    /* pager：上一页 / 页码 / 下一页（data-list 供 JS 局部无刷新替换列表容器） */
    echo '<nav class="tri3-pager" data-style="pager"' . $listAttr . '>';
    if ($page > 1) {
        $prevApi = !empty($apiParams) ? ' data-api="' . htmlspecialchars(tri3_list_api_url(array_merge($apiParams, array('page' => $page - 1))), ENT_QUOTES, 'UTF-8') . '"' : '';
        echo '<a class="tri3-pager-btn" data-tri3-pg="1" data-page="' . ($page - 1) . '"' . $prevApi . ' href="' . htmlspecialchars($base . ($page - 1), ENT_QUOTES, 'UTF-8') . '"><i class="ri-arrow-left-s-line"></i>上一页</a>';
    } else {
        echo '<span class="tri3-pager-btn is-disabled"><i class="ri-arrow-left-s-line"></i>上一页</span>';
    }
    echo '<span class="tri3-pager-info">' . $page . ' / ' . $totalPage . '</span>';
    if ($page < $totalPage) {
        $nextApi = !empty($apiParams) ? ' data-api="' . htmlspecialchars(tri3_list_api_url(array_merge($apiParams, array('page' => $page + 1))), ENT_QUOTES, 'UTF-8') . '"' : '';
        echo '<a class="tri3-pager-btn" data-tri3-pg="1" data-page="' . ($page + 1) . '"' . $nextApi . ' href="' . htmlspecialchars($base . ($page + 1), ENT_QUOTES, 'UTF-8') . '">下一页<i class="ri-arrow-right-s-line"></i></a>';
    } else {
        echo '<span class="tri3-pager-btn is-disabled">下一页<i class="ri-arrow-right-s-line"></i></span>';
    }
    echo '</nav>';
}

/**
 * 读取主题选项（带默认值兜底，未设置/留空时返回默认值）
 */
function tri_opt(string $key, $default = ''): string
{
    $o = Helper::options();
    if (!isset($o->{$key})) {
        return (string) $default;
    }
    $v = $o->{$key};
    /* 复选框等数组选项转逗号串，避免 Array to string conversion 警告 */
    if (is_array($v)) {
        $v = implode(',', $v);
    }
    if (trim((string) $v) !== '') {
        return trim((string) $v);
    }

    return (string) $default;
}

/**
 * 读取 Radio/开关型选项（返回 true/false）
 */
function tri_switch(string $key, string $default = '1'): bool
{
    return tri_opt($key, $default) === '1';
}

/**
 * 读取复选框型选项（兼容字符串 / 数组 / JSON 数组三种存储形态）
 * 勾选存 'on' / ['on'] / "[\"on\"]"，未勾选存 '' / [] / "[]"
 */
function tri_checked(string $key): bool
{
    $o = Helper::options();
    $v = isset($o->{$key}) ? $o->{$key} : '';
    if (is_array($v)) {
        return in_array('on', $v, true);
    }
    $v = trim((string) $v);
    if ($v === 'on' || $v === '1') {
        return true;
    }
    $arr = json_decode($v, true);
    if (is_array($arr)) {
        return in_array('on', $arr, true);
    }
    return false;
}

/**
 * 读取复选框型选项并返回勾选值数组（Typecho Checkbox 以选项 key 作为提交值，如 doubanTypes 勾选 [book,movie]）
 */
function tri_checked_arr(string $key): array
{
    $o = Helper::options();
    $v = isset($o->{$key}) ? $o->{$key} : '';
    if (is_array($v)) {
        return array_values($v);
    }
    $v = trim((string) $v);
    if ($v === '' || $v === '[]') {
        return array();
    }
    $arr = json_decode($v, true);
    if (is_array($arr)) {
        return array_values($arr);
    }
    return array($v);
}

/**
 * 摘要字数（后台可设，默认 123 字，范围 30-500）
 */
function tri_excerpt_len(): int
{
    $len = (int) tri_opt('excerptLen', 45);
    if ($len < 10) {
        $len = 10;
    }
    if ($len > 200) {
        $len = 200;
    }

    return $len;
}

/**
 * 文章列表摘要：优先读取 excerpt 自定义字段（AI 摘要 / 手填摘要），
 * 无字段时回退 Typecho 原生 excerpt()（<!--more--> 截断）。
 */
function tri3_post_excerpt($item, int $len): string
{
    static $triFieldCache = array();
    try {
        $cid = (int) ($item->cid ?? 0);
        if ($cid > 0 && !array_key_exists($cid, $triFieldCache)) {
            /* ___fields() 每次访问都会查一次库，按 cid 静态缓存避免列表 N+1 查询 */
            $triFieldCache[$cid] = $item->fields;
        }
        $fields = $cid > 0 ? $triFieldCache[$cid] : $item->fields;
        if ($fields) {
            $e = trim((string) ($fields->excerpt ?? ''));
            if ($e !== '') {
                $e = trim((string) preg_replace('/\s+/u', ' ', strip_tags($e)));
                if ($e !== '') {
                    if (function_exists('mb_strlen') && mb_strlen($e, 'UTF-8') > $len) {
                        return mb_substr($e, 0, $len, 'UTF-8') . '…';
                    }
                    return $e;
                }
            }
        }
    } catch (Exception $e) {
        /* 字段读取异常时回退下方原生截断 */
    }
    ob_start();
    $item->excerpt($len, '…');

    return (string) ob_get_clean();
}

/**
 * 融合模式文章卡片摘要字数（后台 fusionExcerpt 可调，默认 123）
 */
function tri_fusion_excerpt_len(): int
{
    $len = (int) tri_opt('fusionExcerpt', 123);
    if ($len < 10) {
        $len = 10;
    }
    if ($len > 500) {
        $len = 500;
    }

    return $len;
}

/**
 * 列表首屏图片优先级：同一请求内前 $n 张走 loading=eager + fetchpriority=high，
 * 其余维持 loading=lazy —— 让首屏卡片图/封面不再排在队尾
 */
function tri3_img_priority_attr(int $n = 2): string
{
    static $seq = 0;
    $seq++;
    return $seq <= $n ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
}

/**
 * v1.2.87 图片按视口放行（配合 assets/js/lazy.js 的队列）
 *  - 前 $n 张（首屏）：直接 src + eager + fetchpriority=high
 *  - 其余：1×1 占位 + data-src/srcset；进入视口下方 300px 内由 JS 按「离视口由近到远」排队发送，
 *    最多 4 张并发（saveData / 2G 时 2 张），一张完成或超时才放行下一张
 *  - $fallback：代理取图失败时给 lazy.js 兜底链用（可空）
 */
require_once __DIR__ . '/img-dims.php';

function tri3_img_lazy_attrs(string $src, string $srcset = '', string $sizes = '', string $fallback = '', int $n = 2): string
{
    static $seq = 0;
    if ($src === '') {
        return '';
    }
    $seq++;
    $triSize = tri3_img_size_attr($src);
    if ($seq <= $n) {
        $attr = 'src="' . htmlspecialchars($src) . '"' . $triSize;
        if ($srcset !== '') {
            $attr .= ' srcset="' . htmlspecialchars($srcset) . '" sizes="' . htmlspecialchars($sizes) . '"';
        }
        return $attr . ' data-tri3-lazy="1" loading="eager" fetchpriority="high" decoding="async"';
    }
    $attr = 'data-tri3-lazy="1" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-src="' . htmlspecialchars($src) . '"' . $triSize;
    if ($srcset !== '') {
        $attr .= ' data-srcset="' . htmlspecialchars($srcset) . '" data-sizes="' . htmlspecialchars($sizes) . '"';
    }
    if ($fallback !== '') {
        $attr .= ' data-fallback="' . htmlspecialchars($fallback) . '"';
    }
    return $attr . ' loading="lazy" decoding="async"';
}

/**
 * 邮箱头像多源（顺序即优先级）：实测冷启动 cn.cravatar.com 约 126ms、
 * weavatar.com 约 155ms、cravatar.cn 约 538ms，故以 cn.cravatar.com 为主源。
 * 前台失败时由 assets/js/lazy.js 里的 AV_HOSTS 依次换源，最后落首字母占位
 * ——两处列表必须同步修改
 */
function tri3_gravatar_hosts(): array
{
    return array('cn.cravatar.com/avatar/', 'weavatar.com/avatar/', 'cravatar.cn/avatar/');
}

/**
 * 通过邮箱生成头像 URL（$idx 取多源列表中的第几个源，供兜底换源使用）
 */
function tri3_gravatar_url(string $mail, int $size = 80, int $idx = 0): string
{
    $mail = strtolower(trim($mail));
    if ($mail === '') {
        return '';
    }
    $hosts = tri3_gravatar_hosts();
    if (!isset($hosts[$idx])) {
        $idx = 0;
    }
    return 'https://' . $hosts[$idx] . md5($mail) . '?d=identicon&s=' . max(16, min(512, $size));
}

/**
 * 博主头像：后台头像优先，未设置时用后台邮箱走 Cravatar 国内源；
 * 邮箱也未设置时回退系统管理员邮箱；再兜底固定默认 hash（保证任何情况都有图）
 */
function tri3_avatar_url(int $size = 120): string
{
    $av = trim((string) tri_opt('avatarUrl', ''));
    if ($av !== '') {
        return $av;
    }

    $mail = trim((string) tri_opt('email', ''));
    if ($mail === '') {
        $mail = tri3_admin_mail();
    }
    if ($mail === '') {
        $mail = 'tri3-default@localhost';
    }

    return tri3_gravatar_url($mail, $size);
}

/**
 * 系统首个管理员邮箱（后台未设置主题邮箱时的回退源）
 */
function tri3_admin_mail(): string
{
    static $mail = null;
    if ($mail !== null) {
        return $mail;
    }
    $mail = '';
    try {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('mail')->from('table.users')->order('uid', Typecho_Db::SORT_ASC)->limit(1));
        if ($row && !empty($row['mail'])) {
            $mail = trim((string) $row['mail']);
        }
    } catch (Exception $e) {
        $mail = '';
    }
    return $mail;
}

/**
 * 读取主题选项原始值（未设置/留空返回空字符串，不套默认值）
 */
function tri_opt_raw(string $key): string
{
    $o = Helper::options();
    if (!isset($o->{$key})) {
        return '';
    }
    $v = $o->{$key};
    if (is_array($v)) {
        $v = implode(',', $v);
    }
    return trim((string) $v);
}

/**
 * 读取「首页菜单显示的分类」配置（兼容复选框数组 / 序列化数组 / 逗号·换行分隔 slug）
 * @return string[]
 */
function tri3_menu_cat_filter(): array
{
    $out = [];
    try {
        $v = Helper::options()->homeMenuCats ?? '';
        if (is_array($v)) {
            /* 复选框提交的数组：['default'] 或 ['default' => '默认分类'] */
            foreach ($v as $k => $vv) {
                $s = is_string($k) ? $k : $vv;
                $s = trim((string) $s);
                if ($s !== '') { $out[] = $s; }
            }
            return $out;
        }
        if (is_string($v) && trim($v) !== '') {
            $u = @unserialize($v);
            if (is_array($u) && !empty($u)) {
                foreach ($u as $k => $vv) {
                    $s = is_string($k) ? $k : $vv;
                    $s = trim((string) $s);
                    if ($s !== '') { $out[] = $s; }
                }
                return $out;
            }
            foreach (preg_split('/[\r\n,，]+/', $v) as $x) {
                $x = trim($x);
                if ($x !== '') { $out[] = $x; }
            }
        }
    } catch (\Throwable $e) {
        /* 忽略异常 */
    }
    return $out;
}

/**
 * HEX -> HSL
 */
function tri3_hex_to_hsl(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        $hex = '6750a4';
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;
    $h = 0.0;
    $s = 0.0;
    if ($d != 0) {
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $r) {
            $h = (($g - $b) / $d + ($g < $b ? 6 : 0));
        } elseif ($max === $g) {
            $h = (($b - $r) / $d + 2);
        } else {
            $h = (($r - $g) / $d + 4);
        }
        $h *= 60;
    }
    return array(fmod($h + 360, 360), $s, $l);
}

/**
 * HSL -> HEX
 */
function tri3_hsl_to_hex(float $h, float $s, float $l): string
{
    $h = fmod(fmod($h, 360) + 360, 360);
    $s = max(0.0, min(1.0, $s));
    $l = max(0.0, min(1.0, $l));
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    if ($h < 60) { $r = $c; $g = $x; $b = 0; }
    elseif ($h < 120) { $r = $x; $g = $c; $b = 0; }
    elseif ($h < 180) { $r = 0; $g = $c; $b = $x; }
    elseif ($h < 240) { $r = 0; $g = $x; $b = $c; }
    elseif ($h < 300) { $r = $x; $b = $c; $g = 0; }
    else { $r = $c; $b = $x; $g = 0; }
    $t2 = function ($v) use ($m) {
        return str_pad(dechex(max(0, min(255, (int) round(($v + $m) * 255)))), 2, '0', STR_PAD_LEFT);
    };
    return '#' . $t2($r) . $t2($g) . $t2($b);
}

/**
 * HEX -> CSS rgb 三元组（"r g b" 供现代 CSS color 语法使用）
 */
function tri3_hex_to_rgb_css(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        $hex = '6750a4';
    }
    return hexdec(substr($hex, 0, 2)) . ' ' . hexdec(substr($hex, 2, 2)) . ' ' . hexdec(substr($hex, 4, 2));
}

/**
 * 从背景色推导主色：背景为主驱动，未显式设置主色时主色/渐变/字体全套跟随背景适配
 */
function tri3_primary_from_bg(string $bg): string
{
    $bh = tri3_hex_to_hsl($bg);
    return tri3_hsl_to_hex($bh[0], max(0.35, min(0.65, $bh[1] + 0.15)), 0.42);
}

/**
 * MD3 色板推导（HSL 近似 Material Color Utilities）：
 * 以主色为 seed 推导 Secondary / Tertiary / Container / On 系列，
 * 以背景色为 surface 推导 Surface / On-Surface / Outline 阶梯与渐变背景色斑。
 */
function tri3_md3_palette(string $primary, string $bg = ''): array
{
    if ($bg === '') {
        $bg = '#f8f5fc';
    }
    $ph = tri3_hex_to_hsl($primary);
    $h = $ph[0];
    $s = $ph[1];
    $l = $ph[2];
    $bh = tri3_hex_to_hsl($bg);
    $cSat = max(0.3, min(0.8, $s * 0.6));
    $key = function ($dh, $sat) use ($h) {
        return tri3_hsl_to_hex($h + $dh, max(0.3, min(0.8, $sat)), 0.42);
    };
    $bl = function ($ll, $sat = null) use ($bh) {
        $ss = $sat === null ? $bh[1] : $sat;
        /* MD3：surface / on-surface 阶梯为中性色（不继承背景色相），
           深色文字与浅色表面都只保留极低饱和度，避免黑字带背景色相（如背景偏绿时文字变绿） */
        if ($ll < 0.55 || $ll > 0.8) { $ss = $ss * 0.12; }
        return tri3_hsl_to_hex($bh[0], max(0.02, min(0.5, $ss)), $ll);
    };
    return array(
        'secondary'            => $key(15, $s * 0.7),
        'secondaryContainer'   => tri3_hsl_to_hex($h + 15, max(0.28, min(0.7, $s * 0.5)), 0.90),
        'onSecondaryContainer' => tri3_hsl_to_hex($h + 15, max(0.3, min(0.75, $s * 0.6)), 0.12),
        'tertiary'             => $key(-15, $s * 0.5),
        'primaryContainer'     => tri3_hsl_to_hex($h, $cSat, 0.88),
        'onPrimaryContainer'   => tri3_hsl_to_hex($h, $cSat, 0.12),
        'onPrimary'            => $l > 0.55 ? '#1d1a22' : '#ffffff',
        'surface'              => $bg,
        'onSurface'            => $bl(0.10),
        'background'           => $bg,
        'onBackground'         => $bl(0.10),
        'surfaceVariant'       => $bl(0.90),
        'onSurfaceVariant'     => $bl(0.35),
        'surfaceLowest'        => $bl(0.99),
        'surfaceLow'           => $bl(0.93),
        'surfaceContainer'     => $bl(0.90),
        'surfaceHigh'          => $bl(0.87),
        'surfaceHighest'       => $bl(0.84),
        'outline'              => $bl(0.48, 0.25),
        'outlineVariant'       => $bl(0.78, 0.20),
        /* 渐变色斑（auto 方案）：主色与背景色双驱动 —— 左上主色系 / 右上背景色系 / 左下主色偏移 / 右下背景偏移，
           无论改主色还是改背景色，渐变都会明显跟随变化 */
        'grad1'                => tri3_hsl_to_hex($h, max(0.38, $cSat * 0.9), 0.85),
        'grad2'                => tri3_hsl_to_hex(fmod($bh[0] + 18, 360), max(0.34, min(0.6, $bh[1] * 0.8)), 0.86),
        'grad3'                => tri3_hsl_to_hex(fmod($h - 28, 360), max(0.32, $cSat * 0.7), 0.87),
        'grad4'                => tri3_hsl_to_hex(fmod($bh[0] + 45, 360), max(0.30, min(0.55, $bh[1] * 0.7)), 0.88),
    );
}

/**
 * 导航项图标自动识别（按名称关键词匹配 Remixicon 类名）
 */
function tri3_nav_icon(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $map = array(
        '首页' => 'ri-home-5-line', 'home' => 'ri-home-5-line', '主页' => 'ri-home-5-line',
        'rss' => 'ri-rss-line', '订阅' => 'ri-rss-line', 'feed' => 'ri-rss-line',
        '关于' => 'ri-information-line', 'about' => 'ri-information-line', '简介' => 'ri-information-line',
        '归档' => 'ri-archive-line', 'archive' => 'ri-archive-line', '文章' => 'ri-article-line',
        '分类' => 'ri-folder-2-line', 'category' => 'ri-folder-2-line', '栏目' => 'ri-folder-2-line',
        '标签' => 'ri-price-tag-line', 'tag' => 'ri-price-tag-line',
        '友链' => 'ri-links-line', 'link' => 'ri-links-line', '朋友' => 'ri-links-line', '链接' => 'ri-links-line',
        '留言' => 'ri-chat-3-line', 'message' => 'ri-chat-3-line', '评论' => 'ri-chat-3-line',
        '相册' => 'ri-image-line', 'album' => 'ri-image-line', '图片' => 'ri-image-line',
        '搜索' => 'ri-search-line', 'search' => 'ri-search-line',
        '音乐' => 'ri-music-2-line', 'music' => 'ri-music-2-line',
        '视频' => 'ri-video-line', 'video' => 'ri-video-line',
        '项目' => 'ri-code-box-line', 'project' => 'ri-code-box-line', '作品' => 'ri-code-box-line',
        '简历' => 'ri-file-user-line', 'resume' => 'ri-file-user-line',
    );
    foreach ($map as $key => $icon) {
        if ($key !== '' && strpos($name, $key) !== false) {
            return $icon;
        }
    }
    return 'ri-link';
}

/**
 * 是否显示左栏（参考 Pix 可自由搭配：左右栏可独立开关/隐藏）
 * 兼容旧版：未配置过 leftSidebarEnable 时默认显示
 */
function tri_show_left(): bool
{
    $v = tri_opt('leftSidebarEnable', '');

    return $v === '' ? true : $v === '1';
}

/**
 * 是否显示右栏（独立开关；兼容旧版 sidebarEnable 字段）
 */
function tri_show_right(): bool
{
    $v = tri_opt('rightSidebarEnable', '');
    if ($v !== '') {
        return $v === '1';
    }

    return tri_switch('sidebarEnable', '1');
}

/**
 * 获取 Banner 图片 URL 列表（数量不限）
 * 优先级：bannerUrls（每行一个 URL） > 旧版 banner1-3Url > 主题自带图
 * 返回数组中元素为 URL 字符串，或数字 1/2/3 表示回退主题自带 banner-N.jpg
 */
function tri_banner_urls(): array
{
    $raw = tri_opt('bannerUrls', '');
    $urls = array_values(array_filter(
        array_map('trim', explode("\n", $raw)),
        static function ($u) {
            return $u !== '';
        }
    ));

    if (!empty($urls)) {
        return $urls;
    }

    $legacy = [];
    for ($i = 1; $i <= 3; $i++) {
        $u = tri_opt('banner' . $i . 'Url', '');
        if ($u !== '') {
            $legacy[] = $u;
        }
    }

    if (!empty($legacy)) {
        return $legacy;
    }

    return [1, 2, 3];
}

/**
 * 获取站点名首字符（用于头像占位，兼容无 mbstring 环境）
 */
function trim3_first_char(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '博';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 1, 'UTF-8');
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

    return $chars[0] ?? '博';
}

/**
 * 独立页面链接（按 slug 查，返回 permalink；不存在返回空）
 */
function tri3_page_url(string $slug): string
{
    $slug = trim($slug);
    if ($slug === '') {
        return '';
    }
    static $map = null;
    static $tplMap = null;
    if ($map === null) {
        $map = array();
        $tplMap = array();
        try {
            $pages = Typecho_Widget::widget('Widget_Contents_Page_List');
            if ($pages->have()) {
                while ($pages->next()) {
                    $map[(string) $pages->slug] = (string) $pages->permalink;
                    $t = trim((string) $pages->template);
                    if ($t !== '' && !isset($tplMap[$t])) {
                        $tplMap[$t] = (string) $pages->permalink;
                    }
                }
            }
        } catch (Exception $e) {
            $map = array();
            $tplMap = array();
        }
    }

    if (isset($map[$slug])) {
        return $map[$slug];
    }
    /* 模板回退：固定单页类型（首页菜单）的 page:slug 解析不到时，
       按主题模板文件自动找到使用该模板的页面，用户无需关心 slug */
    $tplFallback = array(
        'music'   => 'page-music.php',
        'album'   => 'page-albums.php',
        'douban'  => 'page-douban.php',
        'bangumi' => 'page-bangumi.php',
        'archive' => 'archive.php',
        'moment'  => 'page-moment.php',
        'links'   => 'page-links.php',
    );
    $tpl = $tplFallback[$slug] ?? '';
    if ($tpl !== '' && isset($tplMap[$tpl])) {
        return $tplMap[$tpl];
    }
    return '';
}

/**
 * 首页横向菜单项（参考 sigge.cn 顶部导航）
 *
 * 后台配置：每行一个菜单项，格式 名称|链接|类型
 *   类型：link（普通链接，默认） / posts（点击展开文章分类）
 *   链接支持特殊值：
 *     home      -> 站点首页
 *     page:slug -> 独立页面（如 page:moment、page:start-page）
 *     普通 URL   -> 原样使用
 * 留空时默认：首页 说说(若有) 关于(若有) 文章(展开分类)
 *
 * @return array<int, array{name:string, url:string, type:string}>
 */
/**
 * 按 slug 查文章分类链接（供首页菜单「具体分类」类型使用）
 */
function tri3_category_url(string $slug): string
{
    $slug = trim($slug);
    if ($slug === '') {
        return '';
    }
    try {
        $triCat = \Typecho\Widget::widget('Widget_Metas_Category_List');
        if ($triCat->have()) {
            while ($triCat->next()) {
                if ((string) $triCat->slug === $slug) {
                    return (string) $triCat->permalink;
                }
            }
        }
    } catch (\Throwable $e) {
    }
    return '';
}

/**
 * 首页横向菜单（homeMenu）：名称/链接/类型
 */
function tri3_menu_items(): array
{
    $raw = trim((string) tri_opt('homeMenu', ''));
    $items = [];

    if ($raw !== '') {
        /* 可视化编辑器输出 JSON：兼容解析 */
        $triJson = json_decode($raw, true);
        if (is_array($triJson)) {
            foreach ($triJson as $triRow) {
                if (!is_array($triRow)) { continue; }
                $name = trim((string) ($triRow['name'] ?? ''));
                if ($name === '') { continue; }
                $triJType = strtolower(trim((string) ($triRow['type'] ?? '')));
                if (!in_array($triJType, array('posts', 'category', 'home', 'moment', 'album', 'links', 'about', 'articles', 'bangumi', 'douban', 'archive', 'music'), true)) {
                    $triJType = 'link';
                }
                $items[] = array(
                    'name' => $name,
                    'url'  => trim((string) ($triRow['url'] ?? '')),
                    'type' => $triJType,
                );
            }
        } else {
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = explode('|', $line);
                $name = trim($parts[0] ?? '');
                if ($name === '') {
                    continue;
                }
                $url = trim($parts[1] ?? '');
                $type = strtolower(trim($parts[2] ?? ''));
                if (!in_array($type, array('posts', 'category', 'home', 'moment', 'album', 'links', 'about', 'articles', 'bangumi', 'douban', 'archive', 'music'), true)) {
                    $type = 'link';
                }
                $items[] = array('name' => $name, 'url' => $url, 'type' => $type);
            }
        }
    }

    if (empty($items)) {
        $items[] = array('name' => '首页', 'url' => 'home', 'type' => 'link');
        if (tri3_page_url('moment') !== '') {
            $items[] = array('name' => '说说', 'url' => 'page:moment', 'type' => 'link');
        }
        if (tri3_page_url('music') !== '') {
            $items[] = array('name' => '音乐', 'url' => 'page:music', 'type' => 'music');
        }
        if (tri3_page_url('start-page') !== '') {
            $items[] = array('name' => '关于', 'url' => 'page:start-page', 'type' => 'link');
        }
        $items[] = array('name' => '文章', 'url' => '', 'type' => 'posts');
    }

    /* 解析特殊链接值 */
    foreach ($items as &$it) {
        $u = $it['url'];
        if ($u === 'home' || $u === '/') {
            $it['url'] = rtrim((string) Typecho_Widget::widget('Widget_Options')->siteUrl, '/');
        } elseif (strpos($u, 'page:') === 0) {
            $it['url'] = tri3_page_url(substr($u, 5));
        } elseif (strpos($u, 'category:') === 0) {
            $it['url'] = tri3_category_url(substr($u, 9));
        }
    }
    unset($it);

    return $items;
}

/**
 * 解析播放器「歌单链接/ID」为 Meting 参数（server/type/id）
 * 支持：直接 ID（默认网易云歌单）、music.163.com 链接、y.qq.com 链接等
 */

/**
 * 统一解析播放器资源：明确填写的「平台 + 类型 + 资源 ID」
 * 返回 array('server', 'type', 'id')
 */
function tri3_player_source(): array
{
    $rid = trim((string) tri_opt('metingResourceId', ''));
    $server = trim((string) tri_opt('metingPlatform', 'netease'));
    if (!in_array($server, array('netease', 'tencent', 'kugou', 'kuwo'), true)) { $server = 'netease'; }
    $type = trim((string) tri_opt('metingResourceType', 'playlist'));
    if (!in_array($type, array('playlist', 'album', 'artist', 'song'), true)) { $type = 'playlist'; }
    return array('server' => $server, 'type' => $type, 'id' => $rid);
}

/**
 * 说说正文表情渲染：
 *  1) 经典表情文本代码（/微笑 等）→ Emoji
 *  2) 微博表情 [滑稽] 等 → 表情图片
 *  3) 阿鲁 [aru0000] 等 → 表情 gif 图片
 */
function tri3_qq_emoji(string $s): string
{
    static $map = array(
        '/微笑' => '🙂', '/大笑' => '😄', '/呲牙' => '😁', '/调皮' => '😜',
        '/可爱' => '🥰', '/害羞' => '😊', '/亲亲' => '😘', '/流泪' => '😭',
        '/大哭' => '😢', '/难过' => '😞', '/困' => '😪', '/惊讶' => '😲',
        '/酷' => '😎', '/汗' => '😓', '/抓狂' => '🤯', '/吐' => '🤮',
        '/鄙视' => '🙄', '/奋斗' => '💪', '/鼓掌' => '👏', '/示爱' => '💕',
        '/得意' => '😏', '/衰' => '🥶',
    );
    $s = strtr($s, $map);
    /* 微博表情（贴吧图源） */
    static $wb = array(
        '[滑稽]' => 'huaji', '[捂脸]' => 'wuzuixiao', '[笑哭]' => 'xiaoku', '[微笑]' => 'xiaoyan',
        '[开心]' => 'taikaixin', '[太开心]' => 'haha', '[泪]' => 'lei', '[生气]' => 'nu',
        '[惊讶]' => 'jingya', '[疑问]' => 'yiwen', '[黑线]' => 'heixian', '[汗]' => 'han',
        '[害羞]' => 'xiaohonglian', '[委屈]' => 'weiqu', '[无奈]' => 'mianqiang', '[二哈]' => 'what',
        '[坏笑]' => 'hehe', '[抠鼻]' => 'bishi', '[喷]' => 'pen', '[吐]' => 'tu',
        '[阴险]' => 'yinxian', '[惊哭]' => 'jingku', '[OK]' => 'ok', '[点赞]' => 'damuzhi',
        '[鼓掌]' => 'zhenbang', '[胜利]' => 'shengli', '[睡觉]' => 'shuijiao', '[玫瑰]' => 'meigui',
        '[爱心]' => 'aixin', '[蛋糕]' => 'dangao', '[礼物]' => 'liwu', '[彩虹]' => 'caihong',
        '[太阳]' => 'taiyang', '[音乐]' => 'yinyue', '[星星月亮]' => 'xingxingyueliang', '[赞]' => 'a',
    );
    foreach ($wb as $code => $file) {
        if (strpos($s, $code) !== false) {
            $s = str_replace($code, '<img class="tri-em" src="https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/' . $file . '.png" alt="' . trim($code, '[]') . '" loading="lazy">', $s);
        }
    }
    /* 阿鲁 [aru0000]-[aru0029]（大小写不敏感） */
    $s = preg_replace_callback('/\[aru(\d{4})\]/i', function ($m) {
        return '<img class="tri-em" src="https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/aru/aru' . strtolower($m[1]) . '.gif" alt="aru" loading="lazy">';
    }, $s);
    return $s;
}

/**
 * 同源 CSRF 防护（写操作专用）：
 *  优先校验 Origin（浏览器强制携带，含隐私模式 / 跨站 POST 表单必带），
 *  缺失时回退校验 Referer；两者都缺失则拒绝。
 * 仅用于 POST 写操作；GET 读操作不得调用（直接访问无来源标识）。
 */
function tri3_csrf_ok(): bool
{
    $site = trim((string) Helper::options()->siteUrl);
    if ($site === '') {
        return false;
    }
    $siteParts = parse_url($site);
    if (!isset($siteParts['scheme'], $siteParts['host'])) {
        return false;
    }
    $expected = strtolower((string) $siteParts['scheme']) . '://' . strtolower((string) $siteParts['host']);
    if (isset($siteParts['port'])) {
        $expected .= ':' . (int) $siteParts['port'];
    }

    $triOrigin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
    if ($triOrigin !== '') {
        $originParts = parse_url($triOrigin);
        if (!isset($originParts['scheme'], $originParts['host'])) {
            return false;
        }
        $actual = strtolower((string) $originParts['scheme']) . '://' . strtolower((string) $originParts['host']);
        if (isset($originParts['port'])) {
            $actual .= ':' . (int) $originParts['port'];
        }
        return $actual === $expected;
    }

    $triRef = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
    if ($triRef !== '') {
        $refParts = parse_url($triRef);
        if (!isset($refParts['scheme'], $refParts['host'])) {
            return false;
        }
        $actual = strtolower((string) $refParts['scheme']) . '://' . strtolower((string) $refParts['host']);
        if (isset($refParts['port'])) {
            $actual .= ':' . (int) $refParts['port'];
        }
        return $actual === $expected;
    }
    return false;
}

/**
 * 客户端真实 IP（站点在 CDN 之后，REMOTE_ADDR 只是 CDN 节点）：
 * 依次取 X-Forwarded-For 首个合法 IP → X-Real-IP → CF-Connecting-IP → REMOTE_ADDR。
 * 用于防刷/评论频率/登录锁定等「按访客」而非「按 CDN 节点」的计数。
 */
function tri3_client_ip(): string
{
    $triCands = array();
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $triP) {
            $triCands[] = trim($triP);
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) { $triCands[] = trim((string) $_SERVER['HTTP_X_REAL_IP']); }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) { $triCands[] = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']); }
    $triCands[] = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    foreach ($triCands as $triIp) {
        if ($triIp !== '' && filter_var($triIp, FILTER_VALIDATE_IP)) {
            return $triIp;
        }
    }
    return '';
}

/**
 * 调试日志：默认关闭，只在后台「调试日志」开关打开时写入 cache/debug.log
 * （原来若干处无条件写 /tmp，生产环境既浪费 IO 又把访客信息落到 tmp 里）。
 */
function tri3_debug_log(string $tag, string $msg): void
{
    static $triOn = null;
    if ($triOn === null) {
        $triOn = false;
        try {
            $triOn = (string) tri_opt('debugLog', '0') === '1';
        } catch (Throwable $e) {
            $triOn = false;
        }
    }
    if (!$triOn) {
        return;
    }
    $triFile = __DIR__ . '/../cache/debug.log';
    @file_put_contents($triFile, date('Y-m-d H:i:s') . ' ' . $tag . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * 原子写文件：先写同目录临时文件再 rename，避免并发读方读到半个文件
 * （JSON 缓存/配置缓存被截断后 json_decode 会失败，表现为数据「时好时坏」）。
 */
function tri3_atomic_write(string $file, string $data): bool
{
    $triTmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($triTmp, $data) === false) {
        return false;
    }
    if (!@rename($triTmp, $file)) {
        @unlink($triTmp);
        return false;
    }
    return true;
}

/**
 * 说说正文图文混排渲染：
 *  1) 全文转义（防 XSS）
 *  2) QQ / 微博 / 阿鲁表情 → 图片
 *  3) 媒体标记 → 对应媒体组件（可自由排版插入文字中间）
 *     [img]url[/img]
 *     [music]server|id|title|artist|pic[/music] 或 [music]id[/music]
 *     [video]src|poster[/video]
 *     [live]img|video[/live]
 */
/**
 * 提取 B 站视频标识：支持 BV 号 / av 号 / 含 BV 或 av 的完整链接 / 纯数字(av)
 */
function tri3_bili_bvid($raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/BV[0-9A-Za-z]{6,}/', $raw, $m)) {
        return $m[0];
    }
    if (preg_match('/av(\d+)/i', $raw, $m)) {
        return 'av' . $m[1];
    }
    if (preg_match('/^(\d+)$/', $raw, $m)) {
        return 'av' . $m[1];
    }
    return '';
}

/**
 * 渲染 B 站视频播放器（官方 iframe，16:9 圆角卡片）
 */
function tri3_bili_player_html($bvid, int $cid): string
{
    $bvid = tri3_bili_bvid($bvid);
    if ($bvid === '') {
        return '';
    }
    $param = (strpos($bvid, 'av') === 0)
        ? 'aid=' . urlencode(substr($bvid, 2))
        : 'bvid=' . urlencode($bvid);
    $src = 'https://player.bilibili.com/player.html?' . $param . '&page=1&high_quality=1&danmaku=0&autoplay=0';
    return '<div class="mc-bili" data-cid="' . (int) $cid . '"><iframe class="mc-bili-frame" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true" loading="lazy" title="哔哩哔哩视频"></iframe><span class="mc-bili-badge"><i class="ri-bilibili-line"></i>哔哩哔哩</span></div>';
}

function tri3_render_moment_text(string $text, $mCid): string
{
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $safe = tri3_qq_emoji($safe);
    $cid  = (int) $mCid;
    /* 图片 */
    $safe = preg_replace_callback('/\[img\](.*?)\[\/img\]/is', function ($mm) use ($cid) {
        $url = htmlspecialchars_decode(trim($mm[1]), ENT_QUOTES);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $mm[0];
        }
        $sig = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($url) : $url;
        return '<span class="mc-inline-media"><a class="moment-img" href="' . htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') . '" data-fancybox="moment-' . (int) $cid . '" rel="noopener"><img src="' . htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') . '" loading="lazy" alt=""></a></span>';
    }, $safe);

    /* 音乐 */
    $safe = preg_replace_callback('/\[music\](.*?)\[\/music\]/is', function ($mm) use ($cid) {
        $raw    = htmlspecialchars_decode(trim($mm[1]), ENT_QUOTES);
        $parts  = explode('|', $raw);
        $first  = trim($parts[0] ?? '');
        $server = 'netease';
        $id = $title = $artist = $pic = '';
        if ($first !== '' && preg_match('#^[a-z]+$#i', $first) && !ctype_digit($first)) {
            $server = strtolower($first);
            $id     = trim($parts[1] ?? '');
            $title  = trim($parts[2] ?? '');
            $artist = trim($parts[3] ?? '');
            $pic    = trim($parts[4] ?? '');
        } else {
            $id     = $first;
            $title  = trim($parts[1] ?? '');
            $artist = trim($parts[2] ?? '');
            $pic    = trim($parts[3] ?? '');
        }
        /* 兼容前端简写：qq -> tencent */
        if ($server === 'qq') {
            $server = 'tencent';
        }
        if (!in_array($server, array('netease', 'tencent', 'local'), true)) {
            $server = 'netease';
        }
        if ($id === '' || !preg_match('#^[A-Za-z0-9]+$#', $id)) {
            return $mm[0];
        }
        if ($pic !== '' && !preg_match('#^https?://#i', $pic)) {
            $pic = '';
        }
        $cover = $pic !== ''
            ? '<img src="' . htmlspecialchars($pic, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">'
            : '<i class="ri-music-2-fill"></i>';
        return '<div class="mc-music" data-cid="' . $cid . '" data-server="' . htmlspecialchars($server, ENT_QUOTES, 'UTF-8') . '" data-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" data-title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" data-artist="' . htmlspecialchars($artist, ENT_QUOTES, 'UTF-8') . '" data-pic="' . htmlspecialchars($pic, ENT_QUOTES, 'UTF-8') . '"><div class="mc-music-cover">' . $cover . '</div><div class="mc-music-meta"><span class="mc-music-title">' . htmlspecialchars($title !== '' ? $title : '未知歌曲', ENT_QUOTES, 'UTF-8') . '</span><span class="mc-music-artist">' . htmlspecialchars($artist !== '' ? $artist : '未知歌手', ENT_QUOTES, 'UTF-8') . '</span></div><button type="button" class="mc-music-play" aria-label="播放这首音乐"><i class="ri-play-fill"></i></button></div>';
    }, $safe);

    /* 视频 */
    $safe = preg_replace_callback('/\[video\](.*?)\[\/video\]/is', function ($mm) use ($cid) {
        $parts = explode('|', htmlspecialchars_decode(trim($mm[1]), ENT_QUOTES));
        $src   = trim($parts[0] ?? '');
        if ($src === '' || !preg_match('#^https?://#i', $src)) {
            return $mm[0];
        }
        $poster = trim($parts[1] ?? '');
        if ($poster !== '' && !preg_match('#^https?://#i', $poster)) {
            $poster = '';
        }
        $posterAttr = $poster !== '' ? ' poster="' . htmlspecialchars($poster, ENT_QUOTES, 'UTF-8') . '"' : '';
        return '<div class="mc-video" data-cid="' . $cid . '"><video class="mc-video-el" preload="metadata" playsinline webkit-playsinline x5-playsinline x5-video-player-type="h5" disablepictureinpicture controlslist="nodownload noplaybackrate nofullscreen" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"' . $posterAttr . '></video><div class="mc-video-mask"><button type="button" class="mc-video-play" aria-label="播放视频"><i class="ri-play-fill"></i></button></div><div class="mc-video-bar" hidden><button type="button" class="mc-vb-toggle" aria-label="播放/暂停"><i class="ri-pause-fill"></i></button><div class="mc-vb-progress"><span class="mc-vb-fill"></span></div><span class="mc-vb-time">0:00 / 0:00</span><button type="button" class="mc-vb-full" aria-label="全屏"><i class="ri-fullscreen-line"></i></button></div></div>';
    }, $safe);

    /* 实况 */
    $safe = preg_replace_callback('/\[live\](.*?)\[\/live\]/is', function ($mm) use ($cid) {
        $parts = explode('|', htmlspecialchars_decode(trim($mm[1]), ENT_QUOTES));
        $img   = trim($parts[0] ?? '');
        $video = trim($parts[1] ?? '');
        $hasImg = ($img !== '' && preg_match('#^https?://#i', $img));
        $hasVid = ($video !== '' && preg_match('#^https?://#i', $video));
        if (!$hasImg && !$hasVid) {
            return $mm[0];
        }
        $html = '<div class="mc-live" data-cid="' . $cid . '">';
        if ($hasImg) {
            $html .= '<img class="mc-live-img" src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">';
        }
        if ($hasVid) {
            $html .= '<video class="mc-live-video" loop muted playsinline webkit-playsinline x5-playsinline x5-video-player-type="h5" disablepictureinpicture preload="metadata" src="' . htmlspecialchars($video, ENT_QUOTES, 'UTF-8') . '"></video>';
        }
        $html .= '<span class="mc-live-badge"><i class="ri-video-line"></i>实况</span></div>';
        return $html;
    }, $safe);

    /* B站视频 */
    $safe = preg_replace_callback('/\[bili\](.*?)\[\/bili\]/is', function ($mm) use ($cid) {
        return tri3_bili_player_html(trim($mm[1]), $cid);
    }, $safe);

    /* pre-wrap 已处理换行，不再 nl2br 避免双重间距 */
    return $safe;
}

/* ========== 评论区渲染函数（迁移自 comments.php，供页面与 AJAX 复用） ========== */
if (!function_exists('tri3_comment_sort')) {
/* 评论排序：默认倒序（最新在上）；cookie 记忆 + URL 参数切换 */
function tri3_comment_sort() {
    static $sort = null;
    if ($sort === null) {
        $sort = 'desc';
        if (isset($_COOKIE['tri3_comment_sort']) && in_array($_COOKIE['tri3_comment_sort'], array('asc', 'desc'), true)) {
            $sort = $_COOKIE['tri3_comment_sort'];
        }
        if (isset($_GET['commentSort']) && in_array($_GET['commentSort'], array('asc', 'desc'), true)) {
            $sort = $_GET['commentSort'];
            @setcookie('tri3_comment_sort', $sort, time() + 2592000, '/');
        }
    }
    return $sort;
}

/* 排序切换链接（保留当前 URL 其他参数） */
function tri3_sort_url($s) {
    $url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $q = parse_url($url, PHP_URL_QUERY);
    $params = array();
    if ($q) { parse_str($q, $params); }
    $params['commentSort'] = $s;
    $base = strtok($url, '?');
    return $base . '?' . http_build_query($params);
}

/**
 * 实时评论总数（approved 口径，与评论列表渲染一致）
 * $archive 可为 Archive 对象 / stdClass（含 cid）或直接 int cid
 */
function tri3_comment_count($archive): int
{
    $cid = is_object($archive) ? (int) $archive->cid : (int) $archive;
    if ($cid <= 0) {
        return 0;
    }
    return tri_redis_remember('cid-' . $cid, 'comments', function () use ($cid) {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select(array('COUNT(*)' => 'num'))->from('table.comments')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved'));
        return (int) ($row['num'] ?? 0);
    }, 60);
}

/* 当前登录用户是否为管理员（静态缓存，前台/后台/独立 API 通用） */
function tri3_is_admin(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = false;
        try {
            $u = Typecho_Widget::widget('Widget_User');
            $ok = $u->hasLogin() && $u->pass('administrator');
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/* 自定义评论树渲染（支持正序/倒序） */
function tri3_render_comment_tree($archive) {
    $sort = tri3_comment_sort();
    $db = Typecho_Db::get();
    $rows = $db->fetchAll($db->select()->from('table.comments')
        ->where('cid = ?', (int) $archive->cid)
        ->where('type = ?', 'comment')
        ->where('status = ?', 'approved')
        ->order('table.comments.coid', $sort === 'asc' ? Typecho_Db::SORT_ASC : Typecho_Db::SORT_DESC));
    if (!$rows) {
        echo '<div class="comment-empty"><i class="ri-chat-smile-3-line"></i><p>还没有评论，来说两句吧</p></div>';
        return;
    }
    $byId = array();
    foreach ($rows as $r) { $byId[(int) $r['coid']] = $r; }
    echo '<ol class="comment-list">';
    foreach ($rows as $r) { tri3_echo_comment($r, $archive, $byId); }
    echo '</ol>';
}

function tri3_echo_comment(&$r, $archive, $byId = array()) {
    $isOwner = (int) $r['authorId'] && (int) $r['authorId'] === (int) $archive->authorId;
    $triAv = tri3_comment_avatar((string) ($r['qq'] ?? ''), (string) $r['mail'], 40);
    $author = htmlspecialchars((string) $r['author']);
    $authorHtml = !empty($r['url'])
        ? '<a href="' . htmlspecialchars((string) $r['url']) . '" rel="external nofollow" target="_blank">' . $author . '</a>'
        : $author;
    $cls = 'comment-item' . ($isOwner ? ' comment-by-author' : '');
    echo '<li id="comment-' . (int) $r['coid'] . '" class="' . $cls . '">';
    if ($triAv !== '') {
        echo '<span class="comment-avatar"><img src="' . $triAv . '" alt="" loading="lazy">'
            . ($isOwner ? '<i class="comment-avatar-dot"></i>' : '') . '</span>';
    }
    echo '<div class="comment-body">';
    echo '<div class="comment-meta">';
    echo '<span class="comment-author">' . $authorHtml . ($isOwner ? '<i class="comment-badge">博主</i>' : '') . '</span>';
    echo '<span class="comment-meta-dot">·</span>';
    echo '<time class="comment-time" datetime="' . date('c', (int) $r['created']) . '" title="' . date('Y-m-d H:i', (int) $r['created']) . '">'
        . tri3_time_ago((int) $r['created']) . '</time>';
    echo '<span class="comment-reply-spacer"></span>';
    echo '<button type="button" class="comment-reply-btn" data-coid="' . (int) $r['coid'] . '" data-author="' . $author . '">回复</button>';
    if (tri3_is_admin()) {
        echo '<button type="button" class="comment-del-btn" data-coid="' . (int) $r['coid'] . '">删除</button>';
    }
    echo '</div>';
    echo '<div class="comment-bubble">';
    $pid = (int) $r['parent'];
    if ($pid && isset($byId[$pid])) {
        $pName = htmlspecialchars((string) $byId[$pid]['author']);
        $pText = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/!\[[^\]]*\]\([^)]*\)/', '$1', (string) $byId[$pid]['text']))));
        if (function_exists('mb_substr')) { $pText = mb_substr($pText, 0, 42, 'UTF-8'); }
        else { $pText = substr($pText, 0, 42); }
        if (mb_strlen($pText, 'UTF-8') >= 42) { $pText .= '…'; }
        echo '<a class="comment-quote" href="#comment-' . $pid . '" title="查看原评论">'
            . '<span class="comment-quote-name">@' . $pName . '</span>'
            . '<span class="comment-quote-text">' . htmlspecialchars($pText) . '</span></a>';
    }
    echo '<div class="comment-content">' . tri3_comment_text((string) $r['text']) . '</div></div>';
    echo '</div>';
    echo '</li>';
}

/* 评论正文：转义 + QQ 表情 + 音乐分享卡片 + Markdown 图片语法（![alt](http url)）→ <img> */
/* 评论正文：转义 + QQ 表情 + Markdown 图片语法（![alt](http url)）→ <img>（http(s) 白名单，点击新窗口/灯箱） */
function tri3_comment_text($text) {
    $t = tri3_qq_emoji(htmlspecialchars((string) $text));
    $t = preg_replace_callback('/!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/', function ($m) {
        $alt = trim($m[1]);
        if ($alt === '') { $alt = '评论图片'; }
        $url = $m[2];
        $sig = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($url) : $url;
        return '<a class="comment-img-link tri-gb" href="' . $sig . '" data-fancybox="comment" rel="noopener nofollow">'
            . '<img class="comment-img" src="' . $sig . '" alt="' . htmlspecialchars($alt) . '" loading="lazy"></a>';
    }, $t);
    return nl2br($t);
}/* 相对时间：刚刚 / N分钟前 / N小时前 / N天前 / 具体日期 */
function tri3_time_ago($ts) {
    if (!$ts) { return ''; }
    $diff = time() - $ts;
    if ($diff < 60) { return '刚刚'; }
    if ($diff < 3600) { return floor($diff / 60) . '分钟前'; }
    if ($diff < 86400) { return floor($diff / 3600) . '小时前'; }
    if ($diff < 2592000) { return floor($diff / 86400) . '天前'; }
    return date('Y-m-d', $ts);
}

}

/**
 * 融合模式列表：文章 + 说说 按时间线（created DESC）混合
 * @param bool $includePrivate 是否包含私密说说（管理员登录态）
 * @return array 每条：type=post（title/permalink/created/excerpt/thumb/cat/cmt）
 *               或 type=moment（moment=tri_moment_list 行 / created）
 */
function tri_fusion_items(bool $includePrivate = false): array
{
    return tri_redis_remember($includePrivate ? 'admin' : 'public', 'fusion', function () use ($includePrivate) {
        return tri_fusion_items_uncached($includePrivate);
    }, 300);
}

function tri_fusion_items_uncached(bool $includePrivate = false): array
{
    $items = array();
    try {
        $db = Typecho_Db::get();
        $allPosts = $db->fetchAll(
            $db->select()->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('table.contents.created', Typecho_Db::SORT_DESC)
                ->limit(500)
        );
        $catMap = array();
        $cids = array_column($allPosts, 'cid');
        if ($cids) {
            try {
                $relRows = $db->fetchAll(
                    $db->select()->from('table.relationships')->where('cid IN ?', $cids)
                );
                $relMids = array_column($relRows, 'mid');
                $catRows = array();
                if ($relMids) {
                    $catRows = $db->fetchAll(
                        $db->select('mid', 'name')->from('table.metas')
                            ->where('type = ?', 'category')
                            ->where('mid IN ?', $relMids)
                    );
                }
                foreach ($relRows as $rr) {
                    foreach ($catRows as $cr) {
                        if ((int) $rr['mid'] === (int) $cr['mid'] && !isset($catMap[$rr['cid']])) {
                            $catMap[$rr['cid']] = (string) $cr['name'];
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                $catMap = array();
            }
        }
        $excerptMap = array();
        if ($cids) {
            try {
                $fRows = $db->fetchAll(
                    $db->select('cid', 'str_value')->from('table.fields')
                        ->where('cid IN ? AND name = ?', $cids, 'excerpt')
                );
                foreach ($fRows as $fr) {
                    $excerptMap[(int) $fr['cid']] = trim((string) $fr['str_value']);
                }
            } catch (Exception $e) {
                $excerptMap = array();
            }
        }
        /* 一次性实时统计文章评论数（与 tri3_comment_count / 说说列表一致），
           避免依赖 contents.commentsNum 缓存字段（主题 API 新增评论曾不更新导致显示滞后） */
        $cmtMap = array();
        if ($cids) {
            try {
                $cmtRows = $db->fetchAll(
                    $db->select('cid', array('COUNT(*)' => 'num'))->from('table.comments')
                        ->where('cid IN ? AND type = ? AND status = ?', $cids, 'comment', 'approved')
                        ->group('cid')
                );
                foreach ($cmtRows as $cr) {
                    $cmtMap[(int) $cr['cid']] = (int) $cr['num'];
                }
            } catch (Exception $e) {
                $cmtMap = array();
            }
        }
        foreach ($allPosts as $pr) {
            $rawText = (string) $pr['text'];
            $rawText = str_replace('<!--markdown-->', '', $rawText);
            /* 1) 提取图片（按出现顺序去重，最多 9 张）：
               a. HTML <img>；b. Markdown 内联 ![](url)；c. Markdown 引用式 ![desc][ref]+[ref]: url；
               d. 裸 URL 图片（tri-gallery 等短代码里的 http 图片直链） */
            $imgs = array();
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)/i', $rawText, $fm)) {
                foreach ($fm[1] as $u) { $imgs[] = trim($u); }
            }
            if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)/', $rawText, $fm2)) {
                foreach ($fm2[1] as $u) { $imgs[] = trim($u); }
            }
            $refMap = array();
            if (preg_match_all('/^\[([^\]]+)\]:\s*(\S+)/m', $rawText, $fm3)) {
                foreach ($fm3[1] as $i => $ref) { $refMap[trim($ref)] = trim($fm3[2][$i]); }
            }
            if (preg_match_all('/!\[[^\]]*\]\[([^\]]+)\]/', $rawText, $fm4)) {
                foreach ($fm4[1] as $ref) {
                    $ref = trim($ref);
                    if (isset($refMap[$ref])) { $imgs[] = $refMap[$ref]; }
                }
            }
            $imgUrlRe = '~https?://[^\s]+?\.(?:jpe?g|png|webp|gif|avif|bmp)(?:[?#][^\s]*)?~i';
            if (preg_match_all($imgUrlRe, $rawText, $fm5)) {
                foreach ($fm5[0] as $u) { $imgs[] = trim($u); }
            }
            $imgs = array_values(array_unique($imgs));
            /* 2) 剔除短代码标签（花括号 {xxx} / {/xxx}，方括号 [tri-gallery] / [/xx]），保留其中文字内容；
               markdown 链接 [text](url) 与引用式图片语法一并整体剔除 */
            $noShort = $rawText;
            $noShort = preg_replace('/!\[[^\]]*\]\([^)\s]+\)?/', ' ', $noShort);          /* 先剔 md 内联图片 */
            $noShort = preg_replace('/\[[^\]]+\]\([^)\s]+\)?/', ' ', $noShort);          /* [text](url) 链接 */
            $noShort = preg_replace('/\{[^{}]*\}/', '', $noShort);                        /* {xxx} 及成对标签 */
            $noShort = preg_replace('/\[\/?[a-z][a-z0-9-]*(?:\s[^\]]*)?\]/i', '', $noShort); /* [tri-gallery] 等 */
            /* 3) 剔除图片语法与裸图片 URL：HTML img、md 内联/引用式、引用定义行、裸 URL 图片 */
            $noImgText = preg_replace('/<img[^>]*>/i', ' ', $noShort);
            $noImgText = preg_replace('/!\[[^\]]*\]\([^)\s]+\)?/', ' ', $noImgText);
            $noImgText = preg_replace('/!\[[^\]]*\]\[[^\]]+\]/', ' ', $noImgText);
            $noImgText = preg_replace('/^\s*\[[^\]]+\]:\s*\S+.*$/m', '', $noImgText);
            $noImgText = preg_replace($imgUrlRe, ' ', $noImgText);
            $plainRaw = trim(strip_tags($noImgText));
            /* 4) # 标题：正文首个 Markdown 标题行作为卡片标题（点击跳转文章），摘要从标题行之后开始 */
            $fuseTitle = trim((string) $pr['title']);
            if (preg_match('/^#{1,6}\s*(.+)$/m', $plainRaw, $hm) && trim($hm[1]) !== '') {
                $fuseTitle = trim($hm[1]);
                $plainRaw = trim(preg_replace('/^#{1,6}\s*.+$/m', '', $plainRaw, 1));
            }
            $plain = trim(preg_replace('/\s+/', ' ', $plainRaw));
            $aiExcerpt = isset($excerptMap[(int) $pr['cid']]) ? $excerptMap[(int) $pr['cid']] : '';
            if ($aiExcerpt !== '') {
                /* AI 摘要 / 手填摘要优先 */
                $excerpt = function_exists('mb_substr')
                    ? mb_substr($aiExcerpt, 0, tri_fusion_excerpt_len(), 'UTF-8')
                    : substr($aiExcerpt, 0, tri_fusion_excerpt_len());
            } else {
                $excerpt = function_exists('mb_substr')
                    ? mb_substr($plain, 0, tri_fusion_excerpt_len(), 'UTF-8')
                    : substr($plain, 0, tri_fusion_excerpt_len());
            }
            $permalink = '';
            try {
                $permalink = Typecho_Router::url('post', array('cid' => (int) $pr['cid'], 'slug' => (string) $pr['slug']), (string) Helper::options()->index);
            } catch (Exception $e) {
                $permalink = '';
            }
            if ($permalink === '') {
                $permalink = rtrim((string) Helper::options()->siteUrl, '/') . '/archives/' . (int) $pr['cid'] . '/';
            }
            $items[] = array(
                'type'      => 'post',
                'cid'       => (int) $pr['cid'],
                'title'     => $fuseTitle,
                'permalink' => $permalink,
                'created'   => (int) $pr['created'],
                'excerpt'   => $excerpt,
                'imgs'      => array_slice($imgs, 0, 9),
                'cat'       => isset($catMap[$pr['cid']]) ? $catMap[$pr['cid']] : '',
                'cmt'       => isset($cmtMap[(int) $pr['cid']]) ? (int) $cmtMap[(int) $pr['cid']] : 0,
            );
        }
    } catch (Exception $e) {
        /* 文章查询失败时退化为仅说说 */
    }
    foreach (tri_moment_list(1, 1000, $includePrivate, 'all', '') as $mm) {
        $items[] = array(
            'type'      => 'moment',
            'moment'    => $mm,
            'cid'       => (int) $mm['cid'],
            'created'   => (int) $mm['created'],
        );
    }
    usort($items, function ($a, $b) {
        /* 置顶说说优先（moment_sticky=1），同置顶再按时间倒序 */
        $sa = ($a['type'] === 'moment' && !empty($a['moment']['sticky'])) ? 1 : 0;
        $sb = ($b['type'] === 'moment' && !empty($b['moment']['sticky'])) ? 1 : 0;
        if ($sa !== $sb) { return $sb - $sa; }
        return (int) $b['created'] - (int) $a['created'];
    });
    return $items;
}

/* 相邻文章（上一篇/下一篇，按发布时间+同时刻 cid 次序） */
function tri3_adjacent_post($archive, $dir = 'prev') {
    $db = Typecho_Db::get();
    $t = (int) $archive->created;
    $cid = (int) $archive->cid;
    $sel = $db->select('table.contents.cid', 'table.contents.title', 'table.contents.slug', 'table.contents.created', 'table.metas.slug AS catSlug', 'table.metas.name AS catName')
        ->from('table.contents')
        ->join('table.relationships', 'table.relationships.cid = table.contents.cid', Typecho_Db::INNER_JOIN)
        ->join('table.metas', 'table.metas.mid = table.relationships.mid AND table.metas.type = \'category\'', Typecho_Db::INNER_JOIN)
        ->where('table.contents.type = ?', 'post')
        ->where('table.contents.status = ?', 'publish');
    if ($dir === 'prev') {
        $sel->where('(table.contents.created < ? OR (table.contents.created = ? AND table.contents.cid < ?))', $t, $t, $cid)
            ->order('table.contents.created', Typecho_Db::SORT_DESC)
            ->order('table.contents.cid', Typecho_Db::SORT_DESC);
    } else {
        $sel->where('(table.contents.created > ? OR (table.contents.created = ? AND table.contents.cid > ?))', $t, $t, $cid)
            ->order('table.contents.created', Typecho_Db::SORT_ASC)
            ->order('table.contents.cid', Typecho_Db::SORT_ASC);
    }
    $row = $db->fetchRow($sel->limit(1));
    if ($row) {
        $row['permalink'] = '';
        try {
            $row['permalink'] = Typecho_Router::url('post', array(
                'cid' => (int) $row['cid'],
                'slug' => (string) $row['slug'],
                'category' => (string) ($row['catSlug'] ?? '')
            ), (string) Helper::options()->index);
        } catch (Exception $e) {
            $row['permalink'] = '';
        }
        if ($row['permalink'] === '') {
            $row['permalink'] = rtrim((string) Helper::options()->siteUrl, '/') . '/archives/' . (int) $row['cid'] . '/';
        }
    }
    return $row;
}

/* ============================================================
 * 评论 QQ 字段 + 邮件通知（TriM3）
 * ============================================================ */

/* 确保 typecho_comments 表有 qq 列（一次性 ALTER，幂等） */
function tri3_ensure_qq_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Typecho_Db::get();
        $pfx = $db->getPrefix();
        $cols = $db->fetchAll($db->query("SHOW COLUMNS FROM {$pfx}comments"));
        foreach ($cols as $c) {
            if (strtolower((string) ($c['Field'] ?? '')) === 'qq') {
                return;
            }
        }
        $db->query("ALTER TABLE {$pfx}comments ADD qq VARCHAR(32) NOT NULL DEFAULT ''");
    } catch (Exception $e) {
    }
}

/* 评论者头像：QQ 号优先（QQ 头像国内源 q1.qlogo.cn），否则 Cravatar 邮箱头像 */
function tri3_comment_avatar(string $qq, string $mail, int $size = 40): string
{
    $qq = preg_replace('/\D/', '', trim($qq));
    if ($qq !== '') {
        return 'https://q1.qlogo.cn/g?b=qq&nk=' . $qq . '&s=' . max(16, min(512, $size * 4));
    }
    return tri3_gravatar_url($mail, $size);
}

/* 评论表单 QQ 输入框回填值（cookie 记忆） */
function tri3_comment_qq_value(): string
{
    $v = isset($_COOKIE['tri3_comment_qq']) ? trim((string) $_COOKIE['tri3_comment_qq']) : '';
    return $v === '' ? '' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/* 站点名称（邮件主题用） */
function tri3_site_name(): string
{
    $n = trim((string) tri_opt('authorName', ''));
    if ($n === '') {
        $n = (string) Helper::options()->title;
    }
    return $n;
}

/* ---------- 邮件发送 ---------- */

/* 极简 SMTP 客户端（支持 SSL / STARTTLS / AUTH LOGIN），失败返回 false */
function tri3_smtp_send(string $to, string $subject, string $html): bool
{
    $host = trim((string) tri_opt('smtpHost', ''));
    if ($host === '') {
        return false;
    }

    $port = (int) tri_opt('smtpPort', '465');
    if ($port <= 0 || $port > 65535) {
        $port = 465;
    }

    $user = trim((string) tri_opt('smtpUser', ''));
    $pass = trim((string) tri_opt('smtpPass', ''));
    $secure = (string) tri_opt('smtpSecure', 'ssl');
    $from = trim((string) tri_opt('mailFromAddr', ''));
    if ($from === '') {
        $from = $user !== '' ? $user : tri3_admin_mail();
    }
    $name = trim((string) tri_opt('mailFromName', ''));
    if ($from === '') {
        return false;
    }

    $fp = @fsockopen(($secure === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 15);
    if (!$fp) {
        return false;
    }

    stream_set_timeout($fp, 25);
    $readLine = function () use ($fp) {
        $l = fgets($fp, 515);
        return is_string($l) ? trim($l) : '';
    };
    $send = function (string $c) use ($fp, $readLine) {
        fwrite($fp, $c . "\r\n");
        $lines = array();
        do {
            $l = $readLine();
            $lines[] = $l;
        } while (isset($l[3]) && $l[3] === '-');
        return $lines;
    };
    $isOk = function (array $lines, string $code = '250'): bool {
        $last = end($lines);
        return is_string($last) && strpos($last, $code) === 0;
    };

    $greet = $readLine();
    if (strpos($greet, '220') !== 0) {
        fclose($fp);
        return false;
    }

    $send('EHLO ' . $host);
    if ($secure === 'tls') {
        if (!$isOk($send('STARTTLS'), '220')) {
            fclose($fp);
            return false;
        }

        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }

        $send('EHLO ' . $host);
    }
    if ($user !== '' && $pass !== '') {
        if (!$isOk($send('AUTH LOGIN'), '334')) {
            fclose($fp);
            return false;
        }

        $send(base64_encode($user));
        if (!$isOk($send(base64_encode($pass)), '235')) {
            fclose($fp);
            return false;
        }

    }
    if (!$isOk($send('MAIL FROM:<' . $from . '>'), '250')) {
        fclose($fp);
        return false;
    }

    if (!$isOk($send('RCPT TO:<' . $to . '>'), '250')) {
        fclose($fp);
        return false;
    }

    if (!$isOk($send('DATA'), '354')) {
        fclose($fp);
        return false;
    }

    $enc = function (string $s): string {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    };
    $fromHeader = $name !== '' ? $enc($name) . ' <' . $from . '>' : $from;
    $data = 'To: <' . $to . ">\r\n"
        . 'From: ' . $fromHeader . "\r\n"
        . 'Subject: ' . $enc($subject) . "\r\n"
        . 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
        . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n.\r\n";
    $triLen = strlen($data);
    $triOff = 0;
    while ($triOff < $triLen) {
        $triW = fwrite($fp, substr($data, $triOff));
        if ($triW === false || $triW === 0) {
            break;
        }
        $triOff += $triW;
    }
    $last = $readLine();
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return strpos($last, '250') === 0;
}

/* 统一发信入口：smtp（默认）/ mail() */
function tri3_send_mail(string $to, string $subject, string $html): bool
{
    if (trim($to) === '' || !filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $to = trim($to);
    if (tri_opt('mailMode', 'smtp') !== 'mail') {
        return tri3_smtp_send($to, $subject, $html);
    }
    $from = trim((string) tri_opt('mailFromAddr', ''));
    if ($from === '') {
        $from = tri3_admin_mail();
    }
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    if ($from !== '') {
        $headers .= 'From: ' . $from . "\r\n";
    }
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
}

/* ---------- 邮件 HTML 模板（MD3 风格卡片） ---------- */
function tri3_mail_tpl(string $kind, array $d): string
{
    $pri = trim((string) tri_opt('primaryColor', '#6750a4'));
    if ($pri === '' || $pri[0] !== '#') {
        $pri = '#6750a4';
    }
    $site = tri3_site_name();
    $siteUrl = rtrim((string) Helper::options()->siteUrl, '/');
    $esc = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $author = $esc($d['author'] ?? '访客');
    $content = nl2br($esc($d['content'] ?? ''));
    $time = $esc($d['time'] ?? '');
    $link = trim((string) ($d['link'] ?? ''));
    if ($link === '') {
        $link = $siteUrl;
    }
    $link = $esc($link);
    $waitTip = !empty($d['waiting'])
        ? '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:12px"><tr><td style="background:#fdf3e7;border:1px solid #f5d9b8;border-radius:12px;padding:11px 14px"><div style="font-size:13px;color:#9a6700;line-height:1.7">该评论当前为「待审核」状态，请前往后台审核后再公开展示。</div></td></tr></table>'
        : '';
    $isReply = $kind === 'reply';
    $title = $isReply ? '你的评论收到了新回复' : '收到一条新评论';
    $slogan = $isReply ? '博主回复了你在本站的评论' : '有人在你站点留下了新的声音';
    $rawAuthor = trim(strip_tags((string) ($d['author'] ?? '访客')));
    $siteChar = trim(strip_tags($site));
    $siteChar = $siteChar !== '' ? mb_substr($siteChar, 0, 1, 'UTF-8') : 'B';
    $siteLogo = trim((string) tri_opt('logoUrl', ''));
    if ($siteLogo === '') {
        try {
            $siteLogo = trim((string) Helper::options()->logoUrl);
        } catch (Exception $e) {
            $siteLogo = '';
        }
    }
    $iconBlock = $siteLogo !== ''
        ? '<table cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" style="width:56px;height:56px;border-radius:14px;overflow:hidden"><img src="' . $esc($siteLogo) . '" alt="" width="56" height="56" style="display:block;width:56px;height:56px;border:0;border-radius:14px"></td></tr></table>'
        : '<table cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" style="width:56px;height:56px;border-radius:14px;background:#f0f0f4;font-size:24px;font-weight:700;color:' . $pri . ';line-height:56px">' . $siteChar . '</td></tr></table>';
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" xmlns="http://www.w3.org/1999/xhtml">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="X-UA-Compatible" content="IE=edge"></head>
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif">
<center role="presentation" style="width:100%;table-layout:fixed">
  <table width="100%" bgcolor="#f5f5f7" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" style="padding:40px 16px">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px">
      <tr><td style="padding:44px 40px 6px">
        <!-- 顶部图标 -->
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" style="padding-bottom:22px">
          {$iconBlock}
        </td></tr></table>
        <!-- 标题 -->
        <div style="text-align:center;font-size:24px;font-weight:700;color:#1d1d1f;letter-spacing:-.01em;line-height:1.35">{$title}</div>
        <!-- 副标题 -->
        <div style="text-align:center;font-size:14px;color:#6e6e73;margin-top:8px;line-height:1.6">{$slogan}</div>
        <!-- 作者 / 时间 -->
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:30px">
          <tr><td style="background:#fbfbfd;border:1px solid #e8e8ed;border-radius:14px;padding:13px 18px">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr>
              <td style="font-size:15px;font-weight:600;color:#1d1d1f">{$rawAuthor}</td>
              <td align="right" style="font-size:13px;color:#86868b">{$time}</td>
            </tr></table>
          </td></tr>
        </table>
        <!-- 评论内容 -->
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:12px">
          <tr><td style="background:#fbfbfd;border:1px solid #e8e8ed;border-left:3px solid {$pri};border-radius:12px;padding:16px 18px">
            <div style="font-size:15px;line-height:1.75;color:#1d1d1f;word-break:break-word">{$content}</div>
          </td></tr>
        </table>
        {$waitTip}
        <!-- 主按钮 -->
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:26px">
          <tr><td>
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr>
              <td align="center" style="background:{$pri};border-radius:12px">
                <a href="{$link}" style="display:block;padding:13px 24px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:12px">查看内容</a>
              </td>
            </tr></table>
          </td></tr>
        </table>
        <!-- 页脚 -->
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:36px;border-top:1px solid #ececf0">
          <tr><td align="center" style="padding:22px 0 36px">
            <div style="font-size:13px;color:#86868b;line-height:1.7">本邮件由 {$site} 自动发送，请勿直接回复</div>
            <div style="font-size:13px;line-height:1.7"><a href="{$siteUrl}" style="color:{$pri};text-decoration:none">{$siteUrl}</a></div>
          </td></tr>
        </table>
      </td></tr>
    </table>
  </td></tr></table>
</center>
</body>
</html>
HTML;
}

/* ---------- 评论通知统一入口 ----------
 * 访客评论 → 通知站长（后台「通知邮箱」）
 * 站长回复 → 通知被回复的访客（有 QQ 优先发 QQ 邮箱 qq@qq.com）
 */
function tri3_notify_comment(array $c): void
{
    if (!tri_switch('mailEnable', '1')) {
        return;
    }
    $isOwner = (int) ($c['authorId'] ?? 0) > 0;
    $parent = (int) ($c['parent'] ?? 0);
    $status = (string) ($c['status'] ?? 'approved');
    $db = Typecho_Db::get();

    /* 访客评论 → 通知站长 */
    if (!$isOwner) {
        if (!tri_switch('mailNotifyAdmin', '1')) {
            return;
        }
        $to = trim((string) tri_opt('mailAdminTo', ''));
        if ($to === '') {
            $to = tri3_admin_mail();
        }
        if ($to === '') {
            return;
        }
        $d = array(
            'author'  => (string) ($c['author'] ?? '访客'),
            'content' => (string) ($c['text'] ?? ''),
            'time'    => date('Y-m-d H:i', (int) ($c['created'] ?? time())),
            'link'    => (string) ($c['pageurl'] ?? ''),
            'waiting' => $status === 'waiting',
        );
        $triR = tri3_send_mail($to, '【' . tri3_site_name() . '】收到一条新评论', tri3_mail_tpl('new', $d));
        tri3_debug_log('comment-mail', 'ADMIN_NOTIFY to=' . $to . ' result=' . ($triR ? 'OK' : 'FAIL'));
        return;
    }

    /* 站长回复 → 通知被回复的访客 */
    if ($parent > 0 && tri_switch('mailReplyNotify', '1') && $status === 'approved') {
        try {
            $p = $db->fetchRow($db->select()->from('table.comments')->where('coid = ?', $parent));
        } catch (Exception $e) {
            $p = null;
        }
        if (!$p) {
            return;
        }
        $pqq = preg_replace('/\D/', '', (string) ($p['qq'] ?? ''));
        $to = '';
        if ($pqq !== '') {
            $to = $pqq . '@qq.com';
        } elseif (filter_var((string) ($p['mail'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $to = trim((string) $p['mail']);
        }
        if ($to === '') {
            return;
        }
        $d = array(
            'author'  => (string) ($c['author'] ?? '博主'),
            'content' => (string) ($c['text'] ?? ''),
            'time'    => date('Y-m-d H:i', (int) ($c['created'] ?? time())),
            'link'    => (string) ($c['pageurl'] ?? ''),
            'waiting' => false,
        );
        $triR2 = tri3_send_mail($to, '【' . tri3_site_name() . '】您的评论收到了新回复', tri3_mail_tpl('reply', $d));
        tri3_debug_log('comment-mail', 'REPLY_NOTIFY to=' . $to . ' result=' . ($triR2 ? 'OK' : 'FAIL'));
    }
}

/* ---------- Typecho 原生评论钩子（文章 / 页面 / 友链 / 豆瓣 / 番剧等） ----------
 * filter：提交校验前把 QQ 并入评论数据（配合表 qq 列一起入库）
 * finishComment：评论入库后触发邮件通知 */
if (class_exists('Typecho_Plugin')) {
    Typecho_Plugin::factory('Widget_Feedback')->filter = function ($comment, $content) {
        tri3_ensure_qq_column();
        $qq = preg_replace('/\D/', '', (string) ($_POST['qq'] ?? ''));
        if ($qq !== '') {
            $comment['qq'] = $qq;
            @setcookie('tri3_comment_qq', $qq, time() + 2592000, '/');
        }
        /* 垃圾评论过滤（原生提交兜底；常规路径被 main.js 拦截走 comment-api，双保险） */
        try {
            $triSpamData = array(
                'text'   => (string) ($comment['text'] ?? ''),
                'author' => (string) ($comment['author'] ?? ''),
                'mail'   => (string) ($comment['mail'] ?? ''),
                'ip'     => function_exists('tri3_client_ip') ? tri3_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'agent'  => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'hp'     => (string) ($_REQUEST['tri3_hp'] ?? ''),
            );
            $triR = tri3_spam_check($triSpamData);
            if (!$triR['ok']) {
                if ($triR['pending']) {
                    $comment['status'] = 'waiting';
                    return $comment;
                }
                /* 直接拒绝：蜜罐静默；其余给出友好提示（可能是误拉黑） */
                if ($triR['silent']) {
                    throw new \Typecho\Exception(_t('评论提交失败'));
                }
                throw new \Typecho\Exception(_t('评论未通过内容检查，请修改后重试'));
            }
            $triAi = tri3_spam_ai_check($triSpamData);
            if ($triAi !== null && !$triAi['ok']) {
                if ($triAi['pending']) {
                    $comment['status'] = 'waiting';
                } else {
                    throw new \Typecho\Exception(_t('评论未通过内容检查，请修改后重试'));
                }
            }
        } catch (\Typecho\Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            /* 过滤引擎自身异常不阻塞评论 */
        }
        return $comment;
    };
    Typecho_Plugin::factory('Widget_Feedback')->finishComment = function ($feedback) {
        try {
            $coid = (int) ($feedback->coid ?? 0);
            if ($coid <= 0) {
                return;
            }
            $row = Typecho_Db::get()->fetchRow(Typecho_Db::get()->select()->from('table.comments')->where('coid = ?', $coid));
            if (!$row) {
                return;
            }
            $row['pageurl'] = (string) ($_POST['pageurl'] ?? '');
            tri3_notify_comment($row);
        } catch (Exception $e) {
        }
    };
}

/* 阅读时间估算：按正文纯文本字数 ÷ 300 字/分钟 */
function tri3_read_time($widget): string
{
    try {
        $content = (string) $widget->content;
        $text = trim(strip_tags($content));
        $text = preg_replace('/\[\/?(img|music|video|live)\][^\[\]]*/i', '', $text);
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        $min = max(1, (int) ceil($len / 300));
        return $min . ' 分钟';
    } catch (Throwable $e) {
        return '1 分钟';
    }
}

/* ============================================================
 * 垃圾评论过滤引擎（规则驱动 + 可选豆包 AI 语义层）
 * 设计原则：宁可放过，不可误杀 —— 命中默认「待审核」而非直接拒绝，
 * 高置信命中（蜜罐 / IP / 邮箱黑名单）才直接丢弃。
 * ============================================================ */

/** 读取垃圾过滤设置（统一默认值；未设置的项走默认，保证旧配置兼容） */
function tri3_spam_settings(): array
{
    $m = (string) tri_opt('spamMode', 'normal');
    if (!in_array($m, array('loose', 'normal', 'strict'), true)) { $m = 'normal'; }
    $act = (string) tri_opt('spamAction', 'pending');
    if (!in_array($act, array('pending', 'reject'), true)) { $act = 'pending'; }
    $aiAct = (string) tri_opt('spamAiAction', 'pending');
    if (!in_array($aiAct, array('pending', 'reject'), true)) { $aiAct = 'pending'; }
    $aiScope = (string) tri_opt('spamAiScope', 'suspicious');
    if (!in_array($aiScope, array('suspicious', 'all'), true)) { $aiScope = 'suspicious'; }
    return array(
        'enable'     => (int) tri_opt('spamEnable', '1'),
        'mode'       => $m,
        'keywords'   => trim((string) tri_opt('spamKeywords', '')),
        'maxLinks'   => max(0, (int) tri_opt('spamMaxLinks', '3')),
        'minLen'     => max(0, (int) tri_opt('spamMinLen', '2')),
        'hp'         => (int) tri_opt('spamHp', '1'),
        'ipList'     => trim((string) tri_opt('spamIpList', '')),
        'mailList'   => trim((string) tri_opt('spamMailList', '')),
        'action'     => $act,
        'rateEnable' => (int) tri_opt('spamRateEnable', '1'),
        'rateMin'    => max(0, (int) tri_opt('spamRateMin', '3')),
        'rateTen'    => max(0, (int) tri_opt('spamRateTen', '10')),'aiEnable'   => (int) tri_opt('spamAiEnable', '0'),
        'aiKey'      => trim((string) tri_opt('spamAiKey', '')),
        'aiModel'    => trim((string) tri_opt('spamAiModel', '')) ?: 'doubao-seed-2-1-pro-260628',
        'aiEndpoint' => trim((string) tri_opt('spamAiEndpoint', '')) ?: 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
        'aiScope'    => $aiScope,
        'aiAction'   => $aiAct,
        'aiTimeout'  => max(2, min(30, (int) tri_opt('spamAiTimeout', '15'))),
    );
}

/**
 * 规则引擎过滤。
 * @param array $d text / author / mail / ip / agent / hp（蜜罐值）
 * @return array ['ok'=>bool, 'pending'=>bool, 'reason'=>string, 'silent'=>bool]
 *   ok=true 放行；ok=false 且 pending=true 标记待审核；ok=false 且 silent=true 静默丢弃（bot 无感知）
 */
function tri3_spam_check(array $d): array
{
    $s = tri3_spam_settings();
    if (!$s['enable']) {
        return array('ok' => true, 'pending' => false, 'reason' => '', 'silent' => false);
    }
    $text   = (string) ($d['text'] ?? '');
    $author = (string) ($d['author'] ?? '');
    $mail   = (string) ($d['mail'] ?? '');
    $ip     = (string) ($d['ip'] ?? '');
    $agent  = (string) ($d['agent'] ?? '');
    $hp     = (string) ($d['hp'] ?? '');

    /* 1) 蜜罐：隐藏字段被填 → 高置信 bot，静默丢弃（不提示、不入库） */
    if ($s['hp'] && $hp !== '') {
        return array('ok' => false, 'pending' => false, 'reason' => 'honeypot', 'silent' => true);
    }

    /* 2) IP 黑名单（精确匹配，支持每行一个或逗号分隔） */
    if ($s['ipList'] !== '' && $ip !== '') {
        foreach (preg_split('/[\r\n,]+/', $s['ipList']) as $triIp) {
            $triIp = trim($triIp);
            if ($triIp !== '' && $ip === $triIp) {
                return array('ok' => false, 'pending' => false, 'reason' => 'ip_blacklist', 'silent' => false);
            }
        }
    }

    /* 3) 邮箱域名黑名单（精确域名或子域名，如 qq.com / mail.ru） */
    if ($s['mailList'] !== '' && $mail !== '') {
        $triMailDom = strtolower((string) parse_url('http://' . $mail, PHP_URL_HOST));
        if ($triMailDom === '') { $triMailDom = strtolower($mail); }
        foreach (preg_split('/[\r\n,]+/', $s['mailList']) as $triDom) {
            $triDom = strtolower(trim($triDom));
            if ($triDom === '' || $triMailDom === '') { continue; }
            if ($triMailDom === $triDom || substr($triMailDom, -(strlen($triDom) + 1)) === '.' . $triDom) {
                return array('ok' => false, 'pending' => false, 'reason' => 'mail_blacklist', 'silent' => false);
            }
        }
    }

    /* 4) 黑名单关键词：正文 / 昵称命中 → 按全局处置（默认待审） */
    if ($s['keywords'] !== '') {
        foreach (preg_split('/[\r\n,]+/', $s['keywords']) as $triKw) {
            $triKw = trim($triKw);
            if ($triKw === '') { continue; }
            if (stripos($text, $triKw) !== false || stripos($author, $triKw) !== false) {
                $triPend = $s['action'] === 'pending';
                return array('ok' => false, 'pending' => $triPend, 'reason' => 'keyword:' . mb_substr($triKw, 0, 20, 'UTF-8'), 'silent' => false);
            }
        }
    }

    /* 5) 链接数量：正文 URL 数超过阈值 → 待审（默认 3 条） */
    if ($s['maxLinks'] > 0) {
        preg_match_all('#https?://\S+#i', $text, $triUrls);
        if (count($triUrls[0]) > $s['maxLinks']) {
            return array('ok' => false, 'pending' => true, 'reason' => 'too_many_links', 'silent' => false);
        }
    }

    /* 6) 最短长度：去空白后过短 → 待审（默认 2 字符，防刷屏） */
    if ($s['minLen'] > 0) {
        $triPlain = trim(preg_replace('/\s+/u', '', $text));
        $triLen = function_exists('mb_strlen') ? mb_strlen($triPlain, 'UTF-8') : strlen($triPlain);
        if ($triLen > 0 && $triLen < $s['minLen']) {
            return array('ok' => false, 'pending' => true, 'reason' => 'too_short', 'silent' => false);
        }
    }

    /* 7) 恶意代码 / 混淆脚本特征：无正常交流语义，直接待审（默认模式生效） */
    if (preg_match('/(?:eval\s*\(\s*function|unescape\s*\(|fromCharCode\s*\(|<script[\s>]|document\.cookie|String\.fromCharCode)/i', $text)) {
        return array('ok' => false, 'pending' => true, 'reason' => 'obfuscated_code', 'silent' => false);
    }

    /* 10) 纯链接评论（正文只有推广链接、无实质文字）→ 待审 */
    $triNoUrl = trim(preg_replace('/\s+/u', '', preg_replace('#https?://\S+#i', '', $text)));
    if ($triNoUrl === '' && preg_match('#https?://#i', $text)) {
        return array('ok' => false, 'pending' => true, 'reason' => 'link_only', 'silent' => false);
    }

    /* 11) 重复字符轰炸（如 aaaa... / 9999...）→ 待审 */
    $triLen2 = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($triLen2 > 16 && preg_match('/(.)\1{11,}/u', $text)) {
        return array('ok' => false, 'pending' => true, 'reason' => 'repeated_chars', 'silent' => false);
    }

    /* 12) 重复评论：同一 IP 近 10 分钟提交过相同正文 → 待审 */
    $triPlain2 = trim($text);
    if ($triPlain2 !== '' && $ip !== '') {
        try {
            $triDb2 = Typecho_Db::get();
            $triSame = $triDb2->fetchRow($triDb2->select('coid')->from('table.comments')
                ->where('ip = ?', $ip)
                ->where('text = ?', $triPlain2)
                ->where('created > ?', time() - 600)
                ->limit(1));
            if ($triSame) {
                return array('ok' => false, 'pending' => true, 'reason' => 'duplicate', 'silent' => false);
            }
        } catch (\Throwable $e) {}
    }

    /* 13) 内置多语言垃圾特征（不依赖后台关键词；英文/俄语/小语种博彩、招聘、网赚、课程推广）→ 待审 */
    $triLangHit = false;
    if (preg_match('/(?:\bbet\b|\bcasino\b|\bbonus\b|\bpromo\b|\blottery\b|\bgambl|\baffiliate\b|\bvip\b|earn money|make money|sign ?up|free credit|deposit)/i', $text)) {
        $triLangHit = true;
    }
    if (!$triLangHit) {
        $triLower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach (array('работа', 'заработ', 'онлайн школа', 'онлайн-школа', 'ваканси', 'заработать', 'деньги', 'курс', 'казино', 'ставки', 'заработок') as $triW) {
            if ($triW !== '' && (function_exists('mb_strpos') ? mb_strpos($triLower, $triW) !== false : strpos($triLower, $triW) !== false)) {
                $triLangHit = true;
                break;
            }
        }
    }
    if (!$triLangHit) {
        /* 突厥语系（阿塞拜疆/土耳其）：博彩、下注、免费奖金 */
        foreach (array('bukmeker', 'mərc', 'bahis', 'kumar', 'deneme bonusu', 'canlı bahis', 'qazan', 'pul qazan') as $triW) {
            if ($triW !== '' && (function_exists('mb_strpos') ? mb_strpos($triLower, $triW) !== false : strpos($triLower, $triW) !== false)) {
                $triLangHit = true;
                break;
            }
        }
    }
    /* 西里尔 / 阿拉伯 / 希伯来 / 亚美尼亚 / 格鲁吉亚等字符占比 > 25% 且正文 ≥ 20 字符 → 待审 */
    if (!$triLangHit) {
        $triCyr = preg_match_all('/[\x{0400}-\x{052F}\x{0600}-\x{06FF}\x{0590}-\x{05FF}\x{10A0}-\x{10FF}\x{0530}-\x{058F}]/u', $text);
        $triLen3 = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($triLen3 >= 20 && $triCyr > 0 && $triCyr / $triLen3 > 0.25) {
            $triLangHit = true;
        }
    }
    if ($triLangHit) {
        return array('ok' => false, 'pending' => true, 'reason' => 'multilang_spam', 'silent' => false);
    }

    /* 严格模式附加规则（默认不启用，避免误杀） */
    if ($s['mode'] === 'strict') {
        /* 8) 空 UA / 明显爬虫 UA */
        if ($agent === '' || preg_match('#(curl|wget|python-requests|Go-http-client|scrapy|okhttp|Java/|libwww|httpunit|masscan)#i', $agent)) {
            return array('ok' => false, 'pending' => true, 'reason' => 'bot_agent', 'silent' => false);
        }
        /* 9) 链接密度：链接字符占比 > 50% 且总长 > 20 */
        preg_match_all('#https?://\S+#i', $text, $triU2);
        $triLinkChars = 0;
        foreach ($triU2[0] as $triU) { $triLinkChars += strlen($triU); }
        $triTotal = strlen($text);
        if ($triTotal > 20 && $triLinkChars > $triTotal * 0.5) {
            return array('ok' => false, 'pending' => true, 'reason' => 'link_dense', 'silent' => false);
        }
        /* 10) 连续重复字符 ≥ 20（如 ddddddddd…） */
        if (preg_match('/(.)\1{19,}/u', $text)) {
            return array('ok' => false, 'pending' => true, 'reason' => 'repeated', 'silent' => false);
        }
    }

    return array('ok' => true, 'pending' => false, 'reason' => '', 'silent' => false);
}

/**
 * 豆包 AI 语义过滤（可选层）。
 * 未启用 / 未填 Key / 调用失败 / 解析失败 → 一律返回 null（放行），防过度防御。
 * @return array|null 非 null 时为 ['ok'=>bool, 'pending'=>bool, 'reason'=>string]
 */

/**
 * 评论全局限速（IP 滑动窗口）：原生 Typecho 通道与主题 AJAX 评论统一使用。
 * 防止绕过前端 JS 直接 POST 原生端点刷评论 / 注入测试。
 * 窗口：60 秒内最多 rateMin 条；600 秒内最多 rateTen 条（后台「评论」组可配）。
 * 超限返回 ok=false，调用方应直接拒绝提交。
 */
function tri3_comment_rate_limit($ip = '')
{
    $s = tri3_spam_settings();
    if (empty($s['rateEnable'])) {
        return array('ok' => true, 'msg' => '');
    }
    $ip = $ip !== '' ? $ip : (function_exists('tri3_client_ip') ? tri3_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
        return array('ok' => true, 'msg' => ''); // 本机调试不限制
    }
    $min = (int) $s['rateMin'];
    $ten = (int) $s['rateTen'];
    if ($min <= 0 && $ten <= 0) {
        return array('ok' => true, 'msg' => '');
    }
    try {
        $db = Typecho_Db::get();
        $now = time();
        $count = function ($sec) use ($db, $ip, $now) {
            $row = $db->fetchRow($db->select(array('COUNT(*)' => 'num'))->from('table.comments')
                ->where('ip = ?', $ip)
                ->where('created > ?', $now - $sec)
                ->where('type = ?', 'comment'));
            return (int) ($row['num'] ?? 0);
        };
        if ($min > 0 && $count(60) >= $min) {
            return array('ok' => false, 'msg' => '评论太频繁了，稍后再试');
        }
        if ($ten > 0 && $count(600) >= $ten) {
            return array('ok' => false, 'msg' => '评论太频繁了，稍后再试');
        }
    } catch (\Throwable $e) {
        return array('ok' => true, 'msg' => ''); // 异常放行，不阻塞正常评论
    }
    return array('ok' => true, 'msg' => '');
}

function tri3_spam_ai_check(array $d): ?array
{
    $s = tri3_spam_settings();
    /* v1.2.85：主开关（评论规则引擎）关闭时不再调用 AI —— 旧版只看 aiEnable，
     * 关掉主开关后 AI 仍会联网复核，两个开关互相打架 */
    if (!$s['enable'] || !$s['aiEnable'] || $s['aiKey'] === '') {
        return null;
    }
    $text = trim((string) ($d['text'] ?? ''));
    if ($text === '') {
        return null;
    }
    $text = function_exists('mb_substr') ? mb_substr($text, 0, 600, 'UTF-8') : substr($text, 0, 600);
    $author = function_exists('mb_substr') ? mb_substr((string) ($d['author'] ?? ''), 0, 50, 'UTF-8') : substr((string) ($d['author'] ?? ''), 0, 50);

    $payload = array(
        'model' => $s['aiModel'],
        'messages' => array(
            array(
                'role' => 'system',
                'content' => '你是博客评论内容审核助手。判断用户评论是否为垃圾评论。垃圾评论包括：广告推广、引流、博彩、色情、代写、SEO 外链、刷屏灌水、无意义内容，不限于中文——任何语言（英语、俄语、阿塞拜疆语、土耳其语、阿拉伯语等）的博彩、招聘、网赚、课程、促销推广都是垃圾。注意：1. 评论者昵称也是重要判断依据：昵称包含品牌名或推广关键词（如 1xbet、casino、bonus、promo、school、online、rabota、вакансии 等）并带随机字母/数字后缀，是典型垃圾评论特征；2. 软广告常伪装成个人经历叙述（如"我在很多博彩公司玩过""犹豫要不要换学校然后发现某在线学校""朋友推荐了一个工作平台"），内容围绕推广某个品牌或服务（博彩公司、在线学校、招聘平台、理财课程等）就属于垃圾，即使没有网址；3. 正常交流、提问、讨论（无论何种语言）一律 spam=false。只输出 JSON，格式：{"spam":true或false,"score":0到100的整数,"reason":"简短中文原因"}。推广意图明确时坚决拦截，勿因语言障碍放过。',
            ),
            array(
                'role' => 'user',
                'content' => "评论者昵称：{$author}\n评论内容：\n{$text}",
            ),
        ),
        'temperature' => 0.1,
        'max_tokens' => 80,
        'stream' => false,
    );

    $ch = curl_init($s['aiEndpoint']);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $s['aiTimeout'],
        CURLOPT_CONNECTTIMEOUT => min(5, $s['aiTimeout']),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $s['aiKey'],
        ),
    ));
    $triResp = curl_exec($ch);
    if ($triResp === false || $triResp === '') {
        $triCurlErr = (string) curl_error($ch);
        unset($ch); /* PHP 8+ 自动释放 */
        /* 网络失败 / 超时：转待审核（AI 判不了就人工审，不放过垃圾、不误杀真实评论） */
        return array('ok' => false, 'pending' => true, 'reason' => 'ai_timeout', 'error' => 'AI 请求失败（网络错误/超时）：' . $triCurlErr . '（已转待审核，可适当调大「AI 超时」）');
    }
    unset($ch); /* PHP 8+ 自动释放；不再调用已弃用的 curl_close() */
    $triJ = json_decode((string) $triResp, true);
    if (isset($triJ['error'])) {
        /* 接口/鉴权错误（key 无效、模型不存在等）：配置问题不误伤评论，放行并记录 */
        $triErr = (string) ($triJ['error']['message'] ?? json_encode($triJ['error']));
        $triErr = function_exists('mb_substr') ? mb_substr($triErr, 0, 120, 'UTF-8') : substr($triErr, 0, 120);
        return array('ok' => true, 'pending' => false, 'reason' => '', 'error' => 'AI 接口错误：' . $triErr);
    }
    $triContent = (string) ($triJ['choices'][0]['message']['content'] ?? '');
    if (trim($triContent) === '') {
        return array('ok' => false, 'pending' => true, 'reason' => 'ai_empty', 'error' => 'AI 返回内容为空（模型名/接入点可能无效），已转待审核');
    }
    /* 兼容纯 JSON 与 markdown 代码块包裹 */
    $triCj = json_decode(trim($triContent), true);
    if (!is_array($triCj)) {
        if (preg_match('/\{(?:[^{}]|"[^"]*")*\}/s', $triContent, $triMm)) {
            $triCj = json_decode($triMm[0], true);
        }
    }
    if (!is_array($triCj) || !array_key_exists('spam', $triCj)) {
        return array('ok' => false, 'pending' => true, 'reason' => 'ai_parse', 'error' => 'AI 返回格式无法解析，已转待审核');
    }
    $triSpam = !empty($triCj['spam']);
    $triScore = max(0, min(100, (int) ($triCj['score'] ?? 50)));
    /* 明确非垃圾（score < 70）→ 放行 */
    if (!$triSpam && $triScore < 70) {
        return array('ok' => true, 'pending' => false, 'reason' => '');
    }
    /* 判定垃圾（spam=true 或 score ≥ 85）→ 按处置设置 */
    if ($triSpam || $triScore >= 85) {
        $triReason = (string) ($triCj['reason'] ?? '疑似垃圾评论');
        $triReason = function_exists('mb_substr') ? mb_substr($triReason, 0, 40, 'UTF-8') : substr($triReason, 0, 40);
        $triPend = $s['aiAction'] === 'pending';
        return array('ok' => false, 'pending' => $triPend, 'reason' => 'ai:' . $triReason);
    }
    return null; /* 边缘分数放行 */
}

/* ============================================================
 * remixicon 图标子集检测：后台配置（导航/手机菜单/首页菜单/小组件/友链）
 * 里出现子集之外的图标类时返回 true，header 改用完整图标库，
 * 保证用户后台新增任意图标都能正常显示，无需手动维护子集。
 * ============================================================ */
function tri3_ri_need_full(): bool
{
    static $triRiNeed = null;
    if ($triRiNeed !== null) {
        return $triRiNeed;
    }
    $triRiNeed = false;
    $triSubFile = __DIR__ . '/ri-subset-list.php';
    if (!is_file($triSubFile)) {
        $triRiNeed = true;
        return $triRiNeed;
    }
    $triSet = array_flip(require $triSubFile);
    $triHay = '';
    foreach (array('navItems', 'mobileMenu', 'homeMenu', 'widgetsLeft', 'widgetsRight', 'friends') as $triKey) {
        $triHay .= (string) tri_opt($triKey, '') . "\n";
    }
    if (preg_match_all('/ri-[a-z0-9]+(?:-[a-z0-9]+)*/', $triHay, $triM)) {
        foreach ($triM[0] as $triCls) {
            /* 子集名单以不带 ri- 前缀的图标名存储，这里兼容带/不带前缀两种写法 */
            if (!isset($triSet[$triCls]) && !isset($triSet[substr($triCls, 3)])) {
                $triRiNeed = true;
                break;
            }
        }
    }
    return $triRiNeed;
}

/* ============================================================
 * 图片加载优化：自建代理（缩放 + WebP + 磁盘缓存）
 * tri3_qiniu_sign_url()  七牛 CDN 时间戳防盗链动态签名（每次输出重新签名，永不过期）
 * tri3_img_proxy_url()  生成代理 URL（白名单外/已代理/空图原样返回）
 * tri3_img_proxy_html()  替换内容中 <img> 为代理地址并补 lazy/decoding
 * 说明：只代理本站域名与后台图床域名的图片；代理失败时 img.php 302 回原图，
 *       不影响显示（零风险降级）。七牛图床图片不做代理，改为动态防盗链签名。
 * ============================================================ */
/* ---------- 后台「图床设置」的统一取值出口（v1.2.85） ----------
 * 此前宽度写死 1080/1200、质量写死 70/75/78，后台填的「默认宽度 800 / 默认质量 80」
 * 在各个分支里都不生效，等于摆设。现在所有出图路径都从这里取值。 */
function tri3_img_default_w(): int
{
    $w = (int) tri_opt('imgDefaultW', '800');

    return max(32, min($w > 0 ? $w : 800, 2000));
}

function tri3_img_default_q(): int
{
    $q = (int) tri_opt('imgDefaultQ', '80');

    return max(30, min($q > 0 ? $q : 80, 95));
}

/* WebP 开关：关闭时所有分支都不再加 /format/webp */
function tri3_img_webp_on(): bool
{
    return tri_opt('imgWebpEnable', '1') === '1';
}

/* 代理总开关：关闭＝直接输出原图（不再绕 img.php） */
function tri3_img_proxy_on(): bool
{
    return tri_opt('imgProxyEnable', '1') === '1';
}

/* CDN 处理参数串：宽度/质量/格式全部来自后台设置；不透明格式不强制转 JPEG，
 * 避免带透明通道的 PNG 被压成黑底。$crop=true 时居中裁剪正方形。 */
function tri3_img_view2_params(int $w = 0, bool $crop = false): string
{
    $w = $w > 0 ? max(32, min($w, 2000)) : tri3_img_default_w();
    $fmt = tri3_img_webp_on() ? '/format/webp' : '';

    return 'imageView2/' . ($crop ? 1 : 0) . '/w/' . $w . '/q/' . tri3_img_default_q() . $fmt;
}

function tri3_qiniu_sign_url($url)
{

    /* CDN URL 鉴权分支（多吉云 Type A 签名）
     * 域名取自后台「图床设置」的七牛/自定义域名，密钥取自 dogyunKey 设置项；
     * 两者都配置才启用 → 未配置的站点自动跳过，不影响其它安装 */
    $triHost = (string) @parse_url($url, PHP_URL_HOST);
    $dogeKey = trim((string) tri_opt('dogyunKey', ''));
    $dogeDomains = array();
    foreach (array((string) tri_opt('qiniuDomain', ''), (string) tri_opt('albumApiDomain', '')) as $triDU) {
        $triDU = trim($triDU);
        if ($triDU === '') { continue; }
        $triDH = @parse_url((strpos($triDU, '//') === false) ? '//' . $triDU : $triDU, PHP_URL_HOST);
        if (is_string($triDH) && $triDH !== '') { $dogeDomains[] = strtolower($triDH); }
    }
    $triSiteHost = strtolower((string) @parse_url((string) (Helper::options()->siteUrl ?? ''), PHP_URL_HOST));
    if ($triSiteHost !== '') {
        $dogeDomains[] = 'img.' . $triSiteHost;
        $dogeDomains[] = 'test.' . $triSiteHost;
    }
    $dogeDomains = array_values(array_unique($dogeDomains));
    if ($dogeKey !== '' && $triHost !== '' && in_array(strtolower($triHost), $dogeDomains, true)) {
        $dogeUid = '0';
        $dogeTtl = 604800; /* 7 天：页面/HTML 会被 CDN 缓存，签名必须活得比缓存久 */
        $dogePath = (string) @parse_url($url, PHP_URL_PATH);
        if ($dogePath === '' || $dogePath === '/') { return $url; }
        $dogeQuery = (string) @parse_url($url, PHP_URL_QUERY);
        if ($dogeQuery !== '' && strpos($dogeQuery, 'auth_key=') !== false) {
            /* 已经带签名：没过期就直接复用（避免同一图片每页重签、打散 CDN 缓存）；
             * 过期了必须剥掉旧签名重新签 —— 历史数据（如相册封面）里存过签名 URL，
             * 旧逻辑在这里原样返回，签名一过期图片就永远 403。 */
            if (preg_match('/(?:^|&)auth_key=(\d+)-/', $dogeQuery, $triSig) && (int) $triSig[1] > time() + 600) {
                return $url;
            }
            $triKeepQ = array();
            foreach (explode('&', $dogeQuery) as $triKv) {
                if ($triKv === '' || strpos($triKv, 'auth_key=') === 0) { continue; }
                $triKeepQ[] = $triKv;
            }
            $dogeQuery = implode('&', $triKeepQ);
            $url = substr($url, 0, (int) strpos($url, '?')) . ($dogeQuery !== '' ? '?' . $dogeQuery : '');
        }
        /* 自动追加七牛图片处理：缩放+WebP+质量压缩（避免重复添加） */
        /* v1.2.85：宽度/质量/是否 WebP 一律取后台设置；总开关关闭时不加处理参数 */
        if (tri3_img_proxy_on()
            && preg_match('/\.(jpe?g|png|webp)$/i', $dogePath)
            && strpos($dogeQuery, 'imageView2') === false
        ) {
            $sep2 = ($dogeQuery !== '') ? '&' : '?';
            $url .= $sep2 . tri3_img_view2_params();
            $dogeQuery = (string) @parse_url($url, PHP_URL_QUERY);
        }
        $dogeWindow = 3600; /* 1 小时对齐窗口：同一小时内 URL 稳定，CDN 才能缓存住图片 */
        $dogeTs = floor((time() + $dogeTtl) / $dogeWindow) * $dogeWindow;
        $dogeRand = substr(md5($dogePath), 0, 24);
        $dogeRaw = $dogePath . '-' . $dogeTs . '-' . $dogeRand . '-' . $dogeUid . '-' . $dogeKey;
        $dogeHash = md5($dogeRaw);
        $dogeSep = ($dogeQuery !== '') ? '&' : '?';
        return $url . $dogeSep . 'auth_key=' . $dogeTs . '-' . $dogeRand . '-' . $dogeUid . '-' . $dogeHash;
    }
    $url = trim((string) $url);
    if ($url === '' || strpos($url, 'data:') === 0 || strpos($url, 'img.php') !== false) {
        return $url;
    }
    $secret = trim((string) tri_opt('qiniuTokenSecret', ''));
    $domain = rtrim(trim((string) tri_opt('qiniuDomain', '')), '/');
    if ($secret === '' || $domain === '') {
        return $url;
    }
    $path = (string) @parse_url($url, PHP_URL_PATH);
    if ($path === '' || $path === '/') {
        return $url;
    }
    $host = (string) @parse_url($url, PHP_URL_HOST);
    $domHost = (string) @parse_url($domain, PHP_URL_HOST);
    if ($domHost !== '' && strcasecmp($host, $domHost) !== 0) {
        return $url;
    }
    $ttl = (int) tri_opt('qiniuTokenTtl', '86400');
    if ($ttl < 60) {
        $ttl = 86400;
    }
    $qWindow = 600;
    $qT = dechex(floor((time() + $ttl) / $qWindow) * $qWindow);
    $qEnc = str_replace('%2F', '/', rawurlencode($path));
    $qSign = md5($secret . $qEnc . $qT);
    /* 访问 URL 的 path 也按文档要求 url_encode（与签名时同一算法），
       避免中文/特殊字符文件名导致 CDN 校验不匹配 */
    $out = $domain . $qEnc;
    /* 保留原 query 原文（仅剥离已有的 sign / t，防止二次签名，不改动其余编码） */
    $query = (string) @parse_url($url, PHP_URL_QUERY);
    if ($query !== '') {
        $qKeep = array();
        foreach (explode('&', $query) as $kv) {
            $kv = trim($kv);
            if ($kv === '') {
                continue;
            }
            $k = strtok($kv, '=');
            if ($k === 'sign' || $k === 't') {
                continue;
            }
            $qKeep[] = $kv;
        }
        if (!empty($qKeep)) {
            $out .= '?' . implode('&', $qKeep);
        }
    }
    $out .= (strpos($out, '?') !== false ? '&' : '?') . 'sign=' . $qSign . '&t=' . $qT;
    return $out;
}

/**
 * 对自定义 HTML 内容中的七牛图做动态签名（img src 与链接 href），
 * 用于页脚 / 通知条 / 自定义 HTML 小组件等 raw HTML 输出区域。
 * 未启用防盗链时原样返回（零影响）。
 */
function tri3_sign_content_imgs($html)
{
    $html = (string) $html;
    if ($html === '' || strpos($html, '<img') === false && strpos($html, '<a') === false) {
        return $html;
    }
    $html = preg_replace_callback('/<img([^>]*?)\bsrc\s*=\s*["\']([^"\']+)["\']([^>]*)>/i', function ($m) {
        $src = trim($m[2]);
        $sig = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($src) : $src;
        /* v1.2.87：正文图补 原生懒加载（正文图不能用 1×1 占位，会没有原始宽高导致塌陷跳动） */
        $head = $m[1];
        $tail = $m[3];
        if (!preg_match('/\sloading\s*=/i', $head . $tail)) {
            $tail .= ' loading="lazy"';
        }
        if (!preg_match('/\sdecoding\s*=/i', $head . $tail)) {
            $tail .= ' decoding="async"';
        }
        if ($sig === $src) {
            return '<img' . $head . ' src="' . $m[2] . '"' . $tail . '>';
        }
        return '<img' . $head . ' src="' . htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') . '"' . $tail . '>';
    }, $html);
    $html = preg_replace_callback('/<a([^>]*?)\bhref\s*=\s*["\']([^"\']+)["\']([^>]*)>/i', function ($m) {
        $src = trim($m[2]);
        $sig = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($src) : $src;
        if ($sig === $src) {
            return $m[0];
        }
        return '<a' . $m[1] . ' href="' . htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') . '"' . $m[3] . '>';
    }, $html);
    return $html;
}

/* 图片代理/防盗链允许的域名清单（SSRF 防护核心）
 * 站点域名取自 Typecho siteUrl（管理员设定，请求头无法伪造）
 *   → 换域名安装本主题后白名单自动生效，无需改动任何代码
 * 同时收录后台「图床设置」填写的七牛 / 兰空域名，以及公共缩略图站
 * 前缀 '.' 的条目表示允许其任意子域（图床/静态 CDN 常走 img.example.com） */
function tri3_img_allow_hosts()
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }
    /* 公共缩略图站：每日一图小组件用 */
    $hosts = array('img95.699pic.com', 'ts1.tc.mm.bing.net', 'ts2.tc.mm.bing.net', 'ts3.tc.mm.bing.net', 'ts4.tc.mm.bing.net');
    $srcs = array();
    if (class_exists('Helper') && method_exists('Helper', 'options')) {
        $opt = Helper::options();
        if ($opt) {
            $srcs[] = (string) ($opt->siteUrl ?? '');
            $srcs[] = (string) ($opt->qiniuDomain ?? '');
            $srcs[] = (string) ($opt->albumApiDomain ?? '');
        }
    }
    foreach ($srcs as $triSrcOne) {
        $triSrcOne = trim((string) $triSrcOne);
        if ($triSrcOne === '') { continue; }
        $triH = @parse_url((strpos($triSrcOne, '//') === false) ? '//' . $triSrcOne : $triSrcOne, PHP_URL_HOST);
        if (!is_string($triH) || $triH === '') { continue; }
        $triH = strtolower($triH);
        $hosts[] = $triH;
        $hosts[] = '.' . $triH;
    }
    $hosts = array_values(array_unique($hosts));
    tri3_img_sync_runtime_conf($hosts);
    return $hosts;
}

/* 把 img.php 运行期需要的站点配置写入 assets/cache/img-hosts.php。
 * img.php 刻意不引导 Typecho：其 Response 会在 shutdown 阶段把响应的状态码
 * 强制改回 200（实测确认），会让 img.php 的 403 失效、且每条图片请求都要付
 * 一次 Typecho 初始化的开销。改为读这里生成的配置文件：零副作用、更快。
 * 内容无变化时不写盘。 */
function tri3_img_sync_runtime_conf(array $hosts)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $extra = array();
    if (class_exists('Helper') && method_exists('Helper', 'options')) {
        $opt = Helper::options();
        if ($opt) {
            /* 附带上 img.php 需要的后台开关，让它不必引导 Typecho 也能读到真实设置 */
            foreach (array('siteUrl', 'qiniuDomain', 'albumApiDomain', 'dogyunKey',
                           'imgProxyEnable', 'imgWebpEnable', 'imgDefaultW', 'imgDefaultQ', 'imgCacheMax') as $triK) {
                $triV = $opt->{$triK} ?? '';
                if (is_array($triV)) { $triV = implode(',', $triV); }
                $triV = trim((string) $triV);
                if ($triV !== '') { $extra[$triK] = $triV; }
            }
        }
    }
    $php = '<?php' . "\n"
         . '/* TriM3 自动生成：api/img.php 的域名白名单与 CDN 配置，请勿手工编辑。 */' . "\n"
         . 'return ' . var_export(array('hosts' => array_values($hosts), 'extra' => $extra), true) . ';' . "\n";
    $file = dirname(__DIR__) . '/assets/cache/img-hosts.php';
    if (!is_file($file) || @file_get_contents($file) !== $php) {
        @mkdir(dirname($file), 0755, true);
        tri3_atomic_write($file, $php);
    }
}

/* host 是否在白名单内；以 '.' 开头的条目按后缀匹配其子域 */
function tri3_img_host_allowed($host)
{
    $host = strtolower(ltrim(trim((string) $host), '.'));
    if ($host === '') {
        return false;
    }
    foreach (tri3_img_allow_hosts() as $triA) {
        if ($triA === '') { continue; }
        if ($triA[0] === '.') {
            if (substr($host, -strlen($triA)) === $triA) { return true; }
        } elseif ($host === $triA) {
            return true;
        }
    }
    return false;
}

/**
 * 生成同一张 CDN 图的 srcset 候选：复用已有的 imageView2 参数，只改 w/ 值。
 * 多吉云 Type A 签名只与 path 有关，改查询参数不会破坏 auth_key，
 * 所以可以安全地给出多档宽度，让浏览器按视口与 DPR 自选，手机不必再拉 1080。
 */
function tri3_img_srcset(string $url, array $widths): string
{
    if ($url === '' || !preg_match('#imageView2/[^/]*/w/\d+#', $url)) { return ''; }
    $tri3Out = array();
    foreach ($widths as $tri3W) {
        $tri3W = (int) $tri3W;
        if ($tri3W < 32) { continue; }
        $tri3U = preg_replace('#(imageView2/[^/]*/w/)\d+#', '${1}' . $tri3W, $url, 1);
        if (!is_string($tri3U) || $tri3U === '') { continue; }
        /* 与基准同宽的档位同样是合法候选，不能当成「没变化」丢掉 */
        $tri3Out[] = $tri3U . ' ' . $tri3W . 'w';
    }
    return implode(', ', $tri3Out);
}

/**
 * 把远端封面字节压成 Web 友好的缩略图（限宽 + JPEG 重编码）再落盘。
 * 上游封面常是 960x1280 的 PNG（2MB 级），直接落盘会让列表页白背几 MB；
 * GD 不可用或解码失败时退回原始字节，保证缓存环节不会因压缩失败而缺图。
 */
function tri3_img_web_thumb(string $bytes, string $file, int $maxW = 400, int $quality = 86): bool
{
    if ($bytes === '' || strlen($bytes) < 100) { return false; }
    if (function_exists('imagecreatefromstring')) {
        $tri3Im = @imagecreatefromstring($bytes);
        if ($tri3Im !== false) {
            $tri3W = imagesx($tri3Im);
            $tri3H = imagesy($tri3Im);
            if ($tri3W > $maxW) {
                $tri3Th = (int) max(1, (int) round($tri3H * $maxW / $tri3W));
                $tri3Dst = @imagescale($tri3Im, $maxW, $tri3Th, IMG_BICUBIC);
                if ($tri3Dst !== false) { $tri3Im = $tri3Dst; }
            }
            /* 不调用 imagedestroy：PHP 8.0 起已是空操作，8.5 起会打弃用告警 */
            $tri3Ok = @imagejpeg($tri3Im, $file, $quality);
            if ($tri3Ok) { return true; }
        }
    }
    return @file_put_contents($file, $bytes) !== false;
}

function tri3_img_proxy_url($src, $w = 0, $fmt = 'auto')
{
    /* $w 未指定时用后台「默认宽度」，不再写死 1200 */
    if ((int) $w <= 0) { $w = tri3_img_default_w(); }
    $src = trim((string) $src);
    if ($src === '' || strpos($src, 'data:') === 0 || strpos($src, 'img.php') !== false || strpos($src, 'meting-api.php') !== false) {
        return $src;
    }
    $host = @parse_url($src, PHP_URL_HOST);
    if ($host === false || $host === null || $host === '') {
        return $src;
    }
    /* CDN 图（七牛 / 多吉云 + 七牛存储）：
     * 直接用七牛 imageView2 参数做缩放/WebP/质量压缩，CDN 实时处理并缓存，
     * 不经 PHP 代理，零额外开销。
     * 域名取自后台「图床设置」的 qiniuDomain（七牛域名）+ 本站 test 子域：
     * 未配置的站点该分支不命中，自动走下面的 PHP 代理分支（同样可用）。 */
    $triHost = strtolower((string) @parse_url($src, PHP_URL_HOST));
    $triQiniuRaw = trim((string) tri_opt('qiniuDomain', ''));
    $triQiniuHost = $triQiniuRaw === '' ? '' : strtolower((string) @parse_url(
        (strpos($triQiniuRaw, '//') === false) ? '//' . $triQiniuRaw : $triQiniuRaw, PHP_URL_HOST));
    $triSiteHost = strtolower((string) @parse_url((string) (Helper::options()->siteUrl ?? ''), PHP_URL_HOST));
    $triEdgeHosts = array();
    if ($triQiniuHost !== '') { $triEdgeHosts[] = $triQiniuHost; }
    if ($triSiteHost !== '') { $triEdgeHosts[] = 'test.' . $triSiteHost; }
    if (in_array($triHost, $triEdgeHosts, true)) {
        $w = max(32, min((int) $w, 2000));
        $q = tri3_img_default_q(); /* v1.2.85：原来写死 75 */
        $fmtParam = '';
        /* WebP 输出（现代浏览器都支持）；后台关掉 WebP 时不加该参数，保持原格式 */
        if ($fmt !== 'jpeg' && tri3_img_webp_on()) {
            $fmtParam = '/format/webp';
        }
        /* mode=0: 等比缩放，限定长边；mode=1: 居中裁剪正方形 */
        $qiniuParams = 'imageView2/0/w/' . $w . '/q/' . $q . $fmtParam;
        /* 追加到 URL（不影响多吉云 auth_key，因为 auth_key 基于 path 签名）
         * 历史数据里可能已经存了带 imageView2 / auth_key 的 URL（相册封面、正文图），
         * 先剥掉旧的处理参数和旧签名，避免出现两个 imageView2 和一个过期 auth_key。 */
        $srcBase = $src;
        $srcQuery = (string) @parse_url($src, PHP_URL_QUERY);
        if ($srcQuery !== '' && (strpos($srcQuery, 'imageView2') !== false || strpos($srcQuery, 'auth_key=') !== false)) {
            $triKeepQ = array();
            foreach (explode('&', $srcQuery) as $triKv) {
                if ($triKv === '' || strpos($triKv, 'imageView2') === 0 || strpos($triKv, 'auth_key=') === 0) { continue; }
                $triKeepQ[] = $triKv;
            }
            $srcBase = substr($src, 0, (int) strpos($src, '?')) . ($triKeepQ ? '?' . implode('&', $triKeepQ) : '');
        }
        $sep = (strpos($srcBase, '?') !== false) ? '&' : '?';
        $srcProcessed = $srcBase . $sep . $qiniuParams;
        return function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($srcProcessed) : $srcProcessed;
    }
    if (!tri3_img_host_allowed($host)) {
        return $src;
    }
    /* v1.2.50：本站、且已经落在主题 cache/ 目录下的图，是主题自己预生成的缩略图
     * （如 cache/bangumi-img/*.jpg，128px）。它们已是最小尺寸，代理提供不了任何
     * 优化，却要为每张图付出「一个 PHP 进程 + 一次回环 HTTP 抓本机文件 + 一次解码
     * 重编码 + 一次落盘」；首页一次就有十几张，会直接占满 php-fpm 进程池，把紧随其
     * 后的导航请求挤掉（表现为 REFUSED_STREAM / 零字节失败 -> Swup 整页刷新）。
     * 这类文件直接输出原地址，交给静态服务与浏览器/Service Worker 缓存。
     * 路径由 themeUrl 推导，不写死。 */
    $triThemePath = rtrim((string) @parse_url((string) Helper::options()->themeUrl, PHP_URL_PATH), '/');
    if ($triThemePath !== '' && $triThemePath !== '/') {
        $triSrcPath = (string) @parse_url($src, PHP_URL_PATH);
        if ($triSrcPath !== '' && strpos($triSrcPath, $triThemePath . '/cache/') === 0) {
            return $src;
        }
    }
    /* 总开关关闭＝直接输出原图：不再生成代理地址（旧版仍会生成，每条图片多一次 302） */
    if (!tri3_img_proxy_on()) {
        return function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($src) : $src;
    }
    $base = (string) Helper::options()->themeUrl;
    $base = rtrim($base, '/');
    $triF = $fmt === 'webp' ? 'webp' : 'auto';
    if (!tri3_img_webp_on()) { $triF = 'jpeg'; }
    return $base . '/api/img.php?src=' . rawurlencode($src) . '&w=' . max(32, min((int) $w, 2000)) . '&q=' . tri3_img_default_q() . '&f=' . $triF;
}

function tri3_img_proxy_html($html)
{
    if ($html === '' || strpos($html, '<img') === false) {
        return $html;
    }
    return preg_replace_callback('/<img([^>]*?)\bsrc\s*=\s*["\']([^"\']+)["\']([^>]*)>/i', function ($m) {
        $src = trim($m[2]);
        if ($src === '' || strpos($src, 'data:') === 0 || strpos($src, 'img.php') !== false) {
            return $m[0];
        }
        $proxy = tri3_img_proxy_url($src, 800, 'auto');
        if ($proxy === $src) {
            return $m[0];
        }
        $attrs = trim($m[1] . ' ' . $m[3]);
        /* 灯箱原图：记录原图 URL，前端灯箱优先用 data-original（大图），列表用代理图 */
        $orig = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url($src) : $src;
        if (stripos($attrs, 'data-original=') === false) { $attrs .= ' data-original="' . htmlspecialchars($orig, ENT_QUOTES, 'UTF-8') . '"'; }
        if (stripos($attrs, 'loading=') === false) { $attrs .= ' loading="lazy"'; }
        if (stripos($attrs, 'decoding=') === false) { $attrs .= ' decoding="async"'; }

        return '<img' . ($attrs !== '' ? ' ' . $attrs : '') . ' src="' . htmlspecialchars($proxy, ENT_QUOTES, 'UTF-8') . '">';
    }, $html);
}

/* ==================== AI 文章摘要 ====================
 * 复用「豆包 AI 语义过滤」的 Key / 模型 / 接口配置；
 * 未填 Key 或未启用 AI 时自动跳过，不影响任何现有功能。
 * 摘要写入文章 excerpt 字段（Typecho 原生摘要框），
 * 前台列表 / 融合模式自动优先显示该字段。 */

/** AI 摘要是否可用（配置齐全才算可用） */
function tri3_ai_summary_available(): bool
{
    if ((int) tri_opt('aiSummaryEnable', '1') !== 1) {
        return false;
    }
    $k = trim((string) tri_opt('spamAiKey', ''));
    $m = trim((string) tri_opt('spamAiModel', ''));
    $e = trim((string) tri_opt('spamAiEndpoint', ''));
    return $k !== '' && $m !== '' && $e !== '';
}

/** 通用豆包 AI 对话：成功返回文本，失败 / 未配置返回 null */
function tri3_ai_chat(string $system, string $user, int $timeout = 20): ?string
{
    if (!tri3_ai_summary_available()) {
        return null;
    }
    $payload = array(
        'model'        => trim((string) tri_opt('spamAiModel', '')),
        'messages'     => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
        'temperature'  => 0.4,
        'max_tokens'   => 400,
        'stream'       => false,
        /* 关闭深度思考：摘要等生成任务无需长推理，2-3 秒即可返回（不支持的模型会自动忽略该参数） */
        'thinking'     => array('type' => 'disabled'),
    );
    $ch = curl_init(trim((string) tri_opt('spamAiEndpoint', '')));
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(5, min(60, $timeout)),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim((string) tri_opt('spamAiKey', '')),
        ),
    ));
    $resp = curl_exec($ch);
    $curlErr = (string) curl_error($ch);
    unset($ch);
    if ($resp === false || $resp === '') {
        tri3_debug_log('ai-summary', ' CHAT_NET cid-err=' . $curlErr);
        return null;
    }
    $j = json_decode((string) $resp, true);
    if (!is_array($j) || isset($j['error'])) {
        $errMsg = is_array($j) && isset($j['error']) ? (string) ($j['error']['message'] ?? '') : substr((string) $resp, 0, 200);
        tri3_debug_log('ai-summary', ' CHAT_API ' . $errMsg);
        return null;
    }
    $content = trim((string) ($j['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        tri3_debug_log('ai-summary', ' CHAT_EMPTY resp=' . substr((string) $resp, 0, 200));
        return null;
    }
    /* 去掉可能的 markdown 代码块包裹 */
    if (preg_match('/```(?:[a-z]*)\n?(.*?)```/s', $content, $m)) {
        $content = trim($m[1]);
    }
    return $content;
}

/** 为文章生成 AI 摘要并写入 excerpt 字段；成功返回摘要文本，否则返回 null */
function tri3_ai_summary_for_post($cid, $title, $contentText): ?string
{
    $cid = (int) $cid;
    if ($cid <= 0 || !tri3_ai_summary_available()) {
        return null;
    }
    $len = max(30, min(300, (int) tri_opt('aiSummaryLen', '45')));
    /* 正文纯文本：去 HTML / 短代码 / 压缩空白 */
    $plain = trim(strip_tags((string) $contentText));
    $plain = preg_replace('/\{[^{}]*\}/', '', $plain);
    $plain = preg_replace('/\[\/?[a-z][a-z0-9-]*(?:\s[^\]]*)?\]/i', '', $plain);
    $plain = preg_replace('/\s+/', ' ', $plain);
    if (function_exists('mb_substr')) {
        $plain = mb_substr($plain, 0, 3000, 'UTF-8');
    } else {
        $plain = substr($plain, 0, 3000);
    }
    if (trim($plain) === '') {
        return null;
    }
    $title = trim((string) $title);
    $sys = '你是博客助手，为文章生成简洁、准确的中文摘要。只输出摘要正文本身，不要标题、不要引号、不要前后缀、不要 emoji、不要省略号结尾。摘要需概括文章核心内容，通顺自然，适合放在文章列表卡片上。';
    $user = "文章标题：{$title}\n\n正文：\n{$plain}\n\n请用约 {$len} 个字生成摘要：";
    $out = null;
    for ($triTry = 0; $triTry < 3; $triTry++) {
        $out = tri3_ai_chat($sys, $user, 25);
        if ($out !== null) { break; }
        usleep(300000); /* 300ms 后重试，最多 3 次 */
    }
    if ($out === null) {
        tri3_debug_log('ai-summary', ' SUMMARY_FAIL cid=' . $cid . ' plainLen=' . strlen($plain));
        return null;
    }
    $out = trim($out, "\"'“”《》 \t\r\n");
    if ($out === '') {
        return null;
    }
    /* 写入 excerpt 字段 */
    try {
        $db = Typecho_Db::get();
        $exists = $db->fetchRow(
            $db->select()->from('table.fields')
                ->where('cid = ? AND name = ?', $cid, 'excerpt')
        );
        if ($exists) {
            $db->query($db->update('table.fields')
                ->rows(array('str_value' => $out))
                ->where('cid = ? AND name = ?', $cid, 'excerpt'));
        } else {
            $db->query($db->insert('table.fields')
                ->rows(array(
                    'cid'        => $cid,
                    'name'       => 'excerpt',
                    'type'       => 'str',
                    'str_value'  => $out,
                    'int_value'  => 0,
                    'float_value'=> 0,
                )));
        }
    } catch (Exception $e) {
        return null;
    }
    return $out;
}

/** 文章发布 / 编辑保存钩子：无手填摘要时自动生成 AI 摘要（失败静默，不影响发布） */
if (class_exists('Typecho_Plugin')) {
    Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = function ($contents, $post) {
        try {
            if (!tri3_ai_summary_available()) {
                return;
            }
            $cid = (int) ($post->cid ?? 0);
            if ($cid <= 0) {
                return;
            }
            /* 已有摘要（手填或已生成）则跳过 */
            $db = Typecho_Db::get();
            $exists = $db->fetchRow(
                $db->select()->from('table.fields')
                    ->where('cid = ? AND name = ?', $cid, 'excerpt')
            );
            if ($exists && trim((string) $exists['str_value']) !== '') {
                return;
            }
            $title = (string) ($contents['title'] ?? '');
            $text  = (string) ($contents['text'] ?? '');
            if ($title === '') {
                $title = (string) ($post->title ?? '');
            }
            if ($text === '') {
                $text = (string) ($post->content ?? '');
            }
            tri3_ai_summary_for_post($cid, $title, $text);
        } catch (Throwable $e) {
            /* AI 摘要失败不影响发布 */
        }
    };
}

if (is_file(__DIR__ . '/ri-audit.php')) { require_once __DIR__ . '/ri-audit.php'; }
