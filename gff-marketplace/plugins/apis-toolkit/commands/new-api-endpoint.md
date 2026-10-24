---
description: 建立新 API 端點（FormRequest + Controller + Service + Route），分 frontend / agent / external 三面向。
argument-hint: "<HTTP 方法> <路由路徑> <面向>"
disable-model-invocation: true
---

輸入（HTTP 方法、路由路徑、面向 frontend/agent/external）：$ARGUMENTS

---

## 步驟 0：復用檢查

動手產出任何檔案前，先確認是否已有同事做過類似功能，避免重工：

1. 搜既有代碼：`app/Services`、`app/Helpers`、`Common`、同面向既有 Controller / FormRequest
2. `git log --grep='<功能關鍵字>'` 查歷史 commit
3. 有 gitlab MCP 時搜相關 MR

查到類似實作先比對再決定：能復用就直接復用；需要整段複製時，改為抽共用方法，禁止複製貼上改值。

## 架構規則

```
Request → FormRequest (驗證) → Controller (轉發) → Service (業務邏輯) → Response
```

| 層 | 職責 | 禁止 |
|---|---|---|
| `FormRequest` | 驗證規則、authorize | 業務邏輯 |
| `Controller` | 呼叫 Service，回傳響應 | 直接查詢 DB、業務判斷 |
| `Service` | 核心業務邏輯 | 直接使用 Facade（除 Log/DB/Config） |

## 目錄對應

| 面向 | Controller 目錄 | Route 檔案 |
|---|---|---|
| frontend | `Http/Controllers/Frontend/` | `routes/frontend/auth.php` |
| agent | `Http/Controllers/Agent/` | `routes/agent/auth.php` |
| external | `Http/Controllers/External/` | `routes/external/` |

## 強制要求

- 所有檔案首行：`declare(strict_types=1);`
- Service 使用 Constructor DI，避免直接使用 Facade
- 寫入操作必須包裹在 `DB::beginTransaction()` 中
- 讀取關聯資料必須使用 `with()` 預加載，禁止 N+1
- 金額欄位運算使用 `Common::numAdd/Sub/Mul/Div`
- 例外：`ApiException('English message')`；日誌：`Log::error('繁中描述', [...])`

## 輸出檔案清單

1. `app/Http/Requests/{Facing}/{FeatureName}Request.php`
2. `app/Http/Controllers/{Facing}/{FeatureName}Controller.php`
3. `app/Services/{FeatureName}Service.php`（若邏輯複雜）
4. `routes/{facing}/auth.php`（新增路由）
