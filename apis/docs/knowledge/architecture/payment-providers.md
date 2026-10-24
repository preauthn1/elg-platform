---
type: Architecture
id: architecture/payment-providers
title: 金流商子系統
description: 金流商 Provider 的目錄要有哪些檔、怎麼註冊在 config/payment.php，以及代收代付回調從哪裡進來。
domain: 金流
tags: [金流商, Payment Provider, 代收, 代付, 回調, config/payment.php, trait]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
evidence:
  - { kind: code, ref: /config/payment.php, title: "provider code → Driver class 註冊表", at: 2026-10-21 }
  - { kind: code, ref: /app/Services/Payment/Providers/ }
  - { kind: code, ref: /routes/external/api.php, title: "代收代付回調入口" }
---

# 金流商子系統

金流商整合統一放在 `app/Services/Payment/Providers/{Name}/`，並以 provider code 註冊在 `config/payment.php` 的 `provider` 陣列。陣列的 key 是金流商代碼，value 是 `Driver` class。

## 目錄結構（六個檔）

同時做代收與代付的 provider 必須包含：

| 檔案 | 用途 |
|---|---|
| `Driver.php` | provider 入口，實作統一介面 |
| `Deposit.php` | 代收（充值）下單邏輯 |
| `DepositCallback.php` | 代收回調（金流商通知入帳） |
| `Withdrawal.php` | 代付（取款）下單邏輯 |
| `WithdrawalCallback.php` | 代付回調（金流商通知出款結果） |
| `{Name}Trait.php` | 共用的 HTTP 與簽名邏輯；trait 成員保持 `protected` |

參考實作：`app/Services/Payment/Providers/EpicPay/`（CLAUDE.md 列為範例）。

## 註冊

`config/payment.php` 的 `provider` 陣列把金流商代碼對應到 Driver class。協定相同的金流商，可以用一層很薄的繼承沿用同一個 Driver，所以一個 Driver 可能被多個代碼共用，例如：

- `ANTZPAY` 沿用 `Kira\Driver`。兩者協定同源，差異只在代付的明確拒絕判定與交易號前綴。
- `SPEEDPAY` 沿用 `PayMate\Driver`。端點差異寫在 `Data/providers/SpeedPay.php`，不另立目錄。
- `TTPAY` 沿用 `DgPay\Driver`，是 DgPay 換了名稱的白牌版本。
- `DGPAY` 與 `DG` 共用同一個 `DgPay\Driver`，只是代碼不同。

## 代收代付回調入口

External 面向的 `routes/external/api.php` 掛 `deposit/notify/{agentCashFlowId?}` 與 `withdrawal/notify/{agentCashFlowId?}`，分別交給 `CashFlow\DepositNotifyController` 與 `WithdrawalNotifyController` 處理，再分派到對應 provider 的 `DepositCallback` 與 `WithdrawalCallback`。這兩條路由刻意不掛 throttle（限流，詳見 [architecture/api-facets](api-facets.md)）。

## 目前 Providers 目錄清單

A3Pay、AkPay、AllPay、AntzPay、Ap8Pay、B3Wallet、BigPayz、BillionPay、CCPayment、DgPay、EasyPay、EpicPay、FPay、FlashPay、GMPay、GffPay、GlobePay、Kira、LegendPay、MajesticPay、MaxPay、PayEssence、PayMate、PayPro、QuickPay、Sure、SurePay、TakaPay、TgPay、TngPin、TopPay、TrustPay、TtPay、VaderPay、VtPay、YeePay、ZPay。

## 邊界

- **進口**：
  - External 面向的 `deposit/notify` 與 `withdrawal/notify` 路由（三方回調）。
  - 內部由 `app/Services/CashFlow/` 等業務層呼叫 `Deposit`、`Withdrawal` 下單。
- **出口**：Provider 的 `{Name}Trait.php` 以 `Http::logAction()` 呼叫金流商的 API；回調結果寫回 `agent_cash_flows` 與訂單表。

## 相關文件

- [decisions/0002-redundancy-via-extra-payment-channels](../decisions/0002-redundancy-via-extra-payment-channels.md)
- [architecture/api-facets](api-facets.md)
- `apis/external/`：金流回調 Controller 的骨架，目前只產 rules 引用到的
- [rules/payment-callback-common-checks](../rules/payment-callback-common-checks.md)、[rules/payout-submit-and-status](../rules/payout-submit-and-status.md)，以及各家金流商的 `rules/provider-*`
