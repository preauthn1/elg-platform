---
name: git-commit
description: GFFv3 內部 repo 的 commit message 規範與歷史收斂。當要寫或改 commit message、問 commit 格式，說「收斂 commit／整理 commit／amend／重整歷史」，或說「push／可以推了／開 MR」（push 前先做收斂檢查）時載入。單講「收斂」指整理 commit；分支、MR、清理走 release-workflow。
---

# git-commit：把歷史寫成團隊看得懂的敘事

一句話版本：**每個 commit 是一個章節，message 用大白話講清楚「改了什麼、為什麼」；push 之前把章節整理好，因為 push 之後就很難再改。**

| 部分 | 回答的問題 |
|---|---|
| 一、Commit message 規範 | 一個 commit 的 message 該長什麼樣、怎麼驗收 |
| 二、歷史整理（收斂） | 什麼時候該併、該拆；怎麼安全地併；push 前要檢查什麼 |
| 附錄 | 容器環境、hook 放行規則、hook 安裝 |

## 適用範圍與邊界

- 只適用公司內部 GFFv3 repo（`APIs`、`API_Agent`、`API_External`、`API_Frontend`、`Hyperf/*`、`gff-marketplace` 等）。type 表與 hook 行為皆以此為前提；個人專案或外部 OSS repo 依該 repo 自己的規範。
- 本 skill 管：單一 commit 的 message 格式與驗收、commit 前的歸屬判定、amend／fixup／soft reset 三種收斂技法、push 前的收斂檢查、要不要 force push 的判斷。
- 本 skill 不管：branch／worktree／develop／MR／發布／清理的生命週期，一律走 `release-workflow` skill，本文不重述。
- 載入時機：要建立或改寫 commit message、問 commit 格式、準備提交代碼；說「收斂／收斂 commit／整理 commit／amend／把修正併回前面的 commit／重整歷史」；說「push／可以推了／推上去／開 MR」（push 前先對未推的 commit 做收斂檢查）。
- 單講「收斂」一律指整理 commit。要清掉已合併的分支或 worktree，請說「清理分支」「清理 worktree」，走 `release-workflow`。

## 總則：歷史是寫給團隊的敘事，不是流水帳

git 歷史的讀者是團隊：之後接手的人、查 `git blame` 找回歸的人、review 這條分支的人。歷史要回答「這條分支做了什麼、為什麼這樣做」，讓沒跟過這條線的成員看 log 就能進入狀況；不是「我今天依序做了哪些動作」的工作日誌。

兩條推論，分別由兩個部分落實：

1. **每個 commit 是敘事的一個章節**：一個完整、站得住的變更，不是時間軸上的一格。「先寫錯、後面再修」是流水帳，要收斂掉（第二部分）。
2. **每段敘事要讓讀者當場讀得懂**：用大白話，不用速記代號；引用附出處，但出處不能代替解釋（第一部分）。

---

# 一、Commit message 規範

## 1.1 格式

一個完整的 message 長這樣。trailer 沒有可寫的就省略（Agent 提交時必附的兩行見下方「Agent 提交時的 trailer」）；body 在標題說得完時可省略，但涉及取捨的變更一律要寫（見 1.4「涉及取捨的變更」）：

```
<type>[(scope)]: <描述>

<body：改了什麼、為什麼改、影響範圍、怎麼驗證；涉及取捨時寫選了什麼、沒選什麼、為什麼；條列優先>

<trailer：Refs、Co-Authored-By、Claude-Session 等機器可讀的附註>
```

| 規則 | 限制 |
|---|---|
| 標題格式 | `<type>[(scope)]: <描述>`，冒號後一個空格，type 一律小寫 |
| 標題長度 | ≤ 100 字元（含 type） |
| 描述最短 | ≥ 4 字 |
| scope | 選填；填模組／plugin／skill 的原名，例：`feat(payment)`、`skill(git-commit)`；不自創縮寫 |
| body | 標題說得完就不寫；有原因、影響範圍、驗證方式才寫。涉及取捨的變更一律要寫，判定與內容見 1.4「涉及取捨的變更」。標題與 body 之間空一行 |
| trailer | 放在 message 最後，前面空一行；`Refs: target/tickets#NNNN`、`Co-Authored-By: Claude <模型名稱> <user@example.invalid>`、`Claude-Session: <URL>` 都屬此類 |
| 語言 | 繁體中文；英文技術名詞保留英文 |
| 多行 message 的寫法 | 先寫進檔案，再 `git commit -F <檔案>`。不用 `-m` 串多行，中文多行走 `-m` 容易被 shell 吃掉換行 |

**Agent 提交時的 trailer**：Agent 建立或改寫的 commit，在 message 最後附上兩行。讀歷史的人看得出這顆有 AI 參與、用的是哪個模型，需要時能回查當時的對話。

```
Co-Authored-By: Claude <模型名稱> <user@example.invalid>
Claude-Session: <這場對話的網址>
```

- `Co-Authored-By` 記錄當時使用的模型，例如 `Co-Authored-By: Claude Opus 5 <user@example.invalid>`，跟 Claude Code 預設的署名同一個格式。Claude Code 有提供署名文字時照它給的原樣寫；沒有提供時照上面的格式寫，模型名稱用執行環境告知的名稱。同一顆 commit 只留一行署名。
- `Claude-Session` 寫這場對話的網址。執行環境沒有提供網址時，改寫 session 代號（環境變數 `CLAUDE_CODE_SESSION_ID`；在同一台機器上可用 `claude --resume <代號>` 回到那場對話）；兩者都取不到就省略這一行。本機的對話紀錄有保留期限（Claude Code 設定的 `cleanupPeriodDays`），過期後代號就回不去。
- 兩行的值都只寫取得到的實際值，不填推測值。
- 人自己手寫的 commit 不需要這兩行。

## 1.2 有效類型（type）

| Type | 適用場景 | 範例 |
|---|---|---|
| `feat` | 新增功能 | `feat: 新增 TngPin 代收金流商` |
| `fix` | 修復 Bug | `fix: 修正 DepositCallback 回調冪等性問題` |
| `refactor` | 重構（不影響行為） | `refactor: 統一使用 findAndLockDepositOrder 查詢訂單` |
| `test` | 新增或修改測試 | `test: 補充 TopPay 代付回調測試案例` |
| `chore` | 雜項維護（依賴更新、設定調整） | `chore: 更新 composer 依賴至最新版本` |
| `docs` | 文件變更 | `docs: 補充遊戲商接入流程說明` |
| `perf` | 改善效能 | `perf: 優化提款訂單查詢索引` |
| `revert` | 回滾先前的 commit | `revert: 還原錯誤的 Migration 變更` |
| `style` | 調整程式碼風格（Pint 格式化） | `style: 執行 Pint 統一縮排格式` |
| `ci` | CI/CD 相關設定變更 | `ci: 更新 pipeline 快取策略` |
| `ai` | AI 輔助生成的設定 | `ai(vendor): 優化帳號綁定並發防護邏輯` |
| `skill` | 新增或修改 AI skill / command 設定 | `skill(game-integration): 補充 isMemberAbleToBet 說明` |

`revert` 兩種寫法都合規：`git revert` 自動產生的 `Revert "..."`（hook 自動放行，見附錄 B），或手寫 `revert: <描述>`。

## 1.3 合規 vs 不合規範例

```bash
# ✅ 合規
feat: 新增 PGSoft 轉帳錢包遊戲商 target/tickets#3668
feat(payment): 新增 TngPin 代收金流商 target/tickets#3702
fix(wallet): 修正提款回調金額精度計算錯誤
refactor: 將簽名邏輯抽取至 Trait
test: 新增 EpicPay 代收完整測試案例
perf: 改善會員餘額查詢快取策略

# ❌ 不合規
update something          # 缺少 type
feat:新增功能              # 冒號後缺少空格
FEAT: 新增功能             # type 必須小寫
feat: ok                  # 描述過短（< 4 字）
feat: 新增 XX 金流商        # feat 強制需要 ticket（hook + CI 會擋）
docs: §2.14 轉狀態         # 內部節號，讀者無從查起（見 1.4）
```

> ⚠️ **`feat` 強制需要 ticket**：hook（`.githooks/commit-msg.sh`）與 GitLab CI 的 `lint:commit-message` 都會檢查 `feat` 類型的 commit 必須帶 `target/tickets#NNNN`（或 `target/ticket_tasks#NNNN`、`target/op_tickets#NNNN`、Refs／裸 GitLab URL）。可放在標題或 body。其餘類型不強制。`gff-marketplace` 例外：它沒有這支 hook，CI 也沒有這個 job（見附錄 C），`feat` 不要求工單。

**新功能沒有 ticket 時，先開單再提交，禁止為了避開檢查改填其他類型。** commit 類型要反映改動的性質，讓讀歷史的人能據此分類；把新功能寫成 `fix` 會讓類型失去意義。Agent 遇到這種情況：

1. 停下來告知使用者：這是新功能，`feat` 需要工單。
2. 問使用者要用哪一張既有工單，或要不要開新單。開新單時，需求單走 `pm-ticket-create`（`target/tickets`），線上問題走 `op-ticket-create`（`target/op_tickets`）；兩個 skill 都在 op-toolkit plugin，沒安裝就請使用者自己開好再給單號。
3. 拿到單號才提交。

改動確實是修 bug、重構或其他類型時，照實際性質選類型，不受這條限制。

`gff-marketplace` 不適用「先開單」：新功能照實用 `feat`，有工單就附上，沒有也不必為了提交去開單。

## 1.4 描述要寫給團隊看：用大白話，不用速記

本節只管 commit message。MR description 的結構另見 `mr-report-writing` skill，它的可理解性要求與本節相同。

讀者是沒有參與討論、沒有看過計畫文件的開發者。先用大白話說「什麼東西改了、改成怎樣、為什麼要改」，再給必要的技術細節。不要寫成工作筆記，也不要要求讀者先學會本次討論的代號。

標題必須獨立說明主要變更；body 補充原因、影響與驗證。不硬湊不適用的項目；不得把「待決策」寫成「已決定」，或把「整理文件」寫成「功能已完成」。

### 固定判定規則：不用猜它算不算內部代號

四條規則適用標題、body、清單、表格及引用文字。加上引號、反引號、括號或解釋，都不會讓速記變成合規。

1. **敘述中不用文件定位碼或計畫編碼。**
   包括節號符號、圈號、用字母或數字區分的方案／階段／軌道／任務／優先級，以及換成中文的同義寫法。
   例如「§2.4」「軌①」「方案 D」「第 4 級」「Task 3」「P0」一律改成實際內容。
   是否為文件正式名稱、是否正在修改該文件、是否已經解釋過，均不構成例外。
   本次 message 自己的普通條列序號，以及數量、日期、版本、實際資料值，不屬於計畫編碼。

2. **技術字串只保留可核對的原名，不自行縮短。**
   保留實際程式、schema、設定或工具中的完整識別碼；首次出現時附上它在本次變更中的用途。
   例如「cash_flow_banks（金流商提供的銀行資料表）」。
   不寫「cfb」；不寫「g674」，改寫為「agent_group_id = 674（代理群組識別碼）」。
   命令保留完整名稱，不把 `bank:set-amount-limits` 縮成 `set-amount-limits`。
   無法核對的簡稱改用一般中文；不得以「團隊應該知道」放行。
   領域名詞用正名：幣別、代理組別、金額換算這類平台名詞，有 APIs checkout 時以其根目錄 `GLOSSARY.md` 詞彙表為準、避開它列為避免使用的別名（例：不寫「幣種」，寫「標準幣別」）；沒有 checkout 時照原詞，不猜正名。

3. **出處只負責定位，不能代替解釋。**
   文件引用放在 body，使用完整相對檔案路徑與有意義的節名，不使用節號。
   真實路徑中的字母、數字或代號原樣保留，不能為了避開規則改壞路徑；但不得再拿檔名中的代號當敘述主詞。
   工單、MR、commit SHA、URL 可保留原始識別碼，並標明用途。
   程式位置使用完整相對檔案路徑加行號，不使用省略檔名的 `:行號`。

4. **先解釋，再考慮精簡。**
   對象、動作、結果必須寫清楚。技術術語可保留，但首次出現要用一句短句交代用途。
   標題超過 100 字元時，把次要原因及技術細節移到 body，不得縮回代號。

### 白話改寫範例

| 不合規 | 合規 |
|---|---|
| 備妥續推方案 D 與否的裁決材料 | 整理各白標是否改用同一份銀行主檔的決策資料 |
| 等軌①收斂完成 | 等每個代理群組只剩一套銀行代號 |
| 等第 4 級完成 | 等各白標改用同一份銀行主檔 |
| 更新 §2.4-3 | 更正命令退役結論：整批設定限額仍需要保留 |
| 補上 cfb 對映 | 補上金流商銀行資料表 cash_flow_banks 的對應資料 |

改寫必須符合 diff 與來源；範例不是可直接套用到其他變更的事實。

### 涉及取捨的變更：body 必須寫選了什麼、沒選什麼、為什麼

這條在防什麼：理由沒有地方放，就會永久消失。設計文件不保證長期存在（APIs 的設計文件與實作計劃落實後就刪除、不歸檔），代碼本身也不記理由。之後還能跟著代碼留下「為什麼這樣選、為什麼刻意不做」的，只剩 commit body 與代碼註解。MR description 不會跟著進 git 歷史：實測 APIs 的 merge commit 只帶 MR 標題與連結，用 `git log`、`git blame` 追代碼的人看不到 description。理由沒寫進 body，下一個人只能重新猜，或把刻意不做的事當成遺漏補回去。

**判定規則：符合任一項就算取捨。**

1. 有兩個以上可行的做法，這次選了其中一個。
2. 刻意不做某件事，或刻意保留舊行為不改。
3. 接受一個已知的代價或限制，例如換來簡單，但某種情境仍會出錯。
4. 推翻或修正先前的決定。
5. 寫死一個光看代碼看不出來由的值、例外清單或執行順序。

依設計文件或已拍板的規格實作時同樣適用。文件落實後可能被刪除，文件裡的取捨要在這時寫進 body。

不算取捨的變更：修錯字、純格式化、機械式改名、依既有慣例照抄一筆設定。這類變更只有一種合理做法，body 照 1.1 的規則，標題說得完就不寫。

**body 必須寫出三項，缺一項就重寫：**

| 項目 | 寫什麼 |
|---|---|
| 選了什麼 | 這次採用的做法 |
| 沒選什麼 | 至少一個被排除的做法；刻意不做的事也寫在這裡。只寫「評估過其他做法」不算數，要寫出被排除的是哪一個 |
| 為什麼 | 排除的理由與依據，例如實測結果、數字、工單、拍板紀錄。依據只是推論、沒有實證時，標「未驗證」，不寫成定論 |

寫法不限，可以條列，也可以寫成段落；移除出處後三項仍要讀得懂（見 1.5 可讀性驗收的移除出處測試）。分支上試過又放棄的做法，是「沒選什麼」最好的素材，收斂 commit 時不要一起丟掉（見 2.0「收斂後 message 重寫」）。

```
# ❌ 不合規：看得出改了什麼，看不出為什麼不用別的做法
docs: 刪除已落實的設計文件目錄

# ✅ 合規
docs: 刪除 28 個已落實或已作廢的設計文件目錄，不歸檔

選了：落實的設計文件直接刪除，需要考古時用 git log 查。
沒選：搬進 archive/ 目錄歸檔。歸檔解決不了「沒人再讀」，只讓體積繼續累積。
為什麼：盤點 15 個主題，15 個都與代碼脫節，沒有一個在代碼改動後同步過文件；
已有 3 例錯誤是從文件抄進代碼註解的。
```

## 1.5 可讀性驗收（每次建立或改寫 message 前執行）

提交前確認 message 過得了 1.4 的四條規則與取捨判定。最直接的檢驗是移除出處測試：暫時拿掉路徑、工單、MR、SHA、URL 與程式定位後，剩下的文字仍答得出「改了什麼、改成怎樣」；body 主張的原因或影響也要能獨立理解，只剩「更新狀態」「完成方案」這類空話就重寫。白話改寫不得改變主體、範圍、否定、完成狀態或待決策事項。標題長度用 `wc -m` 或 python 的 `len()` 實際計算，不估。

新建、amend、reword、收斂後重寫、批次重整都適用。操作回報附一行：「message 驗收：速記 0 處；移除出處後仍可理解；取捨：<三項已寫｜不涉及，屬於某類只有一種做法的變更>；標題 N 字元。」此回報不寫進 commit message。

例外：git 自動產生且未人工改寫的 Merge／Revert 訊息，以及待 autosquash 的 `fixup!`／`squash!` 暫存訊息，不改寫其必要前綴。人工新增的敘述與收斂後的最終 message 仍須驗收。hook 或 CI 放行不代表通過可讀性驗收。

---

# 二、歷史整理（收斂）

「收斂」的意思：把散成好幾顆的同一件事併回一顆，讓每顆 commit 單獨看都是一個完整的變更。本部分先講整章通用的鐵則，再依「什麼時候判定」「怎麼併」「push 前怎麼檢查」的順序展開。

## 2.0 鐵則（整章通用）

| 鐵則 | 說明 |
|---|---|
| **只動未 push 的 commit** | 已 push 的 commit 一律用新 commit 追加修正。改已 push 的歷史就得 force push（強制覆蓋遠端），須使用者確認，見 2.5。**合進 develop（並 push develop）的 commit 也算已 push**，即使工作分支本身還沒有 upstream、`git status` 看起來能改：amend 或 rebase 會讓同一份改動在 develop 與發布分支上變成兩組不同的 commit，下一個把發布分支補進 develop 的人就會撞到衝突。判斷方式：先 `git fetch origin develop`，再 `git merge-base --is-ancestor <sha> origin/develop`，回傳 0 就代表已在 develop 上。 |
| **動歷史前先留回復點** | amend 以外的改寫（2.3 的 rebase、2.4 的 soft reset）先打 `git tag backup/<topic>-<日期> HEAD`。做完後跑 `git diff --quiet backup/<topic>-<日期> HEAD`，它不印任何東西，要看 exit code：exit code 為 0（例如 `git diff --quiet backup/<topic>-<日期> HEAD && echo SAME || echo DIFF` 印出 SAME）才算零遺失（tree 逐位元一致）。push 完成後才刪 tag。 |
| **收斂後 message 重寫** | 依合併後的完整範疇重寫，並通過 1.5 驗收。修正過程帶出的有價值發現（例：三方文件與實際回應不符）寫進 body，不因「當作一開始就寫對」而丟失。分支上試過又放棄的做法同理：它是 1.4 取捨三項裡「沒選什麼」的素材，併掉那顆 commit 時理由要留在 body。 |
| **猶豫時寧散勿誤併** | 分不清是「修自己的錯」還是「行為變更」（例如會影響已上線行為的判讀），就開新 commit 並說明原因。誤併會永久抹掉兩件事的邊界，散落至少還能再收。 |
| **做完當場讀回** | 每次 commit／amend／rebase／reset 後用 `git log --oneline -3 --stat` 與 `git status` 讀回結果；回報用讀回的 sha，不憑記憶、不預測。 |

## 2.1 Commit 前的歸屬判定（每次建 commit 前執行）

每次要建 commit 前，先看分支上未 push 的既有 commit，判定這次變更該「併進某一顆」還是「新開一顆」，並在回報中講明結果（「併入 <sha> <標題>」或「新開 commit」）。

| 判定 | 條件 | 處理 |
|---|---|---|
| **併入（收斂）** | 本次變更是在**修正本分支某個未 push commit 自己引入的缺陷**：動的是該 commit 新增／修改的檔案或邏輯，目的是把它做對，不是新需求 | 目標是 HEAD → 2.2 amend；目標更早 → 2.3 fixup |
| **新開 commit** | 新功能、新範疇、對分支開出前就存在的代碼的獨立修改、文件的新增內容 | 正常 commit |

實際判例（同一條分支上的幾段）：

- SurePay 通道查詢實作 commit 之後，發現「三方實際回應的欄位名與大小寫和實作吃的不符」的修正 → **併入**實作 commit。它從未正確過，分開留著的是已被推翻的中間狀態。
- 同一支 SurePay 的 api.md 補「實測補充」一節 → **新開**。這是新增內容，不是修錯。
- 混合變更（一部分修錯、一部分新內容）→ **拆開 staging 分兩次提交**，不揉成一包。

Staging 紀律：以檔案為單位 `git add <檔案>`，commit 前 `git diff --cached --stat` 核對進 staging 的就是這一顆要的內容；不用 `git add -A`／`git add .` 一次全收，混合變更會揉在一起。

同一個檔案裡混了兩件事時逐 hunk 分：`git diff -- <檔案> > <patch 檔>`，只留這一顆要的 hunk，再 `git apply --cached <patch 檔>`。`git add -p` 是互動命令，本環境不可用。

## 2.2 併入 HEAD：amend

觸發：使用者說「amend」「補充到上一個 commit」「整合進前一個 commit」「合併到前一個 commit」；或 2.1 判定併入且目標是 HEAD。

amend 的意思是「把目前 staged 的變更加進最後一顆 commit，並重寫它的 message」。它只能動 HEAD，且 HEAD 必須還沒 push、也還沒合進 develop（見 2.0 鐵則）。

**Step 1：先確認 HEAD 未 push、未合進 develop，再蒐集三份資訊（可平行執行）**

```bash
git status               # 顯示 ahead of 'origin/...' 或分支尚無 upstream → 可 amend；顯示 up to date 或 diverged → HEAD 已在遠端，停止 amend、改新 commit
git merge-base --is-ancestor HEAD origin/develop && echo IN-DEVELOP   # 有 develop 的 repo：先 fetch；印出 IN-DEVELOP 即已合進 develop，停止 amend、改新 commit
git diff --cached        # 目前新 staged 的異動（可能為空）
git show HEAD            # 前一顆 commit 的完整 diff + message
git log --oneline -5     # 近期脈絡，確認 amend 對象正確
```

為何要 `git show HEAD`：只讀前一個 message 字串無法得知原本改了哪些檔案、哪些邏輯；必須看完整 diff 才能在重寫 message 時準確涵蓋原本的範疇。

**Step 2：判斷情境**

| 情境 | 對應處理 |
|---|---|
| 有新 staged 內容 | 合併「前一顆 commit 全部異動」＋「新 staged 異動」，寫出涵蓋兩者的新 message |
| 無新 staged 內容（純整理 message） | 基於 `git show HEAD` 的完整 diff 重新撰寫更精確的 message |

**Step 3：驗收完整 message**

依 1.5 可讀性驗收確認並附回報行。未通過不得進入 Step 4；純 message 整理也不得省略。

**Step 4：執行 amend**

```bash
git commit --amend -F <message 檔>
```

**Step 5：讀回確認**

```bash
git log -1 --stat        # 讀回 sha、標題、檔案清單
git status               # 應仍為 ahead（或無 upstream）；若變成 diverged，代表改到已 push 的歷史，依 2.5 處理
```

## 2.3 併入非 HEAD：fixup + autosquash

目標 commit 不是 HEAD 時 amend 搆不到。做法：先把修正掛成一顆 `fixup!` 暫存 commit，再用 rebase 的 autosquash 功能自動把它壓回目標 commit。

```bash
git commit --fixup=<目標 sha>                  # 修正以 fixup! 暫掛（訊息自動生成，hook 對 fixup! 前綴放行）
git tag backup/<topic>-<日期> HEAD             # 回復點：打在 fixup commit 之後，rebase 前後的 tree 才比得出 SAME
git rebase --autosquash "<目標 sha>^"          # 非互動壓回目標 commit
git diff --quiet backup/<topic>-<日期> HEAD && echo SAME || echo DIFF    # 印出 SAME（exit code 為 0）才算零遺失
```

- **非互動可用**：需 git ≥ 2.44（不需要 `-i`，不會開編輯器）；不確定所在環境的版本時，先跑 `git --version` 核對。
- **途中衝突 → `git rebase --abort`**（實測可完整回到 rebase 前狀態）→ 放棄併入、保留獨立 commit 並說明。不在 rebase 衝突裡手工解：收斂是整理動作，不值得引入解衝突的風險。
- `--fixup` 會**保留目標原 message**。若 message 也要重寫：壓回後目標已是 HEAD → 接著走 2.2；仍非 HEAD → 原 message 已通過 1.5 驗收時可保留、把補充寫進 MR description；原 message 不合規時 MR 補充不能代替修正，改走 2.4 重整。若目前授權不涵蓋該操作，停止改寫並回報未完成項，不得宣稱 message 整理完成。
- `--fixup=amend:<sha>` 無法與 `-m` 併用（`fatal: options '-m' and '--fixup:amend' cannot be used together`，實測），非互動環境不走那條。
- rebase 是歷史改寫：執行前確認目標範圍全部未 push、也未合進 develop（2.0 鐵則）；做完後照 2.0 鐵則讀回，並依 2.5 的表判讀 `git status`。

## 2.4 按主題重整：soft reset 分組

要把一批 commit 按主題重新分組（不只是併一顆）時用這節。`git rebase -i` 在本環境不可用（Bash 工具不支援互動旗標，`git add -i` 同），所以走「退回基準點、變更留在工作區、按組重新 commit」的做法：

```bash
git tag backup/<topic>-<日期> HEAD              # 1. 回復點
git merge-base --is-ancestor <base> HEAD        # 2. 基準點必須是 HEAD 的祖先（exit 0 才繼續）
git diff --stat <base>..HEAD                    #    變更全集＝重分組的素材，記下檔案總數
git reset --mixed <base>                        # 3. HEAD 退回基準點；變更留在工作區、index 清空
# 4. 逐組重複：
git add <該組檔案>
git diff --cached --stat                        #    每組獨立核對，不沿用前一組的判斷
git commit -F <message 檔>
# 5. 全部做完：
git diff --quiet backup/<topic>-<日期> HEAD && echo SAME || echo DIFF     #    印出 SAME（exit code 為 0）才算零遺失
```

三個要點：

- **基準點取分岔點**：`git merge-base origin/<發布分支> HEAD`，也就是自己的分支從發布分支分出來的那一顆。不可直接拿最新的 `origin/<發布分支>`：它含有別人在分岔後合進去的 commit，reset 到那裡會把別人的變更當成自己的改動反轉掉。
- **分組依「最終檔案歸屬」而非原 commit 邊界**：同一支舊 commit 動到的檔案可以拆進不同組。先用第 2 步的檔案總數核對各組加總相符、無重疊；同一檔案跨組時改逐 hunk 處理（做法見 2.1 的 Staging 紀律），或放棄該次拆分。
- **tree 比對是零遺失的決定性證據**：tree hash 相同代表逐位元一致，比「測試綠」更強；測試只能證明沒壞。

附帶兩點：拆分後中間那顆可能不再自洽（bisect 會壞），`git checkout <中間 commit>` 跑一次該組相關測試即可驗，驗完切回分支。重整後舊 sha 全數失效，文件與筆記若引用了舊 sha 要一併掃過。

## 2.5 Push 前的收斂檢查（歷史定稿）

觸發：使用者表示要把分支推上遠端或走發布流程（「push」「可以推了」「推上去」「開 MR」「發布」「送出去」「合 develop」）。有 develop 的 repo 在第一次合進 develop 前、其餘 repo 在執行 `git push` 前，必須先完成本節。

為什麼放在 push 前：未推的歷史可以自由整理；push 之後再收斂就得 force push，已被 review 或他人 fetch 過的更麻煩。push 是「歷史定稿」的動作。commit 數量少、改動小，均不構成跳過本節的理由。

本節只處理 commit 粒度與 message。push 時點由使用者決定、衝突預判、MR 開立、保命 tag 與零遺失驗證的完整步驟，依 `release-workflow` skill 的「首次合進 develop 前的歷史檢討」節。

**Step 1：盤點未推 commit**

```bash
git status                                     # 工作區必須乾淨；有未 commit 的變更先依 2.1 處理
git log --oneline --stat <基準 ref>..HEAD       # 基準 ref 見下方說明
```

基準 ref：分支已在遠端時取 `origin/<工作分支>`；尚未 push 時取分岔點 `git merge-base origin/<發布分支> HEAD`。不可直接用 `origin/<發布分支>`：它含分岔後別人合進去的 commit，拿它當 2.4 reset 的基準會把別人的變更當成自己的改動反轉。

只有未推、也未合進 develop 的 commit 進入檢討範圍；已 push 或已合進 develop 的 commit 一律不動（2.0 鐵則）。有 develop 的 repo 依發布流程會先合 develop、等 CI/CD 通過後才首次 push 工作分支，所以這個收斂檢查要在**首次合進 develop 之前**做，不是等到 push 前。

**Step 2：逐顆判定**

對每顆未推 commit 套用下表，判定依據與 2.1 相同：

| 樣態 | 處置 |
|---|---|
| 只寫得出「修正前一顆」：修的是本分支自己引入的缺陷 | 併進被修正的那顆 |
| 同一檔案連續多顆，後者整段覆寫前者 | 併，中間態不留 |
| 一顆做多件事，且各件的讀者不同 | 拆開 |
| 其餘 | 保留，不為了數字好看而合併 |

分不清是「修自己的錯」還是「行為變更」時，保留獨立 commit 並說明原因（2.0 寧散勿誤併）。

**Step 3：回報判定結果，才允許 push**

在操作回報中逐顆列出：「`<sha>` <標題> → 保留」或「→ 併入 `<目標 sha>`」或「→ 拆為 A／B」。

| 判定結果 | 後續 |
|---|---|
| 全部保留 | 回報後直接進入 push |
| 有應併或應拆 | 列出目標形狀（每顆的標題、涵蓋檔案）交使用者拍板；未拍板前不動歷史 |

拍板後的執行方式：目標是 HEAD 走 2.2；目標更早走 2.3；需要重新分組走 2.4。收斂後每顆 message 重寫並逐筆通過 1.5 驗收。整理完成、`git status` 工作區乾淨、`git log --oneline <基準 ref>..HEAD` 讀回確認形狀正確後，才執行 push。

**禁止**：Step 3 尚未回報就執行 `git push`；以「等 MR 合併時 squash」代替本節的收斂。

### push 需不需要 force？

push 前看一次 `git status`，依顯示決定：

| `git status` 顯示 | 意義 | 處理 |
|---|---|---|
| `Your branch is ahead of 'origin/...' by N commits` | 本地多 N 顆、從未 push 過 | `git push`，不需 force |
| 分支尚無 upstream（首次 push） | 遠端還沒有這條分支 | `git push -u origin <分支>` |
| `Your branch is up to date with 'origin/...'` | 本地與遠端一致 | 沒有新東西要推 |
| `Your branch and 'origin/...' have diverged` | amend／rebase／reset 改寫了已 push 的 commit | `git push --force-with-lease`，執行前先取得使用者確認 |

`--force-with-lease` 的意思：只在遠端仍是你上次看到的狀態時才覆蓋；別人若已推了新 commit 會被拒絕，比 `--force` 安全。常見誤判：amend 過已推送的 HEAD 是 diverged（sha 變了），不是 ahead；單純 ahead 是本地 commit 從未推送過的情境，普通 `git push` 即可。

---

# 附錄

## A. 執行環境（本地 git 進容器、網路操作與 worktree 走 host）

APIs 等子專案的**純本地** git 操作（commit、amend、reset、rebase、log、status）在對應容器內執行；**網路操作（fetch、pull、push）一律在 host**，因為容器內讀不到 host 的 git 帳密：容器沿用 host 的 `credential.helper` 設定（mac 上是 `osxkeychain`），但容器裡沒有這個程式，連遠端時會停在 `could not read Username`。worktree 內的 git 操作也在 host，容器解析不到 worktree 的 gitdir（見 `release-workflow`「2. 迭代」與 `branch-sync` 的 `reference/execution-contexts.md`）。純本地命令改寫為：

```bash
# APIs（php-fpm8 容器）
docker exec php-fpm8 bash -c "cd /var/www/APIs && <git cmd>"

# 舊專案 API_External / API_Frontend / API_Agent 等（php-fpm 容器）
docker exec php-fpm bash -c "cd /var/www/<project> && <git cmd>"
```

`gff-marketplace`、`docker-compose-php` 等不掛在容器內的 repo 不受此限，直接本機 `git` 即可。

## B. hook 與 CI 自動放行的 message

以下格式的 commit 會被 hook 與 CI 自動略過格式驗證：

- `Merge branch '...'`：Git 自動產生的合併 commit
- `Revert "..."`：`git revert` 自動產生的還原 commit
- `fixup! ...`：待 autosquash 的 fixup 暫存 commit
- `squash! ...`：待 autosquash 的 squash 暫存 commit

放行只代表 hook 不擋，不代表通過 1.5 的可讀性驗收；人工改寫過的 Merge／Revert 訊息仍須驗收。

## C. Hook 安裝（每位開發者執行一次）

```bash
cp .githooks/pre-commit.sh .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
cp .githooks/commit-msg.sh .git/hooks/commit-msg && chmod +x .git/hooks/commit-msg
```

`.githooks/` 目錄由各 GFFv3 repo 本身提供（`APIs`、`API_External`、`API_Frontend`、`API_Agent` 等都附在 repo 根）。若 clone 完找不到此目錄，先確認你在的是 GFFv3 內部 repo，不是其他專案。

`gff-marketplace` 例外：它的 `.githooks/` 只有 `pre-commit.sh`（白標 registry 變更時才驗證），沒有 `commit-msg.sh`，CI 也沒有 `lint:commit-message`。在這個 repo 只跑上面第一行，message 格式靠本 skill 的規則把關。

PHP repo 的 pre-commit 會依序執行：PHP 語法檢查 → Laravel Pint 格式化 → 目錄結構驗證 → 命名規範驗證 → 禁止提交檔案攔截。

MR 送出後，GitLab CI 的 `lint:commit-message` job 會對 MR 內所有 commit 重新驗證，未通過則無法合併。
