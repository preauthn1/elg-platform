/* 内页专属文案（entity: page_blocks）
   ------------------------------------------------------------------
   page_blocks 是「一页一把钥匙」的单一物件，装的是设计站上原本写死、原型没有管理介面的内页文案。
   决定：不新增第 12 个选单项目 —— 它折进「页面首屏设定」，做成每页一个子分页；
   唯一的例外是 brand.rights（版权声明），照原型放在「品牌影像管理」画面。

   这里用一份「栏位规格表」驱动渲染（描述资料形状，不是内容）：
     group  → fieldset，一栏一列
     list   → 内联表格，可增减（含「至少要保留一列」护栏）；太深的列改用弹窗编辑
   区段、栏位、表格栏都可以带 site: '<键>' —— 该站的 server/sites/<站>.js 把这个键列进
   admin.hidden 就不画（那一站的样板不读这个栏位）。 */
import * as store from '../store.js';
import { openDlg, closeDlg, markDlgSaved } from '../shell.js';
import {
  esc,
  headingFields,
  ACTIONS,
  sw,
  selectOf,
  langInput,
  getInlineLang,
  renderView,
  paintDlg,
  addRow,
  delRow,
  assetThumb,
  assetInput,
  DESC_OPTIONAL_PH,
  OPTIONAL_PH,
  BTN_OPTIONAL_PH,
  SECTION_OPTIONAL_PH,
} from '../ui.js';
import { shown, optional } from '../site.js';

const MODEL = 'page_blocks';
/* ⚠ 這幾個中文字串是「存進 content.json 的識別碼」，server 端用 z.enum 驗證，
   所以必須維持原樣（繁體）—— 轉成簡體會讓存檔直接 422。
   畫面上要顯示簡體，靠下面的 *_LABEL 對照表（跟語系的 LANG_NAME 同一個做法）。
   之後若要徹底解決，正解是把識別碼換成 ASCII code（像遊戲分類的 SLOT/SPORTS），
   那需要連 schema 與既有資料一起遷移，不在這次範圍。 */
const MEDIA_CATS = [
  { v: '代言人', t: '代言人' },
  { v: '贊助', t: '赞助' },
  { v: '品牌', t: '品牌' },
  { v: '音樂', t: '音乐' },
];

/* ---------- 可重复使用的栏位组 ---------- */
/* 没给 ph 的逐语系栏位，English 空栏会显示「必填」，要跟 server/schema/content.js 的
   needDefaultLang 一致：前台留空就不输出的给选填 ph，留空会开天窗的才必填。 */
const HEADING = [
  { k: 'eyebrow', t: '英文眉标', type: 'lang', ph: OPTIONAL_PH },
  { k: 'title', t: '主标题', type: 'lang' },
  { k: 'sub', t: '副标题（强调色）', type: 'lang', ph: OPTIONAL_PH },
  // 选填：不给 ph 的话 English 空栏会显示「必填」（见 ui.js 的 DESC_OPTIONAL_PH）
  { k: 'desc', t: '说明文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
];
/* 收尾行动区。三页各有一套 —— 别合成一份共用的清单。

   这三页的样板长得像但不一样：核心优势的第二行标题吃 sub（跟主标题同一行、强调色），
   游戏内容吃 title2（换行），代理招募的按钮文字吃 btn 而不是 cta。
   之前是一份联集，于是每一页都摆着两三个「填了完全没反应」的输入框
   （代理招募那张卡的「主标题第二行」与「按钮文字」就是，样板根本没读）。
   站方填了没反应，又没有任何错误可循 —— 比少一个栏位难查得多。

   ⚠ 要改这里之前先看对应的样板读了哪几个 final.xxx：
     templates/pages/advantages.njk  eyebrow / title / sub  / desc / cta / ctaUrl
     templates/pages/games.njk       eyebrow / title / title2 / desc / cta / ctaUrl
     templates/pages/partners.njk    eyebrow / title / sub  / desc / btn / link */
const CTA_BASE = [
  { k: 'eyebrow', t: '英文眉标', type: 'lang', ph: OPTIONAL_PH },
  { k: 'title', t: '主标题', type: 'lang', ph: SECTION_OPTIONAL_PH },
];
const CTA_TAIL = [
  { k: 'desc', t: '说明文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
  { k: 'cta', t: '按钮文字', type: 'lang', ph: BTN_OPTIONAL_PH },
  { k: 'ctaVal', t: '连结内容', type: 'link' },
];
// 核心优势：第二行是主标题旁的强调色文字
const CTA_ADVANTAGES = [...CTA_BASE, { k: 'sub', t: '主标题第二行（强调色）', type: 'lang', ph: OPTIONAL_PH }, ...CTA_TAIL];
// 游戏内容：第二行是换行后的第二行标题
const CTA_GAMES = [...CTA_BASE, { k: 'title2', t: '主标题第二行', type: 'lang', ph: OPTIONAL_PH }, ...CTA_TAIL];
const TITLE_DESC = [
  { k: 'title', t: '标题', type: 'lang' },
  { k: 'desc', t: '说明', type: 'langarea', ph: DESC_OPTIONAL_PH },
];
/* 每個 variant 只用得到自己那一套視覺欄位 —— 對照 templates/<站>/pages/advantages.njk
   的 {% if a.variant == ... %} 分支，跟那邊的 v.xxx 一一對上。
   gameCategories 的方格：有的站拿「特色標籤」攤開（沒有自己的資料），有的站是自帶圖片的圖卡（adv.gameCards）。 */
const forVariant =
  (...vs) =>
  (r) =>
    vs.includes(r.variant);
// 防护层那一组（核心标签／注记／防护层清单）只有部分站点的样板有
const SECURITY_CORE = shown('adv.securityCore') ? ['security'] : [];

const LABEL_ONLY = [{ k: 'label', t: '标签', type: 'lang' }];

/* ---------- 每一页的规格 ---------- */
export const PB_PAGES = [
  {
    key: 'advantages',
    name: '核心优势',
    path: '/advantages',
    sections: [
      {
        kind: 'list',
        t: '六大优势',
        heading: 'headings.overview',
        p: 'items',
        fixed: 6,
        cols: [
          { k: 'id', t: '锚点代码', type: 'ro', w: 130 },
          { k: 'variant', t: '版型', type: 'ro', w: 140 },
          { k: 'en', t: '拉丁小标', type: 'text', w: 250 },
          { k: 'title', t: '标题', type: 'lang', w: 215 },
          { k: 'summary', t: '总览卡短说明', type: 'langarea', ph: DESC_OPTIONAL_PH },
        ],
        detail: {
          title: '核心优势细节',
          sections: [
            {
              kind: 'group',
              t: '基本',
              p: '',
              f: [
                { k: 'id', t: '锚点代码', type: 'ro' },
                { k: 'variant', t: '版型', type: 'ro' },
                { k: 'en', t: '拉丁小标', type: 'text' },
                { k: 'title', t: '标题', type: 'lang' },
                { k: 'detailTitle', t: '细节段标题', type: 'lang', ph: '选填 · 留空＝沿用上面的标题', site: 'adv.detailTitle' },
                { k: 'summary', t: '总览卡短说明', type: 'langarea', ph: DESC_OPTIONAL_PH },
                { k: 'subtitle', t: '细节副标', type: 'lang', ph: OPTIONAL_PH },
                { k: 'desc', t: '细节说明', type: 'langarea', ph: DESC_OPTIONAL_PH },
                {
                  k: 'short',
                  t: '生态圈节点文字',
                  type: 'lang',
                  note: '页面最下方那张环形图的节点，版面只放得下两个字',
                  ...(optional('adv.short') ? { ph: '选填 · 留空＝沿用上面的标题' } : {}),
                },
                /* 丰富游戏、快速存取不读细节段背景图，所以不给栏位（填了没反应比没有栏位更难查）。 */
                {
                  k: 'img',
                  t: '背景大图',
                  type: 'img',
                  tw: 170,
                  th: 68,
                  hint: '细节段的满版背景 · 各语系共用 · 留空＝只有底色',
                  when: forVariant('speed', 'security', 'fairness', 'support'),
                  site: 'adv.img',
                },
              ],
            },
            {
              kind: 'langlist',
              t: '特色标签',
              p: 'tags',
              max: 8,
              // 有的站点丰富游戏／专业服务不显示特色标签
              when: (r) => shown('adv.tagsAll') || !['gameCategories', 'support'].includes(r.variant),
            },
            {
              kind: 'group',
              t: '视觉区文字',
              p: 'visual',
              f: [
                { k: 'coreLabel', t: '核心标签（拉丁）', type: 'text', when: forVariant('speed', ...SECURITY_CORE) },
                { k: 'coreState', t: '核心状态（拉丁）', type: 'text', when: forVariant('speed') },
                { k: 'coreNote', t: '核心注记（拉丁）', type: 'text', when: forVariant('security'), site: 'adv.securityCore' },
                { k: 'headLabel', t: '面板标签（拉丁）', type: 'text', when: forVariant('support'), site: 'adv.supportPanel' },
                { k: 'rowState', t: '列状态字（拉丁）', type: 'text', when: forVariant('support'), site: 'adv.supportPanel' },
                { k: 'footer', t: '底部横条文字', type: 'lang', ph: OPTIONAL_PH, when: forVariant('support') },
              ],
            },
            { kind: 'list', t: '讯号状态', p: 'visual.status', when: forVariant('speed'), min: 1, max: 8, cols: LABEL_ONLY },
            { kind: 'list', t: '交易流程', p: 'visual.steps', when: forVariant('transaction'), min: 1, max: 8, cols: LABEL_ONLY },
            {
              kind: 'list',
              t: '防护层',
              p: 'visual.layers',
              when: forVariant('security'),
              site: 'adv.securityCore',
              min: 1,
              max: 8,
              cols: LABEL_ONLY,
            },
            {
              kind: 'list',
              t: '项目清单',
              p: 'visual.items',
              when: forVariant('fairness'),
              site: 'adv.fairnessItems',
              min: 1,
              max: 8,
              cols: TITLE_DESC,
            },
            {
              kind: 'list',
              t: '游戏图卡',
              p: 'visual.items',
              when: forVariant('gameCategories'),
              site: 'adv.gameCards',
              min: 0,
              max: 8,
              note: '设计稿是 6 张；图片各语系共用',
              cols: [
                { k: 'label', t: '标签', type: 'lang', w: 220 },
                { k: 'img', t: '图片', type: 'img', tw: 52, th: 62, iw: 220 },
              ],
            },
            { kind: 'list', t: '服务项目', p: 'visual.items', when: forVariant('support'), min: 1, max: 8, cols: LABEL_ONLY },
          ],
        },
      },
      {
        kind: 'group',
        t: '生态圈',
        p: 'ecosystem',
        heading: 'headings.ecosystem',
        f: [
          { k: 'coreLabel', t: '核心标签（拉丁）', type: 'text' },
        ],
      },
      { kind: 'group', t: '收尾行动区', p: 'final', f: CTA_ADVANTAGES },
    ],
  },
  {
    key: 'games',
    name: '游戏内容',
    path: '/games',
    sections: [
      { kind: 'group', t: '游戏商区块标题', p: 'headings.providers', f: HEADING },
      { kind: 'group', t: '重点游戏商区块标题', p: 'headings.featured', f: HEADING },
      { kind: 'slot', name: 'gameCards' },
      { kind: 'group', t: '生态圈区块标题', p: 'headings.ecosystem', f: HEADING },
      {
        kind: 'group',
        t: '生态圈',
        p: 'ecosystem',
        f: [{ k: 'coreLabel', t: '核心标签（拉丁）', type: 'text' }],
      },
      {
        kind: 'group',
        t: '生态圈第 6 颗节点（更多内容）',
        p: 'ecosystem.more',
        f: [
          { k: 'label', t: '节点代码（拉丁）', type: 'text' },
          { k: 'name', t: '节点名称', type: 'lang' },
        ],
      },
      { kind: 'group', t: '收尾行动区', p: 'final', f: CTA_GAMES },
    ],
  },
  {
    key: 'promotions',
    name: '热门活动',
    path: '/promotions',
    sections: [
      {
        kind: 'group',
        t: '分页标签',
        p: 'tabs',
        f: [
          { k: 'current', t: '进行中', type: 'lang' },
          { k: 'past', t: '往期回顾', type: 'lang' },
        ],
      },
      { kind: 'group', t: '进行中区块标题', p: 'headings.current', f: HEADING },
      { kind: 'slot', name: 'promotionCard' },
      {
        kind: 'group',
        t: '往期回顾首屏（/promotions/archive）',
        p: 'archive',
        note: '往期回顾是独立路由，不在页面清单里，首屏文案在这里改',
        f: [
          { k: 'page', t: 'SEO 页名', type: 'lang', ph: '选填 · 留空＝浏览器标题只显示品牌名' },
          { k: 'eyebrow', t: '英文眉标', type: 'lang', ph: OPTIONAL_PH },
          { k: 'title', t: '主标题', type: 'lang' },
          { k: 'sub', t: '副标题（强调色）', type: 'lang', ph: OPTIONAL_PH },
          { k: 'desc', t: '说明文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
          { k: 'cta', t: '按钮文字', type: 'lang', ph: BTN_OPTIONAL_PH },
            { k: 'ctaVal', t: '连结内容', type: 'link' },
        ],
      },
      { kind: 'group', t: '往期回顾区块标题', p: 'headings.past', f: HEADING },
      {
        kind: 'group',
        t: '卡片按钮文字',
        p: '',
        f: [
          { k: 'cta', t: '进行中卡片按钮', type: 'lang', ph: BTN_OPTIONAL_PH },
          { k: 'pastCta', t: '往期卡片按钮', type: 'lang', ph: BTN_OPTIONAL_PH },
        ],
      },
    ],
  },
  {
    key: 'blog',
    name: '品牌资讯',
    path: '/blog',
    sections: [
      { kind: 'group', t: '最新焦点区块标题', p: 'headings.featured', f: HEADING },
      { kind: 'group', t: '最新文章区块标题', p: 'headings.latest', f: HEADING },
      { kind: 'slot', name: 'articleCards' },
    ],
  },
  {
    key: 'brand',
    name: '品牌影像',
    path: '/brand',
    sections: [
      { kind: 'group', t: '影像库区块标题', p: 'headings.archive', f: HEADING },
      { kind: 'slot', name: 'mediaCards' },
      {
        kind: 'list',
        t: '四个子页（代言人 / 赞助 / 品牌 / 音乐）',
        p: 'categories',
        fixed: 4,
        note: '这四条路径不在页面清单里，首屏文案跟着分类设定走',
        cols: [
          { k: 'cat', t: '分类', type: 'select', opts: MEDIA_CATS, w: 130 },
          { k: 'path', t: '路径', type: 'text', w: 195 },
          { k: 'label', t: '分页标签', type: 'lang', w: 180 },
          { k: 'title', t: '主标题', type: 'lang' },
        ],
        detail: {
          title: '品牌影像子页首屏',
          sections: [
            {
              kind: 'group',
              t: '子页设定',
              p: '',
              f: [
                { k: 'cat', t: '分类', type: 'select', opts: MEDIA_CATS },
                { k: 'path', t: '前台路径', type: 'text' },
                { k: 'label', t: '分页标签', type: 'lang' },
                { k: 'page', t: 'SEO 页名', type: 'lang', ph: '选填 · 留空＝页尾连结沿用分页标签' },
                { k: 'eyebrow', t: '英文小标', type: 'lang', ph: OPTIONAL_PH },
                { k: 'title', t: '主标题', type: 'lang' },
                { k: 'sub', t: '副标题', type: 'lang', ph: OPTIONAL_PH },
                { k: 'desc', t: '描述文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
                { k: 'cta', t: 'CTA 文字', type: 'lang', ph: BTN_OPTIONAL_PH },
                              { k: 'ctaVal', t: 'CTA 连结内容', type: 'link' },
              ],
            },
          ],
        },
      },
      { kind: 'note', t: '版权声明请到「品牌影像管理」编辑。', site: 'brand.rightsNote' },
    ],
  },
  {
    key: 'contact',
    name: '官方联系',
    path: '/contact/channels',
    sections: [
      { kind: 'group', t: '区块标题', p: 'heading', f: HEADING },
      { kind: 'slot', name: 'contactCard' },
    ],
  },
  {
    key: 'guide',
    name: '使用指南',
    path: '/guide',
    sections: [
      {
        kind: 'list',
        t: '指南分页',
        p: 'sections',
        min: 1,
        max: 12,
        cols: [
          { k: 'code', t: '代码', type: 'text', w: 150 },
          { k: 'path', t: '路径', type: 'text', w: 205 },
          { k: 'name', t: '分页名称', type: 'lang', w: 220 },
        ],
        // 首屏不随分页换的站点：提醒站方到上方「首屏」卡改
        note: shown('guide.heroNote') ? '每个分页的首屏都一样，在上方「首屏」卡改' : '',
        tpl: {
          code: '',
          path: '/guide/',
          name: {},
          ...(shown('guide.seo') ? { page: {}, desc: {} } : {}),
          ...(shown('guide.image') ? { image: '' } : {}),
          heading: {},
          steps: [{ title: {}, desc: {} }],
          notice: {},
        },
        detail: {
          title: '使用指南分页',
          sections: [
            {
              kind: 'group',
              t: '分页',
              p: '',
              f: [
                { k: 'code', t: '代码', type: 'text' },
                { k: 'path', t: '前台路径', type: 'text' },
                { k: 'name', t: '分页名称', type: 'lang', w: 220 },
                // 子页 /guide/<code> 的浏览器标题与 meta description
                { k: 'page', t: 'SEO 页名', type: 'lang', ph: '选填 · 留空＝分页名称', site: 'guide.seo' },
                { k: 'desc', t: 'SEO 描述', type: 'langarea', ph: '选填 · 浏览器搜寻结果下方那段说明', site: 'guide.seo' },
                {
                  k: 'image',
                  t: '手机示意图',
                  type: 'img',
                  tw: 62,
                  th: 96,
                  hint: '面板右侧那张 · 各语系共用 · 留空＝沿用第一个分页的图',
                  site: 'guide.image',
                },
              ],
            },
            { kind: 'group', t: '区块标题', p: 'heading', f: HEADING },
            { kind: 'list', t: '步骤', p: 'steps', min: 1, max: 12, cols: TITLE_DESC },
            {
              kind: 'group',
              t: '注意事项',
              p: 'notice',
              f: [
                { k: 'title', t: '标题', type: 'lang', ph: OPTIONAL_PH },
                { k: 'desc', t: '内容', type: 'langarea', ph: '选填 · 留空就不显示注意事项' },
              ],
            },
          ],
        },
      },
    ],
  },
  {
    key: 'faq',
    name: '常见问题',
    path: '/faq',
    sections: [
      {
        kind: 'list',
        t: '问答',
        p: 'items',
        min: 1,
        max: 50,
        cols: [
          { k: 'q', t: '问题', type: 'lang', w: 300 },
          { k: 'a', t: '回答', type: 'langarea' },
        ],
      },
    ],
  },
  {
    key: 'partners',
    name: '代理招募',
    path: '/partners',
    sections: [
      { kind: 'list', t: '合作优势', p: 'benefits', heading: 'headings.benefits', min: 1, max: 12, cols: TITLE_DESC },
      {
        kind: 'group',
        t: '支援区块',
        p: 'support',
        f: [
          { k: 'eyebrow', t: '英文眉标', type: 'lang', ph: OPTIONAL_PH },
          { k: 'title', t: '主标题', type: 'lang', ph: SECTION_OPTIONAL_PH },
          { k: 'sub', t: '副标题', type: 'lang', ph: OPTIONAL_PH },
          { k: 'desc', t: '说明文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
        ],
      },
      { kind: 'list', t: '支援项目', p: 'support.items', min: 1, max: 12, cols: TITLE_DESC },
      { kind: 'list', t: '加入流程', p: 'steps', heading: 'headings.steps', min: 1, max: 8, cols: TITLE_DESC },
      {
        kind: 'group',
        t: '收尾行动区',
        p: 'finalCta',
        /* 这一页的按钮文字读 btn 不是 cta（样板：finalCta.btn + finalCta.link），
           所以整张卡只有一颗按钮 —— 原本那个「第二颗按钮」的名字是错的。 */
        f: [
          ...CTA_BASE,
          { k: 'sub', t: '主标题第二行（强调色）', type: 'lang', ph: OPTIONAL_PH },
          { k: 'desc', t: '说明文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
          { k: 'btn', t: '按钮文字', type: 'lang', ph: BTN_OPTIONAL_PH },
          { k: 'ctaVal', t: '连结内容', type: 'link' },
          { k: 'markTop', t: '角落装饰字（上）', type: 'text', site: 'partners.mark' },
          { k: 'markBottom', t: '角落装饰字（下）', type: 'text', site: 'partners.mark' },
        ],
      },
    ],
  },
  {
    key: 'compliance',
    name: '信任与安全',
    path: '/compliance',
    sections: [
      {
        kind: 'list',
        t: '政策面板',
        p: 'panels',
        heading: 'headings.policies',
        min: 1,
        max: 8,
        note: 'code 同时是面板 id 与 /compliance#code 深连结的锚点',
        cols: [
          { k: 'code', t: '代码', type: 'text', w: 150 },
          { k: 'kicker', t: '拉丁小标', type: 'text', w: 225 },
          { k: 'tab', t: '分页标签', type: 'lang', w: 200 },
          { k: 'title', t: '标题', type: 'lang', w: 240 },
        ],
        tpl: { code: '', tab: {}, kicker: '', title: {}, sub: {}, desc: {}, policies: [], notice: {} },
        detail: {
          title: '政策面板',
          sections: [
            {
              kind: 'group',
              t: '面板',
              p: '',
              f: [
                { k: 'code', t: '代码', type: 'text' },
                { k: 'kicker', t: '拉丁小标', type: 'text' },
                { k: 'tab', t: '分页标签', type: 'lang' },
                { k: 'title', t: '标题', type: 'lang' },
                { k: 'sub', t: '副标题', type: 'lang', ph: OPTIONAL_PH },
                { k: 'desc', t: '说明文案', type: 'langarea', ph: DESC_OPTIONAL_PH },
              ],
            },
            { kind: 'list', t: '政策条目', p: 'policies', min: 0, max: 12, cols: TITLE_DESC },
            {
              kind: 'group',
              t: '注意事项',
              p: 'notice',
              f: [
                { k: 'label', t: '标签（拉丁）', type: 'text' },
                { k: 'desc', t: '内容', type: 'langarea', ph: '选填 · 留空就不显示注意事项' },
              ],
            },
          ],
        },
      },
    ],
  },
];


/* ---------- 清单注册表（给 pb.add / pb.del / pb.edit 用） ---------- */
const LISTS = {};

function emptyRow(cols, tpl) {
  if (tpl) return structuredClone(tpl);
  const o = {};
  for (const c of cols) {
    if (c.type === 'lang' || c.type === 'langarea' || c.type === 'langlist') o[c.k] = {};
    else if (c.type === 'bool') o[c.k] = false;
    else if (c.type === 'select') o[c.k] = c.opts[0];
    else o[c.k] = '';
  }
  return o;
}

/* ---------- 单一栏位 ---------- */
function fieldHtml(f, base, obj, lang) {
  const bind = `${MODEL}:${base ? base + '.' : ''}${f.k}`;
  const v = obj ? obj[f.k] : undefined;
  switch (f.type) {
    case 'lang':
      return langInput(bind, v, lang, { ph: f.ph });
    case 'langarea':
      return langInput(bind, v, lang, { area: true, ph: f.ph });
    case 'bool':
      return sw(!!v, bind);
    case 'select':
      return selectOf(f.opts, v, bind, 'min-width:130px');
    /* 唯读：值有意义但站方不该改。这两个栏位（锚点代码、版型）决定套哪一套
       写死的版面与图示 —— 改了那一格的图示与右侧视觉就对不上，而且不会报错。 */
    case 'ro':
      return `<span class="mono muted" style="font-size:13.5px">${esc(v || '—')}</span>`;
    case 'link':
      return `<input class="inp mono" style="min-width:0;width:100%;font-size:13.5px" data-bind="${bind}" value="${esc(
        v || ''
      )}" placeholder="/path、#anchor 或 https://…">`;
    /* 单张图片（存的是 assetRef 字串）：缩图 + 上传钮 + 可直接贴路径的输入框。
       example.invalid / f.th 调缩图尺寸、f.iw 调输入框宽（f.w 是表格栏宽，别混用）；f.hint 是输入框下方的一行小字。 */
    case 'img':
      return `<div class="rowline" style="align-items:flex-start;gap:10px">
        ${assetThumb(v, { meta: f.t, w: example.invalid || 96, h: f.th || 62 })}
        <div>
          ${assetInput(bind, v, { w: f.iw || 240 })}
          ${f.hint ? `<div class="muted" style="font-size:13px;margin-top:4px">${esc(f.hint)}</div>` : ''}
        </div>
      </div>`;
    default:
      return `<input class="inp" style="min-width:0;width:100%" data-bind="${bind}" value="${esc(v || '')}">`;
  }
}

/* ---------- 区段 ---------- */
/* 一个前台区块＝一张卡。原本所有 group 都塞进一张「页面内容」，而 list 各自是一张卡 ——
   两者层级不同，光调 sections 的顺序排不出前台顺序（标题永远黏在一起、列表永远在最后）。
   heading 给了就把该区块的标题并进同一张卡，不要另开一张「区块标题」。 */
function renderGroup(sec, base, root, lang) {
  const obj = sec.p ? get(root, sec.p) || {} : root;
  const p = sec.p ? `${base}.${sec.p}` : base;
  // 欄位層級也能過濾；整組都被濾掉就整張卡不畫
  const fields = sec.f.filter((fd) => visible(fd, root));
  if (!fields.length) return '';
  return `<div class="card">
    ${cardHead(sec.t, sec.note)}
    <div class="card-b">
      ${sec.heading ? headingFields(`${MODEL}:${base}.${sec.heading}`, get(root, sec.heading), lang) : ""}
      <div class="langblk">
        ${fields
          .map(
            (fd) => `<div class="langrow">
          <div class="lname">${esc(fd.t)}</div>
          ${fieldHtml(fd, p, obj, lang)}
        </div>`
          )
          .join("")}
      </div>
    </div>
  </div>`;
}

/** 卡头：区块名 + 右侧「更新储存」（同一页每张卡都存 page_blocks，所以共用 pb.save） */
function cardHead(title, note) {
  return `<div class="card-h">${esc(title)}${
    note ? ` <span class="sub">${esc(note)}</span>` : ""
  }<div class="right"><button class="btn sm primary" data-act="pb.save">更新储存</button></div></div>`;
}

function renderLangList(sec, base, root, lang) {
  const p = `${base}.${sec.p}`;
  const map = get(root, sec.p) || {};
  const arr = Array.isArray(map[lang]) ? map[lang] : [];
  const n = Math.max(arr.length + 1, 3);
  return `<div class="fieldset" style="margin-bottom:12px">
    <div class="fs-t">${esc(sec.t)}</div>
    <div class="rowline" style="gap:8px;flex-wrap:wrap">
      ${Array.from({ length: Math.min(n, sec.max || 8) })
        .map(
          (_, i) =>
            `<input class="inp" style="min-width:150px" data-bind="${MODEL}:${p}.${lang}.${i}" value="${esc(
              arr[i] || ''
            )}" placeholder="项目 ${i + 1}">`
        )
        .join('')}
    </div>
  </div>`;
}

function renderList(sec, base, root, lang) {
  const p = `${base}.${sec.p}`;
  const arr = get(root, sec.p) || [];
  const min = sec.fixed ? sec.fixed : sec.min === undefined ? 1 : sec.min;
  const canEdit = !sec.fixed;
  /* 欄位也吃 when —— 同一張清單在不同 variant 下用到的欄位不一樣
     （公平透明讀標題＋說明，專業服務讀標籤）。連 LISTS 一起存過濾後的，
     這樣「新增」建出來的空列也不會帶那些永遠不顯示的鍵。 */
  const cols = sec.cols.filter((c) => visible(c, root));
  LISTS[p] = { cols, min, max: sec.fixed || sec.max || 50, tpl: sec.tpl, detail: sec.detail, title: sec.t };
  return `<div class="card">
    <div class="card-h">${esc(sec.t)} <span class="sub">${
    sec.fixed ? `固定 ${sec.fixed} 笔` : `${arr.length} 笔`
  }${sec.note ? ' · ' + esc(sec.note) : ''}</span>
      <div class="right">${
        canEdit ? `<button class="btn sm" data-act="pb.add" data-arg="${esc(p)}">新增</button>` : ""
      }<button class="btn sm primary" data-act="pb.save">更新储存</button></div>
    </div>
    ${sec.heading ? `<div class="card-b">${headingFields(`${MODEL}:${base}.${sec.heading}`, get(root, sec.heading), lang)}</div>` : ""}
    <div class="card-b flush"><div class="tblwrap"><table class="tbl">
      <thead><tr>${cols
        .map((c) => `<th ${c.w ? `style="width:${c.w}px"` : ''}>${esc(c.t)}</th>`)
        .join('')}${sec.detail ? '<th style="width:70px">细节</th>' : ''}${
    canEdit ? '<th style="width:70px">操作</th>' : ''
  }</tr></thead>
      <tbody>${arr
        .map(
          (row, i) => `<tr>
        ${cols.map((c) => `<td>${fieldHtml(c, `${p}.${i}`, row, lang)}</td>`).join('')}
        ${
          sec.detail
            ? `<td class="ops"><button class="btn text" data-act="pb.edit" data-arg="${esc(p)}|${i}">编辑</button></td>`
            : ''
        }
        ${
          canEdit
            ? `<td class="ops"><button class="btn text danger-t" data-act="pb.del" data-arg="${esc(
                p
              )}|${i}">删除</button></td>`
            : ''
        }
      </tr>`
        )
        .join('')}</tbody>
    </table></div></div>
  </div>`;
}

function get(root, path) {
  let cur = root;
  for (const seg of String(path).split('.')) {
    if (cur === null || cur === undefined) return undefined;
    cur = cur[seg];
  }
  return cur;
}

/* when(record) 給「同一張表單、每一筆只用得到其中一部分」的情形用。
   六大優勢就是這樣：一筆只有一個 variant，四張清單裡只有一張是它的 ——
   全部畫出來的話站方會往不會顯示的欄位填字，而且看不出哪張才是自己該改的。
   site 是站點層級的同一件事：這一站的樣板不讀就不畫。 */
function visible(x, root) {
  return (!example.invalid || shown(example.invalid)) && (!x.when || x.when(root || {}));
}

function renderSections(sections, base, root, lang, slots = {}) {
  return sections
    .filter((sec) => visible(sec, root))
    .map((sec) => {
      if (sec.kind === "slot") return (slots[sec.name] || (() => ""))();
      if (sec.kind === 'group') return renderGroup(sec, base, root, lang);
      if (sec.kind === 'list') return renderList(sec, base, root, lang);
      if (sec.kind === 'langlist') return renderLangList(sec, base, root, lang);
      if (sec.kind === 'note')
        return `<div class="hint"><span class="ic">ℹ</span><div>${esc(sec.t)}</div></div>`;
      return '';
    })
    .join('');
}

/* ---------- 对外：某一页的文案卡（挂在该页自己的画面上） ---------- */
export function blocksCard(pbKey, slots = {}) {
  const pb = store.get("page_blocks");
  const lang = getInlineLang("page");
  const spec = PB_PAGES.find((p) => p.key === pbKey);
  if (!spec) return ""; // 这一页没有可编辑的内页文案（例如首页）
  const root = pb[spec.key] || {};
  return renderSections(spec.sections, spec.key, root, lang, slots);
}

/* ---------- 动作 ---------- */
Object.assign(ACTIONS, {
  /* ⚠ 弹窗里卡头的「更新储存」必须走弹窗自己的储存（dlg.save → onSave）。
     直接 store.save 的话弹窗没被标成「已存」，接着按 ✕／取消会把草稿还原成旧字，下次储存就把旧字存回去。 */
  'pb.save': (_arg, btn) =>
    btn && btn.closest && btn.closest('#dlgBody') ? ACTIONS['dlg.save']() : store.save('page_blocks'),
  'pb.add': (path) => {
    const meta = LISTS[path];
    if (!meta) return;
    const arr = get(store.get('page_blocks'), path);
    if (!Array.isArray(arr)) return;
    if (addRow(arr, emptyRow(meta.cols, meta.tpl), { max: meta.max })) refresh();
  },
  'pb.del': (arg) => {
    const [path, i] = arg.split('|');
    const meta = LISTS[path];
    const arr = get(store.get('page_blocks'), path);
    if (!Array.isArray(arr) || !meta) return;
    if (delRow(arr, +i, { min: meta.min })) refresh();
  },
  'pb.edit': (arg) => {
    const [path, i] = arg.split('|');
    const meta = LISTS[path];
    if (!meta || !meta.detail) return;
    const root = store.get('page_blocks');
    const arr = get(root, path);
    const rowPath = `${path}.${i}`;
    const backup = JSON.parse(JSON.stringify(arr[+i]));
    openDlg(`${meta.detail.title} - 第 ${+i + 1} 笔`, {
      step1: () => {
        const lang = getInlineLang('page');
        return `
          ${renderSections(meta.detail.sections, rowPath, get(root, rowPath), lang)}`;
      },
      onSave: async () => {
        // saveFromDialog 而不是 save：验证失败的 issues 要印在弹窗顶端的 #dlgErr，
        // 走 save 的话会印到弹窗**后面**的 #errslot，被遮罩盖住看不到。
        const ok = await store.saveFromDialog('page_blocks');
        // 只有真的存成功才标记 —— 先标记再存的话，存失败之后按取消／✕，
        // onClose 收到 saved=true 就不会还原备份，失败的编辑会留在草稿里
        if (!ok) return;
        markDlgSaved();
        closeDlg();
      },
      onClose: (saved) => {
        if (saved) return;
        const list = get(store.get('page_blocks'), path);
        if (Array.isArray(list)) list[+i] = backup;
        renderView();
      },
    });
  },
});

/** 清单增减后：在弹窗里就重画弹窗，否则重画整个画面 */
function refresh() {
  if (document.getElementById('mask').classList.contains('open')) paintDlg();
  else renderView();
}
