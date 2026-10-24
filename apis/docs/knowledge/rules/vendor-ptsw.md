---
type: Rule
id: rules/vendor-ptsw
title: PTSW 無縫錢包回調
description: PlayTech 的無縫錢包版（PTSW）發回調給我方時，登入驗證、查餘額、投注、派彩、取消投注、關局與獎金入帳各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, PTSW, PlayTech, Playtech, 真人, 會員, callback, seamless wallet, single wallet]
entities:
  - api: "POST external/api/sw/pt/auth"
  - api: "POST external/api/sw/pt/authenticate"
  - api: "POST external/api/sw/pt/balance"
  - api: "POST external/api/sw/pt/getBalance"
  - api: "POST external/api/sw/pt/keepAlive"
  - api: "POST external/api/sw/pt/logout"
  - api: "POST external/api/sw/pt/notifyBonusEvent"
  - api: "POST external/api/sw/pt/bet"
  - api: "POST external/api/sw/pt/settlement"
  - api: "POST external/api/sw/pt/gameRoundResult"
  - api: "POST external/api/sw/pt/transfer"
  - api: "POST external/api/sw/pt/transferFunds"
  - class: 'App\Http\Controllers\External\SingleWallets\PlayTechController'
  - class: 'App\Vendors\PlayTechSW\GameRoundLock'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/PlayTechController.php
    title: PTSW 回調 controller
    at: 2026-10-22
  - kind: code
    ref: /app/Vendors/PlayTechSW/GameRoundLock.php
    title: 同局鎖，回調與補帳命令共用
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2133
    title: 取消投注前驗證原投注已扣款
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2141
    title: 拒絕同一筆投注的重複取消
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2143
    title: 被拒的取消投注留標記、派彩讀注單加鎖、同局鎖不分大小寫
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2159
    title: 派彩撞同局鎖逾時改回可重試的錯誤碼
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3079
    title: 未扣款的投注被取消後平白入帳
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3089
    title: 同一筆投注被不同交易碼重複取消
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3096
    title: 取消投注收尾修正
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3101
    title: 派彩撞同局鎖逾時後注單卡在進行中
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:41:16+08:00" }
---

## 這個主題在講什麼

PTSW 是遊戲商 PlayTech 以無縫錢包接入的版本，主要是真人遊戲與老虎機。玩家登入、查餘額、投注、派彩、取消投注、關局，以及遊戲商發獎金時，PlayTech 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把餘額回給 PlayTech。

名詞說明：

| 名詞 | 意思 |
|---|---|
| 局號 | PlayTech 回調帶的遊戲局代碼。PTSW 的真人遊戲允許同一局多次投注（例如 21 點分牌、保險，百家樂多手），所以一局可以對應多筆投注 |
| 交易碼 | PlayTech 為每一筆投注、派彩、取消投注給的代碼 |
| 對應投注的交易碼 | 取消投注回調裡指出「要取消哪一筆投注」的交易碼 |
| 請求序號 | 每次回調帶的序號，PlayTech 重送同一筆時序號不變 |
| 交易紀錄 | 我方為每次投注、派彩、取消投注、獎金回調存的一列紀錄，用來判斷重送 |
| 同局鎖 | 同一局、同一個玩家帳號的回調一次只處理一筆的鎖，等待上限 5 秒 |
| 外部令牌 | 玩家進遊戲時我方發給 PlayTech 的登入憑證，存在玩家帳號上 |

PlayTech 的派彩、取消投注、關局都打同一個派彩入口：帶「取消」類型的就是取消投注，帶關局資訊的就是關局。

本份目前收錄 PTSW 全部 12 個回調入口的行為。PTSW 的前置檢查（IP 白名單、玩家帳號解析、外部令牌）由它自己的入口處理，沒有走 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md) 的共用檢查，錯誤碼也不在那份的對照表，所以寫在本份。

## 怎麼觸發

沒有操作畫面。玩家在 PlayTech 遊戲內操作時，由 PlayTech 發回調觸發。QA 可以在 dev 站進入 PTSW 的遊戲下注來觸發。補帳命令 `bet-record-recovery:refund-unsettled` 也會對 PTSW 注單補做取消投注，它與回調共用同局鎖（R-26）。

## 規則

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時回請求序號與餘額，餘額是一般錢包加禮碼錢包的合計乘以匯率，四捨五入到小數點後 2 位；失敗時回錯誤物件，內含錯誤碼與說明。 | code:PlayTechController::response、code:PlayTechController::errorResponse、test:PtswBalanceTest::test_balance_success_returns_converted_balance |
| R-2 | 回調沒帶請求序號或玩家帳號時回 INTERNAL_ERROR。在正式環境，來源 IP 不在 PTSW 的 IP 白名單時回 INTERNAL_ERROR，訊息「IP not in whitelist」；白名單為空時不檢查。 | code:PlayTechController::callAction |
| R-3 | 玩家帳號不存在或尚未綁定會員時回 ERR_PLAYER_NOT_FOUND。玩家帳號所屬的遊戲商戶不存在或已停用時回 INTERNAL_ERROR。 | code:PlayTechController::callAction |
| R-4 | 登入驗證、查餘額、投注、保持連線、登出這五種回調，請求帶的外部令牌必須等於玩家帳號目前的外部令牌，否則回 ERR_AUTHENTICATION_FAILED。派彩（含取消投注、關局）與獎金入帳不比對外部令牌。 | code:PlayTechController::callAction、test:PtswAuthTest::test_auth_invalid_token_returns_err_authentication_failed、test:PtswBalanceTest::test_balance_invalid_token_returns_err_authentication_failed、test:PtswBetTest::test_bet_invalid_token_returns_err_authentication_failed |
| R-5 | 處理過程發生任何未預期的錯誤時回 INTERNAL_ERROR，說明「System internal error」。 | code:PlayTechController::callAction |

### 登入、查餘額與連線

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 登入驗證時，玩家帳號停用回 ERR_PLAYER_NOT_FOUND；通過時回玩家帳號、遊戲商戶的幣別與國家代碼。遊戲商戶配置有設 VIP 等級時，回應另外帶上該等級；沒設就不帶。 | code:PlayTechController::auth、test:PtswAuthTest::test_auth_success_without_vip_level_omits_sub_brand、test:PtswAuthTest::test_auth_success_with_vip_level_returns_sub_brand、test:PtswAuthTest::test_auth_inactive_account_returns_err_player_not_found |
| R-7 | 查餘額時，玩家帳號停用回 ERR_PLAYER_NOT_FOUND；否則照 R-1 回餘額。 | code:PlayTechController::balance、test:PtswBalanceTest::test_balance_success_returns_converted_balance、test:PtswBalanceTest::test_balance_inactive_account_returns_err_player_not_found |
| R-8 | 保持連線與獎金事件通知只回請求序號，不做任何處理。 | code:PlayTechController::keepAlive、code:PlayTechController::notifyBonusEvent、test:PtswKeepAliveTest::test_keep_alive_success_returns_request_id |
| R-9 | 登出時清掉玩家帳號的外部令牌；之後要比對外部令牌的回調都會回 ERR_AUTHENTICATION_FAILED，直到玩家重新進遊戲。 | code:PlayTechController::logout |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 投注時，局號以「live_」開頭的一律記為真人大廳遊戲 ubal，不看回調帶的個別遊戲桌代碼；其他照回調帶的遊戲代碼。遊戲代碼在我方不存在回 ERR_TRANSACTION_DECLINED，說明「The gameCodeName is not supported」；會員不具下注資格回 ERR_TRANSACTION_DECLINED，說明「Account could not bet yet」。兩者都不扣款。 | code:PlayTechController::bet、test:PtswBetTest::test_bet_unsupported_game_code_returns_err_transaction_declined、test:PtswLiveBetGameCodeTest::test_live_table_bet_with_individual_game_code_is_not_declined、test:PtswLiveBetGameCodeTest::test_slot_bet_still_passes |
| R-11 | 投注額（依匯率換算）超過一般錢包加禮碼錢包的合計時回 ERR_INSUFFICIENT_FUNDS，不扣款。 | code:PlayTechController::bet、test:PtswBetTest::test_bet_insufficient_funds_returns_error_and_does_not_deduct_balance |
| R-12 | 投注通過時，投注額從會員錢包扣除（禮碼先扣、一般錢包補足），注單狀態為進行中。同一局的多次投注累加到同一筆注單，投注與有效投注逐次加總。 | code:PlayTechController::bet、code:PlayTechController::firstOrNewBetRecord、test:PtswBetTest::test_bet_success_deducts_balance_and_creates_bet_record、test:PtswMultiBetTest::test_multi_bet_same_round_accumulates_bet_and_valid_bet |
| R-13 | 投注重送時（同局同請求序號，或同局同交易碼換了請求序號），回目前餘額與成功，不再扣款、不再累加注單。 | code:PlayTechController::findExistingDealRecord、code:PlayTechController::hasAppliedDealTransaction、test:PtswBetTest::test_bet_duplicate_request_id_is_idempotent、test:PtswDuplicateCallbackRaceTest::test_duplicate_transaction_code_does_not_double_accumulate |

### 同局鎖

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 同一局、同一個玩家帳號的投注與派彩回調一次只處理一筆，局號與玩家帳號比對不分大小寫。 | code:PlayTechController::runLockedDealAction、code:GameRoundLock::key、test:PtswDuplicateCallbackRaceTest::test_bet_acquires_and_releases_named_lock、test:PtswDuplicateCallbackRaceTest::test_settlement_acquires_named_lock、mr:!2143 |
| R-15 | 等同局鎖超過 5 秒時：投注回 ERR_TRANSACTION_DECLINED（PlayTech 文件定為不重試），派彩回 INTERNAL_ERROR（PlayTech 文件定為會重試）。兩者都不留交易紀錄、不建注單、不動錢包。 | code:PlayTechController::lockTimeoutErrorCode、test:PtswLockTimeoutErrorCodeTest::test_settlement_lock_timeout_returns_retryable_internal_error、test:PtswLockTimeoutErrorCodeTest::test_bet_lock_timeout_keeps_non_retryable_declined、test:PtswLockTimeoutErrorCodeTest::test_settlement_lock_timeout_logs_action_and_error_code、mr:!2159 |

### 派彩與關局

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-16 | 收到派彩時，派彩額（依匯率換算，扣掉其中的彩金部分）與彩金加入會員錢包，累加到該局注單的輸贏與彩金。只有派彩、沒帶關局資訊時，注單維持進行中、不建 TBR；同一局的多筆派彩逐筆累加。 | code:PlayTechController::settlement、code:PlayTechController::resolveSettlementAmounts、test:PtswProgressivePayoutSessionLogTest::test_consecutive_payouts_accumulate_into_single_transaction_log |
| R-17 | 回調帶關局資訊時，注單改為已結算，派彩時間取關局時間，並建立 TBR。玩家輸掉的局只送關局、不帶派彩，也照樣結算並建立 TBR。 | code:PlayTechController::resolveSettlementOutcome、test:PtswSettlementTest::test_settlement_with_pay_and_round_close_settles_and_pays_out、test:PtswSettlementNoPayTest::test_settlement_without_pay_writes_null_transaction_code_and_does_not_collide、test:PtswProgressivePayoutSessionLogTest::test_consecutive_payouts_accumulate_into_single_transaction_log |
| R-18 | 派彩或關局找不到該局該會員的注單時回 INTERNAL_ERROR，說明「bet record not found」，不動錢包、不建 TBR。 | code:PlayTechController::settlement、test:PtswSettlementTest::test_settlement_without_existing_bet_record_returns_internal_error |
| R-19 | 派彩、取消投注、關局的回調重送時（同局同請求序號，或同局同交易碼），回目前餘額與成功，不重複加款或退回投注額、不重複建 TBR。 | code:PlayTechController::findExistingDealRecord、test:PtswSettlementTest::test_settlement_duplicate_request_id_is_idempotent、test:PtswRefundDuplicateGuardTest::test_resending_applied_refund_transaction_code_stays_idempotent |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 收到取消投注時，把回調帶的取消金額（依匯率換算）退回會員錢包，並從注單的投注與有效投注扣回同一筆金額，注單改為已取消，不建 TBR。同一個回調若也帶關局資訊，注單改為已結算並建立 TBR。 | code:PlayTechController::settlement、code:PlayTechController::resolveSettlementOutcome、test:PtswSettlementTest::test_settlement_with_refund_reverts_bet_and_sets_refund_status |
| R-21 | 取消投注沒帶對應投注的交易碼時一律拒絕，回 ERR_NO_BET，不動錢包與注單；即使該局有已扣款的投注也一樣。 | code:PlayTechController::settlement、test:PtswRefundUnappliedBetTest::test_refund_without_related_code_and_no_applied_bet_does_not_credit_wallet、test:PtswRefundUnappliedBetTest::test_refund_without_related_code_is_rejected_even_if_bet_applied、mr:!2133 |
| R-22 | 取消投注指到的投注從未扣款時（例如投注因餘額不足被拒），回 ERR_NO_BET，不動錢包與注單；帶關局資訊時注單也維持進行中、不建 TBR。同一局有多筆投注時，只有已扣款的那幾筆能被取消。 | code:PlayTechController::hasAppliedBetForRefund、test:PtswRefundUnappliedBetTest::test_refund_for_unapplied_bet_does_not_credit_wallet、test:PtswRefundUnappliedBetTest::test_refund_in_multi_bet_round_only_refunds_applied_bet、test:PtswRefundUnappliedBetTest::test_refund_with_round_close_on_unapplied_bet_is_rejected、mr:!2133 |
| R-23 | 同一筆投注只能被取消一次。PlayTech 換一個交易碼再送同一筆投注的取消（對應投注的交易碼只差大小寫也算同一筆），或補帳命令已整局退過款，都回 ERR_NO_BET，不動錢包與注單；帶關局資訊時注單也維持已取消。同一局的其他投注仍可各被取消一次。 | code:PlayTechController::isBetAlreadyRefunded、test:PtswRefundDuplicateGuardTest::test_second_refund_with_new_transaction_code_is_rejected、test:PtswRefundDuplicateGuardTest::test_second_refund_with_case_variant_related_code_is_rejected、test:PtswRefundDuplicateGuardTest::test_second_refund_with_round_close_is_rejected_and_keeps_refund_status、test:PtswRefundDuplicateGuardTest::test_refund_after_recovery_command_refund_is_rejected、test:PtswRefundDuplicateGuardTest::test_refund_for_other_bet_after_recovery_refunded_whole_multi_bet_round_is_rejected、test:PtswRefundDuplicateGuardTest::test_multi_bet_round_allows_one_refund_per_bet_then_rejects_repeat、mr:!2141 |
| R-24 | 取消投注找不到該局該會員的注單時回 ERR_NO_BET，不動錢包。 | code:PlayTechController::settlement、test:PtswRefundRejectedMarkerTest::test_refund_rejected_for_missing_bet_record_marks_deal_record |
| R-25 | 被拒的取消投注會在交易紀錄留下被拒標記。帶被拒標記的紀錄不算「已取消」：同一筆投注之後合法的取消仍會放行，注單累計對帳命令也不把它當成已生效的取消。被拒的取消投注重送（同請求序號或同交易碼）回成功，錢包不動、標記保留。PlayTech 回調內容自帶同名標記時一律剝除，不影響判斷。 | code:PlayTechController::rejectRefund、code:PlayTechController::createDealRecord、command:playtech:reconcile-bet-accumulation、test:PtswRefundRejectedMarkerTest::test_refund_rejected_for_missing_related_code_marks_deal_record、test:PtswRefundRejectedMarkerTest::test_refund_rejected_for_unapplied_bet_marks_deal_record、test:PtswRefundRejectedMarkerTest::test_duplicate_refund_marks_only_the_rejected_event、test:PtswRefundRejectedMarkerTest::test_vendor_payload_cannot_forge_rejected_marker、test:PtswRefundRejectedMarkerTest::test_rejected_refund_replay_keeps_marker_and_returns_success、test:PtswRefundRejectedMarkerTest::test_rejected_refund_event_does_not_count_as_refunded_in_duplicate_guard、test:PtswRefundUnappliedBetTest::test_rejected_refund_replay_does_not_change_wallet、test:ReconcileBetAccumulationTest::test_rejected_refund_event_is_excluded_from_expected_bet、mr:!2143 |
| R-26 | 回調的取消投注與補帳命令的取消投注共用同一把同局鎖；回調處理同局時，補帳命令不會退回投注額，避免兩邊各退一次。 | code:GameRoundLock::synchronize、command:bet-record-recovery:refund-unsettled、test:RefundUnsettledPtswTest::test_does_not_refund_while_callback_holds_the_game_round_lock、mr:!2141 |

### 獎金入帳

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-27 | 收到獎金入帳（不帶局號）時，金額依匯率換算後加入會員錢包，建立一筆已結算、沒有局號的注單與 TBR。同請求序號與同交易碼的重送回目前餘額與成功，不重複加款。 | code:PlayTechController::transferFunds、test:PtswTransferFundsTest::test_transfer_funds_success_credits_balance_and_creates_bet_record、test:PtswTransferFundsTest::test_transfer_funds_duplicate_request_id_is_idempotent |

## 還沒確認的事

- 投注因餘額不足被拒時，仍會留下一筆金額 0、狀態進行中的注單與一筆未生效的交易紀錄。MR !2133 註明另案處理，目前是在對帳盤點時排除（[op_tickets#3079](http://example.invalid:1688/target/op_tickets/-/work_items/3079)）。
- 已取消的局再收到只帶關局資訊的回調時，注單會從已取消改成已結算並建立 TBR。MR !2143 註明已登記給 PM 另案拍板（[op_tickets#3089](http://example.invalid:1688/target/op_tickets/-/work_items/3089)）。
