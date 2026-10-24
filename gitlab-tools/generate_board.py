#!/usr/bin/env python3
"""
產生 ticket_tasks 成員任務看板的靜態 HTML（public/index.html）。
在 GitLab CI 中執行：用唯讀 token 向 GraphQL 抓開啟中任務，把資料烤進 HTML。
Token 只在 CI runner 使用，不會出現在產出的頁面裡。

需要的環境變數：
  GITLAB_READ_TOKEN  : 具 read_api 權限的 Project/Group Access Token（設為 masked CI 變數）
  CI_SERVER_URL      : GitLab 網址（CI 自動提供，例如 http://example.invalid:1688）
  BOARD_PROJECT      : 目標專案完整路徑（預設 target/ticket_tasks）
"""
import os
import sys
import datetime

from gitlab_api import (
    GitLabGraphQLError,
    atomic_write_text,
    fetch_all_work_items,
    json_for_html,
)
from tw_holidays import TW_HOLIDAYS

SERVER  = os.environ.get("CI_SERVER_URL", "http://example.invalid:1688").rstrip("/")
PROJECT = os.environ.get("BOARD_PROJECT", "target/ticket_tasks")
TOKEN   = [REDACTED]"GITLAB_READ_TOKEN")
TIMEZONE = datetime.timezone(datetime.timedelta(hours=8), name="Asia/Taipei")
SOON_DAYS = 2
OVERLOAD_TASKS = 8

FIELDS = """
iid title
widgets {
  ... on WorkItemWidgetStartAndDueDate { startDate dueDate }
  ... on WorkItemWidgetAssignees { assignees { nodes { name username avatarUrl } } }
}
"""


def fetch_nodes():
    if not TOKEN:
        [REDACTED]"錯誤：缺少 GITLAB_READ_TOKEN 環境變數")
    try:
        return fetch_all_work_items(SERVER, PROJECT, TOKEN, FIELDS)
    except GitLabGraphQLError as error:
        sys.exit("GraphQL 錯誤：" + str(error))


def to_rows(nodes):
    rows = []
    for n in nodes:
        start = due = ""
        for w in n.get("widgets", []):
            if "startDate" in w:
                start = w.get("startDate") or ""
                due = w.get("dueDate") or ""
        title = n["title"]
        if title.startswith("[GFF]"):
            title = title[5:]
        assignees = [
            {
                "username": a.get("username") or a["name"],
                "name": a["name"],
                "avatar": a.get("avatarUrl") or "",
            }
            for w in n.get("widgets", []) if "assignees" in w
            for a in w["assignees"]["nodes"]
        ]
        rows.append([int(n["iid"]), assignees, start, due, title])
    rows.sort(key=lambda r: -r[0])
    return rows


def main():
    rows = to_rows(fetch_nodes())
    now = datetime.datetime.now(TIMEZONE)
    asof = now.strftime("%Y-%m-%d")
    lastupd = now.strftime("%Y-%m-%d %H:%M")

    html = (TEMPLATE
            .replace("__RAW_JSON__", json_for_html(rows))
            .replace("__HOLIDAYS__", json_for_html(TW_HOLIDAYS))
            .replace("__ASOF__", asof)
            .replace("__LASTUPDATED__", lastupd)
            .replace("__SOON_DAYS__", str(SOON_DAYS))
            .replace("__OVERLOAD_TASKS__", str(OVERLOAD_TASKS))
            .replace("__ORIGIN__", SERVER)
            .replace("__PROJECT__", PROJECT))
    atomic_write_text("public/index.html", html)
    print("已產生 public/index.html：%d 個任務，更新時間 %s" % (len(rows), lastupd))


TEMPLATE = r"""<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ticket_tasks 成員任務看板</title>
<style>
:root { color-scheme: light;
  --overdue:#c92a2a; --today:#e8590c; --soon:#f0a000; --later:#4c8dd6; --none:#ced4da; }
* { box-sizing: border-box; }
body { margin:0; padding:16px; font-family:-apple-system,"PingFang TC","Microsoft JhengHei","Segoe UI",sans-serif; background:#f6f7f9; color:#1c2430; font-size:15px; zoom:1.18; }
h1 { font-size:18px; margin:0 0 2px; }
h2 { font-size:13px; margin:20px 0 8px; color:#3a4453; letter-spacing:.02em; }
.topbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.topbar .meta { font-size:12px; color:#6b7686; }
.topbar .meta a { color:#3b6fd6; text-decoration:none; }
.topbar .meta b { color:#3a4453; }
.refbtn { display:inline-flex; align-items:center; gap:6px; border:1px solid #cfd6e0; background:#fff; color:#2c3644; font-size:12.5px; font-weight:600; padding:5px 12px; border-radius:8px; cursor:pointer; }
.refbtn:hover { background:#f2f5f9; border-color:#b9c2d0; }
.stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
.stat { background:#fff; border:1px solid #e5e8ee; border-radius:10px; padding:9px 13px; min-width:88px; }
.stat .n { font-size:21px; font-weight:700; line-height:1; }
.stat .l { font-size:11px; color:#6b7686; margin-top:4px; }
.stat.warn .n { color:#d9480f; } .stat.today .n { color:#e8590c; }
.ov { background:#fff; border:1px solid #e5e8ee; border-radius:12px; padding:12px 14px; }
.ovrow { display:flex; align-items:center; gap:10px; margin:5px 0; cursor:pointer; border-radius:6px; padding:2px 4px; }
.ovrow:hover { background:#f2f4f8; } .ovrow.sel { background:#eaf1fd; }
.ovname { width:96px; flex:0 0 96px; font-size:12.5px; font-weight:600; text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ovname .role { font-weight:400; color:#98a1b0; font-size:10px; margin-left:3px; }
.ovtrack { flex:1; height:20px; background:#f1f3f5; border-radius:5px; overflow:hidden; }
.ovbar { height:100%; display:flex; border-radius:5px; overflow:hidden; }
.seg { height:100%; display:flex; align-items:center; justify-content:center; font-size:10px; color:#fff; font-weight:700; min-width:0; }
.seg.overdue{background:var(--overdue)} .seg.today{background:var(--today)} .seg.soon{background:var(--soon)} .seg.later{background:var(--later)} .seg.none{background:var(--none);color:#5c6470}
.ovtot { width:30px; flex:0 0 30px; font-size:13px; font-weight:700; }
.legend { font-size:11px; color:#6b7686; margin-top:10px; display:flex; gap:14px; flex-wrap:wrap; }
.legend span { display:inline-flex; align-items:center; gap:5px; }
.dot { width:10px; height:10px; border-radius:3px; display:inline-block; }
table { border-collapse:collapse; width:100%; background:#fff; border:1px solid #e5e8ee; border-radius:12px; overflow:hidden; font-size:12.5px; }
th,td { padding:7px 10px; text-align:center; border-bottom:1px solid #eef0f4; }
th { background:#f0f2f6; cursor:pointer; user-select:none; white-space:nowrap; font-size:12px; color:#3a4453; }
th:hover { background:#e6e9f0; }
th.arrow::after { content:" \25B4"; opacity:.5; } th.arrow.desc::after { content:" \25BE"; }
td.name { text-align:left; font-weight:600; }
td.name .role { font-weight:400; color:#98a1b0; font-size:10px; margin-left:4px; }
tbody tr { cursor:pointer; } tbody tr:hover { background:#f7f9fc; } tbody tr.sel { background:#eaf1fd; }
.pill { display:inline-block; min-width:20px; padding:1px 7px; border-radius:20px; font-weight:700; font-size:11.5px; }
.pill.z { color:#c1c7d0; font-weight:400; }
.tot { font-weight:800; } .tot.hi { color:#d9480f; }
.ctrl { display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin:6px 0 4px; font-size:12.5px; }
.seg-btns { display:inline-flex; border:1px solid #d3d9e2; border-radius:8px; overflow:hidden; }
.seg-btns button { border:0; background:#fff; padding:5px 11px; font-size:12.5px; cursor:pointer; color:#3a4453; }
.seg-btns button.on { background:#3b6fd6; color:#fff; }
.chk { display:inline-flex; align-items:center; gap:5px; color:#3a4453; cursor:pointer; }
.focus-note { font-size:12px; color:#1971c2; }
.focus-note a { color:#c92a2a; cursor:pointer; text-decoration:none; margin-left:6px; }
.board { display:flex; gap:12px; overflow-x:auto; padding-bottom:12px; align-items:flex-start; }
.col { background:#eef0f4; border-radius:12px; padding:10px; min-width:238px; max-width:238px; flex:0 0 auto; }
.col.unassigned { background:#fdecec; } .col.overload { background:#fff4e6; }
.col-head { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:8px; padding:0 2px; }
.col-head .name { font-weight:700; font-size:13.5px; }
.col-head .role { font-size:10px; color:#7a8494; margin-left:5px; }
.col-head .cnt { background:#d4d9e2; color:#333; border-radius:20px; font-size:12px; font-weight:700; padding:1px 9px; }
.col.overload .cnt { background:#ffa94d; color:#5c2e00; }
.col.unassigned .cnt { background:#ffa8a8; color:#6a1a1a; }
.flag { font-size:10px; color:#d9480f; font-weight:600; margin-bottom:6px; padding:0 2px; }
.card { background:#fff; border:1px solid #e5e8ee; border-radius:8px; padding:8px 9px; margin-bottom:7px; text-decoration:none; display:block; color:inherit; }
.card:hover { border-color:#b9c2d0; }
.card .t { font-size:12.5px; line-height:1.35; margin-bottom:5px; }
.dates { display:flex; align-items:center; gap:6px; font-size:10.5px; color:#5c6470; margin-bottom:6px; background:#f5f7fa; border-radius:5px; padding:2px 6px; }
.dates .cal { opacity:.6; } .dates .d1 { font-weight:600; } .dates .arw { color:#adb5bd; } .dates .d2 { font-weight:700; }
.dates .d2.overdue{color:var(--overdue)} .dates .d2.today{color:var(--today)} .dates .d2.soon{color:#a67a00} .dates .d2.later{color:#2a6cb8} .dates .d2.none{color:#adb5bd}
.dur { margin-left:auto; color:#98a1b0; font-size:10px; }
.card .m { display:flex; align-items:center; justify-content:space-between; gap:6px; }
.iid { font-size:11px; color:#8a93a2; font-weight:600; }
.who { font-size:10.5px; color:#495566; background:#eef1f5; border-radius:4px; padding:0 5px; }
.badge { font-size:10px; font-weight:700; padding:1px 7px; border-radius:20px; white-space:nowrap; margin-left:auto; }
.badge.overdue{background:#ffe3e3;color:var(--overdue)} .badge.today{background:#ffe8cc;color:var(--today)} .badge.soon{background:#fff3bf;color:#a67a00} .badge.later{background:#e7f0fb;color:#2a6cb8} .badge.none{display:none}
.tag { font-size:9.5px; padding:0 5px; border-radius:4px; background:#edf0f4; color:#6b7686; margin-left:4px; }
.tag.qa{background:#e6fcf5;color:#0c8599} .tag.dev{background:#e7f5ff;color:#1971c2} .tag.fe{background:#f3e8ff;color:#8035c6}
.empty { color:#adb5bd; font-size:12px; padding:10px; }
h2.sec-toggle { cursor:pointer; user-select:none; display:flex; align-items:center; gap:6px; }
h2.sec-toggle .caret { font-size:11px; color:#8a93a2; transition:transform .15s; }
h2.sec-toggle.collapsed .caret { transform:rotate(-90deg); }
.sec.collapsed { display:none; }
.two-col { display:flex; flex-wrap:wrap; gap:6px 20px; align-items:flex-start; }
.two-col .pane { flex:1 1 460px; min-width:0; }
.view-panel.hidden { display:none; }
.cal-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 10px; }
.cal-toolbar .spacer { flex:1; }
.cal-toolbar select { border:1px solid #cfd6e0; background:#fff; color:#2c3644; border-radius:7px; padding:5px 8px; font-size:12px; }
.cal-title { min-width:128px; text-align:center; font-size:15px; font-weight:700; }
.cal-shell { background:#fff; border:1px solid #dfe3e9; border-radius:12px; overflow:hidden; }
.cal-weekdays { display:grid; grid-template-columns:repeat(7,minmax(105px,1fr)); background:#f0f2f6; border-bottom:1px solid #dfe3e9; }
.cal-weekdays div { padding:7px 8px; text-align:center; font-size:11.5px; font-weight:700; color:#596273; border-right:1px solid #e2e5ea; }
.cal-weekdays div:last-child { border-right:0; }
.cal-weekdays .sat { color:#2463a7; background:#edf5ff; }
.cal-weekdays .sun { color:#b4233b; background:#fff0f2; }
.cal-scroll { overflow-x:auto; }
.cal-body { min-width:735px; }
.cal-week { position:relative; border-bottom:1px solid #e2e5ea; }
.cal-week:last-child { border-bottom:0; }
.cal-days { display:grid; grid-template-columns:repeat(7,minmax(105px,1fr)); }
.cal-day { min-height:86px; padding:6px 7px; border-right:1px solid #e2e5ea; background:#fff; }
.cal-day:last-child { border-right:0; }
.cal-day.out { background:#f6f7f9; color:#aab1bc; }
.cal-day.sat { background:#edf5ff; }
.cal-day.sun { background:#fff0f2; }
.cal-day.holiday { background:#fff1cc; color:#a33b16; }
.cal-day.today { box-shadow:inset 0 0 0 2px #ffd43b; }
.cal-day-num { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; font-size:11.5px; font-weight:700; border-radius:12px; }
.cal-day.today .cal-day-num { background:#3b6fd6; color:#fff; }
.cal-holiday-name { display:inline-block; max-width:calc(100% - 28px); margin-left:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:9px; font-weight:700; color:#b54720; vertical-align:middle; }
.cal-events { position:absolute; z-index:2; top:33px; left:2px; right:2px; display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); grid-auto-rows:20px; row-gap:3px; pointer-events:none; }
.cal-event { pointer-events:auto; min-width:0; margin:0 2px; padding:2px 5px; border-radius:4px; color:#fff; background:#4c8dd6; font-size:10.5px; font-weight:600; line-height:16px; text-decoration:none; white-space:nowrap; overflow:hidden; box-shadow:0 1px 1px #0002; display:flex; align-items:center; gap:4px; }
.cal-event.overdue { box-shadow:inset 0 -3px 0 #7b0000,0 1px 1px #0002; }
.cal-event.today { box-shadow:inset 0 -3px 0 #8f3600,0 1px 1px #0002; }
.cal-event.soon { box-shadow:inset 0 -3px 0 #ffe066,0 1px 1px #0002; }
.cal-event.later { box-shadow:inset 0 -3px 0 #b9d8ff,0 1px 1px #0002; }
.cal-event.none { box-shadow:inset 0 -3px 0 #d9dde3,0 1px 1px #0002; }
.cal-event.cont-left { border-radius:0 4px 4px 0; }
.cal-event.cont-right { border-radius:4px 0 0 4px; }
.cal-event.cont-left.cont-right { border-radius:0; }
.cal-event-title { flex:1 1 auto; min-width:18px; overflow:hidden; text-overflow:ellipsis; }
.cal-people { flex:0 0 auto; display:flex; align-items:center; gap:3px; min-width:0; }
.cal-avatars { display:flex; padding-left:4px; }
.cal-avatar { position:relative; width:15px; height:15px; margin-left:-4px; border:1px solid #fff; border-radius:50%; background:#465466; color:#fff; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; font-size:8px; font-weight:800; line-height:1; }
.cal-avatar img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.cal-who { max-width:74px; overflow:hidden; text-overflow:ellipsis; font-size:9.5px; }
.cal-unassigned { flex:0 0 auto; font-size:9.5px; padding:0 4px; border-radius:8px; background:#fff3; }
.cal-person-legend { display:flex; align-items:center; flex-wrap:wrap; gap:5px 12px; margin:0 1px 9px; color:#667181; font-size:10.5px; }
.cal-person-legend span { display:inline-flex; align-items:center; gap:4px; }
.cal-person-dot { width:10px; height:10px; border-radius:3px; }
.cal-day-legend { display:flex; flex-wrap:wrap; gap:6px 13px; margin:-3px 1px 9px; color:#667181; font-size:10.5px; }
.cal-day-legend span { display:inline-flex; align-items:center; gap:4px; }
.cal-day-swatch { width:12px; height:12px; border:1px solid #d9dde3; border-radius:3px; }
.cal-day-swatch.sat { background:#edf5ff; }
.cal-day-swatch.sun { background:#fff0f2; }
.cal-day-swatch.holiday { background:#fff1cc; }
.unscheduled { margin-top:12px; background:#fff; border:1px solid #e5e8ee; border-radius:12px; padding:11px 13px; }
.unscheduled h3 { margin:0 0 8px; font-size:13px; }
.unscheduled-list { display:flex; flex-wrap:wrap; gap:6px; }
.unscheduled-item { max-width:330px; color:#3a4453; background:#eef1f5; border-radius:5px; padding:4px 8px; font-size:11px; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-empty { min-height:120px; display:flex; align-items:center; justify-content:center; color:#adb5bd; font-size:12px; }
</style>
</head>
<body>
<h1>ticket_tasks · 成員任務安排看板</h1>
<div class="topbar">
  <button class="refbtn" onclick="location.reload()"><span>🔄</span>重新載入</button>
  <div class="meta">最後更新 <b>__LASTUPDATED__</b> · 資料日 __ASOF__ · <a href="__ORIGIN__/__PROJECT__/-/issues" target="_blank">在 GitLab 開啟</a></div>
</div>
<div class="stats" id="stats"></div>
<div class="two-col">
<section class="pane">
<h2 class="sec-toggle" data-target="ovSec">負載總覽 <span class="caret">▾</span></h2>
<div class="sec" id="ovSec">
<div class="ov">
  <div id="ovbars"></div>
  <div class="legend">
    <span><i class="dot" style="background:var(--overdue)"></i>逾期</span>
    <span><i class="dot" style="background:var(--today)"></i>今日到期</span>
    <span><i class="dot" style="background:var(--soon)"></i>__SOON_DAYS__ 日內</span>
    <span><i class="dot" style="background:var(--later)"></i>其後</span>
    <span><i class="dot" style="background:var(--none)"></i>無到期</span>
    <span style="color:#98a1b0">· 點成員可聚焦</span>
  </div>
</div>
</div>
</section>
<section class="pane">
<h2 class="sec-toggle" data-target="tblSec">成員摘要（點欄位可排序、點列可聚焦）<span class="caret">▾</span></h2>
<div class="sec" id="tblSec">
<table id="tbl"><thead></thead><tbody></tbody></table>
</div>
</section>
</div>
<h2>任務明細</h2>
<div class="ctrl">
  <div class="seg-btns" id="viewBtns">
    <button data-v="board" class="on">看板</button>
    <button data-v="calendar">日曆</button>
  </div>
  <div class="seg-btns" id="groupBtns">
    <button data-g="member" class="on">依成員</button>
    <button data-g="due">依到期</button>
    <button data-g="project">依專案</button>
  </div>
  <label class="chk"><input type="checkbox" id="fUrgent">只看今日＋逾期</label>
  <label class="chk"><input type="checkbox" id="fNoDate">隱藏無到期</label>
  <span class="focus-note" id="focusNote"></span>
</div>
<div class="view-panel" id="boardPanel"><div class="board" id="board"></div></div>
<div class="view-panel hidden" id="calendarPanel">
  <div class="cal-toolbar">
    <button class="refbtn" id="calPrev" type="button">‹</button>
    <button class="refbtn" id="calToday" type="button">今天</button>
    <button class="refbtn" id="calNext" type="button">›</button>
    <span class="cal-title" id="calTitle"></span>
    <span class="spacer"></span>
    <select id="calMember" aria-label="依成員篩選"></select>
    <select id="calProject" aria-label="依專案篩選"></select>
    <select id="calDue" aria-label="依期限狀態篩選"></select>
    <div class="seg-btns" id="calModeBtns">
      <button data-m="month" class="on">月</button>
      <button data-m="week">週</button>
    </div>
  </div>
  <div class="cal-person-legend" id="calPersonLegend"></div>
  <div class="cal-day-legend"><span><i class="cal-day-swatch sat"></i>週六</span><span><i class="cal-day-swatch sun"></i>週日</span><span><i class="cal-day-swatch holiday"></i>國定假日／補假（官方資料 2025–2027）</span></div>
  <div class="cal-scroll"><div class="cal-shell cal-body">
    <div class="cal-weekdays"><div>週一</div><div>週二</div><div>週三</div><div>週四</div><div>週五</div><div class="sat">週六</div><div class="sun">週日</div></div>
    <div id="calendar"></div>
  </div></div>
  <div class="unscheduled" id="unscheduled"></div>
</div>
<script>
const ASOF = "__ASOF__";
const BASE = "__ORIGIN__/__PROJECT__/-/work_items/";
const RAW = __RAW_JSON__;
const HOLIDAYS = __HOLIDAYS__;
const ROLE = {"Benwu":"QA","zoe":"QA","ryan":"前端","rb":"開發","neil":"開發","Tim":"開發","Max":"開發","Rhys":"開發","daniel":"開發","hongze":"開發"};
const SOON_DAYS = __SOON_DAYS__, OVERLOAD_TASKS = __OVERLOAD_TASKS__;
const BUCKETS = ["overdue","today","soon","later","none"];
const BLABEL = {overdue:"逾期",today:"今日",soon:SOON_DAYS+"日內",later:"其後",none:"無期限"};
function bucket(due){ if(!due) return "none"; if(due<ASOF) return "overdue"; if(due===ASOF) return "today";
  return ((new Date(due)-new Date(ASOF))/86400000)<=SOON_DAYS ? "soon":"later"; }
function badgeLabel(b){ if(b==="none")return""; if(b==="overdue")return"逾期"; if(b==="today")return"今日到期"; return"即將到期"; }
function md(d){ return d? (parseInt(d.slice(5,7))+"/"+d.slice(8,10)) : "—"; }
function days(a,b){ if(!a||!b) return null; return Math.round((new Date(b)-new Date(a))/86400000)+1; }
function roleTag(t){ if(/測試$/.test(t))return["qa","QA"]; if(/前端$/.test(t))return["fe","前端"]; if(/開發/.test(t))return["dev","開發"]; return["","其他"]; }
function projOf(t){ const m=t.match(/^\[([^\]]+)\]/); return m?m[1]:"一般"; }
function esc(v){return String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));}
const ITEMS = RAW.map(([iid,as,s,due,title])=>({iid,people:as||[],s,due,title,b:bucket(due),proj:projOf(title)}));
let group="member", fUrgent=false, fNoDate=false, focus=null, sortKey="total", sortDesc=true;
let view="board", calMode="month", calCursor=parseDay(ASOF), calProject="", calDue="";
const UNASSIGNED="x:", members=Object.create(null), memberNames=Object.create(null);
function memberKey(p){return "u:"+(p.username||p.name);}
for(const it of ITEMS){
  if(!it.people.length){(members[UNASSIGNED]=members[UNASSIGNED]||[]).push(it);continue;}
  for(const p of it.people){const k=memberKey(p);memberNames[k]=p.name||p.username;(members[k]=members[k]||[]).push(it);}
}
const order=Object.keys(members).filter(k=>k!==UNASSIGNED).sort((a,b)=>members[b].length-members[a].length||a.localeCompare(b));
if(members[UNASSIGNED]) order.push(UNASSIGNED);
const maxTot=Math.max(1,...order.map(k=>members[k].length));
function dispName(k){ return k===UNASSIGNED?"未分配":(memberNames[k]||k.slice(2)); }
function counts(list){ const c={overdue:0,today:0,soon:0,later:0,none:0}; list.forEach(i=>c[i.b]++); return c; }
function renderStats(){
  const total=ITEMS.length, un=(members[UNASSIGNED]||[]).length;
  const today=ITEMS.filter(i=>i.b==="today").length, overdue=ITEMS.filter(i=>i.b==="overdue").length;
  document.getElementById("stats").innerHTML=[
    `<div class="stat"><div class="n">${total}</div><div class="l">開啟中任務</div></div>`,
    `<div class="stat"><div class="n">${order.length-(un?1:0)}</div><div class="l">參與成員</div></div>`,
    `<div class="stat today"><div class="n">${today}</div><div class="l">今日到期</div></div>`,
    overdue?`<div class="stat warn"><div class="n">${overdue}</div><div class="l">已逾期</div></div>`:``,
    `<div class="stat warn"><div class="n">${un}</div><div class="l">未分配</div></div>`].join("");
}
function renderOverview(){
  const el=document.getElementById("ovbars"); el.innerHTML="";
  for(const k of order){ const list=members[k], c=counts(list);
    const row=document.createElement("div"); row.className="ovrow"+(focus===k?" sel":"");
    const role=k===UNASSIGNED?"":`<span class="role">${esc(ROLE[k.slice(2)]||"")}</span>`;
    let segs=""; for(const b of BUCKETS){ if(c[b]>0) segs+=`<div class="seg ${b}" style="flex:${c[b]}" title="${BLABEL[b]} ${c[b]}">${c[b]}</div>`; }
    row.innerHTML=`<div class="ovname">${esc(dispName(k))}${role}</div><div class="ovtrack"><div class="ovbar" style="width:${list.length/maxTot*100}%">${segs}</div></div><div class="ovtot">${list.length}</div>`;
    row.onclick=()=>setFocus(focus===k?null:k); el.appendChild(row); }
}
const COLS=[["name","成員",false],["total","總數",true],["overdue","逾期",true],["today","今日",true],["soon",SOON_DAYS+"日內",true],["later","其後",true],["none","無期限",true]];
function renderTable(){
  const thead=document.querySelector("#tbl thead"), tb=document.querySelector("#tbl tbody");
  thead.innerHTML="<tr>"+COLS.map(([k,l,s])=>{const cls=(s?"arrow":"")+(k===sortKey&&sortDesc?" desc":""); return `<th data-k="${k}" class="${cls}">${l}</th>`;}).join("")+"</tr>";
  thead.querySelectorAll("th").forEach(th=>th.onclick=()=>{const k=th.dataset.k; if(k===sortKey)sortDesc=!sortDesc; else{sortKey=k;sortDesc=(k!=="name");} renderTable();});
  const rows=order.map(k=>({k,c:counts(members[k]),total:members[k].length}));
  rows.sort((a,b)=>{let v; if(sortKey==="name")v=dispName(a.k).localeCompare(dispName(b.k)); else if(sortKey==="total")v=a.total-b.total; else v=a.c[sortKey]-b.c[sortKey]; return sortDesc?-v:v;});
  tb.innerHTML=rows.map(r=>{
    const role=r.k===UNASSIGNED?"":`<span class="role">${esc(ROLE[r.k.slice(2)]||"")}</span>`;
    const cell=(b)=>{const v=r.c[b];return `<td><span class="pill ${v?b:'z'}" style="${v?('color:var(--'+b+')'):''}">${v||'·'}</span></td>`;};
    const hi=r.total>=OVERLOAD_TASKS?" hi":"";
    return `<tr data-k="${esc(r.k)}" class="${focus===r.k?'sel':''}"><td class="name">${esc(dispName(r.k))}${role}</td><td class="tot${hi}">${r.total}</td>`+cell("overdue")+cell("today")+cell("soon")+cell("later")+cell("none")+`</tr>`;}).join("");
  tb.querySelectorAll("tr").forEach(tr=>tr.onclick=()=>setFocus(focus===tr.dataset.k?null:tr.dataset.k));
}
function passFilter(it){ if(fUrgent&&!(it.b==="today"||it.b==="overdue"))return false; if(fNoDate&&it.b==="none")return false;
  if(focus!==null){const keys=it.people.length?it.people.map(memberKey):[UNASSIGNED]; if(!keys.includes(focus))return false;} return true; }
function cardHTML(it,showWho){
  const b=it.b,[rc,rl]=roleTag(it.title);
  const names=it.people.length?it.people.map(p=>p.name||p.username).join(" + "):"未分配";
  const who=showWho?`<span class="who">${esc(names)}</span>`:"";
  const t=it.title.replace(/-(測試|開發|前端)$/,""); const dur=days(it.s,it.due); const durTxt=dur?`<span class="dur">${dur}天</span>`:"";
  const bl=badgeLabel(b); const badge=bl?`<span class="badge ${b}">${bl}</span>`:"";
  return `<a class="card" href="${BASE+it.iid}" target="_blank"><div class="t">${esc(t)}<span class="tag ${rc}">${rl}</span></div><div class="dates"><span class="cal">🗓</span><span class="d1">${md(it.s)}</span><span class="arw">→</span><span class="d2 ${b}">${md(it.due)}</span>${durTxt}</div><div class="m"><span class="iid">#${it.iid}</span>${who}${badge}</div></a>`;
}
function renderBoard(){
  const board=document.getElementById("board"); board.innerHTML="";
  const items=ITEMS.filter(passFilter); let cols=[];
  if(group==="member"){ for(const k of order){ if(focus!==null&&k!==focus)continue;
    const list=members[k].filter(passFilter);
    cols.push({label:dispName(k),role:k===UNASSIGNED?"":ROLE[k.slice(2)],items:list,kind:k===UNASSIGNED?"unassigned":(members[k].length>=OVERLOAD_TASKS?"overload":"")}); } }
  else if(group==="due"){ for(const b of BUCKETS) cols.push({label:BLABEL[b],role:"",items:items.filter(i=>i.b===b),kind:(b==="overdue"||b==="today")?"unassigned":""}); }
  else { const projs=Object.create(null); items.forEach(i=>{(projs[i.proj]=projs[i.proj]||[]).push(i);});
    Object.keys(projs).sort((a,b)=>projs[b].length-projs[a].length||a.localeCompare(b)).forEach(p=>cols.push({label:p,role:"",items:projs[p],kind:""})); }
  const showWho=group!=="member";
  for(const col of cols){ const d=document.createElement("div"); d.className="col"+(col.kind?(" "+col.kind):"");
    const role=col.role?`<span class="role">${esc(col.role)}</span>`:"";
    d.innerHTML=`<div class="col-head"><div><span class="name">${esc(col.label)}</span>${role}</div><span class="cnt">${col.items.length}</span></div>`+(col.kind==="overload"?`<div class="flag">⚠ 負載偏高</div>`:``)+(col.kind==="unassigned"&&group==="member"?`<div class="flag">⚠ 尚未指派</div>`:``);
    const body=col.items.length? col.items.slice().sort((a,b)=>(a.due||"9999").localeCompare(b.due||"9999")||(a.s||"9999").localeCompare(b.s||"9999")).map(it=>cardHTML(it,showWho)).join("") : `<div class="empty">—</div>`;
    d.insertAdjacentHTML("beforeend",body); board.appendChild(d); }
}
function parseDay(s){const p=s.split("-").map(Number);return new Date(p[0],p[1]-1,p[2],12);}
function dateKey(d){return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");}
function addDay(d,n){const x=new Date(d);x.setDate(x.getDate()+n);return x;}
function monday(d){const x=new Date(d),w=(x.getDay()+6)%7;return addDay(x,-w);}
function calendarItems(){return ITEMS.filter(passFilter).filter(it=>(!calProject||it.proj===calProject)&&(!calDue||it.b===calDue));}
function initCalendarFilters(){
  const member=document.getElementById("calMember");
  member.innerHTML=`<option value="">全部成員</option>`+order.map(k=>`<option value="${esc(k)}">${esc(dispName(k))}</option>`).join("");
  member.onchange=()=>setFocus(member.value||null);
  const projects=[...new Set(ITEMS.map(i=>i.proj))].sort((a,b)=>a.localeCompare(b));
  document.getElementById("calProject").innerHTML=`<option value="">全部專案</option>`+projects.map(p=>`<option value="${esc(p)}">${esc(p)}</option>`).join("");
  document.getElementById("calDue").innerHTML=`<option value="">全部期限</option>`+BUCKETS.map(b=>`<option value="${b}">${BLABEL[b]}</option>`).join("");
  document.getElementById("calProject").onchange=e=>{calProject=e.target.value;renderCalendar();};
  document.getElementById("calDue").onchange=e=>{calDue=e.target.value;renderCalendar();};
}
function eventRange(it){
  if(!it.s&&!it.due)return null;
  let a=parseDay(it.s||it.due),b=parseDay(it.due||it.s);
  if(a>b)[a,b]=[b,a];
  return [a,b];
}
function safeAvatar(p){
  if(!p.avatar)return "";
  try{const base=new URL(BASE),u=new URL(p.avatar,base.origin+"/");return u.protocol==="http:"||u.protocol==="https:"?u.href:"";}catch(e){return "";}
}
function calendarPeople(people){
  if(!people.length)return `<span class="cal-unassigned">未分配</span>`;
  const names=people.map(p=>p.name||p.username),shown=people.slice(0,3);
  const avatars=shown.map(p=>{const name=p.name||p.username||"?",src=safeAvatar(p),initial=esc(Array.from(name)[0]||"?");return `<span class="cal-avatar" title="${esc(name)}"><span>${initial}</span>${src?`<img src="${esc(src)}" alt="" onerror="this.remove()">`:""}</span>`;}).join("");
  const more=people.length>1?` +${people.length-1}`:"";
  return `<span class="cal-people" title="${esc(names.join(" + "))}"><span class="cal-avatars">${avatars}</span><span class="cal-who">${esc(names[0])}${more}</span></span>`;
}
function memberColor(p){
  if(!p)return "#7c8796";
  const key=p.username||p.name||"?";let h=2166136261;
  for(let i=0;i<key.length;i++){h^=key.charCodeAt(i);h=Math.imul(h,16777619);}
  return `hsl(${(h>>>0)%360} 58% 38%)`;
}
function eventBackground(people){
  const unique=[];for(const p of people){if(!unique.some(x=>memberKey(x)===memberKey(p)))unique.push(p);}
  if(!unique.length)return "#7c8796";
  if(unique.length===1)return memberColor(unique[0]);
  const n=unique.length;
  return `linear-gradient(90deg,${unique.map((p,i)=>`${memberColor(p)} ${i/n*100}% ${((i+1)/n)*100}%`).join(",")})`;
}
function renderPersonLegend(items){
  const people=Object.create(null);let unassigned=false;
  for(const it of items){if(!it.people.length)unassigned=true;for(const p of it.people)people[memberKey(p)]=p;}
  const entries=Object.keys(people).sort((a,b)=>(people[a].name||a).localeCompare(people[b].name||b)).map(k=>`<span><i class="cal-person-dot" style="background:${memberColor(people[k])}"></i>${esc(people[k].name||people[k].username)}</span>`);
  if(unassigned)entries.push(`<span><i class="cal-person-dot" style="background:#7c8796"></i>未分配</span>`);
  document.getElementById("calPersonLegend").innerHTML=entries.join("")+`<span>· 任務條底線表示期限狀態</span>`;
}
function weekSegments(items,ws,we){
  const segs=[];
  for(const it of items){const range=eventRange(it);if(!range)continue;const [a,b]=range;if(b<ws||a>we)continue;
    const from=a<ws?ws:a,to=b>we?we:b;
    segs.push({it,start:Math.round((from-ws)/86400000),end:Math.round((to-ws)/86400000),left:a<ws,right:b>we});
  }
  segs.sort((a,b)=>a.start-b.start||b.end-a.end||a.it.iid-b.it.iid);
  const occupied=[];
  for(const s of segs){let row=0;while(occupied[row]!=null&&occupied[row]>=s.start)row++;occupied[row]=s.end;s.row=row;}
  return {segs,rows:occupied.length};
}
function renderCalendar(){
  const el=document.getElementById("calendar"),items=calendarItems();
  renderPersonLegend(items);
  let start,end;
  if(calMode==="week"){start=monday(calCursor);end=addDay(start,6);document.getElementById("calTitle").textContent=`${start.getFullYear()} / ${start.getMonth()+1} / ${start.getDate()}－${end.getMonth()+1} / ${end.getDate()}`;}
  else {const first=new Date(calCursor.getFullYear(),calCursor.getMonth(),1,12),last=new Date(calCursor.getFullYear(),calCursor.getMonth()+1,0,12);start=monday(first);end=addDay(monday(last),6);document.getElementById("calTitle").textContent=`${first.getFullYear()} 年 ${first.getMonth()+1} 月`;}
  let html="";
  for(let ws=new Date(start);ws<=end;ws=addDay(ws,7)){
    const we=addDay(ws,6),pack=weekSegments(items,ws,we),height=Math.max(86,39+pack.rows*23);
    let cells="";for(let i=0;i<7;i++){const d=addDay(ws,i),key=dateKey(d),holiday=HOLIDAYS[key]||"";const out=calMode==="month"&&d.getMonth()!==calCursor.getMonth();const dayClass=i===5?" sat":i===6?" sun":"";cells+=`<div class="cal-day${dayClass}${out?' out':''}${holiday?' holiday':''}${key===ASOF?' today':''}" style="min-height:${height}px"${holiday?` title="${esc(holiday)}"`:""}><span class="cal-day-num">${d.getDate()}</span>${holiday?`<span class="cal-holiday-name">${esc(holiday)}</span>`:""}</div>`;}
    const events=pack.segs.map(s=>{const names=s.it.people.length?s.it.people.map(p=>p.name||p.username).join(" + "):"未分配";const cls=`cal-event ${s.it.b}${s.left?' cont-left':''}${s.right?' cont-right':''}`;return `<a class="${cls}" style="grid-column:${s.start+1}/${s.end+2};grid-row:${s.row+1};background:${eventBackground(s.it.people)};color:#fff" href="${BASE+s.it.iid}" target="_blank" title="#${s.it.iid} ${esc(s.it.title)} · ${esc(names)}">${calendarPeople(s.it.people)}<span class="cal-event-title">#${s.it.iid} ${esc(s.it.title)}</span></a>`;}).join("");
    html+=`<div class="cal-week"><div class="cal-days">${cells}</div><div class="cal-events">${events}</div></div>`;
  }
  el.innerHTML=html||`<div class="cal-empty">這個期間沒有已排程任務</div>`;
  const loose=items.filter(it=>!it.s&&!it.due),box=document.getElementById("unscheduled");
  box.style.display=fNoDate?"none":"block";
  box.innerHTML=`<h3>未排程 <span class="pill">${loose.length}</span></h3><div class="unscheduled-list">${loose.length?loose.map(it=>`<a class="unscheduled-item" href="${BASE+it.iid}" target="_blank" title="${esc(it.title)}">#${it.iid} ${esc(it.title)}</a>`).join(""):`<span class="empty">—</span>`}</div>`;
}
function renderActiveView(){if(view==="board")renderBoard();else renderCalendar();}
function setFocus(k){ focus=k; document.getElementById("calMember").value=k||""; renderFocusNote(); renderOverview(); renderTable(); renderActiveView(); }
function renderFocusNote(){ const el=document.getElementById("focusNote"); el.innerHTML=focus?`聚焦：<b>${esc(dispName(focus))}</b><a onclick="setFocus(null)">✕ 清除</a>`:""; }
document.querySelectorAll("#viewBtns button").forEach(b=>b.onclick=()=>{view=b.dataset.v;document.querySelectorAll("#viewBtns button").forEach(x=>x.classList.toggle("on",x===b));document.getElementById("boardPanel").classList.toggle("hidden",view!=="board");document.getElementById("calendarPanel").classList.toggle("hidden",view!=="calendar");document.getElementById("groupBtns").style.display=view==="board"?"inline-flex":"none";renderActiveView();});
document.querySelectorAll("#groupBtns button").forEach(b=>b.onclick=()=>{group=b.dataset.g; document.querySelectorAll("#groupBtns button").forEach(x=>x.classList.toggle("on",x===b)); renderBoard();});
document.querySelectorAll("#calModeBtns button").forEach(b=>b.onclick=()=>{calMode=b.dataset.m;document.querySelectorAll("#calModeBtns button").forEach(x=>x.classList.toggle("on",x===b));renderCalendar();});
document.getElementById("calPrev").onclick=()=>{calCursor=calMode==="month"?new Date(calCursor.getFullYear(),calCursor.getMonth()-1,1,12):addDay(calCursor,-7);renderCalendar();};
document.getElementById("calNext").onclick=()=>{calCursor=calMode==="month"?new Date(calCursor.getFullYear(),calCursor.getMonth()+1,1,12):addDay(calCursor,7);renderCalendar();};
document.getElementById("calToday").onclick=()=>{calCursor=parseDay(ASOF);renderCalendar();};
document.getElementById("fUrgent").onchange=e=>{fUrgent=e.target.checked; renderActiveView();};
document.getElementById("fNoDate").onchange=e=>{fNoDate=e.target.checked; renderActiveView();};
initCalendarFilters(); renderStats(); renderOverview(); renderTable(); renderBoard();
document.querySelectorAll('h2.sec-toggle').forEach(h=>{
  const t=document.getElementById(h.dataset.target);
  h.onclick=()=>{ const c=t.classList.toggle('collapsed'); h.classList.toggle('collapsed',c); try{ localStorage.setItem('sec_'+h.dataset.target, c?'1':'0'); }catch(e){} };
});
function setColl(target, collapsed){ const t=document.getElementById(target); const hh=document.querySelector('h2.sec-toggle[data-target="'+target+'"]'); if(!t||!hh) return; t.classList.toggle('collapsed', collapsed); hh.classList.toggle('collapsed', collapsed); }
(function(){ let s=null; try{ s=localStorage.getItem('sec_tblSec'); }catch(e){} if(s==='1') setColl('tblSec', true); })();
function autoOv(){
  let s=null; try{ s=localStorage.getItem('sec_ovSec'); }catch(e){}
  if(s!==null){ setColl('ovSec', s==='1'); return; }
  const panes=document.querySelectorAll('.two-col .pane');
  let side=false;
  if(panes.length>=2){ side = Math.abs(panes[0].getBoundingClientRect().top - panes[1].getBoundingClientRect().top) < 4; }
  setColl('ovSec', !side);
}
autoOv();
window.addEventListener('resize', ()=>{ clearTimeout(window.__ovT); window.__ovT=setTimeout(autoOv, 150); });
</script>
</body>
</html>"""

if __name__ == "__main__":
    main()
