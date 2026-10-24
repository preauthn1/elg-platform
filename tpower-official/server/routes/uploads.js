import { Router } from 'express';
import crypto from 'node:crypto';
import multer from 'multer';
import sharp from 'sharp';
import { listEntities, readEntity } from '../lib/store.js';
import * as storage from '../lib/storage.js';

export const uploadsRouter = Router();

// 站方上传的素材，实际位置是 content/../uploads/（见 storage.js 的 localPath）。
export const UPLOAD_DIR = 'uploads';
const IMAGE_RE = /\.(webp|png|jpe?g|gif|avif)$/i;

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 8 * 1024 * 1024, files: 1 },
});

const ALLOWED = new Set(['jpeg', 'png', 'webp', 'gif', 'avif']);
const MAX_WIDTH = 2400;

/* 网站图示（favicon）的边长上限。
   ⚠ 不走一般的 webp 管线，三个原因：
     1. 要正方形。浏览器分页、iOS 主画面、Google 搜寻结果都假设 1:1，
        长方形的 logo 直接拿来用会被压扁或裁掉。
     2. 要 PNG。webp 当 favicon 在 Chromium／Firefox 可以，但 Safari 与部分爬虫不认。
     3. 不放大。边长取「原图长边」与 512 的较小值 —— 站方传一张 64px 的小图标，
        硬放大到 512 只会糊掉；传大图就缩到 512，够 iOS 主画面（180）与 Android（192）用。 */
const FAVICON_MAX = 512;

// 上传管线：只信任档案内容（sharp 实际解码），不信任副档名/Content-Type。
// 一律重新编码成 webp（顺便去 EXIF、压尺寸），档名由内容 hash 产生 → 换图即换网址，不用手动清快取。
// 注：影片依原型规格「仅支援填入连结，不提供上传」，所以这里只处理图片。
uploadsRouter.post('/uploads', upload.single('file'), async (req, res) => {
  if (!req.file) return res.status(400).json({ ok: false, error: '没有收到档案' });
  let meta;
  try {
    meta = await sharp(req.file.buffer).metadata();
  } catch {
    return res.status(422).json({ ok: false, error: '不是可辨识的图片档' });
  }
  if (!ALLOWED.has(meta.format)) {
    return res.status(422).json({ ok: false, error: `不支援的格式：${meta.format}（允许 jpg/png/webp/gif/avif）` });
  }
  const favicon = req.query.kind === 'favicon';
  // favicon 只取第一格：动图当分页图示没有意义，而且 PNG 本来就不会动
  const animated = !favicon && (meta.format === 'gif' || (meta.pages || 1) > 1);
  let out;
  try {
    const img = sharp(req.file.buffer, { animated }).rotate(); // 依 EXIF 转正后再去除 metadata
    if (favicon) {
      const side = Math.min(FAVICON_MAX, Math.max(meta.width || 0, meta.height || 0)) || FAVICON_MAX;
      out = await img
        // contain + 透明底：长方形的图补成正方形，不裁掉任何一边
        .resize(side, side, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png()
        .toBuffer();
    } else {
      out = await img.resize({ width: MAX_WIDTH, withoutEnlargement: true }).webp({ quality: 82 }).toBuffer();
    }
  } catch {
    return res.status(422).json({ ok: false, error: '图片转档失败' });
  }
  const hash = crypto.createHash('sha1').update(out).digest('hex').slice(0, 16);
  const name = favicon ? `fav-${hash}.png` : `img-${hash}.webp`;
  await storage.putObject(`${UPLOAD_DIR}/${name}`, out, favicon ? 'image/png' : 'image/webp');
  const info = await sharp(out).metadata();
  console.log(`[uploads] ${name} ${info.width}x${info.height} (${Math.round(out.length / 1024)}KB) by=${req.session.user}`);
  res.json({ ok: true, url: `/uploads/${name}`, bytes: out.length, width: info.width, height: info.height });
});

uploadsRouter.get('/uploads', async (req, res, next) => {
  try {
    const files = (await storage.listObjects(UPLOAD_DIR))
      .filter((o) => IMAGE_RE.test(o.name))
      .map((o) => ({ name: o.name, url: `/uploads/${o.name}`, bytes: o.size, mtime: Date.parse(o.at) || 0 }))
      .sort((a, b) => b.mtime - a.mtime);
    res.json({ ok: true, data: files });
  } catch (err) {
    next(err);
  }
});

// 删除前检查：任何内容 JSON 还引用中的档案不能删
uploadsRouter.delete('/uploads/:name', async (req, res, next) => {
  try {
    const { name } = req.params;
    if (!/^[\w.-]+$/.test(name)) return res.status(400).json({ ok: false, error: '档名不合法' });
    if (!(await storage.getObject(`${UPLOAD_DIR}/${name}`))) {
      return res.status(404).json({ ok: false, error: '档案不存在' });
    }

    // listEntities 只回站方内容的类别（users / stats_history / audit 已被排除，
    // 见 store.js 的 SYSTEM_KEYS），所以不必再自己过滤一次
    const needle = `/uploads/${name}`;
    for (const ent of listEntities()) {
      if (JSON.stringify(readEntity(ent) ?? {}).includes(needle)) {
        return res.status(409).json({ ok: false, error: `仍被「${ent}」引用，请先在内容中移除` });
      }
    }
    await storage.removeObject(`${UPLOAD_DIR}/${name}`);
    console.log(`[uploads] 删除 ${name} by=${req.session.user}`);
    res.json({ ok: true });
  } catch (err) {
    next(err);
  }
});
