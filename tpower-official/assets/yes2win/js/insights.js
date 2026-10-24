/* 品牌資訊列表的分類分頁與搜尋（分類走 data-cat，空字串＝全部；兩個條件是「且」）。 */
(function () {
  'use strict';

  var section = document.querySelector('.insights-latest');
  if (!section) return;

  var tabsBox = section.querySelector('.insights-tabs');
  var input = section.querySelector('.insights-search input');
  var empty = section.querySelector('.insights-empty');
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
    var shown = 0;
    cards.forEach(function (c, i) {
      var okCat = !cat || c.getAttribute('data-cat') === cat;
      var okText = !q || haystacks[i].indexOf(q) >= 0;
      if (okCat && okText) { c.removeAttribute('hidden'); shown++; }
      else c.setAttribute('hidden', '');
    });
    if (empty) empty.hidden = shown > 0;
  }

  function select(btn, focus) {
    cat = btn.getAttribute('data-cat') || '';
    tabs.forEach(function (t) {
      var on = t === btn;
      t.classList.toggle('active', on);
      t.setAttribute('aria-selected', String(on));
      // roving tabindex：Tab 只停在選中的分類，分類之間用左右鍵
      t.setAttribute('tabindex', on ? '0' : '-1');
    });
    if (focus) btn.focus();
    if (tabsBox.scrollWidth > tabsBox.clientWidth) {
      var l = btn.offsetLeft - tabsBox.offsetLeft;
      if (l < tabsBox.scrollLeft || l + btn.offsetWidth > tabsBox.scrollLeft + tabsBox.clientWidth) {
        tabsBox.scrollLeft = l - (tabsBox.clientWidth - btn.offsetWidth) / 2;
      }
    }
    apply();
  }

  if (tabsBox) {
    tabsBox.addEventListener('click', function (e) {
      if (!e.target.closest) return;
      var btn = e.target.closest('button[data-cat]');
      if (!btn || !tabsBox.contains(btn)) return;
      e.preventDefault();
      select(btn, false);
    });
    tabsBox.addEventListener('keydown', function (e) {
      var i = tabs.indexOf(document.activeElement);
      if (i < 0) return;
      var next = -1;
      if (e.key === 'ArrowRight') next = (i + 1) % tabs.length;
      else if (e.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
      else if (e.key === 'Home') next = 0;
      else if (e.key === 'End') next = tabs.length - 1;
      if (next < 0) return;
      e.preventDefault();
      select(tabs[next], true);
    });
  }

  if (input) {
    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') e.preventDefault();
    });
  }
})();

/* 文章彈窗：點背景不關（設計站就是這樣）。卡片保留 href：沒有 JS 時照樣進獨立頁；有內文才攔截。
   樣式在 assets/css/pages/blog_compliance.css，獨立頁 article.njk 共用同一組 class。 */
(function () {
  'use strict';

  var grid = document.querySelector('.insights-grid');
  // 最新焦點是同一篇文章的另一個版面（h2），行為要跟下方卡片一致
  var featured = document.querySelector('.insights-featured');
  // 掛在 .site-app：<main> 有 isolation:isolate 自成堆疊脈絡，放在裡面壓不過 sticky 頁首
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
  var seq = 0;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function close() {
    if (!backdrop) return;
    backdrop.remove();
    backdrop = null;
    if (lastCard) { lastCard.focus(); lastCard = null; }
  }

  /* 內文是扁平的 p/h3，版面是「導言 + 每個 h3 各自一個 section」，在這裡組回去。 */
  function buildBody(tpl) {
    var nodes = [].slice.call(tpl.content.childNodes);
    var out = [];
    var lead = null;
    var current = null;
    nodes.forEach(function (n) {
      if (n.nodeType !== 1) return;
      var tag = n.tagName.toLowerCase();
      if (tag === 'h3') {
        current = el('section');
        current.appendChild(n.cloneNode(true));
        out.push(current);
        return;
      }
      if (!lead && tag === 'p' && !current) {
        lead = n.cloneNode(true);
        lead.className = 'insight-modal-lead';
        return;
      }
      if (current) current.appendChild(n.cloneNode(true));
      else out.push(n.cloneNode(true));
    });
    return { lead: lead, sections: out };
  }

  function open(card) {
    close();
    lastCard = card;
    var title = ((card.querySelector('h2, h3') || {}).textContent || '').trim();
    var badge = card.querySelector('.insight-badge');
    var label = card.querySelector('.insight-label');
    var cover = card.querySelector('img');
    var tpl = card.querySelector('template.insight-body');
    var titleId = 'insight-modal-title-' + (++seq);

    backdrop = el('div', 'modal-backdrop insight-backdrop');
    backdrop.setAttribute('role', 'presentation');

    var box = el('div', 'modal insight-modal');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', titleId);

    var x = el('button', 'modal-close', '×');
    x.type = 'button';
    x.setAttribute('aria-label', T.close);

    var head = el('header', 'insight-modal-head');
    head.appendChild(el('span', 'eyebrow', T.eyebrow));
    var bar = el('div', 'insight-modal-titlebar');
    var h2 = el('h2', null, title);
    h2.id = titleId;
    bar.appendChild(h2);
    if (badge || label) {
      var meta = el('div', 'insight-modal-meta');
      if (badge) meta.appendChild(el('span', 'insight-badge', badge.textContent.trim()));
      if (label) meta.appendChild(el('small', 'insight-label', label.textContent.trim()));
      bar.appendChild(meta);
    }
    head.appendChild(bar);

    var article = el('article', 'insight-modal-content');
    if (cover) {
      var img = el('img', 'insight-modal-cover');
      img.src = cover.currentSrc || cover.getAttribute('src');
      img.alt = title;
      img.setAttribute('decoding', 'async');
      article.appendChild(img);
    }
    if (tpl) {
      var b = buildBody(tpl);
      if (b.lead) article.appendChild(b.lead);
      b.sections.forEach(function (s) { article.appendChild(s); });
    }
    var bottom = el('button', 'insight-modal-close-bottom', T.closeLabel);
    bottom.type = 'button';
    article.appendChild(bottom);

    box.appendChild(x);
    box.appendChild(head);
    box.appendChild(article);
    backdrop.appendChild(box);
    mount.appendChild(backdrop);
    x.focus();

    x.addEventListener('click', close);
    bottom.addEventListener('click', close);
    box.addEventListener('click', function (e) { e.stopPropagation(); });
    backdrop.addEventListener('click', close);

    box.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var f = [].filter.call(
        box.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
        function (n) { return n.offsetParent !== null; } // 桌機版的底部按鈕是 display:none，跳過
      );
      if (!f.length) return;
      var first = f[0];
      var last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  function onCardClick(e) {
    if (!e.target.closest) return;
    // 讓使用者照常用 Ctrl／⌘／Shift／中鍵在新分頁開獨立頁
    if (e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var card = e.target.closest('.insight-card, .insights-featured');
    if (!card) return;
    // 沒有內文就別攔截，讓它照常跳獨立頁
    if (!card.querySelector('template.insight-body')) return;
    e.preventDefault();
    open(card);
  }
  if (grid) grid.addEventListener('click', onCardClick);
  if (featured) featured.addEventListener('click', onCardClick);

  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Escape' || e.key === 'Esc') && backdrop) close();
  });
})();
