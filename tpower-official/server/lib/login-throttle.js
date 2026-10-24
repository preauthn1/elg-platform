/* 针对「同一个帐号」的登入节流 —— 补掉 per-IP 限流挡不住的分散攻击。

   ⚠ 为什么需要它：express-rate-limit 的 key 是 IP，所以换 IP 就重新拿到一份额度。
   有一池 proxy 的攻击者对同一个帐号的尝试次数其实没有上限。这一支跟来源无关，
   只看「这个帐号连续失败几次」，所以换几个 IP 都一样要等。

   ⚠ 刻意宽松：站方自己会打错密码，而被锁在门外的代价比慢一点严重得多。
   所以是「前几次完全不罚，之后才慢慢加长等待」，不是硬锁定：
     连续失败 1..10 次   完全不等（站方摸索密码的空间）
     第 11 次起          等 30 秒，之后每多失败一次翻倍，上限 5 分钟
     登入成功            立刻归零
     30 分钟没再尝试     自动归零

   对攻击者的效果：上限 5 分钟＝同一个帐号最多约 12 次/小时，
   不管他有几个 IP。对站方的效果：正常打错几次完全无感。

   状态放记忆体，重启归零 —— 单行程部署可接受，也不想为了它去写 system.json
   （那会变成「每次失败登入都重写 102 KB」，正是我们想避免的）。 */

const FREE_ATTEMPTS = 10; // 前几次完全不罚
const BASE_DELAY_MS = 30 * 1000; // 超过之后的第一段等待
const MAX_DELAY_MS = 5 * 60 * 1000; // 等待上限
const FORGET_MS = 30 * 60 * 1000; // 这么久没动作就把纪录忘掉

/** username → { fails, until, at } */
const state = new Map();

/* 定期清掉过期的项目，否则被乱打的帐号名会一直累积在记忆体里
   （攻击者可以用随机帐号名塞爆 Map）。unref 让它不阻止行程结束。 */
const SWEEP_MS = 5 * 60 * 1000;
const sweeper = setInterval(() => {
  const now = Date.now();
  for (const [k, v] of state) if (now - v.at > FORGET_MS) state.delete(k);
}, SWEEP_MS);
if (sweeper.unref) sweeper.unref();

/** 现在还要等几毫秒才让这个帐号再试；0＝可以试。 */
export function retryAfterMs(username) {
  const rec = state.get(String(username));
  if (!rec) return 0;
  const now = Date.now();
  if (now - rec.at > FORGET_MS) {
    state.delete(String(username));
    return 0;
  }
  return Math.max(0, rec.until - now);
}

/** 记一次失败，回传「下一次要等多久」（给讯息用）。 */
export function noteFailure(username) {
  const key = String(username);
  const now = Date.now();
  const prev = state.get(key);
  const fails = (prev && now - prev.at <= FORGET_MS ? prev.fails : 0) + 1;
  // 第 FREE_ATTEMPTS+1 次失败开始罚：30s、60s、120s…最多 MAX_DELAY_MS
  const over = fails - FREE_ATTEMPTS;
  const wait = over <= 0 ? 0 : Math.min(BASE_DELAY_MS * Math.pow(2, over - 1), MAX_DELAY_MS);
  state.set(key, { fails, until: now + wait, at: now });
  return wait;
}

/** 登入成功：这个帐号的纪录归零。 */
export function clearFailures(username) {
  state.delete(String(username));
}

/** 给测试与排查用：看目前的状态。 */
export function throttleState() {
  return [...state.entries()].map(([username, v]) => ({
    username,
    fails: v.fails,
    waitMs: Math.max(0, v.until - Date.now()),
  }));
}
