(function () {
  'use strict';

  var VERSION = '1.2.5';
  var loading = null;
  var root = (function () {
    var script = document.currentScript;
    if (script && script.src) {
      try { return new URL('../../', script.src).href; } catch (e) {}
    }
    return '/';
  })();

  function hasCodeBlock() {
    return !!document.querySelector('.post-content pre, .entry-content pre, .single-body pre');
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var el = document.createElement('script');
      el.src = src + '?v=' + VERSION;
      el.defer = true;
      el.onload = resolve;
      el.onerror = function () { reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(el);
    });
  }

  function loadAssets() {
    if (loading) { return loading; }
    var assets = [
      'assets/lib/prism/prism.min.js',
      'assets/lib/prism/components/prism-css.min.js',
      'assets/lib/prism/components/prism-javascript.min.js',
      'assets/lib/prism/components/prism-markup-templating.min.js',
      'assets/lib/prism/components/prism-php.min.js',
      'assets/js/code-block.min.js'
    ];
    loading = assets.reduce(function (chain, asset) {
      return chain.then(function () { return loadScript(root + asset); });
    }, Promise.resolve());
    return loading;
  }

  function init() {
    if (!hasCodeBlock()) { return; }
    loadAssets().catch(function (err) {
      console.warn('[Tri3] code highlight load failed:', err);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  document.addEventListener('tri3:render', init);
})();
