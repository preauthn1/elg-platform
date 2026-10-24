---
name: reconcile-bet-records
description: 遊戲商三表（三方 ↔ BR ↔ TBR ↔ SBR）對帳診斷，只讀不寫，終點給出該走哪條修復路徑。當使用者說「對帳」「跑三表對齊」「對齊看看先不要動」「BR 跟 TBR 對不上看差在哪」時載入。🚫 要修、要補帳走 repair-bet-records；查補帳紀錄走 list-sbr-corrections。
argument-hint: "[vendor] [日期 Y-m-d] [日期 Y-m-d?]"
---

你是博弈平台的對帳工程師，負責**診斷**任一遊戲商的三表帳目是否對齊、並判定該走哪條修復路徑。

依本 SOP 處理：$ARGUMENTS

> **進場前必讀**：本 SOP 為**單一 (vendor × environment) 對帳診斷**。多白標需逐 connection 依序重跑，不混場。
>
> **vendor 專屬細節**（取資料命令、時區、欄位 mapping、status enum、特殊 incident）放在 `docs/reconcile/vendors/{name}.md`。處理特定 vendor 前先讀對應卡片。

## 觸發與分流

- **範圍**：遊戲商三表（vendor 三方 ↔ `xxx_bet_records` ↔ `total_bet_records` ↔ `summary_bet_records`）的對帳診斷：環境健檢、三表 SUM 對齊、內部 drift 分流判斷、逐筆或分組驗證，終點輸出分流結論（走哪條修復路徑：Phase B′／C／D）。
- **觸發**：「對帳／對齊／診斷／查差異／先看不要動」這類純查不改的說法。典型句型：「跑 PPSW 5/2 三表對齊」「PTSW 5/15 對齊看看先不要動」「KissH5 BR 跟 TBR 對不上看一下差在哪」。
- **🚫 不觸發**：要實際修復（補帳／補進 SBR／修 BR、TBR／重建 SBR／取三方修）走 `repair-bet-records`；查補帳結果、SBR correction 走 `list-sbr-corrections`。

---

## 本 skill 是純讀診斷（要修復走 repair）

本 skill **全程純讀、不執行任何寫入命令**（不跑 `summary_bet_record` 實寫、不跑 `fix:*`/`patch:*`、不跑會寫 vendor cache 表的 `<vendor>:game-rounds` fetch、不對 DB 做任何 UPDATE/DELETE/INSERT、不對 `bet_record_recovery_logs` 以外的表做 `lockForUpdate` + 寫入）。產出是**分流結論**：三表對齊狀態 + 若有 drift 該走哪條修復路徑。

| 想做的事 | 用哪個 skill |
|---|---|
| 對帳 / 對齊看看 / 先看不要動 / 查差在哪 / 跑 SUM 驗證 | **本 skill**（reconcile-bet-records，純讀診斷） |
| 補帳 / 補進 SBR / 重建 SBR / 修 BR·TBR / 取三方修 | [`repair-bet-records`](../repair-bet-records/SKILL.md)（寫入修復；起手需本 skill 的分流結論） |
| 查補帳結果 / SBR correction remark | `list-sbr-corrections` |

> 診斷中途若使用者改口「那就補吧 / 幫我修」→ 交棒給 [`repair-bet-records`](../repair-bet-records/SKILL.md)，帶上本 skill 已得出的分流結論；**不在本 skill 內執行寫入**。

---

## 0. 心智模型：五層資料 + 三個對齊邊界

```
┌─────────────────────────────────────────────────────────────────┐
│ Layer 0:vendor source-of-truth                                  │
│   來源:遊戲商後台 CSV / API / DataFeed                          │
│   特性:唯一真值;時區與精度依各 vendor 規格                     │
│   ★ 工程師通常不主動拉,而是 OP 抄表寫成對帳 excel              │
└──────────────────┬───────────────────────────────────────────────┘
                   │ ① vendor ↔ BR 對齊（Phase D 才查）
                   ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 1:xxx_bet_records（vendor 自己的注單表）                  │
│   key:bet_id（注單唯一 ID）、reference_no（settle 後的訂單號）  │
│   時間:bet_time、payout_time                                   │
└──────────────────┬───────────────────────────────────────────────┘
                   │ ② BR ↔ TBR 對齊
                   ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 2:total_bet_records（跨 vendor 統一彙總表）              │
│   key:bet_record_id（指向 BR.id）+ vendor_id                   │
│   時間:payout_time（多數 vendor）或 bet_time（依 timeField）   │
└──────────────────┬───────────────────────────────────────────────┘
                   │ ③ TBR ↔ SBR 對齊（透過 SummaryBetRecordCommand）
                   ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 3:summary_bet_records（小時彙總、後台統計來源）          │
│   key:(member_id, game_id, vendor_id, hour)                    │
│   時間:data_time（小時桶）、summary_time（寫入時刻）           │
└─────────────────────────────────────────────────────────────────┘
```

> **★ Layer 0 是「最後手段的校正基準」，不是流程起點**：對帳**從內部三表（Layer 1→3）對齊開始**（零成本、純查我方 DB）；三表全對齊後仍 ≠ BO，才取 Layer 0（CSV / API / DataFeed）逐筆校正（Phase D、最後一步、取三方最貴）。Layer 0 畫在最頂代表「真值層級」，不是「執行順序起點」。

**對齊指標**（任一邊界都應同時滿足）：
- `SUM(bet)` 一致
- `SUM(valid_bet)` 一致（注意：SBR 用的是 `SUM(TBR.valid_bet)`）
- `SUM(win_lose)` 一致
- 筆數對齊（status=已結算的）

**核心規則**：對齊由「邊界」驅動，每個邊界各有自己的修復工具與驗證 SQL。**不要跳邊界**（例如直接改 SBR 是錯的，要回到上游邊界處理）。

### 下游報表資料來源（改報表前必先 grep）

不同下游報表讀的層**並不一致**（代理後台日報讀 TBR、會員 App / 下線佣金讀 SBR、SR11 推測讀 SBR 但**未驗證**）。**改報表前必先 grep 該 controller 的 Model 與讀取層**：來源層該欄位為 0 → 補來源層寫入；來源層有值但模板沒顯示 → 才改模板。完整對照表（含證據檔、SR11 caveat）→ [`reference/policies.md`](reference/policies.md) §A。

---

## 1. 流程總覽（決策樹）

本 SOP 是**決策樹**，不是線性流程，核心是**「由內而外、成本遞增」的升級原則**。本 skill 負責 **Phase A（環境健檢）+ Phase B（內部對齊分流）**，產出分流結論；分流去向的**修復執行（Phase B′/C/D/E）在 [`repair-bet-records`](../repair-bet-records/SKILL.md) skill**。

> **觸發點**：對帳幾乎都是 OP 回報「vendor 後台 (BO) 總額 ≠ 我方後台報表（如 SR11）」而啟動。⚠️ SR11 讀哪一層在 APIs repo 未驗證、僅依 OP 經驗推測讀 SBR（見 §0「下游報表資料來源」）——別預設「SR11=SBR drift」，先確認 OP 比的是哪份報表、該報表讀哪一層。
>
> **成本遞增鐵則**：取三方資料（BO 後台匯出 / API 調用）是**最貴**的一步，**不是第一步**。BO≠SBR 的差異**絕大多數來自我方內部 propagation gap**（BR 沒寫進 TBR、或 TBR 沒統計進 SBR），這些純查我方 DB 就能定位、純內部就能修。**先窮盡內部三表對齊（零成本），三表全等後仍 ≠ BO，才取三方**（此時差異「必定」落在 BR↔vendor 邊界——缺漏/孤兒/金額/歸戶四類皆可能，見 §3 分流判斷推論）。

```
Phase A: 環境健檢　【本 skill】
  Step 0  vendor 解析、判型、對帳卡 gate（無卡 / 缺必填 → STOP）、是否啟用

Phase B: 對帳輸入收集 + 內部對齊分流（零成本，純查我方 DB）　【本 skill】
  Step 1  接收 OP 對帳 excel（vendor BO 抄表 sum + 總輸贏 + 筆數）
  Step 2  跑三表 SUM 對齊 → 先定位 drift 在哪個內部邊界:
          ┌─ 全對齊              → Step 2.5 逐筆/分組驗證 → 內部 rollup pass(暫時通過,非結案)
          │                        若需 source-of-truth 結案 → repair Phase D Step 6-7 vendor 逐筆驗證
          ├─ TBR=BR ≠ SBR        → 分流結論「Phase C」(repair:純內部重建 SBR)
          ├─ BR ≠ TBR  ┬ B-1 BR已結算、TBR漏寫  → 分流結論「Phase B′」(repair:純內部回寫 TBR)
          │            └ B-2 BR未結算/缺結算結果 → 分流結論「Phase D」(repair:需三方拿結算真值)
          └─ 三表全等 ≠ BO       → 分流結論「Phase D」(★ 殘差必在 BR↔vendor 邊界;
                                    唯一該取三方的時機;結案/稽核驗證另計,見結案分級)
                                    (推論詳述見 §3 分流判斷)

─────────────── 以下修復路徑在 repair-bet-records skill 執行 ───────────────

Phase B′: BR→TBR 內部重建（B-1 路徑,純內部、不取三方）　【repair】
Phase C : SBR 重建路徑（輕、最常見,純內部）　【repair】
Phase D : 取三方比對 + 修 BR/TBR（重、最後手段）　【repair】
Phase E : 收尾（殘留 unsettled 盤點與處置）　【repair】
```

> ⚠️ **本決策樹只描述「走哪條路」**。本 skill 的終點是**輸出分流結論**（見 §3 末「分流結論輸出」）；實際修復寫入由 [`repair-bet-records`](../repair-bet-records/SKILL.md) 接手，其每個寫入命令前各有 STOP gate confirm。

---

## 2. Phase A — 環境健檢

### Step 0：vendor 解析 + 判型 + 對帳卡 gate

**任何對帳作業開工前都先跑這段**。確認 (a) 連到的 DB 是不是目標環境、(b) 使用者口語的 vendor 名稱對應到哪一個 `vendor_id`、(c) BR 表名為何、(d) **該 vendor 有沒有對帳卡**：卡是本 SOP 的硬依賴，無卡不對。

全程純讀、零進容器：vendor 解析 + 判型走一條 mysql MCP，查詢參數（`time_field` / 有效投注謂詞 / 歸戶 / 切口 / 金額口徑）全讀對帳卡，不讀 config、不帶預設值。

> **為何卡是硬依賴**：沒卡也跑得出數字，但「有效投注謂詞 / aggregator 歸戶 / 切口 / 金額口徑」全靠猜，跑出的對齊無法保證正確。金流對帳場景「看起來對、卻無法保證」會被當成已對齊結案，比停下來更危險。硬依賴的標的是**卡這份契約**，不是 config 註冊：config 未註冊但有完整卡，照樣能正確對帳（走 vendor 歸戶 SQL）；有 config 卻無卡也不該對（切口可信，但歸戶 / 謂詞仍空）。

**(1) vendor 解析 + 是否啟用 + 判型 → 一條 mysql MCP**（用對應白標工具 `mcp__plugin_apis-toolkit_mysql-{白標}__mysql_query`，不進容器）：

```sql
SELECT v.id, v.name, v.tname, v.is_single_wallet,
       EXISTS(SELECT 1 FROM vendor_infos vi WHERE vi.vendor_id = v.id AND vi.status = 1) AS enabled,
       (SELECT COUNT(*) FROM vendors v2 WHERE v2.tname = v.tname) AS tname_vendor_count
FROM vendors v WHERE v.id = {vendorId};
```

- `tname` → BR 表名 `{tname}_bet_records`；`is_single_wallet` → 單錢包 / 轉帳錢包；`enabled=0` → 未啟用、可能無資料。
- **判型**：`tname_vendor_count > 1` → **aggregator 共用表卡**（一表掛多 vendor、靠 `vendor_info_id` 歸戶、BR 不能直接 SUM）；`= 1` → **專屬表卡**（一表一 vendor）。判型決定該找哪份範本、套哪組終驗 SQL（見 §6）。
- vendor 名稱模糊（PPSW、Kiss、PG…）/ SW vs TW 雙版本 / 找不到 → 見 [`reference/vendor_resolver.md`](reference/vendor_resolver.md)（含模糊查 + 候選列示）。**解析失敗（找不到 / 多筆歧義）就停下來問操作者**，不要自行猜測。

**(2) 對帳卡 gate → 找卡、檢查必填、缺則 STOP**：

依 §6 索引找該 vendor 的對帳卡 `docs/reconcile/vendors/{name}.md`：

| 情況 | 動作 |
|---|---|
| 卡不存在 | **STOP**，引導當場建最小卡（見 §6.1 onboarding），不 fallback 預設值硬跑 |
| 卡存在但缺必填段（見 §6.2 必填契約） | **STOP**，列出缺項、補齊才續 |
| 必填齊全 | 用卡片參數組（`time_field` / 有效投注謂詞 / 歸戶謂詞 / 切口 / 金額口徑）往下跑 Step 2 |

- 卡內 `time_field` ＝ TBR/SBR 切口、`br_time_field` ＝ BR 切口（多數兩者同 ＝ `payout_time`；少數不同 → 以卡為準，否則 Step 2 會把「切口不同」誤判成 BR≠TBR drift）。
- **Phase C 可用性**：卡內註明該 vendor 是否註冊於 `config/summary_bet_record.php`（reconcile 據此指分流、不執行重建）。卡內 `time_field` 須與 config 同步：改 config 要回補卡（config 仍是 SBR 命令實讀的值、卡只是對帳查詢的權威副本）。

> ⚠️ **環境一致**：選對 mysql MCP 工具＝選對環境（`mysql-tpower` 連 TPower、`mysql-vworld` 連 vworld），配合 whitelabel 雙層環境標示。reconcile 全程走 MCP、不進容器，不需 `config('database.default')` 或 `DB_CONNECTION` 確認環境。

> **`time_field` 的意義**：`time_field` ＝ TBR/SBR 切口（多數 vendor 是 `payout_time`，少數結算即勝負的 vendor 是 `bet_time`），對帳查詢從**卡片**取得（卡內值須與 `config/summary_bet_record.php` 同步，後者是 `SummaryBetRecordCommand` 寫 SBR 實讀的值）。Step 2 / §4 的 TBR/SBR SQL **必須跟著用這個欄位**過濾，否則對 `bet_time` 系 vendor 會把 SBR(用 bet_time 算) 跟 TBR(被 SQL 用 payout_time 切) 拿來對：對到的是錯位資料，會把健康狀態當成 drift、或反過來把 drift 藏起來。
>
> BR 表的時間欄位用卡片的 `br_time_field`（多數情境也是 `payout_time`，少數 vendor 自帶不同時間欄位 → 見卡片）。

### Step 0-bis：未註冊 vendor 的 TBR↔SBR drift 處置

**前提：已有對帳卡**（無卡在 Step 0 gate 已 STOP）。卡內 Phase C 可用性註明該 vendor 未註冊於 config（Phase C 不可用），且跑完 Step 2 確認 BR=TBR ≠ SBR 時：**未註冊不代表 drift 可視為非 drift 結案**（SBR 是會員 App 統計 / 下線佣金 /（推測）SR11 的來源，缺欄位 = 這些報表長期錯）。三路徑處置（Path 1 註冊 config / Path 2 vendor-specific patch / Path 3 known limitation 追蹤）詳見 [`reference/policies.md`](reference/policies.md) §B。

### Step 0.5：同 DB 多子站／多白標範疇切分（若適用）

部分 DB connection（如 `vworld`）底下掛 **≥2 棵白標代理子樹**、或單一白標再切多個 vendor 子站（見 [`whitelabel-container-exec`](../whitelabel-container-exec/SKILL.md) skill §1-2）。**對帳三表（BR/TBR/SBR）是 vendor-level、不會自動限縮到白標／子站**——OP 對帳目標若是某個白標（例 `vworld` DB 上的 VWORLD vs WINTOTO）或子站，所有 Step 2/§4 SQL 都要額外切會員集合（**白標層**用 root 代理的 `hierarchy_path` 子樹＝下方 (A)、**子站層**用 `vendor_info_id`＝下方 (B)），否則撈到的是整個 DB 該 vendor 的注單、跟白標／子站 OP excel 對不齊。

**何時要切**：

| 情境 | 動作 |
|---|---|
| 使用者明示子站名（「WTT」「Wintoto」「VWSG」等） | 必切 |
| 同 DB 上 `vendor_infos` 表該 vendor 有多筆 row | 確認是否需切（問操作者「對 OP 哪個子站？」） |
| 單一 vendor_info | 跳過 |
| 使用者明示白標名做分帳（「VWORLD」「WINTOTO」）| 必切（用下方 (A) root 代理子樹）|

#### (A) 白標 → root 代理（`hierarchy_path` 子樹切法，vendor-agnostic、白標層首選）

部分 DB connection 底下掛多棵白標代理子樹，必須以 **root 代理的 `hierarchy_path` 子樹**切分（依「會員所屬代理是否落在該白標子樹」）。此法**對任何 vendor 通用**——三表都走 `member_id`／`m.parent`，不需各 vendor 的 `{vendor}_accounts` 表。

白標業務 root agent_id 一律以 **registry（`../../registry/whitelabels.metadata.yaml` 的 `rootAgentId`）為準，本文件不列舉**；切分前 `Read` registry 取目標白標的 `rootAgentId` 代入下方查詢。常見：`vworld` DB 掛 VWorld、WTT（Wintoto）與 VWSG（vworldsg）三棵白標主樹，皆於 registry 標 `rootAgentId`（WTT 對 PTSW 另切子站 `vendor_info_id`＝下方 (B)）。

> root 的 `hierarchy_path` 易變、不入 registry：以 `rootAgentId` 在 DB 衍生——`SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>`，**勿手寫**。

**三表 SQL 透過 `members.parent → agents` join 該白標子樹**（錨定式比對）：

**做法（mysql MCP，兩條）**：MCP 單語句、不能 `SET @var`，改為先取 root 的 `hierarchy_path`、再內聯到三表查詢：

```sql
-- 條 1：取 root hierarchy_path（rootAgentId 取自 registry，勿手寫 path）
SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>;        -- 例得 '/1/3389'
```

```sql
-- 條 2：三表查詢內聯該 path（例 PTSW=144、TBR）。
--   ★ 用 path 自身 + path + '/%'（加分隔斜線），禁裸尾 '/1/3389%'
--     （會誤抓 '/1/33892…' 這類數字前綴相同的別樹）。
SELECT t.vendor_id, COUNT(*) AS cnt, SUM(t.valid_bet) AS vb, SUM(t.win_lose) AS wl
FROM total_bet_records t
JOIN members m ON m.id = t.member_id
JOIN agents  a ON a.id = m.parent
             AND ( a.hierarchy_path = '/1/3389'
                OR a.hierarchy_path LIKE '/1/3389/%' )
WHERE t.vendor_id = 144 AND t.payout_time >= '2026-03-31 16:00:00' AND t.payout_time < '2026-04-30 16:00:00';
```

> ⚠️ **collation**：mysql MCP 連線以 utf8mb4 建立、查詢不需自帶 `SET NAMES`。若字面量比較報 *Illegal mix of collations*（`agents.hierarchy_path` 是 `utf8mb4_unicode_ci`），在字面量側加 `COLLATE utf8mb4_unicode_ci`（如 `a.hierarchy_path = '/1/3389' COLLATE utf8mb4_unicode_ci`）；切勿轉 `0900_ai_ci`：會與索引 collation 不符、`hierarchy_path` 索引失效退化全表掃描。

#### (B) 子站 vendor_info_id 切法（vendor-specific）

**解析子站 vendor_info_id**（用 `agent_vendors` 三方 mapping）：

```sql
-- mysql MCP（rootAgentId 取自 registry，vendorId 例 PTSW=144）
SELECT vendor_info_id FROM agent_vendors WHERE agent_id = <rootAgentId> AND vendor_id = {vendorId};  -- 例得 615
```

**Step 2 / §4 SQL 加子站篩選**（三表都無 `vendor_info_id` 欄位、走 `{vendor}_accounts` join）：

```sql
-- BR：username 對 username
FROM {vendor}_bet_records bb
JOIN {vendor}_accounts pa ON pa.username = bb.username AND pa.vendor_info_id = ?

-- TBR / SBR：member_id 對 member_id
FROM total_bet_records t   -- 或 summary_bet_records s
JOIN {vendor}_accounts pa ON pa.member_id = t.member_id AND pa.vendor_info_id = ?
```

範例（PTSW 切 WTT，VWorld 上）：

```sql
SELECT COUNT(*) AS cnt, SUM(t.valid_bet) AS vb
FROM total_bet_records t
JOIN ptsw_accounts pa ON pa.member_id = t.member_id AND pa.vendor_info_id = 615  -- WTT
WHERE t.vendor_id = 144 AND t.payout_time >= '2026-03-31 16:00:00' AND t.payout_time < '2026-04-30 16:00:00';
```

> ⚠️ **SBR 命令本身（`summary_bet_record --vendor=N --date=Y-m-d`）沒有 `--vendor-info` 參數**、會以整個 vendor 為單位寫入 correction（覆蓋所有子站）。子站切分是「查詢驗證」層的事；「重建 SBR」（repair Phase C）仍按整個 vendor 跑——多子站環境的 SBR correction 全部子站共寫。

---

## 3. Phase B — 對帳輸入收集 + 內部對齊分流

### Step 1：接收 OP 對帳 excel

OP 每日在各 vendor 後台 (BO) 抄表，寫入公司對帳 excel — 這是 vendor 後台數字的權威來源，**工程師不主動拉 vendor 後台**。

需從 OP 取得（或對帳 excel 直接查）：
- 對帳日期區間（GMT+8）
- vendor 後台該區間 `sum_bet`、`sum_win_lose`、筆數
- OP 認為的差異描述（「我方少了 X」「金額對不上」）

> 若 OP 沒提供具體數字、只說「某 vendor 對不上」→ 先請 OP 補抄表數字再開工。沒有 vendor 後台 sum 沒法判定 Phase D 入口。

### Step 2：跑三表 SUM 對齊 + 與 OP excel 比對

跑下方查詢，三個邊界都對 → 進入分流判斷：

> ⚠️ **BR 邊界 schema 高度 vendor-specific：本範本 BR 查詢假設有 `bet`/`valid_bet`/`status`/`win_lose` 欄，但實戰隨機 3 個 TW vendor 只 1 個能直接套。套 BR 查詢前先 `DESCRIBE {brTable}` 確認欄位**（TBR/SBR 是統一表、欄位穩定，不受此限）：
> - **欄位缺失 / 異名 / 語意**：`status`（已結算過濾）**限單錢包（SW）**；TW 的 BR 多無 `status`（實證 pp 用 `bet_status` 異名、vpower 全異）→ 結算判斷依該 vendor（見 vendor 卡 / DESCRIBE）。⚠️ **`status` 值的語意也 vendor-specific**：實證 fumasw 是 SW、有 `status` 卻像投注/派彩分錄（status=1 與 0 的 `win_lose` 正負相反）、非單純「已結算」，硬套 `status={SETTLED}` 會跑出假 drift。`valid_bet`（聚合商常無）、`bet`、甚至**時間欄 `payout_time` 都可能異名**（實證 vpower=`time`/`utc_time`、pp=`end_time`）→ **`{brTimeField}` 與可比指標一律以 DESCRIBE 實際欄位為準，缺哪剔除哪，BR↔TBR 只比兩端都有的欄**。
> - **範疇**：聚合商共用表（一表多 vendor、無 `vendor_id` 欄、靠 `vendor_info_id` 區分）、或單一 `vendor_id` 跨幣別 → 對帳範疇須改為 `(vendor_info_id 集合 × 幣別 × status 對稱)`，完整範本見 vendor 卡 `docs/reconcile/vendors/{name}.md`。
> - **效能**：這類大表（如 `pgssw_bet_records`，約 8 千萬行）時間欄位常無索引，range 掃描退化全表；查詢前先 `EXPLAIN`，補索引走 APIs migration、不在本 skill。

> **三條走 mysql MCP**（對應白標工具 `mcp__plugin_apis-toolkit_mysql-{白標}__mysql_query`；Step 2 / 2.5 / §4 的 SQL 一律如此）。佔位符代入後跑（MCP 無 prepared statement、值內聯）：`{brTable}`/`{brTimeField}`/`{timeField}`（Step 0 取得，多數 `payout_time`）、`{vendorId}`、`{SETTLED}`（vendor 卡 status enum）、`{utcStart}`/`{utcEnd}`（UTC 半開區間）。
> 範例：GMT+8 [2026-04-01, 2026-05-01) → `{utcStart}`=`2026-03-31 16:00:00`、`{utcEnd}`=`2026-04-30 16:00:00`；vendor 83、`ppsw_bet_records`、`payout_time`。

```sql
-- (1) BR 已結算：四指標(cnt + raw_bet + valid_bet + wl)。BR 通常無 commission/jackpot；時間用 {brTimeField}
--     ★ status={SETTLED} 限 SW；TW 無 status（pp 用 bet_status、vpower 全異）；payout_time/valid_bet/bet 都可能異名或缺 → 一律以 DESCRIBE 實際欄位為準、缺則剔除（見上方路標）
SELECT COUNT(*) AS cnt, SUM(bet) AS raw_bet, SUM(valid_bet) AS valid_bet, SUM(win_lose) AS wl
FROM {brTable}
WHERE {brTimeField} >= '{utcStart}' AND {brTimeField} < '{utcEnd}' AND status = {SETTLED};
```

```sql
-- (2) TBR：含財務欄位 rc/comm/jp(SBR 從 TBR 聚合,不查會漏抓 drift)。時間用 {timeField},不能硬編 payout_time
SELECT COUNT(*) AS cnt, SUM(bet) AS raw_bet, SUM(valid_bet) AS valid_bet, SUM(win_lose) AS wl,
       SUM(rolling_chip) AS rc, SUM(commission) AS comm, SUM(jackpot) AS jp
FROM total_bet_records
WHERE vendor_id = {vendorId} AND {timeField} >= '{utcStart}' AND {timeField} < '{utcEnd}';
```

```sql
-- (3) SBR：用 data_time(不是 summary_time、否則漏 correction)。SBR.bet = SUM(TBR.valid_bet),跟 TBR 比對 valid_bet
SELECT SUM(record_count) AS cnt, SUM(bet) AS valid_bet, SUM(win_lose) AS wl,
       SUM(rolling_chip) AS rc, SUM(commission) AS comm, SUM(jackpot) AS jp
FROM summary_bet_records
WHERE vendor_id = {vendorId} AND data_time >= '{utcStart}' AND data_time < '{utcEnd}';
```

> **多子站 / 白標**：三條各加 §0.5 的 scope JOIN（白標層 `agents` hierarchy、子站層 `{vendor}_accounts.vendor_info_id`；BR 走 username、TBR/SBR 走 member_id）。

### 分流判斷

「三表全等」必須**所有指標**皆等：
- **BR ↔ TBR**：`cnt`、`raw_bet`、`valid_bet`、`wl`
- **TBR ↔ SBR**：`cnt`、`valid_bet`、`wl`、**以及 `rolling_chip` / `commission` / `jackpot`**（SBR 從 TBR 聚合這三欄）

只比 `valid_bet/wl` 會漏抓的陷阱：
- **抵消重複列**：兩筆 raw_bet 互相抵消、valid_bet 加總對得上、但 cnt 多了
- **raw_bet drift**：raw_bet 對不上、但 valid_bet 因 vendor 剔除規則相等 — 代表上游有人改錯欄位
- **財務欄位 drift**：bet/wl 都對、但 commission/jackpot/rolling_chip 漂移 — 會讓後台統計與會計欄位長期不一致

> **成本遞增分流原則**：下表由上到下＝由內而外、由便宜到貴。**永遠先解內部 drift（Phase C / Phase B′，純查我方 DB），把三表對齊後才考慮取三方（Phase D）**。不要一看到 BR≠TBR 就跳三方——多數 BR≠TBR 是純內部 propagation gap。

| 結果 | 分流結論（去 repair 走哪條） | 成本 |
|---|---|---|
| **所有指標全等（三表）+ 與 BO(OP excel) 相等** | 過 Step 2.5 後標記**「內部 rollup pass（暫時通過、未做 source-of-truth 比對）」**；最終結案見下方分級 | 零 |
| **TBR=BR ≠ SBR**（含 rc/comm/jp drift 也歸此分支） | → **Phase C**（TBR→SBR 沒統計到，**純內部**重建 SBR） | 低 |
| **BR ≠ TBR · B-1**：BR 已結算、金額齊全，只是 TBR 漏寫（BR-only 方向） | → **Phase B′**（純內部從 BR 回寫 TBR，不取三方）；補完回 Step 2 重驗 | 低 |
| **BR ≠ TBR · B-2**：BR 未結算 / 缺結算結果，或 TBR-only / 金額 mismatch 無法判定誰對 | → **Phase D**（**需三方拿結算真值**才能判斷 BR 對錯） | 高 |
| **三表全等 ≠ BO(OP excel)** | 先做切口前置判斷（見下方）；非切口 → **★ 殘差必在 BR↔vendor 邊界（推論見下）→ Phase D 取三方**（唯一該取三方的時機） | 高 |

> **★ 「三表全等 ≠ BO」⟹ 差異落在 BR↔vendor 邊界的推論**：TBR、SBR 都是從 BR 衍生計算的。若我方三表內部已全等、卻仍對不上 BO 總額，差異**不可能**在 TBR/SBR（它們忠實反映了 BR），只可能在「BR 與 vendor source-of-truth 之間」。**但不要預設只有「BR 缺漏」**——repair Step 7 列的四類差異都會造成「內部全等卻 ≠ BO」：缺漏（vendor 有 BR 無）、孤兒（BR 有 vendor 無）、金額錯、歸戶錯。這是**mismatch 查源頭流程中、唯一需主動取三方的情境**；在此之前（內部三表還沒對齊）取三方都是把最貴的工具用在更便宜就能解的問題上。修復方向（Type A/B/C）依實際差異類型決定，不可一律當缺漏走 Type A 補建。
>
> ⚠️ **「唯一」僅限 mismatch 排查**：另有一類取三方與 mismatch 無關——**source-of-truth 結案**（月結 / 稽核 / 客訴牽連），即使三表 + BO aggregate 已全等，仍須跑 repair Step 6-7 逐筆 vendor diff 做嚴格驗證（見下方「對帳狀態分兩級」）。這不是「為了解差異」而是「為了證明真的沒差異」。

> **B-1 vs B-2 怎麼分**：⚠️ §Step 2.5 的 BR↔TBR 查詢**只撈 `status={SETTLED}`、且未 select `status`**，所以它列出的 BR-only 都是「已結算卻漏寫 TBR」＝**B-1 候選**；它**看不到未結算的 BR-only**。完整判別要**兩段查**：
> - **已結算 BR-only**（B-1）：Step 2.5 查詢 (1) 列出的那批；再核對金額齊全（`valid_bet/win_lose` 非空）
> - **未結算 BR-only**（B-2）：另跑 §4.3 的 pending 殘留查詢（`status<>{SETTLED} AND payout_time IS NULL`）；有 row = 待結算、需三方拿結算結果
> - 另外 TBR-only / 金額 mismatch 無法判定誰是真值 → 也歸 B-2（需三方）

> 若分流不明（例如 BR≠TBR 且 ≠BO），先把 BR↔TBR 對齊（B-1 純內部 / B-2 三方），對齊後再回看 BO 是否跟著對。

> ⚠️ **「BR=TBR ≠ SBR」即使 vendor 未在通用 SBR config 註冊，drift 仍是真實 drift**
>
> Step 0 讀卡發現卡內 Phase C 註明 vendor 未註冊（Phase C 不可用）時，**不代表 TBR↔SBR 不對齊可以視為「該存在的差異」結案**。SBR 是會員 App 統計、下線佣金、（推測）SR11 等下游報表的資料來源（對照含 grep 證據見 [`reference/policies.md`](reference/policies.md) §A），SBR 漏寫欄位會讓這些報表長期少呈現該欄位。
>
> ❌ 禁止：「vendor 沒接通用 SBR、所以 SBR 缺欄位是正常」；以及不經 grep 就主張「改報表模板顯示就好」
>
> ✅ 應做：依 §Step 0-bis 三選一處置（Path 1 註冊到 config / Path 2 vendor-specific patch / Path 3 known limitation 追蹤），不可靜默結案。

### ⚠️ 「全等結案」的限制（aggregate vs 逐筆 source-of-truth）

OP excel 是 vendor 後台抄表 sum，本質是 **aggregate 對比**。即使三表全等 + OP 對得上，仍可能存在以下情境通過驗證：
- vendor 三方有某筆 bet_id、我方完全沒有 — 該筆金額 vs 一筆我方獨有的孤兒列剛好抵消
- 某筆注單在我方記到錯 username / 錯 member — 三表內部一致、但 vendor 三方對該玩家的 bet_id 集合對不上
- 重複/合併列：底層列數不同、SUM 剛好等

**對帳狀態分兩級**（**`結案` 一詞保留給 source-of-truth 通過**；aggregate 對齊只是暫時通過、不是結案）：

| 狀態 | 條件 | 適用 / 殘留風險 / 必須升級觸發 |
|---|---|---|
| **內部 rollup pass（暫時通過）**| 三表內部對齊 + Step 2.5 兩個查詢都 0 row + OP aggregate 對 | **適用**：每日例行對帳。**殘留風險**：vendor 三方 bet_id 缺漏 / 多餘 / 錯記 member，aggregate 抵消後仍可能存在。**Owner**：對帳工程師標記此狀態時必須同步紀錄「vendor row-level 未驗證」於工單 / changelog。**強制升級**到 source-of-truth 結案的情境：(a) 月結（每月對該月所有 internal rollup pass 日期跑一輪 repair Phase D Step 6-7）；(b) 該日有任一 BR 涉及客訴 / 大額爭議；(c) OP / 客服回報該日帳目問題；(d) vendor 後台異常通知；(e) 主管 / 稽核臨時抽查 — 滿足任一就必須升級 |
| **Source-of-truth 結案**（嚴格、終結）| 上方所有 + repair Phase D Step 6-7 vendor 三方 bet_id 集合與 BR 逐筆 join 全對齊 | **適用**：月結、稽核要求、可疑大額差異、客訴牽連帳目。**真正可關工單** |

> 「內部 rollup pass」**不是結案**，是「暫不開單但風險未消除」的工程狀態。對外回報（OP / 客服 / 會計）若被要求「該日有無對齊」，回答應為「內部三表已對齊、vendor source-of-truth 未驗證；如需嚴格驗證請啟動 repair Phase D」，不可直接說「對齊完成」。

### Step 2.5：結案前逐筆 / 分組驗證（防 aggregate 漏抓）

**aggregate SUM/COUNT 全等不代表底層資料正確**。常見的「總額全等但底層污染」情境：
- 兩筆 raw_bet 互相抵消（+100/-100）— SUM 對、cnt 對、但底層多了 1 對假帳
- TBR 的 `member_id` 寫錯（A 玩家的注單記到 B 玩家）— vendor 總額對、但會員報表錯
- SBR 聚合到錯 `game_id` 或錯 `vendor_id` — 月帳合計對、但遊戲別/代理別統計污染

只有逐筆 + 分組驗證都歸零才能標記「內部 rollup pass」（暫時通過，不是結案；結案語意見上方分級表）：

> 走 mysql MCP、佔位符內聯（同 Step 2）；兩條各一次 `mysql_query`。

```sql
-- (1) BR↔TBR 雙向逐筆檢查(BR-only / TBR-only / member 寫錯 / 金額 drift 都要抓)
--     用 UNION ALL 模擬 FULL OUTER;從兩側分別出發 LEFT JOIN 對方,只保留異常 row
SELECT 'BR_only_or_mismatch' AS issue,
       b.id AS br_id, b.bet_id, b.member_id AS br_mid,
       t.id AS tbr_id, t.member_id AS tbr_mid, t.reference_no AS tbr_ref,
       b.valid_bet AS br_vb, t.valid_bet AS tbr_vb,
       b.win_lose  AS br_wl, t.win_lose  AS tbr_wl
FROM {brTable} b
LEFT JOIN total_bet_records t
    ON t.bet_record_id = b.id AND t.vendor_id = {vendorId}
WHERE b.{brTimeField} >= '{utcStart}' AND b.{brTimeField} < '{utcEnd}'
  AND b.status = {SETTLED}
  AND (t.id IS NULL                                       -- BR-only(TBR 缺)
       OR b.member_id <> t.member_id                       -- member 寫錯
       OR CAST(b.bet_id AS CHAR) <> t.reference_no         -- lineage 斷:TBR 指錯 BR 但同 window
       OR b.valid_bet <> t.valid_bet                        -- 金額 drift(DECIMAL(18,4) 直接比、0 容忍)
       OR b.win_lose  <> t.win_lose)                        -- 金額 drift

UNION ALL

SELECT 'TBR_only_or_wrong_br' AS issue,
       b.id, b.bet_id, b.member_id,
       t.id, t.member_id, t.reference_no,
       b.valid_bet, t.valid_bet,
       b.win_lose,  t.win_lose
FROM total_bet_records t
LEFT JOIN {brTable} b
    ON b.id = t.bet_record_id
   AND b.{brTimeField} >= '{utcStart}' AND b.{brTimeField} < '{utcEnd}'
   AND b.status = {SETTLED}
WHERE t.vendor_id = {vendorId}
  AND t.{timeField} >= '{utcStart}' AND t.{timeField} < '{utcEnd}'
  AND b.id IS NULL;                                       -- TBR-only(BR 不在窗 / 指錯 BR / status 不對)
```

```sql
-- (2) TBR↔SBR 分組重算(對 hour × member × game × vendor、雙向 + 所有財務欄位)
--     用 UNION ALL 模擬 FULL OUTER 含 SBR-only 分組
--     比對欄位:record_count(cnt) / valid_bet / win_lose / rolling_chip / commission / jackpot
--     (cost 是 SBR 衍生欄位、來自 calculateGameFee,不在 TBR 對齊範圍)
SELECT hr, member_id, game_id,
       SUM(t_cnt) AS t_cnt, SUM(s_cnt) AS s_cnt,
       SUM(t_vb)  AS t_vb,  SUM(s_vb)  AS s_vb,
       SUM(t_wl)  AS t_wl,  SUM(s_wl)  AS s_wl,
       SUM(t_rc)  AS t_rc,  SUM(s_rc)  AS s_rc,
       SUM(t_comm) AS t_comm, SUM(s_comm) AS s_comm,
       SUM(t_jp)  AS t_jp,  SUM(s_jp)  AS s_jp
FROM (
    SELECT DATE_FORMAT({timeField}, '%Y-%m-%d %H:00:00') AS hr,
           member_id, game_id,
           COUNT(*) AS t_cnt, SUM(valid_bet) AS t_vb, SUM(win_lose) AS t_wl,
           SUM(rolling_chip) AS t_rc, SUM(commission) AS t_comm, SUM(jackpot) AS t_jp,
           0 AS s_cnt, 0 AS s_vb, 0 AS s_wl, 0 AS s_rc, 0 AS s_comm, 0 AS s_jp
    FROM total_bet_records
    WHERE vendor_id = {vendorId} AND {timeField} >= '{utcStart}' AND {timeField} < '{utcEnd}'
    GROUP BY hr, member_id, game_id
    UNION ALL
    SELECT data_time AS hr, member_id, game_id,
           0, 0, 0, 0, 0, 0,
           SUM(record_count), SUM(bet), SUM(win_lose),
           SUM(rolling_chip), SUM(commission), SUM(jackpot)
    FROM summary_bet_records
    WHERE vendor_id = {vendorId} AND data_time >= '{utcStart}' AND data_time < '{utcEnd}'
    GROUP BY data_time, member_id, game_id
) u
GROUP BY hr, member_id, game_id
-- DECIMAL(18,4) 用 <> 直接比、0 容忍(任何容忍值都會吞掉 0.0001 級 drift,違反 §1 「差任何值都是 bug」)
HAVING SUM(t_cnt) <> SUM(s_cnt)
    OR SUM(t_vb)   <> SUM(s_vb)
    OR SUM(t_wl)   <> SUM(s_wl)
    OR SUM(t_rc)   <> SUM(s_rc)
    OR SUM(t_comm) <> SUM(s_comm)
    OR SUM(t_jp)   <> SUM(s_jp);
```

判讀（**依哪個查詢失敗決定分流結論，不要一律丟 Phase D**）：
- **查詢 (1) BR↔TBR 非 0** → 屬 BR↔TBR 邊界，分 B-1/B-2（⚠️ 此查詢已 `status={SETTLED}` 過濾、未 select status，**它列出的 BR-only 都是已結算的 B-1 候選、看不到未結算列**，分流要兩段查）：
  - 此查詢列出的 **BR-only**（已結算）→ **B-1**：再過 §Phase B′ 的「BO 佐證」後純內部回寫 TBR（在 repair）
  - 此查詢列出的 **TBR-only / 金額 mismatch**（無法判定誰是真值）→ **B-2**：Phase D 取三方
  - **另跑 §4.3 pending 查詢**抓「未結算 BR-only」→ **B-2**：待結算、需三方拿結算結果（這批不在本查詢結果內）
- **查詢 (2) TBR↔SBR 分組非 0** → 屬 TBR↔SBR 邊界：分流結論 **Phase C 重建 SBR**（純內部）；Phase C Step 5 若重跑後仍 drift，才依其補償路徑進 Phase D
  - `t_cnt > 0 AND s_cnt = 0` → SBR 該分組整段缺失（Phase C 重建）
  - `s_cnt > 0 AND t_cnt = 0` → SBR 聚到錯 member/game/hour（污染分組，Phase C 重算）
  - 兩邊都有但 cnt 或金額不等 → 該分組重算錯（Phase C 重建）

> **兩個查詢都必須回傳 0 row** 才能標記「內部 rollup pass」。任一回傳 row 即代表「總額對但底層污染」、需修正後重驗——但**修正邊界依上方判讀**（查詢(1)→BR↔TBR / 查詢(2)→Phase C），不是一律進 Phase D。
>
> ⚠️ **內部 rollup pass ≠ 結案**：通過 Step 2.5 只代表 BR↔TBR↔SBR 內部一致；vendor source-of-truth 未驗證。唯一可標記「結案」的路徑是走完 repair Phase D Step 6-7 取三方做 bet_id 逐筆 diff（見上方「對帳狀態分兩級」說明）。
>
> 跨白標時、(1)/(2) 兩個查詢都要逐 connection 跑。

**「三表全等 ≠ OP excel」前置判斷**：OP 是抄 vendor 後台（vendor 本身的時間切口），多數 vendor 後台以投注時間切，理論上對齊內部 `data_time`。但若 OP 抄的是 **summary_time / 寫入日視角的報表**（如 SR11、會計端報表），就會在當日 SBR 有跨日 correction 時偏離。
>
> 先查當日 SBR 是否有 `correction=1` 且 `data_time` 與 `summary_time` 落不同日的列 → 若有、且差額 ≈ 跨日 correction 列總和 → 純切口語意問題，跟 OP 對齊用語結案；否則 → 分流結論 Phase D 取三方。詳判讀公式與 SQL 見 [`reference/sum_validation_advanced.md`](reference/sum_validation_advanced.md) §4。

> 進階 SQL（找差異小時、TBR vs 原始 BR、跨白標循環、GMT+8↔UTC 對照、常見誤判陷阱）→ [`reference/sum_validation_advanced.md`](reference/sum_validation_advanced.md)

---

## 分流結論輸出（本 skill 終點 → 交棒 repair）

診斷完成後，輸出**分流結論**並指路。標準格式：

```
【對帳診斷結論】<env> | <vendor>(id) | <日期範圍>
- 三表狀態:BR↔TBR <對齊/B-1/B-2>、TBR↔SBR <對齊/drift>、三表↔BO <對齊/差額X>
- Step 2.5 逐筆:查詢(1) <0 row / N row>、查詢(2) <0 row / N row>
- 分流結論:<內部 rollup pass(暫時通過) / Phase C / Phase B′ / Phase D>
- 下一步:<無需修復,標記 rollup pass 並記錄「row-level 未驗證」
          / 要修復 → 用 repair-bet-records skill,帶此結論走 <Phase C/B′/D>>
```

| 分流結論 | 交棒給 repair 的哪條路徑 |
|---|---|
| 內部 rollup pass（暫時通過） | 無須修復；若需 source-of-truth 結案 → repair Phase D Step 6-7 |
| TBR=BR ≠ SBR | repair **Phase C**（重建 SBR） |
| BR ≠ TBR · B-1 | repair **Phase B′**（回寫 TBR） |
| BR ≠ TBR · B-2 | repair **Phase D**（取三方） |
| 三表全等 ≠ BO（非切口） | repair **Phase D**（取三方逐筆查修） |
| 三表全等 ≠ OP（切口問題） | 無須修復，用語結案 |

> 要修復時：明確告知使用者「接下來是寫入操作，交給 [`repair-bet-records`](../repair-bet-records/SKILL.md)」，並把上方分流結論一併帶過去；repair 起手會核對此結論、每個寫入命令前各有 STOP gate confirm。

---

## 4. 基本 SUM 驗證 SQL 範本

> Step 2 會用；repair 各 Phase 的「重跑驗證」也指回這裡。**§4 SQL 一律走 mysql MCP、佔位符內聯（同 Step 2）**。進階 SQL（找差異小時、TBR vs 原始 BR、跨白標循環、誤判陷阱、跨日 correction 分析）→ [`reference/sum_validation_advanced.md`](reference/sum_validation_advanced.md)

### 4.1 月份／區間三表總額對齊（核心）

見 Phase B 的 Step 2 範本。三個查詢一次跑，比對「分流判斷」一節列的全部指標（BR↔TBR 四項、TBR↔SBR 六項）。

### 4.2 找差異小時（TBR vs SBR）

用 `UNION ALL + GROUP BY` 模擬 FULL OUTER JOIN —— 含 **TBR-only 小時**（SBR 漏跑該小時）與 **SBR-only 小時**（SBR 有多餘 correction，但 TBR 已無對應）兩種高風險情境。單向 LEFT JOIN 會把 SBR-only 整段漏掉、且 `s.bet=NULL` 時 `ABS(s.bet - t.bet)` 為 NULL 不會觸發 HAVING。

```sql
SELECT hr,
       SUM(t_cnt) AS t_cnt, SUM(s_cnt) AS s_cnt,
       SUM(t_bet) AS t_bet, SUM(s_bet) AS s_bet,
       SUM(t_wl)  AS t_wl,  SUM(s_wl)  AS s_wl,
       SUM(s_bet) - SUM(t_bet) AS bet_diff,
       SUM(s_wl)  - SUM(t_wl)  AS wl_diff,
       SUM(s_cnt) - SUM(t_cnt) AS cnt_diff
-- ★ 把下方 {timeField} 兩處換成該 vendor 的 time_field(payout_time / bet_time,見 Step 0 解析)
FROM (
    SELECT DATE_FORMAT({timeField}, '%Y-%m-%d %H:00:00') AS hr,
           COUNT(*) AS t_cnt, SUM(valid_bet) AS t_bet, SUM(win_lose) AS t_wl,
           0 AS s_cnt, 0 AS s_bet, 0 AS s_wl
    FROM total_bet_records
    WHERE vendor_id = {vendorId} AND {timeField} >= '{utcStart}' AND {timeField} < '{utcEnd}'
    GROUP BY hr
    UNION ALL
    SELECT data_time AS hr,
           0, 0, 0,
           SUM(record_count), SUM(bet), SUM(win_lose)
    FROM summary_bet_records
    WHERE vendor_id = {vendorId} AND data_time >= '{utcStart}' AND data_time < '{utcEnd}'
    GROUP BY data_time
) u
GROUP BY hr
HAVING bet_diff <> 0 OR wl_diff <> 0 OR cnt_diff <> 0  -- DECIMAL 直接比,不容忍
ORDER BY hr;
```

判讀：
- **`t_cnt > 0 AND s_cnt = 0`** → SBR 該小時整段缺失（排程漏跑該小時）
- **`s_cnt > 0 AND t_cnt = 0`** → SBR 有 correction 但 TBR 已無對應（撤銷 / 跨日漂移 / 錯誤 correction）
- **兩邊都有但 diff 不為 0** → 該小時 SBR 計算錯或漏 row

### 4.3 TBR vs 原始 BR 表

`total.reference_no = xxx_bet_records.bet_id`（**注意：reference_no 對 bet_id**）：

```sql
-- TBR 有但 BR 沒（原始表漏寫）
-- ★ TBR 側時間用 {timeField}(TBR/SBR 欄位、見 Step 0);
--   BR 側 join 不限時間欄位、用 bet_id 對即可
SELECT COUNT(*), SUM(t.valid_bet)
FROM total_bet_records t
LEFT JOIN {brTable} p ON p.bet_id = t.reference_no
WHERE t.vendor_id = {vendorId} AND t.{timeField} >= '{utcStart}' AND t.{timeField} < '{utcEnd}'
  AND p.id IS NULL;

-- BR status 分佈(已結算用 {brTimeField},見 Step 0)
SELECT status, COUNT(*), SUM(valid_bet), SUM(win_lose)
FROM {brTable}
WHERE {brTimeField} >= '{utcStart}' AND {brTimeField} < '{utcEnd}'
  AND status = {SETTLED}
GROUP BY status;

-- BR status 分佈(未結算殘留必須用 bet_time + payout_time IS NULL)
--   未結算 row 通常無 payout_time;即使 vendor 的 {brTimeField}=bet_time,
--   pending 也應該用 bet_time + IS NULL 過濾,確保切口一致
SELECT status, COUNT(*), SUM(bet), SUM(valid_bet)
FROM {brTable}
WHERE bet_time >= '{utcStart}' AND bet_time < '{utcEnd}'
  AND status <> {SETTLED}
  AND payout_time IS NULL
GROUP BY status;
```

> ⚠️ 月結前查 pending 殘留**禁用** `payout_time` range — `payout_time IS NULL` 不會出現在範圍內。
> ⚠️ Step 2 / §4 所有 SQL 必須**三邊切口一致**：BR 用 `{brTimeField}`、TBR/SBR 用 `{timeField}`；vendor 卡指定例外時，兩者可能不同 — 不一致會把切口問題誤判成 BR≠TBR drift。

---

## 5. 防呆檢查清單（診斷）

### Phase A（進場前）
- [ ] 確認 vendor 三方資料時區（UTC vs SGT vs 其他，見 vendor 卡）；對帳時區的程式端設定是 APIs `config/summary_bet_record.php` 的 `reconcile_timezone`（與 UTC 的時差小時數，未設＝+8；2026-09 新增），卡片寫的時區要與它一致，不一致先查是哪邊錯
- [ ] 確認該 vendor 有沒有設 `next_day_full_rerun`（同一份 config）：有的話每日 UTC 00:05（UTC+8 08:05）會依對帳時區把「昨天」整天重跑一次（轉帳錢包先拉三方注單再彙總、單一錢包只彙總）；診斷「昨天」的差異前先確認這輪重跑已跑完，否則看到的 drift 可能只是還沒重跑
- [ ] 確認 `bet_id` 全程以 string 處理（避免 int 大數精度誤差）
- [ ] **對帳卡存在且必填齊全（Step 0 gate 通過）**；卡型已判（專屬 / aggregator）、`time_field` 取自卡片
- [ ] 多白標：確認當前 connection 是要對帳的目標環境

### Phase B（對帳輸入 + 分流）
- [ ] OP 已提供對帳 excel 數字（vendor 後台 sum_bet、sum_win_lose、筆數）
- [ ] SBR 查詢用 `data_time` 而非 `summary_time`
- [ ] TBR ↔ SBR 對齊含 `rolling_chip` / `commission` / `jackpot` 三欄（漏比這三欄 → 後台統計與會計欄位 drift）
- [ ] BR ↔ TBR 對齊同時比 `cnt` + `raw_bet` + `valid_bet` + `wl`（漏 raw_bet/cnt → 抵消重複列或 raw_bet drift 看不到）
- [ ] **成本遞增：先窮盡內部路徑（repair Phase B′ 回寫 TBR / Phase C 重建 SBR，純查我方 DB）再考慮取三方**；不要一看到 BR≠TBR 就跳 Phase D
- [ ] **BR≠TBR 先分 B-1 / B-2**：已結算 BR-only（Step 2.5 查詢1）→ B-1；未結算 BR-only（§4.3 pending 查詢）/ TBR-only / 金額 mismatch → B-2 取三方
- [ ] **B-1 分流結論需附註「回寫 TBR 前須過 BO 佐證」**：避免把 settled BR 孤兒誤判為可回寫（完整防呆規則在 repair Phase B′，本 skill 不重述）
- [ ] **三表全等仍 ≠ BO ⟹ 差異落在 BR↔vendor 邊界**（TBR/SBR 皆從 BR 衍生）→ 這是 **mismatch 查源頭**唯一該取三方的時機（結案/稽核驗證另計），在此之前別取三方；修復方向看 repair Step 7 四類差異（缺漏/孤兒/金額/歸戶），別預設只有缺漏
- [ ] **Phase C 不可用（vendor 未註冊）時，禁止用「報表口徑差」解釋 TBR↔SBR drift**；確認該 vendor 走 §Step 0-bis 三選一處置（Path 1 註冊 config / Path 2 vendor-specific patch / Path 3 known limitation 追蹤），不可靜默結案
- [ ] **改下游報表前先 grep 該 controller 的讀取層**（見 [`reference/policies.md`](reference/policies.md) §A 含證據檔）；報表呈現錯時：來源層欄位為 0 → 補來源層寫入；來源層有值 → 才是改模板場合。**SR11 屬未驗證、不可直接套「SR11=SBR」斷言**

### 跨日 / 結帳前（診斷面）
- [ ] 月結帳前先匯出 status=未結算清單（殘留處置在 repair Phase E）
- [ ] 未處置的「未結算」紀錄**不應計入月帳營收**，僅作待結算負債管理
- [ ] 跨白標部署需逐 connection 重跑（見 `reference/sum_validation_advanced.md` §5）

---

## 6. vendor 卡片索引

每 vendor 的細節（取資料來源、時區、欄位 mapping、status enum、專屬命令、已知 incident）放在 APIs repo 的 `docs/reconcile/vendors/{name}.md`（本 skill 與 repair 共用同一份）。**卡是本 SOP 的硬依賴**（Step 0 gate），缺卡或缺必填段一律 STOP、走 §6.1 onboarding。

**卡片清單以 APIs repo `docs/reconcile/vendors/` 目錄本身為準**，不在此列舉以免 drift。目錄裡另有兩份範本：`_template-dedicated.md`（專屬型）與 `_template-aggregator.md`（aggregator 型）。找不到對應卡片＝依 Step 0 判型後複製範本填寫（見 §6.1）。

| 卡型 | 怎麼認 | 卡片範本 |
|---|---|---|
| 專屬 | BR 表一表一 vendor | `_template-dedicated.md` |
| aggregator | 共用表一表掛多 vendor | `_template-aggregator.md` |

> **卡型**（Step 0 (1) 判型決定）：**專屬**＝ BR 表一表一 vendor、可直接 SUM、有 status / payout_time / valid_bet；**aggregator**＝ 共用表一表掛多 vendor（無 `vendor_id` 欄、靠 `vendor_info_id` 歸戶、BR 不能直接 SUM、TBR 由 SP 帶）。兩型範本與終驗 SQL 差異大，分兩份範本。

### 6.1 Onboarding：建立新卡（Step 0 gate STOP 時走這裡）

| 步驟 | 動作 |
|---|---|
| A 判型 | `SELECT COUNT(*) FROM vendors WHERE tname=(SELECT tname FROM vendors WHERE id={vendorId})`（>1 ＝ aggregator）；`DESCRIBE {tname}_bet_records` 看有無 status / payout_time / valid_bet |
| B 填卡 | aggregator 複製 `docs/reconcile/vendors/_template-aggregator.md`、專屬複製 `_template-dedicated.md`，逐項填 §6.2 必填契約 |
| C 驗收 | 拿一個歷史日跑通三表對齊（SUM 全等 + Step 2.5 兩查詢 0 row）才算卡建成 |

> （選用）讓 Phase C（通用 SBR 重建）可用：在 `config/summary_bet_record.php` 註冊 `vendor_id => [third_party_code, time_field]`，並於卡內 Phase C 可用性註明。未註冊不擋對帳（卡齊全即可對），只是 Phase C 不可用、TBR↔SBR drift 走 Step 0-bis 三路徑。

### 6.2 必填契約（Step 0 gate 檢查的就是這份）

缺任一必填項即 STOP。完整形式要求見範本頂部「必填契約聲明區」，下為速查：

**共用核心（兩型都必填）**：識別（`vendor_id` / `tname`→BR 表名 / `is_single_wallet`）、TBR/SBR `time_field`（須與 config 同步）、時區 + UTC 換算（須與 `config/summary_bet_record.php` 的 `reconcile_timezone` 一致）、金額口徑（`valid_bet` / `win_lose` / `jackpot`；尤其 win 是否含 jackpot）、**三方回核**（取三方注單的來源／命令 + 三方欄位→BR 欄位 mapping，供三表 SUM 對齊後逐筆確認 BR 與三方吻合；若該 vendor 無三方對源、純信任 callback，須明寫此聲明與原因，不可留空）、未結算判定謂詞、終驗 SQL（內聯可執行、不留 `{SETTLED}` 等佔位符）。

**專屬表卡專屬**：BR 有效投注篩選謂詞（可貼 WHERE 完整條件、註明是否附加排除）、BR `time_field`、status enum 全集、Phase C 可用性。

**aggregator 共用表卡專屬**：vendor 歸戶謂詞（從共用表切單一 vendor 的完整 join）、共用表特有排除條件、適用 `vendor_id` 清單 + 範例 vendor、BR 結構差異聲明（無 status / payout_time / valid_bet 時列明哪些由 SP 帶）、BR→TBR 機制（SP 名稱）、切口欄位。

---

## 7. 相關 skill 與檔案

### Skill cross-ref
- **本 skill** —— 對帳**診斷**（純讀）：Phase A 環境健檢 + Phase B 內部對齊分流，輸出分流結論
- [`repair-bet-records`](../repair-bet-records/SKILL.md) —— 對帳**修復**（寫入）：依本 skill 分流結論執行 Phase B′/C/D/E；共享本 skill 的 §0 心智模型 / §4 SUM SQL
- `list-sbr-corrections` —— SBR correction 補帳結果查詢（OP 友善 remark）
- `whitelabel-container-exec` —— 多白標 DB connection 切換協議
- `docs/reconcile/vendors/{name}.md` —— vendor 對接卡（取資料、時區、命令、status enum）

### References（本 skill 內部，需要時讀）
- [`reference/vendor_resolver.md`](reference/vendor_resolver.md)：vendor 名稱模糊時的候選查詢（mysql MCP；Step 0 模糊查 / SW vs TW 歧義）
- [`reference/sum_validation_advanced.md`](reference/sum_validation_advanced.md) — 進階 SUM 驗證、跨日 correction 分析、跨白標、誤判陷阱

### 關鍵檔案（診斷查詢用）
| 用途 | 路徑 |
|---|---|
| SBR vendor 設定 | `config/summary_bet_record.php` |
| TBR Service | `app/Services/TotalBetRecordService.php` |
| 數值運算（禁原生浮點） | `app/Helpers/Common.php` |

> 修復相關檔案（SBR 命令、recovery_logs Model、Revert 命令等）見 [`repair-bet-records` §關鍵檔案](../repair-bet-records/SKILL.md)。
