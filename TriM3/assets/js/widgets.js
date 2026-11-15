/* ============================================================
 * TriM3 小组件系统 - 前台交互
 * 时钟 / 日历 / 进度 / 倒计时 / 一言 / 诗词 / 访客计数
 * 初始化时机：DOMContentLoaded + tri3:render（Swup 切换后重扫）
 * ============================================================ */
(function () {
  'use strict';

  /* ---------------- 工具 ---------------- */
  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /* ---------------- 翻页时钟 ---------------- */
  function initFlipclock(el) {
    var showSec = el.getAttribute('data-tw-sec') !== '0';
    var fcs = $all('[data-fc]', el);
    if (!fcs.length) return;
    function setDigit(fc, v) {
      if (fc.dataset.v === String(v)) return;
      var bs = fc.querySelectorAll('b');
      if (bs.length < 2) { bs[0].textContent = v; return; }
      bs[1].textContent = v;
      fc.classList.add('flip');
      clearTimeout(fc._t);
      fc._t = setTimeout(function () {
        bs[0].textContent = v;
        fc.classList.remove('flip');
      }, 320);
      fc.dataset.v = v;
    }
    function tick() {
      var d = new Date();
      var h = pad(d.getHours()), m = pad(d.getMinutes()), s = pad(d.getSeconds());
      setDigit($('[data-fc="h1"]', el), h.charAt(0));
      setDigit($('[data-fc="h2"]', el), h.charAt(1));
      setDigit($('[data-fc="m1"]', el), m.charAt(0));
      setDigit($('[data-fc="m2"]', el), m.charAt(1));
      if (showSec) {
        setDigit($('[data-fc="s1"]', el), s.charAt(0));
        setDigit($('[data-fc="s2"]', el), s.charAt(1));
      }
    }
    tick();
    if (el._timer) { clearInterval(el._timer); }
    el.setAttribute('data-tri-timer', '1');
    el._timer = setInterval(tick, 1000);
  }

  /* ---------------- 简约日历 ---------------- */
  function initCalendar(el) {
    var today = new Date();
    var y = today.getFullYear(), mo = today.getMonth();
    var first = new Date(y, mo, 1);
    var startDow = first.getDay(); /* 0 周日 */
    var daysInMonth = new Date(y, mo + 1, 0).getDate();
    var prevDays = new Date(y, mo, 0).getDate();
    var head = '<div class="tw-cal-head"><b>' + y + ' 年 ' + (mo + 1) + ' 月</b></div>';
    var grid = '<div class="tw-cal-grid">';
    ['日', '一', '二', '三', '四', '五', '六'].forEach(function (w) {
      grid += '<span class="w">' + w + '</span>';
    });
    for (var i = startDow - 1; i >= 0; i--) {
      grid += '<span class="d other">' + (prevDays - i) + '</span>';
    }
    for (var d = 1; d <= daysInMonth; d++) {
      var cls = 'd' + (d === today.getDate() ? ' today' : '');
      grid += '<span class="' + cls + '">' + d + '</span>';
    }
    grid += '</div>';
    el.innerHTML = head + grid;
  }

  /* ---------------- 进度条 ---------------- */
  function initProgress(el) {
    var kind = el.getAttribute('data-tw-progress');
    var now = new Date();
    var pct = 0;
    if (kind === 'day') {
      var daySec = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
      pct = daySec / 86400 * 100;
    } else if (kind === 'week') {
      var dow = (now.getDay() + 6) % 7; /* 周一=0 */
      var daySec2 = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
      pct = (dow * 86400 + daySec2) / (7 * 86400) * 100;
    } else if (kind === 'year') {
      var start = new Date(now.getFullYear(), 0, 1);
      var end = new Date(now.getFullYear() + 1, 0, 1);
      pct = (now - start) / (end - start) * 100;
    }
    pct = Math.min(100, Math.max(0, pct));
    requestAnimationFrame(function () {
      $('.tw-progress-bar i', el).style.width = pct.toFixed(2) + '%';
      $('b', el).textContent = pct.toFixed(1) + '%';
    });
  }

  /* ---------------- 倒计时 ---------------- */
  function initCountdown(el) {
    var target = parseInt(el.getAttribute('data-tw-target'), 10);
    if (!target) return;
    function tick() {
      var diff = target - Date.now();
      if (diff < 0) diff = 0;
      var s = Math.floor(diff / 1000);
      var d = Math.floor(s / 86400); s -= d * 86400;
      var h = Math.floor(s / 3600); s -= h * 3600;
      var m = Math.floor(s / 60); s -= m * 60;
      $('[data-unit="d"]', el).textContent = d > 99 ? d : pad(d);
      $('[data-unit="h"]', el).textContent = pad(h);
      $('[data-unit="m"]', el).textContent = pad(m);
      $('[data-unit="s"]', el).textContent = pad(s);
    }
    tick();
    if (el._timer) { clearInterval(el._timer); }
    el.setAttribute('data-tri-timer', '1');
    el._timer = setInterval(tick, 1000);
  }

  /* ---------------- 一言 ---------------- */
  function initHitokoto(el) {
    var cat = (el.getAttribute('data-tw-cat') || '').trim();
    var url = 'https://v1.hitokoto.cn/?encode=json' + (cat ? '&cat=' + encodeURIComponent(cat) : '');
    fetch(url, { mode: 'cors' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.hitokoto) throw new Error('empty');
      $('.tw-quote-text', el).textContent = j.hitokoto;
      $('.tw-quote-src', el).textContent = (j.from || '') + (j.from_who ? ' · ' + j.from_who : '');
    }).catch(function () {
      $('.tw-quote-text', el).textContent = '一花一世界，一叶一菩提。';
      $('.tw-quote-src', el).textContent = '佛典';
    });
  }

  /* ---------------- 今日诗词（走服务端代理，避免 CORS 预检） ---------------- */
  function poemFallback(el) {
    $('.tw-quote-text', el).textContent = '白日依山尽，黄河入海流。';
    $('.tw-quote-src', el).textContent = '王之涣 · 登鹳雀楼';
  }
  function initPoem(el) {
    var api = window.TRI3_WIDGETS && window.TRI3_WIDGETS.api;
    if (!api) { poemFallback(el); return; }
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'do=poem',
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok || !j.content) throw new Error('no data');
      var content = j.content || '';
      var origin = j.origin || '';
      var author = j.author || '';
      var lines = content.split('\n').filter(function (l) { return l.trim() !== ''; });
      var text = lines.slice(0, 2).join('，').replace(/[，。；、]$/, '');
      $('.tw-quote-text', el).textContent = text;
      $('.tw-quote-src', el).textContent = (author ? author + ' · ' : '') + origin;
    }).catch(function () {
      poemFallback(el);
    });
  }

  /* ---------------- 访客计数（每次进站都刷新数字；是否 +1 由服务端按浏览器/天判定） ---------------- */
  var triViewPostedAt = 0;
  function viewAdd() {
    var api = window.TRI3_WIDGETS && window.TRI3_WIDGETS.api;
    if (!api) return;
    /* init() 在首屏会从 DOMContentLoaded 与 tri3:render 各触发一次，两次并发 POST 会让新访客被 +2；
       这里 3 秒内只发一次，Swup 翻页（间隔更长）仍会刷新数字。 */
    var now = Date.now();
    if (now - triViewPostedAt < 3000) return;
    triViewPostedAt = now;
    fetch(api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'do=view_add',
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) return;
      document.querySelectorAll('.tw-views-item .tw-views-num').forEach(function (n, i) {
        n.textContent = i === 0 ? (j.today || 0) : (j.total || 0);
      });
    }).catch(function () {});
  }

  /* ---------------- 归档：点击月份展开（懒加载） ---------------- */
  function initArchive(el) {
    var month = el.querySelector('.tw-archive-month-title');
    if (!month) return;
    month.addEventListener('click', function () {
      var loaded = el.getAttribute('data-tw-loaded') === '1';
      var list = el.querySelector('.tw-archive-posts');
      if (!list) return;
      if (loaded) {
        var show = list.hasAttribute('hidden');
        list.toggleAttribute('hidden', !show);
        el.classList.toggle('active', show);
        return;
      }
      var ym = el.getAttribute('data-tw-ym');
      var api = window.TRI3_WIDGETS && window.TRI3_WIDGETS.api;
      if (!api) { return; }
      el.classList.add('loading');
      fetch(api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'do=archive_month&ym=' + encodeURIComponent(ym),
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (j) {
        el.classList.remove('loading');
        if (!j || !j.ok) return;
        var rows = j.rows || [];
        var html = '';
        rows.forEach(function (it) {
          html += '<li><a href="' + (it.permalink || '#') + '">' + escapeHtml(it.title) +
            '<span class="tw-list-date">' + (it.d || '') + '</span></a></li>';
        });
        list.innerHTML = html || '<li class="tw-empty">本月暂无文章</li>';
        el.setAttribute('data-tw-loaded', '1');
        list.removeAttribute('hidden');
        el.classList.add('active');
      }).catch(function () {
        el.classList.remove('loading');
      });
    });
  }

  /* ---------------- 初始化入口 ---------------- */
  var inited = false;
  function init() {
    // 只初始化还没初始化过的组件，避免重复加载 API
    var toInit = function(selector, fn) {
      $all(selector).forEach(function(el) {
        if (el.dataset.twInit === '1') return;
        el.dataset.twInit = '1';
        fn(el);
      });
    };
    toInit('.tw-flipclock', initFlipclock);
    toInit('[data-tw-calendar]', initCalendar);
    toInit('[data-tw-progress]', initProgress);
    toInit('.tw-countdown', initCountdown);
    toInit('[data-tw-quote="hitokoto"]', initHitokoto);
    toInit('[data-tw-quote="poem"]', initPoem);
    toInit('.tw-archive-month', initArchive);
    viewAdd();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  /* Swup 切换后重新扫描 */
  document.addEventListener('tri3:render', function () { init(); });
  document.addEventListener('tri3:homelist', function () { init(); });
})();

// Swup navigation cleanup: clear all widget timers
// 定时器在创建时会给元素打 data-tri-timer 标记；此前这里查的是 [data-timer]，
// 而全仓没有任何元素带该属性 → 每次换页后翻页钟/倒计时的 interval 都留着，持有旧 DOM。
document.addEventListener('swup:visit:start', function() {
  document.querySelectorAll('[data-tri-timer]').forEach(function(el) {
    if (el._timer) { clearInterval(el._timer); el._timer = null; }
    el.removeAttribute('data-tri-timer');
  });
});
