# CLAUDE.md — TARS 前台/後台回歸測試專案(Playwright)

這是給 QA 用的回歸測試專案(名為 TARS,取自《星際效應》的機器人;終極目標是定時自動回歸測試)。主要使用者是**手動測試出身的 QA**,他們用自然語言請你建立/執行/除錯測試——請主動代勞技術細節(git、指令、selector),並用繁體中文、非工程術語回應。

測試對象**僅限 dev 環境**(站台網址與帳密一律從 `.env` 讀,經 `config/operators.ts` 解析)。**禁止對任何白標站(tpower / vw / wintoto…)或 prod 站台做任何「操作」**——不可用瀏覽器開啟、登入、點擊,不可對其跑測試、寫入任何資料。`OPERATOR_KEYS` 只有 `dev`,且 `.env` 出現非 DEV 的站台網址時 config 會直接報錯拒跑;這是刻意的防護,使用者要求測其他站台時,回覆「本專案僅限 dev」並請他找工程師,不要繞過。

**唯一例外:查 GCP log(唯讀)不受站台限制**——QA 可以查**所有站台**(dev、白標、prod)的 log,見下節。

## 查 GCP log(所有站台皆可,唯讀)

QA 排查問題時常需要看白標站或正式站的伺服器 log(例:「幫我找 tpower 最後一次遊戲商 callback 紀錄」)。這類需求用 **gcp-mcp 的 observability 工具**(`list_log_entries` / `list_log_names` / `get_trace`…)直接查,**不受「僅限 dev」限制**,因為它是唯讀查詢、不會碰到站台本身。

規則:

- **只能讀,不能動**:查 log、看 trace、看 metrics 都可以;但不可因為在 log 看到某站台的網址,就用瀏覽器去開它、登入它——站台操作仍然僅限 dev。
- **不改設定**:不可建立/修改/刪除 GCP 上的任何資源(sink、alert、bucket…),只用查詢類工具。
- **回報時注意敏感資料**:log 內容可能含會員資料、token、金流參數,回給 QA 時只摘錄回答問題所需的欄位,不要整段原文照貼。
- 查詢結果只用於排查與回報,**不能把白標/prod 的網址或帳密寫進 `.env` 或任何測試**——測試執行永遠僅限 dev。

## 測試分類(不分前後台,只分風險)

**這是本專案的結構規則**:project 不分前後台,每個營運商只有三個——**guest(未登入)/ logged(需登入態、不留資料)/ dangerous(會產生髒資料)**。目錄固定為 `tests/<分類>/`,project 名為 `<op>-<分類>`,由 config 自動產生。站台(前台/後台)不是分類軸——**一個測試案例可以混用前後台的原子操作**(例:前台下注 → 後台查注單),站台由測試內用哪個 fixture 決定(見下節)。

| 目錄 | project | 登入態(由 fixture 提供) | 何時用 |
| --- | --- | --- | --- |
| `tests/guest/` | `<op>-guest` | 兩站皆未登入 | 免登入的操作(公開頁、登入頁本身) |
| `tests/logged/` | `<op>-logged` | 前台=會員、後台=admin | 需登入、不留資料的操作(查看/查詢類) |
| `tests/dangerous/` | `<op>-dangerous` | 前台=未登入(配 member 當場註冊)、後台=admin | 會**產生髒資料**的操作(註冊、建立代理等) |

QA 勾 guest + logged 就是「不留資料的安全回歸」。分類拿不準時,問使用者:「這個操作會不會在 dev 產生新資料(訂單、帳號)?」會 → dangerous;不會但要登入 → logged;免登入 → guest。

## 站台 fixtures(fixtures/sites.ts)

測試一律 `import { test, expect } from '../../fixtures/sites'`,原子操作用 fixture 決定操作哪個站台:

- **`frontendPage`**:前台(會員站)頁面,baseURL 已綁前台網址。logged 分類自動帶 `.env` 固定會員帳號登入態。
- **`backendPage`**:後台(代理管理站)頁面。logged / dangerous 分類自動帶 admin 登入態。
- **`member`**:當場隨機註冊一組全新前台帳號(`qa` 開頭)並在 frontendPage 登入(dangerous 用);驗證碼自動從後台簡訊記錄查(`utils/backendOtp.ts`),所以 `.env` 後台帳密必填。帳號只屬於該支測試。

登入態來源:`<op>-setup`(`tests/setup/auth.setup.ts`)為有帳密的站台各寫一份登入檔(`.auth/<op>-frontend.json` / `.auth/<op>-backend.json`),logged/dangerous 自動依賴它;fixture 缺登入檔時會當場登入(單獨跑一支也不會壞)。對應站台未設定網址/帳密時,fixture 會自動 skip 並寫明原因。

**注意:`.env` 固定會員帳號的資料會跨執行累積**,寫測試時斷言不要假設帳號是乾淨的(例如餘額為 0)。

## 測試樹結構(必守)

```
<模組>_<子模組>.spec.ts            ← 檔名:模組_子模組(snake_case 英文,如 member_login.spec.ts)
  [前台] 驗證登入成功              ← 第一層 describe = 測試案例(「驗證…」句式,前綴 [前台]/[後台]/[前後台])
    [前台] 輸入帳密並送出          ← test = 原子操作(前綴 [前台]/[後台] 標明操作哪個站台)
    [前台] 確認導向會員首頁
```

- **測試案例** = `test.describe.serial('[站台] 驗證…')`。案例名要讓 QA 一看就懂在驗什麼(「驗證登入失敗-密碼包含不合法字元」);涉及兩站的案例前綴用 `[前後台]`。
- **原子操作** = 案例內每個 `test()`,前綴標站台。serial 模式下前面的操作失敗、後面自動標 skipped,QA 能直接看出是哪一步出錯;要注意 **skip 不會讓後續跳過**,需要傳遞時用旗標(範例見 `agent_management.spec.ts` 的 `featureAvailable`)。
- **操作內用 `test.step('中文說明', …)` 包每個關鍵動作**,UI 模式/報告點開就有中文執行軌跡可追溯;斷言一律帶中文說明(`expect(x, '說明').…`)。
- **案例之間完全獨立**:每個案例自己準備狀態(自己的帳號、自己的登入),不能吃前一個案例留下的東西——QA 在 UI 模式單獨跑任一案例都要能過。原子操作之間才共用狀態(伺服器端資料透過 fixture 的同一份登入態;需要延續瀏覽器 session 的登入類案例,用 `beforeAll` 自建共用 page,範例見 `tests/logged/agent_login.spec.ts`)。
- 單一操作的案例不必硬拆多個原子操作。

### 目前的測試案例(新增/刪除測試時必須同步更新這份清單)

| 目錄 | 檔案 | 測試案例(→原子操作) |
| --- | --- | --- |
| `tests/guest/` | `member_home.spec.ts` | [前台] 驗證首頁可正常載入 → 開啟首頁並確認載入 |
| `tests/guest/` | `agent_login.spec.ts` | [後台] 驗證登入頁可正常載入 → 開啟登入頁並確認登入表單 |
| `tests/logged/` | `agent_login.spec.ts` | [後台] 驗證登入後可進入後台首頁 → 登入、進入後台首頁(共用 session) |
| `tests/logged/` | `member_center.spec.ts` | [前台] 驗證登入態可進入會員中心 → 確認首頁為登入態、進入會員中心 |
| `tests/dangerous/` | `member_register.spec.ts` | [前後台] 驗證會員可完成註冊並為登入態 → 填寫註冊資料並發送簡訊、後台從簡訊記錄查驗證碼、填入驗證碼完成註冊、確認註冊後為登入態(不用 member fixture,beforeAll 自建共用 page 讓每階段都是看得見的原子操作) |
| `tests/dangerous/` | `game_bet_pxplay.spec.ts` | [前後台] 驗證會員在 Pxplay SW 下注後可在後台報表查到注單與扣點 → 以有餘額會員登入並記下餘額、進 Pxplay SW（236）開啟遊戲、下一注並記下結算後餘額、SR12 查到這一局注單（並核對前台餘額變動＝注單輸贏）、SR14 找到這家最新的單一錢包場次列、點開明細有這一注、當下點數等於前台餘額（單一錢包在 SR14 是同場次合併一列、日期記第一注那天，所以往前查 30 天）。測試內容在 `tests/shared/gameBetSuite.ts`，這支只指定要測哪一家 |
| `tests/dangerous/` | `agent_management.spec.ts` | [後台] 驗證可建立代理並掛在總代底下 → 建立代理、列表驗證;[後台] 驗證可限制代理登入並解除 → 建立測試代理、限制登入、驗證操作紀錄、解除限制(功能未部署或無 agent_sys_lock 權限時自「限制登入」起跳過);[後台] 驗證代理帳號停用後無法登入後台 → 建立測試代理、停用該代理、以停用帳密嘗試登入並確認失敗(與「限制登入」是不同機制,各建一個 `qaag` 帳號) |
| `tests/dangerous/` | `member_change_phone.spec.ts` | [前台] 驗證會員可修改手機號並生效 → 用 `.env` 固定會員帳號登入(登入後先回首頁等資料載完,直接跳設定頁前端會先顯示錯誤的未綁定預設選單)、進設定頁開啟修改手機號、對目前手機號發驗證碼、後台簡訊記錄查驗證碼、輸入後填新手機號再發一次驗證碼、後台再查一次、輸入完成修改、重新整理確認畫面顯示的目前手機號已變成新號碼。**會把 `.env` 固定會員帳號的手機號永久改成新號碼**,屬正常現象 |

### 下注測試怎麼擴充到多個遊戲商

驗投注的流程（登入 → 開遊戲 → 下一注 → 對 SR12/SR14）各家完全一樣,只有「怎麼進到那家、怎麼下一注」不同,所以拆成三層:

- `config/gameVendors.ts`——每家一個 export(遊戲商編號、名稱、要玩哪款遊戲、測試標籤、轉動鈕比例座標)
- `tests/shared/gameBetSuite.ts`——共用測試案例(`registerGameBetTests(vendor)`),流程只有一份
- `tests/dangerous/game_bet_<家>.spec.ts`——一家一支、內容只有一行呼叫,**讓 UI 模式的檔案清單一眼看到有哪幾家、可以單獨跑**

新增一家 = `config/gameVendors.ts` 加一個 export + 複製一支 spec 改名。玩法跟「按一下轉動鈕就是一注」不同的(真人、體育、捕魚),為那家在 `pages/frontend/games/` 另寫一個 game 類別,別把 if 塞進共用流程。轉帳錢包的遊戲商流程本質不同(要多「平台轉入/轉回」兩個操作、報表看 SR06),另開一支 suite,不要混。

單獨挑幾家跑:UI 模式在篩選框輸入遊戲商名稱或標籤(`@pxplay`);指令用 `npx playwright test -g "@pxplay|@megah5" --project dev-dangerous`,全部下注測試是 `-g @game-bet`。

這份清單是「回歸測試涵蓋了什麼」的權威索引:新增測試前先掃一遍,**避免建重複的測試**;新增後把「檔案 + 一句話測試案例」補進來(README 第四節有一份 QA 面向的相同清單,一併同步)。

## 寫測試的慣例

- **selector 集中在 Page Object**:`pages/<frontend|backend>/<Name>Page.ts`。頁面互動邏輯放 Page Object,測試檔只表達意圖。修 selector 改 Page Object,不要在測試檔內散落 selector。
- selector 優先序:`getByRole` / `getByLabel` / `getByPlaceholder` / `getByText` > `data-testid` > CSS。**避免 `nth()`、深層 CSS chain、auto-generated class**——這些一改版就碎。
- 測試標題、`test.step` 與註解**一律繁體中文**,描述業務行為,不寫技術細節。
- **絕不寫死網址、帳號、密碼**:網址走 fixture 的 baseURL(相對路徑 `frontendPage.goto('/member')`),帳密走 `.env` / `getOperatorSite()`。
- 斷言要驗**業務結果**(餘額出現、訂單建立),不只驗「頁面沒炸」。

## 用瀏覽器探索(建測試前必做)

寫測試前先用 Playwright MCP(`browser_navigate` / `browser_snapshot` / `browser_click`…)把流程實際走一遍,邊走邊記可用的 selector。站台網址與帳密直接讀 `.env`(用 Read 工具看,**不要把密碼印在對話中**)。優先用 `browser_snapshot`(a11y 樹)而非截圖來找元素。

## 驗證與指令

```bash
npx playwright test --project dev-guest                     # 跑某分類
npx playwright test tests/logged/xxx.spec.ts --project dev-logged   # 跑單檔
npm run ui                                                   # QA 慣用的 UI 模式
npm run report                                               # 上次執行的 HTML 報告
```

- 新測試**跑到綠才算完成**,跑不綠不准 commit。
- 失敗時看 `test-results/` 的截圖/trace 與報告附件「失敗當下畫面(前台/後台)」分析,先判斷「產品 bug」還是「測試脆弱(selector 壞了、時序)」,再回報使用者。
- 跑 dangerous 會在 dev 產生 `qa` 開頭的新帳號,屬正常現象。

## Git 規範(重要)

QA 不熟 git,由你代操作,規則:

- **禁止直接 commit 到 main**。新測試一律開分支:`qa/<使用者名>/<主題>`(例 `qa/amy/wallet-balance`)。
- commit 前必須:該測試本地跑綠 + `git status` 確認只包含預期檔案(spec、Page Object;**不可包含 `.env`、`.auth/`、`test-results/`**)。
- commit message 繁中一行,說明測了什麼行為。
- push 與開 MR 前先詢問使用者;MR 指給工程師 review,**不代按合併**。

## 營運商限制

**測試執行僅允許 dev,禁止新增其他營運商/白標站。**`OPERATOR_KEYS` 固定為 `['dev']`,`resolveOperatorSites()` 會擋掉 `.env` 裡任何非 DEV 的站台網址;且 URL 主機名只允許 **example.invalid、example.invalid(皆含子網域)與 .local**,其他網域(白標站、正式站)一律拒絕啟動。若未來政策改變要開放,必須由工程師修改 `config/operators.ts` 的防護邏輯,不要替 QA 繞過。

這個限制管的是「**操作站台**」(瀏覽器、測試、寫資料)。**查 GCP log 不在此限**——所有站台的 log 都可以查,規則見開頭〈查 GCP log〉一節。
