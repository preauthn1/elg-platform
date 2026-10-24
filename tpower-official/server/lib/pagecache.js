/* 公开页的记忆体快取。两个失效条件：内容版本号变动（存档）、TTL 到期。
   TTL 同时是「时间驱动内容」的最大延迟 —— 活动 14:00 到期，最晚 14:01 从列表消失。

   刻意不做「哪个类别影响哪些页」的对照表：settings / seo / site_tagline 一改就是
   全部 92 页，对照表多数情况等于全清，却要多维护一份迟早跟 render.js 分岔的知识。 */
import { config } from './config.js';
import { contentVersion } from './store.js';

const TTL_MS = config.pageCacheSeconds * 1000;
// 碰到上限代表有预期外的 key 在灌进来，整批丢掉最安全（LRU 要另外记存取时间，不值得）
const MAX_ENTRIES = 400;

const entries = new Map(); // key → { html, version, expires }
const pending = new Map(); // key → Promise<string>，同一页同时 miss 只渲染一次

/** 取快取或渲染。producer 回传 string 或 Promise<string>；丢出的错误不会被快取。 */
export async function cachedHtml(key, producer) {
  if (TTL_MS <= 0) return producer();

  const hit = entries.get(key);
  if (hit && hit.version === contentVersion() && hit.expires > Date.now()) return hit.html;

  const inflight = pending.get(key);
  if (inflight) return inflight;

  // version 要在渲染「之前」取：渲染期间有人存档的话，这份输出是旧内容渲出来的，
  // 记上旧版本号才会在下一次读取时判定过期。记成渲染后的版本号会让它多活一整个 TTL。
  const version = contentVersion();
  const job = (async () => producer())()
    .then((html) => {
      entries.set(key, { html, version, expires: Date.now() + TTL_MS });
      if (entries.size > MAX_ENTRIES) entries.clear();
      return html;
    })
    .finally(() => pending.delete(key));

  pending.set(key, job);
  return job;
}
