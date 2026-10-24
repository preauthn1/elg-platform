---
name: rd-ticket-create
description: 'RD 在 target/tickets 開需求單，或替既有需求單補寫子單內容：開修復單（自己發現的 bug）、開 RD 提的新功能單，以及替 PM 開好的單寫開發子單（邏輯、流程、資料表結構）與測試子單（測試步驟）。當 RD 說「開修復單」「開 bug 單」「RD 開單」「補開發子單」「補測試子單」「補寫子單」「幫 #單號 寫開發子單」「幫 #單號 寫測試步驟」時觸發；pm-ticket-create 判定開單的人是 RD 時也會轉過來。🚫 PM 開需求單、補拆子任務（建出子任務本身）走 pm-ticket-create；改主單描述走 pm-ticket-edit；線上問題單走 op-ticket-create。'
---

# RD 開單與補寫子單

一句話版本：**RD 開新功能單時，主單一樣用 PM 的角度寫，PM 不需要知道的細節放子單：開發子單寫怎麼做，測試子單寫怎麼測。修 bug 用修復單骨架，主單直接寫出問題的方法或端點與觸發條件，讓 RD 快速切入；RD 提的新功能沿用 PM 的分類骨架。替 PM 開好的單補寫子單時，只寫子單、不改範圍；主單裡屬於 RD 的細節列成建議，RD 同意才搬。寫進 GitLab 前一律先印預覽，使用者回「y」才寫入。**

> 目標 project：主單在 `target/tickets`（365）、子任務在 `target/ticket_tasks`（544）。開單的機制（路由題、指派、標籤、排期、預覽、寫入步驟）與 `pm-ticket-create` 共用，本 skill 只寫 RD 不一樣的地方。

## 名詞速查

| 名詞 | 意思 |
|---|---|
| 主單 | 需求本體那張單，用 PM 的角度寫（修復單例外，見下一列）；範圍與預期結果只寫在這裡 |
| 開發子單、測試子單 | 從主單拆出的子任務；開發子單寫給 RD（怎麼做），測試子單寫給 QA（怎麼測） |
| 修復單 | RD 自己發現 bug 開的單，主單寫給 RD 快速切入：問題發生原因（可寫方法名、端點、資料表欄位）、影響範圍、預期結果；骨架見 `reference/bugfix-schema.md` |
| 多問題修復單 | 一次修兩個以上互不相干問題的修復單；主單只放問題清單，每個問題的原因、影響、預期結果寫在各組開發子單，見 `reference/bugfix-schema.md` 的「一次修多個問題」 |
| 新功能單 | RD 提的需求，主單沿用 `pm-ticket-create` 的五份分類骨架 |
| 補寫 | 主單與子任務都已經建好，替子任務寫內容 |
| 預期結果 | 每一項需求完成後應該看到什麼，寫在主單，是唯一的驗收標準 |
| 共用參考檔 | `../pm-ticket-create/reference/` 底下的檔案：路由題、骨架、名單、標籤、排期、標題、內文品質、API 步驟 |

## 0. 必須遵守的契約

- 沿用 `../pm-ticket-create/SKILL.md` 的「Skill 必須遵守的契約」全部條文：照階段順序、不自作主張、預覽強制、內文品質必檢、失敗即停、走 MCP、給人看的文字一律白話。
- **範圍不是 RD 這支 skill 改的**：補寫子單時不改主單的「做什麼、不做什麼」與預期結果。覺得範圍不對，在回報裡提出來，由使用者找 PM 討論。
- **預估工時填區間的最大值**：把預估工時寫進工單（留言裡的 `/estimate` 快速命令，或 `time_estimate` 欄位）時，估出來是區間就填最大值：`~4-5h` 填 `5h`、`~3-4h` 填 `4h`。給使用者看的回覆仍用區間標出不確定。
- **多問題修復單照 `reference/bugfix-schema.md` 的「一次修多個問題」，蓋過本 skill、`reference/`，以及本 skill 沿用的 `../pm-ticket-create/` 規則裡的下列規則**：預期結果、問題發生原因、影響範圍寫在主單，子單以主單為準、對照主單預期結果，都改成看各組開發子單；不需要 QA 驗收時仍建開發子任務並寫內文，預覽照 `../pm-ticket-create/SKILL.md`「`RD_SELF` 改到兩個以上 repo」的方式保留建子任務的步驟；內文品質自檢的預期結果檢查改看各組開發子單，預覽「預期結果」那一行寫「不適用（多問題修復單，見各組開發子單）」。

## 1. 起手：檢查環境、確認身分、判斷模式

1. 照 `../pm-ticket-create/SKILL.md` 的「環境檢查」跑 `whoami` 與 `list_labels`，失敗時的提示也照它。
2. 拿 `whoami` 的帳號對名單檔 `team-roster.yaml`（讀法見 `../pm-ticket-create/SKILL.md` 名詞表的「名單檔」）：
   - 後端、前端 → 往下走。
   - PM → 告訴使用者「你是 PM，改用 PM 開單的流程」，載入 `pm-ticket-create`，本 skill 不往下問。
   - 測試組、名單外 → 問一次「你這次是以 RD 還是 PM 的身分開單？」，答 PM 就轉到 `pm-ticket-create`。
3. 照 `reference/rd-questions.yaml` 的 `preflight` 從使用者第一句話判斷模式（開修復單、開新功能單、補寫子單），判斷得出來就用一句話確認，判斷不出來才問「這次要做哪一件事？」。

## 2. 開修復單、開新功能單

兩個模式走同一套流程，差別只在主單骨架：

| 模式 | 主單骨架 | 字數上限 |
|---|---|---|
| 開修復單 | `reference/bugfix-schema.md` | 1,500 字（問題單） |
| 開新功能單 | `../pm-ticket-create/reference/description-schema/` 的五份分類骨架，照分類題選 | 照 `../pm-ticket-create/reference/writing-quality.md` 的字數上限 |

### 2.1 問路由題

照 `../pm-ticket-create/reference/questions.yaml` 的順序問，中間插入本 skill 的兩題（`reference/rd-questions.yaml`）：

```
站台 → 分類（修復單不問，固定用修復單骨架，子任務選項見 `reference/rd-questions.yaml` 的 `bugfix_subtask_presets`）→ 優先級
→ 需要 QA 驗收嗎
     ├─ 需要   → 主單給哪位 PM → 子任務模式 → 排期 → 里程碑
     └─ 不需要 → 子任務模式定為「RD 自己做」（RD_SELF），不建子任務（改兩個以上 repo 時每個 repo 各一張開發子任務）→ 排期（開發起訖就是主單起訖）→ 里程碑
```

- 選「不需要」時，這張單要改兩個以上的 repo 的話，仍然每個 repo 各開一張開發子任務，指派給開單的 RD，不開測試子任務（見 pm-ticket-create 的 `reference/subtask-patterns.md`「RD 自己做、不需要 QA 驗收」）。
- 開發子任務要給誰照樣從名單點選，把開單的 RD 排第一並標推薦。
- 測試子任務預設 `Benwu`，要改派從測試組點選。

### 2.2 寫主單

- 照選定的骨架逐欄收集，填寫規則照 `../pm-ticket-create/reference/description-schema/_schema-contract.md`。
- **新功能單用 PM 的角度寫。** 邏輯、流程、資料表結構、部署順序、測試步驟不寫進主單，留到下一步寫進子單。主單有對應段落時，寫一行指過去，例如「欄位與建表 SQL 寫在「APIs開發」子單」。
- **修復單直接寫出問題的方法或端點與觸發條件。** 方法名、端點路徑、資料表欄位可以寫進主單；修法、部署順序、測試步驟仍寫在子單（見 `reference/bugfix-schema.md`）。
- 新功能單不需要 QA 驗收時，不寫預期結果，改寫選填的「驗收方式」（見 `_schema-contract.md` 規則 9）。修復單不論要不要 QA 驗收，一律寫預期結果（見 `reference/bugfix-schema.md`）。

### 2.3 寫子單

需要 QA 驗收時，照 `reference/subtask-content.md` 的骨架，把開發子單與測試子單的內文一起寫好：

- 開發子單：新功能單寫邏輯、細部流程、資料表結構、部署順序；修復單寫修法、要改的地方、部署順序。
- 測試子單：新功能單每一項需求寫測試步驟；修復單寫重現步驟與驗證步驟。每一項對回主單的需求編號或預期結果第幾條。
- 拆成多張開發子單時（例如 APIs開發、Agent開發），各寫各的範圍；測試子單同理。
- 使用者還寫不出來的部分寫 `?? 待補：<缺什麼>`，不編內容。

### 2.4 檢查、預覽、寫入

1. 照 `../pm-ticket-create/SKILL.md` 的「內文品質自檢」與「需求完整性逼問」檢查主單。子單另外檢查兩件事：有沒有重述範圍或改寫預期結果、測試步驟是不是每項都對得回主單。
2. 照 `../pm-ticket-create/SKILL.md` 的「預覽」格式印預覽。子任務那一行的「內文」欄寫出放了什麼、幾個字。
3. 使用者回 `y` 後，照 `../pm-ticket-create/reference/api-cheatsheet.md` 的步驟寫入：子任務內文在建立時用 `create_work_item` 的 `description` 一次帶入。
4. 完成回報照 `../pm-ticket-create/SKILL.md` 的「完成回報」。

## 3. 補寫既有子單

主單與子任務已經由 PM 開好，這個模式只替子任務寫內容。

| 步驟 | 做什麼 | 用的工具 |
|---|---|---|
| 1. 讀主單 | 存成本機檔案再讀，記下每一項需求的編號與預期結果 | `mcp__plugin_gitlab-mcp_gitlab-workitem__save_issue_description` |
| 2. 查子任務 | 列出主單底下的子任務，依標題結尾分出開發子單與測試子單，記下各自的內文是不是空的 | `mcp__plugin_gitlab-mcp_gitlab-workitem__list_child_items`、`mcp__plugin_gitlab-mcp_gitlab__get_issue` |
| 3. 寫子單內容 | 照 `reference/subtask-content.md` 的骨架寫 | 無 |
| 4. 挑主單裡屬於 RD 的細節 | 照 `reference/subtask-content.md` 的「補寫時發現主單有 PM 不需要的細節」列成建議 | 無 |
| 5. 預覽 | 每張子單要寫的內容與字數；「建議從主單搬到子單」區塊；子單原本已有內容的，寫出要保留還是改寫 | 無 |
| 6. 寫入 | 使用者回 `y` 後先寫子單，再搬主單（同意搬的才搬） | 見 `reference/subtask-content.md` 的「寫進 GitLab 的方式」 |
| 7. 驗證 | 讀回子單與主單，內容跟預覽一致 | `get_issue` |

- 主單底下沒有要寫的那種子任務（例如沒有測試子單）時，停下回報，請使用者用 `pm-ticket-create` 的補拆子任務模式先建出來，本 skill 不建子任務。
- 一次指定好幾張主單時，每一張各自跑這七步、各自預覽。

## 4. 紅線

- 🚫 **不改範圍與預期結果**：做什麼、不做什麼、做到怎樣算完成，以主單為準；子單不重述、不改寫。
- 🚫 **不替使用者編內容**：寫不出來的留 `?? 待補：<缺什麼>`。
- 🚫 **沒經同意不改主單**：搬細節要使用者在預覽同意。
- 🚫 **不整份覆蓋已有內容的子單**：先問保留還是改寫。
- 🚫 **不呼叫 `update_work_item`**：在公司 GitLab 一律失敗，理由見 `../pm-ticket-create/reference/api-cheatsheet.md`。
- ✅ **預覽是契約**：使用者回 `y` 後，寫入內容必須等於最後一份預覽。

## 5. 參考檔索引

| 檔案 | 用途 |
|---|---|
| `reference/rd-questions.yaml` | 本 skill 自己的題目：模式、需要 QA 驗收嗎、主單給哪位 PM |
| `reference/bugfix-schema.md` | 修復單主單骨架 |
| `reference/subtask-content.md` | 開發子單、測試子單的骨架，搬細節的規則，寫入方式 |
| `../pm-ticket-create/SKILL.md` | 共用的環境檢查、內文品質自檢、需求完整性逼問、預覽、完成回報 |
| `../pm-ticket-create/reference/` | 共用的路由題、分類骨架、名單、標籤、排期、標題、內文品質、API 步驟 |
| `../pm-ticket-edit/SKILL.md` | 改已有內容的子單與主單時的改法 |
