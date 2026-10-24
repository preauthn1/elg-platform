# 標準銀行名稱配置使用說明

## 📋 概述

標準銀行名稱配置系統用於統一各金流商的銀行名稱和代碼，確保資料一致性。

## 📁 文件結構

```
app/Console/Commands/CashFlow/Data/banks/
├── MYR.php                  # 馬來西亞令吉銀行配置 ✅
├── VND.php                  # 越南盾銀行配置 ✅
├── IDR.php                  # 印尼盾銀行配置 ✅
├── SGD.php                  # 新加坡元銀行配置 ✅
├── INR.php                  # 印度盧比銀行配置 ✅
└── README.md                # 使用說明
```

> 舊的 `database/data/standard_bank_codes.php` 已退場（2026-08-28）：內容全數併入
> 上方分檔，`SyncStandardBanksSeeder` 隨部署直接讀分檔 upsert 至各站 `standard_banks`。
> 結構不變式（欄位齊全／code 前綴與唯一／同幣別名稱不爭用）由
> `tests/Unit/Data/StandardBankRegistryTest.php` 把關。
>
> 沒有 BDT（currency_id 19）分檔是刻意擱置、不是漏掉：Dev 有零星 BDT 銀行列但無業務需求，
> 等業務裁決後再建檔；在那之前 sync 對 BDT 是 no-op。

## 🔑 標準銀行代碼系統

### 代碼格式

我司標準銀行代碼格式：`{幣別}_{銀行縮寫}`

例如：
- `MYR_MAYBANK` - 馬來西亞 Maybank
- `VND_TECHCOMBANK` - 越南 Techcombank
- `IDR_BCA` - 印尼 Bank Central Asia
- `SGD_DBS` - 新加坡 DBS Bank

### 配置結構（banks/{CUR}.php 合併版條目）

```php
[
    'code' => 'MYR_MAYBANK',            // 我司標準代碼：{幣別}_{名稱}，必以幣別前綴開頭
    'standard_name' => '(MYR) - Maybank', // 正規化目標名稱（與 banks.name 比對，含幣別前綴）
    'name' => 'Maybank',                // 標準名稱（不含前綴，存 standard_banks.name）
    'swift' => 'MBBEMYKL',              // SWIFT BIC（純資訊屬性：可為 null、可跨列重複，不作任何鍵）
    'aliases' => [                      // 別名（精確匹配用）
        'Maybank',
        'Malayan Banking',
        'MBB',
    ],
    'keywords' => ['maybank', 'malayan'], // 關鍵字（模糊匹配用）
]
```

補充規則：

- **code 前綴與 currency 互為校驗**：可用一條查詢揪出掛錯幣別的列；每碼必含底線，
  結構上不可能與 8 碼 BIC 同形。
- **Corporate Account 變體為獨立列**：code 取 `{本尊碼}_CORP`（如 `MYR_MAYBANK_CORP`），
  swift 沿用本尊（同一機構）。
- **條目順序有語意**：先出現者優先命中（Corporate 條目必須排在對應一般銀行之前），
  不可依字母或 code 重排。
- **兩個過渡欄位讓「寫進 `standard_banks` 的列」與「`cash-flow:sync` 看到的條目」暫時不同**
  （規則與理由的權威出處是 `App\Services\CashFlow\Bank\StandardBankRegistry` 的 docblock）：
  - `retired_into`：填現在那家的 code，用在已合併或改名的銀行。寫進 `standard_banks` 時這一列設成停用、
    名稱與別名併進現在那家；`cash-flow:sync` 照舊用這個條目比對，金流商代碼不會改掛。
  - `sync_visible => false`：條目只寫進 `standard_banks`，`cash-flow:sync` 與 `bank:fix-mismatch` 看不到它；
    它的名稱與別名在 `standard_banks` 只歸它。
  - 兩者都等各站舊名銀行列收成一列後才拿掉（見 `docs/plans/2026-10-08-bank-registry-audit/`）。
- `third_party_codes` 已廢止：三方碼權威在通道配置層（`cash_flow_banks.provider_bank_code`
  與 ACF `available_banks`），標準銀行表不再持有。

## 🛠️ 銀行代碼標準化命令（已退役）

`bank:standardize` 已於 2026-08-26 刪除（原 deprecated 禁跑：改 banks.code 時不同步
cash_flow_banks.bank_code，代付查表會斷付）。替代路徑：

- agent 層碼收斂走 `bank:rename-bank`（單組）／`bank:converge-cross-code`（批次編排）
- base（group 0）收斂走 `bank:merge-base-duplicate-banks`／`bank:merge-base-synonym-banks`
- 名稱／重複修復走 `bank:fix-mismatch`（合併邏輯已抽至 `BankRepairService` 共用）

## 📊 已支援的幣別

| 幣別代碼 | 幣別名稱 | 配置檔案 | 銀行數量 | 狀態 |
|---------|---------|---------|---------|------|
| MYR | 馬來西亞令吉 | MYR.php | 47 | ✅ 已完成 |
| VND | 越南盾 | VND.php | 54 | ✅ 已完成 |
| IDR | 印尼盾 | IDR.php | 168 | ✅ 已完成 |
| SGD | 新加坡元 | SGD.php | 57 | ✅ 已完成 |
| INR | 印度盧比 | INR.php | 40 | ✅ 已完成 |


## 🎯 配置文件格式

每個幣別檔為條目陣列（結構見上方「配置結構」）。命名規則：

1. **standard_name**：格式 `(幣別代碼) - 銀行英文名稱`（如 `(MYR) - Maybank`），
   幣別代碼大寫；INR 為歷史例外（不帶前綴）。
2. **code**：`{幣別}_{名稱}` 全大寫語意碼；名稱段去除 Bank / Berhad 等通用字
   （`Bank of X` 的 Bank 保留）。既有碼不可改名（會被 standard_bank_id 身分鏈消費）。

## 💡 使用方式

### 1. 基本用法

```php
use App\Helpers\StandardBankNameHelper;

// 取得標準銀行名稱
$standardName = StandardBankNameHelper::getStandardName('MYR', 'Maybank');
// 返回：(MYR) - Maybank

// 如果找不到，使用預設值
$standardName = StandardBankNameHelper::getStandardName('MYR', 'Unknown Bank', '(MYR) - Unknown Bank');
```

### 2. 標準化銀行名稱

```php
// 從三方名稱提取銀行簡稱
$bankKey = StandardBankNameHelper::extractBankKey('(MYR) - HSBC Bank');
// 返回：HSBC Bank

// 標準化銀行名稱
$standardName = StandardBankNameHelper::standardize('MYR', '(MYR) - CIMB Bank');
// 返回：(MYR) - CIMB Bank

// 如果輸入不包含幣別前綴
$standardName = StandardBankNameHelper::standardize('MYR', 'Public Bank');
// 返回：(MYR) - Public Bank
```

### 3. 批量標準化

```php
// 標準化銀行列表
$banks = [
    ['name' => '(MYR) - Maybank', 'code' => 'MBB'],
    ['name' => '(MYR) - CIMB', 'code' => 'CIMB'],  // 名稱不標準
];

$standardizedBanks = StandardBankNameHelper::standardizeBankList('MYR', $banks);
// 結果：
// [
//     ['name' => '(MYR) - Maybank', 'code' => 'MBB'],
//     ['name' => '(MYR) - CIMB Bank', 'code' => 'CIMB'],  // ✅ 已標準化
// ]
```

### 4. 取得所有標準銀行

```php
// 取得 MYR 所有標準銀行名稱
$allBanks = StandardBankNameHelper::getAllStandardNames('MYR');
// 返回：['(MYR) - Maybank', '(MYR) - CIMB Bank', ...]

// 檢查銀行是否存在
$exists = StandardBankNameHelper::exists('MYR', 'Maybank');
// 返回：true
```

## 🔧 在 SyncCashFlowsCommand 中使用

修改 `processSingleWithdrawalBank` 方法：

```php
use App\Helpers\StandardBankNameHelper;

protected function processSingleWithdrawalBank(
    CashFlow $cashFlow,
    int $currencyId,
    string $currencyCode,
    array $option,
    array &$stats
): void {
    // 提取銀行資訊
    $name = trim($option['name'] ?? '');
    $providerBankCode = trim($option['code'] ?? '');

    // 🎯 使用標準銀行名稱
    $bankKey = StandardBankNameHelper::extractBankKey($name);
    $standardName = StandardBankNameHelper::getStandardName(
        $currencyCode,
        $bankKey,
        $name  // 找不到時使用原名稱
    );

    $this->line(sprintf('    處理銀行: %s', $standardName));

    // 使用標準名稱查找或建立銀行
    $bank = $this->ensurePlatformBank(
        $standardName,  // ✅ 使用標準名稱
        $currencyId,
        $channel,
        $cashFlow,
        $stats
    );

    // ...
}
```

## 📊 實際效果

### 修改前

不同金流商可能使用不同的銀行名稱：

```
EpicPay:  (MYR) - HSBC Bank
FPay:     (MYR) - HSBC Bank
GMPay:    (MYR) - HSBC

資料庫結果：
- (MYR) - HSBC Bank  (ID: 1)
- (MYR) - HSBC       (ID: 2)  ❌ 重複
```

### 修改後

所有金流商使用統一的標準名稱：

```
EpicPay:  (MYR) - HSBC Bank  ✅
FPay:     (MYR) - HSBC Bank  ✅
GMPay:    (MYR) - HSBC Bank  ✅

資料庫結果：
- (MYR) - HSBC Bank  (ID: 1)  ✅ 統一
```

## 🚀 建立新幣別配置

### 步驟 1：收集銀行資料

從 `providers/` 目錄下的配置文件中收集該幣別的所有銀行：

```bash
# 搜尋 VND 銀行
grep -r "VND.*-" app/Console/Commands/CashFlow/Data/providers/
```

### 步驟 2：建立配置文件

建立 `banks/VND.php`：

```php
<?php

declare(strict_types=1);

return [
    'Techcombank' => '(VND) - Techcombank',
    'Vietcombank' => '(VND) - Vietcombank',
    'Sacombank' => '(VND) - Sacombank',
    // ...
];
```

### 步驟 3：測試

```php
$standardName = StandardBankNameHelper::getStandardName('VND', 'Techcombank');
// 返回：(VND) - Techcombank
```

## ⚠️ 注意事項

1. **大小寫敏感**
   - Key 是大小寫敏感的
   - 建議統一使用標準的英文名稱（首字母大寫）

2. **名稱變體**
   - 同一銀行可能有多個變體名稱
   - 在配置中統一為一個標準名稱

3. **新增銀行**
   - 新增前先檢查是否已存在類似名稱
   - 避免重複或相似的銀行名稱

4. **維護一致性**
   - 所有金流商應使用相同的銀行簡稱作為 Key
   - 定期檢查並統一命名

## 📝 維護清單

- [x] MYR 馬來西亞令吉
- [ ] VND 越南盾
- [ ] SGD 新加坡元
- [ ] IDR 印尼盾
- [ ] THB 泰銖
- [ ] INR 印度盧比

---

**文檔版本**：1.0  
**最後更新**：2026-01-22  
**維護者**：RB
