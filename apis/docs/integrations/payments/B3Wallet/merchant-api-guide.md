# 商户 API 接入文档

**文档版本：** `1.0.0`

**更新日期：** `2026-09-04`

**语言：** 简体中文 | [English](merchant-api-guide.en.md)

本文档版本为中英文共用的修订号，独立于系统版本和 API 路径版本。后续修订须同步更新两份文档及版本号。

**API Base URL:** `https://www.judy88.xin`

---

## 一、快速上手

建议按以下顺序接入，先跑通最小闭环，再扩大流量。

### 第 1 步：获取并保存凭据

从管理员处确认 `PaymentKey` 与 `PaymentPass`，配置好服务器 Base URL、收款/出金回调地址与 IP 白名单。**同时请平台确认已为商户开通所需币种及收款/出金方式**；商户按币种和付款方式接入，无需选择支付服务商。

### 第 2 步：先接入 Create Bill / Query Bill

先打通收款链路。创建时请注意：

- **新接入必须传 `DepositInstruction`**：`PAYNOW`（扫码/即时支付）、`BANK_TRANSFER`（银行转账）、`CRYPTO`（USDT 链上收款）或 `AUTO`（自动选择）。各方式要求见下表；值须使用大写。
- 商户拥有独立的多币种钱包。`Currency` 可传平台已为该商户开通的 `SGD`、`AUD`、`HKD`、`MYR`、`PHP`、`INR`、`THB`；不传使用商户默认币种。`USDT` 仅用于 `CRYPTO`。系统不会自动跨币种换汇。
- 创建成功后**以响应 `Amount` 为准**展示给用户并对账：部分通道（如 HKD）可能微调应付金额。
- 引导用户打开完整 **`BillUrl`**：通道单常跳转外部收银台；**勿假定** `GET /api/v1/bills/{billId}/qr` 总有二维码。
- **INR UPI**：用户付完后须在托管付款页提交 **12 位 UTR**（非商户 API 字段）；入账仍以 Callback + Query 为准。
- 可选传 `PayerName` 预填付款人银行登记姓名。

**`DepositInstruction` 如何选择：**

| 请求值 | 适用场景 | 付款人操作与接入差异 |
|------|------|------|
| `PAYNOW` | 扫码/即时支付；商户扫码接入统一使用此值 | SGD 对应 PayNow，其他币种按已开通能力提供当地扫码/即时支付。打开 `BillUrl` 按页面指引付款；不保证可单独获取二维码。它不是仅限 SGD 的币种标识。 |
| `BANK_TRANSFER` | 明确需要银行转账 | 按 `BillUrl` 中的收款信息和步骤转账；付款页要求上传凭证时必须完成上传。与扫码支付的付款步骤不同。 |
| `CRYPTO` | USDT 链上收款 | 必须同时传 `Currency=USDT` 和 `Chain=TRON`、`ETHEREUM` 或 `BSC`。按付款页显示的网络、地址与精确金额转账，等待回调并查单确认。 |
| `AUTO` | 不指定具体付款方式，接受平台自动选择 | 按已开通能力选择，最终方式以响应和 `BillUrl` 为准；不保证每笔都是扫码。若业务要求固定方式，应明确传上述对应值；USDT 链上收款请显式使用 `CRYPTO`。 |

**扫码请求与响应的区别：** 本文只将 `PAYNOW` 列为扫码请求选项。接口还接受 `QR`，统一路由将两者视为扫码类，但部分支付服务的具体产品选择仍有差异，已有接入不要未经联调直接互换。**传 `PAYNOW` 创建的新扫码账单，响应 `DepositInstruction` 当前为 `QR`**；商户应将其识别为扫码支付，不应要求响应值与请求值完全相同。

上述方式须先由平台开通，列出币种不代表该币种支持全部方式。省略 `DepositInstruction` 仅用于旧版兼容，新接入请勿省略。内部或历史标记不能作为创建参数使用。

创建后跳转 `BillUrl`，后台用查询接口确认状态（CRYPTO 建议以 Callback 为主）。

### 第 3 步：接入 Payout 提现

使用与账单相同的 `PaymentKey:PaymentPass`；建议携带 `Idempotency-Key`。

**创建成功只表示受理并冻结余额（`Paid=false`），不等于已打款。** 平台审核处理后，以 Callback + `GET /api/v1/payouts/{payoutId}` 确认最终结果。

- **BANK_TRANSFER / PAYNOW / AUTO**：须先开通对应币种与出金方式。出金 `AUTO` 按银行转账处理，与收款 `AUTO` 含义不同。AUD 建议传真实的 `Bsb` / `DocumentNumber` / `RecipientEmail`。INR 银行出金须传 `Ifsc`。
- **CRYPTO**：须 `Currency=USDT`、`Chain`、`ToAddress`。

### 第 4 步：联调回调与异常重试

- **法币收款（BANK_TRANSFER / PAYNOW，含多币种）**：收到 Callback 后仍建议查询二次确认。
- **CRYPTO 收款**：强依赖 Callback；失败须人工对账。
- **CRYPTO / 通道出金**：等运营提交后的终态 Callback 或查询结果。
- 记录幂等键、请求体和响应日志；Callback 处理须幂等。

---

## 二、API 对接指南

### 鉴权说明

开放接口统一使用 `Authorization` 请求头，格式为 `PaymentKey:PaymentPass`。

```
Authorization: PaymentKey:PaymentPass
```

**约束：**
- 商户状态必须是 `ACTIVE`。
- 配置了 IP 白名单时，来源 IP 必须命中。
- 错误凭据或缺少 `Authorization` 均会返回 `401`。

---

### 通用请求头

| 字段 | 位置 | 必填 | 类型 | 说明 |
|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 格式为 `PaymentKey:PaymentPass`，缺失或错误返回 401 |
| `Content-Type` | Header | 是 | `application/json` | 请求体 JSON 编码 |
| `Idempotency-Key` | Header | 否 | `string` | 幂等请求键，防止重复提交。相同 key + 相同请求体返回首次响应；请求体不同会被拒绝 |

---

**幂等处理：** 以下规则适用于创建账单和创建出金。相同 key 的请求仍在处理中返回 `409 IDEMPOTENCY_IN_PROGRESS`，稍后用同一个 key 和相同请求体重试；相同 key 配不同请求体返回 `409 IDEMPOTENCY_CONFLICT`。请保持字段与顺序不变。幂等缓存不是永久订单索引；已获得订单号后用查询接口跟踪，未知结果不要直接换新 key 建单。

### 支付链接与返回结果

`BillUrl` 可能是平台付款页，也可能直接指向外部收银台。请原样打开，不固定域名，不自行拼接 `BillAccessToken`。该令牌用于平台付款页操作，商户无需识别具体支付服务商。

部分支付流程返回 `RedirectUrl` 时会附加平台 `BillId`，并覆盖同名参数；其他查询参数及 fragment 保留。返回地址需允许增加 `BillId`，对完整 URL 签名的接入须先适配；附加参数后的 URL 最长 2048 字符。**返回地址不保证包含 `Paid`，浏览器返回不能证明支付成功。**

结果页由商户后端调用鉴权 `GET /api/v1/bills/{billId}`；不得把 `PaymentKey` / `PaymentPass` 放进浏览器。`PENDING` / `PROCESSING` 展示“支付确认中”，可每 2 秒查询一次、最多等待 60 秒，超时后提供稍后刷新，不自动重建订单。`Paid=false` 不等于失败，须结合 `Status`；商户收到服务端通知后应查单确认，并按 `BillId` 幂等入账。

查询时，已结束的订单可能返回状态页链接。创建接口的幂等重放返回首次保存的响应，不刷新支付链接。付款人是否返回、关闭页面均不决定支付状态。

### 2.1 Bill 收款

#### 2.1.1 创建账单 `POST /api/v1/bills/create`

创建收款账单。建议带 `Idempotency-Key` 防重复请求。响应会返回 `BillId`、`BillUrl`、`BillAccessToken`、`DepositInstruction` 等。

**新接入必须传 `DepositInstruction`。** 可选 `PAYNOW` / `BANK_TRANSFER` / `CRYPTO` / `AUTO`，差异见快速上手的方式表。可选传 `PayerName` 预填付款人姓名并跳过付款页姓名填写。

创建后请：以响应 **`Amount`** 为准（通道可能微调）；引导用户打开完整 **`BillUrl`**（通道常外跳收银台，勿假定自建 `GET .../qr` 总有码）。**INR UPI** 用户须在托管页提交 12 位 UTR。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或格式错误返回 401 |
| `Content-Type` | Header | 是 | `application/json` | 请求体 JSON 编码 | - |
| `Idempotency-Key` | Header | 否 | `string` | 幂等请求键 | 相同 key+相同请求体返回首次响应 |
| `ShopName` | Body | 是 | `string` | 商户店铺名 | 1-100 字符 |
| `ProductName` | Body | 是 | `string` | 商品名 | 1-200 字符 |
| `Amount` | Body | 是 | `number` | 订单金额 | 必须大于 0；若配置了收款上下限须在范围内 |
| `SenderId` | Body | 否 | `string` | 商户侧买家标识 | - |
| `PayerName` | Body | 否 | `string` | 付款人银行登记姓名 | 1-100 字符；传入后付款页跳过姓名填写；仅空白视为未传 |
| `RedirectUrl` | Body | 否 | `string(url)` | 付款页返回商户地址 | 仅允许 HTTP/HTTPS；禁止 URL 用户名或密码；最长 2048 字符 |
| `CallbackUrl` | Body | 否 | `string(url)` | 该订单专用回调地址 | 省略时回退商户默认收款回调；不可传空字符串或 null |
| `Param` | Body | 否 | `string` | 透传/玩家标识 | 最长 255；通道场景建议传稳定买家/订单标识 |
| `DepositInstruction` | Body | 是（新接入必传） | `PAYNOW \| BANK_TRANSFER \| CRYPTO \| AUTO` | 收款方式 | 扫码统一传 `PAYNOW`；大小写敏感；`AUTO` 不保证扫码；各方式区别见快速上手 |
| `Chain` | Body | CRYPTO 时必填 | `TRON \| ETHEREUM \| BSC` | 区块链网络 | 仅 `DepositInstruction=CRYPTO` 时有效 |
| `Currency` | Body | 否 | `string` | 币种 | 支持 `SGD`/`AUD`/`HKD`/`MYR`/`PHP`/`INR`/`THB` 多币种钱包；`CRYPTO` 须 `USDT`；不填用默认币种；须已开通对应付款方式与金额限额 |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `BillId` | 是 | `string` | 平台账单号 |
| `BillUrl` | 是 | `string(url)` | 支付页面地址 |
| `BuyerId` | 否 | `string\|null` | 回显 SenderId |
| `Date` | 是 | `string(ISO8601)` | 账单创建时间 |
| `Paid` | 是 | `boolean` | 创建时固定为 false |
| `Param` | 否 | `string\|null` | 回显 Param |
| `ProductName` | 是 | `string` | 回显商品名 |
| `ShopName` | 是 | `string` | 回显店铺名 |
| `Amount` | 是 | `number` | 应付金额（通道可能微调；展示/对账以此为准） |
| `RequestedAmount` | 是 | `number` | 商户请求金额 |
| `PayableAmount` | 是 | `number` | 付款人实际应付金额；通常等于 Amount |
| `FeeRate` | 是 | `number` | 本单锁定的综合有效费率（`FeeAmount / PayableAmount`）；固定费/最低费会使其不等于约定的百分比费率 |
| `FeeAmount` | 是 | `number` | 本单锁定的商户手续费 |
| `NetAmount` | 是 | `number` | 商户钱包预计入账净值（`PayableAmount − FeeAmount`） |
| `Currency` | 是 | `string` | 实际币种 |
| `ExpiresAt` | 是 | `string(ISO8601)` | 账单过期时间 |
| `BillAccessToken` | 是 | `string` | 付款页访问令牌（平台付款页可带 `#token=[REDACTED] PayerName 时用于提交姓名；BANK_TRANSFER 上传凭证亦需携带 |
| `DepositInstruction` | 是 | `string\|null` | 通常为 `BANK_TRANSFER` / `QR` / `CRYPTO`；实际收款方式；传 `PAYNOW` 的新扫码单返回 `QR`，`AUTO` 返回选定方式；历史响应兼容见下文 |
| `ProviderResponse` | 否 | `object` | 可选诊断信息，供平台排查使用；不要据此选择付款方式或判断支付成功 |
| `CryptoChain` | 否 | `TRON\|ETHEREUM\|BSC\|null` | 链名；仅 CRYPTO 时有值 |
| `CryptoAddress` | 否 | `string\|null` | 链上收款地址；仅 CRYPTO 时有值 |
| `CryptoAddressTag` | 否 | `string\|null` | 地址 Tag/Memo（若有） |

**校验与约束：**
- 若带 `Idempotency-Key`，建议使用商户订单号或请求唯一键。
- **新接入必须传 `DepositInstruction`**；传入未支持的枚举值返回 `400 VALIDATION_ERROR`。方式或币种未开通、当前无可用收款服务时可能返回 `422 NO_AVAILABLE_PAYMENT_ACCOUNT`，请联系平台核实。
- `Currency` 不填时使用商户 `defaultCurrency`；显式币种须受支持，且平台已开通对应钱包、付款方式及金额限额。不会把一种币的钱记入另一币种钱包。
- 创建后以响应 `Amount` 为准；通道可能微调应付金额（如 HKD）。
- 通道单：引导用户打开完整 `BillUrl`；勿依赖自建 QR；浏览器回跳不可信。
- **INR UPI**：付款人须在托管页提交 12 位 UTR。
- `BANK_TRANSFER` 是否需要上传凭证，以付款页指引为准；`PAYNOW` 也可能打开外部收银台。
- Bill 的 `CallbackUrl` 不填时，使用商户默认收款回调地址，再回退兼容的默认回调地址。
- `CRYPTO`：须 `Currency=USDT` 且传 `Chain`；精确金额；入账依赖回调。
- 低于最小或高于最大返回 `AMOUNT_BELOW_MIN` / `AMOUNT_ABOVE_MAX`。
- `PayerName` / `RedirectUrl`：同前；回跳不能替代 Callback。

**CRYPTO 链上收款**：付款页展示地址与金额；用户转入精确 USDT。平台回调入账后投递 Bill webhook。

**请求示例（MYR / PAYNOW 扫码）：**

```bash
curl -X POST https://www.judy88.xin/api/v1/bills/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: bill-myr-paynow-001" \
  -d '{
    "ShopName": "Demo Shop",
    "ProductName": "Topup",
    "Amount": 50,
    "Currency": "MYR",
    "DepositInstruction": "PAYNOW",
    "CallbackUrl": "https://merchant.example/callback"
  }'
```

**请求示例（CRYPTO / USDT）：**

```bash
curl -X POST https://www.judy88.xin/api/v1/bills/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: bill-crypto-001" \
  -d '{
    "ShopName": "Demo Shop",
    "ProductName": "USDT Topup",
    "Amount": 100,
    "Currency": "USDT",
    "DepositInstruction": "CRYPTO",
    "Chain": "TRON",
    "CallbackUrl": "https://merchant.example/callback"
  }'
```

**请求示例：**

```bash
curl -X POST https://www.judy88.xin/api/v1/bills/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: bill-20260327-001" \
  -d '{
    "ShopName": "Demo Shop",
    "ProductName": "Topup",
    "Amount": 120.5,
    "DepositInstruction": "BANK_TRANSFER",
    "PayerName": "TAN AH KAU",
    "CallbackUrl": "https://merchant.example/callback"
  }'
```

---

#### 2.1.2 查询账单 `GET /api/v1/bills/{billId}`

查询账单状态与支付时间。建议把查询结果作为最终订单状态依据。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `billId` | Path | 是 | `string` | 账单号 | - |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `BillId` | 是 | `string` | 账单号 |
| `BillUrl` | 是 | `string(url)` | 支付页面地址 |
| `BuyerId` | 否 | `string\|null` | 商户侧买家标识 |
| `Date` | 是 | `string(ISO8601)` | 账单创建时间 |
| `Paid` | 是 | `boolean` | 是否已支付 |
| `Status` | 是 | `PENDING\|PROCESSING\|PAID\|SETTLED\|EXPIRED\|CANCELLED\|REFUNDED\|DISPUTED\|VOIDED` | 账单状态；`PENDING`/`PROCESSING` 均未确认支付 |
| `Param` | 否 | `string\|null` | 透传字段 |
| `ProductName` | 是 | `string` | 商品名 |
| `ShopName` | 是 | `string` | 店铺名 |
| `Amount` | 是 | `number` | 金额 |
| `RequestedAmount` | 是 | `number` | 商户请求金额 |
| `PayableAmount` | 是 | `number` | 付款人实际应付金额 |
| `FeeRate` | 否 | `number\|null` | 本笔锁定的综合有效费率（`FeeAmount / Amount`）；仅无费用快照的旧数据可能为 `null` |
| `FeeAmount` | 是 | `number` | 手续费金额；未确定前为 `0` |
| `NetAmount` | 否 | `number\|null` | 入账净值（`Amount − FeeAmount`）；手续费未确定时为 `null` |
| `Currency` | 是 | `string` | 币种 |
| `ExpiresAt` | 是 | `string(ISO8601)` | 过期时间 |
| `PaidAt` | 否 | `string(ISO8601)\|null` | 支付完成时间，未支付为 null |
| `DepositInstruction` | 是 | `string\|null` | 通常为 `BANK_TRANSFER` / `QR` / `CRYPTO`；实际收款方式；传 `PAYNOW` 的新扫码单返回 `QR`，`AUTO` 返回选定方式；历史响应兼容见下文 |
| `CryptoChain` | 否 | `TRON\|ETHEREUM\|BSC\|null` | 链名 |
| `CryptoAddress` | 否 | `string\|null` | 收款地址 |
| `PaidAmount` | 否 | `number\|null` | 实际到账 USDT（CRYPTO 已支付时） |

**历史响应兼容：** 查询历史账单或重放旧创建请求时，`DepositInstruction` 还可能为 `PAYNOW`、`PAYNOW_I1QR`、`AUTO` 或 `null`。前两者按扫码类识别；后两者按 `BillUrl` 引导付款并查单确认。`PAYNOW_I1QR` 仅是历史响应标记，不能作为创建参数。

**校验与约束：**
- 仅可查询当前商户自己的 `billId`。
- `404` 表示账单不存在或不属于当前商户。

**请求示例：**

```bash
curl -X GET https://www.judy88.xin/api/v1/bills/{billId} \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

#### 2.1.3 取消账单 `POST /api/v1/bills/{billId}/cancel`

取消 `PENDING` / `PROCESSING` 状态的账单。外部支付可能已在处理，取消响应不代表外部付款已撤销；发生扣款或状态不一致时请查单并联系平台核实。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `billId` | Path | 是 | `string` | 待取消账单号 | - |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `success` | 是 | `boolean` | 是否取消成功 |
| `BillId` | 是 | `string` | 账单号 |
| `Status` | 是 | `CANCELLED` | 取消后固定为 CANCELLED |

**校验与约束：**
- `PAID` 状态不能取消（返回 422）。
- `CANCELLED` / `EXPIRED` 状态重复取消返回 422。
- 取消成功后状态为 `CANCELLED`；其他状态或并发状态变化返回 `409 BILL_STATE_CONFLICT`，请先重新查单。

**请求示例：**

```bash
curl -X POST https://www.judy88.xin/api/v1/bills/{billId}/cancel \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

#### 2.1.4 获取二维码 `GET /api/v1/bills/{billId}/qr`

获取账单二维码（可用于收银台展示）。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `billId` | Path | 是 | `string` | 账单号 | - |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `BillId` | 是 | `string` | 账单号 |
| `QrString` | 是 | `string` | 可用于生成二维码的原始字符串 |

**校验与约束：**
- 仅可读取当前商户自己的账单二维码（账单不存在或不属于当前商户返回 404）。
- 账单必须存在 `qrString` 才能返回二维码；否则返回 404，错误信息为 `QR code is not available for this bill`。
- 是否可获取二维码由账单是否生成了 `qrString` 决定，不由 `billId` 的文本格式决定；传 `PAYNOW` 创建的账单也不保证有二维码；返回 404 时请使用完整 `BillUrl`。

**请求示例：**

```bash
curl -X GET https://www.judy88.xin/api/v1/bills/{billId}/qr \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

### 2.2 Payout 出金

#### 2.2.0 获取出金银行列表 `GET /api/v1/payout-recipient-banks`

返回可用于创建出金时 `RecipientBank` 的银行代码列表（仅包含当前已启用银行）。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 |
|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据，格式为 `PaymentKey:PaymentPass` |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `Banks` | 是 | `string[]` | 可用于 `RecipientBank` 的已启用银行代码列表（如 `DBS`、`UOB`） |

**校验与约束：**
- 需商户鉴权（缺失或错误返回 401）。
- 仅返回当前已启用银行代码。

**请求示例：**

```bash
curl -X GET https://www.judy88.xin/api/v1/payout-recipient-banks \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

#### 2.2.1 创建出金 `POST /api/v1/payouts/create`

创建出金请求。请求成功后会先冻结 `TotalDeductAmount`（本金 + 代付手续费）。

**出金方式**：`PayoutMethod` 支持 `BANK_TRANSFER`、`PAYNOW`、`CRYPTO`、`AUTO`。省略或传 `AUTO` 均按银行转账处理，使用银行转账字段，响应为 `BANK_TRANSFER`；不接受收款接口的 `QR`。

##### 银行转账（`PayoutMethod=BANK_TRANSFER`，默认）

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `Content-Type` | Header | 是 | `application/json` | 请求体 JSON 编码 | - |
| `Idempotency-Key` | Header | 否 | `string` | 幂等请求键 | 相同 key+相同请求体返回首次响应 |
| `PayoutMethod` | Body | 否 | `BANK_TRANSFER \| AUTO` | 出金方式 | 不传或传 AUTO 均按银行转账处理 |
| `RecipientAccount` | Body | 是 | `string` | 收款账号 | 1-50 字符 |
| `RecipientName` | Body | 是 | `string` | 收款人姓名 | 1-100 字符 |
| `RecipientBank` | Body | 是 | `string` | 收款银行 | 必须是已启用的银行代码；建议先调用 `GET /api/v1/payout-recipient-banks`；AUD 可用 `CBA`/`ANZ`/`NAB`/`WBC` 等；HKD 可用 `HSBC`/`BOC`/`HSB`/`SCB` 等 |
| `Amount` | Body | 是 | `number` | 出金金额（本金） | 必须大于 0；若配置了提款上下限须在范围内 |
| `CallbackUrl` | Body | 否 | `string(url)` | 该笔出金专用回调地址 | 省略时回退商户默认出金回调；不可传空字符串或 null |
| `Param` | Body | 否 | `string` | 透传字段 | 最长 255 字符；部分通道用作玩家标识 |
| `Currency` | Body | 否 | `string` | 币种 | 与 Bill 相同多币种枚举；`USDT` 仅 CRYPTO；不填用默认币种；从同币种钱包冻结/扣款 |
| `RecipientEmail` | Body | 否 | `string(email)` | 收款人邮箱 | AUD 通道出金建议传；缺省可用运营通道配置 |
| `Bsb` | Body | 否 | `string` | 澳洲 BSB | AUD 通道出金建议传；缺省可用运营通道配置（仅联调） |
| `Ifsc` | Body | INR 时必填 | `string` | 印度 IFSC | `Currency=INR` 的银行出金必填 |
| `DocumentNumber` | Body | 否 | `string` | 证件号 | 驾照/护照等；AUD 通道出金建议传；缺省可用运营通道配置（仅联调） |

**请求示例（银行转账）：**

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: payout-20260420-001" \
  -d '{
    "RecipientAccount": "12345678",
    "RecipientName": "Alice",
    "RecipientBank": "DBS",
    "Amount": 88.8,
    "Param": "order-001"
  }'
```

**请求示例（澳洲 AUD）：**

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: payout-aud-001" \
  -d '{
    "RecipientAccount": "123456789",
    "RecipientName": "Alice Smith",
    "RecipientBank": "CBA",
    "Amount": 50.00,
    "Currency": "AUD",
    "RecipientEmail": "user@example.invalid",
    "Bsb": "062000",
    "DocumentNumber": "N1234567",
    "Param": "player-001"
  }'
```

**请求示例（印度 INR，须 Ifsc）：**

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: payout-inr-001" \
  -d '{
    "RecipientAccount": "1234567890",
    "RecipientName": "Rahul",
    "RecipientBank": "OTHER",
    "Amount": 1000,
    "Currency": "INR",
    "Ifsc": "HDFC0001234",
    "Param": "wd-inr-001"
  }'
```

##### PayNow（`PayoutMethod=PAYNOW`）

向收款人的 PayNow 代理（UEN 或手机号）出金。由平台处理打款；**商户 API 不返回二维码字符串**。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 同银行转账 |
| `Content-Type` | Header | 是 | `application/json` | - | - |
| `Idempotency-Key` | Header | 否 | `string` | 幂等键 | 同银行转账 |
| `PayoutMethod` | Body | 是 | `PAYNOW` | 出金方式 | 固定 `PAYNOW` |
| `PayNowProxyType` | Body | 是 | `UEN` \| `MOBILE` | PayNow 代理类型 | UEN 或手机号 |
| `PayNowProxy` | Body | 是 | `string` | UEN 或手机号 | 服务端 normalize；须符合新加坡 PayNow 常见格式 |
| `RecipientName` | Body | 是 | `string` | 收款人 PayNow 登记名/显示名 | 1-100 字符 |
| `Amount` | Body | 是 | `number` | 出金本金 | 同银行转账限额规则 |
| `CallbackUrl` / `Param` / `Currency` | Body | 否 | - | 同银行转账 | - |
| `RecipientBank` / `RecipientAccount` / `RecipientEmail` / `Bsb` / `Ifsc` / `DocumentNumber` | Body | **勿传** | - | - | 传了返回 400 |

**请求示例（PayNow）：**

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -d '{
    "PayoutMethod": "PAYNOW",
    "PayNowProxyType": "MOBILE",
    "PayNowProxy": "+65 9123 4567",
    "RecipientName": "Alice",
    "Amount": 88.8,
    "Param": "wd-001"
  }'
```

##### 链上出金（`PayoutMethod=CRYPTO`）

向指定区块链地址转出 USDT。创建成功后冻结 USDT 余额（本金 + 代付费）；**运营确认后**平台提交上链，终态以回调与查询接口为准。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `PayoutMethod` | Body | 是 | `CRYPTO` | 出金方式 | 固定 `CRYPTO` |
| `Chain` | Body | 是 | `TRON \| ETHEREUM \| BSC` | 目标链 | 必须使用大写，小写值返回 400 |
| `ToAddress` | Body | 是 | `string` | 收款链上地址 | 须符合对应链地址格式 |
| `Memo` | Body | 否 | `string` | 链上 Memo/Tag | 部分链或场景需要 |
| `RecipientName` | Body | 是 | `string` | 收款人显示名 | 1-100 字符；用于后台展示 |
| `Amount` | Body | 是 | `number` | 出金本金（USDT） | 须大于 0；受 USDT 出金限额约束 |
| `Currency` | Body | 是 | `USDT` | 币种 | CRYPTO 出金固定 USDT |
| `CallbackUrl` / `Param` | Body | 否 | - | 同银行转账 | - |
| `RecipientBank` / `RecipientAccount` / `RecipientEmail` / `Bsb` / `Ifsc` / `DocumentNumber` / PayNow 字段 | Body | **勿传** | - | - | 传了返回 400 |

当前出金创建/查询响应不返回链名或链上交易哈希；商户应保存创建时的 `Chain` 与 `ToAddress`，响应 `RecipientAccount` 为链上收款地址。需要链上交易凭据时请联系平台支持。

**请求示例（CRYPTO）：**

`ToAddress` 为占位符，调用前必须替换为所选网络的真实有效收款地址。

```bash
curl -X POST https://www.judy88.xin/api/v1/payouts/create \
  -H "Authorization: PaymentKey:PaymentPass" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: payout-crypto-001" \
  -d '{
    "PayoutMethod": "CRYPTO",
    "Chain": "TRON",
    "ToAddress": "REPLACE_WITH_VALID_TRON_ADDRESS",
    "RecipientName": "Alice",
    "Amount": 50,
    "Currency": "USDT",
    "Param": "wd-usdt-001"
  }'
```

**输出字段（三种模式均返回）：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `PayoutId` | 是 | `string` | 平台出金号 |
| `PayoutMethod` | 是 | `BANK_TRANSFER` \| `PAYNOW` \| `CRYPTO` | 出金方式 |
| `PayNowProxyType` | 否 | `UEN` \| `MOBILE` \| null | PayNow 代理类型；银行转账/CRYPTO 时为 null |
| `PayNowProxy` | 否 | `string` \| null | 规范化后的 UEN/手机号；银行转账/CRYPTO 时为 null |
| `Amount` | 是 | `number` | 本金 |
| `RecipientAmount` | 是 | `number` | 收款本金；当前与 Amount 相同 |
| `FeeRate` | 是 | `number` | 本单综合有效费率（`FeeAmount / Amount`） |
| `FeeAmount` | 是 | `number` | 本笔商户代付手续费 |
| `TotalDeductAmount` | 是 | `number` | 冻结/扣款总额（本金+手续费） |
| `Date` | 是 | `string(ISO8601)` | 创建时间 |
| `Error` | 否 | `string\|null` | 创建成功时为 null |
| `Paid` | 是 | `boolean` | 创建时固定为 false |
| `RecipientAccount` | 是 | `string` | 银行转账为账号；PayNow 为规范化代理值；CRYPTO 为链上地址 |
| `RecipientBank` | 是 | `string` | 银行 code；PayNow 为 `PAYNOW`；CRYPTO 为 `CRYPTO` |
| `Currency` | 是 | `string` | 实际币种 |

**校验与约束：**
- 银行转账：`RecipientBank` 必须是已启用银行 code；误传 PayNow 字段 → 400。
- PayNow：须传 `PayNowProxyType` + `PayNowProxy`；误传 `RecipientBank`/`RecipientAccount` → 400；代理格式无效 → 400；QR 生成失败 → 422 `PAYNOW_QR_GENERATION_FAILED`。
- CRYPTO：须 `Currency=USDT` 且传 `Chain` + `ToAddress`；地址格式无效 → 400；创建后状态为 `PENDING`，运营提交上链后为 `PROCESSING`；提交结果不明确时为 `OUTCOME_UNKNOWN`，查清前不会重复提交；成功回调后为 `PAID`。
- 当前无可用出金服务时，可能返回 422 `NO_AVAILABLE_PAYMENT_ACCOUNT` 或 `PAYMENT_ACCOUNT_NOT_FOUND`；CRYPTO 出金还须商户 USDT 钱包余额充足。
- 可用余额不足返回 422 `INSUFFICIENT_BALANCE`。
- 创建成功会先冻结 `TotalDeductAmount`，后续完成时再扣减或释放。

---

#### 2.2.2 查询出金 `GET /api/v1/payouts/{payoutId}`

查询出金处理状态与失败原因，建议用于对账和补偿流程。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `payoutId` | Path | 是 | `string` | 出金号 | - |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `PayoutId` | 是 | `string` | 出金号 |
| `PayoutMethod` | 是 | `BANK_TRANSFER` \| `PAYNOW` \| `CRYPTO` | 出金方式 |
| `PayNowProxyType` | 否 | `UEN` \| `MOBILE` \| null | PayNow 代理类型 |
| `PayNowProxy` | 否 | `string` \| null | 规范化 UEN/手机号 |
| `Amount` | 是 | `number` | 金额 |
| `FeeRate` | 是 | `number` | 代付费率（由本笔手续费/本金推算；如 0.01 表示 1%，固定费可能使有效费率超过 1） |
| `FeeAmount` | 是 | `number` | 代付费 |
| `TotalDeductAmount` | 是 | `number` | 冻结/扣款总额（本金+手续费） |
| `Date` | 是 | `string(ISO8601)` | 创建时间 |
| `Error` | 否 | `string\|null` | 失败原因，成功时为 null |
| `Paid` | 是 | `boolean` | 是否已支付 |
| `Status` | 是 | `PENDING\|APPROVED\|PROCESSING\|OUTCOME_UNKNOWN\|PAID\|FAILED\|CANCELLED` | 出金状态；`OUTCOME_UNKNOWN` 表示厂商是否受理尚待对账 |
| `RecipientAccount` | 是 | `string` | 收款账号 |
| `RecipientBank` | 是 | `string` | 收款银行 |
| `Currency` | 是 | `string` | 币种 |
| `PaidAt` | 否 | `string(ISO8601)\|null` | 完成支付时间，未完成为 null |

**校验与约束：**
- 仅可查询当前商户自己的 `payoutId`。
- `404` 表示出金不存在或不属于当前商户。

**请求示例：**

```bash
curl -X GET https://www.judy88.xin/api/v1/payouts/{payoutId} \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

### 2.3 回调通知

平台主动回调到商户配置的回调地址，不需要商户再鉴权。商户应返回 2xx，并用查询接口做最终确认。

**回调重试策略：** 首次立即尝试；失败后的间隔依次为 1、2、5、10、30、60、120、240 分钟。网络错误/超时、408、425、429 和 5xx 会重试，其他非 2xx 响应停止自动重试。单次请求超时为 10 秒；处理完成或可靠保存通知后及时返回 2xx。

**回调地址与确认规则：** `CallbackUrl` 须为公网 HTTP(S) 地址，不允许 URL 用户名/密码、localhost 或私网地址；不使用时请省略，传空字符串或 `null` 会返回 400。平台不跟随 HTTP 重定向，回调地址应直接返回 2xx。回调当前不附带签名或 `Authorization`，因此必须通过商户后端鉴权查单确认订单、金额与支付状态，再幂等入账。

#### 2.3.1 Bill 支付回调

Bill 支付成功后平台会 POST 回调到商户地址。

**回调 Payload：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `BillId` | 是 | `string` | 账单号 |
| `Amount` | 是 | `number` | 账单金额（买家支付毛额） |
| `FeeRate` | 否 | `number\|null` | 本笔锁定的综合有效费率（`FeeAmount / Amount`）；仅无费用快照的旧数据可能为 `null` |
| `FeeAmount` | 是 | `number` | 手续费金额 |
| `NetAmount` | 否 | `number\|null` | 商家钱包入账净值（`Amount − FeeAmount`）；手续费未确定时为 `null` |
| `BuyerId` | 否 | `string\|null` | 商户侧买家标识 |
| `Paid` | 是 | `boolean` | 是否支付成功 |
| `Param` | 否 | `string\|null` | 透传字段 |
| `ProductName` | 是 | `string` | 商品名 |
| `ShopName` | 是 | `string` | 店铺名 |

**回调示例：**

```json
{
  "BillId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 100,
  "FeeRate": 0.02,
  "FeeAmount": 2,
  "NetAmount": 98,
  "BuyerId": "buyer-1",
  "Paid": true,
  "Param": "order-001",
  "ProductName": "Topup",
  "ShopName": "Demo Shop"
}
```

#### 2.3.2 Payout 回调

**何时会 POST（重要）**

- `POST /api/v1/payouts/create` **仅返回同步 JSON**，`Paid` 固定为 `false`；**不会在创建成功这一刻**向你的回调 URL 再发一条 HTTP POST。
- 当出金产生需要通知的处理结果（例如 **已付**、**拒绝**、**失败**、**取消** 等），且已配置有效回调地址时，平台才会 POST 出金回调。

**回调 URL 优先级**

创建请求体里的 `CallbackUrl` → 商户在后台配置的默认「出金回调」→ 兼容的「默认回调」。**均未配置则不会发出金 HTTP 回调。**

**请求约定**

- 方法：`POST`，`Content-Type: application/json`，UTF-8 JSON。
- 字段名为 **PascalCase**，与下表一致。
- **没有**名为 `Status` 或 `Currency` 的字段；业务结果请用 **`Paid`（布尔）** 与 **`Error`（字符串或 null）** 判断；币种请以 **`GET /api/v1/payouts/{payoutId}`** 等接口为准。

**回调 Payload：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `PayoutId` | 是 | `string` | 平台出金 ID（UUID）；建议以此做幂等，同一笔可能因重试收到多次 POST |
| `Amount` | 是 | `number` | 出金本金（JSON 数字） |
| `FeeRate` | 是 | `number` | 代付费率（由本笔手续费/本金推算；如 0.01 表示 1%，固定费可能使有效费率超过 1） |
| `FeeAmount` | 是 | `number` | 代付手续费 |
| `TotalDeductAmount` | 是 | `number` | 商家钱包冻结/扣款总额（本金+手续费） |
| `Paid` | 是 | `boolean` | 仅当平台侧状态为 **PAID** 时为 `true`；其余情况在组包时刻为 `false`（含失败、取消、处理中快照等） |
| `PayoutMethod` | 是 | `BANK_TRANSFER` \| `PAYNOW` \| `CRYPTO` | 出金方式 |
| `PayNowProxyType` | 否 | `UEN` \| `MOBILE` \| null | PayNow 出金时有值；银行转账/CRYPTO 为 null |
| `RecipientAccount` | 是 | `string` | 银行转账为收款账号；PayNow 为规范化代理值；CRYPTO 为链上地址 |
| `RecipientBank` | 是 | `string` | 银行 code；PayNow 为 `PAYNOW`；CRYPTO 为 `CRYPTO` |
| `Param` | 是 | `string\|null` | 创建时 `Param` 的回显；未传则为 `null` |
| `Error` | 是 | `string\|null` | `Paid` 为 `true` 时为 `null`；`Paid` 为 `false` 时多为失败/拒绝/取消原因；也可能为 `null`（例如仍处理中），此时以查询接口为准 |

**语义小结**

- `Paid: true` 且 `Error: null` → 按成功处理。
- `Paid: false` 且 `Error` 非空 → 未成功，原因见 `Error`。
- `Paid: false` 且 `Error: null` → 未判成功；可能仍在处理，请 **`GET /api/v1/payouts/{payoutId}`** 确认。

**回调示例（成功）：**

```json
{
  "PayoutId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 100,
  "FeeRate": 0.01,
  "FeeAmount": 1,
  "TotalDeductAmount": 101,
  "Paid": true,
  "PayoutMethod": "BANK_TRANSFER",
  "PayNowProxyType": null,
  "RecipientAccount": "12345678",
  "RecipientBank": "DBS",
  "Param": "order-001",
  "Error": null
}
```

**回调示例（失败 / 拒绝 / 取消，Error 示意）：**

```json
{
  "PayoutId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 100,
  "FeeRate": 0.01,
  "FeeAmount": 1,
  "TotalDeductAmount": 101,
  "Paid": false,
  "PayoutMethod": "BANK_TRANSFER",
  "PayNowProxyType": null,
  "RecipientAccount": "12345678",
  "RecipientBank": "DBS",
  "Param": "order-001",
  "Error": "Rejected by operator"
}
```

---

## 三、状态流转说明

### Bill 典型流转

```
PENDING -> PROCESSING -> PAID -> SETTLED
PENDING -> PAID -> SETTLED
PENDING -> EXPIRED
PENDING -> CANCELLED
```

### Payout 典型流转

```
PENDING -> PROCESSING -> PAID
PROCESSING -> OUTCOME_UNKNOWN -> PAID / FAILED
PENDING / PROCESSING -> FAILED / CANCELLED
APPROVED（历史状态） -> PROCESSING
```

出金 HTTP 回调在 **PAID / FAILED / CANCELLED** 等需通知场景下入队（非仅创建时）；正文用 `Paid` / `Error` 表达结果，无单独 `Status` 字段。

---

## 四、关键规则与常见误区

1. Create Bill 与 Create Payout **强烈建议**使用 `Idempotency-Key`（接口允许省略），避免网络重试导致重复业务。
2. `BillAccessToken` 在创建响应中返回（同幂等键重放会再次返回原响应；查询接口不返回），仅用于我方托管页面，不得拼进外部收银台链接。未传 `PayerName` 时用于付款页提交姓名；平台付款页上传转账凭证亦需携带。
3. **不要只依赖 Callback 判定成功**，必须调用查询接口做二次确认。
4. 鉴权失败时优先检查 `PaymentKey` / `PaymentPass`、商户状态、IP 白名单。
5. Payout 创建会先冻结余额，**不等于已打款**；通道/链上须运营提交后才到终态。
6. 新接入必须传 `DepositInstruction`；通道单引导打开完整 `BillUrl`，以响应 `Amount` 为准。
7. 收款/出金服务不可用：可能返回 `422 NO_AVAILABLE_PAYMENT_ACCOUNT` 或 `PAYMENT_ACCOUNT_NOT_FOUND`，请联系平台检查商户已开通的币种、方式和当前可用性。
8. 通道明确拒绝返回 `422 PROVIDER_REJECTED`；通道超时、非 JSON、网络中断等结果未知返回 `424 PROVIDER_ERROR`。响应中的 `error.provider` 为脱敏诊断摘要。`outcome=REJECTED` 可根据 `retryable` 决定是否换新 `Idempotency-Key` 重试；`outcome=UNKNOWN` 应将 `attemptId` 连同请求时间交给平台支持核查，确认未建单后才能换新键，避免重复订单。相同键只会重放首次响应。

---

## 五、上线前检查清单

1. 确认生产回调地址可公网访问，并正确返回 2xx。
2. 确保订单号、`Idempotency-Key`、`Param` 的映射可追踪。
3. 完成 Bill 与 Payout 的成功、失败、超时三类联调用例。
4. 配置请求和回调日志告警，包含状态码与响应体截断记录。
5. 建立人工补单流程：查询异常时可根据 `BillId` / `PayoutId` 快速排查。
6. 完成已开通方式的联调：`BANK_TRANSFER` / `PAYNOW` / `CRYPTO` / `AUTO`；通道外跳收银台；响应 `Amount` 微调；INR UTR（收款）与 `Ifsc`（出金）；出金运营提交后终态。

---

## 六、补充信息与排查顺序

1. **收不到回调**：先检查 Bill/Payout 回调地址、服务防火墙、接口返回码是否为 2xx。
2. **状态不一致**：以查询接口结果为准，并核对最近回调日志与请求幂等键。
3. **出金创建失败**：核对余额、币种与收款信息；如返回 `NO_AVAILABLE_PAYMENT_ACCOUNT`，请联系平台确认对应出金方式已开通且当前可用。
4. **持续异常**请提供 `BillId` / `PayoutId`、请求时间、响应码给平台支持处理。
