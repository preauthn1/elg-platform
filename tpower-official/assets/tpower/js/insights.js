/* 品牌資訊列表的分類頁籤與搜尋 —— 設計站是 React state，靜態站用這一小段還原：
   分類走卡片的 data-cat（空字串＝全部），搜尋是標題與摘要的子字串比對。
   兩個條件是「且」的關係，跟設計站一致。
   隱藏用 hidden 屬性（site.css 已有 [hidden]{display:none!important}），
   .insights-grid 的 grid 不會留下空格。 */
(function () {
  'use strict';

  var section = document.querySelector('.insights-latest');
  if (!section) return;

  var tabsBox = section.querySelector('.insights-tabs');
  var input = section.querySelector('.insights-search input');
  var cards = [].slice.call(section.querySelectorAll('.insight-card'));
  if (!cards.length) return;

  var tabs = tabsBox ? [].slice.call(tabsBox.querySelectorAll('button[data-cat]')) : [];
  var cat = '';

  // 卡片的可搜尋文字只算一次（標題＋摘要）
  var haystacks = cards.map(function (c) {
    var h = c.querySelector('h3');
    var p = c.querySelector('p');
    return ((h ? h.textContent : '') + ' ' + (p ? p.textContent : '')).toLowerCase();
  });

  function apply() {
    var q = String(input ? input.value : '').trim().toLowerCase();
    cards.forEach(function (c, i) {
      var okCat = !cat || c.getAttribute('data-cat') === cat;
      var okText = !q || haystacks[i].indexOf(q) >= 0;
      if (okCat && okText) c.removeAttribute('hidden');
      else c.setAttribute('hidden', '');
    });
  }

  if (tabsBox) {
    tabsBox.addEventListener('click', function (e) {
      if (!e.target.closest) return;
      var btn = e.target.closest('button[data-cat]');
      if (!btn || !tabsBox.contains(btn)) return;
      e.preventDefault();
      cat = btn.getAttribute('data-cat') || '';
      tabs.forEach(function (t) {
        var on = t === btn;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', String(on));
      });
      apply();
    });
  }

  if (input) {
    input.addEventListener('input', apply);
    // 避免搜尋框被當成表單送出（設計站的輸入框不在 form 裡，這裡只是保險）
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') e.preventDefault();
    });
  }
})();

/* 文章彈窗。設計站點卡片是開 .modal-backdrop > .modal 不是換頁，樣式 site.css
   原檔就有，所以這裡只補 DOM 與行為：點卡片開、Esc 關、底部「关闭内容」關、
   點彈窗內容不關、點背景**不關**（設計站就是這樣，不是漏掉）。
   兩處比設計站多做：卡片保留 href（沒有 JS 時連結照樣能用，內頁可分享可索引），
   以及焦點管理（宣告了 aria-modal 卻讓 Tab 跑出去，鍵盤使用者會迷路）。 */
(function () {
  'use strict';

  var grid = document.querySelector('.insights-grid');
  /* 最新焦點是同一篇文章的另一個版面（h2 + .insights-featured-copy），
     行為要跟下方卡片一致，所以一起掛。 */
  var featured = document.querySelector('.insights-featured');
  // 掛在 .site-app：<main> 有 isolation:isolate 自成堆疊脈絡，
  // backdrop 放在裡面壓不過 z-index:80 的 sticky header。
  var mount = document.querySelector('.site-app') || document.body;
  if (!grid && !featured) return;

  // 文字掛在 .insights-grid 上；只有最新焦點沒有列表時 grid 不存在，取空字串
  function txt(name) { return (grid && grid.getAttribute(name)) || ''; }
  var T = {
    eyebrow: txt('data-modal-eyebrow'),
    closeLabel: txt('data-modal-close-label'),
    close: txt('data-modal-close'),
  };
  var backdrop = null;
  var lastCard = null;

  function close() {
    if (!backdrop) return;
    backdrop.remove();
    backdrop = null;
    if (lastCard) { lastCard.focus(); lastCard = null; }
  }

  /* 內文是扁平的 <p>導言</p><h3>小標</h3><p>內文</p>…（富文本白名單只有 p/h3，
     沒有 section 也沒有 class）。設計站的版面是「導言 + 每個 h3 各自一個 section」，
     所以在這裡把扁平結構組回去，而不是把版面塞進資料層。 */
  function buildBody(tpl) {
    var nodes = [].slice.call(tpl.content.childNodes);
    var out = [];
    var lead = null;
    var current = null;
    nodes.forEach(function (n) {
      if (n.nodeType !== 1) return;
      var tag = n.tagName.toLowerCase();
      if (tag === 'h3') {
        current = document.createElement('section');
        current.appendChild(n.cloneNode(true));
        out.push(current);
        return;
      }
      if (!lead && tag === 'p' && !current) {
        lead = n.cloneNode(true);
        lead.className = 'insight-modal-lead';
        return;
      }
      (current || { appendChild: function (x) { out.push(x); } }).appendChild(n.cloneNode(true));
    });
    return { lead: lead, sections: out };
  }

  function open(card) {
    close();
    lastCard = card;
    /* 兩種版面共用：列表卡是 h3 + section small，最新焦點是 h2 +
       .insights-featured-copy 裡的 small。用寬鬆的選擇器兩邊都吃得到。 */
    var title = (card.querySelector('h2, h3') || {}).textContent || '';
    var badge = card.querySelector('div > span');
    var label = card.querySelector('small');
    var cover = card.querySelector('img');
    var tpl = card.querySelector('template.insight-body');

    backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.setAttribute('role', 'presentation');

    var box = document.createElement('div');
    box.className = 'modal';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', title);

    var x = document.createElement('button');
    x.className = 'modal-close';
    x.setAttribute('aria-label', T.close);
    x.textContent = '×';

    var eyebrow = document.createElement('span');
    eyebrow.className = 'eyebrow';
    eyebrow.textContent = T.eyebrow;

    var h2 = document.createElement('h2');
    h2.textContent = title;

    var article = document.createElement('article');
    article.className = 'insight-modal-content';

    if (badge || label) {
      var meta = document.createElement('div');
      meta.className = 'insight-modal-meta';
      if (badge) { var bs = document.createElement('span'); bs.textContent = badge.textContent; meta.appendChild(bs); }
      if (label) { var ls = document.createElement('small'); ls.textContent = label.textContent; meta.appendChild(ls); }
      article.appendChild(meta);
    }
    if (cover) {
      var img = document.createElement('img');
      img.src = cover.getAttribute('src');
      img.alt = title;
      img.setAttribute('decoding', 'async');
      article.appendChild(img);
    }
    if (tpl) {
      var b = buildBody(tpl);
      if (b.lead) article.appendChild(b.lead);
      b.sections.forEach(function (s) { article.appendChild(s); });
    }
    var bottom = document.createElement('button');
    bottom.type = 'button';
    bottom.className = 'insight-modal-close-bottom';
    bottom.textContent = T.closeLabel;
    article.appendChild(bottom);

    box.appendChild(x);
    box.appendChild(eyebrow);
    box.appendChild(h2);
    box.appendChild(article);
    backdrop.appendChild(box);
    mount.appendChild(backdrop);
    x.focus();

    x.addEventListener('click', close);
    bottom.addEventListener('click', close);
    box.addEventListener('click', function (e) { e.stopPropagation(); });

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

  function onCardClick(e) {
    if (!e.target.closest) return;
    var card = e.target.closest('.insight-card, .insights-featured');
    if (!card) return;
    // 沒有內文就別攔截，讓它照常跳內頁（總比開一個空彈窗好）
    if (!card.querySelector('template.insight-body')) return;
    e.preventDefault();
    open(card);
  }
  if (grid) grid.addEventListener('click', onCardClick);
  if (featured) featured.addEventListener('click', onCardClick);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop) close();
  });
})();
