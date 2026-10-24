# summary_bet_record 命令使用要點

> **何時讀這份**：Phase C Step 3-4 跑 SBR 補帳前；或要註冊新 vendor 進排程時。

主程式：`app/Console/Commands/SummaryBetRecord/SummaryBetRecordCommand.php`
服務層：`app/Console/Commands/SummaryBetRecord/SummaryBetRecordService.php`
設定檔：`config/summary_bet_record.php`

---

## 1. 參數與時區

**輸入均為 UTC+8（台北），命令內部自動轉 UTC+0**：

| 參數組合 | 行為 |
|---|---|
| 無參數 | 今天（UTC+8）整天 24 小時 |
| `--prev-hour` | 上一小時（UTC+8）—— 排程專用 |
| `--yesterday` | 昨天整天 24 小時 |
| `--date=Y-m-d` | 指定日期整天 24 小時 |
| `--date=Y-m-d --hour=H` | 指定日期＋小時 |
| `--hour=H` | 今天指定小時 |
| `--vendor={id}` | 單一廠商；`0` 或不帶代表**全部廠商**（本對帳 SOP **禁用**，見下方規則） |
| `--dry-run` | 計算差異但 rollback、且**完全跳過 SP 彙總** |

`--prev-hour`、`--yesterday`、`--date` **三者互斥**，違反會直接 abort。

> **⚠️ 本對帳 skill 的命令使用規則**：
> 1. **`--vendor={id}` 強制必填**：本 SOP 為單一 (vendor × env) 對帳；省略 `--vendor` 或 `--vendor=0` 會跑全 vendor、產生跨 vendor 無關 correction，對帳難以 audit、rollback 也只能逐 vendor 處理。例外：使用者**明確要求**全 vendor 維護性重算（極少見）才允許。
> 2. **必須包 `docker exec` + 顯式 `DB_CONNECTION`**：所有 artisan 在 `php-fpm8` 容器內跑，環境用 `-e DB_CONNECTION=<env>` 注入覆蓋（禁止改 `.env`）。
> 3. **無 `--dry-run` 的實寫命令**執行前必須先**文字 confirm 環境 + vendor + 日期**，操作者打「yes」才執行。

### 範例（本對帳 skill 用、皆強制 `--vendor`、皆含 docker wrapper）

```bash
# 試跑歷史日期某小時(僅讀、無寫,可直接執行)
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor=179 --date=2025-12-30 --hour=14 --dry-run"

# 試跑上一小時(排程行為的等價手動模擬)
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor=179 --prev-hour --dry-run"

# 實寫:單日整天 24 小時 ── 執行前先 confirm
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor=179 --date=2025-12-30"

# 實寫:昨天 ── 執行前先 confirm
docker exec -e DB_CONNECTION=<env> php-fpm8 bash -c \
  "cd /var/www/APIs && php artisan summary_bet_record --vendor=179 --yesterday"
```

> 排程行為（無參數預設今天、無 `--vendor` 跑全 vendor）由 APIs scheduler 自動觸發，**人工對帳時不要直接複製排程命令**。

---

## 2. 關帳判斷（決定是否進 correction 模式）

```
關帳時間（UTC）= 當日台北 00:00 - 8h = 前一日 16:00 UTC
start <  關帳時間 → 已關帳 → correction 模式（補差值 + 沖銷舊值，不覆蓋）
start >= 關帳時間 → 未關帳 → 直接覆蓋 SBR
```

**結帳已過的歷史日期重跑會產生 correction=1 列**，這是預期行為，不是 bug。

---

## 3. 輸出語意

```
調帳寫入：新增或補差值 N 筆，沖銷舊記錄 M 筆
```

| 欄位 | 觸發條件 | 寫入內容 |
|---|---|---|
| **新增或補差值** | 本次計算有該記錄、且與 DB 現值不同（或全新） | 寫入新記錄，或寫入「新值 − 舊值」差值 |
| **沖銷舊記錄** | DB 有但本次計算已不存在（第三方撤銷） | 補一筆所有欄位皆取負的記錄，兩筆加總歸零 |

兩者均 0 → 本次計算與 DB 完全一致，無需寫入。

---

## 4. 註冊新 vendor

`config/summary_bet_record.php` 新增 entry：

```php
VendorIdEnum::XXX->value => [
    'third_party_code' => 'XXX',
    'time_field'       => 'payout_time',  // 或 'bet_time'，依 vendor 結算語意
],
```

**未在 config 註冊的 vendor，命令會直接 abort**。

`time_field` 選擇原則：
- `payout_time`：結算才有意義的 vendor（多數）
- `bet_time`：投注即定勝負、無分開派彩的 vendor

---

## 5. Dry-run 用途

| 用途 | 操作 |
|---|---|
| 跑歷史月份前**先評估 correction 規模** | `--dry-run` 看 correction 筆數，確認規模再決定是否實寫 |
| 開發新 vendor 接入時驗證 SUM 邏輯 | `--dry-run --vendor={id} --date=...` |
| 排查 SBR 與 TBR 差異是不是排程漏跑 | `--dry-run --prev-hour --vendor={id}` |

> **dry-run 跳過 SP 彙總**（`SP_summary_bet_record_team`、`SP_summary_rolling_chip_record`），別誤以為跑完了。實寫前還是要再跑一次無 `--dry-run` 版本才會更新 team / rolling chip。

---

## 6. 廠商鎖與 SP 鎖

- `VENDOR_LOCK_TTL = 300s`：同一廠商重疊時間被重複排程觸發時自動跳過
- `SP_LOCK_TTL = 120s`：SP 執行時間通常較短，較小值釋放資源

排程重疊不會撞表，但**手動跑 + 排程同時跑**會被鎖中其中一個。Phase C Step 3 手動 dry-run 時建議用 `--vendor={id}` 縮小鎖範圍。
