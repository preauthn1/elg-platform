---
type: Rule
id: rules/provider-b3wallet
title: B3Wallet 代收與代付
description: B3Wallet 開給會員哪幾條充值的支付通道、代付支援哪 23 家銀行，以及金流商拒絕或回調結果不同時系統怎麼表現。
domain: 金流
subdomain: 會員儲值（上分）
tags: [金流, 充值, 存款, 儲值, deposit, 取款, 提款, withdrawal, 代收, 代付, B3Wallet, B3 Wallet, PayNow, 銀行轉帳, BANK_TRANSFER, SGD, 新加坡, 代付銀行碼, 支付通道, 支付方式]
entities:
  - class: App\Services\Payment\Providers\B3Wallet\Deposit
  - class: App\Services\Payment\Providers\B3Wallet\Withdrawal
  - class: App\Services\Payment\Providers\B3Wallet\DepositCallback
  - class: App\Services\Payment\Providers\B3Wallet\WithdrawalCallback
  - class: App\Services\Payment\Providers\B3Wallet\B3WalletTrait
  - class: App\Services\WithdrawalPayoutService
evidence:
  - kind: code
    ref: /app/Services/Payment/Providers/B3Wallet/Deposit.php
    title: 代收建帳單，依會員所選支付通道決定收款方式
    at: 2026-10-20
  - kind: code
    ref: /app/Services/Payment/Providers/B3Wallet/Withdrawal.php
    title: 代付建單，依取款銀行類型決定出款方式，判讀三方拒絕
  - kind: code
    ref: /app/Console/Commands/CashFlow/Data/providers/B3Wallet.php
    title: 支付通道與 23 家代付銀行的設定檔
  - kind: code
    ref: /app/Services/Payment/Providers/B3Wallet/DepositCallback.php
    title: 代收回調，以查詢帳單結果定案
  - kind: code
    ref: /app/Services/Payment/Providers/B3Wallet/WithdrawalCallback.php
    title: 代付回調，以查詢代付結果定案，不採三方手續費
  - kind: code
    ref: /app/Services/Payment/Providers/B3Wallet/B3WalletTrait.php
    title: 共用請求與查詢，非 2xx 轉為例外
  - kind: code
    ref: /app/Services/WithdrawalPayoutService.php
    title: 代付發起、可用代付渠道過濾、同步結果寫入取款訂單狀態
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2354
    title: B3Wallet 開放多支付方式，代付銀行收斂成 23 家
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4300
    title: "[GFF][TPower]B3 WALLET-SGD 代收代付接入"
  - kind: code
    ref: /docs/integrations/payments/B3Wallet/runbook.md
    title: B3Wallet 上線 runbook（閘門、配置、止血回滾）
related:
  - rules/payment-callback-common-checks
  - rules/payout-submit-and-status
  - rules/payout-bank-code-mapping
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

B3Wallet 是新加坡幣（SGD）的金流商，代收與代付都接。

- 代收：B3Wallet 2026-09 版文件把收款方式從「只有 PayNow」開放成多種，平台據此開出兩條充值的支付通道，PayNow 掃碼與銀行轉帳。會員在充值頁選哪一條，平台就請 B3Wallet 用那種方式收款，會員再到 B3Wallet 的付款頁完成付款。
- 代付：平台向 B3Wallet 出款時，出款方式跟著會員綁的取款銀行走。B3Wallet 只支援 23 家新加坡銀行，會員的取款銀行不在其中時，客服審核取款訂單時就選不到 B3Wallet。

B3Wallet 的回調不帶簽名，平台收到回調後一律回頭向 B3Wallet 查詢，以查詢結果定案，不信回調本身的內容。

本份目前收錄 MR !2354 建立或改變的行為：

- 兩條支付通道。
- 代付銀行清單與出款方式。
- 金流商拒絕與回調的處理。
- 金流商手續費的算法。

同一支 MR 一併修改的分潤金流商手續費統計不屬於 B3Wallet，不收在本份。

## 怎麼觸發

- 充值：會員 App › 充值頁（待確認）選「B3 Wallet - Paynow」或「B3 Wallet - Online Banking」送出，之後跳轉 B3Wallet 付款頁。
- 取款：會員 App › 取款頁送出取款訂單。客服在代理後台 › 自助洗分 › 洗分訂單審核，選代付的金流商戶發起出款（選金流商戶的畫面名稱待確認）。
- 結果：B3Wallet 的三方回調（代收與代付各一支）。

## 規則

### 會員看得到的支付通道

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | B3Wallet 在 SGD 下開兩條充值的支付通道：「B3 Wallet - Paynow」（PayNow 掃碼）與「B3 Wallet - Online Banking」（銀行轉帳），單筆金額範圍都是 10 到 5,000。兩條支付通道背後各是一個金流商戶，可以各自上下架、各自設成本費率。 | code:providers/B3Wallet.php |
| R-2 | 會員選了哪一條支付通道，平台就請 B3Wallet 用那種方式收款。建帳單成功後，會員被導向 B3Wallet 的付款頁完成付款。 | code:B3Wallet\Deposit::handle |
| R-3 | B3Wallet 的「自動選擇方式」與 USDT 鏈上收款都不開給會員。前者看不出會員實際用哪種方式付款；後者 B3Wallet 尚未替平台的金流商戶開通。 | code:B3Wallet\Deposit::handle |
| R-4 | 會員送出充值時，若 B3Wallet 拒絕建帳單（例如該金流商戶沒開通所選的方式、金額低於或高於金流商限額），或連不上 B3Wallet，會員收到錯誤回應，不會被導向付款頁。 | code:B3Wallet\Deposit::handle |

### 充值回調

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 收到 B3Wallet 充值回調後，平台向 B3Wallet 查詢該帳單，依查詢結果處理。已付款或已結算時，充值訂單轉成功，入帳金額以查詢回來的金額為準。已過期或已取消時，充值訂單轉失敗。仍在等待付款時不改訂單，等下一次回調。 | code:B3Wallet\DepositCallback::handle、code:B3WalletTrait::queryBill |
| R-5a | 查詢用的是建單時存下的帳單號，不是回調帶的。回調帶的帳單號跟存下的不一樣時，平台拒絕這筆回調、訂單不動，並發告警（回調沒有簽名，不擋的話可以拿同商戶另一張已付款的帳單來結算這張訂單）。建單時沒存到帳單號的訂單，才改用回調的帳單號去查，而且查回來的我方單號必須是這張訂單。查詢回已付款卻沒帶金額、或查詢打不通時，不入帳，回 HTTP 500 讓 B3Wallet 重送（B3Wallet 只看 HTTP 狀態決定要不要重送，回 2xx 就不再送），並發告警。 | code:B3Wallet\DepositCallback::handle |

### 代付銀行與出款方式

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | B3Wallet 代付只支援以下 23 家 SGD 銀行：DBS Bank Limited、POSB Bank、United Overseas Bank、OCBC Bank、Citibank Singapore、HSBC Bank、Standard Chartered Bank、Maybank Singapore、CIMB Bank、Bank of China Limited、Hong Leong Bank、RHB Bank、Trust Bank、Maribank、GXS Bank、ANEXT Bank、Australia and New Zealand Bank、ING Bank、Bank of East Asia、ICBC Bank、Wise、MatchMove、LiquidPay。 | code:providers/B3Wallet.php |
| R-7 | 會員的取款銀行不在 R-6 的清單時，客服審核這張取款訂單時，B3Wallet 不在可選的代付金流商戶內，原因顯示為「不支援銀行 "<銀行名>" (<銀行代碼>)」。硬送也會被擋下，回應「代付渠道驗證未通過：<原因>」，取款訂單維持原狀態，不會送出給 B3Wallet。 | code:B3Wallet\Withdrawal::handle、code:WithdrawalPayoutService::resolveChannels |
| R-8 | 清單不收各銀行的企業帳戶版本（OCBC、DBS、Maribank、GXS、CIMB 的企業戶）。會員以這些銀行取款時，送給 B3Wallet 的是個人戶的代付銀行碼。 | code:providers/B3Wallet.php |
| R-9 | 出款方式依會員取款銀行的類型決定：加密貨幣類走鏈上出款，PayNow 類走 PayNow 出款，其餘走銀行轉帳。B3Wallet 的代付銀行清單目前只有 R-6 的銀行轉帳銀行，沒有 PayNow 與 USDT，所以實際上 B3Wallet 代付只做銀行轉帳。 | code:B3Wallet\Withdrawal::handle |
| R-10 | 送出代付時一律帶出款的金流商戶的幣別，B3Wallet 從該幣別的錢包扣款。 | code:B3Wallet\Withdrawal::handle |

### 代付結果

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | B3Wallet 以業務理由拒絕代付（例如金流商戶餘額不足、銀行代碼不對）時，取款訂單轉「三方失敗」，失敗原因記下 B3Wallet 回的錯誤訊息，客服可以重新發起代付。 | code:B3Wallet\Withdrawal::handle |
| R-12 | B3Wallet 回伺服器錯誤或連不上時，視為結果未知。取款訂單停在「代付提交中」，不能重送；系統發告警，請人工到 B3Wallet 後台確認有沒有出款，再定案。 | code:B3Wallet\Withdrawal::handle |
| R-13 | B3Wallet 受理代付後，取款訂單轉「代付處理中」。收到代付回調時，平台向 B3Wallet 查詢該筆代付，依查詢結果處理：已付款轉「三方成功」，失敗或取消轉「三方失敗」，其他狀態維持「代付處理中」，等下一次回調。 | code:B3Wallet\WithdrawalCallback::handle、code:B3WalletTrait::queryPayout |
| R-13a | 查詢用的是送出代付時存下的代付單號，不是回調帶的。兩者不一樣時，平台拒絕這筆回調、取款訂單不動，並發告警。送出代付時沒存到代付單號的（結果未知、停在「代付提交中」），才改用回調的代付單號去查，但這個單號不可已經屬於另一次代付請求。查詢回已付款卻沒帶金額、或查詢打不通時，不定案，回 HTTP 500 讓 B3Wallet 重送，並發告警。 | code:B3Wallet\WithdrawalCallback::handle |
| R-13b | 代收建帳單、代收回調、代付回調每一次都留一筆三方往返紀錄，客服可在訂單詳情看到這次的請求、響應與判定結果。建帳單那筆不留請求標頭，因為標頭裡是金流商戶的帳號密碼原文。 | code:B3Wallet\Deposit::handle、code:B3Wallet\DepositCallback::handle、code:B3Wallet\WithdrawalCallback::handle |
| R-14 | 代付成功時，金流商手續費依出款的金流商戶成本費率計算，不採用 B3Wallet 回調帶的手續費。代付失敗時不計金流商手續費。 | code:B3Wallet\WithdrawalCallback::handle |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 會員選「B3 Wallet - Online Banking」充值 100 | 請 B3Wallet 以銀行轉帳收款，會員跳到付款頁看收款銀行資訊 | R-1、R-2 |
| 會員沒付款，帳單過期，B3Wallet 的回調帶未付款 | 查詢得到「已過期」，充值訂單轉失敗 | R-5 |
| 會員取款銀行是 DBS Bank Limited，取款 200 | B3Wallet 是可選的代付金流商戶，以銀行轉帳出款 | R-6、R-9 |
| 會員取款銀行是 OCBC Bank | 送個人戶代碼 OCBC，不是企業戶代碼 | R-8 |
| 會員取款銀行是清單以外的銀行 | 客服選不到 B3Wallet，原因「不支援銀行」 | R-7 |
| 出款時 B3Wallet 回金流商戶餘額不足 | 取款訂單轉「三方失敗」，可改用其他金流商戶重送 | R-11 |
| 出款 50 成功，B3Wallet 回調帶手續費 0.8，出款的金流商戶成本費率 1%、入帳比例 1 | 金流商手續費記 0.5，不是 0.8 | R-14 |
