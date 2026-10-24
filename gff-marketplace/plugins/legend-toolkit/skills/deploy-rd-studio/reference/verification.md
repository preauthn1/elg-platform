# 部署後驗證

> 從 [SKILL.md](../SKILL.md) §5 拆出。SSH 參數對照表、10 個佔位符、Step 1–3 驗證指令 + 選用業務指令、worked example 集中於此。
> 部署後必報 4 件事（= Step 1–3，Step 3 一次出兩項）見 SKILL.md §5 摘要；下方「選用業務指令」不屬四件事。

> ⚠️ **`/var/www/html` 與 `/var/www/html-hk` 不是 git repo**（update 腳本只 `\cp -R *`）。不要對它們跑 `git log`，會失敗。要看 git 歷史請走 `~vwsource/RD-Studio-01/_release`（需 sudo）。

## SSH 參數對照表（套用於下方所有指令模板）

| 環境 | `<ZONE>` | `<HOST>` | `<PROJECT>` | `<PATH>`（部署目標） | `<RELEASE_PATH>`（暫存區，需 sudo） | `<SCRIPT_PATH>` |
|---|---|---|---|---|---|---|
| dev | `asia-east1-a` | `rd-studio-01` | `tp-wz3-rd` | `/var/www/html` | `~vwsource/RD-Studio-01/_release` | `~vwsource/update_rd.sh` |
| tpower-new | `asia-southeast1-a` | `rd-sudio-01-tpower` | `tp-wz3-tpower` | `/var/www/html` | `/vwsource/RD-Studio-01/_release` | `/vwsource/update_live.sh` |
| tpower-hk | `asia-southeast1-a` | `rd-sudio-01-tpower` | `tp-wz3-tpower` | `/var/www/html-hk` | `/vwsource/RD-Studio-01/_release` | `/vwsource/update_live.sh` |
| vw（＝ wintoto） | `asia-southeast1-a` | `rd-studio-01` | `tp-wz3-online` | `/var/www/html` | `~vwsource/RD-Studio-01/_release` | `~vwsource/update_live.sh` |
| alfawin77 | `asia-southeast1-c` | `rd-studio-alfawin77` | `gff-alfawin77` | `/var/www/html` | `/vwsource/RD-Studio-01/_release` | `/vwsource/update_live.sh` |
| yes2win | `asia-southeast1-c` | `rd-studio-yes2win` | `gff-yes2win` | `/var/www/html` | `/vwsource/RD-Studio-01/_release` | `/vwsource/update_live.sh` |
| me77 | `asia-southeast1-c` | `rd-studio-vwb2c` | `gff-vwb2c` | `/var/www/html` | `/vwsource/RD-Studio-01/_release` | `/vwsource/update_live.sh` |

## 完整佔位符清單（共 10 個，下方所有指令模板套用）

| 佔位符 | 來源 / 定義 |
|---|---|
| `<ZONE>` | SSH 參數對照表（上方） |
| `<HOST>` | 同上 |
| `<PROJECT>` | 同上 |
| `<PATH>` | 同上（部署目標，tpower 兩個 target 各跑一次） |
| `<RELEASE_PATH>` | 同上（暫存區，需 sudo） |
| `<SCRIPT_PATH>` | 同上（update 腳本） |
| `<TARGET_SHA>` | SKILL.md §3 preflight 記錄的「本次要部署的具體 commit hash」，**不是** `git rev-parse origin/master` |
| `<CHANGE_PATTERN>` | 本次 patch 改動過、與舊版可區分的字串。**不可沿用本文件的示範值**，必須從本次 commit diff / 使用者取得 |
| `<CHANGED_FILE>` | `<CHANGE_PATTERN>` 所在檔案相對路徑（例 `app/Services/SummaryBetRecords/Base.php`） |
| `<ARTISAN_CMD>` | 選用業務指令要跑的 artisan（含參數），如 `vendor_summary_daily_rerun --date=YYYY-MM-DD --vendor=N` |

> **Claude 必須依目標環境與本次任務脈絡逐一替換**。任何具體值的範例只當示範，**不可直接複製套用到別的環境/別次部署**。

## Step 1 — grep 驗證檔案內容真的更新

```bash
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" \
    --command="grep -F -n -- '<CHANGE_PATTERN>' <PATH>/<CHANGED_FILE>"
# 有輸出 → patch 已上版；無輸出 → 還沒上 / 腳本失敗 / 分支沒對
```

🔴 **tpower-only expanded example — tpower 雙 target 必須各驗一次**（其他環境只有單一 target，跳過此例）：

```bash
# tpower-new（<PATH>=/var/www/html）
gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" \
    --command="grep -F -n -- '<CHANGE_PATTERN>' /var/www/html/<CHANGED_FILE>"
# tpower-hk（<PATH>=/var/www/html-hk，同台機器、同 SSH 連線參數）
gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" \
    --command="grep -F -n -- '<CHANGE_PATTERN>' /var/www/html-hk/<CHANGED_FILE>"
```

## Step 2 — 看 mtime 確認部署時間

```bash
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" \
    --command="ls -la <PATH>/<CHANGED_FILE>"
```

## Step 3 — 暫存區 HEAD 是否含 `<TARGET_SHA>`

```bash
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" \
    --command="sudo bash -c 'cd <RELEASE_PATH> && git rev-parse HEAD && git merge-base --is-ancestor <TARGET_SHA> HEAD && echo ✅ contains || echo ❌ MISSING'"
```

🔴 **回報 `<TARGET_SHA> contains` 才算 deploy 成功**。`MISSING` 代表部署過程拉錯 commit、`update_live.sh` 拉的 default branch 不是 master、或 source repo 沒同步到 push。

## （選用）手動觸發業務指令 — 非驗證四件事之一

```bash
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" \
    --command="cd <PATH> && sudo php artisan <ARTISAN_CMD>"
# <ARTISAN_CMD> 例：vendor_summary_daily_rerun --date=2026-04-22 --vendor=233
#                  get_bolesw_games
```

> 寫 DB 類指令（如 `vendor_summary_daily_rerun`）在 prod 需使用者明確 confirm 日期與廠商，見 SKILL.md §6 矩陣。

## Worked example — 佔位符填法示範（tpower；**fix 相關值為示範假資料，勿照抄**）

> tpower 連線座標（ZONE / HOST / PROJECT / PATH / RELEASE_PATH / SCRIPT_PATH）是真實值；
> `<TARGET_SHA>` / `<CHANGE_PATTERN>` / `<CHANGED_FILE>` 一律換成你本次部署的實際值。

| 佔位符 | 本例填入值 |
|---|---|
| `<TARGET_SHA>` | `1a2b3c4d`（示範；用你本次 fix commit） |
| `<CHANGE_PATTERN>` | `exampleChangedString`（示範；你本次改動、可辨識的字串） |
| `<CHANGED_FILE>` | `app/Services/Example/Foo.php`（示範） |
| `<ZONE>` | `asia-southeast1-a` |
| `<HOST>` | `rd-sudio-01-tpower` |
| `<PROJECT>` | `tp-wz3-tpower` |
| `<PATH>` | `/var/www/html`（tpower-new）/ `/var/www/html-hk`（tpower-hk）兩個都驗 |
| `<RELEASE_PATH>` | `/vwsource/RD-Studio-01/_release` |
| `<SCRIPT_PATH>` | `/vwsource/update_live.sh` |
| `<ARTISAN_CMD>` | N/A（本次部署無業務指令步驟；歷史補帳獨立執行時才填，例 `vendor_summary_daily_rerun --date=YYYY-MM-DD --vendor=N`） |

替換後的 Step 1 + Step 3 完整指令（tpower-new；tpower-hk 把 `<PATH>` 換成 `/var/www/html-hk`）：

```bash
# Step 1 grep (用 -F 避免 pattern 被當 regex；'exampleChangedString' 為示範值)
gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" \
    --command="grep -F -n -- 'exampleChangedString' /var/www/html/app/Services/Example/Foo.php"

# Step 3 暫存區 HEAD 含 commit（1a2b3c4d 為示範 SHA）
gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" \
    --command="sudo bash -c 'cd /vwsource/RD-Studio-01/_release && git rev-parse HEAD && git merge-base --is-ancestor 1a2b3c4d HEAD && echo ✅ contains || echo ❌ MISSING'"
```

## 常用指令模板（debug / 維護用，已實測 work）

> 部署驗證的 grep / mtime / HEAD 模板是上方 Step 1-3。本段只列**部署主線以外的 debug / 維護**指令。

```bash
# 1. 看機器上 update 腳本內容（確認部署機制本身）
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" --command="sudo cat <SCRIPT_PATH>"

# 2. .env key 存在性（不印 value）
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" --command="sudo grep -c '^DB_HOST' <PATH>/.env"

# 3. composer install 是否成功（vendor/ 大小）
gcloud compute ssh --zone "<ZONE>" "<HOST>" --project "<PROJECT>" --command="du -sh <PATH>/vendor 2>/dev/null || echo 'no vendor dir'"
```
