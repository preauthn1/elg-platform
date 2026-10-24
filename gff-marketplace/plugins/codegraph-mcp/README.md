# codegraph-mcp

> GFFv3 團隊共用的 CodeGraph plugin：把 codebase 建成符號知識圖，讓 AI 用一次查詢取回相關符號的原始碼、呼叫路徑與影響範圍（MCP server，加一支開 session 時自動同步索引的 hook）。

## 是什麼

**`codegraph` MCP server**（不含業務 skill）：封裝 [`@colbymchenry/codegraph`](https://example.invalid/package/@colbymchenry/codegraph)，把專案的符號、邊與檔案索引進本機 SQLite，安裝後讓 Claude Code 多出符號層查詢通道。

適合的問題是**符號關係**，例如「誰呼叫這個方法（callers）」「它呼叫了誰（callees）」「改這裡影響範圍多大（impact）」。取回的是逐行標號的原始碼加上符號間的呼叫路徑，取代 grep 加逐檔 Read 的來回。

純文字搜尋（某個字串在哪、找檔名）仍走 Grep / Glob，不需要本 plugin。

**開 session 自動同步索引的 hook**（`hooks/session-sync.sh`）：每次開新 session、接續舊 session 或 `/clear` 時，對當前專案的索引做一次增量同步，補上 MCP server 沒在跑的期間漏掉的變更。專案沒建索引就不做事，只有一個例外：專案是 git worktree、而主 checkout 建過索引時，會在背景替這個 worktree 建一份。細節見「維護」一節。

> 本 plugin **不含 skill**。CodeGraph MCP server 啟動時會自行送出使用說明給 AI（包含「沒有索引的專案改用內建工具」的指引），不需要再包一層 skill 複述。

## 前置條件

| 依賴 | 說明 |
|---|---|
| Node.js + `npx` | macOS：`brew install node`；Windows：[example.invalid](https://example.invalid/) LTS |
| 網路 | 首次啟動會從 example.invalid 抓套件（版本 pin 於本 plugin 的 `.mcp.json`）。含平台 binary 約 250 MB，首次會等一段時間 |
| 磁碟空間 | 每個建了索引的專案會產生 `.codegraph/`，大小隨 codebase 而定（GFF-Marketplace 約 1.3 MB，APIs 約 174 MB）。主 checkout 建過索引的 repo，每個 git worktree 開 session 時也會各建一份 |

不需要憑證、不需要登入，索引與查詢全部在本機完成。

## 安裝

### 1. 安裝 plugin

```
/plugin install codegraph-mcp
/reload-plugins
/mcp
```

Expected：`codegraph` server 狀態為 `connected`。

> **第一次啟動會比較久。** MCP server 以 `npx` 執行，首次要從 npm 下載對應平台的預編譯 binary（單一平台約 250 MB），期間 `/mcp` 可能顯示連線失敗或長時間無回應。等下載完成後再跑一次 `/reload-plugins` 即可。之後啟動讀 npx 快取，不會重新下載。

此時 MCP 已上線，但**還查不到任何東西**：CodeGraph 只讀各專案自己的 `.codegraph/` 索引，而索引要手動建。

### 2. 為想查的專案建索引

索引是**每個專案各自建、每台機器各自建**的本機產物，不進 git。到專案根目錄執行：

```bash
npx -y @colbymchenry/codegraph@<version> init -y
```

`<version>` 請對齊本 plugin `.mcp.json` 內 pin 的版本，避免索引格式與 server 不一致。

常用到的話也可以全域安裝，之後直接打 `codegraph`：

```bash
npm i -g @colbymchenry/codegraph@<version>
codegraph init -y
```

`init` 會建立 `.codegraph/`，內含 SQLite 索引、WAL 檔與 daemon 產物。

### 3. 讓 `.codegraph/` 不要污染 git

`.codegraph/` 自帶 `.gitignore`（內容為 `*` 加 `!.gitignore`）排除自身內容物，但**目錄本身仍會以 untracked 出現在 `git status`**。

- `GFF-Marketplace`、`APIs` 已在 repo 層處理好，不需額外動作。
- 其他 repo 請自行擇一：加進該 repo 的 `.gitignore`（全團隊生效，需走該 repo 的 MR），或加進 `.git/info/exclude`（只影響自己）。

```bash
echo '.codegraph/' >> .git/info/exclude
```

### 4. 驗證

```bash
codegraph status      # 或 npx -y @colbymchenry/codegraph@<version> status
```

Expected：印出 Files / Nodes / Edges 統計與語言分佈。接著開新對話，請 AI 查某個函式的 callers，確認能取回原始碼與呼叫路徑。

## 索引範圍與限制

**索引只涵蓋 CodeGraph 支援的程式語言。** 以 GFF-Marketplace 實測（v1.6.0）：19 個檔案進圖，語言分佈為 javascript 11、php 3、yaml 3、python 2。

同一個 repo 內的 **17 支 `.sh` 與 113 支 `.md` 完全沒有進圖**。也就是說：

| 要查的東西 | 用什麼 |
|---|---|
| JS / TS / PHP / Python 等支援語言的符號關係 | `codegraph` MCP |
| shell 腳本、Markdown、設定檔內容 | Grep / Glob / Read |

查不到結果時先確認「這個檔案的語言有沒有進圖」，再判斷是真的沒有這個符號。

## 維護

索引靠兩個機制跟上檔案變更，一般不需手動同步：

| 機制 | 什麼時候作用 | 怎麼確認 |
|---|---|---|
| MCP server 的 file watcher（監看檔案變更的背景程式） | server 執行期間，檔案一改就同步（server 自述延遲約 1 秒） | `.codegraph/daemon.log` 會記 `Auto-synced N file(s) in Xms` |
| 開 session 自動同步（本 plugin 的 SessionStart hook） | 開新 session、接續舊 session、`/clear` 時各跑一次 `codegraph sync` | `.codegraph/session-sync.log`，只留最近一次的結果 |

**為什麼需要第二個機制**：背景程式不會一直開著。沒有 session 連著超過 5 分鐘它會自己關掉（`daemon.log` 記 `Shutting down (idle timeout; clients=0)`），卡住約 60 秒也會被內建的看門狗強制結束（`Main thread unresponsive for ~60s`）。它停著的期間做的 `git pull`、切分支都不會進索引，下次開 session 時由 hook 補上。

**hook 的行為**：

- 專案根目錄沒有 `.codegraph/` 就直接結束，沒建索引的專案不受影響。例外是 git worktree，見下方「worktree 自動建索引」。
- 只用本機已安裝、版本與 `.mcp.json` 相同的 CodeGraph：先找全域安裝的 `codegraph`，再找 npx 快取（MCP server 啟動時下載到的那一份）。版本相同，索引格式才不會跟 MCP server 不一致。
- 不經過 `npx`，不下載套件。`npx` 即使已經有套件也會先連 npm registry 查版本，registry 沒回應時會讓開 session 卡住（實測卡滿 20 秒以上）。本機找不到相同版本時，這次略過並記在 log，等 MCP server 啟動把套件下載完，下次開 session 就會同步。
- PATH 上的 `codegraph` 或 npm 快取若位在專案目錄內（例如專案的 `node_modules/.bin`），不會被採用。這只比對路徑字串，擋的是常見設定；PATH 裡用 symlink 或 `/./` 繞進專案的寫法擋不到，PATH 上的 `node` 也不檢查。
- 這次呼叫關掉 CodeGraph 的使用統計（`CODEGRAPH_TELEMETRY=0`）：它是自動執行，不算使用者下的命令，也不該為了上傳統計而連網路。同步本身是否完全不發網路請求，沒有實測。你自己下的 `codegraph` 命令與 MCP server 不受影響。
- 索引正被另一個程序鎖住時（`.codegraph/codegraph.lock` 存在），先等最多 3 秒。等不到就在 log 寫一行「索引被另一個程序鎖住」再照常執行。要留意這一行：CodeGraph 拿不到鎖時不會報錯，照樣印 `Already up to date` 並回報成功，實際上沒有同步。
- 同步失敗不會出現在對話裡，也不會擋住開工，錯誤只寫進 `.codegraph/session-sync.log`。懷疑索引沒跟上時先看這個檔。
- 最多跑 30 秒，超過會被 Claude Code 中止。APIs（4,380 個檔）實測同步 24 個檔花 0.8 秒。
- Windows 上 hook 透過 Git Bash 執行，尚未實測。

**worktree 自動建索引**：專案是 git worktree（`git worktree add` 建出來的工作區）、自己沒有 `.codegraph/`、而主 checkout 有的時候，hook 會在背景對這個 worktree 跑一次 `codegraph init -y`。

- 為什麼：worktree 的代碼是另一個分支。拿主 checkout 的索引來查，行號與新增的方法會對不上，所以要有自己的一份。主 checkout 建過索引，代表你要在這個 repo 用 CodeGraph。
- 在背景跑，不拖慢開 session：Claude 的第一則回應要等 hook 結束，而建索引的時間隨 repo 大小而定（APIs 4,513 個檔實測 6.4 秒）。實測用故意拖慢 12 秒的假 `codegraph`，session 照常在 6 秒內回完，背景的建索引之後才跑完。
- 佔用空間：每個 worktree 一份，大小與主 checkout 的索引相近（APIs 的 worktree 實測 209 MB）。不想要時在該 worktree 跑 `codegraph uninit`；只要主 checkout 還有索引，下次開 session 會再建。
- log 在這個 worktree 的 git 目錄：`git rev-parse --absolute-git-dir` 印出的目錄底下的 `codegraph-init.log`。建好之後的每次同步照舊寫 `.codegraph/session-sync.log`。
- 上一次啟動的建索引還在跑時不會重複啟動（同目錄的 `codegraph-init.pid` 記著它的 PID）。
- 只認 worktree 的根目錄。主 checkout 自己沒建索引、或專案不是 git worktree，都不會自動建。
- `.codegraph/` 要不要出現在 `git status`，看 repo 怎麼忽略它：寫在主 checkout 的 `.git/info/exclude` 或 repo 的 `.gitignore` 都會套用到 worktree。

**增量同步不等於整個重建**：APIs 實測，`codegraph index` 整個重建後的關聯（edges）比 `codegraph sync` 增量同步多 864 條（139,662 對 138,798），原因尚未確認。`git pull` 拉進大量變更、或切到差異很大的分支之後，建議手動跑一次 `codegraph index`（APIs 約 6.5 秒）。

下列命令用於想立刻確認索引狀態、或需要手動處理時。

| 情境 | 命令 |
|---|---|
| 改完程式碼、要讓索引跟上 | `codegraph sync` |
| 大幅改動後重建 | `codegraph index` |
| 看索引統計與是否最新 | `codegraph status` |
| 索引被 lock 卡住 | `codegraph unlock` |
| 停掉背景 daemon | `codegraph daemon`（互動選單） |
| 移除索引 | `codegraph uninit`（刪除整個 `.codegraph/`） |

> 懷疑查到舊碼時，跑 `codegraph status` 看是不是 `Index is up to date`，必要時 `codegraph sync`。

改動 `hooks/` 底下的檔案後，本機跑一次整合測試（用假的 `codegraph`，不碰真實索引）：

```bash
bash plugins/codegraph-mcp/hooks/session-sync.test.sh
```

## MCP server

`.mcp.json` 註冊的 server 名稱與啟動方式（**版本 SSOT 在該檔，README 不複述版號**）：

```json
{
  "mcpServers": {
    "codegraph": {
      "command": "npx",
      "args": ["-y", "@colbymchenry/codegraph@<version>", "serve", "--mcp"],
      "env": {
        "CODEGRAPH_NO_DOWNLOAD": "1"
      }
    }
  }
}
```

不需要 wrapper：CodeGraph 不讀取任何憑證，啟動前也不需要解析環境或探測路徑（既有 `gitlab-mcp` / `gcp-mcp` 的 wrapper 是為了讀 PAT 與解析 npm 快取入口），直接由 `npx` 以 pin 的版本啟動即可。`CODEGRAPH_NO_DOWNLOAD` 的用途見下一節。

**per-project 查詢**：server 啟動位置若沒有自己的 `.codegraph/`，就沒有預設專案，但工具仍可用。查詢時傳 `projectPath`（專案路徑或其下任一目錄），CodeGraph 會往上找最近的 `.codegraph/` 作答，一個 session 內可查多個專案。

## 供應鏈與安全邊界

CodeGraph 由個人維護者發布於公開 npm，實際執行的是**預編譯 binary**（各平台各一個 optionalDependency 子套件），內容無法逐行審計。導入前查證過的事實：

| 檢查項 | 結果 |
|---|---|
| 安裝期 script（`postinstall` / `preinstall`） | 無（`scripts: null`），不存在安裝即執行的入口 |
| runtime 相依 | 無（`dependencies: null`），攻擊面不向下擴散 |
| 憑證持有 | 無。不讀 token、不連資料庫，索引與查詢全在本機 |
| 授權 / 原始碼 | MIT，`example.invalid/colbymchenry/codegraph` |

**已封閉的路徑**：套件的 `npm-shim.js` 在「npm registry 沒提供對應平台包」時，會從 GitHub Releases 下載壓縮檔解壓後執行。它雖有 checksum 驗證，但 **checksum 與檔案取自同一個來源 URL**，只能防傳輸損毀，防不了來源本身被攻陷。

本 plugin 因此在 `.mcp.json` 釘上 `CODEGRAPH_NO_DOWNLOAD=1`，讓該 fallback 直接拒絕執行，只保留 npm registry 的內容。開 session 自動同步的 hook 執行同一個套件，也帶同一個設定。

**副作用**：若你的 npm 走鏡像（npmmirror / cnpm / 公司 proxy），鏡像常不同步 per-platform 的 optionalDependencies，會取不到平台包而啟動失敗。解法是從官方 registry 安裝：

```bash
npm i -g @colbymchenry/codegraph@<version> --registry=https://example.invalid
```

## 常見問題

| 症狀 | 原因 / 解法 |
|---|---|
| `/mcp` 看不到 `codegraph` | 確認已安裝 `codegraph-mcp`，再跑 `/reload-plugins` |
| `failed to spawn npx` | host 缺 Node.js / npx，先安裝 Node.js LTS |
| MCP connected 但查不到任何符號 | 該專案還沒建索引，到專案根跑 `init`；或查詢時沒傳 `projectPath` |
| 查得到別的符號、就是查不到某支腳本 | 該語言不在索引範圍（例如 shell），改用 Grep |
| 在 git worktree 裡查不到符號，或查到的是別的分支的代碼 | 這個 worktree 還沒有自己的索引。開一次 session 會自動在背景建（主 checkout 要先建過）；沒建成時看 worktree git 目錄下的 `codegraph-init.log` |
| 查到的原始碼是舊的 | 索引沒跟上，跑 `codegraph sync`；每次開 session 都這樣，看 `.codegraph/session-sync.log` 寫了什麼原因 |
| `git status` 冒出 `?? .codegraph/` | 見「3. 讓 `.codegraph/` 不要污染 git」 |
| npx 抓套件失敗或解壓半殘 | 清除 npx 快取後重試：`rm -rf ~/.npm/_npx` |
| 啟動失敗且訊息提到 `network fallback is disabled` | npm 走了鏡像、取不到平台包。見「供應鏈與安全邊界」，改用官方 registry 安裝 |
