# A3Pay 對接心得

> 只放「讀 `api.md` ＋ 讀程式碼得不到」的一手實測落差與決策索引。廠商原文見 `api.md`，上線步驟見 `runbook.md`，待廠商回覆見 `open-questions.md`。

## 已驗證的文件落差

| # | 文件說法 | 實測 | 驗證方式／日期 | 對實作的影響 |
|---|---|---|---|---|
| 1 | 通用回應是 JSON 信封（`isSuccess`／`successCode`／`errorMessage`／`errorList`） | `GetPayoutDetail` 查不存在的交易號回 **HTTP 400、純文字 `Not found`**，不是 JSON | 2026-09-24 以 dev 測試商戶（MerchantCode `gff`）實打，交易號 `A3PAYPROBE00000000` | `Withdrawal::queryByTransactionNo` 遇非 JSON 回 UNKNOWN（正確，不推斷 NOT_FOUND）；`Withdrawal::handle` 的 performRequest 對「4xx 但 body 非 JSON」判結果未知（停 21），不會誤判成明確拒絕。這也代表 open-questions Q2「4xx＝未建單」的判定只對 **JSON 形狀** 的 4xx 生效，純文字 4xx 一律停 21 交人工 |
| 2 | 商戶出口 IP 須先加白名單 | 從本機 docker（IP 不在工單七個白名單內）打 `CheckAccBalance` 仍回 200 | 2026-09-24 dev | 測試商戶端可能未啟用 IP 白名單，或白名單只管代收代付不管查詢類接口；**不可**據此假設正式商戶也不驗，上線閘門照舊 |
| 3 | A3Pay 公鑰範例為 1024 位（`MIGf…` 開頭） | 工單 #4394 給的測試商戶公鑰是 **2048 位** | `openssl_pkey_get_details` 2026-09-24 | 無影響，`verifyRsaSignature` 不假設長度 |
| 4 | Payin 回應 `data` 鍵為 `paymentOrderId`／`paymentURL`／`paymentQRCode`／`payinActualAmount` | 上午：成功回文件鍵名、**失敗**回應（HTTP 400）的 `data` 是舊鍵名 `transactionId`／`url`／`qrCode`／`depositActualAmount`。廠商（Mizuko）承認是他們的問題，**同日 16:20 已修**：失敗回應改回文件鍵名（實測 PMT10005／PMT10006 皆是）。請求端只吃文件的新欄位名，用舊名回純文字 `Invalid Request` | 2026-09-24 dev log：失敗 `01a0d17f-…`、成功 `01a0d18c-…`；修復後本機重打 | `A3PayTrait::dataValue()` 兩組鍵名都收，保留不拆：同閘道舊版本隨時可能再冒出來 |
| 5 | 代收單筆限額 10–5,000（TG 口頭） | 廠商端通道未開時，任何金額、OB／BQR 一律 HTTP 400 `PMT10005 Invalid Transaction Amount`、`transactionId` null（未建單），收銀台訊息「Service Not Available」。廠商開通後同樣請求即成功 | 2026-09-24 本機重打 7 次全 PMT10005；廠商處理後 dev 第 3 筆成功 | PMT10005 不一定是金額問題，先問廠商通道狀態（open-questions Q5 已結案） |
| 7 | 回調「Reply with success and A3pay stops resending」，未寫重送策略 | **只重送 10 次、每 5 秒一次，約 50 秒內沒收到 success 就永久放棄**，之後不再補送 | 2026-09-24 dev 代付單 `A3PAY2026092406595095564` 取消回調：07:00:39–07:01:24 共 10 次，之後零 | 回調端點斷線超過 1 分鐘的結果只能靠 GetPayoutDetail 或人工；代付查單對 statusId 5 回 UNKNOWN 交人工，取消單要在後台「修改為代付失敗」或請廠商補送 |
| 8 | Payin Callback 的 `Amount` 是「Payin amount requested」 | 是**實付金額**：下單 10、`payinActualAmount` 10.01 → 回調 `Amount` 10.01、`NetAmount` 9.78（手續費以 10.01 計） | 2026-09-24 dev 訂單 13913（成功）、13916（Status 7 也帶 20.01） | `DepositCallback` 對往上 ≤ 0.10 的差額採實付金額重算 amount／tax／point，會員多付多少入帳多少（open-questions Q1 結案） |
| 9 | Submit Payout 帶 `ReturnClientURL`，A3Pay 處理前會打它做 Payout Verification | dev 成功代付（提款單 705，`W2026092400000014`）**沒有**任何 Verification 請求，直接收到 Status 4 回調；`withdrawal/query/7676` 路由 3 小時內只被 Telegram 連結預覽 GET 打過一次 | 2026-09-24 GCP log 全掃該路由 | `WithdrawalQuery` 照文件實作保留，但**不能假設放款前一定會被問**；重複出款的防線仍是回調端 `checkWithdrawalOrderStatus` 與 -2 白名單，不是 Verification |
| 6 | BQR 回 `paymentQRCode`（base64 圖）供顯示 | 同時回收銀台 `paymentURL`（`/Deposit/SGQRDeposit/`）與 `paymentQRCode`（data URI）。我方若回 QR 型別（`QR_CODE_LEGACY`），前台沒有對應處理、畫面沒反應 | 2026-09-24 dev 訂單 13906 | `Deposit` 一律導收銀台 `paymentURL`；只有沒給 URL 才退回 `QR_CODE_IMAGE_PATH` |

## 配置踩坑

- **`RSA_PUBLIC_KEY` 填成我方自己的公鑰**（2026-09-24 dev）：CheckAccBalance、CreatePayin、SubmitPayout 全部正常（那些只用我方私鑰簽），直到第一筆回調才 `Invalid signature`，而 A3Pay 只重送 10 次就放棄。判別方式：A3Pay 公鑰是 1024 位、`MIGf` 開頭；我方 `public-gff.pem` 是 2048 位、`MIIBIjAN` 開頭。填完用一筆回調（或請廠商補送）驗過才算配好。

## 實測確認可行的事

- 信封 key 用小寫 `merchantCode／data／signature／version`（照 Payin 範例），`CheckAccBalance` 回 200 且 `data.mid` 為商戶代碼，代表 AES-256-CBC（PBKDF2-SHA1）密文與 RSA SHA-1 對明文的簽名三方都收（open-questions Q4 可結案：小寫可用）。
- `CheckAccBalance` 回應多一個文件沒寫的欄位 `t1BalanceAmount`，忽略即可。

## 我方實作決策索引

| 決策 | 權威出處 |
|---|---|
| 代付拒絕只認「HTTP 4xx＋errorCode 在白名單 PMT10001／10005／10013」，其餘結果未知停 21 | `app/Services/Payment/Providers/A3Pay/Withdrawal.php` docblock 與 `REJECT_CODES` |
| Payout Verification 只放行 PENDING 記錄、最新一筆、訂單 21／22 | `app/Services/Payment/Providers/A3Pay/WithdrawalQuery.php` docblock |
| 代收 Status 4 為非終態不動單 | `app/Services/Payment/Providers/A3Pay/DepositCallback.php` docblock |
| 代收回調金額往上 ≤ 0.10（三方唯一化上調）採**實付金額**：依建單公式重算 amount／tax／point 再結單；少付或超過仍轉人工 | `DepositCallback` class docblock、`recalculateForActualAmount()` |
| 代付查單 statusId=5 回 UNKNOWN 而非 FOUND | `Withdrawal::queryByTransactionNo` docblock |
| 代付銀行分組宣告 FOREIGN_BANK | `app/Console/Commands/CashFlow/Data/providers/A3Pay.php` 註解 |
| 不與 GlobePay 共用任何類別或 trait | `app/Services/Payment/Providers/A3Pay/Driver.php` docblock |
