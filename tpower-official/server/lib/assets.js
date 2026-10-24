/* 靜態資產的網址 —— 在後面接一個內容版號：/assets/css/site.css?v=a1b2c3d4
 *
 * 為什麼需要：那幾個檔名是固定的（site.css、stats.js…），只靠 max-age 過期。
 * 於是改版推上去之後，回訪的訪客會拿到「新的 HTML 配舊的樣式與舊的腳本」，
 * 而且沒有任何辦法通知他們 —— 實測 Cloudflare 把我們的 1 小時改成 4 小時，
 * 站方只能請人「清快取」。版號一變就是新網址，任何一層快取（瀏覽器、CDN、
 * 公司的代理）都會當成沒看過的東西重抓，不必通知、也不必 purge。
 *
 * 版號是檔案內容的 sha1 前 8 碼，不是 mtime —— 部署是 git clone／git pull，
 * 那會把所有檔案的 mtime 都換掉，用 mtime 的話每次部署全部失效、訪客白白重抓一次。
 * 內容沒變版號就不變，所以只改了一支 JS 的部署，其餘檔案的快取仍然有效。
 *
 * 讀檔不會拖慢渲染：memo 的鍵是 mtime + 大小（stat 只碰 inode，很便宜），
 * 對得上就直接回上次算好的網址，所以一個檔案的內容只會被讀一次。
 *
 * ⚠ 這是專案裡少數會碰檔案系統的地方，所以獨立成一支 —— render.js 不碰檔案系統。
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { config } from './config.js';

// 網址 → { key, url }。key 不同才重算，所以正常情況一個檔案只算一次。
const cache = new Map();

/** /assets/... → /assets/...?v=<8 碼>。算不出來就原樣回傳。 */
export function assetUrl(urlPath) {
  if (typeof urlPath !== 'string' || !urlPath.startsWith('/assets/')) return urlPath;

  const rel = urlPath.slice('/assets/'.length);
  // 樣板傳進來的都是寫死的路徑，擋 .. 只是預防哪天有人把外部輸入接進來
  if (!rel || rel.includes('..')) return urlPath;

  let st;
  try {
    st = fs.statSync(path.join(config.dirs.assets, rel));
  } catch {
    // 檔案不在就送原網址 —— 少一個版號而已，不值得讓整頁掛掉
    return urlPath;
  }

  const key = `${st.mtimeMs}:${st.size}`;
  const hit = cache.get(urlPath);
  if (hit && hit.key === key) return hit.url;

  const v = crypto
    .createHash('sha1')
    .update(fs.readFileSync(path.join(config.dirs.assets, rel)))
    .digest('hex')
    .slice(0, 8);
  const url = `${urlPath}?v=${v}`;
  cache.set(urlPath, { key, url });
  return url;
}
