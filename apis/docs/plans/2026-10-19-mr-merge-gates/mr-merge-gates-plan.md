# MR 合併關卡改造：實作計劃

這份計劃把 `mr-merge-gates-design.md` 的決策拆成施工步驟。每一步寫明改哪裡、怎麼驗收。跨兩個 repo：APIs（CI 檢查程式）與 GFF-Marketplace（`team-roster.yaml`、release-workflow skill）。

## 現況

- 核准檢查在 master：`.gitlab-ci/approval/`。開關是 `APPROVAL_ENFORCE`，兩種模式的 job 都自動跑（第 5 步）。合併後檢查 `合併後檢查核准` 跑 `approval-check.php audit`，用 `sendAuditAlert()` 發 Slack。hotfix 認的是來源分支名稱 `hotfix/`（第 2 步，共用 `gateIsHotfixBranch`）。
- PM 確認檢查已合進 master：`.gitlab-ci/pm-confirm/`。開關是 `PM_CONFIRM_ENFORCE`。最後加標籤的人（`pmConfirmLastAdder`）在這裡；工單解析、名單解析等共用部分在 `.gitlab-ci/lib/`（第 1 步）。hotfix 認的是分支名稱 `hotfix/`。
- 「最後一次推 commit 的時間」是 `.gitlab-ci/lib/merge-gate.php` 的 `gateLastPushAt()`，取系統留言 `added N commit(s)` 的時間；核准檢查的 `approvalEvaluate()` 呼叫它。

## 施工順序

### 第 0 步：前置條件（不寫程式）

這步確認 CI 關卡能真的擋住合併，並讓後面的步驟有共用程式可以接。

| 項目 | 誰做 | 驗收 | 狀態 |
|---|---|---|---|
| MR !2436 合併進 master | RB | master 上有 `.gitlab-ci/pm-confirm/` | 完成（merge commit `af35b863e`） |
| 確認 APIs 已開「Pipelines must succeed」、不把 skipped 當成功、master 禁止直接 push | RB 在專案設定頁看 | 截圖或設定頁讀回 | 完成（RB 在設定頁確認） |
| 建「待審 MR」Slack 頻道，取得頻道 id，並把 `SLACK_BOT_TOKEN` 對應的 Slack app 加進頻道 | RB | 頻道 id 交給 Agent 設成 CI 變數 | 完成（CI 變數 `SLACK_REVIEW_CHANNEL_ID`；bot 沿用 `SLACK_BOT_TOKEN` 那支） |

### 第 1 步：抽出共用程式

QA 檢查要用到和 PM 確認相同的工單解析、名單解析，也要用到核准檢查的「最後一次推 commit 時間」。照專案規則，共用的部分要抽成一份，不能複製貼上。

- 新增 `.gitlab-ci/lib/`，從 `pm-confirm` 搬出工單解析、名單解析、豁免判斷、GitLab API 呼叫，函式改用 `gate` 前綴：
  - `lib/merge-gate.php`（純函式）：`gateExemptReason`、`gateCollectTickets`、`gateParseRoster`、`gateRosterGroupUserIds`、`gateLastPushAt`、`gateTaipeiTime`。
  - `lib/gitlab-api.php`（呼叫 GitLab）：`gitlabGet`、`gitlabGraphql`、`parentTicketOf`、`loadTeamRoster`、`missingOpTickets`；權杖由呼叫端傳入。
  - `lib/git.php`：`gitRun`、`mrCommits`。
- 核准檢查自己的 `gitlabGet` 不共用：它不分辨 404、缺變數的訊息也不同，硬套會改到核准檢查的輸出與 Slack 文字。
- 從 `approvalEvaluate()` 拆出「最後一次推 commit 時間」，成為獨立函式。
- 驗收：`approval-check-test.php`、`pm-confirm-check-test.php` 不改斷言就全過；一條 MR pipeline 的兩道檢查結果和抽出前一樣。

### 第 2 步：hotfix 統一認分支名稱

- **動手前先通知 RD**：核准檢查已經生效，改了會影響急修的做法。
- 核准檢查的放行條件，從「MR 有 `hotfix` 標籤」改成「來源分支以 `hotfix/` 開頭」；放行後照舊發 Slack 提醒在 1 個工作天內補審。
- 移除 `approval-rules.json` 的 `bypass_label`，同步改 `approval.yml` 與 `approval/lib/approval.php` 開頭的說明。
- 測試：`hotfix/` 分支沒核准也放行；`feature/` 分支貼了 `hotfix` 標籤仍不放行。

### 第 3 步：QA 驗收檢查

- 新增 `.gitlab-ci/qa-verify/`，結構比照 `pm-confirm/`：`qa-verify.yml`、`qa-verify-check.php`、`lib/qa-verify.php`、`qa-verify-check-test.php`。開關是 `QA_VERIFY_ENFORCE`，試行時 `allow_failure: true`。
- 判定：
  - 用第 1 步的工單解析找出主單；每張主單底下標題含「- 測試」的子單都要是關閉狀態。
  - 關閉的人屬於 `team-roster.yaml` 的「測試」群組。
  - 關閉時間嚴格晚於 MR 最後一次推 commit 的時間；時間缺漏或比不出先後，一律不通過。
  - 確定一張測試子單都沒有，才直接通過；查詢出錯、分頁沒讀完，一律不通過。
  - 豁免規則和 PM 確認共用同一份：`hotfix/` 分支免檢；全部 commit 都是 docs／style／chore／test／revert 也免檢（共用程式的 `gateExemptReason`）。
  - 只掛線上問題單、沒有需求單的 MR，比照 PM 確認：每張線上問題單都查得到就免檢，查不到就不通過（共用 `missingOpTickets`）。
- 「關閉的人、關閉時間」的來源：
  - 用 gitlab MCP 讀 `target/ticket_tasks` 的已關閉子單，回傳有 `closed_at`（UTC，例如 `2026-10-19T09:31:19.111Z`），沒有 `closed_by`；MCP 的回傳只挑部分欄位，GitLab REST 本身有沒有 `closed_by` 還沒驗證。
  - 系統留言有一則內文是 `closed` 的狀態留言，作者是關單的人，時間與 `closed_at` 相同到秒；但留言只有帳號名稱，沒有使用者 ID。
  - 改讀狀態異動紀錄（resource_state_events）：每筆有使用者 ID、時間與狀態，取最後一筆判斷，和 PM 確認讀標籤異動紀錄的做法一致，也能比對使用者 ID。最後一筆是 `closed` 才算關閉，關閉時間用這筆的 `created_at`。
  - 機器人權杖已實打確認讀得到：`GET /projects/544/issues/1825/resource_state_events` 回 HTTP 200，內容有 `"state":"closed"`、`user.id`（7）與 `created_at`（`2026-10-19T09:31:19.143Z`，比 issue 的 `closed_at` 晚 32 毫秒）。
- 測試（6 個）：全部由測試群組在最後推 commit 之後關閉，通過；由非測試群組的人關閉，不通過；在最後推 commit 之前關閉，不通過；沒有測試子單，通過；查詢失敗，不通過；`hotfix/` 分支，免檢。commit 類型與線上問題單的豁免沿用 PM 確認的既有測試，不另寫。

### 第 4 步：合併後檢查 PM 確認

- `pm-confirm-check.php` 加上合併後檢查的模式，比照 `approval-check.php audit`：在 master push pipeline 執行，找出這次合併的 MR，用標籤異動紀錄重建「合併當下」主單有沒有「0. PM確認」。
- `pmConfirmLastAdder()` 加一個截止時間參數，只看合併時間以前的異動。
- 沒通過、查不到合併時間、檢查本身失敗，都發 Slack。發送沿用 `sendAuditAlert()` 的做法，試行時只印在 log。
- 測試（2 個）：合併後才補貼標籤，判定不通過；合併後才移除標籤，判定通過。

### 第 5 步：三道檢查改成自動跑、提示改成「Run pipeline」

- 核准檢查正式模式拿掉 `when: manual`，改成和 PM 確認一樣自動跑，沒過就 `allow_failure: false`。
- 三道檢查沒通過時印出的提示，統一改成「條件補齊後，在 MR 頁面按『Run pipeline』開一條新的 pipeline」，取代現在的「回到這個 job 按重試」。
- 驗收：開一張測試 MR，確認三道都自動出現；補齊條件後按 Run pipeline，三道都重新判斷。

### 第 6 步：Slack 通知與 release-workflow（GFF-Marketplace）

- `team-roster.yaml` 每個人補 `slack_id`。
- release-workflow skill：
  - RD 確認最後一版時，Agent 問「要發嗎」。回答要發：需要 QA 的 MR tag QA；不需要 QA 的（沒有測試子單或 hotfix）直接 tag 審核人與 PM。
  - 回答「不要」：下次部署或 RD 主動要求時再問。回答「都不用發」：在 MR 貼「不自動通知」標籤。
  - 三道都正式模式的 repo，開 MR 時不再開成 Draft。
- 這一步走 GFF-Marketplace 自己的分支和 MR。

### 第 7 步：試行與切正式

- 第 2～5 步合併後，三道在 APIs 試行。
- 每道各自計算：試行至少 2 週，期間的誤擋都修好，而且最後 1 週沒有新的誤擋，就把那道的開關設成 `"true"`。三道不必同一天切換。
- 切換當下，所有待合併的 MR 都要開新的 MR pipeline。
- 三道都切正式後，APIs 不再開 Draft；接著移植到 agent-backend（待辦 470d53）。

「三道都轉正式」以專案為單位計算：同一個專案裡三個開關都設成 `"true"`，那個專案的 release-workflow 才能跳過「工單有測試子任務時開 Draft」與「PM 確認檢查」兩條規則，改成讀 MR pipeline 裡三道檢查的結果。各專案的開關現況：

| 專案 | 審核人核准 `APPROVAL_ENFORCE` | PM 確認 `PM_CONFIRM_ENFORCE` | QA 驗收 `QA_VERIFY_ENFORCE` | release-workflow 能否跳過那兩條 |
|---|---|---|---|---|
| APIs | `"false"`（試行） | `"true"`（正式） | 未設（試行） | 還不能，差核准與 QA 驗收 |
| agent-backend | 尚未移植 | 尚未移植 | 尚未移植 | 還不能，移植並三道轉正式後才能 |
| GFF-Marketplace | `"true"`（正式） | 不適用 | 不適用 | 不適用：參數表的 PM 確認檢查本來就標「不要」，也沒有 QA 驗收這道 |

- 舊代理後台三個專案與 RD-Studio 沒有 MR 可擋，PM 確認只能靠上版 skill 檢查，release-workflow 的 PM 確認參考文件不能刪。
- 切換開關後回來更新這張表。

## 每步的 MR 安排

- 第 1～5 步都在 APIs，改的都是 `.gitlab-ci/`。第 1 步單獨一張 MR，先確認抽出共用程式後行為沒變；第 2～5 步可以合成一張。
- 第 6 步是 GFF-Marketplace 的 MR。
- 第 0、7 步不寫程式。
