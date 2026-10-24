# DgPay 廠商規格 — 索引與共用規範

DgPay（對外品牌亦稱 **PowerPG**）是面向東南亞市場的第三方支付平台，支援馬來西亞（MYR）、新加坡（SGD）、印尼（IDR）等多幣別，整合含**代收（Deposit）**與**代付（Withdrawal）**。所有請求以 **FORM-DATA** 提交、響應 **JSON**、認證採 **MD5 簽名**（`securityToken`）。API 版本由 `agent_cash_flows.info.VERSION` 決定。

## 文件結構（依版本拆分）

| 文件 | 範疇 | 內容 |
|---|---|---|
| **本檔 `api.md`** | 共用 | 環境資訊、回調處理、回調驗簽、錯誤碼、IP 白名單、ACF info 欄位、參考資料 |
| [`api-v1.md`](./api-v1.md) | **MYR / IDR（V1）** | `/deposit`、`/withdraw`、V1 發起簽名、MYR/IDR 銀行碼與限額 |
| [`api-v2.md`](./api-v2.md) | **SGD（代收 V2 / 代付 V1）** | 代收 `/v2/deposit`（`sender`、V2 簽名）＋代付 `/withdraw`（V1 `data`、⚠️ 見該檔頂部巨坑）、SGD 銀行碼與限額 |

> 內部實作路徑：`app/Services/Payment/Providers/DgPay/`。回調、狀態機、憑證模型 V1/V2 **完全共用**；差異僅在發起端點、參數與發起簽名（見各版本檔）。

---

## 環境資訊

| 項目 | 值 |
|---|---|
| 生產環境 API Base URL（`info.APIUrl`） | `https://example.invalid/ajax/api` |
| 商戶後台 | `https://example.invalid/index.html` |
| 請求格式 | FORM-DATA（`application/x-www-form-urlencoded`） |
| 響應格式 | JSON |
| 認證方式 | MD5 簽名（`securityToken` 欄位） |
| 回調 IP | `0.0.0.0` |

> Secret Key 需登入商戶後台 → Merchant → Edit Merchant Setting → 查看 Secret Key（需 OTP 驗證，發送至電子郵件）。

> ✅ **端點前綴已定案（2026-07-02）**：官方三份文檔端點皆帶 `/ajax/api` 前綴（`/ajax/api/deposit`、`/ajax/api/withdraw`、`/ajax/api/v2/deposit`、`/ajax/api/v2/withdraw`）。配置的 `APIUrl` **即含前綴**（`https://example.invalid/ajax/api`；DEV 實測裸 host 打 `/withdraw` 回 404，舊系統可運作綁定均帶前綴），程式把 `/deposit`、`/withdraw`、`/v2/deposit`、`/v2/withdraw` 等相對路徑直接接在 `APIUrl` 之後。各版本檔記載的端點皆為相對路徑。

---

## 回調處理

DgPay 在訂單狀態變化時以 **HTTP POST FORM-DATA** 推送到發起請求中的 `callbackUrl`。回調與狀態機 V1/V2 共用。

### 代收回調欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `orderId` | string | 三方訂單號（格式：`{merchantOrderId}@{opCode}`，如 `202511275929184104@dgpay_171`） |
| `merchantOrderId` | string | DgPay 內部訂單號 |
| `amount` | string | 訂單金額 |
| `processAmount` | string | 實際處理金額 |
| `chargeFee` | string | 手續費 |
| `status` | string | 訂單狀態碼（見下方） |
| `remark` | string | 備註（如 `Auto Approve By System`） |
| `data` | string | 原始發起回應的 JSON 字串（巢狀） |
| `securityToken` | string | 官方簽名 `md5(opCode + orderId + amount + status + secretKey)`；**現行實作已驗簽（硬拒）**，見〈回調驗簽〉 |

#### 回調範例

```json
{
    "remark": "Auto Approve By System",
    "status": "20",
    "data": "{\"orderId\":\"202511275929184104@dgpay_171\",\"merchantOrderId\":\"202511275929184104\",...}",
    "orderId": "202511275929184104@dgpay_171",
    "chargeFee": "0.7800",
    "processAmount": "30.0000",
    "amount": "30.0000",
    "securityToken": "824ad6613acb2f9c94e6360ed55f066d",
    "merchantOrderId": "202511275929184104"
}
```

### 代收訂單狀態碼（status）

| status | 含義 | 系統處理 |
|---|---|---|
| `20` | 成功（Success） | 更新訂單為成功（終態） |
| `-10` | 失敗（Failed） | 更新訂單為失敗 |
| `-20` | 已過期（Expired） | 更新訂單為失敗 |
| `0` | 待處理（Pending） | No-op（只記錄資料） |
| `1` | 已提交（Submit） | No-op |
| `2` | 佇列中（Queue） | No-op |
| `5` | 待驗證（Pending Verification） | No-op |
| `10` | 處理中（Process） | No-op |

> **三態邏輯**：只有 `status=20` 為成功終態；`-10`/`-20` 為失敗終態；其餘中間態均為 No-op，僅記錄回調資料。

### 冪等性處理

DgPay 可能發生「先回調失敗（-10）→再回調成功（20）」的情況（掉單重送機制）：

- 若訂單已處於 `SUCCESS` 或 `DEPOSIT_FINISHED` 終態，**任何狀態的回調一律 No-op**，直接回傳成功。
- 若訂單處於 `CREATED` 或 `FAILED`，允許重新處理（失敗→成功方向允許翻轉）。

### 回調響應格式（代收）

```json
// 成功
{ "code": "0", "description": "Success" }

// 失敗
{ "code": "-100", "description": "Order not found" }
```

### orderId 解析規則（代收）

回調中的 `orderId` 格式為 `{merchantOrderId}@{opCode}`，系統以 `@` 切割取第一段作為內部 `order_no`，並驗證第二段是否符合該 ACF 配置的商戶代碼（不符則拋出錯誤）。

### 代付回調欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `orderId` | string | 代付訂單號（用於比對 `withdrawal_payout_records`，見 integration.md） |
| `status` | string | 代付狀態碼（見下方） |
| `amount` | string | 代付金額 |
| `securityToken` | string | 官方簽名 `md5(opCode + orderId + amount + status + secretKey)`；**現行實作已驗簽（硬拒）**，見〈回調驗簽〉 |

### 代付狀態碼（status）

| status | 含義 | 系統處理 |
|---|---|---|
| `20` | 成功（Success） | 訂單 → THIRD_PARTY_SUCCESS(24)，交易日誌結算 |
| `-10` | 失敗（Reject） | 訂單 → THIRD_PARTY_FAILED(29) |
| `-20` | 已過期（Expired） | 訂單 → THIRD_PARTY_FAILED(29) |
| `0` | 待處理（Pending） | 維持 PAYMENT_PROCESSING(22)，僅 ack |
| `1` | 已提交（Submit） | 同上 |
| `2` | 佇列中（Queue） | 同上 |
| `5` | 待驗證（Pending Verification） | 同上 |
| `10` | 處理中（Processing） | 同上 |

> **三態邏輯**：`20` 成功終態、`-10`/`-20` 失敗終態；其餘中間態維持 22 不結算，僅回成功 ack 避免三方重送。
>
> **狀態集出處（2026-07-03 對齊）**：官方 §4.1 Notify 載明的狀態集為 `-20/-10/0/5/10/20`（IDR 文檔另無 `5`）；`1 Submit`／`2 Queue` 僅見於官方 §3.4 Query Withdraw Status 的狀態集。本表全列並以 No-op 處理（涵蓋面比官方 Notify 寬，行為不受影響）。

### 回調響應格式（代付）

```json
// 成功 / ack
{ "code": 0, "description": "Success" }

// 失敗（紀錄不存在等）
{ "code": -100, "description": "Fail" }
```

### 回調簽名（三方提供，現行已驗簽硬拒）

**官方文檔（MYR / IDR / SGD V2 三份 §4.1 Notify）均明確定義了回調簽名公式**：

```
securityToken = [REDACTED] + orderId + amount + status + secretKey)
```

> IDR 文檔的回調 `orderId` 格式為 `{商戶交易號}@{opCode}`；MYR / SGD 為商戶交易號原值。拼接時以回調實際帶回的 `orderId` 為準。

**現況（2026-07-01 起）**：`DepositCallback` / `WithdrawalCallback` **已補上驗簽並硬拒**（不符回 code `-100`、不動訂單），封閉了原本「知道商戶代碼與訂單號即可偽造成功回調」的洞。

> ⚠️ **go-live 前置**：此公式源自官方 Notion（repo 內無法覆核），硬拒上線前須以真實回調當 oracle 驗證（代收有現成樣本、代付/SGD V2 需各撈首筆），詳見 `integration.md` §2。IP 白名單（`0.0.0.0`）維持入口層防線。

### 回調驗簽

官方三份 §4.1 一致定義（V1/V2 共用）：

```php
// secretKey = info.MerchantKey
protected function verifyCallbackSignature(array $params): bool
{
    $expected = strtolower(md5(
        $this->infoData->merchant       // opCode
        . $params['orderId']            // 回調帶回的 orderId 原值（IDR 為 {交易號}@{opCode}）
        . $params['amount']
        . $params['status']
        . $this->infoData->merchantKey  // secretKey
    ));

    return hash_equals($expected, (string) ($params['securityToken'] ?? ''));
}
```

| 用途 | 拼接字串（依序） | 算法 |
|---|---|---|
| 代收回調驗簽 | `opCode + orderId + amount + status + secretKey` | MD5 小寫 |
| 代付回調驗簽 | `opCode + orderId + amount + status + secretKey` | MD5 小寫 |

---

## 查單／餘額／幣別清單 API（V1/V2 共用，實作未使用）

官方三份文檔 §3.3–§3.6 定義的輔助端點（POST FORM-DATA，接在 `info.APIUrl` 之後），V1 與 V2 文檔內容一致。**現行實作未串接**（無主動查單），列此供掉單排查／對帳參考（2026-07-03 補齊）：

| 端點 | 用途 | 簽名拼接（MD5 小寫） |
|---|---|---|
| `/queryDepositTrans` | 查代收訂單狀態 | `orderId + reqDateTime + opCode + secretKey` |
| `/queryWithdrawTrans` | 查代付訂單狀態 | `orderId + reqDateTime + opCode + secretKey` |
| `/balance/getBalance` | 查商戶錢包餘額 | `currency + reqDateTime + opCode + secretKey` |
| `/currency/listing/getCurrencyList` | 查商戶幣別清單 | `reqDateTime + opCode + secretKey` |

- 查單請求參數：`orderId`（我方單號）、`opCode`、`reqDateTime`、`securityToken`；響應帶 `refId`（三方單號）、`status`（枚舉同回調；代付查單另有 `1 Submit`/`2 Queue`）、金額與費用欄。
- `getBalance` 請求另帶 `currency`，響應回 `currency`/`balance`。
- ⚠️ **官方文檔筆誤**：SGD V2 文檔 §3.3（查代收）響應表誤植 `amountToWithdraw`、§3.4（查代付）誤植 `amountToDeposit`——兩者互換；MYR/IDR 文檔無此問題。

---

## 錯誤碼

發起響應 `code`（V1/V2 共用）：

| code | 含義 |
|---|---|
| `-1` | System Error |
| `-100` | Internal Server Error |
| `-110` | Parameter is empty |
| `-120` | System parameter validation error |
| `-130` | Whitelist IP error |
| `-140` | Duplicate order id |
| `-150` | Min amount error |
| `-160` | Max amount error |
| `-165` | Invalid Request (Insufficient balance) |
| `-170` | Invalid Amount |
| `-175` | Bank account is not available (need register bank account first) |
| `-180` | Invalid Payment Type |

> `-100 Internal Server Error` 僅 SGD V2 文檔 §8 Error Code Appendix 列出；MYR / IDR 文檔的 §7 未列（但三份文檔的回調響應均以 `-100` 表錯誤）。

---

## 回調 IP 白名單

DgPay 從以下 IP 推送回調，需列入伺服器防火牆白名單：

| 說明 | IP |
|---|---|
| 三方 Server IP | `0.0.0.0` |

---

## 參考資料

### ACF info 欄位說明

| 欄位 | 說明 | 範例 |
|---|---|---|
| `APIUrl` | API Base URL（**含 `/ajax/api` 前綴**） | `https://example.invalid/ajax/api` |
| `Merchant` | 商戶代碼（opCode） | `dgpay_1568` |
| `MerchantKey` | 簽名密鑰 / secretKey（需後台 OTP 取得） | `{UUID 格式}` |
| `Currency` | 幣別 | `MYR` |
| `VERSION` | API 版本（`V1`=MYR/IDR、`V2`=SGD） | `V1` |
| `PROVIDER_ID` | V1 用。**代收留 `0`**（channel key 動態解析）；**代付配實值**（MYR Auto=`5`、IDR=`55`） | `5` |
| `PROVIDER_TYPE` | V1 用。**代收留 `0`**；**代付配實值**（MYR/IDR 均 `10`） | `10` |
| `PAYMENT_CODE` | V2 代收 paymentType override。**新組留空**（由 channel key `P2P`/`QR`/`QRKYC` 動態解析），填值會蓋掉全部通道 | （留空） |
| `DefaultEmail` | THB 通道預設 email | `user@example.invalid` |

### 內部實作路徑

```
app/Services/Payment/Providers/DgPay/
├── Driver.php              # 入口（組合代收 + 代付）
├── Deposit.php             # 代收發起：V1/V2 分支、幣別/通道路由、簽名生成
├── DepositCallback.php     # 代收回調：orderId 解析、三態狀態機、冪等保護
├── Withdrawal.php          # 代付發起：V1 data 組裝（MYR/IDR/SGD 皆走 /withdraw）；
                            #   V2 recipientInfo 分支現無幣別觸發＝dead code（見 integration.md §5）
├── WithdrawalCallback.php  # 代付回調：transaction_no 比對、三態狀態機、冪等保護
└── InfoData.php            # 配置 readonly DTO（apiUrl / version / currency /
                            #   providerId / providerType / paymentCode /
                            #   merchant / merchantKey / defaultEmail）
```

**config/payment.php 配置 key**：`DG`

### 官方 API 文檔（Notion，公開可讀，2026-07-03 經瀏覽器抓取比對）

| 幣別 | 版本 | 用途 | URL |
|---|---|---|---|
| MYR | V1 | 代收＋代付 | `https://example.invalid/Power-PG-API-Document-MYR-5999804000854f329c70ae59ea5f6d15` |
| MYR | V2 DGPay | 參考 | `https://example.invalid/Power-PG-API-Document-MYR-DGPay-4c9a34d9177e44cfa11f6f737dc2441d` |
| SGD | V2 | **SGD 代收**（`/v2/deposit`） | `https://example.invalid/Power-PG-API-Document-SGD-V2-1653a5833ed8801b8360ee6f5e568168` |
| SGD | V1 | **SGD 代付**（`/withdraw`，⚠️ 未重導、仍有效） | `https://example.invalid/Power-PG-API-Document-SGD-6fd128f7c2c64791a2bd1f3b249a662c` |
| IDR | V1 | 代收＋代付 | `https://example.invalid/Power-PG-API-Document-IDR-24e2ac4c005b401e8a141e35c1a562a9` |

> ⚠️ **SGD 代收 V2 / 代付 V1**（2026-07-03 更正）：舊 SGD 文檔（`6fd128f7`）**並未 302 重導**——它是 SGD 代付的權威來源（V1 結構）。詳見 [`api-v2.md`頂部](./api-v2.md) 與 [`integration.md` §5](./integration.md)。

### GitLab 工單

| 工單 | 標題 |
|---|---|
| #3433 | [GFF][APIs] DGPay-MYR 代收代付接入（首版 MYR 對接） |
| #3432 | [GFF][APIs] DGPay-IDR 代收代付接入 |
| #3429 | [GFF][APIs] DGPay-SGD 代收代付接入（含 PayNow QR V2） |
| #3627 | [GFF] DGPay 只保留成功狀態，其餘開放可變動（冪等規則變更） |
| #3658 | [GFF][Wintoto] DGPAY-MYR 代付配置 |
| #3489 | [GFF][Wintoto] DGPay-SGD 代收代付配置 |
| #3488 | [GFF][Yes2win] DGPay-SGD 代收代付配置 |
| #3487 | [GFF][Yes2win] DGPay-MYR 代收代付配置 |
| #3486 | [GFF][Alfawin77] DGPay-IDR 代收代付配置 |
| #3485 | [GFF][Yes2win] DGPay-IDR 代收代付配置 |
| #3248 | [GFF][TPower] DGPAY 增加 Route47 Static QR、Route 2 |
| #2864 | [GFF][VPLUS33] DGPay MYR 代收配置 |
| #2687 | [GFF][Yes2Win] DGPAY MYR 對接（含銀行代碼、限額） |
| #2662 | [GFF][AW77] DGPAY 代收代付（IDR 銀行清單） |
| #2432 | [GFF][TP] DGPay 印尼 IDR 代收代付（費率資訊） |

---

*文件版本：2.2 / 整理日期：2026-06-01 / 對齊官方 Notion 文檔：2026-07-03*
*資料來源：Codebase 實作、GitLab 工單、官方 Notion 文件（MYR / IDR / SGD V2 / SGD 舊 四份，2026-07-03 經瀏覽器實抓比對——公開可讀，無登入牆）。*

> **v2.2 變更（2026-07-03，🕳️ SGD 版本混用巨坑）**：查證舊 SGD 文檔（`6fd128f7`）**未 302 重導、仍有效**，確認 **SGD 代收走 V2、代付走 V1**（`/withdraw`＋`providerId=10`/`providerType=10`＋`data{bankName,accNo,accName}`＋V1 簽名）。`api-v2.md` 標題與 §1/§3/§4/§5 全面改寫（代付段從 V2 `recipientInfo` 更正為 V1 結構）；`api.md` 文檔連結表更正 SGD 用途與重導狀態；`integration.md` 新增 §5 完整記錄坑與生產實證（VWorld/TPower 舊組 binding VERSION=null＋10/10 打款中）；config `withdrawal.info_overrides['SGD']` 由 `VERSION=V2` 更正為 `10/10`。標記 `Withdrawal.php` V2 代付分支為 dead code（去留待決策）。
>
> **v2.1 變更（2026-07-03 對齊）**：IDR 代付更正為官方 §5 Withdraw 條目 `55/10`（`26/10` 降級為舊組 legacy）；MYR 銀行表 Route2 化（官方無「Route 1」，代收僅 Transfer=YES 的 9 家，補 Route47 表）；`/ajax/api` 前綴定案（去除待確認註記）；補查單／餘額／幣別清單端點與簽名；補 `-100` 錯誤碼出處與 Notify 狀態集出處註記。
>
> **v2.0 變更**：原單檔 `api.md` 依版本拆為 `api.md`（共用）＋ `api-v1.md`（MYR/IDR）＋ `api-v2.md`（SGD）。承接 v1.1 對齊修正（回調驗簽公式、IDR 數字碼、SGD `S`/`SG` 代付碼），並補齊 SGD V2 代付 `/v2/withdraw`＋`recipientInfo` 規格。
