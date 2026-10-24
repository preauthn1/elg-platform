/* 前台互動 —— 全站共用的幾件小事：語言選單、行動版選單、
   活動卡按鈕的導向、信任與安全的政策分頁。
   桌機的「更多」用原生 <details>，不需要 JS。
   每一段都先確認 DOM 存在才綁事件，所以在沒有那些區塊的頁面上完全不做事。 */
(function () {
  'use strict';

  // ---------- 語言選單 ----------
  var trigger = document.getElementById('langTrigger');
  var menu = document.getElementById('langMenu');
  if (trigger && menu) {
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.classList.toggle('open');
      trigger.setAttribute('aria-expanded', String(open));
    });
  }

  // ---------- 行動版選單 ----------
  var btn = document.getElementById('menuButton');
  var mobile = document.getElementById('mobileMenu');
  if (btn && mobile) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = mobile.classList.toggle('open');
      btn.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', String(open));
      mobile.setAttribute('aria-hidden', String(!open));
      // 選單展開時鎖住背景捲動
      document.body.style.overflow = open ? 'hidden' : '';
    });
    mobile.addEventListener('click', function (e) {
      if (e.target.tagName !== 'A') return;
      mobile.classList.remove('open');
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      mobile.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    });
  }

  function closeAll() {
    if (menu) {
      menu.classList.remove('open');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }
  }
  document.addEventListener('click', closeAll);
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    closeAll();
    if (mobile && mobile.classList.contains('open')) btn.click();
  });
})();

/* ---------- 卡片按鈕的導向 ----------
   .promo-card 的 CTA 必須是 <button>（CSS 鉤子是標籤選擇器 `.promo-card button`，
   換成 <a> 會整個失去樣式），所以連結掛在 data-href 上，由這段補上點擊行為。 */
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

/* ---------- 信任與安全：政策分頁 ----------
   設計站只把第一個面板送到 HTML，其餘靠 React state 切換；靜態站四個面板全部輸出，
   第一個顯示、其餘掛 hidden，這段負責點擊切換與 location.hash 深連結
   （頁尾或外部連結的 /compliance#privacy 要能直接落在對應面板）。 */
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

  function fromHash() {
    var code = String(window.location.hash || '').replace(/^#/, '');
    if (code) activate(code, false);
  }
  fromHash();
  window.addEventListener('hashchange', fromHash);
})();
