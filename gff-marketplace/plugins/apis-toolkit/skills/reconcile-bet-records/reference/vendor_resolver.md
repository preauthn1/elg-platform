# Vendor 解析 + 環境健檢：候選查詢

> **何時讀這份**：Phase A Step 0 進場時，要把使用者口語的 vendor 名稱（PPSW、Kiss、PG…）解析成 `vendor_id`，並確認當前 DB 連到的環境是否真的有啟用該 vendor。

主 SKILL.md 的 Step 0 用一條 mysql MCP 查詢完成解析與判型；本檔補充名稱模糊時的候選查詢（同樣走對應白標的 mysql MCP 工具）。

---

## 1. 模糊查詢範本（mysql MCP）

用對應白標的 mysql MCP 工具（`mcp__plugin_apis-toolkit_mysql-{白標}__mysql_query`）跑一條查詢，`{needle}` 代入使用者口語輸入轉小寫：

```sql
SELECT id, name, code, tname, third_party_code, is_single_wallet, status
FROM vendors
WHERE LOWER(name) LIKE '%{needle}%' OR LOWER(code) LIKE '%{needle}%'
   OR LOWER(tname) LIKE '%{needle}%' OR LOWER(third_party_code) LIKE '%{needle}%';
```

- 0 筆：找不到，請操作者改用 `vendor_id` 或正式名稱。
- 多筆：列出全部候選（含 SW + TW 雙版本），請操作者指定 `vendor_id`。
- 1 筆：取它的 `id`，回主 SKILL.md Step 0 的查詢確認是否啟用與判型。

> 解析失敗（找不到 / 多筆歧義）就 **停下來問操作者**，不要自行猜測或繼續執行對帳邏輯。

---

## 2. 解析優先序

1. **數字** → 直接當 `vendor_id`
2. **匹配 `VendorIdEnum` case 名稱** → `App\Enums\Vendor\VendorIdEnum::tryFrom()` 或 case 對照
3. **模糊查 `vendors` 表四個欄位**：
   - `name`（中文/英文顯示名稱）
   - `code`（代號）
   - `tname`（**重要：xxx_bet_records 的表名前綴**）
   - `third_party_code`（與遊戲商接口時的代碼）

---

## 3. SW / TW 歧義處理（重要）

**部分遊戲商同時有單錢包 + 轉帳錢包**（vendors 表會有兩筆，例如 `epicwin` 與 `epicwinsw`、`pg` 與 `pgsw`）。

判斷錢包類型的權威欄位：**`vendors.is_single_wallet`**
- `1` → 單錢包（Single Wallet，遊戲商透過回調扣款）
- `0` → 轉帳錢包（Transfer Wallet，玩家需先轉入遊戲商）

模糊查到多筆時：

- 不可自行猜測，**列出所有候選讓操作者確認**
- 顯示欄位至少含：`id, name, code, tname, third_party_code, is_single_wallet, status`
- 提示形式：「找到 N 筆候選（含 SW + TW 雙版本），請指定 vendor_id 或更精確的名稱」

---

## 4. `tname` 是 BR 表前綴

`xxx_bet_records` 的 `xxx` = `vendors.tname`：

| vendor.tname | BR 表名 |
|---|---|
| `ppsw` | `ppsw_bet_records` |
| `kissh5sw` | `kissh5sw_bet_records` |
| `pg` | `pg_bet_records` |

所有用到 `xxx_bet_records` 的 SQL／程式碼，實際表名請動態以 `tname` 組成。

---

## 5. 跨白標切換

逐白標用各自的 mysql MCP 工具跑同一條查詢；白標清單讀 registry（`plugins/apis-toolkit/registry/whitelabels.metadata.yaml`），不在本檔列舉。

每切到新白標**都要重跑本健檢查詢**，因為各白標啟用的 vendor 不同，跨白標可能 vendor_infos.status=0（未啟用）→ 看到 BR 表全空。
