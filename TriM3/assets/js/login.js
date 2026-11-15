/* TriM3 前台登录交互（Swup 适配版）
 *
 * 事件委托绑定（Swup 切换后 后仍有效）：
 *  - document 点击委托处理 .tri-login-entry 打开登录/用户菜单
 *  - 每次打开时读取最新 window.TRI3_LOGIN 配置
 */
(function () {
  'use strict';

  var mask = null;
  var menu = null; /* 已登录右键菜单式小弹窗 */

  function getCfg() {
    return window.TRI3_LOGIN || { logged: false, name: '', avatar: '', adminUrl: '', loginApi: '' };
  }

  function ensureMask() {
    if (mask && !document.contains(mask)) { mask = null; } /* Swup 换页后旧遮罩已脱离文档 */
    if (mask) { return mask; }
    mask = document.querySelector('.tri-dialog-mask');
    if (!mask) {
      mask = document.createElement('div');
      mask.className = 'tri-dialog-mask';
      mask.hidden = true;
      document.body.appendChild(mask);
    }
    return mask;
  }

  function closeDialog() { ensureMask().hidden = true; }
  function closeMenu() { if (menu) { menu.classList.remove('is-open'); } }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- 登录表单（居中弹窗，未登录） ---------- */
  function renderLogin() {
    var m = ensureMask();
    var CFG = getCfg();
    m.innerHTML = '<div class="tri-dialog" role="dialog" aria-modal="true" aria-label="登录">'
      + '<h3>登录</h3>'
      + '<div class="tri-field"><label for="tri-login-name">用户名</label>'
      + '<input id="tri-login-name" type="text" autocomplete="username" value=""></div>'
      + '<div class="tri-field"><label for="tri-login-pass">密码</label>'
      + '<input id="tri-login-pass" type="password" autocomplete="current-password"></div>'
      + '<div class="tri-login-row">'
      + '<label class="tri-check"><input id="tri-login-remember" type="checkbox"> 记住我</label>'
      + '<button type="button" class="tri-btn tri-btn-text" id="tri-login-cancel">取消</button>'
      + '</div>'
      + '<p class="tri-login-error" id="tri-login-error"></p>'
      + '<div class="tri-dialog-actions">'
      + '<button type="button" class="tri-btn tri-btn-filled" id="tri-login-submit">登录</button>'
      + '</div>'
      + '</div>';
    var name = document.getElementById('tri-login-name');
    var pass = document.getElementById('tri-login-pass');
    var err = document.getElementById('tri-login-error');
    var submit = document.getElementById('tri-login-submit');
    var cancel = document.getElementById('tri-login-cancel');
    if (!name || !pass || !err || !submit || !cancel) { return; }
    cancel.addEventListener('click', function (e) { e.stopPropagation(); closeDialog(); });
    name.focus();
    function doLogin() {
      if (submit.disabled) { return; }
      var n = name.value.trim();
      var p = pass.value;
      if (!n || !p) { err.textContent = '请输入用户名和密码'; return; }
      submit.disabled = true;
      submit.textContent = '登录中…';
      var body = 'do=login&name=' + encodeURIComponent(n) + '&password=' + encodeURIComponent(p)
        + (document.getElementById('tri-login-remember').checked ? '&remember=1' : '');
      fetch(CFG.loginApi, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.ok) {
          window.location.reload();
        } else {
          submit.disabled = false;
          submit.textContent = '登录';
          err.textContent = (j && j.msg) || '登录失败';
        }
      }).catch(function () {
        submit.disabled = false;
        submit.textContent = '登录';
        err.textContent = '网络异常，请重试';
      });
    }
    submit.addEventListener('click', function (e) { e.stopPropagation(); doLogin(); });
    pass.addEventListener('keydown', function (e) { if (e.key === 'Enter') { doLogin(); } });
    name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { pass.focus(); } });
  }

  /* ---------- 已登录：头像旁右键菜单式小弹窗 ---------- */
  function openMenu(entry) {
    var CFG = getCfg();
    if (menu && !document.contains(menu)) { menu = null; }
    if (!menu) {
      menu = document.createElement('div');
      menu.className = 'tri-login-menu';
      menu.addEventListener('click', function (e) { e.stopPropagation(); });
      document.body.appendChild(menu);
    }
    var av = CFG.avatar
      ? '<img src="' + esc(CFG.avatar) + '" alt="">'
      : '<span class="tri-menu-av-fallback">' + esc((CFG.name || '?').charAt(0)) + '</span>';
    menu.innerHTML =
      '<div class="tri-menu-head">' + av + '<div class="tri-menu-username">' + esc(CFG.name) + '</div></div>'
      + '<div class="tri-menu-sep"></div>'
      + '<a class="tri-menu-item" href="' + esc(CFG.adminUrl) + 'write-post.php"><i class="ri-edit-line"></i>发布文章</a>'
      + '<a class="tri-menu-item" href="' + esc(CFG.adminUrl) + 'options-theme.php"><i class="ri-settings-3-line"></i>后台设置</a>'
      + '<div class="tri-menu-sep"></div>'
      + '<button type="button" class="tri-menu-item is-danger" id="tri-logout"><i class="ri-logout-box-r-line"></i>退出登录</button>';
    var btn = menu.querySelector('#tri-logout');
    if (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        fetch(CFG.loginApi, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'do=logout',
          credentials: 'same-origin'
        }).then(function () { window.location.reload(); })
          .catch(function () { window.location.reload(); });
      });
    }
    /* 定位：按钮下方右对齐（固定定位，避免被 banner overflow 裁剪） */
    var r = entry.getBoundingClientRect();
    var mw = 224;
    var top = r.bottom + 8;
    menu.style.left = '0px';
    menu.style.top = '0px';
    menu.classList.add('is-open');
    var mh = menu.offsetHeight;
    var left = Math.max(8, r.right - mw);
    if (top + mh > window.innerHeight - 8) {
      top = Math.max(8, r.top - mh - 8);
    }
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  }

  /* ---------- 事件委托 ---------- */
  document.addEventListener('click', function (e) {
    var entry = e.target && e.target.closest ? e.target.closest('.tri-login-entry') : null;
    if (!entry) { return; }
    e.stopPropagation();
    var CFG = getCfg();
    if (menu && menu.classList.contains('is-open')) { closeMenu(); return; }
    if (CFG.logged) {
      openMenu(entry);
    } else {
      renderLogin();
      ensureMask().hidden = false;
    }
  }, true);

  document.addEventListener('click', function () { closeMenu(); });
  /* 点击遮罩空白处关闭登录弹窗（点弹窗内部不消失） */
  document.addEventListener('click', function (e) {
    var mask = e.target;
    if (mask && mask.classList && mask.classList.contains('tri-dialog-mask')) {
      closeDialog();
      return;
    }
    /* Close button for say dialog */
    var closeBtn = e.target && e.target.closest ? e.target.closest('.tri-say-close') : null;
    if (closeBtn) { closeDialog(); return; }
    /* Any cancel/close button inside dialog */
    var cancelBtn = e.target && e.target.closest ? e.target.closest('#tri-login-cancel, .tri-dialog-close') : null;
    if (cancelBtn) { closeDialog(); return; }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeMenu(); closeDialog(); }
  });
  window.addEventListener('scroll', closeMenu, true);
  window.addEventListener('resize', closeMenu);

  /* Swup 后确保遮罩存在且关闭（配置可能变化） */
  document.addEventListener('tri3:render', function () { closeMenu(); closeDialog(); });
})();
