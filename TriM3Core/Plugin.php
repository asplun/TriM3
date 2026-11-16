<?php
/**
 * TriM3Core —— TriM3 主题配套核心插件（Joe 编辑器 + 原生评论过滤桥）
 *
 * 合并自 TriJoeEditor 与 Tri3SpamBridge，作为 TriM3 主题的「必备」配套插件：
 *
 *   ① Joe 编辑器：后台「写文章 / 写页面」替换为最新版 Joe 编辑器（CodeMirror 6），
 *      编辑器中短代码（回复可见 / 音乐卡片 / 歌单）与 TriM3 主题前台联动；
 *      编辑器资源位于主题目录 TriM3/assets/typecho/write/ 下，需保持主题启用。
 *
 *   ② 评论过滤桥：Typecho 原生评论提交（文章/页面 permalink/comment 路由）
 *      不加载主题 functions.php，本插件为 Widget_Feedback 注册过滤钩子，
 *      使原生评论同样受主题「规则引擎 + 豆包 AI」过滤与 IP 限速保护，
 *      防止垃圾机器人绕过前端 JS 直接 POST 原生端点刷评。
 *
 * @package TriM3Core
 * @author 薄荷
 * @version 1.2.6
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class TriM3Core_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件：注册后台编辑器钩子 + 评论过滤桥钩子
     * （Typecho 每次请求都会重新调用 activate，钩子在每个请求都有效）
     */
    public static function activate()
    {
        /* ① 后台写文章 / 写页面：替换为 Joe 编辑器 */
        Typecho\Plugin::factory('admin/write-post.php')->richEditor = array(
            'TriM3Core_Plugin', 'renderEditor'
        );
        Typecho\Plugin::factory('admin/write-page.php')->richEditor = array(
            'TriM3Core_Plugin', 'renderEditor'
        );
        /* ② 评论完成后写 cookie，供前台定位新评论锚点（跳转刚发布的评论） */
        Typecho\Plugin::factory('Widget_Feedback')->finishComment = array(
            'TriM3Core_Plugin', 'markLastComment'
        );
        /* ③ 原生评论过滤桥：Widget_Feedback 提交前过滤（规则引擎 + 豆包 AI + 限速）
         * Typecho 1.3 中 Widget_Feedback::comment() 以 filter('comment', $comment, $content)
         * 触发，钩子 key 为 Widget_Feedback:comment，故必须注册到 'comment' 组件；
         * 注册到 'filter' 组件不会被触发（原 Tri3SpamBridge 写法在 1.3 上失效）。
         * 回调收到参数 ($comment, $content, $comment)。
         */
        Typecho\Plugin::factory('Widget_Feedback')->comment = array(
            'TriM3Core_Plugin', 'spamFilter'
        );
        return 'TriM3Core 已启用：后台 Joe 编辑器与原生评论过滤桥均已接管。';
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
        return 'TriM3Core 已停用，已恢复 Typecho 原版编辑器与默认评论行为。';
    }

    /**
     * 插件配置（无）
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 个人配置（无）
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /* ==================== ① Joe 编辑器 ==================== */

    /**
     * 评论完成钩子：写 cookie 记录刚发布评论的 coid，
     * 前台页面加载时据此滚动并高亮新评论（配合 TriM3 主题 comments.php）
     *
     * @param Widget_Feedback $widget 评论组件（此时已 push 新评论，coid 可用）
     */
    public static function markLastComment($widget)
    {
        try {
            $coid = isset($widget->coid) ? (int) $widget->coid : 0;
            if ($coid > 0) {
                @setcookie('tri_last_comment', (string) $coid, 0, '/');
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * 获取当前启用主题的资源 URL（保证以 / 结尾）
     */
    private static function themeUrl()
    {
        $url = '';
        try {
            $url = trim((string) Typecho_Widget::widget('Widget_Options')->themeUrl);
        } catch (\Exception $e) {
        }
        if ($url === '') {
            return '';
        }
        return rtrim($url, '/') . '/';
    }

    /**
     * 输出 Joe 编辑器资源（richEditor 钩子回调）
     */
    public static function renderEditor()
    {
        $themeUrl = self::themeUrl();
        if ($themeUrl === '') {
            return;
        }
        $w = $themeUrl . 'assets/typecho/write/';
        $lib = $themeUrl . 'assets/lib/';

        /* 上传接口：Typecho 原生 /action/upload */
        $uploadAPI = '';
        try {
            $uploadAPI = Typecho_Widget::widget('Widget_Security')->getIndex('/action/upload');
        } catch (\Exception $e) {
        }
        /* 自动保存：Joe 的 AutoSave 依赖后台按钮 #btn-save，默认关闭 */
        $autoSave = 0;
        /* 视频解析接口（编辑器预览 {dplayer} 用），TriM3 前台自行渲染 */
        $playerAPI = '';

        echo '<link rel="stylesheet" href="' . $lib . 'APlayer@1.10.1/APlayer.min.css">' . "\n";
        echo '<link rel="stylesheet" href="' . $lib . 'prism/prism.min.css">' . "\n";
        echo '<link rel="stylesheet" href="' . $w . 'css/joe.write.min.css">' . "\n";
        echo '<script>' . "\n";
        echo 'window.JoeConfig = {' . "\n";
        echo '  uploadAPI: ' . json_encode($uploadAPI) . ',' . "\n";
        echo '  emojiAPI: ' . json_encode($w . 'json/emoji.json') . ',' . "\n";
        echo '  expressionAPI: ' . json_encode($w . 'json/expression.json') . ',' . "\n";
        echo '  characterAPI: ' . json_encode($w . 'json/character.json') . ',' . "\n";
        echo '  playerAPI: ' . json_encode($playerAPI) . ',' . "\n";
        echo '  autoSave: ' . json_encode($autoSave) . ',' . "\n";
        echo '  themeURL: ' . json_encode($themeUrl) . ',' . "\n";
        echo '  canPreview: true' . "\n";
        echo '};' . "\n";
        echo '</script>' . "\n";
        echo '<script src="' . $lib . 'APlayer@1.10.1/APlayer.min.js"></script>' . "\n";
        echo '<script src="' . $lib . 'prism/prism.min.js"></script>' . "\n";
        echo '<script src="' . $w . 'parse/parse.min.js"></script>' . "\n";
        echo '<script src="' . $w . 'dist/index.bundle.js?v=3"></script>' . "\n";
    }

    /* ==================== ② 原生评论过滤桥 ==================== */

    /**
     * Widget_Feedback 提交前过滤：原生评论通道同样受主题过滤链路保护
     *
     * @param array $comment 评论数据
     * @param mixed $content 内容组件
     * @return array
     */
    public static function spamFilter($comment, $content)
    {
        try {
            /* 幂等加载主题过滤函数（主题为当前启用主题时） */
            if (!function_exists('tri3_spam_check')) {
                $triOpt = \Typecho\Widget::widget('Widget_Options');
                $triTheme = (string) ($triOpt->theme ?? '');
                if ($triTheme !== '') {
                    $triFn = rtrim((string) __TYPECHO_ROOT_DIR__, '/')
                        . '/usr/themes/' . $triTheme . '/functions.php';
                    if (is_file($triFn)) {
                        include_once $triFn;
                    }
                }
            }
            if (!function_exists('tri3_spam_check')) {
                return $comment; /* 主题缺失时放行，不阻塞评论 */
            }

            /* 全局限速（IP 滑动窗口）：原生通道同样限速，防绕过前端直接刷评论 */
            if (function_exists('tri3_comment_rate_limit')) {
                $triRate = tri3_comment_rate_limit();
                if (!$triRate['ok']) {
                    throw new \Typecho\Exception($triRate['msg']);
                }
            }

            /* QQ 字段（与主题 helpers.php 一致） */
            if (function_exists('tri3_ensure_qq_column')) {
                tri3_ensure_qq_column();
            }
            $triQq = preg_replace('/\D/', '', (string) ($_POST['qq'] ?? ''));
            if ($triQq !== '') {
                $comment['qq'] = $triQq;
                @setcookie('tri3_comment_qq', $triQq, time() + 2592000, '/');
            }

            /* 垃圾评论过滤：规则引擎 + 豆包 AI */
            $triSpamData = array(
                'text'   => (string) ($comment['text'] ?? ''),
                'author' => (string) ($comment['author'] ?? ''),
                'mail'   => (string) ($comment['mail'] ?? ''),
                'ip'     => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'agent'  => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'hp'     => (string) ($_REQUEST['tri3_hp'] ?? ''),
            );
            $triR = tri3_spam_check($triSpamData);
            if (!$triR['ok']) {
                if ($triR['pending']) {
                    $comment['status'] = 'waiting';
                    return $comment;
                }
                if ($triR['silent']) {
                    throw new \Typecho\Exception('评论提交失败');
                }
                throw new \Typecho\Exception('评论未通过自动过滤，如有疑问请联系站长');
            }
            $triAi = tri3_spam_ai_check($triSpamData);
            if ($triAi !== null && !$triAi['ok']) {
                if ($triAi['pending']) {
                    $comment['status'] = 'waiting';
                } else {
                    throw new \Typecho\Exception('评论未通过自动过滤，如有疑问请联系站长');
                }
            }
        } catch (\Typecho\Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            /* 过滤引擎自身异常不阻塞评论 */
        }
        return $comment;
    }
}
