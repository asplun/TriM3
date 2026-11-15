/*!
 * TriM3 · Stimulus 应用入口（与 Swup 配套）
 * ---------------------------------------------------------------------------
 * 为什么需要它：Swup 每次导航都会替换整个 <body>，凡是"绑在元素上"的行为都会随旧 DOM 消失，
 * 于是过去只能在每个文件里监听 tri3:render / tri3:render 手动重绑 —— 分散、容易漏、也容易重复绑定。
 * Stimulus 的 connect() / disconnect() 由它自己按 DOM 变化调用，这里把"导航后要重跑的事"收成唯一入口：
 *
 *   1) 页面级幂等初始化：各行为文件把函数注册到 window.TriHooks，由 <body data-controller="tri3">
 *      的 connect() 按 HOOK_ORDER 统一执行；TriHooks.add() 在注册后若应用已就绪会立刻补跑一次。
 *   2) 元素级行为：直接写进标记（data-controller / data-action），如搜索面板、音乐页控件。
 *
 * 依赖：assets/js/stimulus.js（自托管，随主题一起带版本号）
 */
import { Application, Controller } from './stimulus.js?v=1.2.5';

const app = Application.start();

/* ===== 1. 页面生命周期（唯一的"导航后初始化"入口） ===== */
const HOOKS = (window.TriHooks = window.TriHooks || {});

/* 执行顺序：内容渲染 → 取色 → 动画观察者 → 列表样式 → 灯箱 → 播放器状态 → 音乐页 → 其它 */
const HOOK_ORDER = ['joeContent', 'dynamicColor', 'revealObserver', 'gridCards', 'lightgallery', 'playerSync', 'musicPage', 'init'];
let armed = false;
let running = false;

function runHooks() {
  if (running) return;
  running = true;
  try {
    for (let i = 0; i < HOOK_ORDER.length; i++) {
      const name = HOOK_ORDER[i];
      const fn = HOOKS[name];
      if (typeof fn !== 'function') continue;
      try {
        fn();
      } catch (e) {
        console.error('[TriM3] hook "' + name + '" failed:', e);
      }
    }
  } finally {
    running = false;
  }
}

HOOKS.add = function (name, fn) {
  HOOKS[name] = fn;
  if (armed && HOOK_ORDER.indexOf(name) === -1) HOOK_ORDER.push(name);
  if (armed) runHooks();
};

window.TriRunHooks = runHooks;
window.TriStimulus = { application: app, Controller: Controller };

class Tri3Controller extends Controller {
  connect() {
    armed = true;
    window.TriStimulusArmed = true;
    runHooks();
    /* 兼容既有/三方监听：导航完成事件照旧派发一次 */
    document.dispatchEvent(new CustomEvent('tri3:render'));
  }
}
app.register('tri3', Tri3Controller);

/* ===== 2. 搜索面板 =====
   原先分散在三处：footer.php 的 rebindSearch（per-element 绑定）、文档级委托的 toggle、
   以及"点击外部关闭"的全局监听，全靠 tri3:render 重绑维持。
   现在合成一个控制器：connect()/disconnect() 跟着元素生灭，Swup 换 body 时零重绑。 */
class SearchController extends Controller {
  connect() {
    this._onDocClick = this._onDocClick || this.closeOnOutsideClick.bind(this);
    document.removeEventListener('click', this._onDocClick);
    document.addEventListener('click', this._onDocClick);
  }

  disconnect() {
    document.removeEventListener('click', this._onDocClick);
  }

  closeOnOutsideClick(event) {
    if (this.element.contains(event.target)) return;
    this.element.classList.remove('is-open');
  }

  toggle(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    const corner = this.element;
    corner.classList.toggle('is-open');
    const input = corner.querySelector('input');
    if (input && corner.classList.contains('is-open')) {
      setTimeout(function () { input.focus(); }, 300);
    }
  }
}
app.register('search', SearchController);

/* ===== 3. 音乐页控件（原 page-music.php 的内联 onclick） ===== */
class MusicController extends Controller {
  toggleSidebar() { if (typeof window.spToggleSidebar === 'function') window.spToggleSidebar(); }
  toggleMobileList() { if (typeof window.spToggleMobileList === 'function') window.spToggleMobileList(); }
  prev() { if (typeof window.musicPrev === 'function') window.musicPrev(); }
  play() { if (typeof window.musicPlay === 'function') window.musicPlay(); }
  next() { if (typeof window.musicNext === 'function') window.musicNext(); }
  seek(event) { if (typeof window.musicSeek === 'function') window.musicSeek(event); }
  playSong(event) {
    const index = parseInt(event.currentTarget.getAttribute('data-music-index'), 10);
    if (!isNaN(index) && typeof window.spPlaySong === 'function') window.spPlaySong(index);
  }
}
app.register('music', MusicController);
