# B3 Wallet 上線 runbook（多支付方式：PayNow／銀行轉帳）

> 工單 `target/tickets#4300`（TPower 接入）。首次接入是 `#4000`（VWSG，2026-07 已上線，當時三方只有 PayNow 收款、只有 SGD）。
> 本檔只寫 B3 Wallet 專屬的步驟與依據；金流商上線的通用流程（連正式庫、文件與帳密歸檔、通知 PM）見 `docs/金流接入流程/4-上線.md`。
> 憑證值（PaymentKey、PaymentPass）不寫進任何檔案、命令參數或訊息，只在後台或由上線人員填入。
> 廠商原文兩份都在同目錄：`merchant-api-guide-paynow.md`（#4000 初版）、`merchant-api-guide.md`（本次 2026-09 新版），升版時直接 diff。

## 名詞

| 名詞 | 意思 |
|---|---|
| 金流商戶 | `agent_cash_flows` 一列。B3 Wallet 代收是金流商 123（`D_B3WALLET`）、代付 124（`W_B3WALLET`） |
| 支付通道 | 會員存款頁看得到的付款選項，父層是 `order_types`，掛到金流商戶的關聯是 `agent_cash_flow_order_types` |
| 支付組別 | `agent_group_id`。建商戶命令用 `--agent` 指定代理，命令會用該代理的 `cash_flow_group_id` 找組別，同組任一代理都可以 |
| 通道 key | `order_types.key`，**就是送三方的 `DepositInstruction`**。鏈上收款以 `.` 後綴帶鏈別（`CRYPTO.TRON`），送出前拆成 `Chain` 參數 |

## 0. 閘門：缺一項就不啟用

| # | 閘門 | 怎麼確認 | 為什麼 |
|---|---|---|---|
| 1 | 代碼已合入 master、發版並部署到該站 | 看該版 tag 的 `release-prod` job 與部署 pipeline 都成功 | 沒部署，`cash-flow:sync` 讀不到三個通道的配置，而且舊代碼寫死 `PAYNOW`，新建的銀行轉帳／鏈上通道會被當成 PayNow 送出 |
| 2 | **三方已為該商戶開通要用的方式與幣別** | **不要只看書面回覆，直接打**（見下方「怎麼實打確認」）。未開通建單回 `422 NO_AVAILABLE_PAYMENT_ACCOUNT`，會員點了就失敗 | 開通狀態是 per 商戶、per 幣別、per 方式的；`#4300` 測試商戶實測代收與代付的 USDT 都未開通，SGD 兩種方式則可用 |
| 3 | 各方式的費率表已取得 | 書面回覆，或登商戶後台查 | `agent_cash_flows.cost` 要逐通道設；分潤（佔成）的支付成本吃這個值，設錯會算錯代理佔成 |
| 4 | PM 拍板要開哪幾個通道與限額 | 有書面結論 | 限額設錯會讓會員建不了單或超額建單 |

### 怎麼實打確認開通狀態

代收：建一筆最低金額的帳單，沒付款就會過期，不會有金流。422 `NO_AVAILABLE_PAYMENT_ACCOUNT` ＝ 該幣別／方式未開通。

```bash
curl -s -X POST https://www.judy88.xin/api/v1/bills/create \
  -H "Authorization: <PaymentKey>:<PaymentPass>" -H "Content-Type: application/json" \
  -H "Idempotency-Key: probe-$(date +%s)" \
  -d '{"ShopName":"<商戶名>","ProductName":"probe","Amount":10,"DepositInstruction":"PAYNOW","Currency":"SGD"}'
```

代付：要用**格式合法的地址 ＋ 不可能通過的金額**。送無效地址只會拿到 `400 Invalid TRON address format`，
那只代表請求走到了地址驗證、不代表幣別已開通（三方先查開通還是先驗格式，文件沒寫，不能反推）；
而送有效地址配正常金額會真的建單並凍結餘額。合法地址配一個遠超餘額的金額，兩種可能的回應都不會建單：
`422 NO_AVAILABLE_PAYMENT_ACCOUNT` ＝ 未開通，`INSUFFICIENT_BALANCE` / `AMOUNT_ABOVE_MAX` ＝ 已開通（它已經在算錢了）。

**2026-10-01 以 `#4300` 測試商戶實測結果：**

| 方式 | 幣別 | 結果 |
|---|---|---|
| 代收 `PAYNOW` | SGD | ✅ 建單成功，`FeeRate` 0.026 |
| 代收 `BANK_TRANSFER` | SGD | ✅ 建單成功，`FeeRate` 0.026 |
| 代收 `CRYPTO` | USDT | ❌ 422「No active deposit members for currency USDT」（TRON／ETHEREUM／BSC 三條鏈一致，是 currency 層級） |
| 代付 `CRYPTO` | USDT | ❌ 422「No active payout members available」 |

兩種 SGD 代收通道的費率相同（2.6%），所以**就這個商戶而言，拆成兩筆金流商戶不是為了不同 cost，而是為了讓會員能各自選、各自上下架**。TPower 正式商戶的費率要另外確認，不能沿用測試商戶的值。

**USDT（鏈上收付）本次不做**：實測代收與代付都回 422 未開通，客服 2026-10-01 亦確認目前不支援。設定檔已不列 USDT 通道與銀行，Dev 建過的相關資料已刪除。Driver 的鏈上收付代碼保留（`Deposit` 的 `Chain` 後綴解析、`Withdrawal` 的 `CRYPTO` 分支與對應測試），日後廠商開通時只要在設定檔加回通道與銀行即可，代碼不動。

付款頁網域是 `https://example.invalid`（`BillUrl` 回的值），與 API 網域不同；會員端若有網域白名單要一併放行。

（供日後開通 USDT 時參考）B3 Wallet 的鏈上收款**不需要** App 配合：它是建單後跳 `BillUrl` 由三方收銀台顯示地址，不是 CCPayment 那種永久地址模式，App 照既有的跳轉流程走即可。

## 1. 這次改了什麼（代碼面全站生效，先知道再動手）

| 改動 | 影響範圍 | 既有站點會不會變 |
|---|---|---|
| 代收的 `DepositInstruction` 改成取 `order_types.key` | 所有用 B3 Wallet 的站 | **不會**。VWSG（支付組別 19／99）的通道 key 實查為 `PAYNOW`，與原本寫死的值相同，送出內容一字不差 |
| 代付新增 `Currency`（原本不帶，吃商戶預設幣別） | 所有用 B3 Wallet 的站 | **不會**，但要確認該站金流商戶的幣別正確。VWSG 兩組都是 SGD、商戶預設也是 SGD |
| 代付新增 `CRYPTO` 分支（依提款銀行的類型判斷） | 只有建了 USDT 提款銀行的站 | **不會**。沒有 `WithdrawalTypeEnum::CRYPTO` 類型的提款銀行就走不到該分支 |
| 代付回調收下三方的 `FeeAmount`／`FeeRate`／`TotalDeductAmount` | 所有用 B3 Wallet 的站 | **不會**。只收進回調原文備查，`payment_cost` 仍是「提款點數 × 渠道 cost」，與代收同一套口徑 |

🔴 **對既有站點最大的風險不是代碼，是同步命令**：`cash-flow:sync --ids=123` 會依設定檔建出**兩個**通道。在還沒跟廠商開通銀行轉帳的站上跑，前台會多出一個點了就失敗的付款選項。**要在哪一站開哪些通道，由該站自己的工單決定；不要為了「順手」在別站跑這條命令。**

## 2. 正式庫配置

全部在容器內執行，依站點注入 `DB_CONNECTION`（見 `whitelabel-container-exec`）。以下用 TPower 示意。

### 2.1 同步金流商設定（建通道父層與銀行）

```bash
# 先 dry-run 看會建什麼，確認只有預期中的通道
docker exec -e DB_CONNECTION=TPower -u www-data php84-fpm bash -c \
  "cd /var/www/apis && HOME=/tmp php artisan cash-flow:sync --ids=123 --dry-run"

docker exec -e DB_CONNECTION=TPower -u www-data php84-fpm bash -c \
  "cd /var/www/apis && HOME=/tmp php artisan cash-flow:sync --ids=123"

# 代付銀行清單
docker exec -e DB_CONNECTION=TPower -u www-data php84-fpm bash -c \
  "cd /var/www/apis && HOME=/tmp php artisan cash-flow:sync --ids=124"
```

確認：`order_types` 應有 `PAYNOW`(SGD)、`BANK_TRANSFER`(SGD) 兩列。

同步代付銀行時，`TBL`／`ASPIRE`／`YOUBIZ` 三個會因比對信心度 0% 被跳過，這是既有狀況（設定檔註解已記），不影響其他銀行。

### 2.2 建金流商戶

```bash
# 代收 SGD：一個通道一筆，既有單筆記錄就地改名重用
php artisan agent:add-agent-cash-flow --agent=<代理id> --cashflow=123 --currency=SGD --mode=split --migrate-single --dry-run
# 代付 SGD（固定單筆）
php artisan agent:add-agent-cash-flow --agent=<代理id> --cashflow=124 --currency=SGD
```

⚠️ `--migrate-single` 會把既有那筆「(SGD) B3 Wallet - Deposit」**改名**為「(SGD) B3 Wallet - Paynow」並沿用該列（id 不變、既有通道關聯保留），再新建銀行轉帳那筆。**先 dry-run 看遷移計畫表再實跑。**

建完應有三筆：

```
(SGD) B3 Wallet - Paynow           代收
(SGD) B3 Wallet - Online Banking   代收
(SGD) B3 Wallet - Withdrawal       代付
```

### 2.3 填憑證

設定檔的 `PAYMENT_KEY`／`PAYMENT_PASS` 一律留空（機密不進 repo），建完的五筆都是空值，由上線人員在後台填入，或用 `cash-flow:copy-credentials` 從已填好的商戶複製（值不離資料庫）。

`API_URL` 由設定檔帶入，值就是廠商文件開頭載明的 Base URL `https://www.judy88.xin`（`merchant-api-guide.md` 第 11 行），不需要改。商戶後台網址（`#4300` 是 `example.invalid`）不是 API 網址，不要拿它去填。

**上線前重跑一次銀行清單**（唯讀、不建單，可直接打）：

```bash
curl -s -H "Authorization: <PaymentKey>:<PaymentPass>" https://www.judy88.xin/api/v1/payout-recipient-banks
```

回傳的是**該商戶**已啟用的代碼，各商戶不同、而且會變。2026-10-01 用 `#4300` 測試商戶取得 49 碼，比 `#4000`（2026-07-14，27 碼）多出澳洲（CBA/ANZ/NAB/WBC/MACQ/BOQ/BEN/STG/ING/AMP/AMB/CUS/HBS/ME/UBANK）與香港（HSB/BEA/ICBC/CMB/CCB/FPS）兩組，對應三方新開的 AUD／HKD 通道；本次只配 SGD，那兩組不進設定檔。

清單含 `PAYNOW`，但 `BANK_TRANSFER` 模式傳 `RecipientBank=PAYNOW` 三方回 400（PayNow 要走獨立的 `PayoutMethod=PAYNOW`），故設定檔刻意排除，不要當一般銀行加回去。

### 2.4 設 cost、限額與入帳比例

費率表到手後逐筆設定：

```bash
php artisan cash-flow:set-cost --dry-run   # 參數見該命令 --help，任一配對不合格整批零寫入
```

（日後開通 USDT 時）鏈上代收的入帳比例（`agent_cash_flows.rate`）與該幣別提款銀行的 `rate` **必須同值**，否則存提不對等會資損。

## 3. 驗證

| 項目 | 怎麼確認 |
|---|---|
| 每個通道各建一筆小額訂單 | 看出站請求的 `DepositInstruction` 是否等於該通道的 key；鏈上通道要同時帶 `Chain` |
| 回應的 `DepositInstruction` 不等於請求值 | 傳 `PAYNOW` 三方回 `QR` 是正常的，不要當異常。我方不比對這個欄位 |
| 代收入帳 | 回調進來後查單確認 `PAID`／`SETTLED` 才入帳（我方不信回調的 `Paid`） |
| 代付出款 | 三種方式各走一次；鏈上出金要用該鏈的真實有效地址 |
| `payment_cost` 有寫入 | 代付成功後看 `withdrawal_orders.payment_cost` 是否等於提款點數 × 該出款通道的 `agent_cash_flows.cost`。三方回調的 `FeeAmount` 刻意不採用，兩者不相等是正常的 |

## 4. 止血與回滾

| 狀況 | 處置 |
|---|---|
| 某個通道一直失敗（多半是三方沒開通） | 用 `cash-flow:channel-status` 把該通道的 ACF／ACFOT 下架，不必回滾代碼；其他通道不受影響 |
| 要整個停用 B3 Wallet | 同上，把五筆金流商戶 status 關掉 |
| 代碼要回滾 | 回滾後舊代碼寫死 `PAYNOW`，**銀行轉帳與鏈上通道必須先下架**，否則會員從那些通道建單會被當成 PayNow 送出 |

## 5. 分潤支付成本 SP（與本次一併改，但不是 B3 Wallet 專屬）

`SP_calc_profit_share_payment_cost` 改為加總訂單上凍結的 `payment_cost`，不再以「點數 × 當下的 cost」現算。

- **只有 Yes2Win 在跑這支**（MySQL event `Event_Calc_Profit_Share_Payment_Cost`，每天台北 15:07）。TPower／VWorld 沒有對應 event、`summary_profit_share_payment_costs` 為空，不受影響。
- 上線前在 Yes2Win 確認 `order:backfill-payment-cost` 已回補，否則舊單成本為 0。實查 2026-09-28~29：代收 13,554 筆全有值、代付 2,752 筆中 2,708 筆有值（其餘多為 cost=0 的渠道）。
- SP 要隨發版同步到該站資料庫才生效。

## 附：Dev 已建的配置（2026-10-01）

| id | 金流商 | 幣別 | 名稱 | 通道 key |
|---|---|---|---|---|
| 7187 | 123 | SGD | (SGD) B3 Wallet - Paynow | `PAYNOW` |
| 7682 | 123 | SGD | (SGD) B3 Wallet - Online Banking | `BANK_TRANSFER` |
| 7188 | 124 | SGD | (SGD) B3 Wallet - Withdrawal | 17 間銀行 |

憑證填的是工單 `#4300` 的測試商戶（GFFTEST-42-TEST），`API_URL` 為文件載明的 `https://www.judy88.xin`，已實打可用。

原本建過的 USDT 兩筆（代收 7683、代付 7684）與通道父層 `CRYPTO.TRON`、`cash_flow_banks` 對映，
已於 2026-10-01 確認三方未開通後全數刪除，Dev 無殘留。base 層與支付組別的「USDT(TRC20)」銀行列是既有資料
（分別建於 2026-01 與 2023-03，CCPayment／PayEssence 也在用），未動。

`order_types` 5640 的顯示名仍是舊的「B3 Wallet PayNow」（同步命令不改既有名稱），要統一成「B3 Wallet - Paynow」走 `cash-flow:order-type-name`。
