/* 内容资料模型 —— 依工单附的后台原型
   与原型最大的差异：原型的 DATA 是扁平的（每个可翻译栏位只存一个值），
   这里把「逐语系」维度补上 —— 逐语系栏位一律是 { [语系]: 值 }，只有预设语系必填。 */
import { z } from 'zod';
import { site } from '../sites/index.js';

// ---------- 语系 ----------
/* 语系识别码用 BCP 47 标签，不是显示名称 —— 拿显示名当键的话，改选单文字＝改几百个
   资料键，移除语系还会让残留键直接撞 schema。显示名在下面的 LANG_NAME。
   顺序＝前台语系选单的显示顺序，en 排第一。 */
export const LANGS = ['en', 'zh-CN', 'ms'];

/** 选单与后台上的显示名 —— 用该语言自己的称呼（设计站写的是 Bahasa Melayu）。
 *  跟识别码分开，所以改这里不会动到任何一笔资料。 */
export const LANG_NAME = {
  en: 'English',
  'zh-CN': '简体中文',
  ms: 'Bahasa Melayu',
};

/** 主语系（规格 §2）。必填语系、回退语系、x-default、后台列表显示，
 *  四件事共用这一个常数 —— 拆开的话后台会出现两个「预设」，站方看不懂谁是谁。 */
export const DEFAULT_LANG = 'en';

// ---------- 基础型别 ----------
const text = (max) => z.string().max(max);
const trimmed = (max) => z.string().trim().max(max);

const httpsUrl = z.string().url().startsWith('https://', '必须是 https 开头').max(500);
const optionalUrl = httpsUrl.or(z.literal(''));

/** 素材引用：站内上传路径 或 外部网址。
 *  所有可上传图片的栏位都是「上传」与「贴网址」二择一 —— 站方想用外部图床就不必上传。
 *  影片一律只能贴连结（规格明载不提供影片上传，品牌影像的上传选项另计）。 */
const assetRef = z
  .string()
  .max(500)
  .regex(/^\/(uploads|assets)\/[\w./-]+$/, '站内素材路径须在 /uploads/ 或 /assets/ 下')
  // 上面的字元类含 . 与 /，所以 /uploads/../../server/x 会通过。目前没有任何程式
  // 拿这个值去组档案系统路径，但不留这个伏笔给之后的人踩。
  .refine((v) => !v.split('/').includes('..'), '素材路径不可含 ..')
  .or(httpsUrl)
  .or(z.literal(''));


// 前台路径：/、/games、/contact/channels…
const routePath = z.string().regex(/^\/[a-z0-9/_-]*$/, '路径格式不正确').max(120);

// 连结目标：站内路径、锚点或外部网址
const linkTarget = z
  .string()
  .max(500)
  .regex(/^(#[\w-]+|\/[\w./-]*|https?:\/\/\S+)?$/, '连结格式不正确');

const datetime = z
  .string()
  .regex(/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/, '格式需为 YYYY-MM-DD HH:mm:ss')
  .or(z.literal(''))
  .nullable()
  .default(null);

const sortField = z.number().int().min(0).max(9999).default(0);
const enabledField = z.boolean().default(true);


/** 逐语系栏位：{ en: '…', 'zh-CN': '…' }
 *  未列出的语系＝未翻译，前台回退预设语系。
 *  必须用 partialRecord —— z.record 搭 enum key 在 zod v4 是穷尽式的，会强迫每个语系都填。 */
function perLang(valueSchema) {
  return z.partialRecord(z.enum(LANGS), valueSchema).default({});
}

/** 逐语系必填检查：预设语系的值不可为空。prefix 给巢状清单用（例 ['visual', 'status', 0]） */
function needDefaultLang(obj, ctx, field, label, prefix = []) {
  const v = obj?.[field]?.[DEFAULT_LANG];
  if (v === undefined || String(v).trim() === '') {
    ctx.addIssue({
      code: 'custom',
      // 讯息是给站方看的，所以用显示名而不是识别码（'en' 对非技术人员没有意义）
      message: `${label}：${LANG_NAME[DEFAULT_LANG] || DEFAULT_LANG} 为预设语系，必填`,
      path: [...prefix, field, DEFAULT_LANG],
    });
  }
}

/* 必填清单要跟后台一致：后台隐藏的栏位（admin.hidden）不能必填，
   站点样板有退路的栏位（admin.optional）在该站是选填。 */
const adminShown = (key) => !(site.admin.hidden || []).includes(key);
const siteOptional = (key) => (site.admin.optional || []).includes(key);

/* 连结一律只有「值」这一个栏位，留空＝没有连结。
   不分「站内／站外」—— render.js 的 makeResolve 自己判断（# 与 http(s):// 原样保留，
   其余加语系前缀），所以那个下拉只是仪式，还会制造「切回無连结但旧值没清掉」的残留 bug。 */

// ---------- 全站：页面首屏设定（一页一笔，固定不可增删） ----------
// 前 7 笔是规格明载「有可设定首屏」的页面；后 4 笔（使用指南／常见问题／代理招募／
// 信任与安全）在原型里没有后台模组，但设计站每页都有一整组首屏文案，所以照同一张表管理。
// 品牌影像的四个子页不在这里 —— 它们的首屏在 page_blocks.brand.categories，
// 因为那四页是「同一个分类清单的四种过滤」，跟着分类设定走比较不会漂移。
export const PAGE_CODES = [
  'home',
  'advantages',
  'promotions',
  'games',
  'brand',
  'contact',
  'blog',
  'guide',
  'faq',
  'partners',
  'compliance',
];

/** 自订程式码的基本检查。不是安全检查（这两格本来就是给人贴 script 的），
 *  只挡两种「贴下去整站就坏」的情况：
 *    1. 贴成整份网页 —— 带了 <html> / <body> / </head> 这些，会把版面结构整个拆掉；
 *    2. 开头与结尾对不上 —— 少一个 </script> 或 -->，之后的整页内容都会被吞掉，
 *       访客看到一片空白，而且站方很难联想到是这里造成的。
 *  ⚠ 刻意不用 regex：这个专案的 shell 会吃掉反斜线，改用字串比对。 */
function codeProblem(code) {
  const s = String(code || '').toLowerCase();
  for (const tag of ['<!doctype', '<html', '</html', '</head', '<body', '</body']) {
    if (s.includes(tag)) return `不能包含 ${tag}… —— 只要贴服务给的那一段，不是整份网页`;
  }
  const count = (needle) => s.split(needle).length - 1;
  for (const [open, close, name] of [
    ['<script', '</script', '<script>'],
    ['<style', '</style', '<style>'],
    ['<noscript', '</noscript', '<noscript>'],
    ['<!--', '-->', '注解 <!-- -->'],
  ]) {
    if (count(open) !== count(close)) {
      return `${name} 的开头有 ${count(open)} 个、结尾有 ${count(close)} 个，对不上 —— 少了结尾会让之后的整页都消失`;
    }
  }
  return '';
}

const pastedCode = (label) =>
  z
    .string()
    .max(20000, `${label}最多 20000 字`)
    .superRefine((v, ctx) => {
      const p = codeProblem(v);
      if (p) ctx.addIssue({ code: 'custom', message: `${label}：${p}` });
    })
    .default('');

export const pageSchema = z
  .object({
    code: z.enum(PAGE_CODES),
    name: trimmed(60), // 后台显示用的页名，非前台文案
    path: routePath,
    // 导览列、页尾与浏览器标题共用的短页名。留空则退回上面的 name。
    navLabel: perLang(text(40)),
    /* 逐语系文案。站点开了 heroPlaceholders 时，首屏文字可用占位 {brand}、{文字}（强调色），
       由 render.js 的 heroPlain / heroHtml 处理。 */
    eyebrow: perLang(text(120)),
    title: perLang(text(120)),
    sub: perLang(text(120)),
    desc: perLang(text(800)),
    cta: perLang(text(60)),
    ctaVal: linkTarget.default(''),
    /* 首屏背景素材（各语系共用）：首页是首屏主视觉（也是 og:image）。
       内页读不读看站点的样板（有的站内页是 .hero-no-media，没有素材位）。 */
    bg: assetRef.default(''),
    /* 这一页自己的 head 程式码，放在全站共用那段之后。逐语系，而且不退回英文 ——
       中文页拿到英文的 <title> 比没有更糟：它会把自动产生的中文标题挤掉。
       贴了 <title>、description、og:* 这些，自动产生的同名标签就不输出（见 lib/headkeys.js）。 */
    headCode: perLang(pastedCode('此页自订 head')),
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'title', '主标题');
  });

// ---------- 全站：品牌标语（键值设定） ----------
export const siteTaglineSchema = z.object({
  brand: trimmed(60).min(1, '品牌名必填'), // 共用，同时是 SEO 模板与首屏文字的 {brand}
  slogan: perLang(text(200)),
});

// ---------- 全站：官方联系（上限 10 笔） ----------
export const CONTACT_TYPES = ['Telegram', 'WhatsApp', 'Email', 'LINE', '自訂'];

export const contactSchema = z.object({
  id: z.string().max(40),
  type: z.enum(CONTACT_TYPES).default('Telegram'),
  name: perLang(text(60)),
  // 显示文字：写帐号（@tpower）或写「官方帐号即将公布」都行，画面上是同一格
  value: perLang(text(200)),
  // 有填就是可点的连结，没填就是纯文字。就这样，不看别的栏位。
  link: linkTarget.default(''),
  sort: sortField,
  on: enabledField,
});

// ---------- 首页区块 ----------
/** 区块标题（眉标 + 主标 + 红色副标）。
 *  注：后台原型没有管理这几个区块标题的介面，是设计站写死的文字。
 *  这里先放进资料层，让样板不用写死中文；要不要开给站方编辑待确认。 */
const sectionHeading = z
  .object({
    eyebrow: perLang(text(80)),
    title: perLang(text(120)),
    sub: perLang(text(120)),
    // 内页的区块标题多半还有一段说明（首页四个标题没有，留空即不输出）
    desc: perLang(text(400)),
  })
  .superRefine((o, ctx) => needDefaultLang(o, ctx, 'title', '主标题'));

export const homeBlocksSchema = z.object({
  headings: z
    .object({
      stats: sectionHeading,
      journey: sectionHeading,
      ambassador: sectionHeading,
      sponsors: sectionHeading,
    })
    .optional(),
  // 四格导览卡：固定 4 张
  navCards: z
    .array(
      z
        .object({
          no: trimmed(8),
          name: perLang(text(60)),
          en: perLang(text(60)),
          link: linkTarget.default(''),
        })
        .superRefine((o, ctx) => {
          needDefaultLang(o, ctx, 'name', '名称');
          needDefaultLang(o, ctx, 'en', '英文小标');
        })
    )
    .length(4, '四格导览卡固定 4 张'),
  // OUR JOURNEY 时间轴：可增减
  milestones: z
    .array(
      z
        .object({
          no: trimmed(8),
          title: perLang(text(120)),
          desc: perLang(text(800)),
        })
        .superRefine((o, ctx) => needDefaultLang(o, ctx, 'title', '标题'))
    )
    .min(1, '至少要保留一列')
    .max(20),
  // AMBASSADOR LEGACY：固定 1 笔
  ambassador: z.object({
    title: perLang(text(120)),
    desc: perLang(text(800)),
    btn: perLang(text(60)),
    link: linkTarget.default(''),
    img: assetRef.default(''),
  }),
  // SPONSORSHIP HIGHLIGHTS：3 张版位
  sponsorCards: z
    .array(
      z
        .object({
          title: perLang(text(120)),
          link: linkTarget.default(''),
          img: assetRef.default(''),
        })
        .superRefine((o, ctx) => needDefaultLang(o, ctx, 'title', '卡片标题'))
    )
    .min(1, '至少要保留一列')
    .max(12),
});

// ---------- 首页：即时数据（4 笔固定） ----------
/* 除数：万+／万元+ ＝ 1e4，亿+／亿元+ ＝ 1e8（四处要同步：这里、render.js 的
   STAT_UNIT_DIVISOR、lib/stats.js 的 DIVISOR、admin/js/views/statistic.js 的 DIVISOR）。 */
export const STAT_UNITS = ['無', '万+', '亿+', '万元+', '亿元+'];
export const STAT_SOURCES = ['註冊會員數', '代理數', '累計代理佣金', '累計活動獎金'];

export const statisticSchema = z.object({
  source: z.enum(STAT_SOURCES), // 对应 API 端点回的四个栏位，后台唯读
  mult: z.number().min(0).max(100000).default(1),
  unit: z.enum(STAT_UNITS).default('無'),
  /* 这张卡「每几秒跳一次」。站方明确要 15 秒，所以留给他们设。
     每次跳多少由程式算（显示值 × 每天成长% ÷ 86400 × 这个秒数，四舍五入到整数）——
     算出来不到 1 就是不会动，那是倍率太小或数字太小，后台会直接把这句写出来。 */
  tickEverySeconds: z.number().int().min(1).max(3600).default(15),
  title: perLang(text(120)),
  desc: perLang(text(400)), // 可含 {value} 占位符
  sort: sortField,
}).superRefine((o, ctx) => needDefaultLang(o, ctx, 'title', '标题'));

/* ⚠ 这张卡曾经还有 base（基数）、perHour（每小时增加）、decimals（小数位）三栏。
   都拿掉了：
     base      站方要的是「真实值 × 倍率」，加法偏移只是多一个要解释的旋钮
     perHour   逐卡填绝对增量，四张卡量级差一万倍（2 千 vs 3 亿），根本无法凭感觉填；
               改成一个全站的「每天成长 %」，一个数字同时套用四张卡且自动按比例缩放
     decimals  固定 0。会员数带小数（13,410.02）看起来就是假的，而金额卡数字够大，
               整数就滚得很顺 —— 没有任何一张卡需要小数 */

export const statisticsSchema = z.object({
  /* ⚠ brandDomain 不在这里了 —— 它跟 API 网址与三个密钥一起搬到 content/system.json 的
     api 键，后台「系统设置」那一页管（见 server/routes/api-config.js）。
     搬的原因：取数的五个设定原本散在两个档、两个页面，站方每次都要先想「那一栏在哪」。
     它本来放这里是因为「不是秘密、换品牌时站方要能自己改」——「系统设置」同样满足这两件事。
     纯网域的验证也跟着搬过去了（原本是这里的 DOMAIN_RE）。 */
  /* 数字每天往上爬几个百分比。0＝完全不动（只显示真实值 × 倍率）。

     ⚠ 为什么是百分比而不是绝对量：四张卡量级差一万倍（代理数 2 千 vs 累计佣金 3 亿），
     同一个绝对增量对一张卡是「滚得很顺」、对另一张就是「一天涨三成」。百分比会自动
     按比例缩放，一个数字同时套用四张卡，而且「每天成长 3%」这句话本身就看得懂。

     ⚠ 真实值一小时才取一次，而且是跳跃式的 —— 两次取数之间没成长（很常见）就完全
     不动。站方要的「一直在跳」是展示层的需求，跟真实成长快慢无关，所以由这里决定。 */
  growthPerDay: z.number().min(0).max(100).default(0),

  /* ⚠ 没有「起算时间」这个栏位，是刻意的。爬升的原点是「上一次取真实值的时间」，
     所以爬升量被一小时封顶（每天 3% → 一小时最多爬 0.125%），数字永远贴着
     真实值 × 倍率。

     用一个固定的起算时间会怎样：一年之后数字是真实值的十一倍，而要拉回来就得重设
     起算时间，那一刻数字当场往下掉一大截。绑在取数时间上就没有这个问题。

     代价是每小时换真值那一刻，如果真实成长没跟上，数字会少 0.125%。但前台有楼地板
     （记在浏览器里），开着页面的人不会看到它往下掉；新进来的人没看过高的那个数。
     所以没有任何访客会看到倒退。 */

  rows: z.array(statisticSchema).length(4, '数据卡固定 4 张'),
})
  .superRefine((o, ctx) => {
    // 四张卡必须各取一个不同的来源。重复的话两张卡会显示同一个数字，
    // 而这是唯一挡住误操作与「还原到一份坏掉的旧快照」的地方。
    const seen = new Map();
    (o.rows || []).forEach((r, i) => {
      if (seen.has(r.source)) {
        ctx.addIssue({
          code: 'custom',
          message: `取数来源「${r.source}」重复（第 ${seen.get(r.source) + 1} 张与第 ${i + 1} 张），四张卡必须各取一个`,
          path: ['rows', i, 'source'],
        });
      } else {
        seen.set(r.source, i);
      }
    });
  });

// ---------- 活动 ----------
export const promotionSchema = z
  .object({
    id: z.string().max(40),
    label: perLang(text(60)),
    title: perLang(text(200)),
    desc: perLang(text(2000)),
    img: perLang(assetRef), // 活动图片逐语系
    end: datetime,
    linkVal: linkTarget.default(''),
      sort: sortField,
    on: enabledField,
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'title', '活动标题');
  });

// ---------- 游戏 ----------
export const gameCategorySchema = z
  .object({
    // 前台程式识别键，建立后不建议修改
    code: z.string().regex(/^[A-Za-z0-9_]{1,20}$/, '分类代码限英数与底线'),
    label: trimmed(40),
    name: perLang(text(60)),
    sort: sortField,
    on: enabledField,
  })
  .superRefine((o, ctx) => needDefaultLang(o, ctx, 'name', '显示名称'));

export const gameProviderSchema = z
  .object({
    id: z.string().max(40),
    cat: z.string().max(20), // → gameCategories.code
    name: trimmed(80).min(1, '游戏商名称必填'), // 品牌名，不随语系翻译
    desc: perLang(text(1000)),
    logo: assetRef.default(''), // 品牌标志不分语系（留空时样板退回文字 wordmark）
    featured: z.boolean().default(false),
    tags: perLang(z.array(text(40)).max(3)), // 重点标签
    featuredImage: assetRef.default(''), // 重点预览图
    sort: sortField,
    on: enabledField,
  })
  .superRefine((o, ctx) => {
    /* 重点游戏商的预览图是条件式必填 —— .featured-provider-visual 有 min-height:430px
       但没有底图，少了图就是一大块空白。标签不必填，样板有几个画几个。 */
    if (!o.featured) return;
    if (!o.featuredImage) {
      ctx.addIssue({ code: 'custom', message: '列为重点游戏商时，重点预览图必填', path: ['featuredImage'] });
    }
  });

// ---------- 品牌影像 ----------
export const MEDIA_CATS = ['代言人', '贊助', '品牌', '音樂'];

export const mediaSchema = z
  .object({
    id: z.string().max(40),
    cat: z.enum(MEDIA_CATS),
    title: perLang(text(200)),
    note: perLang(text(800)),
    cover: perLang(assetRef), // 封面逐语系
    url: optionalUrl.default(''),
    sort: sortField,
    on: enabledField,
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'title', '影像标题');
  });

// ---------- 品牌资讯 ----------
export const articleCategorySchema = z
  .object({
    code: z.string().regex(/^[a-z0-9_-]{1,20}$/, '分类代码限小写英数、底线与连字号'),
    label: trimmed(40),
    name: perLang(text(60)),
    sort: sortField,
    on: enabledField,
  })
  .superRefine((o, ctx) => needDefaultLang(o, ctx, 'name', '显示名称'));

export const articleSchema = z
  .object({
    id: z.string().max(40),
    slug: z.string().regex(/^[a-z0-9-]{1,80}$/, '网址代称限小写英数与连字号'),
    cat: z.string().max(20), // → articleCategories.code（统一用 code，不用显示名称）
    label: perLang(text(60)),
    title: perLang(text(200)),
    excerpt: perLang(text(1000)),
    cover: assetRef.default(''),
    /* 内文＝开场段落 + 若干「小标题 + 说明」。
       ⚠ 存的是**纯文字**，不是 HTML —— HTML 由 render.js 组出来（articleBodyHtml）。
       原本是一个富文本栏位，站方要自己弄出 <h3> 才排得对，而工具列根本没有那颗按钮；
       前台也只吃这两种（彈窗靠 h3 切段），所以直接做成两个栏位比给一个编辑器直觉得多。
       附带好处：没有 HTML 就没有消毒问题，富文本消毒那一整支已经拿掉。 */
    lead: perLang(text(600)),
    sections: z
      .array(
        z.object({
          title: perLang(text(120)),
          desc: perLang(text(2000)),
        })
      )
      .max(20)
      .default([]),
    featured: z.boolean().default(false), // 最新焦点：全站仅一篇
    sort: sortField,
    on: enabledField,
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'title', '资讯标题');
    needDefaultLang(o, ctx, 'lead', '开场段落');
  });

// ---------- 各内页的专属文案（单一物件，一页一把钥匙） ----------
/* 内页首屏以外的区块文案（区块标题、六大优势细节、代理招募支援清单、四个政策面板…）
   全收在 page_blocks 底下、一页一把钥匙 —— 它们是页面文案不是可增删的资料列。
   图示一律不存这里，对照表在 server/lib/render.js。 */

// 收尾行动区（眉标＋主标＋副标＋说明＋按钮）。games 的 <h2> 是两行，所以多一个 title2。
const ctaShape = {
  eyebrow: perLang(text(80)),
  title: perLang(text(120)),
  title2: perLang(text(120)),
  sub: perLang(text(120)),
  desc: perLang(text(800)),
  cta: perLang(text(60)),
  ctaVal: linkTarget.default(''),
};
const ctaBlock = (extra = {}) => z.object({ ...ctaShape, ...extra });

// 标题＋说明的小卡（优势卡、支援卡、流程卡、政策卡都是这个形状）
const titleDescRow = (descMax = 400) =>
  z.object({ title: perLang(text(120)), desc: perLang(text(descMax)) }).superRefine((row, ctx) => {
    needDefaultLang(row, ctx, 'title', '标题');
  });

// ---- 核心优势 ----
export const ADVANTAGE_VARIANTS = ['speed', 'gameCategories', 'transaction', 'security', 'fairness', 'support'];

/** 六大优势右侧的专属视觉。六种版型共用这一个形状，各自只用得到其中几个栏位
 *  （分成六个 schema 会让后台要为每种版型各写一份表单，不划算）。 */
const advantageVisual = z
  .object({
    coreLabel: trimmed(40).default(''), // 拉丁装饰字：SYSTEM CORE / SECURITY
    coreState: trimmed(40).default(''), // ONLINE
    coreNote: trimmed(60).default(''), // MULTI-LAYER PROTECTION
    headLabel: trimmed(40).default(''), // LIVE SUPPORT
    rowState: trimmed(40).default(''), // 客服面板每列右侧的状态字（五列相同）
    // 讯号状态 / 交易流程 / 防护层：只有一行标签
    status: z.array(z.object({ label: perLang(text(60)) })).max(8).default([]),
    steps: z.array(z.object({ label: perLang(text(60)) })).max(8).default([]),
    layers: z.array(z.object({ label: perLang(text(60)) })).max(8).default([]),
    /* 公平透明（标题＋说明）、专业服务（标签）、丰富游戏的图卡（标签＋图）共用这一组；
       各站用到哪几个栏位看样板。img 各语系共用。 */
    items: z
      .array(
        z.object({
          title: perLang(text(80)),
          desc: perLang(text(200)),
          label: perLang(text(60)),
          img: assetRef.default(''),
        })
      )
      .max(8)
      .default([]),
    footer: perLang(text(200)),
  })
  .default({});

const advantageItem = z
  .object({
    id: z.string().regex(/^[a-z0-9-]{1,30}$/, '锚点代码限小写英数与连字号'),
    variant: z.enum(ADVANTAGE_VARIANTS),
    en: trimmed(60).default(''), // 细节区的拉丁小标
    title: perLang(text(120)),
    // 细节段的标题，留空＝沿用 title
    detailTitle: perLang(text(120)),
    summary: perLang(text(400)), // 总览卡的短说明
    subtitle: perLang(text(120)),
    desc: perLang(text(800)),
    tags: perLang(z.array(text(40)).max(8)),
    short: perLang(text(20)), // 关系图节点的两字标签
    // 细节段的满版背景图（各语系共用）。留空就只有底色渐层
    img: assetRef.default(''),

    visual: advantageVisual,
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'title', '优势标题');
    if (!siteOptional('adv.short')) needDefaultLang(o, ctx, 'short', '生态圈节点文字');
    const v = o.visual || {};
    // 跟后台「视觉区」各清单的 when / site 对齐：画面上看得到那张清单，那一栏才必填
    const rowsNeed = (list, field, label) =>
      (v[list] || []).forEach((r, i) => needDefaultLang(r, ctx, field, label, ['visual', list, i]));
    if (o.variant === 'speed') rowsNeed('status', 'label', '讯号状态');
    if (o.variant === 'transaction') rowsNeed('steps', 'label', '交易流程');
    if (o.variant === 'security' && adminShown('adv.securityCore')) rowsNeed('layers', 'label', '防护层');
    if (o.variant === 'fairness' && adminShown('adv.fairnessItems')) rowsNeed('items', 'title', '项目清单标题');
    if (o.variant === 'gameCategories' && adminShown('adv.gameCards')) rowsNeed('items', 'label', '游戏图卡标签');
    if (o.variant === 'support') rowsNeed('items', 'label', '服务项目');
    // 哪个 variant 必须有哪张清单，看站点的样板（server/sites/<站>.js 的 advantageRequired）
    const req = site.advantageRequired[o.variant];
    if (req && !(v[req[0]] || []).length) {
      ctx.addIssue({ code: 'custom', message: `${req[1]}：至少要一列`, path: ['visual', req[0]] });
    }
  });

const advantagesBlock = z.object({
  headings: z.object({ overview: sectionHeading, ecosystem: sectionHeading }),
  // .ecosystem-node-1 ~ -6 的 CSS 只到第 6 颗，所以固定 6 笔
  items: z.array(advantageItem).length(6, '核心优势固定 6 项'),
  ecosystem: z.object({ coreLabel: trimmed(40).default('') }),
  final: ctaBlock(),
});

// ---- 游戏内容 ----
const gamesBlock = z.object({
  headings: z.object({ providers: sectionHeading, featured: sectionHeading, ecosystem: sectionHeading }),
  ecosystem: z.object({
    coreLabel: trimmed(40).default(''),
    // 生态图的第 6 颗节点不是游戏分类，是设计站补的「更多内容」
    // 节点代码留空＝不画这颗节点，名称才跟着不必填
    more: z
      .object({ label: trimmed(20).default(''), name: perLang(text(60)) })
      .superRefine((o, ctx) => {
        if (o.label) needDefaultLang(o, ctx, 'name', '节点名称');
      })
      .default({}),
  }),
  final: ctaBlock(),
});

// ---- 热门活动 ----
const promotionsBlock = z.object({
  // 进行中 / 往期回顾的分页标签。设计站把两者做成两条路由，点了会换页不是卷动，
  // 所以这里是路由分页（跟品牌影像的四个子页同一个做法）。
  tabs: z.object({ current: perLang(text(60)), past: perLang(text(60)) }).superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'current', '进行中');
    needDefaultLang(o, ctx, 'past', '往期回顾');
  }),
  headings: z.object({ current: sectionHeading, past: sectionHeading }),
  // /promotions/archive 的首屏。这条路径不在规格的 15 条里，所以不进 pages 那张表，
  // 文案放这里（跟 brandCategory 同样的栏位组）。page 是 SEO 标题模板的 {page}。
  archive: z
    .object({
      page: perLang(text(120)),
      eyebrow: perLang(text(120)),
      title: perLang(text(120)),
      sub: perLang(text(120)),
      desc: perLang(text(800)),
      cta: perLang(text(60)),
        ctaVal: linkTarget.default(''),
    })
    .superRefine((o, ctx) => {
      needDefaultLang(o, ctx, 'title', '主标题');
      }),
  cta: perLang(text(60)), // 卡片按钮字，箭头由样板补
  pastCta: perLang(text(60)),
});

// ---- 品牌资讯 ----
const blogBlock = z.object({
  headings: z.object({ featured: sectionHeading, latest: sectionHeading }),
});

// ---- 品牌影像 ----
/** 四个子页（代言人／贊助／品牌／音樂）。
 *  这四条路径不在 pages 那张表里，首屏文案跟着分类设定走。
 *  page 是 SEO 标题模板的 {page}，seo.rows 找不到该路径时才会用到。 */
const brandCategory = z
  .object({
    cat: z.enum(MEDIA_CATS),
    path: routePath,
    label: perLang(text(40)), // 分页标签，同时是影像卡上的分类名
    page: perLang(text(120)),
    eyebrow: perLang(text(120)),
    title: perLang(text(120)),
    sub: perLang(text(120)),
    desc: perLang(text(800)),
    cta: perLang(text(60)),
    ctaVal: linkTarget.default(''),
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'label', '分页标签');
    needDefaultLang(o, ctx, 'title', '主标题');
  });

const brandBlock = z.object({
  headings: z.object({ archive: sectionHeading }),
  // 规格 §4：品牌影像另有「版权声明」（逐语系，键值设定）
  rights: z.object({ label: trimmed(80).default(''), text: perLang(text(1000)) }),
  categories: z.array(brandCategory).length(4, '品牌影像分类固定 4 笔'),
});

// ---- 官方联系 ----
const contactBlock = z.object({ heading: sectionHeading });

// ---- 使用指南 ----
const guideSection = z
  .object({
    code: z.string().regex(/^[a-z0-9-]{1,20}$/, '指南代码限小写英数与连字号'),
    path: routePath,
    name: perLang(text(60)),
    /* 每段自己的首屏（站点开了 guideHeroPerSection 才用：/guide/<code> 的首屏随分页换，
       否则一律用 pages 表里 guide 那笔）。page / desc 是子页的 SEO 页名与描述。 */
    page: perLang(text(120)),
    eyebrow: perLang(text(120)),
    title: perLang(text(120)),
    sub: perLang(text(120)),
    desc: perLang(text(800)),
    cta: perLang(text(60)),
    ctaVal: linkTarget.default(''),
    // 分页面板右侧的手机示意图（各语系共用）。留空就沿用第一个分页的图，都空就不画
    image: assetRef.default(''),
    heading: sectionHeading,
    steps: z.array(titleDescRow(600)).min(1, '至少要保留一列').max(12),
    notice: z.object({ title: perLang(text(60)), desc: perLang(text(600)) }),
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'name', '分页名称');
  });

const guideBlock = z.object({
  sections: z.array(guideSection).min(1, '至少要保留一列').max(12),
});

// ---- 常见问题 ----
const faqBlock = z.object({
  items: z
    .array(
      z.object({ q: perLang(text(200)), a: perLang(text(1200)) }).superRefine((o, ctx) => {
        needDefaultLang(o, ctx, 'q', '问题');
        needDefaultLang(o, ctx, 'a', '回答');
      })
    )
    .min(1, '至少要保留一列')
    .max(50),
});

// ---- 代理招募 ----
const partnersBlock = z.object({
  headings: z.object({ benefits: sectionHeading, steps: sectionHeading }),
  benefits: z.array(titleDescRow(400)).min(1, '至少要保留一列').max(12),
  support: z.object({
    eyebrow: perLang(text(80)),
    title: perLang(text(120)),
    sub: perLang(text(120)),
    desc: perLang(text(800)),
    items: z.array(titleDescRow(300)).min(1, '至少要保留一列').max(12),
  }),
  steps: z.array(titleDescRow(400)).min(1, '至少要保留一列').max(8),
  // 右下角的拉丁字样（LET'S GROW / TOGETHER）是装饰，不翻译
  finalCta: ctaBlock({
    btn: perLang(text(60)),
    markTop: trimmed(40).default(''),
    markBottom: trimmed(40).default(''),
  }),
});

// ---- 信任与安全 ----
/** code 是载重栏位：面板 id、分页按钮 id 与 aria-controls 都靠它，
 *  页尾／外部连结的 /compliance#privacy 这种深连结也靠它。 */
const compliancePanel = z
  .object({
    code: z.string().regex(/^[a-z0-9-]{1,20}$/, '面板代码限小写英数与连字号'),
    tab: perLang(text(60)),
    kicker: trimmed(60).default(''), // 拉丁小标，分页按钮与面板头部共用
    title: perLang(text(120)),
    sub: perLang(text(120)),
    desc: perLang(text(800)),
    policies: z.array(titleDescRow(600)).max(12).default([]),
    notice: z.object({ label: trimmed(60).default(''), desc: perLang(text(600)) }).default({}),
  })
  .superRefine((o, ctx) => {
    needDefaultLang(o, ctx, 'tab', '分页标签');
    needDefaultLang(o, ctx, 'title', '标题');
  });

const complianceBlock = z.object({
  headings: z.object({ policies: sectionHeading }),
  panels: z.array(compliancePanel).min(1, '至少要保留一列').max(8),
});

export const pageBlocksSchema = z.object({
  advantages: advantagesBlock,
  games: gamesBlock,
  promotions: promotionsBlock,
  blog: blogBlock,
  brand: brandBlock,
  contact: contactBlock,
  guide: guideBlock,
  faq: faqBlock,
  partners: partnersBlock,
  compliance: complianceBlock,
});

// ---------- 全站设定（站台与语系） ----------
export const settingsSchema = z
  .object({
    baseUrl: httpsUrl.refine((u) => !u.endsWith('/'), 'baseUrl 结尾不要斜线'),
    languages: z.array(z.enum(LANGS)).min(1).max(LANGS.length),
    defaultLanguage: z.enum(LANGS).default(DEFAULT_LANG),
    // 语系 → 网址前缀与 html lang（只需列出启用的语系）
    langMeta: z.partialRecord(
      z.enum(LANGS),
      z.object({
        slug: z.string().regex(/^[a-z-]{2,10}$/, '网址前缀限小写字母与连字号'),
        htmlLang: trimmed(20),
      })
    ),
    /* 站方自己贴的程式码，原样放进每一页：headCode 在 </head> 前、bodyCode 在 </body> 前。
       Google Analytics、Search Console、FB 像素……服务叫你贴什么就贴什么。

       ⚠ 这两格会在整个网站上执行任意程式，而后台与网站同一个网域 ——
       贴进恶意程式码的话，管理员登入后台时顺手逛网站，那段程式就能用管理员身份做事。
       这是站方选的取舍（不想每开一个服务就来加栏位），这里不做「安全」检查，
       只挡「会让整站坏掉」的贴法，见 pastedCode。每次修改都会进操作纪录。

       取代原本的 gaId 与 googleVerificationMetaTokens（两者上线时都是空的）。 */
    headCode: pastedCode('放在 <head> 的程式码'),
    bodyCode: pastedCode('放在 <body> 结尾的程式码'),
    verificationFileNames: z.array(z.string().regex(/^google[\w-]+\.html$/)).max(5).default([]),
    copyright: perLang(text(300)), // 可用 {year}、{brand} 占位字
    // 页尾 logo 下方那段简介。留空＝沿用首页首屏的描述文案（pages[home].desc）
    footerBlurb: perLang(text(400)),
    /* 站台 Logo 与网站图示（favicon），各语系共用。
       留空＝沿用预设：logo 退回 /uploads/logo.webp（seed 灌进去的原图），
       favicon 则不输出任何 <link>（浏览器自己去要 /favicon.ico）。
       favicon 要走上传端点的 kind=favicon —— 那条会转成正方形 PNG，见 routes/uploads.js。 */
    logo: assetRef.default(''),
    favicon: assetRef.default(''),
    // 页首右侧两颗按钮。设计站写死为「立即加入」「使用指南」，原型没有管理介面，
    // 放这里让样板不用写死中文。
    headerCta: z
      .object({
        registerLabel: perLang(text(40)),
        /* 用 linkTarget 不是 routePath —— 站方常常要把「立即加入」指到遊戲站
           那種外部網址，routePath 只收 / 開頭的站內路徑會直接驗證失敗。 */
        registerPath: linkTarget.default('https://example.invalid'),
        guideLabel: perLang(text(40)),
        guidePath: linkTarget.default('/guide'),
      })
      .default({}),
    redirect: z.object({
      rules: z
        .array(z.object({ contains: z.string().regex(/^[a-zA-Z0-9-]{1,20}$/), to: z.enum(LANGS) }))
        .max(20),
    }),
  })
  .superRefine((s, ctx) => {
    if (!s.languages.includes(s.defaultLanguage)) {
      ctx.addIssue({ code: 'custom', message: '预设语系必须在启用语系清单内', path: ['defaultLanguage'] });
    }
    for (const l of s.languages) {
      if (!s.langMeta[l]) ctx.addIssue({ code: 'custom', message: `langMeta 缺少 ${l}`, path: ['langMeta'] });
    }
    for (const [i, r] of s.redirect.rules.entries()) {
      if (!s.languages.includes(r.to)) {
        ctx.addIssue({ code: 'custom', message: `导向目标 ${r.to} 未启用`, path: ['redirect', 'rules', i] });
      }
    }
  });


// ---------- 各 entity 的顶层 schema（key 对应 content/<key>.json） ----------
export const entitySchemas = {
  settings: settingsSchema,
  site_tagline: siteTaglineSchema,
  pages: z.array(pageSchema).length(PAGE_CODES.length, `页面固定 ${PAGE_CODES.length} 笔`),
  contacts: z.array(contactSchema).min(1, '至少要保留一列').max(10, '官方联系上限 10 笔'),
  home_blocks: homeBlocksSchema,
  page_blocks: pageBlocksSchema,
  statistics: statisticsSchema,
  promotions: z.array(promotionSchema).max(500),
  game_categories: z.array(gameCategorySchema).min(1, '至少要保留一列').max(50),
  game_providers: z.array(gameProviderSchema).max(500),
  media: z.array(mediaSchema).max(1000),
  article_categories: z.array(articleCategorySchema).min(1, '至少要保留一列').max(50),
  articles: z.array(articleSchema).max(5000),
};

export const ENTITY_KEYS = Object.keys(entitySchemas);



/** 验证并正规化某个 entity 的完整内容。
 *  除 schema 验证外，还套用跨列规则（单例焦点、外键、富文本消毒）。 */
export function validateEntity(key, data, { refs = {} } = {}) {
  const schema = entitySchemas[key];
  if (!schema) throw Object.assign(new Error(`未知的资料类别：${key}`), { status: 404 });

  // 从 LANGS 移除一个语系时，磁碟上的 settings.langMeta 还会留着那一键。
  // partialRecord 对不认识的键是直接报错（Invalid key in record），所以站方
  // 下一次存「全站设定」会拿到 422 —— 而画面上根本没有那个语系可以删。
  // 先静静丢掉才是对的：少开一个语系不该让后台存不了东西。
  if (key === 'settings' && data && typeof data.langMeta === 'object' && data.langMeta) {
    const known = Object.fromEntries(Object.entries(data.langMeta).filter(([l]) => LANGS.includes(l)));
    const dropped = Object.keys(data.langMeta).filter((l) => !LANGS.includes(l));
    if (dropped.length) {
      console.log(`[schema] settings.langMeta 丢掉已停用的语系键：${dropped.join('、')}`);
      data = { ...data, langMeta: known };
    }
  }

  const parsed = schema.parse(data);

  if (key === 'articles') {
    // 「最新焦点」全站仅一篇：多篇时只留排序最前的
    const featured = parsed.filter((a) => a.featured);
    if (featured.length > 1) {
      const keep = [...featured].sort((x, y) => (x.sort || 0) - (y.sort || 0))[0];
      for (const a of featured) if (a !== keep) a.featured = false;
    }
    const slugs = new Set();
    for (const a of parsed) {
      if (slugs.has(a.slug)) throw Object.assign(new Error(`网址代称重复：${a.slug}`), { status: 422 });
      slugs.add(a.slug);
    }
    assertFk(parsed, refs.article_categories, 'article_categories');
  }

  if (key === 'game_providers') assertFk(parsed, refs.game_categories, 'game_categories');

  // 分类本身：code 是被别的实体以字串参照的键，所以存档时要守两件事。
  // 少了这两道，把 SLOT 改成 SLOTX 会当场让 8 家游戏商变孤儿 —— 分页列出现
  // 一个空的 SLOTX、8 张卡的分类标签退化成代码本身，而且不会有任何错误。
  if (key === 'game_categories' || key === 'article_categories') {
    assertUniqueCode(parsed);
    assertNoOrphan(parsed, key === 'game_categories' ? refs.game_providers : refs.articles, key);
  }

  return parsed;
}

/** 分类代码必须唯一 —— 重复的话外键参照会指到不确定的那一笔 */
function assertUniqueCode(rows) {
  const seen = new Map();
  for (const [i, r] of rows.entries()) {
    if (seen.has(r.code)) {
      throw Object.assign(
        new Error(`分类代码重复：「${r.code}」出现在第 ${seen.get(r.code) + 1} 笔与第 ${i + 1} 笔`),
        { status: 422 }
      );
    }
    seen.set(r.code, i);
  }
}

/** 改名或删除分类之前，先看底下还有没有挂着的资料。
 *  有的话挡下并讲清楚影响几笔 —— 让站方自己决定要先改挂还是放弃。 */
function assertNoOrphan(rows, dependents, key) {
  if (!Array.isArray(dependents) || !dependents.length) return;
  const codes = new Set(rows.map((r) => r.code));
  const orphans = new Map();
  for (const d of dependents) {
    if (d.cat && !codes.has(d.cat)) orphans.set(d.cat, (orphans.get(d.cat) || 0) + 1);
  }
  if (!orphans.size) return;
  const what = key === 'game_categories' ? '游戏商' : '品牌资讯';
  const list = [...orphans].map(([c, n]) => `「${c}」还有 ${n} 笔`).join('、');
  throw Object.assign(
    new Error(`这样改会让${what}找不到分类：${list}。请先把那些资料改挂到别的分类，或保留原本的分类代码。`),
    { status: 422 }
  );
}

function assertFk(rows, categories, label) {
  const codes = new Set((categories || []).map((c) => c.code));
  if (!codes.size) return; // 分类还没建立时不挡
  for (const [i, r] of rows.entries()) {
    if (r.cat && !codes.has(r.cat)) {
      throw Object.assign(new Error(`第 ${i + 1} 笔的分类「${r.cat}」不存在于 ${label}`), { status: 422 });
    }
  }
}

/** 逐语系取值，该语系留空就显示主语系（规格 §2）。
 *  主语系是必填的（needDefaultLang 挡着），所以这里一定拿得到值。 */
export function langVal(field, lang) {
  if (field === null || field === undefined) return '';
  if (typeof field !== 'object' || Array.isArray(field)) return field;
  const v = field[lang];
  if (v !== undefined && String(v).trim() !== '') return v;
  return field[DEFAULT_LANG] ?? '';
}

/** 把 zod 错误整理成好读的讯息阵列 */
export function zodIssues(err) {
  return (err.issues || []).map((i) => `${i.path.join('.')}: ${i.message}`);
}
