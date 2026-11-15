(function () {
  'use strict';
  var cfg = null;
  var timer = null;
  function getCfg() {
    cfg = window.TRI3_NOTIFY || null;
    return cfg && cfg.api ? cfg : null;
  }
  var latest = 0;
  var button = null;
  var panel = null;
  var busy = false;

  function request(url) {
    return fetch(url, {credentials: 'same-origin', cache: 'no-store'}).then(function (r) {
      if (!r.ok) throw new Error('network');
      return r.json();
    });
  }
  function setBadge(id) {
    latest = Number(id) || 0;
    if (button) button.classList.toggle('has-unread', latest > 0);
  }
  function createPanel() {
    if (!cfg && !getCfg()) return null;
    if (panel && panel.parentNode === button.parentNode) return panel;
    if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
    panel = null;
    panel = document.createElement('section');
    panel.className = 'tri-notify-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', '消息通知');
    panel.hidden = true;
    panel.innerHTML = '<div class="tri-notify-head"><strong>消息通知</strong><button type="button" class="tri-notify-close" aria-label="关闭"><i class="ri-close-line"></i></button></div><div class="tri-notify-visitors"><i class="ri-user-voice-line"></i><span>今日访客</span><strong class="tri-notify-today">' + (Number(cfg.today) || 0) + '</strong></div><div class="tri-notify-list" aria-live="polite"><div class="tri-notify-empty">正在加载…</div></div>';
    button.parentNode.appendChild(panel);
    panel.querySelector('.tri-notify-close').addEventListener('click', close);
    return panel;
  }
  function close() {
    if (!panel) return;
    panel.hidden = true;
    if (button) button.setAttribute('aria-expanded', 'false');
  }
  function showLogin() {
    if (window.TriMsg) window.TriMsg.info('请先登录后查看消息', '需要登录');
    else window.alert('请先登录后查看消息');
  }
  function render(items) {
    var list = panel.querySelector('.tri-notify-list');
    list.textContent = '';
    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'tri-notify-empty';
      empty.textContent = '暂无评论消息';
      list.appendChild(empty);
      return;
    }
    items.forEach(function (item) {
      var a = document.createElement('a');
      var meta = document.createElement('span');
      var text = document.createElement('span');
      var title = document.createElement('span');
      a.className = 'tri-notify-item';
      a.href = item.url || '#';
      meta.className = 'tri-notify-meta';
      meta.textContent = (item.author || '访客') + ' 评论了「' + (item.title || '内容') + '」';
      text.className = 'tri-notify-text';
      text.textContent = item.text || '';
      title.className = 'tri-notify-time';
      title.textContent = item.created ? new Date(item.created * 1000).toLocaleString() : '';
      a.appendChild(meta); a.appendChild(text);
      if (item.status === 'waiting') { var status = document.createElement('span'); status.className = 'tri-notify-status'; status.textContent = '待审核'; a.appendChild(status); }
      a.appendChild(title); list.appendChild(a);
    });
  }
  function load() {
    if (busy) return;
    busy = true;
    var p = createPanel();
    if (!p) { busy = false; return; }
    p.querySelector('.tri-notify-list').innerHTML = '<div class="tri-notify-empty">正在加载…</div>';
    request(cfg.viewsApi + '?do=view_read').then(function (data) {
      if (data && data.ok) p.querySelector('.tri-notify-today').textContent = String(Number(data.today) || 0);
    }).catch(function () {
      /* Visitor statistics are optional; never block notification loading. */
    }).then(function () {
      return request(cfg.api + '?action=list&limit=10');
    }).then(function (data) {
      if (data && data.loginRequired) { showLogin(); close(); return; }
      if (!data || !data.ok) throw new Error('response');
      render(data.items || []);
      if (Number(data.maxId) > Number(data.lastRead)) {
        var body = new URLSearchParams(); body.set('coid', String(data.maxId));
        fetch(cfg.api + '?action=read', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()}).then(function () {
          return request(cfg.api + '?action=list&limit=10');
        }).then(function (state) { if (state && state.ok) setBadge(state.unread ? 1 : 0); });
      } else { setBadge(data.unread ? 1 : 0); }
    }).catch(function () {
      p.querySelector('.tri-notify-list').innerHTML = '<div class="tri-notify-empty">消息暂时无法加载</div>';
    }).then(function () { busy = false; });
  }
  function togglePanel() {
    if (!window.TRI3_LOGIN || !window.TRI3_LOGIN.logged) { showLogin(); return; }
    var p = createPanel();
    if (!p || !button) return;
    p.hidden = !p.hidden;
    button.setAttribute('aria-expanded', p.hidden ? 'false' : 'true');
    if (!p.hidden) load();
  }
  function refresh() {
    var next = document.querySelector('.tri-notify-entry');
    var liveCfg = getCfg();
    if (!liveCfg || !next) return;
    if (next !== button) {
      close();
      if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
      panel = null;
      button = next;
    }
    request(cfg.api + '?action=badge').then(function (data) {
      if (data && data.ok) setBadge(data.unread ? 1 : 0);
    }).catch(function () {});
  }
  function init() {
    refresh();
    if (!timer) {
      timer = setInterval(function () {
        if (document.visibilityState !== 'visible') return;
        refresh();
      }, 60000);
    }
  }
  document.addEventListener('click', function (e) {
    var target = e.target && e.target.closest ? e.target.closest('.tri-notify-entry') : null;
    if (target) {
      e.preventDefault();
      button = target;
      togglePanel();
      return;
    }
    if (panel && !panel.hidden && (!button || (!panel.contains(e.target) && !button.contains(e.target)))) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  ['tri3:render', 'tri3:homelist', 'swup:contentReplaced'].forEach(function (ev) {
    document.addEventListener(ev, function () { window.setTimeout(refresh, 0); });
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
