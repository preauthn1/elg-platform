---
description: 一鍵重建金流商盤點報告 docs/reports/payment-providers-report.html（先 :stale 判斷快取，必要時跑 payment-callback-analysis workflow 增量刷新「回調邏輯」一節，再 merge + 渲染）。
argument-hint: "[full | render]"
disable-model-invocation: true
---

模式：$ARGUMENTS

---

> 一句話重建 `docs/reports/payment-providers-report.html`。本 command 只**批量產生與渲染地圖**（金流商清單 / 白標使用對照 / 回調邏輯 / 文檔缺口），**不做判讀、不列風險**。風險與待修項目在「金流商風險發現」報告。

| 區塊 | 來源 | 判讀 |
|---|---|---|
| 金流商清單 / 白標使用對照 / 文檔缺口 | `report:payment-providers` 即時計算 | — |
| 回調邏輯（各家成功觸發條件與狀態判定風格） | `payment-callback-analysis` workflow → JSON（雜湊快取） | 只萃取事實，不判風險 |
| 逐家深審 / 證據 / 風險發現報告 | `report-payment-audit` skill | ✅ 判讀在此，**不在本 command** |
| 守衛·冪等的 status 事實基礎 | `report-order-status-lifecycle` 報告 | 由 `report-payment-audit` 取用排查 |

**所有 artisan 指令一律在容器執行：**
`docker exec -it php-fpm8 bash -c "cd /var/www/APIs && <cmd>"`

## 模式（$ARGUMENTS）

| 值 | 行為 |
|---|---|
| 空白（預設） | **smart**：先 `:stale`，無變動只渲染、有變動才刷新 |
| `render` | 跳過 AI，直接用現有 JSON 渲染 |
| `full` | 強制全量重跑 workflow（忽略快取） |

## 執行序

### render 模式
1. `php artisan report:payment-providers`
2. 回報輸出路徑 + summary 行。結束。

### smart 模式（預設）
1. `php artisan report:payment-providers:stale --json` → 取需重跑清單。
2. **清單為空** → `php artisan report:payment-providers` → 回報 → 結束（0 agent、免費）。
3. **清單非空** → 進「刷新」，`args` = 該清單。

### full 模式
1. `php artisan report:payment-providers:stale --json --all` → 取 `config/payment.php` 全部金流商清單。
2. 進「刷新」，`args` = 該清單。

### full 模式 / smart 有變動 → 刷新
1. **先用 AskUserQuestion 確認**是否重跑 workflow（預設選項：「不重跑，直接用現有 JSON 渲染」）。問題中明確告知：將 spawn 幾個 agent（= 清單家數；`full` = `--all` 清單的家數，清單內重複註冊的金流商由 workflow 自動去重）、Analyze 階段用哪個模型（以 `.claude/workflows/payment-callback-analysis.js` 的設定為準）。
   - 選「不重跑」→ 退回 render 模式。
2. 確認後 → 呼叫 `Workflow({ name: "payment-callback-analysis", args: <清單> })`。workflow 不自己解析 `config/payment.php`，清單一律由前一節取得後傳入（smart：`:stale --json`；full：`:stale --json --all`）；沒傳或傳空清單會直接報錯。
3. 將回傳的 `result.providers` 寫成 `docs/reports/data/fresh.json`。
4. `php artisan report:payment-providers:merge --input=docs/reports/data/fresh.json`，成功後刪除 `fresh.json`。
5. `php artisan report:payment-providers`。
6. 回報：套用幾家、輸出路徑、summary 行。

## 紅線
- 🚫 未經 AskUserQuestion 確認，不得呼叫 Workflow（會燒 token / spawn agent）。
- 🚫 workflow 因完整性守衛中止（某家 agent 失敗）→ **不 merge、不覆蓋既有 JSON**，回報缺哪幾家，請使用者重試。
- ⚠️ 同一個 session 裡改過 `.claude/workflows/payment-callback-analysis.js` 後，用 `name` 呼叫會跑到 session 開始時載入的舊版（實測：回傳的 Summary 仍是舊描述）。改過腳本就改用 `Workflow({ scriptPath: "<APIs 路徑>/.claude/workflows/payment-callback-analysis.js", args })`，並先核對回傳的 Summary 是新版。
- 改了 workflow 的 prompt 或 schema，要同步把 `app/Services/Report/PaymentCallbackAnalysisCache.php` 的 `PROMPT_VERSION` 加一，舊的快取才會全部失效。
- ✅ HTML 為衍生產出物，可隨時重生；`docs/reports/*.html` **已入庫**（防遺失），但 `payment-callback-analysis.json`（含 hash）才是真正的版控基準。
