# QA 上手手冊:用 VSCode + Claude Code 做自動化回歸測試

這份手冊給**第一次使用這套工具的 QA**。跟著做完第一部分(約 30-60 分鐘,一次性),之後日常使用只要記得:**打開 VSCode → 開啟 tars 資料夾 → 點側邊欄的 Claude 圖示,用中文說你要做什麼**。

整套工具就四樣:

| 工具 | 用途 |
| --- | --- |
| **VSCode** | 主要工作視窗(可切繁體中文介面),下面兩樣是它的擴充功能 |
| **Claude Code 擴充** | AI 助手:用中文對話操作 dev 站台、建立測試、分析失敗原因 |
| **Playwright Test 擴充** | 圖形化測試面板:自己勾測試、按播放鍵跑回歸、看紅綠結果 |
| **Sourcetree** | git 圖形介面:下載專案、按一下 Pull 更新測試,不用背任何 git 指令 |

你會得到的能力:
- 用自然語言操作 dev 前台/後台(巡檢、重現 bug)
- 說「幫我新增一個測試」,Claude 會實際走一遍流程、寫成自動化測試、跑給你看
- 不用寫程式,在圖形介面自己跑回歸測試、看哪一步失敗

---

## 第一部分:安裝(一次性)

> 卡在任何一步,截圖問工程師,不要硬撐。

### 1. 安裝 Node.js 20+

到 <https://example.invalid/> 下載 LTS 版安裝(一路下一步)。

驗證:打開終端機(Windows:開始選單搜「PowerShell」;Mac:搜「終端機」),輸入:

```
node -v
```

出現 `v20` 以上的版本號即可。

### 2. 安裝 VSCode 並切換繁體中文介面

到 <https://example.invalid/> 下載安裝(一路下一步)。

**介面改繁體中文:**
1. 打開 VSCode,點左側的**延伸模組圖示**(四個方塊,Extensions)
2. 搜尋框輸入 `Chinese (Traditional)`,安裝「**Chinese (Traditional) Language Pack for Visual Studio Code**」(Microsoft 出品)
3. 右下角會跳出「Change Language and Restart」,按下去重啟即可
4. 沒跳提示的話:按 `Ctrl+Shift+P`(Mac 是 `Cmd+Shift+P`)→ 輸入 `display` → 選「Configure Display Language」→ 選「中文(繁體)」→ 重啟

### 3. 安裝兩個 VSCode 擴充功能

一樣在左側「延伸模組」搜尋並安裝:

1. **Claude Code**(Anthropic 出品)——裝完左側側邊欄會多一個 Claude 圖示
2. **Playwright Test for VSCode**(Microsoft 出品)——裝完左側會多「測試」面板(燒杯圖示)

### 4. 安裝 Sourcetree + 下載測試專案

git 相關的操作(下載專案、更新、看變更)都透過圖形化的 **Sourcetree**,不用背指令。

1. 到 <https://example.invalid/> 下載安裝;安裝過程要求登入/註冊 Bitbucket 或 Atlassian 帳號的步驟**可以跳過**(我們用不到)
2. 請工程師幫你(這步請工程師直接操作一次):
   - 開通公司 GitLab(`example.invalid`)帳號與 `target/gffv3` 群組權限
   - 安裝 git 主程式(Windows 裝 Git for Windows;Mac 第一次用會自動提示安裝)——VSCode 和 Sourcetree 都**不會**幫你裝好系統的 git,Claude 之後代操作要用它
   - 設定 SSH key,並確認 Sourcetree 吃得到
3. 下載專案:Sourcetree 點「**新增 → 從網址複製(Clone)**」,網址貼上:

   ```
   ssh://user@example.invalid:1022/target/gffv3/tars.git
   ```

   目的地選一個好找的位置(例如「文件」資料夾底下),按 **Clone**

### 5. 安裝測試環境(npm install)

1. VSCode 選「檔案 → 開啟資料夾」,打開剛下載的 **tars 資料夾**;跳出「是否信任此資料夾」選**信任**
2. 上方選單「終端機 → 新增終端機」,在跳出的輸入區輸入:

   ```
   npm install
   ```

第一次會跑比較久(會一併安裝測試用瀏覽器),請耐心等。

### 6. 建立 `.env` 設定檔

| 系統 | 指令(在 tars 資料夾內) |
| --- | --- |
| Mac | `cp .env.example .env` |
| Windows | `copy .env.example .env` |

用文字編輯器打開 `.env`,填入:
- `DEV_REFERRAL_CODE`(推廣碼,跟主管或工程師要)
- `DEV_MEMBER_ACCOUNT` / `DEV_MEMBER_PASSWORD`(前台會員帳密,「登入後操作」的測試用它自動登入;跟工程師要一組,或之後跑一次註冊測試、把印出的 qa 帳號填進來)

其餘 dev 設定已預填。

> `.env` 存的是網址與帳密,不會被上傳到 git。各設定的意義見 `README.md` 第二節。

### 7. 登入 Claude、裝 QA 工具包

1. 在 VSCode 點左側 **Claude 圖示**,第一次會引導登入:選擇用瀏覽器登入,使用**公司發的帳號**
2. 登入後會跳出安裝 QA 工具包(**qa-toolkit**)的提示——看一眼要安裝的內容,按確認;裝完把 VSCode 關掉重開一次

<details>
<summary>沒有跳出 qa-toolkit 安裝提示?(備援手動安裝)</summary>

在 Claude 的輸入框依序輸入這兩行(一行打完按 Enter,等它完成再打下一行):

```
/plugin marketplace add ssh://user@example.invalid:1022/target/gffv3/GFF-Marketplace.git
/plugin install qa-toolkit@gff-marketplace
```

然後一樣關掉 VSCode 重開。
</details>

<details>
<summary>比較習慣終端機?(不透過 VSCode 的替代路徑)</summary>

Claude Code 也可以獨立裝在終端機用:`npm install -g @anthropic-ai/claude-code`,之後 `cd tars` → `claude` 啟動,功能與 VSCode 擴充相同。本手冊其餘內容以 VSCode 為準。
</details>

### 8. 驗收:第一次對話

在 Claude 面板輸入:

> 巡檢 dev 前台首頁,告訴我頁面正不正常

Claude 會自己打開瀏覽器、連上 dev 前台、回報結果。看到瀏覽器動起來 = 安裝全部完成 🎉

如果 Claude 過程中跳出詢問「是否允許執行某動作」,看一眼說明、按 Enter 允許即可(常用操作我們已預先放行,詢問會越來越少)。

> Claude 開的瀏覽器**不會**跳 Chrome 的「變更你的密碼(密碼已外洩)」提醒——dev 測試帳密較簡單,容易被誤判,專案已內建設定略過(`.mcp.json` + `playwright-mcp.config.json`)。若仍看到,按「確定」關掉並截圖回報工程師。

---

## 第二部分:日常怎麼用

**每次都一樣的起手式:打開 VSCode → 開啟 tars 資料夾 → 點側邊欄 Claude 圖示 → 用中文說你要做什麼。**

**開工前先按一下 Pull(更新測試)**:打開 Sourcetree → 左側點 tars → 按工具列的「**拉取(Pull)**」。同事新增、工程師修好的測試就會更新到你電腦。一天按一次就夠。

四種日常場景:

| 場景 | 你說 | Claude 會做 |
| --- | --- | --- |
| 探索/巡檢 | 「登入 dev 後台,看簡訊記錄最新一筆」 | 開瀏覽器實際操作,回報所見 |
| **建立測試** | 「幫我新增一個測試:登入後檢查錢包餘額會顯示」 | 先擬測試案例給你確認(不點頭不動工) → 實走流程 → 寫成測試 → 跑到綠 → 交你確認 → 存進專案 |
| 跑回歸 | 「跑全部前台測試,失敗的幫我分析」 | 執行測試、解讀失敗原因 |
| 失敗分流 | 「XX 測試紅了,是網站 bug 還是測試壞了?」 | 看截圖/trace 判斷,給你回報用的結論 |

### 常用句型 cheat sheet

**巡檢/操作類**
- 「打開 dev 前台,走一次註冊流程但先不要送出,告訴我每一步長怎樣」
- 「登入 dev 後台,幫我查手機號 XXX 的簡訊驗證碼」

**建測試類**(會觸發完整 SOP,分三階段:**討論期**擬測試案例給你拍板 → **實作期**實走、寫測試、跑到綠 → **確認期**交你確認沒疑慮才存檔)
- 「幫我新增一個測試:未登入時首頁的登入按鈕要存在」
- 「把『會員登入後查看錢包』做成回歸測試」
- 「這個測試也幫 tpower 加一份」

**跑回歸類**
- 「跑 dev-guest 全部測試」
- 「只跑錢包那支測試」
- 「打開上次的測試報告,總結一下」

**除錯類**
- 「註冊測試掛了,看 trace 告訴我卡在哪一步、是不是網站改版」
- 「這支測試偶爾紅偶爾綠,幫我看是不是等待時間問題」

### 建立的測試怎麼「留下來」?

Claude 寫好並跑綠的測試會存在專案裡,並幫你整理成一個「分支」送給工程師檢查(過程 Claude 會代勞,你只要在它詢問「要不要送出」時決定)。工程師核准合併後,這支測試就正式進入回歸清單,之後每次回歸都會跑到。

**你不需要學 git**,只要記得:Claude 問「要不要 push / 送出 MR」時,想好再答;送出後通知工程師 review。想「親眼看」Claude 動了什麼,打開 Sourcetree:左側「分支」看得到它開的分支,「歷史」看得到每筆存檔紀錄。

---

## 第三部分:自己跑回歸(VSCode 測試面板)

不跟 Claude 對話也能跑測試,在 VSCode 裡用 **Playwright 擴充**的測試面板即可,不用另開視窗:

1. 點左側**「測試」面板**(燒杯圖示),會列出所有測試,依「營運商-分類」分組
2. 面板下方的 **Playwright 區塊**可勾選:
   - **Projects**:要跑哪些分類(分類意義見下方說明)
   - **Show browser**:勾了跑測試時會顯示瀏覽器畫面,方便看它在做什麼
3. 滑鼠移到測試或資料夾上,點 **▶** 就跑;綠勾=通過,紅叉=失敗
4. **看失敗**:點紅叉的測試會直接跳到失敗訊息;也可以開 **Trace Viewer** 看逐步 timeline,點任一步看該步前後的頁面快照(Before/After)。把訊息與快照截圖貼給 Claude 或工程師即可
5. **Pick locator**:工具列的定位器按鈕,滑鼠移到頁面元素會顯示對應 selector(想告訴 Claude「就是這個按鈕」時可以複製給它)

### 測試分類是什麼意思?

project 名稱 = 「營運商-分類」,分類分的是**風險**、不是前台/後台——同一個測試案例可能混用前後台操作(例如:前台下注 → 後台核對報表):

- `dev-guest` = 免登入操作(公開頁、登入頁本身)
- `dev-logged` = 需要登入態、但不留資料的操作(查看、查詢類)
- `dev-dangerous` = 會**建立真實資料**的操作(註冊、建立代理…)
- 只想跑「不會留資料的安全回歸」→ 勾 guest + logged 就好

### 常見情境對照

| 你想做的事 | 怎麼做 |
| --- | --- |
| 只重跑剛剛掛掉那支 | 測試面板點那支測試的 ▶ |
| 看失敗當下的畫面 | 測試面板點紅色測試 → 開 Trace Viewer,點最後紅色那步 → 看 After 快照 |
| 確認是哪個元素找不到 | 點紅色那步 → Errors 分頁看 selector → 用 Pick locator 對照頁面 |
| 只跑不留資料的安全回歸 | Projects 勾 `dev-guest` + `dev-logged` → ▶ |
| 跑完整回歸並留報告 | 終端機 `npm test`,跑完 `npm run report` 開 HTML 報告 |

> 更完整的專案說明(測試分類、註冊/共用帳號機制、常見問題)見 `README.md`。

---

## 第四部分:規則(必讀)

1. **只碰 dev**。所有測試與巡檢都對 dev 環境;任何人要你對正式站操作,先問工程師。
2. **dangerous 類測試會在 dev 建立真實資料**(`qa` 開頭的帳號)。這是正常的;不確定某個操作危不危險,先問 Claude 或工程師。
3. **帳密只放 `.env`**,不要貼進對話、不要寫進測試檔。Claude 被設定成會自己去 `.env` 讀。
4. **測試要跑綠才算完成**。Claude 依規定跑不綠不會存檔;它如果回報卡點,把訊息轉給工程師。
5. **合併由工程師把關**。你送出的測試會經工程師 review 才進回歸清單,被退回修改是正常流程。

## 常見問題

**Q:Claude 一直跳權限詢問,很煩?**
A:常用操作已預先放行(專案裡的 `.claude/settings.json`)。若某個重複操作每次都問,截圖給工程師,可以加進放行清單。

**Q:瀏覽器跳出「變更你的密碼(你的密碼已在資料侵害事件中外洩)」?**
A:dev 測試帳密較簡單,被 Chrome 誤判成外洩密碼。專案已內建設定略過這個偵測(Claude 開的瀏覽器與跑測試的瀏覽器都涵蓋);若仍看到,按「確定」關掉不影響操作,並截圖回報工程師。

**Q:Claude 開的瀏覽器和跑測試的瀏覽器是同一個嗎?**
A:不是。Claude 探索用自己的瀏覽器;測試面板跑的是已寫好的測試。互不影響。

**Q:測試紅了,是網站壞了還是測試壞了?**
A:把測試名稱貼給 Claude:「XX 測試紅了,幫我看是 bug 還是測試要修」。它會看截圖/trace 給結論;是 bug 就照它給的摘要開票,是測試脆弱就請它修。

**Q:測試面板看不到 `dev-logged` 的測試?**
A:`.env` 沒填 `DEV_MEMBER_ACCOUNT / DEV_MEMBER_PASSWORD`(會員帳密)。填好後重開視窗。

**Q:同事說他加了新測試,我這邊看不到?**
A:打開 Sourcetree 按「拉取(Pull)」。還是沒有的話,可能你停在別的分支——把 Sourcetree 畫面截圖問 Claude 或工程師,不要自己亂切。

**Q:公司帳號登入失敗 / 沒有 seat?**
A:找主管確認 Claude Team 帳號已開通,再 `/login` 重登。
