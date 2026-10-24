# A3Pay 待廠商確認事項

> 接入期間的暫時性文件。廠商回覆後：屬規格者補進 `api.md`、屬已驗證落差者依 litmus test 補進 `integration.md`；全部結案後本檔可刪。
> 提問管道：工單 target/tickets#4394 所附 TG 群「A3Pay - V-World(Online)」。

| # | 優先級 | 狀態 | 題目 | 對應 api.md 章節 |
|---|---|---|---|---|
| Q1 | 中 | **已結案** | 回調 `Amount` 是原始下單金額還是被上調後的 `payinActualAmount`？ | Payin › Submit Payin 回應、Payin Callback |
| Q2 | 中 | 部分結案 | Submit Payout 被拒（isSuccess=false）是否一律走 HTTP 400、且一律代表未建單？有無錯誤碼清單？ | Payout › Submit Payout、通用回應 |
| Q3 | 中 | 待問 | Payin `OB` 是否需要 `FromCustomerBankCode`？需要的話收銀台能否讓會員自己選銀行？ | Payin › Submit Payin |
| Q5 | 高 | **已結案** | 測試商戶 `gff` 打 CreatePayin 任何金額、OB／BQR 都回 `PMT10005 Invalid Transaction Amount`，是否通道未開或限額未設？ | Payin › Submit Payin |
| Q4 | 低 | **已結案** | 信封 key 大小寫（`merchantCode` vs `MerchantCode`）伺服端是否不分大小寫？ | 通用信封 |

## Q1 回調金額口徑

- **問法**：SGD 同收款帳戶同金額時系統會把應付金額上調（100 → 100.01）。Payin Callback 的 `Amount` 與 `NetAmount` 是以 100 還是 100.01 計？
- **影響**：我方回調以 `Amount` 對訂單金額比對；2026-09-24 起 `DepositCallback` 對「往上 ≤ 0.10」的差額視為同金額照訂單金額入帳，少付或超過仍轉人工確認單（`WAITING_MANUAL_DEPOSIT`）並告警。`A3Pay\Deposit` 建單時對 `payinActualAmount` 與訂單金額不同的情況記 warning。
- **驗證方式（不必問廠商）**：dev 連續送兩筆同金額到同一收款帳戶，看第二筆 Submit Payin 回應的 `payinActualAmount` 有沒有上調、付款後回調 `Amount` 帶原始還是實付金額，以及訂單是否照預期直接 SUCCESS。
- **結果（2026-09-24 QA 實測）**：第二筆同金額的 `payinActualAmount` 上調為 10.01，回調 `Amount` 帶 10.01（實付）、`NetAmount` 9.78。Tim 拍板：會員真的多付，訂單改以實付金額入帳（`DepositCallback::recalculateForActualAmount`），不轉人工。

## Q2 代付拒絕形狀

- **問法**：Submit Payout 被拒（餘額不足、限額、帳號無效、參數錯誤）時 HTTP 狀態碼是什麼？是否有 `errorList.ErrorCode` 清單？哪些碼代表訂單**未**建立？
- **影響**：`A3Pay\Withdrawal` 目前以「HTTP 4xx 且 isSuccess=false」視為明確拒絕（訂單轉 29 可重新發起），其餘一律結果未知（訂單停 21 鎖重送）。若廠商說 2xx 也可能帶 isSuccess=false 的拒絕，會有拒絕單卡 21 要人工解；若 4xx 也可能已建單，現行判定會有重複出款風險，必須立即收緊。
- **實測（2026-09-24）**：測試商戶餘額 0 打 SubmitPayout，三種拒絕都是 HTTP 400 JSON 信封、`data.transactionId` null：PMT10013 餘額不足、PMT10001 銀行碼錯、PMT10005 金額低於下限。`A3Pay\Withdrawal` 已改為只認這三個碼為明確拒絕（白名單），其他 4xx 停 21。`GetPayoutDetail` 查無回純文字 `Not found` 廠商已確認（Mizuko）。
- **仍待廠商**：完整 errorCode 清單；帳號無效／收款戶名不符這類「受理後才失敗」的情境是走同步拒絕還是回調 Status 5。

## Q3 OB 銀行碼

- **問法**：`PaymentMethodId=OB` 是否必須帶 `FromCustomerBankCode`？不帶時收銀台是否讓會員自選銀行？
- **影響**：目前 `providers/A3Pay.php` 的 OB 通道沒掛銀行子選項、不送 `FromCustomerBankCode`。若必填，要在 `providers/A3Pay.php` 的 OB 通道補銀行 options（子層 OrderType），並在 Deposit 帶子層 key 作 `FromCustomerBankCode`。
- **廠商回覆**：（待）

## Q4 信封 key 大小寫

- **問法**：文件 Payin 範例信封用小寫（`merchantCode/data/signature/version`），其餘範例用大寫。伺服端是否兩者皆收？
- **影響**：我方送小寫信封（照 Payin 範例）。若只收大寫，改 `A3PayTrait::sendRequestRaw` 的 key 即可。
- **結案（2026-09-24 實測）**：小寫信封打 `CheckAccBalance` 回 200 且 `data.mid` 正確，可用；不必再問。

## Q5 測試商戶代收全部 PMT10005

- **問法**：MerchantCode `gff`，`/api/Payin/CreatePayin`，`PaymentMethodId` OB 與 BQR、`PayinAmount` 13／50／100／1000，全部回 HTTP 400 `errorCode PMT10005 Invalid Transaction Amount`、`transactionId` 為 null。CheckAccBalance 同一組憑證回 200，簽名與加密沒問題。請確認 gff 的 OB／BQR 通道是否啟用、後台限額是否已設。
- **影響**：dev 驗收卡住，代收一筆都建不了。
- **結案（2026-09-24）**：廠商端處理後同樣請求即成功（dev 訂單 13906 取得 `paymentOrderId`）；PMT10005 是通道未開，不是金額。

