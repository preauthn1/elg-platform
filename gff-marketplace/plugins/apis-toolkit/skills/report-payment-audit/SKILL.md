---
name: report-payment-audit
description: 金流商深度稽核、風險發現的處理進度維護與風險發現報告產出，不改程式。當使用者說「審查/稽核金流商」「README 跟 Provider 實作對不對」「查金流商三方文檔有沒有跟實作不符」「查金流商風險」「audit 金流商」時走審查模式；說「回填工單」「這幾筆拍板不修」「標已修」「更新風險發現的處理狀態」時走更新狀態模式；說「產風險發現報告」「更新風險報告」時產報告。
---

# 金流商風險稽核與風險發現報告

**一句話版本**：逐家把「文件說的」對照「程式實際做的」，找出缺陷後分級、分類，寫進 APIs repo 的風險發現資料檔；修復進度（工單、拍板、已修）也記在同一個資料檔；報告是從資料檔產生、放在 APIs 報告中心的 HTML。

## 名詞速查

| 名詞 | 意思 |
|---|---|
| 資料檔 | APIs repo 的 `docs/reports/data/payment-risk-findings.json`，風險發現的唯一資料來源。格式與寫入規則見 `reference/findings-schema.md` |
| 發現 | 資料檔裡的一筆缺陷紀錄，有固定編號 `PRF-NNN` |
| 嚴重度 | 真實 bug、文檔失準、已知限制三級，判準見下方「發現分級」 |
| 問題分類 | 12 類，與 APIs 的 `docs/金流接入流程/附錄-常見缺陷類型.md` 一一對應 |
| 處理狀態 | 待處理、已開單、拍板不修、已修 |
| 共用層 | 不屬於單一金流商的共用程式（`app/Services/Payment/Traits/`、`WithdrawalPayoutService` 等）。它的發現記在資料檔的 `shared`，不算一家金流商 |
| 報告 | APIs repo 的 `docs/reports/payment-risk-findings.html`，在報告中心瀏覽。產法見 `reference/report-html.md` |
| 冪等 | 同一個回調送很多次，結果都跟只送一次一樣，不會重複上分或重複改狀態 |
| 提款單狀態 | 21＝已送出、結果還不知道；22＝金流商已受理、等回調；24＝付款成功；29＝金流商拒絕，可以重新發起代付 |

## 三種模式

| 模式 | 觸發 | 做什麼 | 會寫什麼 |
|---|---|---|---|
| 審查 | 稽核、審查、查風險 | 逐家對照文件與程式，記錄發現 | 資料檔的稽核內容 |
| 更新狀態 | 回填工單、記錄拍板、標已修 | 不重審程式，只改處理狀態 | 資料檔的 `status`、`tickets`、`decision` |
| 產報告 | 產或更新風險發現報告 | 讀資料檔寫出報告 HTML | APIs repo 的 `docs/reports/payment-risk-findings.html` |

三種模式都**不改程式**。要修程式走 `git-commit`、要發布走 `release-workflow`。改資料檔與報告屬於改 APIs repo，同樣要先開工作分支，照 APIs 的 Git 工作紀律。

---

## 審查模式

### 審查範圍

由使用者指定：單一家、多家（逗號分隔）、`all`（`config/payment.php` 註冊的全部）、`remaining`（扣掉資料檔裡已審過的）。沒指定先問清楚。

### 前置：檔案在哪

- 程式碼：`app/Services/Payment/Providers/{Name}/`（master）
- 三方規格：`docs/integrations/payments/{Name}/api.md`（廠商原文整理）＋ `integration.md`（我方已驗證的落差與踩坑，有就必讀）
- 既有發現：資料檔裡該家的節點。重審時以它為底，照 `reference/findings-schema.md` 的寫入規則更新，不整家覆蓋。

### 輔助參照

以下都當**起點線索**，不是結論，仍要回現行程式確認：

- `docs/reports/payment-providers-report.html`（金流商盤點報告，用 `/payment-report` 或容器內 `php artisan report:payment-providers` 重建）：「白標使用對照」決定審查優先序；「回調邏輯」一節的各家成功觸發條件與狀態判定風格，可直接拿來找代付與代收狀態判定的嫌疑家。
- `docs/integrations/payments/代收回調盤點.md`：每家代收回調靠什麼確認來源、簽名含不含金額、金額不符怎麼處理。標「AI 產生、尚未人工逐家覆核」。
- `docs/金流接入流程/附錄-常見缺陷類型.md`：12 類缺陷的現象、成因、正確做法、怎麼發現。發現的分類與修法建議直接對照它。

本 skill 和 `/payment-report` 的分工：判讀準則、風險定義、逐家深審在本 skill；`/payment-report` 只負責重建盤點報告。準則變更時先改本 skill，`payment-callback-analysis` workflow 再跟進。

### 一次性：先確認共用基底行為

讀 `app/Services/Payment/Traits/DepositCallbackTrait.php` 的 `updateDepositOrderSuccess` 與 `WithdrawalCallbackTrait.php` 的 `validateCallbackAmount`：

- 代收：傳入的回調金額不是 `null` 時，以 `Common::numCompare(..., 2)`（精度 2 位小數）和存款單金額比對；傳 `null` 會跳過比對，這正是附錄第六類「缺欄位時不比對」的成因。
- 代付：以 `Common::numCompare` 比對，精度是參數、預設 2，要看呼叫端有沒有傳別的值。
- 兩者都**不以回調金額覆寫訂單金額**。各家自己在回調裡改寫存款單金額的，要另外看。
- 各家把外部 JSON 的浮點金額原樣傳進這條比對路徑**不是 bug**，不要誤報浮點精度問題。

### 每家審查步驟

1. 讀該家 `api.md`（與 `integration.md`）全文，加上所有實作檔：`Driver`、`Deposit`、`DepositCallback`、`Withdrawal`、`WithdrawalCallback`、`{Name}Trait`，以及有的話 `Signature`、`Credentials`、`StatusCodeEnum`、`Config`、`Balance`、`WithdrawalQuery` 等。
2. 逐項對照下表，比「文件說的」與「程式實際做的」：

| 比對項 | 重點 |
|---|---|
| 端點與路徑 | 文件列的網址與程式實際送出的一致嗎？多通道路由（依 `orderType.type/key`）對得上嗎？ |
| 請求參數組裝 | 必填欄位、固定值、欄位來源（會員或訂單）；有沒有**寫死的測試值**（測試 email、`Demo=on`） |
| 簽名生成 | 拼接欄位順序、雜湊算法、大小寫、金額格式化 |
| **回調來源驗證** | **驗簽與 IP 白名單至少要有一道**（兩者都沒有＝任何人都能偽造一筆上分，列真實 bug）。有白名單的要確認白名單是空的時候會拒絕、來源 IP 取 `Common::getIp()` 不是 `$request->ip()`、檢查擋在 `$request->validate()` 之前、`DepositCallback`／`WithdrawalCallback` 的建構子有把 `$agentCashFlow` 存進屬性（只呼叫 `setInfo()` 會讀不到白名單）。判準見 `payment-integration` skill 的回調來源驗證一節 |
| 回調驗簽 | 拼接欄位（常與發起時不同）、金額正規化；**簽名有沒有含金額**（沒含＝改金額不會破簽） |
| **回調反查** | 回調沒簽名、靠查單定案的：**查單是不是在 `DB::beginTransaction()`／`lockForUpdate` 之後**（是＝三方 API 在交易內，列真實 bug）；查單用的是建單時存下的三方單號，還是回調自帶的（用回調的＝可拿同商戶另一張已付款的單換單結算，列真實 bug）；查回的單號、我方單號有沒有跟本單比對；查回已付款沒帶金額時是不是退回用回調金額；查單失敗時回的 HTTP 狀態會不會讓三方重送（對照 `api.md` 的重送規則，三方只看 HTTP 狀態時回 2xx 的 fail＝不再重送、訂單卡住）。判準見 `payment-integration` skill 的 §6.2 |
| 成功判定 | 與文件一致嗎？嚴格比較還是寬鬆比較？ |
| 回調狀態處理 | 有沒有處理中這類中間狀態、是否保留不結單、代收代付是否不對稱 |
| 冪等性 | `findAndLock` 加狀態檢查、`isPayoutRecordProcessed`；**已處理時回成功碼還是失敗碼**（回失敗會害三方一直重送） |
| 代付回調找紀錄 | 用什麼條件找代付紀錄（只用我方交易號，還是加上三方單號）、有沒有呼叫 `checkWithdrawalOrderStatus()` |
| 放款前反查 | 有 `WithdrawalQuery.php` 的，回給三方的格式與狀態碼是否符合文件 |
| 回調響應字串 | success／fail 大小寫、代收與代付是否不同 |
| 銀行代碼映射 | `convertPayoutBankCode`、`available_banks`、`provider_bank_code`；有沒有形同沒做的映射；空銀行碼用 `=== ''` 判斷 |
| 金額與浮點 | 有沒有直接呼叫 `bcadd` 或用原生浮點運算（應走 `Common::`）；外部浮點數入庫前有沒有正規化；有沒有捨去小數 |
| 敏感資訊 | log、錯誤訊息、程式註解、初始化資料檔、文件有沒有外洩金鑰、密碼、token 或會員資料 |
| validate 欄位 | log 與邏輯用到的欄位是否真的在 `$request->validate()` 內（避免未定義陣列鍵） |

3. 依「發現分級」判嚴重度，依附錄判問題分類，寫進資料檔（格式與規則見 `reference/findings-schema.md`）。再進下一家，回報進度 `n/總數`。

### 專項規範

- 代付回調的提款單狀態機（只有 21、22 會被回調處理；只有明確成功或失敗才改成 24 或 29；其餘維持原狀態並記錄）→ `reference/withdrawal-callback-status-rules.md`

### 發現分級

- **真實 bug**（`real`）：有程式或執行期證據（`validate` 規則不含該欄、正式環境帶 `Demo=on`、已處理卻回失敗碼等）。附程式位置與修法。程式路徑確定、但會不會發生取決於三方行為的，仍列真實 bug，標題加「（觸發條件未驗證）」。
- **文檔失準**（`drift`）：文件與程式不符、寫死的測試值、死碼。
- **已知限制**（`known`）：各家 `integration.md` 已自承的設計取捨，不重複當 bug 報。

### 並行

- **3 個以內的 agent 可以直接開**，開之前一句話告知「將開 N 個 agent 做什麼」。適合的切法：每個 agent 負責一批金流商，只回報發現，資料檔由主對話統一寫入。
- 超過 3 個，先用 `AskUserQuestion` 問，說明 agent 數、用途、模型與粗估 token 用量。
- 五小時或每週額度任一低於 20% 時，開之前先告知額度，等使用者決定。

### 查正式站證據

標「觸發條件未驗證」的發現，可以查正式站確認是否發生過：

- **每次查之前都要先取得使用者同意**，說明要查哪個站、查什麼、為什麼。
- 資料庫只用 mysql MCP 唯讀連線（見 `whitelabel-db-query` skill）；log 用 GCP Logging（見 `gcp-log-troubleshoot` skill）。正式站 log 一次查 30 天會很慢，改成逐日查，或先用資料庫找出時間點。
- 結果寫進該筆發現的 `detail`，只寫筆數、時間範圍、站點等摘要，不寫個資與憑證。查到確實發生過的，標題拿掉「（觸發條件未驗證）」。

### 審完要給的東西

1. 逐家結論：clean，或各筆發現（編號、嚴重度、分類、程式位置）。
2. 本次新增或變動的真實 bug 清單，可以直接貼進工單（用 `- [ ]`）。
3. 問使用者要不要重產報告。

**不自動開工單**：開單由使用者決定，走 op-toolkit 的開單 skill。

---

## 更新狀態模式

不重審程式，只改資料檔的處理狀態欄位：

| 使用者說 | 改什麼 |
|---|---|
| 「PRF-xxx 開了 target/tickets#NNNN」 | `tickets` 加上該工單；原本是 `open` 才改成 `ticketed`，其他狀態不變 |
| 「這幾筆拍板不修，理由是……」 | `status` 改 `wontfix`，`decision` 寫理由、拍板人與日期 |
| 「之前不修的改成要修」 | `status` 改回 `open` 或 `ticketed`，`decision` 補改判的理由與日期，保留原本為什麼不修的脈絡 |
| 「PRF-xxx 修好了」 | 先確認修正已在 master（看 commit 或現行程式），`status` 改 `fixed`，`detail` 結尾追加「修正依據：」一段 |

狀態轉換的完整規則見 `reference/findings-schema.md` 的「處理狀態怎麼變」。使用者用金流商名稱或問題描述指稱時，先列出對應的編號給使用者確認，再寫入。寫完照同一份文件的「寫入後驗證」讀回確認。

---

## 產報告

照 `reference/report-html.md` 的步驟產出報告。報告含未修漏洞細節，**禁止發布為 Claude artifact**，只放在 APIs repo 與報告中心。

---

## 鐵則

- **主張 bug 前要有證據**：不可只憑程式對稱性、複製貼上的缺漏或表面差異就說是 bug；最低標準是查過 `validate` 規則或呼叫端。
- **停損**：發現從真實 bug 變成設計層的取捨或資料庫機制要求時，改列已知限制，不要繼續鑽。
- **發現要讓團隊讀得懂**：資料檔進 repo，讀者是沒參與這次稽核的人。不用讀者查不到的內部代號（個人待辦的節號、計畫文件的任務編號、只在某次對話成立的簡稱）；引用文件寫完整相對路徑與節名。判準同 `git-commit` skill 的「描述要寫給團隊看」。
- **不落機密與個資**：資料檔與報告只寫程式位置、欄位名與邏輯描述；三方金鑰、簽名、商戶號、`.env` 與設定撈到的值，一律只寫變數名稱與用途，不寫值。

## 常見 bug 型態（實測命中過，當線索）

- 回調既沒有驗簽、程式內也沒有 IP 白名單（或白名單沒設定就放行）→ 偽造回調可直接上分
- 代付同步回應「不是成功就判拒絕」→ 結果不明的單轉成可重發，重複出款
- 正式環境的請求殘留測試旗標（`'Demo' => 'on'`、沙盒參數）
- 代付回調「已處理」分支回 `failResponse()` 而不是 `successResponse()` → 三方一直重送
- 回調 log 或邏輯引用沒驗證過的欄位（從別家複製的欄位名）→ 未定義陣列鍵警告
- 請求寫死測試手機號或 email
- 文件描述的前綴或格式與實際送出的不符，對應回調裡的 `str_replace` 變成沒有作用的死碼

---

> **擴充規範時**：SKILL.md 維持精簡，新規範細則優先拆到本 skill 目錄下的 `reference/*.md`，由正文按需引用。
