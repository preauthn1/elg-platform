---
name: deploy-rd-studio
description: RD-Studio 多站台上版部署流程（站台清單以 legend-toolkit 的 Makefile `rd/<站台>` 為準）。人在 RD-Studio 目錄說「上版」「部署」，或提到上版／部署 RD-Studio、更新某站台代碼、SSH 進 VM 跑 update 腳本、某站台的更新與驗證步驟時載入。🚫 「發版」走 gitlab-release；舊代理後台三專案走 deploy-agent-stack。
---

# RD-Studio 上版流程

> **文件結構**：本檔留核心流程與安全規範；細節拆在 `reference/`：
> - [reference/update-scripts.md](reference/update-scripts.md) — 已文件化的三支 update 腳本（dev / tpower / vw）（§4）
> - [reference/verification.md](reference/verification.md) — 部署後驗證：SSH 參數表、10 個 placeholder、Step 1–3 驗證 + 選用業務指令、worked example（§5）
> - [reference/troubleshooting.md](reference/troubleshooting.md) — 故障排除、rollback、維護方式（§7 / §8）

> **適用範圍**：人在 RD-Studio 目錄裡單講「上版」「部署」就走本 skill，不必再問是哪個專案；看工作目錄與 git remote 都看不出是哪個專案時才問。不歸本 skill 的說法：「發版」指 APIs 發 release，走 `dev-toolkit:gitlab-release`；舊代理後台 WEB_AgentBackend、API_Agent、客服 socket 上版走 `deploy-agent-stack`；新代理後台 agent-backend 走 `dev-toolkit:release-workflow` 與 `dev-toolkit:gitlab-release`。Claude 代跑與 Prod 決策閘見 §4。

## 1. TL;DR

**Workflow**：本機 `git push origin <branch>` → merge 到目標分支（dev=`develop`、prod=`master`）→ SSH 進對應 VM 跑 update 腳本 → grep 驗證。**代碼推上去不會自動 build**。

> 本節是 **Claude 代跑**用的站台座標（拿來組原始 `gcloud --command`，見 §4）。**人工部署走 `make`**（`plugins/legend-toolkit/script/`，操作清單見該目錄 `README.md`）；Claude 不要呼叫 `make`（理由見 §4）。站台清單以該 Makefile 的 `rd/<站台>` target 為準。

### 站台座標

| 環境 | GCP 專案 | 主機名稱 | Zone | 更新腳本（`--command` 內） | 分支 | 部署目標（驗證 `<PATH>`） | release path（git 驗證用） |
|---|---|---|---|---|---|---|---|
| **dev** | `tp-wz3-rd` | `rd-studio-01` | asia-east1-a | `~vwsource/update_rd.sh` | **`develop`** | `/var/www/html/` | `/home/vwsource/RD-Studio-01` |
| **prod vw** | `tp-wz3-online` | `rd-studio-01` | asia-southeast1-a | `~vwsource/update_live.sh` | `master`（gcloud 預設） | `/var/www/html/` | `/home/vwsource/RD-Studio-01` |
| **prod tpower-new** | `tp-wz3-tpower` | `rd-sudio-01-tpower` | asia-southeast1-a | `/vwsource/update_live.sh` | `master`（gcloud 預設） | `/var/www/html/` | `/vwsource/RD-Studio-01` |
| **prod tpower-hk** | `tp-wz3-tpower` | `rd-sudio-01-tpower` | asia-southeast1-a | 同上腳本（一次兩段） | 同上 | `/var/www/html-hk/` | `/vwsource/RD-Studio-01` |
| **prod alfawin77** | `gff-alfawin77` | `rd-studio-alfawin77` | asia-southeast1-c | `/vwsource/update_live.sh` | `master`（gcloud 預設） | `/var/www/html/` | `/vwsource/RD-Studio-01` |
| **prod yes2win** | `gff-yes2win` | `rd-studio-yes2win` | asia-southeast1-c | `/vwsource/update_live.sh` | `master`（gcloud 預設） | `/var/www/html/` | `/vwsource/RD-Studio-01` |
| **prod me77（vwb2c）** | `gff-vwb2c` | `rd-studio-vwb2c` | asia-southeast1-c | `/vwsource/update_live.sh` | `master`（gcloud 預設） | `/var/www/html/` | `/vwsource/RD-Studio-01` |
| **prod wintoto** | `tp-wz3-online`（同 vw） | `rd-studio-01`（同 vw） | asia-southeast1-a | `~vwsource/update_live.sh`（同 vw） | `master`（gcloud 預設） | `/var/www/html/`（同 vw） | `/home/vwsource/RD-Studio-01` |

> `rd-sudio-01-tpower` 是原始 typo（`sudio` 少一個 `t`），照原樣輸入。
> **tpower**：一次 deploy 同時更新 new（`/var/www/html/`）+ hk（`/var/www/html-hk/`），同一個 `update_live.sh` 已含兩者。
> **release path 兩種根**：vw / dev 走 `/home/vwsource`（= `~vwsource`）；tpower 與 gff-* 站走 `/vwsource`。git 指令（log / rev-parse）一律對 release path 跑，**不對 `/var/www/html*`**（非 git repo，見 §5）。
> **env file**（update 腳本內部 `mv` 用，Claude 通常不直接碰）：dev `.env.rd`、vw `.env.pro`、tpower-new `.env.tpower_new`、tpower-hk `.env.tpower-hk`、wintoto `.env.wintoto_new`、alfawin77 `.env.alfawin77`、yes2win `.env.yes2win`、me77 `.env.vwb2c`（皆存在於 RD-Studio repo `_release/`）。
> **分支**：dev 走 `develop`，**其餘所有 prod 站一律 `master`**（gcloud source repo default branch）。故 §3 / §6 preflight 對所有 prod 站都驗 `origin/master`。
> **alfawin77 / yes2win / me77（gff-* 三站）**：座標與 vw / tpower 同構，**Claude 可代跑**（2026-08-20 唯讀探測確認，見 §4）。三站的 `update_live.sh` 各自 `mv` 自己的 env file、單一部署目標 `/var/www/html/`（無 hk 之類第二 target）、release path `/vwsource/RD-Studio-01`、`sudo` 為 NOPASSWD。
> ⚠️ **Makefile 這三站寫的是 `sudo -i sh`，Claude 一律改用 `sudo sh`**（去掉 `-i`）：`-i` 起 login shell 需要 TTY，Claude 的 Bash 無 TTY。去掉 `-i` 安全，理由是實測 `sudo`（非 login）下 `$HOME` 已是 `/root`（`update_live.sh` → `login.sh` 寫 `~/.gitcookies` 與 `git config --global` 落點不變）、腳本第一行即 `cd /vwsource`（cwd 差異無影響）、且 `secure_path` 內 `gcloud` / `composer` / `php` / `git` 四者全部解析得到。
> **wintoto**：與 vw **共用資料庫、共用同一套 rd-studio**（同 VM `rd-studio-01`、同 install、同 `/var/www/html`），座標整列 = vw。因此 **wintoto 沒有獨立部署——部署 vw 即涵蓋 wintoto**，`make` 亦無需 `rd/wintoto` target。（repo 內 `.env.wintoto_new` 存在，但實際服務走 vw 的 install。）

---

## 2. Repo & 分支策略

### `origin` = gcloud source repo（不是 GitHub / GitLab）

```bash
git remote -v
# origin  ssh://...@example.invalid:2022/p/tp-wz3-rd/r/RD-Studio-01
```

- 本機 `git push origin <branch>` 直接推進 gcloud source repo
- update 腳本內 `gcloud source repos clone RD-Studio-01 --project=tp-wz3-rd` 拉的是同一個 repo → **所有站台（含各 prod，即使部署機在 `tp-wz3-online` / `gff-*` 等別的 GCP 專案）的程式碼都來自 `tp-wz3-rd` 這一顆 source repo**；退版時改的就是它的 master（見 §7 rollback）
- 沒有 GitHub / GitLab 鏡像

### dev 走 `develop`、prod 走 `master`（⚠️ 分歧是常態，會咬人）

- `update_rd.sh` 明確 `git checkout develop`
- `update_live.sh` **不執行 checkout**，依賴 gcloud source repo 的 **default branch**（目前是 `master`）；若有人在 GCP 把 default branch 改掉，prod 會跟著改，這是**隱形依賴**
- 腳本開頭 `\rm -R RD-Studio-01` 會重新 clone，**機器上不存在獨立版本、`_release` 每次重跑都被重建 → 版本永遠跟著 master**。退版即基於此：本機 `git revert` master、重跑腳本（見 [reference/troubleshooting.md](reference/troubleshooting.md) Rollback）
- `develop` 與 `master` **預期會分歧**（develop 領先很多、或 master 有 hotfix 沒回流）：
  - 🛑 **部署 prod 前必須以 `git merge-base --is-ancestor` 嚴格驗證目標 commit 在 `origin/master`**（不能只看 `git log --oneline`，會誤判）
  - 🛑 **Hotfix 直接打 master 後必須立刻 merge 回 `develop`**，否則 dev 重現不出 hotfix 的行為、後續 develop → master merge 會帶回過時程式碼

```
feature/<name> ──merge──> develop ──(dev + update_rd.sh)──> dev
                            │
                          (測試通過)
                            │
                            └─merge──> master ──(prod + update_live.sh)──> prod
```

> Hotfix 直接打 master 後，**記得回流 develop**，否則 dev 看不到該修正。

---

## 3. 部署前 preflight

> **`<TARGET_SHA>` 定義**：本次要上版的具體 commit SHA（通常是 PR/merge commit 或 fix commit 的 hash），**不是 `origin/master` 當下 HEAD**。例如某次 fix 的 commit SHA `1a2b3c4d`、其 merge commit `9f8e7d6`（示範值，請用你本次的實際 commit）。Claude 應從使用者 / 對話脈絡 / `git log origin/<branch>` 取得具體值，不可用 `git rev-parse origin/master`（會變成「檢查 master 是否在 master」永遠 pass）。
>
> 🔒 驗證錨點建議用**完整 40 碼 SHA**（短 SHA 在未 fetch / 衝突時可能誤判）。

> 📍 **本節與 §6 決策閘的所有 git 指令都在「本機 RD-Studio clone」內執行**（cwd = 本地 `RD-Studio-01`，`origin` = gcloud source repo，見 §2）。
> **路徑取得**：讀環境變數 `$RD_STUDIO_REPO_DIR`（建議設在 `~/.claude/settings.json` 的 `env`，跑 `/resolve-repos` 取得片段——settings 的 `env` 會跨 Bash 呼叫保留）。未設定時請使用者跑 `/resolve-repos` 補上、或直接問路徑，勿臆測。動手前先站對 repo：
> ```bash
> cd "$RD_STUDIO_REPO_DIR"       # 來自 Claude settings env
> git remote get-url origin      # 尾段須為 /r/RD-Studio-01
> ```
> ⚠️ 在別的 repo cwd（APIS / GFF-Marketplace 等）跑 `merge-base … origin/master` 會對到**錯的 origin**、**靜默**回無意義的 ✅/❌。下表指令皆假設已 `cd "$RD_STUDIO_REPO_DIR"`。

| 檢查項 | 指令 / 行動 |
|---|---|
| 本機 commit 完成、push 完成 | `git status` clean；`git log origin/<branch>..HEAD` 無未推送 |
| 上 dev：`<TARGET_SHA>` 已在 `develop` | `git fetch origin --quiet && git merge-base --is-ancestor <TARGET_SHA> origin/develop && echo ✅ \|\| echo ❌` |
| 上 prod：`<TARGET_SHA>` 已在 `master` | `git fetch origin --quiet && git merge-base --is-ancestor <TARGET_SHA> origin/master && echo ✅ \|\| echo ❌` |
| **記錄 `<TARGET_SHA>` 作為部署後驗證錨點** | 部署後機器暫存區 HEAD（見 [reference/verification.md](reference/verification.md) Step 3）必須能 `merge-base --is-ancestor` 包含 `<TARGET_SHA>` |
| 無破壞性 migration / config 變更 | 自查 `database/migrations/`、`config/` |
| 新增 `env('XXX')` 已先在機器補 .env | SSH 進去確認對應 `.env.<env>` |
| （prod）已通知 stakeholder / 時段 | 對外通知 |

---

## 4. 執行部署（Claude 代跑）

> 🛑 **代跑前必看第 6 節的「Prod 決策閘」與「能/不能矩陣」**。
> 🚫 **不要呼叫 `make`**：人工部署才走 `make rd/<站台>/deploy`（見 `plugins/legend-toolkit/script/README.md`）。`make` target 的 `CONFIRM`（`read -p`）需要 TTY，Claude Bash 無 TTY 會被判「已取消」`exit 1`，部署不會發生。Claude 一律用下方原始 `gcloud ... --command`（無互動 prompt）。
> ⚠️ **tpower 一次更新 new + hk 兩站**（`update_live.sh` 已含兩者）。

### Dev（使用者授權後）
```bash
gcloud compute ssh --zone "asia-east1-a" "rd-studio-01" --project "tp-wz3-rd" \
    --command="sudo sh ~vwsource/update_rd.sh"
```

### Prod tpower：hk + new 一起上（Prod 決策閘四條件皆滿足）
```bash
gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" \
    --command="sudo sh /vwsource/update_live.sh"
```

### Prod vw（Prod 決策閘四條件皆滿足）
```bash
gcloud compute ssh --zone "asia-southeast1-a" "rd-studio-01" --project "tp-wz3-online" \
    --command="sudo sh ~vwsource/update_live.sh"
```

### Prod alfawin77 / yes2win / me77（Prod 決策閘四條件皆滿足）

> ⚠️ **必須用 `sudo sh`，不要照 Makefile 的 `sudo -i sh`**（`-i` 需 TTY，理由見 §1）。三站皆單一 target `/var/www/html/`。

```bash
# alfawin77
gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-alfawin77" --project "gff-alfawin77" \
    --command="sudo sh /vwsource/update_live.sh"

# yes2win
gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-yes2win" --project "gff-yes2win" \
    --command="sudo sh /vwsource/update_live.sh"

# me77（主機名是 rd-studio-vwb2c）
gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-vwb2c" --project "gff-vwb2c" \
    --command="sudo sh /vwsource/update_live.sh"
```

### 更新腳本內容

已文件化的 update 腳本（dev / tpower / vw；gff-* 三站各有自己的 `update_live.sh`，形狀同 tpower 版但只有單一 target 且 `mv` 各自 env file，wintoto 共用 vw）完整內容（含最後同步日期、re-fetch 維護方式）見 **[reference/update-scripts.md](reference/update-scripts.md)**。都從同一顆 gcloud source repo（`tp-wz3-rd`）拉，差別在 checkout / env / 目的地。

---

## 5. 部署後驗證 → 見 [reference/verification.md](reference/verification.md)

完整內容（SSH 參數對照表、10 個 placeholder 清單、Step 1–3 驗證 + 選用業務指令模板、worked example、debug 模板）在 reference。

🔴 **部署後必報 4 件事**（缺一視為驗證未完成；對應 reference 的 Step 1–3，Step 3 一次出兩項）：
1. `grep -F` 比對結果**含行號**（Step 1）
2. mtime（Step 2）
3. 暫存區 `git rev-parse HEAD` SHA（Step 3）
4. `merge-base --is-ancestor <TARGET_SHA> HEAD` 結果（Step 3 同一指令，✅ contains / ❌ MISSING）

> ⚠️ `/var/www/html`、`/var/www/html-hk` **不是 git repo**，git 指令只能對 `<RELEASE_PATH>`（`~vwsource/...` 或 `/vwsource/...`，需 sudo）跑。

---

## 6. Claude 自動化（macOS）

### 真實限制（不是「沙箱」）

Claude Code 的 Bash 工具跑在使用者本機 macOS，`gcloud` / `ssh` 都可用。卡點是：

1. **無 TTY**：互動 prompt（passphrase、`sudo -i`、yes/no）會失敗
2. **SSH key 有 passphrase**：`~/.ssh/google_compute_engine` 加密，無 TTY 讀不到 passphrase → permission denied
3. **shell state 不保留**：每次 Bash 呼叫獨立，無法 `ssh → sudo → cd → run` 連續操作
4. **權限分類器**：對 prod / shared infra 寫入可能被擋（讀取通常 OK）

### 解法：把 key 載入 macOS ssh-agent（使用者一次設定）

```bash
ssh-add ~/.ssh/google_compute_engine          # 輸入 passphrase 一次
ssh-add -l                                    # 驗證已載入
# 永久保存到 Keychain（登入時自動載入）：
ssh-add --apple-use-keychain ~/.ssh/google_compute_engine
```

之後 Claude 的 Bash session 透過 `SSH_AUTH_SOCK`（macOS launchd socket）共用 agent。

### Claude 能 / 不能代跑矩陣

> 部署驗證 / debug 指令模板與佔位符替換規則見 [reference/verification.md](reference/verification.md)。

| 動作 | Claude 可代跑條件 | 備註 |
|---|---|---|
| `--command="cat <file>"` / `grep` / `ls` / `find` 唯讀 | ✅ 可主動 | 例：grep 驗版本、檢視 update 腳本 |
| `git log/status` | ✅ 可主動 | 但只能對有 .git 的暫存區 `~vwsource/RD-Studio-01/_release`（**不是 `/var/www/html`**） |
| `sudo sh ~vwsource/update_rd.sh`（dev） | ✅ 使用者明確授權後 | dev 機 sudo NOPASSWD、`--command=` 不需 TTY、classifier 不擋（2026-05-27 實測 work） |
| `sudo sh /vwsource/update_live.sh`（prod tpower） | ✅ Prod 決策閘四條件皆滿足 | 同上機制（2026-05-27 實測 work）；遵守下方「Prod 部署規範」 |
| `sudo sh ~vwsource/update_live.sh`（prod vw） | ✅ Prod 決策閘四條件皆滿足 | 同上 |
| `sudo sh /vwsource/update_live.sh`（prod alfawin77 / yes2win / me77） | ✅ Prod 決策閘四條件皆滿足 | **去掉 Makefile 的 `-i`**（見 §1 / §4）；`sudo` NOPASSWD 與 PATH 已於 2026-08-20 實測 |
| `php artisan` 寫 DB 指令（dev） | ⚠️ 先 confirm | 經使用者授權可跑 |
| `php artisan` 寫 DB 指令（prod） | ⚠️ 先 confirm + 明示日期/廠商 | 影響真實玩家資料 |

### 🛑 Prod 決策閘（Claude 跑 prod deploy 前，**四條件必須同時滿足**）

1. **使用者明確說出目標環境**（`vw` / `tpower` / `alfawin77` / `yes2win` / `me77`；tpower 一定 = hk + new 兩個 target 同時更新，其餘各站皆單一 target。「上 prod」但未指名站台**不算滿足**，必須問）
2. **使用者明確說「現在執行」 / 「上 prod」 / 等價指令**（不是只在討論流程）
3. **`<TARGET_SHA>` 已嚴格驗證在 `origin/master`**（在本機 RD-Studio clone 內，見 §3 📍）：
   ```bash
   cd "$RD_STUDIO_REPO_DIR" && git fetch origin --quiet
   git merge-base --is-ancestor <TARGET_SHA> origin/master && echo "✅ in master" || echo "❌ NOT in master"
   ```
   ⚠️ **`<TARGET_SHA>` 必須是「本次要部署的具體 commit hash」**（從 `git log <branch>` 取或使用者提供），**不可用 `git rev-parse origin/master`**——那會變成「檢查 master 是否在 master」永遠 pass，失去 gate 意義。
   ⚠️ **不要用 `git log origin/master --oneline -5`** 確認 — 只看最近 5 筆，commit 若在更早位置會誤判「不在 master」；反之 hash 衝突也可能誤判通過。`merge-base --is-ancestor` 是唯一可信驗證。
4. **這次新上去的改動，需求主單都已經由 PM 確認**：照 dev-toolkit `release-workflow` 的 PM 確認檢查做。它不在本 plugin 裡，用 `get_file_contents(project_id="target/gffv3/gff-marketplace", file_path="plugins/dev-toolkit/skills/release-workflow/reference/pm-confirm-check.md", ref="main")` 讀，讀不到就停下來回報，不跳過。
   - 這裡沒有 MR，只看 commit 標題與內文。範圍是「這個站台目前部署的 commit」到 `<TARGET_SHA>`：先在部署機讀目前版本（`sudo bash -c 'cd <RELEASE_PATH> && git rev-parse HEAD'`，參數照 [reference/verification.md](reference/verification.md) 的 SSH 參數對照表），再在本機跑 `git log --no-merges --format='%s%n%b' <目前版本>..<TARGET_SHA>`。讀不到目前版本時，問使用者上次部署的 commit，不猜。
   - 豁免條件照該文件：commit 全是 docs／style／chore／test／revert，或提到的工單全部是線上問題單（`target/op_tickets`）而且查得到，就豁免。RD-Studio 直接在 master 上修的 hotfix 沒有 `hotfix/` 分支名可看，一樣要跑檢查；來不及等 PM 時，由使用者決定是否照常部署，Claude 不自行放行。
   - 沒通過就停下來，把不通過的主單與原因列給使用者，不 merge、不跑 update 腳本。

> 任一條件不滿足 → Claude 必須停下用 `AskUserQuestion` 釐清，**不可自行假設後執行**。

### 🔒 技術性硬閘（強烈建議每位代跑者各自設定）

上面的 Prod 決策閘靠 Claude 自律；要**不受模型判斷影響**的保護，在個人 `~/.claude/settings.json` 加一條，讓 prod 部署指令每次強制人工確認：

```json
{ "permissions": { "ask": ["Bash(*update_live.sh*)"] } }
```

- `update_live.sh` 是**全部 prod 站**（vw / tpower / alfawin77 / yes2win / me77）共用的檔名，一條 glob 即可全數涵蓋；dev 的 `update_rd.sh` **不受影響**，維持原本較自由。
- 這條規則寫在**個人** settings，不隨 plugin 散佈——**每位會代跑 prod 的人都要自己加**，否則該機器沒有這道硬閘。

### Prod 部署規範（Claude 代跑時必須遵守）

1. **一次一台**：跑完一個環境立刻 grep 驗證 + 看 mtime + 看暫存區 HEAD，**tpower 必須分別驗證 `/var/www/html/` 和 `/var/www/html-hk/` 兩個 target**，再跑下一台
2. **不批次**：不在同個 Bash 呼叫裡 deploy 多環境（避免一個失敗連鎖未驗）
3. **遇錯立停**：composer 錯誤、`Cloning into` 失敗、`Permission denied` → 完整輸出貼回使用者，**不自行重試**
4. **不主動跑 `daily_rerun` 或其他寫 DB artisan** — 部署本身結束工作；歷史回填需使用者明示日期與廠商
5. **回報格式固定**：環境名 / 部署目標路徑（tpower 列兩個 target）/ `<CHANGE_PATTERN>` 行號 / mtime / 暫存區 HEAD SHA + `merge-base --is-ancestor <TARGET_SHA> HEAD` 結果（✅ contains / ❌ MISSING）
6. **預設拒絕跨環境部署**（同時上 dev + prod 等）— prod 必須單獨確認

---

## 7. 常見故障 / 排除、rollback、維護 → 見 [reference/troubleshooting.md](reference/troubleshooting.md)

故障對照表、prod rollback 程序、腳本/環境維護方式集中於該檔。

---

## 8. 驗收標準（使用者檢核 Claude 用）

逐條對照即可，細節見對應節、不在此重述：

1. **部署前三問**（§3 / §6 決策閘）：目標環境 + 明確「現在執行」意圖 + `<TARGET_SHA>` 已 `merge-base --is-ancestor` 在 `origin/<branch>`；缺一停下問。
2. **tpower 兩 target 都驗**（§1）：部署後 `/var/www/html/` 與 `/var/www/html-hk/` 各驗一次。
3. **部署後四件事**（§5）：grep 行號 / mtime / `git rev-parse HEAD` / `merge-base --is-ancestor` 全報，缺一未完成。
4. **git 只對 release path 跑**（§5）：`<RELEASE_PATH>`（需 sudo），不對 `/var/www/html*`（非 git repo）。
5. **prod 寫 DB artisan 需授權**（§6 矩陣）：`vendor_summary_daily_rerun` 等須使用者明示「日期 + 廠商 ID」才跑。
