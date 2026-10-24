---
type: Rule
id: rules/vendor-epicwin
title: EpicWin 無縫錢包回調
description: EpicWin 發回調給我方時，查餘額、投注、派彩、取消投注、現金獎金與彩金各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, EpicWin, EpicSW, 會員, callback, seamless wallet, single wallet]
entities:
  - api: "POST external/api/sw/epic/GetBalance"
  - api: "POST external/api/sw/epic/Bet"
  - api: "POST external/api/sw/epic/GameResult"
  - api: "POST external/api/sw/epic/Rollback"
  - api: "POST external/api/sw/epic/CashBonus"
  - api: "POST external/api/sw/epic/Jackpot"
  - class: 'App\Http\Controllers\External\SingleWallets\EpicWinController'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/EpicWinController.php
    title: EpicWin 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2414
    title: 已結算注單再收到派彩補送時保留首次結算時間
  - kind: ticket
    ref: http://example.invalid:1688/target/ticket_tasks/-/work_items/1709
    title: 跨午夜補送造成對帳日差
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:41:16+08:00" }
---

## 這個主題在講什麼

EpicWin 以無縫錢包接入。玩家查餘額、投注、局結束派彩、投注被取消，以及遊戲商發現金獎金（CashBonus）或彩金時，EpicWin 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把餘額回給 EpicWin。

EpicWin 的派彩回調帶的是整局的最終結果（投注、有效投注、輸贏），不是增量；同一筆投注的結果可能被補送或調整，我方以「新結果減舊結果」的差額調整錢包。

本份目前收錄 EpicWin 全部 6 個回調入口的行為。EpicWin 的前置檢查（IP 白名單、玩家帳號解析、簽章、連線憑證）由它自己的入口處理。連線憑證（token）是玩家登入遊戲時拿到、EpicWin 回調時帶回來給我方比對的字串。這些檢查沒有走 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md) 的共用檢查，錯誤碼也不在那份的對照表，所以寫在本份。

## 怎麼觸發

沒有操作畫面。玩家在 EpicWin 遊戲內操作時，由 EpicWin 發回調觸發。QA 可以在 dev 站進入 EpicWin 的遊戲下注來觸發。

## 規則

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200，成功時狀態碼為 200。查餘額回餘額；投注、派彩、取消投注、現金獎金、彩金回處理前與處理後的餘額。回給遊戲商的餘額都是一般錢包加禮碼錢包的合計乘以匯率。 | code:EpicWinController::balanceResponse、code:EpicWinController::response、test:EpicWinSWGetBalanceTest::test_get_balance_ok |
| R-2 | 回調依序檢查，任一不過就擋下、不動錢包、不寫注單：在正式環境來源 IP 不在 EpicWin 的 IP 白名單回 900500（白名單為空時不檢查）；玩家帳號不存在或尚未綁定會員回 900404；回調帶的遊戲商戶代碼對不上玩家帳號所屬、且啟用中的遊戲商戶回 900405；簽章不符或遊戲商戶沒設密鑰回 900407。 | code:EpicWinController::callAction、code:EpicWinController::checkSign、test:EpicWinSWGetBalanceTest::test_get_balance_invalid_player、test:EpicWinSWGetBalanceTest::test_get_balance_invalid_operator、test:EpicWinSWGetBalanceTest::test_get_balance_invalid_signature、test:EpicWinSWBetTest::test_bet_invalid_signature、test:EpicWinSWGameResultTest::test_settle_invalid_signature、test:EpicWinSWRollbackTest::test_rollback_invalid_signature、test:EpicWinSWCashBonusTest::test_cash_bonus_invalid_signature、test:EpicWinSWJackpotTest::test_jackpot_invalid_signature |
| R-3 | 回調有帶連線憑證時，必須等於玩家帳號目前的連線憑證，否則回 900404，不動錢包、不寫注單；玩家帳號有連線憑證時，帶空字串也算不符。沒帶連線憑證時不檢查。 | code:EpicWinController::checkAuthToken、test:EpicWinSWGameResultTest::test_settle_with_stale_authtoken_is_rejected、test:EpicWinSWGameResultTest::test_settle_with_empty_authtoken_is_rejected_when_session_token_set、test:EpicWinSWGameResultTest::test_settle_without_authtoken_passes_token_gate、test:EpicWinSWCashBonusTest::test_cash_bonus_with_stale_authtoken_is_rejected_and_no_credit、test:EpicWinSWCashBonusTest::test_cash_bonus_with_matching_authtoken_passes、test:EpicWinSWCashBonusTest::test_cash_bonus_without_authtoken_passes |
| R-4 | 處理過程發生任何未預期的錯誤時回 900500。 | code:EpicWinController::callAction |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 查餘額時，玩家帳號停用回 900404；否則照 R-1 回餘額。 | code:EpicWinController::getBalance、test:EpicWinSWGetBalanceTest::test_get_balance_ok、test:EpicWinSWGetBalanceTest::test_get_balance_inactive_account |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 投注時依序檢查，任一不過就擋下、不扣款、不建注單：玩家帳號停用回 900404；會員不具下注資格回 900416；一般錢包加禮碼錢包不足以支付投注額回 900605；同一個投注編號已存在回 900409。 | code:EpicWinController::bet、test:EpicWinSWBetTest::test_bet_insufficient_balance、test:EpicWinSWBetTest::test_bet_duplicate_transaction |
| R-7 | 投注通過時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、一般錢包補足），建一筆未結算的注單（投注與有效投注都是投注額、輸贏是負的投注額），並建立 TBR。 | code:EpicWinController::bet、test:EpicWinSWBetTest::test_bet_ok_deducts_balance_and_creates_records |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 收到派彩時，依投注編號找原注單；找不到回 900415，不動錢包。 | code:EpicWinController::settle、test:EpicWinSWGameResultTest::test_settle_bet_transaction_not_found |
| R-9 | 派彩通過時，注單的投注、有效投注、輸贏改成回調帶的值（依匯率換算），注單改為已結算。錢包依新舊輸贏的差額調整，並建立或更新該局 TBR。 | code:EpicWinController::settle、test:EpicWinSWGameResultTest::test_settle_after_bet_applies_win_lose_and_settles_record |
| R-10 | 注單已結算後再收到派彩補送時，派彩時間維持首次結算的時間，不改成補送當下。補送內容與首次相同時，錢包與注單都不變；遊戲商調整金額時，金額與錢包照新結果依差額更新，注單仍歸在首次結算那天。 | code:EpicWinController::settle、test:EpicWinSWGameResultTest::test_resending_settle_is_idempotent、test:EpicWinSWGameResultTest::test_resent_result_after_midnight_keeps_original_payout_time、test:EpicWinSWGameResultTest::test_resent_result_with_adjusted_amount_updates_amount_but_keeps_payout_time、mr:!2414 |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 收到取消投注時，依投注編號找原注單；找不到回 900415，不動錢包。 | code:EpicWinController::rollback、test:EpicWinSWRollbackTest::test_rollback_bet_transaction_not_found |
| R-12 | 取消投注通過時，注單的投注、有效投注、輸贏都歸 0，注單改回未結算，派彩時間記為取消的時間。錢包依差額調整：未派彩的投注退回投注額；已派彩的投注連同派彩一起沖回。重送同一筆取消投注時差額為 0，不重複退回投注額、不新增 TBR。 | code:EpicWinController::rollback、test:EpicWinSWRollbackTest::test_rollback_after_bet_refunds_balance、test:EpicWinSWRollbackTest::test_resending_rollback_is_idempotent |

### 現金獎金與彩金

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 收到現金獎金時，依匯率換算的金額加入會員錢包，建一筆已結算、遊戲代碼為「CashBonus」的注單，並建立 TBR。同一個交易編號已存在時回 900409，不重複加款。 | code:EpicWinController::bonus、test:EpicWinSWCashBonusTest::test_cash_bonus_credits_balance_and_creates_records、test:EpicWinSWCashBonusTest::test_cash_bonus_duplicate_transaction |
| R-14 | 收到彩金時，依匯率換算的金額加入會員錢包，建一筆已結算、遊戲代碼為「Jackpot」的注單，並建立 TBR。同一個交易編號已存在時回 900409，不重複加款。 | code:EpicWinController::jackpot、test:EpicWinSWJackpotTest::test_jackpot_credits_balance_and_creates_records、test:EpicWinSWJackpotTest::test_jackpot_duplicate_transaction |

## 還沒確認的事

- 已被移到隔天的歷史注單不會自動改回首次結算那天；首次結算很多天後才調整的金額，因為 SBR（TBR 依每人每小時每款遊戲聚合後的結算數字）只重跑前一整天，仍可能落回原日期。MR !2414 註明目前補送都在首次結算後 1 小時內，實際碰不到（[ticket_tasks#1709](http://example.invalid:1688/target/ticket_tasks/-/work_items/1709)）。
