/* TriM3 · 全站动效驱动器（IntersectionObserver 驱动）
 *
 * 职责：
 *  1) 数字滚动 countUp：.tri3-count[data-count] 进入视口后从 0 平滑滚动到目标值，
 *     完成标记 .done；无 JS / reduced-motion 时保留初始真实值（SEO 安全）。
 *  2) 归档柱状图生长：.stat-bars 进入视口后加 .is-anim，柱条 scaleY 生长。
 *  3) 与 Swup / 首页列表局部切换协同：监听 tri3:render 事件与 HomeList 切换后重扫。
 *  4) 尊重 prefers-reduced-motion：直接完成态，不执行动画。
 */
(function () {
  'use strict';

  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var io = null;

  /* ---------- 数字格式化 ---------- */
  function fmtNum(val, digits) {
    if (digits > 0) {
      return val.toFixed(digits);
    }
    /* 千分位 */
    try {
      return Number(val.toFixed(0)).toLocaleString('zh-CN');
    } catch (e) {
      return String(Math.round(val));
    }
  }

  /* ---------- 单个数字滚动 ---------- */
  function runCount(el) {
    if (el.classList.contains('done')) { return; }
    var raw = (el.getAttribute('data-count') || '').trim();
    if (raw === '') { return; }
    var target = parseFloat(raw);
    if (isNaN(target)) { return; }
    var hasDot = raw.indexOf('.') >= 0;
    var digits = hasDot ? (raw.split('.')[1] || '').length : 0;

    if (reduced || typeof requestAnimationFrame === 'undefined') {
      el.textContent = fmtNum(target, digits);
      el.classList.add('done');
      return;
    }

    /* 同步归零：footer 脚本在首屏绘制前执行，用户只会看到 0→N 的滚动 */
    el.textContent = '0';

    var dur = 1200;
    var start = null;
    function step(ts) {
      if (start === null) { start = ts; }
      var p = Math.min(1, (ts - start) / dur);
      var ease = 1 - Math.pow(1 - p, 3);
      el.textContent = fmtNum(target * ease, digits);
      if (p < 1) {
        requestAnimationFrame(step);
      } else {
        el.textContent = fmtNum(target, digits);
        el.classList.add('done');
      }
    }
    requestAnimationFrame(step);
  }

  /* ---------- 扫描并观察 ---------- */
  function scan(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;

    var counts = root.querySelectorAll('.tri3-count[data-count]:not(.done)');
    Array.prototype.forEach.call(counts, function (el) {
      if (reduced || typeof IntersectionObserver === 'undefined') {
        runCount(el);
        return;
      }
      var r = el.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      if (r.top < vh && r.bottom > 0) {
        runCount(el);
      } else if (io) {
        io.observe(el);
      }
    });

    var bars = root.querySelectorAll('.stat-bars:not(.is-anim)');
    Array.prototype.forEach.call(bars, function (el) {
      if (reduced || typeof IntersectionObserver === 'undefined') {
        el.classList.add('is-anim');
        return;
      }
      var r = el.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      if (r.top < vh && r.bottom > 0) {
        el.classList.add('is-anim');
      } else if (io) {
        io.observe(el);
      }
    });
  }

  function init() {
    if (typeof IntersectionObserver === 'undefined') { return; }
    if (io) { return; }
    io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) { return; }
        var t = en.target;
        io.unobserve(t);
        if (t.classList.contains('tri3-count')) {
          runCount(t);
        } else if (t.classList.contains('stat-bars')) {
          t.classList.add('is-anim');
        }
      });
    }, { rootMargin: '0px 0px -10% 0px' });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); scan(); });
  } else {
    init();
    scan();
  }

  /* Swup 整页切换 / 首页列表局部切换 / 说说分页 后重扫 */
  document.addEventListener('tri3:render', function () { init(); scan(); });
  document.addEventListener('tri3:list-switch', function () { init(); scan(document); });
  document.addEventListener('tri3:home-switch', function () { init(); scan(document); });
  document.addEventListener('tri3:homelist', function () { init(); scan(document); });

  window.Tri3Motion = {
    update: function (scope) { init(); scan(scope); },
    runCount: runCount
  };
})();
