var io = null;
var moTimer = null;
var mo = null;

var revealSelectors = '.header-reveal, .banner-reveal, .main-reveal, .tri3-reveal:not(.tw-item), .text-reveal, .img-reveal, .homeimg-reveal';

function nodesEdit() {
  var content = document.querySelector('.tri-article-content, .entry-content, .post-content, article .content');
  if (!content) return;
  content.querySelectorAll('p, h2, h3, ul, ol, blockquote, pre, table').forEach(function(el) {
    el.classList.add('text-reveal');
  });
  content.querySelectorAll('img').forEach(function(img) {
    img.classList.add('img-reveal');
  });
}

function showBatch(elements) {
  var rows = {};
  elements.forEach(function(el) {
    if (el.classList.contains('is-visible')) return;
    var r = el.getBoundingClientRect();
    var rowKey = Math.round(r.top);
    if (!rows[rowKey]) rows[rowKey] = [];
    rows[rowKey].push(el);
  });
  var sortedRows = Object.keys(rows).sort(function(a, b) { return parseFloat(a) - parseFloat(b); });
  sortedRows.forEach(function(rowKey, rowIdx) {
    var rowEls = rows[rowKey].sort(function(a, b) {
      return a.getBoundingClientRect().left - b.getBoundingClientRect().left;
    });
    rowEls.forEach(function(el, colIdx) {
      var delay = rowIdx * 60 + colIdx * 40;
      el.style.animationDelay = delay + 'ms';
      el.classList.add('is-visible');
      setTimeout(function() {
        el.style.animationDelay = '';
        el.style.willChange = '';
      }, 800 + delay);
    });
  });
}

function showInViewport() {
  var vh = window.innerHeight;
  var batch = [];
  document.querySelectorAll(revealSelectors).forEach(function(el) {
    if (el.classList.contains('is-visible')) return;
    var rect = el.getBoundingClientRect();
    if (rect.top < vh - 100 && rect.bottom > -100 && rect.height > 0) {
      batch.push(el);
    }
  });
  if (batch.length) showBatch(batch);
}

function initObserver() {
  if (io) io.disconnect();
  if (mo) { mo.disconnect(); mo = null; }
  nodesEdit();

  io = new IntersectionObserver(function(entries) {
    var batch = [];
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        batch.push(entry.target);
        io.unobserve(entry.target);
      }
    });
    if (batch.length) showBatch(batch);
  }, {
    root: null,
    rootMargin: '0px 0px 0px 0px',
    threshold: 0
  });

  document.querySelectorAll(revealSelectors).forEach(function(el) {
    if (!el.classList.contains('is-visible')) io.observe(el);
  });

  setTimeout(showInViewport, 100);

  var main = document.querySelector('.col-main') || document.querySelector('#main') || document.querySelector('main') || document.getElementById('tri3-home-list');
  if (main) {
    mo = new MutationObserver(function(mutations) {
      if (moTimer) clearTimeout(moTimer);
      moTimer = requestAnimationFrame(function() {
        var newElements = [];
        mutations.forEach(function(m) {
          m.addedNodes.forEach(function(node) {
            if (node.nodeType !== 1) return;
            if (node.matches && node.matches(revealSelectors)) newElements.push(node);
            if (node.querySelectorAll) node.querySelectorAll(revealSelectors).forEach(function(el) { newElements.push(el); });
          });
        });
        if (newElements.length) {
          newElements.forEach(function(el) {
            if (!el.classList.contains('is-visible')) io.observe(el);
          });
        }
        setGridCardsClass();
      });
    });
    mo.observe(main, { childList: true, subtree: true });
  }
}

document.documentElement.classList.add('sr');
initObserver();

/* 导航后的重跑交给 Stimulus（assets/js/tri-app.js）：注册到 TriHooks，
   tri3:render 事件也由它统一派发。 */
(window.TriHooks = window.TriHooks || {}).revealObserver = function() { setTimeout(initObserver, 50); };
// Set grid-cards class only on the container that DIRECTLY contains post-card-grid
function setGridCardsClass() {
  document.querySelectorAll('.articles-list, .home-list, .post-list').forEach(function(container) {
    var directChildren = Array.prototype.filter.call(container.children, function(c) {
      return c.classList && c.classList.contains('post-card-grid');
    });
    if (directChildren.length > 0) {
      if (!container.classList.contains('grid-cards')) container.classList.add('grid-cards');
    } else {
      container.classList.remove('grid-cards');
    }
  });
}
(window.TriHooks = window.TriHooks || {}).gridCards = function() { setTimeout(setGridCardsClass, 100); };
document.addEventListener('DOMContentLoaded', setGridCardsClass);
setGridCardsClass();


