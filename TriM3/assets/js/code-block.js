(function () {
  'use strict';
  function getLang(pre) {
    var code = pre.querySelector('code');
    if (!code) return 'code';
    var m = (code.className || '').match(/language-(\w+)/);
    return m ? m[1] : 'code';
  }
  function enhance(pre) {
    if (pre.closest('.code-block')) return;
    if (pre.parentElement && pre.parentElement.tagName === 'P') return;
    var lang = getLang(pre);
    var wrapper = document.createElement('div');
    wrapper.className = 'code-block';
    var header = document.createElement('div');
    header.className = 'code-block-header';
    header.innerHTML =
      '<div class="code-block-dots"><span></span><span></span><span></span></div>' +
      '<span class="code-block-lang">' + lang + '</span>' +
      '<div class="code-block-actions">' +
      '<button class="cb-copy" title="复制代码" aria-label="复制"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>' +
      '<button class="cb-theme" title="切换明暗" aria-label="切换"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg></button>' +
      '</div>';
    pre.parentNode.insertBefore(wrapper, pre);
    wrapper.appendChild(header);
    wrapper.appendChild(pre);
    var copyBtn = header.querySelector('.cb-copy');
    copyBtn.addEventListener('click', function () {
      var code = pre.querySelector('code') || pre;
      var text = code.textContent;
      function done() {
        copyBtn.classList.add('copied');
        var orig = copyBtn.innerHTML;
        copyBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
        setTimeout(function(){ copyBtn.classList.remove('copied'); copyBtn.innerHTML = orig; }, 1500);
      }
      if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done).catch(done); }
      else { var ta = document.createElement('textarea'); ta.value = text; ta.style.cssText='position:fixed;opacity:0'; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');}catch(e){} document.body.removeChild(ta); done(); }
    });
    var themeBtn = header.querySelector('.cb-theme');
    themeBtn.addEventListener('click', function(){ wrapper.classList.toggle('cb-light'); });
  }
  function init() {
    var pres = document.querySelectorAll('.post-content pre, .entry-content pre, .single-body pre');
    for (var i = 0; i < pres.length; i++) { enhance(pres[i]); }
    if (window.Prism) { Prism.highlightAllUnder(document); }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
  document.addEventListener('tri3:render', init);
})();
