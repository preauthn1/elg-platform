---
description: 逐白標清理 withdrawal/banks 的 active 重複銀行（dry-run → 綁定評估 → execute → guard → 驗證），含寫入 confirm 與回滾。
argument-hint: "<白標>（如 TPower / VWorld / Yes2Win）"
disable-model-invocation: true
---

目標白標：$ARGUMENTS

---

> 🛑 **先停下，不要執行 `--execute`**：六站清理都已完成。`bank:dedup-active` 判定保留哪一列（survivor）的依據會過期，營運方手動切過流量的列可能被判成重複，照本 SOP 清理會把會員綁定改指到已停用的列。遇到新的重複銀行時，先回報使用者，不照本 SOP 走。

> 本 command 驅動 `withdrawal/banks` 重複銀行清理的逐白標 SOP。底層命令：`bank:dedup-active`（偵測/清理）、`bank:guard-active-banks`（防再生約束）、`bank:dedup-rollback`（回滾）；審計寫 `bank_dedup_logs`。背景：同一 `(agent_group_id, type, currency_id, code)` 存在新舊兩筆 active 列（2026 匯入未與既有列收斂，`banks` 對這組自然鍵沒有唯一約束），前台 `withdrawal/banks` 清單因而出現同名重複。survivor 取每組最新 `created_at` 的列；active 重複的 loser 只改 `status=0`、不刪（`WithdrawalOrder::bank()` 是沒有 status 過濾的 belongsTo，刪列會讓歷史單解析回 null）；`deleted_at` 只由 shadow（inactive 同名重複）清理標記，命令刻意先清 shadow 再清 active，避免本輪剛停用的 loser 被標刪。約束做成命令而非 migration，是因為 dedup 測試刻意插入兩筆同鍵 active 列模擬清理前狀態，自動套用會讓測試失敗，且 MySQL DDL 非交易性無法回滾。
>
> **強制紀律**：
> - 依 `whitelabel-container-exec` skill 對白標注入 `-e DB_CONNECTION=$ARGUMENTS`、雙層環境標示、首次切換前查 `bootstrap/cache/config.php`。
> - dry-run 與評估查詢為唯讀，可直接跑；**`--execute` 與 `bank:guard-active-banks` 為生產寫入／DDL，每一步先文字 confirm（等使用者回 yes/確認）才跑**。
> - 未指定白標、或無法對應 registry → 停下來問，不猜。

## Step 0 — 前置

1. 環境標示：回應首行 `🔌 當前環境：$ARGUMENTS`。
2. 查 config cache（存在會使注入失效）：
   ```bash
   docker exec php-fpm8 bash -c "cd /var/www/APIs && ls bootstrap/cache/config.php 2>/dev/null && echo HAS_CACHE || echo OK"
   ```
   `HAS_CACHE` → 先 `php artisan config:clear`。
3. 確認審計表已部署（`--execute` 需要）：
   ```bash
   docker exec -i -e DB_CONNECTION=$ARGUMENTS php-fpm8 bash -c "cd /var/www/APIs && php artisan migrate:status 2>&1 | grep -i bank_dedup"
   ```
   顯示 `Ran` → OK；`Pending` 或查無 → 提示需先部署／`php artisan migrate`（migrate 屬寫入，需 confirm）。

## Step 1 — dry-run 盤點（唯讀）

```bash
docker exec -i -e DB_CONNECTION=$ARGUMENTS php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:dedup-active"
```

回報組數。0 組 → 該白標無重複，跳到 Step 5 詢問是否仍要上約束後結束。

## Step 2 — 綁定影響評估（唯讀，決定能否安全清）

用 `whitelabel-db-query`（mysql MCP）對 $ARGUMENTS 跑下列查詢，逐 loser 標：名稱是否與 survivor case-fold 相符、會員綁定數、出款訂單數：

```sql
WITH ranked AS (
  SELECT b.id, b.agent_group_id, b.type, b.currency_id, b.code, b.name, b.created_at,
         COUNT(*) OVER (PARTITION BY b.agent_group_id,b.type,b.currency_id,b.code) AS grp_cnt,
         ROW_NUMBER() OVER (PARTITION BY b.agent_group_id,b.type,b.currency_id,b.code ORDER BY b.created_at DESC, b.id DESC) AS rn,
         FIRST_VALUE(b.name) OVER (PARTITION BY b.agent_group_id,b.type,b.currency_id,b.code ORDER BY b.created_at DESC, b.id DESC) AS survivor_name
  FROM banks b WHERE b.status = 1
)
SELECT r.agent_group_id, r.code, r.id AS loser_id, r.name AS loser_name, r.survivor_name,
       (LOWER(TRIM(r.name)) = LOWER(TRIM(r.survivor_name))) AS name_eq,
       (SELECT COUNT(*) FROM member_bank_accounts mba WHERE mba.bank_id = r.id) AS bindings,
       (SELECT COUNT(*) FROM withdrawal_orders wo WHERE wo.bank_id = r.id) AS orders
FROM ranked r
WHERE r.grp_cnt > 1 AND r.rn > 1
ORDER BY bindings DESC, name_eq ASC, r.agent_group_id, r.code;
```

分類回報：
- **有綁定 + 名稱相符**：安全合併（同一家銀行，re-point 真實會員）。
- **有綁定 + 名稱不符**：疑似 code 重用（同碼不同銀行）→ 命令的安全閘會自動跳過轉人工複核；**提醒使用者這幾組需人工裁決**。
- **零綁定**：template 冗餘，合併零會員影響。

另查**待審核實名件**（舊系統核准時以 bank_id 直建綁定、零狀態檢查；命令會自動 re-point 至 survivor，此查詢供影響面預告）：

```sql
SELECT r.id, r.member_id, r.bank_id, b.name AS bank_name, r.created_at
FROM realname_validation_requests r JOIN banks b ON b.id = r.bank_id
WHERE r.status = 0;
```

其中 bank_id 落在 loser/shadow 集合者，執行時會出現在「實名件 re-point」計數。

## Step 3 — 確認寫入

向使用者收斂摘要（總組數、安全合併數、預期 flag 數、最大宗綁定），**明確問：是否對 $ARGUMENTS 執行清理（生產寫入）？** 等回 yes/確認。

## Step 4 — 執行清理（confirm 後）

```bash
docker exec -i -e DB_CONNECTION=$ARGUMENTS php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:dedup-active --execute --force"
```

讀輸出的 `re-point / 停用綁定 / 停用銀行 / 人工複核` 數。**驗算**：`re-point + 停用綁定` 應等於受影響 loser 綁定總數。

## Step 5 — 驗證 + 上約束

1. dry-run 複查應為 **0 組**：
   ```bash
   docker exec -i -e DB_CONNECTION=$ARGUMENTS php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:dedup-active"
   ```
2. （可選，唯讀）對受影響 agent_group 跑「殘留同名」查詢確認 OP 視角無重複。
3. **問是否上唯一約束**（DDL 寫入）→ confirm 後：
   ```bash
   docker exec -i -e DB_CONNECTION=$ARGUMENTS php-fpm8 bash -c "cd /var/www/APIs && php artisan bank:guard-active-banks"
   ```
   安全閘會擋未清淨的站；idempotent。

## 回滾

🔴 **禁止全量跑 `bank:dedup-rollback --execute`**，原因有三：

- 它沒有範圍選項，會把 `bank_dedup_logs` 裡整段 dedup 歷史一次反轉，不只本輪。
- 它會卸下 `uk_banks_active` 唯一約束，但不會裝回，跑完之後重複列會無聲再生。
- 同一組有兩個以上 loser、且同一會員都綁過時，出款訂單會被改回錯的 loser。

要回滾時，先查出本輪審計 id 的邊界與要反向的列，整理後回報使用者，由使用者決定處理方式；不要自己下 UPDATE 反向改資料。回滾完成後必須再跑 `bank:guard-active-banks` 把約束裝回。細節見 `app/Console/Commands/CashFlow/Bank/DedupRollback.php` 的 docblock。

## 完成回報

逐白標回報：清理組數、re-point/停用/flag、是否已上約束、是否有待人工裁決的 code 重用組。
