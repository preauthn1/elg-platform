# 對帳與補帳整合

> 這份管遊戲商怎麼接上對帳與補帳：會動錢包的補帳／退款命令要守的規約（R27）、`summary_bet_records` 的排程與新增註冊步驟、OP 補跑、以及拉注單。寫補帳命令、註冊整點彙總或做拉注單時讀。

`summary_bet_records` 的彙總與補帳邏輯收於本專案（`apis`），由整點排程命令讀 `config/summary_bet_record.php` 驅動，**已與外部 RD-Studio-01 專案脫鉤**。新接遊戲商只改本專案，不需動其他 repo。

## 補帳／退款命令的規約

會動錢包的 artisan 命令與線上回調改的是同一批注單，兩者的併發是真實存在的。

### R27｜命令必須取回調用的那把同局 named lock

- 🔴 取鎖在 `DB::transaction` **之外**、釋放**晚於** commit；取不到鎖即以 failed 收單，**不得進入退款**。
- 不共用的後果：命令交易進行中三方送同局 REFUND，回調先取到鎖再開交易，read view 建在命令 commit 之前 → **兩道退款閘都看不到命令補的事件 → 各退一次**（`7fd890a56`）。
- 新增任何會改注單或錢包的命令時，第一件事是查該 vendor 的回調用了哪把序列化鎖，profile 補上對應的 `round_lock_key`。

### 命令本身的必要條件

| 項目 | 要求 |
|---|---|
| dry-run | **預設 dry-run**，`--execute` 才寫入 |
| 理由 | `--execute` 必填 `--reason`，另帶 `--by`／`--source` |
| 鎖內重驗 | 鎖到之後**必須重跑一次全部斷言**——dry-run 與 execute 之間狀態可能已變 |
| 審計 | 寫 `bet_record_recovery_logs`：before／after 快照、`balance_diff`、`performed_by`、`reason` |
| 禮碼 | 含禮碼的注單一律拒絕（退真金與退禮碼是兩條路徑） |
| 寫入目標 | 執行前確認寫連線是主庫（`@@read_only`），從庫會寫壞複寫 |
| 報表 | 結果落檔並印出路徑 |

### 撤回紀律

人工撤回命令補的退款時，**必須一併刪掉命令補的 deal record**——守衛把那筆事件視為「整局已退」，殘留會讓該局後續**合法**的 REFUND 被永久拒絕（`36b6f757d`）。這條要寫進該 vendor 的 `docs/reconcile/vendors/<X>.md`。

### 共用審計表的欄位定義取所有 vendor 的上界

`bet_record_recovery_logs.bet_id` 原定 `string(50)`，而 Ace333SW 的 bet_id 實際 58 字元 → MySQL 1406 讓**整筆補帳交易回滾**，事故當下補帳留不下任何審計紀錄（`0711db2db`）。新增共用契約表的欄位時，寬度以最寬的 vendor 為準。

### 三方協定「不重送」時必須有主動偵測入口

有些協定沒有取消 action、且三方明示不會再送任何請求（FumaSW 即如此），錢只能由我方主動退。

- 🚫 這種協定**不可只靠三方重送兜底**。接入時就要規劃：怎麼偵測「已扣款但永不結算」的注單、輪詢頻率、告警門檻。
- 事故實證：FumaSW 43 筆停在 BET／`status=0`，是事故後人工普查才發現的（`d2ae11714`）。ClotPlaySW 的 `integration.md` 也把「cancel timeout 後需主動偵測」列為尚未落地的 Gap。
- 接入時把偵測方式、輪詢頻率與告警門檻寫進該 vendor 的 `integration.md`。

## summary_bet_records 流程說明

| 階段 | 動作 |
|---|---|
| 回調 Controller | `TotalBetRecordService::create()` 寫入 `total_bet_records` |
| 排程（每小時 :30） | `php artisan summary_bet_record:dispatch` — 派發前一小時的彙總 job（Horizon long-running，job 內建回看補洞） |
| 排程（每日 UTC 00:25） | `php artisan summary_bet_record:rerun-full-day` — 對 `next_day_full_rerun` 的遊戲商以各自對帳時區重跑前一整天 |
| 排程（每日 UTC 02:40） | `php artisan summary_bet_record:audit` — 以 `queue_job_runs` 稽核完成標記缺口（回看視窗外的靜默漏桶） |
| 手動／補跑 | `php artisan summary_bet_record`（本檔「重跑（OP 對帳補跑）」的選項）|
| 命令內部 | 讀 `config/summary_bet_record.php`，逐 vendor 取 `service_class`（未指定則用預設 `SummaryBetRecordService`），由 service 讀 `total_bet_records` 聚合後寫入 `summary_bet_records` |

> ⚠️ **排程入口是 `:dispatch`，不是 `summary_bet_record` 本身**：整點跑的是 `:dispatch`，裸命令只用於手動補跑。三支排程的註冊都在 `routes/console.php`。

## 新增步驟

列於 `config/summary_bet_record.php` 的遊戲商才走此整點排程補帳；其餘以各自專屬補帳服務處理。要納入新接遊戲商：

**① 在 `config/summary_bet_record.php` 註冊一筆** — key 為 `VendorIdEnum::XXX->value`：

```php
VendorIdEnum::XXX->value => [
    'third_party_code' => 'XXX',
    'time_field' => 'payout_time', // 或 'bet_time'，見下方說明
    // 'service_class' => \App\Console\Commands\SummaryBetRecord\Vendors\XxxSummaryBetRecordService::class, // 選配
    // 'schedule_enabled' => false,  // 選配，預設 true；false = 不進整點排程，只在明確 --vendor 時跑（後台補跑用）
    // 'reconcile_timezone' => 8,    // 選配，預設 8；對帳時區（與 UTC 的時差小時數），清單以 docs/運維每日對帳/每日遊戲對帳.sql 為準
    // 'next_day_full_rerun' => true, // 選配，預設 false；true = 每日由 summary_bet_record:rerun-full-day 以對帳時區重跑前一整天
],
```

> 聚合遊戲商（多個子遊戲商共用同一三方 code，如 GMSW）以 `array_fill_keys` 群組化，共用設定只寫一次。vendor_id 為整數 key，**務必用 union (+) 合併，不可用 array spread (...)**——spread 會把整數 key 重新編號，導致 vendor_id 全錯。

**② （選配）建立專屬 Service** — 僅當該遊戲商有特殊聚合邏輯時：`app/Console/Commands/SummaryBetRecord/Vendors/{VendorName}SummaryBetRecordService.php`，繼承 `SummaryBetRecordService` 並覆寫 `buildAggregateQuery()` 等方法，再於上面 config 的 `service_class` 指向它。標準情況省略 `service_class` 即用預設 `SummaryBetRecordService`，不需建檔。

**`time_field` 選擇規則：**

| 情況 | 使用欄位 |
|---|---|
| 遊戲商投注與派彩分開（有獨立的 settle/payout 回調） | `payout_time` |
| 遊戲商單一回調，bet_time 與 payout_time 同時寫入 | `payout_time`（預設值） |
| 遊戲商只有投注時間有意義 | `bet_time` |

> 大多數情況使用預設 `payout_time` 即可。

## 重跑（OP 對帳補跑）

OP 對帳發現 SBR 落差時，以同一命令加選項手動重跑指定時段，取代舊 RD-Studio-01 的 `VendorBetRecordRerun`：

```bash
php artisan summary_bet_record --vendor={vendorId} --date=YYYY-MM-DD --hour=H
php artisan summary_bet_record --vendor={vendorId} --prev-hour
php artisan summary_bet_record --vendor={vendorId} --yesterday
php artisan summary_bet_record --vendor={vendorId} --dry-run --date=YYYY-MM-DD --hour=H  # 只算差異不寫入
php artisan summary_bet_record --vendor={vendorId} --date=YYYY-MM-DD --timezone=UTC         # 日期／小時改以該時區解讀（預設 +08:00；可選值見命令 docblock）
```

> 未在 `config/summary_bet_record.php` 註冊的 vendor 無法以此命令觸發（命令會報「廠商 ID 未在設定中定義」）。
>
> `--date` / `--hour` **一律以 `--timezone` 解讀，預設 +08:00**（不是該商的 `reconcile_timezone`）；可選值與關帳判斷的例外見 `SummaryBetRecordCommand` 的 class docblock。對帳時區不是 +8 的遊戲商補跑時務必顯式帶 `--timezone`，否則切錯時窗。

## Service 行為說明

`SummaryBetRecordService` 的核心邏輯：
- **未關帳**（當前小時）：先刪除該時段舊資料，重新從 `total_bet_records` 聚合後寫入
- **已關帳**（過去時段）：計算新舊差異，以**調帳**方式寫入差額（`correction=1`），不覆蓋原始資料

標準情況直接用 `SummaryBetRecordService`（不指定 `service_class`），不需覆寫任何方法。只有遊戲商有特殊聚合邏輯時才建專屬 Service 覆寫 `buildAggregateQuery()`。

## 拉注單（三方主動查詢補注單）

轉帳錢包沒有回調，注單靠我方定時打三方查詢補進來；單錢包的注單已由回調即時記帳，**只放行乾跑比對**。兩者共用同一套架構，Driver 端只實作契約、不自己寫入。

| 介面（`App\Vendors\Contracts\`） | 行為 |
|---|---|
| `SupportsBetRecordFetch` | 能力契約（含 `fetchBetRecords()`／`formatBetRecord()`／`betRecordUniqueKey()` 等）。實作即可被 `fetch_bet_record --direct --vendor={id}` 手動補拉，**不進排程** |
| `ShouldScheduleBetRecordFetch`（extends 上者） | 排程 marker，納入 `fetch_bet_record` 自動排程；降級為父介面即退出排程、仍可手動補拉 |

```bash
php artisan fetch_bet_record --vendor={vendorId} --dry-run            # 只比對不寫入，列出將新增／金額異動的注單
php artisan fetch_bet_record --vendor={vendorId} --date=YYYY-MM-DD    # 正式拉某日（另有 --start/--end/--minutes）
php artisan fetch_bet_record --vendor={vendorId} --update             # 金額異動時更新既有列並重轉 TBR（取消單靠此生效）
php artisan fetch_bet_record --vendor={vendorId} --direct --use-cache # 同步跑完整窗口、沿用一小時內的乾跑快取
```

🔴 **禁止在 Driver 內直接寫 BR 或 TBR，也禁止另組一套逐商排程**：寫入、以 `betRecordUniqueKey()` 去重、TBR 轉檔同交易、逐商錯誤隔離全由 `BetRecordFetchService` 負責；拉取失敗一律拋例外交呼叫端處理，不得在 Driver 內吞例外或自行重送。各方法的語意（憑證共用鍵、時間窗切片、頻率預算、最早可拉時間）以 `app/Vendors/Contracts/SupportsBetRecordFetch.php` 的 docblock 為權威出處。

> TBR 轉檔由 PHP 在拉單的同一個事務內寫入，**不經過 `SP_transfer_bet_record_*` 預存程序**。
