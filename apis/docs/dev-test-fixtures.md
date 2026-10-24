# Dev 環境常設測試設施

> **這份文件的用途＝防誤刪。**
> 以下資料列刻意長期保留在 **Dev 環境資料庫**（`DB_CONNECTION=mysql`），用於重現正式環境難以人為觸發的異常場景。
> 它們看起來像測試殘留，但**不是**——清理 Dev 測試資料時請跳過本表所列項目。

---

## 代付逾時測試渠道（AgentCashFlow）

| 項目 | 值 |
|---|---|
| `agent_cash_flows.id` | **7196** |
| 名稱 | `(MYR) QA 逾時測試渠道` |
| `agent_group_id` | 3 |
| `currency_id` | 1（MYR） |
| `cash_flow_id` | 78（EpicPay） |
| `status` | 1（啟用——QA 必須在後台選得到） |
| 建立時間 | 2026-08-18 |
| 來源 | `target/tickets#4137` 代付事務邊界改造的 QA 驗收 |

### 它做什麼

`info.API_URL` 指向 **黑洞位址 `0.0.0.0:81`**：TCP SYN 被丟包、不回 RST，代付請求會一路掛到 HTTP client 逾時（實測在途窗約 10.3 秒）才拋出。這是 Dev 唯一能**穩定重現三方逾時**的渠道，用來驗證：

- 代付請求逾時後訂單停留 `status=21`（代付請求中），而非誤判失敗
- `withdrawal_payout_records` 留下 `is_success_payout=0`（PENDING）痕跡——事務邊界段一先 commit
- 排程 `withdrawal:alert-stuck-submitted` 對停留超時單告警
- 後台「補填三方單號」／「標記失敗」兩顆按鈕的收尾路徑

### 為什麼不要刪

重建成本高，且三個坑都踩過：

1. **`available_banks` 必須夠全**——最初只抄 ACF 191 的 21 組 MYR 代碼，缺碼的銀行會被「不支援銀行」濾掉，QA 在前端選不到銀行就測不下去。現為 Dev 全部啟用的 MYR 代碼共 **110 組**（黑洞渠道不需真三方碼，`code => code` 佔位即可）。
2. **`name` 不可留空**——渠道顯示名取值為 `info['DisplayName'] ?? name`（`WithdrawalPayoutService::fetchPayoutChannels()`），最初 `name` 是空字串又沒有 `DisplayName`，後台下拉顯示空白，QA 根本找不到這個渠道。
3. **黑洞位址要選對**——`0.0.0.0:81` 這個組合經實測會讓連線一路掛到逾時（在途窗約 10.3 秒），才走得到「結果未明」那條路徑。至於「哪些位址不行」目前沒有實測資料：一般而言會立刻回絕連線的位址會走成連線失敗、測不到逾時，但**此推論未驗證**，換位址前請自己實測在途時間。

### 隔離性

`agent_cash_flows` 各白標 DB 獨立，**不存在同步到正式站的路徑**：

- `app:upgrade` 灌的主檔為 Vendor／CashFlow／AgentSettingDefine／AgentMenu／SmsGateway／Country／Currency，**不含 AgentCashFlow**
- 2026-08-20 六站實查（TPower／VWorld／Yes2Win／Alfawin77／Me77／VPlus33）`id = 7196 OR name LIKE '%QA4137%'` 皆為空集合

### 伴生的訂單存量

同批 QA 測試在 Dev 留下 `withdrawal_orders` 停留單（2026-08-18 ~ 08-19），刻意保留作為狀態機樣本：`status=21` 5 筆、`status=22` 6 筆、`status=29` 18 筆。
