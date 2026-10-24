---
type: Rule
id: rules/vendor-aigamingsw
title: AiGamingSW 無縫錢包回調
description: AiGamingSW 發回調給我方時，查餘額、投注扣款、結算、獎勵遊戲（bonus game）派彩、取消投注、純派彩各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, AiGamingSW, AiGaming, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:51+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/AiGamingSWController.php, title: AiGamingSW 回調 controller, at: 2026-10-22 }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/BaseController.php, title: 錢包異動與現金紀錄關聯 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2118, title: 新增 AiGamingSW 無縫錢包遊戲商 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4251, title: 接入 AiGamingSW }
entities:
  - { api: "POST external/api/sw/aigaming/seamless/getBalance" }
  - { api: "POST external/api/sw/aigaming/seamless/deduct" }
  - { api: "POST external/api/sw/aigaming/seamless/settle" }
  - { api: "POST external/api/sw/aigaming/seamless/bonus" }
  - { api: "POST external/api/sw/aigaming/seamless/rollback" }
  - { api: "POST external/api/sw/aigaming/seamless/cashBonus" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\AiGamingSWController" }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

AiGamingSW 是以無縫錢包接入的遊戲商。玩家查餘額、投注、結算、玩獎勵遊戲（bonus game，例如免費旋轉）、被取消投注、拿到純派彩時，AiGamingSW 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。

名詞說明：

| 名詞 | 意思 |
|---|---|
| 投注扣款 | 玩家下注時，遊戲商要我方先扣款的回調 |
| 獎勵遊戲派彩 | 免費旋轉等獎勵遊戲的派彩。遊戲商另給一個獨立的交易編號，不是原投注的編號 |
| 純派彩 | 沒有對應投注的派彩，我方記為活動獎金 |

本份目前收錄：六個回調入口的前置檢查、回應格式、各回調的處理與重複請求的回應。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 AiGamingSW 的遊戲下注來觸發。

## 規則

下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)。AiGamingSW 還沒有改用共用的前置檢查，IP 白名單、簽章、帳號解析、停用與下注資格由 AiGamingSW 自己檢查；實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時錯誤碼為 0，並帶目前餘額（真金加禮碼再乘以匯率）；失敗時填對應錯誤碼，餘額填 0。 | code:AiGamingSWController::successResponse、code:AiGamingSWController::errorResponse、test:GetBalanceTest::test_get_balance_returns_member_balance |
| R-2 | 在正式環境，來源 IP 不在 AiGamingSW 遊戲商層級的 IP 白名單時，回錯誤碼 2，訊息「Invalid Ip」。白名單為空時不檢查。 | code:AiGamingSWController::callAction |
| R-3 | 回調沒帶玩家帳號時，回錯誤碼 3，訊息「Username empty」。 | code:AiGamingSWController::callAction |
| R-4 | 玩家帳號在我方不存在、是尚未綁定會員的池帳號、所屬的遊戲商戶不存在或已停用時，回錯誤碼 1，訊息「Member not exist」。 | code:AiGamingSWController::callAction、test:GetBalanceTest::test_get_balance_rejects_unknown_username |
| R-5 | 簽章與我方用遊戲商戶金鑰算出的值不符時，回錯誤碼 4，訊息「apiKey Error」，不動錢包。遊戲商戶沒有設定金鑰時，一律以同一個錯誤碼拒收，不放行。 | code:AiGamingSWController::isSignatureValid、test:GetBalanceTest::test_get_balance_rejects_invalid_signature、test:DeductTest::test_deduct_rejects_invalid_signature |
| R-6 | 回調帶的錢包幣別與遊戲商戶的商戶幣別不同時，回錯誤碼 7，訊息「Invalid walletType」，不動錢包。 | code:AiGamingSWController::callAction、test:GetBalanceTest::test_get_balance_rejects_wallet_type_mismatch |
| R-7 | 玩家帳號停用時，所有回調（包括查餘額）都回錯誤碼 1，訊息「Member not exist」，不動錢包。這點與共用文件「停用時查餘額照回餘額」不同。 | code:AiGamingSWController::callAction、test:GetBalanceTest::test_get_balance_rejects_disabled_account |
| R-8 | 遊戲商送來的投注時間與結算時間不帶時區，一律當成 UTC+8 換成 UTC 寫入注單。時間格式無法解析時，以當下時間寫入。 | code:AiGamingSWController::parseVendorTime、test:DeductTest::test_deduct_creates_bet_record_and_deducts_balance、test:SettleTest::test_settle_credits_payout_and_creates_total_bet_record |

### 投注扣款

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-9 | 收到投注扣款時，投注額除以匯率換成平台金額，從會員錢包扣除（禮碼先扣、真金補足），產生一筆未結算注單。投注當下不建 TBR，結算時才建。 | code:AiGamingSWController::deduct、test:DeductTest::test_deduct_creates_bet_record_and_deducts_balance、test:DeductTest::test_deduct_converts_amount_by_rate_when_not_one_to_one、mr:!2118 |
| R-10 | 會員不具下注資格時，回錯誤碼 7，訊息「Bet not allowed」，不建注單、不扣款。下注資格中的「遊戲能不能下注」只看遊戲商戶的遊戲是否標為可下注。 | code:AiGamingSWController::deduct、code:BetHelper::assertMemberAbleToBet |
| R-11 | 沒帶交易編號，或投注額不大於 0 時，回錯誤碼 7，訊息「Invalid parameter」。 | code:AiGamingSWController::deduct |
| R-12 | 同一個交易編號的投注扣款重複送達時，回錯誤碼 5003，不再扣款、不再建注單。兩筆幾乎同時到達時也只扣一次。 | code:AiGamingSWController::deduct、test:DeductTest::test_deduct_rejects_duplicate_transaction_id、mr:!2118 |
| R-13 | 會員真金加禮碼不足以支付投注額時，回錯誤碼 5，不建注單、不扣款。 | code:AiGamingSWController::deduct、test:DeductTest::test_deduct_rejects_insufficient_balance |

### 結算

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 收到結算時，依交易編號找到未結算的原注單，派彩額（含本金）除以匯率後加入會員錢包，注單改為已結算，並建立該局 TBR。每個交易編號只結算一次。 | code:AiGamingSWController::settle、test:SettleTest::test_settle_credits_payout_and_creates_total_bet_record、mr:!2118 |
| R-15 | 結算結果為取消時，本金以派彩額退回會員錢包，該注單的有效投注記為 0。 | code:AiGamingSWController::settle、test:SettleTest::test_settle_cancel_refunds_stake_and_zeroes_valid_bet、mr:!2118 |
| R-16 | 輸局結算派彩為 0、錢包沒有變動時，該局 TBR 仍關聯到投注當時的現金交易紀錄，讓這局出現在現金紀錄明細。 | code:AiGamingSWController::settle、code:BaseController::changeBalance、test:SettleTest::test_settle_with_zero_payout_still_links_transaction_log、mr:!2118 |
| R-17 | 結算找不到原注單時回錯誤碼 6；原注單已取消投注時回錯誤碼 5008；原注單已結算過時回錯誤碼 2001。三種情況都不動錢包。 | code:AiGamingSWController::settle、test:SettleTest::test_settle_returns_error_when_bet_not_exists、test:SettleTest::test_settle_is_idempotent_for_settled_bet |

### 獎勵遊戲派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-18 | 收到獎勵遊戲派彩時，以它自己的交易編號另建一筆已結算注單（投注額 0），派彩額除以匯率後加入會員錢包，並建立 TBR。原投注的注單不受影響。 | code:AiGamingSWController::bonus、test:BonusTest::test_bonus_creates_standalone_record_and_credits_payout_only、test:BonusTest::test_bonus_does_not_touch_original_bet_record、mr:!2118 |
| R-19 | 獎勵遊戲派彩額為 0（免費旋轉沒中獎）時，只是過程通知：回成功與目前餘額，不建注單、不建 TBR、不動錢包。 | code:AiGamingSWController::bonus、test:BonusTest::test_bonus_with_zero_payout_is_acknowledged_without_record、mr:!2118 |
| R-20 | 沒帶交易編號或派彩額為負數時，回錯誤碼 7；同一個交易編號重複送達時，回錯誤碼 5003，不重複加款。 | code:AiGamingSWController::bonus、test:BonusTest::test_bonus_rejects_duplicate_transaction_id |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-21 | 收到取消投注時，依交易編號找到未結算的原注單，把投注額退回會員錢包，注單的投注額、有效投注、派彩、輸贏歸零並改為已取消。原注單還沒結算、沒有 TBR，所以不建也不改 TBR。 | code:AiGamingSWController::rollback、test:RollbackTest::test_rollback_refunds_deducted_stake、mr:!2118 |
| R-22 | 取消投注找不到原注單時回錯誤碼 6；原注單已取消過時回錯誤碼 5008；原注單已結算時回錯誤碼 2001。三種情況都不動錢包。 | code:AiGamingSWController::rollback、test:RollbackTest::test_rollback_returns_error_when_bet_not_exists、test:RollbackTest::test_rollback_is_idempotent、test:RollbackTest::test_rollback_rejects_settled_bet |

### 純派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 收到純派彩時，以它自己的交易編號另建一筆已結算注單（投注額、派彩、輸贏都是 0），金額除以匯率後記為活動獎金加入會員錢包，並建立 TBR，金額記在 TBR 的活動獎金。 | code:AiGamingSWController::cashBonus、test:CashBonusTest::test_cash_bonus_credits_payout_as_bonus |
| R-24 | 純派彩沒帶交易編號或金額不大於 0 時，回錯誤碼 7，不建注單；同一個交易編號重複送達時，回錯誤碼 5003，不重複加款。 | code:AiGamingSWController::cashBonus、test:CashBonusTest::test_cash_bonus_rejects_zero_payout、test:CashBonusTest::test_cash_bonus_rejects_duplicate_transaction_id |
