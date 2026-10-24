# Contributing to GFF-Marketplace

> **文件職責原則（治本，避免 SSOT 漂移）：規則只寫在本檔。** 其他文件（`project-conventions` skill、各 README、`CLAUDE.md`）只**指向**本檔對應章節，**不複述規則內容**。需要範例 / 表格時也放這裡，不另開重複文件。新增規則 → 改本檔一處；其他文件最多更新「指向哪一節」。

## SSOT 來源對照

每類事實/規範只有**一個**權威來源；其他文件一律「指向、不複述」。新增或修改某類內容前，先查本表確認該往哪寫。

| 類別 | 單一來源（SSOT） | 其他文件的規則 |
|---|---|---|
| 慣例規則（命名 / description / MR / reference / 安全邊界…） | 本檔 `CONTRIBUTING.md` | 只指向章節，不複述（見「文件職責原則」） |
| plugin 名冊（名稱 / 內容 / 受眾） | `README.md` | 「Plugin 切分原則」等章節只指向 |
| skill 目錄（清單 / 觸發 / reference 結構） | `docs/plugin-catalogue.md` | 其他處連結 |
| Skill 速查表（給同事看的「這樣說 → 叫起哪支 skill」，依角色分頁） | `docs/skill-cheatsheet.html` | 發布版是 claude.ai artifact，只從這個檔重發，由 artifact 擁有者（RB）執行；`validate.sh` 第 9 項提醒要跟著改 |
| 白標**事實**（canonical / 別名 / connectionId / rootAgentId） | `plugins/apis-toolkit/registry/whitelabels.metadata.yaml` | skill 執行時 `Read` 代入、**禁硬寫值**（見「白標事實 SSOT（apis-toolkit）」） |
| 白標**查詢規範**（rootAgentId 切子樹 / fail-closed / sharesConnection） | 本檔「白標事實 SSOT（apis-toolkit）」（rationale）＋ `whitelabel-container-exec` skill（容器 / DB 協議與 alias 正規化） | skill 已正確委派＋只留 point-of-use 強制（2026-06-18 抽查 OK，修 1 處把應指向「白標事實 SSOT（apis-toolkit）」卻指向「Helper 與高風險寫入安全邊界」的過期引用） |
| 白標 → GCP project 對照（`gcp-log-troubleshoot` 用） | `gcp-log-troubleshoot` skill 的「白標 → GCP project 對照表」節內嵌 | skill 已遷 `apis-toolkit`（與 registry 同 plugin）；白標→GCP mapping 目前仍內嵌在該節（原 foundation→domain 分離理由已不適用）。後續可評估改讀 registry 消重，暫列已知小成本 |
| APIs 容器 / DB 執行協議 | `whitelabel-container-exec` skill | 其他 skill 引用、不重定義（見「Plugin 切分原則」） |
| MCP 套件 pin 版本 | 各 plugin `bin/*.js` 的 `PKG` 常數 | README / docs 用 `@<version>` 佔位、不複述版號 |
| 安裝 / onboarding 步驟 | `ONBOARDING.md` | README quick-install 為精簡鏡像、指向 ONBOARDING |
| 版本 / 發布機制 | 本檔「發布與版本機制」 | 其他處指向 |
| 本機 hooks 用途 | 本檔「本機自動把關（hooks）」 | `CLAUDE.md` / skill 指向 |
| PAT 來源 | 見下「刻意例外」 | 無 |

**刻意例外（非飄移，記錄分工）**

- **PAT 設定 SSOT**：操作步驟只維護在 `gitlab-mcp/README.md` 的「GitLab PAT 設定 (SSOT)」。MCP 工具呼叫與 `release-workflow` 的 GitLab 操作均經 gitlab MCP（`release-workflow` 不再持有 token、不直打 `api/v4`）；runtime 由 `bin/gitlab-mcp.js` 注入 PAT，macOS / Linux 推薦 `~/.gff/secrets.env`（key `GITLAB_PERSONAL_ACCESS_TOKEN`），Windows 可依 README opt-in 使用 secrets 檔，`user_config.gitlab_pat` 僅作 legacy fallback。舊 macOS keychain `gitlab-rb` 與 `target-pat` 已退役。註：`op-ticket-create/reference/mcp-setup.md` 的自建 server（Option A/B/C）為**歷史備援**，非第二條主要設定文件。

## 規則

### 1. 變更流程

| 動作 | 規則 |
|------|------|
| 一般改動（含 skill 新增 / 修改、typo、format、README） | 本地 `bash scripts/validate.sh` 綠後開 MR（target `main`）；動到需要核准的範圍時，合併前須由作者以外的審核人核准，範圍見下方「合併前核准」。`main` 不能直接 push |
| Breaking change（移除 skill / 讓原本會觸發的說法不再觸發 / 改 plugin.json / 改 marketplace.json / 改 `.mcp.json` / 改 `bin/`，完整清單見「Plugin 修改流程」） | 開 MR 標 `breaking` label + 描述影響範圍；貼了 `breaking` 的 MR 一律要審核人核准（`hotfix` 標籤例外，見「合併前核准」） |

### 1-1. 合併前核准

動到下列範圍的 MR，要由作者以外的一位審核人核准才能合併；其餘 MR 不需要核准。實際會不會擋下合併，看本節最後的「生效開關」。

為什麼分級：下列改動合併後會裝到團隊的電腦上，出錯會讓別人的 Claude 壞掉、連錯服務或裝不起來，要有第二個人看過。其餘改動出錯可以事後修：skill 內文這類文字調整合併後有 Slack 通知；CI、核准機制與規則文件只影響這個 repo 自己的流程，不會裝到團隊電腦上。全部都審會讓審核人把時間花在這些地方，真正該看的反而容易被草率帶過。

| 需要核准的範圍 | 內容 |
|---|---|
| plugin 設定與會執行的程式 | 根目錄的 `.claude-plugin/`；各 plugin 的 `.claude-plugin/`、`.mcp.json`、`bin/`、`hooks/`、`scripts/`、`script/`、`registry/`（skill 目錄底下同名的資料夾也算） |
| 團隊名單 | 根目錄的 `team-roster.yaml`。合併後不必更新 plugin 就立刻生效，其中的 PM 組決定誰貼的「0. PM確認」能讓改動合併、上線 |
| 讓原本會觸發的說法不再觸發，或移除 skill | 包括刪掉說法、加上排除、改 skill 名稱。同事原本講的話會叫不起來，所以要核准。檔案路徑分不出來，以 `breaking` 標籤認定：貼了 `breaking` 的 MR 一律要核准（`hotfix` 標籤例外，見本節的「緊急狀況才用 `hotfix` 標籤」） |

只新增說法或觸發情境、原本的說法照樣觸發的 MR，不算 breaking、不貼標籤、不需要核准。它的風險是搶走別支 skill 的說法，這要靠觸發詞測試抓（見「Description 觸發詞清單」），審核人用看的看不出來。

不需要核准的：skill 內文與 `reference/`、`commands/`、`agents/`、README、`docs/`，以及 CI、核准機制與本機把關（`.gitlab-ci.yml`、`.gitlab/`、`scripts/`、`.githooks/`、`.claude/hooks/`、`.claude/settings.json`）、規則文件（`CONTRIBUTING.md`、`CLAUDE.md`）。確切的路徑清單以 `.gitlab/approval-rules.json` 為準。

- **審核人**：`.gitlab/approval-rules.json` 的名單（目前是 RB、Toro、Rhys）。
- **不需要核准的 MR**：正式模式下仍要按一次 `需要審核人核准` 這個 job 的 ▶，它會回報「直接通過」，作者自己按即可。
- **流程**（需要核准的 MR）：
  1. 作者開 MR 後，順手按「設定自動合併」（Set to auto-merge）。之後檢查一通過，GitLab 就自動合併，不必回來重新整理頁面找合併按鈕。AI 開的 MR 由使用者本人按，AI 不代為設定（合併一律由使用者本人決定）。
  2. 審核人先按 GitLab 的「核准」（Approve），**再**按 pipeline 最後的 `需要審核人核准` 這個 job 的 ▶。順序不能反過來：這個 job 只在按下 ▶ 的那一刻檢查一次，先按 ▶ 再核准，檢查會因為還沒有核准而失敗。
  3. 檢查通過才能合併。沒通過時 MR 頁面只會顯示失敗，**要點進這個 job 看執行紀錄（log）**，最後幾行會寫原因和可以找誰；核准後在 job 頁面右上角按「重試」。
- **改了就要重審**：核准後又推 commit，舊核准不算。GitLab 社群版不會自動移除核准，畫面上仍顯示「已核准」，所以 pipeline 的 `提醒重新核准` 這個 job 會由 bot（approval-bot-token）在 MR 留言 @ 核准已失效的審核人。審核人重審後，要先按「撤銷核准」再按「核准」（不先撤銷就按不了），再按 `需要審核人核准` 的 ▶。這個 job 只提醒、不擋合併，同一個 commit 只提醒一次。
- **維護提醒用的 token**：`提醒重新核准` 靠專案 CI 變數 `APPROVAL_BOT_TOKEN`（Project Access Token，api scope，勾 Masked、不可勾 Protected，否則一般分支的 MR pipeline 讀不到）。token 到期前要到 Settings → Access tokens 換新，並更新這個變數；到期日寫在變數的 Description。沒設時這個 job 只印「略過提醒」；過期時 job 會失敗但不擋合併。兩種情況都等於沒有提醒。`MR 介入通知` 也用這個 token，而且只認這個 token 的帳號寫的紀錄留言；換成新建的 token 後，把專案 CI 變數 `MR_NOTIFY_SINCE` 改成換新當下的時間，否則開著的 MR 會重發一輪通知。
- **MR 介入通知**：加審核人、按核准、貼標籤都不會觸發 pipeline，所以由排程 pipeline（週一到週五 09:00～19:00 每 15 分鐘，跑在 `main`）掃描開著的 MR，輪到誰動手就在 MR 留言加 Slack @ 誰，最多延遲 15 分鐘。目前通知三種事：
  1. 被指派為審核人：新加進來的審核人才通知，Draft 也發。
  2. 核准因新 commit 失效：通知核准已失效的審核人。MR 留言和 `提醒重新核准` 用同一個標記，同一次推送只留一則。
  3. 可以合併：通知作者。條件是不是 Draft、GitLab 判定可以合併、有審核人的有效核准（動到核准領域時要該領域審核人的；有指派審核人時，其中任一位即可）。沒動到核准領域、也沒指派審核人的 MR 不需要核准，前兩項成立就通知。

  每張 MR 有一則機器人的紀錄留言，記下已經通知過的事，同一件事不會重複通知，請不要編輯或刪除。Slack 用 target/gffv3 群組 CI 變數 `SLACK_USER_MAP`（GitLab 帳號對 Slack user id 的 JSON 物件，例如 `{"toro": "U0BGVBVN4RG"}`）找人；對不到的人只寫帳號、不 @，新成員補進這個變數即可，不用發 MR。專案 CI 變數 `MR_NOTIFY_SINCE` 是上線時間：沒設時只記錄、不發通知；有設時，在這個時間之前就開著的 MR 第一次被掃到只記錄。
- **要取消核准，先把 MR 改回 Draft**：取消核准不會讓已通過的檢查失效，Draft 才擋得住合併。合併後 `合併後檢查核准` 也會回頭檢查，沒有有效核准就發 Slack 警告。
- **緊急狀況才用 `hotfix` 標籤**：線上出問題、必須馬上合併、又找不到審核人時，貼上 `hotfix` 標籤。核准檢查會先看核准，有有效核准就照常通過；沒有才因為標籤放行，合併後發 Slack 提醒，審核人要在 1 個工作天內補審。這個標籤跟「Hotfix 流程」一節的 `hotfix/<topic>` 分支名稱無關，只有貼標籤才會放行。

**生效開關**：專案 CI 變數 `APPROVAL_ENFORCE`（設定 → CI/CD → 變數，不可勾 Protected）。值是 `true` 時是正式模式，`需要審核人核准` 這個 job 要手動按 ▶、沒通過不能合併；其他值或沒設是試行模式，它會自動跑、沒有核准只顯示警告，`合併後檢查核准` 也不發 Slack。切換只改變數，不必發 MR。

**這套檢查防的是疏忽，不是刻意繞過。** 它擋得住「忘了找人審」「審完又偷推 commit」。但 CI 設定、名單檔與檢查腳本都在本 repo，而且改它們本身不需要核准：作者可以在自己的 MR 裡改掉核准範圍再直接合併，那張 MR 也會照改過的版本檢查。這是刻意的取捨，讓 CI 與規則文件的調整不必等人。這些修改都會留在 MR 的 diff 與 git 歷史；看到 `.gitlab-ci.yml`、`.gitlab/approval-rules.json`、`scripts/approval-check.php`、`scripts/lib/approval.php` 有改動，事後回頭查這幾個檔的歷史即可。

### 2. 新增 skill 的最低門檻

每個新 skill 必須含：

1. `plugins/<plugin>/skills/<name>/SKILL.md`（含 frontmatter + 觸發詞 + 主流程）
2. SKILL.md 的 `description:` 遵循「description 不塞規則」規範（見「Description 觸發詞清單」）：
   - 只放**觸發訊號 + 一句摘要**
   - 規則細節寫正文，不塞 description

建議附「至少 1 個對話範例」（冷啟測試證據，showing skill 觸發 + AI 行為），不在必須清單內。

`reference/*.md` 子文件（細節 / 模板 / 範例）不在必須清單內，**預設建議**至少 1 個：若 SKILL.md 已自足、且無「常見路徑用不到」的內容可外移，得無 reference（見「Reference 子文件」節的 Reference 定位 doctrine），勿為湊門檻硬留。

### 3. Skill 命名規範

1. 目錄名 = kebab-case，2-4 個 token
2. 結構：`<情境 / 主題>-<動作 / 結果>`，例 `pm-ticket-create`、`bank-config-supplement`
3. 動作詞優先用動名詞或名詞化動詞：`create`、`troubleshoot`、`intake`、`reconciliation`、`query`、`supplement`、`fix`、`split`
4. 例外允許「業界既有複合詞」：`integration`、`best-practices`、`commit`
5. 避免角色前綴（角色由 plugin 決定，不在 skill 名稱重複）
6. **`ops-` 前綴保留給 plugin 用**，skill 不可用 `ops-` 開頭；`op-` 專指 `op_tickets` repo
7. 不用技術細節做名字（用使用情境）

**命名 do / don't：**

| ❌ 避免 | ✅ 改用 | 原因 |
|---|---|---|
| `gitlab-api-wrapper` | `pm-ticket-create` | 用使用情境，不用技術細節 |
| `ops-reconcile` | `reconcile-bet-records` | `ops-` 保留給 plugin，skill 不可用 |
| `agent-ticket-create` | `pm-ticket-create` | 不重複角色前綴（角色由 plugin 決定） |
| `details` / `misc` | `subtask-patterns` | reference 檔名要描述性、專精一件事 |

### 4. Plugin 切分原則

Plugin 按**工作目的＋執行依賴**切分，不按角色。當前 plugin roster（名稱 / 內容 / 受眾）見 [README](README.md)，本節只定切分規則、不複述名冊。

新增 skill 時：

1. 想清楚這 skill 服務的「工作情境」是什麼，對應到哪個 plugin（roster 見 README）
2. 同時檢查**執行依賴**：若 skill 強依賴特定 repo、Docker container、Laravel/Artisan、專案路徑或專案 hooks，通常應放在該專案 plugin。例：依賴 APIs repo、`php-fpm8`、`/var/www/APIs`、`DB_CONNECTION` 的流程（DB 連線切換、對帳、報表），歸 `apis-toolkit`，不放全域泛用 plugin
3. 跨情境的 skill 寧可放主要使用情境的 plugin，**不要重複放兩個 plugin**（會導致 skill 名衝突）
4. 若沒有合適 plugin，先評估「新增 plugin」的必要性（涉及 marketplace.json 修改、影響所有安裝者，屬 breaking 走 MR）

新增需要 GitLab MCP 的 plugin：必須在 plugin.json 加 `"dependencies": ["gitlab-mcp"]`，**不要**自己宣告同名 `gitlab` MCP server。

新增需要 GCP Cloud Logging MCP 的 plugin：必須在 plugin.json 加 `"dependencies": ["gcp-mcp"]`，**不要**自己宣告同名 `observability` MCP server。

`bin/*.js` 或 `.mcp.json` 內引用的 MCP 套件字串**必須 pin 版本**（帶 `@<version>`，例 `@zereight/mcp-gitlab@<version>`），未 pin 會被 `validate.sh` 擋下。

### 4-1. 命名與分包第一性原則

- **兩層命名**：`*-mcp`＝純基礎設施 MCP server（被依賴、不放業務 skill，如 gitlab-mcp / gcp-mcp）；`*-toolkit`＝角色／領域技能包（apis-toolkit / dev-toolkit / op-toolkit / legend-toolkit）。
- **分包判準＝共同啟用邊界**：以「誰、在哪個 repo／情境會一起啟用」決定歸屬，而非主題相似。
- **獨立成 plugin 的門檻**：啟用邊界不同（受眾／repo 不重疊）即獨立，即使只有 1 個 skill。
- **legend-toolkit**：Legend 系列舊專案（legend-app / legend-server / ptt-app 等）的維運技能（以環境部署為主）歸此包。

### 5. Description 觸發詞清單

寫 description 時：

- 列出使用者**實際說過、或確認會說的話**（「開工單 / 拆子任務 / 為何看不到銀行」等），不自己編。動詞收斂到使用者真的用過的，寧少勿多：使用者說「查」「查詢」，就不另外加「列」「看」「顯示」
- 寫新 skill 前，先請使用者給 3 到 5 句他會實際輸入的說法，從裡面抽出動詞、名詞與句型，description 只放這些或近似改寫
- 不要列**技術術語**（除非該術語就是 user 用語）
- 多語混用 OK（繁中 + 英文工具名）
- 每個說法都講出對象，例如寫「同步分支」「對齊 git」，不寫只有動詞的「拉最新」「對齊」。只有動詞的說法會被別的事情誤觸：「拉最新工單」「拉最新 log」可能叫起同步分支的 skill，「對齊」本身又是對帳 skill 的說法。只講動詞時，在 description 寫明「不觸發，先問要做什麼」，或指明歸哪支 skill
- 對象能從情境看出來時，不要求使用者講，也不寫成「先問」。例如人在某個專案目錄裡說「上版」「部署」，Claude 看得到工作目錄與 git remote，直接依所在專案判斷；看不出專案時才問
- 加新說法前先搜全部 SKILL.md 的 description，確認這個說法沒被別支 skill 用
- 改觸發詞之前先跑觸發詞測試，確認新寫法不會搶走別支 skill 的說法、也不會被別支搶走，跑法見 `evals/README.md`

**好 / 壞例：**

- ❌ 壞（塞規則、列技術術語）：`處理工單建立流程，會呼叫 GitLab API v4 的 issues endpoint，支援 labels 陣列與 assignee，並依 routing-rules 決定 project…`
- ✅ 好（觸發訊號 + 一句摘要）：`建工單（GFF 需求單 / OP 線上問題單）。當說「開工單 / 拆子任務 / 為何看不到銀行」時觸發。`

### 6. Reference 子文件

放在 `plugins/<plugin>/skills/<name>/reference/` 下，每個檔案專精一件事：

- `subtask-patterns.md`（不要寫成 `details.md`、`misc.md`）
- 檔案太長 → 拆多個檔案 + SKILL.md 加 ToC 連結

**Reference 定位 doctrine**（決定「該不該拆」）：

- reference 是**例外與校正層**，不是深度資料倉：放「常見路徑用不到、只在特定步驟 / 例外 / 校正時才需要」的內容。每次使用都要讀的，留主檔。
- **主檔自足優先**：SKILL.md 在不讀任何 reference 時，應能解決絕大多數情境；reference 只在資料不正確、或遇某些例外規則時補強校正。
- **correctness / 可讀性 > token**：若拆分造成「跳著看才連貫」的可讀性斷層，寧可不拆（runtime friction 見 `project-conventions` 的「Runtime friction 優先」節）。
- 拆分前自問：這段是否每次都用到？外移後主檔是否仍自足？瘦身後若 reference 只剩每次必讀內容，**fold 回主檔**比硬留更對（例 `whitelabel-db-query` 的 scoped-templates 併回主檔）。

### 6-1. Markdown 文件位置

AI runtime 規則寫在對應 skill 的 `SKILL.md` / `reference/` 內；給人閱讀的文件（marketplace 導覽、plugin catalogue）放 `docs/`；root 入口文件（README / ONBOARDING / CONTRIBUTING / CLAUDE.md）維持在 repo 根。

### 6-2. Skill 內容須跨用戶自足（禁引用作者本地 memory）

Skill 是給所有安裝者用的，但作者本地的 Claude auto-memory（`~/.claude/projects/<專案>/memory/` 下的 `feedback_*` / `reference_*` / incident 記憶）只存在作者機器上，其他用戶讀不到。「Reference 子文件」節的「主檔自足」延伸到跨用戶層：**skill 不得依賴任何 user memory 才能運作**。

- 🚫 **禁止在 SKILL.md / reference 引用 user memory key**：如 `依 memory \`feedback_xxx\``、`` `register-mobile-9015-debug` memory ``、`[[reference_yyy]]` wiki 連結。這些對其他用戶都是死引用。
- **實質內容落成文字**：memory 承載的規則 / SOP 直接寫進正文或 reference，讓 skill 不讀 memory 也自足。
- **改引共用 SSOT**：規則已有權威來源時指向它、不指 memory（commit 規範 → `git-commit` skill；慣例 → 本 `CONTRIBUTING.md` 章節）。
- **失效 / 一次性 memory 直接刪**：已結案 incident、標「一次性」或已不存在的 memory 引用，清掉不留。

### 6-3. 白話寫作規範（skill 正文、reference 讀者文案、commit message、MR description、工單）

**定義**：沒參與討論的人，不用先學代碼或行話，讀一遍就知道這段在說什麼、要做什麼；原本的規則、數字、實測事實一個都不少。這裡要的不是「講給外行聽」的簡化，而是「第一次讀就懂、內容一分不減」。skill 對 commit message 與工單的可讀性要求（`git-commit` skill「描述要寫給團隊看」、`sure-score` 的評分紀律），skill 本身同樣要遵守。

**四條判準（不用讀者查不到的代碼、術語第一次出現就解釋、一句話講一件事、內容一分不減）與寫法細則的正本是 `plugins/dev-toolkit/skills/plain-writing/SKILL.md`**：寫 skill 正文、reference 讀者文案、commit message、MR description、工單時，用 Skill 工具叫起 `dev-toolkit:plain-writing` 照它寫，本節不複述（`scripts/validate.sh` 第 10 項會確認本節仍指向它）。

本 repo 在那四條之上的補充：

- 對應 reference 檔名或 schema id 的代碼（`Mode A`、`Phase 2`、`Q3`、`A-payment`）放括號當別名，不動 reference 的 id。篇幅較長的 skill 在開頭放「一句話版本」與「名詞速查」表。
- **領域名詞用正名**：幣別、代理與組別、金額換算這類平台領域名詞，以 APIs 專案根目錄的 `GLOSSARY.md`（詞彙表，每條附「避免使用」的別名）為準，轉寫文案時用它的正名、不用它列為避免的詞（對帳名詞已收在它的「對帳」節）；對帳資料表與時間欄位見 APIs `docs/對帳資料表與時間欄位.md`，金流接入操作用語見 APIs `docs/金流接入流程/`。詞彙表只在 APIs 維護，本 repo 不複製、不搬移；skill 正文需要某個領域名詞時直接寫出它的定義，不要求讀者去讀 APIs（見「Skill 內容須跨用戶自足」）。
- reference 只改讀者會看到的文案（工單模板、摘要格式、標題規則）；`api-cheatsheet`、`mcp-setup`、`parse-schema`、`questions.yaml` 這類純機器規格不套白話規範。

自檢：改完用 `grep -c $'\u2014' <檔案>` 應為 0（搜尋 U+2014 這一個字元，單個與連用兩個的破折號都會抓到）；再把自己當成沒跟過這條線的同事，從頭讀一遍。

### 7. 不要碰的東西

- 🚫 不要在 skill 內硬寫個人 token / PAT / 密碼
- 🚫 不要 commit `.DS_Store` / IDE config / 個人筆記
- 🚫 不要在 plugin wrapper（`bin/*.js`）內呼叫 `sudo` / 改使用者 shell config / 改 git global config

文件示範 PAT 一律用含 `xxxx` 佔位符：✅ `glpat-[REDACTED]`；❌ 任何 `glpat-` 後接 20 個真實字元的 token（會被 `validate.sh` 祕密掃描判為外洩）。

### 8. Commit message

遵循 `git-commit` skill 規範（繁中、`<type>(<scope>): <subject>`，type 限定 feat/fix/refactor/test/chore/docs/perf/skill 等）。

例：
- `skill(op-ticket-create): 加入 Mode B 寫入順序鐵則`
- `feat(plugin-marketplace): 新增 xxx-toolkit plugin`
- `fix(pm-ticket-create): 修正 labels 必須為 array`

### 9. Plugin 修改流程

| 動作 | 規矩 |
|---|---|
| 改 SKILL.md 內容 / 新增 skill | 本地驗證綠後開 MR；只改內文、新增 skill，或只新增觸發說法不需要核准；讓原本會觸發的說法不再觸發，或移除 skill，要貼 `breaking` 標籤並由審核人核准（見「合併前核准」）；新增 skill 建議附對話範例；新增 skill 或改到觸發詞時，同一個 MR 更新 `docs/skill-cheatsheet.html`（不收錄的 skill 列進頁內 `cheatsheet-skip` 註解） |
| 改 plugin.json / .mcp.json / bin/wrapper | 標 `breaking` label；需手動測 install + /reload-plugins + /mcp 通過 |
| 改 marketplace.json（新增 plugin / 改 description / 改 source） | 標 `breaking` label + 更新 ONBOARDING + README |
| 新增 plugin | 同上 + 新 plugin 自身的 cold-start 流程文件 |

**Reviewer**：本 repo 一律走 MR；動到「合併前核准」所列範圍的 MR，要由作者以外的審核人核准才能合併，範圍、審核人名單與流程見該節。

### 9-1. Hotfix 流程

production 出包時：

1. 從 `main` 開 `hotfix/<topic>` 分支，修最小範圍。
2. 開 MR + 標 `hotfix` label。
3. 找得到審核人就照常核准；找不到時，`hotfix` 標籤會讓核准檢查直接放行，作者可以自己合併。合併後 `合併後檢查核准` 會發 Slack 提醒，審核人要在 1 個工作天內補審（見「合併前核准」）。

### 10. 白標事實 SSOT（apis-toolkit）

`apis-toolkit` 各 skill 涉及白標識別與查詢範疇時，**白標事實一律以 `plugins/apis-toolkit/registry/whitelabels.metadata.yaml` 為單一真實來源**，skill **禁止硬抄**白標事實（canonical 名 / 別名、connectionId、rootAgentId、hierarchy_path）。已知例外：`gcp-log-troubleshoot` 內嵌白標 → GCP project 對照表（表內含 canonical 名與別名），已列在「SSOT 來源對照」表的已知小成本。

- **穩定鍵**（connectionId、rootAgentId 等）：執行時 `Read` registry 取值代入；skill 正文不列舉具體數值。
- **易變值**（hierarchy_path）：不入 registry，由 rootAgentId 在 DB 衍生（`SELECT hierarchy_path FROM agents WHERE id = <rootAgentId>`），避免與 DB 對賭同步。
- **查詢一律以白標 rootAgentId 切 hierarchy 子樹範疇**；對「未指定白標」須 fail-closed（abort，除非明確要求整庫 census 並標示）。`sharesConnection: true`（多白標共用 DB，如 VWorld/WTT）尤須嚴守。
- **vendor 層事實**（`vendor_info_id` 等 vendor × 白標 二維鍵）不屬白標 registry，留各 vendor 對接卡。
- **驗證分層**：`check-registry.php` 驗結構/型別（**CI 硬閘**＋本機/pre-commit，同一支 php SSOT）；`verify-registry-live.py` 驗 live 語意（rootAgentId 存在、username 吻合、`hierarchy_path == /1/<id>`，DB 可達時跑）。結構驗證擋不住「打錯但合法」的 rootAgentId，故**rootAgentId 異動或新增 production 白標後，DB 可達時須跑 live 驗證**。live 語意非 CI 硬閘（CI 無 DB）。
- registry schema 與消費契約見 `plugins/apis-toolkit/registry/README.md`；新增白標只改 registry 一處。

### 11. Helper 與高風險寫入安全邊界

- Helper / wrapper 只用於高頻、機械性、人工易犯錯且後果嚴重的操作；不為日常查詢或低風險流程建沉重 helper。`validate.sh` 只驗結構 / manifest / 祕密 / MCP pin / 必要硬性不變式，不把每個 workflow 的 runtime gate 都塞進去。
- 高風險資料寫入的安全邊界放**可審計流程**（既有 Artisan command / 專用腳本、dry-run / execute、明確環境標示、完整輸出日誌、必要時人工確認），不靠 prompt 規則或 SQL guard 假裝能完整防事故。

---

## 發布與版本機制

本 repo 走 **commit-SHA 版本制**：`plugin.json` 刻意不設 `version`，Claude Code 自動以 git commit SHA 當版本，**每個 commit 即新版本**（官方明文支援，且為快速迭代的推薦做法）。

- **版本解析順序**（官方）：`plugin.json` `version` → `marketplace.json` entry `version` → **git commit SHA** → `unknown`。本 repo 用第 3 層，故**不要**在 `plugin.json` / `marketplace.json` 設 `version`：一旦設了，推新 commit 不會讓使用者的 plugin 換版（手動更新與自動更新都會判為「已是最新」）。
- **發布 ＝ 變更 merge 進 main**：所有改動一律走 MR（見「變更流程」），MR merge 到 main 即發布；breaking change 另標 `breaking` label（觸發 Slack 通知，見下）。
- **使用者取得新版**：團隊統一做法是兩步，都在 Claude Code 對話框裡完成，沒有作業系統差異：
  1. **每個人開一次自動更新**：`/plugin` → Marketplaces → `gff-marketplace` → Enable auto-update。gff-marketplace 是第三方市集，官方預設不開。開了之後每次啟動會在背景更新清單與已裝 plugin，照提示跑 `/reload-plugins` 套用。
  2. **要馬上拿到新版**：`/plugin marketplace update gff-marketplace`，再 `/reload-plugins`。第一行會把已裝的 plugin 一併換到新 commit，有沒有開自動更新都一樣（Claude Code 2.1.280 實測：沒開自動更新的第三方市集也從 `bb6c1cf` 換到 `8c28e93`，輸出 `1 plugin bumped`；官方文件只寫這個命令會更新市集清單）。
  
  文件與 Slack 通知只寫這兩步。不要寫逐支 `/plugin update <plugin>@gff-marketplace`：它會打開 plugin 選單、不會直接更新（Claude Code 2.1.280 實測）。
- 來源：[plugins-reference #version-management](https://example.invalid/docs/en/plugins-reference#version-management)、[plugin-marketplaces #version-resolution](https://example.invalid/docs/en/plugin-marketplaces#version-resolution-and-release-channels)、[discover-plugins #configure-auto-updates](https://example.invalid/docs/en/discover-plugins#configure-auto-updates)、[discover-plugins #apply-plugin-changes-without-restarting](https://example.invalid/docs/en/discover-plugins#apply-plugin-changes-without-restarting)。

---

## CI / 自動驗證

每次推分支自動跑 [.gitlab-ci.yml](.gitlab-ci.yml) 的硬閘 `validate:structure`。分支已開 MR 時跑的是 MR pipeline（開 MR 當下也會跑一次），還沒開 MR 時跑分支 pipeline，同一次推送只會跑一條。推 tag 不會起 pipeline：

| Job | 內容 | 擋不擋 merge |
|---|---|---|
| `validate:structure` | `bash scripts/validate.sh`：marketplace.json 合法、plugin source 存在且 name 一致、SKILL.md 含 `name` frontmatter、plugin 內 markdown 的相對連結不退出自己的 plugin 目錄、掃密鑰外洩（GitLab PAT、Slack token、GCP 私鑰、AWS Access Key ID）、MCP 套件已 pin 版本、agent 引用的市集 MCP 存在、各 plugin 的 hook 與 MCP wrapper 結構（DB 命令攔截 hook、gitlab-mcp、gcp-mcp、mysql MCP）、白標 registry 結構、MR 核准判定測試、MR 介入通知判定測試、寫作 skill 的結構約定（mr-report-writing 的 MR 報告驗證器要過自己的夾具測試、tech-doc-writing 與 mr-report-writing 要叫起 plain-writing、6-3 要指向 plain-writing）；Skill 速查表沒跟上 skill 異動只發警告。完整清單見 `scripts/validate.sh` 開頭 | 是（必過） |
| `notify:merge` | `php scripts/notify-merge.php`：push 進 main 後查該 commit 對應 MR，依變更性質分流發 Slack（`chat.postMessage`）：帶 `breaking` label，或動到 plugin 設定檔（`.claude-plugin/marketplace.json`、`plugin.json`、`.mcp.json`、`bin/`）時發重大變更通知（列 MR 標題、作者、連結與更新步驟；動到 MCP 設定時另提醒重啟、跑 `/mcp`，仍未上線才重裝）；否則動到 `plugins/*/skills/**`、`plugins/*/agents/**`、`plugins/*/commands/**` 發一般更新通知（列 MR 標題、作者、連結與受影響的 skill、agent、command，不附更新步驟）；兩者皆非則不發。動到哪些檔案用 git 算；算不出來時，重大變更通知照發但不列受影響的 plugin，非 breaking 的讓 job 失敗、不會靜默略過。需 CI 變數 `SLACK_BOT_TOKEN`（`xoxb-`，scope `chat:write`）/ `SLACK_CHANNEL_ID`（未設則 exit 0 略過）。僅 `main` + push 觸發 | 否（`allow_failure: true`，Slack 不通不擋 pipeline） |
| `需要審核人核准` | `php scripts/approval-check.php check`：只在 MR pipeline 出現的手動 job。MR 沒動到 `.gitlab/approval-rules.json` 所列的路徑、也沒貼 `breaking` 標籤時直接通過；動到時，讀 MR 的系統留言，確認有作者以外的審核人核准、而且核准在最後一次推 commit 之後；沒有有效核准時，貼了 `hotfix` 標籤才放行。規則見上方「合併前核准」 | 正式模式是（沒按 ▶ 或沒通過都不能合併）；試行模式否（只顯示警告） |
| `提醒重新核准` | `php scripts/approval-check.php notify-stale`：只在 MR pipeline 出現、每次推 commit 自動跑。發現有審核人的核准早於最後一次推 commit（畫面上仍顯示已核准、實際已失效）時，由 bot 在 MR 留言 @ 那幾位，提醒先撤銷核准再重新核准、再按 ▶；同一個 commit 只提醒一次。需 CI 變數 `APPROVAL_BOT_TOKEN`（Project Access Token，`api` scope，Masked、不可 Protected；未設則 exit 0 略過） | 否（`allow_failure: true`，只提醒不擋合併） |
| `合併後檢查核准` | `php scripts/approval-check.php audit`：合併進 main 後回頭檢查，合併當下沒有有效核准（不論有沒有 `hotfix` 標籤）、查不到合併時間、或檢查本身執行失敗，都用 `notify:merge` 同一組 Slack 變數發警告；兩個 Slack 變數沒設定時只印在 job log。僅 `main` + push 觸發 | 否（`allow_failure: true`） |
| `MR 介入通知` | `php scripts/mr-intervention-notify.php`：只在排程 pipeline 出現（排程起的 pipeline 只跑這一個 job）。掃描所有開著的 MR，輪到誰動手就在 MR 留言加 Slack @ 誰，見上方「合併前核准」的「MR 介入通知」 | 否（`allow_failure: true`） |

- **runner**：用 `php8` tag（K8s executor，與 apis 共用的 group runner）；無 tag 的 job 不會被撿走。
- **image**：內部 `phpbase/8`（runner 只拉得到內部 registry，公開 Docker Hub 鏡像會 ImagePullBackOff）。`validate.sh` 用 **php** 解析 JSON（不需 jq / apk add / npm install）。
- **官方 `claude plugin validate`** 沒有在 CI 跑，pre-commit 也不跑，請在 commit 前於本機手動跑（用一般模式而非 `--strict`：本 repo 走 commit-SHA 版本制、plugin.json 刻意不設 `version`，`--strict` 會把「無 version」警告判成錯誤）。CI 的 job 連得到公開網路（npm、Debian 套件來源實測可連），但 `phpbase` 映像檔沒有 node，apt 提供的 nodejs 版本低於 Claude Code 的 npm 套件要求（實測 20 對 22 以上），要另外安裝新版 node 才跑得了。
- **`breaking` label**：MR 變更若屬 breaking（manifest / `.mcp.json` / 讓原本的觸發說法失效等，見「Plugin 修改流程」），合併前請掛上 `breaking` label，`notify:merge` 會在合併進 main 後據此發 Slack 通知團隊更新。動到 plugin 設定檔（`.claude-plugin/marketplace.json`、`plugin.json`、`.mcp.json`、`bin/`）時，忘了掛也會自動發重大變更通知；讓原本的觸發說法失效、移除 skill 從檔案路徑分不出來，一定要掛；只新增觸發說法的不掛，合併後照一般更新通知發。
- **一般更新通知**：沒掛 `breaking`、但動到 plugin 的 skill、agent 或 command（`plugins/*/skills/**`、`plugins/*/agents/**`、`plugins/*/commands/**`）的 MR，合併後 `notify:merge` 會自動發一則輕量通知（列受影響的 skill、agent、command），**不需掛任何 label**。只改 plugin 裡其他檔案、文件或 CI 時不發。用意是提醒團隊：commit-SHA 版本制下每次合併都是新版，沒更新就吃不到新版內容（取得方式見本節上方「使用者取得新版」），但這類變更不會讓舊版壞掉，不適合當 breaking 處理。

本機可先自驗（需 `php`，PHP shop 一定有）：

```bash
bash scripts/validate.sh
```

---

## 本機自動把關（hooks）

本 repo 有兩類本機 hook，皆為**防呆**，最終硬閘仍以 CI `validate:structure` 為準。三層職責互不重疊：

### Claude Code session hooks（`.claude/hooks/`，由 `.claude/settings.json` 註冊）

只在 **Claude 改檔的當下**生效，攔 AI 容易犯的兩類錯：

| Hook | 觸發點 | 作用 |
|---|---|---|
| `block-pat-write.sh` | PreToolUse（Write / Edit / MultiEdit） | 寫入磁碟前偵測密鑰（GitLab PAT、Slack token、GCP 私鑰、AWS key；各自的文件佔位符放行），命中即 exit 2 擋下寫入。它是「不要碰的東西」節祕密規則的「事前阻擋」版，也就是 validate.sh 第 4 項提前到寫入前執行。hook 本身出錯時擋下；本機沒有 node 時放行並提醒，交給 CI 掃描。validate.sh 第 4b 項會實際送假 token 測它還擋不擋得住。只管 Write / Edit / MultiEdit 這三種工具：用 Bash 寫入的檔案（例如 `sed -i`、heredoc）不經過它，只靠 CI 掃描 |
| `validate-on-edit.sh` | PostToolUse（Write / Edit / MultiEdit） | 改到 `plugin.json` / `marketplace.json` / `.mcp.json` / `hooks.json` / `SKILL.md` / `*.metadata.yaml`、`plugins/<plugin>/bin/` 正下方的 `.js`（MCP 包裝程式）或 `docs/skill-cheatsheet.html` 後立即跑 `scripts/validate.sh`，把結構錯誤即時餵回 Claude（exit 2 + stderr）；驗證通過但有警告（例如Skill 速查表沒跟上；改了 description 時一併列出該跑的觸發詞測試案例）時，同樣用 exit 2 把警告送回，讓 Claude 在同一個 MR 內處理 |

### Git pre-commit hook（`.githooks/pre-commit.sh`，每位開發者一次性安裝）

對**所有 commit（人 / AI / 任何工具）**生效，與 Claude hook **職責不重疊**：它管的是白標 registry ↔ DB 一致性，不是祕密 / manifest 結構：

- **registry-gated**：只在 `plugins/apis-toolkit/registry/whitelabels.metadata.yaml` 被 staged 時觸發，其餘 commit 零摩擦。
- 觸發時跑 `check-registry.php`（結構 / 型別，失敗即擋，與 CI 同一支）＋ `verify-registry-live.py`（live 語意：DB 連不到只警告放行、值不一致才擋）。
- 安裝指令見「開發環境」；驗證分層與消費契約見「白標事實 SSOT（apis-toolkit）」與 `plugins/apis-toolkit/registry/README.md`。

### 三層防線分工

| 層 | 範圍 | 管什麼 | 是否硬閘 |
|---|---|---|---|
| Claude session hooks | 只限 Claude 用 Write / Edit 改檔的當下 | 密鑰外洩、manifest 結構 | 否（AI 防呆） |
| git pre-commit | 所有 commit（裝了 hook 的環境） | registry ↔ DB 一致性 | 否（DB 不可達只警告放行） |
| CI `validate:structure` | 每次推分支 / 開 MR | manifest / 祕密 / MCP pin / registry 結構等不變式 | **是（必過）** |

三者互補、不可互相取代：Claude hook 罩不到非 AI 的 commit，git pre-commit 罩不到別人沒裝 hook 的環境，registry↔DB **live 一致性**又不在 CI 範疇內。`validate.sh` 檢查的不變式由 CI 硬閘把關（registry 結構亦已納入 `check-registry.php`）。

---

## 開發環境

```bash
git clone http://example.invalid:1688/target/gffv3/gff-marketplace.git
cd gff-marketplace
# 一次性：安裝 pre-commit hook（白標 registry 變更時自動驗證；沒動 registry 的 commit 不受影響）
cp .githooks/pre-commit.sh .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
# 於工作分支改 plugins/<plugin>/skills/<name>/SKILL.md（勿在 main 直接改）
git switch -c skill/<topic> origin/main
# 另開終端機，只在這次 session 載入改過的 plugin 試（見下方「測試 skill 的最佳實踐」）：
#   claude --plugin-dir "$(pwd)/plugins/<plugin>"
git add <改過或新增的檔案>        # 新增的檔案（例如新 skill 的 SKILL.md）也要 add
git commit -m "skill(xxx): <message>"
git push -u origin skill/<topic>   # 開 MR（target main）；動到「合併前核准」所列範圍才要找審核人核准，再按「需要審核人核准」job 的 ▶；breaking 記得標 label
```

> pre-commit hook 只在 `plugins/apis-toolkit/registry/whitelabels.metadata.yaml` 被 staged 時觸發：`check-registry.php`（結構，失敗即擋）＋ `verify-registry-live.py`（live 語意，DB 連不到只警告放行、值不一致才擋）。詳見 `plugins/apis-toolkit/registry/README.md`「驗證分層」。

## 測試 skill 的最佳實踐

每次改完 SKILL.md 跑一次「冷啟測試」：

**怎麼載入改過的版本**：用 `claude --plugin-dir <repo>/plugins/<plugin>` 開新 session。這個旗標只在這次 session 有效；本機這份跟已安裝的同名 plugin 撞名時，這次 session 以本機這份為準，不用先解除安裝。改完檔案打 `/reload-plugins` 重新載入，不必重開（出處：[Test your plugins locally](https://example.invalid/docs/en/plugins)、[CLI reference](https://example.invalid/docs/en/cli-reference)）。例外：plugin 被 managed settings 強制啟用或停用時，這個旗標蓋不過去。

**不要用 `/plugin marketplace add` 載入本機這份 repo**：
- 它不接受 `file://`，本機路徑要寫成 `./` 開頭。
- 本 repo 的 `marketplace.json` 名稱是 `gff-marketplace`，跟大家已安裝的市集同名。同名的市集再 add 一次，會直接取代已安裝的那個（出處：[plugin-marketplaces](https://example.invalid/docs/en/plugin-marketplaces) 的 `name` 欄位說明）。

1. 開新對話（避免被上個對話的記憶影響）
2. 用 description 列的觸發詞之一觸發
3. 看 AI 是否照新規則走完整流程
4. 留存對話 snippet 當證據（走 MR 時貼進 description）

---

## 反饋

實作中發現問題：開 issue 到 target/gffv3/gff-marketplace。
