# Stored Procedure(SP) 異動方式
## 文件
各 SP 的建立文件存放在 `database/procedures` 中，新的 SP 也應更新在此

## 更新
```sh
php artisan db:seed --class=SyncProcedureSeeder
```
此指令會執行 `SyncProcedureSeeder` 同步到 DB，過程會檢查 md5 後的狀態

## 已移植 PHP 的 SP 對照表（雙軌執行中，改動前必讀）

下列 SP 已逐列等值移植為 APIs 的 PHP 類別（target/tickets#4257，SBR 整點彙總 Queue 化）。
移植後 SP **沒有退場**：舊系統 RD-Studio-01 的排程仍每小時呼叫原 SP，與 APIs 的 PHP 版
對同一批彙總表刪除重建——兩邊同時在跑，稱為「雙軌」。

移植類集中在 `app/Console/Commands/SummaryBetRecord/SP/`，**檔名與類名直接沿用原 SP 名**
（例：`SP_summary_bet_record_team.sql` ↔ `SP_summary_bet_record_team.php`），
看到檔名即知對應哪支 SP，不需查表。

| SP（database/procedures/） | PHP 移植類（app/Console/Commands/SummaryBetRecord/SP/） | 用途 |
|---|---|---|
| `SP_summary_bet_record_team.sql` | `SP_summary_bet_record_team.php` | 代理團隊面彙總（逐層兩段 UNION 滾算） |
| `SP_summary_rolling_chip_record.sql` | `SP_summary_rolling_chip_record.php` | 滾碼彙總（五來源 UNION） |
| `SP_summary_event_record_by_rolling_chip.sql` | `SP_summary_event_record_by_rolling_chip.php` | 滾碼活動彙總（會員／代理面窗口重建） |

（team 移植類亦涵蓋 `COM_Update_Control_Table.sql` 的 control table 寫入行為。）

### 雙軌期間的改動紀律

- **改任何一邊，必須同步改另一邊**：兩邊計算行為若有差異，雙軌互相覆蓋會造成報表數值
  在兩次排程之間來回跳動（閃爍）。禁止只改 SP 或只改 PHP，也禁止在 PHP 側「順手優化」。
- **SP 定義檔與 DB 本體都不可刪**，直到雙軌收斂（擇一停用；待辦登記於
  `docs/未完成計劃/RB/TODO.md`「2.17 SBR 排程改用佇列後、等舊系統退場才能做的事」）。
- 等值由測試鎖住：`tests/Unit/Console/Commands/SummaryBetRecord/SummaryProcedurePortEquivalenceTest.php`
  以同一組資料先跑 SP、再跑 PHP 逐列比對。動過任一邊後必跑此測試。
- 注意：既有的 `ProcedureDirectorySyncedWithDatabaseTest` 只驗 SP「存在」、不驗「內容」；
  直接改 DB 內的 SP 不會被它抓到，一律改 `database/procedures/` 定義檔再以 seeder 同步。

## 已由 PHP 取代、待退場的 SP（不是雙軌，是替換）

| SP（database/procedures/） | 取代者 | 狀態 |
|---|---|---|
| `SP_summary_event_record_by_payment.sql` | `App\Services\Summary\PaymentCostSummaryService`（排程 `summary:payment-cost` 每小時、重算 `order:recalc-payment-cost-summary`） | target/tickets#4369：排程上線後停用各站 MySQL EVENT `Event_Summary_event_reocrd_by_payment`，觀察一輪後刪 SP |
| `SP_summary_event_record_by_payment_W.sql` | 同上（event 8 代付） | 同上；原 EVENT `Event_Summary_event_reocrd_by_payment_W` 只有 Yes2Win 有 |
| `SP_summary_event_record_by_vip.sql` | `App\Services\Summary\VipRewardSummaryService`（排程 `summary:event-record-vip` 每小時；回補用同一支命令加 `--from`／`--to`） | target/tickets#4420：部署後逐站停用 MySQL EVENT `Event_Summary_event_reocrd_by_vip`。SP 檔頭已標作廢，定義只留給回滾，觀察期後移除檔案並 DROP PROCEDURE |
| `SP_summary_event_record_by_sms.sql` | `App\Services\Summary\SmsCostSummaryService`（排程 `summary:event-record-sms` 每小時；回補用同一支命令加 `--from`／`--to`；歷史簡訊成本用 `sms:backfill-cost` 回補） | target/tickets#4424：上線順序不可換，依序是部署、發送端開始寫入成本、跑 `sms:backfill-cost` 到未回補筆數歸零、確認排程接手後逐站停用 EVENT `Event_Summary_event_reocrd_by_sms`。SP 檔頭已標作廢，處理方式同上 |

這四支**不做雙軌**。原代付 SP 寫入前不刪舊列，與 PHP 版並行會撞主鍵報錯；VIP 與簡訊兩支的 PHP 版是該 event 的唯一寫入者，兩個寫入者不可並存。
依上表的時機停用 EVENT；回滾＝關 Laravel 排程、依 SP 檔頭註解重建 EVENT。
