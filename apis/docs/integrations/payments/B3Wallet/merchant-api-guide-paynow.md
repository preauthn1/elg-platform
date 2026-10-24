# 商户 API 接入文档（PayNow 收款）

**API Base URL:** `https://www.judy88.xin`

> 本文档仅覆盖 PayNow 收款接入。完整双通道（含银行转账）文档见 [`merchant-api-guide.md`](merchant-api-guide.md)。

---

## 一、快速上手

建议按以下顺序接入，先跑通最小闭环，再扩大流量。

### 第 1 步：获取并保存凭据

从管理员处确认 `PaymentKey` 与 `PaymentPass`，配置好服务器 Base URL、收款/出款回调地址与 IP 白名单。

### 第 2 步：先接入 Create Bill / Query Bill

先打通收款链路：创建账单时在请求体传入 `DepositInstruction`（固定为 `PAYNOW`）。可选传 `PayerName` 预填付款人银行登记姓名，用户打开 `BillUrl` 时将跳过姓名填写；未传则付款页须用户自行填写。

- **PAYNOW**：创建时分配 PayNow 收款户并返回二维码。

创建后跳转 `BillUrl`，后台再用查询接口确认状态。

### 第 3 步：接入 Payout 提现

使用与账单相同的 `PaymentKey:PaymentPass` 请求头调用创建提现接口；建议携带 `Idempotency-Key`。系统会按入金分组自动关联收款户口用于内部记账。

### 第 4 步：联调回调与异常重试

接收 Callback 后仍需主动查询二次确认，并记录幂等键、请求体和响应日志。

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

### 2.1 Bill 收款

#### 2.1.1 创建账单 `POST /api/v1/bills/create`

创建收款账单。建议带 `Idempotency-Key` 防重复请求。响应会返回 `BillId`、`BillUrl`、`BillAccessToken`、`DepositInstruction` 等。

传 `DepositInstruction=PAYNOW` 时系统分配 PayNow 收款户并生成 QR。可选传 `PayerName` 预填并锁定付款人姓名，付款页将跳过姓名步骤；未传则由用户在付款页填写。

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
| `RedirectUrl` | Body | 否 | `string(url)` | 支付完成后跳转地址 | 必须是合法 URL |
| `CallbackUrl` | Body | 否 | `string(url)` | 该订单专用回调地址 | 为空时回退商户默认收款回调 |
| `Param` | Body | 否 | `string` | 透传字段，便于对账 | 最长 255 字符 |
| `DepositInstruction` | Body | 是 | `PAYNOW` | 充值方式 | 固定为 `PAYNOW` |
| `Currency` | Body | 否 | `string` | 币种 | 当前仅支持 `SGD`；不填用商户默认币种（默认 `SGD`） |

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
| `Amount` | 是 | `number` | 回显金额 |
| `Currency` | 是 | `string` | 实际币种 |
| `ExpiresAt` | 是 | `string(ISO8601)` | 账单过期时间 |
| `BillAccessToken` | 是 | `string` | 付款页访问令牌（嵌入 BillUrl 的 `#token=[REDACTED] PayerName 时用于提交姓名 |
| `DepositInstruction` | 是 | `PAYNOW` | 充值通道 |

**校验与约束：**
- 若带 `Idempotency-Key`，建议使用商户订单号或请求唯一键。
- `Currency` 不填时，系统使用商户 `defaultCurrency`（当前默认 `SGD`）。
- Bill 的 `CallbackUrl` 不填时，系统回退 `merchant.billCallbackUrl`（再回退 `merchant.callbackUrl`）。
- 当前对外仅支持 `SGD`：传入非默认币种会返回 `AMOUNT_LIMITS_NOT_DEFINED_FOR_CURRENCY`。
- 低于最小或高于最大返回 `AMOUNT_BELOW_MIN` / `AMOUNT_ABOVE_MAX`。
- `PAYNOW`：创建时分配 PayNow 收款户并执行限流。
- `PayerName`（可选）：传入有效姓名则在创建时锁定，付款页跳过姓名填写；仅空白视为未传。与 `SenderId` 独立。

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
    "DepositInstruction": "PAYNOW",
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
| `Status` | 是 | `PENDING\|PAID\|SETTLED\|EXPIRED\|CANCELLED` | 账单状态 |
| `Param` | 否 | `string\|null` | 透传字段 |
| `ProductName` | 是 | `string` | 商品名 |
| `ShopName` | 是 | `string` | 店铺名 |
| `Amount` | 是 | `number` | 金额 |
| `Currency` | 是 | `string` | 币种 |
| `ExpiresAt` | 是 | `string(ISO8601)` | 过期时间 |
| `PaidAt` | 否 | `string(ISO8601)\|null` | 支付完成时间，未支付为 null |
| `DepositInstruction` | 是 | `PAYNOW` | 充值通道 |

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

取消未完成账单，避免过期前被继续支付。

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
- 取消成功后状态固定为 `CANCELLED`。

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
- 本接入方式创建的账单均会生成 `qrString`，可直接调用本接口获取二维码。

**请求示例：**

```bash
curl -X GET https://www.judy88.xin/api/v1/bills/{billId}/qr \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

### 2.2 Payout 出款

#### 2.2.0 获取出款银行列表 `GET /api/v1/payout-recipient-banks`

返回可用于创建出款时 `RecipientBank` 的银行代码列表（仅包含当前已启用银行）。

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

#### 2.2.1 创建出款 `POST /api/v1/payouts/create`

创建出款请求。请求成功后会先冻结余额（本金 + 代付费 `withdrawRate`）。

**出款方式**：通过 `PayoutMethod` 区分。不传时默认为 `BANK_TRANSFER`（与历史集成完全兼容）。

##### 银行转账（`PayoutMethod=BANK_TRANSFER`，默认）

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `Content-Type` | Header | 是 | `application/json` | 请求体 JSON 编码 | - |
| `Idempotency-Key` | Header | 否 | `string` | 幂等请求键 | 相同 key+相同请求体返回首次响应 |
| `PayoutMethod` | Body | 否 | `BANK_TRANSFER` | 出款方式 | 不传默认为银行转账 |
| `RecipientAccount` | Body | 是 | `string` | 收款账号 | 1-50 字符 |
| `RecipientName` | Body | 是 | `string` | 收款人姓名 | 1-100 字符 |
| `RecipientBank` | Body | 是 | `string` | 收款银行 | 必须是已启用的银行代码；建议先调用 `GET /api/v1/payout-recipient-banks` |
| `Amount` | Body | 是 | `number` | 出款金额（本金） | 必须大于 0；若配置了提款上下限须在范围内 |
| `CallbackUrl` | Body | 否 | `string(url)` | 该笔出款专用回调地址 | 为空时回退商户默认出款回调 |
| `Param` | Body | 否 | `string` | 透传字段 | 最长 255 字符 |
| `Currency` | Body | 否 | `string` | 币种 | 当前仅支持 `SGD`；不填用商户默认币种 |

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

##### PayNow（`PayoutMethod=PAYNOW`）

向收款人的 PayNow 代理（UEN 或手机号）出款。平台在创建时生成 SGQR 供运营扫码打款；**商户 API 不返回 QR 字符串**。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 同银行转账 |
| `Content-Type` | Header | 是 | `application/json` | - | - |
| `Idempotency-Key` | Header | 否 | `string` | 幂等键 | 同银行转账 |
| `PayoutMethod` | Body | 是 | `PAYNOW` | 出款方式 | 固定 `PAYNOW` |
| `PayNowProxyType` | Body | 是 | `UEN` \| `MOBILE` | PayNow 代理类型 | UEN 或手机号 |
| `PayNowProxy` | Body | 是 | `string` | UEN 或手机号 | 服务端 normalize；须符合新加坡 PayNow 常见格式 |
| `RecipientName` | Body | 是 | `string` | 收款人 PayNow 登记名/显示名 | 1-100 字符；写入 SGQR 显示名 |
| `Amount` | Body | 是 | `number` | 出款本金（QR 金额） | 同银行转账限额规则 |
| `CallbackUrl` / `Param` / `Currency` | Body | 否 | - | 同银行转账 | - |
| `RecipientBank` / `RecipientAccount` | Body | **勿传** | - | - | 传了返回 400 |

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

**输出字段（两种模式均返回）：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `PayoutId` | 是 | `string` | 平台出款号 |
| `PayoutMethod` | 是 | `BANK_TRANSFER` \| `PAYNOW` | 出款方式 |
| `PayNowProxyType` | 否 | `UEN` \| `MOBILE` \| null | PayNow 代理类型；银行转账时为 null |
| `PayNowProxy` | 否 | `string` \| null | 规范化后的 UEN/手机号；银行转账时为 null |
| `Amount` | 是 | `number` | 本金 |
| `FeeAmount` | 是 | `number` | 代付费 |
| `TotalDeductAmount` | 是 | `number` | 冻结/扣款总额（本金+手续费） |
| `Date` | 是 | `string(ISO8601)` | 创建时间 |
| `Error` | 否 | `string\|null` | 创建成功时为 null |
| `Paid` | 是 | `boolean` | 创建时固定为 false |
| `RecipientAccount` | 是 | `string` | 银行转账为账号；PayNow 为规范化代理值 |
| `RecipientBank` | 是 | `string` | 银行 code；PayNow 固定为 `PAYNOW` |
| `Currency` | 是 | `string` | 实际币种 |

**校验与约束：**
- 银行转账：`RecipientBank` 必须是已启用银行 code；误传 PayNow 字段 → 400。
- PayNow：须传 `PayNowProxyType` + `PayNowProxy`；误传 `RecipientBank`/`RecipientAccount` → 400；代理格式无效 → 400；QR 生成失败 → 422 `PAYNOW_QR_GENERATION_FAILED`。
- 商户在入金分组内无可用 ACTIVE 收款户时返回 422 `PAYMENT_ACCOUNT_NOT_FOUND`。
- 可用余额不足返回 422 `INSUFFICIENT_BALANCE`。
- 创建成功会先冻结 `TotalDeductAmount`，后续完成时再扣减或释放。

---

#### 2.2.2 查询出款 `GET /api/v1/payouts/{payoutId}`

查询出款处理状态与失败原因，建议用于对账和补偿流程。

**输入参数：**

| 字段 | 位置 | 必填 | 类型 | 说明 | 规则 |
|------|------|------|------|------|------|
| `Authorization` | Header | 是 | `string` | 鉴权凭据 | 缺失或错误返回 401 |
| `payoutId` | Path | 是 | `string` | 出款号 | - |

**输出字段：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `PayoutId` | 是 | `string` | 出款号 |
| `PayoutMethod` | 是 | `BANK_TRANSFER` \| `PAYNOW` | 出款方式 |
| `PayNowProxyType` | 否 | `UEN` \| `MOBILE` \| null | PayNow 代理类型 |
| `PayNowProxy` | 否 | `string` \| null | 规范化 UEN/手机号 |
| `Amount` | 是 | `number` | 金额 |
| `Date` | 是 | `string(ISO8601)` | 创建时间 |
| `Error` | 否 | `string\|null` | 失败原因，成功时为 null |
| `Paid` | 是 | `boolean` | 是否已支付 |
| `Status` | 是 | `PENDING\|APPROVED\|PROCESSING\|PAID\|FAILED\|CANCELLED` | 出款状态 |
| `RecipientAccount` | 是 | `string` | 收款账号 |
| `RecipientBank` | 是 | `string` | 收款银行 |
| `Currency` | 是 | `string` | 币种 |
| `PaidAt` | 否 | `string(ISO8601)\|null` | 完成支付时间，未完成为 null |

**校验与约束：**
- 仅可查询当前商户自己的 `payoutId`。
- `404` 表示出款不存在或不属于当前商户。

**请求示例：**

```bash
curl -X GET https://www.judy88.xin/api/v1/payouts/{payoutId} \
  -H "Authorization: PaymentKey:PaymentPass"
```

---

### 2.3 回调通知

平台主动回调到商户配置的回调地址，不需要商户再鉴权。商户应返回 2xx，并用查询接口做最终确认。

**回调重试策略（分钟级退避）：** 0, 1, 2, 5, 10, 30, 60, 120, 240

#### 2.3.1 Bill 支付回调

Bill 支付成功后平台会 POST 回调到商户地址。

**回调 Payload：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `BillId` | 是 | `string` | 账单号 |
| `Amount` | 是 | `number` | 账单金额 |
| `BuyerId` | 否 | `string\|null` | 商户侧买家标识 |
| `Paid` | 是 | `boolean` | 是否支付成功 |
| `Param` | 否 | `string\|null` | 透传字段 |
| `ProductName` | 是 | `string` | 商品名 |
| `ShopName` | 是 | `string` | 店铺名 |

**回调示例：**

```json
{
  "BillId": "550e8400-e29b-41d4-a716-446655440000",
  "Amount": 120.5,
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
- 当运营侧产生需要通知的结果（例如标记 **已付**、**拒绝**、**失败**、**取消** 等），且该笔出款当时能解析到回调 URL，平台才会将一条出款回调 **入队** 并 POST 到你配置的地址（与运营手动「再次入队回调」同类机制）。

**回调 URL 优先级**

创建请求体里的 `CallbackUrl` → 商户在后台配置的默认「出款回调」→ 兼容的「默认回调」。**均未配置则不会发出款 HTTP 回调。**

**请求约定**

- 方法：`POST`，`Content-Type: application/json`，UTF-8 JSON。
- 字段名为 **PascalCase**，与下表一致。
- **没有**名为 `Status` 或 `Currency` 的字段；业务结果请用 **`Paid`（布尔）** 与 **`Error`（字符串或 null）** 判断；币种请以 **`GET /api/v1/payouts/{payoutId}`** 等接口为准。

**回调 Payload：**

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `PayoutId` | 是 | `string` | 平台出款 ID（UUID）；建议以此做幂等，同一笔可能因重试收到多次 POST |
| `Amount` | 是 | `number` | 出款金额（JSON 数字） |
| `Paid` | 是 | `boolean` | 仅当平台侧状态为 **PAID** 时为 `true`；其余情况在组包时刻为 `false`（含失败、取消、处理中快照等） |
| `PayoutMethod` | 是 | `BANK_TRANSFER` \| `PAYNOW` | 出款方式 |
| `PayNowProxyType` | 否 | `UEN` \| `MOBILE` \| null | PayNow 出款时有值；银行转账为 null |
| `RecipientAccount` | 是 | `string` | 银行转账为收款账号；PayNow 为规范化代理值 |
| `RecipientBank` | 是 | `string` | 银行 code；PayNow 为 `PAYNOW` |
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
  "Amount": 88.8,
  "Paid": true,
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
  "Amount": 88.8,
  "Paid": false,
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
PENDING -> PAID -> SETTLED
PENDING -> EXPIRED
PENDING -> CANCELLED
```

### Payout 典型流转

```
PENDING -> APPROVED -> PROCESSING -> PAID
           （历史）   -> FAILED（拒绝/失败）
                    -> CANCELLED（取消）
```

出款 HTTP 回调在 **PAID / FAILED / CANCELLED** 等需通知场景下入队（非仅创建时）；正文用 `Paid` / `Error` 表达结果，无单独 `Status` 字段。

---

## 四、关键规则与常见误区

1. Create Bill 与 Create Payout **必须**使用 `Idempotency-Key`，避免网络重试导致重复业务。
2. `BillAccessToken` 仅在创建响应返回一次，须随 `BillUrl` 下发给用户。未传 `PayerName` 时用于付款页提交姓名。
3. **不要只依赖 Callback 判定成功**，必须调用查询接口做二次确认。
4. 鉴权失败时优先检查 `PaymentKey` / `PaymentPass`、商户状态、IP 白名单。
5. Payout 创建会先冻结余额，完成后再扣减或释放，需在财务侧区分冻结与可用金额。
6. PayNow 账单在创建时即分配收款户并生成 QR，可通过 `GET /api/v1/bills/{billId}/qr` 获取二维码字符串。

---

## 五、上线前检查清单

1. 确认生产回调地址可公网访问，并正确返回 2xx。
2. 确保订单号、`Idempotency-Key`、`Param` 的映射可追踪。
3. 完成 Bill 与 Payout 的成功、失败、超时三类联调用例。
4. 配置请求和回调日志告警，包含状态码与响应体截断记录。
5. 建立人工补单流程：查询异常时可根据 `BillId` / `PayoutId` 快速排查。
6. 已联调 Create Bill（`PAYNOW` 创建即有收款户与 QR，可选 PayerName 跳过姓名步骤）。

---

## 六、补充信息与排查顺序

1. **收不到回调**：先检查 Bill/Payout 回调地址、服务防火墙、接口返回码是否为 2xx。
2. **状态不一致**：以查询接口结果为准，并核对最近回调日志与请求幂等键。
3. **出款创建失败**：检查入金分组与收款户配置、余额与币种是否一致。
4. **持续异常**请提供 `BillId` / `PayoutId`、请求时间、响应码给平台支持处理。
