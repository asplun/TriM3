/* TriM3 动态取色：从 Banner 提取主色 → 生成配色 → CSS 变量覆盖 */
(function () {
'use strict';
if (window.__triDC) return;
window.__triDC = true;

function hsl(h, s, l) {
  h = ((h % 360) + 360) % 360;
  s = Math.max(0, Math.min(1, s)); l = Math.max(0, Math.min(1, l));
  var c = (1 - Math.abs(2 * l - 1)) * s;
  var x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  var m = l - c / 2;
  var r, g, b;
  if (h < 60) { r=c; g=x; b=0; } else if (h < 120) { r=x; g=c; b=0; }
  else if (h < 180) { r=0; g=c; b=x; } else if (h < 240) { r=0; g=x; b=c; }
  else if (h < 300) { r=x; g=0; b=c; } else { r=c; g=0; b=x; }
  var t = function (v) { return Math.max(0, Math.min(255, Math.round((v+m)*255))).toString(16).padStart(2, '0'); };
  return '#' + t(r) + t(g) + t(b);
}

function extractColor(img) {
  var c = document.createElement('canvas');
  c.width = 80; c.height = 45;
  var ctx = c.getContext('2d', { willReadFrequently: true });
  try {
    ctx.drawImage(img, 0, 0, 80, 45);
    var d = ctx.getImageData(0, 0, 80, 45).data;
    var bins = {};
    var bestHue = 265, bestScore = 0;
    
    for (var i = 0; i < d.length; i += 4) {
      var r=d[i]/255, g=d[i+1]/255, b=d[i+2]/255;
      var mx=Math.max(r,g,b), mn=Math.min(r,g,b), l=(mx+mn)/2;
      var s = mx===mn ? 0 : (l>0.5 ? (mx-mn)/(2-mx-mn) : (mx-mn)/(mx+mn));
      if (s < 0.15 || l < 0.1 || l > 0.95) continue;
      
      var h = 0;
      if (mx !== mn) {
        var dd = mx - mn;
        if (mx === r) h = (g-b)/dd+(g<b?6:0);
        else if (mx === g) h = (b-r)/dd+2;
        else h = (r-g)/dd+4;
        h *= 60;
      }
      var bk = Math.round(h/10)*10;
      if (!bins[bk]) bins[bk] = { score: 0, sat: 0 };
      bins[bk].score += s * s;
      bins[bk].sat += s;
    }
    
    for (var k in bins) {
      if (bins[k].score > bestScore) {
        bestScore = bins[k].score;
        bestHue = +k;
      }
    }
    
    return { hue: bestHue, sat: 0.75 };
  } catch(e) {
    return { hue: 265, sat: 0.5 };
  }
}

function makePalette(color, isDark) {
  var hue = color.hue;
  var baseSat = color.sat;
  
  // Light mode: warm/bright tones
  // Dark mode: cool/deep tones
  var primaryL = isDark ? 0.70 : 0.45;
  var containerL = isDark ? 0.22 : 0.85;
  
  // Main colors with good saturation
  var primarySat = Math.min(0.85, baseSat);
  var containerSat = Math.min(0.65, baseSat * 0.7);
  
  // Surface colors - more noticeable tint
  var surfaceSat = isDark ? 0.22 : 0.35;
  var bgL = isDark ? 0.08 : 0.88;
  var surfL = isDark ? 0.10 : 0.90;
  
  function key(dh, sa) { return hsl(hue+dh, Math.max(0.3,Math.min(0.8,sa)), primaryL); }
  
  return {
    // Primary
    '--md-sys-color-primary': hsl(hue, primarySat, primaryL),
    '--md-sys-color-on-primary': isDark ? '#fff' : '#fff',
    '--md-sys-color-primary-container': hsl(hue, containerSat, containerL),
    '--md-sys-color-on-primary-container': hsl(hue, containerSat, isDark?0.95:0.10),
    
    // Secondary
    '--md-sys-color-secondary': key(20, primarySat*0.7),
    '--md-sys-color-secondary-container': hsl(hue+20, containerSat*0.8, isDark?0.25:0.88),
    '--md-sys-color-on-secondary-container': hsl(hue+20, containerSat*0.8, isDark?0.95:0.12),
    
    // Tertiary
    '--md-sys-color-tertiary': key(-20, primarySat*0.5),
    
    // Background & Surface - noticeable tint
    '--md-sys-color-background': hsl(hue, surfaceSat, bgL),
    '--md-sys-color-on-background': hsl(hue, surfaceSat*0.5, isDark?0.90:0.08),
    '--md-sys-color-surface': hsl(hue, surfaceSat, surfL),
    '--md-sys-color-on-surface': hsl(hue, surfaceSat*0.5, isDark?0.90:0.08),
    '--md-sys-color-surface-variant': hsl(hue, surfaceSat*1.2, isDark?0.20:0.85),
    '--md-sys-color-on-surface-variant': hsl(hue, surfaceSat*0.8, isDark?0.75:0.35),
    
    // Surface containers - progressively more saturated
    '--md-sys-color-surface-container-lowest': hsl(hue, surfaceSat*0.8, isDark?0.06:0.96),
    '--md-sys-color-surface-container-low': hsl(hue, surfaceSat*1.0, isDark?0.12:0.93),
    '--md-sys-color-surface-container': hsl(hue, surfaceSat*1.3, isDark?0.16:0.90),
    '--md-sys-color-surface-container-high': hsl(hue, surfaceSat*1.6, isDark?0.20:0.87),
    '--md-sys-color-surface-container-highest': hsl(hue, surfaceSat*2.0, isDark?0.24:0.84),
    
    // Outline
    '--md-sys-color-outline': hsl(hue, surfaceSat*0.8, isDark?0.60:0.45),
    '--md-sys-color-outline-variant': hsl(hue, surfaceSat*0.6, isDark?0.35:0.75),
    
    // Gradient
    '--tri-grad-1': hsl(hue, primarySat*0.9, isDark?0.18:0.82),
    '--tri-grad-2': hsl(hue+25, primarySat*0.6, isDark?0.20:0.84),
    '--tri-grad-3': hsl(hue-25, primarySat*0.7, isDark?0.16:0.86),
    '--tri-grad-4': hsl(hue+45, primarySat*0.4, isDark?0.14:0.88)
  };
}

function apply(pal) {
  var root = document.documentElement;
  // Clean up old gradient style tags
  var old = document.getElementById('tri-dc-gradient');
  if (old) old.remove();
  for (var k in pal) root.style.setProperty(k, pal[k], 'important');
  root.style.setProperty('background-color', 'transparent', 'important');
  
  function rgb(hex) {
    var h = hex.replace('#','');
    return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)].join(',');
  }
  
  function doBody() {
    if (document.body) document.body.style.setProperty('background-color', pal['--md-sys-color-background'], 'important');
    if (!document.getElementById('tri-dc-gradient')) {
      var st = document.createElement('style');
      st.id = 'tri-dc-gradient';
      document.head.appendChild(st);
    }
    document.getElementById('tri-dc-gradient').textContent =
      'body::before{background-image:' +
      'radial-gradient(1100px 720px at 0% 0%, rgb(' + rgb(pal['--tri-grad-1']) + ' / 0.95), transparent 72%),' +
      'radial-gradient(1100px 720px at 100% 0%, rgb(' + rgb(pal['--tri-grad-2']) + ' / 0.85), transparent 72%),' +
      'radial-gradient(720px 520px at 30% 108%, rgb(' + rgb(pal['--tri-grad-3']) + ' / 0.78), transparent 66%),' +
      'radial-gradient(720px 520px at 85% 112%, rgb(' + rgb(pal['--tri-grad-4']) + ' / 0.70), transparent 66%) !important;}';
  }
  if (document.body) doBody();
  else document.addEventListener('DOMContentLoaded', doBody);
}

function isDarkNow() {
  return document.documentElement.classList.contains('dark');
}

function applyColor(color) {
  apply(makePalette(color, isDarkNow()));
}

try {
  var saved = localStorage.getItem('triDCColor');
  if (saved) {
    var c = JSON.parse(saved);
    applyColor(c);
  }
} catch(e) {}

var darkObserver = new MutationObserver(function() {
  try {
    var saved = localStorage.getItem('triDCColor');
    if (saved) {
      var c = JSON.parse(saved);
      applyColor(c);
    }
  } catch(e) {}
});
darkObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

var cache = {};

function processSrc(src, cb) {
  if (cache[src]) { cb(cache[src]); return; }
  var im = new Image();
  im.crossOrigin = 'anonymous';
  im.onload = function () {
    var c = extractColor(im);
    cache[src] = c;
    cb(c);
  };
  im.onerror = function () {
    try {
      var proxy = (window.TRI3_THEME_URL || '/usr/themes/') + 'api/img.php?src=' + encodeURIComponent(src) + '&w=80&q=70&f=jpeg';
      var im2 = new Image();
      im2.crossOrigin = 'anonymous';
      im2.onload = function () {
        var c = extractColor(im2);
        cache[src] = c;
        cb(c);
      };
      im2.onerror = function () { cb(null); };
      im2.src = proxy;
    } catch(e) { cb(null); }
  };
  im.src = src;
}

function init() {
  var slides = document.querySelectorAll('.hero-slider .slide img');
  var done = false;
  for (var i = 0; i < slides.length; i++) {
    (function(img) {
      if (done) return;
      processSrc(img.currentSrc || img.src, function(c) {
        if (c !== null && !done) {
          done = true;
          try { localStorage.setItem('triDCColor', JSON.stringify(c)); } catch(e) {}
          applyColor(c);
        }
      });
    })(slides[i]);
  }
}

var _dcLastSrc = '';
function checkCarousel() {
  var active = document.querySelector('.hero-slider .slide.is-active img');
  if (!active) return;
  var src = active.currentSrc || active.src;
  if (!src || src === _dcLastSrc) return;
  _dcLastSrc = src;
  processSrc(src, function(c) {
    if (c !== null) {
      try { localStorage.setItem('triDCColor', JSON.stringify(c)); } catch(e) {}
      applyColor(c);
    }
  });
}
var _dcCarouselObs = new MutationObserver(checkCarousel);
function startCarouselObs() {
  var container = document.querySelector('.hero-slider');
  if (container) _dcCarouselObs.observe(container, { attributes: true, subtree: true, attributeFilter: ['class'] });
}
if (document.body) startCarouselObs();
else document.addEventListener('DOMContentLoaded', startCarouselObs);

function boot() { init(); startCarouselObs(); }
if (document.body) boot();
else document.addEventListener('DOMContentLoaded', boot);

(window.TriHooks = window.TriHooks || {}).dynamicColor = function() {
  try {
    // Re-apply saved color
    var saved = localStorage.getItem('triDCColor');
    if (saved) {
      var c = JSON.parse(saved);
      applyColor(c);
    }
    // Re-setup carousel observer for new body
    _dcCarouselObs.disconnect();
    startCarouselObs();
    checkCarousel();
  } catch(e) {}
};
})();
