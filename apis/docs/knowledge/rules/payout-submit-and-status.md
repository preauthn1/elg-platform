---
type: Rule
id: rules/payout-submit-and-status
title: 代付送件與狀態流轉
description: 客服在洗分訂單審核按「設定代付」後，系統送件前擋什麼、金流商回什麼會變成什麼狀態、回調怎麼處理、哪些情況告警。
domain: 金流
subdomain: 會員提現（洗分）
tags: [金流, 代付, 取款, 提款, 提現, 出款, 設定代付, 代付銀行碼, 三方銀行碼, 回調, 告警, 重複出款, 三方時間戳記, KIRA, AntzPay, SurePay, FPay, GMPay, TakaPay, VaderPay, payout, withdrawal]
entities:
  - class: App\Services\WithdrawalPayoutService
  - class: App\Services\Payment\Traits\WithdrawalPayoutTrait
  - class: App\Services\Payment\Providers\FPay\Withdrawal
  - class: App\Services\Payment\Providers\GMPay\Withdrawal
  - class: App\Services\Payment\Providers\TakaPay\Withdrawal
  - class: App\Services\Payment\Providers\VaderPay\Withdrawal
  - class: App\Services\Payment\Providers\SurePay\Withdrawal
  - class: App\Services\Payment\Providers\Kira\Withdrawal
  - class: App\Services\Payment\Providers\Kira\WithdrawalCallback
  - class: App\Services\Payment\Providers\AntzPay\Withdrawal
  - class: App\Services\BotNotifyService
  - command: withdrawal:resolve-payout
evidence:
  - kind: code
    ref: /app/Services/WithdrawalPayoutService.php
    title: 設定代付的兩段式送件（先寫代付提交中，送件後依同步結果定狀態）
    at: 2026-10-20
  - kind: code
    ref: /app/Services/Payment/Traits/WithdrawalPayoutTrait.php
    title: 代付請求紀錄建立、同步結果分類、代付銀行碼查表、回調的訂單狀態檢查
  - kind: code
    ref: /app/Services/Payment/Providers/FPay/Withdrawal.php
    title: FPay 查不到代付銀行碼時先回失敗
  - kind: code
    ref: /app/Services/Payment/Providers/GMPay/Withdrawal.php
    title: GMPay 查不到代付銀行碼時先回失敗
  - kind: code
    ref: /app/Services/Payment/Providers/TakaPay/Withdrawal.php
    title: TakaPay 代付銀行碼只取啟用中的對照
  - kind: code
    ref: /app/Services/Payment/Providers/VaderPay/Withdrawal.php
    title: VaderPay 代付銀行碼只取啟用中的對照
  - kind: code
    ref: /app/Services/Payment/Providers/SurePay/Withdrawal.php
    title: SurePay 錯誤表以外的碼帶出拒絕原因
  - kind: code
    ref: /app/Services/Payment/Providers/Kira/Withdrawal.php
    title: KIRA 只依業務碼判定明確拒絕
  - kind: code
    ref: /app/Services/Payment/Providers/Kira/WithdrawalCallback.php
    title: KIRA／AntzPay 已判拒絕的請求再收到回調時分流
  - kind: code
    ref: /app/Services/Payment/Providers/AntzPay/Withdrawal.php
    title: AntzPay 的明確拒絕白名單（業務碼 0、401）
  - kind: code
    ref: /app/Console/Commands/Order/ResolvePayoutOrder.php
    title: 代付停留單的人工定案命令，只處理 21、22
  - kind: code
    ref: /app/Services/BotNotifyService.php
    title: 交易回滾後告警照送並加註
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2215
    title: FPay、GMPay 代付查不到代付銀行碼時先回失敗，不再把空碼送給金流商
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2228
    title: 代付銀行碼查表只認啟用中的對應、判空一律逐字比對
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2235
    title: 移除已廢除的代付狀態 23（三方查證中）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2372
    title: 代付建紀錄不再把我方時間塞進三方時間戳記
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2198
    title: SurePay 代付被拒的錯誤碼不在錯誤表時，客服畫面改帶出拒絕原因
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2271
    title: KIRA 代付業務碼非 200 判明確拒絕，已判拒絕的單再收到回調時分流處理
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2274
    title: KIRA 代付只依業務碼判定明確拒絕，HTTP 錯誤回到結果未知
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2285
    title: 代付回調被訂單狀態檢查拒絕時，防重複出款告警改在交易結束後送出
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2292
    title: 告警改為交易回滾後照樣送出並標註（R-14 依此）
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4137
    title: 代付事務邊界改造（廢除狀態 23）
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4393
    title: KIRAPAY 代付除了 code 200 以外都判斷失敗
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3175
    title: SurePay 代付錯誤訊息只顯示 Unknown error code
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3215
    title: TPower KIRA 代付回業務碼 3001 停在代付提交中
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3269
    title: 代付回調狀態檢查的告警送不出去
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3467
    title: TPower 新幣代付訂單時間不準確
related:
  - rules/payment-callback-common-checks
  - rules/payout-bank-code-mapping
  - rules/provider-a3pay
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

會員的取款訂單審核通過後，客服在代理後台替這張取款訂單選一個代付的金流商戶，按「設定代付」，系統就把出款請求送給金流商。

送件前，系統先把訂單轉成「代付提交中」(21)，擋住重複送件。之後依金流商當下的回應決定訂單去向：

- 金流商受理：訂單轉「代付處理中」(22)，等回調。
- 金流商明確拒絕：訂單轉「三方失敗」(29)，客服可以重新設定代付。
- 看不出結果：訂單停在 21，等回調或人工核對。

最後的成敗由金流商的回調定案：成功是「三方成功」(24)，失敗是「三方失敗」(29)。

「明確拒絕」與「結果未知」要分開處理：

- 明確拒絕代表金流商確定不會出款，重新送件不會重複出款。
- 結果未知代表金流商可能已經收單，這時重新送件可能重複出款，所以訂單停在 21 不放行。

本份目前收錄的範圍：

- 送件前的代付銀行碼檢查（MR !2215、!2228）。
- SurePay、KIRA 與 AntzPay 的回應判定（MR !2198、!2271、!2274）。
- 代付回調的訂單狀態檢查與告警（MR !2235、!2285）。
- 人工定案命令不再處理狀態 23（MR !2235）。
- 代付請求紀錄的三方時間戳記（MR !2372）。
- 理解以上條目所需的送件與狀態流轉規則。

其他金流商的個別判定隨後續 MR 補入。代付銀行碼對照表本身怎麼維護，見 [rules/payout-bank-code-mapping](payout-bank-code-mapping.md)；回調的來源 IP 與簽名檢查，見 [rules/payment-callback-common-checks](payment-callback-common-checks.md)。

## 怎麼觸發

- 送件：代理後台 › 自助洗分 › 洗分訂單審核 › 設定代付。
- 派款結果：金流商的代付回調（沒有畫面）。
- 停在代付提交中的訂單：排程每 5 分鐘掃描並告警（沒有畫面）。人工核對後，在洗分訂單審核以「填寫三方單號」或「修改為代付失敗」定案，或由 RD 在命令列執行人工定案命令。

## 規則

### 送件與同步結果

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 只有狀態是「通過」(3) 或「三方失敗」(29) 的取款訂單可以設定代付。其他狀態（含代付提交中 21、代付處理中 22、三方成功 24）按下後擋下，回應「Order status not allowed for payout request」，訂單狀態不變，不送任何請求給金流商。 | code:WithdrawalPayoutService::submit |
| R-2 | 設定代付時，訂單先轉為「代付提交中」(21) 再送件。金流商當下受理時，訂單轉「代付處理中」(22) 並記下三方單號，等回調定案。 | code:WithdrawalPayoutService::submit |
| R-3 | 金流商當下明確拒絕時，訂單轉「三方失敗」(29)，客服看到的錯誤提示就是拒絕原因。客服可以換金流商戶，或處理原因後，重新設定代付。 | code:WithdrawalPayoutService::submit |
| R-4 | 送件逾時、連線失敗，或金流商的回應無法判定為受理或明確拒絕時，訂單停在「代付提交中」(21)，回應「Payout result unknown, order stays at payment submitted」，並發告警，請人工到金流商後台核對是否出款。這張取款訂單不能再設定代付（見 R-1），只能等回調或人工定案。 | code:WithdrawalPayoutService::submit |

### 送件前的代付銀行碼檢查

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 走 FPay、GMPay、TakaPay、VaderPay 代付時，若訂單的取款銀行查不到代付銀行碼，系統不送件給金流商，直接判明確拒絕。訂單轉「三方失敗」(29)，錯誤提示為「Provider bank code not configured for bank: <我方銀行碼>」，補好對照或換金流商戶後，可以重新設定代付。FPay 在這種情況下連登入憑證都不去取。 | code:FPay\Withdrawal::handle、code:GMPay\Withdrawal::handle |
| R-6 | TakaPay、VaderPay 查代付銀行碼時只認啟用中的對照。同一家銀行同時留著停用與啟用的對照時，用啟用的那筆；啟用的有多筆時，取最早建立的那筆。只剩停用的對照時，視同查不到（見 R-5）。 | code:TakaPay\Withdrawal::handle、code:VaderPay\Withdrawal::handle |
| R-7 | 代付銀行碼是 `0` 時是有效的碼，照常送給金流商，不會被當成沒設定而擋下。只有空白才算查不到。 | code:WithdrawalPayoutTrait::convertPayoutBankCode |

### 金流商當下回應的判定

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | SurePay 拒絕代付、錯誤碼不在系統的錯誤表裡時，錯誤提示帶出金流商給的原因，例如「Unknown error code: -110 (Invalid Credential)」。金流商沒給原因，或原因只有空白時，只顯示「Unknown error code: <碼>」。錯誤表裡有的碼照舊顯示表內文字。這三種情況都判明確拒絕（見 R-3）。 | code:SurePay\Withdrawal::handle |
| R-9 | KIRA 代付請求的 HTTP 回應正常，但業務碼不是 200 時（例如 400、1200、3001、4001），判明確拒絕，訂單轉「三方失敗」(29)，可以重新設定代付。 | code:Kira\Withdrawal::handle |
| R-10 | KIRA 代付請求的 HTTP 狀態不是 2xx 時（含 520、522，即使回應內容帶了業務碼），以及連線逾時、回應解不出數字業務碼、業務碼 200 卻沒有三方交易號時，系統一律判結果未知，訂單停在「代付提交中」(21)（見 R-4）。 | code:Kira\Withdrawal::handle |
| R-11 | AntzPay 和 KIRA 共用同一套程式，但 AntzPay 只有業務碼 0 與 401 判明確拒絕。其他業務碼（含 400）與所有 HTTP 錯誤一律判結果未知，訂單停在「代付提交中」(21)。 | code:AntzPay\Withdrawal::isRejectedBusinessCode |

### 代付回調

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-12 | 代付回調只在訂單是「代付提交中」(21) 或「代付處理中」(22) 時處理並定案。訂單是「三方失敗」(29) 時拒絕這次回調、不改訂單，並告警「訂單已標記三方失敗(29)仍收到回調，已拒絕。若該回調為成功通知，訂單可能遭誤標，重新發起將導致重複出款，請立即人工核對三方實際出款結果」。 | code:WithdrawalPayoutTrait::checkWithdrawalOrderStatus |
| R-13 | 訂單是 21、22、29 以外的狀態時（含已退役的狀態 23「三方查證中」），代付回調一律拒絕、不改訂單，並告警「訂單狀態不合預期」與當前狀態值。狀態 23 已廢除，系統不再寫入；出現 23 代表有未知的寫入來源，要先追查。 | code:WithdrawalPayoutTrait::checkWithdrawalOrderStatus |
| R-14 | R-12、R-13 的告警在回調被拒絕、整筆處理撤銷之後照樣送出，不會跟著消失。訊息開頭會加註「【交易已回滾，以下內容描述的寫入未生效】」。 | code:BotNotifyService |
| R-15 | KIRA 或 AntzPay 的代付請求已在當下判明確拒絕，之後又收到這筆請求的非成功回調（FAILED、PENDING 等）時，系統回覆金流商已收到，不改訂單、不告警。FAILED 會留下回調內容。訂單即使還停在「代付提交中」(21)，也不由這個回調替它定案。 | code:Kira\WithdrawalCallback::handle |
| R-16 | KIRA 或 AntzPay 的代付請求已在當下判明確拒絕，之後卻收到這筆請求的成功回調時，不論訂單目前狀態，一律告警「代付同步回應已判明確拒絕（確定不會出款），卻收到成功回調：拒絕判定可能有誤，訂單可能重複出款，請立即人工核對三方實際出款結果」。訂單還在 21 或 22 時，照常定案為「三方成功」(24)。訂單已是 29 或 24 時，回調被拒絕，並另外收到 R-12 或 R-13 的告警。 | code:Kira\WithdrawalCallback::handle |

### 人工定案命令

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-17 | 代付停留的取款訂單用人工定案命令處理。這支命令只處理「代付提交中」(21) 與「代付處理中」(22) 的訂單，可定為「三方失敗」(29) 解鎖重送，或定為「三方成功」(24)。其他狀態（含狀態 23 與終態 24）一律拒絕執行，訂單不變。 | command:withdrawal:resolve-payout |

### 代付請求紀錄

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-18 | 送件時建立的代付請求紀錄，「三方時間戳記」留空，只由回調帶回的時間寫入。回調抵達前，代理後台這一欄顯示空白，不再顯示我方送件時間。送件時間看代付請求紀錄的建立時間。2026-10-07 之前建立的紀錄不回補，可能仍是我方送件時間。 | code:WithdrawalPayoutTrait::createPayoutRecord |

## Examples

KIRA 代付請求的各種回應：

| KIRA 的回應 | 訂單結果 | 依據 |
|---|---|---|
| HTTP 200，業務碼 200，帶三方交易號 | 代付處理中(22)，等回調 | R-2 |
| HTTP 200，業務碼 3001 | 三方失敗(29)，可重新設定代付 | R-9 |
| HTTP 400，內容帶業務碼 3001 | 停在代付提交中(21)，告警 | R-10、R-4 |
| HTTP 522 | 停在代付提交中(21)，告警 | R-10 |
| 業務碼 3001 判拒絕後，事後補送 FAILED 回調 | 訂單維持 29，不告警 | R-15 |
| 業務碼 3001 判拒絕後，事後補送 SUCCESS 回調，訂單已是 29 | 回調被拒絕，訂單維持 29，收到兩則告警 | R-16、R-12 |
| 同樣是 HTTP 200、業務碼 400，但走的是 AntzPay | 停在代付提交中(21)，告警；KIRA 則轉 29 | R-11、R-9 |
