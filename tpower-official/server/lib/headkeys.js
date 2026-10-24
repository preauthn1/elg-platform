/* 站方自己貼的 head 程式碼裡，定義了哪些「網站本來就會自動產生」的標籤。
 *
 * 用途：讓自訂的蓋掉自動的，而不是兩個並存。例如這一頁貼了自己的 <title>，網站就不再
 * 輸出自動產生的那個 —— 一頁兩個 <title> 時瀏覽器只吃第一個（自動的那個排在前面），
 * 站方貼的等於沒用，SEO 檢測工具還會報重複。description、og:*、twitter:* 同理。
 *
 * 認得的標籤 → 鍵：
 *   <title>…</title>                    → title
 *   <meta name="description" …>         → description（name 的值，小寫）
 *   <meta property="og:title" …>        → og:title（property 的值，小寫）
 *   <link rel="canonical" …>            → canonical
 *
 * 另外把站方自訂的標題與描述的「文字」取出來，讓 og:title、twitter:title 這些自動標籤
 * 跟著用（站方只貼了 <title> 沒貼 og:title 時，分享預覽才不會還是舊標題）。
 *
 * ⚠ 這支用 Write 工具直接寫入，不要經過 shell 的 heredoc 改它 —— 這個專案的 shell
 * 會吃掉反斜線，下面的 \s 會變成 s，語法照樣合法但意思全錯。
 */

/** 傳入多段 head 程式碼（共用的、這一頁的），後面的優先。 */
export function headOverrides(...codes) {
  const keys = {};
  let title = '';
  let description = '';
  for (const code of codes) {
    const html = String(code || '');
    const t = html.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
    if (t) {
      keys.title = true;
      title = decode(t[1]).trim() || title;
    }
    for (const tag of html.match(/<(?:meta|link)\b[^>]*>/gi) || []) {
      const name = (attr(tag, 'name') || attr(tag, 'property')).toLowerCase();
      if (name) {
        keys[name] = true;
        if (name === 'description') description = decode(attr(tag, 'content')).trim() || description;
      }
      if (attr(tag, 'rel').toLowerCase() === 'canonical') keys.canonical = true;
    }
  }
  return { keys, title, description };
}

/** 取某個屬性的值。雙引號、單引號、沒加引號三種寫法都吃；找不到回空字串。 */
function attr(tag, name) {
  const re = new RegExp('\\s' + name + '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))', 'i');
  const m = tag.match(re);
  return m ? (m[1] ?? m[2] ?? m[3] ?? '') : '';
}

/** 標題與描述會再被樣板輸出一次（og:title 等），先把站方寫的 HTML 實體還原，
 *  不然「A &amp; B」會被二次跳脫成「A &amp;amp; B」。 */
function decode(s) {
  return String(s)
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;|&apos;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&');
}
