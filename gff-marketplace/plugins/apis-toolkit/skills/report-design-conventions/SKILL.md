---
name: report-design-conventions
description: 團隊的報告／簡報 HTML 設計規範（Frest 樣式、素色線條 icon、流程圖版型、截圖驗收）。當使用者說「產一份報告」「做一份簡報」「設計對照簡報」「HTML 報告」「更新 docs/reports 的 XX 報告」時自動載入。
---

# 報告／簡報 HTML 設計規範

> 適用於 APIs `docs/reports/` 下的報告、設計對照簡報，以及其他要給使用者檢視的 HTML 產出。
> **樣式 SSOT＝APIs repo `docs/reports/assets/report.css`**（含完整色票 token／版面／表格／chip／流程圖／註解卡）；
> 本 skill 收斂的是「怎麼用」與**曾被打回的路線**，不複述 CSS 內已定義的值。
> 這套規範是多份報告經連續多輪審閱反饋收斂出的定案，直接套用可省掉整輪打回。

## 0. 產出位置與骨架

**兩軌分流（動手前先判定去向）**：

| 產出類型 | 去向 |
|---|---|
| 含業務數據的正式報告（對帳／金流／營運稽核，及 generator 產物） | repo `docs/reports/`，本 skill 全文適用；**禁止發布為 Claude artifact** |
| 純設計對照簡報（零業務數據、零 PII） | 得發布為 Claude artifact（試點階段），發布前經使用者同意 |

- 禁止理由：對帳／金流等未公開營運數據不得託管於外部服務；artisan generator 只產本地 HTML、無法發布 artifact；artifact 的 CSP 載不到 repo 相對路徑 CSS，`report.css` SSOT 得逐頁內聯、改版不回灌。
- 走 artifact 軌時：整套樣式內聯進頁面、逐段確認內容零業務數據零 PII；其餘設計規範（§1 硬規則、§2 內容結構、§3 驗收）仍適用。
- 歸類無法判定時一律走 repo 軌。

- 輸出到 APIs repo `docs/reports/<topic>.html`，head 引用 `<link rel="stylesheet" href="assets/report.css">`（相對路徑：`/docs/reports/{name}` 路由與 `file://` 直開皆可解析）；字體用 Google Fonts 的 Public Sans。
- **不要內聯整套樣式**；只有報告專屬的少量樣式才寫頁內 `<style>`。
- 骨架照現有報告抄：深色側欄（brand＋編號章節選單 `.mn`）＋ sticky topbar（crumb／h1／chips）＋ `.container` 內逐節 `.card`（`card-head` 的 `.idx` 放章節 icon）。
  - 流程圖型範本：`docs/reports/withdrawal-status-lifecycle-target-flow.html`
  - 設計對照簡報型範本：`docs/reports/sbr-summary-queue-redesign.html`
- 素色線條 icon 的 `<symbol>` 集（feather 風、`stroke="currentColor"`）內聯於 `<body>` 開頭，內文以 `<svg class="ni"><use href="#i-xxx"/></svg>` 引用。**引用前確認 symbol 真的有定義**——引到不存在的 id 不會報錯、只會靜默空白。

## 1. 硬規則（曾被打回的路線，勿重走）

| ❌ 禁止 | ✅ 改用 | 備註 |
|---|---|---|
| 內文任何 emoji（註解前綴、chips、節點描述） | 素色線條 SVG `<use>` | 真 emoji 判準正則 `(?:[\x{1F300}-\x{1FAFF}]|\S\x{FE0F})`；`✓`／`✗`／箭頭**不是** emoji、是刻意設計，清理時別誤刪 |
| chip／label 用深色文字（含以 WCAG AA 為由） | Frest 官方 bg-label 樣式：淺底＋**原色文字** | AA 只適用正文；「深藍黑字 chip」「同色系深字」都被打回過 |
| 深色混濁實底節點徽章 | solid 原色底＋白字（`.fn-n`） | 徽章內圖示也用素色 SVG，且必給尺寸（無尺寸會撐滿容器） |
| `code` 元素用紅色 | primary 深紫（css 已定義） | 紅 code 散在版面「很醜」 |
| 流程連接線標籤（`.fc-l`）粗體 | 一律 font-weight 400 | 含內部 `<b>` 一併 400 |
| 分支標籤容器 `display:flex` | block＋`text-align:center` | inline 文字＋`<b>`＋`<br>` 會被拆成水平 flex items 跑位 |
| 分支「標籤列、節點列各自 flex-wrap」靠水平位置對照 | **每條路徑一欄**（`.fsplit`／`.fpath`：標籤 → 專屬箭頭 → 結果節點） | 旁路（不經共同步驟）用虛線箭頭＋虛線框 `.bypass` |
| 節點徽章放看不清的字元；圈號與狀態碼混用 | 徽章＝結果狀態碼或 icon；圈號（①②Ⓐ…）只當出口編號前綴 | 灰底白「↗」被打回過 |
| 圈號字元裸寫 | 包 `.cn` 放大 | 字形筆畫小，不放大難讀 |

## 2. 內容結構

- **首節固定「一分鐘摘要」**：面向／重點兩欄表，內容必須自足——術語首次出現附白話、狀態碼附中文名稱，只讀這節就能判斷全貌。
- **讀者視角＝PM／營運非工程師**：工程術語白話化（事務＝要嘛全寫入要嘛全取消）；方法名／欄位名／類別名收進「工程對應」欄或工程備註，不混在正文。
- **要 PM 拍板的決策題附建議**：每個選項寫建議、理由與不做的後果；AI 擬的推薦項標「AI 推薦」，頁尾說明「AI 依工程紀錄給的建議，不代表工程團隊的立場，決定權在 PM」。該 RD 本人拍板過的才寫「工程建議」。
- 名詞對照表用 `.terms`（首欄 nowrap）；長段落改條列或表格；一段只講一件事。
- 結構裝置要載資訊：編號標記只用在真正的序列（流程、時間軸），不當裝飾。
- 統計磚（`.stats`）放讀者最該記住的 3–4 個數字，不塞滿。
- 報告落檔只放聚合結果；任何 PII 入頁前必遮蔽（帳號保頭尾、姓名留姓、卡號末 4 碼）。

## 3. 驗收（必做——CSS 衝突純靜態檢查抓不到）

1. headless Chrome 全頁截圖實看渲染再收工：

   ```bash
   "/Applications/Google example.invalid/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
     --screenshot=/tmp/report.png --window-size=1440,15000 "file:///.../docs/reports/<name>.html"
   ```

2. 裁段檢視細節：**`sips` 的 `--cropOffset` 不可靠**（實測被忽略、永遠裁中央），用 PIL 依內容高度裁段：

   ```python
   from PIL import Image
   im = Image.open('/tmp/report.png').convert('RGB')  # 先掃底色找內容底部，再每 ~2900px 一段 crop
   ```

3. 已踩過、只有截圖看得到的 CSS 衝突：`.flow` 類名衝突（節點型須加 `.inline`）、`.fn-n svg` 無尺寸撐滿容器。

## 4. 本地預覽

- `file://` 直開即可（css 走相對路徑）。
- 要走站台：`/docs/reports/{name}` 路由（`ReportDocsAccess`：沿用 API 文件開關 `scramble.enabled`，本機與 dev2 可看，正式站回 404）。`php-fpm8` 容器無 port mapping，`artisan serve` 不通；需要瀏覽器直連就在 `Docker-Compose/nginx/sites/` 加 `test.local` vhost，用完撤 conf。

## 5. 更新既有報告

- generator 類報告（lifecycle 系列、`php artisan report:payment-providers`）改版時**同步改 generator**，不要只改產出物。風險發現報告沒有 generator，由 `report-payment-audit` skill 讀資料檔直接寫 `docs/reports/payment-risk-findings.html`，改版時改那支 skill。
- 報告內的 `file:line` 引用會隨 code drift：重產時全部重新 grep 取當前行號，不可沿用舊值。

## 對話範例

> 使用者：「幫我做一份 XX 改造前後的對照簡報」
> AI：載入本 skill → 產出 `docs/reports/xx-redesign.html`（引用 assets/report.css、素色 icon、一分鐘摘要開頭、每條分支一欄的流程圖）→ headless Chrome 截圖逐段檢視 → 修掉渲染問題後回報。
