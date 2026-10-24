---
type: Rule
id: rules/provider-a3pay
title: A3Pay 代收與代付
description: 新加坡幣金流商 A3Pay 的代收（網銀 OB、掃碼 BQR）與代付（銀行轉帳）怎麼建單、怎麼判讀金流商回應與回調、什麼情況交人工。
domain: 金流
subdomain: 會員儲值（上分）
tags: [金流, 金流商, A3Pay, 代收, 代付, 充值, 取款, 儲值, 上分, 提款, 提現, 出款, 回調, 新加坡幣, SGD, VWorld-SG, deposit, withdrawal, payout, payout verification, callback]
entities:
  - class: App\Services\Payment\Providers\A3Pay\Driver
  - class: App\Services\Payment\Providers\A3Pay\A3PayTrait
  - class: App\Services\Payment\Providers\A3Pay\Deposit
  - class: App\Services\Payment\Providers\A3Pay\DepositCallback
  - class: App\Services\Payment\Providers\A3Pay\Withdrawal
  - class: App\Services\Payment\Providers\A3Pay\WithdrawalCallback
  - class: App\Services\Payment\Providers\A3Pay\WithdrawalQuery
  - command: withdrawal:alert-stuck-submitted
evidence:
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/Driver.php
    title: A3Pay Driver，掛代收、代付、回調、放款前確認與代付查單
    at: 2026-10-20
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/A3PayTrait.php
    title: 加解密、簽名、送請求與錯誤訊息組法（describeError）
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/Deposit.php
    title: 代收建單與導頁方式
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/DepositCallback.php
    title: 代收回調，三方唯一化上調改以實付金額入帳
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/Withdrawal.php
    title: 代付建單的明確拒絕白名單與代付查單
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/WithdrawalCallback.php
    title: 代付回調三態判讀
  - kind: code
    ref: /app/Services/Payment/Providers/A3Pay/WithdrawalQuery.php
    title: 放款前確認（Payout Verification）的核對順序
  - kind: code
    ref: /app/Console/Commands/CashFlow/Data/providers/A3Pay.php
    title: 代收通道、代付銀行與單筆限額的預設配置
  - kind: code
    ref: /app/Services/WithdrawalPayoutService.php
    title: 代付結果轉成取款訂單狀態（21／22／29）
  - kind: code
    ref: /app/Services/Payment/Traits/DepositCallbackTrait.php
    title: 代收回調金額不符轉待人工確認上分
  - kind: code
    ref: /app/Console/Commands/Schedule/AlertStuckSubmittedWithdrawal.php
    title: 停在代付提交中的訂單自動查單與告警
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2312
    title: 新增 A3Pay SGD 代收代付
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2324
    title: A3Pay 閘道層錯誤帶出狀態碼，不再一律顯示 Unknown error
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4394
    title: "[GFF][VWorld-SG]A3Pay-SGD 代收代付接入"
  - kind: code
    ref: /docs/integrations/payments/A3Pay/integration.md
    title: A3Pay 實測落差與實作決策索引
related:
  - rules/payment-callback-common-checks
  - rules/payout-submit-and-status
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

A3Pay 是收付新加坡幣（SGD）的金流商，給 VWorld-SG 使用。

- 代收：有兩條支付通道，網銀（OB）與掃碼（BQR）。會員選了支付通道送出充值後，被導到 A3Pay 的收銀台付款。A3Pay 再以回調通知結果，成功才上分。
- 代付：走銀行轉帳。會員的取款訂單審核通過後，平台向 A3Pay 發起出款，A3Pay 受理後以回調通知結果。A3Pay 出款前，也可能回頭向平台確認這筆取款訂單是否該付，稱為放款前確認。

代付的判讀以「寧可交人工，不可重複出款」為原則。只有能證明 A3Pay 沒有建單的拒絕，才讓訂單回到可重新發起的狀態；其他看不清楚的回應一律停住，並告警交人工。

所有金流商共用的回調檢查，例如來源 IP、訂單是否屬於這家金流商、重複回調的冪等處理，見 [rules/payment-callback-common-checks](payment-callback-common-checks.md)，本份不重寫。

已知的金流商行為（實測結果，不是平台規則）：

- A3Pay 的回調只重送 10 次、每 5 秒一次，約 50 秒內沒收到 success 就不再補送。
- 在 dev 成功代付時，A3Pay 沒有來做放款前確認，所以防重複出款不能只靠那一關。

本份目前收錄的範圍：MR !2312（新接 A3Pay 代收與代付）與 MR !2324（代收建單錯誤訊息帶出閘道層的狀態碼）建立的行為。

## 怎麼觸發

- 代收：會員 App › 充值頁，選「A3Pay - Online banking」或「A3Pay - QR code」支付通道送出。
- 代付：會員 App › 取款頁送出取款訂單，審核通過後發起代付。發起代付的後台畫面名稱（待確認）。
- 三方回調：A3Pay 打回平台的代收回調、代付回調，以及代付出款前的放款前確認，都沒有畫面。
- 排程：`withdrawal:alert-stuck-submitted` 每 5 分鐘執行一次，對停在代付提交中的訂單向 A3Pay 查單。

## 規則

### 代收建單

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 會員選 A3Pay 的網銀或掃碼支付通道送出充值，A3Pay 受理後，系統一律把會員導到 A3Pay 的收銀台頁面付款，掃碼支付通道也一樣（收銀台自己會顯示 QR 碼）。只有 A3Pay 沒給收銀台網址、只給 QR 圖時，才改為直接顯示 QR 圖。 | code:A3Pay\Deposit::handle |
| R-2 | 送給 A3Pay 的付款人姓名是會員的英文名加英文姓。會員兩者都沒填時，改用會員帳號，避免 A3Pay 以缺欄位拒單。 | code:A3Pay\Deposit::handle |
| R-3 | A3Pay 回應受理，卻既沒有收銀台網址也沒有 QR 圖時，這次充值當作失敗，會員收到錯誤，不會被導頁。 | code:A3Pay\Deposit::handle |
| R-4 | A3Pay 在業務層拒絕建單時，會員收到的錯誤訊息是「A3Pay deposit error: 」接上 A3Pay 的錯誤說明。A3Pay 有給錯誤訊息就用錯誤訊息，沒有就把錯誤清單串成「錯誤碼: 說明」。 | code:A3Pay\A3PayTrait::describeError |
| R-5 | 請求在 A3Pay 前置閘道就被擋下（例如平台出口 IP 不在 A3Pay 白名單、回 HTTP 403）時，錯誤訊息帶出閘道回的標題與狀態碼，例如「A3Pay deposit error: Forbidden (403)」，不再只顯示「Unknown error」。閘道沒給狀態碼時只帶標題。錯誤訊息、錯誤清單、閘道標題三者都沒有時，才顯示「Unknown error」。 | code:A3Pay\A3PayTrait::describeError |
| R-6 | A3Pay 回的內容不是 JSON 時，錯誤訊息是「A3Pay unparseable response from <端點> (<HTTP 狀態碼>)」，帶出狀態碼，不帶 A3Pay 回應的原文（原文只記在 HTTP log）。連不上 A3Pay 或逾時時，錯誤訊息是「A3Pay request failed: 」接上連線錯誤原因。 | code:A3Pay\A3PayTrait::sendRequestRaw |

### 代收回調

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 代收回調有以下任一情況時被拒絕：缺少加密資料或簽名、解密失敗、簽名驗不過、解密後缺訂單號、狀態或金額、金額不是數字。被拒絕時訂單不動、不回 success，並發錯誤告警。 | code:A3Pay\DepositCallback::handle |
| R-8 | 回調狀態是 6（成功）時，充值訂單轉成「成功(未上分)」，並記下 A3Pay 的單號。狀態是 7（失敗）時，訂單轉失敗。其他狀態（包含 4 Verify Fail）都不是終態（不會再變的最終狀態）：訂單維持原狀、照樣回 success，等 A3Pay 送來終態的回調再結單。 | code:A3Pay\DepositCallback::handle |
| R-9 | 成功回調的金額比訂單多、差額在 0.10 以內（含 0.10）時，視為 A3Pay 為了區分同金額付款而微幅上調（例如下單 10、實付 10.01）。這時訂單金額改成實付金額，會員手續費與入帳點數依建單時同一套公式重算，然後照常轉成功。入帳點數等於實付金額乘以建單時的入帳比例，再扣掉金流商戶設定的固定手續費與浮動手續費。會員多付多少就入帳多少，不轉人工。 | code:A3Pay\DepositCallback::handle |
| R-10 | 成功回調的金額比訂單少（即使只差 0.01），或比訂單多超過 0.10 時，訂單轉成待人工確認上分（狀態 4），金額不改，並發「代收回調金額不符」告警。 | code:A3Pay\DepositCallback::handle、code:DepositCallbackTrait |
| R-11 | 失敗回調帶的金額即使和訂單不同，也只把訂單標失敗，訂單金額不改。 | code:A3Pay\DepositCallback::handle |

### 代付發起

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-12 | 取款訂單的銀行在 A3Pay 對不到代付銀行碼時，不送請求給 A3Pay，這次代付直接失敗，取款訂單轉「三方失敗」（29），可以重新發起。 | code:A3Pay\Withdrawal::handle |
| R-13 | A3Pay 受理並回了單號時，取款訂單轉「代付處理中」（22），記下 A3Pay 的單號，等回調定案。 | code:A3Pay\Withdrawal::handle |
| R-14 | A3Pay 回 HTTP 4xx、明確表示失敗，而且錯誤碼是 PMT10001（銀行碼錯誤）、PMT10005（金額不符限制；支付通道未開通時也會出現）、PMT10013（A3Pay 帳戶餘額不足）三者之一時，視為 A3Pay 沒有建單。取款訂單轉「三方失敗」（29），可以重新發起。 | code:A3Pay\Withdrawal::handle |
| R-15 | 其餘回應一律視為結果未知，包括 HTTP 5xx、回應不是 JSON、HTTP 2xx 卻表示失敗、4xx 但錯誤碼不在 R-14 的三個之內、表示受理卻沒有單號、連不上 A3Pay 或逾時。這時取款訂單停在「代付提交中」（21），不能重新發起，並發告警，請人工到 A3Pay 後台確認。A3Pay 有這筆單時，填寫三方單號轉 22；查無此單時，修改為代付失敗轉 29。 | code:A3Pay\Withdrawal::handle |
| R-16 | 停在「代付提交中」（21）超過 10 分鐘的取款訂單，排程會向 A3Pay 查單。A3Pay 查得到這筆、狀態不是 5（失敗），而且帶有單號時，系統自動補上單號轉「代付處理中」（22），不再告警。A3Pay 回已失敗、查無此單、查單失敗，或回應不完整時，一律不改訂單，照舊告警交人工。 | code:A3Pay\Withdrawal::queryByTransactionNo、command:withdrawal:alert-stuck-submitted |

### 代付回調

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-17 | 代付回調有以下任一情況時被拒絕：缺少加密資料或簽名、解密失敗、解密後缺交易號、狀態或金額、金額不是數字、簽名驗不過。被拒絕時訂單與代付紀錄都不動，並發錯誤告警。 | code:A3Pay\WithdrawalCallback::handle |
| R-18 | 回調狀態是 4（成功）時，取款訂單轉「三方成功」（24）；狀態是 5（失敗）時，轉「三方失敗」（29）。訂單停在「代付提交中」（21）時收到回調，同樣依回調定案。其他狀態視為處理中，訂單與代付紀錄都不動，照樣回 success。 | code:A3Pay\WithdrawalCallback::handle |
| R-19 | 成功回調的金額和取款訂單金額不同時，訂單照樣轉「三方成功」（24），只另發「代付回調金額不符」告警，交人工核對。 | code:A3Pay\WithdrawalCallback::handle |
| R-20 | 取款訂單已是「三方失敗」（29）或其他不合預期的狀態時收到代付回調，處理方式依 [rules/payout-submit-and-status#R-12](payout-submit-and-status.md)、[rules/payout-submit-and-status#R-13](payout-submit-and-status.md)：回調被拒絕、不改訂單，並告警。 | code:A3Pay\WithdrawalCallback::handle |

### 放款前確認

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-21 | A3Pay 出款前來確認時，以下五項全部成立才回 success、允許出款。一，交易號是這個金流商戶的代付紀錄。二，它是該取款訂單最新的一次代付。三，這筆紀錄仍在等待結果（沒有被判為金流商拒絕、請求逾時，也還沒定案）。四，取款訂單在「代付提交中」（21）或「代付處理中」（22）。五，金額與紀錄相符、幣別與金流商戶設定相符。任何一項不符，或簽名驗不過、資料解不開，都回 failed，A3Pay 不出款。 | code:A3Pay\WithdrawalQuery::handle |
| R-22 | 放款前確認只回答能不能出款，不改取款訂單，也不改代付紀錄。取款訂單已是「三方失敗」（29）時回 failed，並發防重複出款告警。 | code:A3Pay\WithdrawalQuery::handle |

### 預設配置

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-23 | 以金流配置命令建立 A3Pay 時，代收預設兩條支付通道「A3Pay - Online banking」與「A3Pay - QR code」，單筆 10 到 5,000 新加坡幣。代付預設支援 20 家新加坡銀行，單筆 50 到 5,000 新加坡幣。成本費率不寫在配置裡，由各站在後台自設。 | code:providers/A3Pay.php |

## Examples

金流商戶固定手續費 1、浮動手續費 0.1（10%），入帳比例 1，充值訂單金額 100：

| 情境 | 結果 | 依據 |
|---|---|---|
| 成功回調金額 100.00 | 成功(未上分)，金額 100 不變 | R-8 |
| 成功回調金額 100.05 | 訂單金額改 100.05，會員手續費 1 + 100.05 × 0.1 = 11.005，入帳點數 100.05 − 11.005 = 89.045 | R-9 |
| 成功回調金額 100.10 | 差額剛好 0.10，金額改 100.10 照常成功 | R-9 |
| 成功回調金額 100.11 | 待人工確認上分，金額不改 | R-10 |
| 成功回調金額 99.99 | 待人工確認上分，金額不改 | R-10 |
| 失敗回調金額 20.01（訂單 20） | 失敗，金額維持 20 | R-11 |

代付發起時 A3Pay 的回應：

| A3Pay 回應 | 取款訂單 | 依據 |
|---|---|---|
| HTTP 200，受理並帶單號 | 代付處理中（22） | R-13 |
| HTTP 400，錯誤碼 PMT10013 | 三方失敗（29），可重新發起 | R-14 |
| HTTP 400，錯誤碼不在 R-14 的三個之內 | 代付提交中（21），告警交人工 | R-15 |
| HTTP 200，表示失敗 | 代付提交中（21），告警交人工 | R-15 |
| HTTP 500 或逾時 | 代付提交中（21），告警交人工 | R-15 |
