<?php
/**
 * TriM3 图标子集完整性兜底（服务端）
 *
 * 背景：
 *   tri3_ri_need_full() 只检查后台配置的 6 个字段（导航 / 手机菜单 / 首页菜单 /
 *   左右小组件 / 友链）。模板文件里写死的图标不在检测范围内 —— 子集名单一旦
 *   没跟上模板改动，图标会静默显示为空白，而且不会回退到完整图标库。
 *
 * 做法：
 *   header.php 最开头开启输出缓冲，footer.php 结束时扫描「实际渲染出的 HTML」
 *   里所有 <i class="ri-xxx"> 的图标名，与子集名单逐一比对。只要发现一个
 *   子集外、且完整图标库里确实存在的图标，就把子集 CSS 换成完整图标库 CSS，
 *   保证任何来源（模板 / PHP 拼装 / 后台配置）的图标都能正常显示。
 *
 * 说明：
 *   只扫描 <i> 元素的 class，避开主题自身以 ri- 开头的 CSS 钩子类
 *   （如 ri-msg-box / ri-say-emoji / ri-cmt-* 等，它们不需要字形）。
 *   名字在完整图标库里也不存在的，说明是写错的图标名，切换也无意义，直接跳过。
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

if (!function_exists('tri3_ri_log')) {
    /**
     * 内部诊断日志（仅在 DEBUG 开关打开时写文件）
     */
    function tri3_ri_log(string $msg): void
    {
        if (!defined('TRI3_RI_DEBUG') || !TRI3_RI_DEBUG) {
            return;
        }
        if (function_exists('tri3_debug_log')) {
            tri3_debug_log('ri-audit', ' ' . $msg);
        }
    }
}

if (!function_exists('tri3_ri_buf_start')) {
    /**
     * 开启输出缓冲（幂等）
     */
    function tri3_ri_buf_start(): void
    {
        if (defined('TRI3_RI_BUF') || !function_exists('ob_start')) {
            return;
        }
        define('TRI3_RI_BUF', true);
        ob_start();
        $GLOBALS['tri3_ri_ob_base'] = ob_get_level();
        tri3_ri_log('buf_start base=' . ob_get_level());
    }
}

if (!function_exists('tri3_ri_full_names')) {
    /**
     * 完整图标库的类名集合（进程内只读一次）
     *
     * @return array<string,int>
     */
    function tri3_ri_full_names(): array
    {
        static $triFull = null;
        if ($triFull !== null) {
            return $triFull;
        }
        $triFull = array();
        $triCss = __DIR__ . '/../assets/css/remixicon.min.css';
        if (is_file($triCss) && ($triRaw = @file_get_contents($triCss)) !== false) {
            if (preg_match_all('/\.ri-([a-z0-9]+(?:-[a-z0-9]+)*):before/', $triRaw, $triM)) {
                $triFull = array_flip($triM[1]);
            }
        }
        return $triFull;
    }
}

if (!function_exists('tri3_ri_audit_output')) {
    /**
     * 扫描已渲染的 HTML，必要时把子集图标库换成完整图标库
     */
    function tri3_ri_audit_output(): void
    {
        if (!defined('TRI3_RI_BUF')) {
            tri3_ri_log('RET no-flag');
            return;
        }
        $triBase = isset($GLOBALS['tri3_ri_ob_base']) ? (int) $GLOBALS['tri3_ri_ob_base'] : 0;
        /* 缓冲层级必须与开启时一致，避免破坏其它局部缓冲 */
        if (ob_get_level() !== $triBase) {
            tri3_ri_log('RET level ' . ob_get_level() . ' != base ' . $triBase);
            return;
        }
        $triHtml = ob_get_contents();
        if (!is_string($triHtml) || $triHtml === '') {
            tri3_ri_log('RET empty buffer');
            return;
        }
        /* 已经是完整图标库，无需处理 */
        if (strpos($triHtml, 'remixicon-subset.min.css') === false) {
            tri3_ri_log('RET no subset ref, len=' . strlen($triHtml));
            return;
        }
        $triSubFile = __DIR__ . '/ri-subset-list.php';
        if (!is_file($triSubFile)) {
            tri3_ri_log('RET no subset list file');
            return;
        }
        $triSet = array_flip(require $triSubFile);
        $triFullSet = tri3_ri_full_names();

        $triNeed = false;
        $triHit = '';
        if (preg_match_all('/<i\b[^>]*\bclass\s*=\s*(["\'])([^"\']*)\1/i', $triHtml, $triM)) {
            foreach ($triM[2] as $triCls) {
                if (!$triCls || strpos($triCls, 'ri-') === false) {
                    continue;
                }
                if (preg_match_all('/\bri-([a-z0-9]+(?:-[a-z0-9]+)*)/', $triCls, $triNames)) {
                    foreach ($triNames[1] as $triName) {
                        if (isset($triSet[$triName])) {
                            continue;
                        }
                        /* 完整图标库里也没有 → 写错的图标名，切库也救不了，跳过 */
                        if ($triFullSet && !isset($triFullSet[$triName])) {
                            continue;
                        }
                        $triNeed = true;
                        $triHit = $triName;
                        break 2;
                    }
                }
            }
        }
        tri3_ri_log('scan len=' . strlen($triHtml) . ' iTags=' . count($triM[2]) . ' need=' . ($triNeed ? 'Y' : 'N') . ' hit=' . $triHit);
        if (!$triNeed) {
            return;
        }
        $triNew = str_replace('remixicon-subset.min.css', 'remixicon.min.css', $triHtml);
        ob_clean();
        echo $triNew;
        tri3_ri_log('SWAPPED okl=' . ob_get_level() . ' newlen=' . strlen($triNew));
    }
}
