# CCPayment 上線 runbook（USDT-TRC20 代收與代付，各站通用）

> 工單 `target/tickets#4238`（開發）；各站正式配置：TPower `#4239`、Yes2Win `#4240`、Wintoto `#4241`。本檔只寫 CCPayment 專屬的步驟與依據；金流商上線的通用流程（連正式庫、文件與帳密歸檔、通知 PM）見 `docs/金流接入流程/4-上線.md`。
> 每一步都寫「做什麼、為什麼、怎麼確認」。憑證值（APP ID、App Secret）不寫進任何檔案、命令參數或訊息，只在後台填入。
> 本檔的步驟每站都要走一遍。各站不同的是 id 與連線，先查「站點對照表」一節，步驟裡用「正式組」「測試組提款銀行列」這類稱呼指代，不寫死某一站的 id。

## 名詞

| 名詞 | 意思 |
|---|---|
| 金流商戶 | `agent_cash_flows` 一列，金流商發給平台的一組收付款身分。CCPayment 每組各有代收（金流商 129）與代付（金流商 130）兩列 |
| 支付通道 | 會員存款頁看得到的一個付款選項，父層是 `order_types`，掛到金流商戶的關聯是 `agent_cash_flow_order_types` |
| 支付組別 | `agent_group_id`。每站有一個正式組、一個測試組，兩組是孿生，設定要成對做 |
| base 層銀行 | `banks` 裡 `agent_group_id = 0` 的列，是各支付組別銀行列的模板 |
| 提款銀行列 | 支付組別底下 USDT-TRC20 那一列 `banks`，會員綁提款帳戶、提款扣點都看它 |
| 入帳比例 | 代收金流商戶的 `agent_cash_flows.rate`，存入 1 USDT 換幾點 |

## 站點對照表

| 項目 | TPower | Wintoto | Yes2Win |
|---|---|---|---|
| 容器連線名（`DB_CONNECTION`） | `TPower` | `VWorld`（Wintoto 與 VWorld 共用一套資料庫） | `Yes2Win` |
| 主庫埠號的環境變數 | `TP_DB_PORT_WRITE` | `VW_DB_PORT_WRITE` | `Y2W_DB_PORT_WRITE` |
| dbc 主庫（`dbc list` 查埠號） | `tp-wz3-tpower:…:xsgcasino-online` | `tp-wz3-online:…:xsgcasino-online` | `gff-yes2win:…:xsgcasino-online` |
| 唯讀查詢（mysql MCP） | `mysql-tpower` | `mysql-vworld` | `mysql-yes2win` |
| 正式組（代理 id） | 674（71541） | 70（59457） | 7（組內任一代理，例 100003） |
| 測試組（代理 id） | 688（71561） | 93（59610） | 12（組內任一代理，例 100004） |
| base 層 USDT-TRC20 銀行 | 1369 | 1168 | 1338 |
| 正式組／測試組提款銀行列 | 485／771 | 205／206 | 449／587 |
| 回調網域 | `https://example.invalid` | `https://example.invalid` | `https://example.invalid` |
| 進度 | 四個金流商戶已建：測試組啟用、正式組停用 | 同左 | 同左 |

- 建商戶命令用 `--agent` 指定代理，命令會用這個代理的 `cash_flow_group_id` 找支付組別，所以同組任一代理都可以。
- 回調網域的依據：各站 `withdrawal_payout_records.payout_order_request_body` 裡既有代付請求帶的 notify 網址。VWorld 資料庫有多個站點共用，查 Wintoto 時要限定 Wintoto 支付組別的金流商戶。
- 已建站點的金流商戶 id 與目前的值見附錄「各站已建的金流商戶」。

## 0. 閘門：缺一項就不啟用

| # | 閘門 | 怎麼確認 | 為什麼 |
|---|---|---|---|
| 1 | 代碼已合入 master、發版並部署到該站 | 看該版 tag 的 `release-prod` job 與部署 pipeline 都成功；tag 打了不等於已上線 | 沒部署，`cash-flow:sync` 讀不到 129／130 的配置 |
| 2 | 端到端實測通過 | 測試組在正式庫上取址、小額代收入帳、小額代付各走通一次。規格沒寫死的四件事已在 Dev 測試網確認：`referenceId` 帶連字號能取址；回調回應 `text/html` 被金流商接受；成功字串是首字大寫 `Success`；金流商重送回調時 `Timestamp` 是否換新（後台看不到標頭，我方驗簽不看時效，不阻塞）。代收入帳、代付出款與三種拒絕碼也已在測試網走通（`integration.md` 第 10 節、本檔第 8 節） | 這四件事規格沒寫死，只能實打確認（`open-questions.md` 第 21 題、`integration.md`「我方實作決策」一節的「回應 Content-Type」列） |
| 3 | App 會顯示存款方式清單帶的 `crypto_wallet_address`（FonPay 時代就有的欄位） | 前端／App 確認現版對加密貨幣通道會顯示地址與 QR、不顯示金額表單 | 清單會替還沒有地址的會員自動取址，App 不必新增呼叫；`POST deposit/crypto-address` 只是可選的主動取址。App 若把這類通道當一般表單送 `create-order` 會被擋（回 400）。**代付不受影響，可以先上**。Wintoto 測試組已取址成功（`member_crypto_wallets` 有列），但前台沒顯示地址，交 PM 確認前台 |
| 4 | PM 拍板 | 有書面結論 | 風險交易處置（預設建單為狀態 4 待人工確認）；網路費由誰負擔（預設會員負擔，到帳數量＝提現數量減網路費）；提款銀行列的固定手續費（TPower 由 PM 定為 1.5，其他站比照與否要告知 PM）；**匯率由誰維護：代收的入帳比例與提款銀行列的 `rate` 必須同值，否則存提不對等會資損（設定步驟見 3.8）** |
| 5 | 金流商後台設定完成 | 「金流商後台設定」一節逐項打勾 | 網域不在白名單，取址與代付會被金流商拒絕 |

## 1. 上線會動到什麼（先知道再動手）

**上線命令會改寫的既有資料**

| 資料 | 變化 | 依據 |
|---|---|---|
| 正式組與測試組的提款銀行列 | `agent:add-agent-cash-flow` 建代付金流商戶時，以 base 層 USDT-TRC20 那列為模板整列覆寫：名稱換成 base 的名稱、固定手續費變 0、備註清空、圖示換成 base 的、status 變 1（停用中的測試組列會被打開）。**保留**：code、限額（目標列為 0 時才套渠道值）、匯率 | `app/Services/CashFlow/Bank/AgentBankSyncService.php` 的 `syncToAgentGroup()`：目標列存在就 `update` 全部屬性。銀行同步不看 `--status`，正式組建成停用也照樣覆寫 |
| 會員的提款銀行綁定 | 不受影響：`member_bank_accounts` 只存 `bank_id`，沒有 `bank_code` | 查正式庫欄位 |
| 新提款單的 `bank_code` | 取自 `banks.code`，必須是 `USDT0001`，才能對上代付金流商戶 `available_banks` 的 `BankCode_ELG` | `app/Http/Controllers/Frontend/WithdrawalController.php` 建提款單的 `bank_code` |
| PayPro 代付的印尼銀行碼補丁 | 不受影響：`cash-flow:sync --ids=129,130` 的「預先停用金流商銀行關聯」只作用在 129／130 自己的 `cash_flow_banks` | `app/Console/Commands/CashFlow/SyncCashFlowsCommand.php` 的 `disableAllCashFlowBanks()` 以 `cash_flow_id` 過濾 |

**代碼面全站生效的一處**：`deposit/create-order` 對「綁定加密貨幣錢包」的金流商（FonPay 35、CCPayment 129）一律回 400 不建單。六個白標（TPower、VWorld、Yes2Win、Alfawin77、ME77、VPlus33）裡 FonPay 只有 VWorld 支付組別 664 一個啟用中的金流商戶（106），近 90 天零存款單，而且 APIs 本來就沒有 FonPay 的 Driver，實際行為變化為零；Wintoto 與 VWSG 沒有查（本機沒有它們的唯讀連線），**未驗證**。

## 2. 金流商後台（CCPayment Dashboard）設定

每站的正式商戶各自設定一次；測試組若用同一個正式 APP ID（見 3.7），不必另設。

| 項目 | 位置 | 為什麼 |
|---|---|---|
| notifyUrl 網域白名單 | Dashboard > Developer > notifyUrl Domain Whitelist，加入該站的回調網域（站點對照表；回調網址形狀是 `…/external/api/deposit/notify/{金流商戶 id}` 與 `…/external/api/withdrawal/notify/{金流商戶 id}`） | 取址（`getOrCreateAppDepositAddress`）與代付（`applyAppWithdrawToNetwork`）都帶 `notifyUrl`，網域不在白名單金流商會回 `12004`（`api.md` 的 notifyUrl 說明）。後台會把加入的值標準化成根網域（例如 `example.invalid`），且**加完要等幾分鐘才生效**（Dev 實測前三次仍被拒），先等再試。只設 Webhook URL 不夠，取址與代付仍會回 `12004` |
| IP 白名單（我方 → 金流商） | Dashboard > Developer > IP Whitelist | 商戶有啟用這項時，我方出站公網 IP 不在名單會回 `12002 IpNotInWhitelist`（`api.md`「IP 白名單」一節）。各站出站 IP 不同，以該站工單描述為準 |
| 回調來源 IP（金流商 → 我方） | 我方 ingress／nginx | 金流商 Webhook 只從 `0.0.0.0` 發出（`api.md` 引 markdown 版 5.2）。External API 的來源限制在 ingress／nginx 層、代碼層沒有；**ingress 有沒有限制未查證**，有的話要加這個 IP |
| 憑證 | 該站正式商戶的 APP ID 與 App Secret | 只在後台填入 `agent_cash_flows.info`（「填憑證」一步），不進檔案、命令參數與聊天 |
| 地址配額 | 上線前先向金流商申請把正式 APP ID 的永久地址配額（預設 1000）擴到夠用 | 開存款方式清單就取址，每位開過儲值頁的會員占一個地址、解綁不釋放，1000 個撐不了幾天（TPower 近 30 天有存款單的會員 13,138 人）；用完金流商回 `15001`，清單會留空地址並記 log。測試組共用正式 APP ID 時，測試取的地址也占正式配額。金流商說擴容不收費、沒有上限，但申請流程與時效沒問（`open-questions.md` 第 15、20 題）；用量門檻見「上線後監控」一節 |

## 3. 正式庫配置（全部在容器內執行，連正式庫）

### 3.0 連線與防呆

這步確保命令打到的是該站的主庫。八個站的 database 名稱都叫 `xsgcasino`，連錯站從命令輸出看不出來。

- **埠號**：用 `dbc list` 查該站 dbc 主庫（站點對照表）那一列的 PORT，再用 `nc -z 0.0.0.0 <埠號>` 確認有人在聽。埠號因 IAM 帳號而異，不沿用別人或上次的值，也不憑口頭提供的數字。
- **連線注入**：每條命令都帶 `docker exec -e DB_CONNECTION=<連線名> -e <埠號變數>=<埠號> php-fpm8 …`。容器內不能有 `bootstrap/cache/config.php`，否則注入無效。
- **乾跑也要連主庫**：`cash-flow:sync --dry-run` 與 `agent:add-agent-cash-flow --dry-run` 都是在交易內照寫再回滾，連到從庫會在第一個寫入噴 `1290 super-read-only`，而且先印完摘要才報錯，只看摘要會誤判成功。
- **身分核對**：動手前用唯讀 MCP 查一次這幾個值並記下來，命令輸出要跟它對得上：該站兩組提款銀行列的 id、code、`rate`；129／130 的 `agent_cash_flows`、`cash_flow_banks` 列數。
- **環境確認**：`agent:add-agent-cash-flow` 偵測到正式庫會要求確認。人在終端機跑就照實回答；由 AI 在非互動環境代跑時答不了確認、連乾跑都要加 `--force`，所以代跑前一定要先做完身分核對，並逐步取得同意。`cash-flow:sync` 沒有這個確認。

### 3.1 前置盤點：提款銀行碼與 base 層名稱

這步確認兩件事：提款銀行列的 code 是 `USDT0001`、base 層的名稱是標準名「USDT - TRC20」。兩件都要在同步（3.2）之前做完，而且**一定先改碼、再改名**，順序反了同步會改壞 base 層的 code。

```sql
-- 唯讀 MCP：該站所有 USDT 銀行列
SELECT id, agent_group_id, name, code, status, rate, tax, min_amount, max_amount
FROM banks WHERE currency_id = 10 AND deleted_at IS NULL ORDER BY agent_group_id, id;
```

| 要看的 | 預期 | 不符時 |
|---|---|---|
| 1. 兩組提款銀行列的 code | `USDT0001` | 用 `bank:recode-bank --pair=<id>:USDT0001 --reason=…` 就地改碼（預設只出預檢報表，加 `--execute` 才寫）。歷史提款單的 `bank_code` 保留舊碼，不改 |
| 2. base 層 USDT-TRC20 那列的名稱（改碼做完才做） | 「USDT - TRC20」（ERC20 那列是「USDT - ERC20」） | 用 `bank:set-name --pair=<id>:"USDT - TRC20" --reason=…` 改名，用法同上 |

- **同步怎麼決定 base 層的 code**：同步先用 `StandardBankNameHelper::matchBankName()` 拿資料檔的銀行名去比對 **base 層**的啟用列，取得「標準名」（就是比對到的那列 base 層的名稱）；再由 `SyncCashFlowsCommand::syncBankCode()` 在**所有支付組別**的啟用列裡，找 id 最小、名稱與標準名完全相同的其他列，把 base 層那列的 code 改成它的 code。
- **為什麼一定先改碼再改名**：觸發條件是「base 層的名稱和某個提款銀行列相同，而那列的 code 不同、id 又比 base 列小」。以 Yes2Win 為例，449 的名稱已經是「USDT - TRC20」、code 是舊碼、id 比 base 層 1338 小；只要 1338 先被改名成「USDT - TRC20」，同步就會把 1338 的 `USDT0001` 改成舊碼。改名前 1338 叫「USDT(TRC20)」，沒有其他列同名，所以不會觸發（Yes2Win 演練時，改碼前的同步乾跑確認 1338 的 code 沒被改）。同步的統計表顯示「Bank 更新 0」，只有一行「代碼: A => B」看得出來；`bank:set-name` 的預覽不會提醒這件事。
- **為什麼名稱也要改**：建代付商戶（3.4）會把提款銀行列的名稱改成 base 層的名稱。base 是舊名的話，兩組提款銀行列也會被改成舊名，跟各站統一的標準名「USDT - TRC20」不一致。資料檔（`app/Console/Commands/CashFlow/Data/providers/CCPayment.php`）的銀行名也是標準名。
- `bank:recode-bank` 目前只在銀行收斂分支（worktree `APIs-payment-bank-convergence`），master 沒有，要從那個 worktree 跑；`bank:set-name` 已在 master。兩支都會寫審計表 `bank_dedup_logs`。

### 3.2 同步金流商配置

```bash
php artisan cash-flow:sync --ids=129,130 --dry-run
php artisan cash-flow:sync --ids=129,130
```

預期：金流商設定（CashFlow）新增 0 筆（129／130 已由部署時的 migration 建好）；支付通道父層（OrderType）新增 1 筆 `CCPAYMENT.USDT_TRX`（key＝幣別代號＋三方鏈代號，coinId 執行期向金流商查，見 `integration.md` 第 9 節「幣別與鏈別放哪」）；金流商銀行（CashFlowBank）新增 1 筆 `provider_bank_code=USDT_TRX`（幣別代號＋三方鏈代號，代付的幣與鏈都由它決定），對到 base 層 USDT-TRC20 那列；輸出**不得出現**「代碼: … => …」改到 base 層那列。多出任何一筆就停下查原因。**正式庫不得出現** `CCPAYMENT.TETH_ETH_SEPOLIA`（測試網通道只在非正式庫連線宣告，見第 8 節）。

### 3.3 同步後的守護命令

```bash
php artisan cash-flow:backfill-tpower-payout-bankcodes --dry-run
php artisan bank:merge-base-duplicate-banks    # 預設只出報表，加 --execute 才寫；沒有 --dry-run 選項
php artisan bank:merge-base-synonym-banks      # 同上
```

一律先看預覽，有項目才實跑，並把原因記進本檔。兩支 `bank:merge-base-*` 不接受 `--dry-run`（加了會直接報錯「option does not exist」）。各站適用情形：

| 命令 | 適用 | 說明 |
|---|---|---|
| `cash-flow:backfill-tpower-payout-bankcodes` | TPower | 只補 TPower PayPro（金流商 102）印尼盾的代付銀行碼；TPower 實跑零動作；Yes2Win 乾跑四列全部跳過（缺 base 層銀行） |
| `bank:merge-base-duplicate-banks`／`bank:merge-base-synonym-banks` | VWorld 資料庫（Wintoto） | 配對表寫死 VWorld 的 bank id，在其他站跑會因 id 對不上整批攔截、零寫入（TPower 實測 71／201 項不符，Yes2Win 實測 119／211 項不符）。Wintoto 實跑：synonym 零項目；duplicate 被兩列與 USDT 無關的已軟刪列整批攔截、零寫入 |

### 3.4 建立金流商戶：先測試組，再正式組

```bash
# 測試組：代收、代付各一筆，建成啟用，用來做正式庫上的實測
php artisan agent:add-agent-cash-flow --agent=<測試組代理 id> --cashflow=129 --currency=USDT --mode=single --dry-run
php artisan agent:add-agent-cash-flow --agent=<測試組代理 id> --cashflow=129 --currency=USDT --mode=single
php artisan agent:add-agent-cash-flow --agent=<測試組代理 id> --cashflow=130 --currency=USDT --dry-run
php artisan agent:add-agent-cash-flow --agent=<測試組代理 id> --cashflow=130 --currency=USDT

# 正式組：建成停用並隱藏（--status=0），憑證填好、驗證過再啟用
php artisan agent:add-agent-cash-flow --agent=<正式組代理 id> --cashflow=129 --currency=USDT --mode=single --status=0 --dry-run
php artisan agent:add-agent-cash-flow --agent=<正式組代理 id> --cashflow=129 --currency=USDT --mode=single --status=0
php artisan agent:add-agent-cash-flow --agent=<正式組代理 id> --cashflow=130 --currency=USDT --status=0 --dry-run
php artisan agent:add-agent-cash-flow --agent=<正式組代理 id> --cashflow=130 --currency=USDT --status=0
```

- `--mode=single`：代收只有一條支付通道且配置檔沒有 cost，單筆模式（一個金流商戶掛全部通道）就夠；代付固定單筆，不吃 `--mode`。
- `--status` 寫在營運實際使用的上下架開關上。代收寫在支付通道關聯（`agent_cash_flow_order_types`），代收金流商戶本身固定是啟用。代付寫在金流商戶（`agent_cash_flows`）。`--status=0` 時，同一層的 `backend_display` 也會設成 0，後台列表就會隱藏這一列。新舊代理後台都是依金流類型讀這兩層。
- 預期每次：`agent_cash_flows` 新增 1 筆，名稱「(USDT) CCPayment - Deposit」或「(USDT) CCPayment - Withdrawal」；代收另建 `agent_cash_flow_order_types` 1 筆；代付的 `info.available_banks` 出現 `BankCode_ELG=USDT0001 → BankCode_Third=USDT_TRX`，並更新提款銀行列 1 筆（「上線會動到什麼」一節說的覆寫）。
- 代付乾跑顯示「available_banks 無差異」可能是預覽讀到同一交易內的資料，不準；以實跑後讀回為準。
- 建出的 `info` 帶四個鍵：`API_URL`、`MERCHANT_PAY_NETWORK_FEE`（false）已填，`APP_ID`、`APP_SECRET` 留空（`CCPayment.php` 的 credentials 模板）。幣別與鏈別都不在 info：代收跟著支付通道、代付跟著提款銀行的三方代碼（`integration.md` 第 9 節「幣別與鏈別放哪」）。
- 建出的代收商戶入帳比例是資料檔的占位值 1、支付通道關聯的代收最低額是 0，都要在 3.8 改掉。

### 3.5 建完立刻改回提款銀行列

建代付商戶會把提款銀行列的固定手續費改成 0（見「上線會動到什麼」）。正式組的提款銀行列通常正在服務人工 USDT 提款，手續費 0 的期間建立的提款單都不收手續費，所以**建完正式組代付就立刻改回**，縮短空窗。建之前先把兩組提款銀行列的全部欄位留一份快照，改回時照快照核對。

- 手續費：改回 PM 定的值（TPower 1.5；Wintoto 原為 1.0，RB 決定改成 1.5 對齊 TPower，這是營運設定變更，要告知 PM）。
- 備註被清空、圖示換成 base 的：要不要改回看營運需要。
- 測試組提款銀行列的 status 被改成 1：測試組本來就要用，保持啟用。
- 空窗期間有沒有提款單：用唯讀 MCP 查該列在空窗時段的 `withdrawal_orders`。TPower 空窗 7 秒、Wintoto 約 1 分鐘，期間都是零筆。

### 3.6 讀回

```bash
php artisan cash-flow:channel-status 129
php artisan cash-flow:channel-status 130
```

看樹狀輸出：金流商戶與支付通道關聯的 id 與狀態，把四個金流商戶 id 與兩個支付通道關聯 id 記進附錄「各站已建的金流商戶」（後面填憑證、啟用、監控都用）。回調網址就是「回調網域 ＋ `/external/api/deposit/notify/{代收金流商戶 id}`」與「`/external/api/withdrawal/notify/{代付金流商戶 id}`」。

### 3.7 填憑證

- 後台的金流商戶編輯頁，對四個金流商戶各填 `APP_ID` 與 `APP_SECRET`（後台的確切選單路徑本檔**未驗證**，以既有金流商的做法為準）。沒有後台權限時，由本人以隱藏輸入的一次性命令寫入，值不經聊天、不進命令參數。
- 同一個 APP ID 的其他金流商戶用命令複製，值不離資料庫：

```bash
php artisan cash-flow:copy-credentials --from=<已填好的金流商戶 id> --to=<其餘 id，逗號分隔> --keys=APP_ID:APP_ID,APP_SECRET:[REDACTED] --dry-run
```

- **測試組用該站的正式憑證**（TPower、Wintoto 都這樣做，RB 拍板）。原本規劃測試組填金流商的測試商戶 GFFTEST，好處是取址不吃正式配額；改用正式憑證是為了讓測試組實測走的就是正式商戶的後台設定（白名單、配額），代價是測試組取的地址會占正式 APP ID 的配額。
- 讀回只看有沒有值與字元數（例如 `CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(info,'$.APP_ID')))`），不把值查出來。

### 3.8 限額、手續費、匯率與通道顯示

- 代付限額：提款銀行列既有的限額會保留，不必再設（TPower 100～10,000；Wintoto 100～30,000）。
- 代收最低額：在支付通道關聯（`agent_cash_flow_order_types.min_amount`）設定，PM 定為 5 USDT。**這個最低額擋不住小額**：鏈上轉帳沒辦法在轉入前攔，低於最低額的入款照樣入帳、只記 log（`DepositCallback::createDepositOrder()`）。不要改 `cash_flows.php` 的 info 限額，那會改到 base 層共用的銀行列。
- 固定手續費：見 3.5。
- 🔴 **匯率必須設對，否則存提不對等（資損風險）**：存款入帳的點數＝存入 USDT × 代收商戶的 `agent_cash_flows.rate`，再扣代收手續費（`OrderService::createOrder`）；提款扣的點數＝提領 USDT × 提款銀行列的 `banks.rate`（`WithdrawalController`）。兩者是不同來源，必須是同一個值。代付金流商戶的 `rate` 不參與計算。
  - **問題**：建商戶命令從 `cash_flows.php` 讀匯率，第 129 筆（代收）寫死 `'rate' => '1.00000'` 是占位值，不是真匯率。照它上線，存 1 USDT 只給 1 點，提 1 USDT 卻扣「提款銀行匯率」的點數；反過來入帳比例比提款匯率高，會員存了再提就能套利（例如 4.25 對 4.121，每來回一次多拿約 3%）。
  - **上線動作**：建完代收商戶後，把入帳比例設成**和同組提款銀行列 `rate` 相同的值**（RB 定案）。正式組與測試組各自對齊自己的提款銀行列，兩組的值可以不同。
  - **CCPayment 的入帳比例改了立刻對所有未到帳的轉帳生效**：永久地址沒有會員先建單這一步，存款單是回調時才建，匯率在那一刻寫進訂單。調匯率時同組的代收與提款銀行列要一起改。
  - 營運會成對調整既有 USDT 代收與提款匯率，CCPayment 的代收商戶要加入同一套調整流程（問 PM）。
  - Dev 存提匯率統一為 2（見 8.2）。
- 通道顯示（runbook 之前沒寫到的兩個缺口，交 PM 決定內容）：
  - `order_type_names` 不會被 sync 建立，不補的話會員看到的是資料檔的通道名「CCPayment USDT (TRC20)」。既有 USDT 通道的多語名稱是後台手建的。
  - 支付通道關聯的 `settings` 建成 `{}`，沒有儲值頁說明文字（`PopupText`，格式見 8.1 第 5 步）。
  - 支付通道父層的 `info` 維持 `{"data":[]}`（儲值頁不顯示金額欄位），不要改成 null。

### 3.9 營運方收款地址白名單（風險交易退款用）

風險交易的存款，營運在金流商後台退款時可以退回付款人，也可以退到營運方自己的地址。退到營運方地址等於平台收下這筆錢，之後由營運按手動上分。
排程 `ccpayment:sync-risky-refunds`（每 10 分鐘）只認白名單裡的地址，退到白名單以外的地址一律不放行手動上分、並告警。

- 白名單存在**代收**金流商戶的 `agent_cash_flows.info`，鍵名 `RISKY_REFUND_MERCHANT_ADDRESSES`，值是字串陣列，例如 `["TXxx...", "TYyy..."]`。
- 地址逐字比對（只去掉前後空白），大小寫要和鏈上地址完全一致。
- 沒設定時當成空清單：退回付款人照常處理，退到其他任何地址都只告警。
- 地址由誰提供、誰維護，待 PM 確認（target/tickets#4463「待確認」）。
- 讀回只看鍵在不在、有幾個地址：`JSON_LENGTH(JSON_EXTRACT(info, '$.RISKY_REFUND_MERCHANT_ADDRESSES'))`，不要查整欄 `info`（含憑證）。

## 4. 啟用順序與驗證（正式組）

### 4.1 代付先上（RB 2026-09-15 定案：代付不等 App，先上）

1. 找一筆已審核（狀態 3）的 USDT 提款單，確認看得到 CCPayment：`php artisan withdrawal:check-payout-channels <提款單 id>`。
2. 啟用代付金流商戶，同時恢復後台顯示：`php artisan cash-flow:channel-status 130 --acf=<代付金流商戶 id> --set-status=1 --backend-display=1`。
3. 小額實付一筆（會員負擔網路費時，到帳數量會少一筆網路費，客服要先知道）。
4. 看狀態走 21（已送出）→ 22（處理中）→ 24（金流商成功）；回調由 `WithdrawalCallback` 驗簽後以查單二次確認才定案。卡在 22 超過 2 小時，排程會請金流商重送回調並告警；卡在 21 由既有排程 `withdrawal:alert-stuck-submitted` 查證。

### 4.2 代收後上（閘門「App 會顯示清單帶的地址」過了才做）

1. 確認入帳比例已照 3.8 設成與提款銀行列同值。
2. 啟用代收支付通道關聯，同時恢復後台顯示：`php artisan cash-flow:channel-status 129 --acfot=<支付通道關聯 id> --set-status=1 --backend-display=1`。代收的開關在支付通道關聯這一層，代收金流商戶本身建立時就是啟用，不用動。先用 `php artisan cash-flow:channel-status 129` 讀樹狀圖，確認金流商戶是「上架」。
3. 用測試會員在 App 開儲值頁：存款方式清單對 USDT 通道帶回 `crypto_wallet_address`（清單自動取址）；`member_crypto_wallets` 多一列（`agent_cash_flow_order_type_id`＝該支付通道關聯 id、`status` 1）。清單沒帶地址就是取址失敗：看 log 的「Deposit address fetch on listing failed」與金流商業務碼，`12002` 是 IP 白名單、`12004` 是 notifyUrl 網域白名單、`15001` 是配額用完；失敗後同一支付通道冷卻一分鐘才會再試（已確認過地址的會員在 5 分鐘快取內不受冷卻影響）。
4. 小額轉入該地址；金流商發 DirectDeposit 回調 → 我方回查 → `orders` 新單狀態 1 → 餘額入帳；我方回應 HTTP 200、內容 `Success`。回調的判定矩陣（幣別不符、風險交易、處理中）見 `app/Services/Payment/Providers/CCPayment/DepositCallback.php` 的 docblock。
5. 手跑一次對帳排程看輸出：`php artisan ccpayment:resend-missing-webhooks --dry-run`（排程每 30 分鐘自動跑，只掃啟用中的金流商戶）。

## 5. 止血與回滾

| 情境 | 做法 | 要知道的副作用 |
|---|---|---|
| 停止新流量 | 先隱藏、再停用，兩個參數可以同一次帶：代收 `php artisan cash-flow:channel-status 129 --acfot=<支付通道關聯 id> --backend-display=0 --set-status=0`；代付 `php artisan cash-flow:channel-status 130 --acf=<代付金流商戶 id> --backend-display=0 --set-status=0` | 清單不再顯示、不再取址、不再發起代付，後台列表也隱藏。代收金流商戶維持啟用 |
| 停用期間的入款 | 不用另外處理 | 回調端只用金流商戶 id 反查、不看 status（`DepositNotifyController` 的 docblock），已發地址的入款仍會入帳。但對帳排程只掃啟用中的金流商戶，停用期間漏收的回調不會自動補送；恢復啟用後用 `--hours` 加大回看範圍手跑一次 |
| 代碼回滾 | **先照「停止新流量」停掉該站兩組的代收與代付，再回滾** | 舊代碼沒有 CCPayment 的 Driver、也沒有 `create-order` 的擋門，金流商戶還啟用時會員點選會走建單流程並失敗 |
| 風險地址回調 | 代碼自動：該列停用、立刻解綁、告警 | 不自動通知會員（推播失敗率太高），營運視情況用後台站內信手動發；會員下次開儲值頁會換發新地址 |
| 配額用完 | 聯繫金流商客服擴容 | 金流商回 `15001 PaymentAddressNumTooMuch`，還沒有地址的會員拿不到地址；已有地址的不受影響 |
| 金流商 Webhook IP 變動 | 更新 ingress／nginx 名單 | 有限制來源時，回調會整批被擋，表現是排程一直重送仍缺 |

## 6. 上線後監控（至少第一週每天看）

- 告警（BotNotify）：`referenceId` 反解失敗、代幣或鏈別不符、風險交易建單為狀態 4、風險地址、排程重送後仍缺的存款與卡 22 的提款單。
- 排程輸出：`ccpayment:resend-missing-webhooks` 每 30 分鐘一次，輸出進 log。
- 地址用量（唯讀）：`SELECT COUNT(*) FROM member_crypto_wallets WHERE agent_cash_flow_order_type_id IN (<代收支付通道關聯 id>)`；同一個 APP ID 的正式組與測試組要加總；用到 800 就申請擴容（門檻自訂）。
- 存款單的 `ip` 欄位是金流商回調的來源 IP，不是會員 IP；風控報表若看 ip 要知道。

## 7. 收尾

- 依 `docs/金流接入流程/4-上線.md`：文件歸檔、帳密歸檔、通知 PM。
- 實測結果回填：`open-questions.md` 第 21、22 題；`integration.md`「我方實作決策」一節的「回應 Content-Type」列。
- 各站做完後回填附錄「各站已建的金流商戶」。
- push 前刪除 `docs/plans/2026-09-15-ccpayment-permanent-address/`（設計與計畫落實即刪）。

## 8. Dev 測試網 QA 驗收環境（2026-09-16 起，RB 決定保持、不還原）

這一節寫 Dev 現在的設定和 QA 怎麼測。Dev 走的是 CCPayment 的 ETH Sepolia 測試網，測試幣叫 TETH，不花真錢；正式環境用 USDT-TRC20，值不同，上線時照第 3 節配置，不要把本節的值搬過去。

2026-09-16 起代收的幣與鏈由支付通道決定（`integration.md` 第 9 節「幣別與鏈別放哪」），測試網不再靠改商戶 info 的 `CHAIN`（該鍵已移除），而是自己一條支付通道「CCPayment TETH (Sepolia Testnet)」（`CCPAYMENT.TETH_ETH_SEPOLIA`：幣別代號 TETH、三方鏈代號 ETH_SEPOLIA，coinId 執行期向金流商 `getCoinList` 查）；資料檔只在非正式庫連線宣告它，正式庫不會有。同日傍晚 key 由「代碼對照表」改成「幣別代號＋三方鏈代號」，Dev 上舊 key 的兩條通道（5907、5909）用 sync 的 `--rename-key` 就地改鍵、關聯與地址都保留，步驟見 8.1。

### 8.1 部署本次改動後的一次性動作（Dev，依序）

1. 這步把舊 key 的兩條支付通道父層就地改鍵（`order_types` 列與 id 不變，關聯 1242／1244 與已取的地址自動跟上）。不要改完資料檔就直接 `cash-flow:sync`：那會把舊 key 的列下架、另建兩條新列，已取的地址就失聯了（`ChannelKey` docblock 的硬規則）：

   ```bash
   php artisan cash-flow:sync --ids=129 --rename-key=CCPAYMENT.USDT_TRC20=CCPAYMENT.USDT_TRX --rename-key=CCPAYMENT.TETH_SEPOLIA=CCPAYMENT.TETH_ETH_SEPOLIA --dry-run
   php artisan cash-flow:sync --ids=129 --rename-key=CCPAYMENT.USDT_TRC20=CCPAYMENT.USDT_TRX --rename-key=CCPAYMENT.TETH_SEPOLIA=CCPAYMENT.TETH_ETH_SEPOLIA
   ```

   預期：就地改鍵 2 筆（5907 → `CCPAYMENT.USDT_TRX`、5909 → `CCPAYMENT.TETH_ETH_SEPOLIA`），新增 0、下架 0。（5909 與關聯 1244 是 2026-09-16 下午照舊版本節建的：當時 sync 新增父層 1、建商戶命令補關聯 1；改鍵後繼續沿用。）

2. 關聯不必再建：商戶 7672 底下的 1242（5907，USDT_TRX）與 1244（5909，TETH_ETH_SEPOLIA）都在。只在需要確認時乾跑建商戶命令，預期商戶 0 筆新增、關聯統計 2 筆但資料表不多列（顯示要新增商戶或關聯就停下查原因）：

   ```bash
   php artisan agent:add-agent-cash-flow --agent=4249 --cashflow=129 --currency=USDT --mode=single --dry-run
   ```

   要在別的非正式庫從零建時才實跑（加 `--no-interaction`）：走「更新現有」路徑，info 只補缺不覆寫，憑證不會被洗掉；單筆模式會用資料檔的限額寫關聯的 `min_amount`／`max_amount`。

3. 這步清掉商戶 info 裡已無人讀取的 `CHAIN` 鍵，並把代收商戶的 `COIN_ID` 改回正式值（代收已不讀它，改回只是避免誤導）；代付商戶 7673 的 `COIN_ID` 留 1891，代付測試網要用（RB 在 Dev 庫執行；已於 2026-09-16 跑完。`COIN_ID` 後來整個移除，見第 6 步）：

   ```sql
   START TRANSACTION;
   UPDATE agent_cash_flows SET info = JSON_SET(JSON_REMOVE(info, '$.CHAIN'), '$.COIN_ID', '1280'), updated_at = NOW()
   WHERE id = 7672 AND JSON_CONTAINS_PATH(info, 'one', '$.CHAIN');
   UPDATE agent_cash_flows SET info = JSON_REMOVE(info, '$.CHAIN'), updated_at = NOW()
   WHERE id = 7673 AND JSON_CONTAINS_PATH(info, 'one', '$.CHAIN');
   UPDATE member_crypto_wallets SET status = 0, updated_at = NOW() WHERE id = 82 AND status = 1;
   UPDATE member_crypto_wallets SET status = 1, updated_at = NOW() WHERE id = 81 AND status = 0;
   COMMIT;
   ```

   列 82 是靠改商戶 `CHAIN` 那個年代取的 0x 地址，卻綁在關聯 1242（USDT_TRX）上：不停用的話，清單會把它當「CCPayment USDT (TRC20)」的地址顯示；Sepolia 的款打到它，回調會被判鏈別不符只告警不入帳。列 81 是 TRX 地址，改回啟用給 TRC20 通道用。

4. 會員 48897 重新開儲值頁：存款方式清單會替關聯 1244（還沒有地址）向金流商取址（`MemberCryptoWalletService::walletsForChannels`，金流商配額加一），選 USDT →「CCPayment TETH (Sepolia Testnet)」看到新的 0x 地址、「CCPayment USDT (TRC20)」看到列 81 的 T 開頭地址。之後 QA 都打測試網通道那個 0x 地址。舊前台不會在點選時呼叫 `deposit/crypto-address`，所以清單沒帶地址時畫面會顯示一般金額表單、送出被 `create-order` 擋下（「Payment channel does not accept deposit orders」），那代表清單取址失敗，查 log 的「Deposit address fetch on listing failed」（常見原因：GFFTEST 後台沒啟用該幣或鏈，金流商 `getCoinList` 就不會回它）。

5. 儲值頁不顯示金額欄位、改顯示說明文字（2026-09-16 RB 決定先在 Dev 用 SQL 直改驗前端，代碼側是資料檔的 `amount_field => false`，見 `integration.md` 第 9 節「儲值頁的金額欄位與說明文字」）：`order_types` 5907／5909 的 `info` 改成 `{"data":[]}`（與部署後 sync 組出的值相同，之後重跑 sync 不會再改它）；關聯 1242／1244 的 `settings` 設 `PopupText`（鍵 `0` 預設、`1` 英文、`2` 繁中；必須是以語言 id 為鍵的 JSON 物件，不是陣列）。清單快取 1 分鐘，改完等一下再開儲值頁。2026-09-16 實測：金額欄位消失、`PopupText` 是進入儲值畫面時的彈窗；說明文字怎麼呈現交 PM 規劃（`integration.md` 第 9 節「儲值頁的金額欄位與說明文字」）。目前 Dev 的 5907／5909 `info` 由 RB 清成 NULL，部署本分支後重跑 `cash-flow:sync --ids=129` 會寫成 `{"data":[]}`。

6. 這步配合 2026-09-17 的改動：代付的幣與鏈改由提款銀行的三方代碼決定，商戶 info 不再放 `COIN_ID`。**必須和含這個改動的代碼一起上**：先改資料、代碼還是舊的，舊代碼會把 `TETH_ETH_SEPOLIA` 整串當鏈代號送出去，被金流商拒絕；先上代碼、資料還是舊的，新代碼拆不出幣別，代付在送出前就失敗（訂單可重送，不會卡住）。兩種都不會錯付，但中間那段時間 Dev 出不了款，所以部署後立刻跑（在 Dev 庫執行）：

   ```sql
   START TRANSACTION;
   UPDATE agent_cash_flows
   SET info = JSON_REMOVE(JSON_SET(info, '$.available_banks[0].BankCode_Third', 'TETH_ETH_SEPOLIA'), '$.COIN_ID'), updated_at = NOW()
   WHERE id = 7673 AND cash_flow_id = 130
     AND JSON_LENGTH(info, '$.available_banks') = 1
     AND JSON_UNQUOTE(JSON_EXTRACT(info, '$.available_banks[0].BankCode_ELG')) = 'TRC20'
     AND JSON_UNQUOTE(JSON_EXTRACT(info, '$.available_banks[0].BankCode_Third')) IN ('ETH_SEPOLIA', 'USDT_TRX');
   UPDATE agent_cash_flows SET info = JSON_REMOVE(info, '$.COIN_ID'), updated_at = NOW()
   WHERE id = 7672 AND cash_flow_id = 129 AND JSON_CONTAINS_PATH(info, 'one', '$.COIN_ID');
   UPDATE cash_flow_banks SET provider_bank_code = 'TETH_ETH_SEPOLIA', updated_at = NOW()
   WHERE id = 4739 AND cash_flow_id = 130 AND bank_code = 'TRC20' AND provider_bank_code IN ('ETH_SEPOLIA', 'USDT_TRX');

   -- COMMIT 之前先核對：下面這句必須回 1 列、兩個值都是 TETH_ETH_SEPOLIA、coin_id 是 NULL。不是就 ROLLBACK，不要 COMMIT
   SELECT JSON_UNQUOTE(JSON_EXTRACT(example.invalid, '$.available_banks[0].BankCode_Third')) AS json_code,
          JSON_EXTRACT(example.invalid, '$.COIN_ID') AS coin_id,
          cfb.provider_bank_code AS cfb_code
   FROM agent_cash_flows acf JOIN cash_flow_banks cfb ON cfb.id = 4739
   WHERE acf.id = 7673;

   COMMIT;
   ```

   各句影響幾列不固定，判準是 COMMIT 前那句核對，不是影響列數：第一次跑，7673 與 4739 各影響 1 列，7672 影響 1 列；重跑時已經是目標值的那幾句會影響 0 列，這是正常的。守衛條件的用意：7673 的 `available_banks` 必須恰好一筆、而且就是 TRC20 那家，才用 `[0]` 定位（多一筆或換了銀行就一列都不改，核對那句會看到舊值，此時 ROLLBACK 查原因）；三方代碼同時接受 `ETH_SEPOLIA`（部署前的舊值）與 `USDT_TRX`（被 sync 或建商戶命令洗成正式值之後），所以同一段 SQL 既是首次遷移也是事後恢復。驗證：Dev 發一筆代付，log 的 `applyAppWithdrawToNetwork` 請求帶 `coinId` 1891、`chain` `ETH_SEPOLIA`。

   什麼時候要重跑：在 Dev 重跑 `cash-flow:sync --ids=130`，會把 4739 的三方代碼改回資料檔的 `USDT_TRX`（sync 對既有列「無條件更新 provider_bank_code」，`SyncCashFlowsCommand` 的金流商銀行更新段）。Dev 代付現在讀的是商戶 info 的 `available_banks`（sync 不動它），所以出款當下不受影響；但之後若再跑建商戶命令重建 `available_banks`，它會照 4739 的值寫，測試網設定就被洗成正式值。發生時重跑上面整段即可。

### 8.2 Dev 現值對正式值

| 項目 | Dev 現值 | 正式環境該是 |
|---|---|---|
| GFFTEST 後台 | ETH test network 開啟；TETH 已加進啟用代幣；儲值 Webhook 網址填 7672 的回調網址 | 測試網關閉 |
| 代收支付通道（商戶 7672） | 關聯 1242「CCPayment USDT (TRC20)」（5907，`CCPAYMENT.USDT_TRX`）＋ 1244「CCPayment TETH (Sepolia Testnet)」（5909，`CCPAYMENT.TETH_ETH_SEPOLIA`），key 於 2026-09-16 就地改鍵 | 只有 TRC20 一條 |
| 代收商戶 7672 info | 無 `CHAIN`；`COIN_ID` 已無人讀取，8.1 第 6 步移除 | 只有憑證與 `MERCHANT_PAY_NETWORK_FEE` |
| USDT 存提匯率：代收商戶 7672 的 `agent_cash_flows.rate` 與群組 3 提款銀行列 banks 50 的 `rate` | 兩側皆 2（2026-09-17：代收原為資料檔占位值 1，QA 在後台改成 2；提款原為 4.15，RB 拍板統一後改成 2。存提對等） | 營運核定的 USDT 對主幣匯率，兩側同值；資料檔占位值 1 不可照搬（見 3.8） |
| 代付商戶 7673 info | 無 `CHAIN`、無 `COIN_ID`、`available_banks` TRC20→`TETH_ETH_SEPOLIA`（8.1 第 6 步改的；之前是 `COIN_ID=1891` 加 TRC20→`ETH_SEPOLIA`） | TRC20→`USDT_TRX` |
| cash_flow_banks 4739（cash_flow 130、TRC20） | `provider_bank_code=TETH_ETH_SEPOLIA`（8.1 第 6 步改的） | `USDT_TRX` |
| 群組 3 自助洗分（agent_cash_flows 6） | `MinAmount=0.01` | 原值 100 |
| 群組 3 的 FonPay 代收商戶（agent_cash_flows 62，USDT-ERC20／TRC20／BNB 三條通道） | 2026-09-16 以 `cash-flow:channel-status 35 --acf=62 --set-status=0` 停用（RB 決定，讓 USDT 下拉只剩 CCPayment 兩條，避免 FonPay 通道 `info` 為 NULL 干擾前端欄位判讀） | 與 CCPayment 無關，正式庫不動 |
| 會員 48897 的收款地址 | 列 81（TRX 地址，關聯 1242）啟用、列 82（舊 0x 地址，關聯 1242）停用、關聯 1244 的新列啟用（8.1 第 4 步開儲值頁時取的） | 只有 TRC20 通道的地址 |

### 8.3 QA 怎麼測

1. 代收：會員在儲值頁選 USDT →「CCPayment TETH (Sepolia Testnet)」，畫面給的是 0x 地址（測試網）。用水龍頭把 Sepolia ETH 打到該地址（最低 0.001 就會記錄）。到帳後若 Dev 沒建單，到 GFFTEST 後台對那筆按「發送 Webhook」（TETH 加進啟用代幣後應會自動送達，尚未再驗）。
2. 代付：會員先綁一個「USDT(TRC20)」提款帳戶，帳號填自己的 EVM 錢包地址（MetaMask 的 0x 地址，不能填我方收款地址）。提款金額最少 **0.01 USDT**（Dev 群組 3 自助洗分調低值，見 8.2；banks 50 的 `min_amount` 是 0，實際下限走代理／群組取嚴，`WithdrawalService::checkWithdrawalAmountLimit`）。扣的點數＝提款 USDT × 2（提款銀行匯率），餘額來自代收存款換得的點數（2026-09-17 起存提匯率統一為 2，見 8.2）。代理後台放款後訂單約 20 秒轉 24。
3. 補送排程 `ccpayment:resend-missing-webhooks` 每 30 分鐘一輪：測試網通道的 coinId 會被存款清單 API 拒絕（11000），排程只跳過那條通道並記一筆 `Log::error`，TRC20 通道照常對帳，命令不再整個失敗。

### 8.4 不還原

Dev 是測試環境，長期維持測試網設定，不還原成正式值（RB 2026-09-16 定案）。8.2 的「正式環境該是」欄只是提醒上線時別把 Dev 的值搬過去。真要暫停測試網時，把商戶 7672 與測試網通道的關聯（`agent_cash_flow_order_types`）status 改 0 就好，`order_types` 那列留著（非正式庫連線的資料檔會一直宣告它）；`cash-flow:sync` 不會把被停用的關聯或通道的 status 寫回 1，要再用就手動改回 1。

## 附：各站已建的金流商戶

TPower 與 Wintoto 於 2026-09-23、Yes2Win 於 2026-09-29 以唯讀 MCP 讀回。「入帳比例」是代收金流商戶的 `rate`，「提款匯率」是同組提款銀行列的 `rate`。兩個值都由 PM 向代理商取得，可以不同值，例如 Yes2Win 正式組。

| 站 | 組 | 代收商戶（支付通道關聯） | 代付商戶 | 狀態 | 入帳比例／提款匯率 | 支付通道父層 | 金流商銀行 |
|---|---|---|---|---|---|---|---|
| TPower | 688 測試 | 446（1044） | 447 | 啟用 | 4.176／4.176（771） | 697 | 2615 |
| TPower | 674 正式 | 450（1046） | 451 | 停用 | 4.121／4.121（485） | 697 | 2615 |
| Wintoto | 93 測試 | 381（845） | 382 | 啟用 | 4.25／4.25（206） | 635 | 1222 |
| Wintoto | 70 正式 | 383（846） | 384 | 停用 | 4.121／4.121（205） | 635 | 1222 |
| Yes2Win | 12 測試 | 300（937） | 301 | 啟用 | 4.176／4.176（587） | 650 | 2157 |
| Yes2Win | 7 正式 | 304（939） | 305 | 停用 | 4.107／4.129（449） | 650 | 2157 |

- 已建的六個代收支付通道關聯最低額都是 5、最高額 0（不限）；六個提款銀行列手續費都是 1.5（固定）。
- 「停用」照營運的關閉流程：代收看支付通道關聯的 status，代付看金流商戶的 status，兩者的 `backend_display` 都是 0。Yes2Win 正式組的代收金流商戶 304 本身是啟用，擋住會員的是支付通道關聯 939。
- 憑證：TPower 四筆同一組 TPower 正式憑證、Wintoto 四筆同一組 Wintoto 正式憑證（只查字元數，未查值）。Yes2Win 四筆也是同一組 Yes2Win 正式憑證，此項未重新讀回核對。
- Yes2Win 配置前的 2026-09-23 盤點（唯讀，下列改碼與改名已於同日執行，現值見上表與站點對照表）：129／130 在且啟用，CCPayment 的金流商戶、金流商銀行、支付通道父層都是 0 筆。提款銀行列 449（組 7，啟用，4.121，手續費 1.5，100～10,000）與 587（組 12，**停用**，4.176，手續費 1.5，100～10,000）的 code 都是 `TPCC01`、不是 `USDT0001`；base 層 1338 的名稱是「USDT(TRC20)」、不是標準名。兩件都要照 3.1 先改碼、再改名，最後才同步。449 的備註寫「2026-02-13 棄用」，但近 90 天仍有 139 筆提款單（最新 2026-09-19），是服務中的提款銀行，3.5 的空窗要照正式組處理。既有 USDT 代收入帳比例：組 7 為 4.099，組 12 為 4.25／3.949。

## 附：TPower 上線前的 USDT 現況（2026-09-15 唯讀查詢正式庫）

- 代收：PayEssence（金流商 81，金流商戶 306／301）與 AllPay 聚合殼（金流商 3，金流商戶 117／180）。
- 代付：**沒有任何 USDT 代付金流商戶**（`agent_cash_flows` 沒有 `cash_flow_type=3` 且 `currency_id=10` 的列）。近 90 天綁 USDT 提款銀行列（485／771）的提款單 346 筆全部沒有金流商單號：274 筆已轉帳（狀態 4）、14 筆失敗（2）、57 筆退款（9）、1 筆待審（1）。也就是說 USDT 提款目前全靠人工轉帳，CCPayment 代付上線後才有自動出款可選。
- 485／771 已於 2026-09-15 由銀行收斂作業就地改碼 TPCC01 → USDT0001。

## 附：本檔的驗證依據

| 斷言 | 依據 |
|---|---|
| TPower 沒有 USDT 代付金流商戶；近 90 天 485／771 提款單狀態分布 | `mysql-tpower` 唯讀查詢 `agent_cash_flows`、`withdrawal_orders` |
| 上線命令對提款銀行列的覆寫範圍 | `AgentBankSyncService::syncToAgentGroup()`；TPower 與 Wintoto 實跑後讀回 |
| 同步以 base 層比對出的標準名，照「所有組別裡 id 最小的同名啟用列」改 base 層的 code | `StandardBankNameHelper::matchBankName()`（只比對 base 層啟用列）、`SyncCashFlowsCommand::ensurePlatformBank()` 與 `syncBankCode()`；Wintoto 同步前發現；Yes2Win 改碼前同步乾跑確認 base 層舊名時不觸發 |
| Yes2Win 改碼、改名已完成 | 2026-09-23 執行 `bank:recode-bank`（449／587）與 `bank:set-name`（1338／1339）；`mysql-yes2win` 讀回 449／587 的 code 為 `USDT0001`，1338／1339 名稱為「USDT - TRC20」「USDT - ERC20」 |
| 資料檔銀行名須與 base 層逐字相同 | `app/Console/Commands/CashFlow/Data/providers/CCPayment.php` 代付 `banks` 的註解 |
| `merge-base-*` 只適用 VWorld 資料庫 | TPower 乾跑整批攔截（71／201 項不符）；命令內的配對表 |
| 建商戶命令用代理的 `cash_flow_group_id` 找支付組別 | `AddAgentCashFlow` 的 `resolveAgent()` 與其後寫入的 `agent_group_id` |
| 入帳比例與提款匯率的算法 | `OrderService::createOrder()`；`WithdrawalController` 建提款單的 `$point` |
| CCPayment 存款單在回調時建立 | `DepositCallback::createDepositOrder()` 呼叫 `OrderService::createOrder()`；`Deposit.php` 擋會員建單 |
| 部分同步不沖掉 PayPro 補丁 | `SyncCashFlowsCommand::disableAllCashFlowBanks()` |
| 停用金流商戶不擋回調 | `DepositNotifyController` 的 docblock 與 `__invoke()` |
| 取址端點要求金流商、金流商戶、支付通道關聯三者皆啟用 | `DepositService::getOrderType()` |
| FonPay 影響面 | 六個白標的 `agent_cash_flows`、VWorld 的 `orders` 近 90 天 |
| credentials 模板寫進 info | `tests/Feature/Console/Commands/CashFlow/AddAgentCashFlowCcpaymentCredentialsTest.php` |
| 各站回調網域 | 各站 `withdrawal_payout_records.payout_order_request_body` 的 notify 網址（Yes2Win 近 30 天 32,492 筆皆為同一網域） |
| 各站已建的金流商戶與現值 | `mysql-tpower`、`mysql-vworld`、`mysql-yes2win` 唯讀讀回 |
