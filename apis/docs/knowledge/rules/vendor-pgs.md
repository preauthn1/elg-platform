---
type: Rule
id: rules/vendor-target
title: target（含 BWG、TIGER9）無縫錢包回調
description: 聚合商 target 的無縫錢包（PGSSW，含經它接入的 BWG 與 TIGER9）發回調給我方時，查餘額、下注、派彩、改派彩、取消投注與活動派彩各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, target, PGSSW, Pegasus, BWG, TIGER9, 聚合商, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:59+08:00" }
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/PegasusController.php
    title: target 回調 controller
    at: 2026-10-22
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/BWGController.php
    title: BWG 回調路徑沿用 target controller
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2146
    title: PGSSW 派彩／取消投注改鎖定讀注單與 TBR
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3075
    title: TPower 取消投注回成功卻未退款
entities:
  - { api: "POST external/api/sw/target/players/wallet" }
  - { api: "POST external/api/sw/target/bet/placeBet" }
  - { api: "POST external/api/sw/target/bet/settle" }
  - { api: "POST external/api/sw/target/bet/settle-change" }
  - { api: "POST external/api/sw/target/bet/settle-without-payout" }
  - { api: "POST external/api/sw/target/bet/cancel" }
  - { api: "POST external/api/sw/target/bonus/settle" }
  - { api: "POST external/api/sw/target/transaction/event-bonus" }
  - { api: "POST external/api/sw/bwg/players/wallet" }
  - { api: "POST external/api/sw/bwg/bet/placeBet" }
  - { api: "POST external/api/sw/bwg/bet/settle" }
  - { api: "POST external/api/sw/bwg/bet/settle-change" }
  - { api: "POST external/api/sw/bwg/bet/settle-without-payout" }
  - { api: "POST external/api/sw/bwg/bet/cancel" }
  - { api: "POST external/api/sw/bwg/bonus/settle" }
  - { api: "POST external/api/sw/bwg/transaction/event-bonus" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\PegasusController" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\BWGController" }
  - { table: pgssw_bet_records }
  - { table: pgssw_cancel_logs }
  - { table: pgssw_event_records }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

target（程式裡叫 PegasusSW 或 PGSSW）是一家聚合商，以無縫錢包接入。BWG 與 TIGER9 經由 target 接入，三者共用同一套回調處理與同一組注單表。會員查餘額、下注、取得派彩、遊戲商改派彩或取消投注、發活動派彩時，遊戲商會發回調給我方。我方依回調增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），再把餘額回給遊戲商。

target 與 TIGER9 打 target 的回調路徑，BWG 打自己的回調路徑，兩條路徑的行為相同。回調屬於哪一家遊戲商，由請求標頭（header）帶的身分代碼對到的遊戲商戶決定，不由路徑決定。

target 的回調還沒改用無縫錢包的共用前置檢查流程，IP 白名單、身分代碼、玩家帳號解析由自己的入口檢查，所以行為與錯誤碼寫在本份。下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)；target 實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

連線憑證（session token）是玩家登入遊戲時拿到、遊戲商回調時帶回來給我方比對的字串，用來找出是哪個玩家帳號。

本份目前收錄：前置檢查、查餘額、下注、派彩、改派彩、取消投注、遊戲內獎勵派彩、活動派彩，以及已停用的「派彩不異動餘額」通知。

## 怎麼觸發

沒有操作畫面。會員在 target、BWG 或 TIGER9 的遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入這三家的遊戲下注來觸發。

## 規則

### 回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時代碼為 0，並回乘過匯率的餘額與交易編號；失敗時回對應代碼，訊息是代碼名稱。 | code:PegasusController::response、code:PegasusController::errorResponse |
| R-2 | 回給遊戲商的餘額先乘匯率，再無條件捨去到小數兩位，不四捨五入。 | code:PegasusController::response、test:GetBalanceTest::test_get_balance_truncates_to_two_decimals、test:GetBalanceTest::test_get_balance_never_rounds_up、test:GetBalanceTest::test_get_balance_applies_rate_before_truncating |
| R-3 | 回調帶玩家帳號時以玩家帳號查，沒帶時以連線憑證查。查不到、或玩家帳號尚未綁定會員時，回代碼 401002。 | code:PegasusController::callAction、test:GetBalanceTest::test_get_balance_returns_balance_by_player_unique_id、test:GetBalanceTest::test_get_balance_returns_error_when_session_token_not_found、test:PlaceBetTest::test_place_bet_returns_error_when_player_not_found |
| R-4 | 請求標頭帶的身分代碼對不到該玩家帳號所屬、且啟用中的遊戲商戶時，回代碼 401002，不動錢包、不寫注單。 | code:PegasusController::callAction、code:PegasusController::getVendorInfo、test:PlaceBetTest::test_place_bet_returns_error_when_identity_header_is_wrong、test:CancelTest::test_cancel_returns_error_when_identity_header_is_wrong、test:SettleTest::test_settle_returns_error_when_identity_header_is_wrong |
| R-5 | 正式環境來源 IP 不在對到的那家遊戲商的白名單時，回代碼 500001。 | code:PegasusController::callAction |
| R-6 | 處理中發生非預期錯誤時，回代碼 500001，真實錯誤只寫 log。 | code:PegasusController::callAction |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 查餘額時回會員真金加禮碼的合計。玩家帳號已停用時回代碼 401002。 | code:PegasusController::getBalance、test:GetBalanceTest::test_get_balance_returns_member_balance、test:GetBalanceTest::test_get_balance_returns_error_when_account_disabled |

### 下注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），建立一筆未結算注單與 TBR。 | code:PegasusController::placeBet、test:PlaceBetTest::test_place_bet_deducts_balance_and_creates_record |
| R-9 | 玩家帳號已停用時回代碼 401002；會員真金加禮碼不足時回代碼 403203。兩種都不建注單、不扣款。 | code:PegasusController::placeBet、test:PlaceBetTest::test_place_bet_returns_error_when_account_disabled、test:PlaceBetTest::test_place_bet_returns_insufficient_balance |
| R-10 | 同一個交易編號的下注重複送達時，回代碼 500029，不重複扣款，注單只有一筆。 | code:PegasusController::placeBet、test:PlaceBetTest::test_place_bet_returns_duplicate_transaction_for_same_id |

### 派彩與改派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 收到派彩回調時，注單的投注改記回調帶的有效投注額，派彩額加入會員錢包，注單改為已結算並更新 TBR。錢包異動含新舊投注額的差額。 | code:PegasusController::settle、test:SettleTest::test_settle_credits_payout_and_updates_record、test:SettleTest::test_settle_zero_payout_on_loss |
| R-12 | 派彩時找不到注單，或注單已不是未結算（已派彩或已取消），回代碼 403601，不動錢包。 | code:PegasusController::settle、test:SettleTest::test_settle_returns_not_found_when_bet_does_not_exist、test:SettleTest::test_settle_returns_invalid_status_when_already_settled、test:SettleTest::test_settle_returns_invalid_status_when_bet_is_cancelled |
| R-13 | 收到改派彩回調時，以回調帶的新投注額與新派彩額覆寫注單，錢包只異動新舊差額，注單記為已結算並更新 TBR。找不到注單時回代碼 404016。 | code:PegasusController::settleChange、test:StaleSnapshotReadTest::test_settle_change_updates_bet_committed_after_transaction_snapshot |
| R-14 | 派彩、改派彩、取消投注都在鎖住會員錢包之後，以鎖定讀查注單。注單在回調等鎖期間才建立完成，仍查得到，不會誤判為查無注單。 | code:PegasusController::settle、code:PegasusController::cancel、code:PegasusController::settleChange、test:StaleSnapshotReadTest::test_cancel_rolls_back_bet_committed_after_transaction_snapshot、test:StaleSnapshotReadTest::test_settle_pays_out_bet_committed_after_transaction_snapshot、mr:!2146 |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 收到取消投注回調時，先依交易編號建立取消紀錄。找到同一玩家帳號的注單後，投注、派彩、輸贏歸零，注單改為已取消，錢包依前後差額退回（真金回真金、禮碼回禮碼），TBR 同步歸零，取消紀錄標為已處理。 | code:PegasusController::cancel、test:CancelTest::test_cancel_refunds_bet_and_updates_record_to_rollback、mr:!2146 |
| R-16 | 同一個交易編號的取消投注已處理過，再收到時直接回代碼 0，不再退回投注額。 | code:PegasusController::cancel、test:CancelTest::test_cancel_returns_ok_when_already_processed |
| R-17 | 找不到注單，或注單屬於別的玩家帳號時，回代碼 0 與當前餘額，不動錢包、不改注單。取消紀錄留在待處理。 | code:PegasusController::cancel、test:CancelTest::test_cancel_returns_ok_when_bet_record_not_found、test:CancelTest::test_cancel_returns_ok_without_refund_when_username_mismatch |

### 遊戲內獎勵與活動派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-18 | 收到遊戲內獎勵（免費遊戲等）派彩回調時，派彩額加入會員錢包，另建一筆投注為 0 的已結算注單，並建立 TBR，TBR 的獎金欄記這筆派彩額。同一個交易編號重複送達時回代碼 500029，不重複加款。 | code:PegasusController::bonus、test:BonusTest::test_bonus_credits_payout_and_returns_standard_response、test:BonusTest::test_bonus_marks_bet_record_as_settled、test:BonusTest::test_bonus_returns_duplicate_transaction_for_same_id |
| R-19 | 收到活動派彩回調時，金額加入會員錢包，另建一筆投注為 0 的注單與 TBR（TBR 的獎金欄記這筆金額），並寫一筆活動派彩紀錄。回應帶異動前與異動後的餘額，同樣無條件捨去到小數兩位。同一個交易編號重複送達時回代碼 500029。 | code:PegasusController::eventBonus、test:EventBonusTest::test_event_bonus_credits_amount_and_creates_records、test:EventBonusTest::test_event_bonus_truncates_amounts_to_two_decimals、test:EventBonusTest::test_event_bonus_returns_duplicate_transaction_for_same_id |
| R-20 | 「派彩不異動餘額」通知（settle-without-payout）已停用、不再主動對接。仍收到時只記注單與 TBR，不異動會員錢包；同一個交易編號重複送達時回代碼 500029。 | code:PegasusController::settleWithoutPayout |

## 還沒確認的事

- 會員不具下注資格時，下注回調回的是代碼 0（成功碼）、餘額 0、交易編號 -1，不是錯誤碼。待 RD 確認遊戲商怎麼解讀這個回應，以及是否應改回錯誤碼（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 取消投注不檢查注單狀態：已派彩的注單被取消時，會退回投注並收回派彩。待 PM 與 RD 確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 改派彩不檢查注單狀態：已取消的注單收到改派彩，會依新金額重新入帳並改回已結算。待 PM 與 RD 確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
