# apis-toolkit hooks

兩組 hook：db-command guard（PreToolUse，fail-closed）與 no-hardcode 規則掃描（PostToolUse，fail-open）。

## db-command guard

PreToolUse hook：攔截 AI agent 自主執行的破壞性 Laravel Artisan DB 命令（`migrate:fresh`/`db:wipe` 等），fail-closed。

| 檔 | 角色 |
|---|---|
| `hooks.json` | PreToolUse 掛 Bash matcher，指向 `guard-db-commands.sh` |
| `guard-db-commands.sh` | bash wrapper：收 stdin、呼叫分類器；**fail-closed**（node 非零 exit / 缺席 → deny）|
| `db-command-classifier.mjs` | 純分類器（shell-token 解析、per Artisan 呼叫取最嚴、解析失敗一律 deny）+ main-guard CLI |
| `db-command-classifier.test.mjs` | node:test 單元測試（spec §7 矩陣：變體 + 多命令繞過）|
| `guard-db-commands.test.sh` | bash 整合測試（含 malformed/缺 command/node 缺席 fail-closed）|

## no-hardcode 規則掃描

PostToolUse hook：掃描本次新寫入 PHP 內容（`Write.content` / `Edit.new_string`，不掃整檔）的 hardcode 樣態，命中以 `decision:block` 回饋讓 Claude 當場修正。**fail-open**（掃描器失敗/缺席 → 靜默放行；lint 回饋性質，與 db guard 的 fail-closed 語意相反屬刻意設計）。

| 檔 | 角色 |
|---|---|
| `hooks.json` | PostToolUse 掛 `Edit\|MultiEdit\|Write` matcher，指向 `check-php-hardcode.sh` |
| `check-php-hardcode.sh` | bash wrapper：收 stdin、呼叫掃描器；**fail-open** |
| `php-hardcode-scanner.mjs` | 純掃描器 + main-guard CLI；範圍限 `app/`、`routes/` 下 `.php`（config/tests/database/vendor 略過）|
| `php-hardcode-scanner.test.mjs` | node:test 單元測試（路徑過濾、各樣態、豁免、registry 解析）|
| `check-php-hardcode.test.sh` | bash 整合測試（block 輸出、豁免、malformed/node 缺席 fail-open）|

樣態：literal URL、IP、疑似密鑰、config 外 `env()`、白標名字面值（名單動態讀 `../registry/whitelabels.metadata.yaml`；排除 `env: test` 與 <3 字元別名控噪音；`Log::` 行與註解行的 URL 不掃）。魔術數字不掃（regex 噪音過高，交 `/code-quality` review 層）。豁免：該行加 `// hardcode-ok: 原因`。

## 測試（本機；CI 無 node）

> **CI runner 只有 php、無 node**（見 `scripts/validate.sh` 開頭）。故行為測試只能本機跑；CI 由 `validate.sh` 做結構檢查（檔案存在、JSON 合法、可執行、manifest 指向）。

```bash
# 單元（分類器）— 用 node 內建 glob（node 22 的 `--test <目錄>` 會誤當模組執行）
node --test 'plugins/apis-toolkit/hooks/**/*.test.mjs'

# 整合（wrapper fail-closed）
bash plugins/apis-toolkit/hooks/guard-db-commands.test.sh

# 結構（同 CI）
bash scripts/validate.sh
```

**改動本目錄任何檔後，務必本機跑上述三項全綠再 commit。**

## 分級（v5 收斂，spec §4）

- **deny**：`migrate:fresh`、`migrate:refresh`、`migrate:reset`、`db:wipe`
- **ask**：plain `migrate`（無 `--pretend`）、`migrate:rollback`、`db:seed`
- **allow**：`migrate --pretend`、`migrate:status`、其他

清單可擴充：改 `db-command-classifier.mjs` 的 `DENY`/`ASK` 集 + 補對應測試案例。
