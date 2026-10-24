# PM 確認檢查

這份檢查在確認：改動要合進發布分支、或要部署到正式環境之前，需求主單已經由 PM 驗收過。依據是主單掛著「0. PM確認」標籤，而且最後一次加上這個標籤的人是 PM。它和「測試子任務已關閉」一樣，是合併與上線的驗收條件。

## 什麼時候跑

| 時機 | 在哪裡 | 沒通過時 |
|---|---|---|
| 開 MR | `release-workflow`「3.3 開 MR」規則 9 | MR 開成 Draft（草稿，GitLab 會擋下合併） |
| 把 MR 改回 Ready、回報可合併之前 | `release-workflow`「3.3 開 MR」規則 5、8、9 | MR 維持 Draft，回報缺什麼 |
| 發版打 tag 之前 | `gitlab-release` | 停下來，不打 tag |
| RD-Studio 上正式站之前 | `legend-toolkit` 的 `deploy-rd-studio` | 停下來，不 merge、不跑 update 腳本 |
| WEB_AgentBackend、API_Agent、WEB_CustomerServiceSocket 收進 `master`、以及上正式站之前 | `legend-toolkit` 的 `deploy-agent-stack` | 停下來，不 merge、不 push |

每個時機都重跑一次，不沿用前一次的結果：PM 可能在中間拿掉標籤，名單也可能改過。

**適用範圍**：`release-workflow` 參數表「PM 確認檢查」標「要」的 repo、`gitlab-release` 參數表同名欄位標「有」的 repo，以及上表點名的上版 skill。標「不要」或「無」的 repo 不跑。

## 步驟

### 1. 先看能不能豁免

這步在做什麼：線上急修與不影響功能的改動不必等 PM，先排除掉，後面就不用查工單。符合任一條就算通過，回報時寫明是哪一條（另有一種豁免是「只附線上問題單」，要先收集工單才判斷得出來，寫在第 2 步）：

- **hotfix**：工作分支名稱以 `hotfix/` 開頭。分支名之外的說法（使用者口頭講「這是 hotfix」、MR 標題寫 hotfix）不算：分支名是每個人、每支工具都看得到的依據，日後各專案的 CI 加上同一道檢查時也用它判斷。
- **只有不影響功能的 commit**：這次要合進去（或要上版）的 commit，扣掉合併用的 commit 之後至少有一顆，而且每一顆標題的類型都是下列之一（類型寫法依 `git-commit` skill）：

  | 類型 | 例子 |
  |---|---|
  | `docs` | `docs: 補充遊戲商接入流程說明` |
  | `style` | `style: 執行 Pint 統一縮排格式` |
  | `chore` | `chore: 更新 composer 依賴至最新版本` |
  | `test` | `test: 補充 TopPay 代付回調測試案例` |
  | `revert` | `revert: 還原錯誤的 Migration 變更`，或 `git revert` 自動產生的 `Revert "..."` |

  標題看不出類型（不是 `type:` 或 `type(scope):` 開頭）的 commit 一顆就讓整批不豁免。只看 MR 標題不算數：標題寫 `chore`、裡面夾帶 `feat` 的 MR 照樣要檢查。

  MR 的 commit 範圍用 `git log --no-merges --format='%s' origin/<發布分支>..<工作分支>` 列出；上版 skill 的範圍由該 skill 指定。

### 2. 收集這次改動提到的需求單

這步在做什麼：找出這批改動對應到哪幾張需求主單，PM 的標籤貼在主單上。

- **從哪裡找**：MR 描述，以及範圍內每一顆 commit 的標題與內文（`git log --format='%s%n%b'`），全部收集後去掉重複。
- **認得的寫法**：`target/tickets#NNNN`、`target/ticket_tasks#NNNN`，以及這兩個專案的工單網址（`.../target/tickets/-/work_items/NNNN`、`.../target/ticket_tasks/-/work_items/NNNN`，`issues` 網址同理）。
- **不認的寫法**：只寫 `#NNNN`（在 MR 裡指的是這個 repo 自己的 issue）、分支名裡的數字（看不出是哪個專案的單號）。
- **線上問題單另外記下**：`target/op_tickets#NNNN` 與它的工單網址不算需求單，但要記下來給下一段判斷。

收集完照下表決定怎麼走：

| 收集到的工單 | 結果 |
|---|---|
| 有任何一張需求單 | 往下做第 3 步。同時附的線上問題單不影響，需求單照樣要 PM 確認 |
| 沒有需求單，只有線上問題單 | 每張線上問題單都用 `get_issue(project_id="target/op_tickets", issue_iid=NNNN)` 查得到，就豁免，不往下做；有一張查不到就不通過，原因寫「線上問題單 #NNNN 不存在」。理由：線上問題單是維運開的線上 bug，性質同 hotfix，修完由維運驗證並關單，沒有 PM 確認流程；先查單存不存在，是防止隨手寫一個單號就跳過檢查 |
| 一張都沒有 | 不通過，原因寫「找不到需求單連結」 |

### 3. 子任務換成主單

| 找到的是 | 對應的主單 |
|---|---|
| `target/tickets#NNNN` | 就是主單 |
| `target/ticket_tasks#NNNN` | `list_child_items(project_id="target/ticket_tasks", iid=NNNN)` 回傳的 `parent`。`get_issue` 讀不到跨專案的父子關係，不要用它找 |

`parent` 是 `null` 或查詢失敗時算不通過，原因寫「子任務 #NNNN 找不到主單」。換完再去掉重複的主單。

### 4. 讀團隊名單

這步在做什麼：確認誰是 PM。名單放在 GFF-Marketplace 根目錄，不會跟著 plugin 裝到本機，一律讀 GitLab 上 `main` 的版本：

```
get_file_contents(project_id="target/gffv3/gff-marketplace", file_path="team-roster.yaml", ref="main")
```

- `members` 裡 `groups` 含 `PM` 的人就是 PM，比對用 `user_id`。
- 回傳的 `commit_id` 記下來，回報時附上，之後查得到當時用的是哪一版名單。
- 讀不到、格式不對、PM 組是空的，整個檢查算不通過。不用記憶、舊的副本或本機檔案代替。

### 5. 逐張檢查主單

每張主單都要通過，有一張不通過整批就不通過。

1. **標籤還在**：`get_issue(project_id="target/tickets", issue_iid=NNNN)` 的 `labels` 要有 `0. PM確認`。沒有就不通過，原因寫「主單 #NNNN 沒有 0. PM確認」。
2. **找出最後一次加標籤的人**：
   - 先用 `list_labels(project_id="target/tickets", search="0. PM確認")` 取得標籤編號（系統留言裡用 `~編號` 表示標籤）。
   - 用 `list_work_item_notes(project_id="target/tickets", iid=NNNN, page_size=100)` 讀完所有留言（有下一頁就翻到最後一頁）。
   - 只看系統留言（`system: true`）裡的標籤異動。一則留言可能同時加好幾個、又拿掉幾個，例如 `added ~12464 ~12463 labels and removed ~12462 label`，要拆成「added 那段」和「removed 那段」分開看。
   - 取時間（`createdAt`）最晚、而且標籤編號出現在「added 那段」的那一則，它的 `author` 就是最後一次加標籤的人。同一秒內有兩則以上提到這個標籤時，先後判斷不出來（留言編號是雜湊值，不能拿來排序），算不通過，原因寫「主單 #NNNN 的標籤異動先後判斷不出來」。
   - 找不到這樣的留言，或留言格式看不懂，算不通過，不猜。
3. **加標籤的人是 PM**：用 `get_users(usernames=["<author>"])` 換成使用者 ID，和名單的 PM 組比對。不在 PM 組就不通過，原因寫「主單 #NNNN 的 0. PM確認 是 <帳號> 加的，不在 PM 名單」。

### 6. 回報結果

通過與不通過都要回報，格式固定，讓使用者一眼看出卡在哪。留言的 `createdAt` 是 UTC，回報時換成台北時間（加 8 小時）並寫明：

```
PM 確認檢查：不通過
- 主單 target/tickets#4519：沒有 0. PM確認
- 主單 target/tickets#4530：通過（Frank 於 2026-10-16 14:08 台北時間加上）
名單版本：team-roster.yaml @ <commit_id 前 8 碼>
```

豁免時寫 `PM 確認檢查：豁免（hotfix 分支）`、`PM 確認檢查：豁免（全部 commit 都是 docs／style／chore／test／revert）` 或 `PM 確認檢查：豁免（只附線上問題單 target/op_tickets#NNNN）`。

任何一步呼叫 GitLab 失敗，都算不通過並寫出失敗的步驟，不當成通過。
