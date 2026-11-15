/* TriM3 · 主题交互脚本（Swup 适配版）

 *

 * 设计原则（配合全站 Swup）：

 *  - 所有事件一律使用 document 级事件委托（Swup 切换 DOM 后仍有效，不会重复绑定）

 *  - 需要按页面状态重建的（轮播定时器 / 首页菜单初始态 / FAB 显隐）放在 TriM3Init()

 *    TriM3Init 必须幂等：可被重复调用（页面加载一次 + 每次 Swup 后一次）

 */

(function () {

  'use strict';

  if (window.__triMainInit) { return; }



 window.__triMainInit = true;

  


  var root = document.documentElement;



  /* ============================================================

     TriM3Init —— Swup 后 / 页面加载时重建页面状态（幂等）

     ============================================================ */

  function TriM3Init() {

    /* 同一份 DOM 只初始化一次。

       重复来源：页面加载时 main.js 自启一次、tri-app 的 tri3 控制器 connect() 又调
       runHooks → TriHooks.init → 这里一次；每次 Swup 换页后 tri-swup 的 page:view
       与 tri-app 的 connect() 也各跑一次 runHooks。结果是首屏 2 次、每次换页 2 次，
       轮播定时器/观察者会被叠加两套。用「div.layout 元素身份」判重：Swup 换容器后
       自然是新元素，一轮只跑一次。 */
    var triInitLayout = document.querySelector('div.layout');
    if (triInitLayout && TriM3Init.__layout === triInitLayout) { return; }
    TriM3Init.__layout = triInitLayout;

    /* ---------- 评论提交后定位新评论（插件 cookie + Typecho 锚点双通道） ---------- */

    (function () {

      try {

        var coid = null;

        var m = document.cookie.match(/(?:^|; )tri_last_comment=(\d+)/);

        if (m) { coid = m[1]; document.cookie = 'tri_last_comment=; Max-Age=0; path=/'; }

        if (!coid) {

          var hm = location.hash.match(/^#comment-(\d+)$/);

          if (hm) { coid = hm[1]; }

        }

        if (!coid) { return; }

        var el = document.getElementById('comment-' + coid);

        if (!el) { return; }

        /* 评论区高度固定：容器内滚到评论可见 + 页面滚到评论区顶部（偏移 120 给头部）。

           load 后执行 + 延迟重试，避免加载早期布局变动导致偏移 */

        var locate = function () {

          try {

            var wrap = el.closest('.tr-chat-card') || el.closest('.tri-cmt-body') || el;

            try { el.scrollIntoView({ behavior: 'auto', block: 'nearest' }); } catch (e2) {}

            var y = wrap.getBoundingClientRect().top + window.scrollY - 120;

            window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });

          } catch (e) {}

        };

        var flashOn = function () {

          try {

            el.classList.add('comment-flash');

            setTimeout(function () { el.classList.remove("comment-flash"); }, 3400);

          } catch (e) {}

        };

        flashOn();

        if (document.readyState === 'complete') {

          setTimeout(locate, 250);

        } else {

          window.addEventListener('load', function () { setTimeout(locate, 300); });

        }

        setTimeout(locate, 1800);

      } catch (e) {}

})();



    /* ---------- 深色模式按钮图标 ---------- */

    var isDark = root.classList.contains('dark');

    document.querySelectorAll('#theme-toggle, #theme-toggle-m, #theme-toggle-fab').forEach(function (btn) {

      var i = btn.querySelector('i');

      if (i) { i.className = isDark ? 'ri-sun-line' : 'ri-moon-line'; }

    });



    /* 悬浮按钮组定位：由 CSS calc 贴大框外右缘（纯 CSS，无需 JS） */

    /* ---------- FAB 回到顶部显隐 ---------- */

    var fab = document.getElementById('back-top');

    if (fab) {

      if (window.scrollY > 480) { fab.classList.add('show'); }

      else { fab.classList.remove('show'); }

    }

    /* ---------- 悬浮目录挂载：必须挂在 body 上，不能留在 .layout 内 ----------
       .layout 带 backdrop-filter: blur(8px)，会形成 position: fixed 的包含块：
       目录会按 .layout 定位并随页面滚动。所以 layout 内只留 [data-tri3-toc-marker]，
       真正的 aside 由这里注入到 body（Swup 换页后据此重建/移除）。 */
    function syncTocAside() {
      var marker = document.querySelector('[data-tri3-toc-marker]');
      var aside = document.querySelector('body > .tri3-toc-float');
      if (!marker) {
        if (aside) { aside.parentNode.removeChild(aside); }
        return;
      }
      if (!aside) {
        aside = document.createElement('aside');
        aside.className = 'tri3-toc tri3-toc-float';
        aside.setAttribute('data-tri3-toc', '');
        aside.setAttribute('aria-label', '文章目录');
        aside.innerHTML = '<div class="tri3-toc-head"><i class="ri-list-unordered"></i><span>目录</span></div>'
          + '<div class="tri3-toc-body"><ol class="tri3-toc-list"></ol></div>';
        document.body.appendChild(aside);
      }
    }

    syncTocAside();

    /* ---------- 歌单页背景层：脚本注入到 body，离开歌单页时清理残留 ----------
       Swup 只替换 div.layout，body 上的 #sp-bg 不会自动移除，所以每次初始化都按标记核对一次。 */
    if (!document.querySelector('[data-sp-bg-marker]')) {
      var staleSpBg = document.querySelector('body > .sp-bg');
      if (staleSpBg) { staleSpBg.parentNode.removeChild(staleSpBg); }
    }

    /* ---------- 目录按钮显隐：仅文章页且开启 TOC 时显示（Swup 后重跑，防残留） ---------- */

    var tocFabBtn = document.querySelector('.tri3-toc-fab');

    var hasTocBox = !!document.querySelector('[data-tri3-toc]');

    if (tocFabBtn) {

      if (hasTocBox) { tocFabBtn.classList.remove('tri3-hidden'); }

      else { tocFabBtn.classList.add('tri3-hidden'); }

    }

    /* 目录重建（Swup 后新文章页的 [data-tri3-toc] 列表为空，需重跑） */

    if (window.TriTocInit) { try { window.TriTocInit(); } catch (e) {} }

    /* ---------- 首页横向菜单：拖拽滚动 + 滚轮横向（项多时可滑动查看） ---------- */

    document.querySelectorAll('.home-menu-inner').forEach(function (el) {

      if (el.getAttribute('data-drag-bound')) { return; }

      el.setAttribute('data-drag-bound', '1');

      /* v1.2.58：拖拽状态改存元素自身（el.__triDrag），不再用闭包变量 */

      el.addEventListener('mousedown', function (e) {

        if (e.button !== 0) { return; }

        /* 链接（菜单项）上不启动拖拽：保证整框点击都响应，不被拖拽滚动吞掉 */
        if (e.target && e.target.closest && e.target.closest('a')) { return; }

        el.__triDrag.isDown = true; el.__triDrag.moved = false;

        el.__triDrag.startX = e.pageX; el.__triDrag.startLeft = el.scrollLeft;

        window.__triHomeDragEl = el;

      });

      /* v1.2.58：window 是单例，拖拽监听只注册一次，改由 __triHomeDragEl 指向当前元素。
         此前该监听写在每个 .home-menu-inner 的闭包里，Swup 换页后元素是新的、
         window 上的旧监听却永不摘除，导致越翻页每次鼠标移动要跑越多处理器。 */
      el.__triDrag = { isDown: false, moved: false, startX: 0, startLeft: 0 };

      if (!window.__triHomeDragBound) {

        window.__triHomeDragBound = 1;

        window.addEventListener('mousemove', function (e) {

          var t = window.__triHomeDragEl;

          var s = t && t.__triDrag;

          if (!s || !s.isDown) { return; }

          var dx = e.pageX - s.startX;

          if (Math.abs(dx) > 3) { s.moved = true; }

          t.scrollLeft = s.startLeft - dx;

        });

        window.addEventListener('mouseup', function () {

          var t = window.__triHomeDragEl;

          if (t && t.__triDrag) { t.__triDrag.isDown = false; }

        });

      }

      /* 拖拽后阻止误触发链接跳转 */

      el.addEventListener('click', function (e) {
        if (el.__triDrag.moved) {
          el.__triDrag.moved = false;
          if (!(e.target && e.target.closest && e.target.closest('a'))) {
            e.preventDefault(); e.stopPropagation();
          }
        }
      }, false);

      /* 滚轮横向滚动 */

      el.addEventListener('wheel', function (e) {

        if (el.scrollWidth > el.clientWidth) {

          e.preventDefault();

          el.scrollLeft += e.deltaY;

        }

      }, { passive: false });

    });



    /* ---------- Banner 轮播（幂等重建：先清旧定时器再启动） ---------- */

    var track = document.querySelector('.slider-track');

    if (track) {

      if (track._triTimer) { clearInterval(track._triTimer); }

      var slides = track.querySelectorAll('.slide');

      var dots = Array.prototype.slice.call(document.querySelectorAll('.slider-dots .dot'));

      var cur = 0;

      for (var j = 0; j < slides.length; j++) {

        if (slides[j].classList.contains('is-active')) { cur = j; }

      }

      var sec = parseInt(track.getAttribute('data-interval') || '5', 10);

      if (sec > 0) {
        var startCarousel = function() {
          if (track._triTimer) return;
          track._triTimer = setInterval(function () {
            cur = (cur + 1) % slides.length;
            for (var k = 0; k < slides.length; k++) {
              slides[k].classList.toggle('is-active', k === cur);
              if (dots[k]) { dots[k].classList.toggle('is-active', k === cur); }
            }
          }, sec * 1000);
        };
        var stopCarousel = function() {
          if (track._triTimer) { clearInterval(track._triTimer); track._triTimer = null; }
        };
        startCarousel();
        if (typeof IntersectionObserver !== 'undefined') {
          /* 重复初始化时先断开上一次的观察者，否则每次 init 都会多一个观察者盯同一个 track */
          if (track._triIO) { try { track._triIO.disconnect(); } catch (e) {} track._triIO = null; }
          var _carouselIO = new IntersectionObserver(function(entries) {
            entries.forEach(function(en) {
              if (en.isIntersecting) { startCarousel(); }
              else { stopCarousel(); }
            });
          }, { threshold: 0.1 });
          track._triIO = _carouselIO;
          _carouselIO.observe(track);
        }
      }

    }



    /* ---------- 首页菜单初始态（分类页显示「首页+文章+分类」，其余显示默认菜单；跟随当前页） ---------- */

    var nav = document.querySelector('.home-menu');

    if (nav) {

      var def = nav.querySelector('[data-menu-default]');

      var posts = nav.querySelector('[data-menu-posts]');

      if (posts) {

        if (document.body.getAttribute('data-in-category') === '1') {

          posts.hidden = false;

          if (def) { def.hidden = true; }

        } else {

          posts.hidden = true;

          if (def) { def.hidden = false; }

        }

      }

    }



    /* ---------- 首页列表局部切换：恢复高亮（列表容器由 Tri3HomeList 管理） ---------- */

    if (window.Tri3HomeList) {
      if (window.Tri3HomeList.syncFromUrl) {
        try { window.Tri3HomeList.syncFromUrl(); } catch(e) {}
      }

      try {
        if (history.state && history.state.tri3list) {
          window.Tri3HomeList.restore();
        } else if (document.getElementById('tri3-home-list')) {
          /* Swup 整页切回首页（非前进/后退）：以 DOM 权威重置列表状态，
             清除上一页面残留的页码/分类，避免无限加载加载错内容或不加载 */
          window.Tri3HomeList.resetFromDom();
        }
      } catch (e) {}
    }



    /* ---------- Joe 短代码版式（Swup 切换后重渲染 [tri-*] 短代码） ---------- */

    if (window.Tri3Joe) {

      try { window.Tri3Joe.render(); } catch (e) {}

    }



    /* ---------- 懒加载 + 滚动显现动效（Swup 切换后重扫新容器） ---------- */

    if (window.Tri3Lazy) {

      try { window.Tri3Lazy.update(); } catch (e) {}

    }

    /* ---------- 无限加载（加载更多进入视口自动加载；Swup 切换后重新观察新按钮） ---------- */

    try { tri3InfiniteInit(); } catch (e) {}



    /* ---------- 头像播放器 UI 同步（Swup 切换后恢复） ---------- */

    if (window.TriPlayerSync) {

      try { window.TriPlayerSync(); } catch (e) {}

    }



    /* ---------- 酷安式底部导航：图标实心化 + 点击涟漪（幂等，先定义供高亮逻辑调用） ---------- */
    if (!window.triSyncTabIcons) {

      var triFillCache = {};

      function triHasFill(cls) {

        if (cls in triFillCache) { return triFillCache[cls]; }

        try {

          var probe = document.createElement('i');

          probe.className = cls;

          probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;';

          document.body.appendChild(probe);

          var fillC = getComputedStyle(probe, '::before').content;

          document.body.removeChild(probe);

          var lineCls = cls.replace(/-fill\b/, '-line');

          var probe2 = document.createElement('i');

          probe2.className = lineCls;

          probe2.style.cssText = probe.style.cssText;

          document.body.appendChild(probe2);

          var lineC = getComputedStyle(probe2, '::before').content;

          document.body.removeChild(probe2);

          triFillCache[cls] = !!(fillC && fillC !== 'none' && fillC !== 'normal' && fillC !== lineC);

        } catch (e) { triFillCache[cls] = false; }

        return triFillCache[cls];

      }

      window.triSyncTabIcons = function () {

        document.querySelectorAll('.mobile-tab').forEach(function (a) {

          var i = a.querySelector('i');

          if (!i) { return; }

          var active = a.classList.contains('is-active');

          var cls = i.className || '';

          if (/\bri-[\w-]+-line\b/.test(cls)) {

            var fillCls = cls.replace(/-line\b/, '-fill');

            if (active && !triHasFill(fillCls)) { return; }

            i.className = active ? fillCls : fillCls.replace(/-fill\b/, '-line');

          }

        });

      };

      /* 触摸动效：触点液态光晕（pointerdown 从手指位置涌出光斑，pointerup 淡出；图标回弹） */
      window.triTabPress = function (ev) {

        var a = ev.currentTarget;

        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

        if (a.querySelector('.tri-press-glow')) { return; }

        var r = a.getBoundingClientRect();

        var x = (ev.clientX != null ? ev.clientX : (r.left + r.width / 2)) - r.left;

        var y = (ev.clientY != null ? ev.clientY : (r.top + r.height / 2)) - r.top;

        var glow = document.createElement('span');

        glow.className = 'tri-press-glow';

        glow.style.left = x + 'px';

        glow.style.top = y + 'px';

        a.appendChild(glow);

        a.classList.add('is-pressed');

      };

      window.triTabRelease = function (ev) {

        var a = ev.currentTarget;

        a.classList.remove('is-pressed');

        var g = a.querySelector('.tri-press-glow');

        if (g) {

          g.classList.add('out');

          setTimeout(function () { if (g.parentNode) { g.parentNode.removeChild(g); } }, 340);

        }

      };

    }

    /* 每次 TriM3Init 防重绑触摸动效（Swup 切换节点后自动补绑） */
    document.querySelectorAll('.mobile-tabbar .mobile-tab').forEach(function (a) {

      if (a.getAttribute('data-ripple-bound')) { return; }

      a.setAttribute('data-ripple-bound', '1');

      a.addEventListener('pointerdown', window.triTabPress || function () {}, { passive: true });

      a.addEventListener('pointerup', window.triTabRelease || function () {}, { passive: true });

      a.addEventListener('pointerleave', window.triTabRelease || function () {}, { passive: true });

      a.addEventListener('pointercancel', window.triTabRelease || function () {}, { passive: true });

    });

    /* ---------- 手机端底部导航 active 高亮（Swup 切换后刷新） ---------- */

    (function () {

      try {

        var tb = document.querySelector('.mobile-tabbar');

        if (!tb) { return; }

        var cur = (location.pathname || '/').replace(/\/+$/, '');

        if (cur === '' || cur === '/index.php') { cur = ''; }

        var links = tb.querySelectorAll('.mobile-tab');

        /* 同链接只高亮首个（首页链接指向同一 URL 时，避免多个 tab 同时激活） */
        var homeShown = false;

        links.forEach(function (a) {

          var href = a.getAttribute('href') || '';

          var p = '';

          try { p = new URL(href, location.origin).pathname.replace(/\/+$/, ''); } catch (e) { p = ''; }

          var isHome = p === '' || p === '/' || p === '/index.php';

          var active;
          if (isHome) {
            active = (cur === '') && !homeShown;
            if (active) { homeShown = true; }
          } else {
            active = (cur !== '' && p !== '' && cur.indexOf(p) === 0);
          }

          a.classList.toggle('is-active', active);

        });

        /* 酷安式：选中图标实心化（-line → -fill，带存在性探测） */
        try { window.triSyncTabIcons && window.triSyncTabIcons(); } catch (e) {}

        /* 酷安式：液态玻璃胶囊跟随激活 tab（Swup 切换后同步位置） */
        try { window.triMoveTabPill && window.triMoveTabPill(); } catch (e) {}

      } catch (e) {}

    
})();

    /* ---------- 酷安液态玻璃胶囊：位置驱动（幂等，Swup/旋转时同步） ---------- */
    if (!window.triMoveTabPill) {

      window.triMoveTabPill = function () {

        var bar = document.querySelector('.mobile-tabbar');

        if (!bar) { return; }

        var pill = bar.querySelector('.tri-tab-pill');

        if (!pill) { return; }

        var act = bar.querySelector('.mobile-tab.is-active');

        if (!act) { return; }

        var bw = bar.getBoundingClientRect();

        var aw = act.getBoundingClientRect();

        /* MD3 胶囊固定 64px 宽（规范值），居中于激活 tab */
        var pw = 64;

        pill.style.width = pw + 'px';

        pill.style.left = (aw.left - bw.left + (aw.width - pw) / 2) + 'px';

      };

      var triPillResizeT = null;

      window.addEventListener('resize', function () {

        clearTimeout(triPillResizeT);

        triPillResizeT = setTimeout(function () { try { window.triMoveTabPill(); } catch (e) {} }, 120);

      });

    }

    try { window.triMoveTabPill && window.triMoveTabPill(); } catch (e) {}



    /* ---------- 说说图集增强（幂等重建） ---------- */

    if (window.TriMomentInit) {

      try { window.TriMomentInit(); } catch (e) {}
      try { if (typeof box === "undefined" || !box) throw 0; var _k=box.children; var _c=0; for(var _i=0;_i<_k.length;_i++){if(!_k[_i].classList.contains("tri3-loadmore-wrap")&&!_k[_i].classList.contains("tri3-pager"))_c++;} if(_c>80){var _r=_c-60;for(var _j=0;_j<_k.length&&_r>0;_j++){if(!_k[_j].classList.contains("tri3-loadmore-wrap")&&!_k[_j].classList.contains("tri3-pager")){_k[_j].remove();_r--;}}}} catch(e){}

    }

  }




    /* ---------- 文章页悬浮导航（目录 TOC）：生成 + 滚动高亮 + 移动端按钮（可重跑，Swup 后由 TriM3Init 再次调用） ---------- */

    function buildToc() {

      try {

        var tocBoxes = document.querySelectorAll('[data-tri3-toc]');

        var content = document.querySelector('.post-content');

        var heads = content ? content.querySelectorAll('h2, h3, h4') : null;

        /* 无容器/无内容时：清空已有列表并隐藏容器 */

        if (!tocBoxes.length || !content || !heads || !heads.length) {

          tocBoxes.forEach(function (box) {

            var l = box.querySelector('.tri3-toc-list');

            if (l) { l.innerHTML = ''; }

            box.style.display = 'none';

          });

          return;

        }

        var idx = 0;

        heads.forEach(function (h) { if (!h.id) { h.id = 'tri3-toc-' + (++idx); } });

        var items = [];

        heads.forEach(function (h) {

          var lv = h.tagName === 'H2' ? 1 : (h.tagName === 'H3' ? 2 : 3);

          items.push({ id: h.id, text: h.textContent.trim(), lv: lv });

        });

        /* 渲染到每个 TOC 容器（幂等：先清空再重建） */

        tocBoxes.forEach(function (box) {

          var list = box.querySelector('.tri3-toc-list');

          if (!list) { return; }

          list.innerHTML = '';

          items.forEach(function (it) {

            var li = document.createElement('li');

            var a = document.createElement('a');

            a.href = '#' + it.id;

            a.textContent = it.text;

            a.setAttribute('data-target', it.id);

            if (it.lv > 1) { a.className = 'toc-lv' + it.lv; }

            a.addEventListener('click', function (ev) {

              ev.preventDefault();

              var t = document.getElementById(it.id);

              if (!t) { return; }

              var y = t.getBoundingClientRect().top + window.scrollY - 100;

              window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });

              var mob = document.querySelector('.tri3-toc-mobile.is-open');

              if (mob) { mob.classList.remove('is-open'); }

              var fab = document.querySelector('.tri3-toc-fab');

              if (fab) { fab.classList.remove('is-on'); }

            });

            li.appendChild(a);

            list.appendChild(li);

          });

          var flat = list.querySelectorAll('a');

          box.style.display = flat.length < 3 ? 'none' : '';

        });

        /* 更新已存在的移动端面板列表 */

        var mob0 = document.querySelector('.tri3-toc-mobile');

        if (mob0) {

          var srcList0 = document.querySelector('[data-tri3-toc] .tri3-toc-list');

          var dstList0 = mob0.querySelector('.tri3-toc-list');

          if (srcList0 && dstList0) { dstList0.innerHTML = srcList0.innerHTML; }

        }

        /* 一次性绑定：悬浮按钮 + 面板内容委托 + 滚动高亮 */

        if (!window.__tri3TocBound) {

          window.__tri3TocBound = true;

          var createMobilePanel = function () {

            var mob = document.querySelector('.tri3-toc-mobile');

            if (mob) { return mob; }

            var src = document.querySelector('[data-tri3-toc]');

            if (!src) { return null; }

            mob = document.createElement('div');

            mob.className = 'tri3-toc-mobile';

            mob.setAttribute('data-tri3-toc', '');

            mob.innerHTML = '<div class="tri3-toc-head"><i class="ri-list-unordered"></i><span>目录</span></div>'

              + '<div class="tri3-toc-body"><ol class="tri3-toc-list"></ol></div>';

            document.body.appendChild(mob);

            var srcList = src.querySelector('.tri3-toc-list');

            var dstList = mob.querySelector('.tri3-toc-list');

            if (srcList && dstList) { dstList.innerHTML = srcList.innerHTML; }

            return mob;

          };

          var fab = document.querySelector('.tri3-toc-fab');

          if (fab) {

            fab.addEventListener('click', function () {

              var mob = createMobilePanel();

              if (!mob) { return; }

              var open = mob.classList.toggle('is-open');

              fab.classList.toggle('is-on', open);

            });

          }

          /* 面板内容点击（事件委托：列表重建后依然有效） */

          document.addEventListener('click', function (e) {

            var a = e.target && e.target.closest ? e.target.closest('.tri3-toc-mobile .tri3-toc-list a') : null;

            if (!a) { return; }

            e.preventDefault();

            var id = a.getAttribute('data-target');

            var t = document.getElementById(id);

            if (t) {

              var y = t.getBoundingClientRect().top + window.scrollY - 100;

              window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });

            }

            var mob = document.querySelector('.tri3-toc-mobile.is-open');

            if (mob) { mob.classList.remove('is-open'); }

            var fab2 = document.querySelector('.tri3-toc-fab');

            if (fab2) { fab2.classList.remove('is-on'); }

          });

          /* 滚动高亮（实时查询当前标题） */

          var activeId = '';

          var updateActive = function () {

            var hs = document.querySelectorAll('.post-content h2, .post-content h3, .post-content h4');

            var mark = null;

            var mid = window.scrollY + window.innerHeight * 0.28;

            hs.forEach(function (h) {

              var top = h.getBoundingClientRect().top + window.scrollY;

              if (top <= mid) { mark = h.id; }

            });

            if (mark && mark !== activeId) {

              activeId = mark;

              document.querySelectorAll('.tri3-toc-list a').forEach(function (a) {

                a.classList.toggle('is-active', a.getAttribute('data-target') === activeId);

              });

            }

          };

          var ticking = false;

          var onScroll = function () {

            if (ticking) { return; }

            ticking = true;

            window.requestAnimationFrame(function () { updateActive(); ticking = false; });

          };

          window.addEventListener('scroll', onScroll, { passive: true });

        }

        requestAnimationFrame(function () {

          var hs = document.querySelectorAll('.post-content h2, .post-content h3, .post-content h4');

          var mark = null;

          var mid = window.scrollY + window.innerHeight * 0.28;

          hs.forEach(function (h) {

            var top = h.getBoundingClientRect().top + window.scrollY;

            if (top <= mid) { mark = h.id; }

          });

          if (mark) {

            document.querySelectorAll('.tri3-toc-list a').forEach(function (a) {

              a.classList.toggle('is-active', a.getAttribute('data-target') === mark);

            });

          }

        }, 400);

      } catch (e) {}

    }

    window.TriTocInit = buildToc;

    buildToc();


  window.TriM3Init = TriM3Init;



  /* ============================================================

     深色模式（事件委托）

     ============================================================ */

  function applyTheme(theme) {
    var root = document.documentElement;
    if (theme === 'dark') { root.classList.add('dark'); }
    else { root.classList.remove('dark'); }
    try { localStorage.setItem('trim3-theme', theme); } catch (e) {}
    var isDark = theme === 'dark';
    document.querySelectorAll('#theme-toggle, #theme-toggle-m, #theme-toggle-fab').forEach(function (btn) {
      var i = btn.querySelector('i');
      if (i) { i.className = isDark ? 'ri-sun-line' : 'ri-moon-line'; }
    });
  }



  /* Theme toggle handled by standalone script in footer.php */
  document.addEventListener('click', function (e) {
    /* Old theme toggle handler removed - now in footer.php */



    /* ---------- 移动端抽屉 ---------- */

    var t = e.target && e.target.closest ? e.target.closest('#drawer-toggle, #drawer-close, #drawer-scrim') : null;

    if (t) {

      var drawer = document.getElementById('drawer');

      var scrim = document.getElementById('drawer-scrim');

      if (t.id === 'drawer-toggle') {

        if (drawer) drawer.classList.add('open');

        if (scrim) scrim.classList.add('show');

      } else {

        if (drawer) drawer.classList.remove('open');

        if (scrim) scrim.classList.remove('show');

      }

      return;

    }

    /* 抽屉内点击链接自动关闭 */

    var dl = e.target && e.target.closest ? e.target.closest('#drawer a') : null;

    if (dl) {

      var d = document.getElementById('drawer');

      var sc = document.getElementById('drawer-scrim');

      if (d) d.classList.remove('open');

      if (sc) sc.classList.remove('show');

    }



    /* ---------- FAB ---------- */

    if (e.target && e.target.closest && e.target.closest('#back-top')) {

      window.scrollTo({ top: 0, behavior: 'smooth' });

      return;

    }



    /* ---------- 首页菜单：局部切换下方列表（点首页/文章/分类/说说/独立页，不整页跳转） ---------- */

    var mi = e.target && e.target.closest ? e.target.closest('.home-menu a') : null;

    if (mi && document.getElementById('tri3-home-list') && window.Tri3HomeList) {

      /* 仅处理首页/文章/分类/说说/站内独立页链接（外部链接交给默认行为/Swup） */

      var mhref = mi.getAttribute('href') || '';

      /* 音乐页由 page-music.php 独立模板渲染（播放器），没有可供列表渲染的正文；
         不走首页列表局部渲染，交给 Swup 做页面级局部换页（div.layout 整体替换）。 */
      if (/\/music\.html(?:\?|$)/.test(mhref)) {

        /* 新菜单项没有 data-no-swup，Swup 自己会接管这次点击（不要重复 navigate，否则两次换页互相打断）；
           只有缓存里的旧菜单项还带 data-no-swup，这时手动交给 Swup 做局部换页。 */
        if (mi.hasAttribute('data-no-swup') && !(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) && window.TriSwup && typeof window.TriSwup.navigate === 'function') {

          e.preventDefault();

          window.TriSwup.navigate(mhref);

        }

        return;

      }

      var isCat = mhref.indexOf('/category/') !== -1;

      var isPosts = mi.hasAttribute('data-posts-link');

      var isHome = tri3IsHomeHref(mhref);

      var triType = mi.getAttribute('data-tri3-type') || '';

      if (isCat || isPosts || isHome || triType === 'moment' || triType === 'page') {

        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }

        e.preventDefault();

        window.Tri3HomeList.navigate(mhref, isCat, mi);

        return;

      }

    }



    /* ---------- 列表内分页链接：局部切换（文章分页 / 说说分页，pager 模式） ---------- */

    var pg = e.target && e.target.closest ? e.target.closest('#tri3-home-list .tri3-pager a') : null;

    if (pg && window.Tri3HomeList && document.getElementById('tri3-home-list')) {

      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }

      var pgHref = pg.getAttribute('href') || '';

      var pgM = pgHref.match(/page[\/=](\d+)/);

      if (pgM) {

        e.preventDefault();

        window.Tri3HomeList.loadPage(parseInt(pgM[1], 10));

        return;

      }

    }

    /* ---------- 独立页分页链接：局部无刷新替换列表（分类 / 归档 / 搜索 / 文章列表 / 说说页 pager 模式） ---------- */

    var pgX = e.target && e.target.closest ? e.target.closest('.tri3-pager[data-list] a') : null;

    if (pgX && !(pgX.closest && pgX.closest('#tri3-home-list'))) {

      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }

      /* data-list 由 tri3_pagination() 写在 <nav class="tri3-pager"> 上（lib/helpers.php:120），
         不在 <a> 上：先回退到最近的 .tri3-pager 取值，容器确实存在才接管，否则保持原跳转。 */
      var pgNav = pgX.closest ? pgX.closest('.tri3-pager') : null;
      var pgSel = pgX.getAttribute('data-list') || (pgNav ? pgNav.getAttribute('data-list') : '');
      if (pgSel && document.querySelector(pgSel)) {
        e.preventDefault();
        tri3PagerAjax(pgX);
        return;
      }

    }



    /* ---------- 加载更多（loadmore 模式）：首页列表走 Tri3HomeList，独立页通用追加 ---------- */

    var lm = e.target && e.target.closest ? e.target.closest('.tri3-loadmore') : null;

    if (lm) {

      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }

      e.preventDefault();

      appendLoadMore(lm);

      return;

    }



    /* ---------- Banner 左上角搜索：点击向右延伸展开，外部/Esc 收起 ---------- */

    var sbtn = e.target && e.target.closest ? e.target.closest('.tri-search-entry') : null;

    if (sbtn) {

      e.preventDefault();

      var sc = sbtn.closest('.tri-search-corner');

      if (sc) {

        if (!sc.classList.contains('is-open')) {

          sc.classList.add('is-open');

          var si = sc.querySelector('input');

          if (si) { setTimeout(function () { si.focus(); }, 260); }

        } else {

          sc.classList.remove('is-open');

        }

      }

      return;

    }

    if (e.target && !e.target.closest('.tri-search-corner')) {

      document.querySelectorAll('.tri-search-corner.is-open').forEach(function (sc) {

        sc.classList.remove('is-open');

      });

    }



    /* ---------- Banner 圆点 ---------- */    var dot = e.target && e.target.closest ? e.target.closest('.slider-dots .dot') : null;

    if (dot) {

      var st = document.querySelector('.slider-track');

      if (st) {

        var idx = Array.prototype.indexOf.call(dot.parentNode.children, dot);

        var sls = st.querySelectorAll('.slide');

        var dts = Array.prototype.slice.call(document.querySelectorAll('.slider-dots .dot'));

        var c2 = Math.max(0, Math.min(idx, sls.length - 1));

        for (var k2 = 0; k2 < sls.length; k2++) {

          sls[k2].classList.toggle('is-active', k2 === c2);

          if (dts[k2]) { dts[k2].classList.toggle('is-active', k2 === c2); }

        }

        /* 重置自动轮播 */

        if (st._triTimer) { clearInterval(st._triTimer); }

        var sec2 = parseInt(st.getAttribute('data-interval') || '5', 10);

        if (sec2 > 0) {

          st._triTimer = setInterval(function () {

            var s2 = st.querySelectorAll('.slide');

            var cur2 = 0;

            for (var m = 0; m < s2.length; m++) { if (s2[m].classList.contains('is-active')) { cur2 = m; } }

            cur2 = (cur2 + 1) % s2.length;

            for (var k3 = 0; k3 < s2.length; k3++) {

              s2[k3].classList.toggle('is-active', k3 === cur2);

              if (dts[k3]) { dts[k3].classList.toggle('is-active', k3 === cur2); }

            }

          }, sec2 * 1000);

        }

      }

      return;

    }

    /* ---------- 评论区回复（委托）：点击回复 → 聚焦输入框 + 回复目标条 + parent 隐藏域 ---------- */

    var rp = e.target && e.target.closest ? e.target.closest('.comment-reply-btn') : null;

    if (rp) {

      e.preventDefault();

      var cform = document.getElementById('comment-form');

      var cta = document.getElementById('textarea');

      var cbar = document.getElementById('reply-target-bar');

      var cname = document.getElementById('reply-target-name');

      if (cform && cta) {

        var cp = cform.querySelector('input[name=parent]');

        if (!cp) { cp = document.createElement('input'); cp.type = 'hidden'; cp.name = 'parent'; cform.appendChild(cp); }

        cp.value = rp.getAttribute('data-coid') || '0';

        if (cbar && cname) { cname.textContent = rp.getAttribute('data-author') || ''; cbar.hidden = false; }

        cta.focus();

        try { cta.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (err) {}

      }

      return;

    }

    /* ---------- 评论区删除（委托）：仅管理员按钮，确认后 AJAX 删除该评论及其子回复 ---------- */

    var cdel = e.target && e.target.closest ? e.target.closest('.comment-del-btn') : null;

    if (cdel) {

      e.preventDefault();

      var dCoid = cdel.getAttribute('data-coid') || '';

      if (!dCoid) { return; }

      var dApi = ((window.TRI3_CMT || {}).api) || '';

      if (dApi === '') { return; }

      if (!window.confirm('确定删除这条评论吗？该评论的回复会一并删除。')) { return; }

      var dLi = cdel.closest ? cdel.closest('.comment-item') : null;

      var dFd = new FormData();

      dFd.append('action', 'delete');

      dFd.append('coid', dCoid);

      fetch(dApi, { method: 'POST', body: dFd, credentials: 'same-origin' })

        .then(function (r) { return r.json(); })

        .then(function (d) {

          if (!d || !d.ok) { throw new Error((d && d.msg) || '删除失败'); }

          if (dLi && dLi.parentNode) { dLi.remove(); }

          var dCnt = document.querySelector('.comment-count');

          if (dCnt && typeof d.count === 'number') { dCnt.textContent = d.count; }

          if (window.TriMsg) { window.TriMsg.show('评论已删除'); }

        })

        .catch(function (err) {

          if (window.TriMsg) { window.TriMsg.show(err && err.message ? err.message : '删除失败'); }

        });

      return;

    }

    var cc2 = e.target && e.target.closest ? e.target.closest('#reply-target-cancel') : null;

    if (cc2) {

      var cform2 = document.getElementById('comment-form');

      var cp2 = cform2 && cform2.querySelector('input[name=parent]');

      if (cp2) { cp2.value = ''; }

      var cbar2 = document.getElementById('reply-target-bar');

      if (cbar2) { cbar2.hidden = true; }

      var cta2 = document.getElementById('textarea');

      if (cta2) { cta2.focus(); }

      return;

    }

    /* ---------- 「填写信息」展开/收起游客信息面板（文章页/单页/说说评论区通用，Swup 后委托仍有效） ---------- */

    var cIt = e.target && e.target.closest ? e.target.closest('#comment-info-toggle') : null;

    if (cIt) {

      e.preventDefault();

      var cPanel = document.getElementById('comment-guest-panel');

      if (cPanel) {

        var cOpen = cPanel.classList.toggle('is-open');

        cIt.classList.toggle('is-active', cOpen);

        if (cOpen) {

          var cFirst = cPanel.querySelector('input');

          if (cFirst) { try { cFirst.focus(); } catch (err) {} }

        }

      }

      return;

    }

  });
  /* ---------- 评论区提交（委托）：首页局部单页 / 文章页统一 AJAX 到主题 comment-api，
     原因：局部渲染时表单 token 基于 list-api URL 生成，与 Typecho 原生
     提交校验（基于 referer）不匹配，且 Typecho 1.3 Feedback 无 AJAX 分支，
     原生提交会整页跳转。成功 → 局部刷新列表 + 定位新评论；失败 → toast。 ---------- */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.id || f.id !== 'comment-form' || !f.getAttribute) { return; }
    var cid = f.getAttribute('data-cid') || '';
    if (cid === '') { return; }
    var api = ((window.TRI3_CMT || {}).api) || '';
    if (api === '') { return; }
    e.preventDefault();
    var btn = document.getElementById('comment-submit');
    var originHtml = btn ? (btn.innerHTML || '') : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ri-loader-4-line"></i>'; }
    var fd = new FormData(f);
    fd.append('action', 'post');
    fd.append('cid', cid);
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { throw new Error((d && d.msg) || '提交失败，请稍后再试'); }
        var box = document.getElementById('comments');
        var area = box ? box.querySelector('.chat-list-area') : null;
        if (area && d.html) { area.innerHTML = d.html; }
        var cntEl = box ? box.querySelector('.comment-count') : null;
        if (cntEl && typeof d.count === 'number') { cntEl.textContent = d.count; }
        /* 复位输入区 */
        f.reset();
        var gp = document.getElementById('comment-guest-panel');
        if (gp) { gp.style.display = ''; gp.classList.remove('is-open'); }
        var rb = document.getElementById('reply-target-bar');
        if (rb) { rb.hidden = true; }
        var pp = f.querySelector('input[name=parent]');
        if (pp) { pp.value = ''; }
        var ta = document.getElementById('textarea');
        if (ta) { ta.style.height = ''; }
        if (d.waiting) { (window.TriMsg ? window.TriMsg.show : function(m){alert(m)})('评论已提交，等待审核'); }
        /* 定位到新评论 */
        if (d.coid) {
          requestAnimationFrame(function () {
            var el = document.getElementById('comment-' + d.coid);
            if (el) {
              try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (err) { el.scrollIntoView(); }
              el.classList.add('cmt-flash');
              setTimeout(function () { if (el.classList) { el.classList.remove("cmt-flash"); } }, 1600);
            } else if (area) { try { area.scrollTop = area.scrollHeight; } catch (err) {} }
          }, 150);
        } else if (area) {
          try { area.scrollTop = area.scrollHeight; } catch (err) {}
        }
      })
      .catch(function (err) {
        (window.TriMsg ? window.TriMsg.show : function(m){alert(m)})(err && err.message ? err.message : '评论失败');
      })
      .finally(function () {
        if (btn) { btn.disabled = false; btn.innerHTML = originHtml; }
      });
  });


  /* ---------- 滚动：FAB 显隐（委托监听一次即可，Swup 后状态由 TriM3Init 恢复） ---------- */

  var _fabTicking = false;
  window.addEventListener('scroll', function () {
    if (_fabTicking) return;
    _fabTicking = true;
    window.requestAnimationFrame(function () {
      _fabTicking = false;
      var fab = document.getElementById('back-top');
      if (fab) {
        if (window.scrollY > 480) { fab.classList.add('show'); }
        else { fab.classList.remove('show'); }
      }
    });
  }, { passive: true });



  /* ---------- Esc 关闭展开的 Banner 搜索框 ---------- */

  document.addEventListener('keydown', function (e) {

    if (e.key === 'Escape') {

      document.querySelectorAll('.tri-search-corner.is-open').forEach(function (sc) {

        sc.classList.remove('is-open');

      });

    }

  });



  /* ---------- 首页列表局部切换（Tri3HomeList） ---------- */

  function tri3IsHomeHref(href) {

    var u;

    try { u = new URL(href, location.href); } catch (e) { return false; }

    var p = u.pathname.replace(/\/+$/, '');

    return p === '' || p === '/index.php';

  }



  var Tri3HomeList = (function () {

    var listApi = '';

    var cache = {};

    /* 首页列表模式：original→posts / moment→moment / fusion→fusion（由 index.php 输出） */

    var _hm = document.querySelector('script[data-home-list-api]');

    var _mode = (_hm && _hm.getAttribute('data-home-mode')) || 'original';

    var state = {

      type: homeMode(),

      cat: '', page: 1, posts: false, slug: '', moments: false,

      /* 代际令牌：每次新导航递增；旧响应 reqId 不匹配时丢弃，杜绝异步竞态覆盖 */
      reqId: 0,

      /* 加载更多在途标记：防止无限加载重复触发 */
      loadingMore: false

    };



    /* 动态读取首页列表模式（Swup 整页切换后 DOM 已更新，避免闭包残留首屏旧值：

       从非首页进入时 _mode 可能取到 original，导致点首页固定成文章列表） */

    function homeMode() {

      var s = document.querySelector('script[data-home-list-api]');

      var m = s ? (s.getAttribute('data-home-mode') || 'original') : 'original';

      return m === 'moment' ? 'moment' : (m === 'fusion' ? 'fusion' : 'posts');

    }



    /* 每次调用动态读取 DOM 中的 list-api 地址（Swup 整页切换后 DOM 已更新，

       避免闭包残留旧值/空值导致回退到站点根 list-api.php 404） */

    function getListApi() {

      var s = document.querySelector('script[data-home-list-api]');

      if (s && s.getAttribute('data-home-list-api')) {

        listApi = s.getAttribute('data-home-list-api');

      }

      return listApi;

    }



    function apiUrl(type, cat, page, slug) {

      type = type || 'posts';

      var q;

      if (type === 'moment') {

        q = '?type=moment&cat=' + encodeURIComponent(cat || '') + '&page=' + (page || 1);

      } else if (type === 'fusion') {

        q = '?type=fusion&page=' + (page || 1);

      } else if (type === 'page') {

        q = '?type=page&slug=' + encodeURIComponent(slug || '') + '&page=' + (page || 1);

      } else {

        q = '?type=posts&cat=' + encodeURIComponent(cat || '') + '&page=' + (page || 1);

      }

      return getListApi() + q + '&_=' + Date.now();

    }



    function extractCat(href) {

      var m = href.match(/\/category\/([^/?]+)/);

      return m ? decodeURIComponent(m[1]) : '';

    }



    var _triClickedItem = null;

    function activateLocalAssets(root) {
      var links = Array.prototype.slice.call(root.querySelectorAll('link[rel="stylesheet"][href]'));
      var scripts = Array.prototype.slice.call(root.querySelectorAll('script[src]'));

      links.forEach(function (node) { node.remove(); });
      scripts.forEach(function (node) { node.remove(); });

      links.forEach(function (node) {
        var href = node.href;
        var exists = Array.prototype.some.call(document.head.querySelectorAll('link[rel="stylesheet"]'), function (link) {
          return link.href === href;
        });
        if (!exists) {
          var link = document.createElement('link');
          link.rel = 'stylesheet';
          link.href = href;
          document.head.appendChild(link);
        }
      });

      scripts.forEach(function (node) {
        var src = node.src;
        var exists = Array.prototype.some.call(document.querySelectorAll('script[src]'), function (script) {
          return script.src === src;
        });
        if (!exists) {
          var script = document.createElement('script');
          script.src = src;
          script.defer = true;
          script.async = false;
          document.head.appendChild(script);
        }
      });
    }

    function setMenuActive(cat, fromPosts, fromMoments) {
      var nav = document.querySelector('.home-menu');
      if (!nav) { return; }
      var def = nav.querySelector('[data-menu-default]');
      var posts = nav.querySelector('[data-menu-posts]');
      var moments = nav.querySelector('[data-menu-moments]');
      var inMoments = !!fromMoments;
      var inPosts = !inMoments && (!!cat || !!fromPosts);
      if (def) { def.hidden = inMoments || inPosts; }
      if (posts) { posts.hidden = !inPosts; }
      if (moments) { moments.hidden = !inMoments; }
      var newMode = inMoments ? 'moments' : (inPosts ? 'posts' : 'default');
      nav.setAttribute('data-mode', newMode);
      /* 高亮：在可见组里找匹配的链接 */
      var clickedHref = _triClickedItem ? (_triClickedItem.getAttribute('href') || '') : '';
      nav.querySelectorAll('.home-menu-item').forEach(function (it) {
        it.classList.remove('is-active');
      });
      /* 在可见组里找 href 匹配的链接 */
      var visibleNav = nav.querySelector('.home-menu-inner:not([hidden])');
      if (visibleNav && clickedHref) {
        var match = null;
        visibleNav.querySelectorAll('.home-menu-item').forEach(function (it) {
          if (it.getAttribute('href') === clickedHref) { match = it; }
        });
        if (match) { match.classList.add('is-active'); }
      }
      /* 如果没找到匹配的，用 _triClickedItem 本身 */
      if (!match && _triClickedItem && document.body.contains(_triClickedItem)) {
        _triClickedItem.classList.add('is-active');
      }
    }

    function render(html) {

      var box = document.getElementById('tri3-home-list');

      if (!box) { return; }

      /* MD3 fade-through 列表切换：旧内容淡出 70ms → 替换 → 新内容淡入 */
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      var apply = function () {

        box.classList.remove('tri3-list-leave');

        box.innerHTML = html;

        activateLocalAssets(box);

        /* 列表切换淡入（平滑无感） */

        box.classList.remove('tri3-list-swap');

        void box.offsetWidth;

        // swap animation disabled

        /* 列表内图片懒加载重新观察 */

        if (window.Tri3Lazy && window.Tri3Lazy.update) {

          try { window.Tri3Lazy.update(box); } catch (e) {}

        }

        /* 通知灯箱等模块重扫（说说/文章卡片新 DOM） */
        document.dispatchEvent(new CustomEvent('tri3:homelist'));
        /* 触发页面专属 CSS/JS 加载（番剧/相册/豆瓣等） */
        document.dispatchEvent(new CustomEvent('tri3:render'));

        /* 列表内说说图集增强（左右按钮/进度条，幂等） */

        if (window.TriMomentInit) {

          try { window.TriMomentInit(); } catch (e) {}
      try { if (typeof box === "undefined" || !box) throw 0; var _k=box.children; var _c=0; for(var _i=0;_i<_k.length;_i++){if(!_k[_i].classList.contains("tri3-loadmore-wrap")&&!_k[_i].classList.contains("tri3-pager"))_c++;} if(_c>80){var _r=_c-60;for(var _j=0;_j<_k.length&&_r>0;_j++){if(!_k[_j].classList.contains("tri3-loadmore-wrap")&&!_k[_j].classList.contains("tri3-pager")){_k[_j].remove();_r--;}}}} catch(e){}

        }

        /* 重新观察加载更多按钮：列表替换后无限加载必须重绑，否则不自动续载 */

        try { tri3InfiniteInit(); } catch (e) {}

        /* 新内容加载后重新初始化灯箱 */
        try { triInitLightgallery(); } catch (e) {}

      };

      apply();

    }



    function load(cat, page, silent, fromPosts, type, slug, fromMoments) {

      cat = cat || '';

      page = page || 1;

      fromPosts = !!fromPosts;

      fromMoments = !!fromMoments;

      type = type || 'posts';

      slug = slug || '';

      /* 新导航：递增代际令牌，使之前发出的所有请求响应失效 */
      var myReq = ++state.reqId;

      var key = type + ':' + cat + ':' + (slug || '') + ':' + page;

      if (cache[key]) {

        var boxC = document.getElementById('tri3-home-list');

        if (boxC) { boxC.setAttribute('data-tri3-type', type); boxC.setAttribute('data-tri3-page', String(page)); }

        render(cache[key]);

        state.type = type; state.cat = cat; state.page = page; state.posts = fromPosts; state.slug = slug; state.moments = fromMoments;

        setMenuActive(cat, fromPosts, fromMoments);

        return;

      }

      var box = document.getElementById('tri3-home-list');

      /* 立即切换菜单（不等AJAX完成，避免闪烁原菜单） */
      setMenuActive(cat, fromPosts, fromMoments);

      if (box && !silent) { box.classList.add('is-loading'); }

      fetch(apiUrl(type, cat, page, slug), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })

        .then(function (r) { return r.text(); })

        .then(function (html) {

          /* 旧代际响应：丢弃，不覆盖当前列表 */
          if (myReq !== state.reqId) { return; }

          cache[key] = html;

          var boxC = document.getElementById('tri3-home-list');

          if (boxC) { boxC.setAttribute('data-tri3-type', type); boxC.setAttribute('data-tri3-page', String(page)); }

          render(html);

          state.type = type; state.cat = cat; state.page = page; state.posts = fromPosts; state.slug = slug; state.moments = fromMoments;

          setMenuActive(cat, fromPosts, fromMoments);

        })

        .catch(function () {

          if (myReq !== state.reqId) { return; }

          if (box) { box.classList.remove('is-loading'); }

          toastHome('列表加载失败');

        })

        .finally(function () {

          if (myReq === state.reqId && box) { box.classList.remove('is-loading'); }

        });

    }



    /* pushState 辅助：写入列表历史并保持滚动位置不跳动。

       - 目标 URL 与当前 pathname 语义等价（如 / 与无尾斜杠站点根）：不写历史，

         避免浏览器因 URL 变化自动回到顶部（点「首页」保持原位）。

       - 真正变化时：push 后立即恢复 scrollY（列表局部切换不整页跳转）。 */

    function pushListState(data, href) {

      var sy = window.scrollY;

      try {

        var u = new URL(href, location.href);

        var cur = location.pathname.replace(/\/+$/, '') || '/';

        var nu = u.pathname.replace(/\/+$/, '') || '/';

        if (cur === nu && !u.search && !u.hash) {

          return; /* 语义等价：不写历史，零跳动 */

        }

        history.pushState(data, '', href);

        /* v1.2.62：局部切换只换了列表内容，页面专属样式要按新地址补上，
           否则番剧/豆瓣/友联等页会缺样式（相册有服务端 link 所以看起来正常）。 */
        if (window.Tri3PageCss) { try { window.Tri3PageCss(href); } catch (e3) {} }

      } catch (e) { return; }

      if (Math.abs(window.scrollY - sy) > 0) { window.scrollTo(0, sy); }

    }



    function navigate(href, isCat, el) {

      /* 文章入口（posts-link）：分类汇总 = 全部文章（cat=''）；分类链接：该分类文章；首页：全部文章；

         说说入口（moments-link）：分类汇总 = 全部说说；说说分类（data-tri3-cat）：该分类说说；

         独立页（data-tri3-type=page）：切换下方列表为页面内容，banner 与个人信息不动 */

      var fromPosts = !!(el && el.hasAttribute('data-posts-link'));

      var fromMoments = !!(el && (el.hasAttribute('data-moments-link') || el.hasAttribute('data-tri3-cat')));

      var triType = (el && el.getAttribute('data-tri3-type')) || '';

      var slug = (el && el.getAttribute('data-tri3-slug')) || '';

      var isHome = tri3IsHomeHref(href);

      var cat = '';

      if (isCat && !fromPosts) {

        cat = extractCat(href);

      }

      if (fromMoments) {

        cat = (el && el.getAttribute('data-tri3-cat')) || '';

      }

      if (href.indexOf('#') !== -1) { return; }

      /* 首页：恢复到首页初始列表模式（原样=文章 / 说说 / 融合），

         不要固定 posts 导致融合/说说模式下点首页切回文章列表。

         模式优先级：菜单项 data-home-mode（服务端渲染权威值）> 当前 DOM 的 home-mode 脚本

         （非首页/Swup 瞬态时 footer.php 注入的 script 无 data-home-mode，会误回退 original） */

      var type = triType || 'posts';

      if (isHome) {

        type = (el && el.getAttribute('data-home-mode')) || homeMode();

      }

      _triClickedItem = el;
      load(cat, 1, false, fromPosts, type, slug, fromMoments);

      /* 更新地址（不整页跳转，等价 URL 不写历史保持原位） */

      pushListState({ tri3list: { type: type, cat: cat, page: 1, posts: fromPosts, slug: slug, moments: fromMoments } }, href);

    }



    function loadPage(page) {

      /* 列表内分页链接局部切换（说说分页 / 文章分页） */

      load(state.cat || '', page, false, !!state.posts, state.type || 'posts', state.slug || '', !!state.moments);

      try {

        var href = location.href.replace(/[?&]page=\d+/, '');

        href += (href.indexOf('?') === -1 ? '?' : '&') + 'page=' + page;

        pushListState({ tri3list: { type: state.type || 'posts', cat: state.cat || '', page: page, posts: !!state.posts, slug: state.slug || '', moments: !!state.moments } }, href);

      } catch (e) {}

    }



    function getState() { return { type: state.type, cat: state.cat, page: state.page, posts: state.posts, slug: state.slug, moments: state.moments }; }



    /* 追加下一页内容（加载更多）：列表项保留，分页控件原位替换 */

    function appendFrag(html) {

      var box = document.getElementById('tri3-home-list');

      if (!box) { return; }

      var tmp = document.createElement('div');

      tmp.innerHTML = html;

      /* 顺序：先移除旧分页控件 → 追加内容 → 新控件再追加到末尾（按钮永远在内容后面） */
      var oldCtrl = box.querySelector('.tri3-loadmore-wrap, .tri3-pager');

      if (!oldCtrl) {
        /* 初始分页控件在列表容器之后（兄弟节点），不在 box 内，需一并查找移除 */
        var sib2 = box.nextElementSibling;
        while (sib2) {
          if (sib2.classList && (sib2.classList.contains('tri3-loadmore-wrap') || sib2.classList.contains('tri3-pager'))) { oldCtrl = sib2; break; }
          sib2 = sib2.nextElementSibling;
        }
      }

      if (oldCtrl) { oldCtrl.remove(); }

      var newCtrl = tmp.querySelector('.tri3-loadmore-wrap, .tri3-pager');

      /* 服务端 tri3_ajax 返回完整列表区块时只取容器内卡片项，避免嵌套列表容器 */
      var nb = tmp.querySelector('#tri3-home-list');

      var items = (nb && nb.children && nb.children.length) ? nb.children : tmp.children;


      Array.prototype.slice.call(items).forEach(function (el) {

        if (el.classList && (el.classList.contains('tri3-loadmore-wrap') || el.classList.contains('tri3-pager'))) { return; }


        box.appendChild(el);

      });

      if (newCtrl) { box.appendChild(newCtrl); }

      if (window.Tri3Lazy && window.Tri3Lazy.update) { try { window.Tri3Lazy.update(box); } catch (e) {} }

      if (window.TriMomentInit) { try { window.TriMomentInit(); } catch (e) {} }
      
      try { if (typeof box === "undefined" || !box) throw 0; var _k=box.children; var _c=0; for(var _i=0;_i<_k.length;_i++){if(!_k[_i].classList.contains("tri3-loadmore-wrap")&&!_k[_i].classList.contains("tri3-pager"))_c++;} if(_c>80){var _r=_c-60;for(var _j=0;_j<_k.length&&_r>0;_j++){if(!_k[_j].classList.contains("tri3-loadmore-wrap")&&!_k[_j].classList.contains("tri3-pager")){_k[_j].remove();_r--;}}}} catch(e){}

      /* 新按钮出现后重新观察（无限加载自动续载） */

      try { tri3InfiniteInit(); } catch (e) {}

        /* 新内容加载后重新初始化灯箱 */
        try { if (window.tri3RefreshLightbox) window.tri3RefreshLightbox(box); else if (window.triScheduleLightgallery) triScheduleLightgallery(); } catch (e) {}

    }



    function loadMore() {

      var box = document.getElementById('tri3-home-list');

      if (!box) { return; }

      /* 列表类型权威来源 = 容器 data-tri3-type（服务端首屏 / Swup 整页替换时写入）。
         state 可能还是旧导航上下文（例如从 404/文章页 Swup 回首页后未点导航），
         不先同步就加载更多会把错误的列表类型（如 posts）追加进当前融合首页。 */
      var domType = box.getAttribute('data-tri3-type') || '';

      /* 容器 data-tri3-page 为整页渲染（首屏 / Swup 回首页）时的权威页码 */
      var domPage = parseInt(box.getAttribute('data-tri3-page') || '', 10) || 0;

      if (domType && domType !== state.type) {

        state.type = domType;

        state.cat = '';

        state.slug = '';

        state.moments = false;

        state.page = 1;

        /* 作废此前所有在途请求 */
        state.reqId++;

      } else if (domPage && domPage !== state.page) {

        /* 类型相同但页码残留（Swup 切回首页时旧闭包 state 未重置）：
           以容器权威页码对齐，避免跳过中间页或越界 404 导致加载不出内容 */
        state.page = domPage;

        state.cat = '';

        state.slug = '';

        state.moments = false;

        state.reqId++;

      }

      /* 加载在途：忽略重复触发（IO + scroll 兜底双路径） */
      if (state.loadingMore) { return; }

      var next = state.page + 1;

      /* 快照当前代际与列表上下文：响应返回时若列表已被导航替换则丢弃 */
      var myReq = state.reqId;

      var myType = state.type, myCat = state.cat, mySlug = state.slug;

      var key = myType + ':' + myCat + ':' + (mySlug || '') + ':' + next;

      if (cache[key]) {

        if (myReq !== state.reqId) { return; }

        appendFrag(cache[key]);

        state.page = next;

        box.setAttribute('data-tri3-page', String(next));

        return;

      }

      state.loadingMore = true;

      var btn = box.querySelector('.tri3-loadmore');

      if (btn) { btn.classList.add('is-loading'); btn.disabled = true; }

      fetch(apiUrl(myType, myCat, next, mySlug), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })

        /* 5xx 不能当列表内容解析（抖动时会把错误页追加进列表） */

        .then(function (r) {

          if (r.status >= 500) { var _e5 = new Error('HTTP ' + r.status); _e5.status = r.status; throw _e5; }

          return r.text();

        })

        .then(function (html) {

          /* 列表已被导航切换（代际变化）或 type 上下文改变：丢弃，避免污染新列表 */
          if (myReq !== state.reqId || state.type !== myType || state.cat !== myCat || (state.slug || '') !== mySlug) { return; }

          if (state.lmRetry && state.lmRetry[key]) { state.lmRetry[key] = 0; }

          cache[key] = html;

          appendFrag(html);

          state.page = next;

          box.setAttribute('data-tri3-page', String(next));

        })

        .catch(function (err) {

          if (myReq !== state.reqId) { return; }

          var _st = (err && err.status) || 0;

          var _tried = (state.lmRetry && state.lmRetry[key]) || 0;

          /* 网络中断或 5xx：同一分页只自动重试一次，偶发抖动不再直接报失败 */

          if (_tried < 1 && (_st === 0 || _st >= 500)) {

            state.lmRetry = state.lmRetry || {};

            state.lmRetry[key] = _tried + 1;

            setTimeout(function () { if (myReq === state.reqId) { try { loadMore(); } catch (e) {} } }, 420);

            return;

          }

          if (state.lmRetry && state.lmRetry[key]) { state.lmRetry[key] = 0; }

          console.warn('[Tri3] load-more failed:', _st || 'network');

          toastHome('加载失败');

          /* 允许重试：清除按钮观察标记，下次滚动重新触发 */
          var bb = document.querySelector('#tri3-home-list .tri3-loadmore');

          if (bb) { bb.removeAttribute('data-inf-observed'); }

        })

        .finally(function () {

          /* Cooldown: prevent rapid-fire loads on mobile scroll */
          state.loadingMore = true;
          setTimeout(function(){
            state.loadingMore = false;
            var b0 = document.querySelector('#tri3-home-list .tri3-loadmore');
            if (b0 && myReq === state.reqId) { b0.classList.remove('is-loading'); b0.disabled = false; }
          }, 150);

          /* 按钮可能已被 render/append 替换，重新查找 */
          var b2 = document.querySelector('#tri3-home-list .tri3-loadmore');

          if (b2 && myReq === state.reqId) { b2.classList.remove('is-loading'); b2.disabled = false; }

          if (b2 && myReq !== state.reqId) {

            /* 列表已切换：移除旧按钮观察标记，让新列表按钮正常观察 */
            b2.removeAttribute('data-inf-observed');

          }

        });

    }



    function restore() {

      /* 浏览器前进/后退后恢复列表状态；无 tri3list state 时服务端已渲染正确内容，不重复请求 */

      if (history.state && history.state.tri3list) {

        var s = history.state.tri3list;

        load(s.cat || '', s.page || 1, true, !!s.posts, s.type || 'posts', s.slug || '', !!s.moments);

      }

    }



    function setApi(url) { listApi = url; }



    /* Swup 整页切回首页后，以当前 DOM 为权威重置列表状态（type 相同但 page/cat 残留时，
       不清零会导致无限加载跳过中间页直接加载后面的内容，或越界 404 加载不出来） */
    function resetFromDom() {
      var box = document.getElementById('tri3-home-list');
      var t = (box && box.getAttribute('data-tri3-type')) || homeMode();
      state.type = t === 'moment' ? 'moment' : (t === 'fusion' ? 'fusion' : 'posts');
      state.cat = '';
      state.slug = '';
      state.posts = false;
      state.moments = false;
      state.page = 1;
      state.reqId++;
      state.loadingMore = false;
    }



    function toastHome(msg) { if (window.TriMsg) { window.TriMsg.show(msg); } }
    return { navigate: navigate, restore: restore, setApi: setApi, load: load, loadPage: loadPage, loadMore: loadMore, getState: getState, resetFromDom: resetFromDom };

  
})();



  /* ---------- 独立页加载更多（archive / moment 页）：AJAX 追加列表项 ---------- */

  function appendLoadMore(btn) {

    if (!btn || btn.disabled) { return; }

    var listSel = btn.getAttribute('data-list');

    var href = btn.getAttribute('data-href');

    var box = listSel ? document.querySelector(listSel) : (btn.closest('.archive-list, .moment-list') || null);

    if (!box || !href) { return; }

    var wrap = btn.closest('.tri3-loadmore-wrap');

    btn.disabled = true;

    btn.classList.add('is-loading');

    fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })

      .then(function (r) { return r.text(); })

      .then(function (html) {

        var tmp = document.createElement('div');

        tmp.innerHTML = html;

        /* 顺序：先移除旧控件 → 追加内容 → 新控件追加到末尾 */
        var oldWrap = box.querySelector('.tri3-loadmore-wrap, .tri3-pager');

        if (!oldWrap) {
          /* 初始分页控件由 tri3_pagination 输出在列表容器之后（兄弟节点），
             不在 box 内，需从紧随容器的兄弟节点查找，否则残留到最后一页 */
          var sib = box.nextElementSibling;
          while (sib) {
            if (sib.classList && (sib.classList.contains('tri3-loadmore-wrap') || sib.classList.contains('tri3-pager'))) { oldWrap = sib; break; }
            sib = sib.nextElementSibling;
          }
        }

        if (oldWrap) { oldWrap.remove(); }

        var newCtrl = tmp.querySelector('.tri3-loadmore-wrap, .tri3-pager');

        /* 服务端 tri3_ajax 返回的是完整列表区块（含列表容器、标题等），
           只取列表容器内的卡片项追加，避免把容器/标题嵌套进 box 造成结构爆炸 */
        var nb = listSel ? tmp.querySelector(listSel) : null;

        var items = (nb && nb.children && nb.children.length) ? nb.children : tmp.children;


        Array.prototype.slice.call(items).forEach(function (el) {

          if (el.classList && (el.classList.contains('tri3-loadmore-wrap') || el.classList.contains('tri3-pager'))) { return; }


          box.appendChild(el);

        });

        if (newCtrl) { box.appendChild(newCtrl); }

        if (window.Tri3Lazy && window.Tri3Lazy.update) { try { window.Tri3Lazy.update(box); } catch (e) {} }

        if (window.TriMomentInit) { try { window.TriMomentInit(); } catch (e) {} }

        
      try { if (typeof box === "undefined" || !box) throw 0; var _k=box.children; var _c=0; for(var _i=0;_i<_k.length;_i++){if(!_k[_i].classList.contains("tri3-loadmore-wrap")&&!_k[_i].classList.contains("tri3-pager"))_c++;} if(_c>80){var _r=_c-60;for(var _j=0;_j<_k.length&&_r>0;_j++){if(!_k[_j].classList.contains("tri3-loadmore-wrap")&&!_k[_j].classList.contains("tri3-pager")){_k[_j].remove();_r--;}}}} catch(e){}

        /* 新按钮出现后重新观察（无限加载自动续载） */

        try { tri3InfiniteInit(); } catch (e2) {}

        /* 新内容加载后重新初始化灯箱 */
        try { if (window.tri3RefreshLightbox) window.tri3RefreshLightbox(box); else if (window.triScheduleLightgallery) triScheduleLightgallery(); } catch (e) {}

      })

      .catch(function () {

        btn.disabled = false;

        btn.classList.remove('is-loading');

        if (window.TriMsg) { window.TriMsg.show('加载失败'); }

      });

  }



  window.Tri3HomeList = Tri3HomeList;

  /* 根据当前 URL 同步高亮（直接打开页面时用） */
  function syncMenuActiveFromUrl() {
    var nav = document.querySelector('.home-menu');
    if (!nav) { return; }
    var curPath = (location.pathname || '').replace(/\/+$/, '');
    var items = nav.querySelectorAll('.home-menu-item');
    var found = null;
    items.forEach(function(it) {
      var href = it.getAttribute('href') || '';
      try {
        var hrefPath = new URL(href, location.origin).pathname.replace(/\/+$/, '');
        if (curPath === hrefPath || curPath.indexOf(hrefPath + '/') === 0) {
          found = it;
        }
      } catch(e) {}
    });
    if (found) {
      _triClickedItem = found;
      var inMoments = found.hasAttribute('data-moments-link') || found.hasAttribute('data-tri3-cat');
      var inPosts = !inMoments && (found.hasAttribute('data-posts-link') || found.getAttribute('href').indexOf('/category/') !== -1);
      setMenuActive('', inPosts, inMoments);
    }
  }
  window.Tri3HomeList.syncFromUrl = syncMenuActiveFromUrl;

  /* ---------- 独立页分页：上一页/下一页 局部无刷新替换列表（不整页刷新） ---------- */

  function tri3PagerAjax(link) {

    if (!link || link.classList.contains('is-loading')) { return; }

    /* data-list 落在外层 <nav class="tri3-pager">（lib/helpers.php:120），锚点自身没有该属性 */
    var navEl = (link.closest && link.closest('.tri3-pager')) || null;

    var listSel = link.getAttribute('data-list') || (navEl ? navEl.getAttribute('data-list') : '');

    if (!listSel) { return; }

    var box = document.querySelector(listSel);

    if (!box) { return; }

    var href = link.getAttribute('href') || '';

    if (!href || href.charAt(0) === '#') { return; }

    link.classList.add('is-loading');

    fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })

      /* 5xx 不能当列表内容解析 */

      .then(function (r) {

        if (r.status >= 500) { var _e5 = new Error('HTTP ' + r.status); _e5.status = r.status; throw _e5; }

        return r.text();

      })

      .then(function (html) {

        var doc = new DOMParser().parseFromString(html, 'text/html');

        var nb = doc.querySelector(listSel);

        if (!nb) {

          /* 兜底：解析失败走整页跳转 */

          location.href = href;

          return;

        }

        /* 新分页控件：新页面中紧随列表容器之后的 pager */

        var newPager = null;

        var ns = nb.nextElementSibling;

        while (ns) {

          if (ns.classList && ns.classList.contains('tri3-pager')) { newPager = ns; break; }

          ns = ns.nextElementSibling;

        }

        /* 当前页面列表容器之后的 pager */

        var curPager = null;

        var cs = box.nextElementSibling;

        while (cs) {

          if (cs.classList && cs.classList.contains('tri3-pager')) { curPager = cs; break; }

          cs = cs.nextElementSibling;

        }

        box.innerHTML = nb.innerHTML;

        if (curPager && newPager) { curPager.outerHTML = newPager.outerHTML; }

        /* 滚动到列表顶部（避开顶栏），保留一点上下文 */

        var top = box.getBoundingClientRect().top + window.pageYOffset - 96;

        try { window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' }); } catch (e2) {}

        try { history.pushState({}, '', href); } catch (e3) {}

        if (window.Tri3Lazy && window.Tri3Lazy.update) { try { window.Tri3Lazy.update(box); } catch (e4) {} }

        if (window.TriMomentInit) { try { window.TriMomentInit(); } catch (e5) {} }
      try { if (typeof box === "undefined" || !box) throw 0; var _k=box.children; var _c=0; for(var _i=0;_i<_k.length;_i++){if(!_k[_i].classList.contains("tri3-loadmore-wrap")&&!_k[_i].classList.contains("tri3-pager"))_c++;} if(_c>80){var _r=_c-60;for(var _j=0;_j<_k.length&&_r>0;_j++){if(!_k[_j].classList.contains("tri3-loadmore-wrap")&&!_k[_j].classList.contains("tri3-pager")){_k[_j].remove();_r--;}}}} catch(e){}

        tri3InfiniteInit();

        /* 新内容加载后重新初始化灯箱 */
        try { if (window.tri3RefreshLightbox) window.tri3RefreshLightbox(box); else if (window.triScheduleLightgallery) triScheduleLightgallery(); } catch (e) {}

      })

      .catch(function () {

        if (window.TriMsg) { window.TriMsg.show('加载失败'); }

      })

      .finally(function () { link.classList.remove('is-loading'); });

  }

  /* ---------- 无限加载：加载更多按钮进入视口自动加载下一页（滚动自动触发） ---------- */

  function tri3InfiniteInit() {

    if (typeof IntersectionObserver === 'undefined') { return; }

    if (!window.__triInfIO) {

      window.__triInfIO = new IntersectionObserver(function (entries) {

        entries.forEach(function (en) {

          if (!en.isIntersecting) { return; }

          var btn = en.target;

          if (window.__triInfIO) { window.__triInfIO.unobserve(btn); }

          if (!btn || btn.disabled || btn.classList.contains('is-loading')) { return; }

                        if (btn.closest && btn.closest('#tri3-home-list') && window.Tri3HomeList && document.querySelector('.home-menu')) {
                window.Tri3HomeList.loadMore();
              } else {
                appendLoadMore(btn);
              }
          });
      }, { rootMargin: (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) ? '60px 0px' : '280px 0px', threshold: 0.01 });

    }

    Array.prototype.forEach.call(document.querySelectorAll('.tri3-loadmore[data-infinite="1"]'), function (btn) {

      if (btn.getAttribute('data-inf-observed')) { return; }

      btn.setAttribute('data-inf-observed', '1');

      window.__triInfIO.observe(btn);

    });

    /* scroll 兜底：IO 失效或未触发时，按钮进入视口附近也自动加载（幂等，observed 标记防重复） */

    if (!window.__triInfScrollBound) {

      window.__triInfScrollBound = 1;

      var triInfTick = function () {

        if (window.__triInfTicking) { return; }

        window.__triInfTicking = 1;

        requestAnimationFrame(function () {

          window.__triInfTicking = 0;

          Array.prototype.forEach.call(document.querySelectorAll('.tri3-loadmore[data-infinite="1"]'), function (btn) {

            if (btn.disabled || btn.classList.contains('is-loading')) { return; }

            var rect = btn.getBoundingClientRect();

            if (rect.top < window.innerHeight + 280) {

              btn.setAttribute('data-inf-observed', '1');

                        if (btn.closest && btn.closest('#tri3-home-list') && window.Tri3HomeList && document.querySelector('.home-menu')) {
            window.Tri3HomeList.loadMore();
            return;
          }
          appendLoadMore(btn);    }

          });

        }, 80);

      };

      document.addEventListener('scroll', triInfTick, { passive: true });

      window.addEventListener('resize', triInfTick, { passive: true });

      triInfTick();

    }

  }

  /* 注入 list-api 地址 */

  (function () {

    try {

      var s = document.querySelector('script[data-home-list-api]');

      if (s) { Tri3HomeList.setApi(s.getAttribute('data-home-list-api')); }

    } catch (e) {}

  
})();



  /* ---------- 首次初始化 ---------- */

  if (document.readyState === 'loading') {

    document.addEventListener('DOMContentLoaded', TriM3Init);

  } else {

    TriM3Init();

  }


})();

/* ---------- 豆瓣页：分类 Tab + 年份跳转 + 状态/类型筛选 + 搜索（委托，Swup 安全） ---------- */
function triDbApplyFilter(triDbSec) {
  if (!triDbSec) { return; }
  var triDbActiveStatus = '';
  var triDbActiveGenre = '';
  var triDbSearch = '';
  var triDbFilter = triDbSec.querySelector('.douban-filters');
  if (triDbFilter) {
    var triDbS = triDbFilter.querySelector('.douban-filter.is-active');
    if (triDbS) { triDbActiveStatus = triDbS.getAttribute('data-douban-status') || ''; }
    var triDbG = triDbFilter.querySelector('.douban-genre.is-active');
    if (triDbG) { triDbActiveGenre = triDbG.getAttribute('data-douban-genre') || ''; }
  }
  var triDbRoot = triDbSec.closest ? triDbSec.closest('.douban-card') : null;
  var triDbBox = triDbRoot ? triDbRoot.querySelector('.douban-search-input') : null;
  if (triDbBox) { triDbSearch = (triDbBox.value || '').toLowerCase().trim(); }
  var triDbEmpty = triDbActiveStatus === '' && triDbActiveGenre === '' && triDbSearch === '';
  triDbSec.querySelectorAll('.douban-group').forEach(function (triDbGrp) {
    var triDbVisible = 0;
    triDbGrp.querySelectorAll('.douban-item').forEach(function (triDbIt) {
      var triDbShow = true;
      if (triDbActiveStatus && triDbIt.getAttribute('data-status') !== triDbActiveStatus) { triDbShow = false; }
      if (triDbShow && triDbActiveGenre) {
        var triDbGs = (triDbIt.getAttribute('data-genres') || '').split('|');
        if (triDbGs.indexOf(triDbActiveGenre) === -1) { triDbShow = false; }
      }
      if (triDbShow && triDbSearch) {
        if ((triDbIt.getAttribute('data-title') || '').indexOf(triDbSearch) === -1) { triDbShow = false; }
      }
      triDbIt.classList.toggle('is-hidden', !triDbShow);
      if (triDbShow) { triDbVisible++; }
    });
    triDbGrp.classList.toggle('is-hidden', triDbEmpty ? false : triDbVisible === 0);
  });
}

document.addEventListener('click', function (triDbE) {
  /* 分类 Tab */
  var triDbBtn = triDbE.target && triDbE.target.closest ? triDbE.target.closest('.douban-tab') : null;
  if (triDbBtn) {
    var triDbType = triDbBtn.getAttribute('data-douban-tab');
    if (!triDbType) { return; }
    var triDbRoot = triDbBtn.closest('.douban-card');
    if (!triDbRoot) { return; }
    triDbRoot.querySelectorAll('.douban-tab').forEach(function (t) { t.classList.toggle('is-active', t === triDbBtn); });
    triDbRoot.querySelectorAll('.douban-sec').forEach(function (se) { se.classList.toggle('is-active', se.getAttribute('data-douban-sec') === triDbType); });
    return;
  }
  /* 状态筛选 */
  var triDbF = triDbE.target && triDbE.target.closest ? triDbE.target.closest('.douban-filter') : null;
  if (triDbF) {
    var triDbSecF = triDbF.closest('.douban-sec');
    if (!triDbSecF) { return; }
    triDbSecF.querySelectorAll('.douban-filter').forEach(function (f) { f.classList.toggle('is-active', f === triDbF); });
    triDbApplyFilter(triDbSecF);
    return;
  }
  /* 类型筛选 */
  var triDbG2 = triDbE.target && triDbE.target.closest ? triDbE.target.closest('.douban-genre') : null;
  if (triDbG2) {
    var triDbSecG = triDbG2.closest('.douban-sec');
    if (!triDbSecG) { return; }
    triDbG2.classList.toggle('is-active');
    triDbApplyFilter(triDbSecG);
    return;
  }
  /* 年份快捷跳转 */
  var triDbYA = triDbE.target && triDbE.target.closest ? triDbE.target.closest('.douban-year-anchor') : null;
  if (triDbYA) {
    var triDbSecY = triDbYA.closest('.douban-sec');
    if (!triDbSecY) { return; }
    triDbSecY.querySelectorAll('.douban-year-anchor').forEach(function (a) { a.classList.toggle('is-active', a === triDbYA); });
    var triDbGrpY = triDbSecY.querySelector('.douban-group[data-douban-group="' + triDbYA.getAttribute('data-year') + '"]');
    if (triDbGrpY) {
      var triDbTop = triDbGrpY.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - 90;
      window.scrollTo({ top: triDbTop, behavior: 'smooth' });
    }
    return;
  }
});

document.addEventListener('input', function (triDbE) {
  if (triDbE.target && triDbE.target.matches && triDbE.target.matches('.douban-search-input')) {
    var triDbSecS = triDbE.target.closest('.douban-sec') || document.querySelector('.douban-sec.is-active');
    if (triDbSecS) { triDbApplyFilter(triDbSecS); }
  }
});


/* ---------- 番剧页：状态 Tab + 类型筛选 + 年份跳转 + 搜索（委托，Swup 安全） ---------- */
function triBgmApplyFilter(triBgmSec) {
  if (!triBgmSec) { return; }
  var triBgmStatus = '';
  var triBgmGenre = '';
  var triBgmSearch = '';
  var triBgmTab = triBgmSec.closest('.bgm-card').querySelector('.bgm-tab.is-active');
  if (triBgmTab) { triBgmStatus = triBgmTab.getAttribute('data-bgm-status') || ''; }
  var triBgmG = triBgmSec.querySelector('.bgm-genre.is-active');
  if (triBgmG) { triBgmGenre = triBgmG.getAttribute('data-bgm-genre') || ''; }
  var triBgmBox = triBgmSec.closest('.bgm-card').querySelector('.bgm-search-input');
  if (triBgmBox) { triBgmSearch = (triBgmBox.value || '').toLowerCase().trim(); }
  var triBgmEmpty = triBgmStatus === '' && triBgmGenre === '' && triBgmSearch === '';
  triBgmSec.querySelectorAll('.bgm-group').forEach(function (triBgmGrp) {
    var triBgmVisible = 0;
    triBgmGrp.querySelectorAll('.bgm-item').forEach(function (triBgmIt) {
      var triBgmShow = true;
      if (triBgmStatus && triBgmIt.getAttribute('data-status') !== triBgmStatus) { triBgmShow = false; }
      if (triBgmShow && triBgmGenre) {
        if ((triBgmIt.getAttribute('data-genres') || '').split('|').indexOf(triBgmGenre) === -1) { triBgmShow = false; }
      }
      if (triBgmShow && triBgmSearch) {
        if ((triBgmIt.getAttribute('data-title') || '').indexOf(triBgmSearch) === -1) { triBgmShow = false; }
      }
      triBgmIt.classList.toggle('is-hidden', !triBgmShow);
      if (triBgmShow) { triBgmVisible++; }
    });
    triBgmGrp.classList.toggle('is-hidden', triBgmEmpty ? false : triBgmVisible === 0);
  });
}

document.addEventListener('click', function (triBgmE) {
  var triBgmTabBtn = triBgmE.target && triBgmE.target.closest ? triBgmE.target.closest('.bgm-tab') : null;
  if (triBgmTabBtn) {
    var triBgmCard = triBgmTabBtn.closest('.bgm-card');
    if (!triBgmCard) { return; }
    triBgmCard.querySelectorAll('.bgm-tab').forEach(function (tb) { tb.classList.toggle('is-active', tb === triBgmTabBtn); });
    var triBgmSecT = triBgmCard.querySelector('.bgm-sec');
    if (triBgmSecT) { triBgmApplyFilter(triBgmSecT); }
    return;
  }
  var triBgmGBtn = triBgmE.target && triBgmE.target.closest ? triBgmE.target.closest('.bgm-genre') : null;
  if (triBgmGBtn) {
    var triBgmSecG = triBgmGBtn.closest('.bgm-sec');
    if (!triBgmSecG) { return; }
    triBgmGBtn.classList.toggle('is-active');
    triBgmApplyFilter(triBgmSecG);
    return;
  }
  var triBgmYBtn = triBgmE.target && triBgmE.target.closest ? triBgmE.target.closest('.bgm-year-anchor') : null;
  if (triBgmYBtn) {
    var triBgmCardY = triBgmYBtn.closest('.bgm-card');
    if (!triBgmCardY) { return; }
    triBgmCardY.querySelectorAll('.bgm-year-anchor').forEach(function (a) { a.classList.toggle('is-active', a === triBgmYBtn); });
    var triBgmGrpY = triBgmCardY.querySelector('.bgm-group[data-bgm-group="' + triBgmYBtn.getAttribute('data-bgm-year') + '"]');
    if (triBgmGrpY) {
      var triBgmTop = triBgmGrpY.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop) - 90;
      window.scrollTo({ top: triBgmTop, behavior: 'smooth' });
    }
    return;
  }
});

document.addEventListener('input', function (triBgmE) {
  if (triBgmE.target && triBgmE.target.matches && triBgmE.target.matches('.bgm-search-input')) {
    var triBgmCardS = triBgmE.target.closest('.bgm-card');
    var triBgmSecS = triBgmCardS ? triBgmCardS.querySelector('.bgm-sec') : null;
    if (triBgmSecS) { triBgmApplyFilter(triBgmSecS); }
  }
});

/* ---------- 评论区排序切换（委托）：局部刷新列表，不整页跳转 ---------- */
document.addEventListener('click', function (e) {
  var a = e.target && e.target.closest ? e.target.closest('.comment-sort a') : null;
  if (!a) { return; }
  e.preventDefault();
  e.stopImmediatePropagation();
  var sort = a.classList.contains('comment-sort-asc') ? 'asc' : 'desc';
  var f = document.getElementById('comment-form');
  var cid = f ? (f.getAttribute('data-cid') || '') : '';
  if (cid === '') { return; }
  var api = ((window.TRI3_CMT || {}).api) || '';
  if (api === '') { return; }
  try { document.cookie = 'tri3_comment_sort=' + sort + '; path=/; max-age=2592000'; } catch (err) {}
  var box = document.getElementById('comments');
  var area = box ? box.querySelector('.chat-list-area') : null;
  if (area) { area.style.opacity = '.5'; }
  fetch(api + '?action=list&cid=' + encodeURIComponent(cid) + '&commentSort=' + sort, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
  })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok) { throw new Error((d && d.msg) || '加载失败'); }
      if (area && d.html) { area.innerHTML = d.html; }
      var cntEl = box ? box.querySelector('.comment-count') : null;
      if (cntEl && typeof d.count === 'number') { cntEl.textContent = d.count; }
      var all = document.querySelectorAll('.comment-sort a');
      all.forEach(function (x) { x.classList.toggle('is-active', x === a); });
      if (history.replaceState && a.href) { try { history.replaceState(null, '', a.href); } catch (err) {} }
      try { document.dispatchEvent(new CustomEvent('tri3:comments')); } catch (err) {}
      if (area) { area.style.opacity = ''; }
    })
    .catch(function (err) {
      if (area) { area.style.opacity = ''; }
      (window.TriMsg ? window.TriMsg.show : function(m){alert(m)})(err && err.message ? err.message : '排序加载失败');
    });
}, true);

/* ============================================================
   加载动画覆盖
   ============================================================ */
(function() {
  'use strict';

  /* 1. 图片加载完成标记 */
  function markLoadedImages() {
    document.querySelectorAll('img').forEach(function(img) {
      if (img.complete && img.naturalWidth > 0) {
        img.classList.add('loaded');
      } else if (!img.classList.contains('loaded')) {
        img.addEventListener('load', function() {
          img.classList.add('loaded');
        }, { once: true });
      }
    });
  }

  /* 2. 加载更多按钮 spinner（使用主题自带is-loading类） */
  function bindLoadMore() {
    var wrap = document.querySelector('.tri3-loadmore-wrap');
    if (!wrap || wrap.__bound) return;
    wrap.__bound = true;
    var btn = wrap.querySelector('.tri3-loadmore');

    wrap.addEventListener('click', function() {
      if (!btn || btn.classList.contains('is-loading')) return;
      btn.classList.add('is-loading');

      /* 监听新内容加载完成，2秒后自动恢复 */
      setTimeout(function() {
        btn.classList.remove('is-loading');
        markLoadedImages();
      }, 2000);
    });
  }

  /* 初始化 */
  function init() {
    markLoadedImages();
    bindLoadMore();
  }

  if (document.readyState !== 'loading') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }

  /* Swup 导航后重新初始化 */
  document.addEventListener('tri3:render', function() {
    setTimeout(init, 100);
  });


})();

/* ===== Global card dedup ===== */
window.tri3DedupCards = function(container) {
  var box = container || document.getElementById('tri3-home-list');
  if (!box) return;
  var seen = {};
  var cards = box.querySelectorAll('.post-card');
  for (var i = 0; i < cards.length; i++) {
    var card = cards[i];
    var a = card.querySelector('a[href*="archives"]');
    if (!a) continue;
    var m = a.getAttribute('href').match(/archives\/(\d+)/);
    if (!m) continue;
    var id = m[1];
    if (seen[id]) {
      if (card.parentNode) card.parentNode.removeChild(card);
    } else {
      seen[id] = true;
    }
  }
};
/* Dedup disabled - was removing legitimate cards */


/* ===== lightgallery 1.x 灯箱初始化（官方插件） ===== */
window._triLgInitTimer = null;

function triInitLightgallery() {
  if (typeof jQuery === "undefined" || typeof jQuery.fn.lightGallery === "undefined") return;
  
  var $ = jQuery;

  triAutoRefreshLightgallery();
  
  // 销毁已不在 DOM 中的实例
  $(".lg-initialized").each(function() {
    if (!document.body.contains(this)) {
      try { $(this).data("lightGallery").destroy(true); } catch(e) {}
      /* v1.2.58：只摘类不清实例会让 lightGallery 的 click 仍绑在旧 .lg-trigger 上，
         重新初始化后同一元素被绑多次（实测 4 次），点击一次触发多遍。 */
      var __lg = $(this).data("lightGallery");
      if (__lg && typeof __lg.destroy === "function") { try { __lg.destroy(); } catch (e) {} }
      $(this).removeClass("lg-initialized").removeData("lightGallery");
    }
  });
  
  // 按区域分组初始化灯箱：每个卡片/画廊/文章是独立一组
  var groupSelectors = [
    // 说说卡片 - 每个卡片内的图片一组
    ".moment-card",
    // 图片画廊 - 每个画廊一组
    ".mc-gallery", ".tri3-gallery", ".album-gallery", ".tw-album-grid", ".album-detail", ".album-photos",
    // 相册列表页瀑布流图片
    ".album-mosaic", ".album-local-list",
    // 说说正文行内图片
    ".mc-text", ".mc-inline-media", ".mc-body",
    // 文章详情页内容 - 整篇文章一组
    ".post-content", ".article-content", ".single-body", ".entry-content",
    // 相册页面
    ".album-content",
    // 其他单页内容
    ".tri3-page-single .single-content", ".wp-content"
  ];
  window._triLgGroups = groupSelectors;
  
  var $allContainers = $();
  groupSelectors.forEach(function(sel) {
    $allContainers = $allContainers.add($(sel));
  });
  
  $allContainers.each(function() {
    var $container = $(this);
    if ($container.hasClass("lg-initialized")) return;
    // 跳过嵌套容器（避免父容器和子容器重复初始化）
    if ($container.parents(".lg-initialized").length > 0) return;
    
    // 给图片包裹 a 标签
    var imgCount = 0;
    $container.find("img:not(.no-lightbox):not(.emoji):not(.avatar):not(.mc-avatar):not(.tw-c-avatar):not(.author-avatar):not(.site-logo):not(.logo-img):not(.app-bar img):not(.player-dock img):not(.mobile-tabbar img):not(.tw-bangumi-cover img)").each(function() {
      var $img = $(this);
      // 跳过已经在更内层分组容器中的图片
      if ($img.parents(".moment-card, .post-card, .mc-gallery, .tri3-gallery, .post-content, .single-body").length > 0 &&
          !$container.is(".moment-card, .post-card, .mc-gallery, .tri3-gallery, .post-content, .single-body")) {
        return;
      }
      var $a = $img.closest("a");
      if ($a.length) {
        // 跳过头像链接
        if ($img.hasClass("mc-avatar") || $img.hasClass("tw-c-avatar") || $img.hasClass("avatar") || $img.hasClass("author-avatar")) return;
        if (!$a.hasClass("lg-trigger")) $a.addClass("lg-trigger").attr("data-lg", "");
        imgCount++;
        return;
      }
      if (this.naturalWidth && this.naturalWidth < 50 && this.naturalHeight && this.naturalHeight < 50) return;
      var src = $img.attr("src") || $img.attr("data-src") || "";
      if (!src || src.indexOf("data:") === 0) return;
      
      $a = $("<a>").attr("href", src).addClass("lg-trigger").attr("data-src", src).attr("data-lg", "");
      if ($img.attr("alt")) $a.attr("data-sub-html", "<h4>" + $img.attr("alt") + "</h4>");
      $img.wrap($a);
      imgCount++;
    });
    
    if (imgCount === 0) return;
    
    $container.addClass("lg-initialized");
    
    try {
      $container.lightGallery({
        selector: ".lg-trigger",
        download: true,
        counter: true,
        loop: true,
        escKey: true,
        keyPress: true,
        controls: true,
        hideBarsDelay: 4000,
        allowMediaOverlap: true,
        showAfterLoad: true,
        backdropDuration: 200,
        mode: "lg-fade",
        speed: 400,
        swipeThreshold: 50,
        enableDrag: true,
        enableSwipe: true,
        thumbnail: true,
        showThumbByDefault: false,
        toogleThumb: true,
        thumbWidth: 80,
        thumbContHeight: 100,
        thumbMargin: 5,
        currentPagerPosition: "middle",
        animateThumb: true,
        enableThumbDrag: true,
        enableThumbSwipe: true,
        pager: true,
        zoom: true,
        scale: 1,
        zoomIcons: true,
        actualSize: true,
        fullScreen: true,
        autoplay: false,
        autoplayControls: true,
        fourceAutoplay: false,
        pause: 3000,
        progressBar: true,
        appendAutoplayControlsTo: ".lg-toolbar"
      });
    } catch(e) {
      console.error("[lightgallery] init failed:", e);
    }
  });
}

function triDestroyLightgallery() {
  if (typeof jQuery === "undefined") return;
  jQuery(".lg-initialized").each(function() {
    try { jQuery(this).data("lightGallery").destroy(true); } catch(e) {}
    /* v1.2.58：同上，先 destroy 再摘类 */
    var __lg2 = jQuery(this).data("lightGallery");
    if (__lg2 && typeof __lg2.destroy === "function") { try { __lg2.destroy(); } catch (e) {} }
    jQuery(this).removeClass("lg-initialized").removeData("lightGallery");
  });
}

function triScheduleLightgallery() {
  if (window._triLgInitTimer) clearTimeout(window._triLgInitTimer);
  window._triLgInitTimer = setTimeout(triInitLightgallery, 300);
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", triInitLightgallery);
} else {
  triInitLightgallery();
}

/* ===== v1.2.85：无限加载 / 动态插入的图片同样进灯箱 =====
   列表「加载更多」把新卡片追加进已有容器，而 triInitLightgallery 对 lg-initialized
   的容器整体跳过，导致新增图片点不开灯箱。这里按新增节点定位它所属分组的容器：
   新卡片本身还未初始化 → 只排一次初始化；往已有容器里追加图片（相册瀑布流）→
   先销毁该容器实例再重建，让新图进入同一个画廊。
   初始化会把图片包进 a.lg-trigger，因此观察器不会自激。 */
function triCollectLgGroups(nodes) {
  if (typeof jQuery === "undefined") return null;
  var $ = jQuery;
  var sel = (window._triLgGroups || []).join(",");
  var $found = $();
  $(nodes).each(function () {
    var el = (this && this.nodeType === 1) ? this : (this && this.parentNode);
    if (!el || el.nodeType !== 1) return;
    var $el = $(el);
    if (!sel) { $found = $found.add($el); return; }
    var $grp = $el.is(sel) ? $el : $el.closest(sel);
    $found = $found.add($grp.length ? $grp.first() : $el);
  });
  return $found;
}

function triRefreshLightgallery(nodes) {
  if (typeof jQuery === "undefined" || typeof jQuery.fn.lightGallery === "undefined") return;
  var $ = jQuery;
  if (nodes) {
    var $grps = triCollectLgGroups(nodes);
    if ($grps && $grps.length) {
      $grps.each(function () {
        var $c = $(this);
        if (!$c.hasClass("lg-initialized")) return;   // 新容器无需销毁，交给 schedule 初始化
        try { var lg = $c.data("lightGallery"); if (lg && typeof lg.destroy === "function") lg.destroy(true); } catch (e) {}
        try { var lg2 = $c.data("lightGallery"); if (lg2 && typeof lg2.destroy === "function") lg2.destroy(); } catch (e) {}
        $c.removeClass("lg-initialized").removeData("lightGallery");
      });
    }
  }
  triScheduleLightgallery();
}
window.tri3RefreshLightbox = triRefreshLightgallery;

function triAutoRefreshLightgallery() {
  if (window._triLgObserver || typeof MutationObserver === "undefined" || typeof jQuery === "undefined") return;
  var $ = jQuery;
  var EXCL = ".no-lightbox, .emoji, .avatar, .mc-avatar, .tw-c-avatar, .author-avatar, .site-logo, "
           + ".logo-img, .app-bar img, .player-dock img, .mobile-tabbar img, .tw-bangumi-cover img";
  var obs = new MutationObserver(function (muts) {
    if (window._triLgBusy) return;
    var hits = [];
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var n = added[j];
        if (!n || n.nodeType !== 1) continue;
        var imgs = n.tagName === "IMG" ? [n] : n.querySelectorAll("img");
        var useful = false;
        for (var k = 0; k < imgs.length; k++) {
          var $im = $(imgs[k]);
          if ($im.closest("a.lg-trigger").length) continue;
          if ($im.is(EXCL) || $im.closest(EXCL).length) continue;
          var s = $im.attr("src") || $im.attr("data-src") || "";
          if (!s || s.indexOf("data:") === 0) continue;
          useful = true;
          break;
        }
        if (useful) hits.push(n);
      }
    }
    if (!hits.length) return;
    window._triLgBusy = 1;
    try { triRefreshLightgallery(hits); } catch (e) { console.warn("[Tri3] lightbox refresh failed:", e); }
    setTimeout(function () { window._triLgBusy = 0; }, 1000);
  });
  try {
    obs.observe(document.body, { childList: true, subtree: true });
    window._triLgObserver = obs;
  } catch (e) {}
}

/* Swup 导航后的初始化统一交给 Stimulus（assets/js/tri-app.js 的 tri3 控制器）：
   注册到 window.TriHooks 即可，不必各自监听 tri3:render —— Swup 换 <body> 时 connect() 会重跑。 */
(function (H) {
  H.lightgallery = triScheduleLightgallery;
  H.playerSync = function () {
    try { if (window.TriPlayerSync) window.TriPlayerSync(); } catch (e) {}
  };
  H.init = function () {
    try { if (typeof TriM3Init === "function") TriM3Init(); } catch (e) {}
  };
})(window.TriHooks = window.TriHooks || {});
document.addEventListener("swup:visit:start", triDestroyLightgallery);
