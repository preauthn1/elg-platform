/* 后台 API 客户端 —— 所有变更类请求都必须带 x-requested-with: fetch（伺服器的 CSRF 防护） */

const BASE = '/api/admin';

export class ApiError extends Error {
  constructor(message, { status = 0, issues = [], problems = [] } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.issues = issues;
    this.problems = problems;
  }
}

async function request(method, path, { body, form } = {}) {
  const headers = {};
  if (method !== 'GET') headers['x-requested-with'] = 'fetch';
  let payload;
  if (form) {
    payload = form; // multipart：让浏览器自己带 boundary
  } else if (body !== undefined) {
    headers['content-type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  let resp;
  try {
    resp = await fetch(BASE + path, { method, headers, body: payload, credentials: 'same-origin' });
  } catch (err) {
    throw new ApiError('连线失败：' + err.message, { status: 0 });
  }

  if (resp.status === 401) {
    location.href = '/admin/login.html';
    throw new ApiError('登入已过期', { status: 401 });
  }

  let data = null;
  try {
    data = await resp.json();
  } catch {
    /* 非 JSON 回应 */
  }

  if (!resp.ok || (data && data.ok === false)) {
    throw new ApiError((data && data.error) || `HTTP ${resp.status}`, {
      status: resp.status,
      issues: (data && data.issues) || [],
      problems: (data && data.problems) || [],
    });
  }
  return data;
}

export const api = {
  me: () => request('GET', '/me'),
  logout: () => request('POST', '/logout'),

  getContent: (key) => request('GET', '/content/' + key).then((r) => r.data),
  putContent: (key, data) => request('PUT', '/content/' + key, { body: data }),

  /* 单一类别的最近纪录（官方联系与即时数据那两页的小卡用）。仅管理员可取得。 */
  audit: (entity, limit = 20) =>
    request('GET', `/audit?limit=${limit}` + (entity ? `&entity=${encodeURIComponent(entity)}` : '')).then((r) => r.data),

  /* 操作纪录那一页用。回的是 { data, facets } —— facets 是筛选下拉的选项来源。
     非管理员会拿到 403（伺服器端挡，不是只靠选单藏起来）。 */
  auditLog: ({ limit = 200, action = '', entity = '', user = '' } = {}) => {
    const q = new URLSearchParams({ limit: String(limit) });
    if (action) q.set('action', action);
    if (entity) q.set('entity', entity);
    if (user) q.set('user', user);
    return request('GET', '/audit?' + q.toString());
  },

  /* 内容快照（仅管理员）。listSnapshots 回 [{ file, ts, name }]，新的在前。
     还原会整个覆写一个类别 —— writeEntity 会先留一份快照，所以按错了救得回来。 */
  listSnapshots: () => request('GET', '/snapshots').then((r) => r.data),
  restoreSnapshot: (file) => request('POST', '/restore', { body: { file } }),

  /* 注：后端还有几支这里没包的端点 —— GET /uploads、DELETE /uploads/:name。
     GET /uploads、DELETE /uploads/:name。它们可以用、有权限与稽核，
     只是还没接 UI。前端包装先移除（死程式码），端点留着：
     快照是「改坏了可以捞回来」的唯一途径，砍掉等于拿掉资料安全网。
     要接 UI 时照 getContent 的形状补回来即可。 */

  upload: (file, kind = '') => {
    const fd = new FormData();
    fd.append('file', file);
    return request('POST', '/uploads' + (kind ? '?kind=' + encodeURIComponent(kind) : ''), { form: fd });
  },

  recomputeStats: () => request('POST', '/stats/recompute').then((r) => r.data),

  /* 帐号管理。仅管理员（端点前面有 requireAdmin）。
     回应一律不带 passwordHash —— 杂凑没有理由离开伺服器。 */
  listUsers: () => request('GET', '/users').then((r) => r.data),
  createUser: (body) => request('POST', '/users', { body }).then((r) => r.data),
  updateUser: (username, body) =>
    request('PUT', '/users/' + encodeURIComponent(username), { body }).then((r) => r.data),
  deleteUser: (username) => request('DELETE', '/users/' + encodeURIComponent(username)),

  /* 系统设置（取数用的 API 网址与三个密钥）。仅管理员。
     ⚠ 这四个值原样往返 —— 会出现在 network 面板里，挡在前面的是
     requireAdmin 的三道锁（登入 → admin 角色 → 公司 IP），不是遮罩。 */
  getApiConfig: () => request('GET', '/api-config').then((r) => r.data),
  saveApiConfig: (body) => request('PUT', '/api-config', { body }).then((r) => r.data),
  /* 真的打一次那支端点，回 { stale, values, error }。失败原因是这一页唯一
     能讲出「IV 不是 16 bytes」「端点 404」的地方 —— 首页的「立即重新取数」只回布林。 */
  testApiConfig: () => request('POST', '/api-config/test').then((r) => r.data),
};

/* 前台公开端点：即时数据的预览值（跟前台看到的完全一样） */
export async function publicStats() {
  /* ⚠ cache: 'no-store' 是必要的，不是保险。/api/stats 回
     Cache-Control: public, max-age=60（那是给前台与 CDN 的），所以后台存档后
     重抓会从浏览器快取拿到「存档前」的数字，看起来就像「存了没反应」。 */
  const r = await fetch('/api/stats', { credentials: 'same-origin', cache: 'no-store' });
  const d = await r.json();
  if (!r.ok || !d.ok) throw new ApiError(d.error || '取数失败', { status: r.status });
  return d.data;
}
