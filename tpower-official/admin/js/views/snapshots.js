/* 內容快照 —— 每次存檔前自動留的那一份，可以挑一份還原回去。僅管理員。

   三道鎖在伺服器那一側（requireAdmin）：登入 → 管理員角色 → 公司 IP。
   選單藏起來只是介面 —— editor 看不到這一頁，也打不到端點。

   ⚠ 為什麼不給 editor：還原是整個類別覆寫，等於把那之後的所有修改推翻。
   清單本身也擋著 —— 檔名帶著類別與時間，看得出誰在什麼時候改過什麼。

   ⚠ 還原本身也會先留一份快照（writeEntity 的既有行為），所以按錯了還救得回來。

   ⚠ 資料不走 store.js（那一層是逐類別的內容資料），自己拿 api；
   render() 是同步的拿不到網路結果，所以資料在 mount() 補。 */
import * as store from '../store.js';
import { api } from '../api.js';
import { crumb } from '../shell.js';
import { esc, toast, ACTIONS, selectOf, renderView } from '../ui.js';

let state = { rows: null, error: '', loading: false, selfPaint: false, entity: '' };

const ENTITY_LABEL = {
  settings: '站台设定',
  site_tagline: '品牌标语',
  pages: '页面首屏',
  contacts: '官方联系',
  home_blocks: '首页区块',
  page_blocks: '内页文案',
  statistics: '即时数据',
  promotions: '活动',
  game_categories: '游戏分类',
  game_providers: '游戏商',
  media: '品牌影像',
  article_categories: '资讯分类',
  articles: '品牌资讯',
};
const label = (n) => ENTITY_LABEL[n] || n;

/** '20260908_104512' → '2026-09-08 10:45:12' */
function stamp(ts) {
  const s = String(ts || '');
  if (s.length < 15) return s;
  const d = s.slice(0, 8);
  const t = s.slice(9);
  return `${d.slice(0, 4)}-${d.slice(4, 6)}-${d.slice(6, 8)} ${t.slice(0, 2)}:${t.slice(2, 4)}:${t.slice(4, 6)}`;
}

/* 自己重畫。renderView() 會讓 app.js 再走一次 mount()，所以先插一個記號，
   讓那一次 mount 知道「這是我自己觸發的，不用再抓資料」。
   少了記號就是無窮迴圈；改成「抓過就不再抓」則會在還原之後看到舊清單。 */
function repaint() {
  state.selfPaint = true;
  renderView();
}

async function load() {
  state = { ...state, loading: true };
  try {
    state = { ...state, rows: await api.listSnapshots(), error: '' };
  } catch (err) {
    state = { ...state, error: err.message };
  }
  state = { ...state, loading: false };
  repaint();
}

export const snapshots = {
  needs: [],
  render() {
    if (state.error) {
      return `${crumb('snapshots')}
<div class="card"><div class="card-b">
  <div class="hint warn"><span class="ic">⚠</span><div>讀取失敗：${esc(state.error)}</div></div>
</div></div>`;
    }
    if (!state.rows) return `${crumb('snapshots')}<div class="card"><div class="card-b muted">载入中…</div></div>`;

    const all = state.rows;
    const kinds = [...new Set(all.map((r) => r.name))].sort();
    const rows = state.entity ? all.filter((r) => r.name === state.entity) : all;

    const body = rows.length
      ? rows
          .map(
            (r) => `<tr>
        <td class="mono">${esc(stamp(r.ts))}</td>
        <td>${esc(label(r.name))}</td>
        <td class="ops"><button class="btn text" data-act="snap.restore" data-arg="${esc(r.file)}">还原</button></td>
      </tr>`
          )
          .join('')
      : '<tr><td colspan="3" class="muted">没有快照</td></tr>';

    return `
${crumb('snapshots')}
<div class="card">
  <div class="card-h">内容快照 <span class="sub">${rows.length} 份${
    state.entity ? ' · 共 ' + all.length : ''
  } · 每次存档前自动留存，上限 200 份</span>
    <div class="right">
      ${selectOf(
        [{ v: '', t: '全部类别' }, ...kinds.map((v) => ({ v, t: label(v) }))],
        state.entity,
        '',
        'min-width:130px'
      ).replace('<select', '<select data-snapfilter')}
      <button class="btn sm" data-act="snap.reload">重新载入</button>
    </div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:180px">时间</th><th style="width:180px">类别</th><th>操作</th>
    </tr></thead>
    <tbody>${body}</tbody>
  </table></div></div>
</div>`;
  },
  mount() {
    if (state.loading) return;
    if (state.selfPaint) {
      state.selfPaint = false;
      return;
    }
    load();
  },
};

Object.assign(ACTIONS, {
  'snap.reload': () => load(),

  'snap.restore': async (file) => {
    const row = (state.rows || []).find((r) => r.file === file);
    const what = row ? `${label(row.name)}（${stamp(row.ts)}）` : file;
    /* 還原會整個覆寫那個類別，那之後改的東西全部不見，所以問一次。
       但覆寫本身也會先留一份快照，所以按錯了還救得回來 —— 訊息要把這件事講清楚，
       不然沒人敢按，這一頁就白做了。 */
    if (!confirm(`确定用「${what}」覆写目前的内容？\n\n这个类别在这之后的修改会全部消失。\n（覆写前会自动再留一份快照，所以按错了还能还原回来。）`)) {
      return;
    }
    try {
      const r = await api.restoreSnapshot(file);
      const name = r.restored || (row && row.name) || '';
      /* ⚠ 还原改的是伺服器上的资料，后台记忆体里的那份 draft 还是旧的。
         不重抓的话切到那一页会看到还原前的内容 —— store.ensure() 只抓「还没载入过」
         的类别，已经载入的不会再动。而且那份旧 draft 只要一存档，就把刚还原的东西
         整个盖回去，等於还原白做。 */
      if (name) await store.reload([name]);
      toast(`已还原 ${label(name)}`);
      await load();
    } catch (err) {
      toast(err.message, 'info');
    }
  },
});

/* 筛选下拉的 change 事件。app.js 的全域委派只处理 data-act 与 data-bind，
   所以这里自己挂一个 —— 挂在 document 上一次就好（模组只会载入一次）。
   做法与 views/audit.js 的筛选一致。 */
document.addEventListener('change', (e) => {
  const sel = e.target.closest && e.target.closest('[data-snapfilter]');
  if (!sel) return;
  state.entity = sel.value;
  repaint();
});
