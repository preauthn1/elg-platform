import { Router } from 'express';
import { readEntity, writeEntity, listSnapshots, readSnapshot } from '../lib/store.js';
import { ENTITY_KEYS, validateEntity, zodIssues } from '../schema/content.js';
import { audit, diffSummary, readAudit, auditFacets } from '../lib/audit.js';
import { requireAdmin } from '../middleware/auth.js';

export const contentRouter = Router();

// 外键验证要用到的参照资料
function refsFor(key) {
  if (key === 'articles') return { article_categories: readEntity('article_categories', []) };
  if (key === 'game_providers') return { game_categories: readEntity('game_categories', []) };
  // 反向：存分类时要看得到底下挂了哪些资料，才挡得住「改名／删除造成孤儿」
  if (key === 'game_categories') return { game_providers: readEntity('game_providers', []) };
  if (key === 'article_categories') return { articles: readEntity('articles', []) };
  return {};
}

function assertKey(key) {
  if (!ENTITY_KEYS.includes(key)) {
    throw Object.assign(new Error(`未知的资料类别：${key}`), { status: 404 });
  }
}

contentRouter.get('/content/:key', (req, res, next) => {
  try {
    const { key } = req.params;
    assertKey(key);
    res.json({ ok: true, data: readEntity(key) });
  } catch (err) {
    next(err);
  }
});

contentRouter.put('/content/:key', async (req, res, next) => {
  const { key } = req.params;
  try {
    assertKey(key);

    let data;
    try {
      data = validateEntity(key, req.body, { refs: refsFor(key) });
    } catch (err) {
      if (err.issues) return res.status(422).json({ ok: false, error: '验证未通过', issues: zodIssues(err) });
      throw err;
    }

    const before = readEntity(key);
    // writeEntity 会把内容版本号 +1，公开页的页面快取当下就失效 —— 存档即上线
    await writeEntity(key, data, { by: req.session.user });

    // 一律记：没有角色分级之后，稽核纪录是「谁改了什么」的唯一答案
    audit('update', key, diffSummary(before, data), { user: req.session.user, ip: req.ip });
    res.json({ ok: true });
  } catch (err) {
    next(err);
  }
});

/* --- 储存快照（每次写入前自动留存）——— 仅管理员 ---
   还原会整个覆写一个类别，等于把站方之后的修改全部推翻，所以不给 editor。
   清单本身也挡：档名带着类别与时间，看得出站方什么时候改过什么。 */
contentRouter.get('/snapshots', requireAdmin, async (req, res, next) => {
  try {
    res.json({ ok: true, data: await listSnapshots() });
  } catch (err) {
    next(err);
  }
});

contentRouter.post('/restore', requireAdmin, async (req, res, next) => {
  try {
    const { file } = req.body || {};
    if (typeof file !== 'string') return res.status(400).json({ ok: false, error: '缺少 file' });
    const { name, data } = await readSnapshot(file);
    assertKey(name);
    if (data === null) return res.status(404).json({ ok: false, error: '快照不存在' });

    let valid;
    try {
      valid = validateEntity(name, data, { refs: refsFor(name) });
    } catch (err) {
      return res.status(422).json({
        ok: false,
        error: '快照内容验证未通过',
        issues: err.issues ? zodIssues(err) : [err.message],
      });
    }
    await writeEntity(name, valid, { by: req.session.user });
    audit('restore', name, { from: file }, { user: req.session.user, ip: req.ip });
    res.json({ ok: true, restored: name });
  } catch (err) {
    next(err);
  }
});

/* --- 稽核纪录（仅管理员） ---
   纪录里有每个人的帐号与 IP，所以这支要挡。后台把选单项目藏起来只是介面，
   不是权限 —— 少了 requireAdmin，任何登入的帐号直接打这支就全看到了。 */
contentRouter.get('/audit', requireAdmin, (req, res) => {
  const limit = Math.min(Number(req.query.limit) || 200, 1000);
  res.json({
    ok: true,
    data: readAudit({
      limit,
      entity: req.query.entity || null,
      action: req.query.action || null,
      user: req.query.user || null,
    }),
    facets: auditFacets(),
  });
});
