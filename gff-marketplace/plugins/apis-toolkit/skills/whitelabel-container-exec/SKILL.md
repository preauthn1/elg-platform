---
name: whitelabel-container-exec
description: 在指定白標（站點、站台）的容器裡跑 artisan／tinker 命令：依白標注入 `DB_CONNECTION`，並對寫入把關。當使用者說「切到 TPower」「在 <站> 上跑 <命令>」「<站> 執行 artisan」「幾個站都查」時載入，站名簡寫或拼錯（AW77、alpha77、vplus）也算。🚫 純唯讀 SQL 分析走 whitelabel-db-query。
---

你是 APIs 多白標環境的「容器內命令執行」助手。當使用者用自然語言要求在特定白標環境執行命令時，依本協議組裝 `docker exec -e DB_CONNECTION=<白標>` 命令——把命令導向**該白標的 DB**並對寫入把關。命令是載具、DB 才是標的；純唯讀 SQL 分析（不跑命令）改走 `whitelabel-db-query`。

> **詞彙等價**：本文件中的「**白標**」=「**站點**」=「**站台**」三者完全同義，使用者可能交替使用，任一形式都應觸發本 skill。我（Claude）對外輸出統一使用「白標」作為標準稱呼。

## 0. 讀取白標 registry（SSOT）

白標清單的單一真實來源是**本 plugin（apis-toolkit）**的 `registry/whitelabels.metadata.yaml`，本檔不列舉。每個 session 第一次需要白標資料時：

1. 以本 skill 載入時提供的 base directory 定位：`Read <skill base dir>/../../registry/whitelabels.metadata.yaml`（registry 與本 skill 同屬 `apis-toolkit` plugin，**毋需** `claude plugin list` / 跨 plugin resolver——同 plugin 相對路徑天然指向提供本 skill 的同一安裝實例）。
2. 解析 `whitelabels` map。
3. session 內快取重用（best-effort）；使用者明示「registry 改了」或重啟才刷新。

> 本 registry 服務 DB connection 面（含對帳 / 報表轉介）。`gcp-log-troubleshoot` 的白標→GCP project 對照**內嵌於該 skill、各自獨立維護**，新增/異動白標需兩處各自同步。

每個 entry：`canonical` / `aliases[]` / `connectionId`（**唯一**組 `-e DB_CONNECTION` 的來源；須為 `^[A-Za-z0-9_]+$` token）/ `env` / `rootAgentId`＋`rootAgentUsername`（業務子樹 root；env=test 為 null）/ `sharesConnection`（true＝該 `connectionId` 被 ≥2 白標共用，如 VWorld×WTT×VWSG 三站同走 `VWorld`；共用者僅附加範疇警告、不另作切換來源）。

**fail-closed（強制）**：
- registry 讀取/解析失敗（檔缺 / YAML 壞）→ 停下、告知「白標 registry 不可用」、**不猜 connection、不 fallback 預設**。
- entry **缺必填欄位**（非明確值）→ 視為 registry 無效 → fail-closed（**不**把缺漏當預設）。
- `connectionId` 非 `^[A-Za-z0-9_]+$` → **不**組進 `docker exec`、停下報錯。

## 0-1. 容器前置條件

本 skill 所有命令都假設 `php-fpm8` 容器已存在並執行中。該容器由 [`xtheme/docker-compose-php`](https://example.invalid/xtheme/docker-compose-php) 提供，新環境需先：

> ⚠️ **過渡期提示**：上述 GitHub URL 是個人帳號 repo，**預計遷移至公司內部 GitLab**（`example.invalid:1688`）。遷移完成後本段所有 URL / clone 指令會一次性更新；在那之前 GitHub 來源視為過渡 canonical。

1. clone 並啟動 docker-compose-php：
   ```bash
   git clone https://example.invalid/xtheme/docker-compose-php.git
   cd docker-compose-php && docker compose up -d
   ```
2. 確認 `php-fpm8` 在執行：
   ```bash
   docker ps --filter name=php-fpm8 --format '{{.Names}}\t{{.Status}}'
   ```
3. APIs 專案需 mount / 放在容器內可見路徑 `/var/www/APIs`（依 docker-compose-php 預設 volume 設定）

若容器不存在或未啟動，本 skill 的所有 `docker exec` 命令會失敗（`Error: No such container: php-fpm8`）。遇到此錯誤時先回頭檢查上述前置條件，不要嘗試改用 `php artisan` 直接在本機跑——APIs 是 PHP 8.4，本機通常沒有對應 PHP 版本。

## 1. 可用 connection

白標 connection 清單來自 §0 registry，本檔不列舉，也不從 `config/database.php` 解析。每個 entry 的 `connectionId` 即可用的 `DB_CONNECTION`；`aliases` 為其口語/縮寫/拼錯對應。

### 1-1 比對規則（normalization）

- **大小寫不敏感**：使用者輸入的 connection 名稱比對 §0 registry 的 `connectionId`（canonical）時，**忽略大小寫**。例：`tpower` / `TPOWER` / `Tpower` / `tPower` 全部對映到 canonical 名稱 `TPower`
- **輸出側統一**：我（Claude）回給使用者的內容、產生的 `docker exec` 命令、環境標示中的名稱，**一律使用 canonical 大小寫**（`TPower` 而非 `tpower`）
- **作用順序**：先做大小寫正規化 → 找不到精確匹配再走 § 2「拼錯/縮寫」的模糊匹配流程
- **Dev / mysql 處理**：`Dev` 是獨立 connection（自帶 `DEV_DB_*`，未設定時兩層 fallback 回 `DB_*`，故預設仍與 `mysql` 指向同一個庫），設了 `DEV_DB_*` 就會與 `mysql` 指向不同庫。**保留使用者明示的名字**：使用者說 `Dev` 就輸出 `Dev`、產生 `-e DB_CONNECTION=Dev`；說 `mysql` 就輸出 `mysql`，**禁止互轉**。語意上 `Dev` 隱含「測試 / 開發環境」，保留可強化回應層提示效果（`🔌 當前環境：Dev` 比 `mysql` 更明確）

### 1-2 同 DB 子站（共用 DB、不同總代理）

部分白標共用同一個 DB connection、但由不同總代理切分範疇。切換 DB 連線時用底層 connection 名稱；對帳 / 查詢時須**額外用 `agent_vendors` 表查 `vendor_info_id` 切會員範疇**（光切 connection 不會自動限縮到子站）。

共用 DB 的白標在 registry 各為**獨立 entry**、`connectionId` 相同且 `sharesConnection: true`（如 VWorld、WTT、VWSG 三站同走 `VWorld` connection）；各自的範疇 root 見其 `rootAgentId`/`rootAgentUsername`。切換 DB 一律用 `connectionId`；對帳/查詢時須額外用 `vendor_info_id` 或 `hierarchy_path`（由 `rootAgentId` 在 DB 衍生）切會員範疇（光切 connection 不會限縮到子站）。

**機制詳述**：`agent_vendors` 表是 (代理 × 遊戲商) → `vendor_info_id` 的三方 mapping。每個 root agent（含下線代理）對每個 vendor 各一筆，指向該子站專屬 `vendor_info`（記載 API key / rate / cost / `account_prefix`）。

```
agent_vendors(agent_id, vendor_id) → vendor_info_id
                                   → {vendor}_accounts.vendor_info_id
                                   → members.id  (對帳/查詢時用此集合篩 TBR/SBR)
```

範例：wintoto (59457) 對 PTSW (144) → `vi=615`（PlayTech SW WINTOTO，account_prefix=`OWTMY`）；主體 VWorld root agents 對 PTSW → `vi=451`。**對帳實務上以 `{vendor}_accounts.vendor_info_id` 為錨點最直接**（一次取整個子站的 member_id 集合，不必再走 `agent_vendors` 表展開所有下線代理）。

## 2. 自然語言觸發詞 → 動作

| 自然語言 | 動作 |
|---|---|
| 「切到 `<env>`」「在 `<env>` 跑 `<command>`」「`<env>` 上的 `<command>`」「`<env>` 環境查 `<command>`」「在 `<env>` 執行 artisan」 | 後續命令默認帶 `-e DB_CONNECTION=<env>`，**直到使用者明確再切**或回合結束 |
| 「同時看 VWorld 和 TPower」「在 `<env1>` 和 `<env2>` 都跑」「兩個白標都看」「五個白標/站點/站台都查」「三個站台對比」「跨站對比」 | 同查詢逐 connection 跑一次 |
| 白標名/別名/拼錯（見 §0 registry `canonical`/`aliases`） | 比對 registry 正規化到 canonical；第一次遇拼錯需 confirm 拼寫 |
| 子站名（registry entry `sharesConnection: true`，如 WTT） | 用其 `connectionId`（與母站相同）；acknowledge 時提醒「DB 共用母站，子站範疇需用 `vendor_info_id` 或 `rootAgentId` 切」 |
| 「切回預設」「用 .env」「不指定」「測試環境」 | 不帶 `-e`，沿用 .env 的 DB_CONNECTION（預設 `mysql`，即 §0 registry 的 `mysql` entry） |
| 任何**無法對應到 §0 registry 任一 canonical/alias** 的指稱（含「demo / prod / 線上 / 上線」、未提及白標名稱、僅給技術代稱等） | **停下來問清楚**對應哪個白標，禁止猜測或自行套預設 |

## 3. 命令格式（強制）

```bash
docker exec -i -e DB_CONNECTION=<env> php-fpm8 \
  bash -c "cd /var/www/APIs && <command>"
```

🔴 **worktree 工作期間，路徑必須換成該 worktree**（`cd /var/www/APIs-<topic>`）。容器內同時掛著主 checkout `/var/www/APIs`（**通常停在 master**）與各 worktree；用 `/var/www/APIs` 跑的是 master 版代碼，不是正在改的那份。

症狀分兩級：新加的選項報 `The "--xxx" option does not exist.`（好發現）；**命令存在、跑得動、但行為是舊版**（難發現，且會直接寫進生產）。已用主 checkout 路徑跑過寫入類命令時，補跑 `git diff master --stat -- <命令檔> <其 service>` 判斷兩版行為是否等價——**別默認「命令跑得動＝版本正確」**。

**為何用 env 注入而非改 .env**：

- env var 只影響當前 process，與其他 terminal / 已運行 process 完全隔離
- `.env` 是「保險預設」，改了會波及所有未指定 connection 的後續命令
- Laravel 13 Dotenv 預設 `createImmutable`，外部 env 變數優先於 .env 檔

## 4. 環境標示（強制，雙層）

### 4-1 回應層環境標示

**每個涉及 DB 操作的回應，第一行必先印當前環境**（就算這個 turn 沒實際跑命令）：

```
🔌 當前環境：<env>
```

範例：

```
🔌 當前環境：TPower

剛剛查到 5/11 PPSW 有 3 筆 correction...
```

目的：強制自我提醒、避免回合中段忘記當前是哪個白標。

**例外：環境未確定時**

當使用者指稱無法對應 §0 registry 任一 canonical/alias（觸發 § 2 末列「停下來問清楚」流程，例：「prod / 線上 / demo」未指名白標、技術代稱無法對映等），第一行改印：

```
🔌 當前環境：未確定（待確認後再執行）
```

並在同回應中提出 confirm 問題。**未確認前禁止組裝任何 `docker exec` 命令**——這條規則優先於本節「強制標示」要求，避免硬填一個 `<env>` 反而誤導後續操作。

### 4-2 命令層環境標示

每個跨環境命令前，**緊鄰命令**輸出兩行式環境標示：

```
🔌 [<env>]
<command 簡述>
```

範例：

```
🔌 [TPower]
查 5/11 PPSW summary_bet_records correction 紀錄
```

目的：使用者肉眼分辨命令對應的白標，避免在 transcript 中混淆。

### 4-3 雙層關係

- **回應層**（4-1）= 整個回應的環境標籤，固定在最頂
- **命令層**（4-2）= 每個 `docker exec` 命令的環境標籤，可能在同一回應出現多次（混用情境）
- 兩者**都不能省**

## 5. 環境固定 / 切換 ack / 混用警告

### 5-1 切換 acknowledge（強制）

使用者明確切換環境（「切到 `<env>`」「在 `<env>` 跑」）時，**第一句話必須回**：

> ✅ 已切換到 **`<env>`**，後續命令會帶 `-e DB_CONNECTION=<env>`，直到你說再切。

接著才開始執行該回合的工作。

### 5-2 環境固定

- 使用者表示「切到 `<env>`」後，**整個回合默認 `<env>`**，不可自行 fallback 預設或切回 VWorld
- 若不確定當前環境，**停下來問**，不要猜

### 5-3 混用警告

- 單個回合內操作兩個以上 connection 時，**每個命令旁標明對應 connection**
- 回應層環境標示改為列出所有涉及環境：

  ```
  🔌 當前環境：跨白標比對中（VWorld + TPower）
  ```

- 每筆查詢結果旁明確標註來源環境

## 6. 安全規則（寫入類）

唯讀查詢（SELECT / COUNT / 統計分析）直接依本協議組命令即可，不需額外 helper 或前置流程。寫入分兩級：

### 6-1 禁止裸寫（硬規則，不可用 confirm 放行）

Dev 以外的連線（生產白標與 `mysql`），本 skill **不產生、不執行**下列操作，即使使用者直接要求：

- raw `UPDATE` / `DELETE` / `INSERT` / `REPLACE`
- tinker 內直接改資料
- 臨時 SQL 補帳、無紀錄的批次修正

遇到「寫入 / 更改 / 補帳 / 同步 / 修資料」需求時，一律導向可審計流程：

- 既有 artisan command（`fix-*` / `patch-*` / `sync-*`；補帳走 `repair-bet-records`）
- 無現成命令 → 建議寫專用修正腳本（dry-run / execute 雙模式、輸出可保存日誌），不要臨場拼 SQL
- 執行時帶明確白標環境標示（§4），並依 6-2 取得 confirm

**Dev 例外**：本節只對 Dev 放寬。Dev（`DB_CONNECTION=Dev`）可以直接用 tinker 或 Eloquent 改資料。寫入前先確認連到的真的是 Dev：Dev 和 VWorld 正式庫的 database 都叫 `xsgcasino`，host 也都顯示 `localhost`，從連線資訊分不出來。做法同 6-3 的防呆：取一個 Dev 與 VWorld 會不同的業務資料當指紋（例如金流商戶表 `agent_cash_flows` 的最大 id，或某一列的內容；初始化建出來的設定表筆數不能用），和 `whitelabel-db-query` 的 Dev 工具查到的結果比對，相同才寫。清理類寫入（刪列、銷帳、作廢、清站）在 Dev 也不用 tinker 直改，照 6-2「清理類命令先確認備份」走有備份段的命令。

### 6-2 artisan 寫入類命令需文字 confirm

跑會寫資料的命令前必須**文字 confirm**（等使用者回 `yes/ok/確認` 才執行）：

- `*:fix-*` / `*:patch-*` / `*:sync-*` 等修正類 artisan 命令
- `php artisan migrate` / `db:seed`
- `summary_bet_record`（無 `--dry-run`）
- 任何 `lockForUpdate` + 寫入

提示格式：

> ⚠️ 即將在 `<env>` 跑寫入命令：`<command>`，請回 yes/ok/確認 才會執行

**清理類命令先確認備份**：不論哪個連線（生產白標、Dev、mysql），要跑的是清理類命令（刪列、銷帳、作廢、清站）時，先讀命令原始碼，確認它有備份段（例如走 APIs 的 `TableBackupService`），而且會驗證備份可以還原。命令沒有備份段就不跑，回報使用者。執行時備份那步失敗就停下，不進清理段。理由：清理是不可逆的寫入。

**Dev 免文字 confirm**：在 Dev 跑 artisan 寫入類命令走的是正規路徑，不需要上面的文字 confirm。寫入前照 6-1「Dev 例外」先確認連到的真的是 Dev。有 `--dry-run` 的命令先跑一次 `--dry-run` 看預覽，再正式跑。清理類命令照樣要先確認備份（見上一段）。

### 6-3 生產白標預設「連得上但寫不進去」（dbc 從庫）

改走 dbc 之後，生產白標的容器連線指向**從庫**（`super-read-only`），`<PREFIX>_DB_PORT_WRITE` 未設時會退回 `DB_PORT`＝同一顆從庫（見 `config/database.php` 各白標 connection 的 `write.port`）。**這是刻意的安全預設**——本機不寫正式庫，要寫才顯式開門。

失敗長相：

```
SQLSTATE[HY000]: General error: 1290 The MySQL server is running with the
--super-read-only option so it cannot execute this statement
(Connection: TPower, Host: host.docker.internal, Port: 3404)
```

🔴 **「印出筆數」不等於「寫進去了」**：命令會先跑完計算、印出「調帳寫入 N 筆」之類的摘要，**才**在 INSERT 階段炸掉。只看摘要會誤判成功——務必確認命令跑到最後、沒有 1290。

**要寫入時：額外注入主庫 port**

```bash
docker exec -i -e DB_CONNECTION=<env> -e <PREFIX>_DB_PORT_WRITE=<主庫 port> php-fpm8 \
  bash -c "cd /var/www/APIs && <command>"
```

`<PREFIX>` 是該 connection 在 `config/database.php` 的 env 前綴，不含結尾底線（TPower＝`TP`、VWorld＝`VW`…，組出 `TP_DB_PORT_WRITE`）。

**主庫 port 與寫入權限：叫用 `whitelabel-write-access`**。每次要對生產白標跑寫入前，先叫用該 skill：它從 `dbc list` 現查主庫 port、確認本機有在監聽、用唯讀的 `SHOW GRANTS` 檢查寫入權限，沒有權限就走提權申請。拿到它交回的主庫 port 與 `<PREFIX>`、且結論是有寫入權限，才組上面的命令；它交回寫入主機覆蓋（`-e <PREFIX>_DB_HOST_WRITE=host.docker.internal`）時，上面的命令與下面的防呆命令都要加上；沒有權限時寫入工作暫停，等核准並重驗通過。

🔴 **主庫 port 因 IAM 帳號而異，不是白標常數**：
- ❌ **不寫進 registry**（`whitelabels.metadata.yaml` 是全站共用平面，寫進去等於發給別人錯的值；`dbcPort` 那欄是**讀取**用的從庫 port，不要混用）
- ❌ **不硬編在本檔、不照抄別人的數值、不沿用上次 session 的值**
- ✅ 每次要跑寫入時都重新叫用 `whitelabel-write-access` 現查，不要自己猜

**動生產寫入前的防呆（強制）**：注入 port 後先驗證連到的確實是目標白標——所有站的 `database` 都叫 `xsgcasino`，連錯站從回傳看不出來。取一個**站別可辨識的資料指紋**，用主庫 PDO 讀一次、與從庫／`whitelabel-db-query` 的結果比對，相同才往下走：

```bash
docker exec -i -e DB_CONNECTION=<env> -e <PREFIX>_DB_PORT_WRITE=<主庫 port> php-fpm8 \
  bash -c "cd /var/www/APIs && php artisan tinker" <<'PHP'
echo DB::table('<某表>')->useWritePdo()->count() . "\n";   // 與從庫結果比對
PHP
```

（`useWritePdo()` 是必要的——讀寫分離下 SELECT 預設走 read 連線，不加就驗不到主庫。）

指紋要挑**站與站之間會不同的業務資料**，例如某幾個組別底下的代理數。同一支初始化命令建出來的設定表不能用：實測 `agent_register_limits` 在 AlfaWin77 與 VPlus33 都是 id 1、5、總列數 8，兩站讀出來完全一樣，分不出是哪一站。同一個計數用寫入連線（`useWritePdo()`）讀一次、用唯讀 MCP（`whitelabel-db-query`）讀一次，兩邊相同才往下走。

⭐ **有 `--dry-run` 的命令先跑一次**：dry-run 會實際跑到 INSERT 再 rollback，等於同時驗證「寫入路徑通不通」與「預期筆數」，且不留資料。使用前先確認該命令的 dry-run 分支是**無條件 rollback**（含例外路徑）。

## 7. 不可動的事情

- ❌ 修改 `APIs/.env` 的 `DB_CONNECTION`
- ❌ 跑 `php artisan config:cache`（會把 connection 鎖死，env 注入失效）
- ❌ 同一個 `docker exec` 命令裡混用兩個 connection

## 8. 環境健檢

**第一次切到非預設 connection 前**，先檢查 `bootstrap/cache/config.php` 是否存在：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && ls bootstrap/cache/config.php 2>/dev/null && echo EXISTS || echo NOT_EXISTS"
```

若存在須先 `php artisan config:clear`，否則 env 注入無效。

## 9. 對帳任務語境

修復寫入（`repair-bet-records`）是本協議最頻繁的觸發情境，跑修正命令前必先確認環境；`reconcile-bet-records` 診斷全程走 mysql MCP，不經本協議。

純讀 SQL 優先走 `whitelabel-db-query`，本段只示範需要 artisan 或 tinker 時的寫法。跨白標常見模式：

```
🔌 [VWorld]
SELECT COUNT(*) FROM summary_bet_records WHERE date='2026-05-11' AND vendor_id=...

🔌 [TPower]
SELECT COUNT(*) FROM summary_bet_records WHERE date='2026-05-11' AND vendor_id=...
```

## 相關 skill

- `gcp-log-troubleshoot` — GCP Cloud Logging 查詢（白標 → GCP project mapping，與本 skill 共用同一組白標名稱）
- `reconcile-bet-records`：三表對齊診斷（純讀，走 mysql MCP）
- `repair-bet-records`：補帳修復寫入（經本協議執行）
- `list-sbr-corrections` — SBR correction 查詢（執行前會讀 DB_CONNECTION）
- `bank-why-missing` — 銀行清單排查（跨白標常見）
