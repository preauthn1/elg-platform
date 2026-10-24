/* 操作纪录（仅管理员）——「谁在什么时候、从哪个 IP、改了什么」。
   原型没有这一页：原型假设写进既有系统的稽核日志，本专案自己做（见 spec §5）。

   资料来源是 content/system.json 的 audit 阵列（上限 2000 笔），
   端点 GET /api/admin/audit 前面有 requireAdmin —— 选单藏起来只是介面，
   权限在伺服器那一侧。

   这一页是唯读的，没有存档按钮：稽核纪录能被后台改就不叫稽核纪录了。 */
import { api } from '../api.js';
import { crumb } from '../shell.js';
import { esc, toast, ACTIONS } from '../ui.js';

/* 动作的中文说明。认不出的动作原样显示（旧纪录可能有已经拿掉的动作，
   例如 publish / rollback 是静态发布时代留下的）。 */
const ACTION_LABEL = {
  login: '登入成功',
  login_failed: '登入失败',
  update: '内容变更',
  restore: '还原快照',
  purge_cache: '清除快取',
  recompute: '即时数据重算',
  user_add: '新增帐号',
  user_update: '帐号变更',
  user_remove: '移除帐号',
  publish: '发布（已停用的机制）',
  rollback: '回滚（已停用的机制）',
};
/* 需要一眼看出来的动作。login_failed 是唯一值得标红的 ——
   连续几十笔同一个帐号就是有人在试密码。 */
const ACTION_TONE = {
  login_failed: 'warn',
  restore: 'warn',
  update: 'on',
  // 帐号的增删改是权限变动，翻纪录时要能立刻看到
  user_add: 'warn',
  user_update: 'warn',
  user_remove: 'warn',
};

const ENTITY_LABEL = {
  auth: '登入与帐号',
  site: '全站',
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

// 筛选条件放模组层级，切到别页再切回来时保留
const filter = { action: '', entity: '', user: '', limit: 200 };
let facets = { total: 0, actions: [], users: [], entities: [] };
let rows = [];
let loadErr = '';

function fmtTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? String(iso) : d.toLocaleString('zh-TW', { hour12: false });
}

/** 差异摘要 → 一句人看得懂的话。
 *  diffSummary 对物件给 changedKeys、对阵列给 rowsBefore/rowsAfter/changedRows/added/removed。 */
/* 内容变更画成「栏位　新值」逐行清单；其他动作（登入、还原…）只有一行摘要。
   只显示新值 —— 站方要看的是「我刚刚把它改成什么」。旧值仍然存在纪录里（c.from），
   要查的时候还在，只是不画出来。 */
const isBlank = (s) => s === '（空）' || s === '[]' || s === '{}';
function changeList(r) {
  const all = r.changes || [];
  /* 旧纪录里有「（空）→（空）」这种没有意义的列（伺服器端现在已经不会再写），
     显示时一并滤掉，不然画面上一堆看不出改了什么的行。 */
  const shown = all.filter((c) => !(c.from === c.to || (isBlank(c.from) && isBlank(c.to))));
  const rows = shown.map(
    (c) => `<div class="chg">
      <b>${esc(c.path)}</b>
      <span class="now">${esc(isBlank(c.to) ? '（已清空）' : c.to)}</span>
    </div>`
  );
  // 全部被滤掉：别让这一列看起来什么都没发生，也别谎称「没有变更」—— 旧纪录可能真的有改，只是细节没留下
  if (all.length && !shown.length && !r.addedRows && !r.removedRows) {
    rows.push('<div class="chg muted">无可显示的细节</div>');
  }
  if (r.addedRows) rows.push(`<div class="chg"><b class="add">＋ 新增</b><span class="now">${esc(r.addedRows.join('、'))}</span></div>`);
  if (r.removedRows) rows.push(`<div class="chg"><b class="del">－ 删除</b><span class="now">${esc(r.removedRows.join('、'))}</span></div>`);
  if (r.more) rows.push('<div class="chg muted">还有更多变更未列出</div>');
  return rows.join('');
}

function summarize(r) {
  const list = changeList(r);
  if (list) return list;
  const bits = [];
  if (r.rowsAfter !== undefined) bits.push(`共 ${r.rowsAfter} 笔，内容未变`);
  if (r.from) bits.push(`来源快照 ${r.from}`);
  if (r.username) bits.push(`尝试帐号 ${r.username}`);
  // 合并过的登入失败：讲清楚是几次、来自几个来源
  if (r.count > 1) {
    const src = Array.isArray(r.ips) ? r.ips.length : 1;
    bits.push(`连续失败 ${r.count} 次` + (src > 1 ? `，来自 ${src} 个来源 IP` : ''));
  }
  if (r.stale !== undefined) bits.push(r.stale ? '这一轮没取到数，沿用上一次的值' : '取数成功');
  return bits.length ? `<span class="muted">${esc(bits.join('　'))}</span>` : '';
}

function selectFilter(name, cur, options, allLabel) {
  return `<select class="sel" style="min-width:150px" data-auditfilter="${name}">
    <option value="" ${cur === '' ? 'selected' : ''}>${esc(allLabel)}</option>
    ${options
      .map((o) => `<option value="${esc(o.v)}" ${String(o.v) === String(cur) ? 'selected' : ''}>${esc(o.t)}</option>`)
      .join('')}
  </select>`;
}

export const audit = {
  needs: [],
  render() {
    const actionOpts = facets.actions.map((a) => ({ v: a, t: ACTION_LABEL[a] || a }));
    const entityOpts = facets.entities.map((e) => ({ v: e, t: ENTITY_LABEL[e] || e }));
    const userOpts = facets.users.map((u) => ({ v: u, t: u }));

    return `
${crumb('audit')}
<div class="card">
  <div class="card-h">操作纪录
    <span class="sub">目前共 ${facets.total} 笔</span>
    <div class="right">
      ${selectFilter('action', filter.action, actionOpts, '全部动作')}
      ${selectFilter('entity', filter.entity, entityOpts, '全部类别')}
      ${selectFilter('user', filter.user, userOpts, '全部操作者')}
      ${selectFilter(
        'limit',
        String(filter.limit),
        [50, 200, 500, 1000].map((n) => ({ v: String(n), t: `最近 ${n} 笔` })),
        '笔数'
      )}
      <button class="btn sm" data-act="audit.reload">重新整理</button>
    </div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:150px">时间</th>
      <th style="width:110px">操作者</th>
      <th style="width:130px">动作</th>
      <th style="width:110px">类别</th>
      <th style="width:120px">IP</th>
      <th>摘要</th>
    </tr></thead>
    <tbody>${
      loadErr
        ? `<tr><td colspan="6" class="muted">载入失败：${esc(loadErr)}</td></tr>`
        : rows.length
        ? rows
            .map((r) => {
              const tone = ACTION_TONE[r.action] || '';
              return `<tr>
        <td class="mono" style="font-size:13.5px">${esc(fmtTime(r.at))}
          ${r.count > 1 ? `<div class="muted" style="font-size:12.5px">起 ${esc(fmtTime(r.firstAt))}</div>` : ''}
        </td>
        <td>${esc(r.user || '—')}</td>
        <td><span class="tag ${tone}">${esc(ACTION_LABEL[r.action] || r.action || '—')}</span>
          ${r.count > 1 ? `<span class="tag warn" style="margin-left:4px">×${esc(r.count)}</span>` : ''}
        </td>
        <td>${esc(ENTITY_LABEL[r.entity] || r.entity || '—')}</td>
        <td class="mono muted" style="font-size:13px">${esc(r.ip || '—')}</td>
        <td style="font-size:13.5px">${summarize(r)}</td>
      </tr>`;
            })
            .join('')
        : '<tr><td colspan="6" class="muted">没有符合条件的纪录</td></tr>'
    }</tbody>
  </table></div></div>
</div>`;
  },
  mount() {
    // 第一次进来（还没抓过）才自动载入，切回来时用既有资料，不重打
    if (!rows.length && !loadErr) load();
  },
};

async function load() {
  try {
    const r = await api.auditLog({
      limit: filter.limit,
      action: filter.action,
      entity: filter.entity,
      user: filter.user,
    });
    rows = r.data || [];
    if (r.facets) facets = r.facets;
    loadErr = '';
  } catch (err) {
    rows = [];
    loadErr = err.message;
  }
  // renderView 由 ui.js 注入，重画这一页但不卷回顶端
  const { renderView } = await import('../ui.js');
  renderView();
}

Object.assign(ACTIONS, {
  'audit.reload': async (a, node) => {
    node.disabled = true;
    node.textContent = '载入中…';
    await load();
    toast('已重新整理', 'info');
  },
});

/* 筛选下拉的 change 事件。app.js 的全域委派只处理 data-act 与 data-bind，
   所以这里自己挂一个 —— 挂在 document 上一次就好（模组只会载入一次）。 */
document.addEventListener('change', (e) => {
  const sel = e.target.closest && e.target.closest('[data-auditfilter]');
  if (!sel) return;
  const name = sel.dataset.auditfilter;
  filter[name] = name === 'limit' ? Number(sel.value) || 200 : sel.value;
  load();
});
