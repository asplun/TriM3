/* ============================================================
 * TriM3 统一消息中心（全站消息 / 提示 / 系统通知归集）
 * ------------------------------------------------------------
 * 适用范围：前台（首页 / 文章 / 说说 / 单页）＋ 主题后台（options-theme.php）
 *           ＋ 外部管理页（说说管理 / 相册管理 / 小组件管理）
 *
 * 定位：页面上方居中，z-index 2147483000（全站最高层），
 *       任何弹窗 / 浮层之下都不会挡住消息。
 * 风格：Material Design 3 —— 圆角 14、淡色表面 + 毛玻璃、类型色图标、
 *       elevation 阴影、平滑滑入 / 滑出，多消息在同一个面板内堆叠。
 *
 * 用法：
 *   TriMsg.success('已保存') / TriMsg.error('保存失败')
 *   TriMsg.warning('注意') / TriMsg.info('提示')
 *   TriMsg.show({ type:'success', text:'…', title:'可选', duration:2400 })
 *   TriMsg.closeAll()
 * ============================================================ */
(function () {
  'use strict';

  var CSS = '' +
    '.tri-msg-box{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:2147483000;' +
      'display:flex;flex-direction:column;align-items:center;gap:10px;' +
      'width:min(420px,calc(100vw - 28px));pointer-events:none;' +
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue","PingFang SC","Microsoft YaHei",sans-serif;}' +
    '.tri-msg{pointer-events:auto;position:relative;width:100%;box-sizing:border-box;' +
      'display:flex;align-items:flex-start;gap:12px;padding:13px 42px 13px 14px;' +
      'border-radius:14px;color:var(--tri-msg-ink,#1c1b1f);' +
      'background:var(--tri-msg-bg,rgba(255,255,255,.94));' +
      '-webkit-backdrop-filter:blur(14px) saturate(1.4);backdrop-filter:blur(14px) saturate(1.4);' +
      'box-shadow:0 2px 3px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.14);' +
      'opacity:0;transform:translateY(-16px) scale(.97);' +
      'transition:opacity .3s ease,transform .3s cubic-bezier(.2,.8,.2,1);' +
      'border:1px solid rgba(0,0,0,.05);overflow:hidden;}' +
    '.tri-msg.show{opacity:1;transform:translateY(0) scale(1);}' +
    '.tri-msg.hide{opacity:0;transform:translateY(-14px) scale(.96);transition:opacity .22s,transform .22s;}' +
    '.tri-msg::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;' +
      'background:var(--tri-msg-acc,#6750a4);}' +
    '.tri-msg .tri-msg-ic{flex:0 0 auto;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;' +
      'background:var(--tri-msg-acc);color:#fff;font-size:18px;}' +
    '.tri-msg .tri-msg-tx{flex:1 1 auto;min-width:0;padding-top:1px;}' +
    '.tri-msg .tri-msg-title{font-size:14px;font-weight:600;line-height:1.5;color:var(--tri-msg-ink,#1c1b1f);}' +
    '.tri-msg .tri-msg-text{font-size:13px;line-height:1.55;color:var(--tri-msg-sub,#49454f);margin-top:2px;word-break:break-all;}' +
    '.tri-msg .tri-msg-x{position:absolute;right:8px;top:8px;width:26px;height:26px;border:0;border-radius:50%;cursor:pointer;' +
      'background:transparent;color:var(--tri-msg-sub,#49454f);font-size:16px;display:flex;align-items:center;justify-content:center;' +
      'opacity:0;transition:opacity .18s,background .18s;}' +
    '.tri-msg:hover .tri-msg-x{opacity:.85;}' +
    '.tri-msg .tri-msg-x:hover{background:rgba(0,0,0,.07);opacity:1;}' +
    '.tri-msg .tri-msg-x i{font-size:16px;}' +
    /* 暗色适配：跟随页面根元素 data-theme / html[data-mode] */
    'html[data-theme="dark"] .tri-msg,html[data-mode="dark"] .tri-msg{color:#e6e1e5;' +
      'background:rgba(40,38,42,.92);border-color:rgba(255,255,255,.08);}' +
    'html[data-theme="dark"] .tri-msg .tri-msg-text,html[data-mode="dark"] .tri-msg .tri-msg-text{color:#cac4d0;}' +
    'html[data-theme="dark"] .tri-msg .tri-msg-title,html[data-mode="dark"] .tri-msg .tri-msg-title{color:#e6e1e5;}' +
    'html[data-theme="dark"] .tri-msg .tri-msg-x,html[data-mode="dark"] .tri-msg .tri-msg-x{color:#cac4d0;}' +
    '@media (max-width:520px){.tri-msg-box{top:10px;width:calc(100vw - 20px);gap:8px;}' +
      '.tri-msg{padding:11px 38px 11px 12px;border-radius:12px;}}';

  var ICONS = {
    success: 'ri-checkbox-circle-fill',
    error: 'ri-close-circle-fill',
    warning: 'ri-alert-fill',
    info: 'ri-information-fill'
  };
  var COLORS = {
    success: '#2e7d32',
    error: '#b3261e',
    warning: '#b26a00',
    info: '#6750a4'
  };
  var DEFAULT_MS = {
    success: 2400,
    error: 3800,
    warning: 3200,
    info: 2600
  };
  var MAX = 5;
  var box = null;
  var count = 0;

  function injectCss() {
    if (document.getElementById('tri-msg-style')) { return; }
    var st = document.createElement('style');
    st.id = 'tri-msg-style';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function ensure() {
    injectCss();
    if (box && box.isConnected) { return box; }
    box = document.createElement('div');
    box.className = 'tri-msg-box';
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    document.body.appendChild(box);
    return box;
  }

  function el(tag, cls, html) {
    var d = document.createElement(tag);
    if (cls) { d.className = cls; }
    if (html) { d.innerHTML = html; }
    return d;
  }

  function push(opts) {
    var type = opts.type || 'info';
    var text = opts.text || '';
    var title = opts.title || '';
    var duration = opts.duration || DEFAULT_MS[type] || 2600;
    if (!text && !title) { return; }
    var b = ensure();
    var acc = opts.color || COLORS[type] || COLORS.info;

    var item = el('div', 'tri-msg tri-msg-' + type);
    item.style.setProperty('--tri-msg-acc', acc);
    item.innerHTML =
      '<div class="tri-msg-ic"><i class="' + (ICONS[type] || ICONS.info) + '"></i></div>' +
      '<div class="tri-msg-tx">' +
        (title ? '<div class="tri-msg-title"></div>' : '') +
        '<div class="tri-msg-text"></div>' +
      '</div>' +
      '<button type="button" class="tri-msg-x" aria-label="关闭"><i class="ri-close-line"></i></button>';
    if (title) { item.querySelector('.tri-msg-title').textContent = title; }
    item.querySelector('.tri-msg-text').textContent = text;

    var x = item.querySelector('.tri-msg-x');
    x.addEventListener('click', function () { dismiss(); });

    function dismiss() {
      if (!item.isConnected) { return; }
      item.classList.add('hide');
      clearTimeout(item._t);
      setTimeout(function () {
        item.remove();
        count = Math.max(0, count - 1);
      }, 240);
    }

    /* 堆叠上限：超出时移除最早一条 */
    while (b.children.length >= MAX) {
      var first = b.firstElementChild;
      if (first && first.classList) {
        first.classList.add('hide');
        setTimeout(function () { if (first.isConnected) { first.remove(); count = Math.max(0, count - 1); } }, 220);
      } else if (first) { first.remove(); }
    }

    b.appendChild(item);
    count += 1;
    /* 强制重排后加 .show：入场 transition 正常播放；即使环境节流，最终态也保证可见 */
    void item.offsetWidth;
    item.classList.add('show');
    item._t = setTimeout(dismiss, duration);
  }

  function show(opts) {
    if (typeof opts === 'string') { opts = { text: opts }; }
    if (!opts || typeof opts !== 'object') { opts = { text: String(opts || '') }; }
    push(opts);
  }

  function closeAll() {
    if (!box) { return; }
    Array.prototype.slice.call(box.children).forEach(function (c) {
      if (c.classList) { c.classList.add('hide'); }
      setTimeout(function () { if (c.isConnected) { c.remove(); } }, 220);
    });
    count = 0;
  }

  var api = {
    show: show,
    closeAll: closeAll,
    success: function (text, title) { push({ type: 'success', text: text, title: title || '' }); },
    error: function (text, title) { push({ type: 'error', text: text, title: title || '' }); },
    warning: function (text, title) { push({ type: 'warning', text: text, title: title || '' }); },
    info: function (text, title) { push({ type: 'info', text: text, title: title || '' }); }
  };
  window.TriMsg = api;
  /* 兼容旧调用：window.toast（前台各模块兜底） */
  if (typeof window.toast !== 'function') {
    window.toast = function (m) { api.show(m); };
  }
})();
