<?php
/**
 * TriM3 豆瓣数据层：抓取 + 本地缓存 + 解析（参考 wp-douban 能力增强）
 *
 * 默认数据源：豆瓣移动端 rexxar 接口（m.douban.com，无需 API Key，需伪装 UA）
 * 备用数据源：后台「豆瓣 API 地址」填写自建服务（如 douban-api-rs / douban-api），
 *             约定路径：{API地址}/{uid}/{type}/{count}，返回 rexxar 兼容 JSON
 * type：book / movie / music
 *
 * 封面本地缓存：后台开启后下载到 cache/douban-img/，避免豆瓣图床防盗链失效
 */

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

/**
 * 读取某类型豆瓣标记（带本地 JSON 缓存）
 * @return array ['ok'=>bool, 'items'=>[...], 'msg'=>?]
 */
/* 延迟刷新：若 widgets 未加载则本地兜底（不联网，什么都不做）—— 与 lib/bangumi.php 一致 */
if (!function_exists('tri_widgets_defer')) { function tri_widgets_defer($triKey, $triFn) { } }

function tri_douban_get(string $triType): array
{
    $triUid  = trim((string) tri_opt('doubanUid', ''));
    if ($triUid === '') {
        return array('ok' => false, 'msg' => '未设置豆瓣用户 ID');
    }
    $triCount = max(1, min(50, (int) tri_opt('doubanCount', '12')));
    $triRedisKey = 'douban-' . md5($triUid . '|' . $triType . '|' . $triCount);
    $triRedisData = tri_redis_get($triRedisKey, 'remote');
    if (is_array($triRedisData)) { return $triRedisData; }

    $triCacheDir = __DIR__ . '/../cache/douban';
    if (!is_dir($triCacheDir)) { @mkdir($triCacheDir, 0755, true); }

    $triKey  = md5($triUid . '|' . $triType . '|' . $triCount);
    $triFile = $triCacheDir . '/' . $triKey . '.json';
    $triMaxAge = max(1, (int) tri_opt('doubanCache', '24')) * 3600;

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

    /* 渲染路径绝不联网：有旧缓存先用旧缓存，刷新交给延迟队列
       （与 lib/bangumi.php v1.2.24 同一策略；此前是同步等豆瓣接口，单次约 150ms，
         且某个类型取数失败时不写缓存 → 每次请求都要重拉一次） */
    $triStale = null;
    if (is_file($triFile)) {
        $triDec = json_decode((string) @file_get_contents($triFile), true);
        if (is_array($triDec)) {
            $triStale = $triDec;
            tri_redis_set($triRedisKey, $triDec, 'remote', $triMaxAge);
        }
    }
    /* 失败/空结果短退避：该类型若一直取不到数（例如用户根本没有这类标记），
       不应每次请求都排队重拉一次。退避标记单独存一个 .neg 文件，绝不覆盖已有的好数据。 */
    $triNegFile = $triFile . '.neg';
    $triNegAge  = 1800;
    if (!is_file($triNegFile) || (time() - filemtime($triNegFile)) >= $triNegAge) {
        tri_widgets_defer('douban:' . $triKey, function () use ($triUid, $triType, $triCount, $triFile, $triNegFile, $triRedisKey, $triMaxAge) {
            $triData = tri_douban_remote($triUid, $triType, $triCount);
            if (is_array($triData) && !empty($triData['ok'])) {
                $triJson = json_encode($triData, JSON_UNESCAPED_UNICODE);
                if (function_exists('tri3_atomic_write')) {
                    tri3_atomic_write($triFile, $triJson);
                } else {
                    @file_put_contents($triFile, $triJson);
                }
                tri_redis_set($triRedisKey, $triData, 'remote', $triMaxAge);
                @unlink($triNegFile);
            } else {
                @file_put_contents($triNegFile, (string) time());
            }
        });
    }
    if (is_array($triStale)) { return $triStale; }
    return array('ok' => false, 'items' => array(), 'msg' => '首屏数据准备中');
}

/**
 * 抓取远程数据并解析为统一结构
 */
function tri_douban_remote(string $triUid, string $triType, int $triCount): array
{
    $triApi = trim((string) tri_opt('doubanApi', ''));
    if ($triApi !== '') {
        $triUrl = rtrim($triApi, '/') . '/' . rawurlencode($triUid) . '/' . rawurlencode($triType) . '/' . $triCount;
    } else {
        $triUrl = 'https://m.douban.com/rexxar/api/v2/user/' . rawurlencode($triUid)
                . '/interests?type=' . rawurlencode($triType) . '&max=' . $triCount;
    }

    $triCtx = stream_context_create(array(
        'http' => array(
            'timeout' => 15,
            'ignore_errors' => true,
            'header' =>
                "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1\r\n"
                . "Referer: https://m.douban.com/\r\n"
                . "Accept: application/json, text/plain, */*\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));

    $triBody = @file_get_contents($triUrl, false, $triCtx);
    if ($triBody === false || trim($triBody) === '') {
        return array('ok' => false, 'msg' => '豆瓣接口连接失败');
    }
    $triJson = json_decode($triBody, true);
    if (!is_array($triJson)) {
        return array('ok' => false, 'msg' => '接口返回非 JSON');
    }

    $triList = array();
    foreach (array('interests', 'items', 'collections') as $triK) {
        if (isset($triJson[$triK]) && is_array($triJson[$triK])) { $triList = $triJson[$triK]; break; }
    }
    if (empty($triList)) {
        return array('ok' => false, 'msg' => '未获取到标记数据');
    }

    $triItems = array();
    foreach ($triList as $triIt) {
        if (!is_array($triIt)) { continue; }
        $triSub = isset($triIt['subject']) && is_array($triIt['subject']) ? $triIt['subject'] : $triIt;

        /* 作者 / 导演 */
        $triAuthor = '';
        if (isset($triSub['author'])) {
            $triA = $triSub['author'];
            if (is_array($triA)) {
                $triNames = array();
                foreach ($triA as $triAa) {
                    $triNames[] = is_array($triAa) ? (string) ($triAa['name'] ?? '') : (string) $triAa;
                }
                $triAuthor = implode(' / ', array_filter($triNames));
            } else {
                $triAuthor = (string) $triA;
            }
        }

        /* 类型（genres） */
        $triGenres = array();
        if (isset($triSub['genres']) && is_array($triSub['genres'])) {
            foreach ($triSub['genres'] as $triG) {
                $triGenres[] = is_array($triG) ? (string) ($triG['name'] ?? '') : (string) $triG;
            }
        }
        $triGenres = array_values(array_filter(array_unique($triGenres)));

        /* 年份：pubdate 在部分接口中为数组（如「2023-05」分段），先规整为字符串，避免 Array to string conversion */
        $triPub = $triSub['pubdate'] ?? '';
        if (is_array($triPub)) {
            $triPub = implode(' / ', array_filter(array_map('strval', $triPub)));
        }

        $triItem = array(
            'id'       => (string) ($triSub['id'] ?? ''),
            'title'    => (string) ($triSub['title'] ?? ''),
            'original_title' => (string) ($triSub['original_title'] ?? ''),
            'cover'    => tri_douban_pick_cover($triSub),
            'rating'   => (float) ($triSub['rating']['value'] ?? 0),
            'rating_count' => (int) ($triSub['rating']['count'] ?? 0),
            'status'   => (string) ($triIt['status'] ?? ''),
            'comment'  => (string) ($triIt['comment'] ?? ''),
            'card_subtitle' => (string) ($triSub['card_subtitle'] ?? ''),
            'date'     => (string) substr((string) ($triIt['create_time'] ?? ''), 0, 10),
            'url'      => (string) ($triSub['url'] ?? ($triSub['share_url'] ?? '')),
            'author'   => $triAuthor,
            'year'     => (string) ($triSub['year'] ?? $triPub),
            'genres'   => $triGenres,
        );
        if ($triItem['title'] === '') { continue; }

        /* 封面本地缓存 */
        if (tri_switch('doubanCacheCover', '1') && $triItem['cover'] !== '') {
            $triLocal = tri_douban_cover_cache($triItem['cover'], $triType . '_' . $triItem['id']);
            if ($triLocal !== '') { $triItem['cover'] = $triLocal; }
        }
        $triItems[] = $triItem;
    }
    if (empty($triItems)) {
        return array('ok' => false, 'msg' => '解析不到条目');
    }
    return array('ok' => true, 'items' => $triItems);
}

/**
 * 封面字段容错：pic 可能是字符串 URL，也可能是数组（large/normal/raw）
 */
function tri_douban_pick_cover(array $triSub): string
{
    if (isset($triSub['pic'])) {
        $triPic = $triSub['pic'];
        if (is_array($triPic)) {
            foreach (array('large', 'normal', 'raw', 'default') as $triK) {
                if (isset($triPic[$triK]) && is_string($triPic[$triK]) && $triPic[$triK] !== '') {
                    return (string) $triPic[$triK];
                }
            }
        } elseif (is_string($triPic) && $triPic !== '') {
            return $triPic;
        }
    }
    foreach (array('cover_url', 'image', 'img') as $triK) {
        if (isset($triSub[$triK]) && is_string($triSub[$triK]) && $triSub[$triK] !== '') {
            return (string) $triSub[$triK];
        }
    }
    return '';
}

/**
 * 封面本地缓存：下载到 cache/douban-img/，返回本地 URL（失败返回原 URL）
 */
function tri_douban_cover_cache(string $triCover, string $triKey): string
{
    $triDir = __DIR__ . '/../cache/douban-img';
    if (!is_dir($triDir)) { @mkdir($triDir, 0755, true); }
    $triFile = $triDir . '/' . md5($triKey) . '.jpg';
    if (is_file($triFile) && filesize($triFile) > 200) {
        return rtrim((string) Helper::options()->siteUrl, '/') . '/usr/themes/' . (string) Helper::options()->theme . '/cache/douban-img/' . md5($triKey) . '.jpg';
    }
    $triCtx = stream_context_create(array(
        'http' => array(
            'timeout' => 12,
            'ignore_errors' => true,
            'header' =>
                "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1\r\n"
                . "Referer: https://m.douban.com/\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $triImg = @file_get_contents($triCover, false, $triCtx);
    if ($triImg !== false && strlen($triImg) > 500) {
        /* v1.2.76：落盘前限宽 400 + JPEG 重编码 */
        if (function_exists('tri3_img_web_thumb')) { tri3_img_web_thumb($triImg, $triFile, 400, 86); }
        else { @file_put_contents($triFile, $triImg); }
        return rtrim((string) Helper::options()->siteUrl, '/') . '/usr/themes/' . (string) Helper::options()->theme . '/cache/douban-img/' . md5($triKey) . '.jpg';
    }
    return $triCover;
}

/**
 * 状态码 -> 中文标签（兼容各类型）
 */
function tri_douban_status_text(string $triStatus): string
{
    switch ($triStatus) {
        case 'wish':    return '想读 / 想看 / 想听';
        case 'reading': case 'doing': return '在读 / 在看 / 在听';
        case 'read':    case 'done':  return '读过 / 看过 / 听过';
        case 'mark':    return '标记';
        default:        return $triStatus !== '' ? $triStatus : '标记';
    }
}

/**
 * 状态码 -> 前端 data-status（统一为 wish / doing / done）
 */
function tri_douban_status_key(string $triStatus): string
{
    switch ($triStatus) {
        case 'wish':    return 'wish';
        case 'reading': case 'doing': return 'doing';
        case 'read':    case 'done':  return 'done';
        default:        return 'done';
    }
}
