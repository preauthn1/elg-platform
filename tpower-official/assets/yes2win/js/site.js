/* 前台全站共用互動；每一段都先確認 DOM 存在才綁事件。 */

/* ---------- 頁首：語言下拉／更多下拉／手機選單 ----------
   兩個下拉與手機選單互斥；「更多」是原生 <details>，點外面不會收起，所以這裡補。
   狀態 class 掛在 <html>（CSS 與自動化測試都看）：is-lang-open、is-more-open、is-menu-open、has-nav-overlay。 */
(function () {
  'use strict';

  var root = document.documentElement;
  var langBox = document.querySelector('.language-switcher');
  var langTrigger = document.getElementById('langTrigger');
  var langMenu = document.getElementById('langMenu');
  var more = document.querySelector('details.desktop-more');
  var menuBtn = document.getElementById('menuButton');
  var mobile = document.getElementById('mobileMenu');
  var backdrop = document.querySelector('.nav-backdrop');

  function syncOverlay() {
    var on = root.classList.contains('is-lang-open') || root.classList.contains('is-more-open');
    root.classList.toggle('has-nav-overlay', on);
    if (backdrop) backdrop.hidden = !on;
  }

  function setMore(open) {
    if (!more) return;
    // 改 open 屬性會（非同步）觸發 toggle 事件，那邊會再同步一次 class —— 兩邊結果一樣，無害
    if (more.open !== open) more.open = open;
    root.classList.toggle('is-more-open', open);
    syncOverlay();
  }

  function setMenu(open) {
    if (!menuBtn || !mobile) return;
    var was = mobile.classList.contains('open');
    mobile.classList.toggle('open', open);
    menuBtn.classList.toggle('open', open);
    menuBtn.setAttribute('aria-expanded', String(open));
    mobile.setAttribute('aria-hidden', String(!open));
    root.classList.toggle('is-menu-open', open);
    // 只在狀態真的變了才動，避免把別人設的 overflow 洗掉
    if (open !== was) document.body.style.overflow = open ? 'hidden' : '';
  }

  function setLang(open) {
    if (!langTrigger || !langMenu) return;
    langMenu.classList.toggle('open', open);
    langTrigger.setAttribute('aria-expanded', String(open));
    root.classList.toggle('is-lang-open', open);
    if (open) {
      setMore(false);
      setMenu(false);
    }
    syncOverlay();
  }

  function closeAll() {
    setLang(false);
    setMore(false);
    setMenu(false);
  }

  if (langTrigger && langMenu) {
    langTrigger.addEventListener('click', function () {
      setLang(!langMenu.classList.contains('open'));
    });
  }

  if (more) {
    more.addEventListener('toggle', function () {
      if (more.open) {
        setLang(false);
        setMenu(false);
      }
      root.classList.toggle('is-more-open', more.open);
      syncOverlay();
    });
  }

  if (menuBtn && mobile) {
    menuBtn.addEventListener('click', function () {
      var open = !mobile.classList.contains('open');
      if (open) {
        setLang(false);
        setMore(false);
      }
      setMenu(open);
    });
    // 先收起來再換頁（不然從上一頁返回時 body 還鎖著）
    mobile.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('a')) setMenu(false);
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (root.classList.contains('is-lang-open') && !(langBox && langBox.contains(t))) setLang(false);
    if (more && more.open && !more.contains(t)) setMore(false);
  });

  function closeOnFocusOut(box, close) {
    if (!box) return;
    box.addEventListener('focusout', function (e) {
      if (e.relatedTarget && !box.contains(e.relatedTarget)) close();
    });
  }
  closeOnFocusOut(langBox, function () { setLang(false); });
  closeOnFocusOut(more, function () { setMore(false); });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.key !== 'Esc') return;
    var back = null;
    if (root.classList.contains('is-lang-open')) back = langTrigger;
    else if (more && more.open) back = more.querySelector('summary');
    else if (mobile && mobile.classList.contains('open')) back = menuBtn;
    if (!back) return;
    var inside = document.activeElement && document.activeElement !== document.body;
    closeAll();
    if (inside) back.focus();
  });

  // 跨過 1280 時全部收起：手機選單在桌機版是藏起來的，留著會鎖住捲動
  if (window.matchMedia) {
    var mq = window.matchMedia('(min-width: 1280px)');
    var onChange = function () { closeAll(); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }

  // 從 bfcache 回來時畫面會停在離開前的狀態，一律重置
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) closeAll();
  });
})();

/* ---------- 卡片按鈕的導向：<button data-href>（整張卡已是可點的東西，裡面不能再巢狀 <a>） ---------- */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var el = e.target.closest('[data-href]');
    if (!el) return;
    var url = el.getAttribute('data-href');
    if (!url) return;
    window.location.assign(url);
  });
})();

/* ---------- 膠囊分頁放不下時的提示：掛 is-fade-start／is-fade-end，並把作用中那一項捲進可視範圍（只動容器的 scrollLeft） ---------- */
(function () {
  'use strict';

  var boxes = [].slice.call(document.querySelectorAll('.pill-tabs, .route-tabs, .filter-tabs, .level-tabs, .insights-tabs'));
  if (!boxes.length) return;

  function update(box) {
    var max = box.scrollWidth - box.clientWidth;
    var x = Math.abs(box.scrollLeft);
    box.classList.toggle('is-fade-start', max > 1 && x > 1);
    box.classList.toggle('is-fade-end', max > 1 && x < max - 1);
  }

  function reveal(box) {
    if (box.scrollWidth - box.clientWidth <= 1) return;
    var cur = box.querySelector('.active, [aria-selected="true"], [aria-current="page"]');
    if (!cur) return;
    var b = box.getBoundingClientRect();
    var c = cur.getBoundingClientRect();
    var pad = 24;
    if (c.left < b.left + pad) box.scrollLeft -= b.left + pad - c.left;
    else if (c.right > b.right - pad) box.scrollLeft += c.right - (b.right - pad);
  }

  boxes.forEach(function (box) {
    box.addEventListener('scroll', function () { update(box); }, { passive: true });
    // 篩選型分頁由各頁 JS 切換 .active；等它切完再捲
    box.addEventListener('click', function () {
      setTimeout(function () { reveal(box); update(box); }, 0);
    });
    reveal(box);
    update(box);
  });

  var t;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(function () { boxes.forEach(update); }, 100);
  });
})();

/* ---------- 信任與安全：政策分頁（點擊切換與 location.hash 深連結，例如 /compliance#privacy） ---------- */
(function () {
  'use strict';

  var tabsBox = document.querySelector('.trust-tabs');
  if (!tabsBox) return;

  var tabs = [].slice.call(tabsBox.querySelectorAll('button[aria-controls]'));
  var panels = [].slice.call(document.querySelectorAll('.trust-panel'));
  if (!tabs.length || !panels.length) return;

  function activate(code, pushHash) {
    var target = panels.filter(function (p) {
      return p.id === code;
    })[0];
    if (!target) return false;
    tabs.forEach(function (t) {
      var on = t.getAttribute('aria-controls') === code;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', String(on));
      // roving tabindex（WAI-ARIA tabs）
      t.setAttribute('tabindex', on ? '0' : '-1');
    });
    panels.forEach(function (p) {
      if (p === target) p.removeAttribute('hidden');
      else p.setAttribute('hidden', '');
    });
    // 用 replaceState 而不是改 location.hash：不塞爆上一頁，也不會再觸發捲動
    if (pushHash && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#' + code);
    }
    return true;
  }

  tabsBox.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('button[aria-controls]');
    if (!btn || !tabsBox.contains(btn)) return;
    e.preventDefault();
    activate(btn.getAttribute('aria-controls'), true);
  });

  tabsBox.addEventListener('keydown', function (e) {
    var i = tabs.indexOf(document.activeElement);
    if (i < 0) return;
    var next = -1;
    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = (i + 1) % tabs.length;
    else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = tabs.length - 1;
    if (next < 0) return;
    e.preventDefault();
    tabs[next].focus();
    activate(tabs[next].getAttribute('aria-controls'), true);
  });

  function fromHash() {
    var code = String(window.location.hash || '').replace(/^#/, '');
    if (code) activate(code, false);
  }
  fromHash();
  window.addEventListener('hashchange', fromHash);
})();
