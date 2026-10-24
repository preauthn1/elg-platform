# apis-toolkit

APIs 專案專屬 plugin（於 APIs repo 內使用；安裝見根目錄 `ONBOARDING.md`）。本檔目前只放 **mysql MCP 連線設定**；plugin 內容名冊見根目錄 `README.md`，registry 契約見 `registry/README.md`。

## mysql MCP 連線設定 (SSOT)

七個白標 mysql MCP server（`mysql-vworld` 等，見 `.mcp.json`）由 `bin/mysql-mcp.js` 啟動，連線座標由 `registry/whitelabels.metadata.yaml` 的 `dbcPort` 決定走哪條路徑，全程唯讀（wrapper 強制 `ALLOW_*=false`＋READ ONLY transaction，伺服器層 error 1792 拒寫）：

| 路徑 | 觸發條件 | 座標來源 |
|---|---|---|
| 0. dbc 代理（主路徑） | 該白標 `dbcPort` 為 int（現況：VPlus33 以外全部） | 本機 `0.0.0.0:<dbcPort>`，由 SRE 的 [dbc](http://example.invalid:1688/sre/dbc) 以**個人 Google 帳號 IAM** 代理到 Cloud SQL 從庫；使用者名稱＝gcloud 帳號 `@` 前段、密碼欄留空（身分由 dbc 注入的 IAM token 綁定，名稱與 token 不符只會 Access denied）；DB 名稱取 registry `database`。無共用帳密、每條查詢進 Cloud Logging 稽核 |
| 1. 容器解析 | 該白標 `dbcPort: null`，且 docker 可連線、`php-fpm8` 容器存在並運行中 | 容器內 APIs 的 Laravel config（`wl-resolve-conn.php` 解析 `.read` 座標） |
| 2. secrets.env fallback | 該白標 `dbcPort: null`，且 docker 未安裝／daemon 未連線／`php-fpm8` 容器不存在 | 本機 `~/.gff/secrets.env`（見下） |

dbc 路徑 **fail-closed、不退舊路徑**：`dbc start` 沒掛（port 未監聽）或 gcloud 未登入，該 server 一律不啟動並在錯誤訊息指出要做什麼——查詢必須經 dbc 稽核，不能靜默繞去共用帳密。容器路徑同理：容器**存在但未啟動**不會 fallback 到 secrets。舊路徑（1／2）目前只剩 VPlus33 在用（dbc `instances.yaml` 尚未涵蓋）；SRE 補上後把 registry 的 `dbcPort` 由 `null` 填成 port 即完成切換，wrapper 不用改。

### dbc 前置（每台機器一次）

1. 先裝 [gcloud CLI](https://example.invalid/sdk/docs/install)，再依 [sre/dbc README](http://example.invalid:1688/sre/dbc) 安裝 dbc（macOS / Linux 一行 `install.sh`，Windows `install.ps1`）。
2. `dbc setup`：登入 Google 帳號並設定 ADC。帳號須已加入 `user@example.invalid` 群組（找 SRE）。
3. `dbc doctor` 檢查；其中「mysql client」那項只影響 `dbc connect` 互動模式，MCP 路徑不需要。
4. **每次要用 MCP 前**另開終端機跑 `dbc start`，使用期間保持開啟、**用完就 Ctrl-C 收掉**（前景執行，刻意不支援背景 daemon）。預設模式掛所有從庫＋沒有從庫的主庫（Alfawin77 屬後者），已涵蓋 registry 全部 `dbcPort`。開著的期間，本機任何以你身分執行的程序都能連 `0.0.0.0:34xx`、稽核記在你頭上——這是 dbc 的 loopback 設計，不是 wrapper 能補的，所以不要整天掛著。
5. `/reload-plugins` 後 `/mcp` 應看到各 `mysql-*` 連上，wrapper stderr 標示「連線來源=dbc」。

wrapper 取帳號的順序：`CLOUDSDK_CORE_ACCOUNT` 環境變數 → gcloud 設定檔（`~/.config/gcloud/active_config` → `configurations/config_<name>` 的 `[core] account`；Windows 為 `%APPDATA%\gcloud`，可用 `CLOUDSDK_CONFIG` 改目錄）→ 最後才 spawn `gcloud config get-value account`。gcloud 不在 MCP 子程序的 PATH 也能用。

dbc 路徑已知差異：

- Dev（`3402`，`tp-wz3-rd`）實例 `wait_timeout=120`，閒置兩分鐘斷線、下一筆查詢自動重連；正式站為 28800。
- dbc 不支援 binary prepared statement；本 wrapper 用的 benborla 只走文字協定 `query()`，不受影響。
- Alfawin77 只有主庫（`3511`，`read_only=0`）：拒寫靠兩道獨立防線——伺服器側 IAM 帳號經 `db-prod-user` 角色只有 `xsgcasino`／`xsgcasino_log` 的 `SELECT`（2026-09-02 以 `SHOW GRANTS FOR CURRENT_USER()` 於 `3511` 實查），加上 wrapper 側強制的 READ ONLY transaction。
- benborla 子程序固定以 plugin `bin/` 為 cwd 啟動：它會 `dotenv.config()` 讀 cwd 的 `.env`，若沿用 Claude Code 的專案目錄（如 APIs 的 Laravel `.env`），該檔可把 wrapper 刪掉的繞過鍵（`SCHEMA_*_PERMISSIONS`、`MYSQL_CONNECTION_STRING`）補回來。
- 每條查詢寫入該 GCP 專案的 Cloud Logging `dbc-query-audit`（含帳號與完整 SQL），用個人帳號查生產庫請有此認知。

### 無 docker / APIs 環境設定（macOS / Linux）——僅 `dbcPort: null` 的白標需要

向管理者索取已填值的範本，寫入 `~/.gff/secrets.env`（與 GitLab PAT 同一檔，權限必須 600）；有 `dbcPort` 的白標不讀此檔：

```bash
mkdir -p ~/.gff
touch ~/.gff/secrets.env && chmod 600 ~/.gff/secrets.env
# 編輯檔案，加入下列條目（值以管理者下發為準）
```

```bash
# ── mysql MCP 共用唯讀帳號（各庫同一組）──
GFF_MYSQL_USER=gff_readonly
GFF_MYSQL_PASS=xxxxxxxxxxxx

# ── 每 connection 座標（HOST、DB 必填；PORT 選填，預設 3306）──
GFF_MYSQL_VWORLD_HOST=xx.xx.xx.xx
GFF_MYSQL_VWORLD_DB=xxxxxxxx
GFF_MYSQL_TPOWER_HOST=xx.xx.xx.xx
GFF_MYSQL_TPOWER_DB=xxxxxxxx
GFF_MYSQL_YES2WIN_HOST=xx.xx.xx.xx
GFF_MYSQL_YES2WIN_DB=xxxxxxxx
GFF_MYSQL_ALFAWIN77_HOST=xx.xx.xx.xx
GFF_MYSQL_ALFAWIN77_DB=xxxxxxxx
GFF_MYSQL_ME77_HOST=xx.xx.xx.xx
GFF_MYSQL_ME77_DB=xxxxxxxx
GFF_MYSQL_VPLUS33_HOST=xx.xx.xx.xx
GFF_MYSQL_VPLUS33_DB=xxxxxxxx
```

- key 命名 `GFF_MYSQL_<CONNECTIONID 大寫>_*`，connectionId 對齊 `registry/whitelabels.metadata.yaml`；WTT / VWSG 共用 VWorld connection，不另立條目。
- 只查部分白標可只填該部分：哪個 connection 缺 key，僅該 server 不啟動（錯誤訊息指明缺哪條），其他照常。
- 各庫帳密不同時可用 `GFF_MYSQL_<CONN>_USER` / `_PASS` 覆寫全域。
- 自訂檔案路徑：設 `GFF_SECRETS_FILE` 指向完整路徑。
- 設定或修改後 `/reload-plugins` 重啟生效（wrapper 只在啟動時解析一次）。

### Windows

Node 無法可靠驗證檔案 ACL，預設拒用 secrets 檔存放 DB 憑證；確認風險後設 `GFF_SECRETS_INSECURE_WIN=1` 才啟用（與 gitlab-mcp 同一開關），secrets 檔路徑為 `%USERPROFILE%\.gff\secrets.env`。

### 疑難排解

| 症狀 | 處置 |
|---|---|
| 訊息含「dbc 代理未啟動」 | 另開終端機 `dbc start` 並保持開啟，再 `/reload-plugins`；說 port 被佔用就跑 `dbc doctor` 找誰佔著（多半是另一個終端機已在跑 `dbc start`） |
| 訊息含「取不到 gcloud 登入帳號」 | `dbc setup`（或 `gcloud auth login`）後 `/reload-plugins` |
| `dbc start` 啟動即拒絕、說缺稽核紀錄寫入權限 | 該 GCP 專案缺 `roles/logging.logWriter`，找 SRE 授權 |
| dbc 路徑連上了但查詢回 `Access denied`／權限不足 | 帳號尚未加入 `user@example.invalid`，找 SRE |
| 訊息含「registry 無此白標」或「缺 dbcPort 欄位」 | `.mcp.json` 的 `WHITELABEL` 與 `registry/whitelabels.metadata.yaml` 不對齊。交叉比對只在本機 `node --test bin/mysql-mcp.test.mjs` 做（CI 無 node），`php scripts/check-registry.php` 只驗 registry 本身 |
| 訊息含「dbcPort 未監聽」但你確定沒開 `dbc start` | 有別的程序占著 `34xx`／`35xx`，立刻查是誰（`lsof -nP -iTCP:<port> -sTCP:LISTEN`）；wrapper 只驗「有人在聽」、驗不了「聽的是誰」 |
| `dbcPort: null` 的白標（VPlus33）連不上、訊息含「容器未啟動」或「連線解析失敗」 | 有 APIs 環境：啟動 `php-fpm8` 容器；曾跑過 `config:cache` 先在容器內 `php artisan config:clear`。無 APIs 環境：依上方建立 secrets.env。修正後 `/reload-plugins` |
| 訊息含「座標不完整（host / user / database 必填）」 | 容器起來了但 APIs `.env` 沒該白標的讀庫座標（`VW_/TP_/VP_/Y2W_/AW_/ME_` 等 `*_DB_HOST_READ`、`*_DB_USERNAME_READ`、`*_DB_PASSWORD_READ`）。向管理者索取後補進 APIs `.env`，容器內 `php artisan config:clear`，再 `/reload-plugins` |
| 訊息含「secrets 檔缺必要 key」 | 依訊息補該 connection 的 `GFF_MYSQL_*` 條目後 `/reload-plugins` |
| 訊息含「權限過寬」 | `chmod 600 ~/.gff/secrets.env` |

### 管理者：唯讀帳號前置作業

fallback 憑證應使用各庫**專用 SELECT-only 帳號**（如 `gff_readonly`），不下發可寫帳號；帳號權限與 READ ONLY transaction 構成雙重拒寫。建帳號與下發範本屬 repo 外作業，本檔不存放任何真實座標（`.gitignore` 已擋 `secrets.env`）。
