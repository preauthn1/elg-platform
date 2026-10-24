# cash-flow:sync 使用說明

**功能**：讀取 `Data/cash_flows.php`，將金流商配置同步至資料庫。

## 影響資料表

| 資料表 | 操作 | 條件 |
|--------|------|------|
| `cash_flows` | INSERT / UPDATE | 每次執行都會處理 |
| `order_types` | INSERT / UPDATE | 僅限 type=1（代收）的金流商 |
| `banks` | INSERT / UPDATE | 僅限 type=3（代付）的金流商 |
| `cash_flow_banks` | 先停用該金流商的全部對應，再依銀行名稱比對重新啟用或新建 | 僅限 type=3（代付）的金流商；名稱比對不中的對應會維持停用，見下方「對 TPower 同步 PayPro 代付後，要補跑回填並抽驗」 |

## 參數說明

| 參數 | 說明 | 範例 |
|------|------|------|
| `--ids` | 指定要同步的金流商 ID，多個用逗號分隔；省略則同步全部 | `--ids=79,80` |
| `--currency` | 只同步指定幣別的渠道與銀行；支援幣別代碼或 ID | `--currency=VND` 或 `--currency=3` |
| `--rename-key` | 顯式改鍵 `舊key=新key`：config 裡的渠道 key 改名時，就地更新 `order_types.key`，order type 的 id 不變，商戶與通道的關聯自動跟上；可指定多次 | `--rename-key=OLD_KEY=NEW_KEY` |
| `--dry-run` | 乾跑模式：執行完畢後回滾，用於預覽將要執行的操作 | `--dry-run` |

## 執行模式比較

| 項目 | 正常模式 | `--dry-run` |
|------|:--------:|:-----------:|
| 執行操作 | ✓ | ✓ |
| 驗證邏輯 | ✓ | ✓ |
| 顯示訊息 | ✓ | ✓ |
| 提交變更 | ✓ | ✗ |
| 回滾變更 | ✗ | ✓ |

## 使用範例

```bash
# 同步所有金流商
php artisan cash-flow:sync

# 只同步指定 ID 的金流商
php artisan cash-flow:sync --ids=79,80

# 只同步指定 ID 的 VND 渠道
php artisan cash-flow:sync --ids=79,80 --currency=VND

# 預覽操作（不寫入資料庫）
php artisan cash-flow:sync --ids=79,80 --currency=VND --dry-run
```

## ⚠️ 對 TPower 同步 PayPro 代付後，要補跑回填並抽驗

同步完要補跑回填命令，再抽驗代付是否正常。回填命令是名稱比對漏掉時的安全網，抽驗才是確認有沒有漏的步驟。

**什麼時候適用**：在 TPower 執行 `cash-flow:sync --ids=102`（102 是 PayPro 代付），而且 `--currency` 是 IDR 或沒有指定。`cash-flow:auto-sync` 內部會呼叫本命令，同樣適用。

**為什麼**：同步會先停用這個金流商在 `cash_flow_banks`（金流商銀行對照表，記錄我方銀行碼對應到哪個代付銀行碼）裡的全部對應，再依銀行名稱比對，重新啟用或新建。TPower 有幾家 IDR 銀行與電子錢包，會員綁的是本地碼 `TPIDxx`，這些對應能不能恢復，完全看名稱對不對得上。

- **現況**：依 2026-09-15 的 TPower 資料逐家核對了四家，都對得上。DANA、LinkAja 的銀行名稱與 PayPro 資料相同（只差大小寫也算）；Maybank、OVO 靠 `app/Console/Commands/CashFlow/Data/banks/IDR.php` 裡的銀行別名（同一家銀行的其他寫法）對上。所以同步會自己把這四家的對應重新啟用。這是讀代碼、查資料推論出來的，沒有實際執行同步驗證；其他綁 `TPIDxx` 的銀行沒有逐家核對。
- **什麼情況會漏**：平台這邊的銀行改名、PayPro 資料的銀行名稱改掉，或銀行別名被改掉、刪掉，名稱就對不上，對應會維持停用。之後代付會判定「不支援這家銀行」，出款失敗或退款。2026-07-11 TPower 的 DANA 代付事故就是這樣發生的：當時平台這邊的銀行名稱是「(IDR) - DANA eWallet」，PayPro 資料寫的是「(IDR) - Dana」。

**同步後要做的事**：

1. 補跑回填，先預覽再執行。回填命令重跑幾次結果都一樣：對應都還在時，每一筆都顯示跳過、不會寫入；缺漏或被停用的，才會新建或重新啟用。

   ```bash
   php artisan cash-flow:backfill-tpower-payout-bankcodes --dry-run
   php artisan cash-flow:backfill-tpower-payout-bankcodes
   ```

2. 回填有寫入時，重跑 `agent:add-agent-cash-flow`，把補回的對應寫回金流商戶設定裡的 `available_banks`（派單前檢查「這家銀行支不支援」時讀的清單）。先預覽，確認無誤後拿掉 `--dry-run` 再執行：

   ```bash
   php artisan agent:add-agent-cash-flow --agent=<代理 ID> --cashflow=102 --currency=IDR --dry-run
   ```

3. 抽驗，這步不能省。回填命令只管它自己清單內的銀行，清單外的銀行漏了它不會發現，所以不能只看命令有沒有跑完：
   - `php artisan cash-flow:verify-payout-bank-parity --cash-flow-id=102`：比對兩條查代付銀行碼的路徑（讀金流商戶設定裡的 `available_banks`、查 `cash_flow_banks`）給出的結果是否一致。
   - `php artisan withdrawal:check-payout-channels <提款單 ID>`：列出這張提款單可以派給哪些金流商戶代付。它只看金流商戶設定裡的 `available_banks`，顯示可用不代表出款一定成功，要和上一條一起看。

回填命令有東西要寫入時，會先要求人工確認，而且沒有略過確認的選項，所以不能當成非互動步驟串進其他命令。

## 幣別過濾警示

使用 `--currency` 時，若指定的金流商沒有配置該幣別的渠道或銀行，系統會顯示警告：

```
⚠️  FPay (ID: 67) 沒有配置 SGD (新加坡元) 的代收渠道
⚠️  GMPay (ID: 74) 沒有配置 VND (越南盾) 的代付銀行
```

## 幣別代碼對照表

| 代碼 | ID | 名稱 |
|------|----|------|
| MYR | 1 | 馬來西亞令吉 |
| SGD | 2 | 新加坡元 |
| VND | 3 | 越南盾 |
| IDR | 6 | 印尼盾 |
| THB | 9 | 泰銖 |
| USDT | 10 | 泰達幣 |
| HKD | 16 | 港幣 |
