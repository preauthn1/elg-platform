---
type: Rule
id: rules/provider-ccpayment
title: CCPayment USDT 代收與代付
description: 會員用 CCPayment 的固定鏈上地址充值 USDT、選 USDT 取款銀行取款時，系統怎麼取地址、怎麼憑回調入帳與定案代付、風險交易怎麼鎖住與解鎖。
domain: 金流
subdomain: 會員儲值（上分）
tags: [金流, 充值, 存款, 儲值, deposit, 取款, 提款, 提現, withdrawal, 代收, 代付, CCPayment, USDT, TRC20, TRX, 加密貨幣, crypto, 永久收款地址, 固定地址, permanent address, 風險交易, 風險存款, risky, 手動上分, 退款, 回調, webhook, 網路費]
entities:
  - api: POST frontend/api/deposit/create-order
  - api: POST frontend/api/deposit/crypto-address
  - api: POST frontend/api/deposit/orders
  - api: POST frontend/api/deposit/top-up-channels
  - api: POST frontend/api/deposit/top-up-types
  - api: POST frontend/api/deposit/types
  - class: App\Services\Payment\Providers\CCPayment\DepositCallback
  - class: App\Services\Payment\Providers\CCPayment\RiskyRefundSync
  - class: App\Services\Payment\Providers\CCPayment\Withdrawal
  - class: App\Services\Payment\Providers\CCPayment\WithdrawalCallback
  - class: App\Services\Payment\Providers\CCPayment\Deposit
  - class: App\Services\Payment\Providers\CCPayment\Driver
  - class: App\Services\Payment\Providers\CCPayment\CCPaymentTrait
  - class: App\Services\MemberCryptoWalletService
  - class: App\Models\DepositManualReview
  - table: deposit_manual_reviews
  - command: ccpayment:resend-missing-webhooks
  - command: ccpayment:sync-risky-refunds
evidence:
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/DepositCallback.php
    title: 鏈上入帳回調的判定矩陣、風險交易建單與上鎖、風險地址回調
    at: 2026-10-22
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/RiskyRefundSync.php
    title: 風險存款退款結果同步（ccpayment:sync-risky-refunds）
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/Withdrawal.php
    title: 代付發起、明確拒絕碼白名單、幣鏈由取款銀行決定
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/WithdrawalCallback.php
    title: 代付結果回調的三態判定
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/Deposit.php
    title: 永久收款地址取址與解綁，代收不走填金額建單
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/Driver.php
    title: CCPayment 金流商入口
  - kind: code
    ref: /app/Services/Payment/Providers/CCPayment/CCPaymentTrait.php
    title: 驗簽、幣鏈解析（channelSpec／payoutSpec）、回查
  - kind: code
    ref: /app/Services/MemberCryptoWalletService.php
    title: 充值方式清單自動取址與主動取址
  - kind: code
    ref: /app/Http/Controllers/Frontend/DepositController.php
    title: 會員取址端點與建單擋門
  - kind: code
    ref: /app/Models/DepositManualReview.php
    title: 存款人工確認紀錄（手動上分的鎖）
  - kind: code
    ref: /app/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommand.php
    title: 回調補送排程（ccpayment:resend-missing-webhooks）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2246
    title: 接入 CCPayment 的 USDT 代收與代付（永久收款地址模式）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2369
    title: CCPayment 風險存款退款後自動更新充值訂單，並擋住誤按手動上分
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4238
    title: CCPayment USDT 代收代付接入
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4463
    title: CCPayment 風險存款退款後自動更新訂單，並禁止誤按手動上分
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2463
    title: CCPayment 換商戶後存款方式清單改向目前商戶確認收款地址並換發
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4564
    title: 修復 CCPayment 換商戶後仍顯示舊商戶的收款地址
  - kind: code
    ref: /docs/integrations/payments/CCPayment/integration.md
    title: CCPayment 對接心得與實作決策
  - kind: code
    ref: /docs/integrations/payments/CCPayment/runbook.md
    title: CCPayment 上線 runbook
related:
  - rules/payment-cost-summary
  - rules/payment-callback-common-checks
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T14:40:00+08:00" }
---

## 這個主題在講什麼

CCPayment 是加密貨幣金流商，目前接的是 USDT 在 TRC20 鏈上的代收與代付。

代收採「永久收款地址」：每位會員在一條 CCPayment 支付通道上有一個固定的鏈上收款地址（地址被標為風險地址，或金流商戶換成另一個 CCPayment 商戶帳號時才會換發），會員自己從錢包轉帳到這個地址，不在平台上填金額。款項到鏈上後，CCPayment 打回調通知平台，平台向 CCPayment 回查確認後，才建立充值訂單並上分，也就是「款到才建單」。

代付時，會員選取款銀行「USDT - TRC20」並填自己的鏈上地址。取款訂單審核通過後，由 CCPayment 出款到該地址。

CCPayment 會把部分入帳標成「風險交易」。這類款項不進金流商戶餘額，營運只能到 CCPayment 後台退款，CCPayment 處理後也不通知平台。平台因此這樣處理：

- 建單時就把這類充值訂單鎖住，不准手動上分。
- 再用排程查退款結果，決定退回付款人（拒收），或解鎖讓營運上分（收下）。

金流商手續費怎麼記（代收的服務費、代付的網路費）屬於 [rules/payment-cost-summary](payment-cost-summary.md)，本份只寫風險交易退款後補記的那一步。

本份目前收錄的範圍：MR !2246 建立的取地址、入帳回調、代付與回調補送，MR !2369 建立的風險交易上鎖與退款同步，以及 MR !2463 改成每次向目前金流商戶確認收款地址、換了商戶帳號就換發。

## 怎麼觸發

- 會員 App › 充值頁 › 選 USDT 的「CCPayment USDT (TRC20)」：直接顯示收款地址、QR 碼與複製鈕。
- 會員 App › 取款頁 › 取款銀行「USDT - TRC20」送出取款。審核在代理後台 › 自助洗分 › 洗分訂單審核。
- 代理後台 › 充值訂單 › 手動上分、重置狀態：風險交易的充值訂單在這裡被擋（畫面名稱待確認）。
- 三方回調：鏈上入帳與風險地址通知打代收回調網址，代付結果打代付回調網址。
- 排程：`ccpayment:resend-missing-webhooks` 每 30 分鐘補送回調。`ccpayment:sync-risky-refunds` 每 10 分鐘同步風險交易的退款結果。

## 規則

### 收款地址

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 會員打開充值頁的充值方式清單時，系統向目前的 CCPayment 金流商戶確認這位會員在這條支付通道的收款地址，帶在清單上。還沒有地址就取一個存起來；金流商回的地址跟已存的相同就沿用；不同（例如金流商戶換成另一個 CCPayment 商戶帳號）就把舊地址停用、保留不刪，改存新地址。確認結果記住 5 分鐘，這段時間內再打開不重新確認；以下兩種情況不等 5 分鐘，下次打開就重新確認：金流商戶換了商戶帳號，或記住的地址已經停用（例如 R-13 的風險地址）。 | code:MemberCryptoWalletService::walletsForChannels、code:CCPayment\Deposit::addressScope |
| R-2 | 清單確認地址失敗時，該支付通道照常列出、地址留空，也不顯示先前存的地址，其他支付通道不受影響。向金流商取址失敗後，同一條支付通道一分鐘內清單不再向金流商取地址（會員主動取址不受這個限制）；這段時間內，5 分鐘內在清單確認過地址的會員照常看得到地址，其他會員地址留空。 | code:MemberCryptoWalletService::walletsForChannels |
| R-3 | 會員可以主動要求取得收款地址（App 主動取址，或清單取址失敗後重取）。系統每次都向目前的金流商戶確認，不沿用記住的確認結果：還沒有地址就取一個存起來，地址跟已存的相同就沿用，不同就停用舊地址、改存新地址。向金流商取址失敗時回應錯誤，這條支付通道也照 R-2 一分鐘內不再從清單取址。對不提供收款地址的支付通道要求時，回應錯誤（HTTP 400），不向金流商取。 | code:CCPayment\Deposit::getOrCreateAddress、code:MemberCryptoWalletService::getOrCreateForChannel |
| R-4 | 會員對 CCPayment 支付通道送出「填金額建充值訂單」會被擋下，回應錯誤（HTTP 400，訊息原文「Payment channel does not accept deposit orders」），不建任何充值訂單。 | code:DepositController::createOrder、code:CCPayment\Deposit::handle |
| R-5 | CCPayment 支付通道的充值頁不帶金額輸入欄位，會員直接轉帳，金額以鏈上實收為準。 | code:CCPayment\Deposit::handle |

### 鏈上入帳

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 金流商回調鏈上入帳後，系統一律先向金流商回查這筆入帳。回查結果是成功、幣別與鏈別等於該支付通道的設定，而且不是風險交易，才建立充值訂單並上分。充值訂單的金額是回查到的鏈上實收數量。 | code:CCPayment\DepositCallback::handle |
| R-7 | 回查結果還在處理中或已失敗時，不建充值訂單。處理中的，等金流商下一次回調再判定。 | code:CCPayment\DepositCallback::handle |
| R-8 | 回查到的幣別或鏈別和該支付通道的設定不同時（例如會員轉了別種幣），不建單、不上分，發告警，由營運到金流商後台處理。 | code:CCPayment\DepositCallback::handle |
| R-9 | 回調對不到我方的支付通道或會員，或回查到的收款地址不屬於該會員時，不建單、不上分，發告警。 | code:CCPayment\DepositCallback::handle |
| R-10 | 實收數量低於支付通道的單筆下限時，照常建單上分，只留紀錄。實收數量不是正數時，不建單並告警。 | code:CCPayment\DepositCallback::handle |
| R-11 | 同一筆鏈上入帳的回調重複送達或同時送達時，只會有一張充值訂單。 | code:CCPayment\DepositCallback::handle |
| R-12 | 回調驗簽失敗時，平台回應非成功，金流商會重送，所以不會因為一次簽名錯誤漏掉款項。 | code:CCPaymentTrait::verifyWebhookSignature |
| R-13 | 收款地址被金流商標為風險地址時，系統停用這個地址、向金流商解綁並發告警。會員下次打開充值頁或主動取址時，拿到新地址。系統不自動通知會員，由營運視情況手動發站內信。 | code:CCPayment\DepositCallback::handle |
| R-14 | 同一個金流商戶內的地址停用或換發（例如 R-13 的風險地址）之後，仍轉進舊地址的款照常對回原會員入帳。金流商戶換成另一個 CCPayment 商戶帳號後，轉進舊商戶地址的款不在本條範圍，見「還沒確認的事」。 | code:CCPayment\DepositCallback::handle |

### 風險交易

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 回查結果成功，但被金流商標為風險交易時，充值訂單建為狀態 4（待手動確認上分）、不上分，並發告警。建單的同時，系統把這張單登記為待人工確認並鎖住。處理結果確認前，後台的手動上分與重置狀態依這個鎖被擋（擋下的檢查實作在舊代理後台，本份依據的是鎖的寫入與解除）。 | code:CCPayment\DepositCallback::handle、table:deposit_manual_reviews |
| R-16 | 排程每 10 分鐘向金流商查風險交易的退款結果，只採用退款成功的紀錄。還沒有退款紀錄，或退款處理中的，下一輪再查。 | command:ccpayment:sync-risky-refunds |
| R-17 | 退款退回原付款地址時，充值訂單改為狀態 2（失敗），仍不可上分。 | code:CCPayment\RiskyRefundSync |
| R-18 | 退款退到營運方登記的收款地址白名單時，充值訂單維持狀態 4，但解除鎖定，由營運按手動上分。 | code:CCPayment\RiskyRefundSync |
| R-19 | 以下情況不改單、仍不可上分，並發告警：退款地址既不是原付款地址也不在白名單、退款只有失敗紀錄、一筆入帳對到兩筆以上成功退款。同一筆入帳的同一種情況，24 小時內只告警一次。 | code:CCPayment\RiskyRefundSync |
| R-20 | 營運用批量退款一次退多筆風險交易時，每張充值訂單各自判定、各自更新。 | code:CCPayment\RiskyRefundSync |
| R-21 | 鎖還在，但充值訂單已經不是狀態 4 時，排程不處理這張單並發告警，請人工查核。 | code:CCPayment\RiskyRefundSync |
| R-22 | 依 R-17 或 R-18 更新訂單時，同時補記這張單的金流商手續費（換算規則見 [rules/payment-cost-summary](payment-cost-summary.md)）。單上已有值就不覆寫。重跑排程不會重複寫入。 | code:CCPaymentTrait::actualPaymentCost |

### 代付

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 會員選取款銀行「USDT - TRC20」並填鏈上地址，取款訂單審核通過後，系統以 CCPayment 代付出款到該地址。出哪種幣、走哪條鏈，由所選取款銀行決定。金流商後台沒啟用該幣或該鏈，或金流商戶憑證沒設定時，在送出代付之前就判定失敗，訂單可以重新發起。 | code:CCPayment\Withdrawal::handle |
| R-24 | 網路費預設由會員負擔，會員實收等於取款金額減網路費。金流商戶設定可以改成由平台負擔。 | code:CCPayment\Withdrawal::handle |
| R-25 | 送出代付時，金流商回的錯誤碼若能證明沒有受理（例如餘額不足、地址格式錯誤、幣或鏈不支援），取款訂單轉三方失敗（29），可以重新發起。其他回應（含未知錯誤碼、重複提交、限流）一律當成結果未知，訂單停在代付提交中（21）並告警，由人工到金流商後台查證，避免重複出款。 | code:CCPayment\Withdrawal::handle |
| R-26 | 收到代付結果回調後，系統回查金流商的代付紀錄來定案：成功轉三方成功（24），失敗或被金流商戶拒絕轉三方失敗（29），處理中或等待審核不改狀態。 | code:CCPayment\WithdrawalCallback::handle |
| R-27 | 代付成功，但回查的金額和取款訂單金額不符時，照常定案為成功，只發告警。 | code:CCPayment\WithdrawalCallback::handle |
| R-28 | 同一筆代付的結果回調重複送達時，只處理一次。 | code:CCPayment\WithdrawalCallback::handle |

### 回調補送

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-29 | 排程每 30 分鐘比對一次，找出金流商端已成功、平台卻沒有充值訂單的入帳紀錄，請金流商重送回調。重送過仍缺才告警，不重複重送。排程本身不建單，入帳一律走回調。 | command:ccpayment:resend-missing-webhooks |
| R-30 | 取款訂單停在代付處理中（22）超過 2 小時的，同一個排程請金流商重送代付結果回調，由回調定案。排程本身不改單。 | command:ccpayment:resend-missing-webhooks |
| R-31 | 某一條支付通道查不到入帳紀錄（金流商拒絕、或後台沒啟用該幣或該鏈）時，只跳過那一條，其他支付通道照常比對。 | command:ccpayment:resend-missing-webhooks |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 會員第一次打開充值頁 | 清單直接帶出新取的固定地址；5 分鐘內再打開不重新確認，之後再打開，金流商回同一個地址就照舊顯示 | R-1 |
| 金流商戶換成另一個 CCPayment 商戶帳號，會員再打開充值頁 | 清單顯示新商戶給的地址，舊地址停用、保留不刪 | R-1 |
| 金流商取址 API 故障 | CCPayment 這一項地址空白，不顯示先前存的地址，其他支付通道照常；一分鐘內再開，不再向金流商取 | R-2 |
| 會員轉 10 USDT 到自己的地址 | 回查成功後建 10 USDT 的充值訂單並上分 | R-6 |
| 會員把別種幣轉進 USDT 地址 | 不建單，告警，營運到金流商後台處理 | R-8 |
| 同一筆入帳回調送了三次 | 只有一張充值訂單 | R-11 |
| 風險交易，營運在金流商後台退回付款人 | 下一輪排程後訂單變狀態 2，手動上分仍被擋 | R-15、R-17 |
| 風險交易，營運退到白名單地址 | 訂單仍是狀態 4、解鎖，營運按手動上分後才入帳 | R-15、R-18 |
| 風險交易退到不明地址 | 訂單不變、仍不可上分，告警 | R-19 |
| 代付回「餘額不足」 | 取款訂單轉 29，可重新發起 | R-25 |
| 代付回未知錯誤碼 | 停在 21 並告警，人工查證 | R-25 |
| 代付卡在 22 已 3 小時 | 排程請金流商重送回調；卡 1 小時的不處理 | R-30 |

## 還沒確認的事

- 金流商戶換成另一個 CCPayment 商戶帳號後，會員仍轉進舊商戶地址的款能不能入帳、要怎麼處理：入帳回調的驗簽與回查都用目前的商戶帳號，舊商戶的回調推論會驗簽失敗（從程式推論，未實測）；款項處理主單 [target/tickets#4564](http://example.invalid:1688/target/tickets/-/work_items/4564) 列為不在範圍內，要另外向 CCPayment 確認（尚未開單）。
- 金流商戶換走商戶帳號再換回原帳號後，CCPayment 會不會回傳換帳號期間已停用的原地址還沒驗證；會的話，系統先解綁原地址再取一次，仍是原地址時清單地址留空、主動取址回應錯誤（目前的處理方式見 MR [!2463](http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2463) 的「待決與後續」）。
