/* 活動卡的「查看活動詳情」彈窗。點背景**不關**（設計站就是這樣，不是漏掉）。
   ⚠ 只在「活動沒有設連結」時才開彈窗；設了連結的卡片樣板直接輸出 <a href>。 */
(function () {
  'use strict';

  var grid = document.querySelector('.promo-grid');
  /* 掛在 .site-app 而不是 <main> —— main 有 isolation:isolate 自成堆疊脈絡，
     backdrop 放在裡面時 z-index 再高也壓不過 z-index:80 的 sticky header。 */
  var mount = document.querySelector('.site-app') || document.body;
  if (!grid) return;

  var T = {
    eyebrow: grid.getAttribute('data-modal-eyebrow') || '',
    note: grid.getAttribute('data-modal-note') || '',
    ok: grid.getAttribute('data-modal-ok') || '',
    close: grid.getAttribute('data-modal-close') || '',
  };
  var backdrop = null;
  var lastBtn = null;

  function close() {
    if (!backdrop) return;
    backdrop.remove();
    backdrop = null;
    if (lastBtn) { lastBtn.focus(); lastBtn = null; }
  }

  function open(card, btn) {
    close();
    lastBtn = btn;
    var title = ((card.querySelector('h3') || {}).textContent || '').trim();

    backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.setAttribute('role', 'presentation');

    var box = document.createElement('div');
    box.className = 'modal';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', title);

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'modal-close';
    x.setAttribute('aria-label', T.close);
    x.textContent = '×';

    var eyebrow = document.createElement('span');
    eyebrow.className = 'eyebrow';
    eyebrow.textContent = T.eyebrow;

    var h2 = document.createElement('h2');
    h2.textContent = title;

    /* 條款說明。設計站三張卡的這一段一字不差 —— 它是條款出處的聲明，
       不是某一場活動的內容，所以放在介面字串（server/lib/render.js）而不是
       每張卡各存一份：一份文案改一次，四張卡不會有三張忘了改。 */
    var note = document.createElement('p');
    note.textContent = T.note;

    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'modal-action';
    ok.textContent = T.ok;

    box.appendChild(x);
    box.appendChild(eyebrow);
    box.appendChild(h2);
    box.appendChild(note);
    box.appendChild(ok);

    backdrop.appendChild(box);
    mount.appendChild(backdrop);
    x.focus();

    x.addEventListener('click', close);
    ok.addEventListener('click', close);
    // 點背景不關（跟設計站一致）；這裡只擋住冒泡，避免點內容時誤觸其他 handler
    box.addEventListener('click', function (e) { e.stopPropagation(); });
    backdrop.addEventListener('click', close);

    // 焦點圍堵：宣告了 aria-modal="true" 就等於告訴報讀器「外面的東西不存在」，
    // 若 Tab 還能跑出去，鍵盤使用者會落在報讀器看不到的地方。
    box.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var f = box.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!f.length) return;
      var first = f[0];
      var last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  grid.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('.promo-card button');
    if (!btn || !grid.contains(btn)) return;
    if (btn.hasAttribute('data-href')) return;
    e.preventDefault();
    open(btn.closest('.promo-card'), btn);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop) close();
  });
})();
