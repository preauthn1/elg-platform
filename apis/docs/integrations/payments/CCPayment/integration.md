# CCPayment 對接心得

> 本檔只記錄**接入時才知道、讀 `api.md` 加讀程式碼都得不到**的資訊：文件與實際行為的已驗證落差、廠商文件自身的錯誤、實測確認的陷阱、維運注意。廠商原文規格見同目錄 `api.md`；待廠商確認的項目見 `open-questions.md`。
>
> 🔴 廠商文件以**官方 markdown 版**（`https://example.invalid/apitutorial.md`）為單一事實來源，本檔寫「markdown 版 X.Y」即指該檔的章節編號。其他來源與它不一致時，一律以 markdown 版為準（理由見 §7）。

## 1. `Timestamp` 單位：GitHub SDK 文檔寫錯，實測為秒級

**各來源的說法：**

| 來源 | 說法 |
|---|---|
| markdown 版 3.3、3.4（單一事實來源） | `10-digit timestamp (Unix seconds)`（秒） |
| markdown 版 3.5 的 Node 範例 | `Math.floor(Date.now() / 1000)`（秒） |
| 廠商 GitHub SDK 文檔 `README.md` 的 Shell 範例 | `date +%s`（秒） |
| 廠商 GitHub SDK 文檔 `api/README.md` | `millisecond timestamp`（毫秒） |
| 廠商 GitHub SDK 文檔 `api/appendix.md` | `current request timestamp in milliseconds`（毫秒） |

**實測結論（2026-09-10，測試商戶 GFFTEST）**：以 `date +%s` 秒級 10 位簽名呼叫 `POST /getCoinList`，回應 `code: 10000` 成功。

⇒ **正確單位為秒級（10 位）**，與 markdown 版一致。GitHub SDK 文檔的兩處「milliseconds」是錯的，不要照它實作 —— 填毫秒會讓**全部請求**被判 `11006 ReqExpired`。

## 2. 廠商 GitHub SDK 文檔的 Shell 範例有跨秒 bug

廠商 GitHub SDK 文檔 `README.md` 的「Shell Example」一段（markdown 版沒有收錄；原文照錄，`your_app_id` 等是廠商寫的佔位字）：

```bash
# Set environment variables
export BASE_URL="https://example.invalid/ccpayment/v2"
export APP_ID="your_app_id"
export APP_SECRET=[REDACTED]"

# Generate signature and call API
generate_sign() {
    local body="$1"
    local timestamp=$(date +%s)
    local sign_text="${APP_ID}${timestamp}${body}"
    echo -n "$sign_text" | openssl dgst -sha256 -hmac "$APP_SECRET" | sed 's/^.* //'
}

# Get all assets
curl -X POST "${BASE_URL}/getAppCoinAssetList" \
  -H "Content-Type: application/json" \
  -H "Appid: ${APP_ID}" \
  -H "Sign: $(generate_sign '{}')" \
  -H "Timestamp: $(date +%s)" \
  -d '{}'
```

**`generate_sign` 內與 header 各呼叫一次 `date +%s`**，跨秒時簽名用的 timestamp 與 header 的 timestamp 不同，導致驗簽失敗。發生機率低但非零，且失敗訊息（驗簽失敗）不會指向真正原因。

⇒ 我方實作必須**取一次 timestamp 重複使用**。markdown 版 3.5 的 Node 範例（`const timestamp = ...` 取一次後共用）才是正確寫法。

## 3. TRC20 的 `chain` 值是 `TRX`，不是 `TRC20`

**實測（2026-09-10，`POST /getCoinList`）**：USDT 的 `coinId` 為 `1280`，其 `networks` 的 key 為：

```
ARETH, BSC, ETH, OPTIMISM, POLYGON, SOL, TON, TRX
```

⇒ 送給三方的 `chain` 欄位，TRC20 要填 **`TRX`**。

**為什麼這是陷阱**：我方 `banks` 表的命名慣例是「USDT - TRC20」（TPower 群組 `banks.id = 485`；其 code 原為 `TPCC01`，2026-09-15 銀行收斂作業已就地改為 `USDT0001`），前端與後台顯示的也是「TRC20」。若憑直覺把我方名稱或 `TRC20` 字面送給三方，會拿到 `13001 InvalidChain`，而錯誤訊息只說「Invalid chain」，不會提示正確值。

⇒ 這層轉換不可在 Driver 內寫死或推導。代付時由 `convertPayoutBankCode()` 取出該提款銀行的三方代碼，值是 `USDT_TRX`（幣別代號＋三方鏈代號，2026-09-17 起；之前只放鏈代號 `TRX`），再拆出 `TRX` 送給三方。三方代碼存在哪、為什麼連幣別一起放，見第 9 節「幣別與鏈別放哪」。

## 4. `/getCoinList` 回的是「商戶啟用的代幣」，不是平台全集

**實測（2026-09-10）**：`/getCoinList` 回傳的 `data.coins` 只有 **9 筆**，而 CCPayment 對外宣稱支援 900+ 種加密貨幣。

**原因**（markdown 版已寫明）：11.1 說這支端點回的是 `tokens you've enabled for your business`，也就是商戶在 **Dashboard → Settings → Tokens for your business** 啟用的清單；5.1 另寫未在該清單內的代幣存款**不會**發 Webhook。實測筆數與這個說明一致。

⚠️ 廠商 GitHub SDK 文檔 `api/01-basic-info.md` 把這支端點寫成 `All supported tokens`，照它理解會誤以為 CCPayment 只支援 9 種幣。

維運上的意義：**要新增支援代幣時，第一步是去 dashboard 開啟，而不是改代碼** —— 代碼改了但 dashboard 沒開，該代幣存款不會有 Webhook，等於錢進來了但系統不知道。

## 5. 廠商 GitHub SDK 文檔的參數表漏列 `notifyUrl`

廠商 GitHub SDK 文檔 `api/03-merchant-deposit.md` 的 `CreateAppOrderDepositAddress` 與 `GetOrCreateAppDepositAddress` 參數表都**沒有** `notifyUrl`（全檔零出現，2026-09-15 核對）。markdown 版 5.3 的「支援 notifyUrl 的 API 清單」明列這兩支，7.1 的參數表也有 `notifyUrl`，並附上「`notifyUrl` 綁定於地址、持久有效」這條對回調路由設計至關重要的 Notice（`api.md` §4.1）。

⇒ 與第 1 節的 Timestamp 錯誤同源：GitHub 那份是給 SDK 產生器用的簡化版，**會漏欄位、會寫錯單位，不可當依據**。與 markdown 版的其餘落差見 §8。

**對我方的意義**：我方回調網址帶 `agent_cash_flow_id`，`DepositNotifyController` 缺 id 直接拋例外；若照 GitHub 文檔以為永久地址 API 不能帶 `notifyUrl`，會誤判成「必須另開一條不帶 acf id 的回調路由」，白做一整套分流。

## 6. 整合模式選型歷程：由 Retail Checkout 轉為 Permanent Address

初版（2026-09-10）選 **Retail Checkout Mode**（每筆存款先建單、一次性地址、`ApiDeposit` webhook 帶 `orderId`），理由是可直接沿用我方「先建單、回調對單」的共用回調層（`DepositCallbackTrait::findAndLockDepositOrder()`）；這與廠商 Gaming & eSports 章節的公開建議相反（markdown 版 6.6.3 原文：`This industry rarely uses checkout invoices — it relies on assigning stable, long-term deposit addresses to players.`）。

2026-09-13 改採廠商推薦的 **Permanent Address Mode**：每位會員一個固定地址、`DirectDeposit` webhook 只帶 `referenceId`，訂單於收到 webhook 並回查後才建立。代價正是初版所迴避的那條「憑 webhook 建單」路徑——我方共用回調層沒有這個能力，須新建，不是換個參數就好的事。

Retail Checkout Mode 的廠商規格已自 `api.md` 移除，該檔只保留永久地址模式；需要時回 markdown 版 7.2–7.5 查閱。

## 7. 讀官方文檔：只讀 markdown 版

**做法**：規格一律讀官方 markdown 版 `https://example.invalid/apitutorial.md`（RB 2026-09-14 指點，2026-09-15 定為單一事實來源）。伺服器直接回傳 `text/markdown`（約 134 KB、2270 行），WebFetch 與 `curl` 都讀得到；開頭註明整理自官方 API 文檔、內容忠於原文，網頁右上角 Ask AI 選單的「View as Markdown」也是這一份。`api.md` 的規格內容全部依它撰寫。

**為什麼不用其他來源**：

- 官方網頁版 `https://example.invalid/api/doc/` 是單頁應用程式（內容要由瀏覽器執行 JavaScript 才會產生），WebFetch 與 `curl` 只抓得到空殼。從網頁的 JavaScript 解析出來的內容可能有誤差，不作為依據。
- 廠商 GitHub SDK 文檔（`cctip/ccpayment-sdk-skills`）是給 SDK 產生器用的簡化版，已知寫錯 `Timestamp` 單位、漏列 `notifyUrl`（§1、§5），其餘落差見 §8。

**閱讀時要知道的限制**：

- 只有英文。
- 簽名與驗簽只附 Node 範例。
- 檔內沒有版本號或更新日期；重讀時以取得日期區分（本次為 2026-09-14）。

## 8. 廠商 GitHub SDK 文檔與 markdown 版的落差（一律以 markdown 版為準）

廠商 GitHub SDK 文檔（`https://example.invalid/cctip/ccpayment-sdk-skills`，master 分支）與 markdown 版有以下不同，2026-09-15 逐項對照確認。`api.md` 全部依 markdown 版撰寫；本表讓人看到 GitHub 的寫法時，知道以哪個為準。除了註明「已實測」的項目，都**尚未以實際呼叫證實**。

| 項目 | markdown 版（採用） | GitHub SDK 文檔（不採用） | api.md |
|---|---|---|---|
| `Timestamp` 單位 | 3.3、3.4：10 位 Unix 秒（已實測，見 §1） | `api/README.md`、`api/appendix.md` 寫毫秒 | §2.1 |
| 永久地址取址的 `notifyUrl` | 7.1 參數表有 | `api/03-merchant-deposit.md` 參數表漏列（見 §5） | §4.1 |
| `/getCoinList` 的範圍 | 11.1：商戶啟用的代幣（實測筆數一致，見 §4） | `api/01-basic-info.md` 寫 `All supported tokens` | §7 |
| `networkFeeInquiryID` | 代付請求參數（8.1）與查手續費回傳（11.9）都沒有 | `api/04-merchant-withdraw.md` 的 `GetWithdrawFee` 回傳與 `ApplyAppWithdrawToNetwork` 參數都有 | §6.1、§6.2 |
| 錯誤碼 | 13：共定義 52 個碼；部分說明與 GitHub 不同，例如 `11012` 寫 `The maximum valid period is 10 days`、`15001` 寫 `Available deposit addresses for this account reached the maximum limit` | `api/appendix.md` 另列 `0`、`11016`、`11017`、`13005`、`13006`、`14017`–`14024`、`14026`、`15002`、`15004`、`16001`–`16006`、`17000`–`17007`（`17000`–`17007` 的名稱看起來與地址解綁有關，例如 `17006 UnbindingAddressFailed`） | §3.1 |
| 存款清單查詢的參數 | 7.8：`coinId`、`referenceId`、`orderId`、`chain`、`startAt`、`endAt`、`nextId`；超過 20 筆以 `nextId` 分頁 | 多列 `toAddress`、`recordIds`、`referenceIds`、`orderIds`、`limit`，並寫 `startAt` 預設最近 90 天 | §4.3 |
| 代付清單查詢的參數 | 8.4：`orderIds` 每次最多 20 個，沒有 `toAddress` | 多列 `toAddress`，並寫 `startAt` 預設最近 90 天 | §6.5 |
| 回傳欄位 | 存款紀錄（7.7）沒有 `txIndex`；代付紀錄（8.3）沒有 `appId`、`reason`、`coinUSDPrice`，`withdrawType` 是字串 `Cwallet`／`Network`；代幣（11.1）沒有 `price` | 有這些欄位，`WithdrawType` 寫成數字列舉（`Network` = 1、`Cwallet` = 2） | §4.4、§6.7、§7.1 |
| 鏈別查詢 | 11.12：參數 `chains`；鏈的狀態 `Normal`／`Maintenance` | 寫 `chainIds`、`Normal/Maintain`；另列 `/all/chain`、`/getMainCoinList` 兩支 markdown 版沒有的端點 | §7 |
| 簽名範例 | 只有 Node（3.5 請求、5.4 驗簽） | `README.md` 另有 Shell 範例（有跨秒 bug，見 §2） | §2.2 |

另一個容易讀錯的地方：`getRiskyRefundRecordList` 的端點名稱，加上 GitHub SDK 文檔的回傳模型名稱 `RiskWithdrawRecordEntity`（欄位長得像代付紀錄），容易被誤讀成「代付被風控退回」。依 markdown 版 11.14 的說明，它其實是「風險存款的退款紀錄」，與我方代付訂單無關（`api.md` §6.6）。我方初稿就讀錯過。

## 9. 我方實作決策（2026-09-15 定案）

規則的權威出處是各 class 的 docblock，本節只給索引。

| 項目 | 決策 | 出處 |
|---|---|---|
| referenceId | `{agent_cash_flow_order_type_id}-{member_id}`，回調可反解並與 `member_crypto_wallets` 核對。連字號字元集規格未載，正式取址前先用測試用的金流商戶實打確認（`open-questions.md` 第 21 題） | `app/Services/Payment/Providers/CCPayment/ReferenceId.php` |
| 地址存哪 | `member_crypto_wallets`，schema 不改；一位會員一條支付通道只一列啟用，換發時舊列改停用不刪 | `app/Models/MemberCryptoWallet.php` 的 docblock |
| 取址時機（2026-09-16 改，2026-10-21 再改） | 會員開存款方式清單（`deposit/top-up-types`、`deposit/types`）時，永久地址模式的通道每次都向目前商戶確認地址並存表，App 不必新增呼叫；地址跟表裡的啟用列不同（商戶換了 APP_ID）就把舊列改停用、寫入新列。確認結果以「通道＋會員＋APP_ID 雜湊」快取 5 分鐘。確認失敗只留空地址（連表裡既有的地址也不給）、不擋清單，同一支付通道冷卻一分鐘不再試。10-21 改的原因：Yes2Win 10-16 換 APP_ID 後，清單一直顯示舊商戶的地址（target/tickets#4564）。`POST deposit/crypto-address` 保留給 App 主動取址或重取。`deposit/create-order` 對這類支付通道回錯誤。原本（09-15）是點選才取址，理由是配額只給真的用 USDT 的會員；09-16 確認配額可免費擴容後改成開清單就取，換來不必等 App 發版 | `app/Services/MemberCryptoWalletService.php` 的 `walletsForChannels()`、`app/Enums/CashFlowEnum.php` 的 `PERMANENT_CRYPTO_ADDRESS` |
| 儲值頁的金額欄位與說明文字（2026-09-16 定案，只針對 CCPayment） | 永久地址模式的通道不帶金額表單欄位：資料檔每條 deposit channel 宣告 `amount_field => false`，`cash-flow:sync` 組 `order_types.info` 時就不塞預設的 amount 欄位（存成 `{"data":[]}`），儲值頁只顯示地址，金額以回調的鏈上實收為準。這是逐通道宣告，不做「加密貨幣通道一律隱藏」的通則，因為各家模式不同（PayEssence 仍要會員輸入金額）。2026-09-16 Dev 實測（SQL 直改後由 RB 在網頁前台確認）：`info` 為 `{"data":[]}` 時前端不再畫「請輸入儲值點數」；既有的 `PopupText`（`agent_cash_flow_order_types.settings`，每語言一段、以語言 id 為鍵的物件，找不到當前語言用鍵 `0`）呈現為進入儲值畫面時的彈窗，不是地址下方的固定文字。**說明文字怎麼呈現交 PM 規劃（RB 2026-09-17 決定），本工單不做**：候選是彈窗沿用 `PopupText`，或新增純文字表單欄位型別 `note`（原型已做過並保留在本地 tag `backup/ccpayment-note-field-20260917`：資料檔 `info` 宣告 `type: note`、文字走 `param.display.*` 翻譯鍵、parser 對 note 不附 placeholder，前端要把新型別畫成純文字）。同時發現 FonPay 三條 USDT 通道（order_types 168／180／181）的 `info` 一直是 NULL，CCPayment 改成空清單後它們也沒了金額欄位，推測前端在 `order_type_info` 為 null 時沿用上一個通道的欄位（未驗證，列入前端提問）；Dev 已把 FonPay 商戶 62 停用。「限額 0 - 0」那行是前端另外印 min／max，不在本項範圍 | `app/Console/Commands/CashFlow/SyncCashFlowsCommand.php` 的 `buildParentInfo()`、`app/Console/Commands/CashFlow/Data/providers/CCPayment.php` |
| 幣別與鏈別放哪（2026-09-16 定案，同日兩改） | 代收的幣與鏈一起跟著支付通道走：`order_types.key` 的格式是 `CCPAYMENT.{幣別代號}_{三方鏈代號}`（例 `CCPAYMENT.USDT_TRX`），幣別代號是三方 `getCoinList` 回的 `symbol`、鏈代號是同一筆 `networks` 的鍵，兩個都是三方後台的字面值。coinId 不寫在代碼或設定裡，執行期依幣別代號向 `getCoinList` 查（每商戶快取一天，快取裡沒有先重拉一次），並確認該鏈在該幣的 networks 裡，後台沒啟用一律拋出。所以哪些幣、哪些鏈可用由三方後台控制：加幣加鏈＝後台啟用＋資料檔加一條通道，代碼不動。商戶 info 只放憑證，原本的 `CHAIN` 鍵移除。**代付（2026-09-17 改）**：幣與鏈一起跟著會員所選的提款銀行走。該銀行的三方代碼寫成 `{幣別代號}_{三方鏈代號}`（例 `USDT_TRX`，就是支付通道 key 去掉前綴的後半段，同一條切分規則），代付時拆成幣別代號與鏈代號，coinId 走代收同一套查詢與同一份快取；格式不符或後台沒啟用，在送出代付請求之前就明確失敗，訂單可重送。原本放在商戶 info 的 `COIN_ID`（寫死的三方內部 id 1280）因此移除。為什麼放在提款銀行而不是商戶 info：代付沒有支付通道，而提款銀行「USDT(TRC20)」本身就代表一種幣加一條鏈，和代收的支付通道對稱；Dev 切測試網也從改兩處（`COIN_ID` 與三方代碼）變成只改三方代碼一處。三方代碼的值存在兩個地方、內容相同：資料檔代付 banks 的 `code` 同步成 `cash_flow_banks.provider_bank_code`，建商戶命令再寫進商戶 info 的 `available_banks`；執行期讀哪一處由 `convertPayoutBankCode()` 決定（灰度名單 `PAYOUT_BANK_LOOKUP_PROVIDERS` 沒列 CCPayment 時讀 `available_banks`），本設計兩處都適用。同日稍早的圓桌會審定的是代碼對照表（key → 寫死的 coinId 與 chain），RB 以「幣別應由三方後台控制、不該寫死在代碼」改成動態查詢。否決的兩條路：鏈放商戶 info（一商戶一鏈、改一個值就讓入帳與對帳同時失效）、用 order_types 父子結構放幣與鏈（`order_types.info` 是送給 App 的表單欄位清單裝不了幣別、父通道也會建關聯、代付幣別來源會分裂、App 契約要改；真要同商戶多幣時再走，幣別載體用父層 key） | `app/Services/Payment/Providers/CCPayment/ChannelKey.php`、`app/Services/Payment/Providers/CCPayment/CCPaymentTrait.php` 的 `channelSpec()`／`payoutSpec()` docblock |
| 測試網通道 | 當成一條支付通道 `CCPAYMENT.TETH_ETH_SEPOLIA`（幣別代號 TETH、鏈代號 `ETH_SEPOLIA`，coinId 由 `getCoinList` 查），資料檔只在「非正式庫連線」宣告；判準看 DB 連線名（mysql／dev／testing 為非正式）不看 APP_ENV，因為正式庫操作是注入 `DB_CONNECTION`、APP_ENV 不變 | `app/Console/Commands/CashFlow/Support/DatabaseEnvironment.php`、`app/Console/Commands/CashFlow/Data/providers/CCPayment.php` |
| 入帳判定 | 只信回查；Success 且代幣與鏈別等於支付通道解析出的（幣, 鏈）且非風險交易才建單；幣別或鏈別不符不建單只告警；風險交易建單為待人工確認；Processing／Failed 不建單 | `app/Services/Payment/Providers/CCPayment/DepositCallback.php` docblock 的矩陣 |
| 風險交易的後續（target/tickets#4463） | 金流商不會自動入帳，也不會自動退回，處理後不發 webhook。建單時同交易寫一列存款人工確認紀錄（`deposit_manual_reviews`，待處理、不可上分），舊代理後台的手動上分與重置狀態看到這列就擋。排程 `ccpayment:sync-risky-refunds` 每 10 分鐘查退款紀錄：退回付款人的單改狀態 2；退到營運方白名單地址的單維持狀態 4、改為可上分，由營運按手動上分；地址都不符只告警。金流商的行為見本節之後的「風險交易的後續處理」 | `DepositCallback`、`RiskyRefundSync` 的 class docblock，`App\Models\DepositManualReview` |
| 金流商手續費（target/tickets#4430） | 訂單的 `payment_cost` 不走共用流程的「點數 × 成本費率」：存款寫回查的 `serviceFee` × 存款單匯率，提款寫回查的網路費 `fee.amount` × 提款單匯率。回查值缺漏、或網路費代幣與出款代幣不同時，告警並退回成本費率算法。風險交易單建單時不寫：服務費要等營運處理、退款成功時才從商戶餘額扣，建單時不知道會不會發生。退款成功後由 `ccpayment:sync-risky-refunds` 寫入「重新回查原存款的 `serviceFee` × 存款單匯率」，單上已有值不覆寫。風險退款費不記，由財務從金流商後台對帳 | `DepositCallback`、`WithdrawalCallback` 的 class docblock，`CCPaymentTrait::actualPaymentCost()` |
| 風險地址回調 | 該列停用、立刻解綁、告警；換發留到會員下次開存款方式清單（或點選取址）；不自動通知會員，營運用站內信手動發 | 同上 |
| 驗簽 | 三種回調同一套，常數時間比較，刻意不檢查 Timestamp 時效 | `CCPaymentTrait::verifyWebhookSignature()` |
| 對帳補送 | 排程 `ccpayment:resend-missing-webhooks`（每 30 分鐘）：存款漏單與代付卡在處理中超過 2 小時的單都請金流商重送 webhook，入帳與定案只走回調。存款清單依商戶底下每條支付通道的（幣, 鏈）各拉一輪，一條通道壞掉（key 不在表、三方拒絕該 coinId）只跳過並記錄，不停掉整個商戶；全部通道都拉不到才算失敗 | `app/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommand.php`、`app/Services/Payment/Providers/CCPayment/Reconciliation.php` |
| 代付查單 | Driver 實作 `SupportsPayoutQuery`，以我方訂單號查；查詢失敗回 UNKNOWN，不推斷成查無 | `Withdrawal::queryByOrderId()` |
| 網路費 | 金流商戶 info 的 `MERCHANT_PAY_NETWORK_FEE`，預設 false 由會員負擔；PM 定案後改設定不改代碼 | `app/Services/Payment/Providers/CCPayment/Withdrawal.php` |
| 回應 Content-Type | 不改共用層。2026-09-16 測試網實測：我方回 `text/html` 的 `Success`，金流商後台「回覆日誌」記為 Success、沒有重試，**被接受** | 第 10 節「測試網代收」列 |
| 代付銀行列 | TPower `banks.id 485` 已於 2026-09-15 由銀行收斂作業就地改碼 TPCC01 → USDT0001（孿生測試組 771 同），上線命令會沿用該列並以 base 層 1369 覆寫名稱、手續費（485 的 1.5 與 771 的 1.0 都變 0）與備註，771 的 status 也會被拉回 1；手續費要不要設回等 PM | `runbook.md`（本目錄）第 1 節 |

金流商戶 info 需要的鍵：`API_URL`、`APP_ID`、`APP_SECRET`、`MERCHANT_PAY_NETWORK_FEE`（選填，預設 false）。四個鍵由 `app/Console/Commands/CashFlow/Data/providers/CCPayment.php` 的 credentials 模板隨 `agent:add-agent-cash-flow` 一次寫入，其中 `APP_ID`、`APP_SECRET` 留空由上線人員在後台填入；代付另有 `available_banks`，由同一命令產生。鏈別不在 info：代收的幣與鏈由支付通道決定（上表「幣別與鏈別放哪」），2026-09-16 前的 `CHAIN` 鍵已移除。上線步驟見 `runbook.md`（本目錄）。

### 風險交易的後續處理

風險交易是指存款回查（`getAppDepositRecord`）的 `isFlaggedAsRisky` 為 `true` 的存款。這類存款的款項不會進商戶餘額，要由營運到金流商後台處理。本小節整理金流商 TG 回覆（2026-10-05，RB 提供截圖），markdown 版沒有寫到這些事。

**處理方式**

- 營運到金流商後台「交易 > 風險交易」手動處理，只有「退款」一個動作，差別在退去哪裡：退回付款人的地址，或退到商戶自己指定的其他地址。
- 退到商戶指定的地址，等於商戶接受這筆款，我方要替會員手動上分。退回付款人時，會員沒有付到錢，不可以上分。
- 問金流商能不能限制成「只能退回付款人」時，金流商沒有回答有這項設定，改介紹兩個功能：
  - **風險自動提現**：設定觸發金額，風險存款累積到這個金額，就照設定自動提現。提現的目的地由商戶設定，設成商戶自己的地址時，等於自動接受風險款，每一筆都要替會員上分。
  - **批量退款**：位置在商戶後台「交易 > 風險交易 > 批量退款」。有兩種方式：按幣種退（選幣種，設定同一地址累積的最小提現金額，填退款地址），或按收款地址退（選風險收款地址、選幣種、填退款地址）。退款地址由操作人員自己填。批量退款只付一次網路費，比逐筆退款便宜。
- **一直沒有處理**：款項一直停在待處理，不入帳也不自動退回，沒有處理期限，也不另外收費。

**費用：兩筆都從商戶餘額扣**

- **服務費**：所有成功的存款都收服務費，風險存款也一樣。退款時，從商戶餘額扣這筆存款的服務費。金額就是存款回查紀錄裡的 `serviceFee`，不會在退款當下重新計算，退款完成後才扣。
- **服務費費率**：依每月交易量分檔，例如 0.5%。月交易量達到下一檔時，由金流商調整費率。不是每筆交易各自浮動。
  - 每筆都按帳戶「當前生效的費率」收。交易量達到門檻之前，每一筆都按原費率（例如 0.5%）收。達到門檻後，金流商在系統裡把費率統一調成新費率（例如 0.4%）。
  - 不是分段收費。分段收費是指把同一個月的交易量拆開算，例如前 100 萬按 0.5%、超出的部分按 0.4%。金流商不這樣算。
  - 調整生效之後新進的訂單，才按新費率收。調整之前的訂單維持原費率，不追溯，也不退差額。
  - 對我方的意義：存款回查（`getAppDepositRecord`）的 `serviceFee` 在回查當下就是最終值。訂單 `payment_cost` 記下的金額，事後不會跟實際付的對不上。
- **風險退款費**：退款產生的網路費等費用，也從商戶餘額扣。
  - 每筆風險存款各扣一次風險退款費，批量退款也一樣。
  - 批量退款時，鏈上網路費整批只產生一次，所以總費用比逐筆退款低。
  - 退款紀錄的 `fee` 是整次退款的網路費。退款紀錄沒有每筆存款各自的風險退款費，我方拿不到實際值。
- **最後由誰負擔**：金流商從商戶餘額扣，商戶要不要再向會員收回，由商戶自己的業務規則決定，金流商不強制。金流商建議事先跟會員說明，從會員在平台上的餘額扣除。
- 金流商另有 TRX 能量租賃服務，購買後可以抵扣一般提現與風險提現的網路費。

**怎麼查處理結果**

- 處理後不發 webhook，原存款的回查結果（`getAppDepositRecord` 回傳的所有欄位）也不會改變。所以不能靠回查存款知道處理結果。
- 處理結果要用 `getRiskyRefundRecordList` 查（`api.md` §6.6）。每筆退款紀錄的 `toAddress` 是退款實際送達的地址，`fee` 是這筆退款的網路費，`fromDeposit` 是這筆退款對應的存款（陣列，每個元素帶存款的 `recordId`）。
- **分辨退去哪裡**：非 UTXO 類的鏈（USDT-TRC20 屬於這類），可以比對存款回查的 `fromAddress` 和退款紀錄的 `toAddress`，相同就是退回付款人。UTXO 類的鏈（例如比特幣）一筆交易可能有多個付款地址，存款回查不回傳 `fromAddress`，這個方法不適用。

- **一筆退款可能對到多筆存款**：批量退款會把多筆風險存款合併成一次退款，這筆退款紀錄的 `fromDeposit` 就會有多個元素。金流商已在 TG 確認。

## 10. Dev 實測（2026-09-15，測試商戶 GFFTEST）

| 項目 | 結果 |
|---|---|
| 憑證與出站 IP | `cash-flow:third-party-query 7672`（`getCoinList`＋`getChainList`）成功，回 34 條線路，USDT-TRX 狀態 Normal，沒有 `12002`。當時通道名顯示成 `USDT-TRX`；2026-09-17 起通道名改成與支付通道 key 同一個格式，同一條線路顯示為 `CCPAYMENT.USDT_TRX`，命令才比對得到我方已配置的通道 |
| notifyUrl 網域白名單 | 後台說明原文「您新增的任何值都會被標準化為根網域。最多支援 10 個網域」。加入 `example.invalid` 後前三次取址仍回 `12004`，幾分鐘後同一請求通過。**加完要等一下再試**，不是格式問題 |
| 錯誤碼 `12004` | `api.md` 的錯誤碼表沒有這個碼。實收回應原文：`{"code":12004,"reason":"notifyUrl not in whitelist","msg":"notifyurl not in whitelist, please check the notifyurl whitelist settings on the developer page"}`，發生在 `/getOrCreateAppDepositAddress`，我方拋 `ApiException`、不寫任何資料列 |
| `referenceId` 連字號 | 可用，見 `open-questions.md` 第 21 題 |
| 取址結果 | 會員 48897、支付通道關聯 1242 → `member_crypto_wallets` id 81，地址長度 34，status 1；notifyUrl 綁定為 `https://example.invalid/external/api/deposit/notify/7672` |
| 拋棄式綁定 | 診斷時用 `referenceId=1242-999999999` 與根網域 notifyUrl（`https://example.invalid/...`）在 GFFTEST 取了一個地址 `TMiB2c3KMD5wiUyH51ErhGpPTu12vqGszD`。它不對應任何會員，占 GFFTEST 一個配額；若有款進來，回調會打到不存在的網址，由對帳排程請重送後告警「referenceId 對不到會員」 |
| 前台現行行為（部署後瀏覽器實測） | develop 經 pipeline 48351 部署到 Dev 後，RB 以會員 48897 登入 Dev 會員網站。儲值頁選 USDT →「CCPayment USDT (TRC20)」時**直接顯示 id 81 的地址、QR 與複製鈕，沒有送出鈕**（前台對綁定加密貨幣錢包的通道只渲染這三樣）。畫面上的「請輸入儲值點數」與「充值限額 0 - 0」是共用存款表單的欄位，這條通道用不到；限額 0 - 0 來自支付通道關聯 1242 的 min_amount／max_amount 都是 0。要前端把這兩個欄位藏掉，列進前端提問稿 |
| USDT 在 TRX 鏈的最低入金 | `getCoinList` 回 `networks.TRX.minimumDepositAmount = "0.1"`（`canDeposit = true`、`protocol = TRC20`、`precision = 6`）。真幣測試至少轉 0.1 USDT，低於此金流商不入帳 |
| 測試網 | 官方 markdown 第 4 節與 FAQ（`https://example.invalid/en/support-center/faqs-detail#use-test-network`）都只有 **ETH Sepolia**（Dashboard > Merchant settings > ETH test network），沒提 TRC20（廠商沒有明說不支援）。實測（下三列）：測試網的入金紀錄與提幣都是獨立代幣 **TETH（coinId 1891、chain `ETH_SEPOLIA`）**，不是主網 ETH 的 1161／`ETH`；取址時 chain 用 `ETH` 或 `ETH_SEPOLIA` 回同一個 0x 地址 |
| 測試網代收（2026-09-16） | Dev 商戶 7672 改 `CHAIN=ETH_SEPOLIA`、`COIN_ID=1891`，停用 TRX 地址列 81 後替會員 48897 取址得 0x 地址（列 82）。水龍頭轉 0.05 Sepolia ETH（tx `0xbf740d26…3f3d`），金流商 2 個確認後記為 Success。**TETH 未列入啟用代幣時金流商不會自動發 webhook**，RB 在後台按「發送 Webhook」後 Dev 建存款單 13867（status 3、0.05）、餘額 +0.05。同一筆再按兩次、再用 API `/webhook/resend`（時間範圍＋`AllResult`＋`DirectDeposit`，回 `resendCount 1`）送一次，始終只有一張單，冪等成立。後台「回覆日誌」顯示我方回 `Success`，**回應 `text/html` 被接受**。webhook 本體只有 `type` 與 `msg`（recordId、referenceId、coinId、coinSymbol、status、isFlaggedAsRisky），chain／amount／toAddress 靠 recordId 回查；`status` 是首字大寫 `Success` |
| 測試網代付（2026-09-16） | Dev 商戶 7673 改 `COIN_ID=1891`、`available_banks` 的 TRC20→`ETH_SEPOLIA`（cash_flow_banks 4739 的 provider_bank_code 同步）。三種拒絕碼實測都正確轉 29、代付紀錄標 -2：鏈別還是 TRX 時送 0x 地址回 `14007`；TETH 未列入啟用代幣時回 `14010`（reason `[coinId: 0] Disabled tokens by Merchant`）；改用 1161／ETH 回 `14000`（查的是主網 ETH 餘額 0）。RB 在後台把 TETH 加進啟用代幣後（`getCoinList` 由 9 種變 10 種），提款單 691（0.01 TETH，收款是 RB 的 MetaMask 地址）送 `1891／ETH_SEPOLIA` 回 `10000` 與 recordId，**ApiWithdrawal webhook 自動送達**，22 秒後訂單轉 24；鏈上收到 0.009（網路費 0.001 由會員負擔，對應 `MERCHANT_PAY_NETWORK_FEE=false`）。TETH 提幣下限 0.001、無上限。代付單號前綴同日改為 CCP |
| 測試網限制 | `getAppDepositRecordList` 帶 `coinId=1891` 回 `11000 invalid argument`（不帶 coinId 查得到），補送排程在 Dev 每 30 分鐘記一次 `Log::error`、不告警，QA 期間可忽略。前台提款金額規則是 `numeric|min:1`，0.01 是 RB 直接改提款單 691 的 amount 做出來的（點數 4.15 未動，Dev 帳不平屬刻意）；群組 3 自助洗分的 `MinAmount` 由 100 暫改 0.01。Dev 的 `gff-dev` 專案沒有 pod log，ingress 請求紀錄也不涵蓋 dev-external-api，回調有無送達只能看金流商後台 |
| 還沒測 | 主網 USDT-TRC20 的真幣到帳與代付（測試網只有 TETH）；重送時 `Timestamp` 是否換新（後台看不到標頭，我方驗簽不看時效，不阻塞）；TETH 列入啟用代幣後入金 webhook 是否自動送達（代付已驗自動，入金未再測）；**鏈別改由支付通道決定後（2026-09-16 定案）Dev 測試網代收尚未重驗**：上面兩列的測試網代收是靠改商戶 info 的 `CHAIN` 做出來的，那個鍵已移除，QA 要改走 `runbook.md` 第 8 節重建的測試網通道與新地址 |
