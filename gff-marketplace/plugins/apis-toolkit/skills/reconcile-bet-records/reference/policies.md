# reconcile 決策政策（按需，非主線）

> **何時讀這份**：(A) 要改下游報表、或判斷「報表少欄位該改哪一層」時；(B) 處理「未註冊 vendor 的 TBR↔SBR drift」時。日常對帳主線（環境健檢 → 三表 SUM → 分流結論）不需讀這份；遇到上述兩種特定情境才翻。

## A. 下游報表資料來源（改報表前必先 grep 該 controller）

不同下游報表 / 工具讀的層**並不一致** — 改前必先 grep 該 endpoint 的 Model 與 query。以下是 APIs repo 已 grep 確認的對照（外部系統如 SR11 屬未驗證、需後台 owner 確認）：

| 報表 / 工具 | 讀取層 | 證據 / 備註 |
|---|---|---|
| **代理後台日報、遊戲別報表、代理結算** | Layer 2 `total_bet_records` | `Agent/ReportController.php:53` 用 `TotalBetRecord::query()` — **不讀 SBR** |
| 會員 App「今日 / 昨日下注總和」 | Layer 3 `summary_bet_records` | `Frontend/BetRecordController.php:32,38`（`WHERE data_time >= ?`） |
| 會員 App 投注列表 / 統計 | Layer 3 `summary_bet_records` | `Frontend/MemberController.php:381,458`（`SummaryBetRecord::query()`） |
| 下線佣金週統計（EventV2） | Layer 3 `summary_bet_records` | `EventV2/DownlineCommissionService.php:97`（`YEARWEEK(data_time)`） |
| 會員 / 代理 SBR 關聯（`MemberAgent`） | Layer 3 `summary_bet_records` | `Models/MemberAgent.php:70`（`hasMany(SummaryBetRecord)`） |
| 後台逐筆注單查詢、客服查單、玩家詳單 | Layer 1 `xxx_bet_records` + Layer 2 `total_bet_records` | 不經 SBR；直接讀 BR/TBR |
| **SR11**（後台報表）| ⚠️ **推測讀 SBR、未在 APIs repo 驗證**（grep `SR11` 在 APIs 內只找到 setting key 與 docblock 字眼、無實際 query） | 結論前需後台 / WEB_AgentBackend owner 確認；對外口徑建議寫「依 OP 經驗 SR11 讀 SBR」、不可寫成事實 |
| OP 對帳 excel | Layer 0（vendor 後台抄表） + 後台報表抄表 | OP 把 vendor 後台 sum vs 後台報表 sum 並列；後台報表那欄具體讀哪一層依該報表而定 |
| `list-sbr-corrections` skill | Layer 3 `summary_bet_records` correction=1 列 | 補帳結果 remark；SBR 沒寫過的欄位永遠列不出來 |

> ❌ **常見謬誤**：「報表少了某欄位，改報表模板加 BR/TBR 對應欄位就好。」— 若該報表 controller 讀的就是 SBR、且 SBR 該欄位為 0，改模板只是改顯示邏輯、值還是 0。**但反過來說：若 controller 讀 TBR / BR**，改模板把該欄位顯示出來是合理的補救方向。
>
> ✅ **改報表前先 grep**：(1) 該報表 controller 的 Model 與 query；(2) 該 Model 對應哪一層；(3) 該層該欄位有寫嗎？沒寫 → 補來源層寫入；該層該欄位有值但模板沒顯示 → 才是改模板的場合。

## B. 未註冊 vendor 的 TBR↔SBR drift 處置（Path 1/2/3）

> Step 0 (2) 回「未註冊、Phase C 不可用」且跑完 Step 2 確認 BR=TBR ≠ SBR 後的三路徑決策。命名提示：用 **Path 1 / 2 / 3** 而非 (a)(b)(c)，避免與 repair Phase D Step 8 的「Type A / B / C」（補帳路徑分類）混淆。

未註冊 vendor **不直接跳過 Step 2** — 跑完 Step 2 若該 vendor 確實 BR=TBR ≠ SBR（任一指標：cnt / bet / wl / rolling_chip / commission / jackpot），依下表三選一（**路徑判定屬診斷產出；實際寫入在 repair**）：

| 路徑 | 條件 | 動作 | 風險 |
|---|---|---|---|
| **Path 1：註冊到通用 config** | vendor 的欄位寫入語意與通用 `SummaryBetRecordService` 一致（valid_bet 規則、jackpot 來源、commission/rolling_chip 計算）；接入後該 vendor 寫 TBR 的欄位語意有完整對應 | 加 `vendor_id => ['third_party_code' => '...', 'time_field' => '...']` 到 `config/summary_bet_record.php`，跑 `php artisan summary_bet_record --vendor={id} --date={Y-m-d} --dry-run` 評估 correction 規模，再實寫（實寫在 repair Phase C） | 若該 vendor 過去 SBR 由其他服務寫入（例：vendor-specific 排程、一次性 patch），註冊後通用命令會對「歷史 SBR 既有值 vs TBR 重算值」產出全段差值 correction，規模可能很大；務必先 dry-run 並回報 |
| **Path 2：寫 vendor-specific SBR patch** | 只想補單一欄位（例：jackpot），不想動其他欄位；或欄位語意與通用 service 不同需自訂計算 | 在 `app/Console/Commands/Vendor/{Name}/Patch/` 寫專屬 patch，**只寫該欄位的 correction**（base SBR row 已存在 → UPDATE；不存在 → 走 vendor-specific INSERT）；audit 寫專屬表、不寫 `bet_record_recovery_logs`（recovery_logs 是 BR/TBR 層、非 SBR 層） | patch 自帶 audit 是 ad-hoc 設計，需自寫 revert；累積過多 patch 會變成技術債 |
| **Path 3：標 known limitation + 追蹤** | Path 1 與 Path 2 都尚未評估、或暫無工時 | 在 `docs/reconcile/vendors/{name}.md` 加「Known SBR drift」段（欄位、起算日、估算累積金額），同步開工單；**對外回報需明確說「該欄位 SBR 層未補、相關下游報表會少呈現」**，不可說「對齊」 | 拖延風險：drift 持續累積、相關下游報表持續錯誤；必須有工單追蹤、不可永久遺留 |

> ⚠️ **禁止第四個選項「視為非 drift」**：未註冊 vendor 的 SBR 漏寫不是「該 vendor 不需要寫 SBR」，是「沒人寫 SBR」。會員 App 統計、下線佣金、（推測）SR11 都讀 SBR（見 §A「下游報表資料來源」對照與證據），SBR 缺欄位 = 這些報表長期錯。
>
> 處理 jackpot drift 的常見謬誤：「修報表模板把 BR.jackpot 加進顯示」— 改前必先 grep 該報表 controller 的讀取層：若讀 SBR、改模板沒用（值還是 0）；若讀 TBR / BR、改模板把欄位顯示出來才是合理補救。**SR11 屬未驗證、依 OP 經驗推測讀 SBR；行動前請先確認**。
