---
type: Rule
id: rules/vendor-top55
title: Top55SW 無縫錢包回調
description: 聚合商 Top55SW 發回調給我方時，查餘額、投注、派彩、取消投注與批次交易各要怎麼表現。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 聚合商, 無縫錢包, 回調, Top55, Top55SW, 會員, callback, seamless wallet, single wallet]
entities:
  - api: "POST external/api/sw/top55/api/balance"
  - api: "POST external/api/sw/top55/api/transaction"
  - api: "POST external/api/sw/top55/api/batch-transactions"
  - class: 'App\Http\Controllers\External\SingleWallets\Top55Controller'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/Top55Controller.php
    title: Top55SW 回調 controller
    at: 2026-10-22
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2415
    title: 交易回調以字串查注單編號，修正回應約 1.6 秒
related:
  - rules/single-wallet-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:41:16+08:00" }
---

## 這個主題在講什麼

Top55SW 是聚合商（把多家遊戲商包成同一套 API 轉給我方的中間商），以無縫錢包接入。它旗下的 31 家遊戲商在我方各是一個遊戲商（例如 Top55SW 的 PG、JILI、PP），共用同一組回調入口；我方依回調帶的玩家帳號找出它屬於哪一個遊戲商戶，再決定是哪一家遊戲商。

Top55SW 只有三種回調：查餘額、單筆交易、批次交易。單筆交易用金額正負分辨動作：

| 金額 | 動作 |
|---|---|
| 負數 | 投注 |
| 0 或正數 | 派彩；帶「已取消」旗標時是取消投注 |

玩家輸的局只送一次投注回調，並帶「已結束」旗標；玩家贏的局先送投注，再送一次派彩。

本份目前收錄 Top55SW 三個回調入口的行為。Top55SW 的前置檢查（玩家帳號解析、授權、IP 白名單）由它自己的入口處理，沒有走 [rules/single-wallet-callback-common-checks](single-wallet-callback-common-checks.md) 的共用檢查，錯誤碼也不在那份的對照表，所以寫在本份。

## 怎麼觸發

沒有操作畫面。玩家在 Top55SW 旗下的遊戲內操作時，由 Top55SW 發回調觸發。QA 可以在 dev 站進入 Top55SW 旗下的遊戲下注來觸發。

## 規則

### 共通：回應格式與前置檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 成功與預期內的拒絕都回 HTTP 200。成功時成功旗標為真、錯誤碼為 0，餘額放在訊息欄位；拒絕時成功旗標為假，訊息欄位放錯誤原因，錯誤碼除了餘額不足是 2 之外都是 1。預期外的系統錯誤不在這裡組回應，交給全站的例外處理。 | code:Top55Controller::callAction、code:Top55Controller::transaction、test:Top55SWBetIdBindingTest::test_bet_binds_bet_id_as_string |
| R-2 | 回調依序檢查，任一不過就回錯誤碼 1、不動錢包、不寫注單：沒帶玩家帳號；玩家帳號不存在（「Account not found」）；玩家帳號停用（「Account blocked」）；玩家帳號所屬的遊戲商戶不存在或停用、或會員不存在或停用（「Invalid player account」）；請求標頭（header）沒帶授權或授權不等於該遊戲商戶的用戶端代碼與密鑰；在正式環境來源 IP 不在該遊戲商的 IP 白名單（「Invalid IP」，白名單為空時不檢查）。 | code:Top55Controller::callAction、code:Top55Controller::getPlayerByUsername、code:Top55Controller::validateAccountStatus、code:Top55Controller::vaildateAuthorization |

### 查餘額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-3 | 查餘額時，回一般錢包加禮碼錢包的合計乘以匯率。 | code:Top55Controller::balance |

### 投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-4 | 投注時依序檢查，任一不過就擋下、不扣款、不建注單：會員不具下注資格回錯誤碼 1（「Account could not bet yet」）；一般錢包加禮碼錢包不足以支付投注額回錯誤碼 2（「Insufficient balance」）；同一個注單編號已存在回錯誤碼 1（「Duplicate transaction」）。 | code:Top55Controller::execTransaction |
| R-5 | 投注通過時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、一般錢包補足），建一筆注單：投注與有效投注都是投注額、派彩是 0、輸贏是負的投注額。帶「已結束」旗標時注單直接是已結算並建立 TBR；沒帶時注單是投注中，不建 TBR。 | code:Top55Controller::execTransaction、test:Top55SWBetIdBindingTest::test_bet_binds_bet_id_as_string |

### 派彩與取消投注

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 派彩或取消投注依注單編號找原注單。找不到回錯誤碼 1（「Bet transaction not found」）；已結算回錯誤碼 1（「Bet record already settled」）；已取消回錯誤碼 1（「Bet record already canceled」）。三種都不動錢包，所以同一筆派彩或取消重送只會處理一次。 | code:Top55Controller::execTransaction |
| R-7 | 派彩時，依匯率換算的金額加入會員錢包，注單的派彩改為這個金額、輸贏是派彩減投注，注單改為已結算，並建立 TBR。 | code:Top55Controller::execTransaction、test:Top55SWBetIdBindingTest::test_settle_binds_bet_id_as_string |
| R-8 | 取消投注時，依匯率換算的金額加入會員錢包，注單的派彩改為這個金額、輸贏是派彩減投注，注單改為已取消，不建 TBR。 | code:Top55Controller::execTransaction |

### 批次交易

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-9 | 批次交易依序逐筆處理，每筆各自完成、各自寫入，規則同單筆交易。中途某一筆失敗時整個回調回該筆的錯誤，排在它前面已處理的筆不會撤回；全部成功時回最後一筆處理後的餘額。 | code:Top55Controller::batchTransactions |
