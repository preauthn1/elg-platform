/* 常見問題手風琴：同時只展開一則。展開狀態 = article.open（CSS 做動畫與 chevron 旋轉），這裡只切 class 與 aria-expanded。
   ⚠ 不要改 button 裡的內容 —— 旋轉交給 CSS。 */
(function () {
  'use strict';

  var list = document.querySelector('.faq-list');
  if (!list) return;

  var items = [].slice.call(list.children).filter(function (el) {
    return el.tagName === 'ARTICLE';
  });

  function paint() {
    items.forEach(function (art) {
      var btn = art.querySelector('button[aria-controls]');
      if (btn) btn.setAttribute('aria-expanded', String(art.classList.contains('open')));
    });
  }

  list.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('button[aria-controls]');
    if (!btn || !list.contains(btn)) return;
    var art = btn.closest('article');
    if (!art) return;
    var wasOpen = art.classList.contains('open');
    // 第一次點才打開問題列的高度動畫（見 faq.css .is-animated）
    list.classList.add('is-animated');
    items.forEach(function (x) {
      x.classList.remove('open');
    });
    if (!wasOpen) art.classList.add('open');
    paint();
  });

  paint();
})();
