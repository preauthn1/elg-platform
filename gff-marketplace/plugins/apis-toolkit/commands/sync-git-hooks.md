---
description: 將 `.githooks/` 的 hooks 同步安裝到 `.git/hooks/`，確保本地 hook 與專案規範一致。
disable-model-invocation: true
---

將 `.githooks/` 的 hooks 同步安裝到 `.git/hooks/`，確保本地 hook 與專案規範一致。

---

## 步驟

1. 檢查 `.githooks/` 目錄下有哪些 hook 檔案
2. 逐一複製到 `.git/hooks/`，並設定可執行權限
3. 比對安裝前後的 diff，確認是否有差異
4. 回報每個 hook 的安裝結果（新安裝 / 已是最新 / 已更新）
