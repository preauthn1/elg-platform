/* 常見問題手風琴 —— 設計站是 React state，靜態站用這一小段還原：
   同時只展開一則；展開的那則 <b> 顯示 −，其餘顯示 +。
   初始狀態（第一則展開）由樣板輸出，這裡只負責點擊後的切換。 */
(function () {
  'use strict';

  var list = document.querySelector('.faq-list');
  if (!list) return;

  var items = [].slice.call(list.children);

  function paint() {
    items.forEach(function (art) {
      var btn = art.querySelector('button');
      if (!btn) return;
      var open = art.classList.contains('open');
      btn.setAttribute('aria-expanded', String(open));
      var mark = btn.querySelector('b');
      if (mark) mark.textContent = open ? '−' : '+';
    });
  }

  list.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var btn = e.target.closest('.faq-list > article > button');
    if (!btn) return;
    var art = btn.parentNode;
    var wasOpen = art.classList.contains('open');
    items.forEach(function (x) {
      x.classList.remove('open');
    });
    if (!wasOpen) art.classList.add('open');
    paint();
  });
})();
