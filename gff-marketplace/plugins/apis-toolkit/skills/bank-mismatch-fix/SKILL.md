---
name: bank-mismatch-fix
description: 修 banks 表 mismatch / 合併重複銀行 / standard_name 不一致 / banks 表 duplicate / DB 銀行對齊 standard。當 bank:audit-feed 已歸 0 後需要清理 DB 中與 standard 不符的 banks row 時自動載入。🚫 「前端 / 會員 App 看不到某銀行、清單缺項、提款銀行不見了」這類診斷走 bank-why-missing；matcher no-match / low-confidence、要補 alias/keyword 到 Data/banks/{X}.php 走 bank-config-supplement。
---

# 修 banks 表 DB 不一致 SOP

當使用者說「修 banks 表 mismatch」「合併重複銀行」「DB 銀行對齊 standard」等時觸發。

## ⚠️ 先判斷：是哪一種「重複銀行」

「合併重複銀行」有兩條不同工具，先分流再動手：

| 情境 | 工具 |
|---|---|
| 依**幣別**把 `banks` 表對齊 standard 命名、合併解析到同 standard 的 row（config 補完後的標準化） | **本 skill**（`bank:fix-mismatch --currency={X}`，見下方流程）|
| **逐白標**清 `withdrawal/banks` 的 active 同碼重複（同 `agent_group_id+type+currency_id+code`）、**會員綁定感知 re-point**、含審計／guard 約束／一鍵 rollback | APIs `/bank-dedup-rollout` 命令 |

> **`/bank-dedup-rollout`**（APIs repo `.claude/commands/`，逐白標 SOP）：dry-run → 綁定影響評估 → `--execute` → guard 唯一約束 → 驗證／回滾，寫入步驟逐步 confirm；底層 `bank:dedup-active`／`bank:guard-active-banks`／`bank:dedup-rollback`，審計表 `bank_dedup_logs`，依 `whitelabel-container-exec` 注入 `DB_CONNECTION`。背景與理由見 APIs `.claude/commands/bank-dedup-rollout.md` 本身與 `bank:dedup-active` 等命令 class 的 docblock（原設計文件已於落實後刪除，不歸檔）。
>
> 名稱不符的同碼重複（疑似 code 重用）兩邊都不自動合併 → 人工裁決。

## 環境

本 skill 的命令會寫入指定白標的 DB。開工前先確認白標，所有 `docker exec` 依 `whitelabel-container-exec` 帶 `-e DB_CONNECTION=<白標>`；生產白標要寫入時另依該 skill 的「生產白標預設『連得上但寫不進去』（dbc 從庫）」一節注入主庫 port。純讀的 SQL 查詢可改走 `whitelabel-db-query`。

## ⚠️ 前置條件

**先跑完 `bank-config-supplement` 把 config 補完，否則修一半 config 又補了會白做。**

驗證 config 已乾淨：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:audit-feed --currency={X}"
```

`lost` 與 `low_confidence_feed` 都應為 0（或剩餘為廢棄銀行 dev 確認可忽略）。**未確認前不要往下走。**

## 流程

### 1. 跑 audit

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:fix-mismatch --currency={X}"
```

兩類待修：
- `mismatch`  : DB row name ≠ standard，rename
- `duplicate` : 多 row 解析到同 standard，合併

### 2. 確認分類合理

人工檢視輸出：
- `mismatch` 看每筆的 `current → target` 是否符合預期
- `duplicate` 看 primary 與 duplicates 是否真的應該合併

### 3. dry-run 預覽

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:fix-mismatch --currency={X} --apply --dry-run --force"
```

確認預覽輸出與 step 2 audit 一致、沒有非預期的修改。

### 4. 套用（含備份）

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:fix-mismatch --currency={X} --apply --backup --force"
```

### 5. 重跑 audit 確認歸 0

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:fix-mismatch --currency={X}"
```

`mismatch` 與 `duplicate` 應為 0。

### 6. Commit

```
fix: 修正 {CUR} banks 表 N 筆 mismatch / 合併 M 組 duplicate
```

這一步是修正既有資料，類型用 `fix`；標題與 body 的寫法照 `git-commit` skill。

> **本 commit 只動 DB，不會有檔案 diff** — Git pre-commit 會抱怨「沒檔案改動」。
> 解法：在同一 PR 中跟 Phase 2.5 manual config 補丁混在一起，或改用 `git commit --allow-empty`（後者較少見，要先說明原因）。

## 不要做

- ❌ 跳過 dry-run 直接 apply
- ❌ 跳過 backup（一旦合併出錯難復原）
- ❌ 一次 apply 多幣別（一個幣別一個 commit，便於 review 與 rollback）
- ❌ 在 config 未補完時跑 fix-mismatch（會誤判尚未有 alias 的銀行為 lost、漏修）

## 風險與回滾

- backup 預設寫在 `app/backups/bank-standardize/` 目錄
- 若需要回滾：跑 `php artisan bank:standardize --restore=<檔案>` 載入備份
- 合併後的 row（status=0）保留在 DB 中作為審計痕跡，不要硬刪
