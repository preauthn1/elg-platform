---
type: Rule
id: rules/vendor-astar
title: Astar 無縫錢包回調
description: Astar 發回調給我方時，驗證玩家、查餘額、上桌扣款、離桌入帳、取消投注、查細單與查未完成局各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, Astar, ASTAR, 棋牌, 真人, 會員, callback, seamless wallet, single wallet]
entities:
  - api: "POST external/api/sw/astar/gamepool/{gamehall}/player/auth"
  - api: "GET external/api/sw/astar/gamepool/{gamehall}/player/balance/{id}"
  - api: "POST external/api/sw/astar/gamepool/{gamehall}/player/logout"
  - api: "POST external/api/sw/astar/gamepool/{gamehall}/table/rollout"
  - api: "POST external/api/sw/astar/gamepool/{gamehall}/table/rollin"
  - api: "POST external/api/sw/astar/gamepool/{gamehall}/game/bet/refund"
  - api: "POST external/api/sw/astar/gamepool/{gamehall}/game/detailtoken"
  - api: "GET external/api/sw/astar/gamepool/{gamehall}/game/roundcheck"
  - class: 'App\Http\Controllers\External\SingleWallets\AstarController'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/AstarController.php
    title: Astar 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2340
    title: 離桌入帳改以帶出金額計算，修正棋牌局多還一次帶入金額
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2121
    title: 離桌入帳與取消投注改由注單反查玩家帳號
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4432
    title: 棋牌局每局多還一次帶入金額
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3068
    title: 連線憑證輪換後離桌入帳與取消投注永遠失敗
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:41:16+08:00" }
---

## 這個主題在講什麼

Astar 以無縫錢包接入，提供真人與棋牌遊戲。玩家坐上遊戲桌時，Astar 先發「上桌扣款」回調，從會員錢包扣走帶進桌的金額；玩家離桌或一局結束時，再發「離桌入帳」回調，把帶出桌的金額加回錢包。Astar 另外會發驗證玩家、查餘額、登出、取消投注、查細單與查未完成局的回調。

名詞說明：

| 名詞 | 意思 |
|---|---|
| 遊戲廳代碼 | 回調路徑（Astar 發回調時打的完整網址）裡的一段代碼，對應遊戲商戶配置裡的遊戲廳代碼，用來分辨是哪一個遊戲商戶 |
| 遊戲商戶授權碼 | 遊戲商戶配置裡的授權字串（CS token）。Astar 每次回調都放在請求標頭（header）裡，必須等於遊戲商戶配置裡的值 |
| 連線憑證 | 玩家每次進遊戲時我方重新發給 Astar 的憑證（gametoken），Astar 回調時帶回來讓我方比對是哪個玩家帳號；同一個玩家帳號進一次遊戲就換一次 |
| 交易代碼 | 回調帶的三段式代碼，格式是「環境-動作-局號」，例如 `rel-rollin-VW1194683621` |
| 帶入金額 | 上桌扣款時從錢包扣走、帶進遊戲桌的金額 |
| 帶出金額 | 離桌入帳時 Astar 回報玩家帶出遊戲桌的金額 |

本份目前收錄 Astar 全部 8 個回調入口的行為。Astar 的前置檢查（IP 白名單、遊戲商戶授權、玩家帳號解析）由它自己的入口處理，沒有走 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md) 的共用檢查，錯誤碼也不在那份的對照表，所以寫在本份。

## 怎麼觸發

沒有操作畫面。玩家在 Astar 遊戲內上桌、離桌或取消投注時，由 Astar 發回調觸發。QA 可以在 dev 站進入 Astar 的遊戲來觸發。

## 規則

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時回應碼是「0」、訊息是「success」；失敗時回應碼填對應錯誤碼，訊息填錯誤原因。回應時間一律以 UTC-4 表示。 | code:AstarController::getResponseArray |
| R-2 | 在正式環境，來源 IP 不在 Astar 的 IP 白名單時，任何回調都回錯誤碼 5，不動錢包、不寫注單。白名單為空時不檢查。 | code:AstarController::callAction |
| R-3 | 回調路徑的遊戲廳代碼或請求標頭的遊戲商戶授權碼對不上時，回錯誤碼 4，不動錢包、不寫注單。遊戲商戶已停用時，以局號定位的離桌入帳與取消投注回錯誤碼 4；以玩家帳號定位的回調照 R-4 回錯誤碼 5。 | code:AstarController::verifyVendorInfoAndMember、code:AstarController::authorizeGamehall、test:AstarAuthenticateTest::test_authenticate_failed_with_wrong_token |
| R-4 | 驗證玩家、上桌扣款、查細單用請求帶的連線憑證找玩家帳號；查餘額用網址帶的玩家帳號找。找不到已綁定會員的玩家帳號時回錯誤碼 2。玩家帳號所屬的遊戲商戶已停用，或玩家帳號對不到會員時，回錯誤碼 5。 | code:AstarController::loadAccountAndVerify、code:AstarController::verifyVendorInfoAndMember |
| R-5 | 驗證玩家時，玩家帳號已停用就回錯誤碼 2；通過時回玩家帳號與商戶幣別。 | code:AstarController::authenticate、test:AstarAuthenticateTest::test_authenticate_success |

### 查餘額與登出

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 查餘額時，玩家帳號停用回錯誤碼 2；會員停用或被系統鎖定回錯誤碼 6，訊息「Member is frozen」；會員在鎖分模式下不能對 Astar 下注時也回錯誤碼 6，訊息「Account could not bet yet」。這點與共用檢查「停用時查餘額照回」不同。 | code:AstarController::balance |
| R-7 | 查餘額通過時，回一般錢包加禮碼錢包的合計再乘以匯率。 | code:AstarController::balance、test:AstarBalanceTest::test_balance_success |
| R-8 | 登出時，清掉持有該連線憑證的玩家帳號的連線憑證，一律回成功。登出不檢查遊戲商戶授權碼。 | code:AstarController::logout、test:AstarLogoutTest::test_logout_success |

### 上桌扣款

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-9 | 上桌扣款時依序檢查，任一不過就擋下、不扣款、不建注單：會員不具下注資格回錯誤碼 6；遊戲代碼在我方不存在回錯誤碼 8；交易代碼不是「環境-rollout-局號」三段式或局號對不上回錯誤碼 7；一般錢包加禮碼錢包不足以支付帶入金額回錯誤碼 1。 | code:AstarController::rollout、code:AstarController::validateMtCode |
| R-10 | 上桌扣款通過時，依匯率換算的帶入金額從會員錢包扣除（禮碼先扣、一般錢包補足），建一筆尚未結算的注單：投注與有效投注都是帶入金額、輸贏是負的帶入金額，並回扣款後的餘額乘以匯率。 | code:AstarController::rollout、test:AstarRolloutTest::test_rollout_success |
| R-11 | 同一個局號的上桌扣款重複送達時回錯誤碼 13，不再扣款。這項比對發生在建注單那一步，排在餘額檢查之後，所以餘額已不足的重送會先回錯誤碼 1。 | code:AstarController::rollout |

### 離桌入帳

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-12 | 離桌入帳的交易代碼必須是「環境-rollin-局號」三段式，且局號與請求的局號相同，否則回錯誤碼 7，不動錢包。 | code:AstarController::callAction、code:AstarController::validateMtCode |
| R-13 | 離桌入帳先驗遊戲廳代碼與遊戲商戶授權碼（不符回錯誤碼 4），再依局號找尚未結算的注單。局號不存在、已入帳或已取消時都回錯誤碼 34，不動錢包；所以同一局重送離桌入帳時只會入帳一次。 | code:AstarController::loadSettlementContext、code:AstarController::findRolloutRecord、test:AstarSettlementAfterTokenRotationTest::test_rollin_unknown_round_returns_order_not_found_even_when_token_rotated、test:AstarSettlementAfterTokenRotationTest::test_rollin_with_wrong_cs_token_returns_token_invalid_before_round_lookup、mr:!2121 |
| R-14 | 離桌入帳的玩家帳號由注單反查，不用玩家帳號目前的連線憑證，所以玩家換過連線憑證後，Astar 重送的離桌入帳仍會成功。由舊系統寫入、帶舊格式帳號的注單同樣能反查。 | code:AstarController::loadAccountForSettlement、test:AstarSettlementAfterTokenRotationTest::test_rollin_retry_with_original_gametoken_succeeds_after_account_token_rotated、test:AstarSettlementAfterTokenRotationTest::test_rollin_with_account_current_token_still_succeeds_after_rotation、test:AstarSettlementAfterTokenRotationTest::test_rollin_settles_legacy_bet_record_keyed_by_account_vendor_username、mr:!2121 |
| R-15 | 離桌入帳或取消投注有帶玩家帳號時，該帳號必須是注單所屬的玩家帳號，否則回錯誤碼 2。有帶連線憑證時，必須等於下注當時的連線憑證或玩家帳號目前的連線憑證，兩者都不符回錯誤碼 9。兩種情況都不動錢包。 | code:AstarController::loadAccountForSettlement、test:AstarSettlementAfterTokenRotationTest::test_rollin_rejects_player_id_not_matching_bet_record、test:AstarSettlementAfterTokenRotationTest::test_rollin_rejects_gametoken_matching_neither_bet_record_nor_account、mr:!2121 |
| R-16 | 離桌入帳時，錢包一律加回 Astar 回報的帶出金額（依匯率換算）。注單輸贏等於帶出金額減帶入金額，注單改為已入帳，並建立 TBR，回入帳後的餘額乘以匯率。 | code:AstarController::rollin、test:AstarRollinTest::test_rollin_success、test:AstarRollinSettleAmountTest::test_board_card_recorded_losing_round_credits_only_settle_amount、mr:!2340 |
| R-17 | 棋牌桌（桌名以 `BOARDCARD#` 開頭）的離桌入帳，注單的投注與有效投注都記成輸贏的絕對值；平手離桌時投注、有效投注、輸贏都是 0。 | code:AstarController::rollin、code:AstarController::isBoardCardTable、test:AstarRollinSettleAmountTest::test_board_card_recorded_losing_round_credits_only_settle_amount、test:AstarRollinSettleAmountTest::test_board_card_recorded_winning_round_records_net_win、test:AstarRollinSettleAmountTest::test_board_card_recorded_even_round_records_zero、mr:!2340 |
| R-18 | 真人遊戲（非棋牌桌）的離桌入帳，有效投注照 Astar 回報的有效投注（依匯率換算）。帶出金額不等於「帶入金額加贏額減抽水減房費」時，仍照帶出金額入帳，另記一筆警告紀錄供人工核對。 | code:AstarController::rollin、code:AstarController::warnIfSettleAmountMismatch、test:AstarRollinSettleAmountTest::test_live_game_rollin_keeps_vendor_valid_bet_and_net_win_lose、test:AstarRollinSettleAmountTest::test_live_game_rollin_logs_warning_when_settle_amount_mismatches_formula、mr:!2340 |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-19 | 取消投注依交易代碼找局號：先把整串交易代碼當局號比對，找不到且交易代碼是三段式時，再取第三段當局號。交易代碼為空回錯誤碼 7；請求另外帶了局號、但不是這兩個候選之一時也回錯誤碼 7。 | code:AstarController::resolveRefundRoundCandidates、test:AstarSettlementAfterTokenRotationTest::test_refund_with_only_mtcode_as_round_string_succeeds_after_account_token_rotated、test:AstarSettlementAfterTokenRotationTest::test_refund_with_rollout_style_mtcode_succeeds、test:AstarSettlementAfterTokenRotationTest::test_refund_prefers_exact_mtcode_match_over_third_segment |
| R-20 | 取消投注先驗遊戲廳代碼與遊戲商戶授權碼，對不上啟用中的遊戲商戶就回錯誤碼 4，不動錢包；遊戲商戶已停用時也回錯誤碼 4。玩家帳號由注單反查，不需要連線憑證。 | code:AstarController::loadSettlementContext、code:AstarController::authorizeGamehall、test:AstarSettlementAfterTokenRotationTest::test_refund_from_other_gamehall_with_its_own_cs_token_cannot_touch_this_round、test:AstarSettlementAfterTokenRotationTest::test_refund_when_vendor_info_disabled_returns_token_invalid、mr:!2121 |
| R-21 | 取消投注通過時，把帶入金額退回會員錢包（原本扣的禮碼退回禮碼錢包），注單的投注、有效投注、輸贏都歸 0，注單改為已取消，建立 TBR，並回退回投注額之後的餘額乘以匯率。 | code:AstarController::refund、test:AstarRefundTest::test_refund_success、test:AstarSettlementAfterTokenRotationTest::test_refund_with_only_mtcode_as_round_string_succeeds_after_account_token_rotated |
| R-22 | 取消投注找不到尚未結算的注單時（局號不存在、已入帳或已取消）回錯誤碼 34，不動錢包；所以同一局重送取消投注只會退一次。 | code:AstarController::findRolloutRecord、test:AstarSettlementAfterTokenRotationTest::test_refund_unknown_round_returns_order_not_found、test:AstarSettlementAfterTokenRotationTest::test_refund_is_idempotent_for_already_refunded_round |

### 查細單與查未完成局

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 查細單時，依請求帶的細單憑證找出該玩家帳號的注單，回局號與玩家帳號。 | code:AstarController::detailtoken、test:AstarDetailTokenTest::test_detailtoken_success |
| R-24 | 查未完成局時，回指定時間區間內、遊戲代碼以該遊戲廳代碼開頭、尚未入帳也未取消的所有局號。查未完成局不檢查遊戲商戶授權碼。 | code:AstarController::roundcheck、test:AstarRoundCheckTest::test_roundcheck_success |

## Examples

以下取自 R-16、R-17 的實際局（帳號與金額照正式站紀錄重跑）：

| 情境 | 帶入金額 | 帶出金額 | 錢包加回 | 注單投注 | 注單有效投注 | 注單輸贏 | 依據 |
|---|---|---|---|---|---|---|---|
| 棋牌局輸錢離桌 | 168.60 | 128.60 | 128.60 | 40 | 40 | -40 | R-16、R-17 |
| 棋牌局贏錢離桌 | 126.10 | 145.10 | 145.10 | 19 | 19 | 19 | R-16、R-17 |
| 棋牌局原額離桌 | 130.80 | 130.80 | 130.80 | 0 | 0 | 0 | R-17 |
| 真人百家樂押 40 輸掉 | 40.00 | 0.00 | 0 | 40 | 40 | -40 | R-16、R-18 |

## 還沒確認的事

- 玩家帳號已停用時，離桌入帳與取消投注仍會更新注單狀態，但錢包不加款。這是無縫錢包共用扣帳流程的行為，MR !2121 列為範圍外，尚未拍板（[op_tickets#3068](http://example.invalid:1688/target/op_tickets/-/work_items/3068)）。
- 查未完成局與登出不驗遊戲商戶授權碼；上桌扣款沒有比對請求帶的玩家帳號與連線憑證所屬帳號是否同一人。三項都由 MR !2121 列為範圍外，尚未決定要不要補（[op_tickets#3068](http://example.invalid:1688/target/op_tickets/-/work_items/3068)）。
- 捕魚遊戲的離桌入帳沒有特別處理；有效投注可能是負值並觸發警告紀錄。MR !2340 註明開放捕魚前要另外處理（[tickets#4432](http://example.invalid:1688/target/tickets/-/work_items/4432)）。
