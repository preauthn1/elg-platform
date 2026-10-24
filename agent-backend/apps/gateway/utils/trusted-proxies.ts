import { BlockList } from 'node:net';

import trustedProxiesData from './trusted-proxies.json' with { type: 'json' };

/**
 * 三方 CDN / LB / WAF 信任代理清單與 X-Forwarded-For 解析工具。
 *
 * 直接對齊 gsrc/WEB_AgentBackend/_release/trusted_proxies.php 的同名邏輯
 * （target/tickets#4187）——同一套信任鏈規則，PHP／Node 各自維護一份演算法
 * 實作，但基礎 CIDR 資料現在共用同一份來源：`trusted-proxies.json` 由
 * target/gffv3/apis 的 `trusted-proxies:sync` 指令產生（即時抓取 Cloudflare
 * 官方清單 + Mlytics WAF + Google LB／私網保留網段常數），人工複製到這裡
 * ——避免三個 repo 各自維護一份清單造成 drift。要更新清單時，去 apis 重新
 * 跑一次該指令，把輸出的 `storage/app/trusted-proxies.json` 覆蓋過來這裡
 * 跟 gsrc/WEB_AgentBackend/_release/trusted-proxies.json，不要手動編輯
 * 這份 JSON。
 *
 * 設計原則：只有當 REMOTE_ADDR（gateway 這裡是 socket 的實際連線來源）
 * 落在已知可信任的三方代理清單內，才進一步解析 X-Forwarded-For；否則
 * 直接使用 REMOTE_ADDR，避免公網 client 直接偽造 X-Forwarded-For 竄改 IP。
 */

interface TrustedProxiesData {
  cidrs: Record<string, string[]>;
}

let cachedTrustedBlockList: BlockList | null = null;
let cachedExtraCidrsKey: string | undefined;

function parseExtraCidrs(): string[] {
  const raw = process.env.TRUSTED_PROXY_EXTRA_CIDRS ?? '';
  return raw
    .split(',')
    .map((cidr) => cidr.trim())
    .filter((cidr) => cidr !== '');
}

function addCidrsToBlockList(blockList: BlockList, cidrs: string[]): void {
  for (const cidr of cidrs) {
    const [subnet, prefixRaw] = cidr.split('/');
    if (!subnet || prefixRaw === undefined) continue;
    const prefix = Number.parseInt(prefixRaw, 10);
    const family = subnet.includes(':') ? 'ipv6' : 'ipv4';
    try {
      blockList.addSubnet(subnet, prefix, family);
    } catch {
      // 個別 CIDR 格式有誤時跳過，不讓整份清單失效
    }
  }
}

/** 取得 trusted-proxies.json 裡的基礎 CIDR 清單，檔案缺失/格式錯誤時回空陣列（fail-closed）。 */
function loadBaseCidrs(): string[] {
  const cidrs = (trustedProxiesData as TrustedProxiesData)?.cidrs;
  if (!cidrs || typeof cidrs !== 'object') return [];
  return Object.values(cidrs).flat();
}

/**
 * 取得可信任三方代理清單建成的 BlockList（trusted-proxies.json 的基礎清單
 * + 品牌專屬 TRUSTED_PROXY_EXTRA_CIDRS）。依 TRUSTED_PROXY_EXTRA_CIDRS
 * 內容快取，避免每個請求都重建。
 */
function getTrustedBlockList(): BlockList {
  const extraCidrs = parseExtraCidrs();
  const cacheKey = extraCidrs.join(',');
  if (cachedTrustedBlockList && cachedExtraCidrsKey === cacheKey) {
    return cachedTrustedBlockList;
  }

  const blockList = new BlockList();
  addCidrsToBlockList(blockList, [...loadBaseCidrs(), ...extraCidrs]);

  cachedTrustedBlockList = blockList;
  cachedExtraCidrsKey = cacheKey;
  return blockList;
}

/** 把 IPv4-mapped IPv6（::ffff:a.b.c.d）正規化為 IPv4 格式再比對。 */
function normalizeIp(ip: string): string {
  if (ip.toLowerCase().startsWith('::ffff:')) {
    const mapped = ip.slice(7);
    if (/^\d{1,3}(\.\d{1,3}){3}$/.test(mapped)) {
      return mapped;
    }
  }
  return ip;
}

/** 判斷是否為可信任來源：落在三方 CDN/LB 信任清單或私網/保留網段內。 */
export function isTrustedProxySource(ip: string): boolean {
  if (!ip) return false;
  const normalized = normalizeIp(ip);
  const family = normalized.includes(':') ? 'ipv6' : 'ipv4';
  try {
    return getTrustedBlockList().check(normalized, family);
  } catch {
    return false;
  }
}

/**
 * 解析真正的 client IP。直接對齊 PHP 端 resolveClientIp() 的規則：
 * - 若 remoteAddr（真實 TCP 連線來源）不在信任清單內，代表 client 直連
 *   或經過未知代理，直接回傳 remoteAddr，不採信 X-Forwarded-For。
 * - 若 remoteAddr 在信任清單內（已知的 CDN/LB/WAF），才往 X-Forwarded-For
 *   裡找：從最右邊往左掃描，跳過所有落在信任清單內的節點，回傳第一個
 *   「不在信任清單內」的 IP。
 * - skipLastHop：Google Cloud LB 一定是流量打進來前的最後一跳，會把自己
 *   的 VIP/frontend IP 附加在 X-Forwarded-For 最後一個節點。那些 VIP 現在
 *   已逐一枚舉在清單的 `our_lb_vips` 分組（target/op_tickets#3478 以
 *   `gcloud compute forwarding-rules list` 掃過全部 GCP 專案取得，不是從
 *   log 取樣），所以上面那條掃描本來就會跳過它們，本旗標退為雙保險——只有
 *   清單漏列新建的 LB 時才真正起作用。仍由呼叫端依各品牌實際拓樸明確傳入，
 *   不在此處自動判斷：這個假設只對「GKE + Google Cloud LB」拓樸成立，
 *   其他拓樸啟用可能讓攻擊者偽造的值被誤判為真實 IP。
 * - 若整串 X-Forwarded-For 的 IP 都在信任清單內，fallback 回傳最左邊的 IP。
 */
export function resolveClientIp(
  remoteAddr: string,
  xff: string | undefined,
  skipLastHop = false,
): string {
  if (!isTrustedProxySource(remoteAddr)) {
    return remoteAddr;
  }

  if (!xff || xff.trim() === '') {
    return remoteAddr;
  }

  const chain = xff
    .split(',')
    .map((ip) => ip.trim())
    .filter((ip) => ip !== '');

  if (chain.length === 0) {
    return remoteAddr;
  }

  if (skipLastHop && chain.length > 1) {
    chain.pop();
  }

  for (let i = chain.length - 1; i >= 0; i--) {
    if (!isTrustedProxySource(chain[i])) {
      return chain[i];
    }
  }

  return chain[0];
}
