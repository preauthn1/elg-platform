# 產出風險發現報告（HTML）

報告是 APIs repo 的 `docs/reports/payment-risk-findings.html`，由本 skill 讀資料檔直接寫出，沒有 artisan 命令或 blade 樣板。資料檔才是正本；報告只是看資料的畫面，不在報告上改資料。

報告透過報告中心（`/docs/reports`，`routes/web.php` 的報告登記）瀏覽，由 `ReportDocsAccess` 把關：本機與開啟 API 文件的環境（例如 dev2）看得到，正式環境一律 404。

## 什麼時候產

- 審查模式或更新狀態模式寫完資料檔之後，問使用者要不要重產報告；使用者說要才產。
- 使用者直接說「產風險發現報告」「更新風險報告」時，讀現有資料檔直接產，不重審。
- 報告與資料檔放在同一個 commit，讓兩者在 git 歷史裡永遠對得上。

## 內容與機密

- 放資料檔的完整內容，包含尚未修復的漏洞細節、程式位置與修法。報告只留在 repo 與報告中心，**禁止發布為 Claude artifact**，也不上傳到任何外部服務（同 `report-design-conventions` skill 的兩軌分流）。
- 不放資料檔以外的東西：沒有寫進資料檔的推論、對話裡的臨時結論，都不放進報告。

## 畫面要能做到的事

版面照 `report-design-conventions` skill（共用樣式 `assets/report.css`、素色線條 icon、不用 emoji、首節「一分鐘摘要」）。除此之外格式不設限制，以下是必備功能：

- **兩種檢視可切換**：依問題（依 `category` 分組，同組裡共用層那筆排最前面，各家用 `rootCause` 標出共同成因）與依金流商（一家一區）。**預設依問題**。
- **篩選**：嚴重度、處理狀態、是否要先問金流商。`fixed`（已修）預設隱藏，可以切換顯示。
- **搜尋**：金流商名稱、檔名、標題、編號。
- 每筆至少看得到：編號、嚴重度、處理狀態、金流商、標題、程式位置、描述、修法、工單、拍板理由、是否要問金流商。
- 首節「一分鐘摘要」放統計（各嚴重度、各處理狀態的筆數；已審家數不含共用層）與資料檔的 `generatedAt`、`updatedAt`。
- 每個分類附上它在 `docs/金流接入流程/附錄-常見缺陷類型.md` 的節名，讓讀的人知道去哪裡看原理。

切換、篩選、搜尋用頁內的少量 JavaScript 完成，不引用外部套件。

「修法」不要用共用樣式的亮綠色文字（RB 嫌刺眼），改成淺綠底框、內文用一般文字色，只有「修法：」標籤用深綠。

## 產出步驟

1. 先載入 `report-design-conventions` skill，照它的骨架與硬規則做。
2. 用腳本把資料檔整份嵌進頁面（例如放在 `<script type="application/json">` 裡，再由頁內 JavaScript 畫出來），不要手抄，避免漏筆或抄錯。
3. 寫到 APIs repo 的 `docs/reports/payment-risk-findings.html`。改 repo 要在工作分支上，照 APIs 的 Git 工作紀律。
4. 驗收：
   - 依 `report-design-conventions` 的驗收節，用 headless Chrome 全頁截圖實看渲染，兩種檢視與篩選都要切過一次。
   - 頁面上的總筆數、各嚴重度與各處理狀態筆數，要和資料檔用腳本算出來的一致。
   - 容器內請求 `/docs/reports` 與 `/docs/reports/payment-risk-findings` 都回 200。
5. 回報檔案路徑、核對結果與截圖重點。
