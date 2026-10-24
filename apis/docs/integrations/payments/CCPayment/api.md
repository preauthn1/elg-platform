# CCPayment 廠商規格

> 本檔為**廠商原文規格**（廠商說什麼），照搬不詮釋。我方接入踩坑、文件與實作的已驗證落差請寫入同目錄 `integration.md`；通用接入規範見 `payment-integration` skill。
>
> 🔴 **單一事實來源：官方 markdown 版**（`https://example.invalid/apitutorial.md`，以下簡稱「markdown 版」，引用時附該檔的章節編號，例如「markdown 版 7.1」）。本檔的規格內容一律以 markdown 版為準，官方網頁版與廠商 GitHub SDK 文檔都不作為依據（理由見 `integration.md` §7，GitHub SDK 文檔的落差見 `integration.md` §8）。markdown 版沒寫的事項，只補廠商 TG 回覆與我方實測，並逐處標明出處（見文末「來源」）。

CCPayment 為加密貨幣支付閘道，宣稱支援 900+ 種加密貨幣。本次接入範圍為 **USDT 代收（Deposit）與代付（Withdraw）**，走 **Merchant 模式的 Permanent Address Mode**（每位會員一個固定收款地址；非訂單模式、非 User 子帳戶模式）。**本檔只收錄該模式相關的廠商規格**，訂單模式（Retail Checkout）與 Wallet 模式的規格不收錄，需要時回 markdown 版查閱。

- **API 版本**：V2（markdown 版開頭註明 V1 已棄用，一律使用 V2）
- **Base URL**：`https://example.invalid/ccpayment/v2`
- **測試環境**：markdown 版 4 只列 **ETH Sepolia**（到 Dashboard > Merchant settings > ETH test network 開啟），沒有提到 TRC20；正式環境端點同上

---

## 0. 整合模式（Integration Mode）

廠商提供三種整合模式（Permanent Address / Retail Checkout / Wallet），**選定哪一種決定了用哪組 API 與收哪種 Webhook**。本專案採 **Permanent Address Mode**，以下只整理該模式的廠商原文（markdown 版 2）。

| 模式 | 核心特徵（廠商原文摘譯） | 資金流 | 廠商列的適用業別 |
|---|---|---|---|
| **Permanent Address Mode** | 系統為每位會員指派**專屬永久存款地址** | 會員存款**直接進商戶餘額** | 會員平台、長期訂閱、個人轉帳服務 |

> 廠商對 Permanent Address Mode 的舉例原文：`Example: a gaming platform where users remember their unique deposit address and top up whenever they wish to play.`

### 0.1 廠商對 Gaming & eSports 的建議（原文重點）

> `This industry rarely uses checkout invoices — it relies on assigning stable, long-term deposit addresses to players.`（markdown 版 6.6.3）

廠商給本產業兩條路：Option A 為 Permanent Address Mode（本專案採用），Option B 為 User Wallet System Mode（由 CCPayment 為每位會員獨立管理資金，本專案不採用、不收錄）。Option A 的廠商指引：

| 項目 | 廠商原文／指引 |
|---|---|
| 廠商定位原文 | `Permanent Address Mode (using your own platform ledger)` |
| 取地址 | `getOrCreateAppDepositAddress`（帶會員唯一識別）；XRP／XLM／TON 鏈會一併回 memo |
| 存款 Webhook | **`DirectDeposit`**：收到後先驗簽 |
| 存款確認 | `getAppDepositRecord`：確認最終狀態與 `isFlaggedAsRisky` |
| 代付 | `checkWithdrawalAddressValidity` 或 `getCwalletUserId` 驗證收款方 → `getWithdrawFee` 預覽手續費 → `applyAppWithdrawToNetwork`（鏈上）或 `applyAppWithdrawToCwallet`（站內、即時） |
| 代付 Webhook | `ApiWithdrawal`；提幣到 Cwallet 不發 Webhook，以 `getAppWithdrawRecord` 確認 |

廠商的安全與維運提醒（原文）：

- `Security: regardless of mode, never trust a webhook alone and never auto-credit a transaction flagged as risky — always verify via the respective Get Record endpoints.`
- `Address rotation: if a deposit address is flagged or a player asks to "rotate" their wallet for privacy, call addressUnbinding to release it; the next address-generation call will issue a fresh one.`

> 🔴 **解綁不會回收配額**（廠商 2026-09-14 TG 回覆，markdown 版未載；詳見 §4.1）：解綁只解除地址與原 `referenceId` 的綁定，原地址占用的配額不會退回。解綁後轉進舊地址的款項仍會記入我方 CCPayment 帳戶（markdown 版 7.6 亦載）。

### 0.2 本次接入採用的模式

**採 Permanent Address Mode**（2026-09-13 定案）：每位會員以 `/getOrCreateAppDepositAddress` 取得一個固定收款地址（§4.1），可隨時轉入；存款 Webhook 為 `DirectDeposit`（§5.4，只帶 `referenceId` 不帶 `orderId`），我方於收到 webhook 並回查 `getAppDepositRecord` 後才建立訂單，金額以鏈上實收為準。**本節為我方決策記錄，非廠商規格。**

> ✅ 兩個前提已於 2026-09-14 確認：配額可申請擴容、無固定上限（§4.1，`open-questions.md` 第 15 題）；webhook 驗簽規則已取得，與 API 請求簽名同一套（§5.7，第 2 題），剩「重送時 Timestamp 是否換新」與「成功字串大小寫」待實測。
>
> 2026-09-15 設計定案（我方決策，非廠商規格）：`referenceId` 為 `{agent_cash_flow_order_type_id}-{member_id}`；會員點選支付通道時才取址；地址存 `member_crypto_wallets`；只有回查為 Success、代幣與鏈別等於支付通道設定、非風險交易才入帳；風險地址回調一律停用並解綁；存款漏單與代付卡單以主動重送 webhook（§5.6）補救。索引見 `integration.md` 第 9 節。

---

## 1. 認證與憑證

憑證於 CCPayment Dashboard → Developer 取得，共兩項（**值不寫入本檔，配置於 `agent_cash_flows.info`**；markdown 版 3.1）：

| info 欄位 | 廠商名稱 | 說明 |
|---|---|---|
| `APP_ID` | APP ID | 應用識別碼，隨每次請求送出於 header |
| `APP_SECRET` | APP Secret | HMAC-SHA256 簽名密鑰，**不得外流、不得進 log** |
| `API_URL` | — | 框架固定欄位，值為 Base URL |

> ⚠️ 廠商聲明：使用未授權的 APP ID 多次請求，會封鎖該 IP 與 APP ID。

### IP 白名單（雙向）

| 方向 | 設定位置 | 說明 |
|---|---|---|
| 我方 → CCPayment | CCPayment Dashboard → Developer → IP Whitelist | 原文 `If you have set up an IP whitelist on Dashboard > Developer, ensure the IP address of your requests is included in the whitelist.`（markdown 版 3.1）⇒ 商戶設了白名單時，我方出站公網 IP 必須在名單內，否則回 `12002 IpNotInWhitelist` |
| CCPayment → 我方 | 我方防火牆 / nginx | CCPayment Webhook 發送 IP：**`0.0.0.0`**（markdown 版 5.2） |

---

## 2. 請求規範

以下取自 markdown 版 3.2：

| 規則 | 內容 |
|---|---|
| Transfer Mode | HTTPS |
| Submit Mode | POST（個別 API 可能不同） |
| Content-Type | `application/json` |
| Char Encoding | UTF-8 |
| Signature Algorithm | HmacSHA-256（另支援 RSA） |

### 2.1 Request Header

markdown 版 3.3 原文：`Headers for all requests made to CCPayment follow the same rule.`

| Header | 型別 | 必填 | 說明 |
|---|---|---|---|
| `Appid` | String | ✅ | CCPayment APP ID |
| `Timestamp` | String | ✅ | **10 位 Unix 時間戳（秒）**，有效期 **2 分鐘**。例：`1677152720` |
| `Sign` | String | ✅ | HMAC-SHA256 的 hex 簽名，或 RSA 的 Base64 簽名（見 2.2）。HMAC 範例：`871f0223c66ea72435208d03603a0cb00b90f6ac4a4ba725d00164d967e291f6` |
| `User-Agent` | String | ✅ | **標準 User-Agent 為必填**，才能通過 CCPayment 的安全閘道；缺少或無效**可能**回 **403 Forbidden**。例：`CCPayment-Client/1.0` 或一般瀏覽器的 UA |

> ✅ `Timestamp` 單位已實測確認為秒級：2026-09-10 以測試商戶 GFFTEST 用秒級 10 位簽名呼叫 `/getCoinList`，回應 `code: 10000` 成功，與 markdown 版 3.3、3.4 一致。廠商 GitHub SDK 文檔兩處寫毫秒是錯的（見 `integration.md` §1）。**填錯的後果是全部請求被判 `11006 ReqExpired`。**

> 🔴 `User-Agent` 必填是多數金流商沒有的要求，我方 `Http::logAction()` 出站請求須明確帶上，否則整條通道 403。

### 2.2 簽名（HMAC-SHA256）

簽名字串（signText）為三段字串**直接串接**，無分隔符（markdown 版 3.4，原文照錄）：

```
signText = {Appid} + {Timestamp} + {Payload in JSON format}
(if the request body is empty: signText = {Appid} + {Timestamp})
```

- **HMAC（預設）**：以 `APP_SECRET` 為 key，用 SHA-256 對 signText 做 HMAC，輸出 hex 字串。
- **RSA（替代方案，本次不使用）**：以 RSA 私鑰對 SHA-256 雜湊後的資料簽名，輸出 Base64 字串。

markdown 版 3.4 列的規則（原文）：

- `The payload of the request must be a JSON object.`
- `The payload you sign must be exactly the same as the payload you send in the request.`
- `The timestamp is a 10-digit Unix timestamp in seconds.`

廠商官方 Node 範例（markdown 版 3.5，原文照錄；呼叫不帶參數的 `getCoinList`）：

```javascript
const https = require('https');
const crypto = require('crypto');

const appId = '*** your appId ***';
const appSecret = [REDACTED] your appSecret ***';

const path = 'https://example.invalid/ccpayment/v2/getCoinList';
const args = ''; // empty body

const timestamp = Math.floor(Date.now() / 1000);
let signText = appId + timestamp;
if (args) {
  signText += args;
}

const sign = crypto.createHmac('sha256', appSecret).update(signText).digest('hex');

const options = {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Appid': appId,
    'Sign': sign,
    'Timestamp': timestamp.toString(),
    'User-Agent': 'CCPayment-Client/1.0',
  },
};

const req = https.request(path, options, (res) => {
  let respData = '';
  res.on('data', (chunk) => { respData += chunk; });
  res.on('end', () => { console.log('Response:', respData); });
});

req.write(args);
req.end();
```

帶參數的端點（例如 `getCoin`）只差以下兩行（原文照錄）：

```javascript
const path = 'https://example.invalid/ccpayment/v2/getCoin';
const args = JSON.stringify({ "coinId": 1280 });
```

> ⚠️ 範例的 `const timestamp` **取一次後同時用於簽名與 header**，是正確寫法。若簽名與 header 各取一次時間，跨秒時會驗簽失敗（見 `integration.md` §2）。

### 2.3 限流

markdown 版 3.6（原文）：`Check the specific interface documentation to know the exact rate-limit information. Error code 11004 means you have reached the rate limit. To increase the limit, contact your CCPayment account manager.`

> ⚠️ markdown 版各端點的說明都**沒有列頻率數字**；錯誤碼表另有 `11009 ReqDailyLimit`（每日請求次數上限），數字同樣未載。實際門檻仍待廠商提供（`open-questions.md` 第 14 題）。

---

## 3. 通用回應信封

所有 API 回應統一格式（markdown 版 13）：

```json
{ "code": 10000, "msg": "success", "data": { } }
```

| 欄位 | 說明 |
|---|---|
| `code` | 業務碼，`10000` 為成功 |
| `msg` | 訊息 |
| `data` | 業務資料 |

其他通用約定（整理自 markdown 版）：

- 所有端點都是 `POST`，Base URL 為 `https://example.invalid/ccpayment/v2`（markdown 版 14）。
- 清單類端點以 `nextId` 作為分頁游標。
- 金額、價格、手續費欄位的型別都是 String。

### 3.1 錯誤碼表

以下為 markdown 版 13「Status Codes & Error Codes」列出的全部錯誤碼，分組與 `Error tip`、`Description` 兩欄照錄原文。

**Status Codes**

| Code | Tip | Description（原文） |
|---|---|---|
| `10000` | `Success` | The request has been successfully processed, and the system has returned the required information |
| `10001` | `Failed` | Request failed. Refer to the detailed error message to adjust your request or resolve the issue |

**Common Errors**

| Code | Error tip | Description（原文） |
|---|---|---|
| `11000` | `InvalidArgument` | Invalid argument |
| `11001` | `HeaderInvalidArgument` | Invalid argument in header |
| `11002` | `Internal` | Server-side problem. Try again later or report to the support team |
| `11003` | `NotFound` | Data does not exist |
| `11004` | `RateLimit` | Reached the rate limit. Reduce the frequency of your requests |
| `11005` | `VerifySignFailed` | Signature verification failed |
| `11006` | `ReqExpired` | Request has expired |
| `11007` | `RepeatedSubmit` | Repeated submission |
| `11008` | `QueryDurationTooMax` | Query time range cannot be too large |
| `11009` | `ReqDailyLimit` | Exceeded daily request limit |
| `11010` | `QueryNumMax` | The number of transactions queried is too large. Maximum: 100 |
| `11011` | `OrderDuplicate` | Order ID is repeated |
| `11012` | `ExpiredAtTooMax` | The maximum valid period is 10 days |
| `11013` | `NoSupportVersion` | This account can only access the Version 1 interface |
| `11014` | `MaliciousReq` | Malicious request; this IP has been banned |
| `11015` | `UserIdNotFound` | userId does not exist |
| `11018` | `Too Many Requests` | To avoid concurrent conflicts in batch withdrawal operations, the system employs locking mechanisms and request queuing. This error can arise when a cancellation is attempted during order processing, or when sub-orders are concurrently being appended |

**Account Errors**

| Code | Error tip | Description（原文） |
|---|---|---|
| `12000` | `MerchantDisabled` | Merchant account disabled |
| `12001` | `MerchantNotFound` | Merchant does not exist |
| `12002` | `IpNotInWhitelist` | IP not in whitelist — check the IP whitelist settings on the developer page |
| `12003` | `MerchantApiDisabled` | Go to the dashboard to complete website verification |

**Token Errors**

| Code | Error tip | Description（原文） |
|---|---|---|
| `13000` | `InvalidCoin` | Unsupported coin |
| `13001` | `InvalidChain` | Unsupported network for this token |
| `13002` | `AbnormalCoinPrice` | Abnormal coin price |
| `13003` | `AbnormalCoinPriceNotSupportMode` | Abnormal coin price; only supports merchants paying network fees |
| `13004` | `UnstableBlockchain` | Unstable blockchain. Withdrawal of this coin is not available; try later |

**Withdrawal Errors**

| Code | Error tip | Description（原文） |
|---|---|---|
| `14000` | `BalanceInsufficient` | Not enough balance for withdrawal |
| `14001` | `WithdrawFeeTooLow` | Withdrawal network fee too low. Check the real-time fee via the network fee interface |
| `14002` | `AddressNotActive` | Invalid/inactive receiving address — check if the address is valid |
| `14003` | `AddressEmptyMemo` | Memo for this chain cannot be empty |
| `14004` | `ChainStopWithdraw` | Withdrawals on this chain temporarily suspended |
| `14005` | `WithdrawValueLessThanLimit` | Below the minimum withdrawal limit. Check the limit via Get Token List |
| `14006` | `WithdrawValueMoreThanLimit` | Exceeds the maximum withdrawal limit |
| `14007` | `WithdrawAddrFormat` | Incorrect withdrawal address format |
| `14008` | `WithdrawCannotSelf` | Cannot withdraw to your own CCPayment address |
| `14009` | `CoinNoSupportMemo` | This coin does not support memo |
| `14010` | `NoSupportCoin` | Merchant does not support this token. Check merchant settings or Get Token List |
| `14011` | `WithdrawFeeBalanceNotEnough` | Insufficient native token for paying the network fee |
| `14012` | `NotSupportMerchantTransfer` | Transfer by merchant account ID is only supported between main account and sub-accounts |
| `14013` | `CoinPrecisionLimit` | Exceeded coin precision limit |
| `14014` | `NotFinishCollection` | Unpaid asset-aggregation fees. See details on your dashboard and deposit USDT to pay the outstanding balance |
| `14015` | `WithdrawToContractAddress` | Cannot withdraw to contract addresses |
| `14016` | `WithdrawInvalidAddressType` | Address type error; only transfers to data accounts are supported |
| `14025` | `Invalid Memo Format` | Verify that the withdrawal address memo meets the on-chain requirements |
| `14027` | `Duplicate Sequence Number` | Duplicate sequence numbers detected when calling the Validate Batch Withdrawal Addresses endpoint |
| `14028` | `Cannot Terminate/Cancel` | The current order or sub-order status does not support the terminate/cancel operation |
| `14029` | `Blacklisted Address` | The payout address has been blacklisted and cannot be used for payouts |

**Deposit Errors**

| Code | Error tip | Description（原文） |
|---|---|---|
| `15000` | `GenerateAddressFailed` | Failed to generate address — try again |
| `15001` | `PaymentAddressNumTooMuch` | Available deposit addresses for this account reached the maximum limit |
| `15003` | `ChainStopDeposit` | Deposits for this chain are suspended |

> ⚠️ 上表以外的錯誤碼，markdown 版沒有定義。廠商 GitHub SDK 文檔另列的碼（例如 `14017`–`14024`）與說明文字的差異見 `integration.md` §8；依單一事實來源，本檔不收錄。

> 🔴 **代付白名單（`REJECT_CODES`）選碼警示——以下為語意歧義碼，依 `payment-integration` skill 硬規則「不得入白名單」**（它們無法證明三方未建單，誤判為可重送＝重複出款）：
>
> | Code | Error tip | 為何歧義 |
> |---|---|---|
> | `11007` | `RepeatedSubmit` | 重複提交＝前次可能已受理 |
> | `11011` | `OrderDuplicate` | 該訂單號已存在於三方 |
> | `14027` | `Duplicate Sequence Number` | 序號重複，同上（原文說明指批次代付的地址驗證端點） |
> | `11002` | `Internal` | 系統內部錯誤，發生在請求抵達後 |
> | `11018` | `Too Many Requests` | 原文說明是鎖與請求佇列造成的衝突，不排除已建單 |
> | `10001` | `Failed` | 泛用失敗，語意不明 |
>
> 白名單外的**所有未知碼**一律視為結果未知（拋例外 → 訂單停留 21 鎖定重送並告警，人工查證後轉 22 或 29），不得落入「可重送」路徑。

---

## 4. 代收 API（Merchant Deposit）

本節收錄永久地址模式用到的端點，全部為 `POST`。markdown 版把前四支歸在 Deposit APIs（第 7 節），`/rescanLostTransaction` 歸在 Common APIs（11.6）；後者用途與收款相關，一併收錄於此。訂單模式的 `/createAppOrderDepositAddress` 等端點不收錄。

| Endpoint | 用途 | markdown 版 |
|---|---|---|
| `/getOrCreateAppDepositAddress` | 永久地址模式：取得或建立會員的固定收款地址 | 7.1 |
| `/getAppDepositRecord` | 查詢單筆存款紀錄（收到 `DirectDeposit` webhook 後回查用） | 7.7 |
| `/getAppDepositRecordList` | 查詢存款紀錄清單 | 7.8 |
| `/addressUnbinding` | 解除地址與 `referenceId` 的綁定（§4.5） | 7.6 |
| `/rescanLostTransaction` | 鏈上已轉入卻遲遲沒入帳時，觸發重新掃描該筆交易（§4.6） | 11.6 |

### 4.1 `POST /getOrCreateAppDepositAddress` — 永久地址模式（Get Permanent Deposit Address）

廠商說明（markdown 版 7.1，原文摘譯）：帶 `referenceId` 與 `chain` 呼叫時，CCPayment 先檢查該 reference ID 是否已有永久地址——

- **既有地址**：已有永久地址綁定該 reference ID 時，回傳該地址。
- **新地址**：尚無地址綁定該 reference ID 時，在指定鏈上為該 reference ID 產生新的收款地址並回傳。

> 🔴 **配額（廠商原文）**：`Each APP ID can obtain 1000 addresses via this interface. If you need more, contact customer service.`
> ⇒ 每個 APP ID 經此介面**預設**可取得 **1000 個永久地址**，要更多須聯繫廠商。

**配額的廠商補充說明**（markdown 版未載，取自 2026-09-14 TG 回覆，原文照錄於 `open-questions.md` 第 15 題）：

| 項目 | 廠商說法 |
|---|---|
| 擴容 | 超過 1000 可聯繫廠商申請增加；**沒有固定上限**，依實際業務量持續擴容；**不額外收費** |
| 計算單位 | **按 APP ID 各自計算**：Tpower 與 GFFTEST 若是兩個不同的 APP ID，配額各自獨立 |
| 解綁與配額 | 呼叫 `Address Unbinding` 解綁後，原地址已占用的配額**不會釋放** |
| 解綁後的舊地址 | 解綁只解除地址與原 `referenceId` 的綁定；解綁後轉進舊地址的款項**仍會記入我方 CCPayment 帳戶** |
| 廠商建議 | 會員數量大時**不建議**用頻繁解綁的方式回收或輪用地址；配額不夠就直接申請擴容 |

> **配額用完的錯誤碼**（markdown 版 13）：`15001 PaymentAddressNumTooMuch`，原文 `Available deposit addresses for this account reached the maximum limit`。⚠️ markdown 版的端點索引（第 14 節，共 53 支）沒有查詢已用配額的端點，也沒寫擴容申請多久生效（`open-questions.md` 第 20 題）。

> **多鏈語意**：markdown 版以 reference ID 為單位描述既有地址的判定，**沒有說明**同一個 `referenceId` 換一條鏈取址會回傳什麼，也沒寫每條鏈是否各占一個配額（`open-questions.md` 第 16 題）。另依 §4.5 的廠商規則，EVM 相容鏈的地址解綁會連動，推測 EVM 各鏈共用同一地址（**未驗證**）。

**Request**

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `referenceId` | String | ✅ | 3–64 字元。原文 `Unique reference ID for the user in your system` |
| `chain` | String | ✅ | 鏈別代號。原文 `Call Get Token Information and use data.coin[index].networks[chain].chain to get the chain symbol.` 廠商範例：`"chain": "TRX"` |
| `notifyUrl` | String | — | 以自訂網址接收本請求的 Webhook，網域須在 notifyUrl Domain Whitelist 內。**見下方 Notice** |

> 🔴 **`notifyUrl` 綁定於地址、持久有效（廠商原文，markdown 版 7.1）**：`Notice: if you previously passed a notifyUrl for a specific address and the call succeeded, subsequent calls for the same address without a new notifyUrl still send webhooks to the previously used notifyUrl`（markdown 版 5.3 另以 `Special rule for getOrCreateAppDepositAddress` 重申）
> ⇒ 首次取址時帶入的 `notifyUrl` 會沿用到該地址之後的所有存款通知；後續呼叫未帶新值時不會清空。

**Response `data`**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `address` | String | 永久收款地址 |
| `memo` | String | 需要 memo 的代幣（如 XRP、XLM），付款方必須填入正確 memo 才能付款 |

廠商回應範例（markdown 版 7.1，原文照錄）：

```json
{ "code": 10000, "msg": "success", "data": { "address": "0x7C631947c139F0163fECc0eef39f251D72dAE3B8", "memo": "" } }
```

### 4.2 `POST /getAppDepositRecord` — 查詢單筆存款

廠商說明（markdown 版 7.7，原文）：`Retrieves detailed information for a specific record ID. This is a crucial endpoint to get transaction details and confirm transaction status. Use the returned information to update transaction status and credit your user accordingly.`

**Request**

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `recordId` | String | ✅ | CCPayment 交易唯一 ID |

**Response `data`**：`record`（欄位見 4.4）

### 4.3 `POST /getAppDepositRecordList` — 查詢存款清單

廠商說明（markdown 版 7.8，原文）：`Gets a list of deposit records within a specific time range, sorted by creation time in descending order.`

**Request**

| 欄位 | 型別 | 必填 | 說明（原文） |
|---|---|---|---|
| `coinId` | Integer | — | Coin ID |
| `referenceId` | String | — | 3–64 characters. Unique reference ID for the user in your system |
| `orderId` | String | — | Order ID, 3–64 characters. Do not pass both order ID and reference ID in the same request |
| `chain` | String | — | Chain symbol |
| `startAt` | Integer | — | Retrieve records starting from this timestamp |
| `endAt` | Integer | — | Retrieve records up to this timestamp |
| `nextId` | String | — | Pagination cursor |

**分頁**（原文）：`if the query result exceeds 20 records, a nextId field is returned; use the same query conditions plus nextId to retrieve the remaining data.`

**Response `data`**：`records[]`（每筆欄位同 4.4）、`nextId`

> 廠商建議（markdown 版 6.1，原文）：`Although CCPayment provides real-time webhook notifications, merchants should still set up a scheduled reconciliation mechanism (poll/reconcile) and regularly call this API to prevent missing transactions caused by network anomalies.`
>
> ⚠️ 同一段 6.1 寫這支 API 可依 `time range, user ID, or transaction hash (txId)` 篩選，但 7.8 的參數表沒有 `txId`；能否依 `txId` 查詢**未驗證**。

### 4.4 存款紀錄欄位（`data.record`）

markdown 版 7.7 列的回傳欄位（清單端點 `records[]` 每筆相同）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `recordId` | String | CCPayment 交易唯一 ID（**冪等依據**，見 §5.3） |
| `coinId` | Integer | 存款代幣 ID |
| `coinSymbol` | String | 存款代幣代號 |
| `chain` | String | 鏈別代號 |
| `contract` | String | 合約 |
| `coinUSDPrice` | String | 收款當下的代幣美元價格 |
| `fromAddress` | String | 付款地址；**UTXO 類交易不回傳**（實際付款方在多個輸入地址之中，要向付款人確認） |
| `toAddress` | String | 收款地址 |
| `toMemo` | String | 收款地址的 memo |
| `amount` | String | 實收數量 |
| `serviceFee` | String | 服務費 |
| `txId` | String | 鏈上交易 ID |
| `status` | String | `Success`／`Processing`／`Failed`（各值說明見 §5.4） |
| `isFlaggedAsRisky` | Boolean | 是否為風險交易（見 §5.4） |
| `arrivedAt` | Integer | 到帳時間戳 |
| `referenceId` | String | **只有綁定了 reference ID 的款項才回傳** |
| `orderId` | String | **只有綁定了訂單 ID 的款項才回傳** |

廠商回應範例（markdown 版 7.7，原文照錄）：

```json
{
  "code": 10000,
  "msg": "success",
  "data": {
    "record": {
      "recordId": "20250116073333231508600365121536",
      "orderId": "1737011983",
      "coinId": 1482,
      "coinSymbol": "TRX",
      "chain": "TRX",
      "contract": "TRX",
      "coinUSDPrice": "0.23717",
      "fromAddress": "TRPKg7eGMy9aZS2RumSPeWoyVkDqTVwLgL",
      "toAddress": "TAkmn3f8bgwMAwdVrGfUVSRg4W2XwqgHGc",
      "toMemo": "",
      "amount": "0.5",
      "serviceFee": "0.0025",
      "txId": "f39abf3275607fe2ffd40c06adf877f249829f6d1146a4f72ca2ad79ed7ed072",
      "status": "Success",
      "arrivedAt": 1737012813,
      "isFlaggedAsRisky": false
    }
  }
}
```

> ⇒ 這個官方範例是一筆 **TRX 原生幣**的紀錄（`coinId: 1482`），同一條鏈底下收得到的不只 USDT；判定入帳時不能只看 `status`，還要比對 `coinId` 與 `chain`。
> 🔴 `isFlaggedAsRisky = true` 時款項不會計入我方 CCPayment 餘額（見 §5.4 廠商警告一），處置規則待與 PM 確認（是否照常入帳、或轉人工）。

### 4.5 `POST /addressUnbinding` — 地址解綁（Address Unbinding）

以下取自 markdown 版 7.6。完整網址：`https://example.invalid/ccpayment/v2/addressUnbinding`。

廠商說明（原文）：`Unbinds a deposit address from a userID/referenceID.`

使用案例（原文）：`if a deposit address is flagged as risky, unbinding it helps prevent potential asset freezing by the network. Once unbound, obtain a new deposit address for the same userID/referenceID by calling Get Permanent Deposit Address (7.1) / Create or Get User Deposit Address (10.2).`

**Request**

| 欄位 | 型別 | 必填 | 說明（原文） |
|---|---|---|---|
| `chain` | String | ✅ | Chain symbol of the address to be unbound |
| `address` | String | ✅ | Address to be unbound |

**Response `data`**

| 欄位 | 型別 | 說明（原文） |
|---|---|---|
| `userID` | String | Returned if the address was previously bound to a user ID |
| `referenceID` | String | Returned if the address was previously bound to a referenceID |
| `unbound` | Array | Unbound address details (chain + address) |
| `unbound[index].chain` | String | Network of the unbound address |
| `unbound[index].address` | String | Unbound address |
| `unboundAt` | Integer | Timestamp of the unbinding operation |

廠商回應範例（原文照錄）：

```json
{
  "code": 10000,
  "msg": "success",
  "data": {
    "unbound": [ { "chain": "POLYGON", "address": "0xa9a363196b22c1760cc7B777C5dD6264C376F20a" } ],
    "unboundAt": 1741783734,
    "userID": "",
    "referenceID": "1735287108"
  }
}
```

> ⇒ 以 `referenceId` 綁定的地址，回應的 `userID` 為空字串、`referenceID` 帶原綁定值。

廠商規則（原文）：

- `If an address on an EVM-compatible blockchain (e.g., ETH, BSC, Polygon) is unbound, the corresponding deposit addresses on other EVM-compatible networks are also automatically unbound.`
- `After unbinding, payments sent to the unbound address can still be credited to your merchant account.`

markdown 版 6.1 的接入指引另寫（原文）：`When a user's account status changes (e.g., account cancellation, security risk management, or the need to generate a new address), call the unbinding API with the relevant address details to unbind the address from its corresponding referenceId. Deposits to old addresses will still be credited. To ensure fund security, CCPayment strongly recommends you stop depositing to any address that has been unbound.`

配額不會因解綁而釋放（markdown 版未載，取自廠商 TG 回覆，見 §4.1）。

> ⚠️ 本端點專屬的錯誤碼、舊地址原本綁定的 `notifyUrl` 是否隨解綁清除，markdown 版都未載明（`open-questions.md` 第 17 題）。

### 4.6 `POST /rescanLostTransaction` — 重新掃描漏入帳的交易（Rescan Lost Transaction）

以下取自 markdown 版 11.6（該檔歸在 Common APIs）。

廠商說明（原文）：`Triggers a rescan for a deposit transaction. Use case: a user confirms the on-chain transaction was successful but the funds haven't been credited — call this endpoint (prompting the user to submit the transaction details); the system rescans the transaction to speed up crediting.`

**Request**（原文）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `chain` | String | ✅ | Chain symbol. Get chain info by calling Get Chain List |
| `toAddress` | String | ✅ | Receiving address of the deposit |
| `memo` | String | — | Memo for memo-required coins (XRP, XLM, TON, etc.) |
| `txId` | String | ✅ | TXID |

**Response `data`**：`description`（String）。原文：`detailed message returned by the system; you can display it to the user to indicate the current deposit processing status.`

廠商回應範例（原文照錄）：

```json
{ "code": 10000, "msg": "success", "data": { "description": "The deposit has been rescanned. Please wait for the transaction to be credited." } }
```

---

## 5. Webhook（回調）

### 5.1 設定與發送範圍

- **Webhook URL**：於 Dashboard > Developer > Webhook URL 設定接收網址（全域；markdown 版 5.2）。
- **發送範圍**（markdown 版 5.1，原文）：`Deposits of coins in the "Tokens for your business" list will have webhook notifications sent. Deposits of non-supported coins for your business will NOT trigger webhooks.` 清單在 Dashboard > Settings > Tokens for your business 設定。
- **不發 Webhook 的交易**（markdown 版 5.7，原文）：`No webhooks are sent for: withdrawals to Cwallet accounts, user internal transfers, and swaps.`
- **Webhook 紀錄與手動重送**：Dashboard > Webhook 可查看紀錄，並可單筆或批次重送（markdown 版 5.11）。

另可於 Dashboard > Developer > notifyUrl Domain Whitelist 加入網域後，在支援的 API 以 `notifyUrl` 參數指定該次呼叫的接收網址。markdown 版 5.3 列了七支，本專案用到的兩支：

| API | Endpoint |
|---|---|
| Get Permanent Deposit Address | `getOrCreateAppDepositAddress` |
| Create Single Withdrawal Order | `applyAppWithdrawToNetwork` |

（其餘五支屬訂單模式、Wallet 模式與批次代付，不收錄。）

> ⇒ 我方回調網址帶 `agent_cash_flow_id`（`Common::depositCallbackUrl()` / `withdrawalCallbackUrl()`），`DepositNotifyController` 缺該 id 會直接拋例外，因此**取址與代付請求都應帶 `notifyUrl`**，不可依賴全域網址。永久地址的 `notifyUrl` 綁定語意見 §4.1。

### 5.2 回應格式與重試

- **回應設定**（markdown 版 5.4 Step 3，原文）：`Set the response format to Content-Type: text/plain; charset=utf-8 and return HTTP 200 with a "Success" string in the HTTP payload. When CCPayment receives the correct response, it stops pushing the notification.`
- **重試**（markdown 版 5.5，原文）：`If CCPayment does not receive HTTP 200 with a "Success" string in the response body, it retries up to 6 times at these intervals:`

| 次數 | 間隔（原文 Interval） |
|---|---|
| 1 | 30 秒 |
| 2 | 1 分 30 秒 |
| 3 | 3 分 30 秒 |
| 4 | 7 分 30 秒 |
| 5 | 15 分 30 秒 |
| 6 | 31 分 30 秒 |

> ⚠️ markdown 版只寫 `Interval`，沒說是距首次發送還是距上一次重試。兩種解讀下，最後一次重試都在首次發送的 30 分鐘之後。

> ⚠️ **成功字串的大小寫，markdown 版前後不一致**：
>
> | 出處（markdown 版） | 寫法 |
> |---|---|
> | 5.4 Step 3 回應設定 | `"Success"` |
> | 5.5 重試條件 | `"Success"` |
> | 附錄 Critical Rules Checklist 第 5 條 | body `"Success"` |
> | 5.4 的 Node.js 驗簽範例 | `res.send('success')`（小寫） |
>
> CCPayment 比對時是否區分大小寫**未驗證**，端到端實測時確認。我方目前回傳 `Success`（依文字說明）。

### 5.3 冪等性（廠商要求）

廠商明文要求商戶自行實作冪等（markdown 版 5.6）：

- **冪等依據**：CCPayment 的唯一交易識別碼 **record ID**。
- **處理方式**：收到存款通知時，先以 record ID 檢查該交易是否已入庫；已存在則跳過，避免重複入帳。
- **免責聲明**：廠商聲明對「商戶未實作冪等導致的重複入帳」不負責任。

> markdown 版附錄 Critical Rules Checklist 第 4 條另寫（原文）：`webhook retries (up to 6) will redeliver the same event.`

### 5.4 永久地址存款 Webhook（`type = DirectDeposit`）

廠商原文（markdown 版 7.1）：`Webhook for Direct Deposit — after receiving it, call Get Deposit Record (7.7) to confirm the deposit information`

> 廠商 2026-09-14 TG 回覆再次重申（原文照錄於 `open-questions.md` 第 2 題）：webhook 只是通知，收到 `DirectDeposit` 後要呼叫 `getAppDepositRecord` 回查，確認交易資訊與實際金額。

**Payload 範例（markdown 版 7.1 原文；識別碼在廠商原文中即已截短）**

```json
{
  "type": "DirectDeposit",
  "msg": {
    "recordId": "2024031311310811...",
    "referenceId": "63224704901...",
    "coinId": 1329,
    "coinSymbol": "MATIC",
    "status": "Success",
    "isFlaggedAsRisky": false
  }
}
```

**Webhook Parameters**（markdown 版 7.1）

| 參數 | 型別 | 說明 |
|---|---|---|
| `type` | String | 固定為 `DirectDeposit` |
| `msg.recordId` | String | CCPayment 交易唯一 ID |
| `msg.referenceId` | String | 取址時送出的我方參考 ID |
| `msg.coinId` | Integer | 代幣 ID |
| `msg.coinSymbol` | String | 代幣代號 |
| `msg.status` | String | `Processing`／`Success`／`Failed`（說明見下） |
| `msg.isFlaggedAsRisky` | Boolean | 是否為風險交易（說明見下） |

**`msg.status` 的意思**（markdown 版 5.8「Transaction Statuses in Webhooks」，原文）

| 值 | 說明 |
|---|---|
| `Processing` | Blockchain is processing the transaction |
| `Success` | The transaction has been confirmed |
| `Failed` | The transaction was not completed and the funds were not received |

> ⇒ markdown 版 7.1 與 5.8 都**沒有 `Pending`**。回調判定只把回查結果（§4.4）為 `Success` 的視為可入帳，其餘值（含未列舉的值）一律不入帳、等後續通知；廠商在 6.6.4 的永久地址指引也寫 `Only credit if Success and not risky.`（`open-questions.md` 第 18 題）。

**`msg.isFlaggedAsRisky`**（markdown 版 7.1，原文）：`true: risky — the amount will not be credited; process it at Transaction > Risky Transaction on the dashboard. false: not risky`

> 🔴 **廠商警告一（markdown 版 5.9，原文）**：`If a payment's isFlaggedAsRisky is true, CCPayment will NOT automatically credit the amount to your CCPayment account. Process the amount at Dashboard > Transaction > Risky Transaction. CCPayment strongly recommends not automatically crediting risky payments to your users' accounts. After processing, you can choose to credit the user or refund the payment manually.`
>
> ⇒ `isFlaggedAsRisky = true` 時**款項不在我方 CCPayment 餘額內**，自動入帳等於用自己的錢墊付。

> 🔴 **廠商警告二（markdown 版 5.10，原文）**：`The webhook is for transaction status notification only. After receiving a webhook, always call the corresponding Get Record / Get Order Information API to confirm the actual payment status. Do not treat the webhook status as the final status — this may result in asset loss. CCPayment is not responsible for losses resulting from incorrect integrations.`
>
> ⇒ 廠商明確要求**收到 webhook 後必須回查確認**，不得直接以 webhook 的 status 定案；永久地址模式的回查端點為 `getAppDepositRecord`（本節首行廠商原文），另見 §0.1 廠商提醒 `never trust a webhook alone`。

> 🔴 payload **沒有 `orderId`**（永久地址不綁訂單），也**沒有 `amount`、`chain`、`address`**——金額與到帳地址只能回查 `getAppDepositRecord`（§4.2，回傳的 `data.record` 含 `amount`、`chain`、`toAddress`、`referenceId`）。

### 5.5 代付 Webhook（`type = ApiWithdrawal`）

廠商原文（markdown 版 8.1）：`Webhook for API Withdrawal — payload includes both the order ID and record ID; use the record ID with Get Withdrawal Record to retrieve transfer info and update status / deduct from your user's account`

發送時機（markdown 版 8.1，原文）：`if a withdrawal is processing, CCPayment sends a webhook with status Processing; when confirmed, a second webhook with status Success. In some cases on-chain processing is too fast to capture the processing state — only one webhook with status Success is sent.`

**Webhook Parameters**（markdown 版 8.1）

| 參數 | 型別 | 說明 |
|---|---|---|
| `type` | String | 固定為 `ApiWithdrawal` |
| `msg.recordId` | String | CCPayment 交易唯一 ID |
| `msg.orderId` | String | 我方訂單唯一 ID |
| `msg.coinId` | Integer | 代幣 ID |
| `msg.coinSymbol` | String | 代幣代號 |
| `msg.status` | String | 見下方狀態表 |

> markdown 版 8.1 沒有附 `ApiWithdrawal` 的 payload 範例；參數表**沒有** `isFlaggedAsRisky` 欄位。

**`msg.status` 列舉**（值取自 markdown 版 8.1、說明取自 5.8，原文，共 5 態）

| 值 | 說明 |
|---|---|
| `WaitingApproval` | Withdrawal is waiting manual approval (only if merchant enabled withdrawal approval on the dashboard) |
| `Rejected` | Withdrawal request has been rejected by the merchant (only if approval is enabled) |
| `Processing` | Blockchain is processing the transaction |
| `Success` | The transaction has been confirmed |
| `Failed` | The transaction was not completed and the funds were not received |

> 廠商補充（markdown 版 8.1，原文）：`Once a withdrawal is initiated, the blockchain confirms within a few minutes; however, due to rare external circumstances, merchants may sometimes fail to receive timely webhook notifications — poll Get Withdrawal Record as a fallback.`

> markdown 版附錄「Critical Rules Checklist」第 8 條（原文）：`Withdrawal reliability: on network jitter/timeouts, call Get Withdrawal Record before retrying; if no Success webhook after 2 hours, proactively poll.`

### 5.6 主動重送 Webhook（`Resend Webhook by Calling API`）

廠商提供 API 讓商戶主動要求重送指定時段的 webhook，可用於回調漏收的補救（markdown 版 5.12）。

**端點**：`POST https://example.invalid/ccpayment/v2/webhook/resend`

**Request Parameters**

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `recordIds` | Array | — | 指定要重送的 record ID，**單批最多 50 筆** |
| `startTimestamp` | Integer | ✅ | 重送此時間之後建立的交易，**須為 10 位時間戳** |
| `endTimestamp` | Integer | — | 重送此時間之前建立的交易；留空則為 start 之後一小時內。**start 與 end 最大間隔 1 小時** |
| `webhookResult` | String | — | `Failed`（預設，僅重送該時段失敗的 webhook）／ `AllResult`（重送該時段全部） |
| `transactionType` | String | — | `AllType`（預設）／ `ApiDeposit` ／ `DirectDeposit` ／ `ApiWithdrawal` ／ `UserDeposit` ／ `UserWithdrawal` |

**Response `data`**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `resendCount` | Integer | 成功重送的 webhook 數量（原文 `number of webhooks successfully resent`） |

廠商回應範例（原文照錄）：

```json
{ "code": 10000, "msg": "Success", "data": { "resendCount": 1 } }
```

### 5.7 Webhook 驗簽

**Webhook 有簽名機制**，廠商在整合工作流中明文要求驗簽（markdown 版 6.6.3 Option A，原文）：`On payment you receive a DirectDeposit webhook — verify the signature, then confirm final status and isFlaggedAsRisky via getAppDepositRecord.`

簽名放在 **HTTP request header**，payload 結構（`type` + `msg`）內沒有簽名欄位。`DirectDeposit`（§5.4）、`ApiWithdrawal`（§5.5）與風險地址 Webhook（§5.9）**採用同一套驗簽規則**（markdown 版未寫，取自廠商 2026-09-14 TG 回覆，原文照錄於 `open-questions.md` 第 2 題）。

以下規則取自 markdown 版 5.4「How to Handle Incoming Webhooks」，英文原文逐句翻譯，不增減語意。

**步驟一：驗證簽名**。原文：`Always verify that the signature provided in the webhook matches the one you generate.` Webhook 請求帶以下 header：

| 參數名 | 型別 | 必填 | 說明（原文） |
|---|---|---|---|
| `Appid` | String | 是 | Your CCPayment APP ID |
| `Timestamp` | String | 是 | 10-digit timestamp. Valid within 2 minutes |
| `Sign` | String | 是 | Signature over `{Appid} + {Timestamp} + {Payload JSON}` |

| 演算法 | 廠商步驟（譯） |
|---|---|
| **HMAC**（我方 API 請求採用此法） | 以 HMAC-SHA256 對 `{Appid}+{Timestamp}+{payload}` 產生簽名值，與 header 的 `Sign` 比較。相等即驗簽通過、可繼續處理；不相等即驗簽失敗，請求可能遭竄改 |
| RSA（本專案不使用） | 將 Base64 編碼的簽名解碼，用 RSA 金鑰解密，解出的資料應為 `{APP ID} + {Timestamp} + {Payload JSON object}`；與預期的串接結果比對，一致即通過，不一致即失敗 |

**步驟二：解析 payload**（譯）：用 payload 裡的 `recordId` 或 `referenceId`／`orderId` 呼叫對應的 Get Record 端點取得交易明細。原文強調：`Credit users based on the transaction details returned by the interface, not the webhook alone.`

**步驟三：回應**：`Content-Type: text/plain; charset=utf-8`、HTTP 200、body 含 `"Success"`（原文與大小寫問題見 §5.2）。

廠商 Node.js 驗簽範例（markdown 版 5.4，原文照錄；markdown 版只附這一種語言）：

```javascript
const express = require('express');
const crypto = require('crypto');
const app = express();
function verifySignature(content, signature, app_id, app_secret, timestamp) {
  let sign_text = `${app_id}${timestamp}${content}`;
  let server_sign = crypto.createHmac('sha256', app_secret).update(sign_text).digest('hex');
  return signature === server_sign;
}
app.use(express.text());
app.post('/webhook', (req, res) => {
  const app_id = 'your_app_id';
  const app_secret = [REDACTED]';
  const timestamp = req.header('Timestamp');
  const sign = req.header('Sign');
  const sign_text = req.body;
  if (verifySignature(sign_text, sign, app_id, app_secret, timestamp)) {
    res.send('success');
  } else {
    res.status(401).send('Invalid signature');
  }
});
app.listen(3000);
```

從範例讀出的寫法：

| 項目 | 範例寫法 | 結論 |
|---|---|---|
| 被簽的 body | `app.use(express.text())` 後直接用 `req.body`，也就是**收到的原始 body 字串** | ✅ 簽原始 body，不是重新序列化的 JSON |
| APP ID | 用**商戶自己保存的值**（寫死 `your_app_id`），沒有讀 header 的 `Appid` | ✅ 用我方保存的 APP ID |
| Timestamp | 直接取 header 的 `Timestamp` 拼入，**沒有檢查時效** | ⏳ 見下方未驗證點 |
| 比對方式 | `===`（不是常數時間比較） | 我方改用 `hash_equals` |
| 驗簽後的回應 | 通過回小寫 `success`；失敗回 HTTP 401 | 大小寫矛盾見 §5.2 |

> ⚠️ 仍**未驗證**：重送時 `Timestamp` 會不會換新。markdown 版的 webhook header 表寫 `Valid within 2 minutes`，但重送最晚在首次發送的 30 分鐘之後（§5.2）；若重送沿用首次發送的時間、我方又照表檢查 2 分鐘時效，重送會全部驗簽失敗。官方範例沒有檢查時效。

其他已知的來源驗證手段：**發送來源 IP `0.0.0.0`**（markdown 版 5.2）。

> 🔴 **實作要點**：`CredentialGuardTrait`（空憑證守衛）須一併掛上；三種 webhook 共用同一個驗簽方法；比對簽名用常數時間比較（PHP `hash_equals`），不要照範例用 `===`。
> 🔴 無論驗簽如何實作，**廠商都要求不可僅憑 webhook 入帳**（§0.1 廠商提醒、§5.4 廠商警告二），必須回查 `getAppDepositRecord` 且 `isFlaggedAsRisky = false`。

### 5.8 Webhook type 對照

本專案會收到的三種（markdown 版 5.7）：

| type | 對應 | 確認用端點 |
|---|---|---|
| `DirectDeposit` | Permanent Address Mode 存款（規格見 §5.4） | `getAppDepositRecord` |
| `ApiWithdrawal` | 商戶代付（規格見 §5.5） | `getAppWithdrawRecord` |
| （**無 `type` 欄位**） | 風險地址 Webhook（規格見 §5.9） | — |

> 廠商另有 `ApiDeposit`（訂單模式）、`UserDeposit`／`UserWithdrawal`（Wallet 模式）、`ApiBatchWithdrawal`（批次代付）四種 type，屬本檔不收錄的模式。§5.6 主動重送的 `transactionType` 可用值**不含 `ApiBatchWithdrawal`**。

### 5.9 風險地址 Webhook（無 `type` 欄位）

廠商原文（markdown 版 5.9）：`risky addresses are immediately disabled. CCPayment sends a webhook when an address has been flagged as risky. Deposits to flagged addresses will not trigger automatic webhook notifications; you can manually send webhooks from the dashboard for those deposits.`

**Webhook Parameters**

| 參數 | 型別 | 說明（原文） |
|---|---|---|
| `chainId` | String | Chain ID |
| `address` | String | The receiving address flagged as risky |
| `riskAt` | Integer | Timestamp when the address was flagged |

廠商建議動作（原文）：`After receiving this webhook, promptly inform your clients to stop depositing to the flagged address and call the Address Unbinding API (Section 7.6) to unbind the address.`

> 🔴 三個實作含意：
> 1. 此 webhook **沒有 `type`、`referenceId`、`recordId`**，信封結構與 §5.4 不同；我方回調入口須以「有無 `riskAt`／`type`」判斷分流。反查是哪位會員的地址只能靠 `address`（＋`chainId`）。
> 2. 地址被標記後**立即停用**，之後轉進該地址的款項**不會發 webhook**——系統若未處理此通知，會員後續存款會在 CCPayment 端無聲累積（廠商後台可手動重送）。
> 3. 廠商指示呼叫 `Address Unbinding` 解綁後再取新地址，端點規格見 §4.5。解綁不會回收配額（廠商 2026-09-14 回覆，見 §4.1），換發新地址會再占用一個配額。

`chainId` 的值域與 `chain`（如 `TRX`）的對應關係，markdown 版未載明，**待確認**（`open-questions.md` 第 19 題）。

---

## 6. 代付 API（Merchant Withdraw）

本節收錄單筆代付用到的端點，全部為 `POST`。markdown 版把前四支歸在 Withdrawal APIs（第 8 節），其餘歸在 Common APIs（第 11 節）；批次代付（8.5）不收錄。

| Endpoint | 用途 | 本次是否使用 | markdown 版 |
|---|---|---|---|
| `/applyAppWithdrawToNetwork` | **提幣到外部鏈地址** | ✅ 主要代付入口 | 8.1 |
| `/applyAppWithdrawToCwallet` | 提幣到 Cwallet 帳戶（站內轉帳） | ❌ | 8.2 |
| `/getAppWithdrawRecord` | 查詢單筆代付紀錄 | ✅ 對應 `SupportsPayoutQuery` | 8.3 |
| `/getAppWithdrawRecordList` | 查詢代付紀錄清單 | ⭕ 對帳用 | 8.4 |
| `/checkWithdrawalAddressValidity` | 檢查收款地址能否收款 | ⭕ 廠商在 Gaming 指引列為代付第一步（§0.1） | 11.8 |
| `/getWithdrawFee` | 查詢網路手續費 | ⭕ 視 `merchantPayNetworkFee` 設計而定 | 11.9 |
| `/getCwalletUserId` | 查詢 Cwallet 使用者 | ❌ | 11.7 |
| `/getAutoWithdrawRecordList` | 查詢自動提幣紀錄 | ❌ | 11.13 |
| `/getRiskyRefundRecordList` | 查詢風險存款的退款紀錄 | ❌ 與代付無關（見 6.6） | 11.14 |

### 6.1 `POST /applyAppWithdrawToNetwork` — 提幣到外部鏈地址

廠商說明（markdown 版 8.1，原文）：`Creates a withdrawal order to a blockchain address.` Webhook 的發送時機見 §5.5。

**Request**

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `coinId` | Integer | ✅ | 代幣 ID |
| `chain` | String | ✅ | 鏈別代號 |
| `address` | String | ✅ | 提幣目的地址 |
| `memo` | String | — | 提幣地址的 memo |
| `orderId` | String | ✅ | 代付訂單號，3–64 字元 |
| `amount` | String | ✅ | 提幣數量 |
| `merchantPayNetworkFee` | Boolean | — | 網路手續費是否由商戶承擔。原文：`true: merchant pays network fee → net receivable = withdrawal amount. false (default): user pays network fee → net receivable = withdrawal amount − network fee` |
| `notifyUrl` | String | — | 本次請求的自訂 Webhook 網址（網域須在白名單內） |

**Response `data`**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `recordId` | String | CCPayment 代付紀錄 ID（＝三方單號） |

廠商回應範例（原文照錄）：

```json
{ "code": 10000, "msg": "success", "data": { "recordId": "202403120909501767478092588126208" } }
```

> 🔴 **成功回應只回 `recordId`，沒有狀態欄位**——代付是否成功須靠 Webhook 或 `/getAppWithdrawRecord` 查證定案。

> 廠商規則（markdown 版 8.1 Reliability rule，原文）：`in the rare event of network jitter where you don't receive the response in time, reconfirm the withdrawal status via Get Withdrawal Record (8.3). If it indicates withdrawal success, the request was processed — do not resubmit blindly.`
>
> ⇒ 對應我方「結果未知 → 訂單停留 21 鎖定重送並告警 → 人工查證定案」機制（狀態 23 已於 target/tickets#4137 廢除），廠商有提供查證管道（`/getAppWithdrawRecord`）。

### 6.2 `POST /getWithdrawFee` — 查詢網路手續費

廠商說明（markdown 版 11.9，原文）：`Retrieves the withdrawal network fee for a given token.`

**Request**

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `coinId` | Integer | ✅ | 代幣 ID |
| `chain` | String | ✅ | 鏈別代號 |

**Response `data`**：`fee` 物件，含 `coinId`（Integer，手續費代幣 ID）、`coinSymbol`（String）、`amount`（String）。

廠商回應範例（原文照錄）：

```json
{ "code": 10000, "msg": "success", "data": { "fee": { "coinId": 1329, "coinSymbol": "MATIC", "amount": "0.0213814" } } }
```

### 6.3 `POST /applyAppWithdrawToCwallet` — 提幣到 Cwallet 帳戶（本次不使用）

**Request**（markdown 版 8.2）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `coinId` | Integer | ✅ | 代幣 ID |
| `cwalletUser` | String | ✅ | Cwallet ID 或 Email 皆可 |
| `amount` | String | ✅ | 提幣數量，最低 0.001 USD |
| `orderId` | String | ✅ | 訂單號，3–64 字元 |

**Response `data`**：`recordId`（String）

> 🔴 原文：`For transactions transferred to a Cwallet account, CCPayment does not send a webhook notification.` 要以 `getAppWithdrawRecord` 確認結果。

### 6.4 `POST /getAppWithdrawRecord` — 查詢單筆代付

**Request**（markdown 版 8.3）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `recordId` | String | — | CCPayment 交易唯一 ID |
| `orderId` | String | — | 訂單號，3–64 字元 |

> 規則（原文）：`recordId and orderId cannot both be empty.` ⇒ 兩者至少帶一個；兩者皆空時回什麼錯誤碼，markdown 版未載。

**Response `data`**：`record`（欄位見 6.7）

### 6.5 `POST /getAppWithdrawRecordList` — 查詢代付清單

**Request**（markdown 版 8.4）

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `coinId` | Integer | — | 代幣 ID |
| `orderIds` | Array[String] | — | 訂單號清單，每個 3–64 字元，**每次最多 20 個** |
| `chain` | String | — | 鏈別代號 |
| `startAt` | Integer | — | 起始時間戳 |
| `endAt` | Integer | — | 結束時間戳 |
| `nextId` | String | — | 分頁游標 |

**Response `data`**：`records[]`（每筆欄位同 6.7）、`nextId`。每頁 20 筆，以 `nextId` 翻頁。

### 6.6 `POST /getRiskyRefundRecordList` — 風險存款退款紀錄

廠商說明（markdown 版 11.14，原文）：`Retrieves a list of risk refund records. Multiple query results are returned in reverse order of order-creation time.`

**Request**：`coinId`（Integer）、`chain`（String）、`toAddress`（String）、`startAt`（Integer）、`endAt`（Integer）、`nextId`（String），皆非必填。

**Response `data`**：`records[]`，每筆含 `recordId`、`coinId`、`coinSymbol`、`chain`、`toAddress`、`toMemo`、`amount`、`txId`、`status`（`Processing`／`Success`／`Failed`）、`fee`（網路手續費物件）、`createdAt`、`fromDeposit`；另有 `nextId`。

| 項目 | 原文 |
|---|---|
| `fromDeposit` | `risk-refund associated deposit list`，每筆含風險存款交易的 `recordId` |
| 註記 | `once the risk asset refund is successful, the system deducts the risk refund fee and the corresponding order service fee from the merchant's asset account.` |

> ⇒ 本端點列的是「被判定有風險的**存款**被退款」的紀錄，與我方代付訂單無關。退款由商戶在後台處理風險交易時發起，可以退回付款人，也可以退到商戶指定的其他地址；`toAddress` 是退款實際送達的地址（廠商 TG 回覆，整理在 `integration.md` 第 9 節「風險交易的後續處理」）。容易誤讀的原因見 `integration.md` §8。

### 6.7 代付紀錄欄位（`data.record`）

markdown 版 8.3 列的回傳欄位（清單端點 `records[]` 每筆相同）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `recordId` | String | CCPayment 交易唯一 ID |
| `withdrawType` | String | `Cwallet`：提幣到 Cwallet 使用者；`Network`：提幣到鏈上地址 |
| `coinId` | Integer | 代幣 ID |
| `coinSymbol` | String | 代幣代號 |
| `chain` | String | 鏈別代號 |
| `cwalletUser` | String | Cwallet 使用者 ID，只有 Cwallet 提幣才回傳 |
| `fromAddress` | String | 出款地址；UTXO 類交易與 Cwallet 站內轉帳不回傳 |
| `toAddress` | String | 鏈上收款地址，只有鏈上提幣才回傳 |
| `toMemo` | String | memo，只有鏈上提幣才回傳 |
| `txId` | String | 鏈上交易 ID，只有鏈上提幣才回傳 |
| `status` | String | 狀態，列舉見本節下方 |
| `orderId` | String | 訂單號 |
| `amount` | String | 提幣數量 |
| `fee` | Object | 網路手續費，只有鏈上提幣才有；含 `fee.coinId`、`fee.coinSymbol`、`fee.amount`（參數表三者都標 String，但範例的 `coinId` 是數字） |

廠商回應範例（markdown 版 8.3，原文照錄）：

```json
{
  "code": 10000,
  "msg": "success",
  "data": {
    "record": {
      "recordId": "202403120909501767478092588126208",
      "withdrawType": "Network",
      "coinId": 1891,
      "coinSymbol": "TETH",
      "chain": "POLYGON",
      "fromAddress": "0x1AE2828c18f9E3ae067F569aca1AE2828C4e7",
      "toAddress": "0xBb9C4e7F3687aca1AE2828c18f9E3ae067F569FE",
      "orderId": "1710234589577",
      "txId": "0xb55bb28292de56432b06204f71c68847a71670f2fc311af5c53a6ded45ab047b",
      "toMemo": "",
      "status": "Success",
      "amount": "0.004",
      "fee": { "coinId": 1891, "coinSymbol": "TETH", "amount": "0.001" }
    }
  }
}
```

**`status` 列舉**：`WaitingApproval`／`Rejected`／`Processing`／`Success`／`Failed`（markdown 版 8.3），與代付 Webhook 的 `msg.status`（§5.5）同一組五個值，各值說明見 §5.5。

> **代付三態映射依據已足**（`SUCCESS_CODES` / `FAILURE_CODES` 皆可從 §5.5 逐值指得到依據，且 `FAILURE_CODES` 非空，符合 `payment-integration` skill 的 (A) 雙白名單可列舉模式）；**`REJECT_CODES` 白名單依據見 §3.1 錯誤碼表與其下的歧義碼警示**。

---

## 7. 代幣與鏈別查詢

以下端點在 markdown 版歸在 Common APIs（第 11 節），全部為 `POST`。**廠商未提供靜態的 `coinId` / `chain` 對照表——必須呼叫 API 動態取得。**

| Endpoint | 用途 | 本次是否使用 | markdown 版 |
|---|---|---|---|
| `/getCoinList` | 商戶在後台啟用的代幣清單（無請求參數） | ✅ 取 USDT 的 `coinId` | 11.1 |
| `/getChainList` | 鏈別狀態清單（`chains` 省略時回全部） | ✅ 取 `chain` 值與確認數 | 11.12 |
| `/getCoin` | 單一代幣（`coinId` 必填） | ⭕ | 11.2 |
| `/getCoinUSDTPrice` | 代幣對 USDT 價格（`coinIds` 陣列） | ⭕ | 11.3 |
| `/getFiatList` | 法幣清單（無請求參數） | ❌（`fiatId` 只用於訂單模式計價） | 11.10 |

### 7.1 代幣欄位（`/getCoinList` 的 `data.coins[]`、`/getCoin` 的 `data.coin`）

廠商說明（markdown 版 11.1，原文）：`Retrieves all details of tokens you've enabled for your business ("Tokens for your business").`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `coinId` | Integer | **代幣 ID，代收代付請求用這個值** |
| `symbol` | String | 代幣代號（如 USDT） |
| `coinFullName` | String | 代幣全名 |
| `logoUrl` | String | Logo 網址 |
| `status` | String | 原文：`Normal`: available deposit/withdrawal. `Maintain`: withdrawal suspended. `Pre-delisting`: deposit suspended. `Delisted`: deposit/withdrawal suspended |
| `networks` | Object | 該代幣支援的鏈，**key 為鏈別代號**（如 `BSC`、`ETH`） |
| `networks[chain].chain` | String | 鏈別代號，**API 的 `chain` 參數用這個值** |
| `networks[chain].chainFullName` | String | 鏈全名 |
| `networks[chain].contract` | String | 代幣合約 |
| `networks[chain].precision` | Integer | 精度 |
| `networks[chain].canDeposit` | Boolean | 可否存款 |
| `networks[chain].canWithdraw` | Boolean | 可否提幣 |
| `networks[chain].minimumDepositAmount` | String | 最低存款數量 |
| `networks[chain].minimumWithdrawAmount` | String | 最低提幣數量 |
| `networks[chain].maximumWithdrawAmount` | String | 最高提幣數量，`0` 表示不設上限 |
| `networks[chain].isSupportMemo` | Boolean | 該鏈交易是否需要 memo |

> 🔴 **USDT 是跨鏈代幣**：同一個 `coinId` 之下以 `networks` 的 key 區分 TRC20 / ERC20 / BEP20 等鏈。代收代付請求要同時帶 `coinId` 與 `chain`，缺一不可。

### 7.2 鏈別欄位（`/getChainList` 的 `data.chains[]`）

廠商說明（markdown 版 11.12，原文）：`Retrieves the current network statuses for all supported chains. Pass chain symbols to check specific network statuses.`

**Request**：`chains`（Array，選填），例：`"chains": ["BSC"]`

| 欄位 | 型別 | 說明 |
|---|---|---|
| `chain` | String | **鏈別代號，即請求所用的 `chain` 值** |
| `chainFullName` | String | 鏈全名 |
| `explorer` | String | 區塊瀏覽器網址 |
| `baseUrl` | String | 鏈上交易查詢網址的前綴，含 `%s` 佔位符（例：`https://example.invalid/tx/%s`），代入 `txId` 即得完整查詢網址 |
| `confirmations` | Integer | 所需區塊確認數 |
| `isEVM` | Boolean | `true`：EVM 相容鏈 |
| `supportMemo` | Boolean | `true`：該鏈地址需要 memo／tag |
| `logoUrl` | String | 鏈 Logo |
| `status` | String | 原文：`Normal`: deposits and withdrawals operating normally. `Maintenance`: deposits/withdrawals may experience delays, or transactions on this network cannot be processed at this moment |

> ⚠️ 依錯誤碼說明（§3.1）推知：`supportMemo = true` 的鏈代付缺 `memo` 會回 `14003 AddressEmptyMemo`，memo 格式不符回 `14025 Invalid Memo Format`；代幣不支援 memo 卻帶了 memo 會回 `14009 CoinNoSupportMemo`。**未實測**。

### 7.3 法幣欄位（`/getFiatList` 的 `data.fiats[]`）

| 欄位 | 型別 | 說明（markdown 版 11.10） |
|---|---|---|
| `fiatId` | Integer | 法幣 ID（訂單模式的 `fiatId` 參數用，本專案不用） |
| `symbol` | String | 法幣代號 |
| `logoUrl` | String | Logo 網址 |
| `mark` | String | 法幣圖示 |
| `usdRate` | String | 1 USD 等值的該法幣數量 |

### 7.4 實測補充：USDT 的 `coinId` 與 `chain`（2026-09-10，測試商戶 GFFTEST）

實際呼叫 `POST /getCoinList` 取得（`code: 10000`）：

| 欄位 | 值 |
|---|---|
| `coinId` | **`1280`** |
| `symbol` | `USDT` |
| `coinFullName` | `Tether USD` |
| `status` | `Normal` |
| `networks` 的 key（即 `chain` 可用值） | `ARETH`、`BSC`、`ETH`、`OPTIMISM`、`POLYGON`、`SOL`、`TON`、`TRX` |

> 🔴 **TRC20 對應的 `chain` 值是 `TRX`，不是 `TRC20`**。我方 `banks.name` 慣例寫「USDT - TRC20」、`banks.code` 自 2026-09-15 起為 `USDT0001`（原 `TPCC01`），送給三方時必須轉成 `TRX`，這層轉換由 `convertPayoutBankCode()` 經 `cash_flow_banks` 承載。

⚠️ **`/getCoinList` 回傳的代幣數為 9**，而廠商對外宣稱支援 900+ 種加密貨幣。markdown 版 11.1 寫明這支端點回的是 `tokens you've enabled for your business`，也就是商戶在 Dashboard > Settings > Tokens for your business 啟用的清單，不是平台全集；5.1 另寫只有清單內代幣的存款才會發 Webhook。若之後要新增代幣，要先到 dashboard 開啟，而不是改代碼。

---

## 8. 其他端點（本次接入範圍外）

markdown 版的端點索引（第 14 節）共 53 支，本檔收錄 20 支（含只列在表格、標為不使用的端點）。以下類別**不在本次範圍**，列此備查：

| 類別（markdown 版章節） | 端點數 | 說明 |
|---|---|---|
| Deposit：訂單模式（7.2–7.5） | 4 | 訂單模式與 Hosted Checkout 的取址、查訂單 |
| Batch Withdrawal（8.5） | 7 | 批次代付 |
| Swap（9） | 4 | 商戶代幣兌換 |
| Wallet（10） | 15 | User 子帳戶模式（為每位會員開子帳戶）的餘額、存提、站內轉帳、兌換 |
| Common：商戶餘額（11.4–11.5） | 2 | 查詢商戶餘額 |
| Common：可兌換代幣（11.11） | 1 | 可兌換的代幣清單 |

---

## 來源

**單一事實來源**：本檔的規格內容一律以官方 markdown 版為準（2026-09-15 定案）。

| 來源 | 在本檔的角色 | 網址 / 位置 | 取得日期 |
|---|---|---|---|
| 官方 markdown 版 | **單一事實來源**。開頭註明整理自官方 API 文檔、內容忠於原文；只有英文；檔內沒有版本號或更新日期，重讀時以取得日期區分 | `https://example.invalid/apitutorial.md` | 2026-09-14 |
| 廠商 TG 回覆 | 只補 markdown 版沒寫的事項（配額擴容、解綁與配額、三種 Webhook 共用驗簽），與 markdown 版不衝突 | TG 群「CCPayment & TPOWER [TH041PG]」；原文照錄於 `open-questions.md` 第 2、15 題 | 2026-09-14 |
| 我方實測 | USDT 的 `coinId` 與 `chain` 值（§7.4）、`Timestamp` 單位（§2.1） | 測試商戶 GFFTEST | 2026-09-10 |
| 工單 | — | `target/tickets#4238`、`target/ticket_tasks#1323` | — |

以下來源**不作為規格依據**；與 markdown 版的落差整理在 `integration.md` §8：

| 來源 | 網址 / 位置 | 取得日期 |
|---|---|---|
| 廠商 GitHub SDK 文檔 repo | `https://example.invalid/cctip/ccpayment-sdk-skills`（`README.md` 與 `api/` 目錄，master 分支） | 2026-09-10（2026-09-15 重新核對） |

> **AI 參與程度**：本檔由 AI 依官方 markdown 版整理生成，並補入廠商 TG 回覆與我方實測，逐處標明出處；以 ⇒ 開頭的段落與 🔴 實作要點是我方推論，不是廠商原文。
