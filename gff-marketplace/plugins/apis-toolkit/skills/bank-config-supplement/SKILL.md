---
name: bank-config-supplement
description: 補銀行資料 / 處理 X 幣別 no-match / sync 跑出 low-confidence / 補 alias / 補 keyword / 銀行匹配信心度低。當執行 bank:audit-feed 後需要編輯 Data/banks/{X}.php 補 alias、keyword、entry 時自動載入。🚫 「前端 / 會員 App 看不到某銀行、清單缺項、提款銀行不見了」這類診斷走 bank-why-missing；要清理 DB banks row 對齊 standard 走 bank-mismatch-fix。
---

# 補銀行配置 SOP

當使用者說「補 IDR 銀行」「處理 X 幣別 no-match」「sync 跑出 low-confidence」「補 alias」等時觸發。

## 環境

本 skill 的 `docker exec` 命令只讀指定白標的 DB（config 的修改是改 APIs repo 裡的檔案，不寫 DB）。開工前先確認白標，所有 `docker exec` 依 `whitelabel-container-exec` 帶 `-e DB_CONNECTION=<白標>`。純讀的 SQL 查詢可改走 `whitelabel-db-query`。

## 流程

### 1. 跑 audit 取得待補清單

```bash
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:audit-feed --currency={X} --json"
```

兩類待補：
- `lost`                : DB 銀行 row 無法匹配任何 standard
- `low_confidence_feed` : provider feed 進來時匹配信心度 < 80

### 2. 對每筆套用判斷規則

| 觀察 | 動作 |
|---|---|
| feed name 是既有 entry 的拼寫變體（例 `BCA` vs `Bank Central Asia`） | 加到該 entry 的 `aliases` |
| feed name 是 Syariah / UUS 子行（例 `Bank BCA Syariah`） | **新增獨立 entry**，不加到主行 alias（避免污染） |
| feed name 含 keyword 但會誤匹配（例 `permata` + `syariah` 兩個 keyword 會誤中 Bank Permata 主行） | 改為片語 keyword（`permata syariah`） |
| feed name 是地區性 BPD（例 `BPD Sumbar` vs `Nagari`） | 加到該 BPD entry 的 `aliases` |
| 完全找不到對應 standard | 新增 entry，命名遵循 `(CUR) - {Name}` 規範 |

### 3. 直接編輯 `app/Console/Commands/CashFlow/Data/banks/{X}.php`

維持既有結構，aliases 多筆放陣列、keywords 用 lowercase 片語。

#### 命名規範檢查（重要）

`standard_name` **必須** 含 `(CUR) - {Name}` 前綴。若該幣別檔案大量使用無前綴格式（例如目前 SGD.php 多筆 `'United Overseas Bank'` 缺前綴），補 config 時順手把該檔案所有 standard_name 統一加上前綴 — audit 會把無前綴的 entry 算成 lost。

### 4. 重跑驗證

```bash
docker exec -e DB_CONNECTION=<白標> php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:audit-feed --currency={X}"
```

確認 lost / low-confidence-feed 歸 0（或剩下的是 dev 確認可忽略的廢棄銀行）。

### 5. Commit

```
fix: 補 {CUR} 銀行清單 N 筆 no-match entry
```

這一步是修正既有資料，類型用 `fix`；標題與 body 的寫法照 `git-commit` skill。

## 已知判斷規則（沉澱自 IDR 處理經驗）

1. **Syariah / UUS 一律獨立 entry**：不要把 `Bank XXX Syariah` 加到 `Bank XXX` 的 alias
2. **多詞 keyword 必須是「片語」**：`['permata', 'syariah']` ❌；`['permata syariah']` ✅
3. **地區 BPD 用全名 + 縮寫雙別名**：例如 `BPD Sumatera Barat` + `BPD Sumbar` 都加進 Bank Nagari aliases
4. **名稱前綴規範**：`banks.name` 與 config `standard_name` 一律 `(CUR) - {Name}` 前綴
5. **掃描範圍**：`banks` 表 `agent_group_id = 0`（平台級銀行）

## 不要做

- ❌ 未經使用者確認就寫入 config：先把每筆的判斷（加 alias／新 entry／改片語 keyword）列表給使用者確認，再編輯
- ❌ 修改 DB（DB 修復走 skill `bank-mismatch-fix`）
- ❌ 跨幣別批次處理（一次一個幣別 commit）
