/* TriM3 后台设置面板交互：Tab 归组 / MD3 配色推荐 / 导航可视化编辑器 */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }
  window.Tri3AdminBoot = function () {
    var map = window.TRI3_PANEL_MAP || {};
    var container = document.querySelector('#tri3-config');
    var form = container ? (container.querySelector('form') || document.querySelector('form[action*="themes-edit"]') || document.querySelector('form')) : null;
    if (!container || !form) { return; }

    /* Typecho 1.3 真实时序：themeConfig 输出的骨架在 <form> 之外，
       <form> 由 Form::render() 单独输出。若直接把表单元素移入骨架面板，
       元素会脱离 <form> 导致保存为空。先把骨架块整体移入 <form> 内，
       再进行归组，保证所有元素始终在表单里。 */
    var frag = document.createDocumentFragment();
    ['tri3-hero', 'tri3-tabs', 'tri3-panels', 'tri3-note'].forEach(function (cls) {
      var el = container.querySelector('.' + cls);
      if (el) { frag.appendChild(el); }
    });

    form.classList.add("tri3-form");
    form.insertBefore(frag, form.firstChild);

    /* 左侧菜单 + 内容区：把 tabs 与 panels 包进 tri3-body（新布局） */
    var triTabsEl = form.querySelector('.tri3-tabs');
    var triPanelsEl = form.querySelector('.tri3-panels');
    if (triTabsEl && triPanelsEl && triTabsEl.parentNode === form) {
      var triBodyEl = document.createElement('div');
      triBodyEl.className = 'tri3-body';
      form.insertBefore(triBodyEl, triTabsEl);
      triBodyEl.appendChild(triTabsEl);
      triBodyEl.appendChild(triPanelsEl);
    }

    var opts = document.querySelectorAll('.typecho-option');
    var sections = window.TRI3_PANEL_SECTIONS || null;

    function tri3MoveOption(target, name) {
      opts.forEach(function (o) {
        /* 复选框/多选控件 name 带 [] 后缀（如 homeMenuCats[]），需同时匹配两种形式 */
        var hit = o.querySelector('[name="' + name + '"]') || o.querySelector('[name="' + name + '[]"]');
        if (hit) { target.appendChild(o); }
      });
    }

    Object.keys(map).forEach(function (panel) {
      var panelEl = form.querySelector('.tri3-panel[data-panel="' + panel + '"]');
      if (!panelEl) { return; }
      var secs = (sections && sections[panel]) || null;
      if (secs) {
        /* Section 分组：每个分组 = 卡片（图标 + 标题 + 描述）+ 字段区 */
        secs.forEach(function (sec) {
          var head = document.createElement('div');
          head.className = 'tri3-section-head';
          head.innerHTML = '<i class="tri3-section-ico ' + (sec.icon || 'ri-settings-3-line') + '"></i>'
            + '<div class="tri3-section-txt"><b>' + (sec.title || '') + '</b>'
            + (sec.desc ? '<p>' + sec.desc + '</p>' : '') + '</div>';
          var body = document.createElement('div');
          body.className = 'tri3-section-body';
          var root = document.createElement('div');
          root.className = 'tri3-section';
          root.appendChild(head);
          root.appendChild(body);
          (sec.fields || []).forEach(function (name) { tri3MoveOption(body, name); });
          if (body.children.length) {
            var panelTail = panelEl.querySelector('.tri3-panel-tail');
            if (panelTail) { panelEl.insertBefore(root, panelTail); } else { panelEl.appendChild(root); }
          }
        });
      } else {
        map[panel].forEach(function (name) { tri3MoveOption(panelEl, name); });
      }
      if (panelEl.children.length === 0) {
        panelEl.innerHTML = '<p style="padding:14px;color:#999;margin:0;">本组暂无设置项</p>';
      }
    });


    /* 友链截图缓存：立即刷新按钮 */
    var triLinksPanel = form.querySelector('.tri3-panel[data-panel="links"]');
    if (triLinksPanel) {
      var triBtn = document.createElement('button');
      triBtn.type = 'button';
      triBtn.className = 'tri3-cache-btn';
      triBtn.innerHTML = '<i class="ri-refresh-line"></i>立即刷新截图缓存';
      triBtn.style.cssText = 'margin:14px;padding:9px 18px;border-radius:999px;border:none;background:#6750a4;color:#fff;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;';
      triBtn.addEventListener('click', function () {
        var triTheme = (new URLSearchParams(location.search)).get('theme') || 'TriM3';
        triBtn.disabled = true; triBtn.style.opacity = '.6';
        var triFd = new FormData(); triFd.append('do', 'clear');
        fetch(location.origin + '/usr/themes/' + triTheme + '/api/shot-api.php', { method: 'POST', body: triFd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (window.TriMsg) { window.TriMsg.success(j && j.ok ? ('截图缓存已清空（' + (j.cleared || 0) + ' 张），下次访问友链页自动重新抓取') : ((j && j.msg) || '刷新失败')); return; }
            alert(j && j.ok ? ('截图缓存已清空（' + (j.cleared || 0) + ' 张），下次访问友链页自动重新抓取') : ((j && j.msg) || '刷新失败'));
          })
          .catch(function () { if (window.TriMsg) { window.TriMsg.error('刷新失败，请检查网络'); } else { alert('刷新失败，请检查网络'); } })
          .finally(function () { triBtn.disabled = false; triBtn.style.opacity = '1'; });
      });
      triLinksPanel.appendChild(triBtn);
    }

    var tabs = form.querySelectorAll('.tri3-tab');
    function activateTab(t) {
      tabs.forEach(function (x) { x.classList.remove('is-active'); });
      form.querySelectorAll('.tri3-panel').forEach(function (p) { p.classList.remove('is-active'); });
      t.classList.add('is-active');
      var p = form.querySelector('.tri3-panel[data-panel="' + t.getAttribute('data-panel') + '"]');
      if (p) { p.classList.add('is-active'); }
    }
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        activateTab(t);
        if (history.replaceState) { history.replaceState(null, '', '#' + t.getAttribute('data-panel')); }
      });
    });
    /* 锚点（#album 等）自动激活对应 Tab：相册操作跳回后直达管理区 */
    var hashMap = { mail: 'notify', spam: 'notify', seo: 'tool', backup: 'tool', douban: 'media', bangumi: 'media', links: 'content', manage: 'content', nav: 'layout', album: 'content', imgcache: 'imgcache' };
    var hh = location.hash ? location.hash.replace('#', '') : '';
    if (hh) {
      var hReal = hashMap[hh] || hh;
      var ht = form.querySelector('.tri3-tab[data-panel="' + hReal + '"]');
      if (ht) { activateTab(ht); }
    }

    /* —— 无刷新保存：拦截表单提交，fetch 局部刷新，不整页跳转 —— */
    if (!form._tri3NavBound) {
      form._tri3NavBound = true;
      form.addEventListener('submit', function (e) {
        var subBtn = form.querySelector('.typecho-option-submit button, .typecho-option-submit input[type=submit]');
        var curTab = form.querySelector('.tri3-tab.is-active');
        var curPanel = curTab ? curTab.getAttribute('data-panel') : '';
        e.preventDefault();
        if (subBtn) { subBtn.disabled = true; }
        tri3SaveToast('正在保存设置…');
        var fd = new FormData(form);
        fetch(form.action || location.href, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow' })
          .then(function (r) { return r.text(); })
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nc = doc.querySelector('#tri3-config');
            if (!nc) { throw new Error('no-config'); }
            /* 替换整个内容区（form + config），option 在 form 尾部，必须整体替换 */
            var ncMain = doc.querySelector('.typecho-page-main') || doc.querySelector('form');
            var oldMain = document.querySelector('.typecho-page-main') || document.querySelector('form.tri3-form');
            if (ncMain && oldMain) {
              oldMain.replaceWith(ncMain);
            } else {
              var oldCfg = document.querySelector('#tri3-config');
              if (oldCfg) { oldCfg.replaceWith(nc); }
            }
            /* 重新执行新内容区中的内联脚本（imgcache 面板等） */
            (ncMain || nc).querySelectorAll('script').forEach(function (sc) {
              if (sc.src && sc.src.indexOf('admin.js') !== -1) { return; }
              var ns = document.createElement('script');
              if (sc.src) { ns.src = sc.src; } else { ns.textContent = sc.textContent; }
              document.body.appendChild(ns);
            });
            /* 重新初始化归组 / tab / 颜色 */
            if (window.Tri3AdminBoot) { window.Tri3AdminBoot(); }
            var nt = document.querySelector('#tri3-config .tri3-tab[data-panel="' + curPanel + '"]');
            if (nt) { nt.click(); }
            tri3SaveToast('设置已保存');
          })
          .catch(function () {
            if (subBtn) { subBtn.disabled = false; }
            tri3SaveToast('保存请求异常，正在刷新页面…');
            setTimeout(function () { location.reload(); }, 800);
          });
      });
    }

    /* 保存提示 Toast（顶部居中、最顶层） */
    function tri3SaveToast(msg) {
      var t = document.querySelector('.tri3-save-toast');
      if (!t) {
        t = document.createElement('div');
        t.className = 'tri3-save-toast';
        document.body.appendChild(t);
      }
      t.innerHTML = msg;
      requestAnimationFrame(function () { t.classList.add('show'); });
      clearTimeout(t._h);
      t._h = setTimeout(function () { t.classList.remove('show'); }, 2000);
    }

    ['primaryColor', 'secondaryColor', 'tertiaryColor', 'primaryContainerColor', 'onPrimaryContainerColor', 'onPrimaryColor', 'bgColor'].forEach(function (n) {
      var i = form.querySelector('input[name="' + n + '"]');
      if (i) { i.type = 'color'; }
    });

    /* —— 颜色项：预设色板点选（主交互，不依赖输入框）+ 原生取色器兜底 —— */
    var tri3Palettes = {
      primaryColor: ['#6750a4', '#1565c0', '#006a6a', '#b3261e', '#7d5260', '#386a20'],
      secondaryColor: ['#625b71', '#00639b', '#4a635c', '#8d6e63', '#7d5260', '#5b8def'],
      tertiaryColor: ['#7d5260', '#00696d', '#8d5b1e', '#c2185b', '#7a4b8b', '#3f6833'],
      primaryContainerColor: ['#eaddff', '#d6e3ff', '#9ef0f0', '#ffdad6', '#e8def8', '#dce8ff'],
      onPrimaryContainerColor: ['#21005d', '#001c3a', '#002020', '#410002', '#381e72', '#1a1c1e'],
      onPrimaryColor: ['#ffffff', '#ffffff', '#ffffff', '#ffffff', '#ffffff', '#000000'],
      bgColor: ['#f8f5fc', '#fef7ff', '#e3f2fd', '#f5fbf7', '#fff8f0', '#fafafa']
    };
    Object.keys(tri3Palettes).forEach(function (n) {
      var i = form.querySelector('input[name="' + n + '"]');
      if (!i) { return; }
      /* 把 input 和色板包裹在一个 flex 容器中，让它们在 grid 第二列同行显示 */
      var row = document.createElement('div');
      row.className = 'tri3-color-row';
      row.style.cssText = 'display:flex;align-items:center;flex-wrap:wrap;gap:0;';
      var box = document.createElement('div');
      box.className = 'tri3-color-presets';
      box.style.cssText = 'display:inline-flex;gap:7px;flex-wrap:wrap;margin:0 0 0 12px;align-items:center;';
      tri3Palettes[n].forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'tri3-color-swatch';
        b.style.cssText = 'width:24px;height:24px;border-radius:7px;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.18);cursor:pointer;padding:0;background:' + c + ';transition:transform .12s ease,box-shadow .12s ease;';
        b.title = c;
        b.addEventListener('mouseenter', function () { b.style.transform = 'scale(1.18)'; b.style.boxShadow = '0 0 0 1px rgba(0,0,0,.25),0 2px 8px rgba(0,0,0,.2)'; });
        b.addEventListener('mouseleave', function () { b.style.transform = 'scale(1)'; b.style.boxShadow = '0 0 0 1px rgba(0,0,0,.18)'; });
        b.addEventListener('click', function () {
          i.value = c;
          i.dispatchEvent(new Event('change'));
          i.dispatchEvent(new Event('input'));
        });
        box.appendChild(b);
      });
      var wrap = i.parentNode;
      wrap.insertBefore(row, i); /* 容器插到 input 前面 */
      row.appendChild(i);          /* input 移入容器 */
      row.appendChild(box);        /* 色板移入容器 */
    });

    /* —— MD3 配色推荐：主色变更后自动推导 Secondary / Tertiary / Container 等 —— */
    function tri3HexToHsl(hex) {
      hex = hex.replace('#', '');
      var r = parseInt(hex.substr(0, 2), 16) / 255, g = parseInt(hex.substr(2, 2), 16) / 255, b = parseInt(hex.substr(4, 2), 16) / 255;
      var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, d = max - min, h = 0, s = 0;
      if (d !== 0) {
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
        if (max === r) h = ((g - b) / d + (g < b ? 6 : 0));
        else if (max === g) h = ((b - r) / d + 2);
        else h = ((r - g) / d + 4);
        h *= 60;
      }
      return [h, s, l];
    }
    function tri3HslToHex(h, s, l) {
      h = ((h % 360) + 360) % 360;
      var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = l - c / 2, r = 0, g = 0, b = 0;
      if (h < 60) { r = c; g = x; }
      else if (h < 120) { r = x; g = c; }
      else if (h < 180) { g = c; b = x; }
      else if (h < 240) { r = 0; g = x; b = c; }
      else if (h < 300) { r = x; b = c; }
      else { r = c; b = x; }
      function t2(v) { return Math.round((v + m) * 255).toString(16).padStart(2, '0'); }
      return '#' + t2(r) + t2(g) + t2(b);
    }
    function tri3Md3FromSeed(seed) {
      var hsl = tri3HexToHsl(seed), h = hsl[0], s = hsl[1];
      function key(dh, sat) { return tri3HslToHex(h + dh, Math.min(Math.max(sat, 0.3), 0.8), 0.42); }
      var cSat = Math.min(Math.max(s * 0.6, 0.3), 0.8);
      return {
        secondaryColor: key(15, s * 0.7),
        tertiaryColor: key(-15, s * 0.5),
        primaryContainerColor: tri3HslToHex(h, cSat, 0.88),
        onPrimaryContainerColor: tri3HslToHex(h, cSat, 0.12),
        onPrimaryColor: '#ffffff',
        bgColor: tri3HslToHex(h, cSat * 0.5, 0.96)
      };
    }
    function tri3SetColor(name, val) {
      var i = form.querySelector('input[name="' + name + '"]');
      if (i) { i.value = val; }
    }
    var tri3Primary = form.querySelector('input[name="primaryColor"]');
    if (tri3Primary) {
      tri3Primary.addEventListener('change', function () {
        var rec = tri3Md3FromSeed(tri3Primary.value);
        Object.keys(rec).forEach(function (k) { if (k !== 'bgColor') { tri3SetColor(k, rec[k]); } });
      });
    }
    /* 背景色变更：主色/整套配色按 MD3 自动推荐（背景为主驱动）；但用户选的背景色本身保留，不被推荐值覆盖 */
    var tri3Bg = form.querySelector('input[name="bgColor"]');
    if (tri3Bg) {
      tri3Bg.addEventListener('change', function () {
        var bh = tri3HexToHsl(tri3Bg.value), h = bh[0], s = bh[1];
        var seed = tri3HslToHex(h, Math.min(Math.max(s + 0.15, 0.35), 0.65), 0.42);
        tri3SetColor('primaryColor', seed);
        var rec = tri3Md3FromSeed(seed);
        Object.keys(rec).forEach(function (k) { if (k !== 'bgColor') { tri3SetColor(k, rec[k]); } });
      });
    }
    var tri3Appearance = form.querySelector('.tri3-panel[data-panel="appearance"]');
    if (tri3Appearance) {
      var tri3RecBar = document.createElement('div');
      tri3RecBar.className = 'tri3-rec-bar';
      tri3RecBar.style.cssText = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px;padding:10px 14px;border-radius:12px;background:#f3effc;border:1px solid #e2d6f5;font-size:12px;color:#5b4a7a;line-height:1.5;';
      tri3RecBar.innerHTML = '<button type="button" id="tri3-rec-btn" style="padding:7px 14px;border:none;border-radius:999px;background:#6750a4;color:#fff;font-size:12px;font-weight:600;cursor:pointer;">🎨 按 MD3 重新推荐配色</button><span>修改主色会自动按 Material Design 3 推荐 Secondary / Tertiary / Container 色，均可手动微调后保存。</span>';
      tri3Appearance.insertBefore(tri3RecBar, tri3Appearance.firstChild);
      var tri3RecBtn = document.getElementById('tri3-rec-btn');
      if (tri3RecBtn) {
        tri3RecBtn.addEventListener('click', function () {
          var rec = tri3Md3FromSeed(tri3Primary.value);
          Object.keys(rec).forEach(function (k) { if (k !== 'bgColor') { tri3SetColor(k, rec[k]); } });
        });
      }
    }
    /* 保存按钮固定底栏：任何 Tab 下都可见（用户反馈"设置无法保存"根因：Typecho 保存按钮在页面最底部，切到深层 Tab 后不可见） */
    var triSubmit = form.querySelector('.typecho-option-submit');
    if (triSubmit) {
      triSubmit.classList.add('tri3-submit-bar');
      /* —— 无刷新保存：拦截提交按钮，fetch JSON 保存，原地提示不跳转 —— */
      var triBtn = triSubmit.querySelector('button[type="submit"]') || triSubmit.querySelector('input[type="submit"]');
      if (triBtn) {
        var triBusy = false;
        triBtn.addEventListener('click', function (e) {
          e.preventDefault();
          if (triBusy) { return; }
          /* 收集白名单字段（radio 取选中、checkbox 数组取勾选、其余取 value） */
          var cfg = {};
          Object.keys(map).forEach(function (p) {
            (map[p] || []).forEach(function (name) {
              var els = form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
              if (!els.length) { return; }
              var first = els[0];
              if (first.type === 'radio') {
                var c = form.querySelector('[name="' + name + '"]:checked, [name="' + name + '[]"]:checked');
                if (c) { cfg[name] = c.value; }
              } else if (first.type === 'checkbox' || first.name.indexOf('[]') !== -1) {
                var arr = [];
                els.forEach(function (x) { if (x.checked) { arr.push(x.value); } });
                cfg[name] = arr;
              } else {
                cfg[name] = first.value;
              }
            });
          });
          var fd = new FormData();
          fd.append('tri_do', 'save_cfg');
          fd.append('tri_cfg', JSON.stringify(cfg));
          triBusy = true;
          var oldHtml = triBtn.innerHTML;
          triBtn.disabled = true;
          if (triBtn.tagName === 'BUTTON') { triBtn.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> 保存中…'; }
          fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (window.TriMsg) {
                if (j && j.ok) { window.TriMsg.success((j.msg || '设置已保存') + (j.saved ? '（' + j.saved + ' 项）' : '')); }
                else { window.TriMsg.error((j && j.msg) || '保存失败，请重试'); }
              } else {
                alert((j && j.ok) ? '设置已保存' : ((j && j.msg) || '保存失败，请重试'));
              }
            })
            .catch(function () {
              if (window.TriMsg) { window.TriMsg.error('保存失败：网络错误或登录已过期，请刷新页面重试'); }
              else { alert('保存失败：网络错误或登录已过期'); }
            })
            .finally(function () {
              triBusy = false;
              triBtn.disabled = false;
              if (triBtn.tagName === 'BUTTON') { triBtn.innerHTML = oldHtml; }
            });
        });
      }
    }
  };
  ready(window.Tri3AdminBoot);
})();

  /* 通用拖拽排序（handle 触发；鼠标事件实现，不依赖 HTML5 DnD，真实鼠标 100% 可用）
   * 仅重排 data-sort-row 元素；容器仅绑定一次，重复调用只更新回调 */
  function tri3Sortable(container, onReorder) {
    if (container.getAttribute('data-sortable-bound')) {
      container._triReorder = onReorder;
      return;
    }
    container.setAttribute('data-sortable-bound', '1');
    container._triReorder = onReorder;
    var dragRow = null;
    container.addEventListener('mousedown', function (e) {
      var h = e.target.closest ? e.target.closest('[data-drag-handle]') : null;
      if (!h) { return; }
      if (e.button !== 0) { return; }
      /* 手柄内不应有输入控件；防误拖 */
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') { return; }
      var row = h.parentElement;
      if (!row || row.getAttribute('data-sort-row') !== '1' || !container.contains(row)) { return; }
      e.preventDefault();
      dragRow = row;
      row.style.opacity = '0.45';
      row.style.borderColor = '#6750a4';
      var ghost = document.createElement('div');
      ghost.style.cssText = 'position:fixed;pointer-events:none;z-index:99999;opacity:.9;background:#fff;border:1.5px dashed #6750a4;border-radius:12px;padding:8px 14px;font-size:13px;color:#333;box-shadow:0 8px 24px rgba(0,0,0,.16);white-space:nowrap;';
      var nm = row.querySelector('input[type=text]');
      ghost.textContent = nm && nm.value ? nm.value : '拖动排序…';
      document.body.appendChild(ghost);
      function onMove(ev) {
        if (!dragRow) { return; }
        ev.preventDefault();
        ghost.style.left = (ev.clientX + 14) + 'px';
        ghost.style.top = (ev.clientY + 12) + 'px';
        /* 几何命中：按 y 找第一个中心在鼠标下方的行插到其前，否则插到末尾；
         * 不依赖 elementFromPoint，行间 gap 也能准确判断 */
        var rows = container.querySelectorAll('[data-sort-row]');
        var target = null, before = true;
        for (var i = 0; i < rows.length; i++) {
          if (rows[i] === dragRow) { continue; }
          var rc = rows[i].getBoundingClientRect();
          if (ev.clientY < rc.top + rc.height / 2) { target = rows[i]; before = true; break; }
        }
        if (!target) {
          var last = rows[rows.length - 1];
          if (last && last !== dragRow) { target = last; before = false; }
        }
        if (target && container.contains(target)) {
          container.insertBefore(dragRow, before ? target : target.nextSibling);
        }
      }
      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        if (ghost.parentNode) { ghost.parentNode.removeChild(ghost); }
        if (dragRow) { dragRow.style.opacity = ''; dragRow.style.borderColor = ''; }
        var cb = container._triReorder;
        dragRow = null;
        if (cb) { cb(); }
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  }

  /* 首页菜单显示的分类（复选框）：全部选项可拖拽排序，提交顺序 = DOM 顺序，前台按此顺序展开
   * Typecho Checkbox 每项 DOM：<span><input name="xx[]"> <label for=...>名称</label></span>
   * 专用纵向卡片式排列，拖拽手柄触发，CSS 类 tri3-cat-sortable 控制样式 */
  function tri3CatsSortable() {
    var cbs = Array.prototype.slice.call(document.querySelectorAll('input[name="homeMenuCats[]"]'));
    if (!cbs.length) { return; }
    var items = cbs.map(function (cb) {
      return cb.parentElement;
    }).filter(function (x) { return x && x.getAttribute('data-sort-row') !== '1'; });
    if (!items.length) { return; }
    var wrap = items[0].parentElement;
    if (!wrap || wrap.getAttribute('data-cats-sorted')) { return; }
    wrap.setAttribute('data-cats-sorted', '1');
    /* 给 li 添加专用 class，覆盖通用 radio/checkbox 横向排列样式 */
    var li = wrap.closest ? wrap.closest('li') : wrap.parentElement;
    if (li && li.tagName === 'LI') {
      li.classList.add('tri3-cat-sortable');
    }
    items.forEach(function (sp) {
      sp.setAttribute('data-sort-row', '1');
      /* 移除内联样式，由 CSS .tri3-cat-sortable > span[data-sort-row] 控制 */
      sp.style.cssText = '';
      var h = document.createElement('span');
      h.innerHTML = '<i class="ri-draggable"></i>';
      h.setAttribute('data-drag-handle', '1');
      h.title = '拖动排序';
      /* 手柄样式由 CSS 控制，这里只设必要属性 */
      h.style.cssText = '';
      sp.insertBefore(h, sp.firstChild);
    });
    tri3Sortable(wrap, function () {});
  }

  /* === 导航可视化编辑器（navItems / mobileMenu 共用） === */
  (function () {
    function tri3NavInit(sel, tipText, emptyText) {
    var ta = document.querySelector(sel) || document.querySelector('textarea[name="' + (sel.match(/name="([^"]+)"/) || [])[1] + '"]');
    if (!ta) { return; }
    var ICONS = window.TRI3_ICONS || []; /* 图标库已独立至 js/icons.js */
    function parse() {
      var v = ta.value.trim();
      if (!v) { return []; }
      try {
        var a = JSON.parse(v);
        return Array.isArray(a) ? a : [];
      } catch (e) {
        var out = [];
        v.split(/\\r?\\n/).forEach(function (line) {
          line = line.trim(); if (!line) { return; }
          var pp = line.split('|');
          out.push({ name: (pp[0] || '').trim(), url: (pp[1] || '#').trim(), icon: (pp[2] || '').trim() });
        });
        return out;
      }
    }
    function dump(items) { ta.value = JSON.stringify(items); }

    var wrap = document.createElement('div');
    wrap.className = 'tri3-nav-editor';
    var tip = document.createElement('p');
    tip.textContent = tipText || '可视化添加导航项：先点图标选择，再填名称和链接（图标与名称自动匹配，不用手动找）';
    tip.style.cssText = 'margin:0 0 8px;font-size:12px;color:#666;';
    wrap.appendChild(tip);
    ta.parentNode.insertBefore(wrap, ta);
    ta.style.display = 'none';

    var list = document.createElement('div');
    list.style.cssText = 'display:flex;flex-direction:column;gap:8px;';
    wrap.appendChild(list);

    function renderItem(item, items) {
      var row = document.createElement('div');
      row.style.cssText = 'position:relative;display:flex;align-items:center;gap:8px;background:#f6f6fb;border:1px solid #e6e6f0;border-radius:12px;padding:6px 8px;';
      row.setAttribute('data-sort-row', '1');
      var handle = document.createElement('span');
      handle.innerHTML = '<i class="ri-draggable"></i>';
      handle.setAttribute('data-drag-handle', '1');
      handle.title = '拖动排序';
      handle.style.cssText = 'width:22px;flex:0 0 22px;color:#b0b0c8;font-size:15px;cursor:grab;display:inline-flex;align-items:center;justify-content:center;user-select:none;-webkit-user-select:none;';
      row.appendChild(handle);
      var iconBtn = document.createElement('button');
      iconBtn.type = 'button'; iconBtn.title = '点此选择图标';
      iconBtn.style.cssText = 'width:36px;height:36px;flex:0 0 36px;border-radius:10px;border:1px solid #ddd;background:#fff;cursor:pointer;font-size:18px;color:#5b5b76;display:inline-flex;align-items:center;justify-content:center;';
      iconBtn.innerHTML = '<i class="' + (item.icon || 'ri-link') + '"></i>';
      var nameIn = document.createElement('input');
      nameIn.type = 'text'; nameIn.placeholder = '名称，如：首页';
      nameIn.value = item.name || '';
      nameIn.style.cssText = 'flex:1;min-width:0;padding:7px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px;';
      var urlIn = document.createElement('input');
      urlIn.type = 'text'; urlIn.placeholder = '链接，如：/about.html';
      urlIn.value = item.url || '';
      urlIn.style.cssText = 'flex:1.4;min-width:0;padding:7px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px;';
      var del = document.createElement('button');
      del.type = 'button'; del.innerHTML = '&times;'; del.title = '删除';
      del.style.cssText = 'width:28px;height:28px;flex:0 0 28px;border:none;background:transparent;color:#b3261e;font-size:20px;cursor:pointer;border-radius:8px;line-height:1;';
      var picker = document.createElement('div');
      picker.className = 'tri3-nav-picker';
      picker.style.cssText = 'position:absolute;top:100%;left:0;z-index:99;display:none;background:#fff;border:1px solid #e0e0ea;border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.14);padding:10px;margin-top:4px;width:286px;';
      var searchIn = document.createElement('input');
      searchIn.type = 'text'; searchIn.placeholder = '搜索图标（中/英文或类名，如 share）…';
      searchIn.style.cssText = 'width:100%;box-sizing:border-box;padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:12px;margin-bottom:8px;';
      picker.appendChild(searchIn);
      var grid = document.createElement('div');
      grid.style.cssText = 'display:grid;grid-template-columns:repeat(6,38px);gap:6px;max-height:264px;overflow-y:auto;';
      picker.appendChild(grid);
      function renderGrid(kw) {
        grid.innerHTML = '';
        kw = (kw || '').toLowerCase();
        ICONS.filter(function (ic) { return !kw || ic[0].toLowerCase().indexOf(kw) >= 0 || ic[1].toLowerCase().indexOf(kw) >= 0; }).forEach(function (ic) {
          var b = document.createElement('button');
          b.type = 'button'; b.title = ic[0] + '  ' + ic[1];
          b.style.cssText = 'width:38px;height:38px;border-radius:10px;border:1px solid #eee;background:#fafaff;cursor:pointer;font-size:17px;color:#5b5b76;display:inline-flex;align-items:center;justify-content:center;';
          b.innerHTML = '<i class="' + ic[0] + '"></i>';
          b.addEventListener('click', function () {
            item.icon = ic[0];
            iconBtn.innerHTML = '<i class="' + ic[0] + '"></i>';
            picker.style.display = 'none';
            dump(items);
          });
          grid.appendChild(b);
        });
        if (!grid.children.length) {
          var nf = document.createElement('p');
          nf.textContent = '未找到匹配图标';
          nf.style.cssText = 'margin:0;font-size:12px;color:#999;grid-column:1/-1;text-align:center;';
          grid.appendChild(nf);
        }
      }
      searchIn.addEventListener('input', function () { renderGrid(searchIn.value); });
      row.appendChild(iconBtn);
      row.appendChild(nameIn);
      row.appendChild(urlIn);
      row.appendChild(del);
      row.appendChild(picker);
      iconBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = picker.style.display === 'grid';
        list.querySelectorAll('.tri3-nav-picker').forEach(function (pp) { pp.style.display = 'none'; });
        if (!open) { searchIn.value = ''; renderGrid(''); }
        picker.style.display = open ? 'none' : 'grid';
      });
      function sync() { item.name = nameIn.value; item.url = urlIn.value; dump(items); }
      nameIn.addEventListener('input', sync);
      urlIn.addEventListener('input', sync);
      del.addEventListener('click', function () {
        var i = items.indexOf(item);
        if (i >= 0) { items.splice(i, 1); dump(items); render(items); }
      });
      return row;
    }
    function render(items) {
      list.innerHTML = '';
      items.forEach(function (item, idx) {
        var row = renderItem(item, items);
        row.setAttribute('data-idx', idx);
        list.appendChild(row);
      });
      if (!items.length) {
        var hint = document.createElement('p');
        hint.textContent = emptyText || '尚未添加导航项，将显示默认：首页 + RSS 订阅';
        hint.style.cssText = 'margin:0;font-size:12px;color:#999;';
        list.appendChild(hint);
      }
      tri3Sortable(list, function () {
        var rows = list.querySelectorAll('[data-sort-row]');
        var ni = [];
        rows.forEach(function (r) {
          var i = parseInt(r.getAttribute('data-idx') || '-1', 10);
          if (i >= 0 && i < items.length) { ni.push(items[i]); }
        });
        if (ni.length === items.length) {
          items.length = 0;
          ni.forEach(function (it) { items.push(it); });
          dump(items);
        }
      });
    }
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.innerHTML = '<i class="ri-add-line"></i> 添加导航项';
    addBtn.style.cssText = 'margin-top:8px;padding:8px 14px;border:1px dashed #b9b9d4;border-radius:12px;background:transparent;color:#6750a4;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;';
    addBtn.addEventListener('click', function () {
      var items = parse();
      items.push({ name: '', url: '#', icon: '' });
      dump(items);
      render(items);
    });
    wrap.appendChild(addBtn);

    var initial = parse();
    render(initial);
    }

    /* === 首页菜单可视化编辑器（homeMenu） === */
  function tri3HomeMenuInit() {
    var ta = document.querySelector('#tri3-config textarea[name="homeMenu"]') || document.querySelector('textarea[name="homeMenu"]');
    if (!ta) { return; }
    /* 固定类型：文章分类(展开)、首页、说说、相册、友链、关于 均为固定选项，不依赖单页/分类是否存在 */
    var FIXED_TYPES = {
      home:     { label: '首页',   url: 'home' },
      moment:   { label: '说说',   url: 'page:moment' },
      music:    { label: '音乐',   url: 'page:music' },
      album:    { label: '相册',   url: 'page:album' },
      links:    { label: '友链',   url: 'page:links' },
      about:    { label: '关于',   url: 'page:start-page' },
      articles: { label: '文章',   url: 'page:articles' },
      bangumi:  { label: 'B站番剧', url: 'page:bangumi' },
      douban:   { label: '豆瓣',   url: 'page:douban' },
      archive:  { label: '归档',   url: 'page:archive' }
    };
    function parse() {
      var v = ta.value.trim();
      if (!v) { return []; }
      try {
        var a = JSON.parse(v);
        return Array.isArray(a) ? a : [];
      } catch (e) {
        var out = [];
        v.split(/\r?\n/).forEach(function (line) {
          line = line.trim(); if (!line) { return; }
          var pp = line.split('|');
          var t = (pp[2] || '').trim().toLowerCase();
          if (!(t === 'posts' || t === 'category' || FIXED_TYPES[t])) { t = 'link'; }
          out.push({ name: (pp[0] || '').trim(), url: (pp[1] || '').trim(), type: t });
        });
        return out;
      }
    }
    function dump(items) { ta.value = JSON.stringify(items); }

    var wrap = document.createElement('div');
    wrap.className = 'tri3-nav-editor';
    var tip = document.createElement('p');
    tip.textContent = '可视化添加首页菜单项：填名称，选类型。首页、说说、相册、友链、关于、文章、B站番剧、豆瓣、归档为固定单页选项，选中自动填好链接（文章、相册等需站点已建对应页面）；文章分类展开分类，具体分类需勾选后选择。';
    tip.style.cssText = 'margin:0 0 8px;font-size:12px;color:#666;';
    wrap.appendChild(tip);
    ta.parentNode.insertBefore(wrap, ta);
    ta.style.display = 'none';

    var list = document.createElement('div');
    /* 首页菜单为横向导航：编辑器条目横向排列（自动换行） */
    list.style.cssText = 'display:flex;flex-wrap:wrap;gap:10px;';
    wrap.appendChild(list);

    function renderItem(item, items) {
      var row = document.createElement('div');
      row.style.cssText = 'position:relative;display:flex;align-items:center;gap:8px;background:#f6f6fb;border:1px solid #e6e6f0;border-radius:12px;padding:6px 8px;box-sizing:border-box;width:100%;max-width:100%;';
      row.setAttribute('data-sort-row', '1');
      var handle = document.createElement('span');
      handle.innerHTML = '<i class="ri-draggable"></i>';
      handle.setAttribute('data-drag-handle', '1');
      handle.title = '拖动排序';
      handle.style.cssText = 'width:22px;flex:0 0 22px;color:#b0b0c8;font-size:15px;cursor:grab;display:inline-flex;align-items:center;justify-content:center;user-select:none;-webkit-user-select:none;';
      row.appendChild(handle);
      var nameIn = document.createElement('input');
      nameIn.type = 'text'; nameIn.placeholder = '名称，如：首页';
      nameIn.value = item.name || '';
      nameIn.style.cssText = 'flex:0 0 100px;min-width:0;box-sizing:border-box;padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px;';
      var typeSel = document.createElement('select');
      typeSel.style.cssText = 'flex:0 0 116px;width:116px;padding:6px 4px;border:1px solid #ddd;border-radius:8px;font-size:12px;background:#fff;color:#49454f;';
      var o1 = document.createElement('option'); o1.value = 'link'; o1.textContent = '普通链接'; typeSel.appendChild(o1);
      var o2 = document.createElement('option'); o2.value = 'posts'; o2.textContent = '文章分类'; typeSel.appendChild(o2);
      var o3 = document.createElement('option'); o3.value = 'category'; o3.textContent = '具体分类'; typeSel.appendChild(o3);
      Object.keys(FIXED_TYPES).forEach(function (k) {
        var o = document.createElement('option'); o.value = k; o.textContent = FIXED_TYPES[k].label; typeSel.appendChild(o);
      });
      var curType = 'link';
      if (item.type === 'posts' || item.type === 'category' || FIXED_TYPES[item.type]) { curType = item.type; }
      typeSel.value = curType;
      /* 分类下拉（数据来自「首页菜单显示的分类」复选框） */
      var catSel = document.createElement('select');
      catSel.style.cssText = 'flex:1 1 auto;min-width:120px;box-sizing:border-box;padding:6px 6px;border:1px solid #ddd;border-radius:8px;font-size:12px;background:#fff;color:#49454f;';
      var catOpts = [];
      document.querySelectorAll('input[name="homeMenuCats[]"]').forEach(function (cb) {
        var lbl = cb.closest('label');
        catOpts.push({ slug: cb.value, label: lbl ? lbl.textContent.trim() : cb.value });
      });
      if (!catOpts.length) {
        var co = document.createElement('option'); co.value = ''; co.textContent = '（暂无分类可选，请先在下方「首页菜单显示的分类」中勾选）'; catSel.appendChild(co);
      } else {
        catOpts.forEach(function (c) { var o = document.createElement('option'); o.value = c.slug; o.textContent = c.label; catSel.appendChild(o); });
      }
      var curCat = '';
      if (item.type === 'category') {
        var m = /^category:(.+)$/.exec(item.url || '');
        curCat = m ? m[1] : '';
        if (curCat) { catSel.value = curCat; }
      }
      var urlIn = document.createElement('input');
      urlIn.type = 'text'; urlIn.placeholder = '链接：网址 / page:slug / home（留空=首页）';
      urlIn.value = item.url || '';
      urlIn.style.cssText = 'flex:1 1 auto;min-width:120px;box-sizing:border-box;padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:13px;';
      var del = document.createElement('button');
      del.type = 'button'; del.innerHTML = '&times;'; del.title = '删除';
      del.style.cssText = 'width:30px;height:30px;flex:0 0 30px;border:none;background:transparent;color:#b3261e;font-size:20px;cursor:pointer;border-radius:8px;line-height:1;';
      function urlMode() {
        var t = typeSel.value;
        if (t === 'posts') {
          urlIn.style.display = 'none'; catSel.style.display = 'none';
          urlIn.value = ''; item.url = '';
        } else if (t === 'category') {
          urlIn.style.display = 'none'; catSel.style.display = '';
          item.url = 'category:' + catSel.value;
        } else if (FIXED_TYPES[t]) {
          urlIn.style.display = 'none'; catSel.style.display = 'none';
          urlIn.value = FIXED_TYPES[t].url; item.url = FIXED_TYPES[t].url;
        } else {
          urlIn.style.display = ''; catSel.style.display = 'none';
        }
      }
      row.appendChild(nameIn);
      row.appendChild(typeSel);
      row.appendChild(urlIn);
      row.appendChild(catSel);
      row.appendChild(del);
      urlMode();
      function sync() {
        item.name = nameIn.value;
        if (typeSel.value === 'category') { item.url = 'category:' + catSel.value; }
        else if (FIXED_TYPES[typeSel.value]) { item.url = FIXED_TYPES[typeSel.value].url; }
        else { item.url = urlIn.value; }
        item.type = typeSel.value;
        dump(items);
      }
      nameIn.addEventListener('input', sync);
      urlIn.addEventListener('input', sync);
      catSel.addEventListener('change', function () { item.url = 'category:' + catSel.value; dump(items); });
      typeSel.addEventListener('change', function () { urlMode(); sync(); });
      del.addEventListener('click', function () {
        var i = items.indexOf(item);
        if (i >= 0) { items.splice(i, 1); dump(items); render(items); }
      });
      return row;
    }
    function render(items) {
      list.innerHTML = '';
      items.forEach(function (item, idx) {
        var row = renderItem(item, items);
        row.setAttribute('data-idx', idx);
        list.appendChild(row);
      });
      if (!items.length) {
        var hint = document.createElement('p');
        hint.textContent = '尚未添加菜单项，将显示默认：首页 说说 关于 文章（自动检测）';
        hint.style.cssText = 'margin:0;font-size:12px;color:#999;';
        list.appendChild(hint);
      }
      tri3Sortable(list, function () {
        var rows = list.querySelectorAll('[data-sort-row]');
        var ni = [];
        rows.forEach(function (r) {
          var i = parseInt(r.getAttribute('data-idx') || '-1', 10);
          if (i >= 0 && i < items.length) { ni.push(items[i]); }
        });
        if (ni.length === items.length) {
          items.length = 0;
          ni.forEach(function (it) { items.push(it); });
          dump(items);
        }
      });
    }
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.innerHTML = '<i class="ri-add-line"></i> 添加菜单项';
    addBtn.style.cssText = 'margin-top:8px;padding:8px 14px;border:1px dashed #b9b9d4;border-radius:12px;background:transparent;color:#6750a4;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;';
    addBtn.addEventListener('click', function () {
      var items = parse();
      items.push({ name: '', url: '', type: 'link' });
      dump(items);
      render(items);
    });
    wrap.appendChild(addBtn);

    var initial = parse();
    render(initial);
  }

  
    if (document.readyState !== 'loading') { tri3NavInit('textarea[name="navItems"]', '可视化添加左侧导航项：先点图标选择，再填名称和链接（图标与名称自动匹配，不用手动找）', '尚未添加导航项，将显示默认：首页 + RSS 订阅'); tri3NavInit('textarea[name="mobileMenu"]', '可视化添加手机端底部菜单：先点图标选择，再填名称和链接（图标与名称自动匹配）。留空则前端不显示底部菜单', '尚未添加菜单项，将显示默认：首页 文章 归档 留言 关于'); tri3HomeMenuInit(); tri3CatsSortable(); } else { document.addEventListener('DOMContentLoaded', function () { tri3NavInit('textarea[name="navItems"]', '可视化添加左侧导航项：先点图标选择，再填名称和链接（图标与名称自动匹配，不用手动找）', '尚未添加导航项，将显示默认：首页 + RSS 订阅'); tri3NavInit('textarea[name="mobileMenu"]', '可视化添加手机端底部菜单：先点图标选择，再填名称和链接（图标与名称自动匹配）。留空则前端不显示底部菜单', '尚未添加菜单项，将显示默认：首页 文章 归档 留言 关于'); tri3HomeMenuInit(); tri3CatsSortable(); }); }
  })();

/* —— 友链可视化编辑器：列表 + 增删改 + 拖拽排序 + 分组（数据写回隐藏 textarea[name=friends]） —— */
(function () {
  function frReady(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }
  frReady(function () {
    var form = document.querySelector('form.tri3-form, form[action*="themes-edit"], form');
    if (!form) { return; }
    var panel = form.querySelector('.tri3-panel[data-panel="content"], .tri3-panel[data-panel="links"]');
    if (!panel) { return; }
    var ta = panel.querySelector('textarea[name="friends"]');
    if (!ta) { return; }
    var opt = ta.closest('.typecho-option');
    if (!opt) { return; }
    ta.style.display = 'none';
    var groupsTa = panel.querySelector('textarea[name="friendGroups"]');

    function getGroups() {
      var gs = [];
      if (groupsTa) {
        (groupsTa.value || '').split('\n').forEach(function (g) { g = (g || '').trim(); if (g) { gs.push(g); } });
      }
      return gs;
    }
    function parse() {
      var v = (ta.value || '').trim();
      if (!v) { return []; }
      if (v.charAt(0) === '[') {
        try { var arr = JSON.parse(v); return Array.isArray(arr) ? arr : []; } catch (e) { return []; }
      }
      var out = [];
      v.split('\n').forEach(function (line) {
        line = (line || '').trim(); if (!line) { return; }
        var p = line.split(',').map(function (x) { return (x || '').trim(); });
        if (p.length < 3 || !p[1] || !p[2]) { return; }
        out.push({ img: p[0] || '', url: p[1], name: p[2], desc: p[3] || '', group: p[4] || '默认分组' });
      });
      return out;
    }
    function sync() { ta.value = JSON.stringify(triLinks); }

    var triLinks = parse();
    var box = document.createElement('div');
    box.className = 'tri3-friends-editor';
    opt.appendChild(box);

    function esc(s) {
      return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function letter(name) {
      return esc((name || '?').trim().charAt(0).toUpperCase() || '?');
    }
    function openForm(idx) {
      var item = idx >= 0 ? triLinks[idx] : { img: '', url: '', name: '', desc: '', group: '' };
      var groups = getGroups();
      if (groups.indexOf('默认分组') < 0) { groups.unshift('默认分组'); }
      var dl = groups.map(function (g) { return '<option value="' + esc(g) + '">' + esc(g) + '</option>'; }).join('');
      var modal = document.createElement('div');
      modal.className = 'tri3-friend-modal';
      modal.innerHTML =
        '<div class="tri3-friend-modal-card">' +
          '<div class="tri3-friend-modal-head">' + (idx >= 0 ? '编辑友链' : '添加友链') + '</div>' +
          '<label>站点名称 *<input type="text" class="f-name" value="' + esc(item.name) + '" placeholder="如：示例站点"></label>' +
          '<label>站点 URL *<input type="text" class="f-url" value="' + esc(item.url) + '" placeholder="https://example.com"></label>' +
          '<label>头像 URL<input type="text" class="f-img" value="' + esc(item.img) + '" placeholder="留空显示站点首字母徽标"></label>' +
          '<label>描述<input type="text" class="f-desc" value="' + esc(item.desc) + '" placeholder="一句话描述"></label>' +
          '<label>分组<input type="text" class="f-group" value="' + esc(item.group || '默认分组') + '" list="tri3-friend-groups" placeholder="选择或输入新分组"></label>' +
          '<datalist id="tri3-friend-groups">' + dl + '</datalist>' +
          '<div class="tri3-friend-modal-ops">' +
            '<button type="button" class="f-cancel">取消</button>' +
            '<button type="button" class="f-ok">保存</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(modal);
      function close() { if (modal.parentNode) { modal.parentNode.removeChild(modal); } }
      modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
      modal.querySelector('.f-cancel').addEventListener('click', close);
      modal.querySelector('.f-ok').addEventListener('click', function () {
        var name = modal.querySelector('.f-name').value.trim();
        var url = modal.querySelector('.f-url').value.trim();
        if (!name || !url) { modal.querySelector('.f-name').focus(); return; }
        var obj = {
          name: name, url: url,
          img: modal.querySelector('.f-img').value.trim(),
          desc: modal.querySelector('.f-desc').value.trim(),
          group: modal.querySelector('.f-group').value.trim() || '默认分组'
        };
        if (idx >= 0) { triLinks[idx] = obj; } else { triLinks.push(obj); }
        sync(); render(); close();
      });
      modal.querySelector('.f-name').focus();
    }
    function removeAt(idx) {
      if (!confirm('删除友链「' + triLinks[idx].name + '」？')) { return; }
      triLinks.splice(idx, 1); sync(); render();
    }
    function render() {
      var groups = getGroups();
      var html = '';
      triLinks.forEach(function (it, i) {
        var g = it.group || '默认分组';
        html +=
          '<div class="tri3-friend-card" draggable="true" data-i="' + i + '">' +
            '<span class="f-grip"><i class="ri-draggable"></i></span>' +
            (it.img
              ? '<img class="f-ava" src="' + esc(it.img) + '" alt="" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">'
              : '') +
            '<span class="f-ava f-ava-letter"' + (it.img ? ' style="display:none;"' : '') + '>' + letter(it.name) + '</span>' +
            '<span class="f-info">' +
              '<span class="f-name-line">' + esc(it.name) + '<em>' + esc(g) + '</em></span>' +
              '<span class="f-url-line">' + esc(it.url) + '</span>' +
              (it.desc ? '<span class="f-desc-line">' + esc(it.desc) + '</span>' : '') +
            '</span>' +
            '<span class="f-ops">' +
              '<button type="button" class="f-edit" title="编辑"><i class="ri-pencil-line"></i></button>' +
              '<button type="button" class="f-del" title="删除"><i class="ri-delete-bin-line"></i></button>' +
            '</span>' +
          '</div>';
      });
      if (!triLinks.length) {
        html = '<div class="tri3-friend-empty">还没有友链，点击右上角「添加友链」开始</div>';
      }
      box.innerHTML =
        '<div class="tri3-friends-head">' +
          '<span>共 ' + triLinks.length + ' 条友链 · 拖动排序</span>' +
          '<button type="button" class="f-add"><i class="ri-add-line"></i>添加友链</button>' +
        '</div>' +
        '<div class="tri3-friends-list">' + html + '</div>';
      box.querySelector('.f-add').addEventListener('click', function () { openForm(-1); });
      var cards = box.querySelectorAll('.tri3-friend-card');
      var dragI = -1;
      cards.forEach(function (c) {
        c.addEventListener('dragstart', function () { dragI = parseInt(c.getAttribute('data-i'), 10); c.classList.add('f-dragging'); });
        c.addEventListener('dragend', function () { c.classList.remove('f-dragging'); dragI = -1; });
        c.addEventListener('dragover', function (e) { e.preventDefault(); });
        c.addEventListener('drop', function (e) {
          e.preventDefault();
          var j = parseInt(c.getAttribute('data-i'), 10);
          if (dragI >= 0 && dragI !== j) {
            var mv = triLinks.splice(dragI, 1)[0];
            triLinks.splice(j, 0, mv);
            sync(); render();
          }
        });
        c.querySelector('.f-edit').addEventListener('click', function () { openForm(parseInt(c.getAttribute('data-i'), 10)); });
        c.querySelector('.f-del').addEventListener('click', function () { removeAt(parseInt(c.getAttribute('data-i'), 10)); });
      });
    }
    render();
    /* 分组修改后刷新 datalist 选项 */
    if (groupsTa) {
      groupsTa.addEventListener('input', function () {
        var dl = box.querySelector('datalist');
        if (dl) {
          dl.innerHTML = getGroups().map(function (g) { return '<option value="' + esc(g) + '">' + esc(g) + '</option>'; }).join('');
        }
      });
    }
  });
})();

/* ========== 相册管理（TriM3 后台面板 AJAX 操作） ==========
 * 说明：相册管理 HTML 由 admin.php 通过 AJAX innerHTML 插入，
 * 内联 <script> 不会执行，故函数统一定义在此（全局始终可用）。
 * 点击链接 onclick="return triAmAct(this.href)" 依赖本组函数。 */
function triAmAct(h) {
  fetch(h + '&tri_ajax=1', { credentials: 'same-origin' })
    .then(function (r) { return r.text(); })
    .then(function (t) {
      var j = null;
      try { j = JSON.parse(t); } catch (e) { alert('响应异常：' + t.substring(0, 60)); return; }
      if (j && j.ok) {
        var c = document.querySelector("[data-panel='album']");
        if (c) { c.innerHTML = j.html; } else { location.href = h + '#album'; }
      } else { alert('操作失败'); }
    })
    .catch(function () { alert('网络错误'); });
  return false;
}
function triAmNew() {
  var n = document.getElementById('am-new-name').value;
  var d = document.getElementById('am-new-desc').value;
  if (!n.trim()) { alert('请填写相册名称'); return; }
  triAmAct(document.getElementById('am-base').value + '&tri_do=save&name=' + encodeURIComponent(n) + '&desc=' + encodeURIComponent(d));
}
function triAmEdit() {
  var n = document.getElementById('am-edit-name').value;
  var d = document.getElementById('am-edit-desc').value;
  var a = document.getElementById('am-edit-aid').value;
  if (!n.trim()) { alert('请填写相册名称'); return; }
  triAmAct(document.getElementById('am-base').value + '&tri_do=save&aid=' + a + '&name=' + encodeURIComponent(n) + '&desc=' + encodeURIComponent(d));
}
function triAmLink() {
  var u = document.getElementById('am-link-url').value;
  var t = document.getElementById('am-link-title').value;
  if (!u.trim()) { alert('请输入图片地址'); return; }
  triAmAct(document.getElementById('am-base').value + '&tri_do=padd&aid=' + document.getElementById('am-link-aid').value + '&url=' + encodeURIComponent(u) + '&title=' + encodeURIComponent(t));
}
function triAmPhotoSave() {
  var u = document.getElementById('am-ph-url').value;
  var t = document.getElementById('am-ph-title').value;
  if (!u.trim()) { alert('请输入图片地址'); return; }
  triAmAct(document.getElementById('am-base').value + '&tri_do=pedit&pid=' + document.getElementById('am-ph-pid').value + '&aid=' + document.getElementById('am-ph-aid').value + '&url=' + encodeURIComponent(u) + '&title=' + encodeURIComponent(t));
}
function triAmUpload(aid) {
  var inp = document.getElementById('am-file');
  var u = document.getElementById('am-base').value;
  if (!inp || !inp.files.length) { return; }
  var fd = new FormData();
  fd.append('tri_do', 'upload');
  fd.append('tri_ajax', '1');
  fd.append('aid', aid);
  fd.append('tri_view', aid);
  for (var i = 0; i < inp.files.length; i++) { fd.append('file[]', inp.files[i]); }
  fetch(u, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.text(); })
    .then(function (t) {
      var j = null;
      try { j = JSON.parse(t); } catch (e) { alert('上传失败'); return; }
      if (j && j.ok) {
        var c = document.querySelector("[data-panel='album']");
        if (c) { c.innerHTML = j.html; } else { location.href = u + '#album'; }
      } else { alert('上传失败'); }
    })
    .catch(function () { alert('上传失败'); });
}
