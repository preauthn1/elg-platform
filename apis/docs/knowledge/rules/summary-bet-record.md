---
type: Rule
id: rules/summary-bet-record
title: 注單彙總（SBR）的排程、補洞、稽核與人工補跑
description: SBR 每小時怎麼產生、漏跑時怎麼自動補、已關帳的時段怎麼調帳、每日稽核與隔日整天重跑怎麼運作，以及維運怎麼人工補跑。
domain: 遊戲與遊戲商
subdomain: 報表
tags: [SBR, 注單彙總, 投注彙總, summary_bet_record, TBR, 小時桶, 關帳, 重算, 調帳, 調帳差額, 回看補洞, 稽核, 隔日整天重跑, 對帳時區, Olympus, 重跑注單彙總, 補帳作業, 排程, 報表, 代理, summary bet record, rerun, audit, backfill]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:41+08:00" }
evidence:
  - kind: code
    ref: /app/Jobs/SummarizeBetRecordHour.php
    title: 整點彙總工作與回看補洞
    at: 2026-10-22
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/SummaryBetRecordRunner.php
    title: 單一遊戲商單一小時桶的彙總與結算
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/SummaryBetRecordService.php
    title: 關帳判斷、重算與調帳
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/SummaryBetRecordCommand.php
    title: 人工補跑命令
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/DispatchSummaryBetRecordCommand.php
    title: 整點派發
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/AuditSummaryBetRecordRunsCommand.php
    title: 每日缺口稽核
  - kind: code
    ref: /app/Console/Commands/SummaryBetRecord/RerunFullDaySummaryBetRecordCommand.php
    title: 隔日整天重跑
  - kind: code
    ref: /app/Filament/Admin/Pages/Operations/SummaryBetRecord.php
    title: Olympus 重跑注單彙總頁
  - kind: code
    ref: /config/summary_bet_record.php
    title: 遊戲商彙總設定
  - kind: code
    ref: /routes/console.php
    title: 排程時間
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2080
    title: 整點彙總改走佇列、回看補洞、每日稽核
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2298
    title: 回看補洞不認人工補跑的完成紀錄
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2205
    title: 對帳時區與隔日整天重跑
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2206
    title: 隔日整天重跑改到 UTC 日界之後
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2343
    title: 隔日整天重跑改為逐家依序執行
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2126
    title: 補齊舊專案遊戲商的彙總設定
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2379
    title: 補齊 TTHSW 子遊戲商的彙總設定
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2263
    title: ACE333 納入整點排程
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4257
    title: SBR 整點彙總防漏跑
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4416
    title: 回看補洞不認人工補跑
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4357
    title: 對帳時區與隔日整天重跑
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4303
    title: 補齊舊專案遊戲商的彙總設定
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4456
    title: TTHSW 子遊戲商無法重跑彙總
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4457
    title: TTHSW 子遊戲商無法重跑彙總
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4474
    title: TTHSW 子遊戲商無法重跑彙總
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4068
    title: ACE333 彙總接手 RD-Studio 舊指令
entities:
  - command: summary_bet_record
  - command: summary_bet_record:dispatch
  - command: summary_bet_record:audit
  - command: summary_bet_record:rerun-full-day
  - class: 'App\Jobs\SummarizeBetRecordHour'
  - class: 'App\Console\Commands\SummaryBetRecord\SummaryBetRecordRunner'
  - class: 'App\Console\Commands\SummaryBetRecord\SummaryBetRecordService'
  - class: 'App\Console\Commands\SummaryBetRecord\SummaryBetRecordCommand'
  - class: 'App\Console\Commands\SummaryBetRecord\DispatchSummaryBetRecordCommand'
  - class: 'App\Console\Commands\SummaryBetRecord\AuditSummaryBetRecordRunsCommand'
  - class: 'App\Console\Commands\SummaryBetRecord\RerunFullDaySummaryBetRecordCommand'
  - class: 'App\Filament\Admin\Pages\Operations\SummaryBetRecord'
  - table: summary_bet_records
  - table: queue_job_runs
---

## 這個主題在講什麼

SBR 是把 TBR（各家注單轉成同一組欄位之後的注單）依「每人每小時每款遊戲」聚合後的結算數字，報表與代理分潤都讀它。SBR 以小時桶（整點為界的一小時）為單位產生，每個小時桶跑完後再跑一次結算（全民代理彙總與滾碼結算）。

已關帳的時段不能整段重算，只能追加調帳差額。關帳的界線與調帳的寫法也寫在本份。

本份目前收錄：整點排程與回看補洞、關帳與調帳、人工補跑（命令與 Olympus「重跑注單彙總」頁）、每日缺口稽核、隔日整天重跑。各遊戲商的注單怎麼轉成 TBR 不在本份。

## 怎麼觸發

| 觸發方式 | 時間或位置 |
|---|---|
| 整點排程 `summary_bet_record:dispatch` | 每小時 30 分 |
| 每日稽核 `summary_bet_record:audit` | 每天 UTC 02:40（台北時間 10:40） |
| 隔日整天重跑 `summary_bet_record:rerun-full-day` | 每天 UTC 00:25（台北時間 08:25） |
| 人工補跑 | Olympus「系統操作」底下的「重跑注單彙總」頁，或命令 `summary_bet_record --vendor=<id> --date=<日期> [--hour=<小時>] [--timezone=<時區>] [--dry-run]` |

## 規則

### 整點排程與回看補洞

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 每小時 30 分，排程只派發一個彙總工作給背景的長時佇列，指定處理前一個整點的小時桶（UTC）；排程本身不做彙總。 | code:DispatchSummaryBetRecordCommand::handle、mr:!2080 |
| R-2 | 每個彙總工作回看含本次在內最近 6 個小時桶，找出納入排程的遊戲商中還沒有成功完成紀錄的組合（遊戲商與小時桶），逐一補算。某一輪觸發沒跑到時，由之後 6 小時內的整點工作補上。 | code:SummarizeBetRecordHour::handle、code:SummarizeBetRecordHour::lookbackBuckets、mr:!2080 |
| R-3 | 判斷某個組合是否已完成時，只認排程與回看補洞留下的成功紀錄；人工補跑（Olympus 或命令）的成功紀錄不算。人工補跑過的小時桶，只要還在回看範圍內，仍會被排程再重算一次；重算是覆蓋寫入，不會重複計帳。 | code:SummaryBetRecordRunner::runVendorBucket、code:SummarizeBetRecordHour::handle、mr:!2298 |
| R-4 | 一個遊戲商的一個小時桶彙總成功時，在同一個交易內寫入完成紀錄；彙總失敗時整筆回滾，不留完成紀錄，交給重試或下一輪補。預覽模式不留完成紀錄。 | code:SummaryBetRecordRunner::runVendorBucket、mr:!2080 |
| R-5 | 單一遊戲商失敗不中斷其他遊戲商。工作最後以失敗結束並重試（最多 3 次例外），已完成的組合不再重算。重試耗盡時發 Telegram 告警（系統發到維運的 Telegram 除錯群組的訊息），缺漏交給下一個整點的回看。 | code:SummarizeBetRecordHour::processBucket、code:SummarizeBetRecordHour::failed、mr:!2080 |
| R-6 | 每個彙總工作都會對本次觸發的小時桶重跑結算；回看範圍內的其他小時桶，只有實際補算到遊戲商時才重跑結算。 | code:SummarizeBetRecordHour::handle |
| R-7 | 結算失敗時立即發 Telegram 告警，並清掉該結算涵蓋的小時桶裡由排程與回看補洞留下的完成紀錄（人工補跑的紀錄保留），重試時整格重算。 | code:SummarizeBetRecordHour::runSettlementSafely、code:SummarizeBetRecordHour::resetCompletionMarks、mr:!2080 |
| R-8 | 同一個遊戲商的同一個小時桶，同時只能有一個彙總在跑，排程與人工補跑互相排斥；不同小時桶可以同時跑。 | code:SummaryBetRecordRunner::runVendorBucket、code:SummaryBetRecordRunner::vendorLockKey |
| R-9 | 遊戲商是否納入整點排程，依彙總設定決定。設成不納入的遊戲商（例如 EliteHub 各子遊戲商、FumaSW）不隨排程與稽核執行，只有人工補跑指定該遊戲商時才彙總。ACE333 納入整點排程。 | code:SummaryBetRecordRunner::scheduledConfigs、code:SummaryBetRecordCommand::vendorsForRun、mr:!2126、mr:!2263 |

### 關帳與調帳

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 每天台北時間 14:00 起，前一個台北日（含更早）的小時桶算已關帳；14:00 之前，前一個台北日仍算未關帳。 | code:SummaryBetRecordService::getDailyCutoffTime、code:SummaryBetRecordService::hasPastCutoff |
| R-11 | 未關帳的小時桶彙總時，先刪掉該遊戲商該小時桶原有的 SBR（調帳差額列除外），再整段重寫。 | code:SummaryBetRecordService::process、code:SummaryBetRecordService::clearPreviousSummaryRecords |
| R-12 | 已關帳的小時桶彙總時不改原有的列，改跟現有數字比對：新出現或數字不同的會員與遊戲，寫一列調帳差額；原本有、這次已經沒有的，寫一列全部取負值的沖銷列；數字相同的不寫。調帳差額列一律記在關帳時間那一格。 | code:SummaryBetRecordService::applyHourlyCorrection |
| R-13 | 一次處理多個小時桶時，未關帳的小時桶每跑完一個就跑一次結算；已關帳的小時桶在全部跑完後，以關帳時間只跑一次結算。 | code:SummaryBetRecordCommand::processTimeRange、code:SummarizeBetRecordHour::handle |

### 人工補跑

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 人工補跑可以指定遊戲商、日期、小時。日期與小時預設以台北時間解讀，可改用 UTC、UTC-4、UTC+7；只給日期就跑該時區的整天 24 個小時桶。沒指定遊戲商時，跑所有納入排程的遊戲商。 | code:SummaryBetRecordCommand::parseArguments、code:SummaryBetRecordCommand::vendorsForRun、mr:!2205 |
| R-15 | 指定的遊戲商不在彙總設定裡時，命令直接以失敗結束。 | code:SummaryBetRecordCommand::handle、mr:!2379 |
| R-16 | 預覽模式照常計算並列出寫入筆數或調帳筆數，但不寫入 SBR、不留完成紀錄，也不跑結算。 | code:SummaryBetRecordRunner::runVendorBucket、code:SummaryBetRecordCommand::processTimeRange |
| R-17 | Olympus「重跑注單彙總」頁的遊戲商選單只列出彙總設定裡有的遊戲商。送出的遊戲商、日期、時區、小時與乾跑模式原樣交給補跑命令；小時留空就是整天。 | code:SummaryBetRecord::formComponents、code:SummaryBetRecord::buildParams、test:SummaryBetRecordPageTest::test_execute_dispatches_command_with_vendor_and_date、mr:!2126、mr:!2379 |
| R-18 | 在「重跑注單彙總」頁選遊戲商時，時區預設帶該遊戲商的對帳時區；彙總設定沒寫對帳時區的遊戲商預設台北時間（UTC+8），不看遊戲商本身的計算時區。 | code:AbstractOperationPage::vendorTimezoneOption、test:SummaryBetRecordPageTest::test_choosing_a_vendor_prefills_its_reconcile_timezone、mr:!2205 |

### 每日缺口稽核

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-19 | 每天 UTC 02:40，稽核 4 小時前往回 48 小時內，每個納入排程的遊戲商在每個小時桶是否都有成功完成紀錄；這裡人工補跑的紀錄也算數。 | code:AuditSummaryBetRecordRunsCommand::handle、mr:!2080、mr:!2298 |
| R-20 | 有缺口時發 Telegram 告警：先寫缺漏的遊戲商家數與總天數，再一家一行列出遊戲商名稱與缺漏日期（日期依該遊戲商的對帳時區換算），並附上「請至 Olympus「操作 → 注單彙總」依下列遊戲商與日期重跑整天。」與「逾 14:00 執行須另外填寫補帳 Excel。」兩句提示。命令以失敗結束。 | code:AuditSummaryBetRecordRunsCommand::handle、mr:!2080 |
| R-21 | 稽核只通知，不自動補跑。 | code:AuditSummaryBetRecordRunsCommand::handle |

### 隔日整天重跑

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-22 | 每天 UTC 00:25，對彙總設定標為隔日整天重跑的遊戲商（目前是 BWG、PPPOP），依各自的對帳時區取最近一個已結束的整天重跑。 | code:RerunFullDaySummaryBetRecordCommand::steps、command:summary_bet_record:rerun-full-day、mr:!2205、mr:!2206、mr:!2343 |
| R-23 | 轉帳錢包遊戲商先重新拉取該日注單（金額有異動的既有注單一併更新），再重跑彙總；無縫錢包遊戲商只重跑彙總。 | code:RerunFullDaySummaryBetRecordCommand::steps、mr:!2205 |
| R-24 | 各遊戲商在同一個程序內依序執行。某一家的某一步失敗時，略過這家的後續步驟、繼續下一家；全部跑完若有失敗，命令以失敗結束，由排程失敗告警通知。 | code:RerunFullDaySummaryBetRecordCommand::runVendor、code:RerunFullDaySummaryBetRecordCommand::handle、mr:!2343 |
| R-25 | 上一輪執行還沒結束（以 3600 秒為限）時，下一輪不會疊上來重複執行；這支命令執行再久也不發排程耗時過長告警。 | command:summary_bet_record:rerun-full-day、code:ScheduleProvider::handleSuccessfulTask、mr:!2343 |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 台北時間 10-22 11:00，人工補跑 10-21 整天 | 10-21 還沒關帳，24 個小時桶整段重寫 | R-10、R-11 |
| 台北時間 10-22 15:00，人工補跑 10-21 整天 | 10-21 已關帳，只寫調帳差額，結算以關帳時間跑一次 | R-10、R-12、R-13 |
| 人工補跑某個小時桶後，遊戲商的注單才遲到補進 | 該小時桶還在回看範圍內時，整點排程會再重算一次，把遲到的注單算進去 | R-3 |
| 佇列停擺超過 6 小時 | 回看補不到，每日稽核發告警，由維運到 Olympus 重跑整天 | R-2、R-19、R-20 |
