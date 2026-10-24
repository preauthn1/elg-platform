/* 首頁「平台核心数据」的跳動計數器。
 *
 * 伺服器每小時才取一次真實值（那個 SUM 很貴），但畫面不能一小時才動一次。所以它回傳
 * 的是每張卡的 base（起點）／step（每跳加多少）／everySeconds（節奏）／since（起算
 * 時刻），這支腳本照牆上時鐘算現在該是第幾跳：
 *
 *     base + step × floor((now − since) / everySeconds)
 *
 * 這是個純函式，只跟牆上時鐘有關 —— 所以同一刻打開的每個訪客看到同一個數字，
 * 重新整理也接得上，後台預覽（admin/js/views/statistic.js）算出來的也是同一個。
 * 三邊的公式必須一起改，改一邊就會對不上。
 *
 * 數字不往回跳：四個指標都是累計值（只增不減），加上單調遞增的時鐘，所以總和不減。
 * 唯一的例外（代理被移除讓代理數變少）由 localStorage 裡的樓地板擋住。
 */
(function () {
  'use strict';

  /* ⚠ 網址要帶內容版號（endpoint() 會接上）。這支端點帶 max-age=60 —— 那是給前台與
     CDN 用的，但沒有版號的話，後台改完跳動秒數之後訪客 F5 也還是舊的 step，
     要等一分鐘或手動清快取才會生效。版號一換就是新網址，舊快取自然失效。 */
  var ENDPOINT = '/api/stats';
  /* ⚠ 改過爬升演算法就要升版號：樓地板記的是曾經顯示過的最高值，舊的會把新的數字壓住不動。 */
  var STORE_KEY = 'y2w_stats_floor_v1';

  var el = document.getElementById('coreStats');
  if (!el) return;

  /** 這一次要打的網址。版號取自 #coreStats 的 data-v（伺服器渲染時寫上）。 */
  function endpoint() {
    var v = el.getAttribute('data-v');
    return v ? ENDPOINT + '?v=' + encodeURIComponent(v) : ENDPOINT;
  }

  var state = null;
  var timer = null;
  var reloadTimer = null;

  // 已顯示過的最高值（跨重新整理也記得），用來擋住往回跳
  function readFloor() {
    try {
      return JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
    } catch (e) {
      return {};
    }
  }
  function writeFloor(f) {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify(f));
    } catch (e) {
      /* 隱私模式可能不給寫，忽略 */
    }
  }

  /** 數字的「等效位數」—— 跟 server/lib/render.js 的 statNumLen() 同一個公式，兩邊要一起改。 */
  function numLen(text) {
    var digits = (text.match(/[0-9]/g) || []).length;
    var marks = (text.match(/[.,]/g) || []).length;
    return Math.round((digits + marks * 0.45) * 100) / 100 || 1;
  }

  function fmt(v, decimals) {
    return Number(v).toLocaleString('en-US', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  /* ---------------- 逐位翻動 ----------------

     原本是整串一起淡入位移 0.06em。問題是末位每五秒就變一次，而那個動畫套在
     整個數字上 —— 三億零兩百萬裡只有末三位真的變了，畫面上卻是十一位一起晃，
     看起來像閃一下，不像在滾。

     改成一位一格：只有真的變了的那幾位會動，而且延遲從右往左遞增，
     所以末位先翻、高位跟著翻，就是老虎機轉輪依序停下來的那種階梯感。

     ⚠ 刻意不用 overflow:hidden 做「轉輪窗口」。.stat-value strong 的 line-height
     是 .88（設計站原稿），比 em 框還矮 —— 開了裁切會把數字上緣切掉，
     而要修就得動那個高度，整張卡的排版跟著位移。改用淡出＋位移，
     在 .3 秒的尺度上讀起來一樣是往上翻，而且完全不動排版。 */

  var ROLL_MS = 300; // 跟 CSS 的 tp-sd-out / tp-sd-in 同步
  var ROLL_STAGGER_MS = 26; // 每往左一位多延遲這麼久
  var reduceMotion =
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /** 把數字拆成一位一個 <span class="sd">。分隔符（逗號、小數點）也各佔一個，
   *  但永遠不會動 —— 位數不變時它們的內容本來就一樣。 */
  function buildDigits(node, text) {
    var html = '';
    for (var i = 0; i < text.length; i++) {
      html += '<span class="sd">' + text.charAt(i) + '</span>';
    }
    node.innerHTML = html;
    node.dataset.num = text;
  }

  /** 單一位翻一次。delay 造出從右往左的階梯。
   *
   *  ⚠ 舊字元（old）由呼叫端傳進來，不從 span.textContent 讀。翻動中的 span 裡同時
   *  有兩層，textContent 是「舊+新」兩個字元 —— 讀它就會把「78」當成舊值再翻一次，
   *  數字愈滾愈長。實測分頁被節流時（背景分頁的 timer 會被夾到 1 秒，收尾的 timeout
   *  就比下一次 render 晚），三億會變成「3,022,78909,38651」這種東西。
   *
   *  ⚠ 兩層一定要用 <span>。用過 <b> —— 而 .stat-value b 是「單位標籤（万+）」的既有
   *  樣式：font-size:14px（手機 10px）、color:var(--red-bright)。於是每一位在翻的那
   *  一刻從 25px 白字縮成 10px 紅字，看起來就是「數字消失了」。同理 <em> 也不能用。 */
  function rollDigit(span, old, ch, delay) {
    span.innerHTML =
      '<span class="sd-out" aria-hidden="true">' +
      old +
      '</span><span class="sd-in">' +
      ch +
      '</span>';
    span.style.setProperty('--sd-delay', delay + 'ms');
    span.className = 'sd is-roll';
    /* 收尾：把兩層收回成單純文字。晚到也沒關係 —— 比對的依據是 node.dataset.num
       而不是 DOM，所以就算下一次翻動先發生，也不會拿到錯的舊字元。 */
    window.setTimeout(function () {
      if (span.className !== 'sd is-roll') return; // 已經被下一次翻動接手了
      span.className = 'sd';
      span.style.removeProperty('--sd-delay');
      span.textContent = ch;
    }, ROLL_MS + delay + 40);
  }

  /** 把 node 的內容更新成 text，只有變動的位數會翻。
   *
   *  ⚠ 上一次畫的是什麼記在 node.dataset.num，不從 DOM 反推。翻動中的 span 裡有兩層，
   *  DOM 的 textContent 在那 0.3 秒內是「舊+新」—— 以它為依據就會產生上面說的汙染。 */
  function paintNumber(node, text) {
    var prev = node.dataset.num || '';
    if (prev === text) return;
    node.dataset.num = text;

    // 第一次，或位數變了（例如跨過一個千分位）→ 重建，不做動畫
    if (prev.length !== text.length || node.children.length !== text.length) {
      buildDigits(node, text);
      return;
    }
    if (reduceMotion) {
      buildDigits(node, text);
      return;
    }
    var spans = node.children;
    // 先找出最右邊那個變動的位置，延遲以它為 0 往左遞增
    var last = -1;
    for (var a = text.length - 1; a >= 0; a--) {
      if (prev.charAt(a) !== text.charAt(a)) {
        last = a;
        break;
      }
    }
    if (last < 0) return;
    for (var i = 0; i <= last; i++) {
      if (prev.charAt(i) === text.charAt(i)) continue;
      rollDigit(spans[i], prev.charAt(i), text.charAt(i), (last - i) * ROLL_STAGGER_MS);
    }
  }

  function render() {
    if (!state) return;
    var floor = readFloor();
    var now = Date.now();
    var dirty = false;

    state.rows.forEach(function (row, i) {
      // 只改數字本體。千萬不要對 .stat-value 容器設 textContent ——
      // 那會把裡面的 <strong>（數字）與 <b>（單位）一起清掉。
      var node = el.querySelector('[data-stat="' + i + '"] .stat-value-num');
      if (!node) return;

      // 離散步進：算出從起算時間到現在跳了幾次
      var elapsed = Math.max(0, (now - new Date(row.since).getTime()) / 1000);
      var every = Math.max(1, row.everySeconds || 15);
      var ticks = Math.floor(elapsed / every);
      var target = row.base + (row.step || 0) * ticks;

      // 後台把倍率調小、或真值被修正時，base 會明顯下降 —— 這是刻意的改動，
      // 不該被舊的樓地板卡住，所以重設樓地板。
      var prevFloor = floor[row.source];
      if (typeof prevFloor === 'number' && row.base < prevFloor * 0.98) prevFloor = undefined;
      var shown = typeof prevFloor === 'number' ? Math.max(prevFloor, target) : target;

      if (shown !== prevFloor) {
        floor[row.source] = shown;
        dirty = true;
      }
      /* 單位縮放：payload 的 base/step 是中文計數單位（万+/亿+）的量級，
         所有語系共用同一份。非中文語系在頁面上帶了 data-unit-scale，
         換算只做在格式化這一步 —— 樓地板仍然存 payload 單位，換語系不會互相污染。 */
      var card = node.closest ? node.closest("[data-stat]") : null;
      var scale = card ? parseFloat(card.getAttribute("data-unit-scale")) : 1;
      if (!isFinite(scale) || scale <= 0) scale = 1;
      var dec = card ? parseInt(card.getAttribute("data-unit-decimals"), 10) : NaN;
      if (!isFinite(dec)) dec = row.decimals;
      var text = fmt(shown * scale, dec);
      if (card && node.dataset.num !== text) card.style.setProperty('--num-len', String(numLen(text)));
      paintNumber(node, text);
      /* 說明文案裡的那個小數字也要一起動。不更新的話大數字每 15 秒跳、下面那句
         永遠停在開頁那一刻的值，兩個數字愈差愈多，一眼就看得出是假的。 */
      var descNum = card ? card.querySelector('.stat-desc-num') : null;
      if (descNum) paintNumber(descNum, text);
    });

    if (dirty) writeFloor(floor);
  }

  function schedule() {
    if (timer) clearInterval(timer);
    // 每秒檢查一次是否到了各卡的跳動時點；真正換文字的頻率由 everySeconds 決定
    timer = setInterval(render, 1000);

    if (reloadTimer) clearInterval(reloadTimer);
    var every = Math.max(60, state.refreshSeconds || 600) * 1000;
    reloadTimer = setInterval(function () {
      if (!document.hidden) load();
    }, every);
  }

  function load() {
    fetch(endpoint(), { headers: { accept: 'application/json' } })
      .then(function (r) {
        return r.ok ? r.json() : null;
      })
      .then(function (body) {
        if (!body || !body.ok || !body.data || !body.data.rows) return;
        state = body.data;
        render();
        schedule();
      })
      .catch(function () {
        /* 取數失敗就維持靜態 HTML 裡渲染好的數字，不動它 */
      });
  }

  // 分頁切到背景時不必一直重繪，切回來立刻補算一次（避免看到停在舊值）
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (timer) clearInterval(timer);
      timer = null;
    } else if (state) {
      render();
      schedule();
    }
  });

  load();
})();
