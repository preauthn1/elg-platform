/* 遊戲內容頁的分類篩選 —— 設計站是 React state，靜態站用這一小段還原：
   點分頁 → 換 .active 與 aria-selected，再依 data-cat 顯示／隱藏遊戲商卡。
   第一顆分頁的 data-cat 是空字串，代表「全部」。
   隱藏用 hidden 屬性（site.css 已有 [hidden]{display:none!important}），
   所以 .provider-wall 的 grid 不會留下空格。 */
(function () {
  'use strict';

  var tabsBox = document.querySelector('.provider-tabs');
  var wall = document.querySelector('.provider-wall');
  if (!tabsBox || !wall) return;

  var tabs = [].slice.call(tabsBox.querySelectorAll('button[data-cat]'));
  var cards = [].slice.call(wall.querySelectorAll('.provider-card'));
  if (!tabs.length || !cards.length) return;

  /* 分類底下沒有遊戲商時要顯示的空狀態，伺服器端已經渲染好（預設 hidden）。
     只有真的空的分類才有這個元素，所以通常這個清單只有一筆或是空的。 */
  var empties = [].slice.call(document.querySelectorAll('.provider-empty[data-cat]'));

  function apply(cat) {
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-cat') === cat;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', String(on));
    });
    var shown = 0;
    cards.forEach(function (c) {
      var show = !cat || c.getAttribute('data-cat') === cat;
      if (show) { c.removeAttribute('hidden'); shown++; }
      else c.setAttribute('hidden', '');
    });
    /* 一張卡都沒有 → 把牆整個收起來，換成那個分類的空狀態。
       牆不收的話會留下一個 grid 的空白高度，看起來像壞掉。 */
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
  });
})();

/* 遊戲商彈窗。.modal 那組樣式 site.css 原檔就有，這裡只補 DOM 與行為：
   點卡片開、Esc 關、「返回遊戲陣容」關、點彈窗內容不關，
   點背景**不關**（設計站就是這樣，不是漏掉）。
   捲動鎖不加（會改變版面），但焦點有補 —— 否則鍵盤使用者按 Esc 會掉回頁首。 */
(function () {
  'use strict';

  var wall = document.querySelector('.provider-wall');
  // 掛在 .site-app 而不是 .games-page —— 後者在 <main> 裡，而 main 有
  // isolation:isolate 自成堆疊脈絡，backdrop 的 z-index 再高也壓不過
  // z-index:80 的 sticky header，會變成彈窗開著頁首還露在外面而且照樣可點。
  var mount = document.querySelector('.site-app') || document.body;
  if (!wall || !mount) return;

  var T = {
    eyebrow: wall.getAttribute('data-modal-eyebrow') || '',
    back: wall.getAttribute('data-modal-back') || '',
    close: wall.getAttribute('data-modal-close') || '',
  };
  var backdrop = null;
  var lastCard = null;

  function close() {
    if (!backdrop) return;
    backdrop.remove();
    backdrop = null;
    if (lastCard) { lastCard.focus(); lastCard = null; }
  }

  function open(card) {
    close();
    lastCard = card;
    var name = (card.querySelector('h3') || {}).textContent || '';
    var descEl = card.querySelector('p');

    backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.setAttribute('role', 'presentation');

    var box = document.createElement('div');
    box.className = 'modal';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', name);

    var x = document.createElement('button');
    x.className = 'modal-close';
    x.setAttribute('aria-label', T.close);
    x.textContent = '×';

    var eyebrow = document.createElement('span');
    eyebrow.className = 'eyebrow';
    eyebrow.textContent = T.eyebrow;

    var h2 = document.createElement('h2');
    h2.textContent = name;

    box.appendChild(x);
    box.appendChild(eyebrow);

    /* 遊戲商 Logo。從卡片上的 <img data-logo> 讀，不另外開一份資料 ——
       卡片是伺服器端渲染的，網址已經在 DOM 裡，這裡再存一份只會兩邊不同步。
       沒上傳 logo 的遊戲商卡片上沒有這個元素，彈窗就只有標題（跟現況一樣）。 */
    var logoEl = card.querySelector('[data-logo]');
    var logoSrc = logoEl && logoEl.getAttribute('data-logo');
    if (logoSrc) {
      var logo = document.createElement('img');
      logo.className = 'modal-logo';
      logo.src = logoSrc;
      // 標題已經是遊戲商名稱，logo 再唸一次是重複資訊 → 對報讀器隱藏
      logo.alt = '';
      logo.setAttribute('aria-hidden', 'true');
      box.appendChild(logo);
    }

    box.appendChild(h2);
    if (descEl && descEl.textContent.trim()) {
      var p = document.createElement('p');
      p.textContent = descEl.textContent;
      box.appendChild(p);
    }
    var back = document.createElement('button');
    back.className = 'modal-action';
    back.textContent = T.back;
    box.appendChild(back);

    backdrop.appendChild(box);
    mount.appendChild(backdrop);
    x.focus();

    x.addEventListener('click', close);
    back.addEventListener('click', close);
    // 點背景不關（跟設計站一致）；這裡只擋住冒泡，避免點內容時誤觸其他 handler
    box.addEventListener('click', function (e) { e.stopPropagation(); });

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
