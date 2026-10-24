---
type: Rule
id: rules/vendor-wudisw
title: WudiSW 無縫錢包回調
description: WudiSW 發回調給我方時，查餘額、投注、派彩、取消投注、獎勵各要怎麼表現，重複請求回什麼。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, WudiSW, Wudi, 會員, callback, seamless wallet, single wallet]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:51+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/WudiSWController.php, title: WudiSW 回調 controller, at: 2026-10-22 }
  - { kind: code, ref: /app/Vendors/WudiSW/WudiSWService.php, title: 驗簽、查餘額與交易分派 }
  - { kind: code, ref: /app/Vendors/WudiSW/Libs/Start.php, title: 投注 }
  - { kind: code, ref: /app/Vendors/WudiSW/Libs/End.php, title: 派彩 }
  - { kind: code, ref: /app/Vendors/WudiSW/Libs/Refund.php, title: 取消投注 }
  - { kind: code, ref: /app/Vendors/WudiSW/Libs/Reward.php, title: 獎勵 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2116, title: 重複結算／退款請求回應補上目前餘額 }
entities:
  - { api: "POST external/api/sw/wudi/get_user_balance" }
  - { api: "POST external/api/sw/wudi/transfer" }
  - { class: "App\\Http\\Controllers\\External\\SingleWallets\\WudiSWController" }
  - { class: "App\\Vendors\\WudiSW\\WudiSWService" }
related:
  - rules/single-wallet-callback-common-checks
---

## 這個主題在講什麼

WudiSW 是以無縫錢包接入的遊戲商。玩家查餘額、投注、派彩、取消投注、領獎勵時，WudiSW 會發回調給我方。我方依回調內容增減會員錢包，寫入注單與 TBR（各家注單轉成同一組欄位之後的注單），並把會員餘額回給遊戲商。

WudiSW 只有兩個回調入口：查餘額，以及轉帳。轉帳回調依「轉帳類型」分成投注（start）、派彩（end）、取消投注（refund）、獎勵（reward）四種。

本份目前收錄：兩個回調入口的前置檢查、回應格式、四種轉帳類型的處理，以及重複請求的回應。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，由遊戲商發回調觸發。QA 可以在 dev 站進入 WudiSW 的遊戲下注來觸發。

## 規則

下注資格（會員能不能在這款遊戲下注）與匯率換算的概念見 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md)。WudiSW 還沒有改用共用的前置檢查，IP 白名單、簽章、帳號解析、停用與下注資格由 WudiSW 自己檢查；實際擋下哪些情況、回什麼錯誤碼，寫在本份的規則表。

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為 1000，並帶目前餘額、商戶幣別與請求帶來的交易序號；失敗時填對應錯誤碼，不帶餘額。回應時間以美東時間（America/New_York）格式化。 | code:WudiSWController::callAction、code:WudiSWController::transfer |
| R-2 | 在正式環境，來源 IP 不在 WudiSW 遊戲商層級的 IP 白名單時，回錯誤碼 5201，訊息「IP <來源 IP> not allow」。白名單為空時不檢查。 | code:WudiSWController::callAction |
| R-3 | 簽章與我方用遊戲商戶的金鑰算出的值不符時，回錯誤碼 5001，訊息「Invalid signature」。 | code:WudiSWService::validate |
| R-4 | 回調帶的玩家帳號在我方不存在、帳號所屬的遊戲商戶不存在、或帳號沒有對應的會員時，回錯誤碼 5001。 | code:WudiSWService::validate |
| R-5 | 轉帳類型不是投注、派彩、取消投注、獎勵四種之一時，回錯誤碼 5201。 | code:WudiSWController::transfer |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 查餘額時，回會員真金加禮碼的合計再乘以匯率。 | code:WudiSWService::getBalance、test:WudiSWGetBalanceTest::test_get_balance_with_rolling_chips |
| R-7 | 查餘額時，玩家帳號停用、或會員在鎖分模式（會員參與活動期間，錢包之間的餘額轉移受到限制的狀態）不允許下注，都回錯誤碼 5103，不回餘額。這點與共用文件「停用時查餘額照回餘額」不同。 | code:WudiSWService::getBalance |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 收到投注回調時，投注額取絕對值後除以匯率換成平台金額，從會員錢包扣除（禮碼先扣、真金補足），產生一筆未結算注單，並回扣款後的真金加禮碼餘額。投注當下不建 TBR。 | code:Start::exec、test:WudiSWStartBetTest::test_bet、test:WudiSWStartBetTest::test_bet_with_rolling_chips |
| R-9 | 投注額為 0 的投注回調，只回目前餘額與成功碼 1000，不建注單、不扣款。三方文件規定這種情況只能回 1000，回其他代碼玩家可能會被限制遊戲。 | code:WudiSWService::transferStart |
| R-10 | 投注時遊戲商戶已停用，或會員不具下注資格，回錯誤碼 5201，不建注單、不扣款。投注不檢查玩家帳號是否停用：玩家帳號停用時投注照常扣款、建單。 | code:Start::exec |
| R-11 | 投注時會員真金加禮碼不足以支付投注額，回錯誤碼 5101，不建注單、不扣款。 | code:Start::checkBalanceEnough |
| R-12 | 同一個注單編號的投注重複送達時，回錯誤碼 5107，不再扣款、不再建注單。 | code:WudiSWService::handleTransfer、code:Start::createBetRecord |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-13 | 收到派彩回調時，依注單編號找到未結算的原注單，派彩額除以匯率後加入會員的一般錢包，注單改為已結算，有效投注取回調帶來的有效投注，並建立該局 TBR。 | code:End::exec、test:WudiSWEndBetTest::test_end_bet |
| R-14 | 派彩額為負數時回錯誤碼 5002，不動錢包。 | code:End::exec |
| R-15 | 派彩回調的注單編號找不到原注單時，回錯誤碼 5105，不動錢包。 | code:End::lockAndGetBetRecord |
| R-16 | 注單已派彩過，再收到同一筆的派彩回調時，回成功碼 1000，訊息「Bet record already ended」，並帶目前餘額、幣別與交易序號，不再加款。三方要收到帶餘額的 1000 才會結單。 | code:End::lockAndGetBetRecord、code:WudiSWController::callAction、test:WudiSWEndBetTest::test_duplicate_end_bet_response_contains_current_balance_data、mr:!2116 |
| R-17 | 注單已被取消投注，再收到針對它的派彩回調時，回錯誤碼 5106，不動錢包。 | code:End::lockAndGetBetRecord |
| R-18 | 玩家帳號停用時，派彩回調照樣把注單改為已結算並建 TBR，但不把派彩加進會員錢包。 | code:End::exec |

### 取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-19 | 收到取消投注回調時，依注單編號找到未結算的原注單，退回金額取絕對值後除以匯率，先退回原本用真金支付的部分，超出的再退回禮碼錢包；注單改為已取消。取消投注不建 TBR。 | code:Refund::exec、code:Refund::calcRefunds |
| R-20 | 退回金額超過原注單的投注額時，回錯誤碼 5201，不動錢包。 | code:Refund::calcRefunds |
| R-21 | 取消投注回調的注單編號找不到原注單時，回錯誤碼 5105，不動錢包。 | code:Refund::lockAndGetBetRecord |
| R-22 | 注單已取消過，再收到同一筆的取消投注回調時，回成功碼 1000，訊息「Bet record already refunded」，並帶目前餘額、幣別與交易序號，不再退回投注額。 | code:Refund::lockAndGetBetRecord、code:WudiSWController::callAction、mr:!2116 |
| R-23 | 注單已派彩，再收到針對它的取消投注回調時，回錯誤碼 5106，不動錢包。 | code:Refund::lockAndGetBetRecord |

### 獎勵

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-24 | 收到獎勵回調時不需要原注單，以回調的注單編號另建一筆獎勵注單，金額除以匯率後加入會員的一般錢包，並建立 TBR。 | code:Reward::exec |
| R-25 | 獎勵金額為負數時回錯誤碼 5002；同一個注單編號的獎勵重複送達時回錯誤碼 5107。兩種情況都不動錢包。 | code:Reward::exec、code:WudiSWService::handleTransfer |

### 重複請求回應帶的餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-26 | 重複派彩、重複取消投注回成功碼 1000 時帶的目前餘額，不檢查玩家帳號是否停用、也不檢查鎖分模式，避免確認回應被帳號狀態擋成錯誤。 | code:WudiSWService::getCurrentBalance、mr:!2116 |

## 還沒確認的事

- 玩家帳號停用時，派彩不加進錢包（R-18），但程式仍以「加款後」的餘額寫一筆帳變流水。這筆帳變流水是否應該存在，待 RD 確認（2026-10-22 回填時盤點程式碼發現，尚無工單）。
