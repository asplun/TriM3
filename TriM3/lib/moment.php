<?php
/**
 * TriM3 说说数据层
 * 数据表结构参考 pix 主题「片刻」：
 *   - contents 表新增 type='moment' 记录（正文、时间、作者、状态）
 *   - fields 表存扩展字段：moment_ga(图集JSON) / moment_like(点赞) / moment_sticky(置顶) / moment_cat(分类)
 */

use Typecho\Db;
use Typecho\Widget;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 新增一条说说
 * 扩展字段（JSON 字符串）：
 *   $music 音乐卡片 {server,id,title,artist,pic}
 *   $video 视频     {src,poster}
 *   $live  实况图   {img,video}
 *   $bili  B站视频  {bvid}
 */
function tri_moment_insert(string $content, array $images = [], string $cat = '', bool $private = false, ?array $music = null, ?array $video = null, ?array $live = null, ?array $bili = null): int
{
    $db = Db::get();
    $user = Widget::widget('Widget_User');
    $now = time();
    $slug = 'moment-' . $now . '-' . substr(md5(uniqid((string) mt_rand(), true)), 0, 8);

    $cid = (int) $db->query($db->insert('table.contents')->rows([
        'title'        => '',
        'slug'         => $slug,
        'created'      => $now,
        'modified'     => $now,
        'text'         => (string) $content,
        'order'        => 0,
        'authorId'     => (int) ($user->uid ?? 0),
        'template'     => null,
        'type'         => 'moment',
        'status'       => $private ? 'private' : 'publish',
        'password'     => null,
        'commentsNum'  => 0,
        'allowComment' => '1',
        'allowPing'    => '0',
        'allowFeed'    => '1',
        'parent'       => 0,
    ]));

    if ($cid > 0) {
        tri_moment_set_field($cid, 'moment_ga', $images ? json_encode(array_values($images), JSON_UNESCAPED_UNICODE) : '');
        if ($cat !== '') {
            tri_moment_set_field($cid, 'moment_cat', $cat);
        }
        if ($music && !empty($music['id'])) {
            tri_moment_set_field($cid, 'moment_music', json_encode($music, JSON_UNESCAPED_UNICODE));
        }
        if ($video && !empty($video['src'])) {
            tri_moment_set_field($cid, 'moment_video', json_encode($video, JSON_UNESCAPED_UNICODE));
        }
        if ($live && (!empty($live['img']) || !empty($live['video']))) {
            tri_moment_set_field($cid, 'moment_live', json_encode($live, JSON_UNESCAPED_UNICODE));
        }
        if ($bili && !empty($bili['bvid'])) {
            tri_moment_set_field($cid, 'moment_bili', json_encode($bili, JSON_UNESCAPED_UNICODE));
        }
    }

    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment inserted');

    return $cid;
}

/**
 * 写入自定义字段（先删后插）
 */
function tri_moment_set_field(int $cid, string $name, $value): void
{
    $db = Db::get();
    $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, $name));
    $db->query($db->insert('table.fields')->rows([
        'cid'         => $cid,
        'name'        => $name,
        'type'        => 'str',
        'str_value'   => (string) $value,
        'int_value'   => 0,
        'float_value' => 0,
    ]));
}

/**
 * 点赞 +1，返回最新数量
 */
function tri_moment_like(int $cid): int
{
    $db = Db::get();
    $row = $db->fetchRow($db->select()->from('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_like'));
    $n = $row ? ((int) $row['int_value']) + 1 : 1;
    if ($row) {
        $db->query($db->update('table.fields')->rows(['int_value' => $n])->where('cid = ? AND name = ?', $cid, 'moment_like'));
    } else {
        $db->query($db->insert('table.fields')->rows([
            'cid'         => $cid,
            'name'        => 'moment_like',
            'type'        => 'str',
            'str_value'   => '',
            'int_value'   => $n,
            'float_value' => 0,
        ]));
    }
    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment liked');
    return $n;
}

/**
 * 置顶开关
 */
function tri_moment_toggle_sticky(int $cid): void
{
    $db = Db::get();
    $row = $db->fetchRow($db->select()->from('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_sticky'));
    if ($row) {
        $db->query($db->update('table.fields')->rows(['str_value' => $row['str_value'] === '1' ? '0' : '1'])
            ->where('cid = ? AND name = ?', $cid, 'moment_sticky'));
    } else {
        $db->query($db->insert('table.fields')->rows([
            'cid'         => $cid,
            'name'        => 'moment_sticky',
            'type'        => 'str',
            'str_value'   => '1',
            'int_value'   => 0,
            'float_value' => 0,
        ]));
    }
    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment sticky changed');
}

/**
 * 分页获取说说列表（置顶优先）
 */
function tri_moment_list(int $page = 1, int $pageSize = 10, bool $includePrivate = false, string $filter = 'all', string $cat = ''): array
{
    $key = implode('|', [$page, $pageSize, $includePrivate ? 1 : 0, $filter, $cat]);
    return tri_redis_remember($key, 'moments', function () use ($page, $pageSize, $includePrivate, $filter, $cat) {
        return tri_moment_list_uncached($page, $pageSize, $includePrivate, $filter, $cat);
    }, 300);
}

function tri_moment_list_uncached(int $page = 1, int $pageSize = 10, bool $includePrivate = false, string $filter = 'all', string $cat = ''): array
{
    $db = Db::get();
    $select = $db->select()->from('table.contents')
        ->where('type = ?', 'moment');
    if ($includePrivate) {
        $select->where('status IN ("publish", "private")');
    } else {
        $select->where('status = ?', 'publish');
    }
    if ($filter === 'sticky') {
        $stickyCids = array_column($db->fetchAll(
            $db->select('cid')->from('table.fields')->where('name = ? AND str_value = ?', 'moment_sticky', '1')
        ), 'cid');
        if (!$stickyCids) {
            return [];
        }
        $select->where('cid IN ?', $stickyCids);
    } elseif ($filter === 'private') {
        $select->where('status = ?', 'private');
    }
    if ($cat !== '') {
        $catCids = array_column($db->fetchAll(
            $db->select('cid')->from('table.fields')->where('name = ? AND str_value = ?', 'moment_cat', $cat)
        ), 'cid');
        if (!$catCids) {
            return [];
        }
        $select->where('cid IN ?', $catCids);
    }
    $select->order('table.contents.created', Db::SORT_DESC)->page($page, $pageSize);
    $rows = $db->fetchAll($select);

    $cids = array_column($rows, 'cid');
    $map = [];
    if ($cids) {
        $fs = $db->fetchAll($db->select()->from('table.fields')->where('cid IN ?', $cids));
        foreach ($fs as $f) {
            $map[$f['cid']][$f['name']] = $f;
        }
    }
    foreach ($rows as &$r) {
        $r['ga'] = isset($map[$r['cid']]['moment_ga']) ? (array) json_decode($map[$r['cid']]['moment_ga']['str_value'], true) : [];
        $r['like'] = isset($map[$r['cid']]['moment_like']) ? (int) $map[$r['cid']]['moment_like']['int_value'] : 0;
        $r['sticky'] = isset($map[$r['cid']]['moment_sticky']) && $map[$r['cid']]['moment_sticky']['str_value'] === '1';
        $r['cat'] = isset($map[$r['cid']]['moment_cat']) ? $map[$r['cid']]['moment_cat']['str_value'] : '';
        $r['isPrivate'] = $r['status'] === 'private';
        $r['music'] = isset($map[$r['cid']]['moment_music']) ? (array) json_decode($map[$r['cid']]['moment_music']['str_value'], true) : null;
        $r['video'] = isset($map[$r['cid']]['moment_video']) ? (array) json_decode($map[$r['cid']]['moment_video']['str_value'], true) : null;
        $r['live'] = isset($map[$r['cid']]['moment_live']) ? (array) json_decode($map[$r['cid']]['moment_live']['str_value'], true) : null;
        $r['bili'] = isset($map[$r['cid']]['moment_bili']) ? (array) json_decode($map[$r['cid']]['moment_bili']['str_value'], true) : null;
        $r['cmt'] = 0;
        /* 图片加载优化：说说 [img] 短代码与 <img> 内图走代理（缩放+WebP+懒加载） */
        $triMomentText = isset($r['text']) ? (string) $r['text'] : '';
        if (function_exists('tri3_img_proxy_url')) {
            $triMomentText = (string) preg_replace_callback('/\[img\]([^\[\]]+)\[\/img\]/i', function ($m) {
                return '[img]' . tri3_img_proxy_url(trim($m[1]), 800, 'auto') . '[/img]';
            }, $triMomentText);
        }
        if (function_exists('tri3_img_proxy_html')) {
            $triMomentText = tri3_img_proxy_html($triMomentText);
        }
        $r['text'] = $triMomentText;
    }
    /* 批量统计每条说说的评论数（approved） */
    if ($cids) {
        $cmtRows = $db->fetchAll($db->select('cid', array('COUNT(*)' => 'num'))->from('table.comments')
            ->where('cid IN ?', $cids)
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved')
            ->group('cid'));
        $cmtMap = array();
        foreach ($cmtRows as $cr) { $cmtMap[(int) $cr['cid']] = (int) $cr['num']; }
        foreach ($rows as &$r2) { $r2['cmt'] = isset($cmtMap[(int) $r2['cid']]) ? $cmtMap[(int) $r2['cid']] : 0; }
        unset($r2);
    }
    usort($rows, function ($a, $b) {
        if ($a['sticky'] === $b['sticky']) {
            return 0;
        }
        return $a['sticky'] ? -1 : 1;
    });

    return $rows;
}

/**
 * 说说总数
 */
function tri_moment_count(bool $includePrivate = false, string $filter = 'all', string $cat = ''): int
{
    $key = implode('|', [$includePrivate ? 1 : 0, $filter, $cat]);
    return tri_redis_remember($key, 'moments', function () use ($includePrivate, $filter, $cat) {
        return tri_moment_count_uncached($includePrivate, $filter, $cat);
    }, 300);
}

function tri_moment_count_uncached(bool $includePrivate = false, string $filter = 'all', string $cat = ''): int
{
    $db = Db::get();
    $select = $db->select(['COUNT(*)' => 'num'])->from('table.contents')
        ->where('type = ?', 'moment');
    if ($includePrivate) {
        $select->where('status IN ("publish", "private")');
    } else {
        $select->where('status = ?', 'publish');
    }
    if ($filter === 'sticky') {
        $stickyCids = array_column($db->fetchAll(
            $db->select('cid')->from('table.fields')->where('name = ? AND str_value = ?', 'moment_sticky', '1')
        ), 'cid');
        if (!$stickyCids) {
            return 0;
        }
        $select->where('cid IN ?', $stickyCids);
    } elseif ($filter === 'private') {
        $select->where('status = ?', 'private');
    }
    if ($cat !== '') {
        $catCids = array_column($db->fetchAll(
            $db->select('cid')->from('table.fields')->where('name = ? AND str_value = ?', 'moment_cat', $cat)
        ), 'cid');
        if (!$catCids) {
            return 0;
        }
        $select->where('cid IN ?', $catCids);
    }
    $row = $db->fetchRow($select);
    return (int) $row['num'];
}

/**
 * 获取单条说说详情（含媒体字段）
 */
function tri_moment_get(int $cid): ?array
{
    $db = Db::get();
    $row = $db->fetchRow($db->select()->from('table.contents')
        ->where('cid = ? AND type = ?', $cid, 'moment'));
    if (!$row) {
        return null;
    }
    $fs = $db->fetchAll($db->select()->from('table.fields')->where('cid = ?', $cid));
    $map = [];
    foreach ($fs as $f) {
        $map[$f['name']] = $f;
    }
    $row['ga'] = isset($map['moment_ga']) ? (array) json_decode($map['moment_ga']['str_value'], true) : [];
    $row['like'] = isset($map['moment_like']) ? (int) $map['moment_like']['int_value'] : 0;
    $row['sticky'] = isset($map['moment_sticky']) && $map['moment_sticky']['str_value'] === '1';
    $row['cat'] = isset($map['moment_cat']) ? $map['moment_cat']['str_value'] : '';
    $row['isPrivate'] = $row['status'] === 'private';
    $row['music'] = isset($map['moment_music']) ? (array) json_decode($map['moment_music']['str_value'], true) : null;
    $row['video'] = isset($map['moment_video']) ? (array) json_decode($map['moment_video']['str_value'], true) : null;
    $row['live'] = isset($map['moment_live']) ? (array) json_decode($map['moment_live']['str_value'], true) : null;
    $row['bili'] = isset($map['moment_bili']) ? (array) json_decode($map['moment_bili']['str_value'], true) : null;
    return $row;
}

/**
 * 更新说说（文字 / 图片 / 私密 / 音乐 / 视频 / 实况 / B站）
 */
function tri_moment_update(int $cid, string $content, array $images = [], bool $private = false, ?array $music = null, ?array $video = null, ?array $live = null, string $cat = '', ?array $bili = null): bool
{
    $db = Db::get();
    $row = $db->fetchRow($db->select()->from('table.contents')
        ->where('cid = ? AND type = ?', $cid, 'moment'));
    if (!$row) {
        return false;
    }
    $db->query($db->update('table.contents')->rows([
        'text'     => (string) $content,
        'status'   => $private ? 'private' : 'publish',
        'modified' => time(),
    ])->where('cid = ?', $cid));

    tri_moment_set_field($cid, 'moment_ga', $images ? json_encode(array_values($images), JSON_UNESCAPED_UNICODE) : '');
    if ($cat !== '') {
        tri_moment_set_field($cid, 'moment_cat', $cat);
    } else {
        $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_cat'));
    }
    if ($music && !empty($music['id'])) {
        tri_moment_set_field($cid, 'moment_music', json_encode($music, JSON_UNESCAPED_UNICODE));
    } else {
        $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_music'));
    }
    if ($video && !empty($video['src'])) {
        tri_moment_set_field($cid, 'moment_video', json_encode($video, JSON_UNESCAPED_UNICODE));
    } else {
        $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_video'));
    }
    if ($live && (!empty($live['img']) || !empty($live['video']))) {
        tri_moment_set_field($cid, 'moment_live', json_encode($live, JSON_UNESCAPED_UNICODE));
    } else {
        $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_live'));
    }
    if ($bili && !empty($bili['bvid'])) {
        tri_moment_set_field($cid, 'moment_bili', json_encode($bili, JSON_UNESCAPED_UNICODE));
    } else {
        $db->query($db->delete('table.fields')->where('cid = ? AND name = ?', $cid, 'moment_bili'));
    }
    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment updated');
    return true;
}

/**
 * 删除说说（连带字段）
 */
function tri_moment_delete(int $cid): void
{
    $db = Db::get();
    $db->query($db->delete('table.fields')->where('cid = ?', $cid));
    $db->query($db->delete('table.contents')->where('cid = ? AND type = ?', $cid, 'moment'));
    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment deleted');
}

/**
 * 说说分类（独立于文章分类，存 metas 表 type='moment_cat'）
 * 返回：mid/name/slug/count(该分类下的说说数)
 */
function tri_moment_cats(): array
{
    return tri_redis_remember('all', 'moments', function () {
        return tri_moment_cats_uncached();
    }, 300);
}

function tri_moment_cats_uncached(): array
{
    $db = Db::get();
    $rows = $db->fetchAll($db->select()->from('table.metas')
        ->where('type = ?', 'moment_cat')->order('table.metas.order', Db::SORT_ASC));
    /* 统计每个 slug 下的说说数 */
    $cnt = [];
    try {
        $cntRows = $db->fetchAll($db->select('str_value', 'COUNT(*) AS n')->from('table.fields')
            ->where('name = ?', 'moment_cat')->group('str_value'));
        foreach ($cntRows as $cr) {
            $cnt[(string) ($cr['str_value'] ?? '')] = (int) $cr['n'];
        }
    } catch (Throwable $e) {
        $cnt = [];
    }
    $list = [];
    foreach ($rows as $r) {
        $slug = (string) ($r['slug'] ?? '');
        $list[] = [
            'mid'   => (int) $r['mid'],
            'name'  => (string) ($r['name'] ?? ''),
            'slug'  => $slug,
            'count' => $cnt[$slug] ?? 0,
        ];
    }
    return $list;
}

/**
 * 新增 / 编辑说说分类。$mid=0 时新建；返回 mid。
 */
function tri_moment_cat_save(int $mid, string $name, string $slug, int $order = 0): int
{
    $db = Db::get();
    $name = trim($name);
    $slug = trim($slug);
    if ($name === '') {
        $name = $slug;
    }
    if ($slug === '') {
        $slug = 'mc-' . substr(md5((string) mt_rand()), 0, 6);
    }
    if ($mid > 0) {
        $db->query($db->update('table.metas')->rows([
            'name'  => $name,
            'slug'  => $slug,
            'order' => (int) $order,
        ])->where('mid = ? AND type = ?', $mid, 'moment_cat'));
        tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment category updated');
        return $mid;
    }
    /* 查重（slug 唯一） */
    $exist = $db->fetchRow($db->select()->from('table.metas')
        ->where('type = ? AND slug = ?', 'moment_cat', $slug)->limit(1));
    if ($exist) {
        return (int) $exist['mid'];
    }
    $newId = (int) $db->query($db->insert('table.metas')->rows([
        'name'  => $name,
        'slug'  => $slug,
        'type'  => 'moment_cat',
        'order' => (int) $order,
    ]));
    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment category inserted');
    return $newId;
}

/**
 * 删除说说分类，并把该分类下说说的 moment_cat 字段清掉（回到未分类）
 */
function tri_moment_cat_delete(int $mid): void
{
    $db = Db::get();
    $row = $db->fetchRow($db->select()->from('table.metas')->where('mid = ?', $mid)->limit(1));
    if (!$row) {
        return;
    }
    $slug = (string) ($row['slug'] ?? '');
    $db->query($db->delete('table.metas')->where('mid = ? AND type = ?', $mid, 'moment_cat'));
    if ($slug !== '') {
        $db->query($db->delete('table.fields')->where('name = ? AND str_value = ?', 'moment_cat', $slug));
    }
    tri_redis_invalidate(['moments', 'fusion', 'page'], 'moment category deleted');
}
