---
type: Rule
id: rules/payment-callback-common-checks
title: 金流回調的共用檢查
description: 金流商發給我方的代收與代付回調，在動訂單之前要先通過哪些檢查（來源 IP、金流商比對、簽名、反查），被擋下時怎麼表現、什麼情況發告警、往返怎麼留紀錄。
domain: 金流
subdomain: 會員儲值（上分）
tags: [金流, 代收, 代付, 回調, callback, notify, 三方回調, 來源 IP, IP 白名單, whitelist, 金流商比對, 渠道檢查, 找單限定渠道, 簽名, 驗簽, mdfive_val, 反查, 查單, 限流, throttle, 冪等, 告警, TG 告警, 三方往返紀錄, order request log, AP8PAY, EasyPay, MaxPay, TngPin, GMPay]
entities:
  - class: App\Services\Payment\Traits\CallbackSourceGuardTrait
  - class: App\Services\Payment\Traits\DepositCallbackTrait
  - class: App\Services\Payment\Traits\WithdrawalCallbackTrait
  - class: App\Services\Payment\Traits\RecordsThirdPartyExchangeTrait
  - class: App\Services\Payment\Providers\Ap8Pay\DepositCallback
  - class: App\Services\Payment\Providers\EasyPay\EasyPayTrait
  - class: App\Services\Payment\Providers\EasyPay\DepositCallback
  - class: App\Services\Payment\Providers\EasyPay\WithdrawalCallback
  - class: App\Services\Payment\Providers\MaxPay\DepositCallback
  - class: App\Services\Payment\Providers\MaxPay\WithdrawalCallback
  - class: App\Services\Payment\Providers\TngPin\DepositCallback
  - class: App\Services\Payment\Providers\GMPay\DepositCallback
  - api: ANY external/api/deposit/notify/{agentCashFlowId?}
  - api: ANY external/api/withdrawal/notify/{agentCashFlowId?}
  - class: App\Services\Payment\OrderRequestLogDisplayService
  - command: clean:order-request-logs
evidence:
  - kind: code
    ref: /app/Services/Payment/Traits/CallbackSourceGuardTrait.php
    title: 回調來源 IP 白名單（fail-closed，白名單讀金流商層級）
    at: 2026-10-20
  - kind: code
    ref: /app/Services/Payment/Traits/DepositCallbackTrait.php
    title: 代收回調找單時的金流商比對、訂單狀態冪等檢查、金額不符告警
  - kind: code
    ref: /app/Services/Payment/Traits/WithdrawalCallbackTrait.php
    title: 代付回調找代付請求時的金流商比對、金額不符告警
  - kind: code
    ref: /app/Services/Payment/Traits/RecordsThirdPartyExchangeTrait.php
    title: 三方往返紀錄的共用寫入（交易外寫入）
  - kind: code
    ref: /app/Services/Payment/Providers/Ap8Pay/DepositCallback.php
    title: AP8PAY 代收回調的三道防線（來源 IP、金流商比對、向三方反查）
  - kind: code
    ref: /app/Services/Payment/Providers/EasyPay/EasyPayTrait.php
    title: EasyPay 建單簽名與回調驗簽（帶了才驗）
  - kind: code
    ref: /app/Services/Payment/Providers/EasyPay/DepositCallback.php
    title: EasyPay 代收回調的檢查順序
  - kind: code
    ref: /app/Services/Payment/Providers/EasyPay/WithdrawalCallback.php
    title: EasyPay 代付回調的檢查順序
  - kind: code
    ref: /app/Services/Payment/Providers/MaxPay/DepositCallback.php
    title: MaxPay 代收回調的來源 IP 檢查
  - kind: code
    ref: /app/Services/Payment/Providers/MaxPay/WithdrawalCallback.php
    title: MaxPay 代付回調的來源 IP 檢查
  - kind: code
    ref: /app/Services/Payment/Providers/TngPin/DepositCallback.php
    title: TngPin 代收回調的憑證、來源 IP、簽名檢查與限定金流商戶找單
  - kind: code
    ref: /app/Services/Payment/Providers/GMPay/DepositCallback.php
    title: GMPay 實付金額逾越自動入帳範圍時轉人工並告警
  - kind: code
    ref: /routes/external/api.php
    title: 代收與代付回調路由（刻意不限流）
  - kind: code
    ref: /app/Services/Payment/OrderRequestLogDisplayService.php
    title: 代理後台三方往返紀錄的顯示欄位與分組
  - kind: code
    ref: /app/Console/Commands/Schedule/CleanOrderRequestLogs.php
    title: 三方往返紀錄保留 90 天的清理排程
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2403
    title: EasyPay、MaxPay、TngPin 回調補來源白名單與找單限定，三方往返全程留痕
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2406
    title: 回調渠道檢查改為比對金流商
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2405
    title: 移除三方回調路由的限流
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2381
    title: AP8PAY 代收回調補來源驗證與三方往返紀錄
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2412
    title: EasyPay 建單帶 mdfive_val 簽名，回調帶了才驗
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2095
    title: 修正金流告警頻道名錯誤導致通知不發
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4446
    title: AP8PAY 代收回調補來源驗證與金額檢查
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4449
    title: EasyPay、MaxPay、TngPin 代收、代付回調補來源驗證與找單限定
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4470
    title: EasyPay 代收代付回調啟用 mdfive_val 驗簽
related:
  - rules/payout-submit-and-status
  - rules/provider-a3pay
  - rules/provider-b3wallet
  - rules/provider-ccpayment
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

會員付款或平台出款有結果後，金流商會主動打回調通知我方。我方依回調把充值訂單判成功或失敗，也依回調把取款訂單的代付請求判成功或失敗。

回調網址對外公開、不必登入，任何人都能對它送請求。回調如果是偽造的，會造成以下損失：

- 代收：沒付款的充值訂單被上分。
- 代付：還在出款中的取款訂單先退點給會員，之後金流商又真的出款，等於付了兩次。

所以回調在動訂單之前，要先過幾道檢查：

- 來源 IP 是不是金流商的。
- 找到的訂單是不是這家金流商建的。
- 簽名對不對。
- AP8PAY 另外向金流商反查的結果是什麼。

被擋下的回調不動訂單，只回失敗並留下紀錄；某些異常另外發告警給維運。來源 IP 的白名單設在金流商這一層，同一家金流商底下的所有金流商戶與代理共用一份，由營運在 Olympus 的金流商管理頁維護。

本份目前收錄的範圍：MR !2381、!2403、!2405、!2406、!2412、!2095 建立或改變的代收與代付回調共用檢查與告警，加上理解它們必要的既有行為。既有行為包括回調的冪等檢查（同一張訂單收到多次回調只處理一次）與 TngPin 原有的驗簽。代付回調怎麼判定成功、失敗或處理中不在本份，見 [rules/payout-submit-and-status](payout-submit-and-status.md)。

## 怎麼觸發

沒有操作畫面，由金流商的三方回調觸發：代收打我方的代收回調網址，代付打代付回調網址。網址尾端帶著建單時用的金流商戶。

往返紀錄在以下兩處查看：

- 代理後台 › 支付訂單管理（待確認）› 充值訂單詳細資料 › 三方往返紀錄。
- 代理後台 › 自助洗分 › 洗分訂單審核（待確認）› 取款訂單詳細資料 › 三方往返紀錄。

後台畫面在另一個 repo（agent-backend）另外發版。

## 規則

### 來源 IP 白名單

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | AP8PAY 代收、EasyPay 代收與代付、MaxPay 代收與代付、TngPin 代收的回調，來源 IP 不在該金流商的白名單內時一律擋下並回失敗。充值訂單與代付請求的狀態、金額、點數都不動。這道檢查排在找訂單之前，被擋下的回調不會去找訂單，也不會動到任何訂單。其他金流商的回調目前不檢查來源 IP。 | code:CallbackSourceGuardTrait::isCallbackSourceAllowed |
| R-2 | 上列金流商的白名單沒設定（空白）時，回調全部擋下，不是當作不檢查。白名單沒先設定好就上線時，症狀是該金流商的充值全部不上分、代付回調全部不生效。 | code:CallbackSourceGuardTrait::isCallbackSourceAllowed |
| R-3 | TngPin 代收回調同樣讀金流商層級的白名單，沒設定時擋下，不再讀金流商戶自己的設定。 | code:TngPin\DepositCallback |
| R-4 | 來源 IP 依我方信任的代理鏈（請求到達我方之前經過的轉送伺服器）判定，回調自己帶的轉送標頭（例如 X-Forwarded-For）改變不了判定結果。 | code:CallbackSourceGuardTrait::callbackAllowIps |

### 訂單必須屬於回調的金流商

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 代收回調找到的充值訂單、代付回調找到的代付請求，必須和回調網址上的金流商戶屬於同一家金流商。屬於別家金流商時，當作查無訂單處理：回失敗、不動訂單，並發告警（「代收回調遭拒：訂單不屬於此渠道」或「代付回調遭拒：代付請求不屬於此渠道」）。 | code:DepositCallbackTrait::orderBelongsToCallbackChannel、code:WithdrawalCallbackTrait::payoutRecordInCallbackChannel |
| R-6 | 同一家金流商底下不同金流商戶之間不擋。我方重建金流商戶後，金流商後台的回調網址還指向舊的金流商戶時，回調照常處理訂單。 | code:DepositCallbackTrait::orderBelongsToCallbackChannel |
| R-7 | 訂單或代付請求所掛的金流商戶已不存在時，一律視為不屬於這家金流商，擋下。 | code:DepositCallbackTrait::orderBelongsToCallbackChannel |
| R-8 | TngPin 代收回調比共用規則更嚴：只找回調網址上那個金流商戶建立的充值訂單，同一家金流商的其他金流商戶建的訂單也找不到。 | code:TngPin\DepositCallback |
| R-9 | AP8PAY 代收回調在向金流商反查之前，就先比對金流商。訂單屬於別家金流商時直接擋下並發告警，不會拿別家的訂單去向金流商查單。 | code:Ap8Pay\DepositCallback |

### 簽名與反查（金流商專屬）

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-10 | EasyPay 的代收與代付回調帶了簽名時，簽名必須驗得過，驗不過就擋下、不動訂單。回調沒帶簽名時照常處理。我方金流商戶沒設私鑰時，回調就算帶了簽名也不驗，照常處理。驗簽排在來源 IP 檢查之後。 | code:EasyPay\EasyPayTrait、code:EasyPay\DepositCallback、code:EasyPay\WithdrawalCallback |
| R-11 | EasyPay 金流商戶設了私鑰後，我方送出的代收與代付建單請求都帶簽名。沒設私鑰的金流商戶建單不帶簽名，行為與原本相同。 | code:EasyPay\EasyPayTrait |
| R-12 | TngPin 代收回調除了來源 IP，還要通過簽名驗證。我方金流商戶的憑證沒設定時同樣擋下，回給金流商的回應和簽名不符時相同，不透露是憑證沒設。這是既有行為。 | code:TngPin\DepositCallback |
| R-13 | AP8PAY 代收回調一律向金流商反查，上不上分與上多少分都以反查結果為準，不採信回調自帶的狀態與金額。以下情況訂單不動，回失敗讓金流商重送：反查打不通、金流商還沒有最終結果、查回來的不是本單、查回成功卻沒有金額。 | code:Ap8Pay\DepositCallback |

### 限流

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 代收與代付回調網址不限制次數。同一個來源 IP 在一分鐘內送上百筆回調，也不會因為頻率被擋。 | code:routes/external/api.php |

### 重複回調（既有行為）

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 充值訂單已經不在「建立」狀態（已成功、失敗、已上分、待人工確認）時，再收到回調不改訂單，回成功讓金流商停止重送。查無訂單時回失敗。這是既有行為。 | code:DepositCallbackTrait::checkDepositOrderStatus |

### 告警

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-16 | 代收回調判定成功，但回調金額和充值訂單金額不符時，訂單改為待人工確認、不上分，並發告警「代收回調金額不符」。 | code:DepositCallbackTrait::updateDepositOrderSuccess |
| R-17 | 代付回調判定成功，但回調金額和取款訂單金額不符時，發告警「代付回調金額不符」。這筆仍照成功處理，不因金額不符而擋下。 | code:WithdrawalCallbackTrait::resolveWithdrawalOutcome |
| R-18 | GMPay 代收回調的實付金額低於原始金額，或高出原始金額超過 1.00 時，訂單轉待人工確認、不上分，並發告警「代收回調實付金額逾越自動入帳範圍」。 | code:GMPay\DepositCallback |
| R-19 | AP8PAY 代收回調因來源 IP、金流商比對，或反查到別筆訂單而被擋下時，發告警。反查成功卻沒有金額時也發告警，請人工確認會員是否已付款。其他金流商因來源 IP 或簽名被擋下時只記 log，不發告警。 | code:Ap8Pay\DepositCallback |
| R-20 | 以上告警都發到金流告警群組。群組名稱必須存在於告警設定，否則告警會在沒有任何錯誤提示的情況下消失。 | code:BotNotifyService、mr:!2095 |

### 三方往返紀錄

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-21 | AP8PAY、EasyPay、MaxPay、TngPin 收到的每一次回調都留一筆往返紀錄，包含被擋下的與處理到一半出錯的（AP8PAY 欄位格式不符的回調除外）。紀錄記下時間、判定結果（成功，或被哪一道擋下）、三方單號與來源 IP。紀錄寫在訂單的資料庫交易之外，訂單沒動或交易回復時紀錄仍在。 | code:RecordsThirdPartyExchangeTrait::logExchange |
| R-22 | 被來源 IP 擋下、還沒找到訂單的回調，紀錄仍會出現在對應的充值訂單或代付請求底下。代收的紀錄不會因為識別碼相同而出現在代付請求底下，反之亦然。 | code:RecordsThirdPartyExchangeTrait::logExchange |
| R-23 | 後台的三方往返紀錄只顯示時間、類型、判定結果、回應狀態碼、三方單號與來源 IP，不顯示往返內容原文（原文含金流商戶的金鑰）。 | code:OrderRequestLogDisplayService |
| R-24 | 取款訂單的三方往返紀錄依每一次代付請求分組顯示。還沒送出過代付請求的取款訂單沒有紀錄。 | code:OrderRequestLogDisplayService |
| R-25 | 往返紀錄保留 90 天，每天 05:40 刪除更早的紀錄。 | command:clean:order-request-logs |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| MaxPay 的白名單沒設定，MaxPay 送來一筆真的代收成功回調 | 擋下，訂單維持建立狀態、不上分，留一筆「來源 IP 不在白名單」的往返紀錄 | R-2、R-21 |
| 充值訂單建在 TPower FPay 的金流商戶 271，回調打到同一家 FPay 的舊金流商戶 263 | 照常處理 | R-6 |
| 有人用 EasyPay 的回調網址送來 MaxPay 建的充值訂單號 | 擋下（前提是來源 IP 已通過），訂單不動，發「訂單不屬於此渠道」告警 | R-1、R-5 |
| EasyPay 回調沒帶簽名 | 照常處理 | R-10 |
| EasyPay 回調帶了簽名但簽錯，我方已設私鑰 | 擋下，訂單不動 | R-10 |
| AP8PAY 回調說成功，反查說還沒付款 | 訂單不動、回失敗，金流商會重送 | R-13 |
| 充值訂單 1,000，代收回調說成功、金額 900 | 訂單轉待人工確認、不上分，發告警 | R-16 |
| GMPay 原始金額 100，實付 101.50 | 轉待人工確認、不上分，發告警 | R-18 |
| 同一筆已上分的充值訂單，三方再送一次成功回調 | 不改訂單，回成功 | R-15 |
