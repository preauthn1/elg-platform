#!/usr/bin/env python3
"""Generate the op_tickets operations dashboard at public/op.html."""
import os
import sys
import datetime
import re

from gitlab_api import (
    GitLabGraphQLError,
    atomic_write_text,
    fetch_all_work_items,
    json_for_html,
)
SERVER = os.environ.get("CI_SERVER_URL", "http://example.invalid:1688").rstrip("/")
PROJECT = os.environ.get("OP_PROJECT", "target/op_tickets")
TOKEN = [REDACTED]"GITLAB_READ_TOKEN")
TIMEZONE = datetime.timezone(datetime.timedelta(hours=8), name="Asia/Taipei")
P1_SLA_HOURS = 24
P2_SLA_WORK_DAYS = 3
STALE_HOURS = 12
FIELDS = """
iid title createdAt updatedAt
widgets {
  ... on WorkItemWidgetAssignees { assignees { nodes { name username } } }
  ... on WorkItemWidgetLabels { labels { nodes { title } } }
}
"""


def fetch():
    if not TOKEN:
        [REDACTED]"missing GITLAB_READ_TOKEN")
    try:
        return fetch_all_work_items(SERVER, PROJECT, TOKEN, FIELDS)
    except GitLabGraphQLError as error:
        sys.exit("GraphQL 錯誤：" + str(error))


def prio(labels):
    for l in labels:
        if l.startswith(".P1"): return "P1"
        if l.startswith(".P2"): return "P2"
        if l.startswith(".P3"): return "P3"
    return ""


def platform(title):
    m = re.match(r"^\s*[\[【]([^\]】]+)[\]】]", title)
    return m.group(1).strip() if m else ""


def rows():
    out = []
    for n in fetch():
        labels = []
        for w in n.get("widgets", []):
            if "labels" in w: labels = [x["title"] for x in w["labels"]["nodes"]]
        title = n["title"]
        out.append({
            "iid": int(n["iid"]),
            "assignees": [
                {"username": a.get("username") or a["name"], "name": a["name"]}
                for w in n.get("widgets", []) if "assignees" in w
                for a in w["assignees"]["nodes"]
            ],
            "pri": prio(labels),
            "pf": platform(title),
            "cr": n.get("createdAt", ""),
            "up": n.get("updatedAt", ""),
            "t": re.sub(r"^\s*[\[【][^\]】]+[\]】]\s*", "", title).strip()[:60],
        })
    return out


def main():
    data = rows()
    now = datetime.datetime.now(TIMEZONE)
    html = (TEMPLATE
            .replace("__ROWS__", json_for_html(data))
            .replace("__NOW__", now.strftime("%Y-%m-%d %H:%M"))
            .replace("__P1_SLA_HOURS__", str(P1_SLA_HOURS))
            .replace("__P2_SLA_WORK_DAYS__", str(P2_SLA_WORK_DAYS))
            .replace("__STALE_HOURS__", str(STALE_HOURS))
            .replace("__ORIGIN__", SERVER).replace("__PROJECT__", PROJECT))
    atomic_write_text("public/op.html", html)
    print("op.html: %d tickets @ %s" % (len(data), now.strftime("%H:%M")))


TEMPLATE = r"""<!DOCTYPE html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>op_tickets 維運戰情室</title>
<style>
:root{color-scheme:light; --p1:#c92a2a; --p2:#e8590c; --p3:#868e96; --red:#e03131; --amber:#f08c00; --green:#2f9e44;}
*{box-sizing:border-box;}
body{margin:0;padding:16px;font-family:-apple-system,"PingFang TC","Microsoft JhengHei","Segoe UI",sans-serif;background:#f6f7f9;color:#1c2430;font-size:15px;zoom:1.12;}
h1{font-size:20px;margin:0 0 2px;}
.meta{font-size:13px;color:#6b7686;margin-bottom:14px;}
.meta a{color:#3b6fd6;text-decoration:none;}
.refbtn{display:inline-flex;align-items:center;gap:6px;border:1px solid #cfd6e0;background:#fff;color:#2c3644;font-size:13px;font-weight:600;padding:5px 12px;border-radius:8px;cursor:pointer;margin-right:8px;}
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-bottom:14px;}
.kpi{background:#fff;border:1px solid #e5e8ee;border-radius:10px;padding:10px 12px;}
.kpi .n{font-size:24px;font-weight:800;line-height:1;} .kpi .l{font-size:11.5px;color:#6b7686;margin-top:4px;}
.kpi.p1 .n{color:var(--p1);} .kpi.p2 .n{color:var(--p2);} .kpi.p3 .n{color:var(--p3);}
.kpi.warn{background:#fff5f5;border-color:#ffc9c9;} .kpi.warn .n{color:var(--red);} .kpi.warn .l{color:#c92a2a;}
.ctrl{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:2px 0 12px;font-size:13px;}
.chk{display:inline-flex;align-items:center;gap:5px;color:#3a4453;cursor:pointer;}
.grid{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;}
.grid>.panel{flex:1 1 360px;min-width:0;}
.panel{background:#fff;border:1px solid #e5e8ee;border-radius:12px;padding:12px 14px;}
.panel h2{font-size:14px;margin:0 0 2px;font-weight:700;}
.panel .sub{font-size:11px;color:#868e96;margin-bottom:10px;}
.row{display:flex;align-items:center;gap:8px;margin:7px 0;}
.pill{font-size:10px;font-weight:800;color:#fff;border-radius:4px;padding:1px 5px;flex:0 0 auto;}
.pill.P1{background:var(--p1);} .pill.P2{background:var(--p2);} .pill.P3{background:var(--p3);}
.rtitle{flex:1 1 auto;min-width:60px;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none;color:#1c2430;}
.rtitle:hover{text-decoration:underline;}
.track{flex:0 0 130px;height:15px;background:#f1f3f5;border-radius:8px;overflow:hidden;}
.fill{display:block;height:100%;}
.pct{flex:0 0 74px;text-align:right;font-size:11px;font-weight:800;}
.pf{font-size:10px;font-weight:700;border-radius:4px;padding:1px 6px;flex:0 0 auto;white-space:nowrap;}
.who{font-size:10.5px;color:#495566;background:#eef1f5;border-radius:4px;padding:1px 6px;flex:0 0 auto;white-space:nowrap;}
.orow{display:flex;align-items:center;gap:7px;margin:6px 0;text-decoration:none;color:#1c2430;}
.orow:hover .otitle{text-decoration:underline;}
.oage{flex:0 0 44px;font-size:12.5px;font-weight:800;text-align:right;}
.otitle{flex:1 1 auto;min-width:0;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.owho{flex:0 0 auto;font-size:10.5px;color:#495566;white-space:nowrap;}
.frow{display:flex;align-items:center;gap:6px;margin:6px 0;text-decoration:none;color:#1c2430;}
.frow:hover .ftitle{text-decoration:underline;}
.ftitle{flex:1 1 auto;min-width:0;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rtag{flex:0 0 auto;font-size:9.5px;font-weight:700;color:#a6360d;background:#ffe8e0;border-radius:4px;padding:1px 5px;}
.srow{display:flex;align-items:center;gap:8px;margin:7px 0;}
.smeta{flex:0 0 auto;font-size:10.5px;color:#6b7686;white-space:nowrap;}
.sbar{flex:1 1 auto;height:12px;background:#f1f3f5;border-radius:6px;overflow:hidden;min-width:40px;}
.sfill{display:block;height:100%;}
.sscore{flex:0 0 auto;font-size:11px;font-weight:800;white-space:nowrap;}
.intro{background:#eef4ff;border:1px solid #d0e0ff;border-radius:10px;padding:9px 13px;margin-bottom:12px;font-size:12.5px;color:#2c3a4f;}
.introlead{font-weight:600;}
.intro details{margin-top:6px;}
.intro summary{cursor:pointer;color:#3b6fd6;font-size:12px;}
.intro ul{margin:6px 0 0;padding-left:18px;line-height:1.9;}
.intro b{color:#1c2430;}
.lrow{display:flex;align-items:center;gap:8px;margin:7px 0;}
.lname{flex:0 0 92px;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lbar{flex:0 0 90px;height:12px;background:#f1f3f5;border-radius:6px;overflow:hidden;}
.lfill{display:block;height:100%;}
.lmeta{flex:1 1 auto;min-width:0;font-size:10.5px;color:#6b7686;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lrow{cursor:pointer;padding:1px 3px;border-radius:6px;}
.lrow:hover{background:#f2f4f8;}
.lcaret{flex:0 0 auto;font-size:10px;color:#8a93a2;}
.ldetail{margin:2px 0 8px 22px;}
.litem{display:flex;align-items:center;gap:6px;margin:4px 0;text-decoration:none;color:#1c2430;}
.litem:hover .litt{text-decoration:underline;}
.litt{flex:1 1 auto;min-width:0;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lits{flex:0 0 auto;font-size:11px;font-weight:800;}
.empty{color:#adb5bd;font-size:12px;padding:8px 0;}
.legend{font-size:11px;color:#6b7686;margin-top:8px;}
</style></head><body>
<h1>op_tickets · 維運戰情室</h1>
<div class="meta"><button class="refbtn" onclick="location.reload()">🔄 重新載入</button>最後更新 <b>__NOW__</b> · <a href="__ORIGIN__/__PROJECT__/-/work_items" target="_blank">在 GitLab 開啟</a></div>
<div class="intro">
<div class="introlead">🧭 這頁讓你一分鐘掌握「此刻維運全貌」· 資料每 20 分鐘自動更新，隨時打開都是當下狀態。</div>
<details><summary>這頁怎麼看？（點開各區塊說明）</summary>
<ul>
<li><b>SLA 燃燒跑道</b>：現在最緊急的是哪些 —— 快到／已過時限的工單、在哪個站、誰在扛。</li>
<li><b>需跟進</b>：有沒有工單被晾著 —— 未指派、久未更新、已超時的都會浮出來。</li>
<li><b>依處理人負載</b>：誰的擔子最重 —— 每人手上幾張、幾張告急／超時、最老幾天。</li>
<li><b>站點健康</b>：哪個站點狀況最差 —— 工單多／超時多／又老，分數就低（風險估算，非真實滿意度）。</li>
<li><b>最老未結工單</b>：有沒有陳年老帳 —— 擺最久的排最上，含沒有 SLA 的 P3。</li>
</ul>
</details>
</div>
<div class="kpis" id="kpis"></div>
<div class="ctrl"><label class="chk"><input type="checkbox" id="fHot">只看超時／告急（SLA ≥ 80%）</label></div>
<div class="grid">
<div class="panel"><h2>需跟進</h2><div class="sub">未指派／停滯沒更新（≥__STALE_HOURS__小時）／超時 —— 該推一把的</div><div id="follow"></div></div>
  <div class="panel">
    <h2>SLA 燃燒跑道</h2>
    <div class="sub">已耗時間 ÷ 該級 SLA（P1=__P1_SLA_HOURS__h、P2=__P2_SLA_WORK_DAYS__ 工作日）· 燒最兇在最上（P3 無 SLA 不列）</div>
    <div id="runway"></div>
    <div class="legend">🟢 安全 &lt;80% ・ 🟠 告急 80–100% ・ 🔴 超時</div>
  </div>
</div>
<div class="grid">
  <div class="panel">
    <h2>依處理人負載</h2>
    <div class="sub">每人手上幾張・幾張告急/超時・最老幾天 · 擔子重的排最上</div>
    <div id="load"></div>
  </div>
<div class="panel"><h2>站點健康</h2><div class="sub">依工單量、超時、老化估的風險指標（非真實滿意度）· 差的排最上</div><div id="sites"></div></div>
</div>
<div class="panel" style="margin-top:14px;"><h2>最老未結工單</h2><div class="sub">擺最久的排最上（含 P3）· 點擊開啟</div><div id="oldest"></div></div>
<script>
const ROWS=__ROWS__;
const BASE="__ORIGIN__/__PROJECT__/-/work_items/";
const P1_SLA_HOURS=__P1_SLA_HOURS__, P2_SLA_WORK_DAYS=__P2_SLA_WORK_DAYS__, STALE_HOURS=__STALE_HOURS__;
function esc(v){return String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));}
function workDays(a,b){let x=new Date(a),e=new Date(b),n=0;x.setHours(0,0,0,0);e.setHours(0,0,0,0);let g=0;while(x<e&&g<600){const w=x.getDay();if(w!==0&&w!==6)n++;x.setDate(x.getDate()+1);g++;}return n;}
const now=new Date();
ROWS.forEach(r=>{r.people=r.assignees||[];r.who=r.people.length?r.people.map(p=>p.name||p.username).join(" + "):null;const c=r.cr?new Date(r.cr):now;r.ageH=(now-c)/3600000;r.ageD=Math.floor(r.ageH/24);r.wd=workDays(c,now);
  r.pct=r.pri==="P1"?r.ageH/P1_SLA_HOURS*100:r.pri==="P2"?r.wd/P2_SLA_WORK_DAYS*100:null;
  r.breach=r.pct!=null&&r.pct>100;
  var u=r.up?new Date(r.up):c; r.staleH=(now-u)/3600000;
  r.follow=(!r.who)||r.staleH>=STALE_HOURS||r.breach;});
let fHot=false;
function kpis(){
  const P=k=>ROWS.filter(r=>r.pri===k).length;
  const breach=ROWS.filter(r=>r.breach).length, oldest=Math.max(0,...ROWS.map(r=>r.ageD));
  document.getElementById("kpis").innerHTML=[
    ['','','開啟中',ROWS.length],['p1','','P1 高優先',P('P1')],['p2','','P2 中優先',P('P2')],['p3','','P3 低優先',P('P3')],
    ['','warn','超時',breach],['','','最老未結',oldest+'天']
  ].map(([c,w,l,n])=>`<div class="kpi ${c} ${w}"><div class="n">${n}</div><div class="l">${l}</div></div>`).join("");
}
function pfc(s){let h=0;s=s||"其他";for(let i=0;i<s.length;i++)h=(h*31+s.charCodeAt(i))>>>0;const u=h%360;return {bg:"hsl("+u+" 62% 92%)",fg:"hsl("+u+" 52% 30%)"};}
function runway(){
  let list=ROWS.filter(r=>r.pct!=null);
  if(fHot) list=list.filter(r=>r.pct>=80);
  list.sort((a,b)=>b.pct-a.pct);
  const el=document.getElementById("runway");
  if(!list.length){el.innerHTML='<div class="empty">—</div>';return;}
  el.innerHTML=list.map(r=>{
    const pct=Math.round(r.pct), w=Math.min(100,pct);
    const col=pct>100?"var(--red)":pct>=80?"var(--amber)":"var(--green)";
    const tc=pct>100?"var(--red)":pct>=80?"#a67a00":"var(--green)";
    const face=pct>100?"🔴":pct>=80?"🟠":"🟢";
    const who=r.who?`<span class="who">👤 ${esc(r.who)}</span>`:`<span class="who" style="color:#c0392b;background:#fff0f0">未指派</span>`;
    return `<div class="row"><span class="pill ${r.pri}">${r.pri}</span><span class="pf" style="background:${pfc(r.pf).bg};color:${pfc(r.pf).fg}">${esc(r.pf||'其他')}</span>`+
      `<a class="rtitle" href="${BASE+r.iid}" target="_blank" title="#${r.iid} ${esc(r.t)}">#${r.iid} ${esc(r.t)}</a>`+
      `${who}<span class="track"><span class="fill" style="width:${w}%;background:${col}"></span></span>`+
      `<span class="pct" style="color:${tc}">${pct}% ${face}</span></div>`;
  }).join("");
}
document.getElementById("fHot").onchange=e=>{fHot=e.target.checked;runway();};
function oldest(){
  var list=ROWS.slice().sort(function(a,b){return b.ageD-a.ageD;}).slice(0,8);
  document.getElementById("oldest").innerHTML=list.map(function(r){
    var ac=r.ageD>30?"var(--red)":r.ageD>14?"var(--amber)":"#868e96";
    var c=pfc(r.pf);
    var pbg=r.pri?"":"background:#adb5bd";
    var who=r.who?("👤 "+r.who):"未指派", wst=r.who?"":"color:#c0392b";
    return '<a class="orow" href="'+BASE+r.iid+'" target="_blank">'+
      '<span class="oage" style="color:'+ac+'">'+r.ageD+'天</span>'+
      '<span class="pill '+(r.pri||"")+'" style="'+pbg+'">'+(r.pri||"—")+'</span>'+
      '<span class="pf" style="background:'+c.bg+';color:'+c.fg+'">'+esc(r.pf||"其他")+'</span>'+
      '<span class="otitle">#'+r.iid+' '+esc(r.t)+'</span>'+
      '<span class="owho" style="'+wst+'">'+esc(who)+'</span></a>';
  }).join("");
}
function follow(){
  var list=ROWS.filter(function(r){return r.follow;}).sort(function(a,b){return b.staleH-a.staleH;});
  var el=document.getElementById("follow");
  if(!list.length){el.innerHTML='<div class="empty">目前沒有需跟進的工單 🎉</div>';return;}
  el.innerHTML=list.map(function(r){
    var tags=[];
    if(!r.who)tags.push("未指派");
    if(r.staleH>=STALE_HOURS)tags.push("停滯"+(r.staleH<24?Math.floor(r.staleH)+"小時":Math.floor(r.staleH/24)+"天"));
    
    if(r.breach)tags.push("超時");
    var reason=tags.map(function(t){return '<span class="rtag">'+t+'</span>';}).join("");
    var c=pfc(r.pf);
    return '<a class="frow" href="'+BASE+r.iid+'" target="_blank">'+
      '<span class="pf" style="background:'+c.bg+';color:'+c.fg+'">'+esc(r.pf||"其他")+'</span>'+
      '<span class="ftitle">#'+r.iid+' '+esc(r.t)+'</span>'+reason+'</a>';
  }).join("");
}
function sites(){
  var m=Object.create(null);
  ROWS.forEach(function(r){var k=r.pf||"其他";(m[k]=m[k]||[]).push(r);});
  var rows=Object.keys(m).map(function(k){
    var g=m[k];
    var br=g.filter(function(r){return r.breach;}).length;
    var avg=Math.round(g.reduce(function(s,r){return s+r.ageD;},0)/g.length);
    var score=Math.max(0,Math.min(100,Math.round(100-br*18-g.length*4-avg*0.6)));
    return {k:k,n:g.length,br:br,avg:avg,score:score};
  }).sort(function(a,b){return a.score-b.score;});
  document.getElementById("sites").innerHTML=rows.map(function(s){
    var col=s.score>=80?"var(--green)":s.score>=60?"var(--amber)":"var(--red)";
    var grade=s.score>=80?"良好":s.score>=60?"注意":"需關注";
    var c=pfc(s.k);
    return '<div class="srow">'+
      '<span class="pf" style="background:'+c.bg+';color:'+c.fg+'">'+esc(s.k)+'</span>'+
      '<span class="smeta">'+s.n+'單・'+(s.br?('超時'+s.br+'・'):'')+'均'+s.avg+'天</span>'+
      '<span class="sbar"><span class="sfill" style="width:'+s.score+'%;background:'+col+'"></span></span>'+
      '<span class="sscore" style="color:'+col+'">'+s.score+' '+grade+'</span></div>';
  }).join("");
}
function toggleLoad(i){var d=document.getElementById("ld"+i),c=document.getElementById("lc"+i);if(!d)return;var open=d.style.display==="none";d.style.display=open?"block":"none";if(c)c.textContent=open?"▾":"▸";}
function load(){
  var UNASSIGNED="x:",m=Object.create(null),names=Object.create(null);
  ROWS.forEach(function(r){
    if(!r.people.length){(m[UNASSIGNED]=m[UNASSIGNED]||[]).push(r);return;}
    r.people.forEach(function(p){var k="u:"+(p.username||p.name);names[k]=p.name||p.username;(m[k]=m[k]||[]).push(r);});
  });
  var rows=Object.keys(m).map(function(k){
    var g=m[k];
    var hot=g.filter(function(r){return r.pct!=null&&r.pct>=80;}).length;
    var oldest=Math.max.apply(null,g.map(function(r){return r.ageD;}));
    return {k:k,label:k===UNASSIGNED?"未指派":(names[k]||k.slice(2)),n:g.length,hot:hot,oldest:oldest,un:(k===UNASSIGNED),g:g};
  }).sort(function(a,b){return b.n-a.n||b.hot-a.hot;});
  var max=Math.max.apply(null,rows.map(function(x){return x.n;}))||1;
  document.getElementById("load").innerHTML=rows.map(function(s,idx){
    var w=Math.round(s.n/max*100);
    var col=s.un?"#c0392b":(s.hot?"var(--amber)":"#4c8dd6");
    var name=s.un?'<span style="color:#c0392b">未指派</span>':('👤 '+esc(s.label));
    var hotTxt=s.hot?(' · <b style="color:var(--red)">告急/超時 '+s.hot+'</b>'):'';
    var head='<div class="lrow" data-i="'+idx+'"><span class="lcaret" id="lc'+idx+'">▸</span><span class="lname">'+name+'</span><span class="lbar"><span class="lfill" style="width:'+w+'%;background:'+col+'"></span></span><span class="lmeta">'+s.n+'張'+hotTxt+' · 最老 '+s.oldest+'天</span></div>';
    var items=s.g.slice().sort(function(a,b){return (b.pct||-1)-(a.pct||-1)||b.ageD-a.ageD;}).map(function(r){var pc=r.pct!=null?(Math.round(r.pct)+'%'):(r.ageD+'天');var pcol=r.breach?'var(--red)':(r.pct!=null&&r.pct>=80?'#a67a00':'#6b7686');var cc=pfc(r.pf);return '<a class="litem" href="'+BASE+r.iid+'" target="_blank"><span class="pill '+(r.pri||"")+'" style="'+(r.pri?'':'background:#adb5bd')+'">'+(r.pri||"—")+'</span><span class="pf" style="background:'+cc.bg+';color:'+cc.fg+'">'+esc(r.pf||"其他")+'</span><span class="litt">#'+r.iid+' '+esc(r.t)+'</span><span class="lits" style="color:'+pcol+'">'+pc+'</span></a>';}).join('');
    return head+'<div class="ldetail" id="ld'+idx+'" style="display:none">'+items+'</div>';
  }).join("");
}
kpis(); runway(); oldest(); load(); follow(); sites();
document.getElementById("load").addEventListener("click",function(e){var row=e.target.closest(".lrow");if(row)toggleLoad(row.getAttribute("data-i"));});
</script></body></html>"""

if __name__ == "__main__":
    main()
