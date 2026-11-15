<?php
/**
 * TriM3 相册数据层
 *
 * 数据表：
 *   {prefix}albums  相册（aid / name / description / cover / sort / created）
 *   {prefix}photos  图片（pid / aid / url / thumb / title / sort / created）
 *
 * 图床：
 *   后台配置「自建图床 API」：API 地址 + Token + 文件字段 + 返回 URL 字段路径 + 域名前缀。
 *   配置了 API → 上传走图床；未配置 → 存本机 usr/uploads/albums/。
 *
 * 全部 SQL 走 Typecho 1.3 Query 构建器（自动转义，避免手拼字符串）。
 */

use Typecho\Db;

/** 建表（幂等，懒加载） */
function tri_album_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db = Db::get();
    $pfx = $db->getPrefix();

    $t = $db->fetchAll($db->query("SHOW TABLES LIKE '{$pfx}albums'"));
    if (empty($t)) {
        $db->query("CREATE TABLE IF NOT EXISTS `{$pfx}albums` (
            `aid` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL DEFAULT '',
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `cover` VARCHAR(1000) NOT NULL DEFAULT '',
            `sort` INT NOT NULL DEFAULT 0,
            `created` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`aid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $t2 = $db->fetchAll($db->query("SHOW TABLES LIKE '{$pfx}photos'"));
    if (empty($t2)) {
        $db->query("CREATE TABLE IF NOT EXISTS `{$pfx}photos` (
            `pid` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `aid` INT UNSIGNED NOT NULL DEFAULT 0,
            `url` VARCHAR(1000) NOT NULL DEFAULT '',
            `thumb` VARCHAR(1000) NOT NULL DEFAULT '',
            `title` VARCHAR(200) NOT NULL DEFAULT '',
            `sort` INT NOT NULL DEFAULT 0,
            `created` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`pid`),
            KEY `aid` (`aid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $done = true;
}

/** 相册列表（含图片数与封面回退） */
function tri_album_list(): array
{
    return tri_redis_remember('all', 'albums', function () {
        return tri_album_list_uncached();
    }, 300);
}

function tri_album_list_uncached(): array
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $rows = $db->fetchAll($db->select()->from($pfx . 'albums')->order('sort', Db::SORT_ASC));
    $out = [];
    foreach ($rows as $r) {
        $cover = (string) $r['cover'];
        if ($cover === '' && (int) $r['aid'] > 0) {
            $first = $db->fetchRow($db->select()->from($pfx . 'photos')
                ->where('aid = ?', (int) $r['aid'])
                ->order('sort', Db::SORT_ASC)->limit(1));
            $cover = $first ? (string) $first['url'] : '';
        }
        $cnt = $db->fetchRow($db->select('COUNT(*) AS n')->from($pfx . 'photos')->where('aid = ?', (int) $r['aid']));
        $out[] = [
            'aid' => (int) $r['aid'],
            'name' => (string) $r['name'],
            'description' => (string) $r['description'],
            'cover' => $cover,
            'sort' => (int) $r['sort'],
            'count' => $cnt ? (int) $cnt['n'] : 0,
            'created' => (int) $r['created'],
        ];
    }
    return $out;
}

/** 相册图片列表（按 sort 排序） */
function tri_album_photos(int $aid): array
{
    return tri_redis_remember('photos-' . $aid, 'albums', function () use ($aid) {
        return tri_album_photos_uncached($aid);
    }, 300);
}

function tri_album_photos_uncached(int $aid): array
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $rows = $db->fetchAll($db->select()->from($pfx . 'photos')
        ->where('aid = ?', $aid)
        ->order('sort', Db::SORT_ASC));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'pid' => (int) $r['pid'],
            'url' => (string) $r['url'],
            'thumb' => (string) $r['thumb'],
            'title' => (string) $r['title'],
            'sort' => (int) $r['sort'],
        ];
    }
    return $out;
}

/** 相册详情 */
function tri_album_get(int $aid): ?array
{
    return tri_redis_remember('detail-' . $aid, 'albums', function () use ($aid) {
        return tri_album_get_uncached($aid);
    }, 300);
}

function tri_album_get_uncached(int $aid): ?array
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $row = $db->fetchRow($db->select()->from($pfx . 'albums')->where('aid = ?', $aid)->limit(1));
    if (!$row) {
        return null;
    }
    return [
        'aid' => (int) $row['aid'],
        'name' => (string) $row['name'],
        'description' => (string) $row['description'],
        'cover' => (string) $row['cover'],
        'sort' => (int) $row['sort'],
        'created' => (int) $row['created'],
    ];
}

/** 保存相册（aid=0 新增），返回 aid */
function tri_album_save(int $aid, string $name, string $description, string $cover): int
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $name = trim($name);
    if ($name === '') {
        $name = '未命名相册';
    }
    if ($aid > 0) {
        $db->query($db->update($pfx . 'albums')->rows([
            'name' => $name,
            'description' => $description,
            'cover' => $cover,
        ])->where('aid = ?', $aid));
        tri_redis_invalidate(['albums', 'page'], 'album updated');
        return $aid;
    }
    $id = $db->query($db->insert($pfx . 'albums')->rows([
        'name' => $name,
        'description' => $description,
        'cover' => $cover,
        'sort' => 0,
        'created' => time(),
    ]));
    tri_redis_invalidate(['albums', 'page'], 'album inserted');
    return (int) $id;
}

/** 删除相册（连带图片） */
function tri_album_delete(int $aid): void
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $db->query($db->delete($pfx . 'photos')->where('aid = ?', $aid));
    $db->query($db->delete($pfx . 'albums')->where('aid = ?', $aid));
    tri_redis_invalidate(['albums', 'page'], 'album deleted');
}

/** 相册排序（id 数组） */
function tri_album_sort(array $ids): void
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $i = 0;
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $db->query($db->update($pfx . 'albums')->rows(['sort' => $i])->where('aid = ?', $id));
            $i++;
        }
    }
    tri_redis_invalidate(['albums', 'page'], 'albums sorted');
}

/** 图片列表 */
function tri_photo_list(int $aid): array
{
    return tri_redis_remember('photo-list-' . $aid, 'albums', function () use ($aid) {
        return tri_photo_list_uncached($aid);
    }, 300);
}

function tri_photo_list_uncached(int $aid): array
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $rows = $db->fetchAll($db->select()->from($pfx . 'photos')
        ->where('aid = ?', $aid)
        ->order('sort', Db::SORT_ASC));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'pid' => (int) $r['pid'],
            'aid' => (int) $r['aid'],
            'url' => (string) $r['url'],
            'thumb' => (string) $r['thumb'],
            'title' => (string) $r['title'],
            'sort' => (int) $r['sort'],
            'created' => (int) $r['created'],
        ];
    }
    return $out;
}

/** 保存图片（pid=0 新增），返回 pid */
function tri_photo_save(int $pid, int $aid, string $url, string $title): int
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    if ($pid > 0) {
        $db->query($db->update($pfx . 'photos')->rows([
            'url' => $url,
            'title' => $title,
        ])->where('pid = ?', $pid));
        tri_redis_invalidate(['albums', 'page'], 'photo updated');
        return $pid;
    }
    $id = $db->query($db->insert($pfx . 'photos')->rows([
        'aid' => $aid,
        'url' => $url,
        'title' => $title,
        'sort' => 0,
        'created' => time(),
    ]));
    tri_redis_invalidate(['albums', 'page'], 'photo inserted');
    return (int) $id;
}

/** 删除图片 */
function tri_photo_delete(int $pid): void
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $db->query($db->delete($pfx . 'photos')->where('pid = ?', $pid));
    tri_redis_invalidate(['albums', 'page'], 'photo deleted');
}

/** 图片排序（pid 数组） */
function tri_photo_sort(int $aid, array $pids): void
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $i = 0;
    foreach ($pids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $db->query($db->update($pfx . 'photos')->rows(['sort' => $i])
                ->where('pid = ?', $id)->where('aid = ?', $aid));
            $i++;
        }
    }
    tri_redis_invalidate(['albums', 'page'], 'photos sorted');
}

/** 设封面 */
function tri_album_set_cover(int $aid, string $cover): void
{
    tri_album_ensure_tables();
    $db = Db::get();
    $pfx = $db->getPrefix();
    $db->query($db->update($pfx . 'albums')->rows(['cover' => $cover])->where('aid = ?', $aid));
    tri_redis_invalidate(['albums', 'page'], 'album cover updated');
}

/** 从嵌套数组按点路径取值（data.links.url） */
function tri_album_dig(array $arr, string $path)
{
    $node = $arr;
    foreach (explode('.', $path) as $seg) {
        if (is_array($node) && array_key_exists($seg, $node)) {
            $node = $node[$seg];
        } else {
            return '';
        }
    }
    return is_scalar($node) ? (string) $node : '';
}

/**
 * 上传图片（$_FILES[file]），返回 ['ok'=>bool,'url'=>string,'msg'=>string]
 * 配置了图床 API → 走图床；否则存本机 usr/uploads/albums/YYYYMM/
 */
function tri_album_upload(array $file): array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'url' => '', 'msg' => '未收到文件'];
    }
    $tmp = $file['tmp_name'];
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        return ['ok' => false, 'url' => '', 'msg' => '文件大小超出限制（20MB）'];
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'], true)) {
        return ['ok' => false, 'url' => '', 'msg' => '不支持的图片格式'];
    }

    /* 内容校验：避免仅改后缀的非图片文件入库 */
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string) finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('getimagesize')) {
        $info = @getimagesize($tmp);
        if ($info && !empty($info['mime'])) {
            $mime = (string) $info['mime'];
        }
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-ms-bmp'];
    if ($mime === '' || !in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'url' => '', 'msg' => '文件内容不是有效图片'];
    }

    $type = trim((string) Helper::options()->albumApiType);
    if ($type === 'qiniu') {
        return tri_album_upload_qiniu($file);
    }

    $apiUrl = trim((string) Helper::options()->albumApiUrl);
    if ($apiUrl !== '') {
        return tri_album_upload_remote($file, $apiUrl);
    }

    return tri_album_upload_local($file, $ext);
}

/** 本机存储 */
function tri_album_upload_local(array $file, string $ext): array
{
    $uploadRoot = __TYPECHO_ROOT_DIR__ . '/usr/uploads/albums';
    if (!is_dir($uploadRoot)) {
        @mkdir($uploadRoot, 0755, true);
    }
    $ym = date('Ym');
    $dir = $uploadRoot . '/' . $ym;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fname = date('His') . '_' . substr(md5(uniqid((string) mt_rand(), true)), 0, 8) . '.' . $ext;
    $dest = $dir . '/' . $fname;

    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'url' => '', 'msg' => '保存文件失败'];
    }
    $base = rtrim(Helper::options()->siteUrl, '/');
    return ['ok' => true, 'url' => $base . '/usr/uploads/albums/' . $ym . '/' . $fname, 'msg' => ''];
}

/** 图床 API（兰空 Lsky Pro v2 / Chevereto / 又拍云 / 通用 multipart）
 *
 * 后台「图床类型」albumApiType：
 *   lsky      —— 文件字段 file、Bearer Token、返回 data.links.url（可自定义覆盖）
 *   chevereto —— 文件字段 source、API Key 以 key 字段提交、返回 image.url（可自定义覆盖）
 *   upyun     —— 又拍云 Form API：URL 填 https://v0.api.upyun.com/<服务名>/，
 *                Token 填「操作员名:操作员密码」（冒号分隔），返回 json.url（可自定义覆盖）
 */
function tri_album_upload_remote(array $file, string $apiUrl): array
{
    $opt = Helper::options();
    $type = trim((string) $opt->albumApiType);
    if ($type === '') {
        $type = 'lsky';
    }
    $token = trim((string) $opt->albumApiToken);
    $field = trim((string) $opt->albumApiFileField);
    if ($field === '') {
        $field = ($type === 'chevereto') ? 'source' : 'file';
    }
    $urlPath = trim((string) $opt->albumApiUrlPath);
    if ($urlPath === '') {
        $urlPath = ($type === 'chevereto') ? 'image.url' : 'data.links.url';
    }
    $domain = rtrim(trim((string) $opt->albumApiDomain), '/');

    $payload = [];
    $payload[$field] = new CURLFile($file['tmp_name'], (string) ($file['type'] ?? 'application/octet-stream'), (string) ($file['name'] ?? 'upload'));
    if ($type === 'chevereto' && $token !== '') {
        /* Chevereto 4：API Key 通过 POST 字段 key 提交 */
        $payload['key'] = $token;
    }

    /* 又拍云 Form API：policy + signature（表单字段 + Authorization 头双保险） */
    $upyunAuth = '';
    if ($type === 'upyun' && $token !== '') {
        $parts = explode(':', $token, 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $operator = trim($parts[0]);
            $password = trim($parts[1]);
            $bucket = '';
            $uPath = (string) parse_url($apiUrl, PHP_URL_PATH);
            if ($uPath !== '') {
                $bucket = trim($uPath, '/');
                $bucket = (string) preg_replace('#/.*$#', '', $bucket);
            }
            if ($bucket !== '') {
                $policy = base64_encode((string) json_encode(array(
                    'bucket' => $bucket,
                    'save-key' => '/{year}/{mon}/{day}/{random32}.{suffix}',
                    'expiration' => time() + 600,
                )));
                $sign = md5(md5($password) . '&' . $policy);
                $payload['policy'] = $policy;
                $payload['signature'] = $sign;
                $upyunAuth = 'UPYUN ' . $operator . ':' . $sign;
            }
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($upyunAuth !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $upyunAuth]);
    } elseif ($token !== '' && $type !== 'chevereto') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // PHP 8.5：不再调用已弃用的 curl_close()

    if ($body === false || $err !== '') {
        return ['ok' => false, 'url' => '', 'msg' => '图床请求失败：' . $err];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'url' => '', 'msg' => '图床返回非 JSON（HTTP ' . $code . '）'];
    }
    if ($type === 'upyun') {
        /* 又拍云：成功 code=200、message=ok、url 为完整地址 */
        if (isset($json['code']) && (int) $json['code'] !== 200) {
            return ['ok' => false, 'url' => '', 'msg' => '又拍云错误：' . (string) ($json['message'] ?? ('HTTP ' . $code))];
        }
        $url = (string) ($json['url'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'url' => '', 'msg' => '未能从又拍云响应中解析图片地址'];
        }
        /* Token 防盗链：_upt = MD5(密钥 & etime & URI){中间8位} + etime（官方 CDN 鉴权算法） */
        $tokSecret = trim((string) $opt->upyunTokenSecret);
        if ($tokSecret !== '') {
            $ttl = (int) $opt->upyunTokenTtl;
            if ($ttl < 60) {
                $ttl = 86400;
            }
            $etime = time() + $ttl;
            $uri = (string) parse_url($url, PHP_URL_PATH);
            if ($uri !== '') {
                $upt = substr(md5($tokSecret . $etime . $uri), 12, 8) . $etime;
                $url .= (strpos($url, '?') !== false ? '&' : '?') . '_upt=' . $upt;
            }
        }
        return ['ok' => true, 'url' => $url, 'msg' => ''];
    }
    if (isset($json['status_code']) && (int) $json['status_code'] !== 200 && isset($json['error']['message'])) {
        return ['ok' => false, 'url' => '', 'msg' => '图床错误：' . (string) $json['error']['message']];
    }
    if (isset($json['status']) && (int) $json['status'] !== 200 && isset($json['message'])) {
        return ['ok' => false, 'url' => '', 'msg' => '图床错误：' . (string) $json['message']];
    }
    if (isset($json['code']) && (int) $json['code'] !== 0 && isset($json['msg'])) {
        return ['ok' => false, 'url' => '', 'msg' => '图床错误：' . (string) $json['msg']];
    }
    $url = tri_album_dig($json, $urlPath);
    if ($url === '') {
        $url = tri_album_dig($json, 'image.url');
        if ($url === '') {
            $url = tri_album_dig($json, 'url');
            if ($url === '') {
                $url = tri_album_dig($json, 'data.url');
            }
        }
    }
    if ($url === '') {
        return ['ok' => false, 'url' => '', 'msg' => '未能从图床响应中解析图片地址'];
    }
    if (strpos($url, 'http') !== 0 && $domain !== '') {
        $url = $domain . '/' . ltrim($url, '/');
    }
    return ['ok' => true, 'url' => $url, 'msg' => ''];
}

/** 七牛云（Kodo 对象存储）：服务端生成上传凭证 + 表单直传
 *
 * 后台字段（qiniu 专属）：
 *   qiniuAccessKey / qiniuSecretKey —— 密钥管理中的 AK / SK
 *   qiniuBucket    —— 存储空间名
 *   qiniuRegion    —— 区域（z0 华东 / z1 华北 / z2 华南 / na0 北美 / as0 东南亚）
 *   qiniuPrefix    —— 资源 key 前缀（可选，如 img/）
 * 七牛专属：qiniuDomain 为 CDN 访问域名（图片地址前缀）。
 * 上传凭证：PutPolicy(scope=空间, deadline=now+3600) → urlsafe-base64 → HMAC-SHA1 签名
 * 表单上传：POST multipart 到区域上传域名（token/key/file），返回 {"hash":..., "key":...}
 */
function tri_album_upload_qiniu(array $file): array
{
    $opt = Helper::options();
    $ak = trim((string) $opt->qiniuAccessKey);
    $sk = trim((string) $opt->qiniuSecretKey);
    $bucket = trim((string) $opt->qiniuBucket);
    if ($ak === '' || $sk === '' || $bucket === '') {
        return ['ok' => false, 'url' => '', 'msg' => '七牛云未配置完整：请填写 AccessKey / SecretKey / 存储空间'];
    }
    $domain = rtrim(trim((string) $opt->qiniuDomain), '/');
    if ($domain === '') {
        return ['ok' => false, 'url' => '', 'msg' => '七牛云未配置 CDN 访问域名'];
    }
    if (strpos($domain, 'http') !== 0) {
        $domain = 'https://' . $domain;
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'], true)) {
        return ['ok' => false, 'url' => '', 'msg' => '不支持的图片格式'];
    }
    /* 资源 key：可选前缀 + 日期目录 + 随机名 */
    $prefix = trim((string) $opt->qiniuPrefix);
    $key = ($prefix !== '') ? rtrim($prefix, '/') . '/' : '';
    $key .= date('Y/m/') . date('His') . '_' . substr(md5(uniqid((string) mt_rand(), true)), 0, 8) . '.' . $ext;

    /* 生成上传凭证
     * 注意：base64url 编码必须保留 '=' 填充（与七牛官方 SDK 一致），
     * 去掉 padding 会导致七牛服务端拒绝，返回 401 bad token。 */
    $policy = (string) json_encode(array('scope' => $bucket, 'deadline' => time() + 3600));
    $encPolicy = strtr(base64_encode($policy), '+/', '-_');
    $encSign = strtr(base64_encode((string) hash_hmac('sha1', $encPolicy, $sk, true)), '+/', '-_');
    $uploadToken = $ak . ':' . $encSign . ':' . $encPolicy;

    /* 区域上传域名 */
    $uploadHosts = array(
        'z0'  => 'https://upload.qiniup.com',
        'z1'  => 'https://upload-z1.qiniup.com',
        'z2'  => 'https://upload-z2.qiniup.com',
        'na0' => 'https://upload-na0.qiniup.com',
        'as0' => 'https://upload-as0.qiniup.com',
    );
    $region = trim((string) $opt->qiniuRegion);
    $uploadUrl = $uploadHosts[$region] ?? $uploadHosts['z0'];

    $payload = array(
        'token' => $uploadToken,
        'key'   => $key,
        'file'  => new CURLFile($file['tmp_name'], (string) ($file['type'] ?? 'application/octet-stream'), (string) ($file['name'] ?? 'upload')),
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $uploadUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // PHP 8.5：不再调用已弃用的 curl_close()

    if ($body === false || $err !== '') {
        return ['ok' => false, 'url' => '', 'msg' => '七牛云请求失败：' . $err];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'url' => '', 'msg' => '七牛云返回非 JSON（HTTP ' . $code . '）'];
    }
    if (isset($json['error'])) {
        $em = is_array($json['error']) ? (string) ($json['error']['message'] ?? '') : (string) $json['error'];
        $ec = (string) ($json['error_code'] ?? '');
        $tip = '';
        if ($code === 401 && ($em === 'bad token' || $ec === 'BadToken')) {
            $tip = '；AccessKey/SecretKey 无效或已被关闭，请在七牛控制台重新确认/创建密钥';
        } elseif ($em === 'incorrect region' || $ec === 'IncorrectRegion') {
            $tip = '；存储空间不在所选区域，请核对后台「区域」设置';
        } elseif ($em === 'no such bucket' || $ec === 'NoSuchBucket') {
            $tip = '；存储空间名不存在，请核对「存储空间(Bucket)」';
        } elseif ($code === 403) {
            $tip = '；空间访问被拒绝，请检查空间权限与防盗链设置';
        }
        return ['ok' => false, 'url' => '', 'msg' => '七牛云错误：' . ($em !== '' ? $em : ('HTTP ' . $code)) . $tip];
    }
    $rkey = (string) ($json['key'] ?? '');
    if ($rkey === '') {
        return ['ok' => false, 'url' => '', 'msg' => '未能从七牛云响应中解析资源 key'];
    }
    $url = $domain . '/' . $rkey;
    /* 存原始 URL（不带签名）：防盗链签名由输出层 tri3_qiniu_sign_url() 每次动态生成，
     * 避免固化签名 URL 过期后图片 403；未启用防盗链（密钥留空）时原样返回 */
    return ['ok' => true, 'url' => $url, 'msg' => ''];
}
