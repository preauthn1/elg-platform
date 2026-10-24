/* 即时数据设定 —— 预览直接打前台那支公开端点，所以看到的就是访客看到的。
   这一页的每次储存都会写入操作纪录（画面最下方那张表就是它）。 */
import * as store from '../store.js';
import { api, publicStats } from '../api.js';
import { defText } from './common.js';
import {
  el,
  esc,
  toast,
  ACTIONS,
  selectOf,
  getInlineLang,
  headingFields,
  langInput,
  renderView,
  EDIT_HOOKS,
  DESC_OPTIONAL_PH,
} from '../ui.js';

/* ⚠ 這幾個中文字串是「存進 content.json 的識別碼」，server 端用 z.enum 驗證，
   所以必須維持原樣（繁體）—— 轉成簡體會讓存檔直接 422。
   畫面上要顯示簡體，靠下面的 *_LABEL 對照表（跟語系的 LANG_NAME 同一個做法）。
   之後若要徹底解決，正解是把識別碼換成 ASCII code（像遊戲分類的 SLOT/SPORTS），
   那需要連 schema 與既有資料一起遷移，不在這次範圍。 */
/* ⚠ 「無」是繁體 —— 那是存进 content.json 的识别码（schema 用 z.enum 验证），
   画面上显示的「无」是简体。两个字长得几乎一样但不是同一个字元，所以常数写一次、
   到处引用；曾经有两处手打 '无' 去比对，永远比不中，於是「无」这个单位
   被当成有单位、把「無」字印在数字旁边。 */
const UNIT_NONE = '無';
// 与 server/schema/content.js 的 STAT_UNITS 同步；「万元+／亿元+」给金额卡用，除数同「万+／亿+」
const UNITS = [{ v: UNIT_NONE, t: '无' }, '万+', '亿+', '万元+', '亿元+'];
const SOURCE_LABEL = {
  註冊會員數: '注册会员数',
  代理數: '代理数',
  累計代理佣金: '累计代理佣金',
  累計活動獎金: '累计活动奖金',
};
const sourceLabel = (s) => SOURCE_LABEL[s] || s;

/* 真实值由 API 端点提供，不存在后台的草稿里 —— loadPreview() 拿到后记在这里，
   让「改倍率立刻看到预览」不必再打一次端点。端点还没回来时是空的，预览显示 —。 */
const REAL = {};
/* 真实值第一次拿到之后要重画一次表格 —— 表格里那句「每次 +N / 不会动」算得出来的
   前提是 REAL 有值，而表格是在 loadPreview() 之前就画好的，所以第一次载入看不到。
   ⚠ 只重画一次：renderView() 会再走一次 mount()，而 mount() 又会呼叫 loadPreview，
   不挡就是无穷循环。 */
let previewPainted = false;
const realOf = (row) => REAL[row.source];
const DIVISOR = { [UNIT_NONE]: 1, '万+': 1e4, '亿+': 1e8, '万元+': 1e4, '亿元+': 1e8 };

/* /api/stats 最近一次回的那几列。要的是里面的 base / since —— 爬升的起点与起算时刻。
   ⚠ 少了它，预览就只会显示「真实值 × 倍率」这个起点，而访客看到的是起点加上已经爬了
   几小时的量。站方两边一对，数字差几百，会以为哪一边算错了。 */
let pvSrv = null;
const pvSrvOf = (row) => (pvSrv || []).find((r) => r.source === row.source) || null;
const ANCHOR_RESET_RATIO = 0.9; // 与 server/lib/stats.js 同一个值

/** 这一刻该显示的值。公式与 server/lib/stats.js 的 buildStatsPayload 一字不差：
 *
 *      起点 + 每次跳的量 × floor((现在 − 起算时刻) / 节奏)
 *
 *  只跟墙上时钟有关，所以后台、前台、伺服器三边在同一刻算出同一个数。
 *
 *  ⚠ 起点的取法也照抄伺服器那三种情况（沿用旧起点／真实值反超／倍率被调小重设），
 *  这样「改了倍率还没存档」的预览也等于存档后访客会看到的东西 —— 不然改一个字
 *  预览就掉回没爬升的起点，看起来像倒退了几百。 */
function currentValue(row) {
  const real = realOf(row);
  if (real === undefined) return null;
  const value = (real * Number(row.mult || 0)) / (DIVISOR[row.unit] ?? 1);
  const every = Math.max(1, Number(row.tickEverySeconds || 15));
  const perDay = Number(store.get('statistics').growthPerDay || 0);
  const perSecond = perDay > 0 ? (value * perDay) / 100 / 86400 : 0;
  const step = Math.round(perSecond * every);

  const srv = pvSrvOf(row);
  const now = Date.now();
  const anchorValue = Number((srv || {}).base);
  const anchorAt = Date.parse((srv || {}).since);
  const usable =
    perSecond > 0 &&
    Number.isFinite(anchorValue) &&
    Number.isFinite(anchorAt) &&
    value >= anchorValue * ANCHOR_RESET_RATIO;
  const projected = usable ? anchorValue + (perSecond * Math.max(0, now - anchorAt)) / 1000 : null;
  const keep = usable && projected >= value;

  const base = keep ? anchorValue : value;
  const since = keep ? anchorAt : now;
  const ticks = Math.max(0, Math.floor((now - since) / 1000 / every));
  return base + step * ticks;
}

/** 这张卡每跳一次往上加多少（整数）。公式与 server/lib/stats.js 完全一致 ——
 *  三边（伺服器 payload、后台预览、前台 stats.js）算出来必须一样，
 *  不然预览跟实际画面对不上，站方会以为存档没生效。 */
function stepOf(row) {
  const perDay = Number(store.get('statistics').growthPerDay || 0);
  if (!(perDay > 0)) return 0;
  const real = realOf(row);
  if (real === undefined) return 0;
  const value = (real * Number(row.mult || 0)) / (DIVISOR[row.unit] ?? 1);
  const every = Math.max(1, Number(row.tickEverySeconds || 15));
  return Math.round(((value * perDay) / 100 / 86400) * every);
}

/** 预览那一行末尾的提示。刻意不放在表格的「跳动节奏」格里 ——
 *  改倍率时只有预览区会重画，表格那格会留着旧文字，于是一格说「每次 +1」、
 *  另一格说「不会动」，互相矛盾。放在会跟着更新的地方才有意义。
 *每次跳的量四舍五入到 0 就是不会动 ——
 *  那不是 bug，是这张卡的数字太小撑不起这个节奏，解法是把倍率调大。
 *  站方不该自己拿计算机算这件事，所以直接写在栏位旁边。 */
function tickHint(row) {
  const perDay = Number(store.get('statistics').growthPerDay || 0);
  if (!(perDay > 0)) return '';
  if (realOf(row) === undefined) return '';
  const step = stepOf(row);
  if (step <= 0) return ' · 不会动（数字太小，倍率要调大）';
  return ' · 每次 +' + step.toLocaleString('en-US');
}

/** 预览那个大数字。含爬升量，所以跟访客当下看到的一样 —— 见 currentValue。 */
function displayValue(row) {
  const v = currentValue(row);
  if (v === null) return '—';
  return Math.round(v).toLocaleString('en-US');
}
function rawValue(row) {
  const real = realOf(row);
  if (real === undefined) return '—';
  const v = real * Number(row.mult || 0);
  return v.toLocaleString('en-US', { maximumFractionDigits: 0 });
}
const realText = (row) => {
  const real = realOf(row);
  return real === undefined ? '—' : real.toLocaleString('en-US');
};

export function statisticCards() {
  const st = store.get('statistics');
    const rows = st.rows || [];
    const lang = getInlineLang('page');
    const h = (store.get('home_blocks').headings || {}).stats;
    return `
<div class="card">
  <div class="card-h">平台核心数据 <span class="sub">${rows.length} 张卡 · 卡片与资料来源固定，不可新增或删除</span>
    <div class="right">
      <button class="btn sm" data-act="stat.recompute">立即重新取数</button>
      <button class="btn sm primary" data-act="stat.save">更新储存</button>
    </div>
  </div>
  <div class="card-b">${headingFields('home_blocks:headings.stats', h, lang)}</div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:52px">排序</th><th style="width:130px">资料来源</th>
      <th class="num" style="width:104px">真实值</th>
      <th class="num" style="width:104px">倍率</th>
      <th style="width:92px">显示单位</th>
      <th style="width:104px">跳动节奏</th>
      <th style="width:185px">标题</th><th>说明文案（可含 {value}）</th>
    </tr></thead>
    <tbody>${rows
      .map(
        (s, i) => `
      <tr>
        <td><input class="inp xs num" data-bind="statistics:rows.${i}.sort" data-type="int" data-onedit="statPreview" value="${esc(
          s.sort
        )}"></td>
        <td>${esc(sourceLabel(s.source))}<div class="muted" style="font-size:12.5px">系统取数</div></td>
        <td class="num mono muted">${realText(s)}</td>
        <td><input class="inp sm num" data-bind="statistics:rows.${i}.mult" data-type="num" data-onedit="statPreview" value="${esc(
          s.mult
        )}"></td>
        <td>${selectOf(UNITS, s.unit, `statistics:rows.${i}.unit`, 'min-width:82px', '', 'statPreview')}</td>
        <td><div class="rowline" style="gap:4px"><input class="inp xs num" data-bind="statistics:rows.${i}.tickEverySeconds" data-type="int" data-onedit="statPreview" value="${esc(
          s.tickEverySeconds
        )}"><span class="muted" style="font-size:13px">秒</span></div></td>
        <td>${langInput(`statistics:rows.${i}.title`, s.title, lang, { style: 'min-width:130px' })}</td>
        <td>${langInput(`statistics:rows.${i}.desc`, s.desc, lang, { style: 'min-width:200px', ph: DESC_OPTIONAL_PH })}</td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
  <div class="card-b"><div class="form-row">
    <div class="fitem"><label>每天成长</label>
      <input class="inp sm num" data-bind="statistics:growthPerDay" data-type="num" data-onedit="statPreview"
        value="${esc(st.growthPerDay || 0)}"><span class="muted" style="font-size:13.5px">%
        · 0 就完全不动 · 四张卡共用这一个数，各卡按自己的量级换算</span></div>
  </div></div>
  <div class="card-b">
    <div class="muted" style="font-size:14.5px;margin-bottom:8px">前台预览（真实值每小时取一次，前台在两次取数之间平滑往上爬）</div>
    <div class="preview" id="pvbox">${renderLocalPreview(rows)}</div>
    <div class="muted" style="font-size:13.5px;margin-top:10px" id="pvmeta">正在读取前台实际值…</div>
  </div>
</div>`;
}

/* 前台预览要等 DOM 挂上去才能填 —— 呼叫端（views/pages.js 的首页）在 mount() 里呼叫。 */
export function statisticMount() {
  loadPreview();
}

function renderLocalPreview(rows) {
  return rows
    .map(
      (s, i) => `<div class="pv">
      <div class="idx">0${i + 1}</div>
      <div class="big">${displayValue(s)}<span class="u">${s.unit === UNIT_NONE ? '' : esc(s.unit)}</span></div>
      <div class="cap">${esc(defText(s.title) || sourceLabel(s.source))}</div>
      <div class="raw">真实 ${realText(s)} × ${esc(s.mult)} = ${rawValue(s)}${tickHint(s)}</div>
    </div>`
    )
    .join('');
}

/* 重画预览区。改栏位、取到伺服器起点、每秒跳一次，走的都是这一支。 */
function paintPreview() {
  const box = el('pvbox');
  if (box) box.innerHTML = renderLocalPreview(store.get('statistics').rows || []);
}
EDIT_HOOKS.statPreview = paintPreview;

/* 每秒重画一次，让预览跟前台一起爬。
   ⚠ 挂在模组层级：只会装一次，renderView() 重建 DOM 也不会叠出第二个计时器
   （每一跳都重新 el('pvbox')，所以拿到的一定是当下那颗节点；不在这一页就自己空转）。
   ⚠ 预览区里没有任何输入框，所以整块换掉不会打断打字。 */
setInterval(paintPreview, 1000);

async function loadPreview() {
  const meta = el('pvmeta');
  if (!meta) return;
  try {
    const d = await publicStats();
    for (const r of d.rows || []) if (typeof r.real === 'number') REAL[r.source] = r.real;
    pvSrv = d.rows || null;
    paintPreview();
    meta.innerHTML =
      `前台实际值取数时间 ${esc(new Date(d.meta.at).toLocaleString('zh-TW'))}${
        d.meta.cached ? '（快取）' : ''
      }，前台每 ${esc(d.refreshSeconds)} 秒重新对齐一次。`;
    if (!previewPainted) {
      previewPainted = true;
      renderView();
    }
  } catch (err) {
    meta.textContent = '前台实际值读取失败：' + err.message + '（上方为后台依目前设定算出的预览）';
  }
}


Object.assign(ACTIONS, {
  /* ⚠ 这张卡跨两个 entity：上半部四个区块标题（眉标／主标／副标／说明文案）在
     home_blocks.headings.stats，下半部四列数字在 statistics。所以要存两个。

     只存 statistics 的话，站方改了区块标题按「更新储存」会看到「已更新」，
     而那四栏根本没送出去 —— 存了没反应，也没有任何错误可循。
     （同样的坑之前在 page_blocks 出现过一次，那次是 data-bind 少了 model 前缀。）

     顺序：statistics 先，成功了才存 home_blocks —— 数字那一半有 z.enum 与
     「四张卡不可同来源」的检查，比较容易被挡下来，先做才不会出现
     「标题存了、数字没存」的半套状态。两次 toast 会盖在一起，看起来是一次。 */
  'stat.save': async () => {
    if (await store.save('statistics')) await store.save('home_blocks');
    /* 存完重新跟伺服器对一次起点 —— 改了倍率或节奏，伺服器那侧可能重设起点，
       不重抓的话预览会拿旧起点继续算，跟访客看到的差一截。 */
    loadPreview();
  },
  'stat.recompute': async (a, node) => {
    node.disabled = true;
    try {
      await api.recomputeStats();
      await store.reload(['statistics']);
      toast('已重算真实值并清除取数快取');
      renderView();
    } catch (err) {
      toast('重算失败：' + err.message, 'info');
      node.disabled = false;
    }
  },
});
