---
type: Rule
id: rules/vendor-account-pool
title: 預建遊戲商帳號池
description: 系統怎麼預先向遊戲商建好玩家帳號放進帳號池、會員第一次進遊戲時怎麼綁定、補不進去時怎麼告警，以及 Olympus 上怎麼手動補建與查庫存。
domain: 遊戲與遊戲商
subdomain: 遊戲商與商戶
tags: [帳號池, 預建帳號, 補建遊戲帳號, 帳號庫存, 玩家帳號, 遊戲帳號, 綁定, 遊戲商戶, 預備帳號數量, 水位, 佔位, Olympus, 排程, Telegram 告警, create_vendor_account, account pool, 會員, vendor account, create vendor account, placeholder]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:58+08:00" }
evidence:
  - kind: code
    ref: /app/Services/VendorAccountPoolService.php
    title: 帳號池補建、庫存查詢與卡住偵測
    at: 2026-10-22
  - kind: code
    ref: /app/Jobs/ReplenishVendorAccountPool.php
    title: 單一遊戲商的補建工作
  - kind: code
    ref: /app/Vendors/BaseAbstractVendor.php
    title: 會員綁定玩家帳號
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2144
    title: 預建帳號排程自 RD-Studio 搬到 APIs
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2258
    title: target 聚合商等五家接手建帳號排程
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2345
    title: PPSW 接手建帳號排程並修正流水號
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2174
    title: Evo 建號在寫入當下補密碼
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2366
    title: 帳號池補建的三方呼叫移出交易
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2374
    title: 帳號池補建卡住的每小時監測與告警
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4307
    title: 預建帳號排程搬遷
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4351
    title: 其餘遊戲商接手建帳號排程
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3177
    title: target 聚合商建帳號
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3463
    title: NextSpin 帳號池連續 26 天補不進去
entities:
  - api: "GET olympus/create-vendor-account"
  - api: "GET olympus/vendor-account-pool"
  - command: create_vendor_account
  - command: vendor:monitor-account-pool
  - class: 'App\Console\Commands\Schedule\CreateVendorAccount'
  - class: 'App\Console\Commands\Schedule\MonitorVendorAccountPool'
  - class: 'App\Jobs\ReplenishVendorAccountPool'
  - class: 'App\Services\VendorAccountPoolService'
  - class: 'App\Services\AbstractVendorBatchService'
  - class: 'App\Vendors\Contracts\SupportsAccountCreation'
  - class: 'App\Vendors\Contracts\ShouldScheduleAccountCreation'
  - class: 'App\Vendors\BaseAbstractVendor'
  - class: 'App\Filament\Admin\Pages\Operations\CreateVendorAccount'
  - class: 'App\Filament\Admin\Pages\Queries\VendorAccountPool'
  - table: vendor_infos
related:
  - rules/vendor-and-channel-management
---

## 這個主題在講什麼

會員在每一家遊戲商各有一組玩家帳號。玩家帳號不是會員進遊戲時才去遊戲商那邊建，而是系統事先向遊戲商建好一批，放在我方的帳號池裡；會員第一次需要時，從池裡拿一個綁給他。這樣會員進遊戲時不必等遊戲商建號。

帳號池以遊戲商戶為單位。每個遊戲商戶有一個「預備帳號數量」，是池裡至少要保有的可綁定帳號數。排程每 5 分鐘檢查一次，不足就補。補建對遊戲商一直失敗時，池會慢慢被綁光，所以另有每小時的監測，發現補不進去就發 Telegram 告警（系統發到維運的 Telegram 警告群組的訊息）。

名詞說明：

| 名詞 | 意思 |
|---|---|
| 可綁定帳號 | 已建好、啟用中、還沒綁給任何會員的玩家帳號 |
| 佔位帳號 | 我方已寫入、但遊戲商那邊沒有確認建成的帳號（建號中或建號失敗）。不能被綁定，也不算進可綁定帳號數 |
| 水位 | 某個遊戲商戶目前的可綁定帳號數 |
| 缺口 | 預備帳號數量減去水位；水位不低於預備帳號數量時缺口是 0 |

本份目前收錄的範圍：自動補建排程、建號時帳號怎麼從佔位轉為可綁定、Olympus「補建遊戲帳號」與「帳號庫存查詢」兩頁、補不進去的監測告警、會員綁定玩家帳號。活動帳號池與 FonPay 的建號仍在 RD-Studio，不在本份。

## 怎麼觸發

- 排程：`create_vendor_account` 每 5 分鐘執行；`vendor:monitor-account-pool` 每小時執行。
- Olympus：「系統操作」底下的「補建遊戲帳號」頁手動補建；「查詢」底下的「帳號庫存查詢」頁查庫存。
- 會員：第一次在某個遊戲商戶需要玩家帳號時（例如進遊戲），系統從帳號池綁定一個。

## 規則

### 自動補建排程

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 排程每 5 分鐘把需要補建的遊戲商逐家派成補建工作。納入的遊戲商要同時符合：對接程式宣告加入自動排程、遊戲商開啟、至少有一個開啟的遊戲商戶、不在停用遊戲商清單、目前不在維護時段。 | command:create_vendor_account、code:VendorAccountPoolService::dispatchQueueReplenish、code:AbstractVendorBatchService::availableVendors、mr:!2144 |
| R-2 | 停用遊戲商清單上的遊戲商不會被補建，即使它的對接程式支援建號。XE88、YGG、MENANG、Bola2 目前都在清單上；PPSW 已移出清單，納入自動補建。 | code:AbstractVendorBatchService::enabledVendorsQuery、mr:!2144、mr:!2258、mr:!2345 |
| R-3 | 聚合商的對接程式加入自動排程時，底下所有子遊戲商一起納入。target 聚合商的 target、Jili、target Live、FUNKY GAMES 四家共用同一支對接程式，都在自動補建範圍內。 | code:VendorAccountPoolService::applySchedulableVendorScope、test:Tests\Feature\Vendors\target\DriverTest::test_pgs_aggregate_vendors_are_all_account_creation_capable、mr:!2258 |
| R-4 | 共用同一支對接程式的遊戲商，派發時依序錯開 15 秒，避免同時打同一個遊戲商的 API 而被限流。 | code:VendorAccountPoolService::dispatchQueueReplenish |
| R-5 | 同一家遊戲商的補建工作還沒執行完時，270 秒內再派發的同一家會被略過；執行時同一家遊戲商同時只跑一份，後到的直接丟棄。 | code:ReplenishVendorAccountPool::middleware、mr:!2144 |
| R-6 | 補建工作開始執行時會重新檢查一次：遊戲商或遊戲商戶已停用、遊戲商在維護時段、對接程式不支援建號，都直接略過，並記下略過原因。 | code:ReplenishVendorAccountPool::handle |
| R-7 | 補建工作執行上限 240 秒，補建只用其中八成的時間，時間用完就停，剩下的缺口留給下一輪。補建工作失敗不重試，由下一輪排程重新派發。 | code:ReplenishVendorAccountPool::handle、mr:!2366 |

### 計算缺口與建號

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 一家遊戲商底下每個開啟的遊戲商戶各自計算缺口，水位低於預備帳號數量時才補，缺多少補多少。水位每一輪都從資料庫重新計算，讀主庫，不讀複本。 | code:VendorAccountPoolService::replenishVendor、mr:!2366 |
| R-9 | 新帳號名稱是遊戲商戶的帳號前綴加 8 位流水號，流水號從該遊戲商戶現有的最大流水號加 1 往下接，讀主庫。PlayTechSW、FumaSW 的帳號在前綴與流水號之間多一個底線，流水號照樣接續。 | code:VendorAccountPoolService::replenishVendor、code:App\Vendors\PlayTechSW\Driver::getStoredAccountPrefix、code:App\Vendors\FumaSW\Driver::getStoredAccountPrefix、mr:!2258、mr:!2345、mr:!2366 |
| R-10 | 單一帳號建號失敗時記下失敗，繼續建下一個；單一遊戲商出錯不影響其他遊戲商。 | code:VendorAccountPoolService::replenishVendor、code:AbstractVendorBatchService::loopVendors |
| R-11 | 補建不包在同一個資料庫交易裡。補建中途被中斷時，已經送給遊戲商的帳號紀錄會留在我方，不會被一起回滾清掉。 | code:VendorAccountPoolService::replenishVendor、mr:!2366 |
| R-12 | 要向遊戲商建號的遊戲商，先在我方寫入一筆佔位帳號，遊戲商確認建成後才轉為可綁定。遊戲商回失敗時帳號留在佔位狀態，不進帳號池，也不刪除。 | code:SupportsAccountCreation::createAccount、test:Tests\Feature\Vendors\target\DriverTest::test_create_account_marks_account_bindable_when_vendor_api_succeeds、test:Tests\Feature\Vendors\target\DriverTest::test_create_account_keeps_account_out_of_pool_when_vendor_api_fails、test:Tests\Feature\Vendors\Suncity\DriverTest::test_create_account_releases_account_after_vendor_success、test:Tests\Feature\Vendors\Suncity\DriverTest::test_create_account_keeps_placeholder_when_vendor_fails、test:Tests\Feature\Vendors\Play8oy\DriverTest::test_create_account_writes_username_modified_and_releases_account、test:Tests\Feature\Vendors\Play8oy\DriverTest::test_create_account_keeps_placeholder_member_id_when_vendor_fails、mr:!2258 |
| R-13 | Evo 家族（含 Connect93、Live22）與 PPSW 是例外：帳號寫入當下就是可綁定，遊戲商回失敗後才改成佔位狀態。Evo 家族寫入時同時帶入密碼。Titan Evo 在遊戲商建成後還要設定限紅，限紅設定失敗時帳號改成另一種不可綁定的狀態。 | code:App\Vendors\Evo\Driver::createAccount、code:App\Vendors\PPSW\Driver::createAccount、test:Tests\Feature\Vendors\Evo\DriverTest::test_create_account_persists_row_with_password、test:Tests\Feature\Vendors\Evo\DriverTest::test_create_account_soft_deletes_row_when_vendor_api_fails、mr:!2174、mr:!2345 |
| R-14 | VplusSW 建號時，遊戲商回「user already exist」也算建號成功。 | code:App\Vendors\VplusSW\Driver::createAccount、mr:!2258 |
| R-15 | Haolong 在台北時間 14:00 到 22:00（UTC 06:00 到 14:00）不受理建號。這段時間內 Haolong 的補建整批略過，不寫入任何帳號，缺口由這段時間以外的排程補上。 | code:App\Vendors\Haolong\Driver::createAccount、mr:!2144 |

### Olympus 手動補建與庫存查詢

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-16 | 「補建遊戲帳號」頁的遊戲商選單只列出對接程式支援建號的遊戲商，包括沒有加入自動排程的。選一家送出後，系統立即同步補建這一家，補建結果記在操作紀錄。 | code:App\Filament\Admin\Pages\Operations\CreateVendorAccount::buildParams、command:create_vendor_account、mr:!2144 |
| R-17 | 手動補建指定的遊戲商若在停用遊戲商清單上或正在維護時段，不會補建，命令輸出「沒有可處理的遊戲商」。 | code:AbstractVendorBatchService::availableVendors、command:create_vendor_account |
| R-18 | 手動補建可勾選乾跑模式。乾跑結束後我方資料庫的變更全部回滾，但已經送給遊戲商的建號請求不會撤銷，遊戲商那邊會多出這批帳號。 | code:VendorAccountPoolService::previewReplenish、code:App\Filament\Admin\Pages\Operations\CreateVendorAccount::buildParams |
| R-19 | 「帳號庫存查詢」頁逐個遊戲商戶列出水位、預備帳號數量、缺口，並標示該遊戲商是否在自動排程內、是否在維護時段。可以只看一家，也可以只看缺口大於 0 的遊戲商戶。本頁只查詢，不寫入任何資料。 | code:VendorAccountPoolService::inventory、code:App\Filament\Admin\Pages\Queries\VendorAccountPool::search、mr:!2144 |
| R-20 | 「帳號庫存查詢」列出對接程式支援建號、遊戲商開啟、不在停用遊戲商清單的遊戲商。維護中的遊戲商照樣列出並標註，因為維護期間排程不補，缺口不會自動補上。 | code:VendorAccountPoolService::inventory |
| R-21 | Olympus 的三種角色（Admin、Super Admin、運維）都能進「補建遊戲帳號」與「帳號庫存查詢」兩頁。 | code:UserRoleEnum::canAccessPage、test:RoleMenuAccessTest::test_ops_is_denied_schema_operation_but_allowed_elsewhere |

### 補不進去的監測告警

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-22 | 每小時檢查一次所有支援建號、遊戲商開啟、不在停用遊戲商清單的遊戲商戶。每個遊戲商戶從最新建立的帳號往回看最多 20 筆，數連續幾筆是近 1 小時內建立的佔位帳號；遇到可綁定或已綁定的帳號就停止計算。 | command:vendor:monitor-account-pool、code:VendorAccountPoolService::detectStuckPools、mr:!2374 |
| R-23 | 連續佔位帳號達 10 筆的遊戲商戶才告警，未達的不告警。 | command:vendor:monitor-account-pool、test:MonitorVendorAccountPoolTest::test_no_alert_when_nothing_is_stuck、test:MonitorVendorAccountPoolTest::test_below_threshold_does_not_alert、mr:!2374 |
| R-24 | 只有一個遊戲商戶要告警時，訊息含遊戲商與遊戲商戶、連續筆數、最新一筆帳號與建立時間、池水位，以及「確認三方商戶是否仍有效；已廢棄請停用」的處置提示。連續筆數達到 20 筆的取樣上限時，筆數前加「≥」。 | code:MonitorVendorAccountPool::handle、test:MonitorVendorAccountPoolTest::test_single_merchant_message、test:MonitorVendorAccountPoolTest::test_consecutive_at_sample_cap_is_marked |
| R-25 | 多個遊戲商戶要告警時合併成一則訊息，最多列 8 個，超過的寫「另有 N 個商戶，完整清單見 log」。 | code:MonitorVendorAccountPool::handle、test:MonitorVendorAccountPoolTest::test_multiple_merchants_are_capped_with_a_tail_notice、mr:!2374 |
| R-26 | 告警只在正式環境送到 Telegram；測試與 dev 環境即使命中也不送。手動加乾跑參數時只印結果，不送。 | code:MonitorVendorAccountPool::handle、test:MonitorVendorAccountPoolTest::test_non_production_does_not_send、test:MonitorVendorAccountPoolTest::test_dry_run_does_not_send、mr:!2374 |

### 會員綁定玩家帳號

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-27 | 會員在某個遊戲商戶已經有綁定的玩家帳號時，直接沿用，不再從帳號池拿。 | code:BaseAbstractVendor::getVendorAccount |
| R-28 | 會員在某個遊戲商戶還沒有玩家帳號時，從該遊戲商戶的帳號池取最早建立的一筆可綁定帳號綁給會員。 | code:BaseAbstractVendor::getVendorAccount |
| R-29 | 帳號池沒有可綁定帳號時，回錯誤「暫無可用的遊戲帳號可綁定會員」，會員拿不到玩家帳號。 | code:BaseAbstractVendor::getVendorAccount |
| R-30 | 同一會員對同一遊戲商戶同時送出多個請求時，只會綁到一個帳號。 | code:BaseAbstractVendor::getVendorAccount |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 某遊戲商戶預備帳號數量 100，目前可綁定 70 | 下一輪排程補 30 個，時間不夠的部分再下一輪補 | R-8、R-7 |
| 遊戲商對建號一直回失敗 | 帳號一直停在佔位狀態不進池；1 小時內連續 10 筆以上時，正式環境發 Telegram 告警 | R-12、R-22、R-23、R-26 |
| 營運在台北時間 15:00 對 Haolong 手動補建 | 整批略過，不新增帳號 | R-15、R-16 |
| 會員第一次進一家帳號池已空的遊戲商 | 回「暫無可用的遊戲帳號可綁定會員」 | R-29 |

## 還沒確認的事

- 同一家遊戲商不能讓 RD-Studio 與 APIs 兩邊同時補建，否則會撞流水號。各站 RD-Studio 舊建號排程（包括 `ppsw:create_account`）是否已全部下線，程式裡查不到，待 RD 確認（來源：`target/tickets#4307`、`target/tickets#4351`）。
- Evo 家族（含 Connect93、Live22）與 PPSW 建號時，帳號寫入當下就可綁定，遊戲商回失敗才改成佔位（R-13），與 [decisions/0043](../decisions/0043-vendor-account-placeholder-before-vendor-create.md) 的「先佔位、建成才可綁定」不同。呼叫遊戲商期間帳號可能被會員綁走；PPSW 呼叫遊戲商時若直接拋出例外，帳號會停在可綁定狀態。是否要改，待 RD 判斷（2026-10-22 回填時盤點程式碼發現，尚無工單）。
