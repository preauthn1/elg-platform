# tars

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`tars`　|　文件 **41**　|　主语言：TypeScript 30, JSON 7

## 目录构成

```
pages/frontend/  — 7
pages/backend/  — 5
tests/dangerous/  — 4
utils/  — 3
tests/guest/  — 2
tests/logged/  — 2
config/  — 2
.claude/  — 1
.claude/skills/  — 1
tests/setup/  — 1
tests/shared/  — 1
fixtures/  — 1
```

---

## 原始说明

# TARS — 前台／後台回歸測試（Playwright）

> 名字取自《星際效應》的機器人 TARS：可靠、誠實、髒活累活全包。終極目標是**定時自動回歸測試（scheduled regression testing）**。

這是一套給 **QA 做前台／後台回歸測試**的自動化專案。clone 下來、填好設定，就能用圖形化的 **UI 模式**逐項執行測試，全程看得到瀏覽器每一步在做什麼、哪裡出錯。

目前主要對象是 **dev 環境**（前台 `example.invalid`、代理後台 `example.invalid`）。**測試只能在 dev 執行**；但排查問題時，可以直接請 Claude 幫你**查任何站台（含白標、正式站）的伺服器 log**，例如「幫我找 tpower 最後一次遊戲商 callback 紀錄」——查 log 是唯讀的，不會動到站台。

---

## 一、第一次使用（安裝）

### 1. 需求
- 安裝 [Node.js](https://example.invalid/) 20 以上版本（在終端機打 `node -v` 可確認）

### 2. 下載並安裝
在終端機依序執行：

```bash
git clone <這個專案的網址>
cd tars
npm install
```

`npm install` 會自動把測試用的瀏覽器（Chromium）一起裝好，第一次會跑比較久，請耐心等。

### 3. 建立設定檔
把設定範本複製一份成 `.env`：

```bash
cp .env.example .env
```

然後用任何文字編輯器打開 `.env`，確認／填入設定（見下一節）。

> `.env` 存的是網址與帳密，**不會被上傳到 git**，可以放心填。

---

## 二、設定 `.env`

`.env` 裡跟 dev 有關的設定如下，多數已預先填好，你通常只需要確認「推廣碼」：

| 設定 | 說明 | 預設 |
| --- | --- | --- |
| `DEV_FRONTEND_URL` | 前台網址 | 已填好 |
| `DEV_BACKEND_URL` | 代理後台網址（用來查註冊驗證碼）| 已填好 |
| `DEV_ADMIN_USERNAME` / `DEV_ADMIN_PASSWORD` | 後台管理帳密 | `frank` / `123456` |
| `DEV_MEMBER_ACCOUNT` / `DEV_MEMBER_PASSWORD` | **前台會員帳密（登入後操作用）** | 需自行填入 |
| `DEV_REFERRAL_CODE` | **前台註冊要用的推廣碼** | 需自行確認 |
| `DEV_PHONE_COUNTRY_CODE` | 註冊手機國碼 | `60`（馬來西亞）|
| `DEV_GAME_MEMBER_ACCOUNT` / `DEV_GAME_MEMBER_PASSWORD` | 前台**有餘額**的會員帳密（下注測試用）| 需自行填入 |

三個需要自行填的：

- **`DEV_MEMBER_ACCOUNT` / `DEV_MEMBER_PASSWORD`（會員帳密）**：「登入後操作」的測試用這組帳號自動登入。可以填任何 dev 既有會員，最方便的是跑一次註冊測試、把它印出的 `qa` 開頭帳號填進來。沒填的話「登入後操作」的測試不會出現。
- **`DEV_REFERRAL_CODE`（推廣碼）**：註冊時綁定的代理推廣碼，請填入你要測的那個代理的有效推廣碼。填錯會導致註冊失敗。
- **`DEV_GAME_MEMBER_ACCOUNT` / `DEV_GAME_MEMBER_PASSWORD`（有餘額的會員帳密）**：玩遊戲下注的測試用這組（會真的扣點），刻意跟上面的固定會員分開，免得下注把它的餘額吃掉。沒填的話下注測試自動跳過。

> ⚠️ 改完 `.env` 後，如果 UI 模式正開著，要**關掉重開**才會生效（見下方常見問題）。

---

## 三、開始測試（UI 模式）

這是 QA 最主要的使用方式：

```bash
npm run ui
```

會跳出一個圖形化視窗，操作方式：

1. **左側**會列出所有測試，依 project 分組（`dev-guest`、`dev-logged`、`dev-dangerous`）。
2. 點任何一個測試前面的 **▶ 播放鍵**，就會開始執行。也可以按最上方的 ▶ 一次跑全部。
3. 執行時**右側會顯示瀏覽器畫面**，每一步（點按鈕、填欄位）都看得到。
4. 測試跑完，左邊會顯示 **綠勾（通過）** 或 **紅叉（失敗）**。
5. 點失敗的測試，可以看到**是哪一步失敗、當下的畫面截圖、錯誤訊息**，方便回報問題。

> 小技巧：左上角有搜尋框，可以只篩選你想跑的測試。

---

## 四、測試怎麼分類

**不分前後台**，只依「要不要登入、危不危險」分三種 project：

| Project | 是什麼 | 說明 |
| --- | --- | --- |
| `dev-guest` | 未登入操作 | 不需登入就能做的操作（開首頁、看登入頁） |
| `dev-logged` | 登入後操作 | 需登入、**不留資料**的操作；前台會員／後台管理的登入態都自動備好 |
| `dev-dangerous` | 危險操作 | 會**真的建立資料**的操作（註冊、建立代理；每跑一次留下新帳號） |

> 想跑「不會留資料的安全回歸」，勾 guest + logged 就好。
> 一個測試案例可以**混用前後台的操作**（例如前台註冊時自動到後台撈簡訊驗證碼），每個案例和操作的名稱都會標 `[前台]`／`[後台]`／`[前後台]`，一看就知道動的是哪個站台。

目前的測試案例（測試樹一律是「檔案 > 測試案例 > 操作」，操作依序執行；前面的操作失敗時，後面會顯示為跳過，一眼看出是哪一步出錯）：

- **`dev-guest`**
  - `member_home`：[前台] 驗證首頁可正常載入 — 操作「開啟首頁並確認載入」。
  - `agent_login`：[後台] 驗證登入頁可正常載入 — 操作「開啟登入頁並確認登入表單」。
- **`dev-logged`**
  - `agent_login`：[後台] 驗證登入後可進入後台首頁 — 操作「登入」→「進入後台首頁」，第二步沿用第一步的登入。
  - `member_center`：[前台] 驗證登入態可進入會員中心 — 操作「確認首頁為登入態」→「進入會員中心」，用 `.env` 的固定會員帳號自動登入（詳見第六節）。
- **`dev-dangerous`**
  - `member_register`：[前後台] 驗證會員可完成註冊並為登入態 — 操作「填寫註冊資料並發送簡訊」→「後台從簡訊記錄查驗證碼」→「填入驗證碼完成註冊」→「確認註冊後為登入態」，當場註冊一組全新帳號（詳見第五節）。
  - `game_bet_pxplay`：[前後台] 驗證會員在 Pxplay SW 下注後可在後台報表查到注單與扣點 — 操作「以有餘額的會員登入並記下餘額」→「進入 Pxplay SW 並開啟遊戲」→「下一注並記下結算後的餘額」→「SR12 遊戲投注記錄應查到這一局的注單」→「SR14 現金紀錄表應有這一注且當下點數與前台餘額一致」。用 `.env` 的 `DEV_GAME_MEMBER_ACCOUNT / DEV_GAME_MEMBER_PASSWORD`（要有餘額的會員）。**每跑一次都會真的下一注、真的扣點**，屬正常現象；沒填這組帳密的話整個案例自動跳過。
    - 之後要驗更多遊戲商，就是多幾支 `game_bet_<遊戲商>` 檔案（測試內容共用一份）。**想單獨驗一家**：在 UI 模式點那支檔案（或那個測試案例）的執行鍵。**想驗某幾家**：在 UI 模式上方的篩選框輸入遊戲商名稱、或標籤 `@pxplay`，樹上就只剩符合的，再按「全部執行」；用指令則是 `npx playwright test -g "@pxplay|@megah5" --project dev-dangerous`，要跑全部下注測試用 `-g @game-bet`。
    - ⚠️ 跑這支的時候，**不要自己也用同一個會員帳號登著前台、或開著遊戲視窗**：同帳號重複登入會互相頂掉，被頂掉那邊的餘額會顯示 0.00，測試會誤判成「沒有餘額」。同理，跑任何測試時不要同時用 `.env` 的後台管理帳號操作後台。
  - `agent_management`：[後台] 驗證可建立代理並掛在總代底下 — 操作「建立代理」→「列表查得到且上層為總代」；[後台] 驗證可限制代理登入並解除 — 操作「建立測試代理」→「限制登入」→「驗證操作紀錄」→「解除限制」（功能尚未部署到 dev、或登入帳號沒有 agent_sys_lock 權限時，從「限制登入」起自動跳過）；[後台] 驗證代理帳號停用後無法登入後台 — 操作「建立測試代理」→「停用該代理」→「用停用後的帳密嘗試登入並確認失敗」（這是帳號狀態 Enable/Disable 開關，跟上面的「限制登入」是不同機制；停用後嘗試登入，後台跳出的錯誤訊息跟帳密打錯是同一句，不會特別提示帳號已停用）。**每個案例會在 dev 留下一個 `qaag` 開頭的新代理帳號**，屬正常現象。
  - `member_change_phone`：[前台] 驗證會員可修改手機號並生效 — 操作「登入並發送目前手機號驗證碼」→「後台查詢目前手機號的驗證碼」→「輸入驗證碼並發送新手機號驗證碼」→「後台查詢新手機號的驗證碼」→「完成修改並確認新手機號已生效」，用 `.env` 固定會員帳號登入操作（不是當場註冊）。**每跑一次都會把這組固定會員帳號的手機號永久改成新的隨機號碼**，屬正常現象。

---

## 五、危險操作／註冊流程是怎麼跑的（重要）

`dev-dangerous` 裡用到 `member` 的測試，**會在開始前當場註冊一組全新帳號並登入**，進到測試時前台頁面就是新帳號的登入態。它**不讀取任何預存登入檔**（永遠全新註冊），所以單獨跑任一支（例如 UI 模式只點一支）都能獨立完成。

每支測試開始前（`member` fixture）做的事：

1. 產生一組**隨機新帳號**（帳號會以 `qa` 開頭，方便你事後在後台辨認）
2. 填寫註冊第一步（帳號、姓名、密碼、推廣碼、同意條款）
3. 填手機、按發送、點確認送出簡訊驗證碼
4. **當場另開視窗登入後台 → 到「簡訊記錄」用手機號查出這次的驗證碼**
5. 把驗證碼填回前台，完成註冊並登入；測試可在輸出印出**這次的帳號與密碼**

> 小技巧：把某次註冊印出的帳號密碼填進 `.env` 的 `DEV_MEMBER_ACCOUNT / DEV_MEMBER_PASSWORD`，就能當「登入後操作」的固定會員帳號（見第六節）。

**為什麼要查後台？** 因為 dev 環境的簡訊驗證碼是真的發送、沒有「萬用固定碼」，所以測試改成從後台的簡訊記錄把驗證碼撈回來。這也是為什麼跑註冊測試時，`.env` 裡的**後台網址與帳密是必要的**。

**新增自己的危險操作測試**：在 `tests/dangerous/` 裡新增 `<模組>_<子模組>.spec.ts`，從 `fixtures/sites` 匯入並解構 `frontendPage` 與 `member`，進來 frontendPage 就是「這支測試剛註冊那組帳號」的登入態：

```ts
import { test, expect } from '../../fixtures/sites';

test.describe('[前台] 驗證我的危險操作', () => {
  test('[前台] 我的原子操作', async ({ frontendPage, member }) => {
    // member 帶有這次註冊的帳號資訊（member.username / member.password ...）
    await frontendPage.goto('/member');   // 已是登入態
    // ...做你的回歸項目
  });
});
```

> 每跑一支 dangerous 測試，就會在 dev 建立一個 `qa` 開頭的新帳號。這是正常的，如需清理可到後台用帳號前綴搜尋。

---

## 六、登入後操作（`dev-logged`）

如果你要測「登入後做各種操作」，用 `dev-logged`。前台會員、後台管理的登入態都會自動備好：

- 執行前會**自動先跑登入**（`dev-setup`，因為有依賴關係）：用 `.env` 的會員帳密登入前台、管理帳密登入後台，把登入狀態分別存到 `.auth/dev-frontend.json`、`.auth/dev-backend.json`
- 測試裡用 `frontendPage`（前台）或 `backendPage`（後台）**直接就是登入態，自己不必登入**
- 因為每次都是同一組帳號，帳號上的資料（餘額、紀錄）會**跨執行累積**，適合驗「查看／瀏覽」類操作；會改變帳號狀態的操作要注意前後測試互相影響

**新增自己的操作測試**：在 `tests/logged/` 裡新增 `<模組>_<子模組>.spec.ts`：

```ts
import { test, expect } from '../../fixtures/sites';

test.describe.serial('[前台] 驗證我的操作', () => {
  test('[前台] 我的原子操作', async ({ frontendPage }) => {
    await frontendPage.goto('/member');   // 已是登入態，直接操作
    // ...做你的回歸項目
  });

  test('[後台] 到後台驗證結果', async ({ backendPage }) => {
    await backendPage.goto('/');          // admin 登入態，可直接查資料
  });
});
```

（需要帳號資訊時：`getOperatorSite('dev-frontend')?.credentials`，來自 `config/operators.ts`。）

**三種分類怎麼選？**
- 會建立資料的（註冊、建立代理、下單）→ 用 `dev-dangerous`
- 要登入但不留資料的（查看、查詢）→ 用 `dev-logged`
- 不用登入就能測的 → 用 `dev-guest`

---

## 七、常用指令

```bash
npm run ui        # 圖形化 UI 模式（最常用）
npm test          # 一次跑完全部（無畫面，純文字結果）
npm run report    # 打開上一次執行的詳細報告（含截圖、錄影）
```

只跑某一組：

```bash
npm test -- --project dev-guest      # 只跑未登入
npm test -- --project dev-logged     # 只跑登入後（不留資料）
npm test -- --project dev-dangerous  # 只跑危險操作（會留資料）
```

---

## 八、常見問題

**Q：改了推廣碼（或其他 `.env` 設定）卻沒生效？**
A：`.env` 是在啟動時讀取的。請把 UI 模式關掉，重新 `npm run ui`。

**Q：`dev-logged` 裡的前台測試被跳過（skipped）？**
A：前台的登入後測試要在 `.env` 填了 `DEV_MEMBER_ACCOUNT / DEV_MEMBER_PASSWORD`（會員帳密）才會執行，跳過訊息會寫明缺哪個設定。填好後關掉 UI 重開。

**Q：跑登入後操作，卡在登入（setup 那步紅了）？**
A：多半是 `.env` 的會員帳密不對（帳號被停用、密碼改過）。換一組有效會員帳密；最快的方式是跑一次註冊測試，把它印出的新帳號填進去。

**Q：註冊測試失敗，錯誤是「後台簡訊記錄查無…驗證碼」？**
A：多半是後台帳密不對，或推廣碼無效導致沒真的送出簡訊。請確認 `.env` 的 `DEV_ADMIN_USERNAME/PASSWORD` 與 `DEV_REFERRAL_CODE`。

**Q：註冊測試失敗，卡在「下一步」或某個欄位？**
A：可能是前台改版、欄位變了。這類調整需要工程協助（見下方「給工程／進階」）。

**Q：畫面卡住或瀏覽器沒反應？**
A：先關掉 UI 視窗重跑一次；若持續，執行 `npm run install:browsers` 重裝瀏覽器。

---

## 九、給工程／進階

<details>
<summary>調整登入／註冊的欄位定位、新增測試、新增營運商</summary>

### 專案結構
```
config/operators.ts      營運商清單與 .env 讀取（新增營運商改這裡）
playwright.config.ts     依 .env 動態產生「營運商×分類」的 project（不分前後台）
tests/
  setup/auth.setup.ts          登入 setup（前台會員＋後台管理各寫一份登入檔；自動被依賴，不需手動跑）
  guest/                       未登入測試
  logged/                      登入後、不留資料的測試
  dangerous/                   危險操作（會留資料：註冊、建立代理…）
pages/
  frontend/RegisterPage.ts     前台註冊頁操作（selector 集中在此）
  frontend/LoginPage.ts        前台登入頁（setup 與 frontendPage 補登入用）
  backend/LoginPage.ts         後台登入頁
  backend/SmsRecordPage.ts     後台簡訊記錄查驗證碼
utils/
  random.ts                    隨機帳號產生器（帳號/密碼/手機規則）
  backendOtp.ts                當場登入後台查驗證碼的 helper（不依賴預存狀態）
fixtures/sites.ts              frontendPage / backendPage / member fixtures（站台選擇的核心）
```

### 登入狀態怎麼共用
- `<op>-setup`：跑 `tests/setup/auth.setup.ts`，為有帳密的站台各登入一次，
  把 storageState 寫到 `.auth/<op>-frontend.json`（會員）與 `.auth/<op>-backend.json`（admin）。
- `<op>-logged` / `<op>-dangerous` 在 config 裡 `dependencies: [<op>-setup]`，確保先登入再執行。
- project 本身不設 baseURL / storageState，由 `fixtures/sites.ts` 的
  `frontendPage` / `backendPage` 依「project 分類 × 站台」決定要不要帶登入檔；
  缺登入檔時 fixture 會當場登入（單獨跑一支也不會壞），缺網址/帳密時自動 skip 並寫明原因。
- `member` fixture 不走 setup：每支測試當場註冊，帳號只屬於該支測試。

### 前台改版、selector 對不到
到對應的 `pages/**/*.ts` 調整最上方的 selector。用
`npm run codegen -- <網址>` 開錄製器點選元素，可快速取得正確 selector。

### 新增一支測試
在 `tests/guest/`、`tests/logged/` 或 `tests/dangerous/` 新增 `<模組>_<子模組>.spec.ts`（如 `member_login.spec.ts`），會自動被對應 project 執行。一律 `import { test, expect } from '../../fixtures/sites'`，前台操作用 `frontendPage`、後台操作用 `backendPage`、需要「隨機註冊登入」用 `member`。結構規則（測試案例＋原子操作＋`[前台]/[後台]` 前綴＋`test.step` 中文軌跡）見 CLAUDE.md。

### 營運商限制（重要）
**本專案僅允許 dev 環境**，禁止對任何白標站或正式站執行測試。`OPERATOR_KEYS` 固定為
`['dev']`，`.env` 出現非 `DEV_` 開頭的站台網址時會直接拒絕啟動；且網址主機名
只允許 **example.invalid、example.invalid（皆含子網域）與 .local**，指向其他網域一律拒跑。
若政策改變需要開放，由工程師修改 `config/operators.ts` 的防護邏輯。

### 驗證碼取得方式
dev 沒有固定測試碼，註冊一律自動登入後台（`utils/backendOtp.ts` → `pages/backend/SmsRecordPage.ts`）到簡訊記錄用手機號查最新驗證碼。

</details>

