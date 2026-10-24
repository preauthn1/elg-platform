/* 系统设置 —— 取数用的 API 网址与三个密钥。仅管理员。

   三道锁在伺服器那一侧（requireAdmin）：登入 → 管理员角色 → 公司 IP 白名单。
   选单藏起来只是介面。站方拿的是 editor，看不到这一页也打不到端点。

   ⚠ 四个值直接显示、直接改（站方要求）。它们等同 apis 专案的 .env，
   所以会出现在开发者工具的 network 面板与截图里 —— 挡在前面的是上面那三道锁，
   不是遮罩。这一页不要开给 editor。稽核纪录只记「改了哪几栏」，不记值。

   ⚠ 品牌网域本来在首页「平台核心数据」那张卡（存 content.json），已经搬到这一页 ——
   取数的五个设定原本散在两个档、两个页面，站方每次都要先想「那一栏在哪」。

   ⚠ 资料不走 store.js（那一层是逐类别的内容资料），自己拿 api；
   render() 是同步的拿不到网路结果，所以资料在 mount() 补。 */
import { api } from '../api.js';
import { crumb } from '../shell.js';
import { esc, toast, ACTIONS, renderView } from '../ui.js';

let state = { cfg: null, error: '', test: null, testing: false, loading: false, selfPaint: false };

/* stats.js 回的四个键是繁体 —— 那是存在 content.json 里的识别码，不能改
   （server 端用 z.enum 验证）。画面上要简体，所以照 views/statistic.js 用对照表。 */
const SOURCE_LABEL = {
  註冊會員數: '注册会员数',
  代理數: '代理数',
  累計代理佣金: '累计代理佣金',
  累計活動獎金: '累计活动奖金',
};

const FIELDS = [
  { field: 'baseUrl', id: 'syscfgBase', label: 'API 网址' },
  { field: 'aesKey', id: 'syscfgKey', label: 'AES Key' },
  { field: 'aesIv', id: 'syscfgIv', label: 'AES IV' },
  { field: 'aesMd5', id: 'syscfgMd5', label: 'AES MD5' },
  { field: 'brandDomain', id: 'syscfgDomain', label: '品牌网域' },
];

const val = (id) => ((document.getElementById(id) || {}).value || '').trim();

/* 自己重画。renderView() 会让 app.js 再走一次 mount()，所以先插一个记号，
   让那一次 mount 知道「这是我自己触发的，不用再抓资料」。

   ⚠ 少了这个记号就是无穷循环（mount → fetch → renderView → mount …）。
   而改成别的画面那种「抓过就不再抓」，则会让离开再回来时看到旧值 ——
   这一页整个存在的意义就是回答「现在到底设了什么」。 */
function repaint() {
  state.selfPaint = true;
  renderView();
}

async function fetchCfg() {
  state = { ...state, loading: true };
  try {
    state = { ...state, cfg: await api.getApiConfig(), error: '' };
  } catch (err) {
    state = { ...state, error: err.message };
  }
  state = { ...state, loading: false };
  repaint();
}

const row = (label, inner) => `
  <div class="form-row"><div class="fitem"><label>${esc(label)}</label>${inner}</div></div>`;

const fieldRow = (f, c) =>
  row(f.label, `<input class="inp mono" id="${f.id}" style="min-width:320px" value="${esc(c[f.field] || '')}">`);

function testBlock() {
  if (state.testing) return '<div class="muted" style="margin-top:10px">正在打端点…</div>';
  const t = state.test;
  if (!t) return '';
  if (t.stale) {
    return `<div class="hint warn" style="margin-top:12px"><span class="ic">⚠</span>
      <div>取数失败：${esc(t.error || '（没有回报原因）')}</div></div>`;
  }
  const rows = Object.entries(t.values || {})
    .map(
      ([k, v]) =>
        `<tr><td>${esc(SOURCE_LABEL[k] || k)}</td>` +
        `<td class="num mono">${esc(Number(v).toLocaleString('en-US'))}</td></tr>`
    )
    .join('');
  return `<div style="margin-top:12px;max-width:360px">
    <table class="tbl"><tbody>${rows}</tbody></table></div>`;
}

export const system = {
  needs: [],
  render() {
    if (state.error) {
      return `${crumb('system')}
<div class="card"><div class="card-b">
  <div class="hint warn"><span class="ic">⚠</span><div>读取失败：${esc(state.error)}</div></div>
</div></div>`;
    }
    const c = state.cfg;
    if (!c) return `${crumb('system')}<div class="card"><div class="card-b muted">载入中…</div></div>`;

    return `
${crumb('system')}
<div class="card">
  <div class="card-h">系统设置
    <div class="right">
      <button class="btn sm" data-act="syscfg.test">测试取数</button>
      <button class="btn sm primary" data-act="syscfg.save">更新储存</button>
    </div>
  </div>
  <div class="card-b">
    ${FIELDS.map((f) => fieldRow(f, c)).join('')}
    ${testBlock()}
  </div>
</div>`;
  },
  mount() {
    if (state.loading) return;
    // 这一次 mount 是自己 repaint() 触发的，不是使用者进来
    if (state.selfPaint) {
      state.selfPaint = false;
      return;
    }
    fetchCfg();
  },
};

Object.assign(ACTIONS, {
  'syscfg.save': async () => {
    const body = {};
    // 送什么就是什么：清空就是清空，不必再有「留空＝不改」那条规则
    for (const f of FIELDS) body[f.field] = val(f.id);
    try {
      const r = await api.saveApiConfig(body);
      if (!r.changed.length) return toast('没有栏位需要更新', 'info');
      toast('已更新：' + r.changed.join('、'));
      state = { ...state, test: null };
      await fetchCfg();
    } catch (err) {
      toast(err.message, 'info');
    }
  },

  'syscfg.test': async () => {
    state = { ...state, testing: true, test: null };
    repaint();
    try {
      state = { ...state, test: await api.testApiConfig(), testing: false };
    } catch (err) {
      state = { ...state, test: { stale: true, error: err.message }, testing: false };
    }
    repaint();
  },
});
