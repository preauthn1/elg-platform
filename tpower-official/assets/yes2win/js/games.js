/* 遊戲內容頁的分類篩選（data-cat 空字串＝全部）。
   卡片牆電腦版是「先直後橫」兩欄（grid-auto-flow: column），篩選後要依剩下的卡片數重算 --rows，不然會留下空列。 */
(function () {
  'use strict';

  var tabsBox = document.querySelector('.provider-tabs');
  var wall = document.querySelector('.provider-wall');
  if (!tabsBox || !wall) return;

  var tabs = [].slice.call(tabsBox.querySelectorAll('button[data-cat]'));
  var cards = [].slice.call(wall.querySelectorAll('.provider-card'));
  if (!tabs.length || !cards.length) return;

  var empties = [].slice.call(document.querySelectorAll('.provider-empty[data-cat]'));

  function apply(cat) {
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-cat') === cat;
      t.classList.toggle('active', on);
      t.setAttribute('aria-pressed', String(on));
    });
    var shown = 0;
    cards.forEach(function (c) {
      var show = !cat || c.getAttribute('data-cat') === cat;
      if (show) { c.removeAttribute('hidden'); shown++; }
      else c.setAttribute('hidden', '');
    });
    wall.style.setProperty('--rows', String(Math.max(1, Math.ceil(shown / 2))));
    var box = null;
    for (var i = 0; i < empties.length; i++) {
      var mine = empties[i].getAttribute('data-cat') === cat;
      if (mine && !shown) box = empties[i];
      else empties[i].setAttribute('hidden', '');
    }
    if (box) box.removeAttribute('hidden');
    if (!shown && box) wall.setAttribute('hidden', '');
    else wall.removeAttribute('hidden');
  }

  tabsBox.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('button[data-cat]');
    if (!btn || !tabsBox.contains(btn)) return;
    e.preventDefault();
    apply(btn.getAttribute('data-cat') || '');
    /* 只捲篩選列，不動頁面 */
    var bl = btn.offsetLeft, br = bl + btn.offsetWidth;
    if (bl < tabsBox.scrollLeft || br > tabsBox.scrollLeft + tabsBox.clientWidth) {
      tabsBox.scrollTo({ left: bl - (tabsBox.clientWidth - btn.offsetWidth) / 2, behavior: 'smooth' });
    }
  });
})();

/* 遊戲商彈窗：點背景不關（跟設計站一致）；關掉後焦點回到那張卡的按鈕。 */
(function () {
  'use strict';

  var wall = document.querySelector('.provider-wall');
  // 掛在 .site-app 而不是 .games-page —— 後者在 <main> 裡，main 有 isolation:isolate 自成堆疊脈絡，
  // backdrop 的 z-index 再高也壓不過 z-index:80 的 sticky 頁首。
  var mount = document.querySelector('.site-app') || document.body;
  if (!wall || !mount) return;

  var T = {
    eyebrow: wall.getAttribute('data-modal-eyebrow') || '',
    back: wall.getAttribute('data-modal-back') || '',
    close: wall.getAttribute('data-modal-close') || '',
  };
  var backdrop = null;
  var lastFocus = null;
  var uid = 0;

  function close() {
    if (!backdrop) return;
    backdrop.remove();
    backdrop = null;
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    lastFocus = null;
  }

  function open(card) {
    close();
    lastFocus = card.querySelector('.provider-action') || card;
    var name = ((card.querySelector('h3') || {}).textContent || '').trim();
    var descEl = card.querySelector('.provider-desc');
    var titleId = 'provider-modal-title-' + ++uid;

    backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop provider-modal';
    backdrop.setAttribute('role', 'presentation');

    var box = document.createElement('div');
    box.className = 'modal';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', titleId);

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'modal-close';
    x.setAttribute('aria-label', T.close);
    x.textContent = '×';

    var eyebrow = document.createElement('span');
    eyebrow.className = 'eyebrow';
    eyebrow.textContent = T.eyebrow;

    var h2 = document.createElement('h2');
    h2.id = titleId;
    h2.textContent = name;

    box.appendChild(x);
    box.appendChild(eyebrow);

    /* 遊戲商 Logo：從卡片上的 <img data-logo> 讀；標題已經是遊戲商名稱，logo 對報讀器隱藏。 */
    var logoEl = card.querySelector('[data-logo]');
    var logoSrc = logoEl && logoEl.getAttribute('data-logo');
    if (logoSrc) {
      var logo = document.createElement('img');
      logo.className = 'modal-logo';
      logo.src = logoSrc;
      logo.alt = '';
      logo.setAttribute('aria-hidden', 'true');
      box.appendChild(logo);
    }

    box.appendChild(h2);
    if (descEl && descEl.textContent.trim()) {
      var p = document.createElement('p');
      p.textContent = descEl.textContent.trim();
      box.appendChild(p);
    }
    var back = document.createElement('button');
    back.type = 'button';
    back.className = 'modal-action';
    back.textContent = T.back;
    box.appendChild(back);

    backdrop.appendChild(box);
    mount.appendChild(backdrop);
    x.focus();

    x.addEventListener('click', close);
    back.addEventListener('click', close);
    box.addEventListener('click', function (e) { e.stopPropagation(); });
    backdrop.addEventListener('click', close);

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

  wall.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var card = e.target.closest('.provider-card');
    if (!card || !wall.contains(card)) return;
    e.preventDefault();
    open(card);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop) close();
  });
})();
