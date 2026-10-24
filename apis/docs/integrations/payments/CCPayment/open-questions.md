# CCPayment 待廠商確認事項

> **用途**：接入 CCPayment 期間，廠商文件未載明或自相矛盾、必須向廠商確認的項目清單。
> **文件依據**：廠商文件以官方 markdown 版（`https://example.invalid/apitutorial.md`）為單一事實來源；各題的「markdown 版查證」都以它為準，寫「markdown 版 X.Y」即指該檔的章節編號。其他來源的不同說法不採用。
> **溝通管道**：TG 群「CCPayment & TPOWER [TH041PG]」。
> **回填規約**：廠商回覆後，(a) 屬廠商規格者補進 `api.md` 對應章節並在本檔標記已回覆；(b) 屬「文件與實際行為落差」者依 `payment-integration` skill 的 litmus test 寫進 `integration.md`；(c) 全部結案後本檔可刪除。
>
> ⚠️ 本檔是 `payment-integration` skill 定義的暫時性文件：接入期間存在，全部結案後可刪。同目錄的 `runbook.md` 不同，它在上線後留作維運依據（配置新站點、止血回滾都照它），不隨本檔刪除。

## 本次接入的前提（供廠商理解我方情境）

- 站點：TPower，接入範圍為 **USDT 代收與代付**
- 幣別與鏈別：**僅 USDT-TRC20**
- 整合模式：**Permanent Address Mode**（每位會員一個固定收款地址；2026-09-13 定案，原選的 Retail Checkout Mode 已作廢）
- 代收：`getOrCreateAppDepositAddress` 取址，Webhook 為 `DirectDeposit`；代付：`applyAppWithdrawToNetwork`，Webhook 為 `ApiWithdrawal`

## 狀態總覽

| # | 主題 | 優先級 | 狀態 | api.md 對應章節 |
|---|---|---|---|---|
| 1 | USDT 的 `coinId` 與 TRC20 的 `chain` 值 | 🔴 P0 | ✅ **不需問廠商，已自行呼叫 `/getCoinList` 取得**（2026-09-10 實打） | §7.4 |
| 2 | Webhook 簽名驗證方式 | 🔴 P0 | ✅ **已回覆**（2026-09-14）：header 三件組＋HMAC-SHA256，與 API 請求簽名同一套；剩「重送時 Timestamp 是否換新」「成功字串大小寫」待實測 | §5.2、§5.7 |
| 3 | `Timestamp` 單位（秒 vs 毫秒） | 🔴 P0 | ✅ **已實測確認為秒級 10 位**（2026-09-10，不需問廠商） | §2.1 |
| 4 | 查單 API 的 `status` 列舉值 | ⚠️ P1 | ✅ **markdown 版已載**：存款紀錄 3 值、代付紀錄 5 值，皆與 webhook 相同 | §4.4、§5.5、§6.7 |
| 5 | 風控退款的通知機制 | ⚠️ P1 | ⛔ **前提不成立**：markdown 版 11.14 說明該端點是風險存款的退款紀錄，與代付無關 | §6.6 |
| 6 | 少付（Underpaid）的處理 | ⚠️ P1 | ⛔ 已失效：永久地址模式無應付金額，不存在少付／逾期 | — |
| 7 | 提現審核預設狀態 | ⚠️ P1 | 待回覆（markdown 版未載；可到後台自查） | §5.5 |
| 8 | `getAppWithdrawRecord` 必填參數 | ⚠️ P1 | ✅ **markdown 版已載**：`recordId` 與 `orderId` 不能同時為空 | §6.4 |
| 9 | Testnet 能否測 USDT-TRC20 | 📋 P2 | 🟡 **部分**：TRC20 無測試網；Sepolia 測試網代收代付已於 09-16 走通（TETH 1891／ETH_SEPOLIA）；主網真幣測試可否待 RB | §概述 |
| 10 | IP 白名單確認 | 📋 P2 | 🟡 **部分**：markdown 版只列一個發送 IP；其餘待回覆 | §1 |
| 11 | 手續費歸屬與詢價有效期 | 📋 P2 | 🟡 **部分**：`merchantPayNetworkFee` 已載；markdown 版沒有 `networkFeeInquiryID`；費用金額需實打 | §6.1、§6.2 |
| 12 | 訂單過期時間建議值 | 📋 P2 | ⛔ 已失效：永久地址不設 `expiredAt` | — |
| 13 | Resend Webhook 端點路徑 | 📋 P2 | ✅ **markdown 版已載**：`POST /webhook/resend` | §5.6 |
| 14 | 限流實際門檻 | 📋 P2 | 待回覆（markdown 版沒有頻率數字） | §2.3 |
| 15 | 永久地址 1000 個／APP ID 的配額能否放寬 | 🔴 P0 | ✅ **已回覆**（2026-09-14）：可申請擴容、無固定上限、不收費，按 APP ID 各自計算 | §4.1 |
| 16 | `referenceId` 在多鏈情境的語意 | ⚠️ P1 | 第 1 小題已由設計繞開（2026-09-16：鏈別由支付通道決定，各通道各自 referenceId，不會同一個 referenceId 換鏈取址）；第 2 小題「各鏈是否各占配額」仍待回覆，Dev 已實際出現一個會員兩條鏈兩個地址 | §4.1 |
| 17 | `Address Unbinding` 端點規格 | ⚠️ P1 | 🟡 **大致可答**：markdown 版 7.6 已載路徑、參數、回傳；錯誤碼與 `notifyUrl` 綁定是否清除待回覆 | §0.1、§4.1、§4.5、§5.9 |
| 18 | `DirectDeposit` 的 `status` 列舉是否含 `Pending` | ⚠️ P1 | ✅ **markdown 版已載**：只有 3 值、沒有 `Pending`；實作只認 `Success` | §5.4 |
| 19 | Risky Address webhook 的 `chainId` 值域 | 📋 P2 | 待回覆（markdown 版只寫 `Chain ID`，無值域） | §5.9 |
| 20 | 永久地址配額用完時怎麼發現、怎麼擴容 | 📋 P2 | 🟡 **部分**：錯誤碼 `15001`；用量查詢與擴容時效待回覆（🆕 未送出） | §4.1 |
| 21 | `referenceId` 允許的字元集 | ⚠️ P1 | ✅ 實測結案（2026-09-15 Dev 以測試商戶 GFFTEST 實打 `1242-48897` 與 `1242-999999999`，都取得地址，連字號可用） | §4.1 |
| 22 | 風險地址的判定條件與能否解除 | 📋 P2 | 待回覆（markdown 版只寫採 AML 風控，沒有標準） | §5.9 |

---

## 🔴 P0：阻塞開發（沒有答案無法實作）

### 1. USDT 的 `coinId` 與 TRC20 的 `chain` 值

> ✅ **不需向廠商詢問，我方自行呼叫 API 取得。**

代收 `getOrCreateAppDepositAddress` 將 `chain` 列為必填（markdown 版 7.1），代付 `applyAppWithdrawToNetwork` 將 `coinId` 與 `chain` 列為必填（markdown 版 8.1）。廠商未提供靜態對照表，但提供了查詢端點。

**取得方式**：呼叫 `POST /getCoinList`（markdown 版 11.1，無請求參數；欄位見 api.md §7.1），於回傳的 `data.coins[]` 中找 `symbol = USDT` 者：

- `coinId` ← 該筆的 `coinId`
- `chain` ← 該筆 `networks` 物件的 **key**

> ⚠️ **不要用 `/getCoin`**：該端點的 `coinId` 是必填（markdown 版 11.2），拿它查 USDT 會變成雞生蛋。

**取值腳本**：`ccpay-getcoinlist.sh`（憑證走環境變數 `CCPAY_APP_ID` / `CCPAY_APP_SECRET`，不寫入任何檔案、不進 repo）。執行機器的出口 IP 必須在商戶白名單內，否則回 `12002 IpNotInWhitelist`。

**副作用（順帶驗證三件事）**：

| 一併驗證 | 判讀方式 |
|---|---|
| 第 3 題：`Timestamp` 單位 | 腳本用秒級。回 `10000` 即證明秒級正確；回 `11006 ReqExpired` 則需改毫秒重試 |
| 第 10 題：IP 白名單 | 回 `12002 IpNotInWhitelist` 代表該機器出口 IP 未加入白名單 |
| `User-Agent` 必填 | 移除該 header 應回 HTTP 403（markdown 版 3.3 寫缺少時可能回 403），可反向驗證 |

**實測結果**（2026-09-10，測試商戶 GFFTEST，回 `code: 10000`）：

```
coinId = 1280（symbol USDT，status Normal）
networks 的 key：ARETH、BSC、ETH、OPTIMISM、POLYGON、SOL、TON、TRX
⇒ TRC20 的 chain 值為 TRX
```

完整結果與判讀見 `api.md` §7.4；`/getCoinList` 只回 9 種幣的原因見 `integration.md` §4。

### 2. Webhook 的簽名驗證方式

markdown 版的整合工作流明文要求驗簽（6.6.3 Option A：`On payment you receive a DirectDeposit webhook — verify the signature, then confirm final status and isFlaggedAsRisky via getAppDepositRecord.`），但 Webhook payload 結構（`type` + `msg`）內**沒有簽名欄位**。

**請提供**：

1. 簽名放在哪個 HTTP header？（header 名稱）
2. signText 如何組成？是否同 API 請求的 `Appid + Timestamp + Body`？
3. 演算法是否同為 HMAC-SHA256？
4. 若有驗簽範例程式碼請一併提供。

**影響**：無法實作回調驗簽。在確認前，我方回調端點的來源驗證只剩發送 IP，安全性不足。

**送出**：2026-09-14，TG 群。另追問「三種 webhook 是否同一套簽名」並索取樣本。

**廠商回覆**（2026-09-14 收到；廠商以簡體中文回覆，原文照錄）：

```
Webhook 的验签规则请参考截图。签名信息位于请求 Header 中，DirectDeposit、ApiWithdrawal 以及风险地址通知均采用同一套 Webhook 验签规则。 您登录 API 文档后刷新页面，可在 API文档 → Webhook → Webhook使用指南 中查看完整的验签说明及代码示例哦。

另外，Webhook 仅作为通知，收到 DirectDeposit 后需要通过 获取充值记录接口getAppDepositRecord 再次确认交易信息和具体金额

https://example.invalid/api/doc/?zh-CN#webhook-guide
```

**截圖**：RB 於 2026-09-14 補上兩張官方簡中網頁版「Webhook 使用指南 → Webhook 簽名驗證」截圖並複製頁面全文。規格依 markdown 版 5.4 寫入 api.md §5.7，與截圖內容一致。

**逐題對照**（依 markdown 版 5.4）：

| 我方問題 | 回覆狀況 |
|---|---|
| 1. 簽名放在哪個 header | ✅ `Appid`、`Timestamp`、`Sign` 三個 header |
| 2. signText 如何組成 | ✅ `{Appid} + {Timestamp} + {Payload JSON}`，與 API 請求簽名同一套；依官方 Node 範例，被簽的是收到的原始 body，APP ID 用我方保存的值 |
| 3. 演算法是否為 HMAC-SHA256 | ✅ 是（另列 RSA 驗法，本專案不用） |
| 4. 驗簽範例程式碼 | ✅ 附 Node 範例，已照錄於 api.md §5.7 |
| 追問：三種 webhook 是否同一套 | ✅ 同一套：`DirectDeposit`、`ApiWithdrawal`、風險地址通知（廠商 TG 回覆；markdown 版未寫） |

廠商另重申一條要求：webhook 只是通知，收到 `DirectDeposit` 後要呼叫 `getAppDepositRecord` 回查，確認交易資訊與實際金額（與 markdown 版 7.1、5.10 一致，見 api.md §5.4）。

**結論**：本題結案，驗簽可以實作。剩兩件事 markdown 版沒講清楚，端到端實測時確認（必要時再問廠商）：

1. 重送 webhook 時 `Timestamp` 會不會換新。markdown 版的 webhook header 表寫 `Valid within 2 minutes`，但重送最晚在首次發送的 30 分鐘之後；這決定我方能不能檢查 2 分鐘時效，官方範例沒有檢查時效（api.md §5.7）。
2. 成功回應字串分不分大小寫。markdown 版的文字說明寫 `Success`，Node 驗簽範例回小寫 `success`（api.md §5.2）。

### 3. `Timestamp` 的單位

各來源的說法不一致：

| 來源 | 說法 |
|---|---|
| markdown 版 3.3、3.4 | `10-digit timestamp (Unix seconds)`（秒） |
| markdown 版 3.5 的 Node 範例 | `Math.floor(Date.now() / 1000)`（秒） |
| 廠商 GitHub SDK 文檔 `README.md` 的 Shell 範例 | `date +%s`（秒） |
| 廠商 GitHub SDK 文檔 `api/README.md` | `millisecond timestamp`（毫秒） |
| 廠商 GitHub SDK 文檔 `api/appendix.md` | `current request timestamp in milliseconds`（毫秒） |

**請確認**：正確單位是秒（10 位）還是毫秒（13 位）？

**影響**：填錯會導致所有請求被判 `11006 ReqExpired`。

**結論（不需問廠商）**：2026-09-10 以秒級 10 位簽名呼叫 `/getCoinList` 成功回 `10000`，確認為秒級，與 markdown 版一致；GitHub SDK 文檔兩處寫 milliseconds 為錯誤，已記入 `integration.md` §1。

---

## ⚠️ P1：影響資金正確性

### 4. 查單 API 的 `status` 列舉值

**請確認**：`getAppDepositRecord` 與 `getAppWithdrawRecord` 回傳的 `record.status`，是否與 Webhook 的 `msg.status` 為同一組值？若不同請提供完整列舉。

**影響**：廠商要求「不可僅憑 webhook 定案、須回查確認」，若查單的 status 值不同，我方回查後的判定會出錯。

**markdown 版查證**（不需問廠商）：

| 端點 | `record.status` 列舉 | 與 webhook 是否相同 |
|---|---|---|
| `getAppDepositRecord` | `Success`／`Processing`／`Failed`（7.7） | ✅ 與 `DirectDeposit` 的 `msg.status`（7.1）相同（第 18 題） |
| `getAppWithdrawRecord` | `WaitingApproval`／`Rejected`／`Processing`／`Success`／`Failed`（8.3） | ✅ 與 `ApiWithdrawal` 的 `msg.status`（8.1）相同 |

**結論**：本題結案，已寫入 api.md §4.4、§6.7。

### 5. 風控退款（`getRiskyRefundRecordList`）的通知機制

> ⛔ **前提不成立，不向廠商詢問**：原本以為這個端點代表「已受理的代付可能被風控退回」，想確認退回時會不會發 Webhook、`status` 為何、款項是否回到商戶餘額。markdown 版 11.14 說明它列的是「被判定有風險的**存款**，退款給付款方」的紀錄：回傳的 `fromDeposit` 是對應的風險存款交易；退款成功後，平台從商戶帳戶扣除風險退款費與原存款服務費。它和我方代付訂單無關，代付狀態機不需要為它加「受理 → 風控退回」路徑（api.md §6.6）。

### 6. 少付（Underpaid）與逾期的處理

> ⛔ **2026-09-13 改採 Permanent Address Mode 後失效，不向廠商詢問**：固定地址沒有應付金額與到期時間，會員轉多少記多少，不存在少付／逾期。原本的疑問出自訂單模式（markdown 版 6.6.2 Step 5：`Underpaid or overdue → do not auto-fulfill; flag for manual review.`）：會員轉入金額少於或多於訂單金額時會不會發 Webhook、`status` 為何、款項會不會入商戶餘額、能否補款。

### 7. 提現審核（Withdrawal Approval）預設狀態

代付 Webhook 的 `WaitingApproval` 與 `Rejected` 兩個狀態，markdown 版 5.8 註明只有商戶在 dashboard 啟用提現審核時才會出現（`only if merchant enabled withdrawal approval on the dashboard`）。

**請確認**：我方商戶帳號（正式 Tpower／測試 GFFTEST）目前**預設是啟用還是關閉**提現審核？可否由我方自行切換？

**影響**：決定我方代付狀態機是否需要處理這兩個狀態值。

**markdown 版查證**：沒寫預設值。預設開或關可由我方到兩個商戶後台自行查看，不一定要問廠商。

**廠商回覆**：

```
（待填）
```

### 8. `getAppWithdrawRecord` 的必填參數

markdown 版 8.3 的參數表將 `recordId` 與 `orderId` 皆標記為非必填。

**請確認**：是否至少須擇一？兩者皆空會回傳什麼（錯誤碼？全部紀錄？）

**影響**：我方查單邏輯的參數組裝與錯誤處理。

**markdown 版查證**（不需問廠商）：8.3 的規則寫 `recordId and orderId cannot both be empty.`，也就是兩者至少帶一個。兩者皆空時回什麼錯誤碼，markdown 版未載；我方 `CCPaymentTrait::queryWithdrawRecord()` 固定帶 `recordId`，不影響實作。

**結論**：本題結案，已寫入 api.md §6.4。

---

## 📋 P2：營運與測試

### 9. Testnet 能否測 USDT-TRC20

markdown 版 4 的 Testnet 只提到 ETH Sepolia，但我方接入的是 **USDT-TRC20**。

**請確認**：

1. Testnet 是否支援 TRC20 網路？
2. 若不支援，測試商戶（GFFTEST）能否以小額真幣進行端到端測試？建議的最小測試金額？

**影響**：測試計畫與驗收方式。

**markdown 版查證**：4 只列 ETH Sepolia（需到 Dashboard > Merchant settings > ETH test network 開啟），沒提 TRC20，第 1 小題推定不支援（未經廠商證實）。第 2 小題 markdown 版未載，仍待回覆。另外，廠商 GitHub SDK 文檔的錯誤碼表有 `14022 TestModeLimitAmount`、`14023 TestModeSingleLimitAmount`（測試模式的提幣金額限制），markdown 版沒有這兩個碼，詢問時可一併確認。

**FAQ 查證（2026-09-15，RB 提供 `https://example.invalid/en/support-center/faqs-detail#use-test-network`）**：FAQ 標題「CCPayment ETH Testnet Tutorial」，內容與 markdown 版 4 相同：在 Merchant Settings 開啟 Sepolia Testnet、向水龍頭領免費測試 ETH、測試網與主網獨立、測試資產沒有價值。網頁是前端動態載入，正文由搜尋摘要取得。仍然只有 ETH，第 1 小題維持推定不支援。第 2 小題的金額部分可由 API 回答：`getCoinList` 回 USDT 在 TRX 鏈 `minimumDepositAmount = 0.1`，真幣測試至少轉 0.1 USDT；能否以真幣測、測試款能否提回，仍待廠商或 RB 決定。

**測試網實測（2026-09-16）**：代收與代付都在 Sepolia 走通，測試幣是獨立代幣 TETH（coinId 1891、chain `ETH_SEPOLIA`），要先在後台加進啟用代幣才能提幣、才會自動發 webhook；完整結果見 `integration.md` 第 10 節。Dev 環境依 RB 決定保持測試網設定給 QA 驗收。TRC20 仍沒有測試網，主網真幣測試待 RB 決定。

**廠商回覆**：

```
（待填）
```

### 10. IP 白名單確認

**請確認**：

1. 我方已於工單提供的出站 IP 清單，是否已加入我方商戶的 IP 白名單？
2. 貴方 Webhook 發送 IP 是否**只有 `0.0.0.0`** 一個？未來是否可能變動或增加？若會變動，有無事前通知機制？

**影響**：我方需在防火牆／nginx 層限制回調來源 IP，IP 變動會導致回調全部被擋。

**markdown 版查證**：5.2 只列一個 Webhook 發送 IP `0.0.0.0`，沒提會不會變動或事前通知；3.1 另寫 IP 白名單是商戶有設定時才檢查（`If you have set up an IP whitelist on Dashboard > Developer, ensure the IP address of your requests is included in the whitelist.`）。第 1 小題屬我方帳號設定，第 2 小題的變動與通知機制 markdown 版也未載，兩題仍待回覆。

**廠商回覆**：

```
（待填）
```

### 11. 手續費歸屬與詢價有效期

**請確認**：

1. `applyAppWithdrawToNetwork` 的 `merchantPayNetworkFee` 設 `true` 與 `false` 的實際差異（手續費從哪裡扣、會員實收金額是否不同）？
2. `getWithdrawFee` 回傳的 `networkFeeInquiryID` **有效期多久**？過期後帶入會發生什麼？
3. USDT-TRC20 的網路手續費目前約多少？是否浮動？

**影響**：代付金額計算與會員實收金額，屬金流正確性範圍。

**markdown 版查證**：

| 小題 | 結果 |
|---|---|
| 1. `merchantPayNetworkFee` 的差異 | ✅ 8.1：`true` 商戶付網路費，到帳數量＝提現數量；`false`（預設）會員付網路費，到帳數量＝提現數量－網路費 |
| 2. `networkFeeInquiryID` 有效期 | ⛔ markdown 版的代付請求參數（8.1）與查手續費回傳（11.9）**都沒有這個欄位**，它只出現在廠商 GitHub SDK 文檔。依單一事實來源不使用，本小題不問 |
| 3. USDT-TRC20 網路費金額 | ⏳ markdown 版未載，需呼叫 `/getWithdrawFee` 實查 |

已寫入 api.md §6.1、§6.2。

**廠商回覆**：

```
（待填）
```

### 12. 訂單過期時間建議值

> ⛔ **2026-09-13 改採 Permanent Address Mode 後失效，不向廠商詢問**：永久地址不設 `expiredAt`。原本想問訂單模式 `createAppOrderDepositAddress` 的 `expiredAt` 建議值與上限；markdown 版 13 的 `11012 ExpiredAtTooMax` 說明寫 `The maximum valid period is 10 days`。

### 13. Resend Webhook 端點路徑

**請提供**：主動重送 Webhook（Resend Webhook by Calling API）的完整端點路徑。

**影響**：回調漏收時的補救手段。

**markdown 版查證**（不需問廠商）：5.12 寫端點為 `POST https://example.invalid/ccpayment/v2/webhook/resend`，回傳 `data.resendCount`（成功重送的筆數）。

**結論**：本題結案，已寫入 api.md §5.6。

### 14. 限流實際門檻

錯誤碼 `11004 RateLimit` 的門檻未載明（markdown 版 13 的說明只寫 `Reached the rate limit. Reduce the frequency of your requests`）。

**請提供**：各主要端點的限流數值（每秒／每分鐘上限），特別是 `getOrCreateAppDepositAddress`、`getAppDepositRecord` 與 `applyAppWithdrawToNetwork`。

**markdown 版查證**：3.6 只說 `Check the specific interface documentation to know the exact rate-limit information.`，但各端點說明都沒有頻率數字，仍待回覆。

**廠商回覆**：

```
（待填）
```

---

## 🆕 2026-09-13 改採 Permanent Address Mode 後新增

### 15. 永久地址配額能否放寬（🔴 P0）

markdown 版 7.1 原文：`Each APP ID can obtain 1000 addresses via this interface. If you need more, contact customer service.`

我方 TPower 站點會員數約 100 萬（2026-09-13 實查 `members` 表 999,139 列）。若採「每位會員一個永久地址」，1000 個配額不敷使用。

**請確認**：

1. 此配額是否可依商戶需求放寬？上限為何？是否額外收費？
2. 若無法放寬，貴方對「大量會員但地址有限」的建議做法為何？（例如地址回收／輪用機制）
3. 配額是以 APP ID 計，還是以商戶帳號計？我方正式商戶 Tpower 與測試商戶 GFFTEST 是否各自 1000？

**影響**：決定 Permanent Address Mode 是否可行，或必須只對部分會員開放。

**送出**：2026-09-14，TG 群。另追問「解綁地址後是否釋放配額」。

**廠商回覆**（2026-09-14 收到；廠商以簡體中文回覆，原文照錄）：

```
getOrCreateAppDepositAddress 默认每个 APP ID 可获取 1000 个永久地址。

如果实际用户数量超过 1000，可以联系我们申请增加。地址数量没有固定的最高上限，可以根据您实际的业务使用情况持续扩容，且不会额外收费。

配额是按照 APP ID 单独计算的，因此如果 Tpower 和 GFFTEST 使用的是两个不同的 APP ID，则两边的地址配额分别独立计算。

Address Unbinding 后，原地址已经占用的配额不会释放

因为解绑只是解除该地址与原 referenceId 的绑定。即使已经解绑，后续发送到旧地址的付款仍然可以记入商户账户

因此，对于大量用户场景，我们不建议通过频繁解绑地址的方式进行回收或轮用。如果地址数量超过当前配额，直接联系我们申请扩容就可以
```

**逐題對照**：

| 我方問題 | 廠商答覆 |
|---|---|
| 1. 能否放寬？上限？收費？ | ✅ 超過 1000 可聯繫廠商申請增加；**沒有固定上限**，依實際業務量持續擴容；**不額外收費** |
| 2. 地址不夠時的建議做法 | ✅ **不建議**用頻繁解綁的方式回收或輪用地址；超過配額直接申請擴容 |
| 3. 以 APP ID 還是商戶帳號計 | ✅ 按 APP ID 各自計算；Tpower 與 GFFTEST 若是兩個不同的 APP ID，配額各自獨立 |
| 追問：解綁是否釋放配額 | ✅ **不釋放**。解綁只解除地址與原 `referenceId` 的綁定；解綁後轉進舊地址的款項，仍會記入我方 CCPayment 帳戶 |

**結論**：配額不會卡住 Permanent Address Mode，此模式可行。上線前要依預估的地址用量先向廠商申請擴容（申請流程與生效時間本次未問）。

### 16. `referenceId` 在多鏈情境的語意（⚠️ P1）

markdown 版 7.1 原文：`if a permanent address is already linked to the reference ID, CCPayment returns this address.`——以 reference ID 為單位描述，未提及 `chain`。

**請確認**：

1. 同一 `referenceId` 先以 `chain=TRX` 取址、再以 `chain=ETH` 取址，是回傳兩個不同地址，還是回傳第一次的 TRX 地址（或報錯）？
2. 同一 `referenceId` 在不同鏈各取一次地址，是否各占一個配額？

**影響**：決定多鏈擴充時 `referenceId` 要不要把鏈別編進去（例如 `{member}-{chain}`），以及配額怎麼估算。`referenceId` 格式一旦定了就難改。

**2026-09-16 補記**：代收的幣與鏈改由支付通道決定（`integration.md` 第 9 節「幣別與鏈別放哪」），一條鏈一條支付通道、各自的 `referenceId`（含支付通道關聯 id），所以第 1 小題「同一 `referenceId` 換鏈取址」在我方設計下不會發生，結案。第 2 小題仍要問：Dev 的會員 48897 已同時持有 TRC20 通道與測試網通道各一個地址，配額是不是算兩個，決定正式環境多鏈時的配額估算。

**markdown 版查證**：

- 7.1 沒有說明同一 `referenceId` 換一條鏈取址會回傳什麼，也沒寫配額怎麼計算。
- 7.6 寫 EVM 相容鏈上的地址解綁時，其他 EVM 相容鏈上的地址也會一併解綁，推測 EVM 各鏈共用同一地址（未驗證）。
- 兩個小題都仍待回覆。

**廠商回覆**：

```
（待填）
```

### 17. `Address Unbinding` 端點規格（⚠️ P1）

風險地址 webhook 的廠商建議動作是呼叫 Address Unbinding 解綁後再取新地址（markdown 版 5.9）；Gaming 章節亦提到會員要求輪換地址時用同一端點（6.6.3）。

**請提供**：完整規格。另請確認解綁後對同一 `referenceId` 再呼叫 `getOrCreateAppDepositAddress` 是否會發新地址、舊地址的既有 `notifyUrl` 綁定是否隨解綁清除。

**影響**：風險地址處理流程無法實作。

**廠商回覆**（部分，2026-09-14 隨第 15 題追問取得，原文見第 15 題）：

- 解綁後，原地址已占用的配額**不會釋放**。
- 解綁只解除地址與原 `referenceId` 的綁定；解綁後轉進舊地址的款項**仍會記入我方 CCPayment 帳戶**。
- 廠商不建議用頻繁解綁的方式回收或輪用地址。

**markdown 版查證**（7.6）：

- 端點：`POST https://example.invalid/ccpayment/v2/addressUnbinding`
- 參數：`chain`（必填，要解綁地址的鏈）、`address`（必填，要解綁的地址）
- 回傳：`userID` 或 `referenceID`（看地址原本綁定哪一個）、`unbound[]`（本次解綁的 `chain`＋`address` 清單）、`unboundAt`（解綁時間戳）
- 規則：EVM 相容鏈的地址解綁會連動其他 EVM 鏈；解綁後轉入舊地址的款項仍可記入商戶帳戶（與廠商 TG 回覆一致）
- 解綁後對同一 `referenceId` 重新取址，會拿到新地址（7.6 的使用案例）

已寫入 api.md §4.5。

**仍待回覆**：

1. 本端點的錯誤碼（markdown 版錯誤碼表未列專屬碼；廠商 GitHub SDK 文檔錯誤碼表的 `17000`–`17007` 名稱看起來與解綁有關，例如 `17006 UnbindingAddressFailed`，markdown 版沒有，詢問時可一併確認）。
2. 舊地址原本綁定的 `notifyUrl` 是否隨解綁清除。

### 18. `DirectDeposit` 的 `status` 列舉是否含 `Pending`（⚠️ P1）

**請確認**：`DirectDeposit` 的 `msg.status` 完整列舉，特別是有無 `Pending` 與 `Failed`。

**影響**：回調三態判定的碼集必須逐值有依據，漏一個非終態就會把處理中誤判成失敗。

**markdown 版查證**（不需問廠商）：

- 7.1 的 `DirectDeposit` 參數表與 5.8 的狀態表都只有 `Processing`／`Success`／`Failed`，**沒有 `Pending`**。
- 實作不受影響：回調一律以回查結果為準，只有 `Success` 入帳，其餘值一律不入帳、等後續通知；6.6.4 的永久地址指引也寫 `Only credit if Success and not risky.`

**結論**：本題結案，已寫入 api.md §5.4。

### 19. Risky Address webhook 的 `chainId` 值域（📋 P2）

該 webhook 帶 `chainId`（String），而其他 API 用的是 `chain`（如 `TRX`）。

**請確認**：`chainId` 的值域，以及與 `chain` 的對應關係。

**影響**：我方以 `(order_type, address)` 反查會員時要不要用到 `chainId`。

**markdown 版查證**：5.9 的欄位說明只寫 `Chain ID`，沒有值域，也沒說明與 `chain` 的對應，仍待回覆。

**廠商回覆**：

```
（待填）
```

---

## 🆕 2026-09-14 廠商回覆後衍生

### 20. 永久地址配額用完時怎麼發現、怎麼擴容（📋 P2）

第 15 題廠商答覆「預設每個 APP ID 1000 個，超過可聯繫申請擴容」，但沒有說明配額用完當下會發生什麼，也沒有說明擴容要多久。

**請確認**：

1. 配額用完後，再呼叫 `getOrCreateAppDepositAddress` 替新的 `referenceId` 取址，會回哪個錯誤碼？是否為錯誤碼表的 `15001 PaymentAddressNumTooMuch`（原文 `Available deposit addresses for this account reached the maximum limit`）？
2. 有沒有 API 或後台頁面可以查詢目前已用掉多少配額？
3. 申請擴容要走什麼流程？從申請到生效大約多久？

**影響**：配額用完時，還沒有地址的會員拿不到收款地址，也就無法用這條 USDT 通道存款。我方要據此設計告警（認得出「配額用完」的錯誤碼），並決定用量到多少時提前申請擴容。

**markdown 版查證**：

| 小題 | 結果 |
|---|---|
| 1. 配額用完的錯誤碼 | ✅ 13 的錯誤碼表：`15001 PaymentAddressNumTooMuch`，說明 `Available deposit addresses for this account reached the maximum limit`，指的就是地址數達上限 |
| 2. 查詢已用配額 | ⏳ 14 的端點索引共 53 支，沒有查配額的端點；後台能否查看，markdown 版未載 |
| 3. 擴容流程與時效 | ⏳ 7.1 只寫 `contact customer service`，時效未載 |

**狀態**：第 1 小題已由 markdown 版回答；第 2、3 小題尚未送出，是否詢問廠商由 RB 決定。

**廠商回覆**：

```
（待填）
```

## 🆕 2026-09-15 設計討論後新增

### 21. `referenceId` 允許的字元集（⚠️ P1）

我方 `referenceId` 格式定為 `{agent_cash_flow_order_type_id}-{member_id}`，含連字號。markdown 版 7.1 只寫 `3–64 characters`，沒寫字元集；官方範例都是純數字。

**請確認**：`referenceId` 可否含連字號「-」或英文字母？

**影響**：格式一旦用於正式取址就不能改（地址永久綁定 referenceId）。未回覆前，正式取址之前先用測試用的金流商戶實打一次；被拒就改成純數字固定寬度，只改 `ReferenceId` 類別。

**實測結果（2026-09-15，Dev 環境、測試商戶 GFFTEST）**：`/getOrCreateAppDepositAddress` 以 `referenceId=1242-48897`（chain `TRX`）取得地址 `TNvGjcctBZG3RrGskCU6zpTcQqf8mcxaRH`，另以拋棄式 `1242-999999999` 取得 `TMiB2c3KMD5wiUyH51ErhGpPTu12vqGszD`；兩次都回 `code 10000`，連字號可用。本題結案，不必再問廠商。

**廠商回覆**：

```
（不需要，已實測）
```

### 22. 風險地址的判定條件與能否解除（📋 P2）

markdown 版 5.9 只寫收款地址被標記為風險時會立即停用並發回調；開頭自述採用符合國際規範的 AML 風控，沒有寫判定標準。

**請確認**：

1. 收款地址被標記為風險的條件是什麼？是否只在收到被判定為風險的存款後才標記？
2. 標記後能否解除？

**影響**：我方對風險地址一律停用、解綁、由會員下次點選換發新地址並告警；若標記可能誤判且可解除，營運處置方式會不同。

**廠商回覆**：

```
（待填）
```

---

## 附註

- 本檔的問題來自接入過程中文件沒寫清楚的項目；各題對應的 api.md 章節見狀態總覽。
- P0 已全數結案（2026-09-14）：第 2 題取得驗簽規則、第 15 題確認配額可免費擴容，Permanent Address Mode 可行。P1 可與實作並行，測試前補齊；P2 上線前補齊。
- 2026-09-15 起以官方 markdown 版為單一事實來源，各題查證都以它為準：第 4、8、13、18 題由 markdown 版結案；第 5 題前提不成立；第 9、10、11、17、20 題部分回答；第 7、14、16、19 題 markdown 版未載。
- **目前仍需問廠商的項目**：第 7 題（也可自查後台）、第 9 題第 2 小題、第 10 題、第 11 題第 3 小題（也可實打）、第 14 題、第 16 題（換鏈取址的行為與配額計算；2026-09-15 起 referenceId 含支付通道 id，多鏈各自取址，本題降為非阻塞）、第 17 題的錯誤碼與 `notifyUrl`、第 19 題、第 20 題第 2／3 小題、第 22 題（風險地址判定條件）。第 21 題已於 2026-09-15 實測結案。
- 若廠商偏好英文溝通，可再產出英文版；本檔問題主體的 API 名稱、欄位名與錯誤碼皆已為英文原文。
