/* TriM3 · 懒加载 + 图片失败兜底 + 头像多源兜底 + content-visibility 优化
 *
 * v1.2.87：
 *   0) 图片按视口放行：观察器提前量 900px → 300px，改为队列 + 最多 10 张并发（saveData/2G 为 2），
 *      按文档位置由近到远放行；完成/失败/12s 超时才放下一张
 * v1.2.85：
 *   1) 头像超时/换源只对「可见头像」生效（IntersectionObserver 门控 + 离屏直接不抢跑）
 *      —— 修掉 v1.2.85 会让离屏头像提前发请求、扎堆打镜像的问题
 *   2) 头像超时 4s → 3s
 * v1.2.85：
 *   3) 邮箱头像多源自动换源：cn.cravatar.com → weavatar.com → cravatar.cn → 首字母占位
 *      （与 lib/helpers.php 的 tri3_gravatar_hosts() 保持一致，改一处要同步另一处）
 * v1.2.81：
 *   4) 懒加载提前量 220px → 900px
 *   5) 全站统一失败兜底链：① 原图（代理取回 src / CDN 摘 imageView2 保留签名）
 *      → ② data-fallback → ③ 内联 SVG 占位；捕获阶段接管，Swup 换页后新插入的图同样生效
 *
 * 原有能力保持：data-src/data-srcset 懒加载、content-visibility、Swup 重扫
 */
(function () {
  'use strict';

  var ioImg = null, ioAv = null;
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var AVATAR_TIMEOUT = 3000;

  /* ===== v1.2.87 视口驱动图片队列 =====
   * 目标：图片按「是否进/靠近视口」放行，不再一进页面就整批拉。
   *  - 初始视口内的图立即可发；下方图片要进到视口下方 300px 才发。
   *  - 放行顺序按文档位置由近到远（队列里始终先给最靠近视口的图名额）。
   *  - 最多 10 张并发（由 Q.max 决定；saveData / 2G 时 2 张）；一张完成、失败或超时才放行下一张。
   */
  var Q = { list: [], inflight: 0, max: 10, armed: false };
  (function () {
    try {
      var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection || {};
      if (c.saveData === true) { Q.max = 2; return; }
      if (/^(slow-)?2g$/.test(String(c.effectiveType || ''))) { Q.max = 2; }
    } catch (e) { /* 不支持 NetworkInformation 就按默认 10 张 */ }
  })();

  /* 文档位置（offsetParent 链累计，content-visibility 造成的空 rect 也不影响排序） */
  function docTop(img) {
    var y = 0, n = img;
    while (n) { y += n.offsetTop || 0; n = n.offsetParent; }
    return y;
  }

  /* 图片自己被 CSS 隐藏（display:none）时 IntersectionObserver 永远观察不到它，
     改观察最近一个「能渲染的项目级盒子」祖先：祖先进视口就放行它等着的图。
     宿主高度超过视口 60% 说明这只是个大容器（网格/主体），拿它当宿主会把整页图一次性放行，
     这种情况宁可先挂着，等卡片自己渲染出来再观察。 */
  function waitHost(img) {
    var max = (window.innerHeight || 800) * 0.6;
    var p = img.parentNode, depth = 0;
    while (p && p.nodeType === 1 && p !== document.body && depth < 2) {
      depth++;
      var cs = window.getComputedStyle ? window.getComputedStyle(p) : null;
      if (cs && cs.display !== 'none') {
        var r = p.getBoundingClientRect();
        /* 只认“项目级近亲”：太高的容器（网格/整块主体）一进视口会把一片图一次性放行，
           那种情况宁可挂着等图自己被渲染出来 */
        if (r.height > 0 && r.height <= max) { return p; }
      }
      p = p.parentNode;
    }
    return null;
  }

  function bindHost(host, img) {
    if (!host.__triWait) { host.__triWait = []; }
    host.__triWait.push(img);
    img.__triHost = 1;                                      /* 位置取不到，改按宿主位置判定 */
    if (ioImg) { ioImg.observe(host); }
  }

  /* 既观察不到自己、也找不到合适宿主的图（如还没渲染出来的瀑布流相册）：先挂着，
     等滚动 / 重扫 / 一次性延时检查时，它自己被渲染出来再交给观察器。 */
  var deferred = [];

  function observeImg(img) {
    if (!ioImg) { loadImg(img); return; }
    var r = img.getBoundingClientRect();
    if (r.width > 0 || r.height > 0) { ioImg.observe(img); return; }
    var host = waitHost(img);
    if (host) { bindHost(host, img); return; }
    if (deferred.indexOf(img) < 0) { deferred.push(img); }
  }

  function pumpDeferred() {
    if (!deferred.length) { return; }
    var rest = [];
    deferred.forEach(function (im) {
      if (!im.parentNode) { return; }                 /* 换页后已被移除 */
      if (deferred.indexOf(im) < 0) { return; }       /* 期间已被别的分支接管 */
      var r = im.getBoundingClientRect();
      if (r.width > 0 || r.height > 0) {
        if (ioImg) { ioImg.observe(im); }
        return;
      }
      var host = waitHost(im);
      if (host) { bindHost(host, im); return; }
      rest.push(im);
    });
    deferred = rest;
  }

  /* 滚动时补查一次挂起的图（每帧最多一次） */
  var deferTick = false;
  window.addEventListener('scroll', function () {
    if (deferTick) { return; }
    deferTick = true;
    var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 32); };
    raf(function () { deferTick = false; pumpDeferred(); pump(); });
  }, { passive: true });

  /* 整页布局是否已经稳定：文档高度 150ms 没再变才算稳。
     瀑布流 / 字体 / 动态插入都会改布局，布局还在变时元素位置不可信 */
  var __lastH = -1, __stableAt = 0, __stableTries = 0;
  function layoutStable() {
    var h = document.documentElement ? document.documentElement.scrollHeight : 0;
    var now = Date.now();
    if (h !== __lastH) { __lastH = h; __stableAt = now; return false; }
    if (now - __stableAt > 150) { return true; }
    return false;
  }

  /* 放行前用当前布局重判位置：队列里等太久的图可能已经跑远（或正在被布局变动挤走） */
  function nearNow(img) {
    if (img.__triHost) { return true; }                     /* 借宿主可见性放行的图：位置本身就是 0，按宿主算 */
    var r = img.getBoundingClientRect();
    if (r.height === 0 && r.width === 0) { return false; }  /* 还没被渲染（display:none / content-visibility 跳过）：
                                                              位置不可信，交给宿主或挂起队列，别按位置放行 */
    var vh = window.innerHeight || 800;
    return r.bottom > -(vh + 120) && r.top < vh + 120;
  }

  function inInitialView(img) {
    var r = img.getBoundingClientRect();
    var vh = window.innerHeight || 800;
    return r.height > 0 && r.top < vh && r.bottom > 0;
  }

  function isPlaceholder(img) {
    var src = img.currentSrc || img.getAttribute('src') || '';
    return src.indexOf('data:') === 0;
  }

  /* 首屏立即放行；首屏以外的图等页面 load（或 1.5s 兜底）后再放行：
     瀑布流 / 字体 / 动态插入都会改布局，布局还在变的时候会把下方很远的图误判成“在视口里”提前拉走 */
  function armQueue() {
    if (Q.armed) { return; }
    Q.armed = true;
    pump();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', armQueue); } else { armQueue(); }
  setTimeout(armQueue, 1200);

  function enqueueImg(img) {
    if (!img || img.__triQ) { return; }
    img.__triQ = 1;
    Q.list.push(img);
    pump();
  }

  function pump() {
    while (Q.inflight < Q.max && Q.list.length) {
      Q.list.sort(function (a, b) { return docTop(a) - docTop(b); });
      var idx = -1, i, el, warm = false;
      for (i = 0; i < Q.list.length; i++) {
        el = Q.list[i];
        if (!el.parentNode) { Q.list.splice(i, 1); i--; continue; }             /* 已被换页/移除 */
        if (!el.hasAttribute('data-src') && !el.hasAttribute('data-srcset')) {  /* 已被别的分支处理 */
          Q.list.splice(i, 1); i--; continue;
        }
        if (!nearNow(el)) { el.__triNear = 0; continue; }                      /* 期间跑远了：继续挂着 */
        if (!Q.armed) { warm = true; continue; }   /* 页面 load 前一律不放行：瀑布流/字体回填会把图先堆在顶部，那时位置不可信 */          /* 页面还没 load 完：首屏以外再等等 */
        idx = i; break;                       /* 在视口附近就直接放行（固有尺寸已消掉回流，位置可信） */
      }
      if (idx < 0) {
        return;                                   /* 队列里没有该放的：不重试、不预取远处的图 */
      }
      var img = Q.list.splice(idx, 1)[0];
      img.__triQ = 2;
      Q.inflight++;
      (function (el) {
        var settled = false;
        var timer = setTimeout(function () { finish(); }, 12000);   /* 卡住的请求也要把名额放出来 */
        function finish() {
          if (settled) { return; }
          settled = true;
          clearTimeout(timer);
          Q.inflight--;
          pump();
        }
        loadImg(el, finish);
        if (el.complete && el.naturalWidth > 0 && !isPlaceholder(el)) { finish(); } /* 命中缓存等同步完成的情况 */
      })(img);
    }
  }

  /* 邮箱头像多源（顺序即优先级，与 lib/helpers.php 的 tri3_gravatar_hosts() 一一对应） */
  var AV_HOSTS = ['cn.cravatar.com/avatar/', 'weavatar.com/avatar/', 'cravatar.cn/avatar/'];

  /* 内容图占位：灰底图片图标（内联，不产生任何网络请求） */
  var PH_IMG = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">' +
    '<rect width="64" height="64" fill="#e9ecf2"/>' +
    '<circle cx="23" cy="23" r="6" fill="#bcc5d3"/>' +
    '<path d="M10 50l15-17 10 11 7-8 12 14z" fill="#bcc5d3"/></svg>'
  );

  function isAvatar(img) {
    if (!img) { return false; }
    var cls = (img.className || '') + ' ' + (img.parentNode && img.parentNode.className ? img.parentNode.className : '');
    if (/avatar/i.test(cls)) { return true; }
    return !!(img.closest && img.closest('.comment-avatar, .mc-avatar, .profile-avatar, .tri3-avatar'));
  }

  /* 头像是否在视口附近（离屏头像交给 IntersectionObserver，避免抢跑） */
  function nearViewport(img) {
    var r = img.getBoundingClientRect();
    var vh = window.innerHeight || 800;
    return r.bottom > -240 && r.top < (vh + 240);
  }

  /* 头像占位：源码 hash 取色 + 首字母（中英文皆可） */
  function avatarPH(img) {
    var ch = (img.getAttribute('alt') || '').replace(/^[\s@]+/, '').charAt(0);
    if (!ch) {
      var scope = img.closest && img.closest('.comment-item, .moment-card, .profile-card, .tri3-comment');
      var node = scope && scope.querySelector('.comment-author, .mc-name, .profile-name, .tri3-name');
      if (node) { ch = (node.textContent || '').replace(/^[\s@]+/, '').charAt(0); }
    }
    if (!ch || !/[\w\u4e00-\u9fa5]/.test(ch)) { ch = '·'; }
    var s = img.getAttribute('src') || img.getAttribute('data-src') || '';
    var h = 0;
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) % 360; }
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80">' +
      '<rect width="80" height="80" rx="40" fill="hsl(' + h + ',40%,62%)"/>' +
      '<text x="40" y="53" font-size="36" font-family="-apple-system,\'Microsoft YaHei\',sans-serif" ' +
      'fill="#ffffff" text-anchor="middle">' + ch + '</text></svg>'
    );
  }

  /* 邮箱头像换源：从当前 URL 取 md5 与尺寸，返回下一个源的地址；已试完返回 '' */
  function nextAvatarSrc(img) {
    var cur = img.getAttribute('src') || '';
    var m = cur.match(/\/([0-9a-f]{32})(?:[?#]|$)/i);
    if (!m) { return ''; }                                   /* QQ 头像等非邮箱源不换 */
    var size = (cur.match(/[?&]s=(\d+)/) || [])[1] || '80';
    var have = -1, i;
    for (i = 0; i < AV_HOSTS.length; i++) { if (cur.indexOf(AV_HOSTS[i]) >= 0) { have = i; break; } }
    var next = parseInt(img.getAttribute('data-av-src') || '0', 10) || 0;
    if (have >= 0 && next <= have) { next = have + 1; }
    if (next >= AV_HOSTS.length) { return ''; }
    img.setAttribute('data-av-src', String(next));
    return 'https://' + AV_HOSTS[next] + m[1].toLowerCase() + '?d=identicon&s=' + size;
  }

  /* ① 原图：从当前 URL 推出「不带转换参数的原始地址」；推不出返回 '' */
  function originUrl(u) {
    if (!u || u.indexOf('data:') === 0 || u.indexOf('blob:') === 0) { return ''; }
    try {
      var a = document.createElement('a');
      a.href = u;
      var q = a.search;
      if (!q) { return ''; }
      /* 站点图片代理：取回 query 里的真实源地址（代理不可用/转换失败时直连原图） */
      var m = /[?&]src=([^&]+)/.exec(q);
      if (/\/api\/img\.php$/.test(a.pathname) && m) {
        var raw = decodeURIComponent(m[1]);
        return (raw && raw !== u) ? raw : '';
      }
      /* CDN 转换参数：只摘掉转换段，保留 auth_key 签名 */
      if (/imageView2|imageMogr2|x-oss-process|imageslim/i.test(q)) {
        var base = a.href.slice(0, a.href.length - q.length);
        var sig = a.href.match(/[?&]((?:auth_key|token|sign|signature)=[^&]*)/i);
        if (sig) { base += '?' + sig[1]; }
        return base !== a.href ? base : '';
      }
    } catch (e) { /* 解析失败就走下一步兜底 */ }
    return '';
  }

  function markLoaded(img) {
    img.classList.remove('img-loading');
    img.classList.add('is-loaded');
  }

  function applySrc(img, url) {
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.removeAttribute('data-srcset');
    img.removeAttribute('data-sizes');
    img.setAttribute('data-fb', String((parseInt(img.getAttribute('data-fb') || '0', 10) || 0) + 1));
    img.src = url;
  }

  function avatarPlaceholder(img) {
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.classList.add('img-fallback');
    img.src = avatarPH(img);
    markLoaded(img);
  }

  /* 失败兜底链：头像先换源 → ① 原图 → ② data-fallback → ③ 内联占位 */
  function handleError(img) {
    if (!img || img.tagName !== 'IMG') { return; }
    if (img.hasAttribute('onerror')) { return; }              /* 自带显隐策略的图不接管 */
    if (img.classList.contains('img-fallback')) { return; }    /* 占位图自身再失败就停手，防循环 */
    var step = parseInt(img.getAttribute('data-fb') || '0', 10) || 0;
    var cur = img.getAttribute('src') || '';
    if (isAvatar(img)) {
      var av = nextAvatarSrc(img);
      if (av) { img.src = av; if (nearViewport(img)) { watchAvatar(img, true); } return; }
      avatarPlaceholder(img);
      return;
    }
    if (step === 0) {
      var o = originUrl(cur);
      if (o) { applySrc(img, o); return; }
    }
    if (step <= 1) {
      var fb = img.getAttribute('data-fallback') || '';
      if (fb && fb !== cur) { applySrc(img, fb); return; }
    }
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.setAttribute('data-fb', '3');
    img.classList.add('img-fallback');
    img.src = PH_IMG;
    markLoaded(img);
  }

  /* 捕获阶段接管全站图片加载失败（error 不冒泡，捕获可达） */
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG') { handleError(t); }
  }, true);

  /* 头像超时兜底：只对可见头像计时，卡住就换源，都试完再落首字母占位 */
  function watchAvatar(img, rearm) {
    if (!img || !isAvatar(img)) { return; }
    if (!rearm) {
      if (img.getAttribute('data-av-watch')) { return; }
      img.setAttribute('data-av-watch', '1');
    }
    var t = setTimeout(function () {
      if (img.classList.contains('img-fallback')) { return; }
      if (img.complete && img.naturalWidth > 0) { markLoaded(img); return; }
      if (!nearViewport(img)) { return; }            /* 离屏：不抢跑，等 IO 再次触发 */
      handleError(img);
    }, AVATAR_TIMEOUT);
    img.addEventListener('load', function () { clearTimeout(t); markLoaded(img); }, { once: true });
    img.addEventListener('error', function () { clearTimeout(t); }, { once: true });
  }

  /* 头像进门控：可见（或即将可见）才开始计时与换源，保住 loading=lazy 的省流量效果 */
  function observeAvatar(img) {
    if (!img || !isAvatar(img)) { return; }
    if (img.complete && img.naturalWidth > 0) { markLoaded(img); return; }
    if (ioAv) {
      ioAv.observe(img);
    } else {
      watchAvatar(img);
    }
  }

  function loadImg(img, done) {

    /* 支持 data-srcset / data-sizes —— 懒加载的图也能按视口与 DPR 选档 */
    if (img.dataset && img.dataset.srcset) {
      img.srcset = img.dataset.srcset;
      if (img.dataset.sizes) { img.sizes = img.dataset.sizes; }
      img.removeAttribute('data-srcset');
      img.removeAttribute('data-sizes');
    }
    if (img.dataset && img.dataset.src) {
      var src = img.dataset.src;
      img.removeAttribute('data-src');
      img.classList.remove('img-lazy');
      img.classList.add('img-loading');
      if (!img.getAttribute('decoding')) { img.setAttribute('decoding', 'async'); }
      img.addEventListener('load', function () {
        img.classList.remove('img-loading');
        img.classList.add('is-loaded');
        if (done) { done(); }
      }, { once: true });
      img.addEventListener('error', function () {
        img.classList.remove('img-loading');
        img.classList.add('is-loaded');
        img.classList.add('img-error');
        if (done) { done(); }
      }, { once: true });
      img.src = src;
    } else {
      img.classList.add('is-loaded');
      if (done) { done(); }
    }
    observeAvatar(img);
  }

  function markCv(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;
    var els = root.querySelectorAll('.tri3-cv:not([style*="content-visibility"])');
    Array.prototype.forEach.call(els, function (el) {
      el.style.contentVisibility = 'auto';
      el.style.containIntrinsicSize = 'auto 220px';
    });
  }

  function scan(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;
    var imgs = root.querySelectorAll('img[data-src], img[data-srcset]');
    Array.prototype.forEach.call(imgs, function (img) {
      if (img.complete && img.naturalWidth > 0 && !isPlaceholder(img)) {
        img.classList.add('is-loaded');
        img.removeAttribute('data-src');
        return;
      }
      img.classList.add('img-lazy');
      observeImg(img);
    });
    /* 已经带 src 的图（含换页后新插入的）：补齐过渡态与头像门控 */
    var ready = root.querySelectorAll('img:not([data-src]):not([data-srcset])');
    Array.prototype.forEach.call(ready, function (img) {
      if (img.complete && !isPlaceholder(img)) { img.classList.add('is-loaded'); }
      observeAvatar(img);
    });
    markCv(root);
    pumpDeferred();
  }

  function init() {
    if (typeof IntersectionObserver === 'undefined') { return; }
    if (!ioImg) {
      ioImg = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) { return; }
          var t = en.target;
          if (t.__triWait) {                          /* 宿主进视口：放行它内部等待的图 */
            t.__triWait.forEach(function (im) { enqueueImg(im); });
            t.__triWait = null;
          } else {
            enqueueImg(t);
          }
          ioImg.unobserve(t);
        });
      }, { rootMargin: '120px 0px' });
    }
    if (!ioAv) {
      ioAv = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            watchAvatar(en.target);
            ioAv.unobserve(en.target);
          }
        });
      }, { rootMargin: '240px 0px' });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); scan(); });
  } else {
    init();
    scan();
  }

  /* 挂起的图可能在布局/瀑布流渲染完成后才出现，做两次一次性补查（不是轮询） */
  setTimeout(function () { pumpDeferred(); }, 400);
  setTimeout(function () { pumpDeferred(); }, 1500);

    /* v1.2.89：有界补扫，把 Swup / 无限加载后插入的图交给模块自己的 scan */
  var __soonTick = false, __renderLeft = 3, __lastRender = 0;
  function scanSoon() {
    if (__soonTick) { return; }
    __soonTick = true;
    var raf = window.requestAnimationFrame || function (fn) { return setTimeout(fn, 32); };
    raf(function () {
      __soonTick = false;
      scan(document);
      var p = document.querySelectorAll('img[data-src]');
      var vh = window.innerHeight || 800, hit = false, i, r;
      for (i = 0; i < p.length; i++) {
        r = p[i].getBoundingClientRect();
        if (r.height > 0 && r.top < vh + 120 && r.bottom > -(vh + 120)) { hit = true; break; }
      }
      if (hit && __renderLeft > 0 && Date.now() - __lastRender > 900) {
        __lastRender = Date.now();
        __renderLeft--;
        init(); scan(document);
      }
    });
  }
  window.addEventListener('scroll', scanSoon, { passive: true });
  window.addEventListener('resize', scanSoon, { passive: true });
  if (window.MutationObserver) { new MutationObserver(scanSoon).observe(document.documentElement, { childList: true, subtree: true }); }
  setTimeout(scanSoon, 700); setTimeout(scanSoon, 2000); setTimeout(scanSoon, 4000);
  window.Tri3Lazy = {
    update: function (scope) { init(); scan(scope); },
    loadNow: loadImg
  };

  document.addEventListener('tri3:render', function () {
    init();
    scan();
  });

  /* 过渡细化：兼容老浏览器与 Swup 换页 */
  if (!reduced) {
    document.documentElement.classList.add('tri3-anim');
  }
})();
