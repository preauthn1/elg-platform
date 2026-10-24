---
type: Rule
id: rules/vendor-fumasw
title: FumaSW 無縫錢包回調
description: FumaSW 發回調給我方時，查餘額、投注、派彩各要怎麼表現，以及錯誤時回什麼。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 回調, FumaSW, FUMA, 會員, callback, seamless wallet, single wallet]
entities:
  - api: "POST external/api/sw/fuma"
  - class: 'App\Http\Controllers\External\SingleWallets\FumaSWController'
  - class: 'App\Vendors\FumaSW\FumaSWService'
  - class: 'App\Vendors\FumaSW\Libs\Bet'
  - class: 'App\Vendors\FumaSW\Libs\Settle'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/FumaSWController.php
    title: FumaSW 回調 controller
    at: 2026-10-22
  - kind: code
    ref: /app/Vendors/FumaSW/FumaSWService.php
    title: 解密、遊戲商戶與玩家帳號解析、同局鎖
  - kind: code
    ref: /app/Vendors/FumaSW/Libs/Settle.php
    title: 派彩處理
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2186
    title: 派彩找不到下注時改拋專屬錯誤碼 9012
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2147
    title: 遊戲商戶代碼查詢改綁定參數，修正查無遊戲商戶時的型別錯誤
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3128
    title: 派彩找不到下注時的錯誤碼
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3097
    title: 遊戲商戶代碼查詢的 SQL 注入
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3085
    title: 查無遊戲商戶時錯誤訊息沒有生效
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:41:16+08:00" }
---

## 這個主題在講什麼

FumaSW 以無縫錢包接入。FumaSW 的所有回調都打同一個入口，請求帶一個遊戲商戶代碼與一段加密內容；我方用遊戲商戶代碼找出遊戲商戶，再用該遊戲商戶的金鑰解開內容，依內容裡的動作代碼分成查餘額（1）、投注（2）、派彩（3）三種。

FumaSW 有兩種遊戲商：一般的 FumaSW，以及免盤費的 FumaSW。兩者共用這個入口，由遊戲商戶代碼分辨。

FumaSW 的投注與派彩各自建一筆注單：投注建一筆投注注單，派彩另建一筆派彩注單，兩筆用局號關聯。

本份目前收錄 FumaSW 回調入口的行為。FumaSW 的前置檢查（IP 白名單、解密、玩家帳號解析）由它自己處理，沒有走 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md) 的共用檢查，錯誤碼也不在那份的對照表，所以寫在本份。

## 怎麼觸發

沒有操作畫面。玩家在 FumaSW 遊戲內操作時，由 FumaSW 發回調觸發。QA 可以在 dev 站進入 FumaSW 的遊戲下注來觸發。

## 規則

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 所有回調一律回 HTTP 200。成功時狀態碼為「0000」，並回一般錢包加禮碼錢包的合計乘以匯率，無條件捨去到小數點後 2 位。 | code:FumaSWController::balance、code:FumaSWController::bet、code:FumaSWController::settle |
| R-2 | 任何錯誤一律回錯誤碼 9999，錯誤訊息欄位帶實際原因（例如「Wallet balance not enough」「Place bet record not found」），不動錢包。內部雖然為不同原因定了不同錯誤碼（帳號不存在 7501、餘額不足 6006、重複交易 9011、找不到下注 9012），回應時都換成 9999。 | code:FumaSWController::callAction、code:FumaSWController::errorLogAndResponse |
| R-3 | 在正式環境，來源 IP 不在 FumaSW 的 IP 白名單時回錯誤；白名單為空時不檢查。 | code:FumaSWController::callAction |
| R-4 | 回調帶的遊戲商戶代碼對不到 FumaSW 或免盤費 FumaSW 的任何遊戲商戶時，回錯誤，訊息「Vendor not found」。遊戲商戶代碼只當成一個值比對，帶入 SQL 語法也只會查無遊戲商戶。 | code:FumaSWService::decodeRequestAndValidateSignature、test:DcLookupBindingTest::test_合法的dc可解密並把明文參數合併進請求、test:DcLookupBindingTest::test_dc帶入OR恆真子句時不得命中任何商戶、test:DcLookupBindingTest::test_dc含單引號時不得改變SQL語意、mr:!2147 |
| R-5 | 加密內容用該遊戲商戶的金鑰解不開時回錯誤，訊息「Decrypt data failed」。動作代碼不是 1、2、3 之一時回錯誤，訊息「Invalid action」。 | code:FumaSWService::decodeRequestAndValidateSignature、code:FumaSWController::apiSwitch |
| R-6 | 回調帶的玩家帳號不存在、對不到會員，或會員被系統鎖定時回錯誤。 | code:FumaSWService::getAccount |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 查餘額時，玩家帳號停用回錯誤；會員在鎖分模式下不能對該遊戲商下注時也回錯誤，訊息「Account could not bet yet」。這點與共用檢查「停用時查餘額照回」不同。通過時照 R-1 回餘額。 | code:FumaSWService::getBalance |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 同一局、同一個玩家帳號的投注與派彩一次只處理一筆；等超過 5 秒取不到時回錯誤，不動錢包。 | code:FumaSWService::handle |
| R-9 | 投注時依序檢查，任一不過就回錯誤、不扣款、不留注單：遊戲商戶已停用；會員不具下注資格；同一個交易編號已存在；一般錢包加禮碼錢包不足以支付投注額。 | code:Bet::exec、code:Bet::lockAndSetMember、code:Bet::createBetRecord、code:Bet::checkBalanceEnough |
| R-10 | 投注通過時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、一般錢包補足），建一筆投注注單：投注與有效投注都是投注額、輸贏是負的投注額。投注時不建 TBR。 | code:Bet::exec |

### 派彩

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 派彩時依局號與玩家帳號找投注注單；找不到時回錯誤，訊息「Place bet record not found」，不動錢包、不建派彩注單。 | code:Settle::lockAndGetPlaceBetRecord、mr:!2186 |
| R-12 | 投注注單已被補帳命令退回投注額後（退回命令見 [rules/single-wallet-unsettled-refund](single-wallet-unsettled-refund.md)），遊戲商再送該局的派彩回調一律拒絕：回錯誤碼 9999 並記一筆警告 log，不動錢包、不建派彩注單，投注注單維持「已退款」。沒被退回的投注注單照常派彩（R-14）。 | code:Settle::lockAndGetPlaceBetRecord、test:SettleRefundGuardTest::test_settle_rejects_place_bet_that_was_already_refunded、test:SettleRefundGuardTest::test_settle_still_pays_out_unsettled_place_bet、mr:!2120 |
| R-13 | 派彩金額為負數時回錯誤，不動錢包。 | code:Settle::exec |
| R-14 | 派彩通過時，依匯率換算的派彩額加入會員錢包，建一筆派彩注單（輸贏等於派彩額）。投注注單尚未結算時，把它改為已結算、派彩時間記為這次派彩的時間，並把投注與派彩合併成同一筆 TBR；投注注單已結算時（同一局第二筆以後的派彩），派彩注單自成一筆 TBR。 | code:Settle::exec、test:SettleRefundGuardTest::test_settle_still_pays_out_unsettled_place_bet |
| R-15 | 同一個交易編號的派彩重複送達時回錯誤，不重複加款。同一局不同交易編號的派彩，各自都會入帳。 | code:Settle::createSettleBetRecord、code:FumaSWService::handle |

## 還沒確認的事

- 玩家帳號停用時，派彩照樣建派彩注單與 TBR，但錢包不加款。程式這樣寫，沒有找到對應的工單或 MR 說明（2026-10-22 回填時盤點程式碼發現，尚無工單）。
- MR !2186 的目的是讓「派彩找不到下注」回錯誤碼 9012，但回應時仍被換成 9999（R-2、R-11），FumaSW 實際收到的是 9999。要不要讓專屬錯誤碼回到 FumaSW，待 RD 確認（[op_tickets#3128](http://example.invalid:1688/target/op_tickets/-/work_items/3128)）。
