---
paths:
  - ".claude/skills/**"
---

# 專案 skill 的來源與維護方式

> 本檔只在讀 `.claude/skills/` 底下的檔時載入。`.claude/skills/` 已進 git，不是 Claude Code plugin skill，所以 worktree 內同樣可用。

- `laravel-best-practices`、`tailwindcss-development` 由 boost 同步：跑 `composer boost`（內部執行 `php artisan boost:update`）時，會從 `vendor/laravel/boost/.ai/` 覆寫這兩支。要改它們的行為，不要直接改檔，改用 `.claude/rules/php.md` 的「覆蓋 vendored skill」一節。
- `shared-table-contract` 是手工維護（自 GFF-Marketplace 收編）。它不由 boost 同步，也不會被 `boost:update` 覆蓋，要改就直接改檔。
