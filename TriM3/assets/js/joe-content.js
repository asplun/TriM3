/* ============================================================
   TriM3 · Joe 风格短代码渲染（前台）
   将后台 Joe 编辑器插入的 [tri-*] 短代码渲染为对应版式：
   box / fold / tabs / btn / hl / quote / gallery / divider
   保护 pre/code 内文本；Swup 切换后由 main.js Tri3MInit 调用
   ============================================================ */
(function () {
  'use strict';

  function parseAttrs(str) {
    var o = {};
    str.replace(/(\w+)(?:="([^"]*)")?/g, function (m, k, v) {
      o[k] = (v === undefined ? '' : v);
    });
    return o;
  }

  function render(html) {
    /* 提示框 */
    html = html.replace(/\[tri-box\s+([^\]]*)\]([\s\S]*?)\[\/tri-box\]/g, function (m, attrs, body) {
      var a = parseAttrs(attrs);
      var type = ['success', 'info', 'warning', 'danger'].indexOf(a.type) !== -1 ? a.type : 'info';
      var title = a.title || '';
      return '<div class="tri3-box tri3-box--' + type + '">'
        + (title ? '<div class="tri3-box-title">' + title + '</div>' : '')
        + '<div class="tri3-box-body">' + body + '</div></div>';
    });

    /* 折叠 */
    html = html.replace(/\[tri-fold\s+([^\]]*)\]([\s\S]*?)\[\/tri-fold\]/g, function (m, attrs, body) {
      var a = parseAttrs(attrs);
      return '<details class="tri3-fold"' + (a.open === 'true' ? ' open' : '') + '>'
        + '<summary>' + (a.title || '展开查看') + '</summary>'
        + '<div class="tri3-fold-body">' + body + '</div></details>';
    });

    /* Tabs */
    html = html.replace(/\[tri-tabs\]([\s\S]*?)\[\/tri-tabs\]/g, function (m, body) {
      var nav = [], panels = [], idx = 0;
      body.replace(/\[tri-tab\s+([^\]]*)\]([\s\S]*?)\[\/tri-tab\]/g, function (mm, attrs, content) {
        var a = parseAttrs(attrs);
        var name = a.name || '标签' + (idx + 1);
        nav.push('<button type="button" class="tri3-tabs-tab' + (idx === 0 ? ' is-active' : '') + '" data-idx="' + idx + '">' + name + '</button>');
        panels.push('<div class="tri3-tabs-panel' + (idx === 0 ? ' is-active' : '') + '" data-idx="' + idx + '">' + content + '</div>');
        idx++;
      });
      if (!idx) { return m; }
      return '<div class="tri3-tabs"><div class="tri3-tabs-nav">' + nav.join('') + '</div><div class="tri3-tabs-panels">' + panels.join('') + '</div></div>';
    });

    /* 按钮 */
    html = html.replace(/\[tri-btn\s+([^\]]*)\]([\s\S]*?)\[\/tri-btn\]/g, function (m, attrs, text) {
      var a = parseAttrs(attrs);
      var type = ['primary', 'outline', 'ghost'].indexOf(a.type) !== -1 ? a.type : 'primary';
      var href = a.href || '#';
      return '<a class="tri3-btn tri3-btn--' + type + '" href="' + href + '"'
        + (a.target === 'blank' ? ' target="_blank" rel="noopener nofollow"' : '') + '>'
        + text + '</a>';
    });

    /* 高亮 */
    html = html.replace(/\[tri-hl\]([\s\S]*?)\[\/tri-hl\]/g, '<mark class="tri3-hl">$1</mark>');

    /* 引言 */
    html = html.replace(/\[tri-quote\s+([^\]]*)\]([\s\S]*?)\[\/tri-quote\]/g, function (m, attrs, body) {
      var a = parseAttrs(attrs);
      return '<div class="tri3-quote"><div class="tri3-quote-body">' + body + '</div>'
        + (a.author ? '<div class="tri3-quote-author">—— ' + a.author + '</div>' : '')
        + '</div>';
    });

    /* 图集（每行一个图片地址；复用评论图片灯箱 .comment-img-link 委托）
       markdown 渲染后裸 URL 已变 <a href>、换行变 <br>，先归一化再提取 */
    html = html.replace(/\[tri-gallery\]([\s\S]*?)\[\/tri-gallery\]/g, function (m, urls) {
      var imgs = String(urls || '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<a[^>]*href="([^"]+)"[^>]*>[\s\S]*?<\/a>/g, '$1')
        .split(/\r?\n+/)
        .map(function (u) { return u.trim(); })
        .filter(Boolean);
      if (!imgs.length) { return ''; }
      var figs = imgs.map(function (u) {
        return '<figure class="tri3-gallery-item"><a class="comment-img-link" href="' + u + '" data-fancybox="tri-gallery">'
          + '<img loading="lazy" src="' + u + '" alt=""></a></figure>';
      }).join('');
      return '<div class="tri3-gallery">' + figs + '</div>';
    });

    /* 分隔线 */
    html = html.replace(/\[tri-divider\]/g, '<div class="tri3-divider"></div>');

    return html;
  }

  function bindTabs(scope) {
    scope.querySelectorAll('.tri3-tabs').forEach(function (tabs) {
      var nav = tabs.querySelector('.tri3-tabs-nav');
      if (!nav || nav.getAttribute('data-bound')) { return; }
      nav.setAttribute('data-bound', '1');
      nav.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('.tri3-tabs-tab') : null;
        if (!b) { return; }
        var idx = b.getAttribute('data-idx');
        nav.querySelectorAll('.tri3-tabs-tab').forEach(function (x) {
          x.classList.toggle('is-active', x === b);
        });
        tabs.querySelectorAll('.tri3-tabs-panel').forEach(function (p) {
          p.classList.toggle('is-active', p.getAttribute('data-idx') === idx);
        });
      });
    });
  }

  function renderAll(root) {
    root = root || document;
    if (!root.querySelectorAll) { return; }
    var scopes = root.querySelectorAll('.post-content, .moment-content');
    scopes.forEach(function (scope) {
      /* 保护代码块：其中文本不做短代码替换 */
      scope.querySelectorAll('pre, code').forEach(function (el) {
        el.setAttribute('data-t3k', '1');
      });
      var guard = 0, changed = true;
      while (changed && guard++ < 6) {
        changed = false;
        var els = scope.querySelectorAll('p, div, li, blockquote, td, th, h1, h2, h3, h4, h5, figcaption');
        els.forEach(function (el) {
          if (el.getAttribute('data-t3k') || (el.closest && el.closest('[data-t3k]'))) { return; }
          if (el.innerHTML.indexOf('[tri-') === -1) { return; }
          var nh = render(el.innerHTML);
          if (nh !== el.innerHTML) {
            el.outerHTML = nh;
            changed = true;
          }
        });
      }
      bindTabs(scope);
      joeRender(scope);
    });
  }

  /* ============================================================
     Joe 官方短代码（{xxx} 语法）渲染
     PHP 侧 lib/joe-short.php 已将 {xxx} 转为 joe-* 自定义元素，
     此处再转成最终版式结构（避免 customElements 在 Swup 下的重定义问题）
     ============================================================ */
  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  /* HTTPS 站点强制升级封面链接（网易云等封面源可能返回 http://，避免 Mixed Content 拦截） */
  function joeCover(u) {
    return u ? String(u).replace(/^http:\/\//i, 'https://') : u;
  }

  function parseJoeAttrs(str) {
    var o = {};
    String(str || '').replace(/([\w-]+)(?:="([^"]*)")?/g, function (m, k, v) {
      o[k] = (v === undefined ? '' : v);
    });
    return o;
  }

  function cleanJoeTemp(html) {
    return String(html || '').trim().replace(/^(<br\s*\/?>\s*)+|(<br\s*\/?>\s*)+$/g, '');
  }

  function joeThemeBase() {
    /* 优先匹配版本化的 main-v*.css，回退到 joe-content.css / lightgallery.css */
    var l = document.querySelector('link[href*="assets/css/main"]')
          || document.querySelector('link[href*="assets/css/joe-content"]')
          || document.querySelector('link[href*="assets/css/lightgallery"]');
    if (l) {
      var h = l.getAttribute('href') || '';
      var i = h.indexOf('assets/');
      if (i > -1) { return h.substring(0, i); }
    }
    return '';
  }

  function loadAPlayer(cb) {
    if (window.APlayer) { cb(); return; }
    var base = joeThemeBase();
    if (!base) { cb(); return; }
    if (!document.querySelector('link[href*="APlayer.min.css"]')) {
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = base + 'assets/lib/APlayer@1.10.1/APlayer.min.css';
      document.head.appendChild(l);
    }
    var s = document.createElement('script');
    s.src = base + 'assets/lib/APlayer@1.10.1/APlayer.min.js';
    s.onload = cb;
    document.head.appendChild(s);
  }

  function joeRender(scope) {
    var base = joeThemeBase();
    var meting = base ? base + 'api/meting-api.php' : 'meting-api.php';

    /* 播放器未启用提示 */
    function tip(msg) {
      /* 统一消息中心：全站提示归集到页面上方居中的 TriMsg 弹窗 */
      if (window.TriMsg) { window.TriMsg.show(msg); return; }
      var t = document.querySelector('.moment-toast');
      if (!t) { t = document.createElement('div'); t.className = 'moment-toast'; document.body.appendChild(t); }
      t.textContent = msg;
      t.classList.add('show');
      clearTimeout(t._triP);
      t._triP = setTimeout(function () { t.classList.remove('show'); }, 2200);
    }
    /* 平台名称 */
    function platformName(s) {
      return s === 'qq' || s === 'tencent' ? 'QQ 音乐' : '网易云音乐';
    }
    /* 秒数 → mm:ss */
    function fmtDur(sec) {
      var n = parseInt(sec, 10);
      if (isNaN(n) || n < 0) { return ''; }
      return Math.floor(n / 60) + ':' + ('0' + (n % 60)).slice(-2);
    }
    /* 数字 → 万格式化 */
    function fmtCount(n) {
      n = Math.max(0, Number(n) || 0);
      if (n >= 10000) { return (n / 10000).toFixed(n >= 100000 ? 0 : 1) + '万'; }
      return String(n);
    }

    /* 居中标题 */
    scope.querySelectorAll('joe-mtitle').forEach(function (el) {
      var t = el.getAttribute('title') || '默认标题';
      el.outerHTML = '<span class="joe_mtitle"><span class="joe_mtitle__text">' + escHtml(t) + '</span></span>';
    });

    /* 音乐：单曲 / 歌单 / MP3（联动本地 TriPlayer 悬浮播放器） */
    scope.querySelectorAll('joe-music, joe-mlist, joe-mp3').forEach(function (el) {
      var tag = el.tagName.toLowerCase();
      var server = el.getAttribute('server') || 'netease';
      var id = el.getAttribute('id') || '';
      var color = el.getAttribute('color') || el.getAttribute('theme') || '';
      var ph = document.createElement('div');
      if (tag === 'joe-mlist') {
        /* 歌单卡片：头部（真实标题/副标题/标签/介绍）+ 展开歌曲列表，点击行联动本地播放器 */
        ph.className = 'joe-mlist-card';
        ph.innerHTML = '<div class="joe-mlist-head">'
          + '<div class="joe-mlist-cover"><i class="ri-music-2-fill"></i></div>'
          + '<div class="joe-mlist-meta"><span class="joe-mlist-title">歌单加载中…</span>'
          + '<span class="joe-mlist-sub">' + escHtml(platformName(server)) + '</span></div>'
          + '<button type="button" class="joe-mlist-playall" aria-label="播放全部"><i class="ri-play-fill"></i></button>'
          + '</div>'
          + '<div class="joe-mlist-songs"><div class="joe-mlist-loading">正在拉取歌单…</div></div>';
        el.parentNode.replaceChild(ph, el);
        var headCover = ph.querySelector('.joe-mlist-cover');
        var titleEl = ph.querySelector('.joe-mlist-title');
        var subEl = ph.querySelector('.joe-mlist-sub');
        var songsBox = ph.querySelector('.joe-mlist-songs');
        var playAll = ph.querySelector('.joe-mlist-playall');
        var list = [];
        var detail = null;
        /* 并行：歌单元数据（标题/介绍/标签/封面）+ 歌曲列表 */
        Promise.all([
          fetch(meting + '?server=' + encodeURIComponent(server) + '&type=playlist-detail&id=' + encodeURIComponent(id) + '&_=' + Date.now(), {cache: 'no-store'})
            .then(function (r) { return r.json(); })
            .catch(function () { return null; }),
          fetch(meting + '?server=' + encodeURIComponent(server) + '&type=playlist&id=' + encodeURIComponent(id) + '&_=' + Date.now(), {cache: 'no-store'})
            .then(function (r) { return r.json(); })
            .catch(function () { return null; })
        ]).then(function (res) {
          detail = res[0];
          var data = res[1];
          list = (Array.isArray(data) ? data : []).filter(Boolean);
          if (!list.length) { songsBox.innerHTML = '<div class="joe-mlist-loading">歌单获取失败</div>'; return; }
          var dTitle = (detail && detail.title) || '';
          if (dTitle) {
            titleEl.textContent = dTitle;
            subEl.textContent = platformName(server) + ' · ' + list.length + ' 首';
            var dpic = detail.pic || '';
            /* 封面缺失时回退到首曲封面（QQ 歌单常无专属封面） */
            if (!dpic && list[0]) {
              dpic = list[0].pic_api || list[0].pic || list[0].pic_url || '';
            }
            if (dpic) { headCover.innerHTML = '<img src="' + escHtml(joeCover(dpic)) + '" alt="" loading="lazy">'; }
            /* 标签 chips */
            if (detail.tags && detail.tags.length) {
              var tagWrap = document.createElement('div');
              tagWrap.className = 'joe-mlist-tags';
              detail.tags.slice(0, 6).forEach(function (t) {
                var c = document.createElement('span');
                c.className = 'joe-mlist-tag';
                c.textContent = t;
                tagWrap.appendChild(c);
              });
              ph.querySelector('.joe-mlist-meta').appendChild(tagWrap);
            }
            /* 歌单介绍：有简介显示简介；无简介但有创建者/播放量则显示归属信息 */
            if (detail.description) {
              var dEl = document.createElement('div');
              dEl.className = 'joe-mlist-desc';
              dEl.textContent = detail.description;
              ph.insertBefore(dEl, songsBox);
            } else if (detail.creator || detail.plays) {
              var dEl2 = document.createElement('div');
              dEl2.className = 'joe-mlist-desc joe-mlist-desc-meta';
              var dTxt = '';
              if (detail.creator) { dTxt += 'by ' + detail.creator; }
              if (detail.plays > 0) { dTxt += (dTxt ? ' · ' : '') + fmtCount(detail.plays) + ' 人听过'; }
              dEl2.textContent = dTxt;
              ph.insertBefore(dEl2, songsBox);
            }
          } else {
            titleEl.textContent = '歌单 · ' + list.length + ' 首';
            if (list[0]) {
              var fp = list[0].pic_api || list[0].pic || list[0].pic_url || '';
              if (fp) { headCover.innerHTML = '<img src="' + escHtml(joeCover(fp)) + '" alt="" loading="lazy">'; }
            }
          }
          songsBox.innerHTML = '';
          list.forEach(function (d, n) {
            var row = document.createElement('div');
            row.className = 'joe-mlist-song';
            var p = d.pic_api || d.pic || d.pic_url || '';
            var dur = d.duration ? fmtDur(d.duration) : '';
            row.innerHTML = (p
                ? '<span class="joe-mlist-cover-sm"><img src="' + escHtml(joeCover(p)) + '" alt="" loading="lazy"></span>'
                : '<span class="joe-mlist-idx">' + (n + 1) + '</span>')
              + '<span class="joe-mlist-song-meta"><span class="joe-mlist-name">' + escHtml(d.name || d.title || '未知歌曲') + '</span>'
              + '<span class="joe-mlist-artist">' + escHtml(Array.isArray(d.artist) ? d.artist.join('/') : (d.artist || '')) + '</span></span>'
              + (dur ? '<span class="joe-mlist-dur">' + dur + '</span>' : '')
              + '<span class="joe-mlist-play-ic"><i class="ri-play-fill"></i></span>';
            row.addEventListener('click', function (e) {
              e.preventDefault(); e.stopPropagation();
              if (!window.TriPlayer) { tip('播放器未启用'); return; }
              window.TriPlayer.playList(list, n, server);
            });
            songsBox.appendChild(row);
          });
        }).catch(function () { songsBox.innerHTML = '<div class="joe-mlist-loading">歌单获取失败</div>'; });
        playAll.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          if (!window.TriPlayer) { tip('播放器未启用'); return; }
          if (list.length) { window.TriPlayer.playList(list, 0, server); }
        });
        return;
      }
      /* 单曲 / MP3 卡片：封面 + 歌名 + 歌手 + 播放，点击联动本地播放器 */
      ph.className = 'joe-music-card';
      var isMp3 = tag === 'joe-mp3';
      var mp3Name = el.getAttribute('name') || '音频';
      var mp3Url = el.getAttribute('url') || '';
      var mp3Cover = el.getAttribute('cover') || '';
      ph.innerHTML = '<div class="joe-music-cover"><i class="ri-music-2-fill"></i></div>'
        + '<div class="joe-music-meta"><span class="joe-music-title">' + escHtml(isMp3 ? mp3Name : '音乐加载中…') + '</span>'
        + '<span class="joe-music-artist">' + escHtml(isMp3 ? '外部音频' : platformName(server)) + '</span></div>'
        + '<button type="button" class="joe-music-play" aria-label="播放"><i class="ri-play-fill"></i></button>';
      el.parentNode.replaceChild(ph, el);
      var cover2 = ph.querySelector('.joe-music-cover');
      var titleEl2 = ph.querySelector('.joe-music-title');
      var artistEl = ph.querySelector('.joe-music-artist');
      if (isMp3) {
        if (mp3Cover) { cover2.innerHTML = '<img src="' + escHtml(joeCover(mp3Cover)) + '" alt="" loading="lazy">'; }
        ph.addEventListener('click', function () {
          if (!window.TriPlayer) { tip('播放器未启用'); return; }
          window.TriPlayer.playSong({
            server: server, url_id: '', title: mp3Name, artist: '外部音频',
            pic: joeCover(mp3Cover) || '', pic_api: joeCover(mp3Cover) || '', lrc: '', src: mp3Url
          });
        });
        return;
      }
      if (!id) { titleEl2.textContent = '音乐 ID 未填写'; return; }
      /* 异步拉取单曲信息填充卡片 */
      fetch(meting + '?server=' + encodeURIComponent(server) + '&type=song&id=' + encodeURIComponent(id) + '&_=' + Date.now(), {cache: 'no-store'})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.title) { throw new Error('no song'); }
          titleEl2.textContent = d.title;
          artistEl.textContent = d.artist || platformName(server);
          if (d.pic) { cover2.innerHTML = '<img src="' + escHtml(joeCover(d.pic)) + '" alt="" loading="lazy">'; }
          ph._song = d;
        })
        .catch(function () { titleEl2.textContent = '音乐信息获取失败'; });
      ph.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('.joe-music-play')) { e.preventDefault(); e.stopPropagation(); }
        if (!window.TriPlayer) { tip('播放器未启用'); return; }
        var d = ph._song || {};
        window.TriPlayer.playSong({
          server: server,
          url_id: d.url_id || d.id || id,
          title: d.title || titleEl2.textContent || '未知歌曲',
          artist: d.artist || artistEl.textContent || '',
          pic: d.pic || (meting + '?server=' + encodeURIComponent(server) + '&type=pic&id=' + encodeURIComponent(id)),
          pic_api: d.pic || '',
          lrc: meting + '?server=' + encodeURIComponent(server) + '&type=lrc&id=' + encodeURIComponent(d.url_id || d.id || id),
          src: d.url || ''
        });
      });
    });

    /* 多彩按钮 */
    scope.querySelectorAll('joe-abtn').forEach(function (el) {
      var a = { icon: el.getAttribute('icon') || 'ri-download-2-fill', color: el.getAttribute('color') || '#ff6800', href: el.getAttribute('href') || '#', radius: el.getAttribute('radius') || '17.5px', content: el.getAttribute('content') || '多彩按钮' };
      el.outerHTML = '<a class="joe_abtn" style="background:' + a.color + ';border-radius:' + a.radius + '" href="' + escHtml(a.href) + '" target="_blank" rel="noopener noreferrer nofollow">'
        + '<span class="joe_abtn__icon"><i class="' + escHtml(a.icon) + '"></i></span>'
        + '<span class="joe_abtn__content">' + escHtml(a.content) + '</span></a>';
    });

    /* 标签按钮 */
    scope.querySelectorAll('joe-anote').forEach(function (el) {
      var type = ['secondary', 'success', 'warning', 'error', 'info'].indexOf(el.getAttribute('type')) !== -1 ? el.getAttribute('type') : 'secondary';
      var a = { icon: el.getAttribute('icon') || 'ri-download-2-fill', href: el.getAttribute('href') || '#', type: type, content: el.getAttribute('content') || '标签按钮' };
      el.outerHTML = '<a class="joe_anote ' + a.type + '" href="' + escHtml(a.href) + '" target="_blank" rel="noopener noreferrer nofollow">'
        + '<span class="joe_anote__icon"><i class="' + escHtml(a.icon) + '"></i></span>'
        + '<span class="joe_anote__content">' + escHtml(a.content) + '</span></a>';
    });

    /* 点状分割线 */
    scope.querySelectorAll('joe-dotted').forEach(function (el) {
      var s = el.getAttribute('startColor') || '#ff6c6c';
      var e = el.getAttribute('endColor') || '#1989fa';
      el.outerHTML = '<span class="joe_dotted" style="background-image:repeating-linear-gradient(-45deg,' + s + ' 0,' + s + ' 20%,transparent 0,transparent 25%,' + e + ' 0,' + e + ' 45%,transparent 0,transparent 50%)"></span>';
    });

    /* 网盘 */
    scope.querySelectorAll('joe-cloud').forEach(function (el) {
      var map = { '360': '360网盘', bd: '百度网盘', ty: '天翼网盘', ct: '城通网盘', wy: '微云网盘', github: 'GitHub仓库', lz: '蓝奏云', default: '默认网盘' };
      var t = el.getAttribute('type') || 'default';
      var title = el.getAttribute('title') || '默认标题';
      var url = el.getAttribute('url') || '#';
      var pw = el.getAttribute('password') || '';
      el.outerHTML = '<div class="joe_cloud"><span class="joe_cloud__logo _' + escHtml(t) + '"></span>'
        + '<div class="joe_cloud__describe"><div class="joe_cloud__describe-title">' + escHtml(title) + '</div>'
        + '<div class="joe_cloud__describe-sub">' + escHtml(map[t] || '网盘') + (pw ? '　提取码：' + escHtml(pw) : '') + '</div></div>'
        + '<a class="joe_cloud__btn" href="' + escHtml(url) + '" target="_blank" rel="noopener noreferrer nofollow">打开</a></div>';
    });

    /* 消息提示 */
    scope.querySelectorAll('joe-message').forEach(function (el) {
      var type = ['success', 'info', 'warning', 'error'].indexOf(el.getAttribute('type')) !== -1 ? el.getAttribute('type') : 'info';
      var c = el.getAttribute('content') || '消息内容';
      el.outerHTML = '<div class="joe_message ' + type + '"><i class="ri-' + (type === 'success' ? 'checkbox-circle-fill' : type === 'warning' ? 'alert-fill' : type === 'error' ? 'close-circle-fill' : 'information-fill') + '"></i><span>' + escHtml(c) + '</span></div>';
    });

    /* 进度条 */
    scope.querySelectorAll('joe-progress').forEach(function (el) {
      var pct = /^\d{1,3}%$/.test(el.getAttribute('percentage') || '') ? el.getAttribute('percentage') : '50%';
      var c = el.getAttribute('color') || '#ff6c6c';
      el.outerHTML = '<span class="joe_progress"><span class="joe_progress__strip"><span class="joe_progress__strip-percent" style="width:' + pct + ';background:' + c + '"></span></span><span class="joe_progress__percentage">' + pct + '</span></span>';
    });

    /* 复制 */
    scope.querySelectorAll('joe-copy').forEach(function (el) {
      var st = el.getAttribute('showText') || '点击复制';
      var ct = el.getAttribute('copyText') || '';
      var b = document.createElement('span');
      b.className = 'joe_copy';
      b.textContent = st;
      b.addEventListener('click', function () {
        var done = function () { b.textContent = '已复制'; setTimeout(function () { b.textContent = st; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(ct).then(done, function () { fallbackCopy(ct, done); });
        } else { fallbackCopy(ct, done); }
      });
      el.parentNode.replaceChild(b, el);
    });
    function fallbackCopy(text, cb) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      cb && cb();
    }

    /* 隐藏内容 */
    scope.querySelectorAll('joe-hide').forEach(function (el) {
      var b = document.createElement('div');
      b.className = 'joe_hide';
      b.innerHTML = '此处内容作者设置了 <span class="joe_hide__button">回复</span> 可见';
      b.querySelector('.joe_hide__button').addEventListener('click', function () {
        var c = document.querySelector('.post-comments, .tri3-comment-box, .comments-area');
        if (c) { c.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
      el.parentNode.replaceChild(b, el);
    });

    /* Tabs */
    scope.querySelectorAll('joe-tabs').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var inner = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      var navs = '', bodys = '', idx = 0;
      inner.replace(/\{tabs-pane([^}]*)\}([\s\S]*?)\{\/tabs-pane\}/g, function (m, attrs, content) {
        var a = parseJoeAttrs(attrs);
        var label = a.label || ('标签 ' + (idx + 1));
        navs += '<div class="joe_tabs__head-item' + (idx === 0 ? ' active' : '') + '" data-i="' + idx + '">' + escHtml(label) + '</div>';
        bodys += '<div class="joe_tabs__body-item' + (idx === 0 ? ' is-show' : '') + '" data-i="' + idx + '">' + cleanJoeTemp(content) + '</div>';
        idx++;
      });
      if (!idx) { return; }
      el.outerHTML = '<div class="joe_tabs"><div class="joe_tabs__head">' + navs + '</div><div class="joe_tabs__body">' + bodys + '</div></div>';
    });
    scope.querySelectorAll('.joe_tabs').forEach(function (tabs) {
      if (tabs.getAttribute('data-joe-bound')) { return; }
      tabs.setAttribute('data-joe-bound', '1');
      var head = tabs.querySelector('.joe_tabs__head');
      head.addEventListener('click', function (e) {
        var it = e.target.closest ? e.target.closest('.joe_tabs__head-item') : null;
        if (!it) { return; }
        var i = it.getAttribute('data-i');
        tabs.querySelectorAll('.joe_tabs__head-item').forEach(function (x) { x.classList.toggle('active', x === it); });
        tabs.querySelectorAll('.joe_tabs__body-item').forEach(function (x) { x.classList.toggle('is-show', x.getAttribute('data-i') === i); });
      });
    });

    /* 折叠 */
    scope.querySelectorAll('joe-collapse').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var inner = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      var items = '';
      inner.replace(/\{collapse-item([^}]*)\}([\s\S]*?)\{\/collapse-item\}/g, function (m, attrs, content) {
        var a = parseJoeAttrs(attrs);
        items += '<div class="joe_collapse__item"><div class="joe_collapse__item-head"><span class="joe_collapse__item-label">' + escHtml(a.title || '展开查看') + '</span><i class="ri-arrow-down-s-line joe_collapse__item-icon"></i></div>'
          + '<div class="joe_collapse__item-body"><div class="joe_collapse__item-body-inner">' + cleanJoeTemp(content) + '</div></div></div>';
      });
      if (!items) { return; }
      el.outerHTML = '<div class="joe_collapse">' + items + '</div>';
    });
    scope.querySelectorAll('.joe_collapse__item-head').forEach(function (hd) {
      if (hd.getAttribute('data-joe-bound')) { return; }
      hd.setAttribute('data-joe-bound', '1');
      hd.addEventListener('click', function () {
        var item = hd.parentNode;
        var open = item.classList.contains('is-open');
        item.classList.toggle('is-open', !open);
      });
    });

    /* 时间线 */
    scope.querySelectorAll('joe-timeline').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var inner = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      var items = '';
      inner.replace(/\{timeline-item([^}]*)\}([\s\S]*?)\{\/timeline-item\}/g, function (m, attrs, content) {
        var a = parseJoeAttrs(attrs);
        var c = a.color || '#19be6b';
        items += '<div class="joe_timeline__item"><div class="joe_timeline__item-tail"></div>'
          + '<div class="joe_timeline__item-circle" style="border-color:' + c + '"></div>'
          + '<div class="joe_timeline__item-content">' + cleanJoeTemp(content) + '</div></div>';
      });
      if (!items) { return; }
      el.outerHTML = '<div class="joe_timeline">' + items + '</div>';
    });

    /* 卡片列表 */
    scope.querySelectorAll('joe-card-list').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var inner = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      var items = '';
      inner.replace(/\{card-list-item([^}]*)\}([\s\S]*?)\{\/card-list-item\}/g, function (m, attrs, content) {
        items += '<div class="joe_card__list-item">' + cleanJoeTemp(content) + '</div>';
      });
      if (!items) { return; }
      el.outerHTML = '<div class="joe_card__list">' + items + '</div>';
    });

    /* 多列网格 */
    scope.querySelectorAll('joe-gird').forEach(function (el) {
      var col = el.getAttribute('column') || '3';
      var gap = el.getAttribute('gap') || '15';
      var tmp = el.querySelector('._temp');
      var inner = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      var items = '';
      inner.replace(/\{gird-item\}([\s\S]*?)\{\/gird-item\}/g, function (m, content) {
        items += '<div class="joe_gird__item">' + cleanJoeTemp(content) + '</div>';
      });
      if (!items) { return; }
      el.outerHTML = '<div class="joe_gird" style="--joe-gird-col:' + col + ';--joe-gird-gap:' + gap + 'px">' + items + '</div>';
    });

    /* 卡片 / 标注 / 警示 / 描述卡 */
    scope.querySelectorAll('joe-card-default').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var label = el.getAttribute('label') || '卡片标题';
      var content = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      el.outerHTML = '<div class="joe_card__default"><div class="joe_card__default-title">' + escHtml(label) + '</div><div class="joe_card__default-content">' + content + '</div></div>';
    });
    scope.querySelectorAll('joe-callout').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var c = el.getAttribute('color') || '#f0ad4e';
      var content = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      el.outerHTML = '<div class="joe_callout" style="border-left-color:' + c + '">' + content + '</div>';
    });
    scope.querySelectorAll('joe-alert').forEach(function (el) {
      var type = ['success', 'info', 'warning', 'error'].indexOf(el.getAttribute('type')) !== -1 ? el.getAttribute('type') : 'info';
      var tmp = el.querySelector('._temp');
      var content = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      el.outerHTML = '<div class="joe_alert ' + type + '">' + content + '</div>';
    });
    scope.querySelectorAll('joe-card-describe').forEach(function (el) {
      var tmp = el.querySelector('._temp');
      var title = el.getAttribute('title') || '卡片描述';
      var content = tmp ? cleanJoeTemp(tmp.innerHTML) : '';
      el.outerHTML = '<div class="joe_card__describe"><div class="joe_card__describe-title">' + escHtml(title) + '</div><div class="joe_card__describe-content">' + content + '</div></div>';
    });

    /* B 站视频 */
    scope.querySelectorAll('joe-bilibili').forEach(function (el) {
      var bvid = el.getAttribute('bvid');
      if (!bvid) { el.outerHTML = '<p class="joe-err">Bvid 未填写</p>'; return; }
      var page = el.getAttribute('page') || '1';
      el.outerHTML = '<div class="joe-video"><iframe class="joe-video-el" src="//player.bilibili.com/player.html?bvid=' + encodeURIComponent(bvid) + '&page=' + encodeURIComponent(page) + '" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true"></iframe></div>';
    });

    /* 视频（dplayer） */
    scope.querySelectorAll('joe-dplayer').forEach(function (el) {
      var u = el.getAttribute('url');
      if (!u) { el.outerHTML = '<p class="joe-err">视频地址未填写</p>'; return; }
      el.outerHTML = '<div class="joe-video"><video class="joe-video-el" src="' + escHtml(u) + '" controls preload="metadata" playsinline webkit-playsinline x5-playsinline></video></div>';
    });
  }

window.Tri3Joe = {
    render: renderAll,
    renderShortcodes: render,
    bindTabs: bindTabs,
    joeRender: joeRender
  };

  function boot() {
    renderAll(document);
    document.dispatchEvent(new CustomEvent('tri3:content-rendered'));
  }
  /* 首页列表/Swup 替换后，音乐占位卡可能在首次扫描之后才插入。 */
  ['tri3:render', 'tri3:homelist'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      window.setTimeout(function () { renderAll(document); }, 0);
    });
  });
})();
