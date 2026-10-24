/* 帐号管理 —— 新增／移除帐号、改站方的密码、改角色。仅管理员。

   三道锁在伺服器那一侧（requireAdmin）：登入 → 管理员角色 → IP 白名单。
   选单藏起来只是介面。站方拿的是 editor，看不到这一页。

   ⚠ 画面上不会有密码杂凑 —— 端点回的资料本来就不带（见 routes/users.js）。
   改密码是「输入新的」，不是「看现在的」：bcrypt 是单向的，本来就读不回来。

   ⚠ 资料不走 store.js（那一层是逐类别的内容资料），自己拿 api；
   render() 是同步的拿不到网路结果，所以资料在 mount() 补。 */
import { api } from '../api.js';
import { crumb } from '../shell.js';
import { esc, toast, ACTIONS, selectOf, renderView } from '../ui.js';

let state = { users: [], roles: ['admin', 'editor'], me: '', error: '' };

const ROLE_LABEL = { admin: '管理员', editor: '站方编辑' };
const ROLE_NOTE = {
  admin: '多看得到「操作纪录」与「帐号管理」，限公司 IP 登入',
  editor: '所有内容编辑，任何地方都能登入',
};

/** 'YYYY-MM-DDTHH:mm:ss.sssZ' → 'YYYY-MM-DD HH:mm'；空值回破折号 */
function stamp(v) {
  if (!v) return '—';
  const m = String(v).match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
  return m ? `${m[1]} ${m[2]}` : String(v).slice(0, 16);
}

async function fetchUsers() {
  try {
    const d = await api.listUsers();
    state = { ...d, error: '' };
  } catch (err) {
    state = { ...state, error: err.message };
  }
  renderView();
}

export const accounts = {
  needs: [],
  render() {
    if (state.error) {
      return `${crumb('accounts')}
<div class="card"><div class="card-b">
  <div class="hint"><span class="ic">⚠</span><div>读取失败：${esc(state.error)}</div></div>
</div></div>`;
    }
    const rows = state.users
      .map((u) => {
        const isMe = u.username === example.invalid;
        const lastAdmin = u.role === 'admin' && state.users.filter((x) => x.role === 'admin').length <= 1;
        /* 改角色／移除对这一列没有意义时直接不画，不要画成 disabled ——
           永远点不动的按钮只会让人一直去点它，然后怀疑是不是坏了。
           自己（会把自己踢出这一页）与最后一个管理员（之后就没人管得了帐号）都算。
           ⚠ 这只是介面：真正的把关在 routes/users.js 与 lib/users.js，藏起来不等于挡住。 */
        const locked = isMe || lastAdmin;
        return `<tr>
        <td style="font-weight:600">${esc(u.username)}${
          isMe ? '<span class="muted" style="font-weight:400;font-size:13px">（你）</span>' : ''
        }</td>
        <td>${esc(ROLE_LABEL[u.role] || u.role)}
          <div class="muted" style="font-size:12.5px">${esc(ROLE_NOTE[u.role] || '')}</div></td>
        <td class="mono muted" style="font-size:13px">${esc(stamp(u.createdAt))}</td>
        <td class="mono muted" style="font-size:13px">${esc(stamp(u.updatedAt))}</td>
        <td class="ops">
          <button class="btn text" data-act="acc.pw" data-arg="${esc(u.username)}">改密码</button>
          ${locked
            ? ''
            : `<button class="btn text" data-act="acc.role" data-arg="${esc(u.username)}">改角色</button>
               <button class="btn text danger-t" data-act="acc.del" data-arg="${esc(u.username)}">移除</button>`}
        </td>
      </tr>`;
      })
      .join('');

    return `
${crumb('accounts')}
<div class="card">
  <div class="card-h">后台帐号 <span class="sub">${state.users.length} 个</span>
    <div class="right">
      <button class="btn sm" data-act="acc.reload">重新载入</button>
      <button class="btn sm primary" data-act="acc.add">新增帐号</button>
    </div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:200px">帐号</th><th style="width:280px">角色</th>
      <th style="width:150px">建立</th><th style="width:150px">最后更新</th>
      <th style="width:230px">操作</th>
    </tr></thead>
    <tbody>${rows}</tbody>
  </table></div></div>
</div>`;
  },
  mount() {
    // 只在还没载入过时抓 —— renderView 会再走一次 mount，不挡就会无限循环
    if (!state.users.length && !state.error) fetchUsers();
  },
};

/** 密码栏位（两次输入，避免打错自己却不知道）。
 *  「至少 6 码」放在输入框右边的 .fitem 里 —— 原本是独立一行摆在最左边，
 *  跟栏位对不齐，看起来像掉在外面的一句话。规则贴着它管的那一格才读得懂。 */
const pwFields = () => `
  <div class="form-row">
    <div class="fitem"><label>新密码</label>
      <input class="inp" type="password" id="accpw1" autocomplete="new-password" style="min-width:220px">
      <span class="muted" style="font-size:13.5px">至少 6 码</span></div>
  </div>
  <div class="form-row">
    <div class="fitem"><label>再输入一次</label>
      <input class="inp" type="password" id="accpw2" autocomplete="new-password" style="min-width:220px"></div>
  </div>`;

/** 读两次密码栏位，不一致或太短就回 null 并提示 */
function readPw() {
  const a = (document.getElementById('accpw1') || {}).value || '';
  const b = (document.getElementById('accpw2') || {}).value || '';
  if (a.length < 6) return toast('密码至少 6 码', 'info'), null;
  if (a !== b) return toast('两次输入的密码不一致', 'info'), null;
  return a;
}

/** 包一层：跑 API、成功就重抓清单、失败就把伺服器的讯息显示出来 */
async function run(fn, okMsg) {
  try {
    await fn();
    toast(okMsg);
    await fetchUsers();
    return true;
  } catch (err) {
    toast(err.message, 'info');
    return false;
  }
}

Object.assign(ACTIONS, {
  'acc.reload': () => fetchUsers(),

  'acc.add': async () => {
    const { openDlg, closeDlg } = await import('../shell.js');
    openDlg('新增帐号', {
      step1: () => `
        <div class="form-row">
          <div class="fitem"><label>帐号</label>
            <input class="inp mono" id="accuser" style="min-width:220px" placeholder="限 3-40 字元的英数 / . _ -"></div>
          <div class="fitem"><label>角色</label>
            ${selectOf(
              state.roles.map((v) => ({ v, t: ROLE_LABEL[v] || v })),
              'editor',
              '',
              'min-width:150px'
            ).replace('<select', '<select id="accrole"')}</div>
        </div>
        ${pwFields()}`,
      onSave: async () => {
        const username = ((document.getElementById('accuser') || {}).value || '').trim();
        const role = (document.getElementById('accrole') || {}).value || 'editor';
        if (!username) return toast('请输入帐号', 'info');
        const password = [REDACTED];
        if (!password) return;
        if (await run(() => api.createUser({ username, password, role }), `已新增帐号 ${username}`)) closeDlg();
      },
    });
  },

  'acc.pw': async (username) => {
    const { openDlg, closeDlg } = await import('../shell.js');
    openDlg(`改密码 - ${username}`, {
      step1: () => `<div class="muted" style="margin-bottom:10px">帐号：<b>${esc(username)}</b></div>${pwFields()}`,
      onSave: async () => {
        const password = [REDACTED];
        if (!password) return;
        if (await run(() => api.updateUser(username, { password }), `已更新 ${username} 的密码`)) closeDlg();
      },
    });
  },

  'acc.role': async (username) => {
    const cur = (state.users.find((u) => u.username === username) || {}).role || 'editor';
    const { openDlg, closeDlg } = await import('../shell.js');
    openDlg(`改角色 - ${username}`, {
      step1: () => `
        <div class="form-row">
          <div class="fitem"><label>角色</label>
            ${selectOf(
              state.roles.map((v) => ({ v, t: ROLE_LABEL[v] || v })),
              cur,
              '',
              'min-width:150px'
            ).replace('<select', '<select id="accrole2"')}</div>
        </div>
        <div class="muted" style="font-size:13.5px">
          管理员限公司 IP 登入；站方编辑任何地方都能登入。
        </div>`,
      onSave: async () => {
        const role = (document.getElementById('accrole2') || {}).value;
        if (role === cur) return closeDlg();
        if (await run(() => api.updateUser(username, { role }), `已把 ${username} 改成 ${ROLE_LABEL[role]}`)) {
          closeDlg();
        }
      },
    });
  },

  'acc.del': (username) => {
    // 移除帐号无法复原（密码杂凑不会留副本），所以问一次
    if (!confirm(`确定要移除帐号「${username}」？\n\n这个动作无法复原，之后要重新建立并设新密码。`)) return;
    run(() => api.deleteUser(username), `已移除帐号 ${username}`);
  },
});
