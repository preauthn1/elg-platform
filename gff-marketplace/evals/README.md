# Skill 觸發詞測試

這裡放的是觸發詞測試：給一句使用者會說的話，確認 Claude 叫起的是對的 skill，而且同一組容易撞詞的其他 skill 沒有被叫起。改 skill 的 `description`（觸發詞）之前跑一次，就能知道新寫法會不會搶走別支 skill 的說法，或被別支搶走。

測試用的是 Claude Code 內建的 `claude plugin eval`。每個案例會開一場全新的對話，只載入案例指定的 plugin；判定只看 Claude 有沒有呼叫 `Skill` 工具、叫的是哪一支，不另外請模型評分。

## 怎麼跑

在 repo 根目錄執行。每跑一次都是真實的對話，會花用量，所以一律設花費上限。實測一個案例跑 3 次約 0.5～1.7 美元，實測 15 個案例一輪約 12.9 美元、13 分鐘（目前 19 個案例，整輪會再多幾美元）：

```bash
# 跑全部案例，每個案例 3 次，花費超過 20 美元就停
claude plugin eval . --ablation none --threshold 0.67 --max-cost-usd 20 --no-publish

# 只跑某一組（依 tags 篩選）
claude plugin eval . --tag reconcile-family --ablation none --threshold 0.67 --max-cost-usd 10 --no-publish
claude plugin eval . --tag release-family --ablation none --threshold 0.67 --max-cost-usd 5 --no-publish
claude plugin eval . --tag cross-plugin --ablation none --threshold 0.67 --max-cost-usd 5 --no-publish
claude plugin eval . --tag collision-family --ablation none --threshold 0.67 --max-cost-usd 5 --no-publish

# 只跑一個案例、一次，先確認案例寫對
claude plugin eval . --case repair-fill-gap --ablation none --runs 1 --max-cost-usd 1 --no-publish
```

| 參數 | 為什麼這樣設 |
|---|---|
| `--ablation none` | 只跑「有載入 plugin」這一組。預設還會多跑一組沒有 plugin 的對照，對觸發詞測試沒有意義，而且「不該叫起」的判定在對照組一定通過，會灌水分數 |
| `--threshold 0.67` | 每個案例跑 3 次，允許 1 次失誤。模型每次的選擇不完全相同，要求 3 次全中容易誤報 |
| `--max-cost-usd` | 超過上限就停，回報已跑完的部分 |
| `--no-publish` | 報告只留在本機，不發布 |

第一次在這個目錄執行時會問要不要信任這些 plugin，回答信任即可。排程這類沒有人在旁邊按的環境，加 `--trust-plugin` 跳過這個確認。結果寫在 `evals/results/<時間>/`，裡面有 `report.html`，這個目錄不進版控。

### 跑完清掉留下的沙盒

每個案例都在暫存的沙盒目錄裡跑，裡面有測試用的設定、MCP 模擬回應和對話紀錄。加 `--keep-temp` 時，沙盒會刻意留著方便除錯。沒加也會留下：每次對話跑到 `max_turns` 上限，工具就把這次算成「run failed」，印出 `kept temp (run failed)` 並保留沙盒。案例的 `max_turns` 刻意設得很小（見「怎麼寫案例」），所以大部分執行都會留下一個。這不影響判定，報告的 NOTES 欄會寫 `Reached maximum number of turns`，分數照算。

跑完之後，`/private/tmp/` 底下 `e-` 開頭的目錄照下面清掉：

```bash
# 先確認是 0：沒有程序在用（還在跑測試時不要刪）
for d in /private/tmp/e-*; do lsof +D "$d" 2>/dev/null | tail -n +2; done | wc -l
# 裡面的 sealed/ 權限是全鎖，不先加回權限會刪不掉
for d in /private/tmp/e-*; do chmod -R u+rwx "$d" && rm -rf "$d"; done
```

## 改了某支 skill，要跑哪些案例

判定檔提到那支 skill 的案例都要跑，不管是 `fired-` 還是 `not-` 開頭：被測的 skill 改了 description，可能叫不到自己，也可能搶走別支的說法。`--case` 一次只吃一個名稱，所以逐個跑：

```bash
S=release-workflow   # 換成改過的 skill 名稱
for c in $(ls evals/*/graders/*-"$S".md | cut -d/ -f2 | sort -u); do
  claude plugin eval . --case "$c" --ablation none --threshold 0.67 --max-cost-usd 2 --no-publish
done
```

## 怎麼寫案例

一個案例一個目錄，目錄名就是案例名：

```
evals/<案例名>/
  prompt.md                       # 上半部是設定，分隔線下面是使用者說的那句話
  graders/fired-<skill>.md        # 這支 skill 必須被叫起
  graders/not-<skill>.md          # 這支 skill 不能被叫起（同組的其他 skill 各一個）
```

`prompt.md` 的設定裡，`plugins` 要列出被測 plugin，**以及它的依賴、依賴的依賴**。缺一個，被測 plugin 就整個載入失敗，所有「必須被叫起」的判定都會失敗，報告卻不會直接說原因。依賴寫在各 plugin 的 `.claude-plugin/plugin.json` 的 `dependencies`。例如 apis-toolkit 依賴 dev-toolkit 和 gcp-mcp，dev-toolkit 又依賴 gitlab-mcp，所以要列四個。

案例必須放在 repo 根目錄的 `evals/` 底下，不能放進 plugin 目錄：放在 plugin 底下時，工具只允許載入那個 plugin 自己，列不進它依賴的其他 plugin。

判定檔的寫法照現有案例複製，只改 skill 名稱。`max_turns` 設 3：判定只看有沒有叫起 skill，叫起之後的步驟不影響結果，輪數越多只是越花錢；設太少則可能在叫起 skill 之前就被切斷。另一個代價是：叫起正確的 skill 之後如果又誤叫別支，這種情況會被截掉、測不到。`not-` 開頭的判定多了 `min: 0`、`max: 0`，意思是這支 skill 被叫起 0 次才算通過。

使用者說的那句話要像真的同事會講的：帶站點、遊戲商、日期這些具體對象，不要直接抄 `description` 裡的範例句，否則只是在測字面比對。

## 限制

- 每個案例只載入 `plugins` 列出的 plugin。不同 plugin 之間的撞詞，要把兩邊的 plugin 都列進同一個案例才測得到。apis-toolkit 的依賴鏈已經包含 dev-toolkit，所以對帳和同步分支共用的「對齊」直接用現有的 `plugins` 就測得到（標籤 `cross-plugin` 那組）；其他 plugin（例如 op-toolkit）要另外加。標籤 `collision-family` 那組就是這樣做的：每個案例測一組說法相近的兩支或更多 skill，跨 plugin 的組把兩邊的 plugin 與各自的依賴都列進同一個案例。
- 撞詞的另一支如果是正確那支 skill 規定要接著載入的（例如 report-payment-audit 產報告前要先載入 report-design-conventions），不寫它的 `not-` 判定，只留 `fired-`。寫了的話，照規定走的正確流程反而會被判失敗。
- 測試環境沒有資料庫連線、沒有記憶、沒有 CLAUDE.md，所以只看「叫起哪支 skill」，不看 skill 叫起之後做得對不對。
