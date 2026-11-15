<?php
/**
 * TriM3 · Joe 短代码解析（前台正文）
 *
 * 移植自 HaoOuBa/Joe 主题 core/short.php 的 _parseContent，
 * 将 Joe 编辑器（TriJoeEditor 插件）插入的 {xxx} 短代码转换为
 * joe-* 自定义元素 / 结构，由前台 joe-content.js 渲染为版式。
 * 配合 CodeMirror 编辑器：后台写的内容保存为 markdown + 短代码原文，
 * 输出时本函数在 markdown 渲染（$post->content）之后执行替换。
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 输出文章正文（已做 Joe 短代码转换）
 */
function tri3_joe_content($post)
{
    $content = $post->content;
    /* Typecho 1.3 兜底：若 contents 表缺失 format 列，$post->content 不会解析
       Markdown（直接返回 text 原文）。此处检测 Markdown 原文并在主题内手动渲染，
       不动数据库、不依赖插件；已渲染 HTML（含 <!--markdown--> 标记等）原样通过。 */
    if (tri3_is_raw_markdown($content)) {
        $content = tri3_markdown_render((string) $post->text);
    }
    $login = false;
    try {
        $user = Typecho_Widget::widget('Widget_User');
        if ($user && method_exists($user, 'hasLogin')) {
            $login = (bool) $user->hasLogin();
        }
    } catch (Exception $e) {
        $login = false;
    }
    $content = tri3_joe_parse($content, $post, $login);
    /* 表格排版：服务端把 markdown 裸表格包为 figure.table-figure 滚动容器（与 Joe 编辑器输出结构一致），
       首帧即正确，无需前台 JS 延迟包裹 */
    if (function_exists('tri3_wrap_tables')) {
        $content = tri3_wrap_tables($content);
    }
    /* 图片加载优化：内容图走代理（缩放 + WebP + 懒加载），代理失败自动回原图 */
    if (function_exists('tri3_img_proxy_html')) {
        $content = tri3_img_proxy_html($content);
    }
    /* 供 footer.php 判断是否需要加载 Prism：仅当正文实际含代码块时才加载高亮脚本 */
    if (stripos($content, '<pre') !== false) {
        $GLOBALS['__tri_has_codeblock'] = true;
    }
    echo $content;
}

/**
 * 判断正文是否为「未解析的 Markdown 原文」
 *
 * - 已渲染内容（TriJoeEditor 保存时转换的 <!--markdown--> 标记、已含块级 HTML）→ false
 * - 含行首 Markdown 语法（标题 / 引用 / 列表 / 代码块 / 图片 / 粗体）→ true
 */
function tri3_is_raw_markdown($text): bool
{
    if (!is_string($text) || trim((string) $text) === '') {
        return false;
    }
    $t = ltrim((string) $text);
    /* 保存时已被转换（含 <!--markdown--> 标记），直接输出 */
    if (strpos($t, '<!--markdown-->') === 0) {
        return false;
    }
    /* 行首 Markdown 语法特征（标题 / 引用 / 列表 / 代码块 / 图片 / 分隔线）→ Markdown 原文，
       优先于 HTML 判断：正文可能「手写 HTML 片段 + Markdown 原文」混合，只要出现特征即需解析 */
    if (preg_match('/^(#{1,6}[ \t]|[ \t]{0,3}>[ \t]|[ \t]{0,3}[-*+][ \t]|```|!\[[^\]]*\]\(|~~~|(?:---|___)[ \t]*$)/m', $t)) {
        return true;
    }
    /* 行内强调 / 行内代码 */
    if (strpos($t, '**') !== false || strpos($t, '`') !== false) {
        return true;
    }
    /* 已包含块级 HTML 渲染结果（h1-p-ul 等）且无 Markdown 特征 → 已渲染 */
    if (preg_match('/<(h[1-6]|p|blockquote|pre|ul|ol|table|div)\b/i', $t)) {
        return false;
    }
    return false;
}

/**
 * 手动 Markdown 渲染（Typecho 1.3：Utils\Markdown::convert；旧版：Typecho_Markdown）
 */
function tri3_markdown_render($text): string
{
    if (class_exists('Utils\\Markdown') && method_exists('Utils\\Markdown', 'convert')) {
        return \Utils\Markdown::convert((string) $text);
    }
    if (class_exists('Typecho_Markdown')) {
        return Typecho_Markdown::factory()->convert((string) $text);
    }
    return (string) $text;
}

/**
 * Joe 短代码 → HTML 结构
 */
function tri3_joe_parse($content, $post = null, $login = false)
{
    if (strpos($content, '{lamp/}') !== false) {
        $content = strtr($content, array(
            '{lamp/}' => '<span class="joe_lamp"></span>',
        ));
    }
    if (strpos($content, '{x}') !== false || strpos($content, '{ }') !== false) {
        $content = strtr($content, array(
            '{x}' => '<input type="checkbox" class="joe_checkbox" checked disabled>',
            '{ }' => '<input type="checkbox" class="joe_checkbox" disabled>',
        ));
    }
    if (strpos($content, '{music') !== false) {
        $content = preg_replace('/{music-list([^}]*)\/}/SU', '<joe-mlist $1></joe-mlist>', $content);
        $content = preg_replace('/{music([^}]*)\/}/SU', '<joe-music $1></joe-music>', $content);
    }
    if (strpos($content, '{mp3') !== false) {
        $content = preg_replace('/{mp3([^}]*)\/}/SU', '<joe-mp3 $1></joe-mp3>', $content);
    }
    if (strpos($content, '{bilibili') !== false) {
        $content = preg_replace('/{bilibili([^}]*)\/}/SU', '<joe-bilibili $1></joe-bilibili>', $content);
    }
    if (strpos($content, '{dplayer') !== false) {
        $content = preg_replace('/{dplayer([^}]*)\/}/SU', '<joe-dplayer $1></joe-dplayer>', $content);
    }
    if (strpos($content, '{mtitle') !== false) {
        $content = preg_replace('/{mtitle([^}]*)\/}/SU', '<joe-mtitle $1></joe-mtitle>', $content);
    }
    if (strpos($content, '{abtn') !== false) {
        $content = preg_replace('/{abtn([^}]*)\/}/SU', '<joe-abtn $1></joe-abtn>', $content);
    }
    if (strpos($content, '{cloud') !== false) {
        $content = preg_replace('/{cloud([^}]*)\/}/SU', '<joe-cloud $1></joe-cloud>', $content);
    }
    if (strpos($content, '{anote') !== false) {
        $content = preg_replace('/{anote([^}]*)\/}/SU', '<joe-anote $1></joe-anote>', $content);
    }
    if (strpos($content, '{dotted') !== false) {
        $content = preg_replace('/{dotted([^}]*)\/}/SU', '<joe-dotted $1></joe-dotted>', $content);
    }
    if (strpos($content, '{message') !== false) {
        $content = preg_replace('/{message([^}]*)\/}/SU', '<joe-message $1></joe-message>', $content);
    }
    if (strpos($content, '{progress') !== false) {
        $content = preg_replace('/{progress([^}]*)\/}/SU', '<joe-progress $1></joe-progress>', $content);
    }
    if (strpos($content, '{copy') !== false) {
        $content = preg_replace('/{copy([^}]*)\/}/SU', '<joe-copy $1></joe-copy>', $content);
    }
    if (strpos($content, '{hide') !== false) {
        $hasComment = false;
        try {
            $db = Typecho_Db::get();
            $mail = $post ? $post->remember('mail', true) : null;
            if ($post && isset($post->cid) && $mail) {
                $rows = $db->fetchAll($db->select()->from('table.comments')
                    ->where('cid = ?', $post->cid)
                    ->where('mail = ?', $mail)
                    ->limit(1));
                $hasComment = !empty($rows);
            }
        } catch (Exception $e) {
            $hasComment = false;
        }
        if ($hasComment || $login) {
            $content = preg_replace('/{hide[^}]*}([\s\S]*?){\/hide}/SU', '$1', $content);
        } else {
            $content = preg_replace('/{hide[^}]*}([\s\S]*?){\/hide}/', '<joe-hide></joe-hide>', $content);
        }
    }
    $blocks = array(
        '{card-default' => 'joe-card-default',
        '{callout' => 'joe-callout',
        '{alert' => 'joe-alert',
        '{card-describe' => 'joe-card-describe',
    );
    foreach ($blocks as $open => $tag) {
        if (strpos($content, $open) !== false) {
            $content = preg_replace(
                '/' . preg_quote($open, '/') . '([^}]*)}([\s\S]*?){\/' . str_replace('{', '', $open) . '}/',
                '<section style="margin-bottom:15px"><' . $tag . ' $1><span class="_temp" style="display:none">$2</span></' . $tag . '></section>',
                $content
            );
        }
    }
    if (strpos($content, '{tabs') !== false) {
        $content = preg_replace('/{tabs}([\s\S]*?){\/tabs}/', '<section style="margin-bottom:15px"><joe-tabs><span class="_temp" style="display:none">$1</span></joe-tabs></section>', $content);
    }
    if (strpos($content, '{card-list') !== false) {
        $content = preg_replace('/{card-list}([\s\S]*?){\/card-list}/', '<section style="margin-bottom:15px"><joe-card-list><span class="_temp" style="display:none">$1</span></joe-card-list></section>', $content);
    }
    if (strpos($content, '{timeline') !== false) {
        $content = preg_replace('/{timeline}([\s\S]*?){\/timeline}/', '<section style="margin-bottom:15px"><joe-timeline><span class="_temp" style="display:none">$1</span></joe-timeline></section>', $content);
    }
    if (strpos($content, '{collapse') !== false) {
        $content = preg_replace('/{collapse}([\s\S]*?){\/collapse}/', '<section style="margin-bottom:15px"><joe-collapse><span class="_temp" style="display:none">$1</span></joe-collapse></section>', $content);
    }
    if (strpos($content, '{gird') !== false) {
        $content = preg_replace('/{gird([^}]*)}([\s\S]*?){\/gird}/', '<section style="margin-bottom:15px"><joe-gird $1><span class="_temp" style="display:none">$2</span></joe-gird></section>', $content);
    }
    return $content;
}


/**
 * 服务端把裸 <table>（markdown 表格）包裹为 figure.table-figure 滚动容器，
 * 与 Joe 编辑器输出的表格结构保持一致；已包裹的 figure 跳过，避免二次包裹。
 */
function tri3_wrap_tables($html)
{
    if (!is_string($html) || strpos((string) $html, '<table') === false) {
        return $html;
    }
    $holders = array();
    $html = preg_replace_callback('/<figure\b[^>]*>\s*<table\b.*?<\/table>\s*<\/figure>/is', function ($m) use (&$holders) {
        $k = '%%T3TBL' . count($holders) . '%%';
        $holders[$k] = $m[0];
        return $k;
    }, $html);
    $html = preg_replace_callback('/<table\b.*?<\/table>/is', function ($m) {
        return '<figure class="table-figure">' . $m[0] . '</figure>';
    }, $html);
    foreach ($holders as $k => $v) {
        $html = str_replace($k, $v, $html);
    }
    return $html;
}
