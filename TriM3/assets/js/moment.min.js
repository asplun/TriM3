/* TriM3 说说交互（发布 / 删除 / 点赞 / 置顶 / 音乐卡片 / 视频 / 实况图）—— Pjax 适配版
 *
 * 图集增强为幂等 TriMomentInit()（Tri3Init 每次 Pjax 后调用）
 * 发布 / 删除 / 点赞 / 置顶 / 媒体播放等按钮每次页面渲染时用委托绑定
 */
(function () {
  if (window.__triMomentInit) { return; } window.__triMomentInit = true;
  /* Swup-safe: global dialog close delegation, bound once */
  if (!window.__triDialogMaskBound) {
    window.__triDialogMaskBound = 1;
    document.addEventListener('click', function(e) {
      var t = e.target;
      if (!t || !t.classList) return;
      /* If clicked element IS a mask background, close ALL visible masks */
      if (t.classList.contains('tri-dialog-mask') ||
          t.classList.contains('tri-cmt-mask') ||
          t.classList.contains('tri-edit-mask')) {
        document.querySelectorAll('.tri-dialog-mask:not([hidden]), .tri-cmt-mask:not([hidden]), .tri-edit-mask:not([hidden])').forEach(function(m) {
          m.hidden = true;
        });
        return;
      }
      /* Close button delegation */
      if (t.closest) {
        var cb = t.closest('.tri-cmt-close, .tri-edit-close, .tri-say-close');
        if (cb) {
          var mask = cb.closest('.tri-cmt-mask, .tri-edit-mask, .tri-dialog-mask');
          if (mask) mask.hidden = true;
          e.stopPropagation();
        }
      }
    });
    /* ROOT CAUSE FIX: Swup caches page body with masks. Remove masks BEFORE caching
       so cached pages don't carry old hidden mask elements. */
    document.addEventListener('swup:visit:start', function() {
      var masks = document.querySelectorAll('.tri-cmt-mask, .tri-edit-mask, .tri-dialog-mask');
      for (var i = 0; i < masks.length; i++) { masks[i].remove(); }
      document.body.style.overflow = '';
    });
    /* Also clean on before-render for safety */
    document.addEventListener('swup:content:replace', function() {
      var masks = document.querySelectorAll('.tri-cmt-mask, .tri-edit-mask, .tri-dialog-mask');
      for (var i = 0; i < masks.length; i++) { masks[i].remove(); }
      document.body.style.overflow = '';
    });
  }
  'use strict';

  function getCfg() {
    return window.TRI3_MOMENT || {};
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* HTTPS 站点强制升级图片/封面链接（网易云等封面源可能返回 http://，避免 Mixed Content 拦截） */
  function tri3Cover(u) {
    return u ? String(u).replace(/^http:\/\//i, 'https://') : u;
  }

    function toast(msg) { if (window.TriMsg) { window.TriMsg.show(msg); } }
  /* 图床上传：多图上传 + 图片列表 + 点击插入编辑器光标处
   * codeFn(url) 决定插入的代码格式（说说为 [img]URL[/img]，文章为 Markdown 等） */
  function triUploadPicker(btn, taId, codeFn) {
    if (!btn) { return; }
    var box = null;
    function ensureBox() {
      if (!box || !box.isConnected) {
        box = document.createElement('div');
        box.className = 'tri-upload-list';
        if (btn.isConnected) { btn.parentNode.insertBefore(box, btn.nextSibling); }
        else { document.body.appendChild(box); }
      }
      return box;
    }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var cfg = window.TRI3_UPLOAD || {};
      if (!cfg.enabled || !cfg.api) { toast('后台未配置图床 API'); return; }
      var inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = 'image/*';
      inp.multiple = true;
      inp.onchange = function () {
        inp.remove();
        var files = Array.prototype.slice.call(inp.files || []);
        if (!files.length) { return; }
        ensureBox();
        var i = 0;
        (function next() {
          if (i >= files.length) { return; }
          var f = files[i];
          var idx = i + 1;
          i += 1;
          var fd = new FormData();
          fd.append('file', f);
          var it = document.createElement('span');
          it.className = 'tri-upload-item';
          it.innerHTML = '<i class="ri-loader-4-line tri-upload-spin"></i>';
          it.title = f.name;
          box.appendChild(it);
          toast('上传中 ' + idx + '/' + files.length);
          fetch(cfg.api, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (j && j.ok) {
                var url = (j.urls && j.urls[idx - 1]) || j.url;
                it.innerHTML = '<img src="' + esc(url) + '" alt=""><i class="ri-close-circle-fill tri-upload-del"></i>';
                it.className = 'tri-upload-item is-done';
                it.addEventListener('click', function (ev) {
                  if (ev.target.closest('.tri-upload-del')) { it.remove(); return; }
                  var ta = document.getElementById(taId);
                  if (ta) {
                    insertAtCursor(ta, codeFn(url));
                    toast('已插入编辑器');
                  }
                });
              } else {
                it.remove();
                toast((j && j.msg) || '上传失败');
              }
              next();
            })
            .catch(function () { it.remove(); toast('上传失败，请检查图床配置'); next(); });
        })();
      };
      document.body.appendChild(inp);
      inp.click();
    });
  }
  function triMomentImgCode(url) { return '[img]' + url + '[/img]'; }
  function triArticleImgCode(url) { return '![](' + url + ')'; }

  /* 实况图片本地上传：上传图床成功后把 URL 直接填入目标输入框（无图床时后端自动存本机） */
  function triLiveUploadPicker(btn, inputId) {
    if (!btn) { return; }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var cfg = window.TRI3_UPLOAD || {};
      if (!cfg.enabled || !cfg.api) { toast('后台未配置图床 API'); return; }
      var inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = 'image/*';
      inp.onchange = function () {
        inp.remove();
        var f = (inp.files || [])[0];
        if (!f) { return; }
        var fd = new FormData();
        fd.append('file', f);
        toast('上传中…');
        fetch(cfg.api, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j && j.ok) {
              var url = (j.urls && j.urls[0]) || j.url;
              var el = document.getElementById(inputId);
              if (el && url) { el.value = url; toast('已填入实况图片'); }
              else if (url) { toast('上传成功'); }
              else { toast('上传失败'); }
            } else {
              toast((j && j.msg) || '上传失败');
            }
          })
          .catch(function () { toast('上传失败，请检查图床配置'); });
      };
    });
  }

  /* 实况视频一键上传：选视频 → 本地抽首帧做封面 → 分别上传封面与视频 → 自动填入两个输入框。
   * 体验对齐 iPhone 实况照片：用户只需选一次视频文件，系统自动补关键帧。 */
  function triLiveAutoPicker(btn, imgId, vidId) {
    if (!btn) { return; }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var cfg = window.TRI3_UPLOAD || {};
      if (!cfg.enabled || !cfg.api) { toast('后台未配置图床 API'); return; }
      var inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = 'video/*,.mov,.mp4,.m4v,.3gp';
      inp.onchange = function () {
        inp.remove();
        var f = (inp.files || [])[0];
        if (!f) { return; }
        toast('正在解析视频关键帧…');
        var url = URL.createObjectURL(f);
        var v = document.createElement('video');
        v.muted = true;
        v.playsInline = true;
        v.preload = 'metadata';
        v.onerror = function () {
          URL.revokeObjectURL(url);
          toast('无法解析该视频：实况照片请先在手机相册导出为视频（.mov/.mp4）再上传');
        };
        v.onloadedmetadata = function () {
          try {
            v.currentTime = Math.min(0.3, (v.duration || 1) * 0.1 || 0.1);
          } catch (err) {
            URL.revokeObjectURL(url);
            toast('无法解析该视频，请直接填写 URL');
          }
        };
        v.onseeked = function () {
          try {
            var c = document.createElement('canvas');
            c.width = v.videoWidth || 1280;
            c.height = v.videoHeight || 720;
            c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
            c.toBlob(function (blob) {
              URL.revokeObjectURL(url);
              if (!blob) { toast('关键帧提取失败，请直接填写图片 URL'); return; }
              uploadLiveCoverAndVideo(cfg, blob, f, imgId, vidId);
            }, 'image/jpeg', 0.82);
          } catch (err) {
            URL.revokeObjectURL(url);
            toast('关键帧提取失败，请直接填写 URL');
          }
        };
        v.src = url;
        v.load();
      };
      document.body.appendChild(inp);
      inp.click();
    });
  }

  /* 上传实况封面 + 视频（串行，任一失败即停） */
  function uploadLiveCoverAndVideo(cfg, coverBlob, videoFile, imgId, vidId) {
    var up = function (fd, cb) {
      fetch(cfg.api, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) { cb((j.urls && j.urls[0]) || j.url, null); }
          else { cb(null, (j && j.msg) || '上传失败'); }
        })
        .catch(function () { cb(null, '上传失败，请检查图床配置'); });
    };
    var fd1 = new FormData();
    fd1.append('file', coverBlob, 'live-cover.jpg');
    toast('上传封面…');
    up(fd1, function (imgUrl, err) {
      if (err || !imgUrl) { toast(err || '上传失败'); return; }
      var elImg = document.getElementById(imgId);
      if (elImg) { elImg.value = imgUrl; }
      var fd2 = new FormData();
      fd2.append('file', videoFile);
      toast('上传视频…');
      up(fd2, function (vidUrl, err2) {
        if (err2 || !vidUrl) { toast(err2 || '上传失败'); return; }
        var elVid = document.getElementById(vidId);
        if (elVid) { elVid.value = vidUrl; }
        toast('实况已就绪：关键帧 + 视频已自动填入');
      });
    });
  }

  function post(data, cb) {
    var body = Object.keys(data).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(data[k] == null ? '' : data[k]);
    }).join('&');
    fetch(getCfg().api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.ok) {
          toast((res && res.msg) || '操作失败');
          return;
        }
        cb && cb(res);
      })
      .catch(function () { toast('网络错误'); });
  }

  /* ---------- Meting API 地址（与播放器共用配置） ---------- */
  function metingApi() {
    var p = window.TRI3_PLAYER || {};
    var api = (p.meting && p.meting.api) || '';
    return String(api || '').replace(/\/+$/, '');
  }

  /* ---------- 媒体字段收集（prefix: composer=moment- / 弹窗=tri-say-） ---------- */
  function collectMedia(prefix) {
    var m = {};
    var srv = document.getElementById(prefix + 'music-server');
    var id = document.getElementById(prefix + 'music-id');
    if (srv && id && id.value.trim() !== '') {
      m.music = {
        server: srv.value,
        id: id.value.trim(),
        title: (document.getElementById(prefix + 'music-title') || {}).value || '',
        artist: (document.getElementById(prefix + 'music-artist') || {}).value || '',
        pic: (document.getElementById(prefix + 'music-pic') || {}).value || ''
      };
    }
    var vs = document.getElementById(prefix + 'video-src');
    if (vs && vs.value.trim() !== '') {
      m.video = {
        src: vs.value.trim(),
        poster: (document.getElementById(prefix + 'video-poster') || {}).value || ''
      };
    }
    var li = document.getElementById(prefix + 'live-img');
    var lv = document.getElementById(prefix + 'live-video');
    if (li && (li.value.trim() !== '' || (lv && lv.value.trim() !== ''))) {
      m.live = { img: li.value.trim(), video: (lv && lv.value) ? lv.value.trim() : '' };
    }
    var bv = document.getElementById(prefix + 'bili-bvid');
    if (bv && bv.value.trim() !== '') {
      var bvid = extractBvid(bv.value);
      if (bvid) { m.bili = { bvid: bvid }; }
    }
    return m;
  }

  /* ---------- 发布（composer / 弹窗共用） ---------- */
  function doPublish(prefix, submitBtn) {
    if (!submitBtn || submitBtn.disabled) { return; }
    var content = document.getElementById(prefix + 'content');
    if (!content) { return; }
    var c = content.value.trim();
    if (!c) { toast('内容不能为空'); return; }
    submitBtn.disabled = true;
    var originText = submitBtn.textContent || submitBtn.innerHTML;
    submitBtn.textContent = '发布中…';
    var images = document.getElementById(prefix + 'images');
    var priv = document.getElementById(prefix + 'private');
    var media = collectMedia(prefix);
    /* 图文混排优先：正文已含某媒体标记时，忽略独立媒体字段，避免重复显示 */
    var hasTag = {
      img: /\[img\]/.test(c),
      music: /\[music\]/.test(c),
      video: /\[video\]/.test(c),
      live: /\[live\]/.test(c),
      bili: /\[bili\]/.test(c)
    };
    var data = {
      do: 'add',
      content: c,
      images: (!hasTag.img && images) ? images.value.trim() : '',
      private: priv && priv.checked ? '1' : '',
      cat: (document.getElementById(prefix + 'cat') || {}).value || ''
    };
    if (media.music && !hasTag.music) {
      data.music_server = media.music.server;
      data.music_id = media.music.id;
      data.music_title = media.music.title;
      data.music_artist = media.music.artist;
      data.music_pic = media.music.pic;
    }
    if (media.video && !hasTag.video) {
      data.video_src = media.video.src;
      data.video_poster = media.video.poster;
    }
    if (media.live && !hasTag.live) {
      data.live_img = media.live.img;
      data.live_video = media.live.video;
    }
    if (media.bili && !hasTag.bili) {
      data.bili_bvid = media.bili.bvid;
    }
    post(data, function () {
      toast('发布成功');
      submitBtn.disabled = false;
      submitBtn.textContent = originText;
      var list = document.getElementById('tri3-home-list');
      if (list && window.Tri3HomeList && window.Tri3HomeList.getState) {
        var st = window.Tri3HomeList.getState();
        if (st && st.type === 'moment') {
          window.Tri3HomeList.load('', st.page || 1, false, false, 'moment', '');
          return;
        }
      }
      window.location.reload();
    });
  }

  /* ---------- 音乐预览：调 meting song 端点拉取元数据 ---------- */
  function previewMusic(prefix) {
    var api = metingApi();
    var srvEl = document.getElementById(prefix + 'music-server');
    var idEl = document.getElementById(prefix + 'music-id');
    var pvEl = document.getElementById(prefix + 'music-pv');
    if (!api || !srvEl || !idEl || !pvEl) { return; }
    var srv = srvEl.value === 'qq' ? 'tencent' : srvEl.value;
    var id = extractSongId(idEl.value);
    if (!id) { toast('歌曲 ID 不正确，可粘贴分享链接'); return; }
    pvEl.innerHTML = '<div class="mc-music-pv-loading">拉取中…</div>';
    pvEl.style.display = 'block';
    fetch(api + '?server=' + encodeURIComponent(srv) + '&type=song&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.title) { throw new Error('no song'); }
        var t = document.getElementById(prefix + 'music-title');
        var a = document.getElementById(prefix + 'music-artist');
        var p = document.getElementById(prefix + 'music-pic');
        if (t) { t.value = d.title || ''; }
        if (a) { a.value = d.artist || ''; }
        if (p) { p.value = tri3Cover(d.pic) || ''; }
        var pic = d.pic ? '<img src="' + esc(tri3Cover(d.pic)) + '" alt="">' : '<i class="ri-music-2-fill"></i>';
        pvEl.innerHTML =
          '<div class="mc-music-pv-card">'
          + '<div class="mc-music-pv-cover">' + pic + '</div>'
          + '<div class="mc-music-pv-meta"><span class="mc-music-pv-title">' + esc(d.title || '') + '</span>'
          + '<span class="mc-music-pv-artist">' + esc(d.artist || '') + '</span></div>'
          + '<button type="button" class="mc-music-pv-play" aria-label="试听"><i class="ri-play-fill"></i></button>'
          + '</div>';
        pvEl._song = d;
        pvEl._srv = srv;
      })
      .catch(function () {
        pvEl.innerHTML = '<div class="mc-music-pv-loading">未获取到歌曲信息，可直接发布（播放时再解析）</div>';
        var t = document.getElementById(prefix + 'music-title');
        var a = document.getElementById(prefix + 'music-artist');
        if (t && !t.value) { t.value = ''; }
        if (a && !a.value) { a.value = ''; }
      });
  }

  /* ---------- 音乐卡片试听（发布框预览内） ---------- */
  function previewTrial(btn) {
    var card = btn.closest('.mc-music-pv-card');
    if (!card) { return; }
    var box = card.closest('.mc-music-pv');
    if (!box || !box._song) { return; }
    var api = metingApi();
    if (!window.TriPlayer || !api) { toast('播放器未就绪'); return; }
    var song = box._song;
    var srv = box._srv || 'netease';
    window.TriPlayer.playSong({
      server: srv,
      url_id: song.url_id || song.id || '',
      title: song.title || '',
      artist: song.artist || '',
      pic_api: tri3Cover(song.pic) || '',
      pic: tri3Cover(song.pic) || '',
      lrc: api + '?server=' + encodeURIComponent(srv) + '&type=lrc&id=' + encodeURIComponent(song.url_id || song.id || ''),
      src: song.url || ''
    });
  }

  /* ---------- 每条说说：评论弹窗（懒创建 DOM + 动态 cid，全站可用） ---------- */
  var _cmtInited = false;
  var _cmtOpener = null;
  function initCommentDialog() {
    if (_cmtInited) { return _cmtOpener; }
    _cmtInited = true;
    var CFG = getCfg();
    var LOGIN = window.TRI3_LOGIN || {};
    var api = (CFG.api || '').replace(/moment-api\.php[^/]*$/, 'comment-api.php');
    if (!api) { return; }
    var mask, listEl, countEl, textEl, replyBar, replyName, replyCancel, guestEl, sendBtn;
    var parentVal = 0, curCid = 0;

    function ensure() {
      var old = document.querySelectorAll(".tri-cmt-mask"); for (var k = 0; k < old.length; k++) { old[k].remove(); } mask = null;
      var d = document.createElement('div');
      d.className = 'tri-cmt-mask';
      d.id = 'tri-cmt-mask';
      d.hidden = true;
      d.innerHTML =
        '<div class="tri-cmt-dialog" role="dialog" aria-modal="true" aria-label="评论区">' +
          '<div class="tri-cmt-head">' +
            '<h3 class="tri-cmt-title"><i class="ri-chat-3-line"></i>评论区</h3>' +
            '<span class="tri-cmt-count" id="tri-cmt-count">0</span>' +
            '<button type="button" class="tri-cmt-close" id="tri-cmt-close" aria-label="关闭"><i class="ri-close-line"></i></button>' +
          '</div>' +
          '<div class="tri-cmt-body">' +
            '<div class="tri-cmt-list" id="tri-cmt-list"><div class="comment-empty"><i class="ri-loader-4-line ri-spin"></i><p>加载中…</p></div></div>' +
          '</div>' +
          '<div class="tri-cmt-form">' +
            '<input type="text" id="tri-cmt-hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none">' +
            '<div class="tri-cmt-guest" id="tri-cmt-guest" style="display:none;">' +
              '<input type="text" id="tri-cmt-author" placeholder="昵称" maxlength="50" autocomplete="nickname">' +
              '<input type="email" id="tri-cmt-mail" placeholder="邮箱" autocomplete="email">' +
              '<input type="url" id="tri-cmt-url" placeholder="网站（可选）" autocomplete="url">' +
              '<input type="text" id="tri-cmt-qq" placeholder="QQ 号（可选）" maxlength="20" autocomplete="off">' +
            '</div>' +
            '<div class="reply-target-bar" id="tri-cmt-reply-bar" hidden>' +
              '<span class="reply-target-label"><i class="ri-corner-down-right-line"></i> 回复给 <b id="tri-cmt-reply-name"></b></span>' +
              '<button type="button" class="reply-target-cancel" id="tri-cmt-reply-cancel">取消回复</button>' +
            '</div>' +
            '<div class="chat-input-bar">' +
              '<button type="button" class="chat-emoji-btn" id="tri-cmt-emoji-toggle" title="表情" aria-label="表情"><i class="ri-emotion-line"></i></button>' +
              '<button type="button" class="chat-emoji-btn" id="tri-cmt-img" title="插入图片" aria-label="插入图片"><i class="ri-image-add-line"></i></button>' +
              '<textarea rows="1" id="tri-cmt-text" class="textarea" placeholder="写下你的看法…"></textarea>' +
              '<button type="button" class="comment-submit" id="tri-cmt-send"><i class="ri-send-plane-2-line"></i>发送</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(d);
      mask = d;
      listEl = document.getElementById('tri-cmt-list');
      countEl = document.getElementById('tri-cmt-count');
      textEl = document.getElementById('tri-cmt-text');
      replyBar = document.getElementById('tri-cmt-reply-bar');
      replyName = document.getElementById('tri-cmt-reply-name');
      replyCancel = document.getElementById('tri-cmt-reply-cancel');
      guestEl = document.getElementById('tri-cmt-guest');
      sendBtn = document.getElementById('tri-cmt-send');
      var closeBtn = document.getElementById('tri-cmt-close');
      var emojiBtn = document.getElementById('tri-cmt-emoji-toggle');
      var imgBtn = document.getElementById('tri-cmt-img');
      if (closeBtn) { closeBtn.addEventListener('click', close); }
      /* mask click handled by global delegation */
      if (emojiBtn) { initEmojiPicker(emojiBtn, 'tri-cmt-text'); }
      if (imgBtn) { initImgPicker(imgBtn, 'tri-cmt-text'); }
      if (replyCancel) {
        replyCancel.addEventListener('click', function () {
          parentVal = 0;
          if (replyBar) { replyBar.hidden = true; }
          if (textEl) { textEl.focus(); }
        });
      }
      if (sendBtn) { sendBtn.addEventListener('click', submit); }
      if (textEl) {
        textEl.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
        });
      }
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && mask && !mask.hidden) { close(); }
      });
      /* 弹窗内回复：捕获阶段拦截，避免与页面评论区委托冲突 */
      document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.comment-reply-btn') : null;
        if (!btn) { return; }
        if (!mask || mask.hidden) { return; }
        e.stopPropagation();
        parentVal = parseInt(btn.getAttribute('data-coid'), 10) || 0;
        if (replyName) { replyName.textContent = btn.getAttribute('data-author') || ''; }
        if (replyBar) { replyBar.hidden = false; }
        if (textEl) { textEl.focus(); }
      }, true);
    }

    function load(scrollCoid) {
      if (!listEl) { return; }
      listEl.innerHTML = '<div class="comment-empty"><i class="ri-loader-4-line ri-spin"></i><p>加载中…</p></div>';
      fetch(api + '?action=list&cid=' + curCid, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { listEl.innerHTML = '<div class="comment-empty"><i class="ri-alert-line"></i><p>加载失败</p></div>'; return; }
          listEl.innerHTML = j.html || '<div class="comment-empty"><i class="ri-chat-smile-3-line"></i><p>还没有评论，来抢沙发吧～</p></div>';
          document.dispatchEvent(new CustomEvent('tri3:comments'));
          if (countEl) { countEl.textContent = j.count; }
          listEl.scrollTop = 0;
          if (scrollCoid) {
            var nEl = listEl.querySelector('#comment-' + scrollCoid);
            if (nEl) {
              setTimeout(function () {
                nEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                nEl.classList.add('comment-flash');
                setTimeout(function () { nEl.classList.remove('comment-flash'); }, 3000);
              }, 150);
            }
          }
        })
        .catch(function () {
          listEl.innerHTML = '<div class="comment-empty"><i class="ri-alert-line"></i><p>加载失败，请刷新重试</p></div>';
        });
    }

    function open(c, scrollCoid) {
      ensure();
      if (!mask) { return; }
      curCid = c || 0;
      if (!guestEl) { return; }
      /* 登录态：不显示游客信息输入；未登录：始终显示（有记忆则回填） */
      if (LOGIN.logged) {
        guestEl.style.display = 'none';
      var qqSaved = '';
      try { qqSaved = localStorage.getItem('tri3_cmt_qq') || ''; } catch (e) {}
      var qqEl = document.getElementById('tri-cmt-qq');
      if (qqEl && qqSaved) { qqEl.value = qqSaved; }
      } else {
        guestEl.style.display = 'grid';
        try {
          var au = localStorage.getItem('tri3_cmt_author');
          var em = localStorage.getItem('tri3_cmt_mail');
          var ur = localStorage.getItem('tri3_cmt_url');
          if (au) { document.getElementById('tri-cmt-author').value = au; }
          if (em) { document.getElementById('tri-cmt-mail').value = em; }
          if (ur) { document.getElementById('tri-cmt-url').value = ur; }
        } catch (e) {}
      }
      mask.hidden = false;
      document.body.style.overflow = 'hidden';
      load(scrollCoid || 0);
    }

    function close() {
      if (!mask) { return; }
      mask.hidden = true;
      document.body.style.overflow = '';
    }

    function submit() {
      if (!textEl) { return; }
      var text = textEl.value.trim();
      if (text === '') { toast('请先输入评论内容'); textEl.focus(); return; }
      var fd = new FormData();
      fd.append('action', 'post');
      fd.append('cid', String(curCid));
      fd.append('parent', String(parentVal));
      fd.append('text', text);
      fd.append('tri3_hp', (document.getElementById('tri-cmt-hp') || {}).value || '');
      if (!LOGIN.logged) {
        var author = (document.getElementById('tri-cmt-author') || {}).value || '';
        var mail = (document.getElementById('tri-cmt-mail') || {}).value || '';
        var url = (document.getElementById('tri-cmt-url') || {}).value || '';
        var qq = (document.getElementById('tri-cmt-qq') || {}).value || '';
        fd.append('author', author.trim());
        fd.append('mail', mail.trim());
        fd.append('url', url.trim());
        fd.append('qq', qq.replace(/\D/g, '').slice(0, 20));
        fd.append('pageurl', location.href);
        try {
          localStorage.setItem('tri3_cmt_author', author.trim());
          localStorage.setItem('tri3_cmt_mail', mail.trim());
          localStorage.setItem('tri3_cmt_url', url.trim());
          localStorage.setItem('tri3_cmt_qq', qq.replace(/\D/g, '').slice(0, 20));
        } catch (e) {}
      }
      if (sendBtn) { sendBtn.disabled = true; sendBtn.classList.add('is-loading'); }
      fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (sendBtn) { sendBtn.disabled = false; sendBtn.classList.remove('is-loading'); }
          if (!j || !j.ok) { toast((j && j.msg) || '提交失败'); return; }
          if (textEl) { textEl.value = ''; }
          parentVal = 0;
          if (replyBar) { replyBar.hidden = true; }
          if (j.waiting) { toast('评论已提交，等待审核通过后显示'); }
          load(j.coid || 0);
        })
        .catch(function () {
          if (sendBtn) { sendBtn.disabled = false; sendBtn.classList.remove('is-loading'); }
          toast('网络错误，请重试');
        });
    }

    /* 委托：点击任意 .moment-cmt 打开对应说说评论 */
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.moment-cmt') : null;
      if (!btn) { return; }
      open(parseInt(btn.getAttribute('data-cid'), 10) || 0);
    });

    /* 返回 open，供 TriMomentInit 在 PJAX 后按 URL 参数自动打开评论弹窗 */
    _cmtOpener = open;
    return open;
  }

  /* ---------- 评论图片插入：按钮延伸小浮层（URL → ![alt](url)） ---------- */
  function initImgPicker(btn, taId) {
    if (!btn || btn.getAttribute('data-img-bound')) { return; }
    btn.setAttribute('data-img-bound', '1');
    var pop = null;
    function insert() {
      if (!pop) { return; }
      var inp = pop.querySelector('.chat-img-url');
      var u = (inp.value || '').trim();
      if (!u) { return; }
      if (!/^https?:\/\//i.test(u)) { toast('请输入 http(s) 图片链接'); inp.focus(); return; }
      var ta = document.getElementById(taId);
      if (!ta) { return; }
      var st = ta.selectionStart || ta.value.length;
      var en = ta.selectionEnd || ta.value.length;
      var md = '![](' + u + ')';
      ta.value = ta.value.slice(0, st) + md + ta.value.slice(en);
      var pos = st + md.length;
      if (ta.setSelectionRange) { ta.setSelectionRange(pos, pos); }
      ta.focus();
      inp.value = '';
      pop.classList.remove('show');
    }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!pop) {
        pop = document.createElement('div');
        pop.className = 'chat-img-pop';
        pop.innerHTML = '<input type="text" class="chat-img-url" placeholder="粘贴图片链接（http/https）" maxlength="500">' +
          '<button type="button" class="chat-img-ok" title="插入" aria-label="插入图片"><i class="ri-check-line"></i></button>';
        document.body.appendChild(pop);
        pop.querySelector('.chat-img-ok').addEventListener('click', insert);
        pop.querySelector('.chat-img-url').addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') { ev.preventDefault(); insert(); }
        });
      }
      var r = btn.getBoundingClientRect();
      var pw = 320;
      var px = Math.max(8, Math.min(window.innerWidth - pw - 8, r.left));
      pop.style.width = pw + 'px';
      pop.style.left = px + 'px';
      pop.style.top = (r.bottom + 6) + 'px';
      var show = pop.classList.contains('show');
      pop.classList.toggle('show', !show);
      if (!show) { pop.querySelector('.chat-img-url').focus(); }
    });
    document.addEventListener('click', function (e) {
      if (pop && pop.classList.contains('show') && e.target !== btn && !(pop.contains && pop.contains(e.target))) {
        pop.classList.remove('show');
      }
    });
  }

  /* ---------- 说说编辑：前台弹窗（管理员，懒创建 + 委托） ---------- */
  var _editInited = false;
  function initEditDialog() {
    if (_editInited) { return; }
    _editInited = true;
    var mask, cid = 0;

    function ensure() {
      var old = document.querySelectorAll(".tri-edit-mask"); for (var k = 0; k < old.length; k++) { old[k].remove(); } mask = null;
      var d = document.createElement('div');
      d.className = 'tri-edit-mask';
      d.id = 'tri-edit-mask';
      d.hidden = true;
      d.innerHTML =
        '<div class="tri-edit-dialog" role="dialog" aria-modal="true" aria-label="编辑说说">' +
          '<div class="tri-edit-head">' +
            '<h3 class="tri-edit-title"><i class="ri-edit-line"></i>编辑说说</h3>' +
            '<button type="button" class="tri-edit-close" id="tri-edit-close" aria-label="关闭"><i class="ri-close-line"></i></button>' +
          '</div>' +
          '<div class="tri-edit-body">' +
            '<textarea id="tri-edit-content" class="mc-input" placeholder="说点什么…" maxlength="2000" rows="3"></textarea>' +
            '<div class="mc-imgbox" id="tri-edit-imgbox" style="display:none;">' +
              '<textarea id="tri-edit-images" class="mc-imgs" placeholder="图片链接，每行一个（最多 9 张）"></textarea>' +
            '</div>' +
            '<div class="mc-imgbox" id="tri-edit-musicbox" style="display:none;">' +
              '<div class="mc-media-row">' +
                '<select id="tri-edit-music-server" class="mc-imgs mc-select" aria-label="音乐平台"><option value="netease">网易云音乐</option><option value="qq">QQ 音乐</option></select>' +
                '<input type="text" id="tri-edit-music-id" class="mc-imgs mc-input-sm" placeholder="歌曲 ID（数字）" aria-label="歌曲ID">' +
                '<button type="button" id="tri-edit-music-preview" class="mc-ico" title="预览" aria-label="预览歌曲"><i class="ri-magic-line"></i></button>' +
              '</div>' +
              '<div id="tri-edit-music-pv" class="mc-music-pv" style="display:none;"></div>' +
              '<input type="hidden" id="tri-edit-music-title"><input type="hidden" id="tri-edit-music-artist"><input type="hidden" id="tri-edit-music-pic">' +
            '</div>' +
            '<div class="mc-imgbox" id="tri-edit-videobox" style="display:none;">' +
              '<input type="text" id="tri-edit-video-src" class="mc-imgs mc-input-sm" placeholder="视频直链 URL（mp4 等）" aria-label="视频地址">' +
              '<input type="text" id="tri-edit-video-poster" class="mc-imgs mc-input-sm" placeholder="封面图 URL（可选）" aria-label="视频封面">' +
            '</div>' +
            '<div class="mc-imgbox" id="tri-edit-livebox" style="display:none;">' +
              '<div class="mc-media-row">' +
              '<input type="text" id="tri-edit-live-img" class="mc-imgs mc-input-sm" placeholder="实况图片 URL（静态帧）" aria-label="实况图片">' +
              ((window.TRI3_UPLOAD || {}).enabled ? '<button type="button" class="mc-ico" id="tri-edit-live-upload" title="上传实况图片到图床" aria-label="上传实况图片"><i class="ri-upload-2-line"></i></button>' : '') +
              '</div>' +
              '<div class="mc-media-row">' +
              '<input type="text" id="tri-edit-live-video" class="mc-imgs mc-input-sm" placeholder="实况视频 URL（短视频直链）" aria-label="实况视频">' +
              ((window.TRI3_UPLOAD || {}).enabled ? '<button type="button" class="mc-ico" id="tri-edit-live-video-upload" title="上传实况视频（自动提取关键帧作封面）" aria-label="上传实况视频"><i class="ri-video-upload-line"></i></button>' : '') +
              '</div>' +
            '</div>' +
            '<div class="mc-imgbox" id="tri-edit-bilibox" style="display:none;">' +
              '<input type="text" id="tri-edit-bili-bvid" class="mc-imgs mc-input-sm" placeholder="B 站视频 BV 号或链接（如 BV1xx411c7mD）" aria-label="B站视频">' +
            '</div>' +
            '<div class="mc-toolbar">' +
              '<div class="mc-left">' +
                '<span class="mc-split">'
                + '<button type="button" class="mc-ico" id="tri-edit-img-toggle" title="添加图片" aria-label="添加图片"><i class="ri-image-add-line"></i></button>'
                + '<button type="button" class="mc-ico mc-ico-ins" id="tri-edit-ins-img" title="插入图片到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
                + '</span>'
                + '<span class="mc-split">'
                + '<button type="button" class="mc-ico" id="tri-edit-music-toggle" title="添加音乐" aria-label="添加音乐"><i class="ri-music-2-line"></i></button>'
                + '<button type="button" class="mc-ico mc-ico-ins" id="tri-edit-ins-music" title="插入音乐到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
                + '</span>'
                + '<span class="mc-split">'
                + '<button type="button" class="mc-ico" id="tri-edit-video-toggle" title="添加视频" aria-label="添加视频"><i class="ri-video-add-line"></i></button>'
                + '<button type="button" class="mc-ico mc-ico-ins" id="tri-edit-ins-video" title="插入视频到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
                + '</span>'
                + '<span class="mc-split">'
                + '<button type="button" class="mc-ico" id="tri-edit-live-toggle" title="添加实况图" aria-label="添加实况图"><i class="ri-camera-lens-line"></i></button>'
                + '<button type="button" class="mc-ico mc-ico-ins" id="tri-edit-ins-live" title="插入实况到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
                + '</span>' +
                '<span class="mc-split">' +
                '<button type="button" class="mc-ico" id="tri-edit-bili-toggle" title="添加B站视频" aria-label="添加B站视频"><i class="ri-bilibili-line"></i></button>' +
                '<button type="button" class="mc-ico mc-ico-ins" id="tri-edit-ins-bili" title="插入B站视频到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>' +
                '</span>' +
                '<label class="mc-private"><input type="checkbox" id="tri-edit-private"> 私密</label>' +
              '</div>' +
              '<div class="mc-right"><button type="button" id="tri-edit-save" class="mc-submit">保存</button></div>' +
            '</div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(d);
      mask = d;
      var closeBtn = document.getElementById('tri-edit-close');
      var saveBtn = document.getElementById('tri-edit-save');
      if (closeBtn) { closeBtn.addEventListener('click', close); }
      /* mask click handled by global delegation */
      if (saveBtn) { saveBtn.addEventListener('click', save); }
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && mask && !mask.hidden) { close(); }
      });
      /* 媒体开关 */
      var toggles = {
        'tri-edit-img-toggle': 'tri-edit-imgbox',
        'tri-edit-music-toggle': 'tri-edit-musicbox',
        'tri-edit-video-toggle': 'tri-edit-videobox',
        'tri-edit-live-toggle': 'tri-edit-livebox',
        'tri-edit-bili-toggle': 'tri-edit-bilibox'
      };
      for (var id in toggles) {
        (function (btnId, boxId) {
          var btn = document.getElementById(btnId);
          if (!btn) { return; }
          btn.addEventListener('click', function () {
            var box = document.getElementById(boxId);
            if (!box) { return; }
            var show = box.style.display === 'none';
            box.style.display = show ? 'block' : 'none';
            btn.classList.toggle('is-active', show);
            if (show && btnId === 'tri-edit-img-toggle') {
              var ta = document.getElementById('tri-edit-images');
              if (ta) { ta.focus(); }
            }
          });
        })(id, toggles[id]);
      }
      /* 音乐预览 */
      var pv = document.getElementById('tri-edit-music-preview');
      if (pv) { pv.addEventListener('click', function () { previewMusic('tri-edit-'); }); }
      /* 实况图片本地上传 */
      triLiveUploadPicker(document.getElementById('tri-edit-live-upload'), 'tri-edit-live-img');
      /* 实况视频一键上传：自动抽关键帧作封面 */
      triLiveAutoPicker(document.getElementById('tri-edit-live-video-upload'), 'tri-edit-live-img', 'tri-edit-live-video');
    }

    function fill(data) {
      document.getElementById('tri-edit-content').value = data.text || '';
      var imgs = (data.ga || []).map(function (u) { return u; }).join('\n');
      document.getElementById('tri-edit-images').value = imgs;
      if (imgs) {
        document.getElementById('tri-edit-imgbox').style.display = 'block';
        var tb = document.getElementById('tri-edit-img-toggle');
        if (tb) { tb.classList.add('is-active'); }
      }
      var mu = data.music;
      if (mu && mu.id) {
        document.getElementById('tri-edit-music-server').value = mu.server === 'tencent' ? 'qq' : (mu.server === 'local' ? 'netease' : (mu.server || 'netease'));
        document.getElementById('tri-edit-music-id').value = mu.id || '';
        document.getElementById('tri-edit-music-title').value = mu.title || '';
        document.getElementById('tri-edit-music-artist').value = mu.artist || '';
        document.getElementById('tri-edit-music-pic').value = mu.pic || '';
        document.getElementById('tri-edit-musicbox').style.display = 'block';
        var mb = document.getElementById('tri-edit-music-toggle');
        if (mb) { mb.classList.add('is-active'); }
      }
      var vi = data.video;
      if (vi && vi.src) {
        document.getElementById('tri-edit-video-src').value = vi.src || '';
        document.getElementById('tri-edit-video-poster').value = vi.poster || '';
        document.getElementById('tri-edit-videobox').style.display = 'block';
        var vb = document.getElementById('tri-edit-video-toggle');
        if (vb) { vb.classList.add('is-active'); }
      }
      var li = data.live;
      if (li && li.img && li.video) {
        document.getElementById('tri-edit-live-img').value = li.img || '';
        document.getElementById('tri-edit-live-video').value = li.video || '';
        document.getElementById('tri-edit-livebox').style.display = 'block';
        var lb = document.getElementById('tri-edit-live-toggle');
        if (lb) { lb.classList.add('is-active'); }
      }
      var bi = data.bili;
      if (bi && bi.bvid) {
        document.getElementById('tri-edit-bili-bvid').value = bi.bvid || '';
        document.getElementById('tri-edit-bilibox').style.display = 'block';
        var bb = document.getElementById('tri-edit-bili-toggle');
        if (bb) { bb.classList.add('is-active'); }
      }
      document.getElementById('tri-edit-private').checked = !!data.isPrivate;
    }

    function open(c) {
      ensure();
      if (!mask) { return; }
      cid = c || 0;
      var CFG = getCfg();
      mask.hidden = false;
      document.body.style.overflow = 'hidden';
      var api = CFG.api || '';
      var fd = new FormData();
      fd.append('do', 'view');
      fd.append('cid', String(cid));
      fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { toast((j && j.msg) || '加载失败'); close(); return; }
          fill(j.data);
        })
        .catch(function () { toast('网络错误'); close(); });
    }

    function close() {
      if (!mask) { return; }
      mask.hidden = true;
      document.body.style.overflow = '';
      /* 清空预览 */
      var pv = document.getElementById('tri-edit-music-pv');
      if (pv) { pv.style.display = 'none'; pv.innerHTML = ''; }
    }

    function save() {
      var contentEl = document.getElementById('tri-edit-content');
      if (!contentEl) { return; }
      var c = contentEl.value.trim();
      if (!c) { toast('内容不能为空'); return; }
      var saveBtn = document.getElementById('tri-edit-save');
      if (saveBtn) { saveBtn.disabled = true; var ot = saveBtn.textContent; saveBtn.textContent = '保存中…'; }
      var images = document.getElementById('tri-edit-images');
      var priv = document.getElementById('tri-edit-private');
      var media = collectMedia('tri-edit-');
      /* 图文混排优先：正文已含某媒体标记时，忽略独立媒体字段，避免重复显示 */
      var hasTag = {
        img: /\[img\]/.test(c),
        music: /\[music\]/.test(c),
        video: /\[video\]/.test(c),
        live: /\[live\]/.test(c),
        bili: /\[bili\]/.test(c)
      };
      var data = {
        do: 'edit',
        cid: String(cid),
        content: c,
        images: (!hasTag.img && images) ? images.value.trim() : '',
        private: priv && priv.checked ? '1' : ''
      };
      if (media.music && !hasTag.music) {
        data.music_server = media.music.server;
        data.music_id = media.music.id;
        data.music_title = media.music.title;
        data.music_artist = media.music.artist;
        data.music_pic = media.music.pic;
      }
      if (media.video) {
        data.video_src = media.video.src;
        data.video_poster = media.video.poster;
      }
      if (media.live) {
        data.live_img = media.live.img;
        data.live_video = media.live.video;
      }
      if (media.bili && !hasTag.bili) {
        data.bili_bvid = media.bili.bvid;
      }
      post(data, function () {
        toast('保存成功');
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '保存'; }
        close();
        window.location.reload();
      });
    }

    /* 委托：点击任意 .moment-edit 打开编辑 */
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.moment-edit') : null;
      if (!btn) { return; }
      e.stopPropagation();
      open(parseInt(btn.getAttribute('data-cid'), 10) || 0);
    });
  }

  /* ---------- 图集横向滚动增强（幂等：data-gal 标记已处理的容器） ---------- */
  function TriMomentInit() {
    var CFG = getCfg();
    if (!CFG.api) { return; }
    autofillMusicCards();
    var cmtOpener = initCommentDialog();
    initEditDialog();
    /* 最新评论小组件跳转：URL 带 mom_cid 时自动打开该说说的评论弹窗并定位到 #comment-coid */
    var triMQ = new URLSearchParams(location.search).get('mom_cid');
    if (triMQ && cmtOpener) {
      var triMCid = parseInt(triMQ, 10) || 0;
      var triMCoid = 0;
      var triMM = location.hash.match(/#comment-(\d+)/);
      if (triMM) { triMCoid = parseInt(triMM[1], 10) || 0; }
      if (triMCid && document.querySelector('.moment-card[data-cid="' + triMCid + '"]')) {
        setTimeout(function () { cmtOpener(triMCid, triMCoid); }, 80);
      }
    }
    /* 说说页发布框表情绑定 */
    if (document.getElementById('moment-emoji-toggle') && !document.getElementById('moment-emoji-toggle').getAttribute('data-emoji-bound')) {
      document.getElementById('moment-emoji-toggle').setAttribute('data-emoji-bound', '1');
      initEmojiPicker(document.getElementById('moment-emoji-toggle'), 'moment-content');
    }
    /* 说说页发布框图床上传：多图 + 列表 + 点击插入正文光标处（[img]URL[/img]） */
    var muBtn = document.getElementById('moment-upload-toggle');
    if (muBtn && !muBtn.getAttribute('data-upload-bound')) {
      muBtn.setAttribute('data-upload-bound', '1');
      triUploadPicker(muBtn, 'moment-content', triMomentImgCode);
    }
    /* 实况图片本地上传：上传后填入实况图片输入框 */
    var mlBtn = document.getElementById('moment-live-upload');
    if (mlBtn && !mlBtn.getAttribute('data-upload-bound')) {
      mlBtn.setAttribute('data-upload-bound', '1');
      triLiveUploadPicker(mlBtn, 'moment-live-img');
    }
    /* 实况视频一键上传：自动抽关键帧作封面 */
    var mlvBtn = document.getElementById('moment-live-video-upload');
    if (mlvBtn && !mlvBtn.getAttribute('data-upload-bound')) {
      mlvBtn.setAttribute('data-upload-bound', '1');
      triLiveAutoPicker(mlvBtn, 'moment-live-img', 'moment-live-video');
    }
    /* 评论区等静态表单表情绑定 */
    TriEmojiScan();
    document.querySelectorAll('.mc-gallery').forEach(function (g) {
      if (g.getAttribute('data-gal')) { return; }
      g.setAttribute('data-gal', '1');

      var wrap = document.createElement('div');
      wrap.className = 'mc-gallery-wrap';
      g.parentNode.insertBefore(wrap, g);
      wrap.appendChild(g);

      var prev = document.createElement('button');
      prev.className = 'mc-gal-arrow mc-gal-prev';
      prev.type = 'button';
      prev.setAttribute('aria-label', '上一组图片');
      prev.innerHTML = '<i class="ri-arrow-left-s-line"></i>';
      var next = document.createElement('button');
      next.className = 'mc-gal-arrow mc-gal-next';
      next.type = 'button';
      next.setAttribute('aria-label', '下一组图片');
      next.innerHTML = '<i class="ri-arrow-right-s-line"></i>';
      wrap.appendChild(prev);
      wrap.appendChild(next);

      var bar = document.createElement('div');
      bar.className = 'mc-gal-bar';
      var fill = document.createElement('span');
      fill.className = 'mc-gal-bar-fill';
      bar.appendChild(fill);
      wrap.appendChild(bar);

      function update() {
        var max = g.scrollWidth - g.clientWidth;
        if (max <= 4) {
          prev.classList.add('is-off');
          next.classList.add('is-off');
          bar.classList.remove('is-on');
          return;
        }
        var p = g.scrollLeft / max;
        fill.style.width = (14 + p * 72) + '%';
        prev.classList.toggle('is-off', g.scrollLeft <= 4);
        next.classList.toggle('is-off', g.scrollLeft >= max - 4);
        bar.classList.add('is-on');
      }
      function jump(dir) {
        var imgs = g.querySelectorAll('.moment-img');
        if (!imgs.length) { return; }
        var cur = g.scrollLeft;
        var target = null;
        if (dir > 0) {
          for (var i = 0; i < imgs.length; i++) {
            if (imgs[i].offsetLeft > cur + 8) { target = imgs[i].offsetLeft; break; }
          }
          if (target === null) { target = g.scrollWidth; }
        } else {
          for (var j = imgs.length - 1; j >= 0; j--) {
            if (imgs[j].offsetLeft < cur - 8) { target = imgs[j].offsetLeft; break; }
          }
          if (target === null) { target = 0; }
        }
        target = Math.max(0, Math.min(target, g.scrollWidth - g.clientWidth));
        try {
          g.scrollTo({ left: target, behavior: 'smooth' });
        } catch (e) {
          g.scrollLeft = target;
        }
      }
      prev.addEventListener('click', function () { jump(-1); });
      next.addEventListener('click', function () { jump(1); });
      g.addEventListener('scroll', update, { passive: true });
      /* v1.2.58：resize 只注册一次；回调刷新所有存活画廊，已移除的自动清理。
         此前每个画廊各挂一个 window resize，Swup 换页后旧画廊的监听永不摘除。 */
      window.__triGalUpd = window.__triGalUpd || new Map();
      window.__triGalUpd.set(g, update);

      if (!window.__triGalResizeBound) {

        window.__triGalResizeBound = 1;

        window.addEventListener('resize', function () {

          window.__triGalUpd.forEach(function (fn, el) {

            if (!el.isConnected) { window.__triGalUpd.delete(el); return; }

            try { fn(); } catch (e) {}

          });

        });

      }
      g._upd = update;
      g.querySelectorAll('img').forEach(function (im) {
        if (im.complete) { return; }
        im.addEventListener('load', update);
        im.addEventListener('error', update);
      });
      setTimeout(update, 300);
      setTimeout(update, 1200);
      update();
    });
    /* 懒加载图片（data-src 由 lazy.js 在进入视口后替换）加载完成时刷新按钮状态：
       图片初始为占位 complete=true，上面的 img load 监听会跳过，这里用 document 捕获阶段兜底 */
    if (!window._triGalLoadBound) {
      window._triGalLoadBound = true;
      document.addEventListener('load', function (e) {
        var t = e.target;
        if (t && t.tagName === 'IMG') {
          var gg = t.closest('.mc-gallery');
          if (gg && gg._upd) { gg._upd(); }
        }
      }, true);
    }
  }
  window.TriMomentInit = TriMomentInit;

  /* ---------- 图文混排：在文字光标处插入媒体标记 ---------- */
  function insertAtCursor(ta, text) {
    if (!ta) { return; }
    if (typeof ta.selectionStart !== 'undefined') {
      var st = ta.selectionStart, en = ta.selectionEnd;
      ta.value = ta.value.slice(0, st) + text + ta.value.slice(en);
      ta.selectionStart = ta.selectionEnd = st + text.length;
    } else {
      ta.value += text;
    }
    ta.focus();
  }

  function clearMediaBox(prefix, type) {
    var boxMap = { img: prefix + 'imgbox', music: prefix + 'musicbox', video: prefix + 'videobox', live: prefix + 'livebox' };
    var togMap = { img: prefix + 'img-toggle', music: prefix + 'music-toggle', video: prefix + 'video-toggle', live: prefix + 'live-toggle' };
    var box = document.getElementById(boxMap[type]);
    var tog = document.getElementById(togMap[type]);
    if (box) { box.style.display = 'none'; box.hidden = true; }
    if (tog) { tog.classList.remove('is-active'); }
    if (type === 'img') {
      var f = document.getElementById(prefix + 'images'); if (f) { f.value = ''; }
    } else if (type === 'music') {
      ['music-server', 'music-id', 'music-title', 'music-artist', 'music-pic'].forEach(function (n) {
        var el = document.getElementById(prefix + n); if (el) { el.value = ''; }
      });
      var pv = document.getElementById(prefix + 'music-pv');
      if (pv) { pv.style.display = 'none'; pv.innerHTML = ''; pv._song = null; }
    } else if (type === 'video') {
      ['video-src', 'video-poster'].forEach(function (n) {
        var el = document.getElementById(prefix + n); if (el) { el.value = ''; }
      });
    } else if (type === 'live') {
      ['live-img', 'live-video'].forEach(function (n) {
        var el = document.getElementById(prefix + n); if (el) { el.value = ''; }
      });
    } else if (type === 'bili') {
      var el = document.getElementById(prefix + 'bili-bvid'); if (el) { el.value = ''; }
    }
  }

  /* ---------- 从分享链接中提取歌曲 ID（QQ songmid / 网易云 id） ---------- */
  function extractSongId(raw) {
    raw = (raw || '').trim();
    if (!raw) { return ''; }
    if (/^[A-Za-z0-9]+$/.test(raw)) { return raw; }
    var m;
    /* QQ 音乐：songDetail/{songmid} 或 songmid=xxx */
    m = raw.match(/songDetail\/([A-Za-z0-9]+)/);
    if (m) { return m[1]; }
    m = raw.match(/songmid=([A-Za-z0-9]+)/);
    if (m) { return m[1]; }
    /* 网易云：/song?id=xxx 或 /song/xxx */
    m = raw.match(/[?&]id=(\d+)/);
    if (m) { return m[1]; }
    m = raw.match(/\/song\/(\d+)/);
    if (m) { return m[1]; }
    return '';
  }

  /* ---------- 从 B 站链接 / BV 号 / av 号提取视频标识 ---------- */
  function extractBvid(raw) {
    raw = (raw || '').trim();
    if (!raw) { return ''; }
    var m = raw.match(/BV[0-9A-Za-z]{6,}/);
    if (m) { return m[0]; }
    m = raw.match(/av(\d+)/i);
    if (m) { return 'av' + m[1]; }
    if (/^\d+$/.test(raw)) { return 'av' + raw; }
    return '';
  }

  function insertMediaTag(prefix, type) {
    var ta = document.getElementById(prefix + 'content');
    if (!ta) { return; }

    if (type === 'music') {
      var srv = document.getElementById(prefix + 'music-server');
      var mid = document.getElementById(prefix + 'music-id');
      var server = srv ? (srv.value === 'tencent' ? 'qq' : srv.value) : 'netease';
      var id = extractSongId(mid ? mid.value : '');
      if (!id) {
        insertAtCursor(ta, '[music]netease|歌曲ID[/music]');
        return;
      }
      var tEl = document.getElementById(prefix + 'music-title');
      var aEl = document.getElementById(prefix + 'music-artist');
      var pEl = document.getElementById(prefix + 'music-pic');
      var title = tEl ? tEl.value.trim() : '';
      var artist = aEl ? aEl.value.trim() : '';
      var pic = pEl ? pEl.value.trim() : '';
      var build = function (ti, ar, pi) {
        var parts = [server, id];
        if (ti || ar || pi) { parts.push(ti, ar, pi); }
        insertAtCursor(ta, '[music]' + parts.join('|') + '[/music]');
        clearMediaBox(prefix, 'music');
        toast('已插入音乐卡片到正文');
      };
      var doFallback = function () {
        /* 识别失败：有手动信息则用手动，否则提示不插入（避免无信息卡片） */
        var t = tEl ? tEl.value.trim() : '';
        var a = aEl ? aEl.value.trim() : '';
        var p = pEl ? pEl.value.trim() : '';
        if (t || a || p) { build(t, a, p); }
        else {
          toast(server === 'qq'
            ? 'QQ 音乐识别失败：请填写歌曲分享链接或 songmid（字母数字 ID）'
            : '歌曲识别失败，请检查歌曲 ID 或稍后重试');
        }
      };
      var api = metingApi();
      if (api) {
        fetch(api + '?server=' + encodeURIComponent(server === 'qq' ? 'tencent' : server) + '&type=song&id=' + encodeURIComponent(id))
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.title) {
              /* 手动填写优先，缺封面/歌手时用识别结果补全 */
              var t = tEl ? tEl.value.trim() : '';
              var a = aEl ? aEl.value.trim() : '';
              var p = pEl ? pEl.value.trim() : '';
              build(t || d.title, a || d.artist || '', tri3Cover(p || d.pic || ''));
            } else { doFallback(); }
          })
          .catch(function () { doFallback(); });
      } else {
        doFallback();
      }
      return;
    }

    var tag = '';
    if (type === 'img') {
      var imgs = document.getElementById(prefix + 'images');
      var lines = imgs ? imgs.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean) : [];
      if (lines.length) {
        tag = lines.map(function (u) { return '[img]' + u + '[/img]'; }).join('\n');
      } else {
        tag = '[img]https://图片地址[/img]';
      }
    } else if (type === 'video') {
      var vs = document.getElementById(prefix + 'video-src');
      var vp = document.getElementById(prefix + 'video-poster');
      var src = vs ? vs.value.trim() : '';
      var poster = vp ? vp.value.trim() : '';
      tag = src ? (poster ? '[video]' + src + '|' + poster + '[/video]' : '[video]' + src + '[/video]') : '[video]https://视频地址[/video]';
    } else if (type === 'live') {
      var li = document.getElementById(prefix + 'live-img');
      var lv = document.getElementById(prefix + 'live-video');
      var img = li ? li.value.trim() : '';
      var video = lv ? lv.value.trim() : '';
      tag = (img && video) ? ('[live]' + img + '|' + video + '[/live]') : '[live]https://图片地址|https://视频地址[/live]';
    } else if (type === 'bili') {
      var bv = document.getElementById(prefix + 'bili-bvid');
      var bvid = extractBvid(bv ? bv.value : '');
      tag = bvid ? ('[bili]' + bvid + '[/bili]') : '[bili]BV号或B站链接[/bili]';
    }
    insertAtCursor(ta, tag);
    clearMediaBox(prefix, type);
    toast(type === 'img' ? '已插入图片到正文' : type === 'video' ? '已插入视频到正文' : type === 'live' ? '已插入实况到正文' : '已插入B站视频到正文');
  }

  /* ---------- 历史无信息音乐卡自动补全（异步识别歌曲信息/封面） ---------- */
  function autofillMusicCards() {
    var cards = document.querySelectorAll('.mc-music');
    cards.forEach(function (c) {
      var t = c.getAttribute('data-title') || '';
      if (t && t !== '未知歌曲') { return; }
      var srv = c.getAttribute('data-server') || 'netease';
      var id = c.getAttribute('data-id');
      var api = metingApi();
      if (!id || !api || c.getAttribute('data-autofilled')) { return; }
      c.setAttribute('data-autofilled', '1');
      fetch(api + '?server=' + encodeURIComponent(srv) + '&type=song&id=' + encodeURIComponent(id))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.title) { return; }
          var cover = c.querySelector('.mc-music-cover');
          var ti = c.querySelector('.mc-music-title');
          var ar = c.querySelector('.mc-music-artist');
          if (cover && d.pic) { cover.innerHTML = '<img src="' + esc(tri3Cover(d.pic)) + '" alt="" loading="lazy">'; }
          if (ti) { ti.textContent = d.title; }
          if (ar && d.artist) { ar.textContent = d.artist; }
          c.setAttribute('data-title', d.title);
          c.setAttribute('data-artist', d.artist || '');
          c.setAttribute('data-pic', tri3Cover(d.pic) || '');
        })
        .catch(function () {});
    });
  }

  /* ---------- 说说发布弹窗（登录态 banner 右上角按钮，支持媒体） ---------- */
  function openSayDialog() {
    var mask = document.querySelector('.tri-dialog-mask');
    if (!mask) {
      mask = document.createElement('div');
      mask.className = 'tri-dialog-mask';
      mask.hidden = true;
      document.body.appendChild(mask);
    }
    var cfg = window.TRI3_LOGIN || {};
    var av = cfg.avatar
      ? '<img class="tri-say-avatar" src="' + esc(cfg.avatar) + '" alt="">'
      : '<span class="tri-say-avatar tri-say-av-fallback"><i class="ri-quill-pen-line"></i></span>';
    mask.innerHTML =
      '<div class="tri-dialog tri-say-dialog" role="dialog" aria-modal="true" aria-label="发布说说">'
      + '<div class="tri-say-head">' + av
      + '<div class="tri-say-head-txt"><h3>发布说说</h3><span class="tri-say-sub">分享此刻的想法</span></div>'
      + '<button type="button" class="tri-say-close" id="tri-say-close" aria-label="关闭"><i class="ri-close-line"></i></button>'
      + '</div>'
      + '<div class="tri-say-body">'
      + '<textarea id="tri-say-content" class="tri-say-ta" placeholder="分享此刻的想法…" maxlength="2000" rows="3"></textarea>'
      + '<span class="tri-say-counter" id="tri-say-counter">0 / 2000</span>'
      + '</div>'
      + '<div class="tri-say-toolbar">'
      + '<button type="button" class="mc-ico" id="tri-say-emoji-toggle" title="表情"><i class="ri-emotion-line"></i></button>'
      + ((window.TRI3_UPLOAD || {}).enabled ? '<button type="button" class="mc-ico" id="tri-say-upload-toggle" title="上传图片到图床"><i class="ri-upload-2-line"></i></button>' : '')
      + '<span class="mc-ico-group">'
      + '<button type="button" class="mc-ico" id="tri-say-img-toggle" title="添加图片"><i class="ri-image-add-line"></i></button>'
      + '<button type="button" class="mc-ico mc-ico-ins" id="tri-say-ins-img" title="插入图片到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
      + '</span>'
      + '<span class="mc-ico-group">'
      + '<button type="button" class="mc-ico" id="tri-say-music-toggle" title="添加音乐"><i class="ri-music-2-line"></i></button>'
      + '<button type="button" class="mc-ico mc-ico-ins" id="tri-say-ins-music" title="插入音乐到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
      + '</span>'
      + '<span class="mc-ico-group">'
      + '<button type="button" class="mc-ico" id="tri-say-video-toggle" title="添加视频"><i class="ri-video-add-line"></i></button>'
      + '<button type="button" class="mc-ico mc-ico-ins" id="tri-say-ins-video" title="插入视频到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
      + '</span>'
      + '<span class="mc-ico-group">'
      + '<button type="button" class="mc-ico" id="tri-say-live-toggle" title="添加实况图"><i class="ri-camera-lens-line"></i></button>'
      + '<button type="button" class="mc-ico mc-ico-ins" id="tri-say-ins-live" title="插入实况到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
      + '</span>'
      + '<span class="mc-ico-group">'
      + '<button type="button" class="mc-ico" id="tri-say-bili-toggle" title="添加B站视频"><i class="ri-bilibili-line"></i></button>'
      + '<button type="button" class="mc-ico mc-ico-ins" id="tri-say-ins-bili" title="插入B站视频到文字（自动代入）"><i class="ri-corner-down-right-line"></i></button>'
      + '</span>'
      + '<span class="tri-say-toolbar-tip">点图标添加 · 点角标插入文字</span>'
      + '</div>'
      + '<div class="tri-say-imgs">'
      + '<div class="tri-say-imgs-box" id="tri-say-imgbox" hidden>'
      + '<textarea id="tri-say-images" class="tri-say-ta tri-say-ta-sm" placeholder="每行一个图片地址，最多 9 张"></textarea>'
      + '</div>'
      + '<div class="tri-say-imgs-box" id="tri-say-musicbox" hidden>'
      + '<div class="mc-media-row">'
      + '<select id="tri-say-music-server" class="mc-imgs mc-select"><option value="netease">网易云音乐</option><option value="tencent">QQ 音乐</option></select>'
      + '<input type="text" id="tri-say-music-id" class="mc-imgs mc-input-sm" placeholder="歌曲 ID">'
      + '<button type="button" id="tri-say-music-preview" class="mc-ico" title="预览"><i class="ri-magic-line"></i></button>'
      + '</div>'
      + '<div id="tri-say-music-pv" class="mc-music-pv" style="display:none;"></div>'
      + '<input type="hidden" id="tri-say-music-title"><input type="hidden" id="tri-say-music-artist"><input type="hidden" id="tri-say-music-pic">'
      + '</div>'
      + '<div class="tri-say-imgs-box" id="tri-say-videobox" hidden>'
      + '<input type="text" id="tri-say-video-src" class="tri-say-ta tri-say-ta-sm" placeholder="视频直链 URL（mp4 等）">'
      + '<input type="text" id="tri-say-video-poster" class="tri-say-ta tri-say-ta-sm" placeholder="封面图 URL（可选）">'
      + '</div>'
      + '<div class="tri-say-imgs-box" id="tri-say-livebox" hidden>'
      + '<div class="mc-media-row">'
      + '<input type="text" id="tri-say-live-img" class="tri-say-ta tri-say-ta-sm" placeholder="实况图片 URL（静态帧）">'
      + ((window.TRI3_UPLOAD || {}).enabled ? '<button type="button" class="mc-ico" id="tri-say-live-upload" title="上传实况图片到图床" aria-label="上传实况图片"><i class="ri-upload-2-line"></i></button>' : '')
      + '</div>'
      + '<div class="mc-media-row">'
      + '<input type="text" id="tri-say-live-video" class="tri-say-ta tri-say-ta-sm" placeholder="实况视频 URL（短视频直链）">'
      + ((window.TRI3_UPLOAD || {}).enabled ? '<button type="button" class="mc-ico" id="tri-say-live-video-upload" title="上传实况视频（自动提取关键帧作封面）" aria-label="上传实况视频"><i class="ri-video-upload-line"></i></button>' : '')
      + '</div>'
      + '</div>'
      + '<div class="tri-say-imgs-box" id="tri-say-bilibox" hidden>'
      + '<input type="text" id="tri-say-bili-bvid" class="tri-say-ta tri-say-ta-sm" placeholder="B 站视频 BV 号或链接（如 BV1xx411c7mD）">'
      + '</div>'
      + '</div>'
+ '<div class="tri-say-foot">'
      + '<div class="tri-say-foot-left">'
      + '<label class="tri-check"><input id="tri-say-private" type="checkbox"> 私密</label>'
      + '<select id="tri-say-cat" class="mc-cat-select" aria-label="分类"><option value="">未分类</option></select>'
      + '</div>'
      + '<div class="tri-dialog-actions">'
      + '<button type="button" class="tri-btn tri-btn-text" id="tri-say-cancel">取消</button>'
      + '<button type="button" class="tri-btn tri-btn-filled" id="tri-say-submit">发布</button>'
      + '</div>'
      + '</div>'
      + '</div>';
    /* 说说分类（独立于文章分类）填充 */
    var sayCat = document.getElementById('tri-say-cat');
    if (sayCat) {
      try {
        var sayApi = ((window.TRI3_MOMENT || {}).api || '').split('?')[0];
        fetch(sayApi, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'do=cats', credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j && j.ok && j.list) {
              sayCat.innerHTML = '<option value="">未分类</option>' + j.list.map(function (c) {
                return '<option value="' + esc(c.slug) + '">' + esc(c.name) + '</option>';
              }).join('');
            }
          }).catch(function () {});
      } catch (e) {}
    }
    mask.hidden = false;
    var content = document.getElementById('tri-say-content');
    var submit = document.getElementById('tri-say-submit');
    var cancel = document.getElementById('tri-say-cancel');
    var close = document.getElementById('tri-say-close');
    var counter = document.getElementById('tri-say-counter');
    function closeSay() { mask.hidden = true; }
    cancel.addEventListener('click', function (e) { e.stopPropagation(); closeSay(); });
    close.addEventListener('click', function (e) { e.stopPropagation(); closeSay(); });
    content.addEventListener('input', function () {
      if (counter) { counter.textContent = content.value.length + ' / 2000'; }
    });
    /* 弹窗媒体 toggle */
    [['tri-say-img-toggle', 'tri-say-imgbox'], ['tri-say-music-toggle', 'tri-say-musicbox'],
     ['tri-say-video-toggle', 'tri-say-videobox'], ['tri-say-live-toggle', 'tri-say-livebox'],
     ['tri-say-bili-toggle', 'tri-say-bilibox']].forEach(function (pair) {
      var btn = document.getElementById(pair[0]);
      var box = document.getElementById(pair[1]);
      if (!btn || !box) { return; }
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var show = box.hidden;
        box.hidden = !show;
        btn.classList.toggle('is-active', show);
        if (show && pair[0] === 'tri-say-img-toggle') {
          var ta = document.getElementById('tri-say-images');
          if (ta) { ta.focus(); }
        }
      });
    });
    triUploadPicker(document.getElementById('tri-say-upload-toggle'), 'tri-say-content', triMomentImgCode);
    triLiveUploadPicker(document.getElementById('tri-say-live-upload'), 'tri-say-live-img');
    triLiveAutoPicker(document.getElementById('tri-say-live-video-upload'), 'tri-say-live-img', 'tri-say-live-video');
    var mv = document.getElementById('tri-say-music-preview');
    if (mv) {
      mv.addEventListener('click', function (e) { e.stopPropagation(); previewMusic('tri-say-'); });
    }
    /* 表情功能（通用绑定，面板动态生成） */
    initEmojiPicker(document.getElementById('tri-say-emoji-toggle'), 'tri-say-content');
    if (content) { content.focus(); }
    submit.addEventListener('click', function (e) { e.stopPropagation(); doPublish('tri-say-', submit); });
    content.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { doPublish('tri-say-', submit); }
    });
  }

  /* ---------- 说说表情：Emoji / 颜文字 / 经典表情 ---------- */
  var TRI_EMOJI_GROUPS = [
    { name: 'Emoji', list: ['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','🥰','😘','😜','🤪','🤔','🤗','🤭','🤫','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🥵','🥶','😭','😢','😤','😡','🤬','🤯','😳','🥺','😱','😰','😓','🤝','👍','👎','👌','✌️','🤞','🤟','👏','🙏','💪','🤳','👀','🧠','❤️','💔','💕','🔥','✨','🎉','🎂','🎁','⭐','🌙','☀️','🌈','🍀','🎵'] },
    { name: '经典表情', list: ['/微笑','/大笑','/呲牙','/调皮','/可爱','/害羞','/亲亲','/流泪','/大哭','/难过','/困','/惊讶','/酷','/汗','/抓狂','/吐','/鄙视','/奋斗','/鼓掌','/示爱','/得意','/衰'] },
    { name: '哔哩哔哩', type: 'img', list: [
      { t: '[doge]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/doge.gif' },
      { t: '[亲亲]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/亲亲.gif' },
      { t: '[偷笑]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/偷笑.gif' },
      { t: '[再见]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/再见.gif' },
      { t: '[发怒]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/发怒.gif' },
      { t: '[发财]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/发财.gif' },
      { t: '[可爱]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/可爱.gif' },
      { t: '[吐血]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/吐血.gif' },
      { t: '[呆]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/呆.gif' },
      { t: '[呕吐]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/呕吐.gif' },
      { t: '[困]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/困.gif' },
      { t: '[坏笑]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/坏笑.gif' },
      { t: '[大佬]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/大佬.gif' },
      { t: '[大哭]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/大哭.gif' },
      { t: '[委屈]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/委屈.gif' },
      { t: '[害羞]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/害羞.gif' },
      { t: '[尴尬]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/尴尬.gif' },
      { t: '[微笑]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/微笑.gif' },
      { t: '[思考]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/思考.gif' },
      { t: '[惊吓]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/惊吓.gif' },
      { t: '[打脸]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/打脸.gif' },
      { t: '[抓狂]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/抓狂.gif' },
      { t: '[抠鼻子]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/抠鼻子.gif' },
      { t: '[斜眼笑]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/斜眼笑.gif' },
      { t: '[无奈]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/无奈.gif' },
      { t: '[晕]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/晕.gif' },
      { t: '[流汗]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/流汗.gif' },
      { t: '[流鼻血]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/流鼻血.gif' },
      { t: '[点赞]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/点赞.gif' },
      { t: '[生气]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/生气.gif' },
      { t: '[生病]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/生病.gif' },
      { t: '[疑问]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/疑问.gif' },
      { t: '[白眼]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/白眼.gif' },
      { t: '[睡着]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/睡着.gif' },
      { t: '[笑哭]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/笑哭.gif' },
      { t: '[腼腆]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/腼腆.gif' },
      { t: '[色]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/色.gif' },
      { t: '[调皮]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/调皮.gif' },
      { t: '[鄙视]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/鄙视.gif' },
      { t: '[闭嘴]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/闭嘴.gif' },
      { t: '[难过]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/难过.gif' },
      { t: '[馋]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/馋.gif' },
      { t: '[黑人问号]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/黑人问号.gif' },
      { t: '[鼓掌]', u: 'https://gcore.jsdelivr.net/npm/emotiono@1.0.0/bilibili/鼓掌.gif' }
    ] },
    { name: '微博表情', type: 'img', list: [
      { t: '[滑稽]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/huaji.png' },
      { t: '[捂脸]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/wuzuixiao.png' },
      { t: '[笑哭]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/xiaoku.png' },
      { t: '[微笑]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/xiaoyan.png' },
      { t: '[开心]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/taikaixin.png' },
      { t: '[太开心]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/haha.png' },
      { t: '[泪]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/lei.png' },
      { t: '[生气]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/nu.png' },
      { t: '[惊讶]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/jingya.png' },
      { t: '[疑问]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/yiwen.png' },
      { t: '[黑线]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/heixian.png' },
      { t: '[汗]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/han.png' },
      { t: '[害羞]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/xiaohonglian.png' },
      { t: '[委屈]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/weiqu.png' },
      { t: '[无奈]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/mianqiang.png' },
      { t: '[二哈]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/what.png' },
      { t: '[坏笑]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/hehe.png' },
      { t: '[抠鼻]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/bishi.png' },
      { t: '[喷]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/pen.png' },
      { t: '[吐]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/tu.png' },
      { t: '[阴险]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/yinxian.png' },
      { t: '[惊哭]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/jingku.png' },
      { t: '[OK]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/ok.png' },
      { t: '[点赞]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/damuzhi.png' },
      { t: '[鼓掌]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/zhenbang.png' },
      { t: '[胜利]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/shengli.png' },
      { t: '[睡觉]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/shuijiao.png' },
      { t: '[玫瑰]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/meigui.png' },
      { t: '[爱心]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/aixin.png' },
      { t: '[蛋糕]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/dangao.png' },
      { t: '[礼物]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/liwu.png' },
      { t: '[彩虹]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/caihong.png' },
      { t: '[太阳]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/taiyang.png' },
      { t: '[音乐]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/yinyue.png' },
      { t: '[星星月亮]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/xingxingyueliang.png' },
      { t: '[赞]', u: 'https://gcore.jsdelivr.net/gh/cdn-x/emoticons@3.1/tieba/a.png' }
    ] }
  ];
  var TRI_QQ_EMOJI = { '/微笑':'🙂','/大笑':'😄','/呲牙':'😁','/调皮':'😜','/可爱':'🥰','/害羞':'😊','/亲亲':'😘','/流泪':'😭','/大哭':'😢','/难过':'😞','/困':'😪','/惊讶':'😲','/酷':'😎','/汗':'😓','/抓狂':'🤯','/吐':'🤮','/鄙视':'🙄','/奋斗':'💪','/鼓掌':'👏','/示爱':'💕','/得意':'😏','/衰':'🥶' };
  /* ---------- 表情面板（通用）：Emoji / 经典 / 哔哩哔哩 / 微博 ---------- */
  function emojiPanelHTML(prefix) {
    var p = prefix + '-';
    return '<div class="tri-say-emojis" id="' + p + 'emojis" hidden>'
      + '<div class="tri-say-emoji-tabs">'
      + '<button type="button" class="tri-say-emoji-tab is-active" data-g="0">Emoji</button>'
      + '<button type="button" class="tri-say-emoji-tab" data-g="1">经典表情</button>'
      + '<button type="button" class="tri-say-emoji-tab" data-g="2">哔哩哔哩</button>'
      + '<button type="button" class="tri-say-emoji-tab" data-g="3">微博</button>'
      + '</div>'
      + '<div class="tri-say-emoji-body"><div class="tri-say-emoji-grid" id="' + p + 'emoji-grid"></div></div>'
      + '</div>';
  }
  /* 通用表情绑定：btn 的 id 形如 xxx-emoji-toggle，自动派生 prefix=xxx；
     taId 为目标 textarea 的 id。面板不存在时自动创建插入按钮旁 */
  function initEmojiPicker(btn, taId) {
    if (!btn) { return; }
    var prefix = btn.id.replace(/-emoji-toggle$/, '');
    var p = prefix + '-';
    /* 强制重建：pjax 或版本切换可能残留旧结构面板，直接移除重建，保证 tabs 与当前分组一致 */
    var oldBox = document.getElementById(p + 'emojis');
    if (oldBox) { oldBox.remove(); }
    var holder = document.createElement('div');
    holder.innerHTML = emojiPanelHTML(prefix);
    var box = holder.firstChild;
    /* 统一挂到 body 下：fixed 视口底部浮层（类表情键盘），
       脱离所有容器，不占布局、不被 transform 祖先破坏 fixed、不被 overflow 裁剪 */
    document.body.appendChild(box);
    var grid = document.getElementById(p + 'emoji-grid');
    if (!grid) { return; }
    var cur = 0;
    function render(g) {
      cur = g;
      var grp = TRI_EMOJI_GROUPS[g];
      if (!grp) { return; }
      grid.innerHTML = '';
      grid.className = 'tri-say-emoji-grid'
        + (grp.type === 'img' ? ' tri-say-emoji-imgs' : '');
      (grp.list).forEach(function (em) {
        var s = document.createElement('span');
        s.className = 'tri-say-emoji';
        if (grp.type === 'img') {
          var im = document.createElement('img');
          im.src = em.u;
          im.alt = em.t;
          im.loading = 'lazy';
          s.appendChild(im);
          s.title = em.t;
          s.addEventListener('click', function (e) {
            e.stopPropagation();
            insertEmoji(em.t);
          });
        } else if (g === 1) {
          s.textContent = TRI_QQ_EMOJI[em] || em;
          s.title = em;
          s.addEventListener('click', function (e) {
            e.stopPropagation();
            insertEmoji(em);
          });
        } else {
          s.textContent = em;
          s.title = em;
          s.addEventListener('click', function (e) {
            e.stopPropagation();
            insertEmoji(em);
          });
        }
        grid.appendChild(s);
      });
    }
    function insertEmoji(txt) {
      var ta = document.getElementById(taId);
      if (!ta) { return; }
      var st = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
      var en = ta.selectionEnd != null ? ta.selectionEnd : ta.value.length;
      ta.value = ta.value.slice(0, st) + txt + ta.value.slice(en);
      var np = st + txt.length;
      ta.selectionStart = ta.selectionEnd = np;
      ta.focus();
      ta.dispatchEvent(new Event('input'));
    }
    box.querySelectorAll('.tri-say-emoji-tab').forEach(function (t) {
      t.addEventListener('click', function (e) {
        e.stopPropagation();
        box.querySelectorAll('.tri-say-emoji-tab').forEach(function (x) { x.classList.remove('is-active'); });
        t.classList.add('is-active');
        render(parseInt(t.getAttribute('data-g'), 10) || 0);
      });
    });
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!box.hidden) {
        box.hidden = true;
        box.classList.remove('is-open');
        btn.classList.remove('is-active');
        return;
      }
      /* 打开：从按钮位置延伸（fixed 相对视口），上方优先，空间不足自动向下 */
      box.hidden = false;
      var r = btn.getBoundingClientRect();
      var pw = box.offsetWidth, ph = box.offsetHeight;
      var left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
      var top, origin;
      if (r.top - ph - 8 >= 8) {
        top = r.top - ph - 8;
        origin = (r.left < window.innerWidth / 2 ? 'bottom left' : 'bottom right');
      } else {
        top = r.bottom + 8;
        origin = (r.left < window.innerWidth / 2 ? 'top left' : 'top right');
      }
      box.style.left = left + 'px';
      box.style.top = top + 'px';
      box.style.transformOrigin = origin;
      btn.classList.add('is-active');
      if (grid.children.length === 0) { render(cur); }
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { box.classList.add('is-open'); });
      });
    });
    /* 点击面板外关闭 */
    document.addEventListener('click', function (e) {
      if (box.hidden) { return; }
      if (box.contains(e.target) || e.target.closest('#' + prefix + '-emoji-toggle')) { return; }
      box.hidden = true;
      box.classList.remove('is-open');
      btn.classList.remove('is-active');
    });
    render(cur);
  }
  /* 全局扫描绑定：评论区等静态表单用 data-emoji-picker + data-emoji-target */
  function TriEmojiScan() {
    document.querySelectorAll('[data-emoji-picker]').forEach(function (btn) {
      if (btn.getAttribute('data-emoji-bound')) { return; }
      btn.setAttribute('data-emoji-bound', '1');
      var target = btn.getAttribute('data-emoji-target') || '#textarea';
      var ta = document.querySelector(target);
      if (!ta) { return; }
      initEmojiPicker(btn, ta.id);
    });
    /* 评论图片插入按钮 */
    document.querySelectorAll('[data-img-picker]').forEach(function (b) {
      initImgPicker(b, b.getAttribute('data-img-target') ? b.getAttribute('data-img-target').replace(/^#/, '') : null);
    });
  }
  window.TriEmojiScan = TriEmojiScan;

  /* ---------- 视频播放器控制 ---------- */
  function bindVideo(video) {
    if (video.getAttribute('data-vb')) { return; }
    video.setAttribute('data-vb', '1');
    var wrap = video.closest('.mc-video');
    if (!wrap) { return; }
    var mask = wrap.querySelector('.mc-video-mask');
    var bar = wrap.querySelector('.mc-video-bar');
    var toggle = wrap.querySelector('.mc-vb-toggle');
    var fill = wrap.querySelector('.mc-vb-fill');
    var tEl = wrap.querySelector('.mc-vb-time');
    var full = wrap.querySelector('.mc-vb-full');
    var played = false;

    function showBar() {
      if (bar) { bar.hidden = false; }
      if (mask) { mask.classList.add('is-hide'); }
    }
    function hideBar() {
      if (!video.paused) { return; }
      if (bar) { bar.hidden = true; }
      if (mask) { mask.classList.remove('is-hide'); }
    }
    function fmt(s) {
      s = Math.floor(s || 0);
      var m = Math.floor(s / 60);
      var ss = s % 60;
      return m + ':' + (ss < 10 ? '0' : '') + ss;
    }
    function up() {
      if (fill && video.duration && isFinite(video.duration)) {
        fill.style.width = (video.currentTime / video.duration * 100) + '%';
      }
      if (tEl) { tEl.textContent = fmt(video.currentTime) + ' / ' + fmt(video.duration); }
      if (toggle) {
        toggle.innerHTML = video.paused ? '<i class="ri-play-fill"></i>' : '<i class="ri-pause-fill"></i>';
      }
    }
    function startPlay() {
      played = true;
      wrap.classList.add('is-expanded');
      video.play().catch(function () {});
      showBar();
    }
    var playBtn = wrap.querySelector('.mc-video-play');
    if (playBtn) {
      playBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        startPlay();
      });
    }
    if (toggle) {
      toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (video.paused) { video.play().catch(function () {}); }
        else { video.pause(); }
        showBar();
      });
    }
    if (full) {
      full.addEventListener('click', function (e) {
        e.stopPropagation();
        if (document.fullscreenElement) { document.exitFullscreen().catch(function () {}); }
        else if (wrap.requestFullscreen) { wrap.requestFullscreen().catch(function () {}); }
      });
    }
    var prog = wrap.querySelector('.mc-vb-progress');
    if (prog) {
      prog.addEventListener('click', function (e) {
        var r = prog.getBoundingClientRect();
        var p = (e.clientX - r.left) / r.width;
        if (video.duration && isFinite(video.duration)) {
          video.currentTime = p * video.duration;
        }
      });
    }
    video.addEventListener('timeupdate', up);
    video.addEventListener('play', function () {
      played = true;
      wrap.classList.add('is-expanded');
      if (mask) { mask.classList.add('is-hide'); }
      showBar();
      up();
    });
    video.addEventListener('pause', function () {
      up();
      wrap.classList.remove('is-expanded');
    });
    video.addEventListener('ended', function () {
      wrap.classList.remove('is-expanded');
      if (mask) { mask.classList.remove('is-hide'); }
      if (bar) { bar.hidden = true; }
      if (toggle) { toggle.innerHTML = '<i class="ri-play-fill"></i>'; }
      if (fill) { fill.style.width = '0%'; }
      played = false;
    });
    wrap.addEventListener('mouseenter', showBar);
    wrap.addEventListener('mouseleave', hideBar);
    wrap.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.mc-video-bar')) { return; }
      if (e.target.closest && e.target.closest('.mc-video-play')) { return; }
      if (!played) { startPlay(); return; }
      if (video.paused) { video.play().catch(function () {}); }
      else { video.pause(); }
      showBar();
    });
  }

  /* ---------- 实况图：点击播放 / 取消（无播放按钮，点整图切换） ---------- */
  function bindLive(live) {
    if (live.getAttribute('data-lb')) { return; }
    live.setAttribute('data-lb', '1');
    var video = live.querySelector('.mc-live-video');
    if (!video) { return; }
    /* 播放时容器切换为视频比例（框贴合视频，无留白）；暂停/结束恢复图片比例 */
    video.addEventListener('loadedmetadata', function () {
      if (video.videoWidth && video.videoHeight && live.classList.contains('is-playing')) {
        live.style.aspectRatio = video.videoWidth + ' / ' + video.videoHeight;
      }
    });
    video.addEventListener('pause', function () {
      if (live.classList.contains('is-playing') && !video.ended) {
        live.classList.remove('is-playing');
      }
      live.style.aspectRatio = '';
    });
    video.addEventListener('ended', function () {
      live.classList.remove('is-playing');
      live.style.aspectRatio = '';
    });
  }

  function liveToggle(live) {
    var video = live.querySelector('.mc-live-video');
    if (!video) {
      /* 纯图片实况（无视频）：点击打开灯箱查看原图 */
      var limg = live.querySelector('.mc-live-img');
      if (limg) {
        var big = limg.getAttribute('data-original') || limg.currentSrc || limg.src || '';
        /* 代理 URL（img.php?src=…&w=800）时提取原图地址 */
        var mBig = big.match(/[?&]src=([^&]+)/);
        if (mBig) {
          try { big = decodeURIComponent(mBig[1]); } catch (e) {}
        }
        if (big && window.TriLightbox) {
          window.TriLightbox.open([big], 0);
        }
      }
      return;
    }
    if (live.classList.contains('is-playing')) {
      live.classList.remove('is-playing');
      video.pause();
      video.currentTime = 0;
    } else {
      live.classList.add('is-playing');
      if (video.videoWidth && video.videoHeight) {
        live.style.aspectRatio = video.videoWidth + ' / ' + video.videoHeight;
      }
      video.play().catch(function () {
        /* 视频加载/播放失败：恢复静态图并提示 */
        live.classList.remove('is-playing');
        live.style.aspectRatio = '';
        toast('实况视频加载失败，请检查视频地址');
      });
    }
  }

  /* ---------- 音乐卡片播放（联动 TriPlayer） ---------- */
  function playMusicCard(card) {
    if (!window.TriPlayer) { toast('播放器未就绪'); return; }
    var api = metingApi();
    var server = card.getAttribute('data-server') || 'netease';
    var id = card.getAttribute('data-id') || '';
    var title = card.getAttribute('data-title') || '未知歌曲';
    var artist = card.getAttribute('data-artist') || '';
    var pic = card.getAttribute('data-pic') || '';
    var btn = card.querySelector('.mc-music-play');
    var st = window.TriPlayer.state();
    var cur = st && st.idx === 0 && st.playing && card.classList.contains('is-active');
    if (cur) {
      window.TriPlayer.toggle();
      card.classList.remove('is-active');
      if (btn) { btn.innerHTML = '<i class="ri-play-fill"></i>'; }
      return;
    }
    window.TriPlayer.playSong({
      server: server,
      url_id: id,
      title: title,
      artist: artist,
      pic: pic || (api ? api + '?server=' + encodeURIComponent(server) + '&type=pic&id=' + encodeURIComponent(id) : ''),
      pic_api: tri3Cover(pic || (api ? api + '?server=' + encodeURIComponent(server) + '&type=pic&id=' + encodeURIComponent(id) : '')),
      lrc: api ? api + '?server=' + encodeURIComponent(server) + '&type=lrc&id=' + encodeURIComponent(id) : '',
      src: ''
    });
    card.classList.add('is-active');
    if (btn) { btn.innerHTML = '<i class="ri-pause-fill"></i>'; }
  }

  /* ---------- 事件委托：发布 / 删除 / 点赞 / 置顶 / 媒体开关 / 播放 ---------- */
  document.addEventListener('click', function (e) {
    var CFG = getCfg();
    if (!CFG.api) { return; }

    /* 图文混排：在文字中插入媒体标记（composer / 弹窗通用） */
    var ins = e.target && e.target.closest ? e.target.closest('[id$="-ins-img"], [id$="-ins-music"], [id$="-ins-video"], [id$="-ins-live"], [id$="-ins-bili"]') : null;
    if (ins) {
      e.stopPropagation();
      var m = ins.id.match(/-ins-(img|music|video|live|bili)$/);
      if (m) {
        insertMediaTag(ins.id.slice(0, ins.id.indexOf('-ins-') + 1), m[1]);
      }
      return;
    }

    /* 说说发布弹窗入口（仅登录态显示按钮） */
    var say = e.target && e.target.closest ? e.target.closest('.tri-say-entry') : null;
    if (say) {
      if (!(window.TRI3_LOGIN || {}).logged) { return; }
      e.stopPropagation();
      openSayDialog();
      return;
    }

    /* composer 发布 */
    var pub = e.target && e.target.closest ? e.target.closest('#moment-publish') : null;
    if (pub) {
      e.stopPropagation();
      doPublish('moment-', pub);
      return;
    }

    /* composer 媒体开关 */
    var toggles = {
      '#moment-img-toggle': 'moment-imgbox',
      '#moment-music-toggle': 'moment-musicbox',
      '#moment-video-toggle': 'moment-videobox',
      '#moment-live-toggle': 'moment-livebox',
      '#moment-bili-toggle': 'moment-bilibox'
    };
    for (var sel in toggles) {
      var t = e.target && e.target.closest ? e.target.closest(sel) : null;
      if (t) {
        var box = document.getElementById(toggles[sel]);
        if (box) {
          var show = box.style.display === 'none';
          box.style.display = show ? 'block' : 'none';
          t.classList.toggle('is-active', show);
          if (show && sel === '#moment-img-toggle') {
            var ta = document.getElementById('moment-images');
            if (ta) { ta.focus(); }
          }
        }
        e.stopPropagation();
        return;
      }
    }

    /* composer 音乐预览 */
    var mpv = e.target && e.target.closest ? e.target.closest('#moment-music-preview') : null;
    if (mpv) {
      previewMusic('moment-');
      return;
    }

    /* 发布框试听 */
    var trial = e.target && e.target.closest ? e.target.closest('.mc-music-pv-play') : null;
    if (trial) {
      e.stopPropagation();
      previewTrial(trial);
      return;
    }

    /* 音乐卡片播放 */
    var mcard = e.target && e.target.closest ? e.target.closest('.mc-music') : null;
    if (mcard) {
      e.stopPropagation();
      playMusicCard(mcard);
      return;
    }

    /* 视频播放器（mask 播放按钮） */
    var vplay = e.target && e.target.closest ? e.target.closest('.mc-video-play') : null;
    if (vplay) {
      var vwrap = vplay.closest('.mc-video');
      var video = vwrap && vwrap.querySelector('.mc-video-el');
      if (video) { bindVideo(video); video.play().catch(function () {}); }
      return;
    }

    /* 视频控制条按钮（toggle/full/progress 已各自绑定，这里兜底） */
    var vtoggle = e.target && e.target.closest ? e.target.closest('.mc-vb-toggle') : null;
    if (vtoggle) {
      var vw2 = vtoggle.closest('.mc-video');
      var video2 = vw2 && vw2.querySelector('.mc-video-el');
      if (video2) {
        bindVideo(video2);
        if (video2.paused) { video2.play().catch(function () {}); }
        else { video2.pause(); }
      }
      return;
    }
    var vfull = e.target && e.target.closest ? e.target.closest('.mc-vb-full') : null;
    if (vfull) {
      var vw3 = vfull.closest('.mc-video');
      if (vw3) {
        if (document.fullscreenElement) { document.exitFullscreen().catch(function () {}); }
        else if (vw3.requestFullscreen) { vw3.requestFullscreen().catch(function () {}); }
      }
      return;
    }

    /* 实况图：完全委托切换播放/取消 */
    var live = e.target && e.target.closest ? e.target.closest('.mc-live') : null;
    if (live) {
      e.stopPropagation();
      bindLive(live);
      liveToggle(live);
      return;
    }

    /* 点赞 */
    var like = e.target && e.target.closest ? e.target.closest('.moment-like') : null;
    if (like) {
      if (like.classList.contains('liked')) { toast('已经点过赞啦'); return; }
      var cid = like.getAttribute('data-cid');
      post({ do: 'like', cid: cid }, function (res) {
        like.classList.add('liked');
        var num = like.querySelector('.moment-like-num');
        if (num) { num.textContent = res.like; }
        try {
          document.cookie = 'tri_mlike_' + cid + '=1; path=/; max-age=' + (3600 * 24 * 365);
        } catch (e2) {}
      });
      return;
    }

    /* 删除（管理员） */
    var del = e.target && e.target.closest ? e.target.closest('.moment-del') : null;
    if (del) {
      var did = del.getAttribute('data-cid');
      if (!window.confirm('确定删除这条说说？')) { return; }
      post({ do: 'del', cid: did }, function () {
        toast('已删除');
        var card = document.querySelector('.moment-card[data-cid="' + did + '"]');
        if (card) { card.remove(); }
      });
      return;
    }

    /* 置顶（管理员） */
    var stick = e.target && e.target.closest ? e.target.closest('.moment-stick') : null;
    if (stick) {
      var sid = stick.getAttribute('data-cid');
      post({ do: 'sticky', cid: sid }, function () {
        location.reload();
      });
    }
  });

  /* 视频播放器：timeupdate / 其他事件统一绑定（委托） */
  document.addEventListener('play', function (e) {
    var v = e.target;
    if (v && v.tagName === 'VIDEO' && v.closest && v.closest('.mc-video')) {
      bindVideo(v);
    }
  }, true);

  /* 首次初始化 */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', TriMomentInit);
  } else {
    TriMomentInit();
  }

})();
