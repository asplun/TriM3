<?php
/**
 * TriM3 B 站番剧数据层：抓取追番列表 + 本地缓存 + 封面缓存
 *
 * 数据源：api.bilibili.com/x/space/bangumi/follow/list（公开接口，无需登录）
 * 参数：type=1 番剧 / type=2 追剧；follow_status=0 全部（1 想看 / 2 在看 / 3 看过）
 *
 * 封面本地缓存：后台开启后下载到 cache/bangumi-img/，避免 B 站图床防盗链失效
 */

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

/**
 * 读取追番列表（带本地 JSON 缓存）
 * @return array ['ok'=>bool, 'total'=>int, 'fetched'=>int, 'items'=>[...], 'msg'=>?]
 */
/* 延迟刷新：若 widgets 未加载则本地兜底（不联网，什么都不做） */
if (!function_exists('tri_widgets_defer')) { function tri_widgets_defer($triKey, $triFn) { } }

function tri_bangumi_get(): array
{
    $triUid = trim((string) tri_opt('biliUid', ''));
    if ($triUid === '') {
        return array('ok' => false, 'msg' => '未设置 B 站 UID');
    }
    $triType = tri_opt('biliType', '1') === '2' ? '2' : '1';
    $triCount = max(20, min(500, (int) tri_opt('biliCount', '100')));
    $triRedisKey = 'bangumi-' . md5($triUid . '|' . $triType . '|' . $triCount);
    $triRedisData = tri_redis_get($triRedisKey, 'remote');
    if (is_array($triRedisData)) { return $triRedisData; }

    $triCacheDir = __DIR__ . '/../cache/bangumi';
    if (!is_dir($triCacheDir)) { @mkdir($triCacheDir, 0755, true); }

    $triKey  = md5($triUid . '|' . $triType);
    $triFile = $triCacheDir . '/' . $triKey . '.json';
    $triMaxAge = max(1, (int) tri_opt('biliCache', '24')) * 3600;

    /* 命中缓存 */
    if (is_file($triFile) && (time() - filemtime($triFile)) < $triMaxAge) {
        $triCached = @file_get_contents($triFile);
        if ($triCached !== false) {
            $triDec = json_decode($triCached, true);
            if (is_array($triDec)) {
                tri_redis_set($triRedisKey, $triDec, 'remote', $triMaxAge);
                return $triDec;
            }
        }
    }

    /* v1.2.24：渲染路径绝不联网 —— 有旧缓存就先用，刷新交给延迟队列 */
    $triStale = null;
    if (is_file($triFile)) {
        $triDec = json_decode((string) @file_get_contents($triFile), true);
        if (is_array($triDec)) {
            $triStale = $triDec;
            tri_redis_set($triRedisKey, $triDec, 'remote', $triMaxAge);
        }
    }
    tri_widgets_defer('bangumi:' . $triKey, function () use ($triUid, $triType, $triFile, $triRedisKey, $triMaxAge) {
        $triD = tri_bangumi_fetch($triUid, $triType);
        if (is_array($triD) && !empty($triD['ok'])) {
            $triJson = json_encode($triD, JSON_UNESCAPED_UNICODE);
            if (function_exists('tri3_atomic_write')) {
                tri3_atomic_write($triFile, $triJson);
            } else {
                @file_put_contents($triFile, $triJson);
            }
            tri_redis_set($triRedisKey, $triD, 'remote', $triMaxAge);
        }
    });
    if (is_array($triStale)) { return $triStale; }
    return array('ok' => false, 'total' => 0, 'fetched' => 0, 'items' => array(), 'msg' => '首屏数据准备中');
}

/**
 * 分页抓取 B 站追番列表并规范化为统一结构
 */
function tri_bangumi_fetch(string $triUid, string $triType): array
{
    $triCount = max(20, min(500, (int) tri_opt('biliCount', '100')));
    $triPs = 30;
    $triPages = (int) ceil($triCount / $triPs);

    $triItems = array();
    $triTotal = 0;
    for ($triPn = 1; $triPn <= $triPages; $triPn++) {
        $triApi = 'https://api.bilibili.com/x/space/bangumi/follow/list'
                . '?type=' . $triType
                . '&follow_status=0'
                . '&pn=' . $triPn
                . '&ps=' . $triPs
                . '&vmid=' . rawurlencode($triUid);
        $triBody = tri_bangumi_http($triApi);
        if ($triBody === '') { break; }
        $triJson = json_decode($triBody, true);
        if (!is_array($triJson) || !isset($triJson['code']) || $triJson['code'] !== 0) { break; }

        $triData = isset($triJson['data']) && is_array($triJson['data']) ? $triJson['data'] : array();
        if ($triPn === 1 && isset($triData['total'])) { $triTotal = (int) $triData['total']; }
        $triList = isset($triData['list']) && is_array($triData['list']) ? $triData['list'] : array();

        foreach ($triList as $triIt) {
            if (!is_array($triIt)) { continue; }
            $triN = tri_bangumi_normalize($triIt);
            if ($triN['title'] !== '') { $triItems[] = $triN; }
        }
        if (count($triList) < $triPs) { break; }
        usleep(250000); /* 防限流 */
    }

    if (empty($triItems)) {
        return array('ok' => false, 'msg' => '未获取到追番数据（请检查 UID 及追番是否公开）');
    }
    return array('ok' => true, 'total' => $triTotal, 'fetched' => count($triItems), 'items' => $triItems, 'ts' => time());
}

/**
 * 单条番剧字段规范化
 */
function tri_bangumi_normalize(array $triIt): array
{
    $triItem = array(
        'id'          => (string) ($triIt['season_id'] ?? ''),
        'media_id'    => (string) ($triIt['media_id'] ?? ''),
        'title'       => (string) ($triIt['title'] ?? ''),
        'cover'       => (string) ($triIt['cover'] ?? ''),
        'square_cover'=> (string) ($triIt['square_cover'] ?? ''),
        'wide_cover'  => (string) ($triIt['horizontal_cover_16_9'] ?? ($triIt['horizontal_cover_16_10'] ?? '')),
        'evaluate'    => trim((string) ($triIt['evaluate'] ?? '')),
        'status'      => (string) ($triIt['follow_status'] ?? '2'),
        'is_finish'   => (int) ($triIt['is_finish'] ?? 0),
        'total'       => (int) ($triIt['total_count'] ?? 0),
        'new_ep'      => (string) ($triIt['new_ep']['index_show'] ?? ''),
        'badge'       => (string) ($triIt['badge'] ?? ''),
        'score'       => 0.0,
        'score_count' => 0,
        'styles'      => array(),
        'year'        => '',
        'view'        => (int) ($triIt['stat']['view'] ?? 0),
        'url'         => (string) ($triIt['url'] ?? ('https://www.bilibili.com/bangumi/play/ss' . ($triIt['season_id'] ?? ''))),
    );

    $triRating = isset($triIt['rating']) && is_array($triIt['rating']) ? $triIt['rating'] : array();
    if (!empty($triRating['score'])) {
        $triItem['score'] = (float) $triRating['score'];
        $triItem['score_count'] = (int) ($triRating['count'] ?? 0);
    }

    if (isset($triIt['styles']) && is_array($triIt['styles'])) {
        foreach ($triIt['styles'] as $triS) {
            $triS = trim((string) $triS);
            if ($triS !== '') { $triItem['styles'][] = $triS; }
        }
    }
    $triItem['styles'] = array_values(array_unique($triItem['styles']));

    if (isset($triIt['publish']['release_date']) && is_string($triIt['publish']['release_date'])) {
        $triY = (int) substr($triIt['publish']['release_date'], 0, 4);
        if ($triY >= 1980 && $triY <= 2100) { $triItem['year'] = (string) $triY; }
    }

    /* 封面本地缓存 */
    if (tri_switch('biliCacheCover', '1') && $triItem['cover'] !== '') {
        $triLocal = tri_bangumi_cover_cache($triItem['cover'], $triItem['id']);
        if ($triLocal !== '') { $triItem['cover'] = $triLocal; }
    }
    return $triItem;
}

/**
 * 封面本地缓存：下载到 cache/bangumi-img/，返回本地 URL（失败返回原 URL）
 */
function tri_bangumi_cover_cache(string $triCover, string $triKey): string
{
    $triDir = __DIR__ . '/../cache/bangumi-img';
    if (!is_dir($triDir)) { @mkdir($triDir, 0755, true); }
    $triFile = $triDir . '/' . md5($triKey) . '.jpg';
    if (is_file($triFile) && filesize($triFile) > 200) {
        return rtrim((string) Helper::options()->siteUrl, '/') . '/usr/themes/' . (string) Helper::options()->theme . '/cache/bangumi-img/' . md5($triKey) . '.jpg';
    }
    /* v1.2.24：先返回远端地址，本地副本放到响应之后下载 */
    tri_widgets_defer('bamgimg:' . md5($triKey), function () use ($triCover, $triKey, $triFile) {
        if (is_file($triFile) && filesize($triFile) > 200) { return; }
        $triImg = tri_bangumi_http($triCover, 'image');
        /* v1.2.76：落盘前限宽 400 + JPEG 重编码（上游多为 960x1280 PNG，2MB 级） */
        if ($triImg !== '' && strlen($triImg) > 500) {
            if (function_exists('tri3_img_web_thumb')) { tri3_img_web_thumb($triImg, $triFile, 400, 86); }
            else { @file_put_contents($triFile, $triImg); }
        }
    });
    return $triCover;
}

/**
 * HTTP GET（json / image 两种用途，B 站需伪装 UA + Referer）
 */
function tri_bangumi_http(string $triUrl, string $triKind = 'json'): string
{
    $triReferer = $triKind === 'image'
        ? 'https://www.bilibili.com/'
        : 'https://space.bilibili.com/';
    $triCtx = stream_context_create(array(
        'http' => array(
            'timeout' => 4,   /* 番剧同步不得拖慢首字节 */
            'ignore_errors' => true,
            'header' =>
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
                . "Referer: " . $triReferer . "\r\n"
                . "Accept: application/json, text/plain, */*\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $triBody = @file_get_contents($triUrl, false, $triCtx);
    return $triBody === false ? '' : $triBody;
}

/**
 * 追番状态码 -> 中文标签
 */
function tri_bangumi_status_text(string $triStatus): string
{
    switch ($triStatus) {
        case '1': return '想看';
        case '2': return '在看';
        case '3': return '看过';
        default:  return '在看';
    }
}
