---
name: agent-backend-ui-sync
description: 同步新代理後台（Vben SPA）「後台UI」記憶與後台實際渲染的側邊欄選單。當用戶說「同步後台選單記憶」「比對後台 menu 跟共享記憶」「後台停用的路徑要刪記憶」「後台新增功能要補記憶」「reconcile webui 記憶」「更新代理後台記憶」「對齊代理後台記憶」「用新後台重建頁面路由記憶」時觸發。
---

# 代理後台 UI 記憶同步（新後台 Vben SPA，reconcile）

把共享記憶（魔鏡）裡的「後台UI」功能記憶，跟**新代理後台實際渲染的側邊欄**對齊：後台已停用/移除的路徑 → 刪記憶；後台多出來的新功能 → 補記憶；標籤或麵包屑變了 → 更新。

> **新後台是 Vben Admin 的 Vue SPA**（網址 `https://example.invalid`，登入頁 `/auth/login`），與舊版 PHP 代理後台完全不同：路由是 History 路徑（`/member/index`、`/report/statistical`）而**不是 `.php`**；選單是 `ul.vben-menu`／`li.vben-sub-menu`／`a.vben-menu-item` 的巢狀結構，而不是舊版的 `ul.side-nav-menu`。本 skill 已改寫為新後台專用。

**REQUIRED 前置技能**：新增記憶時用 `memory-mcp:memory-store` skill 的三段模板與 tag 規範；列舉現有記憶時用 `memory-mcp:memory-search` skill 的 `memory_list` 帶 tag 確定性列舉。

**REQUIRED 前置依賴（跨 plugin）**：本 skill 用到兩邊工具——登入/抽側邊欄/驗路由用**本 plugin（`qa-toolkit`）** 的 Playwright MCP + `web-browser` skill；記憶讀/寫/刪用 **`memory-mcp`** 的工具（`memory_search` / `memory_list` / `memory_store` / `memory_delete`）與其 `memory-store` / `memory-search` skill。跑本 skill 前先確認 **`memory-mcp` 也已安裝**（否則沒有記憶工具可用）。

## 核心原則

**權威來源是後台實際渲染的側邊欄（`ul.vben-menu` 的巢狀樹），不是任何選單資料表。** 一切以側邊欄實際顯示的中文標籤與 `href` 路徑為準。

**識別「後台UI」記憶**：tag 同時含 `代理後台` + `後台UI`。功能頁記憶另含 `客服導航` + 模組 tag；清單/導航記憶另含 `清單` + `導航`。每筆功能記憶內文有一行 `網址路徑 /xxx`（新後台為 History 路徑，無 `.php`），是比對主鍵。

## 前置：登入取側邊欄

**登入**：用本 plugin 的 `web-browser` skill 登入 dev 後台——網址/帳密走本機 shell 啟動檔（`~/.zshrc` 或 `~/.bashrc`）的 `WEBBROWSER_BE_<URL|USER|PASS>`（值不進本 skill 或記憶）。新後台 `WEBBROWSER_BE_URL=https://example.invalid`。登入頁欄位：`textbox "帳號"`、`textbox "密碼"`、`button "登入"`；登入成功會導到 `/home`。

⚠️ **帳號權限**：側邊欄**受帳號權限過濾**，權威來源是側邊欄，所以帳號權限不足＝權威集合殘缺。**必須用具備完整客服選單權限的帳號**；若不足需改用完整權限帳號（該憑證由用戶當下提供，不要寫進 skill 或記憶）。

**登入後先驗選單完整度（必做，先於抽取）**：把本次側邊欄頂層數量跟基準（`導航快照` 記憶的頂層數，或用戶已知的模組數）對一下——若明顯偏少（例：只看到 5 個頂層、基準有 33 個），代表此帳號權限被過濾，**停下換全權限帳號**，別拿殘缺選單去跑下面的 diff（會把「其實有、只是你看不到」的功能誤判成停用而誤刪記憶）。

確認完整後，用 Playwright MCP（`browser_evaluate`）在登入後任一頁面抽側邊欄。**Vben 選單即使折疊，整棵樹都在 DOM 裡**（子選單 `ul` 只是 `display:none`），所以不需要逐一 hover/展開，一次遞迴就能抽全：

```js
// browser_evaluate on 新後台任一登入後頁面。用 filename 存檔避免大輸出灌爆 context。
() => {
  const top = document.querySelector('ul.vben-menu');          // 最外層選單（第一個 ul.vben-menu 就是根）
  const out = [];
  const titleOf = (subLi) => {                                  // li.vben-sub-menu 的群組標題
    const h = subLi.querySelector(':scope > .vben-sub-menu-content .vben-sub-menu-content__title span[title]')
           || subLi.querySelector(':scope > .vben-sub-menu-content span[title]');
    return h ? h.getAttribute('title').trim() : '(無標題)';
  };
  const walk = (ul, crumb) => {
    for (const child of ul.children) {
      if (child.tagName === 'A' && child.classList.contains('vben-menu-item')) {   // 葉節點
        const href = (child.getAttribute('href') || '').split('?')[0].split('#')[0];
        const label = (child.querySelector('span[title]')?.getAttribute('title') || child.innerText || '').trim();
        if (href) out.push({ href, crumb: [...crumb, label] });
      } else if (child.classList && child.classList.contains('vben-sub-menu')) {    // 子選單，遞迴進巢狀 ul
        const nested = child.querySelector(':scope > ul.vben-menu');
        if (nested) walk(nested, [...crumb, titleOf(child)]);
      }
    }
  };
  walk(top, []);
  const seen = new Set(), dedup = [];                           // 去重（同路徑多位置各留一次）
  for (const r of out) { const k = r.href + '|' + r.crumb.join('>'); if (!seen.has(k)) { seen.add(k); dedup.push(r); } }
  return JSON.stringify({ total: dedup.length, uniqueHrefs: new Set(dedup.map(r=>r.href)).size, routes: dedup });
}
```

去掉 query string、去重。這份 `{href → crumb}`（每筆 `crumb` 為 `[頂層模組, …, 葉標籤]`）就是**權威集合 A**。基準值：2026-09-04 全權限帳號實測為 **33 個頂層模組、240 個唯一路由**。

## 同步流程 SOP

### 1. 取基準清單 B ＝ 導航快照記憶

`memory_list(tags=["導航快照"])` **取回單筆快照**當基準集合 B——不要逐筆列上百筆功能記憶（會爆 token）。快照內文即上次側邊欄全表：`## 頂層` 標題 + 每行 `路徑<TAB>麵包屑`。解析成 `{路徑 → 麵包屑}`。

- 首次無快照時才 fallback：逐頁 `memory_list(tags=["代理後台","後台UI","客服導航"], tag_match="all", page_size=100)` 重建路徑集，並在 step 5 產生快照。
- 要刪某路徑的**功能記憶**時再單獨 `memory_search` 定位該筆取 `content_hash`（單筆查，不必列全部）。

### 2. 三向 diff（以 href 路徑為主鍵）

| 情況 | 判定 | 動作 |
|---|---|---|
| 路徑在 B（記憶）有、A（側邊欄）**無** | 後台已停用/移除，或此帳號權限已收回 | **刪除**該記憶（先過 confirm gate） |
| 路徑在 A 有、B **無** | 後台新增功能 | 先驗證路由能渲染（見 step 4）→ 能開才**新增**；導回登入/空頁**不新增**、只列略過清單回報 |
| 路徑兩邊都有，但**麵包屑或葉標籤變了** | 選單改名/搬位置 | **更新**（刪舊 + 依新麵包屑重存） |
| 路徑兩邊都有且一致 | 無異動 | 略過 |

> **舊 PHP 後台 → 新 Vben 後台 首次重建**：兩套路由型態不同（`.php` vs History 路徑），無法逐路徑對應。做法是先依集合 A 全量新增新後台記憶，再把舊的 `.php` 後台UI 記憶列成**待刪清單**交用戶確認後刪除（見 step 3），不要嘗試自動配對。

### 3. 刪除：先 dry-run，必須經用戶明確確認（destructive gate）

刪記憶不可逆。**先把「待刪清單」（路徑 + 葉標籤 + content_hash）列給用戶看，說明每筆為何判定停用/汰換，取得明確同意後才刪**。逐筆 `memory_delete(content_hash="<hash>")`。

- 「路徑不在側邊欄」可能是**權限隱藏**而非真的停用——若不確定，先請用戶確認該功能是否真的下架，別逕自刪。
- 勿用 tag 批次刪（會誤刪整類）；一律 by `content_hash` 精準刪。

### 4. 新增 / 更新：先驗路由，再依 memory-store SOP + 真實麵包屑

**先驗證路由（必做，開不了就不補）**：新增前用 Playwright `browser_navigate` 實際開該路徑（`https://example.invalid<path>`），確認**沒有被導回 `/auth/login`、頁面有正常渲染主內容**（不是 SPA 的 404/無權限空白頁）。側邊欄列出不代表頁面可用。**導回登入／404／空頁 → 不新增，列入「略過清單」回報用戶**。

> 首次全量重建、且集合 A 直接來自登入後的權威側邊欄時，可略過逐頁驗證（側邊欄有列＝有權限），改為抽查數個路由確認渲染即可；把「未逐頁驗證」明講在回報裡。

驗證通過後，對該路徑套 `memory-mcp:memory-store` skill 三段模板（**輕量模式：路徑＋麵包屑**）：

- **標題**：口語問句（「XX 在哪裡操作？ / XX 在哪」），把側邊欄**真實葉標籤**放進去當同義詞。
- **內文**：`「<真實葉標籤>」位於新代理後台選單「<真實麵包屑 A > B >…>葉>」，網址路徑 <path>。`
  - 輕量模式只寫定位（葉標籤＋麵包屑＋路由），**不逐頁抓欄位**；日後要補篩選/列表/操作欄位，再逐頁進去補進同一筆（刪舊重存）。
- **出處**：`example.invalid（新後台）側邊欄實測（YYYY-MM-DD）`。
- **tag**：`代理後台,後台UI,客服導航,<真實頂層模組>`；type `reference`。
- 批次補多筆時帶同一個 `conversation_id`（跳過語意去重）。
- **更新**＝先刪舊 hash（過 gate）再依上式重存。

### 5. 更新導航快照 ＋ 產生清單/導航記憶

- **更新快照**（tag `導航快照`）：把本次線上側邊欄全表覆蓋回去（刪舊 `content_hash` 重存新版；含快照日期、頂層數與路由數）。這樣下次 diff 才有正確基準。
- **清單/導航記憶**：批次寫 2 筆以上就要有一筆清單記憶（見 `memory-mcp:memory-store` skill 6.5）。依側邊欄頂層分組、照側邊欄順序，列出每個頂層模組與其葉數；標題用「新代理後台有哪些功能 / 新後台選單清單」。

### 6. 驗證

- 重跑 step 1–2 的 diff，三向差異應為空（或只剩你刻意保留的）。
- 用 `memory_search` 抽查 2–3 個新增/更名項的口語問法都命中正確麵包屑。
- 清單記憶宣稱的 N 與實際功能記憶數 M 交叉核對（見 `memory-mcp:memory-search` skill 的名冊漂移檢查）。

## 雷區

| 雷 | 正解 |
|---|---|
| 用舊選擇器 `ul.side-nav-menu`（舊 PHP 後台）抽新後台 | 新後台一律用 `ul.vben-menu` 遞迴；子選單是 `li.vben-sub-menu`、標題在 `.vben-sub-menu-content__title span[title]`、葉是 `a.vben-menu-item[href]` |
| 以為要 hover 才抓得到子項 | Vben 折疊子選單仍在 DOM（`display:none`），一次遞迴抽全 |
| 把 History 路徑當成要有 `.php` | 新後台路由是 `/member/index` 這種 History 路徑，無 `.php`；比對主鍵是整條 href |
| href 帶 query string 或重複位置，比對對不上 | 去掉 `?`/`#`；同路徑多位置都保留 |
| 權限隱藏誤判成停用而刪掉 | 用完整權限帳號（頂層應為 33 個左右）；不確定先問用戶 |
| 用 tag 批次刪 | 一律 by `content_hash` 精準刪，且先 dry-run + confirm |
| 只補功能記憶、忘了改清單/導航快照 | step 5 一起校正，否則名冊漂移 |
| 側邊欄列了但該路由導回登入/404/空頁 | **不新增**，列入略過清單回報；別把殘缺頁寫成記憶 |
