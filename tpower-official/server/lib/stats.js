/* 即时数据取数层 —— 呼叫 API 专案的 platform-statistic 端点取四个累计数字
   （端点在 apis 专案的 platform-statistic）。

   快取 1 小时（累計活動獎金是全历史 SUM，很贵）。但前台不会每小时才动一次 ——
   每次取到新真值都会存进快照历史，用「上一周期的实际成长」算出每秒爬升速率，
   前台照墙上时钟平滑往上加。所以画面是连续在跳，换真值时也不会突然跳一大格。 */
import crypto from 'node:crypto';
import { readEntity, readSystem, writeSystem } from './store.js';
import { STAT_SOURCES } from '../schema/content.js';

// 真值快取 1 小时：累計活動獎金是全历史 SUM，很贵，不能每次请求都跑
const CACHE_TTL_MS = 3600 * 1000;
// 取数快照存在 content/system.json 的 stats_history 键（见 store.js）
const HISTORY_KEEP = 48;

let cache = { at: 0, data: null };

/* 取数的五个设定都在 content/system.json 的 api 键（跟帐密同一个档，等同机密、不进版控），
   后台「系统设置」那一页管（仅 admin：登入 → admin 角色 → 公司 IP）：
     "api": { "baseUrl": "...", "aesKey": "...", "aesIv": "...", "aesMd5": "...", "brandDomain": "..." }
   ⚠ 这几个值会原样回给浏览器（站方要求可以直接看到、直接改）—— 挡在前面的是那三道锁，
   不是遮罩。稽核纪录只记「改了哪几栏」，不记值。
   改了不必重启：system.json 看 mtime，下一次取数就是新值。 */
function apiConfig() {
  return readSystem('api', {}) || {};
}

/** API 专案的请求外壳：body 是 AES-256-CBC 加密的 params + key=md5(params+MD5_SECRET)。
 *  这是那边所有 frontend 端点的既有规范，所以只能 server-to-server 呼叫，
 *  秘密绝对不能出现在浏览器。 */
function buildEncryptedBody(payload) {
  const { aesKey: key = '', aesIv: iv = '', aesMd5: md5Secret = '' } = apiConfig();
  if (!key || !iv || !md5Secret) {
    throw new Error('system.json 的 api.aesKey / api.aesIv / api.aesMd5 未设定');
  }
  if (Buffer.byteLength(iv) !== 16) {
    throw new Error(`api.aesIv 必须是 16 bytes（目前 ${Buffer.byteLength(iv)}）`);
  }

  // ⚠ 真正的 key 是 md5(aesKey) 的 32 字 hex，不是 aesKey 本身
  //（API 专案的 AesService 先 md5 再喂给 openssl_encrypt，刚好凑满 32 bytes）。
  // aesKey 填该专案 .env 里 AES_KEY 的原值即可，md5 由这里算。
  const aesKey = crypto.createHash('md5').update(key).digest('hex');

  const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(aesKey), Buffer.from(iv));
  const params = Buffer.concat([cipher.update(JSON.stringify(payload), 'utf8'), cipher.final()]).toString('base64');
  // key 栏位＝md5(params + AES_MD5)，比对在 API 专案的 Frontend\DecryptRequest middleware
  return { params, key: crypto.createHash('md5').update(params + md5Secret).digest('hex') };
}

async function fetchFromApi() {
  const base = apiConfig().baseUrl;
  if (!base) throw new Error('system.json 的 api.baseUrl 未设定');
  // 品牌网域跟 API 网址、三个密钥同一个地方（system.json 的 api 键）—— 后台「系统设置」那一页
  const domain = apiConfig().brandDomain || '';
  if (!domain) throw new Error('后台「系统设置」还没填品牌网域');

  const resp = await fetch(`${base.replace(/\/+$/, '')}/frontend/api/platform-statistic`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(buildEncryptedBody({ domain })),
    signal: AbortSignal.timeout(8000),
  });
  if (!resp.ok) throw new Error(`API 回应 ${resp.status}`);
  const body = await resp.json();
  // ⚠ 成功码是 200 —— API 专案的 ApiResponse::success() 写死成 200。
  // 1000 只是 message key 不是回应 code，判错的话端点做出来也永远取不到数。
  const OK_CODES = [200, 1000, 0];
  if (!OK_CODES.includes(body.code)) {
    throw new Error(`API 回传 code=${body.code} ${body.message || ''}`);
  }
  const d = body.data || {};
  return {
    註冊會員數: Number(d.memberCount ?? 0),
    代理數: Number(d.agentCount ?? 0),
    累計代理佣金: Number(d.totalCommission ?? 0),
    累計活動獎金: Number(d.totalEventBonus ?? 0),
  };
}

// ---------- 快照历史 ----------
// readSystem 回的是深拷贝，所以 pushSnapshot 可以就地改
function readHistory() {
  const h = readSystem('stats_history', null);
  const base = h && Array.isArray(h.snapshots) ? h : { snapshots: [] };
  if (!base.anchors || typeof base.anchors !== 'object') base.anchors = {};
  return base;
}

/* 数字往上爬的起点，逐指标记在 stats_history.anchors：{ [source]: { value, at } }。
   前台算的是「起点 + 每跳的量 × 从起点到现在跳了几次」，所以起点一动，整条爬升就重算。

   ⚠ 为什么非得存起来：起点如果每次取真实值就重设（第一版就是这样），爬升量每小时归零
   —— 而前台有「防倒退的楼地板」不让数字掉下去，于是数字被压住，要再爬满一小时才会重新
   开始动。实测就是「跳一小时、卡一小时」交替，站方看到的是三张卡完全不动。

   现在起点只在「真实成长反超了爬升」时才往上抬，平常一直沿用 —— 爬升因此连续，
   而且平常根本不用写档。

   ⚠ 代价：显示值会持续爬到真实值上方，爬多快由后台的「每天成长 %」决定。那是这个功能
   的本质 —— 要「一直在跳」又要「贴着真实值」在数学上不可能同时成立，因为真实值一小时
   才动一次。站方调那个百分比就是在调这件事。

   ⚠ 逃生口：倍率被调小（或指标真的掉了）让真实值换算后不到旧起点的九成时，起点直接降
   回来。少了这个，后台把倍率从 1000 改成 10 之后画面会毫无反应。 */
const ANCHOR_RESET_RATIO = 0.9;

/** 存一笔快照。同一份数值重复取到就只更新时间，不制造假的成长。 */
async function pushSnapshot(values) {
  const hist = readHistory();
  const last = hist.snapshots[hist.snapshots.length - 1];
  const now = new Date().toISOString();
  if (last && JSON.stringify(last.values) === JSON.stringify(values)) {
    last.sameAt = now; // 真值没变，只记下最后一次确认的时间
  } else {
    hist.snapshots.push({ at: now, values });
  }
  hist.snapshots = hist.snapshots.slice(-HISTORY_KEEP);
  await writeSystem('stats_history', hist);
  return hist;
}

/** 上一次成功取到的值。整段历史都空的时候回四个 0。 */
function lastKnownValues() {
  const snaps = readHistory().snapshots;
  const last = snaps[snaps.length - 1];
  if (last && last.values) return { ...last.values };
  const out = {};
  for (const s of STAT_SOURCES) out[s] = 0;
  return out;
}

/** 取得四项真实值（带快取）。
 *  取数失败时沿用上一次成功取到的值（stale=true），不让前台开天窗、也不会归零。
 *  失败时刻意不写快照 —— 写了会让「上一次确认时间」看起来像取数正常。 */
/* 最近一次取数失败的原因。只给后台的「测试取数」看 ——
   刻意不放进 /api/stats 的回应：那是公开端点，错误讯息里可能带 API 网址。 */
let lastError = '';
export function lastStatsError() {
  return lastError;
}

export async function getRealValues({ force = false } = {}) {
  if (!force && cache.data && Date.now() - cache.at < CACHE_TTL_MS) {
    return { ...cache.data, cached: true };
  }
  let values;
  let stale = false;
  try {
    values = await fetchFromApi();
    await pushSnapshot(values);
    lastError = '';
  } catch (err) {
    console.error('[stats] API 取数失败，沿用上一次取到的值：', err.message);
    /* Node 的 fetch 失败一律是 'fetch failed'，真正的原因在 cause.code
       （ENOTFOUND / ECONNREFUSED / CERT_HAS_EXPIRED…）。少了它，后台的
       「测试取数」只能告诉人「失败了」，那等于没讲。 */
    const code = err.cause && err.cause.code ? err.cause.code : '';
    lastError = code ? `${err.message}（${code}）` : err.message;
    values = lastKnownValues();
    stale = true;
  }
  const data = { values, stale, at: new Date().toISOString() };
  cache = { at: Date.now(), data };
  return { ...data, cached: false };
}

export function clearStatsCache() {
  cache = { at: 0, data: null };
}

// 与 schema 的 STAT_UNITS 同步（「元」只是显示字，除数跟不带元的那一组相同）
const DIVISOR = { 無: 1, '万+': 1e4, '亿+': 1e8, '万元+': 1e4, '亿元+': 1e8 };


/** 显示值＝真实值 × 倍率 ÷ 单位除数（基数那一栏已经拿掉，见 schema 的注解） */
export function computeDisplay(row, real) {
  const raw = Number(real || 0) * Number(row.mult || 0);
  return {
    raw,
    value: raw / (DIVISOR[row.unit] ?? 1),
    unit: row.unit === '無' ? '' : row.unit,
  };
}


export function formatValue(v, decimals) {
  return Number(v).toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

/** 产出前台要的完整资料（给 /api/stats 端点与渲染时的初始值共用）。
 *  每一列给的是「起点 + 每秒增量 + 上限」，前台照墙上时钟自己算，
 *  所以就算页面开着好几小时也不需要一直打 API。 */
export async function buildStatsPayload({ force = false } = {}) {
  const st = readEntity('statistics', { rows: [] });
  const refreshSeconds = 600; // 原型规格的刷新频率：前台每 600 秒重新跟伺服器对一次
  const { values, stale, at, cached } = await getRealValues({ force });

  const perDay = Number(st.growthPerDay || 0);
  const nowMs = Date.now();
  const hist = readHistory();
  let anchorsChanged = false;

  const rows = [...(st.rows || [])]
    .sort((a, b) => (a.sort || 0) - (b.sort || 0))
    .map((row) => {
      const real = values[row.source] ?? 0;
      const d = computeDisplay(row, real);
      const everySeconds = Math.max(1, Number(row.tickEverySeconds || 15));

      /* 每次跳多少 = 显示值 × 每天成长% ÷ 一天的秒数 × 这张卡的节奏，四舍五入到整数。

         ⚠ 四舍五入到 0 就是「不会动」。那不是 bug，是这张卡的数字太小撑不起这个节奏
         （2,050 每天涨 3% 是一天 +61，摊到 15 秒是 +0.01）。解法是把倍率调大，
         后台会把「这样不会动」直接写在那一列旁边，不用人自己算。

         ⚠ 小数位固定 0。会员数显示 13,410.02 看起来就是假的；而金额卡数字够大，
         整数就滚得很顺。所以没有任何一张卡需要小数。 */
      /* 每秒爬多少 = 真实值换算 × 每天成长% ÷ 一天的秒数。刻意拿「真实值换算」而不是
         「现在显示的值」去算 —— 后者会复利，数字愈爬愈快。 */
      const perSecond = perDay > 0 ? (d.value * perDay) / 100 / 86400 : 0;
      const step = Math.round(perSecond * everySeconds);

      /* 起点三种情况：
           没有起点／倍率被调小 → 用真实值当起点，从现在起算
           真实成长反超爬升     → 跳到真实值，从现在起算（往上跳，不会倒退）
           其余（绝大多数）     → 沿用旧起点，爬升继续累积 */
      const prev = hist.anchors[row.source];
      const prevValue = Number((prev || {}).value);
      const prevAt = Date.parse((prev || {}).at);
      const usable =
        perSecond > 0 &&
        Number.isFinite(prevValue) &&
        Number.isFinite(prevAt) &&
        d.value >= prevValue * ANCHOR_RESET_RATIO;
      const projected = usable ? prevValue + perSecond * Math.max(0, (nowMs - prevAt) / 1000) : null;

      let baseValue;
      let sinceMs;
      if (usable && projected >= d.value) {
        baseValue = prevValue;
        sinceMs = prevAt;
      } else {
        baseValue = d.value;
        sinceMs = nowMs;
        if (perSecond > 0) {
          hist.anchors[row.source] = { value: d.value, at: new Date(nowMs).toISOString() };
          anchorsChanged = true;
        }
      }

      /* 回给前台的是 base / step / everySeconds / since，前台算
             base + step × floor((now − since) / everySeconds)
         —— 只跟墙上时钟有关，所以每个访客在同一刻算出同一个数，重新整理也一样。

         ⚠ 没有上限。上限原本的用途是「别爬到真实值上方太多」，但这个爬升本来就是
         刻意爬到真值上方的，设了上限就会卡住不动，而「不动」正是要解决的问题。

         那为什么不会往回跳？四个指标都是累计值（会员数、代理数、累计佣金、累计奖金），
         只增不减，所以第一项不会变小；第二项随时钟单调增。两项都不减＝总和不减。
         （唯一的例外是代理被移除让代理数变少，那一小段由前台的楼地板挡住。） */
      const ticks = Math.max(0, Math.floor((nowMs - sinceMs) / 1000 / everySeconds));
      const current = baseValue + step * ticks;

      return {
        source: row.source,
        title: row.title,
        desc: row.desc,
        real, // 这一轮取到的真实值（后台预览用）
        unit: d.unit,
        decimals: 0,
        base: baseValue, // 真实值换算后的显示值＝爬升的起点
        step, // 每跳一次往上加多少
        everySeconds, // 几秒跳一次
        /* current＝「现在这一刻该显示的值」。SSR 与 /api/stats 都用它，不要各自拿 base
           重算 —— render.js 曾经那样做，于是静态 HTML 是没有爬升量的 base，
           JS 一跑数字当场跳一大格，看起来像闪了一下。 */
        current,
        formatted: formatValue(current, 0),
        since: new Date(sinceMs).toISOString(), // 前台从这一刻开始数「跳了几次」
      };
    });

  /* 起点只有在上抬（或被调小重设）时才写档，所以平常一整天可能一次都不写。
     写入失败不让取数跟着失败（画面已经算好了），但一定要留 log。 */
  if (anchorsChanged) {
    writeSystem('stats_history', hist).catch((err) =>
      console.error('[stats] 起点写入失败，下一轮会重算：', err.message)
    );
  }

  return {
    refreshSeconds,
    rows,
    meta: { at, cached, stale },
  };
}
