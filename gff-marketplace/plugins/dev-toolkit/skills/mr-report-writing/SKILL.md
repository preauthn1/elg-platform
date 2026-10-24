---
name: mr-report-writing
description: 發布 Merge Request 時 MR description 的寫法：固定九節的 MR 報告（主旨、驗收進度、變更、設計決定、跟原計畫不同的地方、測試、上線步驟、待決與後續、報告資料），寫完先跑附帶的格式驗證器，通過才交給 release-workflow 開 MR；用字規則叫起 plain-writing。觸發說法：「寫 MR 描述」「寫 MR description」「MR 說明怎麼寫」「開 MR 前先把描述寫好」「更新 MR 描述」。🚫 單講「寫報告」不觸發，走 plain-writing。
---

# mr-report-writing：MR description 就是一份 MR 報告

一句話版本：**MR description 固定九節，前八節寫給審 MR 與日後回查的人看，第九節是同一份內容的 YAML；寫完先跑 `scripts/check-mr-report.php`，全綠才開 MR；推新 commit 後回頭更新。**

| 名詞 | 意思 |
|---|---|
| MR 報告 | 審 MR 的人看完知道做到哪裡、為什麼這樣做、合併後還要做什麼的那份文字；在 GitLab 上就是 MR description |
| 工單 | GitLab 上的需求單。主單在 `target/tickets`，營運單在 `target/op_tickets`，網址長得像 `.../-/work_items/<id>` |
| 預期結果 | 主單上「做完要看到什麼」的那幾條，驗收進度一列對一條 |
| 規則 ID | 知識庫裡一條規則的編號：Rule 文件 ID 加 `#R-` 序號，例如 `rules/vendor-spadegamingsw#R-7`；feature test 用同一個 ID 當 Group 綁回去 |
| 報告資料 | 第九節的 YAML 區塊，給之後的自動化程序轉成 OKF（Open Knowledge Format）知識檔；現在先累積，不做轉換 |
| 驗證器 | `scripts/check-mr-report.php`，讀一個 Markdown 檔，檢查九節結構與報告資料的格式 |

## 一、先叫起 plain-writing

**開始寫之前，用 Skill 工具叫起 `dev-toolkit:plain-writing`**，每一句話照它寫：中立客觀、句子完整、多列點、術語第一次出現就解釋、不用讀者查不到的代碼。**用字規則一律由 `plain-writing` 決定，本 skill 不複述**；兩邊各寫一份的話，改了一邊另一邊就會過期。

叫不起來也不要停：照本 skill 把描述寫完並跑驗證器，事後跟使用者說一聲 plain-writing 沒載入。

本 skill 只管 MR description **多出來**的事：九節結構、各節寫什麼、報告資料、驗證。分支、develop、開 MR 的動作是 `dev-toolkit:release-workflow` 的事；commit message 是 `dev-toolkit:git-commit` 的事。

## 二、先決定寫什麼，再動筆

讀者是審 MR 的人，以及之後回頭查「這個功能當初為什麼這樣做」的人，不是寫的人。

- **diff 看得到的事不寫**：改了哪幾行、新增了哪些函式。
- **diff 看不到的事一定寫**：放棄了哪個做法、為什麼放棄；實作中途改變了哪些決定；合併後還要手動做什麼；哪些驗收還沒做。
- **只寫實際發生過的事**：每一條都要對得到某個 commit、某次測試結果，或使用者做過的決定。不要為了填滿格式補內容；某一節沒有內容就寫「無」，不整節刪掉。
- **討論過程不寫**：MR 只留結論。過程留在對話或工單裡。
- **不寫「待決策」成「已決定」**，不把「整理文件」寫成「功能已完成」。
- **引用工單或其他 MR 一律寫成連結**：顯示文字用 `target/tickets#N` 這種帶專案名的寫法，連結目標用公司 GitLab 的完整網址，例如 `[target/tickets#4137](http://example.invalid:1688/target/tickets/-/work_items/4137)`。子任務是 `.../target/ticket_tasks/-/work_items/<N>`，線上問題單是 `.../target/op_tickets/-/work_items/<N>`，MR 用該專案的路徑，例如 APIs 是 `.../target/gffv3/apis/-/merge_requests/<N>`。不單寫 `target/tickets#N` 或 `#N`。
- **改了系統行為，知識庫要在同一支 MR 裡改好**：只適用有知識庫的 repo，目前是 APIs 的 `docs/knowledge/`。
  - 改了哪條規則，就改對應的 Rule 文件（`docs/knowledge/rules/<slug>.md`）；沒有對應的文件就新增一份。
  - 寫描述前先確認做了：做了，在「驗收進度」的「規則」欄填規則 ID；刻意不做，在「待決與後續」寫一句原因。
  - 不適用：只動效能、重構、log 文案、CI、測試基礎設施或文件的 MR。
  - 細則以該 repo 的 `.claude/rules/knowledge.md` 為準。

## 三、九節，固定這個順序

空白範本在 `reference/template.md`，直接複製來填。九個 `##` 標題一個不能少、順序不能換、不能多加 `##` 節；小節用 `###`。

### 1. 主旨

- 一句話說明要做什麼，連到工單主單。
- 沒工單時以「無工單：」開頭，說明為什麼沒有（例如自發的重構、文件整理）。

### 2. 驗收進度

- 放在前面：審 MR 的人最先想知道「做到哪裡」。
- 表格一列對主單的一條預期結果，欄位固定：`預期結果`、`自動`、`手動`、`備註`。動到知識庫的規則時，在「預期結果」後多一欄 `規則` 填規則 ID。
- 自動欄填涵蓋它的 feature test 檔，寫成 ``✅ `檔名` ``；只能手動驗的填「無」。
- 手動欄用 ⬜ 或 ✅，開 MR 時通常都還是 ⬜。
- 備註寫特殊情況，例如「只能手動驗」「這個 MR 新增的預期結果」。
- 表格下面一句話說明手動驗收要等什麼才能做（例如「等 dev 環境部署完成後由 QA 驗」）。
- 列出主單的**每一條**預期結果，不只這個 MR 動到的。沒工單寫「無」。

### 3. 變更

- 依檔案或模組條列，每項一句話，寫「為什麼改」或「改成什麼行為」，不寫逐行細節。
- 新檔標「（新檔）」。
- 測試替身、文件的改動也要列，合併成一兩項就好。

### 4. 設計決定

- 表格三欄：`決定`、`理由`、`放棄的做法`。
- 只列有其他可行做法、而且選擇會影響行為的決定。
- 「放棄的做法」一定要填。填不出來代表它不是一個決定，不用列。
- 有代價的寫出代價，例如「代價是回調多一次查詢」。
- 沒有就寫「無」。

### 5. 跟原計畫不同的地方

- 實作前跟使用者確認過的計畫，實作時改變的地方逐條記下來並寫原因。
- 包括：多做的、少做的、改做法的、使用者中途做的新決定、工單預期結果的增減。
- 沒有就寫「無」。

### 6. 測試

- 第一行：實際執行的命令與結果。命令依 repo，見 `release-workflow` 的「repo 參數表」：APIs 進 `php-fpm8` 容器跑 `php artisan test --compact <路徑>`；agent-backend 跑 `pnpm test:unit`；GFF-Marketplace 跑 `bash scripts/validate.sh`。
- 新增的測試逐項列出，依檔案分組，檔名後面標項數；feature test 在每一項後面標對應的預期結果或規則 ID。
- 原有的測試只寫總數。
- 最後一句說明測試的限制，例如「三方回調是用假資料打的，沒有連真實金流商」。
- 有失敗的測試照實寫名稱與錯誤訊息，不省略。

### 7. 上線步驟

- 合併後要手動做的事，依序編號，每一步寫到「照著做就能完成」：在哪個畫面、做什麼、填什麼、怎麼確認成功。
- APIs 與 agent-backend 的固定流程（合進 develop 部署 dev、MR 合併、發版）不用寫，只寫流程之外的事：新增的 env 變數要填什麼、`app:upgrade` 要同步的資料、哪些站要手動按部署。
- GFF-Marketplace 寫「合併即發布，同事執行 `/reload-plugins`」；動到 `plugin.json`、`.mcp.json` 時再寫重裝步驟。
- 沒有就寫「無」。只改文件的 MR 寫「無，只改文件，不用重新部署」。

### 8. 待決與後續

- 還沒定的事、刻意留到下一個 MR 的事，各一項。
- 有對應的工單或 MR 就附連結。
- 沒有就寫「無」。

### 9. 報告資料

- 一個 ```` ```yaml ```` 區塊，放在署名前面；一個 MR 只有一個。
- 前八節寫給人看，這一節是**同一份內容的結構化版本**，兩邊必須一致。
- 欄位、允許值、YAML 子集文法與完整範例見 `reference/report-data.md`。常踩的三條：時間要帶時區**並加雙引號**；寫報告時不知道的值填 `null`，不猜；`status: draft` 時 `mergedAt` 必須是 `null`。
- 不放 token、金鑰，也不放會員的姓名、電話這類個資。

最後一行固定署名：

```
🤖 Generated with [Claude Code](https://example.invalid/claude-code)
```

## 四、驗證：寫完先跑驗證器，紅就不開 MR

這步在做什麼：用程式把九節結構與報告資料的格式檢查一遍，格式對了才把描述交出去，審 MR 的人不用再幫忙對格式。

1. 把整份 description 寫進一個檔案（放 scratchpad 目錄，例如 `<scratchpad>/mr-description.md`）。不要直接塞在命令列引號裡，表格和反引號會被 shell 吃掉。
2. 跑驗證器（`<skill 目錄>` 即 skill 載入訊息列出的 Base directory）：

   ```bash
   php <skill 目錄>/scripts/check-mr-report.php <scratchpad>/mr-description.md
   ```

3. 有 ✘ 就照訊息修，改完再跑，直到全部 ✔、exit code 0。
4. 全綠後把這個檔案的內容當作 description 交給 `dev-toolkit:release-workflow` 開 MR（`create_merge_request` 的 `description` 讀這個檔）。沒工單的決定 `id` 寫 `D-MR-<序號>`。

報告資料不記 MR 編號與網址：要讀到這段 YAML 的程序，必須先知道是哪個專案的哪個 MR 才拿得到描述，再抄一份進內容是重複的。需要來源連結的輸出（例如轉成知識檔）由那支程序自己補。

**驗證器是必跑步驟。** 跑不起來（找不到 php、檔案不存在、噴例外）就停下來，把錯誤訊息回報給使用者，不要跳過驗證直接開 MR。

驗證器檢查的事（完整清單在 `scripts/check-mr-report.php` 檔頭）：九節齊全且順序正確、沒有多餘的 `##`、沒內容的節寫了「無」、沒留下範本佔位符、驗收進度與設計決定的表格欄位對、報告資料只有一個 yaml 區塊且能解析、必填欄位齊全、時間帶時區並加引號、`draft` 時 `mergedAt` 是 `null`、最後一行是署名。前八節與 YAML 的數字是否一致它不查，那是寫的人在「送出前檢查」自己對。

## 五、推新 commit 之後

審查期間追加 commit，回頭更新 description：測試數字、驗收進度、變更清單、「跟原計畫不同的地方」、報告資料的 `commits` 與 `tests`。改完再跑一次驗證器，再用 `update_merge_request` 更新。description 是這批變更唯一的整體說明，啟用 squash 時它就是進發布分支那一顆 commit 的內容。

## 送出前檢查

- [ ] 九節都在、順序正確；沒有內容的節寫「無」
- [ ] 驗收進度列出主單的**每一條**預期結果，不只這個 MR 動到的
- [ ] 改了系統行為時，知識庫的 Rule 文件在同一支 MR 裡改好，「規則」欄填了規則 ID；刻意不改的在「待決與後續」有原因
- [ ] 測試數字是**這次實際執行**的結果，不是估計或抄前一次的
- [ ] 新增的測試逐項列出；feature test 都標了預期結果或規則 ID
- [ ] 設計決定的每一列都填了「放棄的做法」
- [ ] 實作中改變的決定都記在「跟原計畫不同的地方」
- [ ] 上線步驟照著做就能完成
- [ ] 報告資料的 `acceptance`、`tests`、`decisions`、`planChanges`、`openItems` 跟前八節的表格、數字、條列一致
- [ ] 報告資料的時間都帶時區並加引號；不知道的值填 `null`
- [ ] 沒有密碼、token、金鑰或真實的會員資料
- [ ] 驗證器跑過、全部 ✔

## 對話範例

> 使用者：「收斂了，幫我寫 MR 描述然後開 MR。」
> AI：載入本 skill，先用 Skill 工具叫起 `dev-toolkit:plain-writing`。讀主單的預期結果、`git log origin/master..HEAD`、這次的測試輸出，照 `reference/template.md` 填九節，報告資料照 `reference/report-data.md` 寫；寫進 scratchpad 檔案後跑 `php <skill 目錄>/scripts/check-mr-report.php`，修到全 ✔；再交給 `dev-toolkit:release-workflow` 走它的發布流程開 MR。

---

`reference/template.md`：九節空白範本。`reference/report-data.md`：報告資料的欄位、允許值、YAML 子集文法與範例。`scripts/check-mr-report.php`：驗證器。`scripts/check-mr-report-test.php` 與 `scripts/fixtures/`：驗證器自己的夾具測試，由 `scripts/validate.sh` 跑。
