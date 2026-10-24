---
paths:
  - "app/Http/Controllers/**/*.php"
  - "app/Console/Commands/**/*.php"
  - "app/Services/Payment/Providers/**/*.php"
  - "app/Vendors/**/*.php"
  - "app/Filament/**/*.php"
---

# 改類別前先找它的規則文件

規則正本在 `docs/knowledge/rules/`。開一支類別準備改行為前，照這個順序找：

1. **看 docblock 有沒有 `@knowledge`**：有就讀那份 Rule 文件，例 `@knowledge rules/withdrawal-member-fee` → `docs/knowledge/rules/withdrawal-member-fee.md`。
2. **沒有就搜 entities**：`grep -rl "<類別短名>" docs/knowledge/rules/`，找 frontmatter `entities` 列到這支類別的文件。
3. **再沒有**：代表這支類別還沒有規則文件，不代表它沒有規則。改了行為就補文件，寫法見 `.claude/rules/knowledge.md`。

這支類別若是 Controller 或 artisan 命令，它在 `apis/` 還有一份骨架文件，路徑是 `docs/knowledge/apis/<frontend|agent|external|console>/<短名或簽名>.md`（命令簽名的冒號改連字號，例 `cash-flow:sync` → `console/cash-flow-sync.md`）。骨架的「相關規則」一節也列出管到它的 Rule 文件。

找到的規則與你要改的行為不同時，先確認是規則要改（同一支 MR 連文件一起改）還是程式有 bug。
