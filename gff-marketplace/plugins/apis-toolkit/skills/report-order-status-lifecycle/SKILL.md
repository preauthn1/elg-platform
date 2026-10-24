---
name: report-order-status-lifecycle
description: 產製代收 / 代付訂單 status 生命週期報告（HTML，逐行追蹤原始碼）。只能打 `/apis-toolkit:report-order-status-lifecycle` 叫用。逐一追蹤實際寫入 status 的位置（含新 APIs 與舊 API_Agent），帶 file:line 渲染成 Vuexy 主題 HTML，輸出到 docs/reports/。
disable-model-invocation: true
---

# 代收 / 代付訂單 status 生命週期報告產製

> 把「訂單 status 從建立到終態的每一次寫入」追蹤出來，附 `file:line`，渲染成與
> `docs/reports/` 其他報告一致的 HTML。報告含舊系統（API_Agent）追蹤，會隨 code drift，
> **每次重產都重新 grep 取當前行號**，不可沿用舊報告的行號。

## 產哪一份

由使用者指定：`代收` / `代付` / 兩份都產。未指定，或只說「生命週期報告」而未限定類型時，預設兩份都產。

| 報告 | 規格檔 | 輸出 |
|---|---|---|
| 代收 | `reference/deposit-spec.md` | `docs/reports/deposit-status-lifecycle-report.html` |
| 代付 | `reference/withdrawal-spec.md` | `docs/reports/withdrawal-status-lifecycle-report.html` |

> 輸出檔 `docs/reports/*.html` **已入庫**（可重生衍生產物，入庫以防遺失）。產完可在 `/docs/reports` 檢視
> （受 `ReportDocsAccess` 保護：沿用 API 文件開關 `scramble.enabled`，本機與 dev2 可看，正式站回 404）。

## 流程

1. **讀規格**：開對應 `reference/*-spec.md`，掌握章節結構、stat 卡、來源檔錨點。
2. **對齊 enum**：grep `app/Enums/{Order,WithdrawalOrder}StatusEnum.php`，確認狀態值與 case 名與規格一致；有增減就更新表格與 stat 卡。
3. **追蹤寫入點**：對規格「來源檔與追蹤錨點」表每一列，用 grep 找出當前 `file:line`。
   - 引用一律標 `->update([` / `::create([` **呼叫行**，非 enum 賦值行（兩者常差數行）。
   - 舊系統檔在 `API_Agent/_release/...`（host 路徑為本機 API_Agent clone 下的 `_release/`，位置依開發者本機而異；不確定時先問使用者）。
   - 找不到錨點＝該邏輯可能已遷移/移除，停下回報，不要猜行號。
4. **填模板**：以 `reference/template.html` 為外殼，替換 `{{PLACEHOLDER}}`，章節 HTML 填入 `{{SECTIONS}}`。狀態徽章用 `.st.sN`（N=狀態值），箭頭用 `.arrow`，流程圖用 `.flow > pre`（橫向節點列另加 `.inline`）。樣式由模板引用 `assets/report.css`，**不要內聯 CSS、不要自創 class**。
5. **寫檔**：輸出到對應 `docs/reports/*.html`。`{{DATE}}` 用今天日期。報告已入庫、讀者是團隊，敘述性文字不用讀者查不到的內部代號（節號、task 編號、對話簡稱），引用文件寫完整相對路徑與節名（判準同 `git-commit` skill 的「描述要寫給團隊看」）。
6. **回報**：列出本次與前版相比有 drift 的 file:line（若有），並提示 `/docs/reports` 可檢視。

## 注意

- **只產報告、不改 code**。追蹤中若發現實作疑似有 bug，分級列出讓使用者決定，不要順手改。
- file:line 是報告價值核心：每一個都要來自本次重產的 grep 結果，不可沿用或推測（呼應專案「主張前要 runtime/grep 證據」原則）。
- 視覺一律走 `template.html`，不要自創樣式，確保與 `payment-providers-report` 等同風格。
- **⛔ 全篇禁用 emoji**（側欄、章節標題、內文註解、chip、節點描述皆然）。行內圖示用
  `<svg class="ni"><use href="#i-xxx"/></svg>`，symbol 集已內聯於 `template.html` 的 body 開頭。
- **圈號字元（①②③ⒶⒷⒸ）內文出現一律包 `<span class="cn">`** 放大；節點徽章 `.fn-n` 內的圈號不包。
- 改動模板或樣式後，**驗收方式＝headless Chrome 全頁截圖**實際看渲染，不能只看 HTML：
  `"/Applications/Google example.invalid/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --hide-scrollbars --screenshot=out.png --window-size=1440,13000 <url>`
  （純靜態檢查抓不到「無尺寸的 `<use>` 型 svg 撐滿容器」「`.flow` 類名衝突使節點變全寬」這類版面缺陷。）
- §2 生命週期狀態機用 **`.fchart` 節點圖**（`.fnode.s{N}` 狀態節點 + `.fconn` 連接 + `.fbranch` 分支標籤 + `.fnotes` 旁註），非 ASCII `<pre>`。是人工整理的語意圖，重產時保留結構、只校正狀態值與分支是否仍符合 code。
