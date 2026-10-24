/* 系统设置 —— 取数用的 API 网址与三个密钥。仅管理员（登入 → admin 角色 → 公司 IP）。

   ⚠ 这四个值会原样回给浏览器（站方要求可以直接看到、直接改）。
   它们等同 apis 专案 .env 里的 AES_KEY / AES_IV / AES_MD5 —— 拿到就能伪造任何
   frontend 请求，所以会出现在开发者工具的 network 面板与截图里。
   挡在前面的是 requireAdmin 的三道锁，不是遮罩。这一页不要开给 editor。

   ⚠ 稽核纪录只记「改了哪几个栏位」，不记值。稽核档跟这四个值是同一份
   system.json，记进去等于同一份秘密存两次，其中一次还在会被翻阅的地方。

   ⚠ brandDomain 本来在 content.json 的 statistics 底下、首页那一页改的，
   已经搬到这里 —— 取数的五个设定散在两个档、两个页面，站方每次都要先想「那一栏在哪」。
   纯网域的验证也跟着搬过来（原本是 schema/content.js 的 DOMAIN_RE）。 */
import { Router } from 'express';
import { readSystem, writeSystem } from '../lib/store.js';
import { audit } from '../lib/audit.js';
import { requireAdmin } from '../middleware/auth.js';
import { getRealValues, clearStatsCache, lastStatsError } from '../lib/stats.js';

export const apiConfigRouter = Router();

const FIELDS = ['baseUrl', 'aesKey', 'aesIv', 'aesMd5', 'brandDomain'];
const MAX_LEN = 300;
const IV_BYTES = 16;

const fail = (res, msg, status = 422) => res.status(status).json({ ok: false, error: msg });

/* 纯网域检查。API 端点拿它查 agent_domains（status=1）找到品牌根代理，再用
   hierarchy_path 前缀收敛整条代理线 —— 一个资料库装多个白标，少了它算的是别人的数字。
   带 https:// 或路径的话那边查不到，四个数字会静静停在旧值，所以在这里就挡掉。

   ⚠ 刻意逐字元检查而不用正规表达式：网域的规则（每段不可用 - 开头结尾、不可有空段）
   写成一行 regex 之后没人看得出漏了哪一条，而漏掉的那一条就是会通过的坏资料。
   回空字串＝没问题。 */
const DOMAIN_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789-.';
function domainProblem(v) {
  const s = v.toLowerCase();
  if (s.startsWith('http://') || s.startsWith('https://')) {
    return '品牌网域请填纯网域（例如 example.invalid），不要带 https://';
  }
  if (s.includes('/')) return '品牌网域请填纯网域（例如 example.invalid），不要带路径';
  if (s.includes(' ')) return '品牌网域不可含空白';
  if (!s.includes('.')) return '品牌网域看起来不完整（例如 example.invalid）';
  for (const ch of s) {
    if (!DOMAIN_CHARS.includes(ch)) return `品牌网域含不合法字元：${ch}`;
  }
  for (const label of s.split('.')) {
    if (!label) return '品牌网域有空的一段（连续的点，或首尾就是点）';
    if (label.startsWith('-') || label.endsWith('-')) return '品牌网域的每一段不可用 - 开头或结尾';
  }
  return '';
}
const current = () => readSystem('api', {}) || {};
const str = (v) => (typeof v === 'string' ? v : '');

apiConfigRouter.get('/api-config', requireAdmin, (req, res) => {
  const c = current();
  res.json({
    ok: true,
    data: {
      baseUrl: str(c.baseUrl),
      aesKey: str(c.aesKey),
      aesIv: str(c.aesIv),
      aesMd5: str(c.aesMd5),
      brandDomain: str(c.brandDomain),
    },
  });
});

apiConfigRouter.put('/api-config', requireAdmin, async (req, res, next) => {
  try {
    const body = req.body || {};
    const next_ = { ...current() };
    const changed = [];

    for (const field of FIELDS) {
      if (body[field] === undefined) continue;
      if (typeof body[field] !== 'string') return fail(res, `${field} 格式不正确`);
      const v = body[field].trim();
      if (v.length > MAX_LEN) return fail(res, `${field} 太长（上限 ${MAX_LEN} 字）`);

      if (field === 'baseUrl' && v !== '') {
        /* 刻意不用正规表达式。这里只要挡掉两种真的会发生的错：忘了带协定、
           整段贴成含路径的网址。深入验证网址形状是 fetch 那时的事。 */
        if (!v.startsWith('http://') && !v.startsWith('https://')) {
          return fail(res, 'API 网址要以 http:// 或 https:// 开头');
        }
        if (v.includes(' ')) return fail(res, 'API 网址不可含空白');
        if (v.endsWith('/frontend/api') || v.includes('/frontend/api/')) {
          return fail(res, '只要填到网域，端点路径由程式接（/frontend/api/platform-statistic）');
        }
      }
      // aes-256-cbc 的 IV 必须刚好 16 bytes，长度不对会在取数时丢 Invalid IV length
      if (field === 'aesIv' && v !== '' && Buffer.byteLength(v) !== IV_BYTES) {
        return fail(res, `AES IV 必须刚好 ${IV_BYTES} bytes，现在是 ${Buffer.byteLength(v)}`);
      }
      if (field === 'brandDomain' && v !== '') {
        const bad = domainProblem(v);
        if (bad) return fail(res, bad);
      }

      if (v !== str(next_[field])) {
        next_[field] = v;
        changed.push(field);
      }
    }

    if (!changed.length) return res.json({ ok: true, data: { changed: [] } });

    await writeSystem('api', next_);
    audit('api_config', 'statistics', { fields: changed }, { user: req.session.user, ip: req.ip });
    res.json({ ok: true, data: { changed } });
  } catch (err) {
    next(err);
  }
});

/* 「测试取数」—— 真的打一次那支端点，然后把失败原因讲出来。

   ⚠ 为什么不叫人去按首页的「立即重新取数」：那一支只回 stale 布林，
   失败原因只进伺服器的 console。这四个值最容易踩的是「IV 不是 16 bytes」
   「baseUrl 少了协定」「端点还没部署（404）」「DNS 找不到（ENOTFOUND）」——
   这几种没有讯息就只能瞎猜。 */
apiConfigRouter.post('/api-config/test', requireAdmin, async (req, res, next) => {
  try {
    clearStatsCache();
    const r = await getRealValues({ force: true });
    audit('api_test', 'statistics', { ok: !r.stale }, { user: req.session.user, ip: req.ip });
    res.json({ ok: true, data: { stale: r.stale, values: r.values || {}, error: lastStatsError() } });
  } catch (err) {
    next(err);
  }
});
