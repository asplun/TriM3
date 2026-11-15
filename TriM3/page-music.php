<?php
/**
 * 音乐歌单 - SPlayer风格
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$GLOBALS['__tri_no_rightbar'] = true;
$triPageKey = 'music';

ob_start();
$this->need('header.php');
$triHeader = ob_get_clean();
echo $triHeader;

$triThemeBase = (string) ($this->options->themeUrl ?? '');
$triMetingApi = trim((string) tri_opt('metingApi', ''));
if ($triMetingApi === '') {
    $triMetingApi = $triThemeBase;
    if ($triMetingApi !== '' && substr($triMetingApi, -1) !== '/') { $triMetingApi .= '/'; }
    $triMetingApi .= 'api/meting-api.php';
}

$triPlaylists = array();
$triPlaylistsRaw = trim((string) tri_opt('musicPlaylists', ''));
if ($triPlaylistsRaw !== '') {
    foreach (preg_split('/\r\n|\r|\n/', $triPlaylistsRaw) as $triLine) {
        $triLine = trim($triLine);
        if ($triLine === '') continue;
        $triParts = explode('|', $triLine);
        if (count($triParts) >= 3) {
            $triPlaylists[] = array(
                'name'   => trim($triParts[0]),
                'server' => trim($triParts[1]),
                'id'     => trim($triParts[2]),
            );
        }
    }
}
if (empty($triPlaylists)) {
    $triMetParsed = tri3_player_source();
    $triPlaylists[] = array(
        'name'   => '默认歌单',
        'server' => $triMetParsed['server'],
        'id'     => $triMetParsed['id'],
    );
}
?>



<div class="layout">
<?php /* 背景层由脚本注入到 body：.layout 有 backdrop-filter，会形成 fixed 包含块，放在 layout 内只会覆盖大框 */ ?>
<div data-sp-bg-marker hidden></div>
  <?php $this->need('component/nav-left.php'); ?>
  <?php $this->need('component/nav.php'); ?>
  <main class="col-main">
    <div class="sp-player" data-controller="music">
      
      <div class="sp-main">
        <div class="sp-tabs" id="sp-tabs">
          <?php foreach ($triPlaylists as $i => $pl): ?>
          <button class="sp-tab<?php echo $i === 0 ? ' active' : ''; ?>"
            data-server="<?php echo htmlspecialchars($pl['server']); ?>"
            data-id="<?php echo htmlspecialchars($pl['id']); ?>"
            data-name="<?php echo htmlspecialchars($pl['name']); ?>"><?php echo htmlspecialchars($pl['name']); ?></button>
          <?php endforeach; ?>
        </div>
        <div class="sp-row" style="display:flex;flex:1;min-height:0;">
          <button class="sp-collapse-btn" id="sp-collapse-btn" data-action="click->music#toggleSidebar">&#9664;</button>
          <div class="sp-left">
          <div class="sp-pl-info">
            <div style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
            <div class="sp-pl-cover" id="sp-pl-cover"></div>
            <span id="sp-pl-platform" style="display:inline-block;padding:2px 10px;border-radius:10px;background:rgba(255,255,255,0.1);font-size:11px;color:rgba(255,255,255,0.5);"></span>
            <span class="sp-pl-tag" id="sp-pl-tag" style="display:inline-block;background:var(--border-light, rgba(255,255,255,0.1));padding:2px 10px;border-radius:10px;font-size:11px;color:var(--text, rgba(255,255,255,0.7));"></span>
          </div>
            <div class="sp-pl-meta">
              <div class="sp-pl-name" id="sp-pl-name">加载中...</div>
              <div class="sp-pl-count" id="sp-pl-count"></div>
              <div class="sp-pl-stats" style="font-size:12px;color:var(--text-muted, rgba(255,255,255,0.4));margin-top:4px;">
                <span id="sp-pl-creator" style="display:none"></span>
                <span id="sp-pl-playcount" style="margin-left:8px;"></span>
              </div>
            </div>
          </div>
          <div class="sp-pl-desc" id="sp-pl-desc" style="display:-webkit-box;font-size:12px;color:var(--text-secondary, rgba(255,255,255,0.5));margin:8px 0 10px;line-height:1.6;cursor:pointer;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"></div>
          <div class="sp-tracks" id="sp-tracks"></div>
        </div>
        <div class="sp-right">
          <button class="sp-right-close" data-action="click->music#toggleMobileList" style="position:absolute;top:12px;right:12px;width:36px;height:36px;border-radius:50%;border:none;background:rgba(255,255,255,0.15);color:#fff;font-size:18px;cursor:pointer;z-index:5;">✕</button>
          <div class="sp-big-cover" id="sp-big-cover"></div>
          <div class="sp-now-platform" id="sp-now-platform"></div>
          <div class="sp-now-title" id="sp-now-title">未播放</div>
          <div class="sp-now-artist" id="sp-now-artist"></div>
          <div class="sp-lyrics" id="sp-lyrics"></div>
        </div>
      </div>
      <div class="sp-bar">
        <button id="sp-bar-toggle" data-action="click->music#toggleMobileList">☰</button>
        <div class="sp-bar-cover" id="sp-bar-cover" data-action="click->music#toggleMobileList"></div>
        <div class="sp-bar-info" data-action="click->music#toggleMobileList">
          <div class="sp-bar-title" id="sp-bar-title"></div>
          <div class="sp-bar-artist" id="sp-bar-artist"></div>
        </div>
        <div class="sp-bar-controls">
          <button class="sp-btn" id="sp-prev" data-action="click->music#prev">&#9664;&#9664;</button>
          <button class="sp-btn sp-btn-play" id="sp-play" data-action="click->music#play">&#9654;</button>
          <button class="sp-btn" id="sp-next" data-action="click->music#next">&#9654;&#9654;</button>
        </div>
        <div class="sp-progress" id="sp-progress" data-action="click->music#seek"><div class="sp-progress-fill" id="sp-progress-fill"></div></div>
        <div class="sp-time" id="sp-time">0:00 / 0:00</div></div>
      </div>
    </div>
  </main>

<script>
(function(){
  /* 防止 data-swup-eval 脚本重复执行导致事件监听器累积：
     每次脚本执行前先移除上一次注册的监听器 */
  if (window._spRemoveListeners) {
    try { window._spRemoveListeners(); } catch(e) {}
  }
  window._spRemoveListeners = null;
  /* 唯一实例 ID：确保只有最新闭包的 spReInit/init 会执行，
     旧闭包即使监听器残留也直接返回，避免引用失效 DOM */
  var _spInstanceId = Date.now() + '_' + Math.random().toString(36).slice(2);
  window._spLatestInstanceId = _spInstanceId;
  /* 停止旧闭包的所有异步操作：定时器、动画帧、页面活跃标志 */
  if (window._spSyncInterval) { clearInterval(window._spSyncInterval); window._spSyncInterval = null; }
  if (window._spLrcAnimId) { cancelAnimationFrame(window._spLrcAnimId); window._spLrcAnimId = null; }
  window._spLrcRafRunning = false;
  window._spPageActive = false;

  var musicStarted = false;
  var API = "<?php echo htmlspecialchars($triMetingApi, ENT_QUOTES); ?>";
  /* 页面级请求中止器：离开音乐页时立刻收掉在途请求，
     避免它们与 Swup 的导航请求在连接层相撞（症状为 Failed to fetch → 整页重载） */
  var _spCtl = (typeof AbortController !== "undefined") ? new AbortController() : null;
  function _spAbort() { try { if (_spCtl) _spCtl.abort(); } catch (e) {} }
  function _spFetchSig() { return _spCtl ? { signal: _spCtl.signal } : {}; }
  function _spNewCtl() { _spCtl = (typeof AbortController !== "undefined") ? new AbortController() : null; }

  var list = [];
  var curIdx = -1;
  var lrcData = [];
  var $ = function(id) { return document.getElementById(id); };
  var serverNames = {netease:"网易云音乐",tencent:"QQ音乐",kugou:"酷狗音乐",kuwo:"酷我音乐"};

  var _plCache = {};
  function loadPlaylist(server, pid, cb) {
    var key = server + ":" + pid;
    window._spPlCache = window._spPlCache || {};
    window._spPlLoading = window._spPlLoading || {};
    if (window._spPlCache[key]) { cb(window._spPlCache[key]); return; }
    /* 防止并发重复请求：缓存设置前的多次调用共享同一次请求 */
    if (window._spPlLoading[key]) {
      window._spPlLoading[key].push(cb);
      return;
    }
    window._spPlLoading[key] = [cb];
    fetch(API + "?server=" + encodeURIComponent(server) + "&type=playlist&id=" + encodeURIComponent(pid), _spFetchSig())
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var items = (data || []).map(function(t) {
          var artist = Array.isArray(t.artist) ? t.artist.join('/') : (t.artist || t.author || "未知歌手");
          var _pic = String(t.pic || t.albumPic || t.pic_api || "").replace(/^http:\/\//i, "https://");
          return { name: t.name || t.title || "未知歌曲", artist: artist, pic: _pic, id: t.id || t.url_id || "", source: server };
        });
        list = items; window._spPlCache[key] = items;
        var cbs = window._spPlLoading[key] || [];
        delete window._spPlLoading[key];
        cbs.forEach(function(c) { c && c(items); });
      })
      .catch(function() {
        list = [];
        var cbs = window._spPlLoading[key] || [];
        delete window._spPlLoading[key];
        cbs.forEach(function(c) { c && c([]); });
      });
  }

  /* 歌单元信息全局缓存：跨页面持久化，切换回来无需重新请求 */
  window._spPlInfoCache = window._spPlInfoCache || {};
  function loadPlaylistInfo(server, pid, cb) {
    if (!pid) { return; } /* 空 id 不去打上游 */
    var key = server + ":" + pid;
    var hit = window._spPlInfoCache[key];
    /* 命中缓存（含在途 Promise）直接复用，不再重复请求同一歌单 */
    if (hit) { if (hit.then) { hit.then(function(d) { cb && cb(d); }); } else { cb && cb(hit); } return; }
    var p = fetch(API + "?server=" + encodeURIComponent(server) + "&type=playlist-info&id=" + encodeURIComponent(pid), _spFetchSig())
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.cover) data.cover = String(data.cover).replace(/^http:\/\//i, "https://");
        window._spPlInfoCache[key] = data;
        return data;
      })
      .catch(function() {
        if (window._spPlInfoCache[key] === p) delete window._spPlInfoCache[key];
        return null;
      });
    window._spPlInfoCache[key] = p; /* 在途 Promise 即锁：并发调用共用同一次请求 */
    p.then(function(data) { cb && cb(data); });
  }

  function loadLrc(id, cb) {
    if (!id) { cb && cb([]); return; }
    fetch(API + "?server=" + encodeURIComponent(currentServer()) + "&type=lrc&id=" + encodeURIComponent(id), _spFetchSig())
      .then(function(r) { return r.text(); })
      .then(function(lrc) {
        var lines = [];
        (lrc || "").split("\n").forEach(function(line) {
          var m = line.match(/\[(\d{2}):(\d{2})(?:\.(\d{1,3}))?\](.*)/);
          if (m) {
            var t = parseInt(m[1]) * 60 + parseInt(m[2]) + (m[3] ? parseInt(m[3]) / (m[3].length === 3 ? 1000 : 100) : 0);
            lines.push({ t: t, text: m[4] });
          }
        });
        cb && cb(lines);
      })
      .catch(function() { cb && cb([]); });
  }

  function currentServer() {
    var tab = document.querySelector(".sp-tab.active");
    return tab ? (tab.getAttribute("data-server") || "netease") : "netease";
  }
  function currentSong() { return list[curIdx] || {}; }

  window.spSwitchTab = function(server, pid, name) {
    document.querySelectorAll(".sp-tab").forEach(function(t) { t.classList.remove("active"); });
    var tab = document.querySelector('.sp-tab[data-server="' + server + '"][data-id="' + pid + '"]');
    if (tab) tab.classList.add("active");
    loadPlaylist(server, pid, function(items) {
      if (!window._spPageActive) return;
      list = items; renderSongList(items);
      var pc = $("sp-pl-count");
      if (pc) { var parts = pc.textContent.split("·"); pc.textContent = items.length + " 首 · " + (parts[1] ? parts[1].trim() : ""); }
    });
    loadPlaylistInfo(server, pid, function(info) { if (!window._spPageActive || !info) return; renderPlInfo(info); });
    if (!musicStarted) {
      $("sp-now-title").textContent = name;
      $("sp-now-artist").textContent = "歌单";
      $("sp-now-platform").textContent = "";
      $("sp-lyrics").innerHTML = "<div style='opacity:0.5'>点击歌曲开始播放</div>";
    }
  };

  /* 背景层必须挂在 body 上：.layout 带 backdrop-filter，会成为 position: fixed 的包含块，
     放在 layout 内时背景只覆盖大框（两侧露出 body 底色）。这里按需创建并复用。 */
  function spEnsureBg() {
    var bg = document.getElementById("sp-bg");
    if (!bg) {
      bg = document.createElement("div");
      bg.className = "sp-bg";
      bg.id = "sp-bg";
      document.body.insertBefore(bg, document.body.firstChild);
    }
    if (window._spLastBgUrl && !bg.style.backgroundImage) {
      bg.style.backgroundImage = "url('" + window._spLastBgUrl + "')";
    }
    return bg;
  }

  /* 统一设置背景并全局缓存，避免切换/首次加载时背景空白；http统一升级为https */
  function spSetBg(url) {
    if (!url) return;
    url = String(url).replace(/^http:\/\//i, "https://");
    window._spLastBgUrl = url;
    var bg = spEnsureBg();
    if (bg) bg.style.backgroundImage = "url('" + url + "')";
  }

  /* 统一设置封面三处（背景/大封面/底栏）并缓存，避免切换页面后封面空白 */
  function spSetCover(url) {
    if (!url) return;
    url = String(url).replace(/^http:\/\//i, "https://");
    window._spLastCover = url;
    if ($("sp-big-cover")) $("sp-big-cover").style.backgroundImage = "url('" + url + "')";
    if ($("sp-bar-cover")) $("sp-bar-cover").style.backgroundImage = "url('" + url + "')";
    spSetBg(url);
  }

  function renderPlInfo(info) {
    if (!window._spPageActive) return;
    if (info.cover && !musicStarted) {
      spSetCover(info.cover);
    }
    $("sp-pl-name").textContent = info.name || "未知歌单";
    var actualCount = (list && list.length) ? list.length : (info.trackCount || 0);
    $("sp-pl-count").textContent = actualCount + " 首 · " + (info.playCount || 0) + " 次播放";

    var plPlat = $("sp-pl-platform");
    if (plPlat) { plPlat.textContent = serverNames[currentServer()] || currentServer(); plPlat.style.display = "inline-block"; plPlat.style.marginTop = "8px"; }
    var cover = info.cover || info.picUrl || "";
    if (cover && $("sp-pl-cover")) $("sp-pl-cover").style.backgroundImage = "url('" + cover + "')";
    if (info.creator && $("sp-pl-creator")) $("sp-pl-creator").textContent = info.creator;
    if ($("sp-pl-desc")) {
      var descEl = $("sp-pl-desc");
      descEl.textContent = info.description || "";
      descEl.style.display = "-webkit-box"; descEl.style.webkitLineClamp = "3";
      descEl.style.webkitBoxOrient = "vertical"; descEl.style.overflow = "hidden"; descEl.style.cursor = "pointer";
      var descOpen = false;
      descEl.onclick = function() { descOpen = !descOpen; descEl.style.webkitLineClamp = descOpen ? "unset" : "3"; descEl.style.display = descOpen ? "block" : "-webkit-box"; };
    }
    if ($("sp-pl-tag")) {
      var tags = (info.tags || []).map(function(t) { return "<span class='sp-tag'>" + t + "</span>"; }).join("");
      $("sp-pl-tag").innerHTML = tags;
    }
  }

  var ITEM_H = 50, VBUF = 8, _allItems = [];
  function renderSongList(items) {
    if (!window._spPageActive || !$("sp-tracks")) return;
    _allItems = items;
    var c = $("sp-tracks");
    if (!c.querySelector(".sp-vs-spacer")) {
      c.innerHTML = '<div class="sp-vs-spacer"></div><div class="sp-vs-viewport"></div>';
      c.addEventListener("scroll", renderVisibleSongs);
    }
    c.querySelector(".sp-vs-spacer").style.height = (items.length * ITEM_H) + "px";
    renderVisibleSongs();
    if (curIdx >= 0) {
      var off = curIdx * ITEM_H;
      if (off < c.scrollTop || off > c.scrollTop + c.clientHeight - ITEM_H)
        c.scrollTop = Math.max(0, off - c.clientHeight / 2);
    }
  }


  /* 封面懒加载：只加载视口内的封面，已加载的URL缓存避免重复请求 */
  window._spCoverCache = window._spCoverCache || new Set();
  window._spCoverObserver = null;
  function spInitCoverObserver() {
    /* Swup导航后DOM被替换，旧observer的root失效，必须重建 */
    if (window._spCoverObserver) {
      try { window._spCoverObserver.disconnect(); } catch(e) {}
      window._spCoverObserver = null;
    }
    var rootEl = $("sp-tracks");
    if (!rootEl) return;
    window._spCoverObserver = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          var el = entry.target;
          var url = el.getAttribute("data-cover");
          if (url) {
            el.style.backgroundImage = "url(\'" + url + "\')";
            el.removeAttribute("data-cover");
            window._spCoverCache.add(url);
          }
          window._spCoverObserver.unobserve(el);
        }
      });
    }, { root: rootEl, rootMargin: "0px", threshold: 0.01 });
  }
  function spObserveCovers() {
    if (!window._spCoverObserver) spInitCoverObserver();
    if (!window._spCoverObserver) return;
    var covers = document.querySelectorAll(".sp-song-cover[data-cover]");
    for (var i = 0; i < covers.length; i++) {
      window._spCoverObserver.observe(covers[i]);
    }
  }

  function renderVisibleSongs() {
    if (!window._spPageActive || !_allItems.length) return;
    var c = $("sp-tracks"), vp = c.querySelector(".sp-vs-viewport");
    if (!vp || !_allItems.length) return;
    var st = c.scrollTop, vh = c.clientHeight;
    var s = Math.max(0, Math.floor(st / ITEM_H) - VBUF);
    var e = Math.min(_allItems.length, Math.ceil((st + vh) / ITEM_H) + VBUF);
    var html = "";
    var lazyCount = 0;
    for (var i = s; i < e; i++) {
      var t = _allItems[i];
      var cs = "";
      if (t.pic) {
        if (window._spCoverCache && window._spCoverCache.has(t.pic)) {
          cs = "style=\"background-image:url('" + t.pic + "')\"";
        } else if (lazyCount < 6) {
          cs = "style=\"background-image:url(\'" + t.pic + "\')\"";
          window._spCoverCache && window._spCoverCache.add(t.pic);
          lazyCount++;
        }
      }
      html += '<div class="sp-song' + (i === curIdx ? " active" : "") + '" data-action="click->music#playSong" data-music-index="' + i + '">' +
        '<span class="sp-song-idx">' + (i + 1) + '</span>' +
        '<span class="sp-song-cover" ' + cs + '></span>' +
        '<span class="sp-song-info"><span class="sp-song-name">' + t.name + '</span>' +
        '<span class="sp-song-artist">' + t.artist + '</span></span></div>';
    }
    vp.style.transform = "translateY(" + (s * ITEM_H) + "px)";
    vp.innerHTML = html;
    spObserveCovers();
  }

  window.spPlaySong = function(idx) {
    musicStarted = true; curIdx = idx;
    var t = currentSong();
    if (!t || !t.id) return;
    if (window.TriPlayer) {
      var items = list.map(function(s) {
        return { name: s.name, artist: s.artist, pic: s.pic, pic_api: s.pic, url_id: s.id, server: s.source, lrc: API + "?server=" + encodeURIComponent(s.source) + "&type=lrc&id=" + encodeURIComponent(s.id) };
      });
      window.TriPlayer.playList(items, idx, t.source || "netease");
    }
    /* 交给播放器播放即可：界面更新统一由播放器广播驱动（spApplyState） */
    renderSongList(list);
  };

  window.musicPlay = function() {
    if (curIdx < 0) { spPlaySong(0); return; }
    if (window.TriPlayer) window.TriPlayer.toggle();
  };
  window.musicPrev = function() { if (window.TriPlayer) window.TriPlayer.prev(); };
  window.musicNext = function() { if (window.TriPlayer) window.TriPlayer.next(); };
  window.musicSeek = function(e) {
    if (!window.TriPlayer || !e) return;
    var bar = e.currentTarget;
    var pct = (e.clientX - bar.getBoundingClientRect().left) / bar.offsetWidth;
    var audio = window.TriPlayer.getAudio ? window.TriPlayer.getAudio() : null;
    if (audio && audio.duration) audio.currentTime = pct * audio.duration;
  };

  var lastLrcIdx = -1;
  window._spLrcAnimId = window._spLrcAnimId || null;
  function renderLrc() {
    if (!window._spPageActive || !$("sp-lyrics")) return;
    if (!lrcData.length) { $("sp-lyrics").innerHTML = "<div style='opacity:0.5'>暂无歌词</div>"; return; }
    lastLrcIdx = -1;
    $("sp-lyrics").innerHTML = lrcData.map(function(l, i) { return '<div class="sp-lrc-line" data-idx="' + i + '">' + l.text + '</div>'; }).join("");
    /* 高亮与滚动由播放器广播（spLyricByTime）驱动，不再单开 rAF */
  }

  /* ===== 视图同步：只订阅播放器广播（TriPlayer.on），不轮询、不复制状态 ===== */
  var lastState = null;
  var lastTrackId = "";
  function fmtTime(v) {
    v = Math.max(0, Math.floor(v || 0));
    var mm = Math.floor(v / 60), ss = v % 60;
    return mm + ":" + (ss < 10 ? "0" : "") + ss;
  }
  function spSetNowPlaying(tr) {
    if (!tr) return;
    if ($("sp-now-title")) $("sp-now-title").textContent = tr.title || "";
    if ($("sp-now-artist")) $("sp-now-artist").textContent = tr.artist || "";
    if ($("sp-now-platform")) $("sp-now-platform").textContent = serverNames[tr.server] || tr.server || "";
    if ($("sp-bar-title")) $("sp-bar-title").textContent = tr.title || "";
    if ($("sp-bar-artist")) $("sp-bar-artist").textContent = tr.artist || "";
    if (tr.cover) { spSetCover(tr.cover); spSetBg(tr.cover); }
  }
  /* 当前曲目在本地歌单里定位（按 id 匹配，而不是拿全局下标硬套） */
  function spSyncHighlight() {
    var cur = (lastState && lastState.track) ? (lastState.track.id || "") : "";
    curIdx = -1;
    if (cur && list && list.length) {
      for (var i = 0; i < list.length; i++) {
        if ((list[i].id || list[i].url_id || "") === cur) { curIdx = i; break; }
      }
    }
    renderVisibleSongs();
  }
  function spLyricByTime(ct) {
    var c = $("sp-lyrics");
    if (!c || !lrcData.length) return;
    var i = -1;
    for (var k = 0; k < lrcData.length; k++) { if (ct >= lrcData[k].t) i = k; else break; }
    if (i < 0) return;
    if (i !== lastLrcIdx) {
      lastLrcIdx = i;
      var lines = c.querySelectorAll(".sp-lrc-line");
      for (var n = 0; n < lines.length; n++) { lines[n].classList.toggle("active", n === i); }
    }
    var act = c.querySelector(".sp-lrc-line.active");
    if (act) {
      var cRect = c.getBoundingClientRect();
      var lRect = act.getBoundingClientRect();
      var target = (lRect.top - cRect.top + c.scrollTop) - c.clientHeight * 0.45 + lRect.height / 2;
      target = Math.max(0, Math.min(target, c.scrollHeight - c.clientHeight));
      c.scrollTop = target;
    }
  }
  function spApplyState(st) {
    if (!window._spPageActive || !st) return;
    lastState = st;
    if (st.playing) musicStarted = true;
    if ($("sp-play")) $("sp-play").innerHTML = st.playing ? "\u23f8" : "\u25b6";
    if (st.kind !== "time") {
      var id = (st.track && st.track.id) || "";
      var changed = id !== lastTrackId;
      lastTrackId = id;
      spSyncHighlight();
      if (id) spSetNowPlaying(st.track);   /* 幂等收敛：每次状态变化都对齐文案与封面 */
      if (changed && id) {
        lastLrcIdx = -1;
        loadLrc(id, function (lines) {
          if (!window._spPageActive) return;
          lrcData = lines || [];
          lastLrcIdx = -1;
          renderLrc();
          spLyricByTime((lastState && lastState.currentTime) || 0);   /* 立刻对齐当前进度 */
        });
      }
    }
    if (st.duration > 0 && $("sp-progress-fill")) {
      $("sp-progress-fill").style.width = Math.min(100, (st.currentTime / st.duration) * 100) + "%";
      if ($("sp-time")) $("sp-time").textContent = fmtTime(st.currentTime) + " / " + fmtTime(st.duration);
    }
    if (lrcData.length) spLyricByTime(st.currentTime || 0);
  }
  function spSubscribe() {
    if (!window.TriPlayer || typeof window.TriPlayer.on !== "function") return;
    if (window._spUnsub) { try { window._spUnsub(); } catch (e) {} }
    window._spUnsub = window.TriPlayer.on(spApplyState);   /* on() 会立刻回调一次 init */
  }

  function spCleanup() {
    /* 只有最新实例的 cleanup 才重置全局状态 */
    if (window._spLatestInstanceId !== _spInstanceId) return;
    _spAbort();
    window._spPageActive = false;
    if (window._spUnsub) { try { window._spUnsub(); } catch (e) {} window._spUnsub = null; }
    window._spInitRunning = false;
    /* 注意：不移除 tri3:render/load 监听器——Swup 从缓存恢复页面时脚本不重执行，
       监听器需保留；spReInit 内的 location.pathname 检查已足够拦截非音乐页 */
  }


  /* 页面活跃标志：必须用全局变量，否则 data-swup-eval 重复执行时
     每个闭包有独立的 _spPageActive，旧闭包的标志无法被 spCleanup 重置 */
  window._spPageActive = true;


  var inited = false;
  window._triPlayerLoaded = true;
  window._spInitRunning = false;

  function init() {
    /* 只有最新实例才执行 */
    if (window._spLatestInstanceId !== _spInstanceId) return;
    _spNewCtl();
    if (window._spInitRunning) return;
    window._spInitRunning = true;
    try {
      /* 立即恢复上次封面与背景（含Swup导航切换场景），避免空白 */
      var _rc = window._spLastCover || window._spLastBgUrl;
      if (_rc) {
        if ($("sp-bg")) $("sp-bg").style.backgroundImage = "url('" + _rc + "')";
        if ($("sp-big-cover")) $("sp-big-cover").style.backgroundImage = "url('" + _rc + "')";
        if ($("sp-bar-cover")) $("sp-bar-cover").style.backgroundImage = "url('" + _rc + "')";
      }
      spInitCoverObserver();
      /* 滚动时继续加载更多封面 */
      var _spCoverScrollTimer = null;
      $("sp-tracks").addEventListener("scroll", function() {
        if (_spCoverScrollTimer) clearTimeout(_spCoverScrollTimer);
        _spCoverScrollTimer = setTimeout(function() { spObserveCovers(); }, 150);
      });
      document.querySelectorAll(".sp-tab").forEach(function(tab) {
        tab.addEventListener("click", function() {
          spSwitchTab(this.getAttribute("data-server"), this.getAttribute("data-id"), this.textContent.trim());
        });
      });

      /* 重置滚动位置，避免浏览器恢复旧位置导致封面不在视口 */
      if ($("sp-tracks")) $("sp-tracks").scrollTop = 0;
      var activeTab = document.querySelector(".sp-tab.active");
      if (activeTab) {
        var srv = activeTab.getAttribute("data-server"), pid = activeTab.getAttribute("data-id"), pname = activeTab.textContent.trim();
        loadPlaylist(srv, pid, function(items) {
          if (!window._spPageActive) return;
          list = items; renderSongList(items); spSyncHighlight();
          /* 未播放音乐时立即用歌单封面/首曲封面设置背景，不必等待延迟的info请求 */
          if (!musicStarted) {
            var _ck = srv + ":" + pid;
            var _ci = (window._spPlInfoCache && window._spPlInfoCache[_ck]) ? window._spPlInfoCache[_ck] : null;
            var _bgUrl = (_ci && _ci.cover) || (items && items[0] && items[0].pic) || "";
            if (_bgUrl) spSetCover(_bgUrl);
          }
        });
        /* 歌单元信息：有全局缓存则立即渲染，否则延迟1.5秒加载（减少首屏请求） */
        var _infoKey = srv + ":" + pid;
        var _cachedInfo = window._spPlInfoCache[_infoKey];
        if (_cachedInfo) {
          renderPlInfo(_cachedInfo);
        } else {
          var _spInfoLoaded = false;
          var loadPlInfoDeferred = function() {
            if (_spInfoLoaded || !window._spPageActive) return;
            _spInfoLoaded = true;
            loadPlaylistInfo(srv, pid, function(info) { if (!window._spPageActive || !info) return; renderPlInfo(info); });
          };
          setTimeout(loadPlInfoDeferred, 1500);
          document.getElementById("sp-tracks")?.addEventListener("scroll", loadPlInfoDeferred, { once: true });
        }
        if (!musicStarted) { $("sp-now-title").textContent = pname; $("sp-now-artist").textContent = "歌单"; }
      }
      /* 订阅播放器状态：一次订阅取代 500ms 轮询 + rAF 歌词循环。
         放在最后，让播放器状态覆盖上面的「歌单名」兜底文案。 */
      spSubscribe();
      inited = true;
      window._spInitRunning = false;
    } catch(e) { console.error("[music-page] init error:", e.message, e.stack); window._spInitRunning = false; }
  }

  function spReInit() {
    /* 仅在音乐页执行；导航到其他页面时页面切换事件仍会触发，需拦截 */
    if (!location.pathname.match(/music/i)) return;
    /* 只有最新实例才执行，旧闭包直接返回 */
    if (window._spLatestInstanceId !== _spInstanceId) return;
    window._spPageActive = true;
    if (!$("sp-progress-fill")) return;
    window._spInitRunning = false;
    init();
  }
  function _spOnPageShow(e) { if (e.persisted) spReInit(); }
  function _spOnPageHide() { spCleanup(); }
  function _spOnBeforeCache() { spCleanup(); }
  /* 关键：Swup 发起导航请求之前就收掉本页在途请求 */
  function _spOnBeforeVisit() { _spAbort(); }

  /* 导航后的重跑交给 Stimulus（assets/js/tri-app.js）：
     spReInit 自身已做「仅在音乐页 / 只跑最新实例」判断，这里用 add() 以便脚本重执行后立即生效。 */
  (window.TriHooks = window.TriHooks || {}).add
    ? window.TriHooks.add("musicPage", spReInit)
    : (window.TriHooks = window.TriHooks || {}).musicPage = spReInit;
  window.addEventListener("pageshow", _spOnPageShow);
  window.addEventListener("pagehide", _spOnPageHide);
  document.addEventListener("swup:visit:start", _spOnBeforeCache);
  document.addEventListener("swup:visit:start", _spOnBeforeVisit);

  /* 保存清理函数，供下次脚本执行时移除本次监听器 */
  window._spRemoveListeners = function() {
    window.removeEventListener("pageshow", _spOnPageShow);
    window.removeEventListener("pagehide", _spOnPageHide);
    document.removeEventListener("swup:visit:start", _spOnBeforeCache);
    document.removeEventListener("swup:visit:start", _spOnBeforeVisit);
  };

  var sidebarCollapsed = false;
  window.spToggleMobileList = function() {
    var right = document.querySelector('.sp-right');
    var row = document.querySelector('.sp-row');
    var left = document.querySelector('.sp-left');
    if (right.classList.contains('open')) {
      right.classList.remove('open');
      if (row) row.classList.remove('mobile-playing');
    } else {
      right.classList.add('open');
      if (row) row.classList.add('mobile-playing');
    }
  };
  window.spToggleSidebar = function() {
    sidebarCollapsed = !sidebarCollapsed;
    var left = document.querySelector('.sp-left');
    var btn = $("sp-collapse-btn");
    if (sidebarCollapsed) {
      left.style.width = '0px'; left.style.padding = '0px'; left.style.overflow = 'hidden';
      if (btn) { btn.innerHTML = '\u25B6'; btn.style.left = '0px'; }
    } else {
      left.style.width = '380px'; left.style.padding = '24px'; left.style.overflow = 'auto';
      if (btn) { btn.innerHTML = '\u25C0'; btn.style.left = '380px'; }
    }
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>
</div>



<?php
$this->need('footer.php');
?>