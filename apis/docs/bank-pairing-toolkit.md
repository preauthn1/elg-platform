# 銀行配對工具組使用說明書

> **版本**：v1.0（2026-05-15）
> **適用對象**：APIs 專案 dev、客服支援工程師
> **合入紀錄**：以 `feature/bank-pairing-toolkit-v2` 分支合入 master（merge commit `ff616c000`），該分支已刪除
> **設計來源**：設計與實作計畫已隨落地歸檔移除（git commit `d180e117f`）
> **狀態**：⚠️ **過渡期工具**。這是操作現行 `banks` 表的應急 SOP，與「金流重構計畫」是兩條獨立的工作，不是該計畫的某個階段。重構計畫第 2 階段切換新表結構（`agent_banks` / `agent_bank_names` / `cash_flow_banks`）後，本工具組需重寫對齊或部分停用。金流重構計畫的文件已刪除，要看最後版本請用 `git show 'f8b144f25^:docs/金流重構計畫.md'`。

---

## 1. 這個工具組解決什麼問題

過去處理「銀行對不上、前端看不到、DB 重複 row」等議題依賴 dev 個案處理 + 記憶。本工具組把這些知識變成可重複執行的命令 + 可教 LLM 的 SOP，讓 dev 與客服支援都能用一致流程處理。

三個面向：

| 面向 | 工具 | 用途 |
|---|---|---|
| **補配置** | `bank:audit-feed` + skill `bank-config-supplement` | 找出 `Data/banks/{X}.php` 缺什麼 alias / entry |
| **修 DB** | `bank:fix-mismatch` + skill `bank-mismatch-fix` | 把 banks 表的 name / 重複 row 對齊到 standard |
| **診斷** | `bank:why-missing` + skill `bank-why-missing` | 8 step checklist 找出某銀行為何前端看不到 |

---

## 2. 快速上手 — 自然語言觸發 skill

平時叫 Claude 處理時，**用自然語言描述問題**就會自動載入對應 skill。下表是常見講法與對應的 skill：

| 你會這樣說 | 自動載入的 skill |
|---|---|
| 「補 IDR 銀行」「處理 X 幣別 no-match」「sync 跑出 low-confidence」 | `bank-config-supplement` |
| 「補 alias / 補 keyword」「銀行匹配信心度低」「銀行清單缺項」 | `bank-config-supplement` |
| 「修 banks 表 mismatch」「合併重複銀行」「DB 銀行對齊 standard」 | `bank-mismatch-fix` |
| 「standard_name 不一致」「banks 表 duplicate」 | `bank-mismatch-fix` |
| 「為何前端看不到 X 銀行」「agent Y 看不到某銀行」 | `bank-why-missing` |
| 「withdrawal/banks 少了某銀行」「客服回報銀行清單缺項」 | `bank-why-missing` |
| 「提款銀行不見了」 | `bank-why-missing` |

---

## 3. 三個典型情境完整流程

### 情境 A：接新 provider 後 sync 跑出 no-match

**症狀**：`cash-flow:sync` 跑完 console 噴 low-confidence warning、新銀行沒被建立、frontend 看不到新銀行。

**對話範例**：

```
你：FPay 新接的 IDR 配對沒過幾家，幫我補配置

Claude（觸發 bank-config-supplement）：
1. 跑 php artisan bank:audit-feed --currency=IDR --json
2. 列出 lost / low-conf-feed 條目
3. 對每筆判斷：加 alias、新增 entry、或拆 entry
4. 編輯 Data/banks/IDR.php
5. 重跑 audit 確認歸 0
6. commit "fix: 補 IDR 銀行清單 N 筆 no-match entry"
```

### 情境 B：客服回報「某銀行前端看不到」

**症狀**：會員打不到提款銀行、後台看到銀行存在但 App 列表沒有、`withdrawal/banks` 回傳少筆。

**對話範例**：

```
你：客服說 agent 4249 的會員看不到 (IDR) - Bank Mandiri，幫我查

Claude（觸發 bank-why-missing）：
1. 確認 bank_id（如未提供會反問）
2. 跑 php artisan bank:why-missing --bank=N --agent=4249
3. 8 step checklist 找出失敗點
4. 提出對應修復命令（見 §6）
5. 套用修復後再跑一次驗證 visible=true
```

### 情境 C：DB 跟 standard 不一致需要清理

**症狀**：banks 表有重複 row、name 跟 standard 不對、後台一個銀行有多筆、`agent_cash_flows.info.available_banks` 殘留舊 code。

**對話範例**：

```
你：修 IDR banks 表的 mismatch

Claude（觸發 bank-mismatch-fix）：
1. 前置確認：bank:audit-feed --currency=IDR 必須歸 0
2. 跑 bank:fix-mismatch --currency=IDR (audit-only)
3. 確認 mismatch / duplicate 分類合理
4. dry-run 預覽
5. backup + apply
6. 重跑驗證歸 0
7. commit "fix: 修正 IDR banks 表 N 筆 mismatch / 合併 M 組 duplicate"
```

---

## 3-1. 判定一列 `banks` 是不是「沒人用、可以停用」

引用面有**四張**表，缺一張就可能誤殺服務中的列：

| 表 | 查法 | 漏查的後果 |
|---|---|---|
| `member_bank_accounts` | `where('bank_id', $id)` | 會員已綁定的銀行變孤兒 |
| `agent_cash_flows.info` 的 `available_banks` | `JSON_SEARCH(JSON_EXTRACT(info,'$.available_banks[*].BankCode_ELG'),'one', $code)` | 代付舊路徑以我方碼查 entry，該行選不到 |
| **`cash_flow_banks`** | `where('bank_id', $id)->orWhere('bank_code', $code)` | **最容易漏**——代付查表以此為準且要求 `status=1`，停用的結果是**斷付**而非降級 |
| `withdrawal_orders.bank_code` | `where('bank_code', $code)`，重點看在途（21／22／23）與可重發（29） | 在途單以碼的快照查 entry，查不到會擋單 |

⚠️ **`cash_flow_banks` 必須 `bank_id` 與 `bank_code` 都查**：已實證的 drift 形態就是 `bank_id` 指 base 層列、`bank_code` 是白標群組層碼，只查其一會漏。

⚠️ **「有引用」與「引用有效」是兩件事**：`cash_flow_banks.status=0` 的引用是死引用（查表本來就要求 `status=1`），停用該 banks 列不改變行為——但這要**明示放行**，不該由判定邏輯自行推定。

🔴 **判定前先想清楚「這列到底是什麼」**：曾有 8 列在越南盾幣別下掛著印尼銀行名，查了會員綁定與 `available_banks` 都是零就判定「幣別錯置、可停用」，漏查 `cash_flow_banks` ⇒ 其中一列的引用**啟用中**且被兩個服務中的代付通道使用。被預檢攔下後性質整個翻轉：不是「印尼銀行錯置到越南盾」，而是「越南盾的銀行列被錯誤命名成印尼銀行名」——三方碼是真實的越南銀行，只有我方 `name` 掛錯。**name 掛錯與幣別錯置的處置方向完全相反**（前者該改名、後者才該停用）。

## 3-2. 怎麼確認一個三方銀行碼是有效的

三方文檔的「常用對照表」**不能用來否證**某個碼無效（通常只列常用者並註明「以後台設定為準」，且格式可能與實際存的不同）。更可靠的做法是**查已收斂站台生產中的 ACF**：

```sql
SELECT id, JSON_LENGTH(info,'$.available_banks') AS n,
       LEFT(JSON_EXTRACT(info,'$.available_banks'), 1200) AS head
FROM agent_cash_flows WHERE cash_flow_id = <cf> AND status = 1;

-- 取單一 entry（JSON_TABLE 在唯讀 MCP 的 parser 會被拒，改用字串定位）
SELECT SUBSTRING(JSON_EXTRACT(info,'$.available_banks'),
       LOCATE('"<ELG_CODE>"', JSON_EXTRACT(info,'$.available_banks')) - 2, 90)
FROM agent_cash_flows WHERE id = <acf>;
```

⚠️ 邊界：部分 provider 的 bank list 是**按商戶動態查詢**的，不同商戶清單理論上可不同 ⇒ 跨站對照屬**強證據而非鐵證**。要鐵證得打三方 API，而那需要該站有配置好的憑證。

## 4. Command 速查

> 所有命令必須在容器內執行：
> ```bash
> docker exec php-fpm8 bash -c "cd /var/www/APIs && <command>"
> ```

### 4.1 `bank:audit-feed` — 補配置稽核（read-only）

```bash
# 全 5 幣別
php artisan bank:audit-feed

# 單一幣別
php artisan bank:audit-feed --currency=IDR

# JSON 輸出（給 skill / 其他工具消費）
php artisan bank:audit-feed --currency=IDR --json
```

**輸出兩類**：
- `lost`：DB 銀行 row 名稱在 config 找不到任何匹配
- `low_confidence_feed`：provider feed 進來時匹配信心度 < 80

**修復路徑**：dev 編輯 `app/Console/Commands/CashFlow/Data/banks/{X}.php`（不會自動寫入）

---

### 4.2 `bank:fix-mismatch` — DB 修復

```bash
# audit-only（預設，不寫入）
php artisan bank:fix-mismatch --currency=IDR

# JSON 輸出
php artisan bank:fix-mismatch --currency=IDR --json

# 唯讀預演（會逐筆跑完整預檢，不需備份）
php artisan bank:fix-mismatch --currency=IDR --apply --dry-run

# 套用 + 備份（--apply 強制要求備份決定）
php artisan bank:fix-mismatch --currency=IDR --apply --backup --force
```

**輸出兩類**：
- `mismatch`：DB row name 不等於 standard，需要 rename
- `duplicate`：多筆 DB row 解析到同一個 standard，需要合併

**安全機制**：
- 預設 audit-only，必須加 `--apply` 才寫入
- `--dry-run` 唯讀預演：與實跑**共用同一批判斷方法**，逐筆報「會合併／會留置＋原因」，
  並列出受影響的 row id——含代付 ACF 同時含兩碼而不會被改寫的那幾張（那是需要人工接手的部分）
- `--apply` **強制要求備份決定**：帶 `--backup`，或以 `--skip-backup` 明示跳過（會再確認一次）
- `--backup` 建**同庫備份表** `<原表名>_bak_<時間戳>`（配置表整表快照、大表只備 dup 引用列；
  manifest 落 `storage/app/backups/table-backup/`；同日多次執行時間戳自動遞增互不覆蓋）。不再委派 `bank:standardize --step=backup`
  ——那條路整表載入記憶體，生產量級（withdrawal_orders 143 萬筆）會 OOM（2026-08-25 VWorld 實測）
- 跑完自動**校驗**：live vs 備份表逐列比對，差異標注預期內／預期外，預期外醒目警示
- 每筆 duplicate **各自一個交易**（一筆撞唯一鍵不會回滾同組其他列），FK 連動 6 張表
  ＋ `agent_cash_flows.info.available_banks` JSON
- `--prune-inactive-collisions`（2026-08-25 Codex 複查後定案；gate 4 於 2026-08-26 擴充）：
  撞鍵留置組中符合任一判準者刪冗餘列（審計 `mismatch_cfb_pruned` 逐列記 row id）後放行合併——
  ①「**恰一側 cfb `status=0`、另一側 `status=1`、且停用側 provider 碼有活列承接**」刪停用列
  ②「**兩側皆 `status=1` 且 provider 碼嚴格相同**」刪 duplicate 側（送三方的碼一樣＝挑碼問題
  不存在；primary 側 `bank_code` 與合併後 `banks.code` 天然一致）。
  gate 缺一即擋：兩側 status／provider 碼取自**同一次鎖內查詢**；「status=0」單獨不能證明
  配置已移除（`cash-flow:sync` 有 matching collision 情境），所以要求活列承接；provider 碼
  比對在 PHP 端嚴格 `===`（不用 SQL `=`，避免 collation 誤判）；同組任一撞點不合格則
  **整組零寫入留置**（all-or-nothing，避免「沒合併但列已被刪」的半套）。
  強制 `--backup`、不接受 `--skip-backup`

> ⚠️ **`order_images` 不在連動清單內**：`order_images.bank_id` 指的是 `bank_accounts.id`
> 而非 `banks.id`（實測佐證見 `BankRepairService` 的類別註解），改到會靜默指錯收款帳戶。

**回滾**：`db:backup-rollback`——從備份表**逐列還原**指定 row（2026-08-25 新增）。

```bash
# 預設 dry-run：逐列列出「現值 → 會還原成的備份值」
php artisan db:backup-rollback --stamp=<備份戳> --table=banks --ids=1371,1372

# --execute 才寫入（需確認），每次執行記審計 action=backup_rollback
php artisan db:backup-rollback --stamp=<備份戳> --table=cash_flow_banks --ids=55 --execute

# 被 prune 刪除的列：--recreate-missing 以備份值 INSERT 重建（保留原 id）
php artisan db:backup-rollback --stamp=<備份戳> --table=cash_flow_banks --ids=55 --execute --recreate-missing
```

- 回滾語意是**逐列覆寫指定 id**，不是整表灌回——整表 TRUNCATE 後灌回會抹掉備份之後新進的
  資料，該路徑（原 `bank:standardize --restore`）已隨命令刪除而消滅（2026-08-26）。
- **重建被刪列**（`--recreate-missing`，2026-08-25 新增）：live 已不存在的列以備份值 INSERT
  重建、保留原 id（`cash_flow_banks` 無 inbound FK，兩站已以 `KEY_COLUMN_USAGE` 實查）；
  插入撞主鍵／唯一鍵時整批回滾 fail-closed——槽位被佔代表狀態已變，先依**相反順序**還原
  （先 restore 佔位的活列、再重建被刪列），不要硬插。未帶此旗標時缺列只回報 missing 不重建。
- 要回滾哪些列由人決定：先看跑完的校驗報告與 `bank_dedup_logs` 的 `mismatch_*` 審計再點名。
- `bank:dedup-rollback` 消費的是 exact 白名單（`repoint`／`deactivate_binding`／…），本命令的
  審計一律 `mismatch_` 前綴，天然隔離、不會被它誤消費。
- 備份表由 `db:prune-table-backups` 排程保留 15 天後自動清除（也可人工提前 `DROP TABLE`）。
- 機制是通用的（`TableBackupService`）：`agent:add-agent-cash-flow` 亦套用同一套備份／回滾／清除。

---

### 4.3 `bank:why-missing` — 可見性診斷（read-only）

三模式（依選項自動判斷）：

```bash
# 模式 A：單一銀行 + agent
php artisan bank:why-missing --bank=N --agent=M

# 模式 B：列出 agent + 幣別下所有不可見銀行
php artisan bank:why-missing --agent=M --currency=IDR

# 模式 C：掃 agent 在某 cf 下所有 feed bank 可見性
php artisan bank:why-missing --agent=M --cashflow=N

# JSON 輸出
php artisan bank:why-missing --bank=N --agent=M --json
```

**輸出**：每銀行的 8 step checklist + 第一個失敗 step + 修復建議命令

---

## 5. 8-step 可見性檢查鏈（why-missing 內部邏輯）

對前端 `frontend/api/withdrawal/banks` 路由（`WithdrawalController::getBanks`）的可見鏈拆解：

| Step | 檢查項 | 失敗代表什麼 | 修復工具 |
|---|---|---|---|
| 1 | `cash_flow_banks` 是否有此 bank（`cash_flow_id` + `currency_id` + `bank_code`，`status=1`）| Provider feed 沒回傳，或 sync 跳過 | 跑 `bank:audit-feed` 看是否在 low-conf-feed |
| 2 | `AgentCashFlow` (`type=3`) 存在？ | Agent 沒設代付配置 | 跑 `agent:add-agent-cash-flow --agent=N --cashflow=M --currency=X` |
| 3 | `AgentCashFlow.status` = 1 | ACF 被停用 | 啟用該 ACF（手動 SQL 或後台）|
| 4 | `available_banks` JSON 包含此 `bank_code`？| ACF 重建時漏掉 | 重跑 `agent:add-agent-cash-flow` |
| 5 | `available_banks` entry 的 `status` ≠ 0 | 該銀行被個別停用 | 後台調整 |
| 6 | `banks` 表有對應 row（`agent_group_id = agent.cash_flow_group_id`）| `syncBanksToAgentGroup` 沒跑或失敗 | 重跑 `agent:add-agent-cash-flow` |
| 7 | `banks.status` = 1 | banks 表 row 被停用 | 手動啟用 |
| 8 | OrderType（withdrawal type）啟用 | 提款方式被停用 | 後台啟用 |

任何一步失敗 → bank 不會出現在前端清單。

---

## 6. 補 config 判斷規則（沉澱自 IDR 處理經驗）

skill `bank-config-supplement` 內建以下規則，dev 編輯 `Data/banks/{X}.php` 時也應遵守：

### 6.1 Syariah / UUS 一律獨立 entry

❌ 不要把 `Bank XXX Syariah` 加到 `Bank XXX` 的 alias  
✅ 新增獨立 entry，否則 keyword 會互打

```php
// ❌ 錯誤
[
    'standard_name' => '(IDR) - Bank BCA',
    'aliases' => ['BCA', 'Bank BCA Syariah'],  // 不要把 Syariah 塞這裡
],

// ✅ 正確
[
    'standard_name' => '(IDR) - Bank Central Asia',
    'aliases' => ['BCA', 'Bank BCA', 'Bank Central Asia'],
],
[
    'standard_name' => '(IDR) - Bank BCA Syariah',  // 獨立 entry
    'aliases' => ['Bank BCA Syariah', 'BCA Syariah', 'BankBCASyariah'],
],
```

### 6.2 多詞 keyword 必須是「片語」

❌ `['permata', 'syariah']` — 會把所有 Syariah 子行誤匹配到 Permata 主行  
✅ `['permata syariah']` — 整段片語精確匹配

### 6.3 地區性 BPD 用全名 + 縮寫雙別名

```php
[
    'standard_name' => '(IDR) - Bank Nagari',
    'aliases' => [
        'Bank Nagari',
        'BPD Sumatera Barat',
        'BPD Sumbar',
        'Bank Sumatera Barat',
    ],
    'keywords' => ['nagari', 'sumbar', 'sumatera barat'],
],
```

### 6.4 名稱前綴規範

`banks.name` 與 config 的 `standard_name` 一律使用 `(CUR) - {Name}` 前綴。
Aliases 可不帶前綴（detector 會自動容忍）。

### 6.5 掃描範圍

工具預設處理 `banks.agent_group_id = 0`（平台級銀行），不處理 agent 客製銀行。

### 6.6 廢棄銀行也加 entry（避免 audit 持續 nag）

像 RBS（已退出印尼）、Bank IFI（已停業）等銀行，DB 仍有 row 時，加上 entry 並用註解標示：

```php
[
    'standard_name' => '(IDR) - Royal Bank of Scotland (RBS)',
    'aliases' => ['Royal Bank of Scotland', 'RBS', 'RBS Indonesia'],
    'keywords' => ['royal bank of scotland', 'rbs'],
    // 已於 2017 年退出印尼市場
],
```

---

## 7. 配置檔案結構

`app/Console/Commands/CashFlow/Data/banks/{CURRENCY}.php` 範例：

```php
<?php

declare(strict_types=1);

return [
    [
        'standard_name' => '(MYR) - Maybank',
        'aliases' => [
            'Maybank',
            'May Bank',
            'Malayan Banking',
            'Malayan Banking Berhad',
            'MBB',
            'MAYBANK2U',
        ],
        'keywords' => [
            'maybank',
            'malayan',
            'maybank2u',
        ],
    ],
    // ...
];
```

支援 5 個幣別檔案：`MYR.php` / `VND.php` / `SGD.php` / `INR.php` / `IDR.php`。

---

## 8. 工程細節

### 8.1 程式碼結構

```
app/
├── Services/CashFlow/Bank/
│   ├── BankMismatchAuditor.php    # 4 detector：lost / low-conf-feed / mismatch / duplicate
│   ├── BankRepairService.php      # rename + duplicate merge + FK 連動 6 張表 + available_banks JSON
│   └── BankVisibilityChecker.php  # 8-step checklist
└── Console/Commands/CashFlow/Bank/
    ├── BankAuditFeedCommand.php   # bank:audit-feed
    ├── BankFixMismatchCommand.php # bank:fix-mismatch
    └── BankWhyMissingCommand.php  # bank:why-missing

# skill 在 GFF-Marketplace repo 的 apis-toolkit plugin
plugins/apis-toolkit/skills/
├── bank-config-supplement/SKILL.md
├── bank-mismatch-fix/SKILL.md
└── bank-why-missing/SKILL.md

tests/Unit/
├── Services/CashFlow/Bank/         # service 層測試（共 18 tests）
└── Console/Commands/CashFlow/      # command 層測試（共 10 tests）
```

### 8.2 匹配引擎

底層使用既有 `App\Helpers\StandardBankNameHelper::matchBankName()`（8 階段策略：DB 精確/別名/模糊 → Config 精確/別名/模糊 → keyword → 字串相似度）。

新增 `BankMismatchAuditor::lookupInConfig()` 跳過 DB 自我匹配，並容忍 `(CUR) - ` 前綴差異（commit `1cf9e7b0f`）。

### 8.3 重複合併連動的 6 張表

`BankRepairService::reassignForeignKeys()` 處理：

1. `withdrawal_orders.bank_id`
2. `member_bank_accounts.bank_id`
3. `cash_flow_banks.bank_id`（`bank_code` 是否一併改，依該列分類決定，見 `BankRepairService` 的類別註解）
4. `realname_validation_requests.bank_id`
5. `agent_bank_accounts.bank_id`
6. `agent_real_name_validation_requests.bank_id`

外加 `agent_cash_flows.info.available_banks[].BankCode_ELG`（JSON 內容置換）。

`order_images.bank_id` 刻意不在清單內，理由見 4.2 的提醒：它指的是 `bank_accounts.id`，不是 `banks.id`。新增任何引用 `banks.id` 或 `banks.code` 的資料表時，必須同步登記進這份清單。

### 8.4 DB 備份還原

`--backup` 建同庫備份表 `<原表名>_bak_<時間戳>`（見 4.2「安全機制」；舊的
`bank:standardize --step=backup` 委派已於 2026-08-25 移除——整表載入在生產量級 OOM）。

還原：`db:backup-rollback` 逐列還原（見 4.2「回滾」）；原 `bank:standardize --restore`
（整表灌回、會抹掉備份後新資料）已隨命令於 2026-08-26 刪除。

---

## 9. 已知 follow-up

從 IDR 試水（commit `554596f27`）發現：

1. **Chained mismatch**：銀行歷經多次併購（如 Harda → Bank Harda Internasional → Allo Bank）會跨 entry，目前需跑兩次 apply 才能完整收斂。建議 `bank:fix-mismatch` 加 fixed-point loop。
2. **Config 命名衝突**：同一名稱（如 `Bank Harda Internasional`）同時是某 entry 的 alias 又是另一個 standalone standard_name。需 dev 整理。
3. **Step 8 OrderType 檢查為簡化版**：目前只看 `bank.type > 0`，未來可深化為查 `agent_cash_flow_order_types`。

---

## 10. 快速指令樣板

複製貼上即可：

```bash
# 全 5 幣別 audit
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:audit-feed"

# IDR 補完 config 後驗證
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:audit-feed --currency=IDR"

# IDR DB 修復 dry-run（唯讀預演，逐筆跑完整預檢）
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:fix-mismatch --currency=IDR --apply --dry-run"

# IDR DB 修復正式套用 + 備份
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:fix-mismatch --currency=IDR --apply --backup --force"

# 疏失時逐列回滾（預設 dry-run；見 4.2「回滾」）
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan db:backup-rollback --stamp=<備份戳> --table=banks --ids=<id,...>"

# 診斷單一銀行
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:why-missing --bank=727 --agent=4249"

# 列出 agent 看不到的所有 IDR 銀行
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:why-missing --agent=4249 --currency=IDR"
```

---

## 11. 參考資料

| 內容 | 路徑 |
|---|---|
| 設計 / 計畫 / pilot run log | 已隨落地歸檔移除（git commit `d180e117f`） |
| 匹配引擎 | `app/Helpers/StandardBankNameHelper.php` |
| 銀行配置 README（舊版）| `app/Console/Commands/CashFlow/Data/banks/README.md` |
| 前端 controller（會員 App 取提款銀行清單） | `app/Http/Controllers/Frontend/WithdrawalController.php` 的 `getBanks()` |
| AgentCashFlow 建立邏輯 | `app/Console/Commands/CashFlow/AddAgentCashFlow.php` 的 `handle()`；`available_banks` 由 `buildAvailableBanks()` 產生 |

---

## 12. 維護者紀錄

| 版本 | 日期 | 變更 |
|---|---|---|
| v1.0 | 2026-05-15 | 工具組首次發布（feature/bank-pairing-toolkit branch） |
