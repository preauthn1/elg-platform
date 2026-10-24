---
name: op-ticket-create
description: target/op_tickets (project 492) 線上問題工單建單助手。當維運/客服說「幫我開 OP 單 / 整理截圖開單 / OP 線上問題」或貼多張 TG 截圖未指明工單號時自動載入，解析截圖後透過 GitLab MCP 直接建單（含上傳截圖），無 MCP 時改出純文字草稿讓使用者手動貼。description 第一行強制 `**TG 群**：xxx`（必填，方便開發回查原始提問者）。所有會寫進 GitLab 的操作必須先經使用者確認。🚫 開發要讀單、解析既有工單、貼 op_tickets 網址走 `op-ticket-read`。
---

# OP 工單建單（target/op_tickets）

一句話版本：**維運或客服手上有一堆 Telegram 截圖，幫他們整理成一張格式正確的 GitLab 工單並直接建立（建單模式）。任何會寫進 GitLab 的動作，都先給使用者看過、點頭才做。**

> 目標 project：`http://example.invalid:1688/target/op_tickets`（id `492`）。
>
> 與 `pm-ticket-create` skill 的分工：那支管 GFF 新需求單（project 365）；本 skill 管 OP 線上問題單（project 492）。
>
> 與 `op-ticket-read` skill 的分工：開發收到既有的 OP 單要讀懂、排查，走那支；本 skill 只管開新單。

## 名詞速查

| 名詞 | 意思 |
|---|---|
| OP 單／op_tickets | 線上問題單，放在 GitLab 專案 `target/op_tickets`（project 492）。客服或維運回報「玩家遇到問題」的地方 |
| TG | Telegram。客服與站方的對話多半發生在 TG 群 |
| MCP | 讓 Claude 直接呼叫外部服務的接口。本 skill 透過 `gitlab-mcp` plugin 提供的 GitLab MCP 建單，工具名稱以 `mcp__plugin_gitlab-mcp_gitlab__` 開頭 |
| PAT | personal access token，登入 GitLab API 用的個人金鑰，由 `gitlab-mcp` plugin 設定與注入，本 skill 不自己拿 |
| iid | 工單在該 project 內的編號，也就是網址最後或 `#N` 的那個數字 |
| issue／work item | GitLab 對同一張單的兩種呈現方式。網址可能顯示 `work_items/N` 或 `issues/N`，讀取一律用 issue 的工具 |
| label | GitLab 工單上的標籤，例如優先級 `.P1 高優先(24小時)`、站台 `站方平台:TPOWER` |
| assignee | 工單指派給哪位開發 |
| vendor_id | 遊戲商在我方 `vendors` 表的數字 id |
| domain | 問題屬於哪個領域（金流、遊戲商、銀行、獎勵……），用來決定下一步走哪支 skill |
| 草稿模式 | 沒有 GitLab MCP 或建單失敗時的退路：把整理好的標題、內文、標籤印成純文字，由使用者手動貼進 GitLab 網頁 |
| markdown ref | 截圖上傳到 GitLab 後回傳的一段文字（形如 `![image](/uploads/<hash>/xxx.png)`），貼進工單內文就會顯示圖片 |

---

## 前置條件

| 模式 | 必備 | 平台限制 |
|---|---|---|
| 建單模式 | 已安裝並設定好 `gitlab-mcp` plugin（PAT 由該 plugin 注入）；網路通到 `example.invalid:1688`（內網或 VPN）；Claude Desktop（或其他能跑 GitLab MCP 的 client） | 不限 OS。若沒裝 gitlab-mcp 會自動改走草稿模式 |

> 建單統一走 GitLab MCP（`mcp__plugin_gitlab-mcp_gitlab__*` tool）。PAT 設定見 `gitlab-mcp` plugin 的 README。

新環境第一次跑前若 `/mcp` 看不到 `gitlab` server，依 gitlab-mcp README 設定 PAT 並 `/reload-plugins` 後再回來。

---

## 建單硬規則（每一張 OP 單都適用，不分開單來源）

下面兩條**不是建單模式專屬**。維運轉單、客服轉單、開發自己開的技術單、把舊單尾巴轉出去的接手單，全都要遵守。

### 🔴 規則一：一定要標優先級

**每一張 OP 單都必須帶 `.P0`～`.P3` 其中一個標籤。** 沒標就是漏了，不是「之後再補」。

標籤字串**有前導點、括號是半形、括號前不留空白**，`labels` 參數要一字不差：

| 要帶的完整字串 | 承諾處理時間 |
|---|---|
| `.P0 緊急(四小時內)` | 4 小時 |
| `.P1 高優先(24小時)` | 24 小時 |
| `.P2 中優先(3個工作日)` | 3 個工作日 |
| `.P3 低優先` | 未定 |

選哪一級等於**對處理時限做出承諾**，不是「感覺有多急」。判準與各級語意見 [reference/label-conventions.md](reference/label-conventions.md) 第 1 節。

⚠️ **`update_issue` 的 `labels` 是覆蓋不是追加**：補標籤時要把原有的標籤一起帶上，否則會被清掉。

> 這條規則要守的是「開發直接建單」這條路徑：`target/op_tickets` 上漏標優先級的單，**都出在開發自己開的技術單**；維運與客服開的單都有標。

### 🔴 規則二：標題提到遊戲商就要附 vendor_id

標題只要出現遊戲商名稱，**後面就要加上該遊戲商的 `vendor_id`**，格式為半形括號：

```
PTSW (144) 已退款局再收關局回調會改成 SETTLE…
Acewin (110) 撤單被我方以「注單已成立」全數拒絕…
SpadegamingSW (81) 重複撤單造成的超退追回…
```

**為什麼**：同名或近名的遊戲商很容易指錯人。實際踩過的兩個例子：`SpadeGaming`(81，單一錢包) 與 `SpadeGaming-T`(47，轉帳錢包) 是兩支不同的接入；`RG`(250，`tname` 為 `rgsw`) 與 `RG Slot`(94，`tname` 為 `evo`) 則完全不相干。只寫名字，收單的人會查錯表。

**id 從哪裡來**：查該白標的 `vendors` 表，別憑印象寫。

```sql
SELECT id, name, tname FROM vendors WHERE tname = '<vendor tname>';
```

⚠️ **一個 `tname` 可能對到多個 `vendor_id`**。例如 `tname='tthsw'` 底下有 18 個 vendor_id（178、215-222、225-231、251、257-259），因為那是一個聚合商、底下掛多家子品牌。這種情況寫「基底 id ＋ 數量」，例如 `TTHSW (178 等 18 個)`，不要只寫一個 id 讓人誤會範圍只有那一支。

---

## 0. 確認是建單（進入 skill 第一步）

依輸入訊號自動判斷：

| 訊號 | 走哪支 |
|---|---|
| 訊息含「開單」「整理截圖開單」「OP 線上問題」「客服反饋」 | 本 skill |
| 使用者貼 ≥ 1 張截圖且**未提工單號** | 本 skill |
| URL `target/op_tickets/-/issues/<iid>` 或 `work_items/<iid>`、「op #N」「解析這張單」 | `op-ticket-read` |
| 使用者貼截圖且**已提工單號** | `op-ticket-read`（截圖是補充資訊） |
| 兩者皆不確定 | AskUserQuestion 詢問 |

判斷完成後**明確告知使用者當前模式**：
> 「進入建單模式，請繼續貼齊所有截圖，並告訴我這是從哪個 TG 群收到的反饋。貼完輸入『開始』。」

---

# 建單模式（Mode B）

> 給維運、客服、PM 用：把 N 張 TG 截圖變成一張建好的 GitLab 工單。
>
> **設計訴求**：讓使用者在 Claude Desktop 全程完成「截圖貼上 → 工單建立」，不切到 GitLab 網頁。
>
> **技術前提**：Claude Desktop 沒有 Bash 工具，必須**透過 GitLab MCP** 呼叫 API。詳見 [reference/mcp-setup.md](reference/mcp-setup.md)。

## 建單第 1 步：環境檢測（B1）

進入建單模式的第一動作：列出當前可用的 MCP tool，識別是否有 GitLab MCP（tool 名稱通常含 `gitlab`、`mcp__plugin_gitlab-mcp_gitlab__*` 或類似前綴）。

> 建單主路徑要的 MCP 來自 **`gitlab-mcp` plugin**（本 marketplace 內，本 skill `dependencies` 已宣告）。新環境若 `/mcp` 看不到 `gitlab` server：
> 1. 確認 `/plugin install gitlab-mcp` 跑過、PAT 在 prompt 時有填入
> 2. `/reload-plugins` 後 `/mcp` 再看一次
> 3. 仍無 → 走草稿模式（建單第 6 步的 6.2.a）並提醒使用者補裝

- ✅ **有 GitLab MCP** → 進入 **MCP 模式**（建單第 5 步之後走主路徑）
- ❌ **無 GitLab MCP** → 進入 **草稿模式** ＋ 提示如何設定 MCP

判斷規則：**只認真實存在的 tool**，不憑印象斷言「應該有」。若使用者自行確認 MCP 已配置但 skill 找不到 tool，先請使用者重啟 Claude Desktop 或檢查 MCP 設定。

## 建單第 2 步：收集輸入（B2）

1. 詢問是否還有截圖要貼（讓使用者一次貼完，避免多輪解析浪費 token）
2. **強制詢問 TG 群名稱**（必填）：
   > 「請告訴我這是從哪個 TG 群收到的反饋？例如 `TPOWER-運維群`、`V-World 站方群`、`yes2win 客服群`」
3. 接受可選的補充文字（站方反饋、玩家訴求、補充背景）
4. 等使用者輸入「開始」或「整理好了」再進入解析

## 建單第 3 步：截圖視覺解析（B3）

依 [reference/screenshot-extraction.md](reference/screenshot-extraction.md) 對每張截圖**分類**：

| 類型 | 萃取目標 |
|---|---|
| TG 對話 | 發言者角色、時間、訴求、玩家 id |
| 後台畫面 | 訂單號、玩家 id、金額、時間、狀態 |
| 玩家投訴文字 | 現象描述、期望 |
| 遊戲畫面／錯誤彈窗 | error code、發生時點、遊戲商 |
| 其他 | 標記 `需手動釐清` |

逐張產生簡述（用於 description 附件清單）：
`[截圖 1] TG 對話：客服 william 於 5/22 10:05 反饋活動 V2 玩家 abc123 可重複領獎`

## 建單第 4 步：欄位整合與補齊（B4）

把所有截圖萃取結果合併為單一工單欄位（依 [reference/parse-schema.md](reference/parse-schema.md)）：

```
tg_group [必]      ← 第 2 步已詢問
tg_contact [必]    ← 從截圖識別發言者（client 端 telegram username）
station [必]
vendor [選]
player_id [選]
order_no [選]
time_range [選]
symptom [必]
expected [必]
domain [必]
```

任一**必填**欄位缺失 → AskUserQuestion 補齊。
非必填欄位缺失 → 留 `-`，不要編造。
寫「現象」「期望」時，幣別、代理組別、金額換算這類平台名詞用正名：有 APIs checkout 就以其根目錄 `GLOSSARY.md` 詞彙表為準、避開它列為避免使用的別名；沒有 checkout（例如 Claude Desktop）就照客服原詞，不猜正名。

用 AskUserQuestion 一次問完分類欄位：

| 問題 | 預設備選 |
|---|---|
| 優先度？ | `.P0 緊急(四小時內)`／`.P1 高優先(24小時)`／`.P2 中優先(3個工作日)`／`.P3 低優先` |
| 問題類別？ | 上分訂單／下注錯誤／代付訂單／分數／多次開洗分／平台數據／投注是否正常／洗分審核／流水／紅包／調整系統／金額在三方／金額顯示 |
| 預設 assignee？ | 依 domain 推薦，對照 [../op-ticket-read/reference/routing-rules.md](../op-ticket-read/reference/routing-rules.md)「Assignee 推薦規則」節；不確定 domain 時預設 `leah`，由 PM 二次分派 |

完整 label 對照與指派個人 label 見 [reference/label-conventions.md](reference/label-conventions.md)。

## 建單第 5 步：建單預覽與確認（B5，強制）

套用 [reference/draft-template.md](reference/draft-template.md) 產生**預覽**：

```
========== OP 工單預覽 ==========
標題：[<站台>] <主題（10-30 字）>

labels：
  - <.P? 優先度>
  - 站方平台:<X>
  - 遊戲商:<Y>（如適用）
  - 問題類別:<Z>（如適用）

assignee：<開發 username>

----- description（GitLab body）-----
**TG 群**：<tg_group>
**聯絡人**：<tg_contact>
**玩家**：<player_id>
**站台**：<station>
**時間**：<UTC+8 timestamp>
**現象**：<萃取後的清晰描述>
**期望**：<玩家期望結果>

**附件**：
- 截圖 1：<簡述>
- 截圖 2：<簡述>

<details><summary>客服原始對話</summary>
<逐字 transcript，按時間順序>
</details>
-----

❓ 確認動作？
  1. 建立（透過 MCP 直接建單 + 上傳截圖）
  2. 修改：哪個欄位？
  3. 取消
```

**等使用者文字回 1／2／3 才進入下一步。任何寫操作禁止跳過確認。**

## 建單第 6 步：執行建單（B6）

### 6.1 MCP 模式（主路徑）：先上傳截圖、再建單，禁止反過來

⚠️ **寫入順序鐵則**：先上傳所有截圖取得 markdown ref，**全部上傳成功後**才呼叫 `create_issue`。
- `upload_markdown` 是以 project 為範圍的上傳，**不需要先有 issue**
- 任何上傳失敗就 **中止，沒有任何 issue 被建立**，可以乾淨重試
- 禁止「先 create 再補 description」的反向流程：create 成功後若上傳或補寫失敗，會留下半成品工單且無法復原

#### 流程（依序執行，任一步失敗即中止）

1. **預上傳所有截圖**（issue 尚未建立）
   - 對每張截圖呼叫 `upload_markdown` tool（`project_id`=492 `target/op_tickets`；`file_path`=本地圖片路徑）
   - 取回 `markdown` 欄位（形如 `![image](/uploads/<hash>/filename)`）
   - **任一張上傳失敗 → 中止，不建單**，顯示哪幾張失敗 ＋ 本地路徑，請使用者重試或調整截圖
2. **嵌入 markdown 到 description**
   - 將步驟 1 取回的所有 markdown 字串替換 description 內的 `<附件占位符>` 或插入「**附件**」段落
   - description 完成後**不再變更**
3. **單次 `create_issue` 寫入**（一次到位，不分段）
   - 傳 `project_id=492`／`title`／完整 description／`labels`（名稱 array）／`assignee_ids`（user id；`create_issue` 只收 id，username 先用 `list_project_members` 換 id）
   - **失敗時不重試 create**（避免重複工單）：回報 error，請使用者檢查後手動重試或走 6.2 草稿模式
   - 成功時回報 issue URL ＋ iid

#### 失敗處理速查

| 失敗點 | 行為 | 為何 |
|--------|------|------|
| 步驟 1 任一張上傳失敗 | 中止整個流程，不建單 | 此時尚無任何 issue，安全重試 |
| 步驟 3 create_issue 失敗 | **不要重試 create** | 重試會造成重複工單；已上傳但沒用到的檔案是次要問題 |
| 步驟 3 失敗後使用者想救回上傳 | 走 6.2 草稿模式：把已上傳的 markdown ref 列給使用者，手動貼到網頁 | 不再嘗試 MCP 寫入 |

#### 完成後回報

```
✅ 工單已建立
   URL:  http://example.invalid:1688/target/op_tickets/-/issues/<iid>
   iid:  <iid>
   截圖: 已上傳 N 張，已嵌入 description
   labels: <列出>
   assignee: <username>
```

### 6.2 草稿模式（退路）

兩種觸發情境：
- (a) **完全無 GitLab MCP**：一開始就走草稿模式
- (b) **6.1 步驟 3 create_issue 失敗**：步驟 1 已成功上傳的 markdown ref 要保留，避免使用者重新上傳

#### (a) 完全無 MCP

1. 輸出第 5 步的預覽純文字
2. 提示使用者：
   ```
   ⚠️ 偵測不到 GitLab MCP，改用草稿模式
   請手動完成：
     1. 開啟 http://example.invalid:1688/target/op_tickets/-/issues/new
     2. 複製上方標題與 description
     3. 逐張上傳截圖（GitLab 網頁拖拉）
     4. 套用 labels / assignee
   或參考 reference/mcp-setup.md 配置 MCP 後重試
   ```

#### (b) 從 6.1 步驟 3 失敗退回

1. 輸出已準備好的 description（**已含步驟 1 上傳取得的 markdown ref**）
2. 提示使用者：
   ```
   ⚠️ 建單 API 失敗：<error>
   截圖已先上傳成功（避免重做），請手動完成最後一步：
     1. 開啟 http://example.invalid:1688/target/op_tickets/-/issues/new
     2. 複製上方完整 description（圖片連結 /uploads/<hash>/... 已內嵌，貼上後 GitLab 會自動渲染）
     3. 套用 labels / assignee：<列出>
   ```
3. **不要**再次嘗試 MCP create：一次失敗後改走純手動

## 建單第 7 步：紅線（B7）

- 🚫 **未確認不寫**：第 5 步確認環節未通過時禁止任何 API 寫操作
- 🚫 **不臆測截圖看不清楚的內容**：標 `需手動釐清`
- 🚫 **不編造玩家 id／訂單號**：若截圖模糊或關鍵字無法辨識，明確說「截圖中此欄無法辨識」
- 🚫 **tg_group 不允許跳過**：使用者試圖跳過時要再次強調「沒有 TG 群名，開發收單後無法找你」
- 🚫 **沒有 `.P0`～`.P3` 不建單**：優先級是必填，使用者沒指定就用 AskUserQuestion 問到為止（見上方「建單硬規則」規則一）
- 🚫 **標題有遊戲商卻沒有 vendor_id 不建單**：先查 `vendors` 表補上（見上方「建單硬規則」規則二）
- ✅ 玩家敏感資料（手機／真名）保留原貌（OP 工單內部使用），但避免在聊天中重複大量列出

---

## 副作用界線

- ✅ 建單模式透過 MCP 建立**新**工單前必須使用者確認
- ✅ 建單模式**絕不**改既有工單

---

## 錯誤處理

| 狀況 | 行為 |
|---|---|
| 建單：截圖模糊 | 明說「截圖 N 中 XX 欄位無法辨識，請提供更高解析度或文字補充」 |
| 建單：必填欄位無法萃取 | AskUserQuestion 補齊（不允許跳過） |
| 建單：截圖上傳失敗（步驟 1） | 中止整個流程、**不建單**，標明哪幾張失敗 ＋ 本地路徑，請使用者重試或調整截圖 |
| 建單：MCP create_issue 失敗（步驟 3） | **禁止重試 create**（避免重複工單）：把 error 回傳，已上傳的 markdown ref 列給使用者走 6.2 草稿模式手動貼回網頁 |
| 建單：禁止「create 後補 description」反向流程 | 上傳必須早於 create，遵循 6.1 鐵則 |

---

## 觸發詞表（完整）

### 建單模式
- 「幫我開 OP 單」「我要開單到 op_tickets」「OP 線上問題」
- 「整理這幾張截圖成工單」「整理 TG 對話開單」「客服反饋」
- 維運貼 ≥ 1 張 TG 截圖且未提既有工單號

❗️ 讀單、解析既有 OP 單 → 走 `op-ticket-read`。含「[GFF]」「拆子任務」「target/tickets」→ 走 `pm-ticket-create`，不是本 skill。
