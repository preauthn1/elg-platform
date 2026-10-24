---
type: Rule
id: rules/vendor-uuslotsw
title: UUSlotSW 無縫錢包回調
description: UUSlotSW 發回調給我方時，查餘額、投注、派彩、取消投注、活動獎金、彩金各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, UUSlotSW, UUSlot, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:51+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/UUSlotSWController.php, title: UUSlotSW 回調 controller, at: 2026-10-22 }
  - { kind: code, ref: /app/Helpers/BetHelper.php, title: 下注資格檢查 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2276, title: callAction 改用共用帳號組裝，下注在鎖會員後驗資格 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4375, title: 無縫錢包下注資格改走共用檢查 }
entities:
  - { api: "POST external/api/sw/uuslot/GetBalance" }
  - { api: "POST external/api/sw/uuslot/Bet" }
  - { api: "POST external/api/sw/uuslot/GameResult" }
  - { api: "POST external/api/sw/uuslot/Rollback" }
  - { api: "POST external/api/sw/uuslot/CashBonus" }
  - { api: "POST external/api/sw/uuslot/Jackpot" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\UUSlotSWController" }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

UUSlotSW 是以無縫錢包接入的遊戲商。玩家查餘額、投注、派彩、被取消投注、領活動獎金或彩金時，UUSlotSW 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。

本份目前收錄：六個回調入口的前置檢查、回應格式、各回調的處理與重複請求的回應。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 UUSlotSW 的遊戲下注來觸發。

## 規則

下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)。UUSlotSW 只用了共用的帳號組裝，IP 白名單、簽章、停用與下注資格由 UUSlotSW 自己檢查；實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為 200；查餘額回目前餘額，其他回調回異動前與異動後的餘額，餘額一律乘以匯率。失敗時填對應錯誤碼，餘額欄位都填 0。 | code:UUSlotSWController::transferResponse、code:UUSlotSWController::errorResponse |
| R-2 | 在正式環境，來源 IP 不在 UUSlotSW 遊戲商層級的 IP 白名單時，回錯誤碼 900406，訊息「IP <來源 IP> not allow」。白名單為空時不檢查。 | code:UUSlotSWController::callAction |
| R-3 | 玩家帳號在我方不存在，或是尚未綁定會員的池帳號，回錯誤碼 900404，訊息「Account not found」。帳號對應的會員不存在時，回錯誤碼 900404，訊息「Account inactive」。 | code:UUSlotSWController::callAction、test:UUSlotSWGetBalanceTest::test_post_get_balance_failed_wrong_player、test:UUSlotSWGetBalanceTest::test_post_get_balance_failed_inactive_player、mr:!2276 |
| R-4 | 玩家帳號停用時，所有回調（包括查餘額、派彩、取消投注）都回錯誤碼 900404，訊息「Account not found」，不動錢包。 | code:UUSlotSWController::callAction、test:UUSlotSWBettabilityTest::test_disabled_account_keeps_legacy_response |
| R-5 | 玩家帳號所屬的遊戲商戶不存在時回錯誤碼 900405，訊息「Merchant Not Found」；遊戲商戶停用時回錯誤碼 900405，訊息「Merchant suspend」；回調帶的商戶代碼與遊戲商戶設定不符時回錯誤碼 900405，訊息「Operator ID Error」。三種情況對所有回調都適用。 | code:UUSlotSWController::callAction |
| R-6 | 簽章與我方用遊戲商戶金鑰算出的值不符，或遊戲商戶沒有設定金鑰時，回錯誤碼 900407，訊息「Invalid Signature」，不動錢包。 | code:UUSlotSWController::isSignatureValid、test:UUSlotSWGameResultTest::test_post_game_result_failed_invalid_signature、test:UUSlotSWRollbackTest::test_post_rollback_failed_invalid_signature、test:UUSlotSWCashBonusTest::test_post_cash_bonus_failed_invalid_signature、test:UUSlotSWJackpotTest::test_post_jackpot_failed_invalid_signature、test:UUSlotSWGetBalanceTest::test_post_get_balance_failed_invalid_signature |
| R-7 | 處理中發生非預期錯誤時，回錯誤碼 900500。 | code:UUSlotSWController::callAction |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 查餘額時，回會員真金加禮碼的合計再乘以匯率。 | code:UUSlotSWController::getBalance |
| R-9 | 查餘額只檢查鎖分模式（會員參與活動期間，錢包之間的餘額轉移受到限制的狀態），不做完整的下注資格檢查。會員在鎖分模式不允許下注時，回錯誤碼 900416，訊息「Account could not bet yet」。 | code:UUSlotSWController::callAction、mr:!2276 |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | 收到投注回調時，投注額除以匯率換成平台金額，從會員錢包扣除（禮碼先扣、真金補足），產生一筆投注狀態的注單。投注當下不建 TBR。 | code:UUSlotSWController::bet、test:UUSlotSWBetTest::test_post_bet_ok_wallet_only、test:UUSlotSWBetTest::test_post_bet_ok_wallet_only_no_rolling_chip_wallet、test:UUSlotSWBetTest::test_post_bet_ok_rolling_chips_only |
| R-11 | 投注在鎖住會員之後才檢查下注資格；會員不具下注資格（例如會員被系統鎖定）時，回錯誤碼 900416，訊息「Account could not bet yet」，不建注單、不扣款。 | code:UUSlotSWController::bet、test:UUSlotSWBettabilityTest::test_bet_is_rejected_when_member_is_locked、test:UUSlotSWBettabilityTest::test_bettability_is_verified_after_member_lock、mr:!2276 |
| R-12 | 下注資格中的「遊戲能不能下注」，只看遊戲商戶的遊戲是否標為可下注；不看遊戲本身的狀態、遊戲商戶遊戲的狀態與代理的遊戲設定。 | code:BetHelper::assertMemberAbleToBet、mr:!2276 |
| R-13 | 投注時會員真金加禮碼不足以支付投注額，回錯誤碼 900605，不建注單、不扣款。 | code:UUSlotSWController::bet |
| R-14 | 同一個注單編號的投注重複送達時，回錯誤碼 900409，不再扣款、不再建注單。 | code:UUSlotSWController::bet |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 收到派彩回調時，依注單編號找到投注狀態的原注單，記下這次的派彩編號，以回調帶來的有效投注、派彩額、輸贏更新注單並改為已派彩，會員錢包依前後差額增減，並建立該局 TBR。 | code:UUSlotSWController::gameResult、test:UUSlotSWGameResultTest::test_post_game_result_ok |
| R-16 | 同一個派彩編號重複送達時，回錯誤碼 900409，不重複加款。 | code:UUSlotSWController::gameResult、test:UUSlotSWGameResultTest::test_post_game_result_failed_duplicate_bet_case |
| R-17 | 派彩回調的注單編號找不到投注狀態的原注單時（不存在、已派彩或已取消），回錯誤碼 900415，不動錢包。 | code:UUSlotSWController::gameResult、test:UUSlotSWGameResultTest::test_post_game_result_failed_no_bet_transaction |
| R-18 | 會員被系統鎖定時，派彩照常完成，不因鎖定而中斷。 | code:UUSlotSWController::gameResult、test:UUSlotSWBettabilityTest::test_game_result_still_settles_when_member_is_locked |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-19 | 收到取消投注回調時，依注單編號找到原注單，把投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），注單改為已取消，注單上保留原本的投注額與輸贏。取消投注不建 TBR。 | code:UUSlotSWController::rollback、test:UUSlotSWRollbackTest::test_post_rollback_ok_wallet_only、test:UUSlotSWRollbackTest::test_post_rollback_ok_wallet_with_rolling_chip |
| R-20 | 取消投注回調的注單編號找不到原注單時，回錯誤碼 900415；注單已取消過時回錯誤碼 900409。兩種情況都不動錢包。 | code:UUSlotSWController::rollback、test:UUSlotSWRollbackTest::test_post_rollback_failed_no_bet_transaction、test:UUSlotSWRollbackTest::test_post_rollback_failed_duplicate_bet_case |
| R-21 | 會員被系統鎖定時，取消投注照常退回投注額，不因鎖定而卡住本金。 | code:UUSlotSWController::rollback、test:UUSlotSWBettabilityTest::test_rollback_still_refunds_when_member_is_locked |

### 活動獎金與彩金

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-22 | 收到活動獎金回調時不需要原注單，以回調的交易編號另建一筆活動獎金注單，金額除以匯率後加入會員錢包，並建立 TBR，金額記在 TBR 的活動獎金。 | code:UUSlotSWController::cashBonus、test:UUSlotSWCashBonusTest::test_post_cash_bonus_ok |
| R-23 | 收到彩金回調時不需要原注單，以回調的交易編號另建一筆彩金注單，金額除以匯率後加入會員錢包，並建立 TBR，金額記在 TBR 的彩金。 | code:UUSlotSWController::jackpot、test:UUSlotSWJackpotTest::test_post_jackpot_ok |
| R-24 | 同一個交易編號的活動獎金或彩金重複送達時，回錯誤碼 900409，不重複加款。 | code:UUSlotSWController::cashBonus、code:UUSlotSWController::jackpot、test:UUSlotSWCashBonusTest::test_post_cash_bonus_failed_duplicate_bet_case、test:UUSlotSWJackpotTest::test_post_jackpot_failed_duplicate_bet_case |

## 還沒確認的事

- 注單已派彩後，再收到同一筆的取消投注回調時，程式只擋「已取消」不擋「已派彩」，會退回投注額但不收回派彩，並把注單改為已取消。這是否為遊戲商協定允許的情境，待 RD 依三方文件確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- 下注資格的「遊戲能不能下注」只看遊戲商戶的遊戲是否標為可下注（R-12）。2026-09-22 改版時，這個放寬讓 VPlus33、VWorld、Yes2Win 的部分遊戲新放行；營運是否長期維護這個設定，待營運確認（target/tickets#4375）。
