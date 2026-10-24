/* 品牌影像館的影像彈窗。設計站點卡片是開一個播放器彈窗，不是換頁 ——
   .modal 那組樣式（連 .video-placeholder 都在）site.css 原檔就有，這裡只補 DOM 與行為。

   行為刻意跟 games.js 的遊戲商彈窗一致：點卡片開、Esc 關、右上 × 關、
   點彈窗內容不關、點背景**不關**（設計站就是這樣，不是漏掉）。

   ⚠ 有影片網址的卡片在 HTML 裡仍然是 <a href>，沒有 JS 時照樣能點開影片；
   有 JS 時這裡 preventDefault 改開彈窗，跟設計站一致。
   沒有網址的卡片是 <button>，彈窗裡顯示「內容正在準備中」的佔位框。 */
(function () {
  'use strict';

  var grid = document.querySelector('.media-grid');
  /* 掛在 .site-app 而不是 <main> —— main 有 isolation:isolate 自成堆疊脈絡，
     backdrop 放在裡面時 z-index 再高也壓不過 z-index:80 的 sticky header，
     會變成彈窗開著頁首還露在外面而且照樣可點。（games.js 同一個坑。） */
  var mount = document.querySelector('.site-app') || document.body;
  if (!grid) return;

  var T = {
    eyebrow: grid.getAttribute('data-modal-eyebrow') || '',
    soon: grid.getAttribute('data-modal-soon') || '',
    watch: grid.getAttribute('data-modal-watch') || '',
    close: grid.getAttribute('data-modal-close') || '',
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
    var title = ((card.querySelector('h3') || {}).textContent || '').trim();
    // 有影片就是 <a>，網址直接讀 href；<button> 沒有 href，回空字串
    var url = card.getAttribute('href') || '';

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

    /* 影片框。兩種狀態：
         有影片 → 後台設定的封面當底圖，中間一顆 ▶ 點了開影片，不放任何文字
                  （文字是「還沒有影片」才要講的話，有影片時多那一句只是雜訊）
         沒影片 → 設計站原樣：空框 + 紅色 ▶ + 「內容正在準備中」

       ⚠ 封面用 <img> 而不是 CSS 的 background-image：網址是站方上傳的檔名，
       串進 url(...) 就是把使用者資料丟進 CSS 語法裡，檔名帶引號或括號就壞掉；
       指定 img.src 是屬性賦值，不經過 CSS 解析。 */
    var frame = document.createElement('div');
    frame.className = 'video-placeholder';

    if (url) {
      // 封面從卡片上那張圖讀 —— 卡片是伺服器端渲染的，網址已經在 DOM 裡，
      // 這裡再存一份只會兩邊不同步。沒設封面就維持空框。
      var coverEl = card.querySelector('img');
      var coverSrc = coverEl && coverEl.getAttribute('src');
      if (coverSrc) {
        var cover = document.createElement('img');
        cover.className = 'video-cover';
        cover.src = coverSrc;
        // 標題已經在上面唸過，封面對報讀器沒有新資訊
        cover.alt = '';
        cover.setAttribute('aria-hidden', 'true');
        frame.appendChild(cover);
      }
      var go = document.createElement('a');
      go.className = 'video-play';
      go.href = url;
      go.target = '_blank';
      // noopener 是必要的：影片放在外站，開新視窗時別把 window.opener 交出去
      go.rel = 'noopener noreferrer';
      // 只有一個 ▶ 字元，報讀器需要一句話說明它是什麼
      go.setAttribute('aria-label', T.watch);
      go.textContent = '▶';
      frame.appendChild(go);
    } else {
      var play = document.createElement('span');
      play.textContent = '▶';
      play.setAttribute('aria-hidden', 'true');
      frame.appendChild(play);
      var soon = document.createElement('p');
      soon.textContent = T.soon;
      frame.appendChild(soon);
    }

    box.appendChild(x);
    box.appendChild(eyebrow);
    box.appendChild(h2);
    box.appendChild(frame);

    backdrop.appendChild(box);
    mount.appendChild(backdrop);
    x.focus();

    x.addEventListener('click', close);
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

  grid.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var card = e.target.closest('.media-card');
    if (!card || !grid.contains(card)) return;
    e.preventDefault();
    open(card);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop) close();
  });
})();
