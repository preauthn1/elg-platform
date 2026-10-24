#!/usr/bin/env node
// gitlab-workitem：補上 @zereight/mcp-gitlab 在公司 GitLab 做不到的工單操作。
// 上游的 update_work_item 不論改什麼，都會先送一個要回 WorkItemWidgetStatus／Weight／HealthStatus 的請求，
// 公司這台（社群版）沒有這三個型別，整個請求被拒。本檔的每個請求只帶用得到的欄位。
//
// 不引入 npm 套件：MCP 協定用 readline 逐行收發 JSON-RPC，GitLab 呼叫用 Node 內建的 fetch（需 Node 18 以上）。
// 金鑰沿用 gitlab-mcp.js 的 resolveGitlabPat，規則與 gitlab server 相同。
'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const readline = require('readline');
const { resolveGitlabPat, PAT_KEY, DEFAULT_GITLAB_API_URL } = require('./gitlab-mcp.js');
const { CalendarError, createCalendar, taipeiToday, parseIsoDate } = require('./tw-workday.js');

const SERVER_INFO = { name: 'gitlab-workitem', version: '1.0.0' };
// 只用到 tools，這幾版的 tools 協定彼此相容；client 要的版本不在清單內時回清單第一個
const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const REQUEST_TIMEOUT_MS = 20000;
const MAX_CHILDREN = 50;

/** 要回給呼叫端看的錯誤。訊息不得含金鑰。 */
class ToolError extends Error {}

// ─────────────────────────────────────────────────────────────
// GitLab 呼叫
// ─────────────────────────────────────────────────────────────

/**
 * REST 與 GraphQL 共用一把金鑰。金鑰缺少時照樣建立，呼叫當下才報錯，
 * 讓 /mcp 仍顯示 connected、錯誤訊息直接告訴使用者去設定 PAT。
 */
function createGitlabClient({ apiUrl, token, fetchImpl = globalThis.fetch, timeoutMs = REQUEST_TIMEOUT_MS }) {
  const restBase = apiUrl.replace(/\/+$/, '');
  const graphqlUrl = restBase.replace(/\/api\/v4$/, '') + '/api/graphql';

  function requireToken() {
    if (!token) {
      throw new ToolError('未取得 GitLab PAT，無法呼叫 GitLab；設定方式見 gitlab-mcp plugin 的 README「GitLab PAT 設定」');
    }
  }

  async function send(url, init, label) {
    let res;
    try {
      res = await fetchImpl(url, { ...init, signal: AbortSignal.timeout(timeoutMs) });
    } catch (e) {
      const reason = e && e.name === 'TimeoutError' ? `逾時（${timeoutMs / 1000} 秒）` : (e && e.message) || String(e);
      throw new ToolError(`${label} 連線失敗：${reason}（外網要先連 VPN）`);
    }
    const text = await res.text();
    let body = null;
    try {
      body = text ? JSON.parse(text) : null;
    } catch {
      // 非 JSON 的回應（例如代理伺服器的錯誤頁），交給呼叫端依狀態碼回報
    }
    return { res, body };
  }

  async function rest(method, pathname, payload) {
    requireToken();
    const label = `GitLab REST ${method} ${pathname}`;
    const init = { method, headers: { 'PRIVATE-TOKEN': token } };
    if (payload !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(payload);
    }
    const { res, body } = await send(restBase + pathname, init, label);
    if (!res.ok) {
      const msg = body && (body.message || body.error);
      const detail = msg ? '：' + (typeof msg === 'string' ? msg : JSON.stringify(msg)) : '';
      throw new ToolError(`${label} 回 ${res.status}${detail}`);
    }
    return body;
  }

  async function graphql(query, variables) {
    requireToken();
    const { res, body } = await send(graphqlUrl, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ query, variables }),
    }, 'GitLab GraphQL');
    if (!res.ok) throw new ToolError(`GitLab GraphQL 回 ${res.status}`);
    if (!body) throw new ToolError('GitLab GraphQL 的回應不是 JSON');
    if (Array.isArray(body.errors) && body.errors.length) {
      throw new ToolError('GitLab GraphQL 錯誤：' + body.errors.map(e => e.message).join('；'));
    }
    return body.data;
  }

  return { rest, graphql };
}

// ─────────────────────────────────────────────────────────────
// 輸入檢查
// ─────────────────────────────────────────────────────────────

const PROJECT_PATH_RE = /^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)+$/;

/** 專案可填路徑（target/tickets）或數字編號，回 { path } 或 { id }。 */
function parseProjectId(value, field) {
  const s = typeof value === 'number' ? String(value) : typeof value === 'string' ? value.trim() : '';
  if (/^[1-9]\d*$/.test(s)) return { id: s };
  if (PROJECT_PATH_RE.test(s) && !s.split('/').some(seg => seg === '.' || seg === '..')) return { path: s };
  throw new ToolError(`${field} 要填專案路徑（例如 target/tickets）或專案數字編號，收到：${JSON.stringify(value)}`);
}

/** 單號是工單畫面上的號碼（iid），不是 GitLab 內部的全域編號。容許前面帶 #。 */
function parseIid(value, field) {
  const s = typeof value === 'number' ? String(value) : typeof value === 'string' ? value.trim().replace(/^#/, '') : '';
  if (!/^[1-9]\d*$/.test(s)) throw new ToolError(`${field} 要填工單畫面上的單號（正整數），收到：${JSON.stringify(value)}`);
  return s;
}

/** YYYY-MM-DD 且是真實存在的日期（擋 2026-02-30 這類）。 */
function parseDate(value, field) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new ToolError(`${field} 格式要是 YYYY-MM-DD，收到：${JSON.stringify(value)}`);
  }
  const d = new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== value) {
    throw new ToolError(`${field} 不是有效日期：${value}`);
  }
  return value;
}

/** REST 路徑裡的專案段：數字編號原樣、路徑要 URL 編碼。 */
function projectKey(ref) {
  return ref.id || encodeURIComponent(ref.path);
}

// ─────────────────────────────────────────────────────────────
// 工單讀取（GraphQL）
// ─────────────────────────────────────────────────────────────

// 只取公司 GitLab 實際查得到的欄位；不要加 Status／Weight／HealthStatus 等 widget
const WORK_ITEM_QUERY = `query($fullPath: ID!, $iid: String!) {
  namespace(fullPath: $fullPath) {
    workItem(iid: $iid) {
      id iid title state reference(full: true) webUrl
      workItemType { name }
      widgets {
        type
        ... on WorkItemWidgetHierarchy {
          parent { id reference(full: true) }
          children(first: 100) { count nodes { id reference(full: true) title state } }
        }
        ... on WorkItemWidgetStartAndDueDate { startDate dueDate }
      }
    }
  }
}`;

async function resolveProjectPath(client, ref) {
  if (ref.path) return ref.path;
  const project = await client.rest('GET', `/projects/${ref.id}`);
  return project.path_with_namespace;
}

/**
 * 以「專案＋單號」讀工單，全域編號（gid）一律從查詢結果取得。
 * 把單號直接拼成 gid://gitlab/WorkItem/<單號> 不會報錯，而是指到另一張不相干的單，所以不提供這種捷徑。
 */
async function fetchWorkItem(client, projectRef, iid) {
  const fullPath = await resolveProjectPath(client, projectRef);
  const data = await client.graphql(WORK_ITEM_QUERY, { fullPath, iid });
  const w = data && data.namespace && data.namespace.workItem;
  if (!w) throw new ToolError(`找不到 ${fullPath}#${iid}，或你的帳號沒有權限看這張單`);
  const widgets = Array.isArray(w.widgets) ? w.widgets : [];
  const hierarchy = widgets.find(x => x.type === 'HIERARCHY');
  const dates = widgets.find(x => x.type === 'START_AND_DUE_DATE');
  const children = hierarchy && hierarchy.children ? hierarchy.children : { count: 0, nodes: [] };
  return {
    gid: w.id,
    reference: w.reference,
    title: w.title,
    state: w.state,
    type: w.workItemType && w.workItemType.name,
    webUrl: w.webUrl,
    hasHierarchy: !!hierarchy,
    parent: hierarchy && hierarchy.parent ? { gid: hierarchy.parent.id, reference: hierarchy.parent.reference } : null,
    children: children.nodes.map(c => ({ gid: c.id, reference: c.reference, title: c.title, state: c.state })),
    childrenCount: children.count,
    hasDates: !!dates,
    startDate: dates ? dates.startDate || null : null,
    dueDate: dates ? dates.dueDate || null : null,
  };
}

// ─────────────────────────────────────────────────────────────
// 工具：掛父子關係
// ─────────────────────────────────────────────────────────────

// 從子任務這一側設 parentId，每張子任務各送一次，錯誤訊息才對得到是哪一張
const ATTACH_MUTATION = `mutation($id: WorkItemID!, $parentId: WorkItemID!) {
  workItemUpdate(input: { id: $id, hierarchyWidget: { parentId: $parentId } }) { errors }
}`;

async function attachChildItems(client, args) {
  const parentRef = parseProjectId(args.parent_project_id, 'parent_project_id');
  const parentIid = parseIid(args.parent_iid, 'parent_iid');
  if (!Array.isArray(args.children) || args.children.length === 0) {
    throw new ToolError('children 至少要有一張子任務');
  }
  if (args.children.length > MAX_CHILDREN) throw new ToolError(`children 一次最多 ${MAX_CHILDREN} 張`);
  const childRefs = args.children.map((c, i) => ({
    project: parseProjectId(c && c.project_id, `children[${i}].project_id`),
    iid: parseIid(c && c.iid, `children[${i}].iid`),
  }));

  const parent = await fetchWorkItem(client, parentRef, parentIid);
  if (!parent.hasHierarchy) throw new ToolError(`${parent.reference}（${parent.type}）不能有子項`);

  // 先全部檢查完才動手：只要有一張不能掛，整批都不寫
  const toAttach = [];
  const already = [];
  const conflicts = [];
  const seen = new Set();
  for (const ref of childRefs) {
    const child = await fetchWorkItem(client, ref.project, ref.iid);
    if (seen.has(child.gid)) continue;
    seen.add(child.gid);
    child.ref = ref;
    if (child.gid === parent.gid) {
      conflicts.push({ reference: child.reference, reason: '不能把主單掛成自己的子項' });
    } else if (child.parent && child.parent.gid === parent.gid) {
      already.push(child);
    } else if (child.parent) {
      conflicts.push({ reference: child.reference, reason: `已經掛在 ${child.parent.reference} 底下；要改掛請先在 GitLab 網頁移除原本的父子關係` });
    } else {
      toAttach.push(child);
    }
  }
  if (conflicts.length) {
    return {
      isError: true,
      result: { parent: parent.reference, attached: [], conflicts, message: '有子任務不能掛，這次一張都沒有掛；處理完再重跑' },
    };
  }

  const attached = [];
  const failed = [];
  for (const child of toAttach) {
    try {
      const data = await client.graphql(ATTACH_MUTATION, { id: child.gid, parentId: parent.gid });
      const errors = (data && data.workItemUpdate && data.workItemUpdate.errors) || [];
      if (errors.length) failed.push({ reference: child.reference, error: errors.join('；') });
      else attached.push(child);
    } catch (e) {
      failed.push({ reference: child.reference, error: e.message });
    }
  }

  // 讀回主單的子項清單驗證；清單超過一頁時，清單裡找不到的再從子任務那一側確認
  const after = await fetchWorkItem(client, parentRef, parentIid);
  const afterGids = new Set(after.children.map(c => c.gid));
  const missing = [];
  for (const child of [...attached, ...already]) {
    if (afterGids.has(child.gid)) continue;
    if (after.childrenCount > after.children.length) {
      const again = await fetchWorkItem(client, child.ref.project, child.ref.iid);
      if (again.parent && again.parent.gid === parent.gid) continue;
    }
    missing.push(child.reference);
  }

  const result = {
    parent: parent.reference,
    attached: attached.map(c => c.reference),
    already_attached: already.map(c => c.reference),
    failed,
    verified: failed.length === 0 && missing.length === 0,
    missing_after_attach: missing,
    children_after: after.children.map(c => ({ reference: c.reference, title: c.title, state: c.state })),
  };
  if (after.childrenCount > after.children.length) result.children_after_truncated = true;
  return { isError: !result.verified, result };
}

/** 唯讀：列出主單現在的子任務，包含已關閉的。補拆子任務前用來判斷哪些已經建過。 */
async function listChildItems(client, args) {
  const project = parseProjectId(args.project_id, 'project_id');
  const iid = parseIid(args.iid, 'iid');
  const item = await fetchWorkItem(client, project, iid);
  if (!item.hasHierarchy) throw new ToolError(`${item.reference}（${item.type}）不能有子項`);
  const result = {
    reference: item.reference,
    title: item.title,
    type: item.type,
    state: item.state,
    parent: item.parent ? item.parent.reference : null,
    children_count: item.childrenCount,
    children: item.children.map(c => ({ reference: c.reference, title: c.title, state: c.state })),
  };
  if (item.childrenCount > item.children.length) result.children_truncated = true;
  return { result };
}

// ─────────────────────────────────────────────────────────────
// 工具：起訖日
// ─────────────────────────────────────────────────────────────

function datesView(item) {
  return { start_date: item.startDate, due_date: item.dueDate };
}

async function getWorkItemDates(client, args) {
  const project = parseProjectId(args.project_id, 'project_id');
  const iid = parseIid(args.iid, 'iid');
  const item = await fetchWorkItem(client, project, iid);
  if (!item.hasDates) throw new ToolError(`${item.reference}（${item.type}）沒有起訖日欄位`);
  return { result: { reference: item.reference, title: item.title, type: item.type, ...datesView(item) } };
}

/** 只帶有填的欄位，沒填的那個不出現在請求裡，GitLab 就不會動它。欄位名來自固定清單，不接外部字串。 */
function buildDatesMutation(fields) {
  const decl = fields.map(f => `$${f}: Date!`).join(', ');
  const inner = fields.map(f => `${f}: $${f}`).join(', ');
  return `mutation($id: WorkItemID!, ${decl}) {
  workItemUpdate(input: { id: $id, startAndDueDateWidget: { ${inner} } }) { errors }
}`;
}

async function setWorkItemDates(client, args) {
  const project = parseProjectId(args.project_id, 'project_id');
  const iid = parseIid(args.iid, 'iid');
  const start = args.start_date === undefined || args.start_date === null ? undefined : parseDate(args.start_date, 'start_date');
  const due = args.due_date === undefined || args.due_date === null ? undefined : parseDate(args.due_date, 'due_date');
  if (start === undefined && due === undefined) throw new ToolError('start_date 與 due_date 至少要填一個');

  const before = await fetchWorkItem(client, project, iid);
  if (!before.hasDates) throw new ToolError(`${before.reference}（${before.type}）沒有起訖日欄位`);
  const effectiveStart = start !== undefined ? start : before.startDate;
  const effectiveDue = due !== undefined ? due : before.dueDate;
  if (effectiveStart && effectiveDue && effectiveStart > effectiveDue) {
    throw new ToolError(`起始日 ${effectiveStart} 晚於結束日 ${effectiveDue}，沒有寫入`);
  }

  const fields = [];
  const variables = { id: before.gid };
  if (start !== undefined) { fields.push('startDate'); variables.startDate = start; }
  if (due !== undefined) { fields.push('dueDate'); variables.dueDate = due; }
  const data = await client.graphql(buildDatesMutation(fields), variables);
  const errors = (data && data.workItemUpdate && data.workItemUpdate.errors) || [];
  if (errors.length) throw new ToolError(`GitLab 拒絕寫入：${errors.join('；')}`);

  const after = await fetchWorkItem(client, project, iid);
  const verified = (start === undefined || after.startDate === start) && (due === undefined || after.dueDate === due);
  return {
    isError: !verified,
    result: { reference: after.reference, before: datesView(before), after: datesView(after), verified },
  };
}

// ─────────────────────────────────────────────────────────────
// 工具：描述存成檔案、從檔案送回
// ─────────────────────────────────────────────────────────────

/** 描述檔的專用目錄。檔名由工具決定，呼叫端不能指定路徑。 */
function defaultDraftsDir() {
  return path.join(os.tmpdir(), 'gff-gitlab-workitem');
}

function currentUid() {
  return typeof process.getuid === 'function' ? process.getuid() : -1;
}

/** 建立目錄並確認是自己擁有的一般資料夾（非 symlink），權限收成 700。 */
function ensureDraftsDir(dir, { platform = process.platform, uid = currentUid() } = {}) {
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const st = fs.lstatSync(dir);
  if (st.isSymbolicLink() || !st.isDirectory()) throw new ToolError(`描述暫存目錄不是一般資料夾：${dir}`);
  if (platform !== 'win32') {
    if (uid >= 0 && st.uid !== uid) throw new ToolError(`描述暫存目錄的擁有者不是目前的使用者：${dir}`);
    if ((st.mode & 0o077) !== 0) fs.chmodSync(dir, 0o700);
  }
}

/** target/tickets#4398 → elg__tickets__4398.md（與同名的 .meta.json）。 */
function draftPaths(dir, reference) {
  const m = /^(.+)#(\d+)$/.exec(reference || '');
  if (!m) throw new ToolError(`無法從 ${JSON.stringify(reference)} 決定描述檔名`);
  const base = `${m[1].replace(/\//g, '__').replace(/[^A-Za-z0-9_.-]/g, '_')}__${m[2]}`;
  return { file: path.join(dir, `${base}.md`), meta: path.join(dir, `${base}.meta.json`) };
}

function sha256(s) {
  return crypto.createHash('sha256').update(s, 'utf8').digest('hex');
}

/** 字數以 Unicode 字元計，中文一字算一。 */
function charCount(s) {
  return Array.from(s).length;
}

function lineCount(s) {
  return s === '' ? 0 : s.split('\n').length;
}

/**
 * GitLab 存描述時會把 CRLF 換成 LF、去掉結尾的空白與換行（開頭與中間的空白保留）。
 * 送出與比對前先做同樣的處理，編輯器在檔尾補的換行才不會被誤判成「有修改」或「寫入結果不一致」。
 */
function normalizeDescription(s) {
  return s.replace(/\r\n/g, '\n').replace(/\s+$/, '');
}

/** 讀描述檔與存檔紀錄。檔案不存在回 null；紀錄缺少或壞掉時 meta 為 null。 */
function readDraft(paths) {
  let content;
  try {
    content = fs.readFileSync(paths.file, 'utf8');
  } catch (e) {
    if (e.code === 'ENOENT') return null;
    throw new ToolError(`描述檔讀取失敗：${paths.file}`);
  }
  let meta = null;
  try {
    meta = JSON.parse(fs.readFileSync(paths.meta, 'utf8'));
  } catch {
    meta = null;
  }
  return { content, meta };
}

function writeMeta(paths, meta) {
  fs.writeFileSync(paths.meta, JSON.stringify(meta, null, 2) + '\n', { mode: 0o600 });
}

function issueReference(issue, fallback) {
  return (issue && issue.references && issue.references.full) || fallback;
}

async function saveIssueDescription(client, draftsDir, args) {
  const project = parseProjectId(args.project_id, 'project_id');
  const iid = parseIid(args.iid, 'iid');
  const overwrite = args.overwrite === true;

  const issue = await client.rest('GET', `/projects/${projectKey(project)}/issues/${iid}`);
  const reference = issueReference(issue, `${project.path || project.id}#${iid}`);
  const description = issue.description || '';

  ensureDraftsDir(draftsDir);
  const paths = draftPaths(draftsDir, reference);
  const existing = readDraft(paths);
  if (existing && !overwrite && normalizeDescription(existing.content) !== normalizeDescription(description)) {
    const editedLocally = !existing.meta || sha256(existing.content) !== existing.meta.saved_sha256;
    if (editedLocally) {
      throw new ToolError(`${paths.file} 有還沒送回的修改，沒有覆蓋。要送回請用 update_issue_description_from_file；要放棄這些修改、重新存檔請加 overwrite: true`);
    }
  }

  fs.writeFileSync(paths.file, description, { mode: 0o600 });
  writeMeta(paths, {
    reference,
    saved_sha256: sha256(description),
    remote_updated_at: issue.updated_at || null,
    saved_at: new Date().toISOString(),
  });
  return {
    result: {
      reference,
      title: issue.title,
      path: paths.file,
      chars: charCount(description),
      lines: lineCount(description),
      web_url: issue.web_url,
    },
  };
}

async function updateIssueDescriptionFromFile(client, draftsDir, args) {
  const project = parseProjectId(args.project_id, 'project_id');
  const iid = parseIid(args.iid, 'iid');
  const force = args.force === true;

  const issuePath = `/projects/${projectKey(project)}/issues/${iid}`;
  const issue = await client.rest('GET', issuePath);
  const reference = issueReference(issue, `${project.path || project.id}#${iid}`);
  const remote = issue.description || '';

  const paths = draftPaths(draftsDir, reference);
  const draft = readDraft(paths);
  if (!draft) throw new ToolError(`找不到 ${reference} 的描述檔（${paths.file}），請先用 save_issue_description 存檔`);
  if (!draft.meta || typeof draft.meta.saved_sha256 !== 'string') {
    throw new ToolError(`${paths.file} 缺少存檔紀錄，無法判斷 GitLab 上的描述有沒有被別人改過；請用 save_issue_description 重新存檔`);
  }
  const content = normalizeDescription(draft.content);
  if (content.trim() === '') {
    throw new ToolError('描述檔是空的，沒有送出；要清空描述請到 GitLab 網頁操作');
  }
  if (sha256(remote) !== draft.meta.saved_sha256 && !force) {
    throw new ToolError(`${reference} 的描述在存檔之後被改過（可能有人在網頁上編輯），沒有送出，以免蓋掉對方的修改。請用 save_issue_description 加 overwrite: true 重新存檔後再改；確認要蓋掉才加 force: true`);
  }
  if (content === normalizeDescription(remote)) {
    return { result: { reference, path: paths.file, changed: false, chars: charCount(remote), message: '描述檔跟 GitLab 上的內容相同，沒有送出' } };
  }

  // PUT 的回應帶有寫入後的描述，直接拿來核對，不必再讀一次
  const updated = await client.rest('PUT', issuePath, { description: content });
  const after = (updated && updated.description) || '';
  const verified = normalizeDescription(after) === content;
  // 一致時把本機檔改成 GitLab 實際存下的內容，接著再改一輪時存檔紀錄與檔案才對得上；
  // 不一致時保留本機檔，不讓使用者的修改被蓋掉
  if (verified) fs.writeFileSync(paths.file, after, { mode: 0o600 });
  writeMeta(paths, {
    reference,
    saved_sha256: sha256(after),
    remote_updated_at: (updated && updated.updated_at) || null,
    saved_at: new Date().toISOString(),
  });
  return {
    isError: !verified,
    result: {
      reference,
      path: paths.file,
      changed: true,
      chars_before: charCount(remote),
      chars_after: charCount(after),
      verified,
      web_url: (updated && updated.web_url) || issue.web_url,
    },
  };
}

// ─────────────────────────────────────────────────────────────
// 工具：台灣工作日（不需要 GitLab 金鑰，只讀 calendar/）
// ─────────────────────────────────────────────────────────────

const MAX_DATES = 31;

async function withCalendar(fn) {
  try {
    return await fn();
  } catch (e) {
    if (e instanceof CalendarError) throw new ToolError(e.message);
    throw e;
  }
}

async function checkWorkdays(calendar, args, now) {
  const today = taipeiToday(now);
  let dates = args.dates;
  if (dates === undefined || dates === null) {
    dates = [new Date(parseIsoDate(today).getTime() + 86400000).toISOString().slice(0, 10)];
  }
  if (!Array.isArray(dates) || dates.length === 0) throw new ToolError('dates 要是日期陣列（YYYY-MM-DD），或整個不填來查明天');
  if (dates.length > MAX_DATES) throw new ToolError(`dates 一次最多 ${MAX_DATES} 個`);
  const out = await withCalendar(() => calendar.checkWorkdays(dates));
  return { result: { today_taipei: today, ...out } };
}

async function nextWorkday(calendar, args) {
  return { result: await withCalendar(() => calendar.nextWorkday(args.after)) };
}

// ─────────────────────────────────────────────────────────────
// 工具清單
// ─────────────────────────────────────────────────────────────

const PROJECT_ID_SCHEMA = { type: 'string', description: '專案路徑（例如 target/tickets）或專案數字編號' };
const IID_SCHEMA = { type: 'integer', minimum: 1, description: '工單畫面上的單號，例如 4398（不是 GitLab 內部的全域編號）' };

const TOOL_DEFINITIONS = [
  {
    name: 'attach_child_items',
    description: '把既有的子任務掛到主單底下（GitLab 工單的父子關係），可以跨專案，例如主單在 target/tickets、子任務在 target/ticket_tasks。主單與子任務都用「專案＋畫面上的單號」指定，工具自己查出 GitLab 內部編號。動手前先檢查全部子任務：已經掛在這張主單下的略過；只要有一張掛在別張單下，整批都不動並回報。掛完讀回主單的子項清單驗證。',
    inputSchema: {
      type: 'object',
      properties: {
        parent_project_id: { ...PROJECT_ID_SCHEMA, description: '主單所在的專案路徑（例如 target/tickets）或專案數字編號' },
        parent_iid: { ...IID_SCHEMA, description: '主單畫面上的單號，例如 4398' },
        children: {
          type: 'array',
          minItems: 1,
          maxItems: MAX_CHILDREN,
          description: '要掛上去的子任務',
          items: {
            type: 'object',
            properties: {
              project_id: { ...PROJECT_ID_SCHEMA, description: '子任務所在的專案路徑（例如 target/ticket_tasks）或專案數字編號' },
              iid: { ...IID_SCHEMA, description: '子任務畫面上的單號' },
            },
            required: ['project_id', 'iid'],
            additionalProperties: false,
          },
        },
      },
      required: ['parent_project_id', 'parent_iid', 'children'],
      additionalProperties: false,
    },
    annotations: { title: '掛父子關係', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'list_child_items',
    description: '列出一張工單現在的子任務與開關狀態（唯讀），包含已關閉的；同時回傳它的主單（parent，沒有主單時是 null）。補拆子任務前用它確認已經建過哪些，不要憑印象或活動紀錄判斷；拿到子任務時也用它找主單，因為 get_issue 讀不到跨專案的父子關係。',
    inputSchema: {
      type: 'object',
      properties: { project_id: { ...PROJECT_ID_SCHEMA, description: '工單所在的專案路徑（例如 target/tickets、target/ticket_tasks）或專案數字編號' }, iid: { ...IID_SCHEMA, description: '工單畫面上的單號' } },
      required: ['project_id', 'iid'],
      additionalProperties: false,
    },
    annotations: { title: '列子任務', readOnlyHint: true, openWorldHint: true },
  },
  {
    name: 'get_work_item_dates',
    description: '讀工單的起始日與結束日（唯讀）。get_issue 只讀得到結束日，起始日要用這支。',
    inputSchema: {
      type: 'object',
      properties: { project_id: PROJECT_ID_SCHEMA, iid: IID_SCHEMA },
      required: ['project_id', 'iid'],
      additionalProperties: false,
    },
    annotations: { title: '讀起訖日', readOnlyHint: true, openWorldHint: true },
  },
  {
    name: 'set_work_item_dates',
    description: '改工單的起始日或結束日（YYYY-MM-DD），至少填一個，沒填的那個維持原值。改完後起始日不能晚於結束日，否則不寫入。寫入後讀回，回傳改前與改後的日期。',
    inputSchema: {
      type: 'object',
      properties: {
        project_id: PROJECT_ID_SCHEMA,
        iid: IID_SCHEMA,
        start_date: { type: 'string', description: '起始日，YYYY-MM-DD' },
        due_date: { type: 'string', description: '結束日，YYYY-MM-DD' },
      },
      required: ['project_id', 'iid'],
      additionalProperties: false,
    },
    annotations: { title: '改起訖日', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'save_issue_description',
    description: '把工單描述存成本機檔案，只回傳檔案路徑、字數與行數，不回傳描述內文。用在大幅改寫描述：存檔後直接改這個檔案（看結構用 grep，看某一段用 sed -n），改完用 update_issue_description_from_file 送回。只改幾個字時用 update_issue_description_patch 比較省。檔案放在本工具專用的暫存目錄，同一張單固定同一個檔名；檔案裡有還沒送回的修改時不會覆蓋，除非加 overwrite: true。',
    inputSchema: {
      type: 'object',
      properties: {
        project_id: PROJECT_ID_SCHEMA,
        iid: IID_SCHEMA,
        overwrite: { type: 'boolean', description: '放棄描述檔裡還沒送回的修改，重新存檔' },
      },
      required: ['project_id', 'iid'],
      additionalProperties: false,
    },
    annotations: { title: '描述存成檔案', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'update_issue_description_from_file',
    description: '把 save_issue_description 存的描述檔送回 GitLab，回傳改前改後的字數與寫入結果是否跟檔案一致，不回傳描述內文。檔案由專案＋單號決定，不能指定其他路徑。存檔之後如果有人在網頁上改過這張單的描述，會停下不送，以免蓋掉對方的修改，確認要蓋掉才加 force: true。描述檔是空的也不送。',
    inputSchema: {
      type: 'object',
      properties: {
        project_id: PROJECT_ID_SCHEMA,
        iid: IID_SCHEMA,
        force: { type: 'boolean', description: '存檔後 GitLab 上的描述被別人改過時，仍然用檔案內容蓋掉' },
      },
      required: ['project_id', 'iid'],
      additionalProperties: false,
    },
    annotations: { title: '從檔案送回描述', readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true },
  },
  {
    name: 'check_workdays',
    description: '查台灣的日期是不是工作日（唯讀，不需要 GitLab）。依政府「行政機關辦公日曆表」判斷，週末、國定假日、補假、補班都已標好；放假日的 note 會寫假日名稱，例如「中秋節」「補假」。不填 dates 時查台灣時間的明天，回傳裡的 today_taipei 是判斷用的今天。日曆沒收錄該年份時回錯誤，不會自己推算。warnings 非空代表政府出了修正版或新年度、repo 還沒跟上，判斷仍以 repo 的日曆為準。',
    inputSchema: {
      type: 'object',
      properties: {
        dates: { type: 'array', items: { type: 'string' }, minItems: 1, maxItems: MAX_DATES, description: '要查的日期，YYYY-MM-DD；不填就查台灣時間的明天' },
      },
      additionalProperties: false,
    },
    annotations: { title: '查工作日', readOnlyHint: true, openWorldHint: false },
  },
  {
    name: 'next_workday',
    description: '找某個日期之後（不含當天）的第一個台灣工作日（唯讀，不需要 GitLab）。排期要「加一天」時用它取代，連假會整段跳過；skipped 列出中間跳過的放假日與假日名稱。日曆沒收錄需要的年份時回錯誤，不會自己推算。',
    inputSchema: {
      type: 'object',
      properties: { after: { type: 'string', description: '起算日期，YYYY-MM-DD；回傳的是這天之後的第一個工作日' } },
      required: ['after'],
      additionalProperties: false,
    },
    annotations: { title: '下一個工作日', readOnlyHint: true, openWorldHint: false },
  },
];

function createToolset({ client, draftsDir, calendar = createCalendar(), now = () => new Date() }) {
  return {
    tools: TOOL_DEFINITIONS,
    handlers: {
      attach_child_items: args => attachChildItems(client, args),
      list_child_items: args => listChildItems(client, args),
      get_work_item_dates: args => getWorkItemDates(client, args),
      set_work_item_dates: args => setWorkItemDates(client, args),
      save_issue_description: args => saveIssueDescription(client, draftsDir, args),
      update_issue_description_from_file: args => updateIssueDescriptionFromFile(client, draftsDir, args),
      check_workdays: args => checkWorkdays(calendar, args, now()),
      next_workday: args => nextWorkday(calendar, args),
    },
  };
}

// ─────────────────────────────────────────────────────────────
// MCP 協定（JSON-RPC 2.0，stdio 一行一則）
// ─────────────────────────────────────────────────────────────

function rpcResult(id, result) {
  return { jsonrpc: '2.0', id, result };
}

function rpcError(id, code, message) {
  return { jsonrpc: '2.0', id, error: { code, message } };
}

function negotiateProtocolVersion(requested) {
  return SUPPORTED_PROTOCOL_VERSIONS.includes(requested) ? requested : SUPPORTED_PROTOCOL_VERSIONS[0];
}

/**
 * 處理一則訊息，回應物件或 null（通知不回）。
 * 工具執行失敗放在結果的 isError，不當成 JSON-RPC 錯誤，讓 Claude 看得到原因。
 */
function createMcpServer({ tools, handlers }) {
  async function handle(msg) {
    if (!msg || typeof msg !== 'object' || Array.isArray(msg) || msg.jsonrpc !== '2.0' || typeof msg.method !== 'string') {
      return rpcError(msg && typeof msg === 'object' && 'id' in msg ? msg.id : null, -32600, 'Invalid Request');
    }
    const isRequest = 'id' in msg && msg.id !== null;
    if (!isRequest) return null;
    const { id } = msg;
    const params = msg.params || {};

    switch (msg.method) {
      case 'initialize':
        return rpcResult(id, {
          protocolVersion: negotiateProtocolVersion(params.protocolVersion),
          capabilities: { tools: {} },
          serverInfo: SERVER_INFO,
        });
      case 'ping':
        return rpcResult(id, {});
      case 'tools/list':
        return rpcResult(id, { tools });
      case 'tools/call': {
        const handler = Object.prototype.hasOwnProperty.call(handlers, params.name) ? handlers[params.name] : null;
        if (!handler) return rpcError(id, -32602, `Unknown tool: ${params.name}`);
        try {
          const out = await handler(params.arguments || {});
          return rpcResult(id, {
            content: [{ type: 'text', text: JSON.stringify(out.result, null, 2) }],
            isError: !!out.isError,
          });
        } catch (e) {
          const text = e instanceof ToolError ? e.message : `內部錯誤：${(e && e.message) || String(e)}`;
          return rpcResult(id, { content: [{ type: 'text', text }], isError: true });
        }
      }
      default:
        return rpcError(id, -32601, `Method not found: ${msg.method}`);
    }
  }
  return { handle };
}

/** stdin 關閉後等處理中的請求都回完才結束。 */
function serveStdio(server, { input = process.stdin, output = process.stdout, onClose = () => process.exit(0) } = {}) {
  const rl = readline.createInterface({ input, crlfDelay: Infinity });
  let pending = 0;
  let closed = false;
  const write = obj => output.write(JSON.stringify(obj) + '\n');
  const maybeFinish = () => {
    if (closed && pending === 0) onClose();
  };

  rl.on('line', async line => {
    if (!line.trim()) return;
    let msg;
    try {
      msg = JSON.parse(line);
    } catch {
      write(rpcError(null, -32700, 'Parse error'));
      return;
    }
    pending += 1;
    try {
      const res = await server.handle(msg);
      if (res) write(res);
    } finally {
      pending -= 1;
      maybeFinish();
    }
  });
  rl.on('close', () => {
    closed = true;
    maybeFinish();
  });
}

function main() {
  if (typeof globalThis.fetch !== 'function') {
    console.error('[gitlab-workitem] 需要 Node 18 以上（內建 fetch），未啟動');
    process.exit(1);
  }
  let resolution;
  try {
    resolution = resolveGitlabPat({
      env: process.env,
      platform: process.platform,
      homedir: os.homedir(),
      uid: currentUid(),
    });
  } catch (e) {
    // fail-closed：與 gitlab server 相同，secrets 檔有問題就不啟動，不退回其他金鑰來源
    console.error('[gitlab-workitem] secrets 解析失敗（fail-closed，未啟動）：' + (e && e.message ? e.message : String(e)));
    process.exit(1);
  }
  const token = [REDACTED] === 'file' ? resolution.token : [REDACTED] || '').trim();
  if (!token) {
    console.error('[gitlab-workitem] 未偵測到 GitLab PAT：工具照常列出，但呼叫時會回報缺少 PAT；設定方式見 plugin README。');
  }
  const client = createGitlabClient({ apiUrl: process.env.GITLAB_API_URL || DEFAULT_GITLAB_API_URL, token });
  serveStdio(createMcpServer(createToolset({ client, draftsDir: defaultDraftsDir() })));
}

if (require.main === module) main();

module.exports = {
  ToolError,
  createGitlabClient,
  parseProjectId,
  parseIid,
  parseDate,
  fetchWorkItem,
  attachChildItems,
  listChildItems,
  getWorkItemDates,
  setWorkItemDates,
  buildDatesMutation,
  saveIssueDescription,
  updateIssueDescriptionFromFile,
  checkWorkdays,
  nextWorkday,
  ensureDraftsDir,
  draftPaths,
  defaultDraftsDir,
  charCount,
  normalizeDescription,
  createToolset,
  createMcpServer,
  serveStdio,
  negotiateProtocolVersion,
  TOOL_DEFINITIONS,
  SUPPORTED_PROTOCOL_VERSIONS,
};
