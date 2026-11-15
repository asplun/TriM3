<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 图片代理缓存的自动预热（把后台「自动」开关真正接上线）
 *
 * 后台一直有 imgCacheAuto / imgCacheInterval 两个开关，但此前只有存值与显示，
 * 没有任何代码去执行 —— 等于打开也不会发生任何事。这里补上执行端：
 *   · 触发方式：访客的 HTML 请求顺带检查一次（无需再配任何外部计划任务）；
 *   · 节流：cache/imgcache-last.txt 记上次触发时间，间隔内只触发一次；
 *   · 触发动作：向 api/img-cache.php?do=run 发一个「发出即断开」的请求，
 *     对方在自己的进程里跑完，访客请求不会等待（用 fsockopen 立即关闭，
 *     不读响应 —— 用 file_get_contents 会等到对方响应首字节，把访客请求一起拖住）；
 *   · 密钥：后台没填时自动生成一个存回主题设置。空密钥下接口只认管理员登录，
 *     访客请求无法触发，自动预热会永远不动。
 */

/* 取/建接口密钥：后台填了就用自己的，没填则生成一个并写回主题设置 */
function tri3_imgcache_secret()
{
    $secret = trim((string) tri_opt('imgCacheSecret', ''));
    if ($secret !== '') { return $secret; }
    $secret = bin2hex(random_bytes(16));
    try {
        $db = \Typecho\Db::get();
        $name = 'theme:' . (string) Helper::options()->theme;
        $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', $name));
        $cfg = ($row && isset($row['value'])) ? json_decode((string) $row['value'], true) : array();
        if (!is_array($cfg)) { $cfg = array(); }
        if (trim((string) ($cfg['imgCacheSecret'] ?? '')) !== '') { return trim((string) $cfg['imgCacheSecret']); }
        $cfg['imgCacheSecret'] = $secret;
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        if ($row) {
            $db->query($db->update('table.options')->rows(array('value' => $json))->where('name = ?', $name));
        } else {
            $db->query($db->insert('table.options')->rows(array('name' => $name, 'user' => 0, 'value' => $json)));
        }
    } catch (Throwable $e) {
        return '';
    }
    return $secret;
}

/* 到点则触发一次后台预热；同一间隔内只触发一次 */
function tri3_imgcache_maybe_warm()
{
    if ((string) tri_opt('imgCacheAuto', '0') !== '1') { return; }
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') { return; }
    if (!empty($_GET['do'])) { return; }
    $days = max(1, min(30, (int) tri_opt('imgCacheInterval', 1)));
    $dir = __DIR__ . '/../cache';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $stamp = $dir . '/imgcache-last.txt';
    $last = is_file($stamp) ? (int) @file_get_contents($stamp) : 0;
    if (time() - $last < $days * 86400) { return; }
    $secret = tri3_imgcache_secret();
    if ($secret === '') { return; }
    /* 先落时间戳：失败也按间隔退避，不在每个访客请求上重试 */
    @file_put_contents($stamp, (string) time(), LOCK_EX);
    $url = rtrim((string) Helper::options()->themeUrl, '/')
         . '/api/img-cache.php?do=run&budget=180&secret=' . rawurlencode($secret);
    $p = @parse_url($url);
    if (!is_array($p) || empty($p['host'])) { return; }
    $scheme = (($p['scheme'] ?? 'https') === 'https') ? 'ssl://' : 'tcp://';
    $port = (int) ($p['port'] ?? (($p['scheme'] ?? 'https') === 'https' ? 443 : 80));
    $fp = @fsockopen($scheme . $p['host'], $port, $errno, $errstr, 2);
    if (!$fp) { return; }
    $path = ($p['path'] ?? '/') . (empty($p['query']) ? '' : '?' . $p['query']);
    @fwrite($fp, "GET " . $path . " HTTP/1.1\r\nHost: " . $p['host']
        . "\r\nUser-Agent: TriM3-Warm/1.0\r\nConnection: close\r\n\r\n");
    @fclose($fp);   /* 发出即断开：不等对方跑完 */
}
