# 加密貨幣金流商接入規範

> 本檔是 `payment-integration` skill 的子文件，只講「加密貨幣型」金流商與「法幣型」不同的地方。主檔的 Driver 結構、代付例外分類、代付回調三態、測試規範、文件規範對加密貨幣型一樣適用，本檔不重複。
> 通則寫成規範；CCPayment（工單 target/tickets#4238，2026-09 接入）只當「參考實作」引用，不是操作手冊。CCPayment 專屬細節在 APIs repo `docs/integrations/payments/CCPayment/` 四份文件與 `app/Services/Payment/Providers/CCPayment/`。

**一句話版本**：加密貨幣金流商的存款不是「建單後跳轉付款」，而是「會員拿一個固定收款地址自己轉幣，錢到了金流商用回調告訴我們，我們回查確認後才建單入帳」；代付則是把幣打到會員自己的鏈上地址。幣別跟著金流商戶、鏈別跟著支付通道，這兩件事不要放反。

## 名詞速查

| 名詞 | 意思 | 在這裡的用法 |
|---|---|---|
| 幣（coin） | 加密貨幣的種類，例如 USDT | 金流商用 `coinId` 這類識別碼指一種幣，識別碼不含鏈 |
| 鏈（chain） | 幣在哪條區塊鏈上流通，例如 TRON、Ethereum、BSC | 金流商用自己的鏈代號，與市場俗稱（TRC20、ERC20）常不一樣 |
| 主網／測試網 | 主網是真錢；測試網是金流商提供的練習環境，用的是沒有價值的測試幣 | 測試幣通常是獨立代幣，識別碼與主網不同 |
| 金流商戶 | 金流商發給平台的一組收付款身分，持有憑證，`agent_cash_flows` 一列，固定對應一種幣別 | 代收與代付各一列 |
| 支付通道 | 會員在存款頁看得到的一個付款選項，`order_types` 加 `agent_cash_flow_order_types` | 一條支付通道固定一條鏈 |
| 支付組別 | 記載金流商戶與支付通道的代理組別，`agent_group_id` | 上線時測試組先行、正式組後上（見主檔「正式環境代理配置流程」） |
| 永久地址（Permanent Address） | 金流商替某位會員配發的固定收款地址，長期有效 | 存在 `member_crypto_wallets` |
| referenceId | 我方取址時交給金流商的識別字串，金流商把地址綁在它身上，回調時原樣帶回 | 格式「支付通道關聯 id-會員 id」 |
| recordId | 金流商替每一筆鏈上交易配的唯一編號 | 我方存款單的 `third_party_order_no`，也是冪等鍵 |
| 回查 | 收到回調後，主動呼叫金流商的查單 API 再確認一次 | 入帳只信回查結果，不信回調本體 |
| 風險交易／風險地址 | 金流商依反洗錢規則把某筆存款或某個地址標記為有風險 | 風險交易不可自動入帳；風險地址要停用換發 |
| 網路費 | 鏈上轉帳要付給區塊鏈的手續費 | 代付時由會員或商戶負擔，金流商參數可選 |

## 這是哪一型金流商（先判斷再往下）

符合下列任一項，就是加密貨幣型，套用本檔：

- 廠商自稱加密貨幣支付閘道（crypto payment gateway），支援 USDT、BTC、ETH 這類幣。
- 會員存款方式是「轉幣到一個地址」，不是「跳轉到付款頁輸入金額」。
- 代付的收款目標是會員的鏈上錢包地址，不是銀行帳號。
- 廠商 API 有 `coinId`／`chain`／`address`／`memo` 這類參數。

法幣型金流商（跳轉付款、建單、回調對單）照主檔走即可。既有的 PayEssence 是「法幣型金流商順帶提供 USDT 通道」的混合案例，它的 USDT 代收仍走建單跳轉，但幣與鏈的配置慣例（下一節）就是從它來的。

## 一、幣與鏈：同一種幣可以走多條鏈

**這節在說什麼**：接加密貨幣金流商，第一個要弄清楚的觀念是「幣」和「鏈」是兩件事，有效的組合是（幣, 鏈）成對出現。

1. **同一種幣可以在多條鏈上流通**。USDT 在 TRON、Ethereum、BSC、Polygon 等鏈上都有。會員轉幣時選錯鏈，錢會打到另一條鏈的同名地址或直接消失，所以每條支付通道都要明確固定一條鏈。
2. **金流商的幣識別碼不含鏈**。以 CCPayment 為例，USDT 的 `coinId` 是 1280，不管走哪條鏈都是這個值；鏈另外用 `chain` 參數指定。`coinId` 這類三方內部識別碼**不寫進代碼**，接入時用幣種清單 API 查，執行期也照查（第二節的動態查做法）；哪些幣可用由三方後台控制。
3. **鏈代號是金流商自己定的，不要用市場俗稱猜**。CCPayment 的 TRON 鏈代號是 `TRX`，不是 `TRC20`；送 `TRC20` 會被拒（錯誤碼 `13001 InvalidChain`），而錯誤訊息不會告訴你正確值。正確值要呼叫金流商的幣種清單 API 拿（CCPayment 是 `/getCoinList`，回傳的 `networks` 物件的 key 就是鏈代號）。
4. **幣種清單 API 回的可能只是「商戶已啟用的幣」**，不是金流商支援的全集。CCPayment 的 `/getCoinList` 只回商戶後台勾選的幣；沒勾的幣即使有人轉進來，金流商也不會發回調。要新增支援幣種，第一步是到金流商後台啟用，不是改代碼。
5. **測試網的測試幣是獨立代幣**。CCPayment 只提供 ETH Sepolia 測試網，測試幣叫 TETH，`coinId` 是 1891、鏈代號是 `ETH_SEPOLIA`，與主網 ETH 的識別碼不同。測試網環境的配置要把幣與鏈都換成測試幣的值，不能只換一半。

## 二、平台配置慣例：幣別跟著金流商戶、鏈別跟著支付通道

**這節在說什麼**：一家金流商要在平台上開出「USDT 走 TRON」「USDT 走 Ethereum」兩個選項時，資料該放哪裡。這是平台既有慣例（PayEssence、舊系統 FonPay 都這樣做），2026-09-16 CCPayment 會審再次確認。

### 紅線：一列金流商戶只對應一種幣別

`agent_cash_flows` 一列＝一個金流商戶＝一種幣別，這條紅線對加密貨幣型不變。金流商戶的 `info` 只放憑證，以及代付要用的幣識別碼（例如 `COIN_ID`；代付沒有支付通道可依附，只能從這裡讀），**不放鏈別**，代收的幣與鏈也不從這裡讀。同一個金流商戶可以掛多條支付通道，每條各自一組（幣, 鏈）。

### 代收：鏈別放在支付通道的 `order_types.key`

- key 命名 `PROVIDER.{幣}_{鏈}`，例如 `CCPAYMENT.USDT_TRX`；PayEssence 用 `PAYESSENCE.TUSDT`（TRON）與 `PAYESSENCE.EUSDT`（Ethereum）；舊系統 FonPay 的 key 是「幣-鏈」直接串起來（例如 `USDT-TRON`）。
- Provider 代碼把 key 轉成金流商參數（幣識別碼＋鏈代號），兩種合規做法，查不到一律拋例外（fail-closed，也就是查不到就停下，不猜幣也不猜鏈）：

  | 做法 | 適用 | 怎麼做 | 參考實作 |
  |---|---|---|---|
  | 靜態對照 | 幣鏈固定、廠商參數是自訂代號（法幣型順帶的加密貨幣通道） | `match` 把 key 對到廠商代號 | PayEssence `Deposit.php` 的 `getCurrency()` 與 `getTransProvider()` |
  | 動態查（2026-09-16 定案） | 廠商提供幣種清單 API、幣鏈由廠商後台啟用決定 | key 只寫廠商後台的字面值：幣別代號＝幣種清單的 `symbol`、鏈代號＝該幣 `networks` 的鍵（也就是 API 的 `chain` 參數值），固定以第一個底線切分（鏈代號可含底線，如 `ETH_SEPOLIA`）。執行期依幣別代號向幣種清單查 `coinId`，並確認鏈在該幣的清單內；啟用清單每金流商戶快取一天，快取裡找不到先重拉一次，仍無則拋出 | CCPayment `ChannelKey.php`（組裝與解析）、`CCPaymentTrait::channelSpec()`（執行期查）；取址、回調、對帳都從支付通道查 |

- 加一條鏈或一種幣：靜態對照＝資料檔 `app/Console/Commands/CashFlow/Data/providers/{Provider}.php` 的 `deposit.channels` 加一列＋對照加一列；動態查＝資料檔加一列＋到廠商後台啟用，代碼不動。兩種都不動金流商戶 `info`。
- 🔴 **通道 key 一旦有會員取過地址就禁止改名**：地址綁的是支付通道關聯 id（`member_crypto_wallets.agent_cash_flow_order_type_id`），回調靠它反查通道再解析 key 取幣鏈；改名等於換一條通道，舊 key 的通道被同步命令下架後，打到舊地址的款會因解析不到幣鏈而不入帳。

### 代付：鏈別放在會員所選的提款銀行

- 代付沒有支付通道可依附，鏈由會員綁定提款帳戶時選的「銀行」決定。銀行名稱沿用 base 層既有標準名，例如 `USDT(TRC20)`、`USDT(ERC20)`，不要另造名稱（主檔「銀行名稱規範」）。
- 銀行代碼轉金流商鏈代號走既有的 `convertPayoutBankCode()`：provider 若在 `config('payment.payout_bank_lookup_providers')` 白名單內，查 `cash_flow_banks.provider_bank_code`；不在白名單則查金流商戶 `info.available_banks`。資料檔 `withdrawal.channels[].banks[].code` 填金流商的鏈代號（CCPayment 填 `TRX`）。
- 參考實作：PayEssence `Withdrawal.php` 的 `getCurrencyTypeId()` 依 `bank_code` 是 `ERC20` 或 `TRC20` 回不同值；CCPayment `Withdrawal.php` 的 `buildPayoutParams()` 以 `convertPayoutBankCode()` 取 chain，`coinId` 讀金流商戶 `info` 的 `COIN_ID`。

### 會審否決的兩條路（不要這樣做）

| 做法 | 為什麼否決 |
|---|---|
| 鏈別放金流商戶 `info`（例如 `CHAIN` 鍵） | 一個金流商戶只能一條鏈。後台改一個 `info` 值，入帳判定與對帳安全網會同時失效；改值期間發出去的異鏈地址不可逆（地址永久綁在金流商端）。CCPayment 初版曾這樣做，2026-09-16 已改回慣例 |
| 幣與鏈用 `order_types` 父子結構（父＝幣、子＝鏈） | 模型最誠實但現階段不做。`order_types.info` 是送給 App 的表單欄位清單，不是 key-value 倉庫，幣別塞不進去；父通道也會建 `agent_cash_flow_order_types`，取址端不擋會生出重複錢包列；子層 key 是同步命令以名稱認列、永不校正的欄位；App 契約要改成選鏈後以子通道 id 取址；referenceId 綁在金流商端地址上不可變，先上現行再改父子會永久留相容碼。真要同一金流商戶多幣時再走父子，幣別載體用父層 key、不用 `info`，且必須在正式發出第一個地址前決定 |

## 三、永久地址模式（Permanent Address）

**這節在說什麼**：加密貨幣代收的主流做法。金流商替每位會員配一個固定收款地址，會員隨時轉幣進去，不需要每次建單。這和主檔的「先建單、回調對單」流程根本不同，共用回調層沒有這個能力，要另外實作。

### 流程

1. **會員開存款方式清單時就自動取址**（2026-09-16 定案）。服務層 `walletsForChannels()` 對永久地址型通道（`CashFlowEnum::PERMANENT_CRYPTO_ADDRESS` 名單）還沒有地址的會員向金流商取址並存進 `member_crypto_wallets`（唯一鍵是支付通道關聯 id 加地址），清單直接帶 `crypto_wallet_address`，App 不必新增呼叫。取址是 fail-soft：失敗就留空地址、同一金流商戶冷卻 1 分鐘不再試（`CacheKeys::cryptoAddressFetchCooldown`），清單照常顯示；取址前從 DB 重載整條通道，因為清單快取裡的金流商戶沒有憑證。`POST deposit/crypto-address` 端點保留給主動取址（例如清單那次失敗後會員再點）。理由：金流商配額可免費擴容，原本「點選才取址、配額只給真的用的會員」的前提不成立；代價是上線前要先申請擴容，每位開過儲值頁的會員占一個地址。
2. **前台不建存款單**。`deposit/create-order` 對這類支付通道回錯誤，不建單；判準是金流商 id 在 `CashFlowEnum::BIND_CRYPTO_WALLET` 名單內。Provider 的 `Deposit::handle()` 一律拋例外當最後防線。
3. **存款單在收到到帳回調、並向金流商回查後才建立**（下一節）。
4. **前台儲值頁對這類通道只顯示 QR 與地址，不渲染送出鈕**。限額來自 `agent_cash_flow_order_types` 的 `min_amount`／`max_amount`（0 代表不限）。畫面上「儲值點數」欄位是共用存款表單的殘留，這條通道用不到；App 端要知道這件事。

### referenceId：讓回調反查得到會員與通道

- 格式「支付通道關聯 id-會員 id」，例如 `12-345`（示範值）。與 `member_crypto_wallets` 的鍵同鍵，回調帶回時直接反解出支付通道與會員，再與該表核對。
- 多鏈時各支付通道各自取址、各占金流商配額（第二條通道的 id 不同，自然拿到不同地址）。
- 格式一旦用於正式取址就不能改（地址永久綁定 referenceId）。金流商對字元集的規定要在正式取址前用測試商戶實打確認；CCPayment 實測連字號可用。
- 參考實作：CCPayment `ReferenceId.php`（`build()` 與 `parse()`，不是我方格式一律回 null）。

### 契約與服務層的分工

- 金流商 Driver 實作 `App\Services\Payment\Contracts\SupportsPermanentDepositAddress`，只有兩個方法：取得或建立地址、解綁地址。實作內只跟金流商往來，**禁止讀寫 `member_crypto_wallets`**，那是服務層的責任。取址對金流商必須是冪等的（同一會員同一通道重複呼叫回同一地址），服務層寫表失敗才能直接重試。
- 服務層 `MemberCryptoWalletService::getOrCreateForChannel()` 負責：交易內鎖查本通道的啟用列（有就直接回，不呼叫金流商）；沒有就取址；金流商回的地址若已在本通道有列，屬於別的會員就拋出（資料異常），本會員的啟用列就直接回（併發已寫入），本會員的停用列（風險地址等）就先解綁再取一次，仍拿到同一地址就拋出；寫入撞唯一鍵（併發）就回讀。
- 取址時 `notifyUrl` 綁定於地址、持久有效，一律帶含金流商戶 id 的回調網址；網域要先加進金流商後台白名單，加完通常要等幾分鐘才生效。
- 參考實作：CCPayment `Deposit.php` 的 `getOrCreateAddress()` 與 `unbind()`。

## 四、代收回調：判定矩陣與冪等

**這節在說什麼**：回調來了不等於錢到了。這節是入帳的守門規則，缺一條都可能入錯帳或重複入帳。

### 不可只信回調，一律回查

金流商的回調本體通常只有交易編號與狀態，沒有金額、鏈、地址（CCPayment 的 DirectDeposit 回調就是這樣，見下一節）。入帳金額、幣、鏈、收款地址、是否風險交易，全部以回查結果為準。回查是 HTTP 呼叫，放在資料庫交易之外，不持鎖等外部回應。

### 判定矩陣（依回查結果）

| 回查結果 | 動作 |
|---|---|
| 同 recordId 已有存款單 | 冪等：不回查、回 ack，回傳既有單 |
| referenceId 不是我方格式 | 不建單、告警、ack（款已在我方金流商帳戶，人工歸屬） |
| 狀態不是成功 | 不建單、ack（處理中等下一次回調；失敗只記錄） |
| 幣或鏈不等於支付通道設定 | 不建單、告警、ack（別種幣，系統無法定價，營運到金流商後台處理） |
| 地址在表裡不屬於 referenceId 的會員 | 不建單、告警、ack（資料異常） |
| 成功且被標記為風險交易 | 建單為狀態 4（待人工確認）、告警（款不在我方餘額；PM 拍板前的預設） |
| 成功且非風險 | 建單、狀態 1、入帳；金額＝回查的實收數量 |

以上每一列都回金流商成功響應（ack），避免金流商重送風暴；只有驗簽失敗才讓回應非 200，讓金流商重送。

### 冪等與鎖序

- 冪等鍵是 `orders.third_party_order_no`＝金流商 recordId。
- 建單交易內的第一個讀是鎖定會員主鍵列（`Member::lockForUpdate()`），之後的一般讀快照才含別人剛 commit 的單；鎖後再查一次冪等鍵。
- **不對不存在的存款單下 `FOR UPDATE`**：對不存在的非唯一鍵下鎖會拿 gap lock，兩個同 recordId 的回調同時進來會 deadlock。
- 反查鍵（支付通道, 地址）不看 `status`：解綁後轉進舊地址的款仍會入帳。
- 低於通道下限的存款照常建單只留紀錄，鏈上轉帳擋不住小額。
- 存款單的 `ip` 欄位是金流商回調的來源 IP，不是會員 IP；風控報表看 ip 時要知道。

### 風險地址回調

金流商把某個收款地址標記為風險時會另發一種回調（CCPayment 的風險地址回調沒有 `type`，只有 `chainId`、`address`、`riskAt`，以「有沒有 riskAt」分流）。處置：該列停用、立刻向金流商解綁、告警；換發新地址留到會員下次開存款方式清單自動取址（服務層保底會再解綁一次）；不自動通知會員，營運視情況用站內信手動發。

**多條 EVM 相容鏈上線前必須加鏈別比對**：ETH、BSC、Polygon 這類 EVM 相容鏈在金流商端是同一個地址，風險地址回調只帶地址，跨支付通道反查時若不比鏈別，可能停用錯的那列。CCPayment 現行單鏈先不看 `chainId`，只記錄。

參考實作：CCPayment `DepositCallback.php`（類別 docblock 有完整矩陣與鎖序說明）。

## 五、金流商回調的實際形狀與對帳補送

**這節在說什麼**：實測出來的回調長相與金流商 API 限制，決定了對帳怎麼做。

### 回調實測形狀（CCPayment，2026-09-16 測試網）

- 本體只有 `type` 與 `msg`；`msg` 含 `recordId`、`referenceId`、`coinId`、`coinSymbol`、`status`、`isFlaggedAsRisky`。沒有金額、鏈、地址，這些靠 recordId 回查。
- `status` 是首字大寫 `Success`。
- 我方回純文字 `Success`（Content-Type `text/html`）被金流商接受，後台「回覆日誌」記為成功、沒有重試，不必為它改共用回應層。
- 三種回調（到帳、代付結果、風險地址）用同一套驗簽：header 三件組（`Appid`、`Timestamp`、`Sign`），簽名字串＝我方保存的 APP ID＋Timestamp＋收到的原始 body，HMAC-SHA256。驗簽用常數時間比較；被簽的是原始 body 字串，不可重新序列化。

### 對帳採「請金流商重送回調」，不自己補單

- 理由：入帳與定案只留回調這一條寫入路徑，判定矩陣不用寫兩次。
- 存款側：拉最近 N 小時本金流商戶底下每條支付通道（幣, 鏈）的存款清單，狀態成功且 `orders.third_party_order_no` 找不到的就是缺漏；第一次看到就請金流商重送，重送過仍缺才告警。處理中的紀錄金流商之後會自己發回調，不算缺漏。
- 代付側：停在代付處理中（22）超過 2 小時且有三方單號的提款單，請金流商重送代付回調，由回調的三態判定定案；重送過仍停在 22 才告警。本命令不改單。
- 單一通道壞掉（key 解析不出幣鏈、幣未在金流商後台啟用、金流商拒絕該幣）跳過並記錄，不讓一條壞通道停掉整個金流商戶的對帳；取址與回調端則維持 fail-closed。
- 去重靠 Cache：重送與告警各記 24 小時，避免每輪把同一筆重送或告警到爆。
- 三種漏單情形都靠這支補：回調漏收、解綁後 notifyUrl 是否清除未知、風險地址的存款不發回調。

### 重送 API 的限制（CCPayment 實測）

| 限制 | 值 | 對實作的影響 |
|---|---|---|
| 單批 `recordIds` 上限 | 50 筆 | 超過要分批 |
| `startTimestamp` 到 `endTimestamp` | 最多相隔 1 小時 | 存款側依到帳時間分小時窗；代付側以提款單最後更新時間前後各半小時 |
| `startTimestamp` | 必填 | 不能只給 recordIds |

參考實作：CCPayment `Reconciliation.php`（拉清單、分批重送）與 `app/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommand.php`（排程每 30 分鐘，`--dry-run` 只列不送）。

## 六、代付

**這節在說什麼**：加密貨幣代付與法幣代付的框架相同（主檔「Withdrawal.php」與「WithdrawalCallback.php」兩節全部適用），這裡只列不同點。

1. **鏈由會員所選的銀行代碼經對照得出**（第二節「代付」），`coinId` 讀金流商戶 `info`。
2. **明確拒絕碼白名單制**（`REJECT_CODES`）照主檔規則，收錄準則是「這個碼能不能證明金流商沒有受理這筆代付」。加密貨幣金流商常見可收錄的碼：餘額不足、地址格式錯誤、鏈不支援、商戶未啟用該幣、不可提到自己的地址、低於或高於單筆限額。刻意不收的碼：泛用失敗、系統內部錯誤、限流、重複提交、訂單號已存在（收了就是把重複出款的向量打開）。只出現在廠商次要文檔（例如 GitHub SDK）而正式文檔沒有的碼一律不收。
3. **代付單號前綴要短**。CCPayment 用 `CCP`；金流商對 `orderId` 長度可能有限制，前綴長了會超。
4. **會員不能提到自己的收款地址**。金流商會拒（CCPayment 錯誤碼 `14008 WithdrawCannotSelf`），QA 測代付時收款地址要填外部錢包（例如 MetaMask）。
5. **網路費由誰負擔**做成金流商戶 `info` 的設定（CCPayment 是 `MERCHANT_PAY_NETWORK_FEE`，預設 false 由會員負擔，到帳數量＝提現數量減網路費），PM 定案後改設定不改代碼。客服要先知道會員實收會少一筆網路費。
6. **代付成功回應通常只有交易編號、沒有狀態**，是否真的出款要靠代付回調或查單定案。Driver 實作 `SupportsPayoutQuery` 時，查詢失敗回 UNKNOWN，不推斷成查無（那會打開重複出款的窗口）。
7. **前台提款金額規則 `numeric|min:1` 對加密貨幣通道偏粗**（出處 CCPayment `integration.md` 實測紀錄，本 skill 未另行核對代碼），屬 PM 待決；測試時若要小額，只能直接改資料。

## 七、測試網 QA 做法

**這節在說什麼**：主網要花真錢，端到端驗收儘量走金流商的測試網。以下是 CCPayment 實測出來的步驟，其他金流商依此類推。

1. **金流商後台開測試網**，並把測試幣加進「啟用代幣」。沒加的話：測試幣入金金流商不會自動發回調（要到後台手動按「發送 Webhook」）、代付會被拒（CCPayment 錯誤碼 `14010 NoSupportCoin`）。
2. **測試網當成一條支付通道**，只在非正式庫連線宣告（判準是 DB 連線名 `mysql`／`dev`／`testing`，與 `agent:add-agent-cash-flow` 等命令同一把尺，不是 `APP_ENV`，因為正式操作是用 `-e DB_CONNECTION=<白標>` 注入、`APP_ENV` 不變）。正式庫永遠沒有這條通道。
3. **測試幣從水龍頭（faucet）領**，外部錢包用 MetaMask 這類瀏覽器錢包；代付測試的收款地址填自己的 MetaMask 地址。
4. **測試幣的識別碼不能當存款清單查詢條件**（CCPayment 帶測試幣 `coinId` 查 `getAppDepositRecordList` 回 `11000 invalid argument`，不帶就查得到），對帳排程在 Dev 會每輪記一次錯誤，QA 期間可忽略。
5. **Dev 環境的測試網設定與還原方式**寫在該金流商 `runbook.md`「Dev 測試網 QA 驗收環境」一節（CCPayment 是第 8 節，含一段還原 SQL）；上線時照 runbook 的正式值配置，不要把 Dev 的測試網值搬過去。
6. **主網真幣測試**若測試網沒有目標鏈（CCPayment 沒有 TRON 測試網），至少要轉該幣在該鏈的最低入金量（CCPayment USDT 在 TRON 是 0.1），低於此金流商不入帳。

## 八、上線前的提醒與待決事項

- **遮罩**：告警訊息目前會把加密貨幣地址與會員 id 明文送出，共用的 `MaskTrait` 沒有加密貨幣地址的 profile，屬全專案待決；接新金流商時先知道這件事，不要再多加明文欄位。
- **配額**：永久地址通常有配額（CCPayment 預設每個 APP ID 1000 個，可免費擴容、解綁不釋放配額）。清單自動取址後，每位開過儲值頁的會員都占一個地址，**上線前先依會員數申請擴容**，上線後監控地址用量，門檻到了再擴。
- **金流商後台白名單**：`notifyUrl` 網域白名單與 IP 白名單都要設，加完要等幾分鐘；正式商戶與測試商戶是不同 APP ID，各自要設。
- **回滾順序**：先停用金流商戶再回滾代碼。舊代碼沒有這家的 Driver 也沒有建單擋門，金流商戶還啟用時會員點選會走建單流程並失敗。
- **停用期間的入款**：回調端只用金流商戶 id 反查、不看 `status`，已發地址的入款仍會入帳；但對帳排程只掃啟用中的金流商戶，恢復啟用後要加大回看範圍手跑一次。

## 參考實作索引（CCPayment，APIs repo）

| 主題 | 檔案 |
|---|---|
| 廠商原文規格 | `docs/integrations/payments/CCPayment/api.md` |
| 已驗證落差與設計決策索引 | `docs/integrations/payments/CCPayment/integration.md` |
| 在站點配置金流商戶（各站通用的上線步驟）與 Dev 測試網 | `docs/integrations/payments/CCPayment/runbook.md` |
| 待廠商回覆 | `docs/integrations/payments/CCPayment/open-questions.md` |
| 取址、解綁 | `app/Services/Payment/Providers/CCPayment/Deposit.php` |
| 回調判定矩陣、風險地址 | `app/Services/Payment/Providers/CCPayment/DepositCallback.php` |
| 對帳補送 | `app/Services/Payment/Providers/CCPayment/Reconciliation.php`、`app/Console/Commands/CashFlow/CCPayment/ResendMissingWebhooksCommand.php` |
| referenceId | `app/Services/Payment/Providers/CCPayment/ReferenceId.php` |
| 代付白名單、鏈取法 | `app/Services/Payment/Providers/CCPayment/Withdrawal.php` |
| 驗簽、幣鏈執行期查詢 | `app/Services/Payment/Providers/CCPayment/CCPaymentTrait.php` |
| 通道 key 組裝與解析、禁止改名規則 | `app/Services/Payment/Providers/CCPayment/ChannelKey.php` |
| 永久地址契約 | `app/Services/Payment/Contracts/SupportsPermanentDepositAddress.php` |
| 服務層取址（清單自動取址 `walletsForChannels()`、主動取址 `getOrCreateForChannel()`） | `app/Services/MemberCryptoWalletService.php` |
| 資料檔 | `app/Console/Commands/CashFlow/Data/providers/CCPayment.php` |
| 平台既有慣例 | `app/Services/Payment/Providers/PayEssence/Deposit.php`、`Withdrawal.php`、`app/Console/Commands/CashFlow/Data/providers/PayEssence.php` |
