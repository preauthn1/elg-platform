import fs from 'node:fs';
import path from 'node:path';
import express from 'express';
import cookieSession from 'cookie-session';
import { config } from './lib/config.js';
import { site, adminSiteConfig } from './sites/index.js';
import { requireAuth, sameOriginGuard } from './middleware/auth.js';
import { authRouter } from './routes/auth.js';
import { contentRouter } from './routes/content.js';
import { uploadsRouter } from './routes/uploads.js';
import { usersRouter } from './routes/users.js';
import { apiConfigRouter } from './routes/api-config.js';
import { publicStatsRouter, adminStatsRouter } from './routes/stats.js';
import { findUser } from './lib/users.js';
import { readSettings, preloadStore } from './lib/store.js';
import { cachedHtml } from './lib/pagecache.js';
import {
  loadContent,
  renderHome,
  renderAdvantages,
  renderPromotions,
  renderPromotionsArchive,
  renderGames,
  renderBrand,
  renderBrandCategory,
  renderContact,
  renderBlog, 
  renderArticle,
  renderGuide,
  renderFaq,
  renderPartners,
  renderCompliance,
  renderNotFound,
  guideSections,
  renderRootRedirect,
  renderRobots,
  renderSitemap,
} from './lib/render.js';

/* 未捕捉的错误：记一行 log，然后**照样结束行程**。改成「只记 log 不结束」是错的 ——
   吞掉的 rejection 会让请求永远不回应，nginx 要等 proxy_read_timeout（300 秒）才给
   一个没讯息的 504；而 EADDRINUSE 这种启动失败会变成 exit 0，systemd 不重启、
   部署脚本把「起不来」读成成功。
   结束行程的代价很小：掉的只有记忆体页面快取，SESSION_SECRET 固定不会登出人，
   nginx 的 proxy_cache_use_stale 会拿旧页顶住那几秒。
   要让行程活下来就把错误接在该接的地方，不是在最外层假装没事。 */
function fatal(kind, err) {
  console.error(`[fatal] ${kind}：`, err);
  process.exitCode = 1;
  process.exit(1);
}
process.on('unhandledRejection', (reason) => fatal('未捕捉的 Promise rejection', reason));
process.on('uncaughtException', (err) => fatal('未捕捉的例外', err));

const app = express();
app.disable('x-powered-by');
// 正式拓扑是 client → nginx → Node，只有一层代理。
// ⚠ 这个数字要跟实际的代理层数一致：设多了，访客自己塞的 X-Forwarded-For
// 会被当成 req.ip，登入限流可被绕过；设少了，全站共用同一个限流桶。
app.set('trust proxy', 1);

app.use((req, res, next) => {
  res.set('X-Content-Type-Options', 'nosniff');
  next();
});

// Container readiness：listen 之前已完成 preloadStore，能連到這裡就代表服務可接流量。
// 不檢查外部統計 API，避免第三方故障導致 Docker 反覆重啟本站。
app.get('/healthz', (req, res) => {
  const settings = readSettings();
  const ready = Boolean(settings.defaultLanguage && Array.isArray(settings.languages));
  res.set('Cache-Control', 'no-store').status(ready ? 200 : 503).json({ ok: ready });
});

/* 任何 .json 档与整个 content/ 都不对外。
   system.json 里有后台帐号的密码杂凑，content.json 是站方的全部内容 ——
   两者都没有理由让人直接下载。

   ⚠ 挡的是「路径长得像档案」，不是 API：/api/stats 与 /api/admin/* 回的是
   JSON 内容但路径不以 .json 结尾，不受影响。admin/ 与 assets/ 底下没有任何
   .json 档（确认过），所以这条不会挡掉正常资源。

   目前没有任何 static 挂在 content/ 上，所以这两条其实都是多的；留着是因为
   它们挡的是「以后有人手滑挂了 express.static(ROOT)」，而那种事的代价是密码外流。
   回 404 不回 403：403 等于告诉对方「这里真的有东西」。 */
app.use((req, res, next) => {
  const p = req.path.toLowerCase();
  if (p.endsWith('.json') || p === '/content' || p.startsWith('/content/')) {
    return res.status(404).end();
  }
  next();
});

app.use(express.json({ limit: '4mb' }));
app.use(
  cookieSession({
    name: site.cookieName,
    keys: [config.sessionSecret],
    httpOnly: true,
    sameSite: 'lax',
    secure: config.isProd,
    maxAge: 8 * 60 * 60 * 1000, // 8 小时
  })
);

// ---------- 公开 API（唯一的动态端点：首页那组会跳动的数字） ----------
app.use('/api', publicStatsRouter);

// ---------- 后台 ----------
/* 后台对所有人开放到「登入页」这一层 —— 站方要自己登入改内容。
   IP 白名单绑的是 admin 角色（操作纪录、帐号管理），不是整个 /admin，
   见 middleware/auth.js 的 isAllowedAdminIp。 */
app.use('/admin', (req, res, next) => {
  res.set('X-Robots-Tag', 'noindex, nofollow');
  res.set('X-Frame-Options', 'DENY');
  next();
});

/* 网站图示（favicon）。设定里有就 302 过去 —— 可能是 /uploads/fav-….png，也可能是外部网址。

   两条的差别只在「没设定时」：
     /favicon.ico    前台。浏览器与爬虫会直接来要它；没设就回空的 404，
                     不要掉进底下的 404 处理器去渲染一整页 HTML。
     /admin/favicon  后台。没设就用后台自己那张蓝底白字的站点字母（site.letter）——
                     站方还没上传之前，后台分页不该变成空白。

   ⚠ 两条都要排在 /admin 的登入检查之前：登入页本身也要用这张图，而那时还没登入。
   ⚠ 302 不是 301：301 会被浏览器永久记住，站方换了图也不会生效。
     快取 5 分钟 —— 所有人拿到的都一样，可以安心交给 nginx。 */
const ADMIN_ICON_SVG = "<svg xmlns='http://example.invalid/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='#006be6'/><text x='16' y='23' font-family='Arial' font-size='20' font-weight='700' fill='#fff' text-anchor='middle'>" + site.letter + "</text></svg>";
function sendFavicon(res, fallback) {
  res.set('Cache-Control', 'public, max-age=300');
  const fav = readSettings().favicon;
  return fav ? res.redirect(302, fav) : fallback(res);
}
app.get('/favicon.ico', (req, res) => sendFavicon(res, (r) => r.status(404).end()));
app.get('/admin/favicon', (req, res) => sendFavicon(res, (r) => r.type('image/svg+xml').send(ADMIN_ICON_SVG)));

// 未登入也要拿得到的档案：登入页本身与它用到的样式
const ADMIN_PUBLIC = new Set(['/login.html', '/admin.css']);
app.use('/admin', (req, res, next) => {
  if (ADMIN_PUBLIC.has(req.path)) return next();
  // 跟 requireAuth 同一套：cookie 只证明登入过，权限每次都回头核对帐号档
  if (req.session && req.session.user && findUser(req.session.user)) return next();
  req.session = null;
  if (req.path.startsWith('/api/')) return res.status(401).json({ ok: false, error: '未登入' });
  return res.redirect('/admin/login.html');
});

/* 后台的两张 HTML 带站点字母（%SITE_LETTER%），这里换掉再送；
   后台程式要的站点设定（隐藏哪些栏位等）由 /admin/js/site.js 给，来源是 server/sites/<站>.js。 */
const sendAdminHtml = (file) => (req, res) => {
  const html = fs.readFileSync(path.join(config.dirs.admin, file), 'utf8');
  res.type('html').send(html.split('%SITE_LETTER%').join(site.letter));
};
app.get(['/admin/', '/admin/index.html'], sendAdminHtml('index.html'));
app.get('/admin/login.html', sendAdminHtml('login.html'));
app.get('/admin/js/site.js', (req, res) => {
  res
    .type('application/javascript')
    .send('export const SITE = ' + JSON.stringify(adminSiteConfig()) + ';\n' +
      'export const shown = (key) => !SITE.hidden.includes(key);\n' +
      'export const optional = (key) => SITE.optional.includes(key);\n');
});

// 静态发布时代这里有一支 /admin/preview/:lang，用来看「还没发布的草稿长什么样」。
// 存档即上线之后它没有意义了 —— 公开页本身就是目前内容的即时渲染。
app.use('/admin', express.static(config.dirs.admin, { index: 'index.html' }));

// ---------- 后台 API ----------
app.use('/api/admin', sameOriginGuard, authRouter);
app.use('/api/admin', requireAuth, contentRouter);
app.use('/api/admin', requireAuth, uploadsRouter);
app.use('/api/admin', requireAuth, usersRouter);
app.use('/api/admin', requireAuth, apiConfigRouter);
app.use('/api/admin', adminStatsRouter);

// ---------- 公开资源 ----------
// 正式环境这两条由 nginx 直接供档（见 deploy/nginx.conf），不会走到这里；
// Node 也挂着，让开发模式与后台预览拿得到。max-age 跟 nginx 那边对齐。
app.use('/uploads', express.static(config.dirs.uploads, { maxAge: config.isProd ? '7d' : 0 }));
/* 资产网址带版号（asset()，见 lib/assets.js），改版即换网址，
   所以可以放到一年 + immutable：连 F5 都不重新验证，改版照样立刻生效。
   ⚠ 这里的值要跟 deploy/nginx.conf 的 location /assets/ 对齐 —— 正式环境
   那两条由 nginx 直接供档，走不到这里。 */
app.use(
  '/assets',
  express.static(config.dirs.assets, {
    maxAge: config.isProd ? '365d' : 0,
    immutable: config.isProd,
  })
);

// 尾斜线统一 301 到不带斜线的版本（/en/ → /en），免得同一页有两个网址被当成重复内容。
// 开头连续的斜线先收成一个：'//example.invalid/' 拿掉尾斜线后会变成协定相对网址，等于开放转址。
app.use((req, res, next) => {
  if ((req.method !== 'GET' && req.method !== 'HEAD') || req.path.length < 2 || !req.path.endsWith('/')) return next();
  const q = req.originalUrl.indexOf('?');
  const target = req.path.replace(/^\/+/, '/').replace(/\/+$/, '') || '/';
  res.redirect(301, target + (q > -1 ? req.originalUrl.slice(q) : ''));
});

// ---------- 公开页：即时渲染 + 页面快取 ----------
// 站方存档就是上线：writeEntity 会把内容版本号 +1，页面快取当下全部失效。
// 快取只是挡掉重复渲染，TTL 见 config.pageCacheSeconds（正式 60 秒、开发 0）。

/** 送 HTML 给访客。
 *  s-maxage 是给 nginx／CDN 的，用的是 sharedCacheSeconds 而不是上面那层的
 *  pageCacheSeconds —— 它们看不到「有人存档」，握几秒就是站方存档后要等几秒。
 *  max-age=0 让浏览器每次回来问，否则站方改完自己重整还是看到旧页。 */
function sendPage(res, html, status = 200) {
  const ttl = config.sharedCacheSeconds;
  res.set('Cache-Control', ttl > 0 ? `public, max-age=0, s-maxage=${ttl}` : 'no-store');
  res.status(status).type('html').send(html);
}

/** 语系前缀（/en /tw /cn /ms）→ 语系名称；认不出来回 null。
 *  只读 settings（store.js 有 memo），不为了确认语系而 parse 整份 122KB 内容 ——
 *  扫站的机器人每打一个不存在的前缀都会走到这里。 */
function langOf(slug) {
  const s = readSettings();
  return (s.languages || []).find((l) => ((s.langMeta || {})[l] || {}).slug === slug) || null;
}

/** 快取 key：路径本身（语系已经在路径里）。去掉尾斜线，免得 /en 与 /en/ 各存一份。 */
function cacheKey(req) {
  return req.path.length > 1 ? req.path.replace(/\/+$/, '') : req.path;
}

app.get('/', async (req, res, next) => {
  try {
    sendPage(res, await cachedHtml('/', () => renderRootRedirect(loadContent())));
  } catch (err) {
    next(err);
  }
});

app.get('/robots.txt', async (req, res, next) => {
  try {
    const body = await cachedHtml('robots.txt', () => renderRobots(loadContent()));
    res.set('Cache-Control', 'public, max-age=600').type('text/plain').send(body);
  } catch (err) {
    next(err);
  }
});

app.get('/sitemap.xml', async (req, res, next) => {
  try {
    const body = await cachedHtml('sitemap.xml', () =>
      renderSitemap(loadContent(), new Date().toISOString().slice(0, 10))
    );
    res.set('Cache-Control', 'public, max-age=600').type('application/xml').send(body);
  } catch (err) {
    next(err);
  }
});

// Search Console 验证档。原本由发布时写进静态站，现在照 settings 即时回应。
// 要排在 /:slug 前面（两者都吃单段路径），认不出来就 next() 交给下一条。
app.get('/:name', (req, res, next) => {
  if (!(readSettings().verificationFileNames || []).includes(req.params.name)) return next();
  res.type('text/plain').send('google-site-verification: ' + req.params.name);
});

/** 每一条公开路径共用的包装：语系解析 → 快取 → 渲染 → 送 HTML。
 *  render 可以是同步或 async，两者都 await。 */
const publicPage = (route, render) =>
  app.get(route, async (req, res, next) => {
    const lang = langOf(req.params.slug);
    if (!lang) return next(); // 认不出语系就交给 404，不必载入内容
    try {
      const html = await cachedHtml(cacheKey(req), () => render(lang, { content: loadContent() }, req));
      sendPage(res, html);
    } catch (err) {
      // 找不到的文章／分类就是 404，不是伺服器错误
      if (err.status === 404) return next();
      next(err);
    }
  });

publicPage('/:slug', (lang, o) => renderHome(lang, o));
publicPage('/:slug/advantages', (lang, o) => renderAdvantages(lang, o));
publicPage('/:slug/promotions', (lang, o) => renderPromotions(lang, o));
publicPage('/:slug/promotions/archive', (lang, o) => renderPromotionsArchive(lang, o));
publicPage('/:slug/games', (lang, o) => renderGames(lang, o));
publicPage('/:slug/brand', (lang, o) => renderBrand(lang, o));
publicPage('/:slug/brand/:cat', (lang, o, req) => renderBrandCategory(lang, req.params.cat, o));
publicPage('/:slug/contact/channels', (lang, o) => renderContact(lang, o));
publicPage('/:slug/blog', (lang, o) => renderBlog(lang, o));
publicPage('/:slug/blog/:article', (lang, o, req) => renderArticle(lang, req.params.article, o));
publicPage('/:slug/guide', (lang, o) => renderGuide(lang, '/guide', o));
publicPage('/:slug/guide/:section', (lang, o, req) => {
  const p = '/guide/' + req.params.section;
  // buildGuideContext 找不到分页时会退回第一段，这里希望直接 404
  if (!guideSections(o.content).some((s) => s.path === p)) {
    throw Object.assign(new Error('unknown guide section'), { status: 404 });
  }
  return renderGuide(lang, p, o);
});
publicPage('/:slug/faq', (lang, o) => renderFaq(lang, o));
publicPage('/:slug/partners', (lang, o) => renderPartners(lang, o));
publicPage('/:slug/compliance', (lang, o) => renderCompliance(lang, o));

// ---------- 404 / 错误处理 ----------
app.use(async (req, res) => {
  if (req.path.startsWith('/api/')) return res.status(404).json({ ok: false, error: 'not found' });
  // 网址开头认得出语系就用那个语系的 404，认不出来走预设语系。
  const lang = langOf(req.path.split('/')[1]) || readSettings().defaultLanguage;
  try {
    // 快取 key 只带语系、不带请求路径 —— 带了的话扫站的机器人可以用無限多个
    // 不存在的网址把快取塞爆（每个 miss 都会 parse 一次整份内容）
    const html = await cachedHtml('404:' + lang, () => renderNotFound(lang, { content: loadContent() }));
    res.set('Cache-Control', 'no-store');
    return res.status(404).type('html').send(html);
  } catch (err) {
    console.error('[error] 404 页渲染失败：', err.message);
    return res.status(404).type('text/plain').send('Not Found');
  }
});

// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
  if (err.type === 'entity.parse.failed') {
    return res.status(400).json({ ok: false, error: 'JSON 格式错误' });
  }
  if (err.code === 'LIMIT_FILE_SIZE') {
    return res.status(413).json({ ok: false, error: '档案过大（上限 8MB）' });
  }
  const status = err.status || 500;

  /* 4xx 的 message 是我们自己写给使用者看的（「档案过大」「这个帐号限公司 IP 登入」…），
     照原样送出去。

     ⚠ 5xx 不行。那是没预期到的例外，message 直接来自 Node 或第三方套件，会把内部细节
     送到浏览器上 —— 例如 ENOENT 会连伺服器上的完整路径一起吐出来
     （…open 'C:/…/content/content.json'），等于免费告诉人家目录结构与部署方式。
     真正的讯息留在 log 里（上面那行 console.error），使用者只拿到一句通用的。 */
  if (status >= 500) console.error('[error]', err);
  const payload = {
    ok: false,
    error: status >= 500 ? '伺服器发生错误，请稍后再试' : err.message || 'server error',
  };
  if (err.problems) payload.problems = err.problems;
  if (req.path.startsWith('/api/')) return res.status(status).json(payload);
  res.status(status).type('text/plain').send(payload.error);
});

/* 先把两份 blob 拉进记忆体才开始接请求 —— 少了这一步，
   第一批请求的同步读会拿到空内容，整站瞬间变成空白页。 */
const where = await preloadStore();

app.listen(config.port, () => {
  console.log(
    '[server] ' + site.name + ' 启动于 http://localhost:' + config.port +
      ` (${config.isProd ? 'production' : 'development'}` +
      `：公开页即时渲染，页面快取 ${config.pageCacheSeconds} 秒` +
      `，资料来源 ${where})`
  );
});
