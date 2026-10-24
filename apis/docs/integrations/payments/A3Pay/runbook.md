# A3Pay 上線 runbook（SGD 代收與代付，VWorld-SG）

> 工單 `target/tickets#4394`。本檔只寫 A3Pay 專屬的步驟與依據；金流商上線的通用流程（連正式庫、文件與帳密歸檔、通知 PM）見 `docs/金流接入流程/4-上線.md`。
> 每一步都寫「做什麼、為什麼、怎麼確認」。憑證值（MerchantCode、AES key、商戶私鑰）不寫進任何檔案、命令參數或訊息，只在後台填入。

## 名詞

| 名詞 | 意思 |
|---|---|
| 金流商戶 | `agent_cash_flows` 一列。A3Pay 每組各有代收（金流商 131 `D_A3PAY`）與代付（金流商 132 `W_A3PAY`）兩列 |
| 支付組別 | `agent_group_id`。VWSG 正式組與測試組是孿生，設定要成對做 |
| Payout Verification | 三方**放款前**打我方 `external/api/withdrawal/query/{金流商戶 id}` 確認單據，我方回 `success` 才出款（`A3Pay\WithdrawalQuery`） |

## 站點對照

| 項目 | VWorld-SG |
|---|---|
| 容器連線名（`DB_CONNECTION`） | `VWorld`（VWSG 與 VWorld、WTT 共用一套資料庫，查詢一律以 `rootAgentId` 切範疇） |
| 主庫埠號的環境變數 | `VW_DB_PORT_WRITE` |
| 唯讀查詢（mysql MCP） | `mysql-vworld` |
| 正式組（代理） | `cash_flow_group_id` 19（`vworldsg`，agents.id 49726） |
| 測試組（代理） | `cash_flow_group_id` 99（`VWSTEST`，agents.id 53614） |
| 幣別 | SGD，`currency_id` 2 |
| 回調網域 | 查該站既有代付請求帶的 notify 網址：`withdrawal_payout_records.payout_order_request_body`，限定 VWSG 支付組別的金流商戶 |
| A3Pay 回調來源 IP | `0.0.0.0`（api.md「A3pay Outgoing IP」） |
| 我方出口 IP（已交 A3Pay 加白名單，工單描述） | 0.0.0.0／86／88、0.0.0.0、0.0.0.0、0.0.0.0、0.0.0.0 |

## 0. 閘門：缺一項就不啟用

| # | 閘門 | 怎麼確認 | 為什麼 |
|---|---|---|---|
| 1 | 代碼已合入 master、發版並部署到 VWorld | 該版 tag 的 `release-prod` 與部署 pipeline 都成功 | 沒部署，`cash-flow:sync` 讀不到 131／132 的配置 |
| 2 | `open-questions.md` Q1、Q2 結案或由 PM 書面接受風險 | 廠商回覆貼進 open-questions.md | Q1 決定回調金額不符會不會每筆變人工單；Q2 決定代付「HTTP 4xx＝未建單」這個判定能不能維持，判錯是重複出款 |
| 3 | 後台群組層限額已設 | 代收：正式組與測試組 OB／BQR 支付通道 min/max；代付：20 家 SGD 提款銀行列 min/max | `cash_flows.php` 刻意不設 info 限額，程式沒有任何金額上下限兜底 |
| 4 | A3Pay BO 已登錄商戶公鑰、我方七個出口 IP 已在白名單 | BO（工單描述有網址與帳號）逐項打勾；小額實測代收建單成功 | 公鑰沒登錄所有請求驗簽失敗；IP 不在名單請求被拒 |
| 5 | 我方 ingress／nginx 若限制 External 來源 IP，已放行 `0.0.0.0` | 詢問維運；小額實測有收到代收回調 | 回調與 Payout Verification 都從這個 IP 來，擋掉就永遠收不到結果 |
| 6a | 憑證填完先驗公鑰不是我方自己的 | 存的 `RSA_PUBLIC_KEY` 必須是 A3Pay 的（1024 位、`MIGf` 開頭），不是 `public-gff.pem`／`public-vworld.pem`（2048 位、`MIIBIjAN` 開頭）；A3Pay 回調只重送 10 次約 50 秒，填錯時第一筆回調就永久遺失、要人工補單 | dev 2026-09-24 實際填錯一次，取消回調全數驗簽失敗後永久遺失 |
| 6 | 會員實際綁定的提款銀行碼都在 A3Pay 對照清單內 | 查近期 SGD 提款單的 `bank_code` 分布，每個碼都要在代付商戶 `available_banks` 的 `BankCode_ELG` 內（2026-09-24 查 VWorld 近 2 萬筆：VWSG 只用 `OCBCSG`、`VWSG009`，皆在清單） | 同一家銀行在組別內常有多代舊碼並存（dev 組別 3 的 DBS 就有 FRSG01／TPSG005／SGD0005／SGD0074 四列），會員綁到舊碼列時代付選不到 A3Pay，只會被「不支援銀行」排除，不會報錯 |
| 7 | 端到端實測通過（測試組） | 代收 OB 一筆、BQR 一筆入帳；代付一筆：確認 log 有 Payout Verification 進來且回 success、隨後收到 Status 4 回調轉 24 | Payout Verification 是放款前唯一閘門，沒實測到就不知道 A3Pay 有沒有真的打 |

## 1. 上線會動到什麼

| 資料 | 變化 | 依據 |
|---|---|---|
| base 層（`agent_group_id=0`）20 家 SGD 銀行列 | `cash-flow:sync` 只新增 `cash_flow_banks` 關聯，不新建銀行：資料檔銀行名全部對齊 base 層既有名稱（2026-09-22 查證 20 家皆存在且啟用） | `providers/A3Pay.php` 註解、`SyncCashFlowsCommand::findPlatformBankByName()` |
| 支付組別提款銀行列 | `agent:add-agent-cash-flow` 建代付商戶時以 base 層為模板同步到該組；既有列會被覆寫名稱／圖示／status | `AgentBankSyncService::syncToAgentGroup()`，同 CCPayment runbook 第 1 節 |
| 支付通道 | 新增父層 `order_types` 兩筆：`OB`（Online banking）、`BQR`（QR code） | `providers/A3Pay.php` deposit.channels |

## 2. 正式庫配置（全部在容器內執行，連主庫）

連線與防呆同 CCPayment runbook 第 3.0 節：`dbc list` 查 VWorld 主庫埠號、`-e DB_CONNECTION=VWorld -e VW_DB_PORT_WRITE=<埠號>` 注入、乾跑也要連主庫（從庫會在第一個寫入噴 `1290`／`1142`）、動手前先用唯讀 MCP 記下 131／132 的 `agent_cash_flows`／`cash_flow_banks` 列數當身分核對。

```bash
# 2.1 同步金流商配置（預期：OrderType 新增 2、CashFlowBank 新增 20、Bank 新增 0 更新 0；多出任何一筆停下查）
php artisan cash-flow:sync --ids=131,132 --dry-run
php artisan cash-flow:sync --ids=131,132

# 2.2 測試組先建（啟用），供測試人員驗收；代收用拆分模式，一個支付方式一筆商戶（OB、BQR 各一筆）
php artisan agent:add-agent-cash-flow --agent=53614 --cashflow=131 --currency=2 --mode=split --dry-run
php artisan agent:add-agent-cash-flow --agent=53614 --cashflow=131 --currency=2 --mode=split
php artisan agent:add-agent-cash-flow --agent=53614 --cashflow=132 --currency=2

# 2.3 正式組後建，帶 --status=0 停用（代收停在支付通道關聯 ACFOT、代付停在商戶，後台開關就是這兩處），驗收通過再開
php artisan agent:add-agent-cash-flow --agent=49726 --cashflow=131 --currency=2 --mode=split --status=0
php artisan agent:add-agent-cash-flow --agent=49726 --cashflow=132 --currency=2 --status=0
```

- 乾跑的統計表只計新增／更新／略過，**不計 `syncBankCode()` 的改碼**：明細若出現 `代碼: X => Y` 就是 base 層銀行要被改碼，
  統計表仍會顯示 Bank 0/0。2026-09-30 VWorld 乾跑抓到 UOB 876 `SGD0001 => UOBSG`、Wise 888 `SGD0013 => WISESG`
  （同名候選列沒限定 agent_group_id，被 group 116／93 的碼拖走），已修（`fix/sync-bank-code-same-group`）；跑正式前仍要 `grep '代碼:'` 確認為 0 行。
- 代收一律 `--mode=split`（2026-09-24 拍板：依支付方式拆成兩筆商戶，OB 與 BQR 各一筆）。資料檔沒設 channel `cost`，
  不帶 `--mode` 時非互動會落到單筆模式；已建成單筆的組別用 `--mode=split --migrate-single` 就地遷移
  （dev 實測：原列改名成 OB、新建一列給 BQR、ACFOT 跟著搬、憑證與限額保留，但 **cost 會被重設為 0**，遷移後要回後台補；
  **歷史訂單的 `orders.agent_cash_flow_id` 不會跟著改**，拆分前建的 BQR 訂單仍指舊列，影響以商戶維度的成本統計，
  遷移後要依 `order_type_id` 把它們改指到對應的新商戶）。
- 限額命令會自己帶：代收 ACFOT `min/max` 10／5000 來自資料檔 channel，代付商戶 `info.min_amount／max_amount` 50／5000
  來自資料檔 withdrawal channel（VWorld 2026-09-30 實測三筆商戶建完即正確，不必再跑 `fix:agent-cash-flow-order-type-limits`）。
- 手續費（`agent_cash_flows.cost`，費率）三筆都要在後台填：代收 OB／BQR 各 0.023、代付 0.013。
- 建完在後台填憑證：`API_URL`＝`https://example.invalid`、`MERCHANT_CODE`、`AES_KEY`、`RSA_PRIVATE_KEY`（商戶私鑰 base64 主體，不含 PEM 頭尾）、`RSA_PUBLIC_KEY`（A3Pay 公鑰 base64 主體）、`CURRENCY`＝`SGD`。四個商戶（兩組 × 代收代付）都要填。

### 2.4 VWorld 2026-09-30 實際配置結果

| 項目 | 值 |
|---|---|
| 父層 OrderType | 646 `OB`（A3Pay - Online banking）、647 `BQR`（A3Pay - QR code） |
| 測試組 53614（group 99）商戶 | 390 OB、391 BQR、392 代付，皆啟用；ACFOT 849／850 限額 10–5000 |
| 正式組 49726（group 19）商戶 | 393 OB、394 BQR（商戶 status=1、ACFOT 851／852 status=0）、395 代付（status=0） |
| 群組 19 DBS 收斂 | 舊碼列 598 `VWSG009`（13 筆會員綁定、36 筆提款單）以 `bank:rename-bank --agent-group=19 --from=598 --to=1574 --execute` 收斂到 add 命令長出的 1574 `DBSSG`；GMPay 239、EasyPay 241、B3Wallet 371 的 `available_banks` 同步改成 `DBSSG`。Tim 拍板「全站統一，收斂」而非讓 A3Pay 遷就舊碼 |
| 群組 99 CIMB 收斂 | add 命令長出 1573 `CIMBSG`；舊列 616 `SGD0002`（2 筆綁定、3 筆提款單）以 rename-bank 收斂到 1573。preflight 先被同碼 type 1 的孤兒列 1057（零引用）擋下，軟刪 1057 後通過 |
| 群組 19 OCBC 收斂 | 舊列 603 `VWSG001`（type 1、停用、3 筆綁定、10 筆終態提款單）收斂到 1096 `OCBCSG`。rename-bank 不做跨 type 收斂，先把 603 的 type 改成 4 再跑 |
| 閘門 6 殘留 | 群組 19：Indian Overseas Bank 1 筆、群組 99：Sing Investments 1 筆，皆為 A3Pay 不支援的銀行，不處理 |

`bank:rename-bank` 的 dry-run 報表只列 `cash_flow_banks` 改指與阻斷，**不列會員綁定、提款單、`available_banks` 的改寫量**；執行前自己用
SELECT 存查那三張表（本次存於 session scratchpad `g19-dbs-snapshot-before.txt`），執行後逐項複驗。

preflight 的「同碼另有不同 type 的列」與「變體 type 與 survivor 不符」兩道閘，擋的是早期主幣正規化前留下的 type 1（BANK）舊列。
舊列零引用時直接軟刪；舊列還有綁定時把它的 type 改成 survivor 的 type 再跑命令，讓改指與審計交給命令，不要手工改綁定表。

## 3. 驗收與啟用順序

1. 測試組代收：OB、BQR 各一筆小額，確認導向收銀台、回調 Status 6 入帳；故意重送同一回調確認冪等（訂單狀態不變、仍回 success）。
2. 測試組代付：一筆小額。log 依序應有 `payout verification passed` → 回調 Status 4 → 訂單 24。若 Verification 沒進來，先查閘門 5。
3. 測試組代付拒絕路徑：用不存在的收款帳號送一筆，觀察是 HTTP 4xx＋isSuccess=false（訂單轉 29，符合 Q2 假設）還是別的形狀；別的形狀就回頭改 `A3Pay\Withdrawal` 的分類並更新 open-questions Q2。
4. 通過後把正式組四個商戶 `status` 改 1，通知 PM。

## 4. 止血與回滾

| 情境 | 動作 |
|---|---|
| 代付出現重複出款疑慮 | 先把正式組代付商戶（132）`status=0`，再查 `withdrawal_payout_records` 同一訂單是否有多筆 SUCCESS |
| 回調金額全部不符（Q1 落在實付金額口徑） | 訂單會全部變 `WAITING_MANUAL_DEPOSIT` 並告警，不會誤上分；停用代收商戶、等廠商說明再改 `DepositCallback` 的比對口徑 |
| 停 21 的代付單 | `withdrawal:alert-stuck-submitted` 會先用 GetPayoutDetail 自動查證（`SupportsPayoutQuery`），查到有單才補單號轉 22；其餘照舊告警交 `withdrawal:resolve-payout` 人工定案 |

## 5. 上線後監控

- 前三天每日看 A3Pay 的 `payout verification rejected` log：正常量應為 0，出現代表有過期交易號被三方拿來問，要對照是哪條路徑產生的。
- `payinActualAmount differs from order amount` warning 的量：對照 Q1 的結論，若廠商說回調帶實付金額，這個量就是未來人工單的量。
