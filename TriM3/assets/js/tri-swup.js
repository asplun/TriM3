/*!
 * TriM3 · Swup 引导（v2 页面切换优化）
 * 淡入淡出 + 顶部进度条 + 平滑滚动
 */
(function () {
  'use strict';
  if (window.__triSwupBoot) return;


  function boot() {
    if (typeof window.Swup !== 'function') {
      return;
    }
    window.__triSwupBoot = 1;

    /* ---- 进度条 ---- */
    var bar = document.createElement('div');
    bar.id = 'tri-progress';
    document.head.appendChild(bar);

    var barTimer = null;
    function showBar() {
      clearTimeout(barTimer);
      bar.className = '';
      bar.classList.add('tri-active');
    }
    function doneBar() {
      bar.classList.remove('tri-active');
      bar.classList.add('tri-done');
      barTimer = setTimeout(function () {
        bar.className = '';
        bar.style.transform = '';
      }, 350);
    }

    /* ---- 插件 ---- */
    var plugins = [];

    if (typeof window.SwupPreloadPlugin === 'function') {
      plugins.push(new window.SwupPreloadPlugin());
    }
    if (typeof window.SwupHeadPlugin === 'function') {
      plugins.push(new window.SwupHeadPlugin({
        persistTags: 'script[src], style'
      }));
    }
    if (typeof window.SwupBodyClassPlugin === 'function') {
      plugins.push(new window.SwupBodyClassPlugin({
        attributes: ['data-page', 'data-in-category']
      }));
    }

    /* ---- Swup 实例 ---- */
    var swup = new window.Swup({
      containers: ['div.layout'],
      linkSelector: 'a[href]',
      animationSelector: '.layout',
      cache: true,
      linkToSelf: 'scroll',
      plugins: plugins
    });
    window.TriSwup = swup;

    /* ---- 生命周期 ---- */

    // 开始导航：显示进度条
    swup.hooks.on('visit:start', function () {
      showBar();
    });

    // 内容替换后：补执行容器内脚本
    swup.hooks.on('content:replace', function () {
      var box = document.querySelector('div.layout');
      if (!box) return;
      Array.prototype.slice.call(box.querySelectorAll('script')).forEach(function (old) {
        var t = (old.getAttribute('type') || '').toLowerCase();
        if (t && t !== 'text/javascript' && t !== 'application/javascript' && t !== 'module') return;
        var el = document.createElement('script');
        Array.prototype.forEach.call(old.attributes, function (a) { el.setAttribute(a.name, a.value); });
        if (old.src) { el.async = false; }
        el.textContent = old.textContent;
        old.parentNode.replaceChild(el, old);
      });
    });

    // 页面渲染完成：滚动到顶部 + 初始化组件 + 收进度条
    swup.hooks.on('page:view', function () {
      doneBar();

      // 平滑滚到顶部（非后退/前进时）
      try {
        if (!swup.visit.history.popstate) {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
      } catch (e) {
        window.scrollTo(0, 0);
      }

      // 主题初始化入口
      try { if (typeof window.TriRunHooks === 'function') window.TriRunHooks(); } catch (e) {}
      document.dispatchEvent(new CustomEvent('tri3:render'));
      document.dispatchEvent(new CustomEvent('tri3:homelist'));
      try { if (typeof window.TriSyncNav === 'function') window.TriSyncNav(location.href); } catch (e) {}
    });

    // 导航失败：收进度条，降级整页跳转
    swup.hooks.on('visit:fail', function (visit) {
      doneBar();
      try {
        if (visit && visit.to && visit.to.url) {
          window.location.href = visit.to.url;
        }
      } catch (e) {}
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
