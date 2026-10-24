---
name: whitelabel-db-query
description: 對白標或 Dev 環境跑唯讀 SQL 分析（經 mysql MCP，唯讀強制）。當使用者說「查／統計／分析 <站> 的訂單、注單、會員」「撈一下 X 表」「跑個 SQL 看看」且不寫入時載入。🚫 artisan 命令走 whitelabel-container-exec；補帳、修資料走 repair-bet-records；對帳診斷走 reconcile-bet-records。
---

# 白標唯讀 SQL 查詢（mysql MCP）

## 三分原則

| 操作 | 路徑 |
|---|---|
| 唯讀 ad-hoc SQL / 分析 | **本 skill**：`mysql MCP` 工具直連該白標 DB（原生 tool call、無容器、唯讀強制） |
| artisan 業務命令 | `whitelabel-container-exec` skill |
| 寫入 / 補帳 / 修數據 / migrate | 既有 artisan 命令（repair-bet-records / recovery，帶審計）；本 skill 一律拒絕 |
| 純讀對帳診斷 | `reconcile-bet-records` skill |

**觸發說法**：對某白標（VWorld／TPower／Yes2Win／Alfawin77／Me77／VPlus33／WTT／VWSG）或 Dev 測試環境跑 ad-hoc SQL、資料分析、統計彙總，且不涉及寫入。例如「查／統計／分析 <白標> 的訂單、注單、會員」「對帳差異分析」「撈一下 X 表」「跑個 SQL 看看」。

## 執行方式

每個白標由獨立 MCP server 提供一個 `mysql_query` 工具，工具名含白標：

| 白標 | MCP 工具 |
|---|---|
| VWorld（含 WTT / VWSG 子站，見下） | `mcp__plugin_apis-toolkit_mysql-vworld__mysql_query` |
| TPower | `mcp__plugin_apis-toolkit_mysql-tpower__mysql_query` |
| Yes2Win | `mcp__plugin_apis-toolkit_mysql-yes2win__mysql_query` |
| Alfawin77 | `mcp__plugin_apis-toolkit_mysql-alfawin77__mysql_query` |
| Me77 | `mcp__plugin_apis-toolkit_mysql-me77__mysql_query` |
| VPlus33 | `mcp__plugin_apis-toolkit_mysql-vplus33__mysql_query` |
| Dev（測試 / 開發環境，**非白標**） | `mcp__plugin_apis-toolkit_mysql-dev__mysql_query` |

> `Dev` 在 `config/database.php` 是獨立 connection（有自己的 `DEV_DB_HOST/PORT/USERNAME/PASSWORD_READ|WRITE`，未設定時才兩層 fallback 回 `DB_*`，因此預設仍指向與 `mysql` 相同的庫）。它語意上代表共用測試 / 開發環境，不是獨立白標。它沒有 rootAgentId，不適用下方「範疇規則」的 root-scoped 要求；但同一庫混雜各種測試資料，跨代理聚合的結果不要當白標數據解讀。

以 `sql` 參數呼叫對應白標的工具。若回 `Parsing failed: …reading 'trim'`、`failed after 0s`，代表參數名傳錯，與並發或查詢內容無關。

### 連線資訊來源（dbc 代理為主，容器 config／secrets.env 只剩 dbc 未涵蓋的白標）

- 路徑由 `registry/whitelabels.metadata.yaml` 的 `dbcPort` 決定：**非 null（現況 VPlus33 以外全部）→ dbc 路徑**，`null` → 舊路徑。權威說明見 plugin `README.md`「mysql MCP 連線設定」。
- **dbc 路徑**：每個 MCP server **啟動時**（session 啟動 / `/reload-plugins`）由 `bin/mysql-mcp.js` 探測本機 `0.0.0.0:<dbcPort>` 是否在監聽（即 `dbc start` 有沒有掛著），取 gcloud 登入帳號 `@` 前段當 MySQL 使用者、無密碼，連到 SRE 的 dbc 代理；dbc 再以該帳號的 IAM 身分接 Cloud SQL **從庫**（Alfawin77 無從庫則為主庫）。**每條查詢都進 Cloud Logging `dbc-query-audit` 稽核**（帳號＋完整 SQL）。
- dbc 路徑 **fail-closed 不退舊路徑**：`dbc start` 沒掛、gcloud 未登入 → 該 server 不啟動（`/mcp` 看不到它），錯誤訊息直接說要跑 `dbc start` 或 `dbc setup`。**使用者回報 mysql-* 連不上時，第一件事是問「`dbc start` 開著嗎」**。
- Dev（`3402`）實例閒置 120 秒斷線、下一筆自動重連；正式站 28800 秒。看到第一筆查詢慢或 reconnect 訊息屬正常。
- **舊路徑（`dbcPort: null`，現況只有 VPlus33）**：wrapper 執行一次 `docker exec` 進 `php-fpm8`，用 `bin/wl-resolve-conn.php` 解析該白標 `config/database.php` 的 `.read` 座標（host/user/pass/database）；docker 環境不可用（未安裝／daemon 未連線／容器不存在）→ 改讀 `~/.gff/secrets.env`；容器**存在但未啟動** → 不 fallback、fail-closed。
- 不論哪條路徑，解析結果只存在 benborla 子程序的**記憶體**（env + 連線池），session 期間有效、結束即清；**每筆查詢**由 benborla（host 上）用連線池直連（dbc 路徑連 loopback、舊路徑連 DB host），**不再進容器**。

### 拒寫保證（不依賴 read replica）

benborla 預設把查詢包在 **READ ONLY transaction**（wrapper 強制 `ALLOW_*=false`、`MYSQL_DISABLE_READ_ONLY_TRANSACTIONS=false`）：MySQL 伺服器層對其內的寫入以 **error 1792** 拒絕，**即使連的是 `read_only=0` 主庫亦然**（已實測；dbc 路徑的 Alfawin77 `3511` 就是主庫）。這一道與 benborla 的 SQL 驗證同源於 wrapper 注入的 env，算一道；dbc 路徑真正獨立的第二道是伺服器側授權：IAM 帳號經 `db-prod-user` 角色只有 `xsgcasino`／`xsgcasino_log` 的 `SELECT`（2026-09-02 於 `3511` 主庫以 `SHOW GRANTS FOR CURRENT_USER()` 實查）。舊路徑（VPlus33）連的是 config 的 `.read` host，只有 wrapper 側那一道。

## 範疇規則

白標業務 root agent id 一律以 **registry（`../../registry/whitelabels.metadata.yaml` 的 `rootAgentId`）為準**，本段不持有；下列為日常摘要，子樹分類／污染樹 census／scoped template／查證法見後續對照段。

- **VWorld connection 是多子樹**（白標 VWorld/WTT/VWSG ＋ 26 棵污染樹）：查 VWorld / WTT / VWSG **必須**在 SQL 內套 scoped template（hierarchy 錨點 JOIN）。各白標 root 以 **registry `rootAgentId`** 為準（`mysql-vworld` 工具 + 該白標 rootAgentId 切範疇）。**光選對工具不會限縮子站**——不加 scope 的整庫聚合會混入所有子樹，須使用者明確要求整庫且結果標示「整庫（含全部子樹）」。
- TPower / Yes2Win / Alfawin77 / Me77 / VPlus33 為單一業務子樹：**觸及 member 事實的查詢（會員 / 訂單 / 注單 / SBR）預設一律以 rootAgentId 切範疇**（與 CONTRIBUTING §10「查詢一律 root-scoped」一致；Yes2Win 等四站另有 RD 測試樹 `100001`，不 scope 會混入）。僅**非租戶 metadata**（vendors / 設定 / 字典表等不掛 member 的表）或使用者顯式要求的整庫 census 可不 scope，後者輸出須標示「整庫」。
- Vendor 專屬對帳或子站查詢，若已知 `{vendor}_accounts.vendor_info_id`，優先用 `vendor_info_id` 錨定會員集合；一般跨 vendor 統計才用 hierarchy 錨點。

### 子樹分類與 raw SQL 政策

各白標 **rootAgentId / rootAgentUsername 見 registry**；下表只補子樹分類與 raw SQL 政策。查詢一律以 rootAgentId 切 hierarchy 範疇（見「Scoped template」），即可排除同 DB 的污染樹與 RD 測試樹。

| connectionId | 子樹分類 | raw SQL 政策（member 事實＝會員/訂單/注單/SBR） |
|---|---|---|
| `VWorld` | **多子樹**（白標 VWorld/WTT/VWSG ＋ 26 棵污染樹，見下） | **預設拒絕**——member 事實一律走 scoped template；整庫 census 須明確 override 並標示「整庫」 |
| `TPower` | 單業務子樹 | member 事實預設 scoped；非租戶 metadata 表可不 scope |
| `Yes2Win` | 單業務子樹（另有 `RD` 測試樹 `100001`） | member 事實預設 scoped（排除 RD）；非租戶 metadata 表可不 scope |
| `Alfawin77` | 單業務子樹（另有 `RD` 測試樹 `100001`） | 同上 |
| `Me77` | 單業務子樹（另有 `RD` 測試樹 `100001`） | 同上 |
| `VPlus33` | 單業務子樹（另有 `RD` 測試樹 `100001`） | 同上 |
| `mysql` / `Dev` | 測試庫，無白標範疇語意 | 不切租戶；輸出必須標示「測試環境資料」 |

- 政策邊界：**「不 scope」僅限非租戶 metadata（不掛 member 的表）或顯式整庫 census**；任何觸及 member 事實的 production 查詢，預設都以 rootAgentId 切範疇——單樹白標放這條只是「污染量小」，不是「可略」。
- root agent id 僅在**各自 connection 內**有意義（同一 id 數字在不同 connection 可能是不同實體，如 Yes2Win 與 Alfawin77 的業務 root 恰好同號），跨 connection 不可比對 id。
- 「查 WTT / Wintoto」「查 VWSG / VWorldSG」「查 VWorld」= 連 `VWorld` connection ＋ 以該白標 `rootAgentId`（registry）切子站範疇。光切 connection 不會限縮子站範疇。

### VWorld 長尾子樹（污染源清單）

VWorld connection 除三棵白標主樹（VWorld / WTT / VWSG）外另有 26 棵樹。整庫聚合（不加 scope）會把以下資料混入，其中**啟用中**的樹是最主要污染源（查證日期 2026-06-12，live read replica；會員數為當時快照，會漂移）：

| root id | username | 狀態 | 會員數 |
|---|---|---|---|
| 53237 | vworldvn | 停用 | 20,659 |
| 105272 | 7Shwe | 停用 | 9,595 |
| 65848 | casino365 | 啟用 | 3,747 |
| 57667 | vworldbn | 停用 | 2,850 |
| 108099 | gffusdt | 啟用 | 979 |
| 60201 | vbet | 啟用 | 878 |
| 38185 | elgadmin | 停用 | 197 |
| 67036 | PGSHKcredit | 啟用 | 117 |
| （其餘 18 棵） | — | — | 各 ≤ 53 |

### Scoped template

**Collation 前提**：`hierarchy_path` 比對與 `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` 一致；mysql MCP server 連線即以 utf8mb4 建立，查詢 SQL 不需自帶 `SET NAMES`（單筆 `mysql_query` 一語句，勿塞多語句 prologue）。

範疇鏈：`<事實表>.member_id → members.id`、`members.parent → agents.id`、`agents.hierarchy_path` 前綴判根。

```sql
-- 子樹會員集合（<ROOT_ID> 代入 registry 的該白標 rootAgentId；root 的 hierarchy_path 由 DB 衍生、不硬寫）
SELECT m.id
FROM members m
JOIN agents ag   ON ag.id   = m.parent
JOIN agents root ON root.id = <ROOT_ID>
WHERE (ag.hierarchy_path = root.hierarchy_path
   OR  ag.hierarchy_path LIKE CONCAT(root.hierarchy_path, '/%'));
```

- **root 的 `hierarchy_path` 一律由 DB 衍生**（`JOIN agents root ON root.id = <ROOT_ID>` 取 `root.hierarchy_path`），**不硬寫 `/1/<ROOT_ID>`**：rootAgentId 不保證 Admin 直屬（例 VWorld `rootAgentId=66829` 的 path 是 `/1/33893/66829`），硬寫 `/1/66829` 會 miss 整棵子樹、查詢回空。
- 兩個條件缺一不可：`= root.hierarchy_path` 涵蓋直掛 root 的會員，`LIKE CONCAT(root.hierarchy_path, '/%')` 涵蓋子孫。
- `CONCAT(..., '/%')` 的分隔斜線不可省：否則 `/1/33893/66829` 會誤匹配 `/1/33893/668290` 這類同數字前綴的別樹。同表同欄比對（`ag` vs `root` 皆 `agents.hierarchy_path`）collation 一致，不需 `COLLATE`。

事實表套用（SBR / TBR）：

```sql
-- 例：某子樹單日 SBR 聚合
SELECT sbr.vendor_id, COUNT(*) AS cnt, SUM(sbr.bet) AS bet, SUM(sbr.win_lose) AS win_lose
FROM summary_bet_records sbr
JOIN members m    ON m.id    = sbr.member_id
JOIN agents  ag   ON ag.id   = m.parent
JOIN agents  root ON root.id = <ROOT_ID>
WHERE (ag.hierarchy_path = root.hierarchy_path OR ag.hierarchy_path LIKE CONCAT(root.hierarchy_path, '/%'))
  AND sbr.summary_time >= '<DATE> 00:00:00'
  AND sbr.summary_time <  '<DATE+1> 00:00:00'
GROUP BY sbr.vendor_id;
```

（時間欄位依查詢目的選 `summary_time` 或 `data_time`；`total_bet_records` 同樣以 `member_id` 接 scope JOIN。）

vendor_info 錨點（對帳實務替代）：vendor 專屬查詢可改以 `{vendor}_accounts.vendor_info_id` 一次錨定整個子站的會員集合（mapping 機制與例值見 `whitelabel-container-exec` skill §1-2，例：WTT × PTSW → `vendor_info_id = 615`）。對帳場景此錨點比 hierarchy JOIN 更直接；一般統計仍以 hierarchy 錨點為通用解。

### 查證方法（污染樹異動時重跑）

逐 connection 執行下列查詢（read replica），按 root agent 分組統計會員數；會員數 ≧ 千級且狀態啟用者視為業務子樹，其餘為內部 / 測試樹：

```sql
SELECT root.id AS root_id, root.username, root.status, COUNT(m.id) AS member_count
FROM members m
JOIN agents ag   ON ag.id   = m.parent
JOIN agents root ON root.id = CAST(SUBSTRING_INDEX(SUBSTRING(ag.hierarchy_path, 4), '/', 1) AS UNSIGNED)
GROUP BY root.id, root.username, root.status
ORDER BY member_count DESC;
```

判定依據：子樹 root = `agents.parent = 1`（Admin 直屬），`hierarchy_path = '/1/<root_id>'`。`parent = 1` 的代理多數是內部 / 測試帳號（VWorld 有 75 個），故子樹分類以**會員數分布**為準，不以 root 數量為準。任一成立即重跑：registry 新增白標 / 子站、既有 connection 出現新的千級會員子樹、總代理 id / username 異動。

## 查詢紀律

- 先定範疇，再寫 SQL：白標、子站、整庫、時間範圍若不明，先問清楚；不要用「可能是」的 scope 直接查生產資料。
- 大表先縮範圍：SBR / TBR / orders / logs 類查詢預設要有時間區間；時間條件用半開區間（`>= start` 且 `< end`），避免 `DATE(column)` 這類讓索引失效的寫法。
- 聚合先於明細：先用 `COUNT` / `GROUP BY` / `SUM` 看規模；明細查詢只選必要欄位，預設 `LIMIT 50`，禁止對大表 `SELECT *`。
- 重查詢先 `EXPLAIN SELECT`：跨多日、含多表 JOIN、或可能掃大表時，先看執行計畫；若疑似全表掃描，先改 SQL，不直接跑。
- 分頁用 keyset：追明細時用 `WHERE id > <last_id> ORDER BY id LIMIT <n>` 這類穩定游標，避免大 offset。
- 多白標對比逐站查：同一 SQL 逐 connection 執行，回覆中並列表格；不要把不同 connection 的 root id / vendor id 當成可互相比對的同一語意。
- 「No response from API」多為連線層，非查詢問題：MCP server `Connection closed`（MCP error -32000）或啟動 `timed out`（容器/連線慢）；確認 `php-fpm8` up、必要時 `/reload-plugins` 即可，不要往「並發/序列化」方向誤判。

### 拿到 ID 先驗前提

排查帶具體會員、訂單或帳號時，先確認前提成立再展開。前提不成立時查出來的帳目都是假的。

- 第一個查詢先確認這個對象存在，而且落在使用者說的白標（例：`SELECT id FROM ptsw_accounts WHERE username = ?`）。查到 0 筆就停下，回報前提對不上，不換別的庫硬湊。
- 帳號前綴只是「先去哪個庫找」的線索，不是歸屬規則。歸屬以目標庫實查為準。
- 排查深度（只查內部資料，還是要向三方取資料）等前提確認之後才問。
- 查詢結論要請營運、PM、三方或 RD 處理某張單、某個代理組別或某條支付通道之前，先查它是不是測試，並在敘述裡標明「正式」或「測試」：
  - 代理組別：`agent_groups.name` 含 `test`（不分大小寫）就是測試組別。代號第一次出現時附上組別名稱。
  - 會員或代理：沿 `members.parent` → `agents.hierarchy_path` 看它是否落在 registry 該白標 `testRootAgentId` 的子樹，或 Yes2Win、Alfawin77、Me77、VPlus33 的 RD 測試樹 `100001`。root 的 path 照「Scoped template」由 DB 衍生。
  - 代理名含 `test` 是測試訊號。代理名含 `bot` 不是測試（例：Telegram 導流線）。
  - 會員是不是測試，以上面的 `testRootAgentId` 子樹為準。有沒有充值與取款紀錄只是輔助訊號：看有沒有已上分的充值訂單（`orders.status = 3`）或已出款的取款訂單（`withdrawal_orders.status IN (4, 24)`），不能只看 `members.first_deposit`，這欄可能整條代理線都是空的。

## 回應紀律

- 環境標示沿用 `whitelabel-container-exec` 的雙層標示（回應層 `🔌 當前環境` + 命令層 `🔌 [<白標>]`）；查 WTT 時於結果註明子樹 root（取自 registry `rootAgentId`）。
- 結果先講邊界：白標 / 子站 scope、時間範圍、是否 sample / limit、是否整庫。VWorld / WTT / VWSG 整庫查詢必須明示「整庫，含全部子樹」。
- 結果先給結論，再補查詢摘要與必要 caveat；不要貼大量 raw rows，除非使用者明確要求明細。
- 若查詢被改窄（加 limit、縮日期、改 sample），回覆要明講這是為了先確認資料形狀或避免壓生產，並說明下一步要放大的條件。
- **PII 一律遮蔽**：真實會員資料進回覆前先遮蔽——`username` 保頭尾（`Az****69`）、手機 `0912***456`、Email `a***@d***.com`、身分證 `A12****789`、銀行帳號／卡號只留末 4 碼、真實姓名只留姓。`member_id` / `agent_id` / 訂單號 / `Tid` 是查修必需的內部識別碼，**照原樣給、不要遮**（遮了等於讓後續查不下去）。示範 SQL 用明顯假值。
- **含 PII 的明細不落檔**：遮蔽後的 raw rows 只在回覆中呈現；不寫入 `docs/`、HTML 報告、artifact 或外部服務（含魔鏡）。需要落檔時先問，且只落聚合結果（`COUNT` / `SUM` / `GROUP BY`）。

## MCP 回傳與語法的兩個坑

1. 🔴 **回傳的 datetime 一律比真實值少 8 小時**：MCP 假設 DB 字面是台北時間再轉 UTC 顯示，但本專案各表字面值實為 **UTC** ⇒ **讀回傳值要 +8h 才是真實 UTC**（再 +8h 才是台北）。下 `WHERE` 條件時直接用真實 UTC 字面值，**不要拿回傳的顯示值回填**。曾因此把 UTC 08:19（台北下午 16:19）誤讀成台北早晨 08:19，事件定性從「排程窗口」翻成「人工操作時段」。
2. **SQL parser 吃不下 `JSON_TABLE(...)` 與 CTE（`WITH ... AS (...)`）**：症狀是 `Error: Parsing failed: Expected [A-Za-z0-9_$...] but "(" found.`，**跟 SQL 本身合不合法無關**（同一條在 DB 裡跑得動），也不會提示卡在哪一段。`JSON_EXTRACT()`／`JSON_UNQUOTE()`／一般子查詢／視窗函數都吃得下，只有那兩種會炸。

   要在 SQL 內展開 JSON 陣列時，改用視窗函數生序號表 join 上去、以 `CONCAT` 組 JSON path 逐 index 取值：

   ```sql
   SELECT acf.id,
          JSON_UNQUOTE(JSON_EXTRACT(example.invalid, CONCAT('$.available_banks[', n.i, '].BankCode_ELG'))) AS code
   FROM agent_cash_flows acf
   JOIN (SELECT ROW_NUMBER() OVER () - 1 AS i FROM information_schema.COLUMNS LIMIT 250) n
     ON n.i < JSON_LENGTH(JSON_EXTRACT(example.invalid, '$.available_banks'))
   ```

## 常見錯誤

| 錯誤 | 修正 |
|---|---|
| 對 VWorld / WTT / VWSG 跑無 scope 的整庫聚合 | 先套 scoped template；整庫需使用者明示 + 結果標示 |
| 用本 skill 跑 UPDATE / 補帳 | 寫入修復走 `repair-bet-records`、artisan 走 `whitelabel-container-exec`（benborla 會直接 error、伺服器層 1792 再擋） |
| 跨白標比對 root agent id | root id 僅在各自 connection 內有意義 |
| MCP 工具 fail-closed 不啟動 | dbc 白標（VPlus33 以外）：多半是 `dbc start` 沒掛著或 gcloud 未登入，先問使用者「`dbc start` 開著嗎」；VPlus33：`php-fpm8` 容器未啟動或 config 解析失敗，先確認容器 up |
