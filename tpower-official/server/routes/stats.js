import { Router } from 'express';
import { buildStatsPayload, clearStatsCache } from '../lib/stats.js';
import { audit } from '../lib/audit.js';
import { requireAuth } from '../middleware/auth.js';

/* 公开端点：首页那组数字。
   公开页本身也是即时渲染的，但那一层有 60 秒页面快取；这支是给前台每 300 秒拉一次、
   让数字在浏览器里持续往上爬用的，所以独立出来（真值在 stats.js 内部快取 1 小时）。
   数字不是 SEO 内容 —— 首页 HTML 里已经有初始值。 */
export const publicStatsRouter = Router();

/* 这支回的是完整 payload，包含 rows[].source / rows[].warning 与 meta。
   ⚠ 不要为了「少外泄内部状态」把栏位滤掉，会坏两个地方：
     - assets/js/stats.js 用 row.source 当 localStorage 楼地板的 key，
       滤掉之后四张卡共用 floor[undefined]，会显示到别张卡的数字、还会往回跳；
     - 后台「前台预览」（admin/js/views/statistic.js）本身就是打这支端点，
       它要 meta.at / meta.stale 与 rows[].warning。 */
publicStatsRouter.get('/stats', async (req, res, next) => {
  try {
    const data = await buildStatsPayload();
    // 让 CDN 也能挡掉大部分请求；爬升是前端算的，所以短快取不影响流畅度
    res.set('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
    res.json({ ok: true, data });
  } catch (err) {
    next(err);
  }
});

/* 后台：「立即重算并清快取」 */
export const adminStatsRouter = Router();

adminStatsRouter.post('/stats/recompute', requireAuth, async (req, res, next) => {
  try {
    clearStatsCache();
    const data = await buildStatsPayload({ force: true });
    audit('recompute', 'statistics', { stale: data.meta.stale }, { user: req.session.user, ip: req.ip });
    res.json({ ok: true, data });
  } catch (err) {
    next(err);
  }
});
