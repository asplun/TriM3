<?php
/**
 * TriM3 后台设置面板（控制台 - 外观 - 设置外观）
 *
 * 采用 Tab 卡片式分组界面：6 个分组，颜色字段自动转为取色器，
 * MD3 主色自动推导 Secondary / Tertiary / Container 配色。
 * 面板交互脚本独立于 assets/js/admin.min.js（含 js/icons.js 图标库）。
 *
 * @package TriM3
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/* ============================================================
 * 相册管理操作入口（传统 GET/POST 表单提交，位于 Typecho 后台
 * 页面内执行，天然受后台登录保护；处理完成后跳回设置面板）
 * ============================================================ */
if (isset($_GET['tri_do']) || isset($_POST['tri_do'])) {
    // 安全修复：必须是管理员才能执行 tri_do
    $triUser = Typecho\Widget::widget('Widget_User');
    if (!$triUser->hasLogin() || !$triUser->pass('administrator', false)) {
        http_response_code(403);
        exit('Forbidden');
    }

    /* 安全门禁（CSRF）：tri_do 是写操作，必须来自本站页面。
       阻断外站表单、<img src>、以及在别人站点里预置的自动提交，避免管理员被借权限。 */
    $_triRefSource = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : (isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '');
    $_triSiteUrl = (string) Typecho\Widget::widget('Widget_Options')->siteUrl;
    $_triSiteHost = strtolower((string) parse_url($_triSiteUrl, PHP_URL_HOST));
    $_triRefHost = strtolower((string) parse_url($_triRefSource, PHP_URL_HOST));
    if ($_triRefSource === '' || $_triSiteHost === '' || $_triRefHost !== $_triSiteHost) {
        http_response_code(403);
        exit('Forbidden (CSRF)');
    }
    require_once __DIR__ . '/album.php';
    tri_album_ensure_tables();
    $triDo = isset($_POST['tri_do']) ? (string) $_POST['tri_do'] : (isset($_GET['tri_do']) ? (string) $_GET['tri_do'] : '');
    $triAjax = isset($_GET['tri_ajax']) || isset($_POST['tri_ajax']);
    $triSite = rtrim((string) Typecho\Widget::widget('Widget_Options')->siteUrl, '/');
    $triThemeName = (string) Typecho\Widget::widget('Widget_Options')->theme;
    $triBack = '/admin/options-theme.php?theme=' . $triThemeName;
    if (isset($_GET['tri_view']) || isset($_POST['tri_view'])) {
        $triBack .= '&tri_view=' . (int) ($_POST['tri_view'] ?? $_GET['tri_view']);
    }
    $triBack .= '#album';

    /* ============ 评论过滤规则自检（基于已保存配置） ============ */
    if ($triDo === 'spam_test') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        if (!function_exists('tri3_spam_check')) {
            require_once __DIR__ . '/helpers.php';
        }
        $triTestText = (string) ($_POST['text'] ?? '');
        $triSpamData = array(
            'text'   => $triTestText,
            'author' => (string) ($_POST['author'] ?? '测试用户'),
            'mail'   => (string) ($_POST['mail'] ?? ''),
            'ip'     => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'agent'  => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'hp'     => '',
        );
        $triR = tri3_spam_check($triSpamData);
        /* AI 层自检：未启用 / 未填 Key / 调用失败 都会给出明确提示，不再静默 */
        $triAi = 'AI 未启用（垃圾评论过滤 → AI 开关未开或未填 API Key）';
        $triS0 = tri3_spam_settings();
        if ($triS0['aiEnable'] && $triS0['aiKey'] !== '') {
            $triAiR0 = tri3_spam_ai_check($triSpamData);
            if ($triAiR0 === null) {
                $triAi = 'AI 已启用但未参与本次判定';
            } elseif (!empty($triAiR0['error'])) {
                $triAi = 'AI 调用失败（已转待审核）：' . $triAiR0['error'];
            } else {
                $triAi = $triAiR0['ok'] ? 'AI 判定：放行' : 'AI 判定：' . ($triAiR0['pending'] ? '待审核' : '直接拒绝');
            }
        }
        echo json_encode(array(
            'ok'    => true,
            'result'=> $triR['ok'] ? '放行' : ($triR['pending'] ? '待审核' : ($triR['silent'] ? '静默丢弃' : '直接拒绝')),
            'rule'  => $triR['reason'] !== '' ? $triR['reason'] : '未命中任何规则',
            'ai'    => $triAi,
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============ AI 文章摘要：批量生成（每批 5 篇，跳过已有摘要） ============ */
    if ($triDo === 'ai_summary_batch') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        if (!Typecho\Widget::widget('Widget_User')->hasLogin()) {
            echo json_encode(array('ok' => false, 'msg' => '登录状态已失效，请刷新页面后重试'));
            exit;
        }
        if (!function_exists('tri3_ai_summary_for_post')) {
            require_once __DIR__ . '/helpers.php';
        }
        try {
            $db = Typecho_Db::get();
            $hasRows = $db->fetchAll(
                $db->select('cid')->from('table.fields')
                    ->where('name = ?', 'excerpt')
                    ->where('str_value != ?', '')
            );
            $hasCids = array_map('intval', array_column($hasRows, 'cid'));
            $select = $db->select('cid', 'title', 'text')->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('table.contents.cid', Typecho_Db::SORT_ASC)
                ->limit(1);
            if ($hasCids) {
                $select->where('cid NOT IN ?', $hasCids);
            }
            $posts = $db->fetchAll($select);
            $done = 0; $fail = 0; $failCids = array();
            foreach ($posts as $p) {
                $r = tri3_ai_summary_for_post((int) $p['cid'], (string) $p['title'], (string) $p['text']);
                if ($r !== null) { $done++; } else { $fail++; $failCids[] = (int) $p['cid']; }
            }
            if ($failCids) {
                tri3_debug_log('ai-summary', ' BATCH fail cids=' . implode(',', $failCids));
            }
            /* 剩余数量 */
            $total = $db->fetchObject(
                $db->select(array('COUNT(cid)' => 'num'))->from('table.contents')
                    ->where('type = ?', 'post')
                    ->where('status = ?', 'publish')
            );
            $totalN = (int) ($total->num ?? 0);
            $remain = max(0, $totalN - count($hasCids) - $done);
            echo json_encode(array(
                'ok' => true,
                'done' => true,
                'okNum' => $done,
                'fail' => $fail,
                'remaining' => $remain,
                'failCids' => $failCids,
                'msg' => '本批完成',
            ), JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(array('ok' => false, 'msg' => '批量生成异常：' . $e->getMessage()));
        }
        exit;
    }

    /* ============ 无刷新保存（后台 无刷新保存）：前端收集字段 → JSON → 写库 → 原地提示 ============ */
    if ($triDo === 'save_cfg') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        if (!Typecho\Widget::widget('Widget_User')->hasLogin()) {
            echo json_encode(array('ok' => false, 'msg' => '登录状态已失效，请刷新页面后重试'));
            exit;
        }
        $triRaw = (string) ($_POST['tri_cfg'] ?? '');
        $triCfg = json_decode($triRaw, true);
        if (!is_array($triCfg)) {
            echo json_encode(array('ok' => false, 'msg' => '数据格式错误，未保存任何修改'));
            exit;
        }
        /* 白名单：全部面板字段（与 panelMap 一致），防止无关参数写入 */
        $triAllKeys = array(
            'authorName', 'authorBio', 'authorSignature', 'profileExtras', 'logoUrl', 'faviconUrl', 'avatarUrl', 'footerHtml', 'github', 'weibo', 'wechat', 'email', 'rssEnable', 'likesNum', 'viewsNum', 'showLikes', 'showComments', 'showViews', 'statsMode', 'statsCustomHtml',
            'pjaxEnable', 'bannerEnable', 'bannerUrls', 'bannerInterval', 'bannerSearch', 'leftSidebarEnable', 'rightSidebarEnable', 'leftWidth', 'mainWidth', 'rightWidth', 'excerptLen', 'bannerHeight', 'paginationStyle', 'pageSize', 'homeListMode', 'postCardStyle', 'fusionExcerpt', 'noticeText',
            'leftFixed', 'navNarrow', 'navItems', 'mobileMenu', 'homeMenu', 'homeMenuCats',
            'primaryColor', 'secondaryColor', 'tertiaryColor', 'primaryContainerColor', 'onPrimaryContainerColor', 'onPrimaryColor', 'dynamicColor', 'bgColor', 'bgGradient', 'bgGradStyle', 'glassEffect', 'radiusScale', 'darkMode', 'siteFont', 'tri3_404Compat',
            'playerEnable', 'playerSource', 'metingPlatform', 'metingResourceType', 'metingResourceId', 'metingApi', 'metingCookieNetease', 'metingCookieTencent', 'playlist', 'musicPlaylists', 'playerVolume', 'playerLyric', 'playerLyricDefault',
            'friends', 'friendGroups', 'friendApply', 'shotCacheDays',
            'albumApiType', 'albumApiUrl', 'albumApiToken', 'albumApiFileField', 'albumApiUrlPath', 'albumApiDomain', 'upyunTokenSecret', 'upyunTokenTtl', 'qiniuAccessKey', 'qiniuSecretKey', 'qiniuBucket', 'qiniuRegion', 'qiniuDomain', 'qiniuPrefix', 'qiniuTokenSecret', 'qiniuTokenTtl',
            'doubanUid', 'doubanApi', 'doubanTypes', 'doubanCount', 'doubanCache', 'doubanShowFilter', 'doubanShowRemark', 'doubanShowRating', 'doubanShowSearch', 'doubanCacheCover',
            'biliUid', 'biliType', 'biliCount', 'biliCache', 'biliShowSearch', 'biliShowFilter', 'biliShowRemark', 'biliCacheCover',
            'mailEnable', 'mailMode', 'smtpHost', 'smtpPort', 'smtpSecure', 'smtpUser', 'smtpPass', 'mailFromName', 'mailFromAddr', 'mailAdminTo', 'mailNotifyAdmin', 'mailReplyNotify',
            'spamEnable', 'spamMode', 'spamKeywords', 'spamMaxLinks', 'spamMinLen', 'spamHp', 'spamIpList', 'spamMailList', 'spamAction', 'spamRateEnable', 'spamRateMin', 'spamRateTen', 'spamAiEnable', 'spamAiKey', 'spamAiModel', 'spamAiEndpoint', 'spamAiScope', 'spamAiAction', 'spamAiTimeout',
            'aiSummaryEnable', 'aiSummaryLen',
            'seoEnable', 'seoSiteName', 'seoSiteDesc', 'seoSiteKeywords', 'seoSiteUrl', 'seoCanonical', 'seoOg', 'seoTwitter', 'seoJsonLd', 'seoBaiduVer', 'seoGoogleVer', 'seoBingVer', 'seoHeaderHtml',
        );
        $triSafe = array();
        foreach ($triAllKeys as $triK) {
            if (!array_key_exists($triK, $triCfg)) { continue; }
            $triV = $triCfg[$triK];
            if (is_array($triV)) {
                $triNV = array();
                foreach ($triV as $triOne) {
                    if (is_string($triOne) || is_int($triOne) || is_float($triOne) || is_bool($triOne)) {
                        $triNV[] = (string) $triOne;
                    }
                }
                $triSafe[$triK] = $triNV;
            } elseif (is_string($triV) || is_int($triV) || is_float($triV) || is_bool($triV) || is_null($triV)) {
                $triSafe[$triK] = $triV;
            }
        }
        /* 显式处理动态色开关：未传值时默认关闭 */
        if (!isset($triSafe['dynamicColor'])) { $triSafe['dynamicColor'] = '0'; }
        $triDb = Typecho\Db::get();
        $triRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', 'theme:' . $triThemeName));
        $triOld = array();
        if ($triRow && isset($triRow['value'])) {
            $triOld = json_decode((string) $triRow['value'], true);
            if (!is_array($triOld)) { $triOld = array(); }
        }
        $triMerged = array_merge($triOld, $triSafe);
        try {
            if ($triRow) {
                $triDb->query($triDb->update('table.options')->rows(array('value' => json_encode($triMerged, JSON_UNESCAPED_UNICODE)))->where('name = ?', 'theme:' . $triThemeName));
            } else {
                $triDb->query($triDb->insert('table.options')->rows(array('name' => 'theme:' . $triThemeName, 'user' => 0, 'value' => json_encode($triMerged, JSON_UNESCAPED_UNICODE))));
            }
            echo json_encode(array('ok' => true, 'saved' => count($triSafe), 'msg' => '设置已保存'));
        } catch (Exception $e) {
            echo json_encode(array('ok' => false, 'msg' => '保存失败：' . $e->getMessage()));
        }
        exit;
    }

    /* ============ 主题数据备份 / 导入 / 重置 ============ */
    if ($triDo === 'export') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        /* 显式处理动态色开关：未传值时默认关闭 */
        if (!isset($triSafe['dynamicColor'])) { $triSafe['dynamicColor'] = '0'; }
        $triDb = Typecho\Db::get();
        $triPf = $triDb->getPrefix();
        $triCfg = array();
        $triOptRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', 'theme:' . $triThemeName));
        if ($triOptRow && isset($triOptRow['value'])) {
            $triCfg = json_decode((string) $triOptRow['value'], true);
            if (!is_array($triCfg)) { $triCfg = array(); }
        }
        $triRow = function ($n) use ($triDb) {
            $r = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', $n));
            return ($r && isset($r['value'])) ? $r['value'] : null;
        };
        $triMoments = $triDb->fetchAll($triDb->query("SELECT * FROM {$triPf}contents WHERE type='moment' ORDER BY cid ASC"));
        $triMids = array();
        foreach ($triMoments as $triM) { $triMids[] = (int) $triM['cid']; }
        $triMComments = array();
        if (!empty($triMids)) {
            $triMComments = $triDb->fetchAll($triDb->query("SELECT * FROM {$triPf}comments WHERE cid IN (" . implode(',', $triMids) . ") ORDER BY coid ASC"));
        }
        $triAlbums = $triDb->fetchAll($triDb->query("SELECT * FROM {$triPf}albums ORDER BY aid ASC"));
        $triPhotos = $triDb->fetchAll($triDb->query("SELECT * FROM {$triPf}photos ORDER BY pid ASC"));
        $triPack = array(
            'app'             => 'TriM3',
            'type'            => 'typecho-theme-backup',
            'version'         => '1.0',
            'exported'        => date('Y-m-d H:i:s'),
            'site'            => rtrim((string) Typecho\Widget::widget('Widget_Options')->siteUrl, '/'),
            'config'          => $triCfg,
            'widgets'         => array('left' => $triRow('widgetsLeft'), 'right' => $triRow('widgetsRight')),
            'moments'         => $triMoments,
            'momentComments'  => $triMComments,
            'albums'          => $triAlbums,
            'photos'          => $triPhotos,
        );
        $triJson = json_encode($triPack, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="tri3-backup-' . date('Ymd-His') . '.json"');
            header('Content-Length: ' . strlen($triJson));
        }
        echo $triJson;
        exit;
    }
    if ($triDo === 'import') {
        $triMsg = 'import_fail';
        $triBakFile = $_FILES['backup_file'] ?? null;
        if ($triBakFile && ($triBakFile['error'] ?? 1) === UPLOAD_ERR_OK && isset($triBakFile['tmp_name']) && is_uploaded_file($triBakFile['tmp_name'])) {
            $triRaw = (string) @file_get_contents($triBakFile['tmp_name']);
            $triData = json_decode($triRaw, true);
            if (is_array($triData) && (($triData['app'] ?? '') === 'TriM3' || ($triData['type'] ?? '') === 'typecho-theme-backup')) {
                /* 显式处理动态色开关：未传值时默认关闭 */
        if (!isset($triSafe['dynamicColor'])) { $triSafe['dynamicColor'] = '0'; }
        $triDb = Typecho\Db::get();
                $triPf = $triDb->getPrefix();
                $triMsg = 'import_ok';
                /* 1) 主题设置（白名单清洗：仅标量 / 数组值，防注入无关配置） */
                if (isset($triData['config']) && is_array($triData['config'])) {
                    $triSafe = array();
                    foreach ($triData['config'] as $triK => $triV) {
                        if (!is_string($triK) || $triK === '' || strlen($triK) > 64) { continue; }
                        if (is_string($triV) || is_int($triV) || is_float($triV) || is_bool($triV) || is_array($triV) || is_null($triV)) {
                            $triSafe[$triK] = $triV;
                        }
                    }
                    try {
                        $triCfgJson = json_encode($triSafe, JSON_UNESCAPED_UNICODE);
                        $triCfgRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', 'theme:' . $triThemeName));
                        if ($triCfgRow) {
                            $triDb->query($triDb->update('table.options')->rows(array('value' => $triCfgJson))->where('name = ?', 'theme:' . $triThemeName));
                        } else {
                            $triDb->query($triDb->insert('table.options')->rows(array('name' => 'theme:' . $triThemeName, 'user' => 0, 'value' => $triCfgJson)));
                        }
                    } catch (Exception $e) {
                        $triMsg = 'import_fail';
                        tri3_debug_log('import', ' CONFIG: ' . $e->getMessage());
                    }
                }
                /* 2) 左右栏小组件配置 */
                foreach (array('widgetsLeft' => 'left', 'widgetsRight' => 'right') as $triWName => $triWKey) {
                    if (!isset($triData['widgets']) || !array_key_exists($triWKey, $triData['widgets']) || $triData['widgets'][$triWKey] === null) { continue; }
                    $triWVal = $triData['widgets'][$triWKey];
                    $triWJson = is_string($triWVal) ? $triWVal : json_encode($triWVal, JSON_UNESCAPED_UNICODE);
                    try {
                        $triWRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', $triWName));
                        if ($triWRow) {
                            $triDb->query($triDb->update('table.options')->rows(array('value' => $triWJson))->where('name = ?', $triWName));
                        } else {
                            $triDb->query($triDb->insert('table.options')->rows(array('name' => $triWName, 'user' => 0, 'value' => $triWJson)));
                        }
                    } catch (Exception $e) {
                        $triMsg = 'import_fail';
                        tri3_debug_log('import', ' WIDGET: ' . $e->getMessage());
                    }
                }
                /* 3) 说说 + 其评论（先删后插，保留主键，单语句自动提交避免长事务锁） */
                try {
                    $triOldIds = array();
                    foreach ($triDb->fetchAll($triDb->query("SELECT cid FROM {$triPf}contents WHERE type='moment'")) as $triR) {
                        $triOldIds[] = (int) $triR['cid'];
                    }
                    if (!empty($triOldIds)) {
                        $triDb->query("DELETE FROM {$triPf}comments WHERE cid IN (" . implode(',', $triOldIds) . ")");
                    }
                    $triDb->query("DELETE FROM {$triPf}contents WHERE type='moment'");
                    $triCols = array('cid', 'title', 'slug', 'created', 'modified', 'text', 'order', 'authorId', 'template', 'type', 'status', 'password', 'commentsNum', 'allowComment', 'allowPing', 'allowFeed', 'parent');
                    foreach ((array) ($triData['moments'] ?? array()) as $triM) {
                        $triM = (array) $triM;
                        $triRows = array();
                        foreach ($triCols as $triC) {
                            if (array_key_exists($triC, $triM)) { $triRows[$triC] = $triM[$triC]; }
                        }
                        if (empty($triRows['cid'])) { continue; }
                        $triDb->query($triDb->insert('table.contents')->rows($triRows));
                    }
                    $triCCols = array('coid', 'cid', 'created', 'author', 'authorId', 'ownerId', 'mail', 'url', 'ip', 'agent', 'text', 'type', 'status', 'parent', 'qq');
                    foreach ((array) ($triData['momentComments'] ?? array()) as $triCm) {
                        $triCm = (array) $triCm;
                        $triRows = array();
                        foreach ($triCCols as $triC) {
                            if (array_key_exists($triC, $triCm)) { $triRows[$triC] = $triCm[$triC]; }
                        }
                        if (empty($triRows['coid'])) { continue; }
                        $triDb->query($triDb->insert('table.comments')->rows($triRows));
                    }
                } catch (Exception $e) {
                    $triMsg = 'import_fail';
                    tri3_debug_log('import', ' MOMENT: ' . $e->getMessage());
                }
                /* 4) 相册 + 图片（先删后插，保留主键，单语句自动提交避免长事务锁） */
                try {
                    $triDb->query("DELETE FROM {$triPf}photos");
                    $triDb->query("DELETE FROM {$triPf}albums");
                    $triACols = array('aid', 'name', 'description', 'cover', 'sort', 'created');
                    foreach ((array) ($triData['albums'] ?? array()) as $triA) {
                        $triA = (array) $triA;
                        $triRows = array();
                        foreach ($triACols as $triC) {
                            if (array_key_exists($triC, $triA)) { $triRows[$triC] = $triA[$triC]; }
                        }
                        if (empty($triRows['aid'])) { continue; }
                        $triDb->query($triDb->insert('table.albums')->rows($triRows));
                    }
                    $triPCols = array('pid', 'aid', 'url', 'thumb', 'title', 'sort', 'created');
                    foreach ((array) ($triData['photos'] ?? array()) as $triP) {
                        $triP = (array) $triP;
                        $triRows = array();
                        foreach ($triPCols as $triC) {
                            if (array_key_exists($triC, $triP)) { $triRows[$triC] = $triP[$triC]; }
                        }
                        if (empty($triRows['pid'])) { continue; }
                        $triDb->query($triDb->insert('table.photos')->rows($triRows));
                    }
                } catch (Exception $e) {
                    $triMsg = 'import_fail';
                    tri3_debug_log('import', ' ALBUM: ' . $e->getMessage());
                }
            }
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { header('Location: ' . $triSite . '/admin/options-theme.php?theme=' . $triThemeName . '&tri_bak=' . $triMsg . '#backup'); }
        exit;
    }
    if ($triDo === 'reset') {
        Typecho\Db::get()->query(Typecho\Db::get()->delete('table.options')->where('name = ?', 'theme:' . $triThemeName));
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { header('Location: ' . $triSite . '/admin/options-theme.php?theme=' . $triThemeName . '&tri_bak=reset_ok#backup'); }
        exit;
    }
    if ($triDo === 'testmail') {
        $triSite2 = rtrim((string) Typecho\Widget::widget('Widget_Options')->siteUrl, '/');
        $triTo = trim((string) tri_opt('mailAdminTo', ''));
        if ($triTo === '') {
            $triTo = tri3_admin_mail();
        }
        $triCanSend = function_exists('tri3_send_mail') && $triTo !== '' && filter_var($triTo, FILTER_VALIDATE_EMAIL);
        $triOk = false;
        if ($triCanSend) {
            $triOk = tri3_send_mail($triTo, '【' . tri3_site_name() . '】邮件通知测试', tri3_mail_tpl('new', array(
                'author'  => '系统测试',
                'content' => '这是一封测试邮件，说明 SMTP 配置正确，评论通知功能可以正常使用。',
                'time'    => date('Y-m-d H:i'),
                'link'    => $triSite2,
            )));
        }
        if ($triAjax) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(array(
                'ok'  => (bool) $triOk,
                'msg' => $triOk
                    ? '测试邮件已发送，请检查收件箱（含垃圾箱）'
                    : (!$triCanSend ? '尚未配置 SMTP：请先保存服务器 / 端口 / 账号 / 授权码' : '测试邮件发送失败，请检查 SMTP 服务器、端口、账号与授权码')
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
        /* 非 AJAX 降级：修正 URL——tri_mail 必须放在 # 之前，否则参数落在锚点里丢失 */
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { header('Location: ' . $triSite . '/admin/options-theme.php?theme=' . $triThemeName . '&tri_mail=' . ($triOk ? 'ok' : (!$triCanSend ? 'noconfig' : 'fail')) . '#mail'); }
        exit;
    }
    if ($triDo === 'save') {
        tri_album_save((int) ($_POST['aid'] ?? $_GET['aid'] ?? 0), (string) ($_POST['name'] ?? $_GET['name'] ?? ''), (string) ($_POST['desc'] ?? $_GET['desc'] ?? ''), '');
    } elseif ($triDo === 'del') {
        tri_album_delete((int) ($_GET['aid'] ?? 0));
    } elseif ($triDo === 'up' || $triDo === 'down') {
        tri_admin_album_move((int) ($_GET['aid'] ?? 0), $triDo === 'up' ? -1 : 1);
    } elseif ($triDo === 'padd') {
        tri_photo_save(0, (int) ($_POST['aid'] ?? $_GET['aid'] ?? 0), (string) ($_POST['url'] ?? $_GET['url'] ?? ''), (string) ($_POST['title'] ?? $_GET['title'] ?? ''));
    } elseif ($triDo === 'pedit') {
        tri_photo_save((int) ($_POST['pid'] ?? $_GET['pid'] ?? 0), (int) ($_POST['aid'] ?? $_GET['aid'] ?? 0), (string) ($_POST['url'] ?? $_GET['url'] ?? ''), (string) ($_POST['title'] ?? $_GET['title'] ?? ''));
    } elseif ($triDo === 'pdel') {
        tri_photo_delete((int) ($_GET['pid'] ?? 0));
    } elseif ($triDo === 'pup' || $triDo === 'pdown') {
        tri_admin_photo_move((int) ($_GET['aid'] ?? 0), (int) ($_GET['pid'] ?? 0), $triDo === 'pup' ? -1 : 1);
    } elseif ($triDo === 'cover') {
        tri_album_set_cover((int) ($_GET['aid'] ?? 0), (string) ($_GET['cover'] ?? ''));
    } elseif ($triDo === 'upload') {
        $triAid = (int) ($_POST['aid'] ?? 0);
        if (isset($_FILES['file']) && is_array($_FILES['file']['name'])) {
            $triCount = count($_FILES['file']['name']);
            for ($triI = 0; $triI < $triCount; $triI++) {
                if (($_FILES['file']['error'][$triI] ?? 1) === UPLOAD_ERR_OK) {
                    $triFile = array(
                        'name' => $_FILES['file']['name'][$triI],
                        'type' => $_FILES['file']['type'][$triI] ?? '',
                        'tmp_name' => $_FILES['file']['tmp_name'][$triI],
                        'error' => $_FILES['file']['error'][$triI],
                        'size' => $_FILES['file']['size'][$triI] ?? 0
                    );
                    $triUrl = tri_album_upload($triFile);
                    if ($triUrl && !empty($triUrl['ok'])) {
                        tri_photo_save(0, $triAid, (string) $triUrl['url'], pathinfo($_FILES['file']['name'][$triI], PATHINFO_FILENAME));
                    }
                }
            }
        }
    }
    if ($triAjax) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        $triV2 = isset($_GET['tri_view']) ? (int) $_GET['tri_view'] : 0;
        $triE2 = isset($_GET['tri_edit']) ? (int) $_GET['tri_edit'] : 0;
        $triHtml = tri_album_mgr_html('/admin/options-theme.php?theme=' . $triThemeName, $triV2, $triE2);
        echo json_encode(array('ok' => true, 'html' => $triHtml));
        exit;
    }
    header('Location: ' . $triSite . $triBack);
    exit;
}

/** 相册排序：与相邻相册交换 sort */
function tri_admin_album_move(int $aid, int $dir): void
{
    tri_album_ensure_tables();
    $db = Typecho\Db::get();
    $pfx = $db->getPrefix();
    $list = tri_album_list();
    $idx = -1;
    foreach ($list as $i => $a) {
        if ((int) $a['aid'] === $aid) { $idx = $i; break; }
    }
    $j = $idx + $dir;
    if ($idx < 0 || $j < 0 || $j >= count($list)) { return; }
    $ids = array();
    foreach ($list as $a) { $ids[] = (int) $a['aid']; }
    $t = $ids[$idx]; $ids[$idx] = $ids[$j]; $ids[$j] = $t;
    tri_album_sort($ids);
}

/** 图片排序：与相邻图片交换 sort */
function tri_admin_photo_move(int $aid, int $pid, int $dir): void
{
    tri_album_ensure_tables();
    $list = tri_photo_list($aid);
    $idx = -1;
    foreach ($list as $i => $ph) {
        if ((int) $ph['pid'] === $pid) { $idx = $i; break; }
    }
    $j = $idx + $dir;
    if ($idx < 0 || $j < 0 || $j >= count($list)) { return; }
    $pids = array();
    foreach ($list as $ph) { $pids[] = (int) $ph['pid']; }
    $t = $pids[$idx]; $pids[$idx] = $pids[$j]; $pids[$j] = $t;
    tri_photo_sort($aid, $pids);
}

/**
 * 主题配置（后台「控制台 - 外观 - 设置外观」）
 */
function tri_album_mgr_html(string $triSelf, int $triView, int $triEdit): string {
    if (!function_exists('tri_album_ensure_tables')) {
        require_once __DIR__ . '/album.php';
    }
    if (!function_exists('tri_h')) {
        function tri_h($triS) { return htmlspecialchars((string) $triS, ENT_QUOTES, 'UTF-8'); }
    }
$triAlbumHtml = '<div class="tri3-album-mgr">'
        . '<input type="hidden" id="am-base" value="' . tri_h($triSelf) . '">';

    /* 链接 helper：全部走 AJAX（返回 false 阻止整页跳转） */
    $triL = function (string $extra, string $title, string $icon, string $confirm = '') use ($triSelf): string {
        $oc = $confirm !== ''
            ? ' onclick="if(!confirm(\'' . $confirm . '\')){return false;}return triAmAct(this.href)"'
            : ' onclick="return triAmAct(this.href)"';
        return '<a href="' . $triSelf . '&' . $extra . '" title="' . $title . '"' . $oc . '><i class="' . $icon . '"></i></a>';
    };

    if ($triView > 0) {
        $triA = tri_album_get($triView);
        if (!$triA) { $triView = 0; }
    }

    if ($triView > 0 && !empty($triA)) {
        /* ---------- 图片管理视图 ---------- */
        if ($triEdit > 0) {
            $triPh = null;
            foreach (tri_photo_list($triView) as $triPhX) {
                if ((int) $triPhX['pid'] === $triEdit) { $triPh = $triPhX; break; }
            }
            if ($triPh) {
                $triAlbumHtml .= '<div class="am-bread"><a href="' . $triSelf . '&tri_view=' . $triView . '" onclick="return triAmAct(this.href)">← 返回图片</a> / 编辑图片</div>'
                    . '<div class="am-form">'
                    . '<label>图片 URL</label><input type="text" id="am-ph-url" value="' . tri_h($triPh['url']) . '" style="width:100%">'
                    . '<label>标题</label><input type="text" id="am-ph-title" value="' . tri_h($triPh['title']) . '" style="width:100%">'
                    . '<input type="hidden" id="am-ph-pid" value="' . (int) $triPh['pid'] . '">'
                    . '<input type="hidden" id="am-ph-aid" value="' . $triView . '">'
                    . '<div class="am-actions"><button type="button" class="am-btn am-btn-primary" onclick="triAmPhotoSave()">保存</button> '
                    . '<a class="am-btn" href="' . $triSelf . '&tri_view=' . $triView . '" onclick="return triAmAct(this.href)">取消</a></div></div>';
            } else {
                $triView = 0;
            }
        } else {
            $triPhotos = tri_photo_list($triView);
            $triAlbumHtml .= '<div class="am-bread"><a href="' . $triSelf . '" onclick="return triAmAct(this.href)">← 返回相册列表</a> / <b>' . tri_h($triA['name']) . '</b>'
                . '<span class="am-count">共 ' . count($triPhotos) . ' 张</span></div>'
                . '<div class="am-upload">'
                . '<input type="file" id="am-file" multiple accept="image/*">'
                . '<button type="button" class="am-btn am-btn-primary" onclick="triAmUpload(' . $triView . ')"><i class="ri-upload-2-line"></i> 上传选中图片</button></div>'
                . '<div class="am-addlink">'
                . '<input type="text" id="am-link-url" placeholder="图片 URL（图床直链）">'
                . '<input type="text" id="am-link-title" placeholder="标题（选填）">'
                . '<input type="hidden" id="am-link-aid" value="' . $triView . '">'
                . '<button type="button" class="am-btn" onclick="triAmLink()"><i class="ri-link"></i> 添加链接</button></div>'
                . '<div class="am-photos">';
            foreach ($triPhotos as $triPhX) {
                $triThumb = !empty($triPhX['thumb']) ? $triPhX['thumb'] : $triPhX['url'];
                $triIsCover = (string) $triPhX['url'] === (string) $triA['cover'];
                $triAlbumHtml .= '<div class="am-photo">'
                    . '<div class="am-photo-img"><img src="' . tri_h($triThumb) . '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">'
                    . ($triIsCover ? '<span class="am-cover-tag">封面</span>' : '') . '</div>'
                    . '<div class="am-photo-meta" title="' . tri_h($triPhX['title']) . '">' . tri_h($triPhX['title'] ?: '未命名') . '</div>'
                    . '<div class="am-photo-ops">'
                    . $triL('tri_do=cover&tri_view=' . $triView . '&aid=' . $triView . '&cover=' . urlencode($triPhX['url']), '设为封面', 'ri-vip-crown-line')
                    . $triL('tri_do=pup&tri_view=' . $triView . '&aid=' . $triView . '&pid=' . $triPhX['pid'], '上移', 'ri-arrow-up-line')
                    . $triL('tri_do=pdown&tri_view=' . $triView . '&aid=' . $triView . '&pid=' . $triPhX['pid'], '下移', 'ri-arrow-down-line')
                    . $triL('tri_view=' . $triView . '&tri_edit=' . $triPhX['pid'], '编辑', 'ri-edit-2-line')
                    . $triL('tri_do=pdel&tri_view=' . $triView . '&pid=' . $triPhX['pid'], '删除', 'ri-delete-bin-line', '确定删除这张图片？')
                    . '</div></div>';
            }
            if (!$triPhotos) {
                $triAlbumHtml .= '<div class="am-empty">这个相册还没有图片，上传或添加链接吧</div>';
            }
            $triAlbumHtml .= '</div>';
        }
    } else {
        /* ---------- 相册列表视图 ---------- */
        $triEditAlbum = null;
        if ($triEdit > 0) {
            foreach (tri_album_list() as $triAX) {
                if ((int) $triAX['aid'] === $triEdit) { $triEditAlbum = $triAX; break; }
            }
        }
        if ($triEditAlbum) {
            $triAlbumHtml .= '<div class="am-bread"><a href="' . $triSelf . '" onclick="return triAmAct(this.href)">← 返回相册列表</a> / 编辑相册</div>'
                . '<div class="am-form">'
                . '<label>相册名称</label><input type="text" id="am-edit-name" value="' . tri_h($triEditAlbum['name']) . '" style="width:100%">'
                . '<label>相册描述</label><input type="text" id="am-edit-desc" value="' . tri_h($triEditAlbum['description']) . '" style="width:100%">'
                . '<input type="hidden" id="am-edit-aid" value="' . (int) $triEditAlbum['aid'] . '">'
                . '<div class="am-actions"><button type="button" class="am-btn am-btn-primary" onclick="triAmEdit()">保存</button> '
                . '<a class="am-btn" href="' . $triSelf . '" onclick="return triAmAct(this.href)">取消</a></div></div>';
        } else {
            $triAlbumHtml .= '<div class="am-head"><b><i class="ri-gallery-line"></i> 相册管理</b>'
                . '<span class="am-count">共 ' . count(tri_album_list()) . ' 个相册</span></div>'
                . '<div class="am-new">'
                . '<input type="text" id="am-new-name" placeholder="新相册名称">'
                . '<input type="text" id="am-new-desc" placeholder="描述（选填）">'
                . '<button type="button" class="am-btn am-btn-primary" onclick="triAmNew()"><i class="ri-add-line"></i> 新建相册</button></div>';
        }
        $triAlbumHtml .= '<div class="am-cards">';
        foreach (tri_album_list() as $triAX) {
            $triCover = !empty($triAX['cover']) ? $triAX['cover'] : '';
            $triCoverHtml = $triCover
                ? '<img src="' . tri_h($triCover) . '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
                : '<i class="ri-image-line am-card-ph"></i>';
            $triAlbumHtml .= '<div class="am-card">'
                . '<div class="am-card-cover">' . $triCoverHtml
                . '<span class="am-card-num">' . (int) $triAX['count'] . ' 张</span></div>'
                . '<div class="am-card-body">'
                . '<b title="' . tri_h($triAX['name']) . '">' . tri_h($triAX['name']) . '</b>'
                . (!empty($triAX['description']) ? '<p>' . tri_h($triAX['description']) . '</p>' : '')
                . '<div class="am-card-ops">'
                . $triL('tri_do=up&aid=' . $triAX['aid'], '上移', 'ri-arrow-up-line')
                . $triL('tri_do=down&aid=' . $triAX['aid'], '下移', 'ri-arrow-down-line')
                . $triL('tri_edit=' . $triAX['aid'], '编辑', 'ri-edit-2-line')
                . $triL('tri_do=del&aid=' . $triAX['aid'], '删除', 'ri-delete-bin-line', '确定删除该相册及其全部图片？')
                . '</div>'
                . '<a class="am-manage" href="' . $triSelf . '&tri_view=' . $triAX['aid'] . '" onclick="return triAmAct(this.href)">管理图片</a>'
                . '</div></div>';
        }
        if (!$triAlbums = tri_album_list()) {
            $triAlbumHtml .= '<div class="am-empty">还没有相册，先新建一个吧</div>';
        }
        $triAlbumHtml .= '</div>';
    }

    return $triAlbumHtml;
}

function themeConfig(Typecho_Widget_Helper_Form $form): void
{
        $panelMap = array(
            'base'       => array('authorName', 'authorBio', 'authorSignature', 'profileExtras', 'logoUrl', 'faviconUrl', 'avatarUrl', 'footerHtml', 'github', 'weibo', 'wechat', 'email', 'rssEnable', 'likesNum', 'viewsNum', 'showLikes', 'showComments', 'showViews', 'statsMode', 'statsCustomHtml'),
            'layout'     => array('pjaxEnable', 'bannerEnable', 'bannerUrls', 'bannerInterval', 'bannerSearch', 'leftSidebarEnable', 'rightSidebarEnable', 'leftWidth', 'mainWidth', 'rightWidth', 'excerptLen', 'bannerHeight', 'paginationStyle', 'pageSize', 'homeListMode', 'postCardStyle', 'fusionExcerpt', 'noticeText', 'leftFixed', 'navNarrow', 'navItems', 'mobileMenu', 'homeMenu', 'homeMenuCats'),
            'appearance' => array('dynamicColor', 'primaryColor', 'secondaryColor', 'tertiaryColor', 'primaryContainerColor', 'onPrimaryContainerColor', 'onPrimaryColor', 'bgColor', 'bgGradient', 'bgGradStyle', 'glassEffect', 'radiusScale', 'darkMode', 'siteFont', 'tri3_404Compat'),
            'player'     => array('playerEnable', 'playerSource', 'metingPlatform', 'metingResourceType', 'metingResourceId', 'metingApi', 'metingCookieNetease', 'metingCookieTencent', 'playlist', 'musicPlaylists', 'playerVolume', 'playerLyric', 'playerLyricDefault'),
            'content'    => array('albumApiType', 'albumApiUrl', 'albumApiToken', 'albumApiFileField', 'albumApiUrlPath', 'albumApiDomain', 'upyunTokenSecret', 'upyunTokenTtl', 'qiniuAccessKey', 'qiniuSecretKey', 'qiniuBucket', 'qiniuRegion', 'qiniuDomain', 'qiniuPrefix', 'qiniuTokenSecret', 'qiniuTokenTtl', 'friends', 'friendGroups', 'friendApply', 'shotCacheDays'),
            'imgcache'   => array('imgCacheSecret', 'imgCacheAuto', 'imgCacheInterval', 'imgProxyEnable', 'imgWebpEnable', 'imgDefaultW', 'imgDefaultQ', 'imgCacheMax'),
            'media'      => array('doubanUid', 'doubanApi', 'doubanTypes', 'doubanCount', 'doubanCache', 'doubanShowFilter', 'doubanShowRemark', 'doubanShowRating', 'doubanShowSearch', 'doubanCacheCover', 'biliUid', 'biliType', 'biliCount', 'biliCache', 'biliShowSearch', 'biliShowFilter', 'biliShowRemark', 'biliCacheCover'),
            'notify'     => array('mailEnable', 'mailMode', 'smtpHost', 'smtpPort', 'smtpSecure', 'smtpUser', 'smtpPass', 'mailFromName', 'mailFromAddr', 'mailAdminTo', 'mailNotifyAdmin', 'mailReplyNotify', 'spamEnable', 'spamMode', 'spamKeywords', 'spamMaxLinks', 'spamMinLen', 'spamHp', 'spamIpList', 'spamMailList', 'spamAction', 'spamRateEnable', 'spamRateMin', 'spamRateTen'),
            'ai'         => array('aiSummaryEnable', 'aiSummaryLen', 'spamAiEnable', 'spamAiKey', 'spamAiModel', 'spamAiEndpoint', 'spamAiScope', 'spamAiAction', 'spamAiTimeout'),
            'tool'       => array('seoEnable', 'seoSiteName', 'seoSiteDesc', 'seoSiteKeywords', 'seoSiteUrl', 'seoCanonical', 'seoOg', 'seoTwitter', 'seoJsonLd', 'seoBaiduVer', 'seoGoogleVer', 'seoBingVer', 'seoHeaderHtml'),
        );
        $siteUrl = Helper::options()->siteUrl;
        $mapJson = json_encode($panelMap);

        /* ================= Tab 内部分组（section）：每个面板下设若干分组卡片 ================= */
        $triSectionMap = array(
            'base' => array(
                array('title' => '站点信息', 'icon' => 'ri-global-line', 'desc' => '站点名称 / 描述 / 关键词 / 地址，留空自动使用 Typecho 原生设置', 'fields' => array('seoSiteName', 'seoSiteDesc', 'seoSiteKeywords', 'seoSiteUrl')),
                array('title' => '作者信息', 'icon' => 'ri-user-smile-line', 'desc' => 'Banner 下方个人信息卡展示的内容', 'fields' => array('authorName', 'authorBio', 'authorSignature', 'profileExtras')),
                array('title' => '品牌标识', 'icon' => 'ri-image-line', 'desc' => 'Logo / Favicon / 头像；头像留空时自动从上方邮箱获取 Cravatar 国内源头像', 'fields' => array('logoUrl', 'faviconUrl', 'avatarUrl', 'email')),
                array('title' => '社交链接', 'icon' => 'ri-share-line', 'desc' => '个人信息卡右侧的社交图标', 'fields' => array('github', 'weibo', 'wechat', 'rssEnable')),
                array('title' => '数据统计', 'icon' => 'ri-bar-chart-line', 'desc' => '个人信息卡上的点赞 / 评论 / 访问量，可自定 HTML 完全接管', 'fields' => array('showLikes', 'showComments', 'showViews', 'likesNum', 'viewsNum', 'statsMode', 'statsCustomHtml')),
                array('title' => '页脚', 'icon' => 'ri-quill-pen-line', 'desc' => '全站页脚自定义内容（支持 HTML）', 'fields' => array('footerHtml')),
            ),
            'layout' => array(
                array('title' => '全站与三栏', 'icon' => 'ri-layout-3-line', 'desc' => 'Swup 无刷新切换与三栏显隐、宽度（三栏宽度可自定义，不影响响应式）', 'fields' => array('pjaxEnable', 'leftSidebarEnable', 'rightSidebarEnable', 'leftWidth', 'mainWidth', 'rightWidth')),
                array('title' => 'Banner 轮播', 'icon' => 'ri-slideshow-line', 'desc' => '首页顶部插画轮播与搜索按钮', 'fields' => array('bannerEnable', 'bannerUrls', 'bannerInterval', 'bannerSearch', 'bannerHeight')),
                array('title' => '列表与内容', 'icon' => 'ri-list-check-2', 'desc' => '首页列表模式（原文 / 说说 / 融合）、摘要字数、分页样式', 'fields' => array('homeListMode', 'postCardStyle', 'fusionExcerpt', 'excerptLen', 'pageSize', 'paginationStyle')),
                array('title' => '通知', 'icon' => 'ri-notification-3-line', 'desc' => '首页横向导航上方的通知条，留空不显示', 'fields' => array('noticeText')),
                array('title' => '左侧导航', 'icon' => 'ri-menu-2-line', 'desc' => '左侧栏导航项（名称 / 链接 / 图标，可视化编辑）', 'fields' => array('navItems')),
                array('title' => '导航行为', 'icon' => 'ri-settings-3-line', 'desc' => '左侧栏固定与收窄（大屏只显示图标，悬停展开）', 'fields' => array('leftFixed', 'navNarrow')),
                array('title' => '首页菜单', 'icon' => 'ri-home-smile-2-line', 'desc' => '首页横向菜单栏与「文章」分类汇总显示', 'fields' => array('homeMenu', 'homeMenuCats')),
                array('title' => '手机端菜单', 'icon' => 'ri-smartphone-line', 'desc' => '手机端底部固定菜单（固定 5 项，不显示图标可留空）', 'fields' => array('mobileMenu')),
            ),
            'appearance' => array(
                array('title' => 'MD3 配色', 'icon' => 'ri-palette-line', 'desc' => '主色驱动整套 Material Design 3 配色，可手动微调；背景色为最底层页面背景', 'fields' => array('dynamicColor', 'bgColor', 'bgGradient', 'bgGradStyle', 'primaryColor', 'secondaryColor', 'tertiaryColor', 'primaryContainerColor', 'onPrimaryContainerColor', 'onPrimaryColor')),
                array('title' => '材质与明暗', 'icon' => 'ri-contrast-2-line', 'desc' => '玻璃亚克力质感、圆角缩放与暗色模式', 'fields' => array('glassEffect', 'radiusScale', 'darkMode')),
                array('title' => '字体', 'icon' => 'ri-font-size', 'desc' => '全站默认字体（内置 OPPO Sans 按需切割加载），可自定义', 'fields' => array('siteFont')),
                array('title' => '兼容', 'icon' => 'ri-bug-line', 'desc' => '旧页面兼容选项', 'fields' => array('tri3_404Compat')),
            ),
            'player' => array(
                array('title' => '播放器开关', 'icon' => 'ri-music-2-line', 'desc' => '头像播放器与悬浮播放器', 'fields' => array('playerEnable', 'playerSource')),
                array('title' => '歌单来源', 'icon' => 'ri-play-list-2-line', 'desc' => '选择平台与类型，填写资源 ID；支持网易云 / QQ / 酷狗 / 酷我', 'fields' => array('metingPlatform', 'metingResourceType', 'metingResourceId', 'metingApi', 'playlist', 'musicPlaylists')),
                array('title' => 'Cookie VIP', 'icon' => 'ri-vip-crown-2-line', 'desc' => '填入 VIP 账号 Cookie 可播放 VIP 曲目（可多平台）', 'fields' => array('metingCookieNetease', 'metingCookieTencent')),
                array('title' => '播放行为', 'icon' => 'ri-settings-3-line', 'desc' => '音量与歌词显示', 'fields' => array('playerVolume', 'playerLyric', 'playerLyricDefault')),
            ),
            'content' => array(
                array('title' => '图床设置', 'icon' => 'ri-cloud-line', 'desc' => '填写图床 API 后，相册管理、后台编辑器、说说发布的上传按钮可直接上传（支持兰空 / Chevereto / 又拍云 / 七牛云）', 'fields' => array('albumApiType', 'albumApiUrl', 'albumApiToken', 'albumApiFileField', 'albumApiUrlPath', 'albumApiDomain', 'upyunTokenSecret', 'upyunTokenTtl', 'qiniuAccessKey', 'qiniuSecretKey', 'qiniuBucket', 'qiniuRegion', 'qiniuDomain', 'qiniuPrefix', 'qiniuTokenSecret', 'qiniuTokenTtl')),
                array('title' => '友链数据', 'icon' => 'ri-link', 'desc' => '每行一个友链：名称|链接|头像|描述，可分组', 'fields' => array('friends', 'friendGroups')),
                array('title' => '申请与截图', 'icon' => 'ri-camera-line', 'desc' => '友链申请开关与网站截图缓存', 'fields' => array('friendApply', 'shotCacheDays')),
            ),
            'media' => array(
                array('title' => '豆瓣 · 数据源', 'icon' => 'ri-finder-line', 'desc' => '豆瓣 UID、数据获取与本地缓存', 'fields' => array('doubanUid', 'doubanApi', 'doubanTypes', 'doubanCount', 'doubanCache', 'doubanCacheCover')),
                array('title' => '豆瓣 · 页面展示', 'icon' => 'ri-layout-grid-line', 'desc' => '筛选 / 短评 / 评分 / 搜索', 'fields' => array('doubanShowFilter', 'doubanShowRemark', 'doubanShowRating', 'doubanShowSearch')),
                array('title' => 'B 站 · 数据源', 'icon' => 'ri-tv-2-line', 'desc' => 'B 站 UID、追番获取与本地缓存', 'fields' => array('biliUid', 'biliType', 'biliCount', 'biliCache', 'biliCacheCover')),
                array('title' => 'B 站 · 页面展示', 'icon' => 'ri-layout-grid-line', 'desc' => '筛选 / 短评 / 搜索', 'fields' => array('biliShowSearch', 'biliShowFilter', 'biliShowRemark')),
            ),
            'notify' => array(
                array('title' => '邮件通知', 'icon' => 'ri-toggle-line', 'desc' => '评论 / 回复时自动发送邮件通知', 'fields' => array('mailEnable', 'mailNotifyAdmin', 'mailReplyNotify')),
                array('title' => 'SMTP 配置', 'icon' => 'ri-server-line', 'desc' => '发件服务器与账号（QQ / 163 邮箱使用授权码）', 'fields' => array('mailMode', 'smtpHost', 'smtpPort', 'smtpSecure', 'smtpUser', 'smtpPass', 'mailFromName', 'mailFromAddr', 'mailAdminTo')),
                array('title' => '评论规则引擎', 'icon' => 'ri-shield-check-line', 'desc' => '关键词 / 链接 / 长度 / 黑白名单规则，原则「宁可放过，不可误杀」', 'fields' => array('spamEnable', 'spamMode', 'spamKeywords', 'spamMaxLinks', 'spamMinLen', 'spamHp', 'spamIpList', 'spamMailList', 'spamAction', 'spamRateEnable', 'spamRateMin', 'spamRateTen')),
            ),
            'ai' => array(
                array('title' => 'AI 文章摘要', 'icon' => 'ri-sparkling-2-line', 'desc' => '发布文章时自动用豆包生成摘要（复用评论过滤的 Key），未填 Key 自动跳过；摘要写入文章摘要框，前台列表 / 融合模式优先显示。已有手填摘要不会覆盖', 'fields' => array('aiSummaryEnable', 'aiSummaryLen')),
                array('title' => 'AI 语义过滤', 'icon' => 'ri-chat-smile-2-line', 'desc' => '可选接入豆包大模型复核可疑评论（配合评论规则引擎使用）', 'fields' => array('spamAiEnable', 'spamAiKey', 'spamAiModel', 'spamAiEndpoint', 'spamAiScope', 'spamAiAction', 'spamAiTimeout')),
            ),
            'tool' => array(
                array('title' => 'SEO 开关', 'icon' => 'ri-toggle-line', 'desc' => '开启后输出以下全部 SEO 标签；关闭则保持 Typecho 原生 title / description / keywords 输出', 'fields' => array('seoEnable')),
                array('title' => '现代 SEO', 'icon' => 'ri-rocket-2-line', 'desc' => 'Canonical、Open Graph、Twitter Card、JSON-LD 结构化数据', 'fields' => array('seoCanonical', 'seoOg', 'seoTwitter', 'seoJsonLd')),
                array('title' => '站点验证', 'icon' => 'ri-verified-badge-line', 'desc' => '百度 / Google / Bing 搜索平台验证代码', 'fields' => array('seoBaiduVer', 'seoGoogleVer', 'seoBingVer')),
                array('title' => '自定义代码', 'icon' => 'ri-code-s-slash-line', 'desc' => '插入到 </head> 前的统计 / 验证 / 第三方脚本', 'fields' => array('seoHeaderHtml')),
            ),
        );
        $sectionJson = json_encode($triSectionMap);
    /* Typecho 的 themeUrl($path) 单参数是 echo 而非 return，不能赋值；
       直接拼接绝对 URL（站点地址 + usr/themes/ + 当前主题目录名） */
    $triThemeUrl = rtrim((string) Helper::options()->siteUrl, '/') . '/usr/themes/' . (string) Helper::options()->theme;

    /* ============================================================
     * 相册管理区（服务端渲染；不用 <form> 避免与 Typecho 主表单嵌套，
     * 操作走 GET 链接，上传走 fetch 直传后台页面，天然带登录态）
     * ============================================================ */
    if (!function_exists('tri_album_ensure_tables')) {
        require_once __DIR__ . '/album.php';
    }
    if (!function_exists('tri_h')) {
        function tri_h($triS) { return htmlspecialchars((string) $triS, ENT_QUOTES, 'UTF-8'); }
    }
    tri_album_ensure_tables();
    $triSelf = '/admin/options-theme.php?theme=' . (string) Helper::options()->theme;
    $triImgSecret = htmlspecialchars((string) (Helper::options()->imgCacheSecret ?? ''), ENT_QUOTES, 'UTF-8');
    $triImgAuto1 = ((string) (Helper::options()->imgCacheAuto ?? '0') === '1') ? ' checked' : '';
    $triImgAuto0 = ((string) (Helper::options()->imgCacheAuto ?? '0') !== '1') ? ' checked' : '';
    $triImgInterval = htmlspecialchars((string) (Helper::options()->imgCacheInterval ?? '1'), ENT_QUOTES, 'UTF-8');
    $triImgProxy1 = ((string) (Helper::options()->imgProxyEnable ?? '1') === '1') ? ' checked' : '';
    $triImgProxy0 = ((string) (Helper::options()->imgProxyEnable ?? '1') !== '1') ? ' checked' : '';
    $triImgWebp1 = ((string) (Helper::options()->imgWebpEnable ?? '1') === '1') ? ' checked' : '';
    $triImgWebp0 = ((string) (Helper::options()->imgWebpEnable ?? '1') !== '1') ? ' checked' : '';
    $triImgDW = htmlspecialchars((string) (Helper::options()->imgDefaultW ?? '800'), ENT_QUOTES, 'UTF-8');
    $triImgDQ = htmlspecialchars((string) (Helper::options()->imgDefaultQ ?? '80'), ENT_QUOTES, 'UTF-8');
    $triImgCM = htmlspecialchars((string) (Helper::options()->imgCacheMax ?? '800'), ENT_QUOTES, 'UTF-8');

    /* ---- 管理后台配色：底色 = 主题背景色 bgColor，整套 MD3 色板（含主色）都由背景色推导 ---- */
    $triAdminBg = tri_opt_raw('bgColor');
    if ($triAdminBg === '') { $triAdminBg = '#f8f5fc'; }
    $triAdminPri = tri3_primary_from_bg($triAdminBg);
    $triAdminPal = tri3_md3_palette($triAdminPri, $triAdminBg);
    $triOnS = $triAdminPal['onSurface'];
    $triOnSV = $triAdminPal['onSurfaceVariant'];
    $triOnSRgb = tri3_hex_to_rgb_css($triOnS);
    $triOnSVRgb = tri3_hex_to_rgb_css($triOnSV);
    

    /* ============================================================
     * 界面骨架：品牌横幅 + Tab 导航 + 面板容器（样式/脚本为独立文件）
     * ============================================================ */
    echo <<<HTML
<link rel="stylesheet" href="{$triThemeUrl}/assets/css/admin.min.css?v=1.2.5">
<link rel="stylesheet" href="{$triThemeUrl}/assets/css/remixicon.min.css">
<div id="tri3-config">
<style>
:root{
  --tri-primary: {$triAdminPri};
  --tri-primary-container: {$triAdminPal['primaryContainer']};
  --tri-on-primary-container: {$triAdminPal['onPrimaryContainer']};
  --tri-on-primary: {$triAdminPal['onPrimary']};
  --tri-secondary: {$triAdminPal['secondary']};
  --tri-surface: {$triAdminPal['surface']};
  --tri-surface-container-low: {$triAdminPal['surfaceLow']};
  --tri-surface-variant: {$triAdminPal['surfaceVariant']};
  --tri-outline: {$triAdminPal['outlineVariant']};
  /* 文本层级（MD3 Text roles，基于 on-surface / on-surface-variant 分层） */
  --tri-text-headline: {$triOnS};
  --tri-text-title: {$triOnS};
  --tri-text-body: rgba({$triOnSRgb} / .78);
  --tri-text-desc: {$triOnSV};
  --tri-text-caption: rgba({$triOnSVRgb} / .85);
  --tri-text-time: rgba({$triOnSVRgb} / .62);
  --tri-text-disabled: rgba({$triOnSRgb} / .38);
}
</style>
<div class="tri3-hero">
  <div class="tri3-hero-cover"><img src="{$triThemeUrl}/screenshot.png" alt="TriM3 主题封面" loading="lazy"></div>
  <div class="tri3-hero-info">
    <h2>TriM3 <span class="tri3-hero-ver">v1.2.5</span></h2>
    <p>Material Design 3 · 三栏个人博客主题 · Swup 无刷新导航 · 安全加固 · 性能优化</p>
    <div class="tri3-hero-meta">
      <span><i class="ri-user-smile-line"></i> 作者 <a href="https://github.com/asplun/TriM3" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline">薄荷</a></span>
      <span><i class="ri-layout-3-line"></i> 三栏响应式布局</span>
      <span><i class="ri-palette-line"></i> MD3 动态配色</span>
      <span><i class="ri-music-2-line"></i> 双播放器</span>
      <span><i class="ri-chat-3-line"></i> 说说 · 相册 · 友链</span>
      <span><i class="ri-shield-check-line"></i> AI 评论过滤</span>
    </div>
  </div>
  <div class="tri3-hero-actions">
    <a class="tri3-hero-link" href="{$siteUrl}" target="_blank" rel="noopener">查看前台 <i class="ri-external-link-line"></i></a>
    <a class="tri3-hero-link ghost" href="{$triThemeUrl}/theme-info.html" target="_blank" rel="noopener">主题说明</a>
  </div>
</div>
<div class="tri3-tabs">
  <button type="button" class="tri3-tab is-active" data-panel="base"><i class="ri-user-3-line"></i><span>站点与作者</span></button>
  <button type="button" class="tri3-tab" data-panel="layout"><i class="ri-layout-3-line"></i><span>布局与导航</span></button>
  <button type="button" class="tri3-tab" data-panel="appearance"><i class="ri-palette-line"></i><span>外观定制</span></button>
  <button type="button" class="tri3-tab" data-panel="player"><i class="ri-music-2-line"></i><span>播放器</span></button>
  <button type="button" class="tri3-tab" data-panel="content"><i class="ri-apps-2-line"></i><span>内容与友链</span></button>
  <button type="button" class="tri3-tab" data-panel="imgcache"><i class="ri-image-2-line"></i><span>图片优化</span></button>
  <button type="button" class="tri3-tab" data-panel="media"><i class="ri-film-line"></i><span>书影番剧</span></button>
  <button type="button" class="tri3-tab" data-panel="notify"><i class="ri-shield-check-line"></i><span>通知与安全</span></button>
  <button type="button" class="tri3-tab" data-panel="ai"><i class="ri-sparkling-2-line"></i><span>AI 智能</span></button>
  <button type="button" class="tri3-tab" data-panel="tool"><i class="ri-tools-line"></i><span>SEO 与备份</span></button>
</div>
<div class="tri3-panels">
  <div class="tri3-panel is-active" data-panel="base">
    <div class="tri3-panel-head"><i class="ri-user-3-fill" style="color:var(--tri-primary,#3D5AFE)"></i><div><b>站点与作者</b><p>个人信息卡、Logo / 头像、社交链接与页脚</p></div></div>
  </div>
  <div class="tri3-panel" data-panel="layout">
    <div class="tri3-panel-head"><i class="ri-layout-3-fill" style="color:var(--tri-primary,#3D5AFE)"></i><div><b>布局与导航</b><p>三栏布局、Banner 轮播、列表模式与全站导航菜单</p></div></div>
  </div>
  <div class="tri3-panel" data-panel="appearance">
    <div class="tri3-panel-head"><i class="ri-palette-fill" style="color:var(--tri-primary,#3D5AFE)"></i><div><b>外观定制</b><p>MD3 配色、材质圆角、暗色模式与字体</p></div></div>
  </div>
  <div class="tri3-panel" data-panel="player">
    <div class="tri3-panel-head"><i class="ri-music-2-fill" style="color:var(--tri-primary,#3D5AFE)"></i><div><b>播放器</b><p>头像 / 悬浮双播放器、歌单来源、VIP Cookie 与播放行为</p></div></div>
  </div>
  <div class="tri3-panel" data-panel="content">
    <div class="tri3-panel-head"><i class="ri-apps-2-fill" style="color:var(--tri-primary,#3D5AFE)"></i><div><b>内容与友链</b><p>图床 API、说说 / 相册 / 小组件管理入口与友链数据</p></div></div>
<div class="tri3-manage-grid">
      <a class="tri3-manage-card" href="{$triThemeUrl}/admin/moments.html" target="_blank" rel="noopener">
        <i class="ri-chat-3-line"></i>
        <b>说说管理</b>
        <span>发布 / 编辑 / 删除说说，插入图片、音乐、视频，分类与分页管理</span>
      </a>
      <a class="tri3-manage-card" href="{$triThemeUrl}/admin/albums.html" target="_blank" rel="noopener">
        <i class="ri-gallery-line"></i>
        <b>相册管理</b>
        <span>新建相册、上传图片、添加链接、排序、封面设置、编辑删除</span>
      </a>
      <a class="tri3-manage-card" href="{$triThemeUrl}/admin/widgets.html" target="_blank" rel="noopener">
        <i class="ri-layout-masonry-line"></i>
        <b>小组件管理</b>
        <span>拖拽式左右栏小组件：天气、倒计时、日历、一言、访客统计等</span>
      </a>
    </div>
    
  </div>
<div class="tri3-panel" data-panel="imgcache">
    <div class="tri3-panel-head" style="display:flex;align-items:center;gap:14px;background:linear-gradient(135deg,rgba(103,80,164,.10),rgba(103,80,164,.02));border:1px solid rgba(103,80,164,.22);border-radius:14px;padding:16px 18px;margin:0 0 4px">
      <i class="ri-image-2-fill" style="font-size:30px;color:#6750a4"></i>
      <div style="flex:1;min-width:0">
        <b style="font-size:14px">图片优化 · 代理缓存预生成</b>
        <p style="font-size:12px;color:var(--tri-text-2,#5B5D64);margin-top:2px">将全站文章 / 说说 / 相册 / 小组件图片通过代理预生成 WebP 缓存，前台访问全部命中缓存，不实时转换占用资源</p>
      </div>
    </div>
    <div class="tri3-bak-cards">
      <div class="tri3-bak-card">
        <i class="ri-speed-line tri3-bak-ico"></i>
        <b>缓存状态</b>
        <p id="ic-stats">读取中…</p>
        <div style="height:6px;background:rgba(103,80,164,.12);border-radius:3px;overflow:hidden;margin-top:10px"><div id="ic-bar" style="height:100%;width:0;background:#6750a4;transition:width .3s"></div></div>
        <p id="ic-txt" style="font-size:12px;color:var(--tri-text-2,#5B5D64);margin-top:6px"></p>
      </div>
      <div class="tri3-bak-card">
        <i class="ri-flashlight-line tri3-bak-ico"></i>
        <b>立即生成缓存</b>
        <p>扫描全部内容图片并通过代理预生成缓存（自动分批，可随时离开页面）</p>
        <button type="button" class="tri3-btn" id="ic-gen"><i class="ri-flashlight-line"></i> 开始生成</button>
        <button type="button" class="tri3-btn is-danger" id="ic-clear" style="margin-left:8px"><i class="ri-delete-bin-line"></i> 清空缓存</button>
      </div>
      <div class="tri3-bak-card">
        <i class="ri-time-line tri3-bak-ico"></i>
        <b>定时自动生成</b>
        <p>开启「自动生成」后，管理员每次登录后台会检查间隔并自动预生成；也可用服务器定时任务（cron / 宝塔 / 任意面板均可），每天执行一次：</p>
        <p style="margin-top:6px"><code style="font-size:11px;word-break:break-all">curl -s "https://你的域名/usr/themes/TriM3/api/img-cache.php?do=run&amp;secret=密钥" &gt;/dev/null 2&gt;&amp;1</code></p>
      </div>
      <div class="tri3-bak-card" style="grid-column:1/-1">
        <i class="ri-settings-3-line tri3-bak-ico"></i>
        <b>图片缓存设置</b>
        <div style="display:flex;flex-direction:column;gap:12px;margin-top:10px">
          <label style="font-size:13px;font-weight:600">缓存密钥
            <input type="text" name="imgCacheSecret" value="{$triImgSecret}" placeholder="留空则仅后台手动生成" style="width:100%;margin-top:6px;padding:8px 10px;border:1px solid rgba(103,80,164,.3);border-radius:8px;background:#fff;font-size:13px;box-sizing:border-box">
            <span style="display:block;font-size:11px;color:var(--tri-text-2,#5B5D64);font-weight:400;margin-top:4px">定时任务接口凭证：curl -s ".../api/img-cache.php?do=run&amp;secret=密钥"</span>
          </label>
          <div style="font-size:13px;font-weight:600">自动生成缓存</div>
          <div style="display:flex;gap:16px;font-size:13px;font-weight:400;margin-top:-6px">
            <label><input type="radio" name="imgCacheAuto" value="1"{$triImgAuto1}> 开启</label>
            <label><input type="radio" name="imgCacheAuto" value="0"{$triImgAuto0}> 关闭</label>
          </div>
          <span style="font-size:11px;color:var(--tri-text-2,#5B5D64);margin-top:-8px">开启后，管理员登录后台自动检查并按间隔触发预生成</span>
          <label style="font-size:13px;font-weight:600">自动生成间隔（天）
            <input type="number" name="imgCacheInterval" value="{$triImgInterval}" min="1" max="30" style="width:120px;margin-top:6px;padding:8px 10px;border:1px solid rgba(103,80,164,.3);border-radius:8px;background:#fff;font-size:13px">
            <span style="display:block;font-size:11px;color:var(--tri-text-2,#5B5D64);font-weight:400;margin-top:4px">距上次生成超过该天数时触发，默认 1 天</span>
          </label>
          <div style="border-top:1px solid rgba(103,80,164,.15);padding-top:12px;margin-top:4px">
            <div style="font-size:13px;font-weight:600;margin-bottom:8px">图片处理全局设置</div>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div>
                <div style="font-size:13px;font-weight:600">图片代理</div>
                <div style="display:flex;gap:16px;font-size:13px;font-weight:400;margin-top:4px">
                  <label><input type="radio" name="imgProxyEnable" value="1"{$triImgProxy1}> 开启（缩放/WebP/裁剪）</label>
                  <label><input type="radio" name="imgProxyEnable" value="0"{$triImgProxy0}> 关闭（直接输出原图）</label>
                </div>
              </div>
              <div>
                <div style="font-size:13px;font-weight:600">WebP 格式</div>
                <div style="display:flex;gap:16px;font-size:13px;font-weight:400;margin-top:4px">
                  <label><input type="radio" name="imgWebpEnable" value="1"{$triImgWebp1}> 开启（浏览器支持时用 WebP）</label>
                  <label><input type="radio" name="imgWebpEnable" value="0"{$triImgWebp0}> 关闭（强制 JPEG）</label>
                </div>
              </div>
              <div style="display:flex;gap:16px;flex-wrap:wrap">
                <label style="font-size:13px;font-weight:600">默认宽度（px）
                  <input type="number" name="imgDefaultW" value="{$triImgDW}" min="32" max="2000" style="width:120px;margin-top:4px;padding:6px 10px;border:1px solid rgba(103,80,164,.3);border-radius:8px;background:#fff;font-size:13px">
                </label>
                <label style="font-size:13px;font-weight:600">默认质量（%）
                  <input type="number" name="imgDefaultQ" value="{$triImgDQ}" min="30" max="95" style="width:120px;margin-top:4px;padding:6px 10px;border:1px solid rgba(103,80,164,.3);border-radius:8px;background:#fff;font-size:13px">
                </label>
                <label style="font-size:13px;font-weight:600">缓存文件上限
                  <input type="number" name="imgCacheMax" value="{$triImgCM}" min="100" max="5000" step="100" style="width:120px;margin-top:4px;padding:6px 10px;border:1px solid rgba(103,80,164,.3);border-radius:8px;background:#fff;font-size:13px">
                  <span style="display:block;font-size:11px;color:var(--tri-text-2,#5B5D64);font-weight:400;margin-top:4px">超过自动清理最旧文件</span>
                </label>
              </div>
            </div>
          </div>
          <button type="button" class="tri3-btn" id="ic-save" style="align-self:flex-start"><i class="ri-save-3-line"></i> 保存全部设置</button>
        </div>
      </div>
    </div>
  </div>
  <div class="tri3-panel" data-panel="media">
    <div class="tri3-panel-head"><i class="ri-film-fill" style="color:#fb7299"></i><div><b>书影番剧</b><p>豆瓣书影音与 B 站追番数据、页面展示</p></div></div>
  </div>
  <div class="tri3-panel" data-panel="notify">
    <div class="tri3-panel-head"><i class="ri-shield-check-fill" style="color:#00897b"></i><div><b>通知与安全</b><p>邮件通知与垃圾评论过滤（规则 + AI 复核）</p></div></div>
    <div class="tri3-panel-actions">
<div class="tri3-panel-actions" style="margin:0 0 6px">
      <button type="button" class="tri3-btn" id="tri3-mail-test-btn"><i class="ri-send-plane-line"></i> 发送测试邮件</button>
    </div>
<div class="tri3-panel-actions" style="margin:0 0 6px">
      <button type="button" class="tri3-btn" id="tri3-spam-test-btn"><i class="ri-flask-line"></i> 测试过滤规则</button>
    </div>
    </div>
  </div>
  <div class="tri3-panel" data-panel="ai">
    <div class="tri3-panel-head"><i class="ri-sparkling-2-fill" style="color:#6750a4"></i><div><b>AI 智能</b><p>文章摘要与评论语义过滤（复用豆包 Key / 模型）</p></div></div>
    <div class="tri3-panel-actions">
<div class="tri3-panel-actions" style="margin:0 0 6px">
      <button type="button" class="tri3-btn" id="tri3-ai-batch-btn"><i class="ri-magic-line"></i> 为已有文章批量生成摘要（自动逐篇生成）</button>
    </div>
    </div>
  </div>
  <div class="tri3-panel" data-panel="tool">
    <div class="tri3-panel-head"><i class="ri-tools-fill" style="color:#009688"></i><div><b>SEO 与备份</b><p>站点 SEO 优化与全量配置备份 / 恢复</p></div></div>
    <div class="tri3-panel-tail">
<div class="tri3-bak-cards">
      <div class="tri3-bak-card">
        <i class="ri-download-2-line tri3-bak-ico"></i>
        <b>导出备份</b>
        <p>下载全部主题数据的 JSON 文件，换服务器或误操作时可随时恢复</p>
        <a class="tri3-btn" href="{$triSelf}&tri_do=export" rel="noopener"><i class="ri-download-2-line"></i> 下载备份文件</a>
      </div>
      <div class="tri3-bak-card">
        <i class="ri-upload-2-line tri3-bak-ico"></i>
        <b>导入备份</b>
        <p>上传备份 JSON 并覆盖当前数据（主题设置、说说、相册、小组件）</p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
          <label class="tri3-bak-file">选择 JSON 文件<input type="file" id="tri3-bak-file-input" accept=".json,application/json"></label>
          <button type="button" class="tri3-btn" id="tri3-bak-import-btn"><i class="ri-upload-2-line"></i> 导入并覆盖</button>
        </div>
      </div>
      <div class="tri3-bak-card is-danger">
        <i class="ri-restart-line tri3-bak-ico"></i>
        <b>重置所有选项</b>
        <p>清空全部主题设置并恢复默认值，不可撤销（说说 / 相册 / 小组件数据不受影响）</p>
        <button type="button" class="tri3-btn is-danger" id="tri3-bak-reset" data-href="{$triSelf}&tri_do=reset"><i class="ri-restart-line"></i> 重置全部设置</button>
      </div>
    </div>
  </div>
    </div>
  </div>
</div>
<div class="tri3-note">💡 设置保存后自动生效，无需修改任何代码。颜色字段点击右侧色块即可打开取色器；「跟随系统」暗色模式时，右上角月亮按钮可手动切换并记忆。</div>
<script>
(function () {
  var p = document.querySelector('.tri3-panel[data-panel="imgcache"]');
  if (!p) { return; }
  var api = '{$triThemeUrl}/api/img-cache.php';
  /* v1.2.85：接口本身「管理员登录 或 密钥」二选一即可，但面板走会话调用时会因会话/同源
     状态读不到数（面板上表现为“读取失败（接口异常）”）。这里统一带上接口密钥（面板里
     就有这个字段），不再依赖会话状态。 */
  function apiSecret() { var el = document.querySelector('input[name="imgCacheSecret"]'); return el ? el.value : ''; }
  function apiUrl(qs) { var s = apiSecret(); if (!s) { return api + qs; } return api + (qs ? qs + '&' : '?') + 'secret=' + encodeURIComponent(s); }
  var genBtn = p.querySelector('#ic-gen'), clearBtn = p.querySelector('#ic-clear');
  var bar = p.querySelector('#ic-bar'), txt = p.querySelector('#ic-txt'), st = p.querySelector('#ic-stats');
  var running = false;
  function fmt(b) { if (b < 1024) { return b + ' B'; } if (b < 1048576) { return (b / 1024).toFixed(1) + ' KB'; } return (b / 1048576).toFixed(1) + ' MB'; }
  function stats() {
    fetch(apiUrl('?do=stats')).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { st.textContent = '读取失败：' + (j.msg || ''); return; }
      st.textContent = '缓存文件 ' + j.files + ' 个（' + fmt(j.bytes) + '） · 上次生成 ' + j.last + ' · 待处理图片 ' + j.total + ' 张';
      /* 自动生成：开启且距上次生成超过间隔天数时触发 */
      var auto = document.querySelector('input[name="imgCacheAuto"]:checked');
      var ivEl = document.querySelector('input[name="imgCacheInterval"]');
      var autoOn = auto ? auto.value === '1' : false;
      var iv = ivEl ? parseInt(ivEl.value || '1', 10) : 1;
      if (autoOn && j.stale >= iv && !running && j.total > 0) { genBtn.click(); }
    }).catch(function (e) { st.textContent = '读取失败：' + ((e && e.message) ? e.message : '接口异常'); });
  }
  function gen(offset) {
    fetch(apiUrl('?do=gen&offset=' + offset + '&n=10')).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { txt.textContent = '生成失败：' + (j.msg || ''); running = false; genBtn.disabled = false; return; }
      txt.textContent = '生成中 ' + j.done + ' / ' + j.total;
      bar.style.width = (j.total ? (j.done / j.total * 100) : 0) + '%';
      if (!j.finished) { gen(j.done); } else { txt.textContent = '全部完成'; running = false; genBtn.disabled = false; stats(); }
    }).catch(function () { txt.textContent = '请求失败，已停止（可重试）'; running = false; genBtn.disabled = false; });
  }
  genBtn.addEventListener('click', function () {
    if (running) { return; }
    running = true; genBtn.disabled = true; bar.style.width = '0'; txt.textContent = '扫描中…';
    fetch(apiUrl('?do=scan')).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { txt.textContent = '扫描失败：' + (j.msg || ''); running = false; genBtn.disabled = false; return; }
      if (!j.total) { txt.textContent = '没有需要处理的图片'; running = false; genBtn.disabled = false; stats(); return; }
      gen(0);
    }).catch(function () { txt.textContent = '扫描请求失败'; running = false; genBtn.disabled = false; });
  });
  var saveBtn = p.querySelector('#ic-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var fd = new FormData();
      fd.append('do', 'save_settings');
      var sec = p.querySelector('input[name="imgCacheSecret"]');
      var on = p.querySelector('input[name="imgCacheAuto"]:checked');
      var iv = p.querySelector('input[name="imgCacheInterval"]');
      fd.append('imgCacheSecret', sec ? sec.value : '');
      fd.append('imgCacheAuto', on ? on.value : '0');
      fd.append('imgCacheInterval', iv ? iv.value : '1');
      var pOn = p.querySelector('input[name="imgProxyEnable"]:checked');
      var wOn = p.querySelector('input[name="imgWebpEnable"]:checked');
      var dW = p.querySelector('input[name="imgDefaultW"]');
      var dQ = p.querySelector('input[name="imgDefaultQ"]');
      var cM = p.querySelector('input[name="imgCacheMax"]');
      fd.append('imgProxyEnable', pOn ? pOn.value : '1');
      fd.append('imgWebpEnable', wOn ? wOn.value : '1');
      fd.append('imgDefaultW', dW ? dW.value : '800');
      fd.append('imgDefaultQ', dQ ? dQ.value : '80');
      fd.append('imgCacheMax', cM ? cM.value : '800');
      txt.textContent = '保存中…';
      fetch(apiUrl(''), { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
        txt.textContent = j.ok ? '缓存设置已保存' : '保存失败：' + (j.msg || '');
      }).catch(function () { txt.textContent = '保存请求失败'; });
    });
  }
  clearBtn.addEventListener('click', function () {
    if (!window.confirm('确定清空全部图片代理缓存？下次访问会重新生成。')) { return; }
    fetch(api + '?do=clear').then(function (r) { return r.json(); }).then(function (j) {
      txt.textContent = '已清空 ' + (j.cleared || 0) + ' 个缓存文件'; bar.style.width = '0'; stats();
    });
  });
  stats();
})();
</script>
<script>window.TRI3_PANEL_MAP = {$mapJson};</script>
<script>window.TRI3_PANEL_SECTIONS = {$sectionJson};</script>
<script src="{$triThemeUrl}/assets/js/tri-msg.min.js?v=1.2.5"></script>
<script src="{$triThemeUrl}/assets/js/icons.js?v=1.2.5"></script>
<script src="{$triThemeUrl}/assets/js/admin.min.js?v=1.2.5"></script>
<script>
(function () {
  var q = new URLSearchParams(location.search);
  var m = q.get('tri_mail');
  var b = q.get('tri_bak');
  var msg = '', color = '#4caf50';
  if (m === 'ok') { msg = '测试邮件已发送，请检查收件箱（含垃圾箱）'; }
  else if (m === 'fail') { msg = '测试邮件发送失败，请检查 SMTP 服务器、端口、账号与授权码'; color = '#b3261e'; }
  else if (m === 'noconfig') { msg = '尚未配置 SMTP：请先填写服务器 / 端口 / 账号 / 授权码'; color = '#b3261e'; }
  else if (b === 'import_ok') { msg = '备份导入成功，所有数据已恢复'; }
  else if (b === 'import_fail') { msg = '备份导入失败：文件无效或格式不匹配'; color = '#b3261e'; }
  else if (b === 'reset_ok') { msg = '已重置全部主题设置，恢复默认值'; }
  if (!msg) { return; }
  /* 统一消息中心：页面加载后由 TriMsg 弹窗展示（上方居中、最高层） */
  (window.TriMsg || { show: function () {} }).show({ text: msg, type: color === '#b3261e' ? 'error' : 'success', duration: 4200 });
})();
/* 备份面板交互：导入（fetch 直传，避免与 Typecho 主表单嵌套）+ 重置（confirm） */
(function () {
  var fi = document.getElementById('tri3-bak-file-input');
  var btn = document.getElementById('tri3-bak-import-btn');
  if (fi && btn) {
    btn.addEventListener('click', function () {
      if (!fi.files || !fi.files[0]) { if (window.TriMsg) { window.TriMsg.error('请先选择备份 JSON 文件'); } else { alert('请先选择备份 JSON 文件'); } return; }
      var fd = new FormData();
      fd.append('tri_do', 'import');
      fd.append('backup_file', fi.files[0]);
      btn.disabled = true;
      btn.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> 导入中…';
      fetch('{$triSelf}', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { window.location.href = '{$triSelf}&tri_bak=' + (r.ok ? 'import_ok' : 'import_fail') + '#backup'; })
        .catch(function () { window.location.href = '{$triSelf}&tri_bak=import_fail#backup'; });
    });
  }
  var rs = document.getElementById('tri3-bak-reset');
  if (rs) {
    rs.addEventListener('click', function () {
      if (confirm('确定要重置全部主题设置吗？此操作不可撤销。说说 / 相册 / 小组件数据不受影响。')) {
        window.location.href = rs.getAttribute('data-href');
      }
    });
  }
})();
/* 发送测试邮件：AJAX 无刷新，结果 toast 提示 */
(function () {
  function tri3Toast(msg, ok) {
    /* 统一消息中心 */
    if (window.TriMsg) { (ok ? window.TriMsg.success : window.TriMsg.error)(msg); return; }
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;top:18px;right:18px;z-index:99999;background:' + (ok ? '#4caf50' : '#b3261e') + ';color:#fff;padding:11px 18px;border-radius:12px;font-size:13px;box-shadow:0 6px 18px rgba(0,0,0,.18);max-width:360px';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); }, 4200);
  }
  var b = document.getElementById('tri3-mail-test-btn');
  if (!b) { return; }
  b.addEventListener('click', function () {
    b.disabled = true;
    b.style.opacity = '.65';
    var fd = new FormData();
    fd.append('tri_do', 'testmail');
    fd.append('tri_ajax', '1');
    fetch('{$triSelf}', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { tri3Toast(j.msg || '已完成', !!j.ok); })
      .catch(function () { tri3Toast('发送失败：网络错误或未登录', false); })
      .finally(function () { b.disabled = false; b.style.opacity = '1'; });
  });
})();
(function () {
  function tri3Toast2(msg, ok) {
    /* 统一消息中心 */
    if (window.TriMsg) { (ok ? window.TriMsg.success : window.TriMsg.error)(msg); return; }
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;top:18px;right:18px;z-index:99999;background:' + (ok ? '#4caf50' : '#b3261e') + ';color:#fff;padding:11px 18px;border-radius:12px;font-size:13px;box-shadow:0 6px 18px rgba(0,0,0,.18);max-width:380px';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); }, 5200);
  }
  var sb = document.getElementById('tri3-spam-test-btn');
  if (!sb) { return; }
  sb.addEventListener('click', function () {
    var txt = window.prompt('输入一段测试评论内容（将用「当前已保存的过滤配置」检测）：', '加微信看片，点击 https://spam.example.com 了解更多');
    if (txt === null) { return; }
    sb.disabled = true;
    sb.style.opacity = '.65';
    var fd = new FormData();
    fd.append('tri_do', 'spam_test');
    fd.append('tri_ajax', '1');
    fd.append('text', txt);
    fetch('{$triSelf}', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { tri3Toast2('检测结果：' + (j.result || '未知') + '（命中规则：' + (j.rule || '无') + '）\\nAI：' + (j.ai || '未启用'), !!j.ok); })
      .catch(function () { tri3Toast2('测试失败：网络错误或未登录', false); })
      .finally(function () { sb.disabled = false; sb.style.opacity = '1'; });
  });
})();
(function () {
  var ab = document.getElementById('tri3-ai-batch-btn');
  if (!ab) { return; }
  var running = false;
  function tri3BatchOne() {
    var fd = new FormData();
    fd.append('tri_do', 'ai_summary_batch');
    fd.append('tri_ajax', '1');
    return fetch('{$triSelf}', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, msg: '网络错误或未登录' }; });
  }
  ab.addEventListener('click', function () {
    if (running) { return; }
    if (!window.confirm('将为「没有摘要」的文章自动逐篇生成 AI 摘要（每篇生成数秒，期间请勿关闭页面；已有手填摘要的文章不会被覆盖）。确定开始？')) { return; }
    running = true;
    ab.disabled = true;
    var okAll = 0, failAll = 0;
    (function next() {
      ab.textContent = '生成中…（已成功 ' + okAll + ' 篇）';
      tri3BatchOne().then(function (j) {
        if (j && j.done) {
          okAll += j.okNum;
          failAll += j.fail;
          if (j.remaining > 0) {
            setTimeout(next, 600);
          } else {
            var m = 'AI 摘要生成完成：成功 ' + okAll + ' 篇' + (failAll > 0 ? '，失败 ' + failAll + ' 篇' : '') + '，全部完成！';
            window.TriMsg ? window.TriMsg.success(m) : alert(m);
            running = false;
            ab.disabled = false;
            ab.textContent = '为已有文章批量生成摘要（自动逐篇生成）';
          }
        } else {
          var em = (j && j.msg) || '生成失败';
          window.TriMsg ? window.TriMsg.error(em) : alert(em);
          running = false;
          ab.disabled = false;
          ab.textContent = '为已有文章批量生成摘要（自动逐篇生成）';
        }
      });
    })();
  });
})();
(function () {
  function tri3Toast3(msg, ok) {
    /* 统一消息中心 */
    if (window.TriMsg) { (ok ? window.TriMsg.success : window.TriMsg.error)(msg); return; }
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;top:18px;right:18px;z-index:99999;background:' + (ok ? '#4caf50' : '#b3261e') + ';color:#fff;padding:11px 18px;border-radius:12px;font-size:13px;box-shadow:0 6px 18px rgba(0,0,0,.18);max-width:420px;word-break:break-all';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); }, 6000);
  }
  var itb = document.getElementById('tri3-imgtest-btn');
  if (!itb) { return; }
  itb.addEventListener('click', function () {
    itb.disabled = true;
    itb.style.opacity = '.65';
    /* 先确认存储方式：未配置图床时提示，避免测试图落到本地 */
    fetch('{$triThemeUrl}/api/album-api.php?do=storage', { method: 'POST', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (st) {
        if (!st || !st.ok) { throw new Error('无法获取存储方式'); }
        if (st.storage === 'local') {
          itb.disabled = false;
          itb.style.opacity = '1';
          tri3Toast3('当前为本地存储，未配置图床。请先在下方填写图床 API 地址与 Token 后再测试', false);
          return;
        }
        return doImgTest(itb);
      })
      .catch(function () { tri3Toast3('测试失败：网络错误或未登录', false); itb.disabled = false; itb.style.opacity = '1'; });
  });
  function doImgTest(btn) {
    var c = document.createElement('canvas');
    c.width = 1; c.height = 1;
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#3D5AFE';
    ctx.fillRect(0, 0, 1, 1);
    c.toBlob(function (blob) {
      var fd = new FormData();
      fd.append('do', 'upload');
      fd.append('aid', '0');
      fd.append('file[]', blob, 'tri3-bed-test.png');
      fetch('{$triThemeUrl}/api/album-api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          btn.disabled = false;
          btn.style.opacity = '1';
          if (j && j.ok && j.count > 0) {
            tri3Toast3('图床连通正常 ✓ 测试图已成功上传', true);
          } else {
            tri3Toast3('图床测试失败：' + ((j && (j.msg || (j.failed && j.failed[0] && j.failed[0].msg))) || '未知错误'), false);
          }
        })
        .catch(function () { tri3Toast3('图床测试失败：网络错误或未登录', false); btn.disabled = false; btn.style.opacity = '1'; });
    }, 'image/png');
  }
})();
(function () {
  /* 图床类型切换：各图床只显示自己的设置
   * 通用：API 地址 / API Token·Key / 图片域名前缀 / 文件字段名 / 返回地址路径
   * 又拍云专属：Token 防盗链密钥 / 签名有效期
   * 七牛云专属：AccessKey / SecretKey / 存储空间 / 区域 / key 前缀（上传域名自动映射，CDN 域名复用通用字段）
   * 注：表单字段由后台表单渲染（在本文档之后输出），必须等 DOM 就绪再初始化 */
  function triBedInit() {
    var triTypeSel = document.querySelector('select[name="albumApiType"]');
    if (!triTypeSel) { return; }
    var triBedRows = {};
    ['albumApiUrl', 'albumApiToken', 'albumApiFileField', 'albumApiUrlPath', 'albumApiDomain', 'upyunTokenSecret', 'upyunTokenTtl', 'qiniuAccessKey', 'qiniuSecretKey', 'qiniuBucket', 'qiniuRegion', 'qiniuDomain', 'qiniuPrefix', 'qiniuTokenSecret', 'qiniuTokenTtl'].forEach(function (id) {
      var el = document.querySelector('[name="' + id + '"]');
      if (!el) { return; }
      var row = el;
      while (row && row.tagName !== 'LI') { row = row.parentNode; }
      if (!row) { triBedRows[id] = el.parentNode; return; }
      /* 隐藏时收起整个字段容器：新版 Typecho 每字段一个 ul.typecho-option（隐藏 li 会留空框），旧版为共享 ul（只能隐藏 li） */
      if (row.parentNode && row.parentNode.tagName === 'UL' && row.parentNode.children.length === 1) {
        triBedRows[id] = row.parentNode;
      } else {
        triBedRows[id] = row;
      }
    });
    function triBedRowDesc(id) {
      var el = document.querySelector('[name="' + id + '"]');
      if (!el) { return null; }
      var row = el;
      while (row && row.tagName !== 'LI') { row = row.parentNode; }
      return row ? row.querySelector('.description') : null;
    }
    function triBedSync() {
      var t = triTypeSel.value || 'lsky';
      Object.keys(triBedRows).forEach(function (id) {
        var row = triBedRows[id];
        if (!row) { return; }
        var show = true;
        if (id === 'upyunTokenSecret' || id === 'upyunTokenTtl') { show = (t === 'upyun'); }
        else if (id === 'qiniuAccessKey' || id === 'qiniuSecretKey' || id === 'qiniuBucket' || id === 'qiniuRegion' || id === 'qiniuDomain' || id === 'qiniuPrefix' || id === 'qiniuTokenSecret' || id === 'qiniuTokenTtl') { show = (t === 'qiniu'); }
        else if (t === 'qiniu' && (id === 'albumApiUrl' || id === 'albumApiToken' || id === 'albumApiFileField' || id === 'albumApiUrlPath' || id === 'albumApiDomain')) { show = false; }
        row.style.display = show ? '' : 'none';
      });
      var qg = document.getElementById('triBedQiniuGen');
      if (qg) { qg.style.display = (t === 'qiniu') ? 'block' : 'none'; }
      var apiDesc = triBedRowDesc('albumApiUrl');
      var tokDesc = triBedRowDesc('albumApiToken');
      var domDesc = triBedRowDesc('albumApiDomain');
      if (t === 'upyun') {
        if (apiDesc) { apiDesc.textContent = '又拍云 Form API 地址：https://v0.api.upyun.com/服务名/（服务名 = 控制台对象存储服务名）。留空则图片上传到本站 /usr/uploads/albums/'; }
        if (tokDesc) { tokDesc.textContent = '又拍云操作员认证：「操作员名:操作员密码」（冒号分隔，来自又拍云控制台「操作员授权」）'; }
        if (domDesc) { domDesc.textContent = '若图床返回相对路径，填域名前缀用于补全，如 https://img.example.com'; }
      } else if (t === 'chevereto') {
        if (apiDesc) { apiDesc.textContent = 'Chevereto 上传接口：https://img.example.com/api/1/upload。留空则图片上传到本站 /usr/uploads/albums/'; }
        if (tokDesc) { tokDesc.textContent = 'Chevereto 网站后台生成的 API Key（以 key 字段提交）'; }
        if (domDesc) { domDesc.textContent = '若图床返回相对路径，填域名前缀用于补全，如 https://img.example.com'; }
      } else if (t === 'qiniu') {
        /* 七牛云专属字段独立显示，通用字段全部隐藏，无需改文案 */
      } else {
        if (apiDesc) { apiDesc.textContent = '兰空图床（Lsky Pro v2）上传接口：https://img.example.com/api/v1/upload。留空则图片上传到本站 /usr/uploads/albums/'; }
        if (tokDesc) { tokDesc.textContent = '兰空接口鉴权令牌（Bearer 方式附加到请求头）'; }
        if (domDesc) { domDesc.textContent = '若图床返回相对路径，填域名前缀用于补全，如 https://img.example.com'; }
      }
    }
    /* ===== 七牛时间戳防盗链：校验链接生成器（官方算法，生成后可粘贴到七牛控制台「时间戳 URL 校验」验证） ===== */
    function triQiniuMd5(input) {
      function safeAdd(x, y) { var lsw = (x & 0xFFFF) + (y & 0xFFFF); return (((x >> 16) + (y >> 16) + (lsw >> 16)) << 16) | (lsw & 0xFFFF); }
      function bitRotateLeft(num, cnt) { return (num << cnt) | (num >>> (32 - cnt)); }
      function md5cmn(q, a, b, x, s, t) { return safeAdd(bitRotateLeft(safeAdd(safeAdd(a, q), safeAdd(x, t)), s), b); }
      function md5ff(a, b, c, d, x, s, t) { return md5cmn((b & c) | ((~b) & d), a, b, x, s, t); }
      function md5gg(a, b, c, d, x, s, t) { return md5cmn((b & d) | (c & (~d)), a, b, x, s, t); }
      function md5hh(a, b, c, d, x, s, t) { return md5cmn(b ^ c ^ d, a, b, x, s, t); }
      function md5ii(a, b, c, d, x, s, t) { return md5cmn(c ^ (b | (~d)), a, b, x, s, t); }
      function binlMD5(x, len) {
        x[len >> 5] |= 0x80 << (len % 32);
        x[(((len + 64) >>> 9) << 4) + 14] = len;
        var i, olda, oldb, oldc, oldd, a = 1732584193, b = -271733879, c = -1732584194, d = 271733878;
        for (i = 0; i < x.length; i += 16) {
          olda = a; oldb = b; oldc = c; oldd = d;
          a = md5ff(a, b, c, d, x[i], 7, -680876936);
          d = md5ff(d, a, b, c, x[i + 1], 12, -389564586);
          c = md5ff(c, d, a, b, x[i + 2], 17, 606105819);
          b = md5ff(b, c, d, a, x[i + 3], 22, -1044525330);
          a = md5ff(a, b, c, d, x[i + 4], 7, -176418897);
          d = md5ff(d, a, b, c, x[i + 5], 12, 1200080426);
          c = md5ff(c, d, a, b, x[i + 6], 17, -1473231341);
          b = md5ff(b, c, d, a, x[i + 7], 22, -45705983);
          a = md5ff(a, b, c, d, x[i + 8], 7, 1770035416);
          d = md5ff(d, a, b, c, x[i + 9], 12, -1958414417);
          c = md5ff(c, d, a, b, x[i + 10], 17, -42063);
          b = md5ff(b, c, d, a, x[i + 11], 22, -1990404162);
          a = md5ff(a, b, c, d, x[i + 12], 7, 1804603682);
          d = md5ff(d, a, b, c, x[i + 13], 12, -40341101);
          c = md5ff(c, d, a, b, x[i + 14], 17, -1502002290);
          b = md5ff(b, c, d, a, x[i + 15], 22, 1236535329);
          a = md5gg(a, b, c, d, x[i + 1], 5, -165796510);
          d = md5gg(d, a, b, c, x[i + 6], 9, -1069501632);
          c = md5gg(c, d, a, b, x[i + 11], 14, 643717713);
          b = md5gg(b, c, d, a, x[i], 20, -373897302);
          a = md5gg(a, b, c, d, x[i + 5], 5, -701558691);
          d = md5gg(d, a, b, c, x[i + 10], 9, 38016083);
          c = md5gg(c, d, a, b, x[i + 15], 14, -660478335);
          b = md5gg(b, c, d, a, x[i + 4], 20, -405537848);
          a = md5gg(a, b, c, d, x[i + 9], 5, 568446438);
          d = md5gg(d, a, b, c, x[i + 14], 9, -1019803690);
          c = md5gg(c, d, a, b, x[i + 3], 14, -187363961);
          b = md5gg(b, c, d, a, x[i + 8], 20, 1163531501);
          a = md5gg(a, b, c, d, x[i + 13], 5, -1444681467);
          d = md5gg(d, a, b, c, x[i + 2], 9, -51403784);
          c = md5gg(c, d, a, b, x[i + 7], 14, 1735328473);
          b = md5gg(b, c, d, a, x[i + 12], 20, -1926607734);
          a = md5hh(a, b, c, d, x[i + 5], 4, -378558);
          d = md5hh(d, a, b, c, x[i + 8], 11, -2022574463);
          c = md5hh(c, d, a, b, x[i + 11], 16, 1839030562);
          b = md5hh(b, c, d, a, x[i + 14], 23, -35309556);
          a = md5hh(a, b, c, d, x[i + 1], 4, -1530992060);
          d = md5hh(d, a, b, c, x[i + 4], 11, 1272893353);
          c = md5hh(c, d, a, b, x[i + 7], 16, -155497632);
          b = md5hh(b, c, d, a, x[i + 10], 23, -1094730640);
          a = md5hh(a, b, c, d, x[i + 13], 4, 681279174);
          d = md5hh(d, a, b, c, x[i + 0], 11, -358537222);
          c = md5hh(c, d, a, b, x[i + 3], 16, -722521979);
          b = md5hh(b, c, d, a, x[i + 6], 23, 76029189);
          a = md5hh(a, b, c, d, x[i + 9], 4, -640364487);
          d = md5hh(d, a, b, c, x[i + 12], 11, -421815835);
          c = md5hh(c, d, a, b, x[i + 15], 16, 530742520);
          b = md5hh(b, c, d, a, x[i + 2], 23, -995338651);
          a = md5ii(a, b, c, d, x[i], 6, -198630844);
          d = md5ii(d, a, b, c, x[i + 7], 10, 1126891415);
          c = md5ii(c, d, a, b, x[i + 14], 15, -1416354905);
          b = md5ii(b, c, d, a, x[i + 5], 21, -57434055);
          a = md5ii(a, b, c, d, x[i + 12], 6, 1700485571);
          d = md5ii(d, a, b, c, x[i + 3], 10, -1894986606);
          c = md5ii(c, d, a, b, x[i + 10], 15, -1051523);
          b = md5ii(b, c, d, a, x[i + 1], 21, -2054922799);
          a = md5ii(a, b, c, d, x[i + 8], 6, 1873313359);
          d = md5ii(d, a, b, c, x[i + 15], 10, -30611744);
          c = md5ii(c, d, a, b, x[i + 6], 15, -1560198380);
          b = md5ii(b, c, d, a, x[i + 13], 21, 1309151649);
          a = md5ii(a, b, c, d, x[i + 4], 6, -145523070);
          d = md5ii(d, a, b, c, x[i + 11], 10, -1120210379);
          c = md5ii(c, d, a, b, x[i + 2], 15, 718787259);
          b = md5ii(b, c, d, a, x[i + 9], 21, -343485551);
          a = safeAdd(a, olda); b = safeAdd(b, oldb); c = safeAdd(c, oldc); d = safeAdd(d, oldd);
        }
        return [a, b, c, d];
      }
      function binl2rstr(input) {
        var i, output = '';
        var length32 = input.length * 32;
        for (i = 0; i < length32; i += 8) { output += String.fromCharCode((input[i >> 5] >>> (i % 32)) & 0xFF); }
        return output;
      }
      function rstr2binl(input) {
        var i, output = [];
        output[(input.length >> 2) - 1] = undefined;
        for (i = 0; i < output.length; i += 1) { output[i] = 0; }
        var length8 = input.length * 8;
        for (i = 0; i < length8; i += 8) { output[i >> 5] |= (input.charCodeAt(i / 8) & 0xFF) << (i % 32); }
        return output;
      }
      function rstrMD5(s) { return binl2rstr(binlMD5(rstr2binl(s), s.length * 8)); }
      function rstr2hex(input) {
        var hexTab = '0123456789abcdef', output = '', x, i;
        for (i = 0; i < input.length; i += 1) { x = input.charCodeAt(i); output += hexTab.charAt((x >>> 4) & 0x0F) + hexTab.charAt(x & 0x0F); }
        return output;
      }
      function str2rstrUTF8(input) { return unescape(encodeURIComponent(input)); }
      return rstr2hex(rstrMD5(str2rstrUTF8(input)));
    }
    function triQiniuBuild() {
      var keyEl = document.querySelector('[name="qiniuTokenSecret"]');
      var ttlEl = document.querySelector('[name="qiniuTokenTtl"]');
      var urlEl = document.getElementById('triQiniuGenUrl');
      var outEl = document.getElementById('triQiniuGenOut');
      if (!keyEl || !ttlEl || !urlEl || !outEl) { return; }
      var key = (keyEl.value || '').trim();
      var url = (urlEl.value || '').trim();
      if (!key) { tri3Toast2('请先填写「七牛 CDN 时间戳防盗链密钥」', false); return; }
      if (!/^https?:\/\//i.test(url)) { tri3Toast2('请填写完整的原始 URL（以 http:// 或 https:// 开头）', false); return; }
      var ttl = parseInt((ttlEl.value || '86400').trim(), 10);
      if (isNaN(ttl) || ttl <= 0) { ttl = 86400; }
      var u;
      try { u = new URL(url); } catch (e) { tri3Toast2('URL 格式不正确', false); return; }
      var enc = u.pathname.split('/').map(function (seg) {
        var d;
        try { d = decodeURIComponent(seg); } catch (e) { d = seg; }
        return encodeURIComponent(d).replace(/[!'()*]/g, function (c) { return '%' + c.charCodeAt(0).toString(16).toUpperCase(); });
      }).join('/');
      var T = (Math.floor(Date.now() / 1000) + ttl).toString(16);
      var sign = triQiniuMd5(key + enc + T);
      var sep = (url.indexOf('?') !== -1) ? '&' : '?';
      outEl.value = url + sep + 'sign=' + sign + '&t=' + T;
      tri3Toast2('校验链接已生成，请复制到七牛控制台「时间戳 URL 校验」验证', true);
    }
    function triBedInjectQiniuGen() {
      var ttlRow = triBedRows['qiniuTokenTtl'];
      if (!ttlRow || document.getElementById('triBedQiniuGen')) { return; }
      var box = document.createElement('div');
      box.id = 'triBedQiniuGen';
      box.style.cssText = 'margin:14px 0 4px;padding:14px 16px;border:1px dashed rgba(61,90,254,.35);border-radius:12px;background:rgba(61,90,254,.04)';
      box.innerHTML = '<b style="font-size:13px">校验链接生成器（七牛时间戳防盗链）</b>' +
        '<p style="font-size:12px;color:var(--tri-text-2,#5B5D64);margin:6px 0">填写要校验的原始图片 URL，点击生成后把结果粘贴到七牛控制台「域名管理 → 访问控制 → 时间戳防盗链 → 时间戳 URL 校验」验证签名算法，通过后再正式开启（密钥与有效期取上方配置）</p>' +
        '<div style="display:flex;gap:8px;align-items:center;margin:8px 0"><input id="triQiniuGenUrl" type="text" style="flex:1;padding:7px 10px;border:1px solid rgba(120,120,135,.25);border-radius:8px;font-size:13px;background:#fff;color:#222" placeholder="https://cdn.example.com/2026/09/16/test.jpg"><button type="button" id="triQiniuGenBtn" class="tri3-btn" style="flex:none"><i class="ri-quill-pen-line"></i> 生成校验链接</button></div>' +
        '<div style="display:flex;gap:8px;align-items:flex-start;margin:8px 0 0"><textarea id="triQiniuGenOut" readonly rows="2" style="flex:1;padding:7px 10px;border:1px solid rgba(120,120,135,.25);border-radius:8px;font-size:12px;background:#fff;color:#333;resize:none;word-break:break-all" placeholder="生成的签名 URL 将显示在这里"></textarea><button type="button" id="triQiniuGenCopy" class="tri3-btn" style="flex:none"><i class="ri-file-copy-line"></i> 复制</button></div>';
      ttlRow.parentNode.insertBefore(box, ttlRow.nextSibling);
      var urlEl = box.querySelector('#triQiniuGenUrl');
      var domInput = document.querySelector('[name="qiniuDomain"]');
      var fill = function () {
        var dv = domInput ? (domInput.value || '').trim() : '';
        if (dv && (urlEl.value || '').trim() === '') { urlEl.value = dv.replace(/\/+$/, '') + '/2026/09/16/test.jpg'; }
      };
      fill();
      if (domInput) { domInput.addEventListener('input', fill); }
      box.querySelector('#triQiniuGenBtn').addEventListener('click', triQiniuBuild);
      box.querySelector('#triQiniuGenCopy').addEventListener('click', function () {
        var out = box.querySelector('#triQiniuGenOut');
        if (!out.value) { tri3Toast2('请先生成校验链接', false); return; }
        out.select();
        try { document.execCommand('copy'); } catch (e) {}
        if (navigator.clipboard) { navigator.clipboard.writeText(out.value).catch(function () {}); }
        tri3Toast2('校验链接已复制', true);
      });
    }
    triTypeSel.addEventListener('change', triBedSync);
    triBedInjectQiniuGen();
    triBedSync();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', triBedInit);
  } else {
    triBedInit();
  }
})();
</script>
</div>
HTML;

        /* ================= ① 站点与作者 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'authorName',
            null,
            '',
            _t('作者昵称'),
            _t('显示于 banner 下方个人信息卡，留空则使用站点名')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'authorBio',
            null,
            '',
            _t('个人简介'),
            _t('显示于个人信息卡简介，留空则使用站点描述')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'profileExtras',
            null,
            '',
            _t('自定义信息'),
            _t('显示于个人简介下方，每行一条（如：📍 北京 · 中国、✉ hi@example.com）')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'authorSignature',
            null,
            '博主 · 记录与分享',
            _t('博主标签'),
            _t('显示于个人信息卡的职业标签，留空则不显示')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'logoUrl',
            null,
            '',
            _t('Logo 图片 URL'),
            _t('左侧栏顶部品牌区显示；留空则使用头像，再留空显示首字母圆形')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'faviconUrl',
            null,
            '',
            _t('Favicon 图标 URL'),
            _t('浏览器标签页图标。填写图片地址（.ico / .png / .svg，建议 32×32 以上）后使用该图标；留空则使用主题默认的 MD3 渐变字母图标（随主色自动变化）')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'avatarUrl',
            null,
            '',
            _t('头像 URL'),
            _t('留空则通过上方邮箱从 Cravatar 国内源自动获取博主头像；邮箱也未填时显示站点名首字母圆形头像')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'footerHtml',
            null,
            '',
            _t('页脚自定义内容（支持 HTML）'),
            _t('留空则显示默认版权行（© 年份 站点名 · Theme by <a href="https://github.com/asplun/TriM3">bohe</a> · Theme <a href="https://github.com/asplun/TriM3">TriM3</a>）；填写后原样输出，可包含链接、图标等任意 HTML')
        ));
    
        /* ================= ② 社交链接 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'github',
            null,
            '',
            _t('GitHub 链接'),
            _t('如 https://github.com/xxx，留空则不显示')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'weibo',
            null,
            '',
            _t('微博链接'),
            _t('如 https://weibo.com/xxx，留空则不显示')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'wechat',
            null,
            '',
            _t('微信号'),
            _t('留空则不显示微信图标')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'email',
            null,
            '',
            _t('邮箱'),
            _t('如 hello@example.com，留空则不显示；未设置头像时以此邮箱从 Cravatar 国内源获取博主与评论头像')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'rssEnable',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('RSS 图标'),
            _t('控制左侧栏与个人信息卡的 RSS 图标')
        ));
    
        /* ================= 全站 Swup ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'pjaxEnable',
            array('1' => _t('启用'), '0' => _t('关闭')),
            '1',
            _t('全站 Swup 无刷新切换'),
            _t('基于 Swup：站内链接无刷新切换（不整页刷新、不闪烁）；关闭后恢复传统整页跳转。首页导航局部切换、说说、评论区等交互不受影响')
        ));

        /* ================= ③ Banner 轮播 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'bannerEnable',
            array('1' => _t('启用'), '0' => _t('关闭')),
            '1',
            _t('Banner 轮播'),
            _t('首页顶部插画轮播，关闭后直接显示个人信息卡')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'bannerUrls',
            null,
            '',
            _t('轮播图片（每行一张 URL）'),
            _t('每行一个图片地址，数量不限；留空则使用主题自带 3 张 banner 图（建议 1200×360 宽图）')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'bannerInterval',
            null,
            '5',
            _t('自动切换间隔（秒）'),
            _t('数字，如 5 表示每 5 秒自动切换一张；0 表示不自动切换，仅手动点圆点')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'bannerSearch',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('Banner 左上角搜索'),
            _t('点击搜索按钮向右延伸出搜索框，提交后进入站内搜索；关闭后不显示该按钮')
        ));
    
        /* ================= ④ 数据统计 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'showLikes',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示点赞数'),
            _t('个人信息卡中的点赞数是否显示')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'showComments',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示评论数'),
            _t('个人信息卡中的评论数是否显示')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'showViews',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示访问数'),
            _t('个人信息卡中的访问数是否显示')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'statsMode',
            array(
                'default' => _t('默认统计'),
                'custom'  => _t('自定义 HTML'),
                'none'    => _t('完全不显示'),
            ),
            'default',
            _t('统计区显示方式'),
            _t('默认统计：显示点赞 / 评论 / 访问（由上面三个开关控制）；自定义 HTML：显示下方填写的代码；完全不显示：隐藏整片统计区')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'statsCustomHtml',
            null,
            '',
            _t('自定义统计 HTML'),
            _t('当「统计区显示方式」选择「自定义 HTML」时生效，直接输出此处代码。可自由排版，如 <div style="display:flex;gap:12px"><span>...</span></div>')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'likesNum',
            null,
            '184',
            _t('点赞数'),
            _t('展示于个人信息卡，可填如 184')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'viewsNum',
            null,
            '3683',
            _t('访问数'),
            _t('展示于个人信息卡，可填如 3683')
        ));
    
        /* ================= ⑤ 布局 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'leftSidebarEnable',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('左侧栏（导航）'),
            _t('参考 Pix 可自由搭配：左右栏各自独立开关，隐藏后主内容自动占满')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'rightSidebarEnable',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('右侧栏（侧边信息）'),
            _t('控制右侧栏整体显示；右侧栏内容由「小组件管理」中的小组件构成，隐藏后主内容自动占满')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'excerptLen',
            null,
            '45',
            _t('摘要字数'),
            _t('文章列表摘要截取字数，默认 45（列表卡片内最多显示 2 行，绝不出现第 3 行）')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'leftFixed',
            array('1' => _t('固定'), '0' => _t('跟随页面滚动')),
            '1',
            _t('左侧栏'),
            _t('桌面端左侧导航栏是否固定不随滚动')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'navNarrow',
            array('1' => _t('收窄（仅图标，悬停显示名称）'), '0' => _t('完整（图标 + 名称）')),
            '0',
            _t('左侧栏桌面端显示方式'),
            _t('收窄后桌面端左侧栏变为 68px 图标栏，鼠标悬停显示菜单名；平板端始终自动收窄，手机端隐藏，此设置不影响二者')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'bannerHeight',
            null,
            '200',
            _t('Banner 高度（px）'),
            _t('默认 200（与 pix 一致）；移动端自动降为 140')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'paginationStyle',
            array(
                'pager'    => _t('上一页 / 下一页'),
                'loadmore' => _t('加载更多 / 无限加载'),
            ),
            'pager',
            _t('列表分页样式'),
            _t('首页 / 分类 / 归档 / 搜索 / 说说 所有列表统一：上一页下一页为局部无刷新切换；加载更多为滚动到底部自动无限加载（也可点击按钮手动加载）')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'pageSize',
            null,
            '10',
            _t('列表每页文章数目'),
            _t('首页 / 分类 / 标签 / 归档 / 搜索等文章列表每页显示的文章数。留空或填 0 时自动跟随 Typecho「设置→阅读→每页文章数目」')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'homeListMode',
            array(
                'original' => _t('原样（当前文章列表）'),
                'moment'   => _t('说说模式（首页显示说说列表）'),
                'fusion'   => _t('融合模式（文章与说说按时间线融合，文章显示为图文动态卡片）'),
            ),
            'original',
            _t('首页文章列表模式'),
            _t('原样：保持现有文章卡片列表；说说模式：首页直接展示说说流；融合模式：文章与说说按发布时间混合排列，文章以图文动态卡片展示（参考 sigge.cn）')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'postCardStyle',
            array(
                'original' => _t('简洁列表（当前样式）'),
                'card'     => _t('卡片式（圆角背景+左封面）'),
                'grid'     => _t('方格式（一行两个）'),
            ),
            'original',
            _t('文章列表卡片样式'),
            _t('简洁列表：细分割线+左封面；卡片式：圆角卡片背景+右封面+分类图标顶栏')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'fusionExcerpt',
            null,
            '123',
            _t('融合模式文章卡片摘要字数'),
            _t('文章卡片正文最多显示多少字（默认 123），仅提取纯文字，短代码不显示；图片以图集形式展示在文字下方')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'leftWidth',
            null,
            '',
            _t('左栏宽度(px)'),
            _t('默认 320，范围 80-800，留空用默认')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'mainWidth',
            null,
            '',
            _t('中栏宽度(px)'),
            _t('默认 640，范围 320-1200，留空用默认。页面总宽度自动 = 左+中+右，始终居中，改宽度不影响其他布局')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'rightWidth',
            null,
            '',
            _t('右栏宽度(px)'),
            _t('默认 320，范围 80-800，留空用默认')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'navItems',
            null,
            '',
            _t('左侧导航项（每行：名称|链接|图标）'),
            _t('每行一个：名称|链接（图标自动识别，例如 首页、关于、分类、友链 会自动配图标；想指定图标可写 名称|链接|图标 三段）。留空则使用默认的「首页 + RSS 订阅」')
        ));

        /* 手机端底部菜单：单个 Textarea，每行 名称|链接|图标，可视化编辑器在 admin.js 提供 */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'mobileMenu',
            null,
            '首页|' . $siteUrl . '|ri-home-5-line' . "
" . '文章|' . $siteUrl . '|ri-article-line' . "
" . '归档|' . $siteUrl . '|ri-archive-line' . "
" . '留言|' . $siteUrl . '|ri-chat-3-line' . "
" . '关于|' . $siteUrl . '|ri-information-line',
            _t('手机端底部菜单（可视化编辑，也可直接填文本）'),
            _t('手机端底部固定导航，每行一个：名称|链接|图标。可视化编辑器：点图标选图标，填名称和链接，可增删调整顺序；图标留空会自动匹配或隐藏。留空则前端不显示底部菜单')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'homeMenu',
            null,
            '',
            _t('首页菜单栏（可视化编辑，也可直接填文本）'),
            _t('个人信息卡下方横向菜单（参考 sigge.cn）。后台提供可视化编辑器：点「添加菜单项」新增，每项可填名称、链接（留空自动 = 首页）、类型（普通链接 / 文章汇总单页模版）。文章分类类型的菜单项，点击后会自动展开为「首页 + 文章 + 分类列表」。留空则默认：首页 说说 关于 文章（自动检测存在的页面）')
        ));

        /* 首页菜单显示的分类：复选框直接勾选，留空 = 显示全部分类
         * 选项按已保存（拖拽后）的顺序排列，未保存过的新分类追加末尾 */
        $triCatOptions = array();
        try {
            $triSavedCats = array();
            $triSavedCatVal = Helper::options()->homeMenuCats ?? '';
            if (is_array($triSavedCatVal)) {
                foreach ($triSavedCatVal as $k => $vv) {
                    $triSavedCats[] = trim((string) (is_string($k) ? $k : $vv));
                }
            }
            $triCatAll = array();
            $triCatList = \Typecho\Widget::widget('Widget_Metas_Category_List');
            if ($triCatList->have()) {
                while ($triCatList->next()) {
                    $triCatAll[(string) $triCatList->slug] = (string) $triCatList->name . '（' . (int) $triCatList->count . ' 篇）';
                }
            }
            if (!empty($triSavedCats)) {
                foreach ($triSavedCats as $triS) {
                    $triS = trim((string) $triS);
                    if ($triS !== '' && isset($triCatAll[$triS])) {
                        $triCatOptions[$triS] = $triCatAll[$triS];
                        unset($triCatAll[$triS]);
                    }
                }
            }
            foreach ($triCatAll as $triS => $triN) {
                $triCatOptions[$triS] = $triN;
            }
        } catch (\Throwable $e) {
            /* 忽略：分类列表获取失败时留空选项 */
        }
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Checkbox(
            'homeMenuCats',
            $triCatOptions,
            array(),
            _t('文章菜单显示的分类'),
            _t('进入「文章」单页后展开的分类列表只显示勾选的分类；不勾选 = 显示全部分类（与说说分类同逻辑）')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'noticeText',
            null,
            '',
            _t('首页通知栏'),
            _t('横向导航上方的通知条（支持少量 HTML，如 <a href=\"https://…\">链接</a>、<strong>加粗</strong>）。留空则不显示')
        ));
    
        /* ================= ⑥ 外观定制 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'dynamicColor',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '0',
            _t('动态取色'),
            _t('开启后自动从首页 Banner 图片提取主色，整套 MD3 配色随之变化（Material You 效果）。开启后下方手动配色仍作为兜底，Banner 加载失败或无 Banner 时使用手动配色')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'primaryColor',
            null,
            '#6750a4',
            _t('主色 Primary'),
            _t('留空自动：由背景色按 MD3 推导。也可手动指定主色（点击右侧色块取色），其余色板颜色将随之重新推荐')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'secondaryColor',
            null,
            '#5b8def',
            _t('次色 Secondary'),
            _t('留空自动：由主色推导（同族偏移色），用于链接与图标点缀；可手动调整')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'tertiaryColor',
            null,
            '#e2558f',
            _t('第三色 Tertiary'),
            _t('留空自动：由主色推导（同族反向偏移色），用于标签点缀；可手动调整')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'primaryContainerColor',
            null,
            '#eaddff',
            _t('主色容器 Primary Container'),
            _t('留空自动：MD3 从主色派生浅色容器，用于选中/激活背景；可手动调整')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'onPrimaryContainerColor',
            null,
            '#21005d',
            _t('容器文字 On Primary Container'),
            _t('留空自动：容器上的深色文字，随主色推导；可手动调整')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'onPrimaryColor',
            null,
            '#ffffff',
            _t('主色上文字 On Primary'),
            _t('留空自动：主色上的文字色（深主色自动取深色、浅主色取白色）；可手动调整')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'bgColor',
            null,
            '#f8f5fc',
            _t('背景色 Background'),
            _t('页面最底层背景色（body）。整套 MD3 色板（主色、次色、容器色、surface 阶梯、outline 以及正文/说明/时间等文本层级）均由它推导，改后全站自动跟随；留空使用默认 #f8f5fc')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'bgGradient',
            array('1' => _t('渐变'), '0' => _t('纯色')),
            '1',
            _t('页面背景'),
            _t('渐变：基于背景色自动生成的淡彩 / 色斑背景，可下方选择方案；纯色：使用背景色，整页纯色')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'bgGradStyle',
            array(
                'auto'    => _t('淡彩（默认）'),
                'primary' => _t('主色同系'),
                'bg'      => _t('背景同系'),
                'calm'    => _t('柔和中性'),
            ),
            'auto',
            _t('渐变方案'),
            _t('仅渐变模式生效，底色始终取背景色。淡彩（默认）：色斑由背景色与主色共同派生，改背景色自动跟随；主色同系：色斑全部由主色派生；背景同系：色斑全部由背景色派生；柔和中性：低饱和米白灰')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'glassEffect',
            array('1' => _t('亚克力'), '0' => _t('不透明')),
            '1',
            _t('大框质感'),
            _t('亚克力：近白半透明 + 背景模糊，透出背景色斑；不透明：纯白')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'radiusScale',
            array(
                's' => _t('小圆角'),
                'm' => _t('中圆角（默认）'),
                'l' => _t('大圆角'),
            ),
            'm',
            _t('圆角尺度'),
            _t('整体卡片圆角大小，Material 3 形状系统')
        ));
    
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'darkMode',
            array(
                '0' => _t('跟随系统 / 手动切换'),
                '1' => _t('默认暗色'),
                '2' => _t('强制亮色'),
            ),
            '0',
            _t('暗色模式'),
            _t('「跟随系统」时右上角月亮按钮可手动切换并记忆')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'siteFont',
            null,
            '',
            _t('全站字体'),
            _t('默认使用内置 OPPO Sans 4.0（已分包按需加载，加速访问）。如需自定义，填写 CSS font-family 栈，如 \"HarmonyOS Sans SC\", \"MiSans\", sans-serif，填写后优先使用自定义字体')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'tri3_404Compat',
            array('1' => _t('兼容模式（默认）'), '0' => _t('标准 404 状态码')),
            '1',
            _t('404 页面输出'),
            _t('兼容模式：以 200 状态输出主题 404 页，页面同时声明 noindex 不被收录（部分服务器/面板会拦截 404 状态响应导致主题 404 无法显示，如宝塔默认 error_page 404）；标准模式：返回 404 状态码，若被服务器拦截则显示服务器默认 404 页')
        ));

        /* ================= ⑧ 播放器 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'playerEnable',
            array('1' => _t('启用'), '0' => _t('关闭')),
            '1',
            _t('头像播放器'),
            _t('在首页个人信息卡头像处集成迷你播放器：未播放时头像右侧显示音量/歌单/歌词按钮，banner 底部分界处显示进度条；鼠标移过头像显示播放键，播放后头像变为歌曲封面并显示上曲/播放/下曲，名字/简介切换为歌曲信息')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'playerSource',
            array('meting' => _t('内置代理（推荐，免配置）'), 'manual' => _t('自定义歌单')),
            'meting',
            _t('歌曲数据源'),
            _t('内置代理：通过主题自带的 api/meting-api.php（Metowolf/Meting 库）在服务器端请求网易云/QQ 等平台，自动拉取歌单、封面与 LRC 歌词，音频经本地转发（无跨域限制）；自定义歌单：手动填写每行一首，歌词用下方「自定义歌词」设置')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'metingPlatform',
            array(
                'netease' => _t('网易云音乐'),
                'tencent' => _t('QQ 音乐'),
                'kugou'   => _t('酷狗音乐'),
                'kuwo'    => _t('酷我音乐'),
            ),
            'netease',
            _t('音乐平台'),
            _t('选择歌曲来源平台，与下方「资源类型」「资源 ID」配合使用。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'metingResourceType',
            array(
                'playlist' => _t('歌单'),
                'album'    => _t('专辑'),
                'artist'   => _t('歌手'),
                'song'     => _t('单曲'),
            ),
            'playlist',
            _t('资源类型'),
            _t('选择资源类型，与上方「音乐平台」和下方「资源 ID」配合使用。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'metingResourceId',
            null,
            '',
            _t('资源 ID'),
            _t('与上方「平台」「类型」配合：直接填写数字/字母 ID 即可，如 QQ 单曲 <code>003OUlho2HcRHC</code>、网易云歌单 <code>3778678</code>。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'metingApi',
            null,
            '',
            _t('Meting API 地址（高级，可留空）'),
            _t('留空自动使用本站内置代理（usr/themes/TriM3/api/meting-api.php，已内置 VIP Cookie 支持）。也可填写自建/第三方 Meting API 地址，例如 <code>https://api.injahow.cn/meting</code>（外部 API 需支持跨域且音频可直连，此时上方 Cookie 不生效）')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'metingCookieNetease',
            null,
            '',
            _t('网易云 Cookie（播放 VIP 音乐，可选）'),
            _t('登录网易云网页版（music.163.com），F12 → Network 复制任意请求的完整 Cookie（需包含 <code>MUSIC_U</code>）粘贴至此。服务端附加该 Cookie 请求平台，即可播放 VIP/需要登录的歌曲。仅对网易云生效，可留空。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'metingCookieTencent',
            null,
            '',
            _t('QQ 音乐 Cookie（播放 VIP 音乐，可选）'),
            _t('登录 QQ 音乐网页版（y.qq.com），F12 → Network 复制任意请求的完整 Cookie（需包含 <code>uin</code> 与 <code>qqmusic_key</code>，VIP 用户优先）粘贴至此。服务端附加该 Cookie 请求平台，即可播放 VIP/需要登录的歌曲。仅对 QQ 音乐生效，可留空。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'playlist',
            null,
            '',
            _t('自定义歌单（每行一首）'),
            _t('仅「自定义歌单」数据源时使用。每行格式：封面图片URL | 歌曲标题 | 音频URL | 歌手/专辑（可选）。示例：<br><code>https://example.com/cover.jpg | 我的歌 | https://example.com/song.mp3 | 我</code>')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'musicPlaylists',
            null,
            '',
            _t('音乐页多歌单（每行一个）'),
            _t('在音乐歌单页显示的歌单列表，前台可切换。每行格式：名称|平台|歌单ID<br>示例：<br><code>我喜欢的音乐|netease|6647175851</code><br><code>华语金曲|tencent|7019251818</code>')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'playerVolume',
            null,
            '0.7',
            _t('默认音量（0-1）'),
            _t('如 0.7 表示 70%；用户点击音量按钮切换静音，音量记忆保存在浏览器')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'playerLyric',
            null,
            '',
            _t('自定义歌词（每行一句）'),
            _t('仅「自定义歌单」数据源且歌曲无 LRC 时使用；Meting 数据源自动使用歌曲自带 LRC 歌词做时间同步。点击名字右侧歌词按钮开关左下角滚动歌词；留空则显示「暂无歌词」。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Checkbox(
            'playerLyricDefault',
            array('on' => _t('默认开启歌词显示')),
            array(),
            _t('歌词默认显示'),
            _t('不勾选 = 默认关闭，歌词浮层隐藏；勾选 = 默认显示。保存后前台即时生效；歌词按钮可在当前页面临时切换，刷新后回到本设置状态。')
        ));

        /* ================= ⑨ 友链 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'friends',
            null,
            '',
            _t('友链列表'),
            _t('使用下方可视化编辑器添加 / 编辑 / 删除友链，拖动卡片即可排序；分组在「友链分组」中定义，编辑友链时可选择或直接输入新分组。数据自动保存到本字段，无需手动填写')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'friendGroups',
            null,
            '',
            _t('友链分组（每行一个分组名）'),
            _t('控制前端分组的显示顺序，每行一个分组名，如：<br><code>推荐</code><br><code>朋友</code><br><code>网站</code><br>留空则按友链数据中出现顺序自动分组；未在列表中列出的分组会排在最后。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'friendApply',
            null,
            '',
            _t('申请友链说明'),
            _t('显示在友链页面上方的说明文字（可含 HTML，如交换友链要求）；留空则不显示')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'shotCacheDays',
            null,
            '5',
            _t('截图缓存（天）'),
            _t('友链网站截图在服务器本地缓存的天数（默认 5），到期后自动重新抓取。点击下方「立即刷新截图缓存」可随时清空缓存强制重抓')
        ));

        /* ================= ⑨ 相册 · 图床 API ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'albumApiType',
            array('lsky' => '兰空图床（Lsky Pro v2）', 'chevereto' => 'Chevereto', 'upyun' => '又拍云（Upyun 对象存储）', 'qiniu' => '七牛云（Kodo 对象存储）'),
            'lsky',
            _t('图床类型'),
            _t('选择图床类型后自动匹配默认文件字段与返回地址路径，也可在下方自定义覆盖。又拍云：API 地址填 https://v0.api.upyun.com/服务名/，Token 填「操作员名:操作员密码」；七牛云：填写 AccessKey / SecretKey / 存储空间 / 区域 / CDN 域名即可，由服务端生成上传凭证直传。填写配置后，相册管理页、后台编辑器、前台/后台说说发布框的上传按钮都会直传图床')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'albumApiUrl',
            null,
            '',
            _t('图床上传 API 地址'),
            _t('图床的上传接口。兰空：https://img.example.com/api/v1/upload；Chevereto：https://img.example.com/api/1/upload；又拍云：https://v0.api.upyun.com/服务名/。留空则图片上传到本站 /usr/uploads/albums/')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'albumApiToken',
            null,
            '',
            _t('API Token / Key'),
            _t('兰空：接口鉴权令牌（Bearer 方式附加到请求头）；Chevereto：网站后台生成的 API Key（以 key 字段提交）；又拍云：「操作员名:操作员密码」（冒号分隔）')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'albumApiFileField',
            null,
            '',
            _t('文件字段名'),
            _t('上传时携带文件的表单字段名，默认按图床类型：兰空 file、Chevereto source，留空自动；特殊图床可自定义覆盖')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'albumApiUrlPath',
            null,
            '',
            _t('返回地址 JSON 路径'),
            _t('图床响应中图片地址所在的字段路径，默认按图床类型：兰空 data.links.url、Chevereto image.url，留空自动；通用接口可为 url 或 data.url')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'albumApiDomain',
            null,
            '',
            _t('图片域名前缀'),
            _t('若图床返回相对路径，填域名前缀用于补全，如 https://img.example.com')
        ));
        /* 又拍云专属：Token 防盗链（CDN 签名防盗链） */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'upyunTokenSecret',
            null,
            '',
            _t('又拍云 Token 防盗链密钥'),
            _t('与又拍云控制台「服务 → 防盗链 → Token 防盗链」中配置的密钥一致（如 abc123456）。留空则不启用防盗链签名。启用后上传返回的图片地址自动追加 _upt 签名（_upt = MD5(密钥 & 过期时间 & 路径) 中间 8 位 + 过期时间），未过期前可正常访问，过期后返回 403')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'upyunTokenTtl',
            null,
            '86400',
            _t('防盗链签名有效期（秒）'),
            _t('生成的 _upt 签名有效期（默认 86400 = 1 天）。到期后图片地址需重新生成（重新上传或刷新缓存）。前台页面会按生成时的时间点计算，建议给足浏览缓存余量，如 2592000（30 天）')
        ));
        /* 七牛云专属：AK / SK / 空间 / 区域 / key 前缀（上传域名由区域自动映射，CDN 域名复用 albumApiDomain） */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuAccessKey',
            null,
            '',
            _t('七牛云 AccessKey'),
            _t('七牛云控制台「密钥管理」中的 AccessKey（AK）。七牛云通过服务端生成上传凭证后表单直传，不暴露密钥到前台')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuSecretKey',
            null,
            '',
            _t('七牛云 SecretKey'),
            _t('七牛云控制台「密钥管理」中的 SecretKey（SK），用于生成上传凭证签名（HMAC-SHA1），仅保存在服务器')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuBucket',
            null,
            '',
            _t('存储空间名（Bucket）'),
            _t('七牛云对象存储空间的名称，如 my-bucket。上传凭证 scope 即为该空间')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'qiniuRegion',
            array('z0' => '华东（upload.qiniup.com）', 'z1' => '华北（upload-z1.qiniup.com）', 'z2' => '华南（upload-z2.qiniup.com）', 'na0' => '北美（upload-na0.qiniup.com）', 'as0' => '东南亚（upload-as0.qiniup.com）'),
            'z0',
            _t('存储区域'),
            _t('选择存储空间所在区域，上传时自动使用对应区域的上传域名')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuDomain',
            null,
            '',
            _t('CDN 访问域名'),
            _t('七牛 CDN 访问域名（图片地址前缀），如 https://cdn.example.com（可用七牛默认测试域名 *.bkt.clouddn.com，或控制台绑定的自定义 HTTPS 域名）')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuPrefix',
            null,
            '',
            _t('资源路径前缀（可选）'),
            _t('上传对象在空间内的 key 前缀，如 img/（留空则直接按 年/月/日/时间戳_随机名 存放）')
        ));
        /* 七牛云专属：CDN 时间戳防盗链（密钥 / 有效期） */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuTokenSecret',
            null,
            '',
            _t('七牛 CDN 时间戳防盗链密钥'),
            _t('与七牛 CDN 控制台「域名管理 → 访问控制 → 时间戳防盗链」中配置的 key 一致（长度 24-40 字符，主/备 key 任选其一）。留空则不启用。启用后主题输出的所有七牛图片地址自动追加 sign 与 t 参数（S = key + url_encode(path) + T，SIGN = md5(S) 小写，T 为过期时间的 16 进制小写），且每次页面输出都会重新签名，不会过期失效。注意：若存储空间为私有空间，还需在 CDN 控制台开启「回源鉴权」，否则签名通过的请求回源时会被空间拒绝（返回 401 download token not specified）')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'qiniuTokenTtl',
            null,
            '86400',
            _t('防盗链签名有效期（秒）'),
            _t('生成的签名有效期（默认 86400 = 1 天）。到期后图片地址需重新生成（重新上传或刷新缓存）。建议给足浏览缓存余量，如 2592000（30 天）')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'doubanUid',
            null,
            '',
            _t('豆瓣用户 ID'),
            _t('豆瓣个人主页 URL 中的 ID，如 https://www.douban.com/people/<code>doubanuser</code>/ 中的 doubanuser')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'doubanApi',
            null,
            '',
            _t('豆瓣 API 地址（可选）'),
            _t('留空使用默认数据源（豆瓣移动端接口）。如自建 douban-api-rs / douban-api 服务，填写服务根地址，约定路径：<code>{地址}/{用户ID}/{type}/{数量}</code>，返回豆瓣兼容 JSON')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Checkbox(
            'doubanTypes',
            array('book' => _t('读书'), 'movie' => _t('电影'), 'music' => _t('音乐')),
            array('book', 'movie', 'music'),
            _t('展示分类'),
            _t('勾选前台豆瓣页要展示的标记分类，默认全部展示')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'doubanCount',
            null,
            '12',
            _t('每类展示数量'),
            _t('每个分类最多展示多少条标记（1-50，默认 12）')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'doubanCache',
            null,
            '24',
            _t('缓存时长（小时）'),
            _t('豆瓣数据在服务器本地缓存的小时数（默认 24），到期自动重新抓取')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'doubanShowFilter',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示状态/类型筛选'),
            _t('前台豆瓣页每类下显示「想读/在读/读过」状态与类型（分类）筛选胶囊')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'doubanShowRemark',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示短评/摘要'),
            _t('卡片上显示你的短评（无短评时显示条目副标题）')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'doubanShowRating',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示豆瓣评分'),
            _t('卡片封面角标与信息区评分条，五星比例展示')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'doubanShowSearch',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示搜索框'),
            _t('前台豆瓣页顶部搜索框，按书名/片名/作者即时过滤')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'doubanCacheCover',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('封面本地缓存'),
            _t('开启后封面图片下载到主题 cache/douban-img 目录，避免豆瓣图床防盗链失效；关闭则直连原图')
        ));

        /* ================= ⑫ 番剧 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'biliUid',
            null,
            '',
            _t('B 站 UID'),
            _t('个人空间链接中的数字，如 https://space.bilibili.com/<code>28645589</code> 中的 28645589。追番列表需在 B 站个人空间设为公开')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'biliType',
            array('1' => _t('番剧'), '2' => _t('追剧（国创/影视）')),
            '1',
            _t('展示类型'),
            _t('番剧：动画；追剧：国创、电影、电视剧、纪录片等')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'biliCount',
            null,
            '100',
            _t('抓取数量'),
            _t('最多抓取的追番条数（20-500，默认 100）。数量越大首次加载越慢')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'biliCache',
            null,
            '24',
            _t('缓存时长（小时）'),
            _t('追番列表本地缓存时间，默认 24 小时，避免频繁请求 B 站接口')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'biliShowSearch',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示搜索框'),
            _t('前台番剧页顶部搜索框，按追番标题即时过滤')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'biliShowFilter',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示类型筛选'),
            _t('按番剧类型（搞笑/奇幻/日常等）过滤的胶囊标签')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'biliShowRemark',
            array('1' => _t('显示'), '0' => _t('隐藏')),
            '1',
            _t('显示简介'),
            _t('卡片底部显示番剧简介（最多一行）')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'biliCacheCover',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('封面本地缓存'),
            _t('开启后封面下载到主题 cache/bangumi-img 目录，避免 B 站图床防盗链失效；关闭则直连原图')
        ));

        /* ================= ⑬ 邮件通知 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'mailEnable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('邮件通知总开关'),
            _t('访客评论通知站长、站长回复通知访客。关闭后不发送任何邮件')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'mailNotifyAdmin',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('新评论通知站长'),
            _t('访客发表评论（含回复）时，发邮件到下方的「通知邮箱」')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'mailReplyNotify',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('回复通知访客'),
            _t('站长回复访客评论时，发邮件给被回复者；对方填写了 QQ 则发到 QQ 邮箱（QQ号@qq.com），否则发到其邮箱')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'mailAdminTo',
            null,
            '',
            _t('通知邮箱（站长收件箱）'),
            _t('新评论 / 待审核评论通知发往该邮箱；留空默认使用系统管理员邮箱')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'mailMode',
            array('smtp' => _t('SMTP 发送（推荐）'), 'mail' => _t('PHP mail() 函数')),
            'smtp',
            _t('发送方式'),
            _t('SMTP 稳定可靠（支持 QQ / 163 / Gmail 等邮箱的授权码）；mail() 依赖服务器已配置 sendmail，成功率较低')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'smtpHost',
            null,
            '',
            _t('SMTP 服务器'),
            _t('如 smtp.qq.com / smtp.163.com / smtp.gmail.com。选择 mail() 方式可留空')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'smtpPort',
            null,
            '465',
            _t('SMTP 端口'),
            _t('SSL 加密默认 465；STARTTLS 加密默认 587；不加密默认 25')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'smtpSecure',
            array('ssl' => _t('SSL（465）'), 'tls' => _t('STARTTLS（587）'), 'none' => _t('不加密（25）')),
            'ssl',
            _t('SMTP 加密方式'),
            _t('绝大多数邮箱服务商要求 SSL；按服务商文档选择')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'smtpUser',
            null,
            '',
            _t('SMTP 账号'),
            _t('发件邮箱完整地址，如 yourname@qq.com')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password(
            'smtpPass',
            null,
            '',
            _t('SMTP 密码 / 授权码'),
            _t('QQ / 163 等邮箱需使用「授权码」而非登录密码，在邮箱设置中开启 SMTP 服务获取')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'mailFromName',
            null,
            '',
            _t('发件人名称'),
            _t('邮件发件人显示名，留空使用 SMTP 账号')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'mailFromAddr',
            null,
            '',
            _t('发件邮箱'),
            _t('邮件 From 地址，留空默认使用 SMTP 账号')
        ));

        /* ============ 评论过滤（规则引擎 + 豆包 AI 可选层） ============ */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'spamEnable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('垃圾评论过滤总开关'),
            _t('关闭后所有规则与 AI 过滤均不生效，评论按 Typecho 默认规则处理')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'spamMode',
            array(
                'loose'  => _t('宽松：只拦高置信垃圾（蜜罐 / 黑白名单 / 关键词）'),
                'normal' => _t('标准：宽松 + 链接数量 / 内容过短（推荐）'),
                'strict' => _t('严格：标准 + 空UA爬虫 / 链接密度 / 连续重复字符'),
            ),
            'normal',
            _t('过滤强度'),
            _t('强度越高拦截越多，误杀风险也越高。建议保持「标准」，确有垃圾骚扰再升级「严格」')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'spamKeywords',
            null,
            '',
            _t('黑名单关键词（每行一个，可带逗号）'),
            _t('正文或昵称包含即触发处置。常见广告词示例：<code>代开发票</code>、<code>加微信</code>、<code>博彩</code>、<code>兼职日结</code>。注意：普通交流中偶尔出现的词可能误伤，请谨慎添加')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamMaxLinks',
            null,
            '3',
            _t('正文最大链接数'),
            _t('评论正文中 URL 数量超过该值即进入待审核；填 0 表示不限制链接数。默认 3')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamMinLen',
            null,
            '2',
            _t('最短内容长度（字符）'),
            _t('去除空白后评论长度低于该值进入待审核（防刷屏）；填 0 表示不限制。默认 2')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'spamHp',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('蜜罐字段检测（推荐开启）'),
            _t('评论表单内置一个人类不可见的隐藏字段，机器人自动填写即判定垃圾并静默丢弃，真人零影响')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'spamRateEnable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('评论全局限速（推荐开启）'),
            _t('按 IP 统计提交频率：60 秒内超过「每分钟上限」或 10 分钟内超过「每 10 分钟上限」即拒绝。原生评论通道与主题 AJAX 评论统一生效，可有效拦截绕过前端直接 POST 刷评论 / 注入测试')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamRateMin',
            null,
            '3',
            _t('每分钟上限（条）'),
            _t('同一 IP 在 60 秒内最多提交的评论数。默认 3，填 0 表示不限制该窗口')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamRateTen',
            null,
            '10',
            _t('每 10 分钟上限（条）'),
            _t('同一 IP 在 600 秒内最多提交的评论数。默认 10，填 0 表示不限制该窗口')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'spamIpList',
            null,
            '',
            _t('IP 黑名单（每行一个）'),
            _t('命中即直接拒绝。支持单个 IP（如 192.168.1.1），建议只添加确认的恶意来源')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'spamMailList',
            null,
            '',
            _t('邮箱域名黑名单（每行一个）'),
            _t('命中即直接拒绝。填域名即可（如 <code>mail.ru</code>），会自动匹配子域名；也可填完整邮箱')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'spamAction',
            array('pending' => _t('标记待审核（推荐，防误杀）'), 'reject' => _t('直接拒绝')), 
            'pending',
            _t('规则命中处置方式'),
            _t('「标记待审核」时，命中规则的评论进入后台评论管理的待审核列表，人工复核后可放行或删除；「直接拒绝」则拦截该评论。建议保持待审核')
        ));

        /* ---- 豆包 AI 语义过滤（可选层） ---- */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'spamAiEnable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '0',
            _t('豆包 AI 智能过滤'),
            _t('在规则引擎之上增加 AI 语义判断，能识别规则覆盖不到的变体垃圾（如拼音广告、语义模糊推广）。需填写下方 API Key 才生效；AI 调用失败或超时时自动放行，不影响正常评论')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password(
            'spamAiKey',
            null,
            '',
            _t('豆包 API Key（ARK_API_KEY）'),
            _t('在火山引擎方舟控制台获取：<code>ark.volcengine.com</code> → API Key 管理 → 创建 API Key。Key 仅保存在服务器设置中，用于服务端调用，不会暴露到前台')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamAiModel',
            null,
            'doubao-seed-2-1-pro-260628',
            _t('模型 ID'),
            _t('方舟已开通的模型 ID，如 doubao-seed-2-1-pro-260628。在方舟「开通管理」中查看你已开通的模型，填对应的 Model ID')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamAiEndpoint',
            null,
            'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
            _t('接口地址（可选）'),
            _t('默认使用火山方舟标准地址，一般无需修改')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'spamAiScope',
            array(
                'suspicious' => _t('仅可疑评论（规则放行后 AI 复核，省调用）'),
                'all'        => _t('全部评论（每条都过 AI，最严但产生调用费用）'),
            ),
            'suspicious',
            _t('AI 调用范围'),
            _t('「仅可疑」时规则引擎放行且未待审的评论才调用 AI；「全部」时每条评论都调用 AI。调用会产生 Token 费用，建议保持「仅可疑」')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'spamAiAction',
            array('pending' => _t('标记待审核（推荐）'), 'reject' => _t('直接拒绝')),
            'pending',
            _t('AI 判定处置方式'),
            _t('AI 判定为垃圾后的处理方式。建议待审核，人工复核避免误杀')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'spamAiTimeout',
            null,
            '15',
            _t('AI 请求超时（秒）'),
            _t('范围 2-30 秒，默认 15。长评论模型生成较慢，超时过短会导致 AI 判不了而转待审核；网络/超时失败会自动转待审核，不会直接放行')
        ));

        /* ================= AI 智能 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'aiSummaryEnable',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            '1',
            _t('AI 文章摘要'),
            _t('发布 / 更新文章时自动生成摘要（复用评论过滤的豆包 Key 与模型）。未填 Key 或关闭时自动跳过，前台继续使用原截断摘要')
        ));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'aiSummaryLen',
            null,
            '45',
            _t('AI 摘要字数'),
            _t('AI 生成的摘要长度，默认 45 字（与列表卡片显示一致，2 行内）。范围 30-300')
        ));

        /* ================= SEO 优化 ================= */
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'seoEnable',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            '1',
            _t('SEO 优化总开关'),
            _t('开启后输出以下全部 SEO 标签；关闭则保持 Typecho 原生 title / description / keywords 输出')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'seoSiteName',
            null,
            '',
            _t('站点名称'),
            _t('用于页面标题与结构化数据的站点名，留空自动使用「设置→基本→站点名称」')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'seoSiteDesc',
            null,
            '',
            _t('站点描述'),
            _t('用于 description 与 Open Graph 描述，留空自动使用「设置→基本→站点描述」。建议 60-120 字')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'seoSiteKeywords',
            null,
            '',
            _t('站点关键词'),
            _t('用于 keywords 标签，多个关键词用英文逗号分隔，留空自动使用「设置→基本→关键字」')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'seoSiteUrl',
            null,
            '',
            _t('站点地址'),
            _t('用于 canonical 与结构化数据，留空自动使用「设置→基本→站点地址」')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'seoCanonical',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            '1',
            _t('Canonical 规范链接'),
            _t('每个页面输出 <link rel="canonical">，声明权威地址，避免重复内容影响收录')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'seoOg',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            '1',
            _t('Open Graph 社交分享'),
            _t('输出 og:title / og:description / og:image / og:url / og:type 等，微信、QQ、Facebook 等平台分享卡片更美观')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'seoTwitter',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            '1',
            _t('Twitter Card 卡片'),
            _t('输出 twitter:card / twitter:title / twitter:description 等，Twitter / X 分享时展示摘要卡片')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Radio(
            'seoJsonLd',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭'),
            ),
            '1',
            _t('JSON-LD 结构化数据'),
            _t('按页面类型自动输出 Schema.org 标记：首页 WebSite+Organization、文章页 Article+BreadcrumbList、分类标签 CollectionPage、搜索页 SearchResultsPage，利于富摘要与 AI 搜索理解')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'seoBaiduVer',
            null,
            '',
            _t('百度站点验证'),
            _t('百度搜索资源平台提供的验证代码，如 H7xxx 之类，自动输出为 <meta name="baidu-site-verification">')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'seoGoogleVer',
            null,
            '',
            _t('Google 站点验证'),
            _t('Google Search Console 提供的验证代码（content 值），自动输出为 <meta name="google-site-verification">')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'seoBingVer',
            null,
            '',
            _t('Bing 站点验证'),
            _t('Bing Webmaster 提供的验证代码（content 值），自动输出为 <meta name="msvalidate.01">')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea(
            'seoHeaderHtml',
            null,
            '',
            _t('自定义 head 代码'),
            _t('插入到 </head> 前，可用于统计代码（百度统计 / 51LA / Umami）、站长验证、字体或其他第三方脚本')
        ));

                        
}
